<?php

declare(strict_types=1);

namespace Central\Api\Domain\Skill;

/**
 * Storage for the skills dictionary, and the queries that derive who holds
 * what.
 *
 * Every method takes a tenant id. `Skills::setTenant(int $tenant_id)` took one
 * from the request body — the same shape as the reporting and settings defects
 * (actions 0c and 0d), on a smaller surface.
 */
interface SkillRepository
{
    // --- the dictionary ---------------------------------------------------

    /**
     * @return array{items: list<Skill>, total: int}
     */
    public function paginate(string $tenantId, SkillFilters $filters, int $page, int $perPage): array;

    public function find(string $tenantId, string $id): ?Skill;

    public function nameExists(string $tenantId, string $name, ?string $exceptId = null): bool;

    public function save(Skill $skill): Skill;

    /**
     * Soft delete.
     *
     * Never hard: a course that teaches a skill points at it, and so does
     * every derived statement about who holds it.
     */
    public function softDelete(string $tenantId, string $id): bool;

    // --- categories -------------------------------------------------------

    /**
     * @return list<SkillCategory>
     */
    public function categories(string $tenantId, bool $activeOnly = false): array;

    public function findCategory(string $tenantId, string $id): ?SkillCategory;

    public function categoryNameExists(string $tenantId, string $name, ?string $exceptId = null): bool;

    public function saveCategory(SkillCategory $category): SkillCategory;

    /**
     * Soft delete. The skills filed under it keep existing, uncategorised.
     */
    public function deleteCategory(string $tenantId, string $id): bool;

    // --- what teaches what ------------------------------------------------

    /**
     * The skills something teaches.
     *
     * A course, a training programme or a learning path — the answer has the
     * same shape whichever it is, so there is one method rather than three
     * that would drift.
     *
     * @return list<array<string, mixed>>
     */
    public function forSubject(string $tenantId, SkillSubject $kind, string $subjectId): array;

    /**
     * Replaces what something teaches, wholesale.
     *
     * One transaction, and the whole set: this course teaches these three
     * things, not "these three plus whatever was there before". The legacy's
     * `assignSkillsToCourse()` deleted and re-inserted without one, so an
     * interrupted save left a course teaching nothing.
     *
     * @param  list<array{skillId: string, proficiency: string, isPrimary: bool, isRequired?: bool}> $skills
     * @return list<array<string, mixed>> what it now teaches
     */
    public function setSubjectSkills(
        string $tenantId,
        SkillSubject $kind,
        string $subjectId,
        array $skills,
    ): array;

    /**
     * Everything that teaches a skill, of any kind.
     *
     * @return list<array<string, mixed>>
     */
    public function subjectsTeaching(string $tenantId, string $skillId): array;

    // --- who holds what ---------------------------------------------------

    /**
     * Everything this person holds or is working towards.
     *
     * One query. `getLearnerSkills()` and `getLearnerSkillProgress()` were two
     * near-identical queries whose only difference was `status = 'completed'`,
     * and they disagreed about what counted.
     *
     * @param  bool $heldOnly whether to drop the ones still in progress
     * @return list<LearnerSkill>
     */
    public function forLearner(string $tenantId, string $userId, bool $heldOnly = false): array;

    /**
     * The people who hold a skill.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function learnersWith(string $tenantId, string $skillId, int $page, int $perPage): array;

    /**
     * Headline counts, and where the organisation is thin.
     *
     * @return array<string, mixed>
     */
    public function statistics(string $tenantId): array;
}
