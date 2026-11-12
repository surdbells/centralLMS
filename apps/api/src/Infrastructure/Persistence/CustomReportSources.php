<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Report\CustomField;
use Central\Api\Domain\Report\CustomFilter;
use Central\Api\Domain\Report\CustomReportSpec;
use Central\Api\Domain\Report\CustomSource;
use Central\Api\Domain\Report\ReportCriteria;
use Central\Api\Domain\Report\ReportValueType;

/**
 * The custom report builder's data sources, and the SQL behind each key.
 *
 * Pure: no connection, so the statement a spec produces can be checked
 * without a database. {@see DbalCustomReportRepository} runs it.
 *
 * The metadata a client sees and the SQL that answers it are declared side by
 * side in {@see definitions()}, one entry per key, so the two cannot drift.
 * A request only ever names keys — validated by {@see CustomReportSpec} — and
 * values, which are bound. Nothing a caller sends is interpolated.
 *
 * Every statement begins with {@see ReportQuery::tenant()}: the tenant from
 * the token, and for the sources whose rows are people, the caller's row
 * scope (themselves and their direct reports, unless they may see everyone).
 *
 * @phpstan-type FieldDef array{0: string, 1: ReportValueType, 2: ?string, 3: string}
 * @phpstan-type FilterDef array{0: CustomFilter, 1: ?string}
 * @phpstan-type SourceDef array{
 *     key: string,
 *     label: string,
 *     description: string,
 *     namesPeople: bool,
 *     from: string,
 *     tenantAlias: string,
 *     userAlias: ?string,
 *     dateColumn: string,
 *     where: list<string>,
 *     tieBreak: string,
 *     defaultSort: string,
 *     defaultDirection: string,
 *     fields: array<string, FieldDef>,
 *     groupings: array<string, array{0: string, 1: string}>,
 *     filters: array<string, FilterDef>,
 *     defaultColumns: list<string>
 * }
 */
final class CustomReportSources
{
    private const LEARNER = "trim(u.first_name || ' ' || u.last_name)";

    /** The organisational lookups every person-based source joins. */
    private const PEOPLE_JOINS = '
        LEFT JOIN lookups d  ON d.id  = u.department_id
        LEFT JOIN lookups t  ON t.id  = u.team_id
        LEFT JOIN lookups b  ON b.id  = u.branch_id
        LEFT JOIN lookups jt ON jt.id = u.job_title_id';

    /** @var array<string, SourceDef>|null */
    private ?array $definitions = null;

    /**
     * @return list<CustomSource>
     */
    public function sources(): array
    {
        return array_values(array_map($this->toSource(...), $this->definitions()));
    }

    public function source(string $key): ?CustomSource
    {
        $definition = $this->definitions()[$key] ?? null;

        return $definition === null ? null : $this->toSource($definition);
    }

    /**
     * The SQL for one run of a spec: a count and a page of rows.
     *
     * @return array{count: string, rows: string, params: array<string, mixed>, types: array<string, mixed>}
     */
    public function statement(CustomReportSpec $spec, ReportCriteria $criteria): array
    {
        $def = $this->definitions()[$spec->source->key]
            ?? throw new \InvalidArgumentException(sprintf('No custom source "%s".', $spec->source->key));

        $q = ReportQuery::tenant($criteria, $def['tenantAlias'], $def['userAlias'])
            ->betweenDates($criteria, $def['dateColumn']);

        foreach ($def['where'] as $clause) {
            $q->where($clause);
        }

        foreach ($spec->rowFilters() as $key => $value) {
            $clause = $def['filters'][$key][1] ?? null;

            if ($clause !== null) {
                $q->where($clause, 'f_' . $key, $value);
            }
        }

        // A source whose rows are not people (the course catalogue) still
        // aggregates over people inside its FROM: those enrolments get the
        // caller's row scope too, or a team lead would read organisation-wide
        // completion and scores per course.
        [$from, $scopeParams] = $this->withEnrolleeScope($def['from'], $criteria);

        $params = $q->paramsWith(['limit' => $spec->perPage, 'offset' => 0, ...$scopeParams]);

        if ($spec->groupBy === null) {
            return [
                'count'  => sprintf('SELECT COUNT(*) FROM %s WHERE %s', $from, $q->sql()),
                'rows'   => sprintf(
                    'SELECT %s FROM %s WHERE %s ORDER BY %s NULLS LAST, %s LIMIT :limit OFFSET :offset',
                    $this->select($spec, $def),
                    $from,
                    $q->sql(),
                    // The expression rather than its alias: the default sort
                    // column need not be one of the columns chosen.
                    $spec->sortBy === null
                        ? sprintf('%s %s', $def['fields'][$def['defaultSort']][3], $def['defaultDirection'])
                        : sprintf('%s %s', $def['fields'][$spec->sortBy][3], strtoupper($spec->sortDirection)),
                    $def['tieBreak'],
                ),
                'params' => $params,
                'types'  => $q->types(),
            ];
        }

        $group = $this->groupExpression($def, $spec->groupBy);

        return [
            'count'  => sprintf(
                'SELECT COUNT(*) FROM (SELECT 1 FROM %s WHERE %s GROUP BY %s) AS custom_groups',
                $from,
                $q->sql(),
                $group,
            ),
            'rows'   => sprintf(
                'SELECT %s FROM %s WHERE %s GROUP BY 1 ORDER BY %s NULLS LAST, 1 LIMIT :limit OFFSET :offset',
                $this->select($spec, $def),
                $from,
                $q->sql(),
                // The biggest groups first unless asked otherwise: a breakdown
                // is usually read for where most of the rows are.
                $spec->sortBy === null
                    ? sprintf('"%s" DESC', CustomReportSpec::RECORDS)
                    : sprintf('"%s" %s', $spec->sortBy, strtoupper($spec->sortDirection)),
            ),
            'params' => $params,
            'types'  => $q->types(),
        ];
    }

    /**
     * Fills a source's `{enrolleeScope}` placeholder — the enrolments a
     * people-free source aggregates over (alias `eu` for their user) — with the
     * same row scope {@see ReportQuery::tenant()} gives people-based sources:
     * one named person; else, for a caller who may not see everyone, themselves
     * and their direct reports; else nothing extra. Parameters are returned
     * only when the placeholder is present, since every one bound must be used.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function withEnrolleeScope(string $from, ReportCriteria $criteria): array
    {
        if (!str_contains($from, '{enrolleeScope}')) {
            return [$from, []];
        }

        $scope = $criteria->scope;

        if ($scope->subjectId !== null) {
            return [
                str_replace('{enrolleeScope}', 'AND eu.id = :enrolleeSubject', $from),
                ['enrolleeSubject' => $scope->subjectId],
            ];
        }

        if (!$scope->tenantWide) {
            return [
                str_replace('{enrolleeScope}', 'AND (eu.id = :enrolleeViewer OR eu.manager_id = :enrolleeViewer)', $from),
                ['enrolleeViewer' => $scope->viewerId],
            ];
        }

        return [str_replace('{enrolleeScope}', '', $from), []];
    }

    /**
     * @param SourceDef $def
     */
    private function select(CustomReportSpec $spec, array $def): string
    {
        $parts = [];

        if ($spec->groupBy !== null) {
            $parts[] = sprintf('%s AS "%s"', $this->groupExpression($def, $spec->groupBy), $spec->groupBy);
            $parts[] = sprintf('COUNT(*) AS "%s"', CustomReportSpec::RECORDS);
        }

        foreach ($spec->fields() as $field) {
            $sql = $def['fields'][$field->key][3];

            $parts[] = sprintf(
                '%s AS "%s"',
                $spec->groupBy === null ? $sql : $this->aggregate($field, $sql),
                $field->key,
            );
        }

        return implode(",\n       ", $parts);
    }

    /**
     * @param SourceDef $def
     */
    private function groupExpression(array $def, string $groupBy): string
    {
        // A person with no department still counts; they are shown under a
        // group of their own rather than vanishing from the breakdown.
        return sprintf("COALESCE(CAST((%s) AS TEXT), 'Not set')", $def['groupings'][$groupBy][1]);
    }

    private function aggregate(CustomField $field, string $sql): string
    {
        return match ($field->aggregate) {
            // Whole seconds: a duration of 5,412.3 seconds is not more precise,
            // only harder to read.
            'avg' => $field->type === ReportValueType::Duration
                ? sprintf('ROUND(AVG(%s))::bigint', $sql)
                : sprintf('ROUND(AVG(%s)::numeric, 1)', $sql),
            'sum' => sprintf('COALESCE(SUM(%s), 0)', $sql),
            'min' => sprintf('MIN(%s)', $sql),
            'max' => sprintf('MAX(%s)', $sql),
            default => throw new \LogicException(sprintf('"%s" has no aggregate.', $field->key)),
        };
    }

    /**
     * @param SourceDef $def
     */
    private function toSource(array $def): CustomSource
    {
        $fields = [];

        foreach ($def['fields'] as $key => [$label, $type, $aggregate]) {
            $fields[] = new CustomField($key, $label, $type, $aggregate);
        }

        $groupings = [];

        foreach ($def['groupings'] as $key => [$label]) {
            $groupings[$key] = $label;
        }

        return new CustomSource(
            $def['key'],
            $def['label'],
            $def['description'],
            $fields,
            $groupings,
            array_values(array_map(static fn (array $f): CustomFilter => $f[0], $def['filters'])),
            $def['defaultColumns'],
            $def['namesPeople'],
        );
    }

    // --- the sources ------------------------------------------------------

    /**
     * @return array<string, SourceDef>
     */
    private function definitions(): array
    {
        return $this->definitions ??= [
            'enrollments'  => $this->enrollments(),
            'compliance'   => $this->compliance(),
            'learners'     => $this->learners(),
            'courses'      => $this->courses(),
            'assessments'  => $this->assessments(),
            'certificates' => $this->certificates(),
        ];
    }

    /**
     * @return SourceDef
     */
    private function enrollments(): array
    {
        return [
            'key'              => 'enrollments',
            'label'            => 'Course enrolments',
            'description'      => 'One row per learner per course: status, progress, score and dates.',
            'namesPeople'      => true,
            'from'             => 'enrollments e
                JOIN users u   ON u.id = e.user_id
                JOIN courses c ON c.id = e.course_id
                LEFT JOIN lookups cat ON cat.id = c.category_id' . self::PEOPLE_JOINS,
            'tenantAlias'      => 'e',
            'userAlias'        => 'u',
            'dateColumn'       => 'e.enrolled_at',
            'where'            => ['e.deleted_at IS NULL'],
            'tieBreak'         => 'e.id',
            'defaultSort'      => 'enrolled_at',
            'defaultDirection' => 'DESC',
            'fields'           => [
                ...$this->peopleFields(),
                'course'          => ['Course', ReportValueType::Text, null, 'c.title'],
                'course_category' => ['Course category', ReportValueType::Text, null, 'cat.name'],
                'status'          => ['Status', ReportValueType::Status, null, 'e.status'],
                'mandatory'       => ['Mandatory', ReportValueType::Status, null, "CASE WHEN (e.is_mandatory OR c.is_mandatory) THEN 'Mandatory' ELSE 'Optional' END"],
                'progress'        => ['Progress', ReportValueType::Percent, 'avg', 'e.progress_percentage'],
                'score'           => ['Score', ReportValueType::Number, 'avg', 'e.final_score'],
                'time_spent'      => ['Time spent', ReportValueType::Duration, 'sum', 'e.time_spent_seconds'],
                'enrolled_at'     => ['Enrolled', ReportValueType::Date, null, 'e.enrolled_at'],
                'due_date'        => ['Due', ReportValueType::Date, null, 'e.due_date'],
                'completed_at'    => ['Completed', ReportValueType::Date, null, 'e.completed_at'],
            ],
            'groupings'        => [
                ...$this->peopleGroupings(),
                'course'          => ['Course', 'c.title'],
                'course_category' => ['Course category', 'cat.name'],
                'status'          => ['Status', 'e.status'],
                'mandatory'       => ['Mandatory', "CASE WHEN (e.is_mandatory OR c.is_mandatory) THEN 'Mandatory' ELSE 'Optional' END"],
                'enrolled_month'  => ['Month enrolled', "to_char(date_trunc('month', e.enrolled_at), 'YYYY-MM')"],
            ],
            'filters'          => [
                'from'     => [CustomFilter::from('Enrolled from'), null],
                'to'       => [CustomFilter::to('Enrolled to'), null],
                ...$this->peopleFilters(),
                'courseId' => [CustomFilter::course(), 'e.course_id = :f_courseId'],
                'status'   => [
                    CustomFilter::select('status', 'Status', [
                        'not_started' => 'Not started',
                        'in_progress' => 'In progress',
                        'completed'   => 'Completed',
                        'dropped'     => 'Dropped',
                    ]),
                    'e.status = :f_status',
                ],
            ],
            'defaultColumns'   => ['learner', 'department', 'course', 'status', 'progress', 'enrolled_at'],
        ];
    }

    /**
     * @return SourceDef
     */
    private function compliance(): array
    {
        // due_date is a DATE: compare with CURRENT_DATE, not now(), or training
        // due today reads as overdue from midnight (as the other reports avoid).
        $flag = "CASE
                    WHEN e.status = 'completed' THEN 'compliant'
                    WHEN e.due_date IS NOT NULL AND e.due_date < CURRENT_DATE THEN 'overdue'
                    ELSE 'pending'
                 END";

        return [
            'key'              => 'compliance',
            'label'            => 'Compliance training',
            'description'      => 'Mandatory training only, with whether each learner is compliant, pending or overdue.',
            'namesPeople'      => true,
            'from'             => 'enrollments e
                JOIN users u   ON u.id = e.user_id
                JOIN courses c ON c.id = e.course_id' . self::PEOPLE_JOINS,
            'tenantAlias'      => 'e',
            'userAlias'        => 'u',
            'dateColumn'       => 'e.due_date',
            'where'            => [
                'e.deleted_at IS NULL',
                // Somebody taken off a course is not non-compliant with it.
                "e.status <> 'dropped'",
                '(e.is_mandatory OR c.is_mandatory)',
            ],
            'tieBreak'         => 'e.id',
            'defaultSort'      => 'due_date',
            'defaultDirection' => 'ASC',
            'fields'           => [
                ...$this->peopleFields(),
                'course'       => ['Course', ReportValueType::Text, null, 'c.title'],
                'compliance'   => ['Compliance', ReportValueType::Status, null, $flag],
                'status'       => ['Status', ReportValueType::Status, null, 'e.status'],
                'progress'     => ['Progress', ReportValueType::Percent, 'avg', 'e.progress_percentage'],
                'days_overdue' => [
                    'Days overdue',
                    ReportValueType::Number,
                    'max',
                    "CASE WHEN e.status <> 'completed' AND e.due_date < CURRENT_DATE
                          THEN (current_date - e.due_date::date) ELSE 0 END",
                ],
                'due_date'     => ['Due', ReportValueType::Date, null, 'e.due_date'],
                'completed_at' => ['Completed', ReportValueType::Date, null, 'e.completed_at'],
            ],
            'groupings'        => [
                ...$this->peopleGroupings(),
                'course'     => ['Course', 'c.title'],
                'compliance' => ['Compliance', $flag],
                'due_month'  => ['Month due', "to_char(date_trunc('month', e.due_date), 'YYYY-MM')"],
            ],
            'filters'          => [
                'from'       => [CustomFilter::from('Due from'), null],
                'to'         => [CustomFilter::to('Due to'), null],
                ...$this->peopleFilters(),
                'courseId'   => [CustomFilter::course(), 'e.course_id = :f_courseId'],
                'compliance' => [
                    CustomFilter::select('compliance', 'Compliance', [
                        'compliant' => 'Compliant',
                        'pending'   => 'Pending',
                        'overdue'   => 'Overdue',
                    ]),
                    sprintf('(%s) = :f_compliance', $flag),
                ],
            ],
            'defaultColumns'   => ['learner', 'department', 'course', 'compliance', 'due_date', 'days_overdue'],
        ];
    }

    /**
     * @return SourceDef
     */
    private function learners(): array
    {
        return [
            'key'              => 'learners',
            'label'            => 'People',
            'description'      => 'One row per person: where they sit in the organisation and their training totals.',
            'namesPeople'      => true,
            'from'             => 'users u' . self::PEOPLE_JOINS . '
                LEFT JOIN LATERAL (
                    SELECT COUNT(*) FILTER (WHERE e.status <> \'dropped\')  AS enrolments,
                           COUNT(*) FILTER (WHERE e.status = \'completed\') AS completed,
                           COALESCE(SUM(e.time_spent_seconds), 0)          AS seconds
                    FROM enrollments e
                    WHERE e.user_id = u.id AND e.deleted_at IS NULL
                ) es ON TRUE',
            'tenantAlias'      => 'u',
            'userAlias'        => 'u',
            'dateColumn'       => 'u.created_at',
            'where'            => ['u.deleted_at IS NULL'],
            'tieBreak'         => 'u.id',
            'defaultSort'      => 'learner',
            'defaultDirection' => 'ASC',
            'fields'           => [
                ...$this->peopleFields(),
                'status'        => ['Account status', ReportValueType::Status, null, 'u.status'],
                'last_login_at' => ['Last signed in', ReportValueType::Date, null, 'u.last_login_at'],
                'logins'        => ['Sign-ins', ReportValueType::Number, 'sum', 'u.login_count'],
                'enrolments'    => ['Enrolments', ReportValueType::Number, 'sum', 'es.enrolments'],
                'completed'     => ['Courses completed', ReportValueType::Number, 'sum', 'es.completed'],
                'time_spent'    => ['Time spent learning', ReportValueType::Duration, 'sum', 'es.seconds'],
                'joined_at'     => ['Joined', ReportValueType::Date, null, 'u.created_at'],
            ],
            'groupings'        => [
                ...$this->peopleGroupings(),
                'status'       => ['Account status', 'u.status'],
                'joined_month' => ['Month joined', "to_char(date_trunc('month', u.created_at), 'YYYY-MM')"],
            ],
            'filters'          => [
                'from'   => [CustomFilter::from('Joined from'), null],
                'to'     => [CustomFilter::to('Joined to'), null],
                ...$this->peopleFilters(),
                'status' => [
                    CustomFilter::select('status', 'Account status', [
                        'active'    => 'Active',
                        'invited'   => 'Invited',
                        'suspended' => 'Suspended',
                        'locked'    => 'Locked',
                    ]),
                    'u.status = :f_status',
                ],
            ],
            'defaultColumns'   => ['learner', 'email', 'department', 'enrolments', 'completed', 'last_login_at'],
        ];
    }

    /**
     * @return SourceDef
     */
    private function courses(): array
    {
        return [
            'key'              => 'courses',
            'label'            => 'Course catalogue',
            'description'      => 'One row per course, with how many people are on it and how many have finished.',
            // Counts, never names: a course row says how many, not who.
            'namesPeople'      => false,
            'from'             => 'courses c
                LEFT JOIN lookups cat ON cat.id = c.category_id
                LEFT JOIN LATERAL (
                    SELECT COUNT(*) FILTER (WHERE e.status <> \'dropped\')  AS enrolled,
                           COUNT(*) FILTER (WHERE e.status = \'completed\') AS completed,
                           AVG(e.final_score)                              AS avg_score
                    FROM enrollments e
                    JOIN users eu ON eu.id = e.user_id
                    WHERE e.course_id = c.id AND e.deleted_at IS NULL {enrolleeScope}
                ) cs ON TRUE',
            'tenantAlias'      => 'c',
            'userAlias'        => null,
            'dateColumn'       => 'c.created_at',
            'where'            => ['c.deleted_at IS NULL'],
            'tieBreak'         => 'c.id',
            'defaultSort'      => 'course',
            'defaultDirection' => 'ASC',
            'fields'           => [
                'course'          => ['Course', ReportValueType::Text, null, 'c.title'],
                'category'        => ['Category', ReportValueType::Text, null, 'cat.name'],
                'status'          => ['Status', ReportValueType::Status, null, 'c.status'],
                'level'           => ['Level', ReportValueType::Text, null, 'c.level'],
                'mandatory'       => ['Mandatory', ReportValueType::Status, null, "CASE WHEN c.is_mandatory THEN 'Mandatory' ELSE 'Optional' END"],
                'duration'        => ['Length', ReportValueType::Duration, 'sum', 'COALESCE(c.duration_minutes, 0) * 60'],
                'enrolled'        => ['Enrolled', ReportValueType::Number, 'sum', 'cs.enrolled'],
                'completed'       => ['Completed', ReportValueType::Number, 'sum', 'cs.completed'],
                'completion_rate' => [
                    'Completion rate',
                    ReportValueType::Percent,
                    'avg',
                    'CASE WHEN cs.enrolled = 0 THEN 0 ELSE ROUND(100.0 * cs.completed / cs.enrolled, 1) END',
                ],
                'average_score'   => ['Average score', ReportValueType::Number, 'avg', 'ROUND(cs.avg_score::numeric, 1)'],
                'published_at'    => ['Published', ReportValueType::Date, null, 'c.published_at'],
                'created_at'      => ['Created', ReportValueType::Date, null, 'c.created_at'],
            ],
            'groupings'        => [
                'category'      => ['Category', 'cat.name'],
                'status'        => ['Status', 'c.status'],
                'level'         => ['Level', 'c.level'],
                'mandatory'     => ['Mandatory', "CASE WHEN c.is_mandatory THEN 'Mandatory' ELSE 'Optional' END"],
                'created_month' => ['Month created', "to_char(date_trunc('month', c.created_at), 'YYYY-MM')"],
            ],
            'filters'          => [
                'from'       => [CustomFilter::from('Created from'), null],
                'to'         => [CustomFilter::to('Created to'), null],
                'categoryId' => [CustomFilter::lookup('categoryId', 'Category', 'category'), 'c.category_id = :f_categoryId'],
                'status'     => [
                    CustomFilter::select('status', 'Status', [
                        'draft'     => 'Draft',
                        'published' => 'Published',
                        'archived'  => 'Archived',
                    ]),
                    'c.status = :f_status',
                ],
            ],
            'defaultColumns'   => ['course', 'category', 'status', 'enrolled', 'completed', 'completion_rate'],
        ];
    }

    /**
     * @return SourceDef
     */
    private function assessments(): array
    {
        $outcome = "CASE WHEN at.passed IS TRUE THEN 'passed' WHEN at.passed IS FALSE THEN 'failed' ELSE 'not_marked' END";

        return [
            'key'              => 'assessments',
            'label'            => 'Assessment attempts',
            'description'      => 'One row per attempt: who sat it, the score and whether they passed.',
            'namesPeople'      => true,
            'from'             => 'attempts at
                JOIN users u       ON u.id = at.user_id
                JOIN assessments a ON a.id = at.assessment_id
                LEFT JOIN courses c ON c.id = a.course_id' . self::PEOPLE_JOINS,
            'tenantAlias'      => 'at',
            'userAlias'        => 'u',
            'dateColumn'       => 'at.submitted_at',
            'where'            => ['a.deleted_at IS NULL'],
            'tieBreak'         => 'at.id',
            'defaultSort'      => 'submitted_at',
            'defaultDirection' => 'DESC',
            'fields'           => [
                ...$this->peopleFields(),
                'assessment'   => ['Assessment', ReportValueType::Text, null, 'a.title'],
                'course'       => ['Course', ReportValueType::Text, null, 'c.title'],
                'attempt'      => ['Attempt', ReportValueType::Number, 'max', 'at.attempt_number'],
                'score'        => ['Score', ReportValueType::Number, 'avg', 'at.raw_score'],
                'percentage'   => ['Percentage', ReportValueType::Percent, 'avg', 'at.percentage'],
                'outcome'      => ['Outcome', ReportValueType::Status, null, $outcome],
                'time_taken'   => ['Time taken', ReportValueType::Duration, 'avg', 'at.time_spent_seconds'],
                'submitted_at' => ['Submitted', ReportValueType::Date, null, 'at.submitted_at'],
            ],
            'groupings'        => [
                ...$this->peopleGroupings(),
                'assessment'      => ['Assessment', 'a.title'],
                'course'          => ['Course', 'c.title'],
                'outcome'         => ['Outcome', $outcome],
                'submitted_month' => ['Month submitted', "to_char(date_trunc('month', at.submitted_at), 'YYYY-MM')"],
            ],
            'filters'          => [
                'from'     => [CustomFilter::from('Submitted from'), null],
                'to'       => [CustomFilter::to('Submitted to'), null],
                ...$this->peopleFilters(),
                'courseId' => [CustomFilter::course(), 'a.course_id = :f_courseId'],
                'outcome'  => [
                    CustomFilter::select('outcome', 'Outcome', [
                        'passed'     => 'Passed',
                        'failed'     => 'Failed',
                        'not_marked' => 'Not marked',
                    ]),
                    sprintf('(%s) = :f_outcome', $outcome),
                ],
            ],
            'defaultColumns'   => ['learner', 'assessment', 'attempt', 'percentage', 'outcome', 'submitted_at'],
        ];
    }

    /**
     * @return SourceDef
     */
    private function certificates(): array
    {
        return [
            'key'              => 'certificates',
            'label'            => 'Certificates',
            'description'      => 'Every certificate issued: to whom, for what, and until when it is valid.',
            'namesPeople'      => true,
            'from'             => 'certificates cert
                JOIN users u ON u.id = cert.user_id' . self::PEOPLE_JOINS,
            'tenantAlias'      => 'cert',
            'userAlias'        => 'u',
            'dateColumn'       => 'cert.issued_at',
            'where'            => [],
            'tieBreak'         => 'cert.id',
            'defaultSort'      => 'issued_at',
            'defaultDirection' => 'DESC',
            'fields'           => [
                ...$this->peopleFields(),
                'number'      => ['Certificate number', ReportValueType::Text, null, 'cert.number'],
                'title'       => ['Awarded for', ReportValueType::Text, null, 'cert.title'],
                'source'      => ['Earned through', ReportValueType::Text, null, 'cert.source'],
                'score'       => ['Score', ReportValueType::Percent, 'avg', 'cert.percentage'],
                'status'      => ['Status', ReportValueType::Status, null, 'cert.status'],
                'issued_at'   => ['Issued', ReportValueType::Date, null, 'cert.issued_at'],
                'valid_until' => ['Valid until', ReportValueType::Date, null, 'cert.valid_until'],
            ],
            'groupings'        => [
                ...$this->peopleGroupings(),
                'title'        => ['Awarded for', 'cert.title'],
                'source'       => ['Earned through', 'cert.source'],
                'status'       => ['Status', 'cert.status'],
                'issued_month' => ['Month issued', "to_char(date_trunc('month', cert.issued_at), 'YYYY-MM')"],
            ],
            'filters'          => [
                'from'     => [CustomFilter::from('Issued from'), null],
                'to'       => [CustomFilter::to('Issued to'), null],
                ...$this->peopleFilters(),
                'courseId' => [CustomFilter::course(), 'cert.course_id = :f_courseId'],
                'status'   => [
                    CustomFilter::select('status', 'Status', [
                        'active'     => 'Active',
                        'expired'    => 'Expired',
                        'revoked'    => 'Revoked',
                        'superseded' => 'Superseded',
                    ]),
                    'cert.status = :f_status',
                ],
            ],
            'defaultColumns'   => ['learner', 'title', 'status', 'issued_at', 'valid_until'],
        ];
    }

    // --- shared pieces ----------------------------------------------------

    /**
     * Who a row is about, for every source whose rows are people.
     *
     * @return array<string, FieldDef>
     */
    private function peopleFields(): array
    {
        return [
            'learner'     => ['Learner', ReportValueType::Text, null, self::LEARNER],
            'email'       => ['Email', ReportValueType::Text, null, 'u.email'],
            'employee_id' => ['Employee ID', ReportValueType::Text, null, 'u.employee_id'],
            'department'  => ['Department', ReportValueType::Text, null, 'd.name'],
            'team'        => ['Team', ReportValueType::Text, null, 't.name'],
            'branch'      => ['Branch', ReportValueType::Text, null, 'b.name'],
            'job_title'   => ['Job title', ReportValueType::Text, null, 'jt.name'],
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private function peopleGroupings(): array
    {
        return [
            'department' => ['Department', 'd.name'],
            'team'       => ['Team', 't.name'],
            'branch'     => ['Branch', 'b.name'],
            'job_title'  => ['Job title', 'jt.name'],
        ];
    }

    /**
     * @return array<string, FilterDef>
     */
    private function peopleFilters(): array
    {
        return [
            'departmentId' => [CustomFilter::lookup('departmentId', 'Department', 'department'), 'u.department_id = :f_departmentId'],
            'teamId'       => [CustomFilter::lookup('teamId', 'Team', 'team'), 'u.team_id = :f_teamId'],
            'branchId'     => [CustomFilter::lookup('branchId', 'Branch', 'branch'), 'u.branch_id = :f_branchId'],
        ];
    }
}
