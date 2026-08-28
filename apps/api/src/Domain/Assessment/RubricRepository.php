<?php

declare(strict_types=1);

namespace Central\Api\Domain\Assessment;

interface RubricRepository
{
    /**
     * Every rubric in a tenant, criteria and bands included.
     *
     * Whole rather than shallow: a rubric without its criteria is a name, and
     * every screen that lists them also shows what they are worth. Fetching
     * the criteria per row afterwards is one query per rubric on a page.
     *
     * @return list<Rubric>
     */
    public function all(string $tenantId, bool $activeOnly = false): array;

    public function find(string $tenantId, string $id): ?Rubric;

    /** Saves the rubric, its criteria and their bands as one transaction. */
    public function save(Rubric $rubric): Rubric;

    /**
     * Replaces a rubric's criteria wholesale.
     *
     * Not a per-criterion API: a rubric edited criterion by criterion can be
     * observed mid-edit totalling something nobody intended, and a marker
     * opening it at that moment marks against it.
     *
     * @param list<RubricCriterion> $criteria
     */
    public function replaceCriteria(string $tenantId, string $rubricId, array $criteria): Rubric;

    /** Soft, so the marks already given against it keep their meaning. */
    public function delete(string $tenantId, string $id): bool;

    /**
     * A copy, ready to be edited.
     *
     * The usual reason to want one: an existing rubric is nearly right for a
     * new assessment.
     */
    public function duplicate(string $tenantId, string $id, string $newName, ?string $by): ?Rubric;

    /** How many questions are marked against each rubric, keyed by rubric id. */
    /** @return array<string, int> */
    public function questionCounts(string $tenantId): array;

    // --- what a marker chose ------------------------------------------------

    /**
     * Records a marker's judgement against one answer.
     *
     * Replaces whatever was there for the same criterion: a second judgement
     * on the same thing is a correction, not an addition.
     *
     * @param list<array{criterionId: string, levelId: ?string, points: float, comment: ?string}> $choices
     */
    public function recordChoices(
        string $tenantId,
        string $answerId,
        array $choices,
        ?string $gradedBy,
    ): void;

    /**
     * What a marker chose on one answer.
     *
     * @return list<array<string, mixed>>
     */
    public function choicesFor(string $tenantId, string $answerId): array;
}
