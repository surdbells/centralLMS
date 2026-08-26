<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Skill\LearnerSkill;
use Central\Api\Domain\Skill\Proficiency;
use Central\Api\Domain\Skill\Skill;
use Central\Api\Domain\Skill\SkillCategory;
use Central\Api\Domain\Skill\SkillFilters;
use Central\Api\Domain\Skill\SkillRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalSkillRepository implements SkillRepository
{
    private const COLUMNS = 's.id, s.tenant_id, s.category_id, s.name, s.description,
                             s.level, s.icon, s.colour, s.is_active, s.created_at';

    private const CATEGORY = 'c.name AS category_name, c.colour AS category_colour';

    /**
     * How many courses teach it, and how many people have finished one.
     *
     * Two correlated subqueries rather than the legacy's two extra round trips
     * per skill inside the listing loop.
     */
    private const COUNTS = "(
            SELECT COUNT(*) FROM course_skills cs
            JOIN courses co ON co.id = cs.course_id AND co.deleted_at IS NULL
            WHERE cs.skill_id = s.id
        ) AS course_count,
        (
            SELECT COUNT(DISTINCT e.user_id)
            FROM course_skills cs
            JOIN enrollments e ON e.course_id = cs.course_id
                              AND e.status = 'completed'
                              AND e.deleted_at IS NULL
            WHERE cs.skill_id = s.id
        ) AS learner_count";

    public function __construct(private readonly Connection $connection)
    {
    }

    // --- the dictionary ---------------------------------------------------

    public function paginate(string $tenantId, SkillFilters $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->conditions($tenantId, $filters);

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM skills s WHERE %s', $where),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s, %s
                 FROM skills s
                 LEFT JOIN skill_categories c ON c.id = s.category_id
                 WHERE %s
                 ORDER BY s.name, s.id
                 LIMIT :limit OFFSET :offset',
                self::COLUMNS,
                self::CATEGORY,
                self::COUNTS,
                $where,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $row): Skill => $this->hydrate($row), $rows),
            'total' => $total,
        ];
    }

    public function find(string $tenantId, string $id): ?Skill
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s, %s
                 FROM skills s
                 LEFT JOIN skill_categories c ON c.id = s.category_id
                 WHERE s.id = :id AND s.tenant_id = :tenant AND s.deleted_at IS NULL',
                self::COLUMNS,
                self::CATEGORY,
                self::COUNTS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function nameExists(string $tenantId, string $name, ?string $exceptId = null): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM skills
             WHERE tenant_id = :tenant
               AND lower(btrim(name)) = lower(btrim(:name))
               AND deleted_at IS NULL
               AND (:exceptId::uuid IS NULL OR id <> :exceptId::uuid)',
            ['tenant' => $tenantId, 'name' => $name, 'exceptId' => $exceptId],
        ) > 0;
    }

    public function save(Skill $skill): Skill
    {
        $parameters = [
            'tenant'      => $skill->tenantId,
            'category'    => $skill->categoryId,
            'name'        => $skill->name,
            'description' => $skill->description,
            'level'       => $skill->level->value,
            'icon'        => $skill->icon,
            'colour'      => $skill->colour,
            'active'      => $skill->isActive,
        ];

        $id = $skill->id === null
            ? (string) $this->connection->fetchOne(
                'INSERT INTO skills (tenant_id, category_id, name, description, level, icon, colour, is_active)
                 VALUES (:tenant, :category, :name, :description, :level, :icon, :colour, :active)
                 RETURNING id',
                $parameters,
                ['active' => ParameterType::BOOLEAN],
            )
            : $skill->id;

        if ($skill->id !== null) {
            $this->connection->executeStatement(
                'UPDATE skills SET
                     category_id = :category, name = :name, description = :description,
                     level = :level, icon = :icon, colour = :colour, is_active = :active,
                     updated_at = now()
                 WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                [...$parameters, 'id' => $id],
                ['active' => ParameterType::BOOLEAN],
            );
        }

        return $this->find($skill->tenantId, $id)
            ?? throw new \RuntimeException('The skill vanished immediately after being saved.');
    }

    public function softDelete(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE skills SET deleted_at = now(), updated_at = now()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    // --- categories -------------------------------------------------------

    public function categories(string $tenantId, bool $activeOnly = false): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT c.id, c.tenant_id, c.name, c.description, c.colour, c.is_active, c.created_at,
                        (
                            SELECT COUNT(*) FROM skills s
                            WHERE s.category_id = c.id AND s.deleted_at IS NULL
                        ) AS skill_count
                 FROM skill_categories c
                 WHERE c.tenant_id = :tenant AND c.deleted_at IS NULL %s
                 ORDER BY c.name, c.id',
                $activeOnly ? 'AND c.is_active' : '',
            ),
            ['tenant' => $tenantId],
        );

        return array_map(fn (array $row): SkillCategory => $this->hydrateCategory($row), $rows);
    }

    public function findCategory(string $tenantId, string $id): ?SkillCategory
    {
        $row = $this->connection->fetchAssociative(
            'SELECT c.id, c.tenant_id, c.name, c.description, c.colour, c.is_active, c.created_at,
                    (
                        SELECT COUNT(*) FROM skills s
                        WHERE s.category_id = c.id AND s.deleted_at IS NULL
                    ) AS skill_count
             FROM skill_categories c
             WHERE c.id = :id AND c.tenant_id = :tenant AND c.deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrateCategory($row);
    }

    public function categoryNameExists(string $tenantId, string $name, ?string $exceptId = null): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM skill_categories
             WHERE tenant_id = :tenant
               AND lower(btrim(name)) = lower(btrim(:name))
               AND deleted_at IS NULL
               AND (:exceptId::uuid IS NULL OR id <> :exceptId::uuid)',
            ['tenant' => $tenantId, 'name' => $name, 'exceptId' => $exceptId],
        ) > 0;
    }

    public function saveCategory(SkillCategory $category): SkillCategory
    {
        $parameters = [
            'tenant'      => $category->tenantId,
            'name'        => $category->name,
            'description' => $category->description,
            'colour'      => $category->colour,
            'active'      => $category->isActive,
        ];

        $id = $category->id ?? (string) $this->connection->fetchOne(
            'INSERT INTO skill_categories (tenant_id, name, description, colour, is_active)
             VALUES (:tenant, :name, :description, :colour, :active)
             RETURNING id',
            $parameters,
            ['active' => ParameterType::BOOLEAN],
        );

        if ($category->id !== null) {
            $this->connection->executeStatement(
                'UPDATE skill_categories SET
                     name = :name, description = :description, colour = :colour,
                     is_active = :active, updated_at = now()
                 WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                [...$parameters, 'id' => $id],
                ['active' => ParameterType::BOOLEAN],
            );
        }

        return $this->findCategory($category->tenantId, $id)
            ?? throw new \RuntimeException('The category vanished immediately after being saved.');
    }

    public function deleteCategory(string $tenantId, string $id): bool
    {
        // The skills filed under it survive, uncategorised — the foreign key
        // is ON DELETE SET NULL and the soft delete has to match. Removing a
        // grouping is not removing what was grouped.
        return $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $id): bool {
                $removed = $tx->executeStatement(
                    'UPDATE skill_categories SET deleted_at = now(), updated_at = now()
                     WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                    ['id' => $id, 'tenant' => $tenantId],
                );

                if ($removed === 0) {
                    return false;
                }

                $tx->executeStatement(
                    'UPDATE skills SET category_id = NULL, updated_at = now()
                     WHERE category_id = :id AND tenant_id = :tenant',
                    ['id' => $id, 'tenant' => $tenantId],
                );

                return true;
            },
        );
    }

    // --- what teaches what ------------------------------------------------

    public function forCourse(string $tenantId, string $courseId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT cs.skill_id, cs.proficiency, cs.is_primary,
                    s.name, s.icon, s.colour, s.description,
                    c.name AS category_name, c.colour AS category_colour
             FROM course_skills cs
             JOIN skills s ON s.id = cs.skill_id AND s.deleted_at IS NULL
             LEFT JOIN skill_categories c ON c.id = s.category_id
             WHERE cs.course_id = :course AND cs.tenant_id = :tenant
             ORDER BY cs.is_primary DESC, s.name',
            ['course' => $courseId, 'tenant' => $tenantId],
        );

        return array_map(
            static fn (array $r): array => [
                'skillId'        => (string) $r['skill_id'],
                'name'           => (string) $r['name'],
                'description'    => $r['description'] === null ? null : (string) $r['description'],
                'icon'           => $r['icon'] === null ? null : (string) $r['icon'],
                'colour'         => (string) $r['colour'],
                'categoryName'   => $r['category_name'] === null ? null : (string) $r['category_name'],
                'categoryColour' => $r['category_colour'] === null ? null : (string) $r['category_colour'],
                'proficiency'    => (string) $r['proficiency'],
                'isPrimary'      => (bool) $r['is_primary'],
            ],
            $rows,
        );
    }

    public function setCourseSkills(string $tenantId, string $courseId, array $skills): array
    {
        $this->connection->transactional(function (Connection $tx) use ($tenantId, $courseId, $skills): void {
            // The whole set, replaced. Deleting and re-inserting outside a
            // transaction — which is what the legacy did — leaves a course
            // teaching nothing if the second half fails.
            $tx->executeStatement(
                'DELETE FROM course_skills WHERE course_id = :course AND tenant_id = :tenant',
                ['course' => $courseId, 'tenant' => $tenantId],
            );

            foreach ($skills as $skill) {
                $tx->executeStatement(
                    'INSERT INTO course_skills (course_id, skill_id, tenant_id, proficiency, is_primary)
                     VALUES (:course, :skill, :tenant, :proficiency, :primary)',
                    [
                        'course'      => $courseId,
                        'skill'       => $skill['skillId'],
                        'tenant'      => $tenantId,
                        'proficiency' => $skill['proficiency'],
                        'primary'     => $skill['isPrimary'],
                    ],
                    ['primary' => ParameterType::BOOLEAN],
                );
            }
        });

        return $this->forCourse($tenantId, $courseId);
    }

    public function coursesTeaching(string $tenantId, string $skillId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT co.id, co.title, co.status, co.duration_minutes, co.thumbnail_url,
                    cs.proficiency, cs.is_primary,
                    (
                        SELECT COUNT(*) FROM enrollments e
                        WHERE e.course_id = co.id AND e.status = 'completed' AND e.deleted_at IS NULL
                    ) AS completions
             FROM course_skills cs
             JOIN courses co ON co.id = cs.course_id AND co.deleted_at IS NULL
             WHERE cs.skill_id = :skill AND cs.tenant_id = :tenant
             ORDER BY cs.is_primary DESC, co.title",
            ['skill' => $skillId, 'tenant' => $tenantId],
        );

        return array_map(
            static fn (array $r): array => [
                'courseId'        => (string) $r['id'],
                'title'           => (string) $r['title'],
                'status'          => (string) $r['status'],
                'durationMinutes' => $r['duration_minutes'] === null ? null : (int) $r['duration_minutes'],
                'thumbnailUrl'    => $r['thumbnail_url'] === null ? null : (string) $r['thumbnail_url'],
                'proficiency'     => (string) $r['proficiency'],
                'isPrimary'       => (bool) $r['is_primary'],
                'completions'     => (int) $r['completions'],
            ],
            $rows,
        );
    }

    // --- who holds what ---------------------------------------------------

    public function forLearner(string $tenantId, string $userId, bool $heldOnly = false): array
    {
        // One query where the legacy had two that disagreed: `getLearnerSkills`
        // counted completed courses and `getLearnerSkillProgress` counted
        // enrolled ones, and each decided the proficiency separately.
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT s.id, s.name, s.description, s.icon, s.colour,
                        s.category_id, c.name AS category_name, c.colour AS category_colour,
                        COUNT(*) FILTER (WHERE e.status = 'completed') AS completed,
                        COUNT(*) FILTER (WHERE e.status NOT IN ('completed', 'dropped')) AS in_progress,
                        MAX(e.completed_at) FILTER (WHERE e.status = 'completed') AS acquired_at,
                        -- The highest level demonstrated by a course actually
                        -- finished. An enrolment on an expert course does not
                        -- make somebody an expert.
                        MAX(CASE WHEN e.status = 'completed' THEN
                            CASE cs.proficiency
                                WHEN 'expert' THEN 4 WHEN 'advanced' THEN 3
                                WHEN 'intermediate' THEN 2 ELSE 1 END
                        END) AS proficiency_rank,
                        (
                            SELECT COUNT(*) FROM course_skills all_cs
                            JOIN courses all_co ON all_co.id = all_cs.course_id AND all_co.deleted_at IS NULL
                            WHERE all_cs.skill_id = s.id
                        ) AS available
                 FROM course_skills cs
                 JOIN skills s ON s.id = cs.skill_id AND s.deleted_at IS NULL AND s.is_active
                 LEFT JOIN skill_categories c ON c.id = s.category_id
                 JOIN enrollments e ON e.course_id = cs.course_id
                                   AND e.user_id = :user
                                   AND e.deleted_at IS NULL
                                   AND e.status <> 'dropped'
                 WHERE s.tenant_id = :tenant
                 GROUP BY s.id, s.name, s.description, s.icon, s.colour,
                          s.category_id, c.name, c.colour
                 %s
                 ORDER BY proficiency_rank DESC NULLS LAST, completed DESC, s.name",
                $heldOnly ? "HAVING COUNT(*) FILTER (WHERE e.status = 'completed') > 0" : '',
            ),
            ['tenant' => $tenantId, 'user' => $userId],
        );

        return array_map(fn (array $row): LearnerSkill => $this->hydrateLearnerSkill($row), $rows);
    }

    public function learnersWith(string $tenantId, string $skillId, int $page, int $perPage): array
    {
        $where = "cs.skill_id = :skill AND cs.tenant_id = :tenant
                  AND e.status = 'completed' AND e.deleted_at IS NULL AND u.deleted_at IS NULL";

        $total = (int) $this->connection->fetchOne(
            sprintf(
                'SELECT COUNT(DISTINCT e.user_id)
                 FROM course_skills cs
                 JOIN enrollments e ON e.course_id = cs.course_id
                 JOIN users u ON u.id = e.user_id
                 WHERE %s',
                $where,
            ),
            ['skill' => $skillId, 'tenant' => $tenantId],
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT u.id, u.email, u.avatar_url,
                        NULLIF(btrim(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '')), '') AS full_name,
                        COUNT(DISTINCT e.course_id) AS courses,
                        MAX(e.completed_at) AS acquired_at,
                        MAX(CASE cs.proficiency
                                WHEN 'expert' THEN 4 WHEN 'advanced' THEN 3
                                WHEN 'intermediate' THEN 2 ELSE 1 END) AS proficiency_rank
                 FROM course_skills cs
                 JOIN enrollments e ON e.course_id = cs.course_id
                 JOIN users u ON u.id = e.user_id
                 WHERE %s
                 GROUP BY u.id, u.email, u.avatar_url, u.first_name, u.last_name
                 ORDER BY proficiency_rank DESC, acquired_at DESC, u.id
                 LIMIT :limit OFFSET :offset",
                $where,
            ),
            [
                'skill'  => $skillId,
                'tenant' => $tenantId,
                'limit'  => $perPage,
                'offset' => ($page - 1) * $perPage,
            ],
        );

        return [
            'items' => array_map(
                fn (array $r): array => [
                    'userId'      => (string) $r['id'],
                    'fullName'    => $r['full_name'] === null ? null : (string) $r['full_name'],
                    'email'       => (string) $r['email'],
                    'avatarUrl'   => $r['avatar_url'] === null ? null : (string) $r['avatar_url'],
                    'courses'     => (int) $r['courses'],
                    'proficiency' => $this->rankToProficiency($r['proficiency_rank'] ?? null)->value,
                    'acquiredAt'  => $this->instant($r['acquired_at'] ?? null),
                ],
                $rows,
            ),
            'total' => $total,
        ];
    }

    public function statistics(string $tenantId): array
    {
        $totals = $this->connection->fetchAssociative(
            'SELECT
                (SELECT COUNT(*) FROM skills WHERE tenant_id = :tenant AND deleted_at IS NULL) AS skills,
                (SELECT COUNT(*) FROM skills WHERE tenant_id = :tenant AND deleted_at IS NULL AND is_active)
                    AS active_skills,
                (SELECT COUNT(*) FROM skill_categories WHERE tenant_id = :tenant AND deleted_at IS NULL)
                    AS categories,
                (SELECT COUNT(DISTINCT course_id) FROM course_skills WHERE tenant_id = :tenant) AS courses_mapped',
            ['tenant' => $tenantId],
        );

        // The skills nothing teaches. The legacy called this a "gap" and
        // computed it as skills with no course — which is the useful one: a
        // skill the organisation has declared and has no way to give anybody.
        $gaps = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.name, s.colour
             FROM skills s
             WHERE s.tenant_id = :tenant AND s.deleted_at IS NULL AND s.is_active
               AND NOT EXISTS (
                   SELECT 1 FROM course_skills cs
                   JOIN courses co ON co.id = cs.course_id AND co.deleted_at IS NULL
                   WHERE cs.skill_id = s.id
               )
             ORDER BY s.name',
            ['tenant' => $tenantId],
        );

        $top = $this->connection->fetchAllAssociative(
            "SELECT s.id, s.name, s.colour,
                    COUNT(DISTINCT e.user_id) AS learners
             FROM skills s
             JOIN course_skills cs ON cs.skill_id = s.id
             JOIN enrollments e ON e.course_id = cs.course_id
                               AND e.status = 'completed'
                               AND e.deleted_at IS NULL
             WHERE s.tenant_id = :tenant AND s.deleted_at IS NULL
             GROUP BY s.id, s.name, s.colour
             ORDER BY learners DESC, s.name
             LIMIT 10",
            ['tenant' => $tenantId],
        );

        $totals = $totals === false ? [] : $totals;

        return [
            'skills'        => (int) ($totals['skills'] ?? 0),
            'activeSkills'  => (int) ($totals['active_skills'] ?? 0),
            'categories'    => (int) ($totals['categories'] ?? 0),
            'coursesMapped' => (int) ($totals['courses_mapped'] ?? 0),
            'untaught'      => array_map(
                static fn (array $r): array => [
                    'skillId' => (string) $r['id'],
                    'name'    => (string) $r['name'],
                    'colour'  => (string) $r['colour'],
                ],
                $gaps,
            ),
            'mostHeld' => array_map(
                static fn (array $r): array => [
                    'skillId'  => (string) $r['id'],
                    'name'     => (string) $r['name'],
                    'colour'   => (string) $r['colour'],
                    'learners' => (int) $r['learners'],
                ],
                $top,
            ),
        ];
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function conditions(string $tenantId, SkillFilters $filters): array
    {
        $where  = ['s.tenant_id = :tenant', 's.deleted_at IS NULL'];
        $params = ['tenant' => $tenantId];

        if ($filters->activeOnly !== null) {
            $where[] = $filters->activeOnly ? 's.is_active' : 'NOT s.is_active';
        }

        if ($filters->categoryId !== null) {
            $where[]              = 's.category_id = :categoryId';
            $params['categoryId'] = $filters->categoryId;
        }

        if ($filters->level !== null) {
            $where[]         = 's.level = :level';
            $params['level'] = $filters->level->value;
        }

        if ($filters->search !== null) {
            $where[]          = '(s.name ILIKE :search OR s.description ILIKE :search)';
            $params['search'] = '%' . $filters->search . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Skill
    {
        return new Skill(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            name: (string) $row['name'],
            level: Proficiency::from((string) $row['level']),
            description: $this->text($row['description'] ?? null),
            categoryId: $this->text($row['category_id'] ?? null),
            icon: $this->text($row['icon'] ?? null),
            colour: (string) $row['colour'],
            isActive: (bool) $row['is_active'],
            createdAt: $this->date($row['created_at'] ?? null),
            categoryName: $this->text($row['category_name'] ?? null),
            categoryColour: $this->text($row['category_colour'] ?? null),
            courseCount: (int) ($row['course_count'] ?? 0),
            learnerCount: (int) ($row['learner_count'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateCategory(array $row): SkillCategory
    {
        return new SkillCategory(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            name: (string) $row['name'],
            description: $this->text($row['description'] ?? null),
            colour: (string) $row['colour'],
            isActive: (bool) $row['is_active'],
            createdAt: $this->date($row['created_at'] ?? null),
            skillCount: (int) ($row['skill_count'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateLearnerSkill(array $row): LearnerSkill
    {
        return new LearnerSkill(
            skillId: (string) $row['id'],
            name: (string) $row['name'],
            proficiency: $this->rankToProficiency($row['proficiency_rank'] ?? null),
            description: $this->text($row['description'] ?? null),
            icon: $this->text($row['icon'] ?? null),
            colour: (string) $row['colour'],
            categoryId: $this->text($row['category_id'] ?? null),
            categoryName: $this->text($row['category_name'] ?? null),
            categoryColour: $this->text($row['category_colour'] ?? null),
            coursesCompleted: (int) ($row['completed'] ?? 0),
            coursesInProgress: (int) ($row['in_progress'] ?? 0),
            coursesAvailable: (int) ($row['available'] ?? 0),
            acquiredAt: $this->date($row['acquired_at'] ?? null),
        );
    }

    /**
     * The rank the query ordered by, back as the thing it stands for.
     *
     * Null means nothing has been completed yet, which reads as beginner —
     * the bottom of the ladder rather than an absence.
     */
    private function rankToProficiency(mixed $rank): Proficiency
    {
        return match ((int) ($rank ?? 1)) {
            4       => Proficiency::Expert,
            3       => Proficiency::Advanced,
            2       => Proficiency::Intermediate,
            default => Proficiency::Beginner,
        };
    }

    private function instant(mixed $value): ?string
    {
        return $this->date($value)?->format(\DateTimeInterface::ATOM);
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
