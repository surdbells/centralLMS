<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Catalog\CourseStatus;
use Central\Api\Domain\LearningPath\CompletionRule;
use Central\Api\Domain\LearningPath\LearningPath;
use Central\Api\Domain\LearningPath\LearningPathRepository;
use Central\Api\Domain\LearningPath\PathEnrollment;
use Central\Api\Domain\LearningPath\PathFilters;
use Central\Api\Domain\LearningPath\PathItem;
use Central\Api\Domain\LearningPath\PathItemKind;
use Central\Api\Domain\LearningPath\PathPacing;
use Central\Api\Domain\LearningPath\PathSection;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalLearningPathRepository implements LearningPathRepository
{
    private const COLUMNS = 'p.id, p.tenant_id, p.title, p.slug, p.summary, p.description,
                             p.thumbnail_url, p.category_id, p.level, p.pacing, p.is_mandatory,
                             p.completion_rule, p.completion_threshold,
                             p.certificate_template_id, p.certificate_validity_months,
                             p.estimated_hours, p.status, p.visibility, p.enrolment_type,
                             p.max_enrolments, p.tags, p.learning_objectives,
                             p.created_by, p.published_at, p.created_at';

    /**
     * The three counters the legacy stored on `learning_paths`.
     *
     * Counted here instead. Stored, they said 2 items for a path with three
     * rows and none active, and 19 enrolments against 33 rows.
     */
    private const COUNTS = "(
            SELECT COUNT(*) FROM learning_path_items i
            WHERE i.path_id = p.id AND i.deleted_at IS NULL
        ) AS item_count,
        (
            SELECT COUNT(*) FROM learning_path_enrollments e
            WHERE e.path_id = p.id AND e.deleted_at IS NULL AND e.status <> 'withdrawn'
        ) AS enrolment_count,
        (
            SELECT COUNT(*) FROM learning_path_enrollments e
            WHERE e.path_id = p.id AND e.deleted_at IS NULL AND e.status = 'completed'
        ) AS completion_count";

    public function __construct(private readonly Connection $connection)
    {
    }

    // --- authoring --------------------------------------------------------

    public function paginate(string $tenantId, PathFilters $filters, int $page, int $perPage): array
    {
        [$where, $params] = $this->conditions($tenantId, $filters);

        $total = (int) $this->connection->fetchOne(
            sprintf('SELECT COUNT(*) FROM learning_paths p WHERE %s', $where),
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s, %s, c.name AS category_name
                 FROM learning_paths p
                 LEFT JOIN lookups c ON c.id = p.category_id
                 WHERE %s
                 ORDER BY p.created_at DESC, p.id DESC
                 LIMIT :limit OFFSET :offset',
                self::COLUMNS,
                self::COUNTS,
                $where,
            ),
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $row): LearningPath => $this->hydrate($row), $rows),
            'total' => $total,
        ];
    }

    public function find(string $tenantId, string $id): ?LearningPath
    {
        return $this->findBy($tenantId, 'p.id = :value', $id);
    }

    public function findBySlug(string $tenantId, string $slug): ?LearningPath
    {
        return $this->findBy($tenantId, 'lower(p.slug) = lower(:value)', $slug);
    }

    public function slugExists(string $tenantId, string $slug, ?string $exceptId = null): bool
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM learning_paths
             WHERE tenant_id = :tenant AND lower(slug) = lower(:slug) AND deleted_at IS NULL
               AND (:exceptId::uuid IS NULL OR id <> :exceptId::uuid)',
            ['tenant' => $tenantId, 'slug' => $slug, 'exceptId' => $exceptId],
        ) > 0;
    }

    public function save(LearningPath $path): LearningPath
    {
        $parameters = [
            'tenant'      => $path->tenantId,
            'title'       => $path->title,
            'slug'        => $path->slug,
            'summary'     => $path->summary,
            'description' => $path->description,
            'thumbnail'   => $path->thumbnailUrl,
            'category'    => $path->categoryId,
            'level'       => $path->level,
            'pacing'      => $path->pacing->value,
            'mandatory'   => $path->isMandatory,
            'rule'        => $path->completionRule->value,
            'threshold'   => $path->completionThreshold,
            'template'    => $path->certificateTemplateId,
            'validity'    => $path->certificateValidityMonths,
            'hours'       => $path->estimatedHours,
            'visibility'  => $path->visibility,
            'enrolType'   => $path->enrolmentType,
            'maxEnrol'    => $path->maxEnrolments,
            'tags'        => (string) json_encode(array_values($path->tags)),
            'objectives'  => (string) json_encode(array_values($path->objectives)),
        ];

        $id = $path->id;

        if ($id === null) {
            $id = (string) $this->connection->fetchOne(
                "INSERT INTO learning_paths
                     (tenant_id, title, slug, summary, description, thumbnail_url, category_id,
                      level, pacing, is_mandatory, completion_rule, completion_threshold,
                      certificate_template_id, certificate_validity_months, estimated_hours,
                      visibility, enrolment_type, max_enrolments, tags, learning_objectives,
                      created_by, status)
                 VALUES (:tenant, :title, :slug, :summary, :description, :thumbnail, :category,
                         :level, :pacing, :mandatory, :rule, :threshold,
                         :template, :validity, :hours,
                         :visibility, :enrolType, :maxEnrol, :tags::jsonb, :objectives::jsonb,
                         :createdBy, 'draft')
                 RETURNING id",
                [...$parameters, 'createdBy' => $path->createdBy],
                ['mandatory' => ParameterType::BOOLEAN],
            );
        } else {
            $this->connection->executeStatement(
                'UPDATE learning_paths SET
                     title = :title, slug = :slug, summary = :summary, description = :description,
                     thumbnail_url = :thumbnail, category_id = :category, level = :level,
                     pacing = :pacing, is_mandatory = :mandatory,
                     completion_rule = :rule, completion_threshold = :threshold,
                     certificate_template_id = :template,
                     certificate_validity_months = :validity, estimated_hours = :hours,
                     visibility = :visibility, enrolment_type = :enrolType,
                     max_enrolments = :maxEnrol, tags = :tags::jsonb,
                     learning_objectives = :objectives::jsonb, updated_at = now()
                 WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                [...$parameters, 'id' => $id],
                ['mandatory' => ParameterType::BOOLEAN],
            );
        }

        return $this->find($path->tenantId, $id)
            ?? throw new \RuntimeException('The path vanished immediately after being saved.');
    }

    public function setStatus(string $tenantId, string $id, string $status): ?LearningPath
    {
        // Publishing stamps the date. The legacy let `status = 'published'` sit
        // beside a null `published_at`, so "when did this go live" had no
        // answer — and the check constraint now refuses that combination.
        $changed = $this->connection->executeStatement(
            "UPDATE learning_paths SET
                 status = :status,
                 published_at = CASE
                     WHEN :status2 = 'published' AND published_at IS NULL THEN now()
                     ELSE published_at
                 END,
                 updated_at = now()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL",
            ['id' => $id, 'tenant' => $tenantId, 'status' => $status, 'status2' => $status],
        );

        return $changed === 0 ? null : $this->find($tenantId, $id);
    }

    public function softDelete(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE learning_paths SET deleted_at = now(), updated_at = now()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    // --- contents ---------------------------------------------------------

    public function sections(string $tenantId, string $pathId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.tenant_id, s.path_id, s.title, s.description, s.sort_order,
                    s.is_milestone, s.min_items_to_complete, s.created_at,
                    (
                        SELECT COUNT(*) FROM learning_path_items i
                        WHERE i.section_id = s.id AND i.deleted_at IS NULL
                    ) AS item_count
             FROM learning_path_sections s
             WHERE s.path_id = :path AND s.tenant_id = :tenant AND s.deleted_at IS NULL
             ORDER BY s.sort_order, s.id',
            ['path' => $pathId, 'tenant' => $tenantId],
        );

        return array_map(fn (array $row): PathSection => $this->hydrateSection($row), $rows);
    }

    public function saveSection(PathSection $section): PathSection
    {
        $parameters = [
            'tenant'      => $section->tenantId,
            'path'        => $section->pathId,
            'title'       => $section->title,
            'description' => $section->description,
            'order'       => $section->sortOrder,
            'milestone'   => $section->isMilestone,
            'minimum'     => $section->minItemsToComplete,
        ];

        $id = $section->id;

        if ($id === null) {
            $id = (string) $this->connection->fetchOne(
                'INSERT INTO learning_path_sections
                     (tenant_id, path_id, title, description, sort_order, is_milestone, min_items_to_complete)
                 VALUES (:tenant, :path, :title, :description, :order, :milestone, :minimum)
                 RETURNING id',
                $parameters,
                ['milestone' => ParameterType::BOOLEAN],
            );
        } else {
            $this->connection->executeStatement(
                'UPDATE learning_path_sections SET
                     title = :title, description = :description, sort_order = :order,
                     is_milestone = :milestone, min_items_to_complete = :minimum, updated_at = now()
                 WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                [...$parameters, 'id' => $id],
                ['milestone' => ParameterType::BOOLEAN],
            );
        }

        foreach ($this->sections($section->tenantId, $section->pathId) as $saved) {
            if ($saved->id === $id) {
                return $saved;
            }
        }

        throw new \RuntimeException('The section vanished immediately after being saved.');
    }

    public function deleteSection(string $tenantId, string $id): bool
    {
        // The items in it survive, ungrouped — the foreign key is ON DELETE
        // SET NULL and the soft delete matches it.
        return $this->connection->transactional(
            function (Connection $tx) use ($tenantId, $id): bool {
                $removed = $tx->executeStatement(
                    'UPDATE learning_path_sections SET deleted_at = now(), updated_at = now()
                     WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                    ['id' => $id, 'tenant' => $tenantId],
                );

                if ($removed === 0) {
                    return false;
                }

                $tx->executeStatement(
                    'UPDATE learning_path_items SET section_id = NULL, updated_at = now()
                     WHERE section_id = :id AND tenant_id = :tenant',
                    ['id' => $id, 'tenant' => $tenantId],
                );

                return true;
            },
        );
    }

    public function items(string $tenantId, string $pathId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT i.*, s.title AS section_title, s.is_milestone,
                    COALESCE(co.title, a.title, ts.title, m.title) AS subject_title
             FROM learning_path_items i
             LEFT JOIN learning_path_sections s ON s.id = i.section_id AND s.deleted_at IS NULL
             LEFT JOIN courses co ON co.id = i.course_id
             LEFT JOIN assessments a ON a.id = i.assessment_id
             LEFT JOIN training_sessions ts ON ts.id = i.session_id
             LEFT JOIN media m ON m.id = i.media_id
             WHERE i.path_id = :path AND i.tenant_id = :tenant AND i.deleted_at IS NULL
             ORDER BY s.sort_order NULLS FIRST, i.sort_order, i.id',
            ['path' => $pathId, 'tenant' => $tenantId],
        );

        return array_map(fn (array $row): PathItem => $this->hydrateItem($row), $rows);
    }

    public function findItem(string $tenantId, string $id): ?PathItem
    {
        $row = $this->connection->fetchAssociative(
            'SELECT i.*, s.title AS section_title, s.is_milestone,
                    COALESCE(co.title, a.title, ts.title, m.title) AS subject_title
             FROM learning_path_items i
             LEFT JOIN learning_path_sections s ON s.id = i.section_id AND s.deleted_at IS NULL
             LEFT JOIN courses co ON co.id = i.course_id
             LEFT JOIN assessments a ON a.id = i.assessment_id
             LEFT JOIN training_sessions ts ON ts.id = i.session_id
             LEFT JOIN media m ON m.id = i.media_id
             WHERE i.id = :id AND i.tenant_id = :tenant AND i.deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrateItem($row);
    }

    public function saveItem(PathItem $item): PathItem
    {
        $parameters = [
            'tenant'      => $item->tenantId,
            'path'        => $item->pathId,
            'section'     => $item->sectionId,
            'kind'        => $item->kind->value,
            'course'      => $item->courseId,
            'assessment'  => $item->assessmentId,
            'session'     => $item->sessionId,
            'media'       => $item->mediaId,
            'url'         => $item->externalUrl,
            'title'       => $item->title,
            'description' => $item->description,
            'order'       => $item->sortOrder,
            'required'    => $item->isRequired,
            'points'      => $item->pointsValue,
            'dueDate'     => $item->dueDate?->format('Y-m-d'),
            'dueDays'     => $item->dueDaysFromEnrolment,
            'minutes'     => $item->estimatedMinutes,
        ];

        $id = $item->id;

        if ($id === null) {
            $id = $this->connection->transactional(
                function (Connection $tx) use ($parameters, $item): string {
                    $id = (string) $tx->fetchOne(
                        'INSERT INTO learning_path_items
                             (tenant_id, path_id, section_id, kind, course_id, assessment_id, session_id,
                              media_id, external_url, title, description, sort_order, is_required,
                              points_value, due_date, due_days_from_enrolment, estimated_minutes)
                         VALUES (:tenant, :path, :section, :kind, :course, :assessment, :session,
                                 :media, :url, :title, :description, :order, :required,
                                 :points, :dueDate, :dueDays, :minutes)
                         RETURNING id',
                        $parameters,
                        ['required' => ParameterType::BOOLEAN],
                    );

                    // Adding a course to a path that already has members puts
                    // them on it. Otherwise thirty people find a step they
                    // cannot open.
                    if ($item->kind === PathItemKind::Course && $item->courseId !== null) {
                        $this->enrolOnCourses($tx, $item->tenantId, $item->pathId, [], null, $item->courseId);
                    }

                    return $id;
                },
            );
        } else {
            $this->connection->executeStatement(
                'UPDATE learning_path_items SET
                     section_id = :section, title = :title, description = :description,
                     sort_order = :order, is_required = :required, points_value = :points,
                     due_date = :dueDate, due_days_from_enrolment = :dueDays,
                     estimated_minutes = :minutes, updated_at = now()
                 WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
                [...$parameters, 'id' => $id],
                ['required' => ParameterType::BOOLEAN],
            );
        }

        return $this->findItem($item->tenantId, $id)
            ?? throw new \RuntimeException('The item vanished immediately after being saved.');
    }

    public function deleteItem(string $tenantId, string $id): bool
    {
        return $this->connection->executeStatement(
            'UPDATE learning_path_items SET deleted_at = now(), updated_at = now()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL',
            ['id' => $id, 'tenant' => $tenantId],
        ) > 0;
    }

    public function reorderItems(string $tenantId, string $pathId, array $orderedIds): void
    {
        if ($orderedIds === []) {
            return;
        }

        // One statement. Reordering with a loop of updates leaves the list in
        // an intermediate order if it fails halfway, and the intermediate
        // order is what somebody's player is showing.
        $this->connection->executeStatement(
            'UPDATE learning_path_items i
             SET sort_order = ordering.position, updated_at = now()
             FROM (SELECT id, ordinality AS position
                   FROM unnest(:ids::uuid[]) WITH ORDINALITY AS t(id, ordinality)) AS ordering
             WHERE i.id = ordering.id
               AND i.path_id = :path
               AND i.tenant_id = :tenant
               AND i.deleted_at IS NULL',
            [
                'ids'    => '{' . implode(',', $orderedIds) . '}',
                'path'   => $pathId,
                'tenant' => $tenantId,
            ],
        );
    }

    // --- enrolment --------------------------------------------------------

    public function enrolments(string $tenantId, string $pathId, int $page, int $perPage): array
    {
        $total = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM learning_path_enrollments
             WHERE path_id = :path AND tenant_id = :tenant AND deleted_at IS NULL',
            ['path' => $pathId, 'tenant' => $tenantId],
        );

        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM learning_path_enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN learning_paths p ON p.id = e.path_id
                 WHERE e.path_id = :path AND e.tenant_id = :tenant AND e.deleted_at IS NULL
                 ORDER BY e.enrolled_at DESC, e.id
                 LIMIT :limit OFFSET :offset',
                $this->enrolmentColumns(),
            ),
            ['path' => $pathId, 'tenant' => $tenantId, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $row): PathEnrollment => $this->hydrateEnrolment($row), $rows),
            'total' => $total,
        ];
    }

    public function findEnrolment(string $tenantId, string $id): ?PathEnrollment
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM learning_path_enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN learning_paths p ON p.id = e.path_id
                 WHERE e.id = :id AND e.tenant_id = :tenant AND e.deleted_at IS NULL',
                $this->enrolmentColumns(),
            ),
            ['id' => $id, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrateEnrolment($row);
    }

    public function enrolmentFor(string $tenantId, string $pathId, string $userId): ?PathEnrollment
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s FROM learning_path_enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN learning_paths p ON p.id = e.path_id
                 WHERE e.path_id = :path AND e.user_id = :user
                   AND e.tenant_id = :tenant AND e.deleted_at IS NULL',
                $this->enrolmentColumns(),
            ),
            ['path' => $pathId, 'user' => $userId, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrateEnrolment($row);
    }

    public function enrol(
        string $tenantId,
        string $pathId,
        array $userIds,
        string $source,
        ?string $assignedBy,
        ?\DateTimeImmutable $dueDate,
        bool $isMandatory,
    ): array {
        if ($userIds === []) {
            return ['enrolled' => 0, 'skipped' => 0];
        }

        return $this->connection->transactional(
            function (Connection $tx) use (
                $tenantId,
                $pathId,
                $userIds,
                $source,
                $assignedBy,
                $dueDate,
                $isMandatory,
            ): array {
                // ON CONFLICT DO NOTHING against the partial unique index, so
                // enrolling a cohort twice adds nobody twice. The legacy had
                // no such index and no such clause.
                $enrolled = (int) $tx->executeStatement(
                    'INSERT INTO learning_path_enrollments
                         (tenant_id, path_id, user_id, source, assigned_by, due_date, is_mandatory)
                     SELECT :tenant, :path, u.id, :source, :assignedBy, :dueDate::date, :mandatory
                     FROM users u
                     WHERE u.id IN (:ids) AND u.tenant_id = :tenant AND u.deleted_at IS NULL
                     ON CONFLICT DO NOTHING',
                    [
                        'tenant'     => $tenantId,
                        'path'       => $pathId,
                        'ids'        => $userIds,
                        'source'     => $source,
                        'assignedBy' => $assignedBy,
                        'dueDate'    => $dueDate?->format('Y-m-d'),
                        'mandatory'  => $isMandatory,
                    ],
                    ['ids' => ArrayParameterType::STRING, 'mandatory' => ParameterType::BOOLEAN],
                );

                // And onto the courses in it, in the same transaction. A path
                // whose members cannot open its courses is a reading list.
                $this->enrolOnCourses($tx, $tenantId, $pathId, $userIds, $assignedBy);

                return ['enrolled' => $enrolled, 'skipped' => count($userIds) - $enrolled];
            },
        );
    }

    /**
     * Puts people onto the courses a path contains.
     *
     * Idempotent, so somebody already taking one of them keeps the enrolment
     * they had — and, with it, the progress that makes the path credit work
     * finished before they joined.
     *
     * @param list<string> $userIds empty means everybody already on the path
     */
    private function enrolOnCourses(
        Connection $tx,
        string $tenantId,
        string $pathId,
        array $userIds,
        ?string $assignedBy,
        ?string $onlyCourseId = null,
    ): void {
        $tx->executeStatement(
            sprintf(
                "INSERT INTO enrollments (tenant_id, course_id, user_id, source, assigned_by, is_mandatory)
                 SELECT :tenant, i.course_id, pe.user_id, 'direct', :assignedBy, i.is_required
                 FROM learning_path_items i
                 JOIN learning_path_enrollments pe
                      ON pe.path_id = i.path_id
                     AND pe.deleted_at IS NULL
                     AND pe.status <> 'withdrawn'
                 WHERE i.path_id = :path
                   AND i.tenant_id = :tenant
                   AND i.deleted_at IS NULL
                   AND i.kind = 'course'
                   AND i.course_id IS NOT NULL
                   %s
                   %s
                 ON CONFLICT DO NOTHING",
                $userIds === [] ? '' : 'AND pe.user_id IN (:ids)',
                $onlyCourseId === null ? '' : 'AND i.course_id = :courseId',
            ),
            $this->courseEnrolmentParameters($tenantId, $pathId, $userIds, $assignedBy, $onlyCourseId),
            $userIds === [] ? [] : ['ids' => ArrayParameterType::STRING],
        );
    }

    /**
     * @param  list<string>         $userIds
     * @return array<string, mixed>
     */
    private function courseEnrolmentParameters(
        string $tenantId,
        string $pathId,
        array $userIds,
        ?string $assignedBy,
        ?string $onlyCourseId,
    ): array {
        $parameters = ['tenant' => $tenantId, 'path' => $pathId, 'assignedBy' => $assignedBy];

        if ($userIds !== []) {
            $parameters['ids'] = $userIds;
        }

        if ($onlyCourseId !== null) {
            $parameters['courseId'] = $onlyCourseId;
        }

        return $parameters;
    }

    public function withdraw(string $tenantId, string $pathId, string $userId): bool
    {
        // Withdrawn rather than deleted: they were on it, and a report about
        // who dropped out needs the row.
        return $this->connection->executeStatement(
            "UPDATE learning_path_enrollments
             SET status = 'withdrawn', updated_at = now()
             WHERE path_id = :path AND user_id = :user AND tenant_id = :tenant
               AND deleted_at IS NULL AND status <> 'completed'",
            ['path' => $pathId, 'user' => $userId, 'tenant' => $tenantId],
        ) > 0;
    }

    public function markComplete(string $tenantId, string $enrolmentId, ?string $certificateId): ?PathEnrollment
    {
        $changed = $this->connection->executeStatement(
            "UPDATE learning_path_enrollments
             SET status = 'completed',
                 completed_at = COALESCE(completed_at, now()),
                 certificate_id = COALESCE(:certificate::uuid, certificate_id),
                 updated_at = now()
             WHERE id = :id AND tenant_id = :tenant AND deleted_at IS NULL AND status = 'active'",
            ['id' => $enrolmentId, 'tenant' => $tenantId, 'certificate' => $certificateId],
        );

        return $changed === 0 ? null : $this->findEnrolment($tenantId, $enrolmentId);
    }

    public function forLearner(string $tenantId, string $userId, ?string $status = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf(
                'SELECT %s FROM learning_path_enrollments e
                 JOIN users u ON u.id = e.user_id
                 JOIN learning_paths p ON p.id = e.path_id
                 WHERE e.user_id = :user AND e.tenant_id = :tenant AND e.deleted_at IS NULL
                   AND (:status::text IS NULL OR e.status = :status::text)
                 ORDER BY e.enrolled_at DESC, e.id',
                $this->enrolmentColumns(),
            ),
            ['user' => $userId, 'tenant' => $tenantId, 'status' => $status],
        );

        return array_map(fn (array $row): PathEnrollment => $this->hydrateEnrolment($row), $rows);
    }

    // --- progress ---------------------------------------------------------

    public function completionsFor(string $tenantId, string $pathId, string $userId): array
    {
        // The whole reason there is no progress table. Four sources, one
        // query, and every answer comes from a record that already exists —
        // which is why a path credits a course finished long before somebody
        // joined it.
        $rows = $this->connection->fetchAllKeyValue(
            "SELECT i.id, MIN(done.at) AS at
             FROM learning_path_items i
             LEFT JOIN LATERAL (
                 -- A course, finished.
                 SELECT ce.completed_at AS at
                 FROM enrollments ce
                 WHERE i.kind = 'course'
                   AND ce.course_id = i.course_id
                   AND ce.user_id = :user
                   AND ce.status = 'completed'
                   AND ce.deleted_at IS NULL

                 UNION ALL

                 -- An assessment, passed. Not merely attempted: the pass is
                 -- stamped at grading and never recomputed.
                 SELECT a.graded_at
                 FROM attempts a
                 WHERE i.kind = 'assessment'
                   AND a.assessment_id = i.assessment_id
                   AND a.user_id = :user
                   AND a.passed

                 UNION ALL

                 -- A session, attended. Late still counts.
                 SELECT sa.updated_at
                 FROM session_attendance sa
                 WHERE i.kind = 'session'
                   AND sa.session_id = i.session_id
                   AND sa.user_id = :user
                   AND sa.status IN ('present', 'late')

                 UNION ALL

                 -- A link or a document, marked.
                 SELECT c.completed_at
                 FROM learning_path_item_completions c
                 JOIN learning_path_enrollments pe ON pe.id = c.enrollment_id
                 WHERE c.item_id = i.id
                   AND pe.user_id = :user
                   AND pe.deleted_at IS NULL
             ) done ON TRUE
             WHERE i.path_id = :path AND i.tenant_id = :tenant AND i.deleted_at IS NULL
             GROUP BY i.id
             HAVING MIN(done.at) IS NOT NULL",
            ['path' => $pathId, 'tenant' => $tenantId, 'user' => $userId],
        );

        $completions = [];

        foreach ($rows as $itemId => $at) {
            $completions[(string) $itemId] = $this->instant($at) ?? '';
        }

        return $completions;
    }

    public function markItem(
        string $tenantId,
        string $enrolmentId,
        string $itemId,
        string $source,
        ?string $completedBy,
        ?string $note,
    ): bool {
        return $this->connection->executeStatement(
            'INSERT INTO learning_path_item_completions
                 (enrollment_id, item_id, tenant_id, source, completed_by, note)
             VALUES (:enrolment, :item, :tenant, :source, :by, :note)
             ON CONFLICT (enrollment_id, item_id) DO NOTHING',
            [
                'enrolment' => $enrolmentId,
                'item'      => $itemId,
                'tenant'    => $tenantId,
                'source'    => $source,
                'by'        => $completedBy,
                'note'      => $note,
            ],
        ) > 0;
    }

    public function unmarkItem(string $tenantId, string $enrolmentId, string $itemId): bool
    {
        return $this->connection->executeStatement(
            'DELETE FROM learning_path_item_completions
             WHERE enrollment_id = :enrolment AND item_id = :item AND tenant_id = :tenant',
            ['enrolment' => $enrolmentId, 'item' => $itemId, 'tenant' => $tenantId],
        ) > 0;
    }

    public function statistics(string $tenantId): array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT
                COUNT(*) AS paths,
                COUNT(*) FILTER (WHERE status = 'published') AS published,
                COUNT(*) FILTER (WHERE is_mandatory) AS mandatory,
                (
                    SELECT COUNT(*) FROM learning_path_enrollments e
                    WHERE e.tenant_id = :tenant AND e.deleted_at IS NULL AND e.status <> 'withdrawn'
                ) AS enrolments,
                (
                    SELECT COUNT(*) FROM learning_path_enrollments e
                    WHERE e.tenant_id = :tenant AND e.deleted_at IS NULL AND e.status = 'completed'
                ) AS completions
             FROM learning_paths
             WHERE tenant_id = :tenant AND deleted_at IS NULL",
            ['tenant' => $tenantId],
        );

        $row = $row === false ? [] : $row;

        return [
            'paths'       => (int) ($row['paths'] ?? 0),
            'published'   => (int) ($row['published'] ?? 0),
            'mandatory'   => (int) ($row['mandatory'] ?? 0),
            'enrolments'  => (int) ($row['enrolments'] ?? 0),
            'completions' => (int) ($row['completions'] ?? 0),
        ];
    }

    // --- helpers ----------------------------------------------------------

    private function findBy(string $tenantId, string $clause, string $value): ?LearningPath
    {
        $row = $this->connection->fetchAssociative(
            sprintf(
                'SELECT %s, %s, c.name AS category_name
                 FROM learning_paths p
                 LEFT JOIN lookups c ON c.id = p.category_id
                 WHERE %s AND p.tenant_id = :tenant AND p.deleted_at IS NULL',
                self::COLUMNS,
                self::COUNTS,
                $clause,
            ),
            ['value' => $value, 'tenant' => $tenantId],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    private function enrolmentColumns(): string
    {
        return "e.id, e.tenant_id, e.path_id, e.user_id, e.status, e.source, e.is_mandatory,
                e.assigned_by, e.due_date, e.enrolled_at, e.started_at, e.completed_at,
                e.certificate_id,
                p.title AS path_title,
                u.email AS learner_email,
                NULLIF(btrim(COALESCE(u.first_name, '') || ' ' || COALESCE(u.last_name, '')), '')
                    AS learner_name";
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function conditions(string $tenantId, PathFilters $filters): array
    {
        $where  = ['p.tenant_id = :tenant', 'p.deleted_at IS NULL'];
        $params = ['tenant' => $tenantId];

        if ($filters->status !== null) {
            $where[]          = 'p.status = :status';
            $params['status'] = $filters->status->value;
        }

        if ($filters->catalogueOnly) {
            $where[] = "p.visibility <> 'private'";
        }

        if ($filters->categoryId !== null) {
            $where[]              = 'p.category_id = :categoryId';
            $params['categoryId'] = $filters->categoryId;
        }

        if ($filters->pacing !== null) {
            $where[]          = 'p.pacing = :pacing';
            $params['pacing'] = $filters->pacing->value;
        }

        if ($filters->mandatoryOnly !== null) {
            $where[] = $filters->mandatoryOnly ? 'p.is_mandatory' : 'NOT p.is_mandatory';
        }

        if ($filters->search !== null) {
            $where[]          = '(p.title ILIKE :search OR p.summary ILIKE :search
                                  OR p.description ILIKE :search)';
            $params['search'] = '%' . $filters->search . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): LearningPath
    {
        return new LearningPath(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            title: (string) $row['title'],
            slug: (string) $row['slug'],
            status: CourseStatus::from((string) $row['status']),
            pacing: PathPacing::from((string) $row['pacing']),
            completionRule: CompletionRule::from((string) $row['completion_rule']),
            completionThreshold: (float) $row['completion_threshold'],
            level: (string) $row['level'],
            visibility: (string) $row['visibility'],
            enrolmentType: (string) $row['enrolment_type'],
            isMandatory: (bool) $row['is_mandatory'],
            summary: $this->text($row['summary'] ?? null),
            description: $this->text($row['description'] ?? null),
            thumbnailUrl: $this->text($row['thumbnail_url'] ?? null),
            categoryId: $this->text($row['category_id'] ?? null),
            certificateTemplateId: $this->text($row['certificate_template_id'] ?? null),
            certificateValidityMonths: $this->int($row['certificate_validity_months'] ?? null),
            estimatedHours: ($row['estimated_hours'] ?? null) === null ? null : (float) $row['estimated_hours'],
            maxEnrolments: $this->int($row['max_enrolments'] ?? null),
            tags: $this->list($row['tags'] ?? null),
            objectives: $this->list($row['learning_objectives'] ?? null),
            createdBy: $this->text($row['created_by'] ?? null),
            publishedAt: $this->date($row['published_at'] ?? null),
            createdAt: $this->date($row['created_at'] ?? null),
            categoryName: $this->text($row['category_name'] ?? null),
            itemCount: (int) ($row['item_count'] ?? 0),
            enrolmentCount: (int) ($row['enrolment_count'] ?? 0),
            completionCount: (int) ($row['completion_count'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateSection(array $row): PathSection
    {
        return new PathSection(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            pathId: (string) $row['path_id'],
            title: (string) $row['title'],
            description: $this->text($row['description'] ?? null),
            sortOrder: (int) ($row['sort_order'] ?? 0),
            isMilestone: (bool) $row['is_milestone'],
            minItemsToComplete: $this->int($row['min_items_to_complete'] ?? null),
            createdAt: $this->date($row['created_at'] ?? null),
            itemCount: (int) ($row['item_count'] ?? 0),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateItem(array $row): PathItem
    {
        return new PathItem(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            pathId: (string) $row['path_id'],
            kind: PathItemKind::from((string) $row['kind']),
            sectionId: $this->text($row['section_id'] ?? null),
            courseId: $this->text($row['course_id'] ?? null),
            assessmentId: $this->text($row['assessment_id'] ?? null),
            sessionId: $this->text($row['session_id'] ?? null),
            mediaId: $this->text($row['media_id'] ?? null),
            externalUrl: $this->text($row['external_url'] ?? null),
            title: $this->text($row['title'] ?? null),
            description: $this->text($row['description'] ?? null),
            sortOrder: (int) ($row['sort_order'] ?? 0),
            isRequired: (bool) $row['is_required'],
            pointsValue: (int) ($row['points_value'] ?? 0),
            dueDate: $this->date($row['due_date'] ?? null),
            dueDaysFromEnrolment: $this->int($row['due_days_from_enrolment'] ?? null),
            estimatedMinutes: $this->int($row['estimated_minutes'] ?? null),
            subjectTitle: $this->text($row['subject_title'] ?? null),
            sectionTitle: $this->text($row['section_title'] ?? null),
            isMilestoneSection: (bool) ($row['is_milestone'] ?? false),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateEnrolment(array $row): PathEnrollment
    {
        return new PathEnrollment(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            pathId: (string) $row['path_id'],
            userId: (string) $row['user_id'],
            status: (string) $row['status'],
            source: (string) $row['source'],
            isMandatory: (bool) $row['is_mandatory'],
            assignedBy: $this->text($row['assigned_by'] ?? null),
            dueDate: $this->date($row['due_date'] ?? null),
            enrolledAt: $this->date($row['enrolled_at'] ?? null),
            startedAt: $this->date($row['started_at'] ?? null),
            completedAt: $this->date($row['completed_at'] ?? null),
            certificateId: $this->text($row['certificate_id'] ?? null),
            pathTitle: $this->text($row['path_title'] ?? null),
            learnerName: $this->text($row['learner_name'] ?? null),
            learnerEmail: $this->text($row['learner_email'] ?? null),
        );
    }

    /**
     * @return list<string>
     */
    private function list(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values(array_map(strval(...), $decoded)) : [];
    }

    private function instant(mixed $value): ?string
    {
        return $this->date($value)?->format(\DateTimeInterface::ATOM);
    }

    private function int(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
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
