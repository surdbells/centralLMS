<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Note\Note;
use Central\Api\Domain\Note\NoteFilters;
use Central\Api\Domain\Note\NoteInput;
use Central\Api\Domain\Note\NoteRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalNoteRepository implements NoteRepository
{
    private const COLUMNS = 'n.id, n.tenant_id, n.user_id, n.lesson_id, n.course_id,
                             n.title, n.content, n.position_seconds, n.is_pinned,
                             n.created_at, n.updated_at';

    private const TITLES = 'l.title AS lesson_title, m.title AS module_title, c.title AS course_title';

    private const JOINS = 'LEFT JOIN lessons l ON l.id = n.lesson_id
                           LEFT JOIN course_modules m ON m.id = l.module_id
                           LEFT JOIN courses c ON c.id = n.course_id';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function paginate(string $tenantId, string $userId, NoteFilters $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->conditions($tenantId, $userId, $filters);

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM notes n WHERE %s', $where),
            $params,
        );

        // Pinned first unless the caller asked for the most recently touched,
        // which is what `user/notes/recent.php` was. `id` last so a page
        // boundary cannot show the same note twice.
        $order = $filters->mostRecentFirst
            ? 'n.updated_at DESC, n.id DESC'
            : 'n.is_pinned DESC, n.position_seconds ASC NULLS LAST, n.created_at DESC, n.id DESC';

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s FROM notes n %s WHERE %s ORDER BY %s LIMIT :limit OFFSET :offset',
                self::COLUMNS,
                self::TITLES,
                self::JOINS,
                $where,
                $order,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $row): Note => $this->hydrate($row), $rows),
            'total' => $total,
        ];
    }

    public function find(string $tenantId, string $userId, string $id): ?Note
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s FROM notes n %s
                 WHERE n.id = :id AND n.tenant_id = :tenant AND n.user_id = :user AND n.deleted_at IS NULL',
                self::COLUMNS,
                self::TITLES,
                self::JOINS,
            ),
            ['id' => $id, 'tenant' => $tenantId, 'user' => $userId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function courseForReachableLesson(string $tenantId, string $userId, string $lessonId): ?string
    {
        // The enrolment is the check. Without it a learner can write notes
        // against any lesson id and be told which ones exist.
        $courseId = $this->connection->fetchOne(
            'SELECT l.course_id
             FROM lessons l
             JOIN enrollments e ON e.course_id = l.course_id
                                AND e.user_id = :user
                                AND e.deleted_at IS NULL
                                AND e.status <> :dropped
             WHERE l.id = :lesson AND l.tenant_id = :tenant AND l.deleted_at IS NULL
             LIMIT 1',
            ['lesson' => $lessonId, 'tenant' => $tenantId, 'user' => $userId, 'dropped' => 'dropped'],
        );

        return is_string($courseId) && $courseId !== '' ? $courseId : null;
    }

    public function create(Note $note): Note
    {
        $id = (string) $this->connection->fetchOne(
            'INSERT INTO notes
                 (tenant_id, user_id, lesson_id, course_id, title, content, position_seconds, is_pinned)
             VALUES (:tenant, :user, :lesson, :course, :title, :content, :position, :pinned)
             RETURNING id',
            [
                'tenant'   => $note->tenantId,
                'user'     => $note->userId,
                'lesson'   => $note->lessonId,
                'course'   => $note->courseId,
                'title'    => $note->title,
                'content'  => $note->content,
                'position' => $note->positionSeconds,
                'pinned'   => $note->isPinned,
            ],
            ['pinned' => ParameterType::BOOLEAN],
        );

        return $this->find($note->tenantId, $note->userId, $id)
            ?? throw new \RuntimeException('The note vanished immediately after being written.');
    }

    public function update(string $tenantId, string $userId, string $id, NoteInput $input): ?Note
    {
        $changed = $this->connection->executeStatement(
            // Only what the edit mentioned. A form that sends the one field it
            // changed must not clear the other three, and `content` is the
            // only one an edit always carries.
            'UPDATE notes SET
                 content = :content,
                 title = CASE WHEN :touchesTitle::boolean THEN :title ELSE title END,
                 position_seconds = CASE WHEN :touchesPosition::boolean
                                         THEN :position::int ELSE position_seconds END,
                 is_pinned = COALESCE(:pinned::boolean, is_pinned),
                 updated_at = now()
             WHERE id = :id AND tenant_id = :tenant AND user_id = :user AND deleted_at IS NULL',
            [
                'id'              => $id,
                'tenant'          => $tenantId,
                'user'            => $userId,
                'title'           => $input->title,
                'touchesTitle'    => $input->touchesTitle,
                'content'         => $input->content,
                'position'        => $input->positionSeconds,
                'touchesPosition' => $input->touchesPosition,
                'pinned'          => $input->isPinned,
            ],
            [
                'pinned'          => ParameterType::BOOLEAN,
                'touchesTitle'    => ParameterType::BOOLEAN,
                'touchesPosition' => ParameterType::BOOLEAN,
            ],
        );

        return $changed === 0 ? null : $this->find($tenantId, $userId, $id);
    }

    public function setPinned(string $tenantId, string $userId, string $id, bool $pinned): ?Note
    {
        $changed = $this->connection->executeStatement(
            'UPDATE notes SET is_pinned = :pinned, updated_at = now()
             WHERE id = :id AND tenant_id = :tenant AND user_id = :user AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId, 'user' => $userId, 'pinned' => $pinned],
            ['pinned' => ParameterType::BOOLEAN],
        );

        return $changed === 0 ? null : $this->find($tenantId, $userId, $id);
    }

    public function softDelete(string $tenantId, string $userId, string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE notes SET deleted_at = now(), updated_at = now()
             WHERE id = :id AND tenant_id = :tenant AND user_id = :user AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId, 'user' => $userId],
        ) > 0;
    }

    public function statistics(string $tenantId, string $userId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE n.is_pinned) AS pinned,
                    COUNT(DISTINCT n.course_id) AS courses
             FROM notes n
             WHERE n.tenant_id = :tenant AND n.user_id = :user AND n.deleted_at IS NULL',
            ['tenant' => $tenantId, 'user' => $userId],
        );

        $row = $row === false ? [] : $row;

        return [
            'total'   => (int) ($row['total'] ?? 0),
            'pinned'  => (int) ($row['pinned'] ?? 0),
            'courses' => (int) ($row['courses'] ?? 0),
        ];
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function conditions(string $tenantId, string $userId, NoteFilters $filters): array
    {
        $where  = ['n.tenant_id = :tenant', 'n.user_id = :user', 'n.deleted_at IS NULL'];
        $params = ['tenant' => $tenantId, 'user' => $userId];

        if ($filters->lessonId !== null) {
            $where[]            = 'n.lesson_id = :lessonId';
            $params['lessonId'] = $filters->lessonId;
        }

        if ($filters->courseId !== null) {
            $where[]            = 'n.course_id = :courseId';
            $params['courseId'] = $filters->courseId;
        }

        if ($filters->pinnedOnly !== null) {
            $where[] = $filters->pinnedOnly ? 'n.is_pinned' : 'NOT n.is_pinned';
        }

        if ($filters->search !== null) {
            $where[]          = '(n.content ILIKE :search OR n.title ILIKE :search)';
            $params['search'] = '%' . $filters->search . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Note
    {
        return new Note(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            userId: (string) $row['user_id'],
            lessonId: (string) $row['lesson_id'],
            courseId: (string) $row['course_id'],
            content: (string) $row['content'],
            title: $this->text($row['title'] ?? null),
            positionSeconds: ($row['position_seconds'] ?? null) === null ? null : (int) $row['position_seconds'],
            isPinned: (bool) $row['is_pinned'],
            createdAt: $this->date($row['created_at'] ?? null),
            updatedAt: $this->date($row['updated_at'] ?? null),
            lessonTitle: $this->text($row['lesson_title'] ?? null),
            moduleTitle: $this->text($row['module_title'] ?? null),
            courseTitle: $this->text($row['course_title'] ?? null),
        );
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
