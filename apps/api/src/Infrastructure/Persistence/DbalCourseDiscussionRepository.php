<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Catalog\CourseDiscussionRepository;
use Doctrine\DBAL\Connection;

final class DbalCourseDiscussionRepository implements CourseDiscussionRepository
{
    private const COLUMNS = "p.id, p.course_id, p.parent_id, p.lesson_id, p.body,
                             p.user_id, p.created_at,
                             TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS author_name,
                             u.avatar_url AS author_avatar";

    public function __construct(private readonly Connection $connection)
    {
    }

    public function thread(string $tenantId, string $courseId): array
    {
        $posts = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT %s FROM course_discussion_posts p
                 JOIN users u ON u.id = p.user_id
                 WHERE p.tenant_id = :tenant AND p.course_id = :course
                   AND p.parent_id IS NULL AND p.deleted_at IS NULL
                 ORDER BY p.created_at DESC",
                self::COLUMNS,
            ),
            ['tenant' => $tenantId, 'course' => $courseId],
        );

        if ($posts === []) {
            return [];
        }

        $replies = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT %s FROM course_discussion_posts p
                 JOIN users u ON u.id = p.user_id
                 WHERE p.tenant_id = :tenant AND p.course_id = :course
                   AND p.parent_id IS NOT NULL AND p.deleted_at IS NULL
                 ORDER BY p.created_at",
                self::COLUMNS,
            ),
            ['tenant' => $tenantId, 'course' => $courseId],
        );

        $byParent = [];

        foreach ($replies as $reply) {
            $byParent[(string) $reply['parent_id']][] = $this->hydrate($reply);
        }

        return array_map(
            fn (array $post): array => [
                ...$this->hydrate($post),
                'replies' => $byParent[(string) $post['id']] ?? [],
            ],
            $posts,
        );
    }

    public function post(
        string $tenantId,
        string $courseId,
        string $userId,
        string $body,
        ?string $parentId,
        ?string $lessonId,
    ): array {
        // Attach a reply to its parent's own post, so a reply to a reply still
        // lands on the top-level question rather than nesting deeper.
        if ($parentId !== null) {
            $parentId = (string) ($this->connection->fetchOne(
                'SELECT COALESCE(parent_id, id) FROM course_discussion_posts
                 WHERE id = :id AND course_id = :course AND tenant_id = :tenant AND deleted_at IS NULL',
                ['id' => $parentId, 'course' => $courseId, 'tenant' => $tenantId],
            ) ?: null);

            if ($parentId === '') {
                $parentId = null;
            }
        }

        $id = (string) $this->connection->fetchOne(
            'INSERT INTO course_discussion_posts
                 (tenant_id, course_id, user_id, parent_id, lesson_id, body)
             VALUES (:tenant, :course, :user, :parent::uuid, :lesson::uuid, :body)
             RETURNING id',
            [
                'tenant' => $tenantId,
                'course' => $courseId,
                'user'   => $userId,
                'parent' => $parentId,
                'lesson' => $lessonId,
                'body'   => $body,
            ],
        );

        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM course_discussion_posts p
                 JOIN users u ON u.id = p.user_id WHERE p.id = :id',
                self::COLUMNS,
            ),
            ['id' => $id],
        );

        return [...$this->hydrate($row === false ? [] : $row), 'replies' => []];
    }

    public function delete(string $tenantId, string $id, string $byUserId, bool $isModerator): bool
    {
        $where = 'id = :id AND tenant_id = :tenant AND deleted_at IS NULL';
        $params = ['id' => $id, 'tenant' => $tenantId];

        if (!$isModerator) {
            $where .= ' AND user_id = :user';
            $params['user'] = $byUserId;
        }

        // Take the replies with it: an answer to a deleted question answers
        // nothing, and leaving them orphaned reads as a conversation with a
        // hole in it.
        return $this->connection->executeStatement(
            sprintf(
                'UPDATE course_discussion_posts SET deleted_at = NOW()
                 WHERE (%s) OR (parent_id = :id AND tenant_id = :tenant AND deleted_at IS NULL)',
                $where,
            ),
            $params,
        ) > 0;
    }

    public function countFor(string $tenantId, string $courseId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM course_discussion_posts
             WHERE tenant_id = :tenant AND course_id = :course AND deleted_at IS NULL',
            ['tenant' => $tenantId, 'course' => $courseId],
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $name = is_string($row['author_name'] ?? null) ? trim($row['author_name']) : '';

        return [
            'id'          => (string) ($row['id'] ?? ''),
            'body'        => (string) ($row['body'] ?? ''),
            'lessonId'    => $row['lesson_id'] === null ? null : (string) $row['lesson_id'],
            'authorId'    => (string) ($row['user_id'] ?? ''),
            'authorName'  => $name === '' ? 'Someone' : $name,
            'authorAvatar' => is_string($row['author_avatar'] ?? null) && $row['author_avatar'] !== ''
                ? $row['author_avatar']
                : null,
            'createdAt'   => (string) ($row['created_at'] ?? ''),
        ];
    }
}
