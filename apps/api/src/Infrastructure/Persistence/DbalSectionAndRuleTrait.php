<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Assessment\AssessmentSection;
use Central\Api\Domain\Assessment\QuestionType;
use Central\Api\Domain\Assessment\RandomRule;
use Central\Api\Domain\Assessment\SectionSelection;
use Central\Api\Domain\Shared\Difficulty;
use Doctrine\DBAL\ArrayParameterType;

/**
 * Sections and random-selection rules.
 *
 * Split out of {@see DbalAssessmentRepository} to keep that file readable —
 * it is the same repository, not a second one, so the two must not be wired
 * independently.
 */
trait DbalSectionAndRuleTrait
{
    // --- sections ---------------------------------------------------------

    private const SECTION_COLUMNS = 's.id, s.tenant_id, s.assessment_id, s.title, s.description,
                                     s.instructions, s.time_limit_minutes, s.selection_type,
                                     s.random_count, s.sort_order, s.created_at, s.updated_at';

    /**
     * Counted here rather than stored, for the same reason
     * `assessments.total_questions` was dropped.
     */
    private const SECTION_DERIVED = '(
            SELECT COUNT(*) FROM assessment_questions aq WHERE aq.section_id = s.id
        ) AS question_count,
        COALESCE((
            SELECT SUM(COALESCE(aq.points_override, q.points))
            FROM assessment_questions aq
            JOIN questions q ON q.id = aq.question_id
            WHERE aq.section_id = s.id
        ), 0) AS total_points';

    public function sectionsFor(string $tenantId, string $assessmentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s FROM assessment_sections s
                 WHERE s.assessment_id = :assessment AND s.tenant_id = :tenant
                 ORDER BY s.sort_order ASC, s.title ASC',
                self::SECTION_COLUMNS,
                self::SECTION_DERIVED,
            ),
            ['assessment' => $assessmentId, 'tenant' => $tenantId],
        );

        return array_map(fn (array $r): AssessmentSection => $this->hydrateSection($r), $rows);
    }

    public function findSection(string $tenantId, string $assessmentId, string $id): ?AssessmentSection
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s FROM assessment_sections s
                 WHERE s.id = :id AND s.assessment_id = :assessment AND s.tenant_id = :tenant',
                self::SECTION_COLUMNS,
                self::SECTION_DERIVED,
            ),
            ['id' => $id, 'assessment' => $assessmentId, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrateSection($row);
    }

    public function createSection(AssessmentSection $section): AssessmentSection
    {
        $id = $this->connection->fetchOne(
            'INSERT INTO assessment_sections
                 (tenant_id, assessment_id, title, description, instructions,
                  time_limit_minutes, selection_type, random_count, sort_order)
             VALUES (
                 :tenant, :assessment, :title, :description, :instructions,
                 :timeLimit, :selection, :randomCount,
                 COALESCE(:sortOrder, (
                     SELECT COALESCE(MAX(sort_order), -1) + 1
                     FROM assessment_sections WHERE assessment_id = :assessment
                 ))
             )
             RETURNING id',
            $this->sectionParams($section) + [
                'sortOrder' => $section->sortOrder > 0 ? $section->sortOrder : null,
            ],
        );

        return $this->findSection($section->tenantId, $section->assessmentId, (string) $id)
            ?? throw new \RuntimeException('Newly created section could not be read back.');
    }

    public function updateSection(AssessmentSection $section): AssessmentSection
    {
        $affected = $this->connection->executeStatement(
            'UPDATE assessment_sections SET
                title = :title, description = :description, instructions = :instructions,
                time_limit_minutes = :timeLimit, selection_type = :selection,
                random_count = :randomCount, sort_order = :sortOrder, updated_at = NOW()
             WHERE id = :id AND assessment_id = :assessment AND tenant_id = :tenant',
            $this->sectionParams($section) + ['id' => $section->id, 'sortOrder' => $section->sortOrder],
        );

        if ($affected === 0) {
            throw new \RuntimeException('Updated section could not be found.');
        }

        return $this->findSection($section->tenantId, $section->assessmentId, $section->id)
            ?? throw new \RuntimeException('Updated section could not be read back.');
    }

    public function deleteSection(string $tenantId, string $assessmentId, string $id): bool
    {
        // The questions survive, unfiled: the foreign key is ON DELETE SET
        // NULL, so removing a heading does not remove the work under it.
        return $this->connection->executeStatement(
            'DELETE FROM assessment_sections
             WHERE id = :id AND assessment_id = :assessment AND tenant_id = :tenant',
            ['id' => $id, 'assessment' => $assessmentId, 'tenant' => $tenantId],
        ) > 0;
    }

    public function reorderSections(string $tenantId, string $assessmentId, array $orderedIds): int
    {
        if ($orderedIds === []) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'UPDATE assessment_sections AS s
             SET sort_order = new_order.position, updated_at = NOW()
             FROM (SELECT * FROM unnest(:ids::uuid[]) WITH ORDINALITY AS t(id, position)) AS new_order
             WHERE s.id = new_order.id
               AND s.assessment_id = :assessment
               AND s.tenant_id = :tenant',
            [
                'ids'        => '{' . implode(',', $orderedIds) . '}',
                'assessment' => $assessmentId,
                'tenant'     => $tenantId,
            ],
        );
    }

    public function setQuestionSection(
        string $tenantId,
        string $assessmentId,
        string $questionId,
        ?string $sectionId,
    ): bool {
        return $this->connection->executeStatement(
            'UPDATE assessment_questions SET section_id = :section
             WHERE assessment_id = :assessment AND question_id = :question AND tenant_id = :tenant',
            [
                'section'    => $sectionId,
                'assessment' => $assessmentId,
                'question'   => $questionId,
                'tenant'     => $tenantId,
            ],
        ) > 0;
    }

    // --- random rules -----------------------------------------------------

    public function rulesFor(string $tenantId, string $assessmentId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, tenant_id, assessment_id, section_id, bank_id, category_id,
                    difficulty, question_types, question_count, points_per_question, sort_order
             FROM assessment_random_rules
             WHERE assessment_id = :assessment AND tenant_id = :tenant
             ORDER BY sort_order ASC',
            ['assessment' => $assessmentId, 'tenant' => $tenantId],
        );

        return array_map(
            function (array $r): RandomRule {
                $rule = $this->hydrateRule($r);

                // How many questions actually match, so the author can be told
                // before a learner meets a short paper.
                return $rule->withAvailableCount($this->countCandidates($rule));
            },
            $rows,
        );
    }

    public function createRule(RandomRule $rule): RandomRule
    {
        $id = (string) $this->connection->fetchOne(
            'INSERT INTO assessment_random_rules
                 (tenant_id, assessment_id, section_id, bank_id, category_id,
                  difficulty, question_types, question_count, points_per_question, sort_order)
             VALUES (:tenant, :assessment, :section, :bank, :category,
                     :difficulty, :types::jsonb, :count, :points, :sortOrder)
             RETURNING id',
            [
                'tenant'     => $rule->tenantId,
                'assessment' => $rule->assessmentId,
                'section'    => $rule->sectionId,
                'bank'       => $rule->bankId,
                'category'   => $rule->categoryId,
                'difficulty' => $rule->difficulty?->value,
                'types'      => $rule->questionTypes === []
                    ? null
                    : json_encode(
                        array_map(static fn (QuestionType $t): string => $t->value, $rule->questionTypes),
                        JSON_THROW_ON_ERROR,
                    ),
                'count'     => $rule->questionCount,
                'points'    => $rule->pointsPerQuestion,
                'sortOrder' => $rule->sortOrder,
            ],
        );

        foreach ($this->rulesFor($rule->tenantId, $rule->assessmentId) as $created) {
            if ($created->id === $id) {
                return $created;
            }
        }

        throw new \RuntimeException('Newly created rule could not be read back.');
    }

    public function deleteRule(string $tenantId, string $assessmentId, string $id): bool
    {
        return $this->connection->executeStatement(
            'DELETE FROM assessment_random_rules
             WHERE id = :id AND assessment_id = :assessment AND tenant_id = :tenant',
            ['id' => $id, 'assessment' => $assessmentId, 'tenant' => $tenantId],
        ) > 0;
    }

    public function candidatesForRule(string $tenantId, RandomRule $rule): array
    {
        [$where, $params] = $this->ruleFilter($tenantId, $rule);

        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            sprintf('SELECT id FROM questions WHERE %s', $where),
            $params,
            $rule->questionTypes === [] ? [] : ['types' => ArrayParameterType::STRING],
        );

        // Loaded through the question repository so options come with them,
        // and in one query rather than one per id.
        return array_values($this->questions->findQuestionsByIds($tenantId, $ids));
    }

    private function countCandidates(RandomRule $rule): int
    {
        [$where, $params] = $this->ruleFilter($rule->tenantId, $rule);

        return (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM questions WHERE %s', $where),
            $params,
            $rule->questionTypes === [] ? [] : ['types' => ArrayParameterType::STRING],
        );
    }

    /**
     * The WHERE clause a rule narrows to.
     *
     * @return array{string, array<string, mixed>}
     */
    private function ruleFilter(string $tenantId, RandomRule $rule): array
    {
        $where = 'tenant_id = :tenant AND bank_id = :bank AND deleted_at IS NULL AND is_active';
        $params = ['tenant' => $tenantId, 'bank' => $rule->bankId];

        if ($rule->difficulty !== null) {
            $where .= ' AND difficulty = :difficulty';
            $params['difficulty'] = $rule->difficulty->value;
        }

        if ($rule->questionTypes !== []) {
            $where .= ' AND type IN (:types)';
            $params['types'] = array_map(
                static fn (QuestionType $t): string => $t->value,
                $rule->questionTypes,
            );
        }

        return [$where, $params];
    }

    // --- hydration --------------------------------------------------------

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSection(array $row): AssessmentSection
    {
        return new AssessmentSection(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            assessmentId: (string) $row['assessment_id'],
            title: (string) $row['title'],
            description: $this->text($row['description'] ?? null),
            instructions: $this->text($row['instructions'] ?? null),
            timeLimitMinutes: isset($row['time_limit_minutes']) ? (int) $row['time_limit_minutes'] : null,
            selectionType: SectionSelection::tryFrom((string) $row['selection_type']) ?? SectionSelection::All,
            randomCount: isset($row['random_count']) ? (int) $row['random_count'] : null,
            sortOrder: (int) $row['sort_order'],
            createdAt: $this->date($row['created_at'] ?? null),
            updatedAt: $this->date($row['updated_at'] ?? null),
            questionCount: (int) ($row['question_count'] ?? 0),
            totalPoints: (float) ($row['total_points'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateRule(array $row): RandomRule
    {
        $types = [];

        if (is_string($row['question_types'] ?? null) && $row['question_types'] !== '') {
            $decoded = json_decode($row['question_types'], true);

            if (is_array($decoded)) {
                foreach ($decoded as $value) {
                    $type = is_string($value) ? QuestionType::tryFrom($value) : null;

                    if ($type !== null) {
                        $types[] = $type;
                    }
                }
            }
        }

        $difficulty = $this->text($row['difficulty'] ?? null);

        return new RandomRule(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            assessmentId: (string) $row['assessment_id'],
            bankId: (string) $row['bank_id'],
            questionCount: (int) $row['question_count'],
            pointsPerQuestion: (float) $row['points_per_question'],
            sectionId: $this->text($row['section_id'] ?? null),
            categoryId: $this->text($row['category_id'] ?? null),
            difficulty: $difficulty === null ? null : Difficulty::tryFrom($difficulty),
            questionTypes: $types,
            sortOrder: (int) $row['sort_order'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionParams(AssessmentSection $section): array
    {
        return [
            'tenant'      => $section->tenantId,
            'assessment'  => $section->assessmentId,
            'title'       => $section->title,
            'description' => $section->description,
            'instructions' => $section->instructions,
            'timeLimit'   => $section->timeLimitMinutes,
            'selection'   => $section->selectionType->value,
            'randomCount' => $section->randomCount,
        ];
    }
}
