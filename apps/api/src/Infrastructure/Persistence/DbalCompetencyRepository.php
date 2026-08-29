<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Competency\AssessmentItem;
use Central\Api\Domain\Competency\AssessmentStatus;
use Central\Api\Domain\Competency\Competency;
use Central\Api\Domain\Competency\CompetencyAssessment;
use Central\Api\Domain\Competency\CompetencyRepository;
use Central\Api\Domain\Competency\JobCompetencyProfile;
use Central\Api\Domain\Competency\ProficiencyLevel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalCompetencyRepository implements CompetencyRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    // --- the dictionary -------------------------------------------------------

    public function competencies(string $tenantId, ?string $function, ?string $search, int $page, int $perPage): array
    {
        $where = ['c.tenant_id = :tenant', 'c.deleted_at IS NULL'];
        $params = ['tenant' => $tenantId];

        if ($function !== null && $function !== '') {
            $where[] = 'c.function_name = :function';
            $params['function'] = $function;
        }

        if ($search !== null && trim($search) !== '') {
            $where[] = '(c.name ILIKE :search OR c.description ILIKE :search)';
            $params['search'] = '%' . trim($search) . '%';
        }

        $clause = implode(' AND ', $where);

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM competencies c WHERE %s', $clause),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT c.*,
                        (SELECT COUNT(*) FROM job_competency_requirements r WHERE r.competency_id = c.id)
                            AS profile_count,
                        (SELECT COUNT(*) FROM competency_assessment_items i WHERE i.competency_id = c.id)
                            AS assessed_count
                 FROM competencies c WHERE %s
                 ORDER BY c.function_name, c.name
                 LIMIT :limit OFFSET :offset",
                $clause,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return [
            'items' => array_map(fn (array $r): Competency => $this->hydrateCompetency($r), $rows),
            'total' => $total,
        ];
    }

    public function findCompetency(string $tenantId, string $id): ?Competency
    {
        $row = $this->connection->fetchAssociative(
            'SELECT c.* FROM competencies c
             WHERE c.id = :id AND c.tenant_id = :tenant AND c.deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrateCompetency($row);
    }

    public function findCompetencyByName(string $tenantId, string $function, string $name): ?Competency
    {
        $row = $this->connection->fetchAssociative(
            'SELECT c.* FROM competencies c
             WHERE c.tenant_id = :tenant AND c.function_name = :function
               AND LOWER(c.name) = LOWER(:name) AND c.deleted_at IS NULL',
            ['tenant' => $tenantId, 'function' => $function, 'name' => $name],
        );

        return $row === false ? null : $this->hydrateCompetency($row);
    }

    public function saveCompetency(Competency $competency): Competency
    {
        $this->connection->executeStatement(
            'INSERT INTO competencies
                 (id, tenant_id, function_name, name, description,
                  knowledge_descriptor, skill_descriptor, mastery_descriptor)
             VALUES (:id, :tenant, :function, :name, :description, :knowledge, :skill, :mastery)
             ON CONFLICT (id) DO UPDATE SET
                 function_name = EXCLUDED.function_name,
                 name = EXCLUDED.name,
                 description = EXCLUDED.description,
                 knowledge_descriptor = EXCLUDED.knowledge_descriptor,
                 skill_descriptor = EXCLUDED.skill_descriptor,
                 mastery_descriptor = EXCLUDED.mastery_descriptor,
                 updated_at = NOW()',
            [
                'id'          => $competency->id,
                'tenant'      => $competency->tenantId,
                'function'    => $competency->functionName,
                'name'        => $competency->name,
                'description' => $competency->description,
                'knowledge'   => $competency->knowledgeDescriptor,
                'skill'       => $competency->skillDescriptor,
                'mastery'     => $competency->masteryDescriptor,
            ],
        );

        return $this->findCompetency($competency->tenantId, $competency->id) ?? $competency;
    }

    public function deleteCompetency(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE competencies SET deleted_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    public function functions(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT function_name AS function,
                    COUNT(*) AS competencies,
                    (SELECT COUNT(*) FROM job_competency_profiles p
                     WHERE p.tenant_id = c.tenant_id AND p.function_name = c.function_name
                       AND p.deleted_at IS NULL) AS profiles
             FROM competencies c
             WHERE c.tenant_id = :tenant AND c.deleted_at IS NULL
             GROUP BY function_name, tenant_id
             ORDER BY function_name",
            ['tenant' => $tenantId],
        );

        return array_map(
            static fn (array $r): array => [
                'function'     => (string) $r['function'],
                'competencies' => (int) $r['competencies'],
                'profiles'     => (int) $r['profiles'],
            ],
            $rows,
        );
    }

    // --- profiles -------------------------------------------------------------

    public function profiles(string $tenantId, ?string $function, ?string $search, int $page, int $perPage): array
    {
        $where = ['p.tenant_id = :tenant', 'p.deleted_at IS NULL'];
        $params = ['tenant' => $tenantId];

        if ($function !== null && $function !== '') {
            $where[] = 'p.function_name = :function';
            $params['function'] = $function;
        }

        if ($search !== null && trim($search) !== '') {
            $where[] = 'p.job_title ILIKE :search';
            $params['search'] = '%' . trim($search) . '%';
        }

        $clause = implode(' AND ', $where);

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM job_competency_profiles p WHERE %s', $clause),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT p.*,
                        (SELECT COUNT(*) FROM job_competency_requirements r WHERE r.profile_id = p.id)
                            AS competency_count,
                        (SELECT COUNT(*) FROM competency_assessments a
                         WHERE a.profile_id = p.id AND a.deleted_at IS NULL) AS assessment_count
                 FROM job_competency_profiles p WHERE %s
                 ORDER BY p.function_name, p.job_title
                 LIMIT :limit OFFSET :offset",
                $clause,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return [
            'items' => array_map(fn (array $r): JobCompetencyProfile => $this->hydrateProfile($r, []), $rows),
            'total' => $total,
        ];
    }

    public function findProfile(string $tenantId, string $id): ?JobCompetencyProfile
    {
        $row = $this->connection->fetchAssociative(
            'SELECT p.* FROM job_competency_profiles p
             WHERE p.id = :id AND p.tenant_id = :tenant AND p.deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        );

        if ($row === false) {
            return null;
        }

        return $this->hydrateProfile($row, $this->requirementsFor($tenantId, $id));
    }

    public function findProfileByTitle(string $tenantId, string $function, string $jobTitle): ?JobCompetencyProfile
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM job_competency_profiles
             WHERE tenant_id = :tenant AND function_name = :function
               AND LOWER(job_title) = LOWER(:title) AND deleted_at IS NULL',
            ['tenant' => $tenantId, 'function' => $function, 'title' => $jobTitle],
        );

        return $id === false ? null : $this->findProfile($tenantId, (string) $id);
    }

    public function saveProfile(JobCompetencyProfile $profile): JobCompetencyProfile
    {
        $this->connection->transactional(function (Connection $tx) use ($profile): void {
            $tx->executeStatement(
                'INSERT INTO job_competency_profiles
                     (id, tenant_id, job_title, function_name, description)
                 VALUES (:id, :tenant, :title, :function, :description)
                 ON CONFLICT (id) DO UPDATE SET
                     job_title = EXCLUDED.job_title,
                     function_name = EXCLUDED.function_name,
                     description = EXCLUDED.description,
                     updated_at = NOW()',
                [
                    'id'          => $profile->id,
                    'tenant'      => $profile->tenantId,
                    'title'       => $profile->jobTitle,
                    'function'    => $profile->functionName,
                    'description' => $profile->description,
                ],
            );

            // The whole requirement set, replaced. Deleting and re-inserting
            // outside a transaction is how a profile ends up requiring nothing
            // when the second half fails.
            $tx->executeStatement(
                'DELETE FROM job_competency_requirements WHERE profile_id = :id',
                ['id' => $profile->id],
            );

            foreach ($profile->requirements as $req) {
                $tx->executeStatement(
                    'INSERT INTO job_competency_requirements
                         (profile_id, competency_id, tenant_id, required_level, is_critical)
                     VALUES (:profile, :competency, :tenant, :level, :critical)',
                    [
                        'profile'    => $profile->id,
                        'competency' => $req['competencyId'],
                        'tenant'     => $profile->tenantId,
                        'level'      => $req['requiredLevel'],
                        'critical'   => $req['isCritical'],
                    ],
                    ['critical' => ParameterType::BOOLEAN],
                );
            }
        });

        return $this->findProfile($profile->tenantId, $profile->id) ?? $profile;
    }

    public function deleteProfile(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE job_competency_profiles SET deleted_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    // --- assessments ----------------------------------------------------------

    public function assessments(
        string $tenantId,
        ?string $cycle,
        ?string $function,
        ?string $status,
        ?string $search,
        int $page,
        int $perPage,
    ): array {
        $where = ['a.tenant_id = :tenant', 'a.deleted_at IS NULL'];
        $params = ['tenant' => $tenantId];

        if ($cycle !== null && $cycle !== '') {
            $where[] = 'a.cycle = :cycle';
            $params['cycle'] = $cycle;
        }
        if ($function !== null && $function !== '') {
            $where[] = 'p.function_name = :function';
            $params['function'] = $function;
        }
        if ($status !== null && $status !== '') {
            $where[] = 'a.status = :status';
            $params['status'] = $status;
        }
        if ($search !== null && trim($search) !== '') {
            $where[] = "TRIM(u.first_name || ' ' || u.last_name) ILIKE :search";
            $params['search'] = '%' . trim($search) . '%';
        }

        $clause = implode(' AND ', $where);
        $from = 'FROM competency_assessments a
                 JOIN users u ON u.id = a.user_id
                 JOIN job_competency_profiles p ON p.id = a.profile_id';

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) %s WHERE %s', $from, $clause),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                "SELECT a.*,
                        TRIM(u.first_name || ' ' || u.last_name) AS user_name,
                        p.job_title, p.function_name
                 %s WHERE %s
                 ORDER BY a.created_at DESC
                 LIMIT :limit OFFSET :offset",
                $from,
                $clause,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        // Items loaded per assessment: the listing shows the readiness figure,
        // which needs them.
        return [
            'items' => array_map(
                fn (array $r): CompetencyAssessment => $this->hydrateAssessment(
                    $r,
                    $this->itemsFor($tenantId, (string) $r['id']),
                ),
                $rows,
            ),
            'total' => $total,
        ];
    }

    public function findAssessment(string $tenantId, string $id): ?CompetencyAssessment
    {
        $row = $this->connection->fetchAssociative(
            "SELECT a.*,
                    TRIM(u.first_name || ' ' || u.last_name) AS user_name,
                    TRIM(ass.first_name || ' ' || ass.last_name) AS assessor_name,
                    p.job_title, p.function_name
             FROM competency_assessments a
             JOIN users u ON u.id = a.user_id
             JOIN job_competency_profiles p ON p.id = a.profile_id
             LEFT JOIN users ass ON ass.id = a.assessor_id
             WHERE a.id = :id AND a.tenant_id = :tenant AND a.deleted_at IS NULL",
            ['id' => $id, 'tenant' => $tenantId],
        );

        if ($row === false) {
            return null;
        }

        return $this->hydrateAssessment($row, $this->itemsFor($tenantId, $id));
    }

    public function findAssessmentFor(string $tenantId, string $userId, string $profileId, string $cycle): ?CompetencyAssessment
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM competency_assessments
             WHERE tenant_id = :tenant AND user_id = :user AND profile_id = :profile
               AND cycle = :cycle AND deleted_at IS NULL',
            ['tenant' => $tenantId, 'user' => $userId, 'profile' => $profileId, 'cycle' => $cycle],
        );

        return $id === false ? null : $this->findAssessment($tenantId, (string) $id);
    }

    public function startAssessment(
        string $tenantId,
        string $userId,
        string $profileId,
        string $cycle,
    ): CompetencyAssessment {
        $id = $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $userId, $profileId, $cycle): string {
                $assessmentId = (string) $tx->fetchOne(
                    "INSERT INTO competency_assessments (tenant_id, user_id, profile_id, cycle)
                     VALUES (:tenant, :user, :profile, :cycle) RETURNING id",
                    ['tenant' => $tenantId, 'user' => $userId, 'profile' => $profileId, 'cycle' => $cycle],
                );

                // One item per requirement, at the level the profile asks for.
                // The assessment mirrors the profile the moment it starts, so
                // adding a competency to the profile later does not silently
                // change what a finished assessment measured.
                $tx->executeStatement(
                    'INSERT INTO competency_assessment_items
                         (assessment_id, competency_id, tenant_id, required_level)
                     SELECT :assessment, r.competency_id, :tenant, r.required_level
                     FROM job_competency_requirements r
                     WHERE r.profile_id = :profile',
                    ['assessment' => $assessmentId, 'tenant' => $tenantId, 'profile' => $profileId],
                );

                return $assessmentId;
            },
        );

        return $this->findAssessment($tenantId, $id)
            ?? throw new \RuntimeException('The assessment vanished immediately after being started.');
    }

    public function recordLevels(string $tenantId, string $assessmentId, string $field, array $levels): ?CompetencyAssessment
    {
        if (!in_array($field, ['self', 'assessed'], true)) {
            throw new \InvalidArgumentException('A level is either self or assessed.');
        }

        $column = $field === 'self' ? 'self_level' : 'assessed_level';

        $this->connection->transactional(function (Connection $tx) use ($tenantId, $assessmentId, $column, $levels): void {
            foreach ($levels as $competencyId => $level) {
                $tx->executeStatement(
                    sprintf(
                        'UPDATE competency_assessment_items SET %s = :level
                         WHERE assessment_id = :assessment AND competency_id = :competency
                           AND tenant_id = :tenant',
                        $column,
                    ),
                    [
                        'level'      => max(0, min(3, (int) $level)),
                        'assessment' => $assessmentId,
                        'competency' => $competencyId,
                        'tenant'     => $tenantId,
                    ],
                );
            }
        });

        return $this->findAssessment($tenantId, $assessmentId);
    }

    public function setStatus(
        string $tenantId,
        string $assessmentId,
        AssessmentStatus $status,
        ?string $assessorId,
        ?string $assessorComment,
    ): ?CompetencyAssessment {
        // The timestamp that goes with the state. Each is set once, when the
        // state is reached, so the dates read as a history rather than being
        // overwritten every save.
        $stamp = match ($status) {
            AssessmentStatus::SelfAssessed => 'self_submitted_at = COALESCE(self_submitted_at, NOW())',
            AssessmentStatus::Reviewed     => 'reviewed_at = COALESCE(reviewed_at, NOW())',
            AssessmentStatus::Finalized    => 'reviewed_at = COALESCE(reviewed_at, NOW()), finalized_at = NOW()',
            AssessmentStatus::Draft        => 'self_submitted_at = self_submitted_at',
        };

        $affected = $this->connection->executeStatement(
            sprintf(
                'UPDATE competency_assessments
                 SET status = :status, %s,
                     assessor_id = COALESCE(:assessor, assessor_id),
                     assessor_comment = COALESCE(:comment, assessor_comment),
                     updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                $stamp,
            ),
            [
                'status'   => $status->value,
                'assessor' => $assessorId,
                'comment'  => $assessorComment,
                'id'       => $assessmentId,
                'tenant'   => $tenantId,
            ],
        );

        return $affected > 0 ? $this->findAssessment($tenantId, $assessmentId) : null;
    }

    public function deleteAssessment(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE competency_assessments SET deleted_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    // --- the outcome report ---------------------------------------------------

    public function outcome(string $tenantId, string $cycle): array
    {
        $params = ['tenant' => $tenantId, 'cycle' => $cycle];

        // Coverage: how many people have an assessment at all, and how many are
        // finalised — the figure a compliance statement should quote.
        $coverage = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS assessments,
                    COUNT(*) FILTER (WHERE status = 'finalized') AS finalized,
                    COUNT(DISTINCT user_id) AS people
             FROM competency_assessments
             WHERE tenant_id = :tenant AND cycle = :cycle AND deleted_at IS NULL",
            $params,
        );
        $coverage = $coverage === false ? [] : $coverage;

        // Gaps by category across every finalised assessment's items — the
        // outcome the whole exercise is for. Only finalised, because an
        // unconfirmed gap is a claim, not a finding.
        $gaps = $this->connection->fetchAssociative(
            "SELECT COUNT(*) AS items,
                    COUNT(*) FILTER (WHERE i.gap = 0) AS met,
                    COUNT(*) FILTER (WHERE i.gap = 1) AS minor,
                    COUNT(*) FILTER (WHERE i.gap = 2) AS major,
                    COUNT(*) FILTER (WHERE i.gap >= 3) AS significant
             FROM competency_assessment_items i
             JOIN competency_assessments a ON a.id = i.assessment_id
             WHERE a.tenant_id = :tenant AND a.cycle = :cycle
               AND a.status = 'finalized' AND a.deleted_at IS NULL",
            $params,
        );
        $gaps = $gaps === false ? [] : $gaps;

        // By function: where the gaps sit, worst first — the section an
        // executive turns to after the headline.
        $byFunction = $this->connection->fetchAllAssociative(
            "SELECT p.function_name AS function,
                    COUNT(DISTINCT a.id) AS assessments,
                    COUNT(i.*) AS requirements,
                    COUNT(i.*) FILTER (WHERE i.gap = 0) AS met,
                    COUNT(i.*) FILTER (WHERE i.gap > 0) AS gaps,
                    ROUND(100.0 * COUNT(i.*) FILTER (WHERE i.gap = 0)
                          / NULLIF(COUNT(i.*), 0), 1) AS readiness
             FROM competency_assessments a
             JOIN job_competency_profiles p ON p.id = a.profile_id
             LEFT JOIN competency_assessment_items i ON i.assessment_id = a.id
             WHERE a.tenant_id = :tenant AND a.cycle = :cycle
               AND a.status = 'finalized' AND a.deleted_at IS NULL
             GROUP BY p.function_name
             ORDER BY readiness ASC NULLS LAST",
            $params,
        );

        // The competencies with the widest gaps across the organisation — the
        // development priorities.
        $topGaps = $this->connection->fetchAllAssociative(
            "SELECT c.name AS competency, c.function_name AS function,
                    COUNT(*) AS assessed,
                    COUNT(*) FILTER (WHERE i.gap > 0) AS with_gap,
                    ROUND(AVG(i.gap)::numeric, 2) AS avg_gap
             FROM competency_assessment_items i
             JOIN competency_assessments a ON a.id = i.assessment_id
             JOIN competencies c ON c.id = i.competency_id
             WHERE a.tenant_id = :tenant AND a.cycle = :cycle
               AND a.status = 'finalized' AND a.deleted_at IS NULL
             GROUP BY c.id, c.name, c.function_name
             HAVING COUNT(*) FILTER (WHERE i.gap > 0) > 0
             ORDER BY avg_gap DESC, with_gap DESC
             LIMIT 15",
            $params,
        );

        $minor = (int) ($gaps['minor'] ?? 0);
        $major = (int) ($gaps['major'] ?? 0);
        $significant = (int) ($gaps['significant'] ?? 0);
        $items = (int) ($gaps['items'] ?? 0);
        $met = (int) ($gaps['met'] ?? 0);

        return [
            'cycle'    => $cycle,
            'coverage' => [
                'people'    => (int) ($coverage['people'] ?? 0),
                'assessments' => (int) ($coverage['assessments'] ?? 0),
                'finalized' => (int) ($coverage['finalized'] ?? 0),
            ],
            'gaps' => [
                'requirements' => $items,
                'met'          => $met,
                'minor'        => $minor,
                'major'        => $major,
                'significant'  => $significant,
                'readiness'    => $items === 0 ? null : round(100 * $met / $items, 1),
                // What it would take to close everything, on the standard
                // estimates. Labelled an estimate wherever it is shown.
                'estimatedMonths' => $minor * 3 + $major * 6 + $significant * 12,
            ],
            'byFunction' => array_map(
                static fn (array $r): array => [
                    'function'     => (string) $r['function'],
                    'assessments'  => (int) $r['assessments'],
                    'requirements' => (int) $r['requirements'],
                    'met'          => (int) $r['met'],
                    'gaps'         => (int) $r['gaps'],
                    'readiness'    => $r['readiness'] === null ? null : (float) $r['readiness'],
                ],
                $byFunction,
            ),
            'topGaps' => array_map(
                static fn (array $r): array => [
                    'competency' => (string) $r['competency'],
                    'function'   => (string) $r['function'],
                    'assessed'   => (int) $r['assessed'],
                    'withGap'    => (int) $r['with_gap'],
                    'avgGap'     => (float) $r['avg_gap'],
                ],
                $topGaps,
            ),
        ];
    }

    public function cycles(string $tenantId): array
    {
        $rows = $this->connection->fetchFirstColumn(
            'SELECT DISTINCT cycle FROM competency_assessments
             WHERE tenant_id = :tenant AND deleted_at IS NULL
             ORDER BY cycle DESC',
            ['tenant' => $tenantId],
        );

        return array_map(static fn (mixed $c): string => (string) $c, $rows);
    }

    // --- internals ------------------------------------------------------------

    /**
     * @return list<array{competencyId: string, competencyName: string, requiredLevel: int, isCritical: bool}>
     */
    private function requirementsFor(string $tenantId, string $profileId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.competency_id, r.required_level, r.is_critical, c.name
             FROM job_competency_requirements r
             JOIN competencies c ON c.id = r.competency_id
             WHERE r.profile_id = :profile AND r.tenant_id = :tenant
             ORDER BY c.name',
            ['profile' => $profileId, 'tenant' => $tenantId],
        );

        return array_map(
            static fn (array $r): array => [
                'competencyId'   => (string) $r['competency_id'],
                'competencyName' => (string) $r['name'],
                'requiredLevel'  => (int) $r['required_level'],
                'isCritical'     => (bool) $r['is_critical'],
            ],
            $rows,
        );
    }

    /**
     * @return list<AssessmentItem>
     */
    private function itemsFor(string $tenantId, string $assessmentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.*, c.name, c.function_name,
                    COALESCE(r.is_critical, FALSE) AS is_critical
             FROM competency_assessment_items i
             JOIN competencies c ON c.id = i.competency_id
             LEFT JOIN competency_assessments a ON a.id = i.assessment_id
             LEFT JOIN job_competency_requirements r
                    ON r.profile_id = a.profile_id AND r.competency_id = i.competency_id
             WHERE i.assessment_id = :assessment AND i.tenant_id = :tenant
             ORDER BY i.gap DESC, c.name',
            ['assessment' => $assessmentId, 'tenant' => $tenantId],
        );

        return array_map(
            static fn (array $r): AssessmentItem => new AssessmentItem(
                competencyId: (string) $r['competency_id'],
                requiredLevel: ProficiencyLevel::from((int) $r['required_level']),
                selfLevel: $r['self_level'] === null ? null : ProficiencyLevel::from((int) $r['self_level']),
                assessedLevel: $r['assessed_level'] === null ? null : ProficiencyLevel::from((int) $r['assessed_level']),
                comment: $r['comment'] === null ? null : (string) $r['comment'],
                isCritical: (bool) $r['is_critical'],
                competencyName: (string) $r['name'],
                functionName: (string) $r['function_name'],
            ),
            $rows,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateCompetency(array $row): Competency
    {
        return new Competency(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            functionName: (string) $row['function_name'],
            name: (string) $row['name'],
            description: $this->text($row['description'] ?? null),
            knowledgeDescriptor: $this->text($row['knowledge_descriptor'] ?? null),
            skillDescriptor: $this->text($row['skill_descriptor'] ?? null),
            masteryDescriptor: $this->text($row['mastery_descriptor'] ?? null),
            createdAt: $this->at($row['created_at'] ?? null),
            profileCount: (int) ($row['profile_count'] ?? 0),
            assessedCount: (int) ($row['assessed_count'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array{competencyId: string, competencyName: string, requiredLevel: int, isCritical: bool}> $requirements
     */
    private function hydrateProfile(array $row, array $requirements): JobCompetencyProfile
    {
        return new JobCompetencyProfile(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            jobTitle: (string) $row['job_title'],
            functionName: (string) $row['function_name'],
            description: $this->text($row['description'] ?? null),
            requirements: $requirements,
            createdAt: $this->at($row['created_at'] ?? null),
            assessmentCount: (int) ($row['assessment_count'] ?? 0),
            competencyCount: isset($row['competency_count']) ? (int) $row['competency_count'] : null,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<AssessmentItem>  $items
     */
    private function hydrateAssessment(array $row, array $items): CompetencyAssessment
    {
        return new CompetencyAssessment(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            userId: (string) $row['user_id'],
            profileId: (string) $row['profile_id'],
            cycle: (string) $row['cycle'],
            status: AssessmentStatus::from((string) $row['status']),
            assessorId: $this->text($row['assessor_id'] ?? null),
            assessorComment: $this->text($row['assessor_comment'] ?? null),
            selfSubmittedAt: $this->at($row['self_submitted_at'] ?? null),
            reviewedAt: $this->at($row['reviewed_at'] ?? null),
            finalizedAt: $this->at($row['finalized_at'] ?? null),
            createdAt: $this->at($row['created_at'] ?? null),
            items: $items,
            userName: $this->displayName($row['user_name'] ?? null),
            jobTitle: $this->text($row['job_title'] ?? null),
            functionName: $this->text($row['function_name'] ?? null),
            assessorName: $this->displayName($row['assessor_name'] ?? null),
        );
    }

    private function displayName(mixed $value): ?string
    {
        $name = is_string($value) ? trim($value) : '';

        return $name === '' ? null : $name;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function at(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) ? new \DateTimeImmutable($value) : null;
    }
}
