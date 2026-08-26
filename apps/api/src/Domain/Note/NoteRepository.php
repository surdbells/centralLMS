<?php

declare(strict_types=1);

namespace Central\Api\Domain\Note;

/**
 * Storage for learner notes.
 *
 * Every method takes both a tenant and the person whose notes these are, and
 * there is no method that fetches somebody else's. The legacy's eleven
 * endpoints each took `user_id` from the request body and passed it through —
 * with `verify_user()` returning 100 unconditionally, that is a note-reading
 * API for the whole organisation.
 */
interface NoteRepository
{
    /**
     * @return array{items: list<Note>, total: int}
     */
    public function paginate(string $tenantId, string $userId, NoteFilters $filters, int $page, int $perPage): array;

    public function find(string $tenantId, string $userId, string $id): ?Note;

    /**
     * Whether the lesson exists, is in this tenant, and the learner is on the
     * course it belongs to.
     *
     * A note against a lesson somebody cannot open is a way of asking whether
     * a lesson id exists, one guess at a time.
     *
     * @return string|null the lesson's course id, or null if not reachable
     */
    public function courseForReachableLesson(string $tenantId, string $userId, string $lessonId): ?string;

    public function create(Note $note): Note;

    /**
     * Edits the text, and whichever of title, position and pin the request
     * actually mentioned. Never the lesson.
     *
     * Moving a note to a different lesson is deleting it and writing another;
     * allowing it means an edit has to re-check reachability, which is a check
     * that will eventually be forgotten.
     */
    public function update(string $tenantId, string $userId, string $id, NoteInput $input): ?Note;

    /**
     * Sets the pin, and returns what it now is.
     *
     * Explicit rather than `SET is_pinned = NOT is_pinned`, which is what the
     * legacy did: two taps racing each other leave the pin in whichever state
     * the second write happened to compute, and neither client is told.
     */
    public function setPinned(string $tenantId, string $userId, string $id, bool $pinned): ?Note;

    public function softDelete(string $tenantId, string $userId, string $id): bool;

    /**
     * How many notes this person has, and how many are pinned.
     *
     * @return array{total: int, pinned: int, courses: int}
     */
    public function statistics(string $tenantId, string $userId): array;
}
