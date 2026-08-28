<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Assessment\Rubric;
use Central\Api\Domain\Assessment\RubricCriterion;
use Central\Api\Domain\Assessment\RubricKind;
use Central\Api\Domain\Assessment\RubricLevel;
use Central\Api\Domain\Assessment\RubricRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalRubricRepository implements RubricRepository
{
    private const COLUMNS = 'id, tenant_id, name, description, kind, is_active,
                             created_by, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function all(string $tenantId, bool $activeOnly = false): array
    {
        $where = 'tenant_id = :tenant AND deleted_at IS NULL';

        if ($activeOnly) {
            $where .= ' AND is_active = TRUE';
        }

        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT %s FROM rubrics WHERE %s ORDER BY name', self::COLUMNS, $where),
            ['tenant' => $tenantId],
        );

        if ($rows === []) {
            return [];
        }

        $ids = array_map(static fn (array $r): string => (string) $r['id'], $rows);
        $criteria = $this->criteriaFor($tenantId, $ids);
        $counts = $this->questionCounts($tenantId);

        return array_map(
            fn (array $r): Rubric => $this->hydrate($r)
                ->withCriteria($criteria[(string) $r['id']] ?? [])
                ->withQuestionCount($counts[(string) $r['id']] ?? 0),
            $rows,
        );
    }

    public function find(string $tenantId, string $id): ?Rubric
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM rubrics WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                self::COLUMNS,
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        if ($row === false) {
            return null;
        }

        $criteria = $this->criteriaFor($tenantId, [$id]);
        $counts = $this->questionCounts($tenantId);

        return $this->hydrate($row)
            ->withCriteria($criteria[$id] ?? [])
            ->withQuestionCount($counts[$id] ?? 0);
    }

    public function save(Rubric $rubric): Rubric
    {
        $this->connection->transactional(function () use ($rubric): void {
            $this->connection->executeStatement(
                'INSERT INTO rubrics (id, tenant_id, name, description, kind, is_active, created_by)
                 VALUES (:id, :tenant, :name, :description, :kind, :active, :by)
                 ON CONFLICT (id) DO UPDATE SET
                     name        = EXCLUDED.name,
                     description = EXCLUDED.description,
                     kind        = EXCLUDED.kind,
                     is_active   = EXCLUDED.is_active,
                     updated_at  = NOW()',
                [
                    'id'          => $rubric->id,
                    'tenant'      => $rubric->tenantId,
                    'name'        => $rubric->name,
                    'description' => $rubric->description,
                    'kind'        => $rubric->kind->value,
                    'active'      => $rubric->isActive,
                    'by'          => $rubric->createdBy,
                ],
                ['active' => ParameterType::BOOLEAN],
            );

            if ($rubric->criteria !== []) {
                $this->writeCriteria($rubric->tenantId, $rubric->id, $rubric->criteria);
            }
        });

        return $this->find($rubric->tenantId, $rubric->id) ?? $rubric;
    }

    public function replaceCriteria(string $tenantId, string $rubricId, array $criteria): Rubric
    {
        $this->connection->transactional(function () use ($tenantId, $rubricId, $criteria): void {
            // The whole set goes at once — see RubricRepository for why a
            // per-criterion API would let a marker see a half-edited rubric.
            // The cascade takes the bands with the criteria.
            $this->connection->executeStatement(
                'DELETE FROM rubric_criteria WHERE rubric_id = :rubric AND tenant_id = :tenant',
                ['rubric' => $rubricId, 'tenant' => $tenantId],
            );

            $this->writeCriteria($tenantId, $rubricId, $criteria);

            $this->connection->executeStatement(
                'UPDATE rubrics SET updated_at = NOW() WHERE id = :id AND tenant_id = :tenant',
                ['id' => $rubricId, 'tenant' => $tenantId],
            );
        });

        return $this->find($tenantId, $rubricId) ?? throw new \RuntimeException('Rubric vanished.');
    }

    public function delete(string $tenantId, string $id): bool
    {
        // Soft: the marks already given against this rubric name its criteria,
        // and a hard delete would leave those scores unexplainable.
        return $this->connection->executeStatement(
            'UPDATE rubrics SET deleted_at = NOW()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    public function duplicate(string $tenantId, string $id, string $newName, ?string $by): ?Rubric
    {
        $original = $this->find($tenantId, $id);

        if ($original === null) {
            return null;
        }

        $copy = new Rubric(
            id: $this->uuid(),
            tenantId: $tenantId,
            name: $newName,
            description: $original->description,
            kind: $original->kind,
            isActive: $original->isActive,
            createdBy: $by,
            criteria: array_map(
                fn (RubricCriterion $c): RubricCriterion => new RubricCriterion(
                    id: $this->uuid(),
                    tenantId: $tenantId,
                    rubricId: '',
                    title: $c->title,
                    description: $c->description,
                    maxPoints: $c->maxPoints,
                    sortOrder: $c->sortOrder,
                    levels: array_map(
                        fn (RubricLevel $l): RubricLevel => new RubricLevel(
                            id: $this->uuid(),
                            tenantId: $tenantId,
                            criterionId: '',
                            label: $l->label,
                            description: $l->description,
                            points: $l->points,
                            sortOrder: $l->sortOrder,
                        ),
                        $c->levels,
                    ),
                ),
                $original->criteria,
            ),
        );

        return $this->save($copy);
    }

    public function questionCounts(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT rubric_id, COUNT(*) AS n FROM questions
             WHERE tenant_id = :tenant AND rubric_id IS NOT NULL
             GROUP BY rubric_id',
            ['tenant' => $tenantId],
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['rubric_id']] = (int) $row['n'];
        }

        return $counts;
    }

    // --- what a marker chose ------------------------------------------------

    public function recordChoices(
        string $tenantId,
        string $answerId,
        array $choices,
        ?string $gradedBy,
    ): void {
        $this->connection->transactional(function () use ($tenantId, $answerId, $choices, $gradedBy): void {
            foreach ($choices as $choice) {
                // A second judgement on the same criterion is a correction,
                // so it replaces rather than adds — the unique index on
                // (answer, criterion) is what makes that a single statement.
                $this->connection->executeStatement(
                    'INSERT INTO attempt_answer_criteria
                         (id, tenant_id, answer_id, criterion_id, level_id, points, comment, graded_by)
                     VALUES (:id, :tenant, :answer, :criterion, :level, :points, :comment, :by)
                     ON CONFLICT (answer_id, criterion_id) DO UPDATE SET
                         level_id  = EXCLUDED.level_id,
                         points    = EXCLUDED.points,
                         comment   = EXCLUDED.comment,
                         graded_by = EXCLUDED.graded_by,
                         graded_at = NOW()',
                    [
                        'id'        => $this->uuid(),
                        'tenant'    => $tenantId,
                        'answer'    => $answerId,
                        'criterion' => $choice['criterionId'],
                        'level'     => $choice['levelId'] ?? null,
                        'points'    => $choice['points'],
                        'comment'   => $choice['comment'] ?? null,
                        'by'        => $gradedBy,
                    ],
                );
            }
        });
    }

    public function choicesFor(string $tenantId, string $answerId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.criterion_id, c.level_id, c.points, c.comment, c.graded_at,
                    cr.title AS criterion_title, l.label AS level_label
             FROM attempt_answer_criteria c
             JOIN rubric_criteria cr ON cr.id = c.criterion_id
             LEFT JOIN rubric_levels l ON l.id = c.level_id
             WHERE c.answer_id = :answer AND c.tenant_id = :tenant
             ORDER BY cr.sort_order',
            ['answer' => $answerId, 'tenant' => $tenantId],
        );

        return array_map(
            static fn (array $r): array => [
                'id'             => (string) $r['id'],
                'criterionId'    => (string) $r['criterion_id'],
                'criterionTitle' => (string) $r['criterion_title'],
                'levelId'        => $r['level_id'] === null ? null : (string) $r['level_id'],
                'levelLabel'     => $r['level_label'] === null ? null : (string) $r['level_label'],
                'points'         => (float) $r['points'],
                'comment'        => $r['comment'] === null ? null : (string) $r['comment'],
                'gradedAt'       => (string) $r['graded_at'],
            ],
            $rows,
        );
    }

    // --- internals ----------------------------------------------------------

    /**
     * @param list<RubricCriterion> $criteria
     */
    private function writeCriteria(string $tenantId, string $rubricId, array $criteria): void
    {
        foreach ($criteria as $position => $criterion) {
            $criterionId = $criterion->id === '' ? $this->uuid() : $criterion->id;

            $this->connection->executeStatement(
                'INSERT INTO rubric_criteria
                     (id, tenant_id, rubric_id, title, description, max_points, sort_order)
                 VALUES (:id, :tenant, :rubric, :title, :description, :points, :sort)
                 ON CONFLICT (id) DO UPDATE SET
                     title       = EXCLUDED.title,
                     description = EXCLUDED.description,
                     max_points  = EXCLUDED.max_points,
                     sort_order  = EXCLUDED.sort_order,
                     updated_at  = NOW()',
                [
                    'id'          => $criterionId,
                    'tenant'      => $tenantId,
                    'rubric'      => $rubricId,
                    'title'       => $criterion->title,
                    'description' => $criterion->description,
                    'points'      => $criterion->maxPoints,
                    // The position in the list is the order, so a caller
                    // cannot hand in two criteria claiming the same place.
                    'sort'        => $position,
                ],
            );

            foreach ($criterion->levels as $band => $level) {
                $this->connection->executeStatement(
                    'INSERT INTO rubric_levels
                         (id, tenant_id, criterion_id, label, description, points, sort_order)
                     VALUES (:id, :tenant, :criterion, :label, :description, :points, :sort)
                     ON CONFLICT (id) DO UPDATE SET
                         label       = EXCLUDED.label,
                         description = EXCLUDED.description,
                         points      = EXCLUDED.points,
                         sort_order  = EXCLUDED.sort_order',
                    [
                        'id'          => $level->id === '' ? $this->uuid() : $level->id,
                        'tenant'      => $tenantId,
                        'criterion'   => $criterionId,
                        'label'       => $level->label,
                        'description' => $level->description,
                        'points'      => $level->points,
                        'sort'        => $band,
                    ],
                );
            }
        }
    }

    /**
     * Every criterion and band for a set of rubrics, in two queries.
     *
     * @param list<string> $rubricIds
     *
     * @return array<string, list<RubricCriterion>>
     */
    private function criteriaFor(string $tenantId, array $rubricIds): array
    {
        if ($rubricIds === []) {
            return [];
        }

        $criterionRows = $this->connection->fetchAllAssociative(
            'SELECT id, tenant_id, rubric_id, title, description, max_points, sort_order,
                    created_at, updated_at
             FROM rubric_criteria
             WHERE tenant_id = :tenant AND rubric_id IN (:ids)
             ORDER BY sort_order, title',
            ['tenant' => $tenantId, 'ids' => $rubricIds],
            ['ids' => ArrayParameterType::STRING],
        );

        if ($criterionRows === []) {
            return [];
        }

        $levelRows = $this->connection->fetchAllAssociative(
            'SELECT id, tenant_id, criterion_id, label, description, points, sort_order
             FROM rubric_levels
             WHERE tenant_id = :tenant AND criterion_id IN (:ids)
             ORDER BY sort_order, points',
            [
                'tenant' => $tenantId,
                'ids'    => array_map(static fn (array $r): string => (string) $r['id'], $criterionRows),
            ],
            ['ids' => ArrayParameterType::STRING],
        );

        $levelsByCriterion = [];

        foreach ($levelRows as $row) {
            $levelsByCriterion[(string) $row['criterion_id']][] = new RubricLevel(
                id: (string) $row['id'],
                tenantId: (string) $row['tenant_id'],
                criterionId: (string) $row['criterion_id'],
                label: (string) $row['label'],
                description: $row['description'] === null ? null : (string) $row['description'],
                points: (float) $row['points'],
                sortOrder: (int) $row['sort_order'],
            );
        }

        $byRubric = [];

        foreach ($criterionRows as $row) {
            $id = (string) $row['id'];

            $byRubric[(string) $row['rubric_id']][] = new RubricCriterion(
                id: $id,
                tenantId: (string) $row['tenant_id'],
                rubricId: (string) $row['rubric_id'],
                title: (string) $row['title'],
                description: $row['description'] === null ? null : (string) $row['description'],
                maxPoints: (float) $row['max_points'],
                sortOrder: (int) $row['sort_order'],
                levels: $levelsByCriterion[$id] ?? [],
                createdAt: $this->at($row['created_at']),
                updatedAt: $this->at($row['updated_at']),
            );
        }

        return $byRubric;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Rubric
    {
        return new Rubric(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            name: (string) $row['name'],
            description: $row['description'] === null ? null : (string) $row['description'],
            kind: RubricKind::from((string) $row['kind']),
            isActive: (bool) $row['is_active'],
            createdBy: $row['created_by'] === null ? null : (string) $row['created_by'],
            createdAt: $this->at($row['created_at']),
            updatedAt: $this->at($row['updated_at']),
        );
    }

    private function at(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) ? new \DateTimeImmutable($value) : null;
    }

    private function uuid(): string
    {
        return (string) $this->connection->fetchOne('SELECT gen_random_uuid()');
    }
}
