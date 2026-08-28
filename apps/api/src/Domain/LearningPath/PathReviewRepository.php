<?php

declare(strict_types=1);

namespace Central\Api\Domain\LearningPath;

interface PathReviewRepository
{
    /**
     * The published reviews of one path, newest first.
     *
     * @return array{items: list<PathReview>, total: int}
     */
    public function published(string $tenantId, string $pathId, int $page, int $perPage): array;

    /**
     * Every review on one path whatever its state, newest first.
     *
     * For moderators only. `$status` narrows it to one state; null is all of
     * them, which is what a moderator means by "all reviews" — a filter that
     * silently omitted the rejected ones would hide the very thing they are
     * looking for.
     *
     * `$reportedOnly` is the other question a moderator asks, and it is not a
     * state: a reported review is sent back to `pending`, so "reported" and
     * "waiting" would otherwise be the same list.
     *
     * @return array{items: list<PathReview>, total: int}
     */
    public function forPath(
        string $tenantId,
        string $pathId,
        ?ModerationStatus $status,
        int $page,
        int $perPage,
        bool $reportedOnly = false,
    ): array;

    /**
     * Everything waiting to be checked, oldest first, across every path.
     *
     * Oldest first because a moderation queue worked newest-first leaves the
     * first review anybody wrote at the bottom for ever.
     *
     * @return array{items: list<PathReview>, total: int}
     */
    public function pending(string $tenantId, int $page, int $perPage): array;

    public function find(string $tenantId, string $id): ?PathReview;

    /** The one this person left for this enrolment, if any. */
    public function forEnrolment(string $tenantId, string $enrollmentId): ?PathReview;

    /**
     * Writes a review.
     *
     * Editing an approved one sends it back to `pending`: the words a
     * moderator approved are not the words that would be shown afterwards.
     */
    public function save(PathReview $review): PathReview;

    public function delete(string $tenantId, string $id, string $byUserId, bool $isModerator): bool;

    // --- moderation -----------------------------------------------------------

    /**
     * Approves it and publishes it in one step.
     *
     * Approving something and then leaving it unpublished is a state nobody
     * means to create, and the one a moderator would be surprised to find.
     */
    public function approve(string $tenantId, string $id, string $moderatorId): ?PathReview;

    public function reject(
        string $tenantId,
        string $id,
        string $moderatorId,
        string $reason,
    ): ?PathReview;

    /** The path's author answering, in public. */
    public function respond(string $tenantId, string $id, string $byUserId, string $text): ?PathReview;

    // --- votes ----------------------------------------------------------------

    /**
     * Marks a review helpful, or takes the mark back.
     *
     * A toggle, and a row rather than a counter: a counter cannot stop one
     * person voting forty times.
     */
    public function toggleHelpful(string $tenantId, string $id, string $userId): int;

    /**
     * Reports a review to the moderators.
     *
     * Not a toggle — reporting something twice is not un-reporting it — and
     * the reason is kept, because "who reported this and why" is the first
     * question a moderator asks.
     */
    public function report(string $tenantId, string $id, string $userId, ?string $reason): int;

    // --- what a path is rated -------------------------------------------------

    /**
     * The rating summary for one path, from its published reviews.
     *
     * Counted rather than stored on `learning_paths`: an average changes
     * whenever anybody reviews, un-reviews or is moderated, and a stored one
     * is wrong from the first of those.
     *
     * @return array<string, mixed>
     */
    public function summaryFor(string $tenantId, string $pathId): array;
}
