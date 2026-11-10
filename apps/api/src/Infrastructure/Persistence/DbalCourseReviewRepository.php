<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Catalog\CourseReview;
use Central\Api\Domain\Catalog\CourseReviewRepository;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class DbalCourseReviewRepository implements CourseReviewRepository
{
    private const COLUMNS = "r.id, r.tenant_id, r.course_id, r.user_id, r.enrollment_id,
                             r.rating, r.title, r.review_text, r.content_rating,
                             r.difficulty_rating, r.practical_value_rating,
                             r.moderation_status, r.is_published,
                             r.created_at, r.updated_at,
                             TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS author_name,
                             c.title AS course_title";

    private const JOINS = 'LEFT JOIN users u ON u.id = r.user_id
                           LEFT JOIN courses c ON c.id = r.course_id';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function published(string $tenantId, string $courseId, int $page, int $perPage): array
    {
        $where = 'r.tenant_id = :tenant AND r.course_id = :course
                  AND r.is_published AND r.deleted_at IS NULL';

        return $this->page($where, ['tenant' => $tenantId, 'course' => $courseId], $page, $perPage);
    }

    public function find(string $tenantId, string $id): ?CourseReview
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM course_reviews r %s
                 WHERE r.id = :id AND r.tenant_id = :tenant AND r.deleted_at IS NULL',
                self::COLUMNS,
                self::JOINS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function forEnrolment(string $tenantId, string $enrollmentId): ?CourseReview
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM course_reviews r %s
                 WHERE r.enrollment_id = :enrolment AND r.tenant_id = :tenant
                   AND r.deleted_at IS NULL',
                self::COLUMNS,
                self::JOINS,
            ),
            ['enrolment' => $enrollmentId, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function forUser(string $tenantId, string $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM course_reviews r %s
                 WHERE r.tenant_id = :tenant AND r.user_id = :user AND r.deleted_at IS NULL
                 ORDER BY r.created_at DESC',
                self::COLUMNS,
                self::JOINS,
            ),
            ['tenant' => $tenantId, 'user' => $userId],
        );

        return array_map(fn (array $row): CourseReview => $this->hydrate($row), $rows);
    }

    public function save(CourseReview $review): CourseReview
    {
        // Editing replaces the row keyed on (enrolment); the unique index makes
        // one enrolment one review, so re-submitting updates rather than stacks.
        $this->connection->executeStatement(
            "INSERT INTO course_reviews
                 (id, tenant_id, course_id, user_id, enrollment_id, rating, title,
                  review_text, content_rating, difficulty_rating, practical_value_rating,
                  moderation_status, is_published)
             VALUES (:id, :tenant, :course, :user, :enrolment, :rating, :title,
                     :text, :content, :difficulty, :practical, 'approved', TRUE)
             ON CONFLICT (enrollment_id) WHERE deleted_at IS NULL DO UPDATE SET
                 rating                 = EXCLUDED.rating,
                 title                  = EXCLUDED.title,
                 review_text            = EXCLUDED.review_text,
                 content_rating         = EXCLUDED.content_rating,
                 difficulty_rating      = EXCLUDED.difficulty_rating,
                 practical_value_rating = EXCLUDED.practical_value_rating,
                 updated_at             = NOW()",
            [
                'id'         => $review->id === '' ? Uuid::uuid4()->toString() : $review->id,
                'tenant'     => $review->tenantId,
                'course'     => $review->courseId,
                'user'       => $review->userId,
                'enrolment'  => $review->enrollmentId,
                'rating'     => $review->rating,
                'title'      => $review->title,
                'text'       => $review->reviewText,
                'content'    => $review->contentRating,
                'difficulty' => $review->difficultyRating,
                'practical'  => $review->practicalValueRating,
            ],
        );

        $this->recomputeAverage($review->tenantId, $review->courseId);

        return $this->forEnrolment($review->tenantId, $review->enrollmentId) ?? $review;
    }

    public function setPublished(string $tenantId, string $id, bool $published, string $moderatorId): ?CourseReview
    {
        $this->connection->executeStatement(
            'UPDATE course_reviews
                SET is_published = :published,
                    moderation_status = :status,
                    moderated_by = :by,
                    moderated_at = NOW(),
                    updated_at = NOW()
              WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            [
                'published' => $published ? 'true' : 'false',
                // Hidden reviews are recorded as rejected so the published-is-
                // approved invariant holds; republishing approves them again.
                'status'    => $published ? 'approved' : 'rejected',
                'by'        => $moderatorId,
                'id'        => $id,
                'tenant'    => $tenantId,
            ],
        );

        $review = $this->find($tenantId, $id);

        if ($review !== null) {
            $this->recomputeAverage($tenantId, $review->courseId);
        }

        return $review;
    }

    public function delete(string $tenantId, string $id, string $byUserId, bool $isModerator): bool
    {
        $review = $this->find($tenantId, $id);

        if ($review === null) {
            return false;
        }

        // The author may remove their own; a moderator may remove anyone's.
        if (!$isModerator && $review->userId !== $byUserId) {
            return false;
        }

        $this->connection->executeStatement(
            'UPDATE course_reviews SET deleted_at = NOW()
              WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        );

        $this->recomputeAverage($tenantId, $review->courseId);

        return true;
    }

    public function summaryFor(string $tenantId, string $courseId): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT
                 COUNT(*)                                   AS count,
                 COALESCE(AVG(rating), 0)                   AS average,
                 COUNT(*) FILTER (WHERE rating = 5)         AS star5,
                 COUNT(*) FILTER (WHERE rating = 4)         AS star4,
                 COUNT(*) FILTER (WHERE rating = 3)         AS star3,
                 COUNT(*) FILTER (WHERE rating = 2)         AS star2,
                 COUNT(*) FILTER (WHERE rating = 1)         AS star1,
                 AVG(content_rating)         AS content_avg,
                 AVG(difficulty_rating)      AS difficulty_avg,
                 AVG(practical_value_rating) AS practical_avg
             FROM course_reviews
             WHERE tenant_id = :tenant AND course_id = :course
               AND is_published AND deleted_at IS NULL",
            ['tenant' => $tenantId, 'course' => $courseId],
        );

        $row = $row === false ? [] : $row;

        return [
            'count'   => (int) ($row['count'] ?? 0),
            'average' => round((float) ($row['average'] ?? 0), 2),
            'distribution' => [
                5 => (int) ($row['star5'] ?? 0),
                4 => (int) ($row['star4'] ?? 0),
                3 => (int) ($row['star3'] ?? 0),
                2 => (int) ($row['star2'] ?? 0),
                1 => (int) ($row['star1'] ?? 0),
            ],
            'contentAverage'    => $row['content_avg'] === null ? null : round((float) $row['content_avg'], 2),
            'difficultyAverage' => $row['difficulty_avg'] === null ? null : round((float) $row['difficulty_avg'], 2),
            'practicalAverage'  => $row['practical_avg'] === null ? null : round((float) $row['practical_avg'], 2),
        ];
    }

    // --- internals ------------------------------------------------------------

    /**
     * Push the average and count from the published reviews back onto the
     * course. These columns are a read cache; the reviews are the truth.
     */
    private function recomputeAverage(string $tenantId, string $courseId): void
    {
        $this->connection->executeStatement(
            'UPDATE courses c SET
                 rating_average = COALESCE(s.avg, 0),
                 rating_count   = COALESCE(s.cnt, 0)
             FROM (
                 SELECT AVG(rating)::numeric(3,2) AS avg, COUNT(*) AS cnt
                 FROM course_reviews
                 WHERE tenant_id = :tenant AND course_id = :course
                   AND is_published AND deleted_at IS NULL
             ) s
             WHERE c.id = :course AND c.tenant_id = :tenant',
            ['tenant' => $tenantId, 'course' => $courseId],
        );
    }

    /**
     * @param  array<string, mixed> $params
     * @return array{items: list<CourseReview>, total: int}
     */
    private function page(string $where, array $params, int $page, int $perPage): array
    {
        $perPage = max(1, min(100, $perPage));
        $offset  = max(0, ($page - 1) * $perPage);

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM course_reviews r WHERE %s', $where),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM course_reviews r %s WHERE %s
                 ORDER BY r.created_at DESC LIMIT %d OFFSET %d',
                self::COLUMNS,
                self::JOINS,
                $where,
                $perPage,
                $offset,
            ),
            $params,
        );

        return [
            'items' => array_map(fn (array $row): CourseReview => $this->hydrate($row), $rows),
            'total' => $total,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): CourseReview
    {
        $time = static fn (mixed $v): ?\DateTimeImmutable => $v === null ? null : new \DateTimeImmutable((string) $v);
        $int  = static fn (mixed $v): ?int => $v === null ? null : (int) $v;

        return new CourseReview(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            courseId: (string) $row['course_id'],
            userId: (string) $row['user_id'],
            enrollmentId: (string) $row['enrollment_id'],
            rating: (int) $row['rating'],
            title: $row['title'] === null ? null : (string) $row['title'],
            reviewText: $row['review_text'] === null ? null : (string) $row['review_text'],
            contentRating: $int($row['content_rating'] ?? null),
            difficultyRating: $int($row['difficulty_rating'] ?? null),
            practicalValueRating: $int($row['practical_value_rating'] ?? null),
            isPublished: (bool) $row['is_published'],
            moderationStatus: (string) $row['moderation_status'],
            createdAt: $time($row['created_at'] ?? null),
            updatedAt: $time($row['updated_at'] ?? null),
            authorName: $row['author_name'] === null ? null : (string) $row['author_name'],
            courseTitle: $row['course_title'] === null ? null : (string) $row['course_title'],
        );
    }
}
