<?php

declare(strict_types=1);

namespace Central\Api\Domain\Catalog;

interface CourseDiscussionRepository
{
    /**
     * The thread on one course: top-level posts newest first, each with its
     * replies oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function thread(string $tenantId, string $courseId): array;

    /**
     * Adds a post, or a reply when a parent is named.
     *
     * A reply's parent must be a top-level post on the same course; replying
     * to a reply attaches to that reply's post instead, so the thread never
     * nests deeper than one level.
     *
     * @return array<string, mixed> the post as the thread lists it
     */
    public function post(
        string $tenantId,
        string $courseId,
        string $userId,
        string $body,
        ?string $parentId,
        ?string $lessonId,
    ): array;

    /**
     * Removes a post. A learner may remove their own; a moderator anybody's.
     *
     * Soft, and it takes the replies with it — a reply to a deleted question
     * is an answer to nothing.
     */
    public function delete(string $tenantId, string $id, string $byUserId, bool $isModerator): bool;

    public function countFor(string $tenantId, string $courseId): int;
}
