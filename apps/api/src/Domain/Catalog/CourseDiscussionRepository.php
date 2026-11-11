<?php

declare(strict_types=1);

namespace Central\Api\Domain\Catalog;

interface CourseDiscussionRepository
{
    public const REACTIONS = ['up', 'heart', 'celebrate', 'insightful'];

    /**
     * The thread on one course: pinned posts first then newest, each with its
     * replies oldest first, reaction counts, the viewer's own reactions, and
     * the answer/pin/lock/edited/instructor/hidden flags.
     *
     * A moderator sees hidden bodies and open-report counts; everyone else
     * sees a hidden post as a placeholder with no body and no report counts.
     *
     * @return list<array<string, mixed>>
     */
    public function thread(string $tenantId, string $courseId, string $viewerId, bool $isModerator): array;

    /**
     * Adds a post, or a reply when a parent is named. Replies never nest deeper
     * than one level.
     *
     * @return array<string, mixed>
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
     * Edits a post's body (own, or a moderator's), stamping edited_at.
     *
     * @return array<string, mixed>|null
     */
    public function edit(string $tenantId, string $postId, string $userId, string $body, bool $isModerator): ?array;

    /**
     * Removes a post (own, or a moderator's); replies go with a removed post.
     */
    public function delete(string $tenantId, string $id, string $byUserId, bool $isModerator): bool;

    /** Toggles one of the caller's reactions on a post; returns whether it is now set. */
    public function react(string $tenantId, string $postId, string $userId, string $kind): bool;

    /**
     * Marks a reply as its thread's accepted answer, or clears it — allowed to
     * the question's author or a moderator. One answer per thread.
     */
    public function setAnswer(string $tenantId, string $replyId, string $userId, bool $isModerator): bool;

    /**
     * Records a report of a post by one of its readers, with a reason. Re-opens
     * an earlier report by the same person. Returns false if the post is gone.
     */
    public function report(string $tenantId, string $postId, string $reporterId, string $reason): bool;

    /** Hides or unhides any post (moderator); hiding also resolves its reports. */
    public function setHidden(string $tenantId, string $postId, bool $hidden): bool;

    /** Clears the open reports on a post without hiding it (moderator dismiss). */
    public function dismissReports(string $tenantId, string $postId): bool;

    /** Pins or unpins a top-level post (moderator). */
    public function setPinned(string $tenantId, string $postId, bool $pinned): bool;

    /** Locks or unlocks a thread against new replies (moderator). */
    public function setLocked(string $tenantId, string $postId, bool $locked): bool;

    /** Whether the thread a reply would attach to is locked. */
    public function isLocked(string $tenantId, string $parentId): bool;

    public function countFor(string $tenantId, string $courseId): int;
}
