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

    /**
     * Records the evidence text on some items — the employee's account of each
     * competency, shown as the report's Evidence column.
     *
     * @param array<string, ?string> $comments competency id => evidence text
     */
    public function recordComments(string $tenantId, string $assessmentId, array $comments): ?CompetencyAssessment;

    public function setStatus(
        string $tenantId,
        string $assessmentId,
        AssessmentStatus $status,
        ?string $assessorId,
        ?string $assessorComment,
    ): ?CompetencyAssessment;

    /**
     * Stores the narrative fields of an individual's report. Only the keys
     * present are written, each replacing what was there; the rest are left.
     *
     * @param array<string, ?string> $fields any of self_comment,
     *        line_manager_comment, line_executive_decision, gaps_identified,
     *        gaps_closure_interventions
     */
    public function saveNarrative(string $tenantId, string $assessmentId, array $fields): ?CompetencyAssessment;

    public function deleteAssessment(string $tenantId, string $id): bool;

    /**
     * The header facts about the person an individual report is for.
     *
     * @return array{name: ?string, email: ?string, jobTitle: ?string, level: ?string, department: ?string, division: ?string}
     */
    public function reportSubject(string $tenantId, string $userId): array;

    /**
     * The level descriptors for some competencies, keyed by competency id.
     *
     * @param  list<string> $competencyIds
     * @return array<string, array{knowledge: ?string, skill: ?string, mastery: ?string}>
     */
    public function descriptorsFor(string $tenantId, array $competencyIds): array;

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

    // --- the analytical reports -----------------------------------------------

    /**
     * Readiness for one cycle broken down by an org or competency axis.
     *
     * `$dimension` is one of department, branch, team, level, job_title
     * (org axes joined through the user) or 'function' (the competency's own
     * axis). Only finalised assessments count, and the stored gap decides the
     * buckets, so each row agrees with the outcome headline.
     *
     * @return list<array{label: string, people: int, requirements: int, met: int, minor: int, major: int, significant: int, gaps: int, readiness: ?float, estimatedMonths: int}>
     */
    public function readinessByDimension(string $tenantId, string $cycle, string $dimension): array;

    /**
     * Every competency measured in the cycle, with how it is held across the
     * organisation — the development-priorities table, widest average gap first.
     *
     * @return list<array{competency: string, function: string, assessed: int, withGap: int, met: int, minor: int, major: int, significant: int, critical: int, avgGap: float, avgRequired: float, estimatedMonths: int}>
     */
    public function gapAnalysis(string $tenantId, string $cycle): array;

    /**
     * Each person still short on a competency their job marks critical — the
     * list a placement decision cannot ignore. One row per person-competency.
     *
     * @return list<array{assessmentId: string, userName: string, jobTitle: ?string, department: ?string, competency: string, function: string, requiredLevel: int, demonstratedLevel: int, gap: int, gapLabel: string, estimatedMonths: int}>
     */
    public function criticalGaps(string $tenantId, string $cycle): array;

    /**
     * How the demonstrated levels are spread against what the jobs require —
     * overall and by function. Reads across every finalised item.
     *
     * @return array{demonstrated: array{none: int, knowledge: int, skill: int, mastery: int, total: int}, required: array{none: int, knowledge: int, skill: int, mastery: int, total: int}, byFunction: list<array{label: string, none: int, knowledge: int, skill: int, mastery: int, total: int}>}
     */
    public function proficiencyDistribution(string $tenantId, string $cycle): array;

    /**
     * How far through the exercise the cycle is — assessments by status, overall
     * and by department. Counts every assessment, not only the finalised ones.
     *
     * @return array{overall: array{total: int, people: int, draft: int, selfAssessed: int, reviewed: int, finalized: int, completion: ?float}, byDepartment: list<array{label: string, total: int, people: int, draft: int, selfAssessed: int, reviewed: int, finalized: int, completion: ?float}>}
     */
    public function completion(string $tenantId, string $cycle): array;
}
