<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Catalog\Course;
use Central\Api\Domain\Catalog\CourseRepository;
use Central\Api\Domain\Catalog\CourseStatus;
use Central\Api\Domain\Shared\Difficulty;
use Doctrine\DBAL\Connection;

final class DbalCourseRepository implements CourseRepository
{
    private const COLUMNS = 'id, tenant_id, title, summary, description, objectives, kind, status, level,
                             category_id, instructor_id, thumbnail_url, pass_percentage, duration_minutes,
                             enrollment_limit, is_mandatory, is_featured, is_onboarding, allow_self_enrollment,
                             starts_on, ends_on, rating_average, rating_count,
                             published_at, created_at, updated_at';

    /** Interpolated into ORDER BY, which cannot be parameterised. */
    private const SORTABLE = ['title', 'status', 'created_at', 'updated_at', 'published_at', 'rating_average'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function paginate(
        string $tenantId,
        int $page,
        int $perPage,
        ?string $search = null,
        ?CourseStatus $status = null,
        ?string $categoryId = null,
        string $sortBy = 'title',
        string $sortDirection = 'asc',
    ): array {
        $where = 'tenant_id = :tenant AND deleted_at IS NULL';
        $params = ['tenant' => $tenantId];

        if ($status !== null) {
            $where .= ' AND status = :status';
            $params['status'] = $status->value;
        }

        if ($categoryId !== null && $categoryId !== '') {
            $where .= ' AND category_id = :category';
            $params['category'] = $categoryId;
        }

        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (title ILIKE :search OR summary ILIKE :search OR description ILIKE :search)';
            $params['search'] = '%' . trim($search) . '%';
        }

        return $this->page($where, $params, $page, $perPage, $sortBy, $sortDirection);
    }

    public function catalogue(string $tenantId, int $page, int $perPage, ?string $search = null): array
    {
        // Published only, and not expressed as an optional filter — a caller
        // cannot forget it and expose drafts to learners.
        $where = "tenant_id = :tenant AND status = 'published' AND deleted_at IS NULL";
        $params = ['tenant' => $tenantId];

        if ($search !== null && trim($search) !== '') {
            $where .= ' AND (title ILIKE :search OR summary ILIKE :search)';
            $params['search'] = '%' . trim($search) . '%';
        }

        return $this->page($where, $params, $page, $perPage, 'title', 'asc');
    }

    public function find(string $tenantId, string $id): ?Course
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM courses WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                self::COLUMNS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(Course $course): Course
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO courses (tenant_id, title, summary, description, objectives, kind, status, level,
                                  category_id, instructor_id, thumbnail_url, pass_percentage, duration_minutes,
                                  enrollment_limit, is_mandatory, is_featured, is_onboarding,
                                  allow_self_enrollment, starts_on, ends_on, published_at)
             VALUES (:tenant, :title, :summary, :description, :objectives, :kind, :status, :level,
                     :category, :instructor, :thumbnail, :passPercentage, :duration,
                     :limit, :mandatory, :featured, :onboarding, :selfEnroll, :startsOn, :endsOn,
                     CASE WHEN :status2 = \'published\' THEN NOW() ELSE NULL END)
             RETURNING id',
            $this->writeParams($course) + ['status2' => $course->status->value],
        );

        return $this->find($course->tenantId, (string) $id)
            ?? throw new \RuntimeException('Newly created course could not be read back.');
    }

    public function update(Course $course): Course
    {
        $this->connection->executeStatement(
            'UPDATE courses SET
                title = :title, summary = :summary, description = :description, objectives = :objectives,
                kind = :kind, level = :level, category_id = :category, instructor_id = :instructor,
                thumbnail_url = :thumbnail, pass_percentage = :passPercentage,
                duration_minutes = :duration, enrollment_limit = :limit,
                is_mandatory = :mandatory, is_featured = :featured, is_onboarding = :onboarding,
                allow_self_enrollment = :selfEnroll, starts_on = :startsOn, ends_on = :endsOn,
                updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            // Status deliberately excluded — publishing goes through setStatus().
            array_diff_key($this->writeParams($course), ['status' => null]) + ['id' => $course->id],
        );

        return $this->find($course->tenantId, $course->id)
            ?? throw new \RuntimeException('Updated course could not be read back.');
    }

    public function setStatus(string $tenantId, string $id, CourseStatus $status): ?Course
    {
        $affected = $this->connection->executeStatement(
            'UPDATE courses
             SET status = :status,
                 -- Stamped the first time it is published, and never cleared:
                 -- "first published on" is a fact about the course, not its
                 -- current state.
                 published_at = CASE
                     WHEN :status2 = \'published\' AND published_at IS NULL THEN NOW()
                     ELSE published_at
                 END,
                 updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            [
                'status'  => $status->value,
                'status2' => $status->value,
                'id'      => $id,
                'tenant'  => $tenantId,
            ],
        );

        return $affected > 0 ? $this->find($tenantId, $id) : null;
    }

    public function softDelete(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            "UPDATE courses SET deleted_at = NOW(), status = 'archived', updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL",
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    public function existsWithTitle(string $tenantId, string $title, ?string $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM courses
                WHERE tenant_id = :tenant AND lower(title) = lower(:title) AND deleted_at IS NULL';
        $params = ['tenant' => $tenantId, 'title' => $title];

        if ($exceptId !== null) {
            $sql .= ' AND id <> :exceptId';
            $params['exceptId'] = $exceptId;
        }

        return $this->connection->fetchOne($sql . ' LIMIT 1', $params) !== false;
    }

    public function hasActiveLessons(string $tenantId, string $courseId): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM lessons
             WHERE course_id = :course AND tenant_id = :tenant
               AND is_active AND deleted_at IS NULL
             LIMIT 1',
            ['course' => $courseId, 'tenant' => $tenantId],
        ) !== false;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{items: list<Course>, total: int}
     */
    private function page(
        string $where,
        array $params,
        int $page,
        int $perPage,
        string $sortBy,
        string $sortDirection,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $sortBy = in_array($sortBy, self::SORTABLE, true) ? $sortBy : 'title';
        $direction = strtolower($sortDirection) === 'desc' ? 'DESC' : 'ASC';

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM courses WHERE %s', $where),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM courses WHERE %s ORDER BY %s %s NULLS LAST, title ASC LIMIT :limit OFFSET :offset',
                self::COLUMNS,
                $where,
                $sortBy,
                $direction,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $row): Course => $this->hydrate($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function writeParams(Course $course): array
    {
        return [
            'tenant'         => $course->tenantId,
            'title'          => $course->title,
            'summary'        => $course->summary,
            'description'    => $course->description,
            'objectives'     => $course->objectives,
            'kind'           => $course->kind,
            'status'         => $course->status->value,
            'level'          => $course->level?->value,
            'category'       => $course->categoryId,
            'instructor'     => $course->instructorId,
            'thumbnail'      => $course->thumbnailUrl,
            'passPercentage' => $course->passPercentage,
            'duration'       => $course->durationMinutes,
            'limit'          => $course->enrollmentLimit,
            'mandatory'      => $course->isMandatory ? 'true' : 'false',
            'featured'       => $course->isFeatured ? 'true' : 'false',
            'onboarding'     => $course->isOnboarding ? 'true' : 'false',
            'selfEnroll'     => $course->allowSelfEnrollment ? 'true' : 'false',
            'startsOn'       => $course->startsOn?->format('Y-m-d'),
            'endsOn'         => $course->endsOn?->format('Y-m-d'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Course
    {
        $status = CourseStatus::tryFrom((string) $row['status']);

        if ($status === null) {
            // The check constraint makes this unreachable; an unknown status
            // must never be silently treated as published.
            throw new \RuntimeException(sprintf('Unknown course status "%s".', $row['status']));
        }

        return new Course(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            title: (string) $row['title'],
            status: $status,
            summary: $this->text($row['summary'] ?? null),
            description: $this->text($row['description'] ?? null),
            objectives: $this->text($row['objectives'] ?? null),
            kind: (string) $row['kind'],
            level: $this->difficulty($row['level'] ?? null),
            categoryId: $this->text($row['category_id'] ?? null),
            instructorId: $this->text($row['instructor_id'] ?? null),
            thumbnailUrl: $this->text($row['thumbnail_url'] ?? null),
            passPercentage: (int) $row['pass_percentage'],
            durationMinutes: isset($row['duration_minutes']) ? (int) $row['duration_minutes'] : null,
            enrollmentLimit: isset($row['enrollment_limit']) ? (int) $row['enrollment_limit'] : null,
            isMandatory: (bool) $row['is_mandatory'],
            isFeatured: (bool) $row['is_featured'],
            isOnboarding: (bool) $row['is_onboarding'],
            allowSelfEnrollment: (bool) $row['allow_self_enrollment'],
            startsOn: $this->date($row['starts_on'] ?? null),
            endsOn: $this->date($row['ends_on'] ?? null),
            ratingAverage: isset($row['rating_average']) ? (float) $row['rating_average'] : null,
            ratingCount: (int) $row['rating_count'],
            publishedAt: $this->date($row['published_at'] ?? null),
            createdAt: $this->date($row['created_at'] ?? null),
            updatedAt: $this->date($row['updated_at'] ?? null),
        );
    }

    /**
     * An unrecognised level is read as "unset" rather than fatal: level is
     * descriptive metadata, and a row imported with a stale value should
     * still be readable. Status gets the opposite treatment — see hydrate().
     */
    private function difficulty(mixed $value): ?Difficulty
    {
        $name = $this->text($value);

        return $name === null ? null : Difficulty::tryFrom($name);
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
