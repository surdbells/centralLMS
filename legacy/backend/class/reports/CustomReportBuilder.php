<?php

/**
 * CustomReportBuilder — Phase 7: Ad-Hoc Custom Report Builder
 *
 * Allows admins to build their own reports by:
 *   1. Selecting a data source (enrollments, users, compliance, assessments, certificates, courses)
 *   2. Picking columns from a whitelisted set
 *   3. Adding filters (date range, department, team, branch, course, status, etc.)
 *   4. Choosing grouping (by department, course, month, etc.)
 *   5. Choosing aggregation (count, sum, avg, min, max)
 *   6. Sorting and pagination
 *   7. Saving & scheduling reports
 *
 * Security:
 *   - ALL column/table references are validated against a whitelist (no raw user SQL)
 *   - Filters use parameterized queries (bind_param)
 *   - Grouping keys are validated before injection
 *   - No arbitrary SQL execution
 *
 * Extends BaseReport to inherit tenant_id, conn, pagination, date range, org filters.
 *
 * Schema dependency: custom_reports table (see INTEGRATION.md)
 */

require_once __DIR__ . '/BaseReport.php';

class CustomReportBuilder extends BaseReport
{
    // =========================================================================
    //  DATA SOURCE DEFINITIONS
    // =========================================================================

    /**
     * Each source defines:
     *   base_table  — FROM clause
     *   default_joins — always-included JOINs
     *   optional_joins — included only when columns from them are selected
     *   columns — whitelist of selectable columns  (key => SQL expression)
     *   date_column — used for date range filtering
     *   filters — available filter keys and their SQL fragments
     *   groupable — columns that can be used in GROUP BY
     */
    private function getDataSources(): array
    {
        return [

            // ─── 1. Enrollments ──────────────────────────────────────
            'enrollments' => [
                'label'       => 'Course Enrollments',
                'description' => 'Enrollment records with learner details, course info, scores, and progress',
                'base_table'  => 'course_enrollments ce',
                'default_joins' => [
                    'JOIN users u ON ce.user_id = u.id AND u.tenant_id = ce.tenant_id',
                    'JOIN courses c ON ce.course_id = c.course_id AND c.tenant_id = ce.tenant_id',
                ],
                'optional_joins' => [
                    'department' => 'LEFT JOIN departments d ON u.department = d.department_id AND d.tenant_id = ce.tenant_id',
                    'team'       => 'LEFT JOIN teams t ON u.team = t.team_id AND t.tenant_id = ce.tenant_id',
                    'branch'     => 'LEFT JOIN branches b ON u.branch = b.branch_id AND b.tenant_id = ce.tenant_id',
                    'category'   => 'LEFT JOIN categories cat ON c.category_id = cat.category_id AND cat.tenant_id = ce.tenant_id',
                    'manager'    => 'LEFT JOIN users mgr ON u.manager_id = mgr.id AND mgr.tenant_id = ce.tenant_id',
                ],
                'columns' => [
                    'learner_name'     => "CONCAT(u.first_name, ' ', u.last_name)",
                    'email'            => 'u.email',
                    'staff_id'         => 'u.staffId',
                    'course_title'     => 'c.title',
                    'course_category'  => 'cat.name',
                    'enrollment_date'  => 'DATE(ce.enrolled_at)',
                    'started_at'       => 'ce.started_at',
                    'completed_at'     => 'ce.completed_at',
                    'due_date'         => 'ce.due_date',
                    'status'           => 'ce.status',
                    'progress'         => 'ce.progress_percentage',
                    'score'            => 'ce.final_score',
                    'time_spent'       => 'ce.time_spent_minutes',
                    'is_mandatory'     => 'ce.is_mandatory',
                    'department_name'  => 'd.department_name',
                    'team_name'        => 't.team_name',
                    'branch_name'      => 'b.branch_name',
                    'manager_name'     => "CONCAT(mgr.first_name, ' ', mgr.last_name)",
                    'hire_date'        => 'u.hire_date',
                ],
                'date_column' => 'ce.enrolled_at',
                'tenant_column' => 'ce.tenant_id',
                'filters' => [
                    'status'       => "ce.status = ?",
                    'is_mandatory' => "ce.is_mandatory = ?",
                    'course_id'    => "ce.course_id = ?",
                    'department_id'=> "u.department = ?",
                    'team_id'      => "u.team = ?",
                    'branch_id'    => "u.branch = ?",
                    'manager_id'   => "u.manager_id = ?",
                ],
                'groupable' => [
                    'department_name', 'team_name', 'branch_name', 'course_title',
                    'course_category', 'status', 'is_mandatory', 'manager_name',
                    'month' => "DATE_FORMAT(ce.enrolled_at, '%Y-%m')",
                    'quarter' => "CONCAT(YEAR(ce.enrolled_at), '-Q', QUARTER(ce.enrolled_at))",
                    'year' => "YEAR(ce.enrolled_at)",
                ],
            ],

            // ─── 2. Users ────────────────────────────────────────────
            'users' => [
                'label'       => 'Users & Learners',
                'description' => 'User profiles with org-unit assignments and activity summary',
                'base_table'  => 'users u',
                'default_joins' => [],
                'optional_joins' => [
                    'department' => 'LEFT JOIN departments d ON u.department = d.department_id AND d.tenant_id = u.tenant_id',
                    'team'       => 'LEFT JOIN teams t ON u.team = t.team_id AND t.tenant_id = u.tenant_id',
                    'branch'     => 'LEFT JOIN branches b ON u.branch = b.branch_id AND b.tenant_id = u.tenant_id',
                    'manager'    => 'LEFT JOIN users mgr ON u.manager_id = mgr.id AND mgr.tenant_id = u.tenant_id',
                    'enrollment_stats' => 'LEFT JOIN (SELECT user_id, tenant_id, COUNT(*) as total_enrolled, SUM(CASE WHEN status=\'completed\' THEN 1 ELSE 0 END) as total_completed, AVG(final_score) as avg_score, SUM(time_spent_minutes) as total_time FROM course_enrollments GROUP BY user_id, tenant_id) es ON es.user_id = u.id AND es.tenant_id = u.tenant_id',
                ],
                'columns' => [
                    'learner_name'    => "CONCAT(u.first_name, ' ', u.last_name)",
                    'first_name'      => 'u.first_name',
                    'last_name'       => 'u.last_name',
                    'email'           => 'u.email',
                    'staff_id'        => 'u.staffId',
                    'role'            => 'u.role',
                    'is_active'       => 'u.is_active',
                    'hire_date'       => 'u.hire_date',
                    'created_at'      => 'DATE(u.created)',
                    'department_name' => 'd.department_name',
                    'team_name'       => 't.team_name',
                    'branch_name'     => 'b.branch_name',
                    'manager_name'    => "CONCAT(mgr.first_name, ' ', mgr.last_name)",
                    'total_enrolled'  => 'COALESCE(es.total_enrolled, 0)',
                    'total_completed' => 'COALESCE(es.total_completed, 0)',
                    'avg_score'       => 'ROUND(COALESCE(es.avg_score, 0), 1)',
                    'total_time_hrs'  => 'ROUND(COALESCE(es.total_time, 0) / 60, 1)',
                ],
                'date_column' => 'u.created',
                'tenant_column' => 'u.tenant_id',
                'filters' => [
                    'role'         => "u.role = ?",
                    'is_active'    => "u.is_active = ?",
                    'department_id'=> "u.department = ?",
                    'team_id'      => "u.team = ?",
                    'branch_id'    => "u.branch = ?",
                    'manager_id'   => "u.manager_id = ?",
                ],
                'groupable' => [
                    'department_name', 'team_name', 'branch_name', 'role', 'is_active',
                    'manager_name',
                    'month' => "DATE_FORMAT(u.created, '%Y-%m')",
                    'year'  => "YEAR(u.created)",
                ],
            ],

            // ─── 3. Compliance ───────────────────────────────────────
            'compliance' => [
                'label'       => 'Compliance Training',
                'description' => 'Mandatory training enrollments with compliance status',
                'base_table'  => 'course_enrollments ce',
                'default_joins' => [
                    'JOIN users u ON ce.user_id = u.id AND u.tenant_id = ce.tenant_id',
                    'JOIN courses c ON ce.course_id = c.course_id AND c.tenant_id = ce.tenant_id',
                ],
                'optional_joins' => [
                    'department' => 'LEFT JOIN departments d ON u.department = d.department_id AND d.tenant_id = ce.tenant_id',
                    'team'       => 'LEFT JOIN teams t ON u.team = t.team_id AND t.tenant_id = ce.tenant_id',
                    'branch'     => 'LEFT JOIN branches b ON u.branch = b.branch_id AND b.tenant_id = ce.tenant_id',
                ],
                'columns' => [
                    'learner_name'    => "CONCAT(u.first_name, ' ', u.last_name)",
                    'email'           => 'u.email',
                    'staff_id'        => 'u.staffId',
                    'course_title'    => 'c.title',
                    'status'          => 'ce.status',
                    'progress'        => 'ce.progress_percentage',
                    'score'           => 'ce.final_score',
                    'due_date'        => 'ce.due_date',
                    'completed_at'    => 'ce.completed_at',
                    'days_overdue'    => "CASE WHEN ce.due_date < CURDATE() AND ce.status != 'completed' THEN DATEDIFF(CURDATE(), ce.due_date) ELSE 0 END",
                    'compliance_flag' => "CASE WHEN ce.status = 'completed' THEN 'compliant' WHEN ce.due_date < CURDATE() THEN 'overdue' ELSE 'pending' END",
                    'department_name' => 'd.department_name',
                    'team_name'       => 't.team_name',
                    'branch_name'     => 'b.branch_name',
                ],
                'date_column' => 'ce.due_date',
                'tenant_column' => 'ce.tenant_id',
                'base_where' => 'ce.is_mandatory = 1',
                'filters' => [
                    'status'       => "ce.status = ?",
                    'course_id'    => "ce.course_id = ?",
                    'department_id'=> "u.department = ?",
                    'team_id'      => "u.team = ?",
                    'branch_id'    => "u.branch = ?",
                    'overdue_only' => "ce.due_date < CURDATE() AND ce.status != 'completed'",
                ],
                'groupable' => [
                    'department_name', 'team_name', 'branch_name', 'course_title',
                    'status', 'compliance_flag',
                    'month' => "DATE_FORMAT(ce.due_date, '%Y-%m')",
                ],
            ],

            // ─── 4. Assessments ──────────────────────────────────────
            'assessments' => [
                'label'       => 'Assessment Results',
                'description' => 'Assessment attempts with scores, grades, and pass/fail status',
                'base_table'  => 'proc_attempts pa',
                'default_joins' => [
                    'JOIN proc_assessments a ON pa.assessment_id = a.assessment_id AND a.tenant_id = pa.tenant_id',
                    'JOIN users u ON pa.user_id = u.id AND u.tenant_id = pa.tenant_id',
                ],
                'optional_joins' => [
                    'department' => 'LEFT JOIN departments d ON u.department = d.department_id AND d.tenant_id = pa.tenant_id',
                    'team'       => 'LEFT JOIN teams t ON u.team = t.team_id AND t.tenant_id = pa.tenant_id',
                    'branch'     => 'LEFT JOIN branches b ON u.branch = b.branch_id AND b.tenant_id = pa.tenant_id',
                ],
                'columns' => [
                    'learner_name'      => "CONCAT(u.first_name, ' ', u.last_name)",
                    'email'             => 'u.email',
                    'assessment_title'  => 'a.title',
                    'assessment_type'   => 'a.assessment_type',
                    'attempt_number'    => 'pa.attempt_number',
                    'score'             => 'pa.score',
                    'max_score'         => 'pa.max_score',
                    'score_pct'         => 'ROUND((pa.score / NULLIF(pa.max_score, 0)) * 100, 1)',
                    'passed'            => 'pa.passed',
                    'grade'             => 'pa.grade',
                    'status'            => 'pa.status',
                    'started_at'        => 'pa.started_at',
                    'completed_at'      => 'pa.completed_at',
                    'duration_seconds'  => 'pa.duration_seconds',
                    'department_name'   => 'd.department_name',
                    'team_name'         => 't.team_name',
                    'branch_name'       => 'b.branch_name',
                ],
                'date_column' => 'pa.started_at',
                'tenant_column' => 'pa.tenant_id',
                'filters' => [
                    'status'          => "pa.status = ?",
                    'passed'          => "pa.passed = ?",
                    'assessment_type' => "a.assessment_type = ?",
                    'assessment_id'   => "pa.assessment_id = ?",
                    'department_id'   => "u.department = ?",
                    'team_id'         => "u.team = ?",
                    'branch_id'       => "u.branch = ?",
                ],
                'groupable' => [
                    'department_name', 'team_name', 'branch_name', 'assessment_title',
                    'assessment_type', 'passed', 'grade',
                    'month' => "DATE_FORMAT(pa.started_at, '%Y-%m')",
                ],
            ],

            // ─── 5. Certificates ─────────────────────────────────────
            'certificates' => [
                'label'       => 'Certificates',
                'description' => 'Issued certificates with expiry tracking',
                'base_table'  => 'certificates cert',
                'default_joins' => [
                    'JOIN users u ON cert.user_id = u.id AND u.tenant_id = cert.tenant_id',
                    'LEFT JOIN courses c ON cert.course_id = c.course_id AND c.tenant_id = cert.tenant_id',
                ],
                'optional_joins' => [
                    'department' => 'LEFT JOIN departments d ON u.department = d.department_id AND d.tenant_id = cert.tenant_id',
                    'team'       => 'LEFT JOIN teams t ON u.team = t.team_id AND t.tenant_id = cert.tenant_id',
                    'branch'     => 'LEFT JOIN branches b ON u.branch = b.branch_id AND b.tenant_id = cert.tenant_id',
                ],
                'columns' => [
                    'learner_name'       => "CONCAT(u.first_name, ' ', u.last_name)",
                    'email'              => 'u.email',
                    'certificate_number' => 'cert.certificate_number',
                    'course_title'       => 'c.title',
                    'issue_date'         => 'cert.issue_date',
                    'expiry_date'        => 'cert.expiry_date',
                    'days_until_expiry'  => "CASE WHEN cert.expiry_date IS NULL THEN NULL ELSE DATEDIFF(cert.expiry_date, CURDATE()) END",
                    'cert_status'        => "CASE WHEN cert.expiry_date IS NULL THEN 'permanent' WHEN cert.expiry_date < CURDATE() THEN 'expired' ELSE 'active' END",
                    'department_name'    => 'd.department_name',
                    'team_name'          => 't.team_name',
                    'branch_name'        => 'b.branch_name',
                ],
                'date_column' => 'cert.issue_date',
                'tenant_column' => 'cert.tenant_id',
                'filters' => [
                    'course_id'    => "cert.course_id = ?",
                    'department_id'=> "u.department = ?",
                    'team_id'      => "u.team = ?",
                    'branch_id'    => "u.branch = ?",
                    'expired'      => "cert.expiry_date < CURDATE()",
                    'expiring_soon'=> "cert.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)",
                ],
                'groupable' => [
                    'department_name', 'team_name', 'branch_name', 'course_title',
                    'cert_status',
                    'month' => "DATE_FORMAT(cert.issue_date, '%Y-%m')",
                    'year'  => "YEAR(cert.issue_date)",
                ],
            ],

            // ─── 6. Courses ──────────────────────────────────────────
            'courses' => [
                'label'       => 'Course Catalog',
                'description' => 'Course information with enrollment and completion statistics',
                'base_table'  => 'courses c',
                'default_joins' => [],
                'optional_joins' => [
                    'category' => 'LEFT JOIN categories cat ON c.category_id = cat.category_id AND cat.tenant_id = c.tenant_id',
                    'enrollment_stats' => 'LEFT JOIN (SELECT course_id, tenant_id, COUNT(*) as total_enrolled, SUM(CASE WHEN status=\'completed\' THEN 1 ELSE 0 END) as total_completed, AVG(final_score) as avg_score, AVG(progress_percentage) as avg_progress, SUM(time_spent_minutes) as total_time FROM course_enrollments GROUP BY course_id, tenant_id) es ON es.course_id = c.course_id AND es.tenant_id = c.tenant_id',
                ],
                'columns' => [
                    'course_title'       => 'c.title',
                    'course_description' => 'c.description',
                    'category_name'      => 'cat.name',
                    'difficulty_level'   => 'c.difficulty_level',
                    'duration_hours'     => 'c.duration_hours',
                    'is_active'          => 'c.is_active',
                    'created_at'         => 'DATE(c.created_at)',
                    'total_enrolled'     => 'COALESCE(es.total_enrolled, 0)',
                    'total_completed'    => 'COALESCE(es.total_completed, 0)',
                    'completion_rate'    => 'ROUND(COALESCE(es.total_completed, 0) / NULLIF(COALESCE(es.total_enrolled, 0), 0) * 100, 1)',
                    'avg_score'          => 'ROUND(COALESCE(es.avg_score, 0), 1)',
                    'avg_progress'       => 'ROUND(COALESCE(es.avg_progress, 0), 1)',
                    'total_time_hrs'     => 'ROUND(COALESCE(es.total_time, 0) / 60, 1)',
                ],
                'date_column' => 'c.created_at',
                'tenant_column' => 'c.tenant_id',
                'filters' => [
                    'is_active'    => "c.is_active = ?",
                    'category_id'  => "c.category_id = ?",
                ],
                'groupable' => [
                    'category_name', 'difficulty_level', 'is_active',
                ],
            ],

            // ─── 7. Learning Paths ───────────────────────────────────
            'learning_paths' => [
                'label'       => 'Learning Path Enrollments',
                'description' => 'Learning path progress with item-level tracking',
                'base_table'  => 'learning_path_enrollments lpe',
                'default_joins' => [
                    'JOIN learning_paths lp ON lpe.path_id = lp.path_id AND lp.tenant_id = lpe.tenant_id',
                    'JOIN users u ON lpe.user_id = u.id AND u.tenant_id = lpe.tenant_id',
                ],
                'optional_joins' => [
                    'department' => 'LEFT JOIN departments d ON u.department = d.department_id AND d.tenant_id = lpe.tenant_id',
                    'team'       => 'LEFT JOIN teams t ON u.team = t.team_id AND t.tenant_id = lpe.tenant_id',
                    'branch'     => 'LEFT JOIN branches b ON u.branch = b.branch_id AND b.tenant_id = lpe.tenant_id',
                    'item_stats' => 'LEFT JOIN (SELECT path_id, tenant_id, COUNT(*) as total_items FROM learning_path_items GROUP BY path_id, tenant_id) lis ON lis.path_id = lpe.path_id AND lis.tenant_id = lpe.tenant_id',
                ],
                'columns' => [
                    'learner_name'    => "CONCAT(u.first_name, ' ', u.last_name)",
                    'email'           => 'u.email',
                    'path_name'       => 'lp.path_name',
                    'status'          => 'lpe.status',
                    'enrolled_at'     => 'lpe.enrolled_at',
                    'completed_at'    => 'lpe.completed_at',
                    'total_items'     => 'COALESCE(lis.total_items, 0)',
                    'department_name' => 'd.department_name',
                    'team_name'       => 't.team_name',
                    'branch_name'     => 'b.branch_name',
                ],
                'date_column' => 'lpe.enrolled_at',
                'tenant_column' => 'lpe.tenant_id',
                'filters' => [
                    'status'       => "lpe.status = ?",
                    'path_id'      => "lpe.path_id = ?",
                    'department_id'=> "u.department = ?",
                    'team_id'      => "u.team = ?",
                    'branch_id'    => "u.branch = ?",
                ],
                'groupable' => [
                    'department_name', 'team_name', 'branch_name', 'path_name', 'status',
                    'month' => "DATE_FORMAT(lpe.enrolled_at, '%Y-%m')",
                ],
            ],

            // ─── 8. ILT Sessions ────────────────────────────────────
            'ilt_sessions' => [
                'label'       => 'Instructor-Led Training',
                'description' => 'ILT session attendance and utilization',
                'base_table'  => 'training_session_attendance tsa',
                'default_joins' => [
                    'JOIN training_sessions ts ON tsa.session_id = ts.session_id AND ts.tenant_id = tsa.tenant_id',
                    'JOIN users u ON tsa.user_id = u.id AND u.tenant_id = tsa.tenant_id',
                    'LEFT JOIN courses c ON ts.course_id = c.course_id AND c.tenant_id = ts.tenant_id',
                ],
                'optional_joins' => [
                    'department'  => 'LEFT JOIN departments d ON u.department = d.department_id AND d.tenant_id = tsa.tenant_id',
                    'team'        => 'LEFT JOIN teams t ON u.team = t.team_id AND t.tenant_id = tsa.tenant_id',
                    'branch'      => 'LEFT JOIN branches b ON u.branch = b.branch_id AND b.tenant_id = tsa.tenant_id',
                    'instructor'  => 'LEFT JOIN users instr ON ts.instructor_id = instr.id AND instr.tenant_id = ts.tenant_id',
                    'venue'       => 'LEFT JOIN venues v ON ts.venue_id = v.venue_id AND v.tenant_id = ts.tenant_id',
                ],
                'columns' => [
                    'learner_name'     => "CONCAT(u.first_name, ' ', u.last_name)",
                    'email'            => 'u.email',
                    'course_title'     => 'c.title',
                    'session_date'     => 'ts.session_date',
                    'start_time'       => 'ts.start_time',
                    'end_time'         => 'ts.end_time',
                    'attendance_status'=> 'tsa.attendance_status',
                    'instructor_name'  => "CONCAT(instr.first_name, ' ', instr.last_name)",
                    'venue_name'       => 'v.venue_name',
                    'venue_location'   => 'v.location',
                    'department_name'  => 'd.department_name',
                    'team_name'        => 't.team_name',
                    'branch_name'      => 'b.branch_name',
                ],
                'date_column' => 'ts.session_date',
                'tenant_column' => 'tsa.tenant_id',
                'filters' => [
                    'attendance_status' => "tsa.attendance_status = ?",
                    'session_id'        => "tsa.session_id = ?",
                    'instructor_id'     => "ts.instructor_id = ?",
                    'venue_id'          => "ts.venue_id = ?",
                    'course_id'         => "ts.course_id = ?",
                    'department_id'     => "u.department = ?",
                    'team_id'           => "u.team = ?",
                    'branch_id'         => "u.branch = ?",
                ],
                'groupable' => [
                    'department_name', 'team_name', 'branch_name', 'course_title',
                    'attendance_status', 'instructor_name', 'venue_name',
                    'month' => "DATE_FORMAT(ts.session_date, '%Y-%m')",
                ],
            ],
        ];
    }


    // =========================================================================
    //  PUBLIC API — Sources & Columns
    // =========================================================================

    /** List available data sources for the dropdown */
    public function getAvailableSources(): array
    {
        $sources = [];
        foreach ($this->getDataSources() as $key => $src) {
            $sources[] = [
                'key'         => $key,
                'label'       => $src['label'],
                'description' => $src['description'],
            ];
        }
        return $sources;
    }

    /** List available columns for a given source */
    public function getColumnsForSource(string $source): array
    {
        $sources = $this->getDataSources();
        if (!isset($sources[$source])) return [];

        $cols = [];
        foreach ($sources[$source]['columns'] as $key => $expr) {
            $cols[] = [
                'key'   => $key,
                'label' => $this->humanize($key),
            ];
        }
        return $cols;
    }

    /** List available filters for a given source */
    public function getFiltersForSource(string $source): array
    {
        $sources = $this->getDataSources();
        if (!isset($sources[$source])) return [];

        $filters = ['date_from', 'date_to'];   // always available
        foreach (array_keys($sources[$source]['filters']) as $fk) {
            $filters[] = $fk;
        }
        return $filters;
    }

    /** List available GROUP BY keys for a given source */
    public function getGroupingsForSource(string $source): array
    {
        $sources = $this->getDataSources();
        if (!isset($sources[$source])) return [];

        $groups = [];
        foreach ($sources[$source]['groupable'] as $k => $v) {
            $key = is_int($k) ? $v : $k;
            $groups[] = [
                'key'   => $key,
                'label' => $this->humanize($key),
            ];
        }
        return $groups;
    }


    // =========================================================================
    //  PUBLIC API — Build & Execute Custom Report
    // =========================================================================

    /**
     * Build and execute a custom report.
     *
     * $config structure:
     *   source      string   — required (e.g. 'enrollments')
     *   columns     string[] — required (e.g. ['learner_name','course_title','status'])
     *   filters     array    — optional key=>value pairs from source's filters
     *   date_from   string   — optional ISO date
     *   date_to     string   — optional ISO date
     *   group_by    string   — optional grouping key
     *   aggregates  array    — optional when grouped: ['count','avg_score','sum_time_spent']
     *   order_by    string   — optional column key
     *   order_dir   string   — optional ASC|DESC
     *   page        int      — optional, default 1
     *   per_page    int      — optional, default 50
     *
     * @return array ['data' => [...], 'meta' => [...], 'columns' => [...]]
     */
    public function buildCustomReport(array $config): array
    {
        $sources = $this->getDataSources();
        $source  = $config['source'] ?? '';

        if (!isset($sources[$source])) {
            return ['error' => "Unknown data source: {$source}", 'available' => array_keys($sources)];
        }

        $src = $sources[$source];

        // ── 1. Validate requested columns ──
        $requestedCols = $config['columns'] ?? array_keys($src['columns']);
        $validCols = [];
        foreach ($requestedCols as $col) {
            if (isset($src['columns'][$col])) {
                $validCols[$col] = $src['columns'][$col];
            }
        }
        if (empty($validCols)) {
            return ['error' => 'No valid columns selected', 'available' => array_keys($src['columns'])];
        }

        // ── 2. Determine which optional JOINs are needed ──
        $neededJoins = $this->resolveJoins($src, $validCols, $config);

        // ── 3. Build grouped or detail query ──
        $groupBy  = $config['group_by'] ?? null;
        $isGrouped = $groupBy && isset($src['groupable']);

        if ($isGrouped) {
            return $this->buildGroupedQuery($src, $validCols, $neededJoins, $config);
        } else {
            return $this->buildDetailQuery($src, $validCols, $neededJoins, $config);
        }
    }


    // =========================================================================
    //  DETAIL QUERY (no grouping)
    // =========================================================================

    private function buildDetailQuery(array $src, array $validCols, array $joins, array $config): array
    {
        // SELECT columns
        $selectParts = [];
        foreach ($validCols as $alias => $expr) {
            $selectParts[] = "{$expr} AS `{$alias}`";
        }
        $selectSQL = implode(', ', $selectParts);

        // FROM + JOINs
        $fromSQL = $src['base_table'];
        $joinSQL = implode("\n", array_merge($src['default_joins'], $joins));

        // WHERE
        [$whereSQL, $types, $values] = $this->buildWhereClause($src, $config);

        // ORDER BY
        $orderSQL = $this->buildOrderClause($validCols, $config);

        // Pagination
        $page    = max(1, (int)($config['page'] ?? 1));
        $perPage = min(500, max(1, (int)($config['per_page'] ?? 50)));
        $offset  = ($page - 1) * $perPage;

        // Count query
        $countSQL = "SELECT COUNT(*) as total FROM {$fromSQL}\n{$joinSQL}\nWHERE {$whereSQL}";
        $total = $this->execScalar($countSQL, $types, $values);

        // Data query
        $dataSQL = "SELECT {$selectSQL}\nFROM {$fromSQL}\n{$joinSQL}\nWHERE {$whereSQL}\n{$orderSQL}\nLIMIT {$perPage} OFFSET {$offset}";
        $data = $this->execQuery($dataSQL, $types, $values);

        return [
            'data' => $data,
            'columns' => array_map(fn($k) => ['key' => $k, 'label' => $this->humanize($k)], array_keys($validCols)),
            'meta' => [
                'source'   => $config['source'],
                'total'    => $total,
                'page'     => $page,
                'per_page' => $perPage,
                'pages'    => $total > 0 ? (int)ceil($total / $perPage) : 1,
                'filters'  => $config['filters'] ?? [],
            ],
        ];
    }


    // =========================================================================
    //  GROUPED QUERY (with aggregates)
    // =========================================================================

    private function buildGroupedQuery(array $src, array $validCols, array $joins, array $config): array
    {
        $groupKey = $config['group_by'];

        // Resolve GROUP BY expression
        $groupExpr = null;
        $groupAlias = $groupKey;
        foreach ($src['groupable'] as $k => $v) {
            if (is_int($k) && $v === $groupKey) {
                // It's a column alias — use the column expression
                if (isset($src['columns'][$groupKey])) {
                    $groupExpr = $src['columns'][$groupKey];
                }
                break;
            }
            if (!is_int($k) && $k === $groupKey) {
                // It's a computed expression (month, quarter, year)
                $groupExpr = $v;
                break;
            }
        }

        if (!$groupExpr) {
            return ['error' => "Invalid group_by: {$groupKey}", 'available' => array_keys($src['groupable'] ?? [])];
        }

        // SELECT: group column + aggregates
        $selectParts = ["{$groupExpr} AS `{$groupAlias}`"];
        $selectParts[] = 'COUNT(*) AS `record_count`';

        // Optional aggregates on numeric columns
        $aggregates = $config['aggregates'] ?? [];
        foreach ($aggregates as $agg) {
            // Format: "avg_score", "sum_time_spent", "max_progress"
            if (preg_match('/^(avg|sum|min|max|count)_(.+)$/', $agg, $m)) {
                $func = strtoupper($m[1]);
                $col  = $m[2];
                if (isset($src['columns'][$col])) {
                    $selectParts[] = "{$func}({$src['columns'][$col]}) AS `{$agg}`";
                }
            }
        }

        $selectSQL = implode(', ', $selectParts);

        // FROM + JOINs
        $fromSQL = $src['base_table'];
        $joinSQL = implode("\n", array_merge($src['default_joins'], $joins));

        // WHERE
        [$whereSQL, $types, $values] = $this->buildWhereClause($src, $config);

        // ORDER BY (default: record_count DESC)
        $orderBy = $config['order_by'] ?? 'record_count';
        $orderDir = strtoupper($config['order_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $orderSQL = "ORDER BY `{$orderBy}` {$orderDir}";

        // Pagination
        $page    = max(1, (int)($config['page'] ?? 1));
        $perPage = min(500, max(1, (int)($config['per_page'] ?? 50)));
        $offset  = ($page - 1) * $perPage;

        $groupBySQL = "GROUP BY `{$groupAlias}`";

        // Count distinct groups
        $countSQL = "SELECT COUNT(DISTINCT {$groupExpr}) as total FROM {$fromSQL}\n{$joinSQL}\nWHERE {$whereSQL}";
        $total = $this->execScalar($countSQL, $types, $values);

        // Data query
        $dataSQL = "SELECT {$selectSQL}\nFROM {$fromSQL}\n{$joinSQL}\nWHERE {$whereSQL}\n{$groupBySQL}\n{$orderSQL}\nLIMIT {$perPage} OFFSET {$offset}";
        $data = $this->execQuery($dataSQL, $types, $values);

        // Build column definitions
        $colDefs = [['key' => $groupAlias, 'label' => $this->humanize($groupAlias)], ['key' => 'record_count', 'label' => 'Count']];
        foreach ($aggregates as $agg) {
            $colDefs[] = ['key' => $agg, 'label' => $this->humanize($agg)];
        }

        return [
            'data' => $data,
            'columns' => $colDefs,
            'meta' => [
                'source'    => $config['source'],
                'group_by'  => $groupKey,
                'total'     => $total,
                'page'      => $page,
                'per_page'  => $perPage,
                'pages'     => $total > 0 ? ceil($total / $perPage) : 1,
                'filters'   => $config['filters'] ?? [],
            ],
        ];
    }


    // =========================================================================
    //  WHERE CLAUSE BUILDER
    // =========================================================================

    private function buildWhereClause(array $src, array $config): array
    {
        $conditions = [];
        $types  = '';
        $values = [];

        // Tenant isolation (always)
        $conditions[] = "{$src['tenant_column']} = ?";
        $types .= 'i';
        $values[] = $this->tenant_id;

        // Base WHERE (e.g. compliance: is_mandatory = 1)
        if (!empty($src['base_where'])) {
            $conditions[] = $src['base_where'];
        }

        // Date range
        if (!empty($config['date_from']) && !empty($src['date_column'])) {
            $conditions[] = "{$src['date_column']} >= ?";
            $types .= 's';
            $values[] = $config['date_from'];
        }
        if (!empty($config['date_to']) && !empty($src['date_column'])) {
            $conditions[] = "{$src['date_column']} <= ?";
            $types .= 's';
            $values[] = $config['date_to'];
        }

        // Source-specific filters
        $filters = $config['filters'] ?? [];
        foreach ($filters as $fk => $fv) {
            if (!isset($src['filters'][$fk])) continue;

            $filterExpr = $src['filters'][$fk];

            // Boolean filters (no parameter — the expression is the condition itself)
            if (strpos($filterExpr, '?') === false) {
                if ($fv) $conditions[] = $filterExpr;
                continue;
            }

            $conditions[] = $filterExpr;
            if (is_int($fv) || (is_string($fv) && ctype_digit($fv))) {
                $types .= 'i';
                $values[] = (int)$fv;
            } else {
                $types .= 's';
                $values[] = (string)$fv;
            }
        }

        $whereSQL = implode(' AND ', $conditions);
        return [$whereSQL, $types, $values];
    }


    // =========================================================================
    //  ORDER CLAUSE BUILDER
    // =========================================================================

    private function buildOrderClause(array $validCols, array $config): string
    {
        $orderBy = $config['order_by'] ?? null;
        $orderDir = strtoupper($config['order_dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

        if ($orderBy && isset($validCols[$orderBy])) {
            return "ORDER BY `{$orderBy}` {$orderDir}";
        }

        // Default: first column ASC
        $firstCol = array_key_first($validCols);
        return $firstCol ? "ORDER BY `{$firstCol}` ASC" : '';
    }


    // =========================================================================
    //  JOIN RESOLVER
    // =========================================================================

    private function resolveJoins(array $src, array $validCols, array $config): array
    {
        $neededJoins = [];
        $optionalJoins = $src['optional_joins'] ?? [];

        // Check which optional joins are needed by selected columns
        foreach ($optionalJoins as $joinKey => $joinSQL) {
            // Check if any selected column references this join's alias
            foreach ($validCols as $alias => $expr) {
                if ($this->joinNeededByExpr($joinKey, $expr)) {
                    $neededJoins[$joinKey] = $joinSQL;
                    break;
                }
            }
        }

        // Check if any filter references an optional join
        $filters = $config['filters'] ?? [];
        foreach ($filters as $fk => $fv) {
            if (!isset($src['filters'][$fk])) continue;
            $filterExpr = $src['filters'][$fk];
            foreach ($optionalJoins as $joinKey => $joinSQL) {
                if ($this->joinNeededByExpr($joinKey, $filterExpr) && !isset($neededJoins[$joinKey])) {
                    $neededJoins[$joinKey] = $joinSQL;
                }
            }
        }

        // Check group_by
        if (!empty($config['group_by'])) {
            $gk = $config['group_by'];
            foreach ($src['groupable'] ?? [] as $k => $v) {
                $colKey = is_int($k) ? $v : $k;
                if ($colKey === $gk && isset($src['columns'][$gk])) {
                    foreach ($optionalJoins as $joinKey => $joinSQL) {
                        if ($this->joinNeededByExpr($joinKey, $src['columns'][$gk]) && !isset($neededJoins[$joinKey])) {
                            $neededJoins[$joinKey] = $joinSQL;
                        }
                    }
                }
            }
        }

        return array_values($neededJoins);
    }

    /** Check if a join key's alias appears in an expression */
    private function joinNeededByExpr(string $joinKey, string $expr): bool
    {
        $aliasMap = [
            'department'       => ['d.', 'departments'],
            'team'             => ['t.', 'teams'],
            'branch'           => ['b.', 'branches'],
            'category'         => ['cat.', 'categories'],
            'manager'          => ['mgr.'],
            'instructor'       => ['instr.'],
            'venue'            => ['v.', 'venues'],
            'enrollment_stats' => ['es.'],
            'item_stats'       => ['lis.'],
        ];

        if (!isset($aliasMap[$joinKey])) return false;

        foreach ($aliasMap[$joinKey] as $needle) {
            if (strpos($expr, $needle) !== false) return true;
        }
        return false;
    }


    // =========================================================================
    //  SAVED CUSTOM REPORTS (CRUD)
    // =========================================================================

    /** Save a custom report configuration */
    public function saveCustomReport(string $name, string $description, array $config): array
    {
        if (!$this->tableExists('custom_reports')) {
            return ['error' => 'custom_reports table does not exist. Run the schema migration.'];
        }

        $configJSON = json_encode($config, JSON_UNESCAPED_UNICODE);
        $userId = $this->user_id ?? 0;

        $stmt = $this->conn->prepare(
            "INSERT INTO custom_reports (user_id, tenant_id, name, description, config, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())"
        );
        $stmt->bind_param("iisss", $userId, $this->tenant_id, $name, $description, $configJSON);
        $stmt->execute();
        $id = $stmt->insert_id;
        $stmt->close();

        return ['success' => true, 'report_id' => $id];
    }

    /** Update an existing saved report */
    public function updateCustomReport(int $reportId, string $name, string $description, array $config): array
    {
        if (!$this->tableExists('custom_reports')) {
            return ['error' => 'custom_reports table does not exist.'];
        }

        $configJSON = json_encode($config, JSON_UNESCAPED_UNICODE);

        $stmt = $this->conn->prepare(
            "UPDATE custom_reports SET name = ?, description = ?, config = ?, updated_at = NOW()
             WHERE id = ? AND tenant_id = ?"
        );
        $stmt->bind_param("sssii", $name, $description, $configJSON, $reportId, $this->tenant_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected > 0 ? ['success' => true] : ['error' => 'Report not found or no changes'];
    }

    /** Delete (soft) a saved report */
    public function deleteCustomReport(int $reportId): array
    {
        if (!$this->tableExists('custom_reports')) {
            return ['error' => 'custom_reports table does not exist.'];
        }

        $stmt = $this->conn->prepare(
            "UPDATE custom_reports SET is_active = 0, updated_at = NOW() WHERE id = ? AND tenant_id = ?"
        );
        $stmt->bind_param("ii", $reportId, $this->tenant_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected > 0 ? ['success' => true] : ['error' => 'Report not found'];
    }

    /** List saved custom reports for this tenant */
    public function listSavedReports(?int $userId = null): array
    {
        if (!$this->tableExists('custom_reports')) return [];

        $sql = "SELECT cr.id, cr.name, cr.description, cr.config, cr.created_at, cr.updated_at,
                       CONCAT(u.first_name, ' ', u.last_name) as created_by
                FROM custom_reports cr
                JOIN users u ON cr.user_id = u.id
                WHERE cr.tenant_id = ? AND cr.is_active = 1";
        $types = 'i';
        $params = [$this->tenant_id];

        if ($userId !== null) {
            $sql .= " AND cr.user_id = ?";
            $types .= 'i';
            $params[] = $userId;
        }

        $sql .= " ORDER BY cr.updated_at DESC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Decode config JSON
        foreach ($rows as &$row) {
            $row['config'] = json_decode($row['config'], true);
        }

        return $rows;
    }

    /** Load and run a saved custom report */
    public function runSavedReport(int $reportId, array $overrides = []): array
    {
        if (!$this->tableExists('custom_reports')) {
            return ['error' => 'custom_reports table does not exist.'];
        }

        $stmt = $this->conn->prepare(
            "SELECT config FROM custom_reports WHERE id = ? AND tenant_id = ? AND is_active = 1"
        );
        $stmt->bind_param("ii", $reportId, $this->tenant_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return ['error' => 'Saved report not found'];
        }

        $config = json_decode($row['config'], true);
        if (!$config) {
            return ['error' => 'Invalid report configuration'];
        }

        // Apply overrides (e.g. pagination, date range)
        $config = array_merge($config, $overrides);

        return $this->buildCustomReport($config);
    }

    /** Duplicate a saved report */
    public function duplicateCustomReport(int $reportId): array
    {
        if (!$this->tableExists('custom_reports')) {
            return ['error' => 'custom_reports table does not exist.'];
        }

        $stmt = $this->conn->prepare(
            "SELECT name, description, config FROM custom_reports WHERE id = ? AND tenant_id = ? AND is_active = 1"
        );
        $stmt->bind_param("ii", $reportId, $this->tenant_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return ['error' => 'Report not found'];
        }

        return $this->saveCustomReport(
            $row['name'] . ' (Copy)',
            $row['description'],
            json_decode($row['config'], true)
        );
    }


    // =========================================================================
    //  HELPER: generate() for BaseReport contract
    // =========================================================================

    public function generate(): array
    {
        return $this->getAvailableSources();
    }


    // =========================================================================
    //  SAFE QUERY EXECUTION (handles bind_param properly)
    // =========================================================================

    /** Execute a parameterized SELECT query, return all rows */
    private function execQuery(string $sql, string $types, array $values): array
    {
        if (empty($types)) {
            $res = $this->conn->query($sql);
            return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        }

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return [];

        // bind_param needs references — build reference array
        $refs = [$types];
        for ($i = 0; $i < count($values); $i++) {
            $refs[] = &$values[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);

        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $data;
    }

    /** Execute a parameterized COUNT query, return single int */
    private function execScalar(string $sql, string $types, array $values): int
    {
        if (empty($types)) {
            $res = $this->conn->query($sql);
            return $res ? (int)$res->fetch_assoc()['total'] : 0;
        }

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) return 0;

        $refs = [$types];
        for ($i = 0; $i < count($values); $i++) {
            $refs[] = &$values[$i];
        }
        call_user_func_array([$stmt, 'bind_param'], $refs);

        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        return $total;
    }


    // =========================================================================
    //  UTILITY
    // =========================================================================

    /** Convert snake_case to Title Case */
    private function humanize(string $key): string
    {
        return ucwords(str_replace(['_', 'pct'], [' ', '%'], $key));
    }
}
