<?php

declare(strict_types=1);

namespace Central\Api\Domain\Competency;

interface CompetencyRepository
{
    // --- the dictionary -------------------------------------------------------

    /**
     * @return array{items: list<Competency>, total: int}
     */
    public function competencies(string $tenantId, ?string $function, ?string $search, int $page, int $perPage): array;

    public function findCompetency(string $tenantId, string $id): ?Competency;

    public function saveCompetency(Competency $competency): Competency;

    public function deleteCompetency(string $tenantId, string $id): bool;

    /**
     * The functions competencies are grouped under, with how many each holds.
     *
     * @return list<array{function: string, competencies: int, profiles: int}>
     */
    public function functions(string $tenantId): array;

    /**
     * Finds a competency by its natural key, for the importer to avoid
     * creating the same one twice across runs.
     */
    public function findCompetencyByName(string $tenantId, string $function, string $name): ?Competency;

    // --- job competency profiles ----------------------------------------------

    /**
     * @return array{items: list<JobCompetencyProfile>, total: int}
     */
    public function profiles(string $tenantId, ?string $function, ?string $search, int $page, int $perPage): array;

    /** With its requirements, each carrying the competency's name. */
    public function findProfile(string $tenantId, string $id): ?JobCompetencyProfile;

    public function findProfileByTitle(string $tenantId, string $function, string $jobTitle): ?JobCompetencyProfile;

    /** Saves the profile and replaces its requirements wholesale, in one go. */
    public function saveProfile(JobCompetencyProfile $profile): JobCompetencyProfile;

    public function deleteProfile(string $tenantId, string $id): bool;

    // --- assessments ----------------------------------------------------------

    /**
     * @return array{items: list<CompetencyAssessment>, total: int}
     */
    public function assessments(
        string $tenantId,
        ?string $cycle,
        ?string $function,
        ?string $status,
        ?string $search,
        int $page,
        int $perPage,
        ?string $userId = null,
    ): array;

    /** With its items, each carrying the competency's name and the gap. */
    public function findAssessment(string $tenantId, string $id): ?CompetencyAssessment;

    public function findAssessmentFor(string $tenantId, string $userId, string $profileId, string $cycle): ?CompetencyAssessment;

    /**
     * Starts an assessment for a person against a profile, seeding an item for
     * every competency the profile requires at the level it requires.
     */
    public function startAssessment(
        string $tenantId,
        string $userId,
        string $profileId,
        string $cycle,
    ): CompetencyAssessment;

    /**
     * Records the levels for some items.
     *
     * `$field` is 'self' for the learner's own assessment or 'assessed' for
     * the expert's, so one method serves both stages rather than two that
     * would drift.
     *
     * @param array<string, int> $levels competency id => level
     */
    public function recordLevels(string $tenantId, string $assessmentId, string $field, array $levels): ?CompetencyAssessment;

    public function setStatus(
        string $tenantId,
        string $assessmentId,
        AssessmentStatus $status,
        ?string $assessorId,
        ?string $assessorComment,
    ): ?CompetencyAssessment;

    public function deleteAssessment(string $tenantId, string $id): bool;

    // --- the outcome report ---------------------------------------------------

    /**
     * The organisation rolled up for one cycle: coverage, gaps by category,
     * and the functions furthest behind.
     *
     * @return array<string, mixed>
     */
    public function outcome(string $tenantId, string $cycle): array;

    /**
     * The distinct cycles that have any assessment, newest first.
     *
     * @return list<string>
     */
    public function cycles(string $tenantId): array;
}
