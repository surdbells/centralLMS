<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\LearningPath\ModerationStatus;
use Central\Api\Domain\LearningPath\PathReview;
use Central\Api\Domain\LearningPath\PathReviewRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalPathReviewRepository implements PathReviewRepository
{
    private const COLUMNS = "r.id, r.tenant_id, r.path_id, r.user_id, r.enrollment_id,
                             r.rating, r.title, r.review_text, r.content_rating,
                             r.difficulty_rating, r.practical_value_rating,
                             r.moderation_status, r.moderated_by, r.moderated_at,
                             r.rejection_reason, r.is_published,
                             r.response_text, r.response_by, r.response_at,
                             r.created_at, r.updated_at,
                             TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS author_name,
                             u.avatar_url AS author_avatar_url,
                             p.title AS path_title";

    /**
     * Helpful and reported, counted from the votes.
     *
     * A stored counter cannot stop one person voting forty times, and cannot
     * answer "who reported this", which is the first thing a moderator asks.
     */
    private const COUNTS = "(
                                SELECT COUNT(*) FROM learning_path_review_votes v
                                WHERE v.review_id = r.id AND v.kind = 'helpful'
                            ) AS helpful_count,
                            (
                                SELECT COUNT(*) FROM learning_path_review_votes v
                                WHERE v.review_id = r.id AND v.kind = 'report'
                            ) AS reported_count";

    private const JOINS = 'LEFT JOIN users u ON u.id = r.user_id
                           LEFT JOIN learning_paths p ON p.id = r.path_id';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function published(string $tenantId, string $pathId, int $page, int $perPage): array
    {
        $where = 'r.tenant_id = :tenant AND r.path_id = :path
                  AND r.is_published AND r.deleted_at IS NULL';
        $params = ['tenant' => $tenantId, 'path' => $pathId];

        return $this->page($where, $params, 'r.created_at DESC', $page, $perPage);
    }

    public function forPath(
        string $tenantId,
        string $pathId,
        ?ModerationStatus $status,
        int $page,
        int $perPage,
        bool $reportedOnly = false,
    ): array {
        $where = 'r.tenant_id = :tenant AND r.path_id = :path AND r.deleted_at IS NULL';
        $params = ['tenant' => $tenantId, 'path' => $pathId];

        if ($status !== null) {
            $where .= ' AND r.moderation_status = :status';
            $params['status'] = $status->value;
        }

        if ($reportedOnly) {
            $where .= " AND EXISTS (
                            SELECT 1 FROM learning_path_review_votes v
                            WHERE v.review_id = r.id AND v.kind = 'report'
                        )";
        }

        return $this->page($where, $params, 'r.created_at DESC', $page, $perPage);
    }

    public function pending(string $tenantId, int $page, int $perPage): array
    {
        // Oldest first: a queue worked newest-first leaves the first review
        // anybody wrote at the bottom for ever.
        return $this->page(
            "r.tenant_id = :tenant AND r.moderation_status = 'pending' AND r.deleted_at IS NULL",
            ['tenant' => $tenantId],
            'r.created_at',
            $page,
            $perPage,
        );
    }

    public function find(string $tenantId, string $id): ?PathReview
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s FROM learning_path_reviews r %s
                 WHERE r.id = :id AND r.tenant_id = :tenant AND r.deleted_at IS NULL',
                self::COLUMNS,
                self::COUNTS,
                self::JOINS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function forEnrolment(string $tenantId, string $enrollmentId): ?PathReview
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s FROM learning_path_reviews r %s
                 WHERE r.enrollment_id = :enrolment AND r.tenant_id = :tenant
                   AND r.deleted_at IS NULL',
                self::COLUMNS,
                self::COUNTS,
                self::JOINS,
            ),
            ['enrolment' => $enrollmentId, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function save(PathReview $review): PathReview
    {
        $this->connection->executeStatement(
            "INSERT INTO learning_path_reviews
                 (id, tenant_id, path_id, user_id, enrollment_id, rating, title, review_text,
                  content_rating, difficulty_rating, practical_value_rating)
             VALUES (:id, :tenant, :path, :user, :enrolment, :rating, :title, :text,
                     :content, :difficulty, :practical)
             ON CONFLICT (id) DO UPDATE SET
                 rating       = EXCLUDED.rating,
                 title        = EXCLUDED.title,
                 review_text  = EXCLUDED.review_text,
                 content_rating         = EXCLUDED.content_rating,
                 difficulty_rating      = EXCLUDED.difficulty_rating,
                 practical_value_rating = EXCLUDED.practical_value_rating,
                 -- Editing sends it back to be checked and takes it off the
                 -- page: the words a moderator approved are not the words
                 -- that would be shown afterwards.
                 moderation_status = 'pending',
                 is_published      = FALSE,
                 moderated_by      = NULL,
                 moderated_at      = NULL,
                 rejection_reason  = NULL,
                 updated_at        = NOW()",
            [
                'id'         => $review->id,
                'tenant'     => $review->tenantId,
                'path'       => $review->pathId,
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

        return $this->find($review->tenantId, $review->id) ?? $review;
    }

    public function delete(string $tenantId, string $id, string $byUserId, bool $isModerator): bool
    {
        // A learner may withdraw their own; a moderator may take down
        // anybody's. Soft either way, so a report about it still resolves.
        $where = 'id = :id AND tenant_id = :tenant AND deleted_at IS NULL';
        $params = ['id' => $id, 'tenant' => $tenantId];

        if (!$isModerator) {
            $where .= ' AND user_id = :user';
            $params['user'] = $byUserId;
        }

        return $this->connection->executeStatement(
            sprintf('UPDATE learning_path_reviews SET deleted_at = NOW() WHERE %s', $where),
            $params,
        ) > 0;
    }

    // --- moderation -----------------------------------------------------------

    public function approve(string $tenantId, string $id, string $moderatorId): ?PathReview
    {
        // Approved and published together: approving something and leaving it
        // hidden is a state nobody means to create.
        $affected = $this->connection->executeStatement(
            "UPDATE learning_path_reviews
             SET moderation_status = 'approved', is_published = TRUE,
                 moderated_by = :by, moderated_at = NOW(), rejection_reason = NULL,
                 updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL",
            ['id' => $id, 'tenant' => $tenantId, 'by' => $moderatorId],
        );

        return $affected > 0 ? $this->find($tenantId, $id) : null;
    }

    public function reject(
        string $tenantId,
        string $id,
        string $moderatorId,
        string $reason,
    ): ?PathReview {
        $affected = $this->connection->executeStatement(
            "UPDATE learning_path_reviews
             SET moderation_status = 'rejected', is_published = FALSE,
                 moderated_by = :by, moderated_at = NOW(), rejection_reason = :reason,
                 updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL",
            ['id' => $id, 'tenant' => $tenantId, 'by' => $moderatorId, 'reason' => $reason],
        );

        return $affected > 0 ? $this->find($tenantId, $id) : null;
    }

    public function respond(string $tenantId, string $id, string $byUserId, string $text): ?PathReview
    {
        $affected = $this->connection->executeStatement(
            'UPDATE learning_path_reviews
             SET response_text = :text, response_by = :by, response_at = NOW(), updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId, 'by' => $byUserId, 'text' => $text],
        );

        return $affected > 0 ? $this->find($tenantId, $id) : null;
    }

    // --- votes ----------------------------------------------------------------

    public function toggleHelpful(string $tenantId, string $id, string $userId): int
    {
        $removed = $this->connection->executeStatement(
            "DELETE FROM learning_path_review_votes
             WHERE review_id = :review AND user_id = :user AND kind = 'helpful'
               AND tenant_id = :tenant",
            ['review' => $id, 'user' => $userId, 'tenant' => $tenantId],
        );

        if ($removed === 0) {
            $this->connection->executeStatement(
                "INSERT INTO learning_path_review_votes (review_id, user_id, tenant_id, kind)
                 VALUES (:review, :user, :tenant, 'helpful')
                 ON CONFLICT (review_id, user_id, kind) DO NOTHING",
                ['review' => $id, 'user' => $userId, 'tenant' => $tenantId],
            );
        }

        return $this->countVotes($tenantId, $id, 'helpful');
    }

    public function report(string $tenantId, string $id, string $userId, ?string $reason): int
    {
        // Not a toggle: reporting something twice is not un-reporting it.
        $this->connection->executeStatement(
            "INSERT INTO learning_path_review_votes (review_id, user_id, tenant_id, kind, reason)
             VALUES (:review, :user, :tenant, 'report', :reason)
             ON CONFLICT (review_id, user_id, kind) DO UPDATE SET reason = EXCLUDED.reason",
            ['review' => $id, 'user' => $userId, 'tenant' => $tenantId, 'reason' => $reason],
        );

        $reports = $this->countVotes($tenantId, $id, 'report');

        // A reported review goes back into the queue and off the page while
        // somebody looks at it. Leaving it up until a moderator happens to
        // notice is what reporting is meant to prevent.
        $this->connection->executeStatement(
            "UPDATE learning_path_reviews
             SET moderation_status = 'pending', is_published = FALSE, updated_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND is_published",
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $reports;
    }

    // --- what a path is rated -------------------------------------------------

    public function summaryFor(string $tenantId, string $pathId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS total,
                    AVG(rating)::numeric(3,2) AS average,
                    AVG(content_rating)::numeric(3,2) AS average_content,
                    AVG(difficulty_rating)::numeric(3,2) AS average_difficulty,
                    AVG(practical_value_rating)::numeric(3,2) AS average_practical,
                    COUNT(*) FILTER (WHERE rating = 5) AS five,
                    COUNT(*) FILTER (WHERE rating = 4) AS four,
                    COUNT(*) FILTER (WHERE rating = 3) AS three,
                    COUNT(*) FILTER (WHERE rating = 2) AS two,
                    COUNT(*) FILTER (WHERE rating = 1) AS one
             FROM learning_path_reviews
             WHERE tenant_id = :tenant AND path_id = :path
               AND is_published AND deleted_at IS NULL',
            ['tenant' => $tenantId, 'path' => $pathId],
        );

        $row = $row === false ? [] : $row;

        return [
            'total' => (int) ($row['total'] ?? 0),
            // Null rather than zero when nobody has reviewed it: "unrated" and
            // "rated nought" are different things, and a zero would sort a new
            // path below a bad one.
            'average'           => $this->score($row['average'] ?? null),
            'averageContent'    => $this->score($row['average_content'] ?? null),
            'averageDifficulty' => $this->score($row['average_difficulty'] ?? null),
            'averagePractical'  => $this->score($row['average_practical'] ?? null),
            'distribution' => [
                5 => (int) ($row['five'] ?? 0),
                4 => (int) ($row['four'] ?? 0),
                3 => (int) ($row['three'] ?? 0),
                2 => (int) ($row['two'] ?? 0),
                1 => (int) ($row['one'] ?? 0),
            ],
        ];
    }

    // --- internals ------------------------------------------------------------

    /**
     * @param array<string, mixed> $params
     *
     * @return array{items: list<PathReview>, total: int}
     */
    private function page(string $where, array $params, string $order, int $page, int $perPage): array
    {
        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM learning_path_reviews r WHERE %s', $where),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s FROM learning_path_reviews r %s
                 WHERE %s ORDER BY %s LIMIT :limit OFFSET :offset',
                self::COLUMNS,
                self::COUNTS,
                self::JOINS,
                $where,
                $order,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return [
            'items' => array_map(fn (array $r): PathReview => $this->hydrate($r), $rows),
            'total' => $total,
        ];
    }

    private function countVotes(string $tenantId, string $reviewId, string $kind): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM learning_path_review_votes
             WHERE review_id = :review AND kind = :kind AND tenant_id = :tenant',
            ['review' => $reviewId, 'kind' => $kind, 'tenant' => $tenantId],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PathReview
    {
        $name = is_string($row['author_name'] ?? null) ? trim($row['author_name']) : '';

        return new PathReview(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            pathId: (string) $row['path_id'],
            userId: (string) $row['user_id'],
            enrollmentId: (string) $row['enrollment_id'],
            rating: (int) $row['rating'],
            title: $this->text($row['title']),
            reviewText: $this->text($row['review_text']),
            contentRating: $row['content_rating'] === null ? null : (int) $row['content_rating'],
            difficultyRating: $row['difficulty_rating'] === null ? null : (int) $row['difficulty_rating'],
            practicalValueRating: $row['practical_value_rating'] === null
                ? null
                : (int) $row['practical_value_rating'],
            moderationStatus: ModerationStatus::from((string) $row['moderation_status']),
            moderatedBy: $this->text($row['moderated_by']),
            moderatedAt: $this->at($row['moderated_at']),
            rejectionReason: $this->text($row['rejection_reason']),
            isPublished: (bool) $row['is_published'],
            responseText: $this->text($row['response_text']),
            responseBy: $this->text($row['response_by']),
            responseAt: $this->at($row['response_at']),
            createdAt: $this->at($row['created_at']),
            updatedAt: $this->at($row['updated_at']),
            authorName: $name === '' ? null : $name,
            authorAvatarUrl: $this->text($row['author_avatar_url'] ?? null),
            pathTitle: $this->text($row['path_title'] ?? null),
            helpfulCount: (int) ($row['helpful_count'] ?? 0),
            reportedCount: (int) ($row['reported_count'] ?? 0),
        );
    }

    /** An average, or null when nobody scored that dimension. */
    private function score(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function at(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) ? new \DateTimeImmutable($value) : null;
    }
}
