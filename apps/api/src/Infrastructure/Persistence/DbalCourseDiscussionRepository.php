<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Catalog\CourseDiscussionRepository;
use Doctrine\DBAL\Connection;

final class DbalCourseDiscussionRepository implements CourseDiscussionRepository
{
    private const COLUMNS = "p.id, p.course_id, p.parent_id, p.lesson_id, p.body,
                             p.user_id, p.created_at, p.edited_at,
                             p.is_answer, p.is_pinned, p.is_locked, p.is_hidden,
                             TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS author_name,
                             u.avatar_url AS author_avatar,
                             (p.user_id = c.instructor_id OR p.user_id = c.created_by) AS is_instructor,
                             (
                                 SELECT COUNT(*) FROM discussion_reports rep
                                 WHERE rep.post_id = p.id AND rep.resolved_at IS NULL
                             ) AS report_count,
                             (
                                 SELECT COALESCE(jsonb_object_agg(k.kind, k.cnt), '{}'::jsonb)
                                 FROM (
                                     SELECT kind, COUNT(*) AS cnt FROM discussion_reactions r
                                     WHERE r.post_id = p.id GROUP BY kind
                                 ) k
                             ) AS reactions,
                             (
                                 SELECT COALESCE(jsonb_agg(r.kind), '[]'::jsonb)
                                 FROM discussion_reactions r
                                 WHERE r.post_id = p.id AND r.user_id = :viewer
                             ) AS my_reactions";

    private const JOINS = 'JOIN users u ON u.id = p.user_id
                           JOIN courses c ON c.id = p.course_id';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function thread(string $tenantId, string $courseId, string $viewerId, bool $isModerator): array
    {
        $params = ['tenant' => $tenantId, 'course' => $courseId, 'viewer' => $viewerId];

        $posts = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT %s FROM course_discussion_posts p %s
                 WHERE p.tenant_id = :tenant AND p.course_id = :course
                   AND p.parent_id IS NULL AND p.deleted_at IS NULL
                 ORDER BY p.is_pinned DESC, p.created_at DESC",
                self::COLUMNS,
                self::JOINS,
            ),
            $params,
        );

        if ($posts === []) {
            return [];
        }

        $replies = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT %s FROM course_discussion_posts p %s
                 WHERE p.tenant_id = :tenant AND p.course_id = :course
                   AND p.parent_id IS NOT NULL AND p.deleted_at IS NULL
                 ORDER BY p.is_answer DESC, p.created_at",
                self::COLUMNS,
                self::JOINS,
            ),
            $params,
        );

        $byParent = [];

        foreach ($replies as $reply) {
            $byParent[(string) $reply['parent_id']][] = $this->hydrate($reply, $isModerator);
        }

        return array_map(function (array $post) use ($byParent, $isModerator): array {
            $replies  = $byParent[(string) $post['id']] ?? [];
            $answered = false;

            foreach ($replies as $r) {
                if ($r['isAnswer'] === true) {
                    $answered = true;
                    break;
                }
            }

            return [...$this->hydrate($post, $isModerator), 'replies' => $replies, 'answered' => $answered];
        }, $posts);
    }

    public function post(
        string $tenantId,
        string $courseId,
        string $userId,
        string $body,
        ?string $parentId,
        ?string $lessonId,
    ): array {
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

        return $this->one($tenantId, $id, $userId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function edit(string $tenantId, string $postId, string $userId, string $body, bool $isModerator): ?array
    {
        $where  = 'id = :id AND tenant_id = :tenant AND deleted_at IS NULL';
        $params = ['id' => $postId, 'tenant' => $tenantId, 'body' => $body];

        if (!$isModerator) {
            $where .= ' AND user_id = :user';
            $params['user'] = $userId;
        }

        $changed = $this->connection->executeStatement(
            sprintf('UPDATE course_discussion_posts SET body = :body, edited_at = NOW() WHERE %s', $where),
            $params,
        );

        return $changed > 0 ? $this->one($tenantId, $postId, $userId) : null;
    }

    public function delete(string $tenantId, string $id, string $byUserId, bool $isModerator): bool
    {
        $where  = 'id = :id AND tenant_id = :tenant AND deleted_at IS NULL';
        $params = ['id' => $id, 'tenant' => $tenantId];

        if (!$isModerator) {
            $where .= ' AND user_id = :user';
            $params['user'] = $byUserId;
        }

        return $this->connection->executeStatement(
            sprintf(
                'UPDATE course_discussion_posts SET deleted_at = NOW()
                 WHERE (%s) OR (parent_id = :id AND tenant_id = :tenant AND deleted_at IS NULL)',
                $where,
            ),
            $params,
        ) > 0;
    }

    public function react(string $tenantId, string $postId, string $userId, string $kind): bool
    {
        // Toggle: delete the row if it exists, otherwise insert it.
        $removed = $this->connection->executeStatement(
            'DELETE FROM discussion_reactions
             WHERE post_id = :post AND user_id = :user AND kind = :kind AND tenant_id = :tenant',
            ['post' => $postId, 'user' => $userId, 'kind' => $kind, 'tenant' => $tenantId],
        );

        if ($removed > 0) {
            return false;
        }

        // Only onto a post that exists in this tenant.
        $this->connection->executeStatement(
            'INSERT INTO discussion_reactions (post_id, user_id, tenant_id, kind)
             SELECT :post, :user, :tenant, :kind
             FROM course_discussion_posts p
             WHERE p.id = :post AND p.tenant_id = :tenant AND p.deleted_at IS NULL
             ON CONFLICT DO NOTHING',
            ['post' => $postId, 'user' => $userId, 'tenant' => $tenantId, 'kind' => $kind],
        );

        return true;
    }

    public function setAnswer(string $tenantId, string $replyId, string $userId, bool $isModerator): bool
    {
        $reply = $this->connection->fetchAssociative(
            'SELECT parent_id, is_answer FROM course_discussion_posts
             WHERE id = :id AND tenant_id = :tenant AND parent_id IS NOT NULL AND deleted_at IS NULL',
            ['id' => $replyId, 'tenant' => $tenantId],
        );

        if ($reply === false || $reply['parent_id'] === null) {
            return false;
        }

        $parentId = (string) $reply['parent_id'];

        if (!$isModerator) {
            // Only the question's author may accept an answer.
            $askerId = $this->connection->fetchOne(
                'SELECT user_id FROM course_discussion_posts WHERE id = :id AND tenant_id = :tenant',
                ['id' => $parentId, 'tenant' => $tenantId],
            );

            if ($askerId !== $userId) {
                return false;
            }
        }

        $setting = !(bool) $reply['is_answer'];

        // One answer per thread: clear the siblings first, then set (or leave
        // cleared when toggling off).
        $this->connection->executeStatement(
            'UPDATE course_discussion_posts SET is_answer = FALSE
             WHERE parent_id = :parent AND tenant_id = :tenant',
            ['parent' => $parentId, 'tenant' => $tenantId],
        );

        if ($setting) {
            $this->connection->executeStatement(
                'UPDATE course_discussion_posts SET is_answer = TRUE
                 WHERE id = :id AND tenant_id = :tenant',
                ['id' => $replyId, 'tenant' => $tenantId],
            );
        }

        return true;
    }

    public function setPinned(string $tenantId, string $postId, bool $pinned): bool
    {
        return $this->setFlag($tenantId, $postId, 'is_pinned', $pinned);
    }

    public function setLocked(string $tenantId, string $postId, bool $locked): bool
    {
        return $this->setFlag($tenantId, $postId, 'is_locked', $locked);
    }

    public function report(string $tenantId, string $postId, string $reporterId, string $reason): bool
    {
        // Only onto a post that actually exists in this tenant; a second report
        // by the same person updates the reason and re-opens it.
        return $this->connection->executeStatement(
            "INSERT INTO discussion_reports (post_id, tenant_id, reporter_id, reason)
             SELECT :post, :tenant, :reporter, :reason
             FROM course_discussion_posts p
             WHERE p.id = :post AND p.tenant_id = :tenant AND p.deleted_at IS NULL
             ON CONFLICT (post_id, reporter_id)
             DO UPDATE SET reason = EXCLUDED.reason, resolved_at = NULL, created_at = NOW()",
            ['post' => $postId, 'tenant' => $tenantId, 'reporter' => $reporterId, 'reason' => $reason],
        ) > 0;
    }

    public function setHidden(string $tenantId, string $postId, bool $hidden): bool
    {
        // Hiding applies to any post, reply included, so it is not the
        // top-level-only setFlag used by pin and lock.
        $changed = $this->connection->executeStatement(
            'UPDATE course_discussion_posts SET is_hidden = :value
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['value' => $hidden ? 'true' : 'false', 'id' => $postId, 'tenant' => $tenantId],
        );

        // Hiding settles the complaint that prompted it.
        if ($changed > 0 && $hidden) {
            $this->dismissReports($tenantId, $postId);
        }

        return $changed > 0;
    }

    public function dismissReports(string $tenantId, string $postId): bool
    {
        return $this->connection->executeStatement(
            'UPDATE discussion_reports SET resolved_at = NOW()
             WHERE post_id = :post AND tenant_id = :tenant AND resolved_at IS NULL',
            ['post' => $postId, 'tenant' => $tenantId],
        ) > 0;
    }

    public function isLocked(string $tenantId, string $parentId): bool
    {
        // The lock lives on the top-level post; a reply's parent chain is one
        // deep, so COALESCE(parent_id, id) is the thread root.
        return (bool) $this->connection->fetchOne(
            'SELECT is_locked FROM course_discussion_posts
             WHERE id = (
                 SELECT COALESCE(parent_id, id) FROM course_discussion_posts
                 WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL
             ) AND tenant_id = :tenant',
            ['id' => $parentId, 'tenant' => $tenantId],
        );
    }

    public function countFor(string $tenantId, string $courseId): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM course_discussion_posts
             WHERE tenant_id = :tenant AND course_id = :course AND deleted_at IS NULL',
            ['tenant' => $tenantId, 'course' => $courseId],
        );
    }

    // --- internals ------------------------------------------------------------

    private function setFlag(string $tenantId, string $postId, string $column, bool $value): bool
    {
        // Pin/lock are thread-level, so they only apply to top-level posts.
        return $this->connection->executeStatement(
            sprintf(
                'UPDATE course_discussion_posts SET %s = :value
                 WHERE id = :id AND tenant_id = :tenant AND parent_id IS NULL AND deleted_at IS NULL',
                $column,
            ),
            ['value' => $value ? 'true' : 'false', 'id' => $postId, 'tenant' => $tenantId],
        ) > 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function one(string $tenantId, string $id, string $viewerId): array
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM course_discussion_posts p %s WHERE p.id = :id AND p.tenant_id = :tenant',
                self::COLUMNS,
                self::JOINS,
            ),
            ['id' => $id, 'tenant' => $tenantId, 'viewer' => $viewerId],
        );

        return $row === false ? [] : [...$this->hydrate($row, true), 'replies' => [], 'answered' => false];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row, bool $isModerator): array
    {
        $name = is_string($row['author_name'] ?? null) ? trim($row['author_name']) : '';

        /** @var array<string, int> $reactions */
        $reactions = json_decode((string) ($row['reactions'] ?? '{}'), true);
        /** @var list<string> $mine */
        $mine = json_decode((string) ($row['my_reactions'] ?? '[]'), true);

        $hidden      = (bool) ($row['is_hidden'] ?? false);
        // A hidden post keeps its place in the thread, but only a moderator
        // reads what it said; to everyone else it is a removed-post shell.
        $showBody    = $isModerator || !$hidden;
        $reportCount = $isModerator ? (int) ($row['report_count'] ?? 0) : 0;

        return [
            'id'          => (string) ($row['id'] ?? ''),
            'body'        => $showBody ? (string) ($row['body'] ?? '') : '',
            'lessonId'    => $row['lesson_id'] === null ? null : (string) $row['lesson_id'],
            'authorId'    => (string) ($row['user_id'] ?? ''),
            'authorName'  => $name === '' ? 'Someone' : $name,
            'authorAvatar' => is_string($row['author_avatar'] ?? null) && $row['author_avatar'] !== ''
                ? $row['author_avatar']
                : null,
            'isInstructor' => (bool) ($row['is_instructor'] ?? false),
            'isAnswer'     => (bool) ($row['is_answer'] ?? false),
            'isPinned'     => (bool) ($row['is_pinned'] ?? false),
            'isLocked'     => (bool) ($row['is_locked'] ?? false),
            'isHidden'     => $hidden,
            'reportCount'  => $reportCount,
            'reactions'    => is_array($reactions) ? (object) $reactions : (object) [],
            'myReactions'  => is_array($mine) ? array_values($mine) : [],
            'createdAt'    => (string) ($row['created_at'] ?? ''),
            'editedAt'     => $row['edited_at'] === null ? null : (string) ($row['edited_at'] ?? ''),
        ];
    }
}
