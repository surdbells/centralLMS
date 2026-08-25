<?php
require_once __DIR__ . '/BaseReport.php';

/**
 * Onboarding & New Hire Reports
 * Industry-standard onboarding tracking — benchmarked against Cornerstone, Docebo, Absorb, D2L.
 *
 * PHASE 9 — Uses existing tables: users (hire_date), course_enrollments, courses
 *           Optional: courses.is_onboarding flag, users.probation_end_date
 *
 * Methods:
 *  1. onboardingProgressByCohort()      – New hires grouped by hire month with completion rates
 *  2. timeToCompetency()                – Days from hire to all mandatory courses completed
 *  3. onboardingCompletionByOrg()       – Onboarding completion rates by department/branch
 *  4. newHireTrainingDashboard()        – Real-time status of all current new hires
 *  5. onboardingDropOff()               – Where in the onboarding flow new hires stall
 *  6. probationTrainingCompliance()     – Mandatory training completed before probation ends
 *
 * "New hire" definition: users.hire_date within the last N days (default 90, configurable via filter).
 * "Onboarding courses": courses flagged with is_onboarding=1 OR is_mandatory=1 enrollments for new hires.
 */
class OnboardingReports extends BaseReport
{
    /** Default number of days from hire_date to still be considered a "new hire" */
    private int $newHireWindowDays = 90;

    /** Default probation period in days (used when users.probation_end_date is NULL) */
    private int $defaultProbationDays = 180;

    public function generate(): array
    {
        return $this->onboardingProgressByCohort();
    }

    /**
     * Apply newHireWindowDays from filters if provided.
     */
    private function applyNewHireWindow(): void
    {
        if (!empty($this->filters['new_hire_window_days'])) {
            $this->newHireWindowDays = max(30, min(365, (int)$this->filters['new_hire_window_days']));
        }
        if (!empty($this->filters['probation_days'])) {
            $this->defaultProbationDays = max(30, min(365, (int)$this->filters['probation_days']));
        }
    }

    /**
     * Check if a column exists on a table (cached per request).
     */
    private function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $key = "{$table}.{$column}";
        if (!isset($cache[$key])) {
            $result = $this->conn->query(
                "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = '{$this->conn->real_escape_string($table)}'
                   AND COLUMN_NAME = '{$this->conn->real_escape_string($column)}'"
            );
            $cache[$key] = $result && $result->fetch_assoc()['cnt'] > 0;
        }
        return $cache[$key];
    }

    /**
     * Build the onboarding course filter.
     * Prefers courses.is_onboarding if the column exists, otherwise falls back to
     * course_enrollments.is_mandatory for new hires.
     */
    private function buildOnboardingCourseCondition(string $courseAlias = 'c', string $enrollAlias = 'ce'): string
    {
        if ($this->columnExists('courses', 'is_onboarding')) {
            return "AND {$courseAlias}.is_onboarding = 1";
        }
        // Fallback: mandatory courses assigned to new hires = onboarding courses
        return "AND {$enrollAlias}.is_mandatory = 1";
    }

    /**
     * Build a condition to identify "new hires" based on hire_date.
     */
    private function buildNewHireCondition(string $userAlias = 'u'): string
    {
        return "AND {$userAlias}.hire_date IS NOT NULL
                AND {$userAlias}.hire_date >= DATE_SUB(CURDATE(), INTERVAL {$this->newHireWindowDays} DAY)";
    }

    /* ══════════════════════════════════════════════════════════════════
     * 1. ONBOARDING PROGRESS BY COHORT
     * Groups new hires by hire month and shows their course completion rates.
     * ══════════════════════════════════════════════════════════════════ */
    public function onboardingProgressByCohort(): array
    {
        $this->applyNewHireWindow();
        $orgFilter = $this->buildOrgFilter();
        $onbCond   = $this->buildOnboardingCourseCondition();

        // Determine grouping: month by default, quarter if filter says so
        $groupBy = ($this->filters['group_by'] ?? 'month') === 'quarter'
            ? "CONCAT(YEAR(u.hire_date), '-Q', QUARTER(u.hire_date))"
            : "DATE_FORMAT(u.hire_date, '%Y-%m')";

        $query = "SELECT
                    {$groupBy} AS cohort,
                    COUNT(DISTINCT u.id) AS total_new_hires,
                    COUNT(ce.id) AS total_enrollments,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                    SUM(CASE WHEN ce.status = 'not_started' OR ce.status IS NULL THEN 1 ELSE 0 END) AS not_started,
                    ROUND(
                        SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) * 100.0
                        / NULLIF(COUNT(ce.id), 0), 1
                    ) AS completion_rate,
                    ROUND(AVG(CASE WHEN ce.status = 'completed'
                        THEN DATEDIFF(ce.completed_at, u.hire_date) END), 1) AS avg_days_to_complete,
                    ROUND(AVG(ce.progress), 1) AS avg_progress
                  FROM users u
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.tenant_id = u.tenant_id
                  LEFT JOIN courses c ON ce.course_id = c.course_id AND c.tenant_id = ce.tenant_id
                  WHERE u.tenant_id = ?
                    AND u.hire_date IS NOT NULL
                    AND u.hire_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                    {$onbCond}
                    {$orgFilter}
                  GROUP BY cohort
                  ORDER BY cohort DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'cohort'               => $row['cohort'],
                'total_new_hires'      => (int)$row['total_new_hires'],
                'total_enrollments'    => (int)$row['total_enrollments'],
                'completed'            => (int)$row['completed'],
                'in_progress'          => (int)$row['in_progress'],
                'not_started'          => (int)$row['not_started'],
                'completion_rate'      => (float)($row['completion_rate'] ?? 0),
                'avg_days_to_complete' => (float)($row['avg_days_to_complete'] ?? 0),
                'avg_progress'         => (float)($row['avg_progress'] ?? 0)
            ];
        }

        $countQuery = "SELECT COUNT(DISTINCT DATE_FORMAT(u.hire_date, '%Y-%m')) AS total
                       FROM users u
                       WHERE u.tenant_id = ? AND u.hire_date IS NOT NULL
                         AND u.hire_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Onboarding Progress by Cohort',
            'description' => 'New hire onboarding completion rates grouped by hire month',
            'chart_type'  => 'bar',
            'chart_config' => [
                'x_axis' => 'cohort',
                'y_axes' => ['completion_rate', 'avg_days_to_complete'],
                'series' => ['completed', 'in_progress', 'not_started']
            ],
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /* ══════════════════════════════════════════════════════════════════
     * 2. TIME-TO-COMPETENCY
     * Measures how many days it takes new hires to complete all mandatory
     * courses from their hire date. The key onboarding KPI.
     * ══════════════════════════════════════════════════════════════════ */
    public function timeToCompetency(): array
    {
        $this->applyNewHireWindow();
        $orgFilter = $this->buildOrgFilter();
        $onbCond   = $this->buildOnboardingCourseCondition();

        $query = "SELECT
                    u.id AS user_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS learner_name,
                    u.email,
                    u.hire_date,
                    d.name AS department_name,
                    COUNT(ce.id) AS total_onboarding_courses,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) AS completed_courses,
                    MAX(CASE WHEN ce.status = 'completed' THEN ce.completed_at END) AS last_completion_date,
                    CASE
                        WHEN SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) = COUNT(ce.id)
                        THEN DATEDIFF(
                            MAX(CASE WHEN ce.status = 'completed' THEN ce.completed_at END),
                            u.hire_date
                        )
                        ELSE NULL
                    END AS days_to_competency,
                    CASE
                        WHEN SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) = COUNT(ce.id)
                            THEN 'competent'
                        WHEN DATEDIFF(CURDATE(), u.hire_date) > {$this->newHireWindowDays}
                            THEN 'overdue'
                        ELSE 'in_progress'
                    END AS competency_status,
                    ROUND(
                        SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) * 100.0
                        / NULLIF(COUNT(ce.id), 0), 1
                    ) AS progress_pct,
                    DATEDIFF(CURDATE(), u.hire_date) AS days_since_hire
                  FROM users u
                  LEFT JOIN departments d ON u.department = d.id
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.tenant_id = u.tenant_id
                  LEFT JOIN courses c ON ce.course_id = c.course_id AND c.tenant_id = ce.tenant_id
                  WHERE u.tenant_id = ?
                    AND u.hire_date IS NOT NULL
                    AND u.hire_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                    {$onbCond}
                    {$orgFilter}
                  GROUP BY u.id, u.first_name, u.last_name, u.email, u.hire_date, d.name
                  ORDER BY days_to_competency ASC, days_since_hire DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'user_id'                  => (int)$row['user_id'],
                'learner_name'             => $row['learner_name'],
                'email'                    => $row['email'],
                'hire_date'                => $row['hire_date'],
                'department_name'          => $row['department_name'] ?? 'Unassigned',
                'total_onboarding_courses' => (int)$row['total_onboarding_courses'],
                'completed_courses'        => (int)$row['completed_courses'],
                'last_completion_date'     => $row['last_completion_date'],
                'days_to_competency'       => $row['days_to_competency'] !== null ? (int)$row['days_to_competency'] : null,
                'competency_status'        => $row['competency_status'],
                'progress_pct'             => (float)($row['progress_pct'] ?? 0),
                'days_since_hire'          => (int)$row['days_since_hire']
            ];
        }

        // Summary statistics
        $summaryQuery = "SELECT
                            COUNT(DISTINCT u.id) AS total_new_hires,
                            SUM(CASE WHEN sub.status = 'competent' THEN 1 ELSE 0 END) AS competent_count,
                            ROUND(AVG(CASE WHEN sub.status = 'competent' THEN sub.dtc END), 1) AS avg_days_to_competency,
                            ROUND(MIN(CASE WHEN sub.status = 'competent' THEN sub.dtc END), 0) AS fastest_days,
                            ROUND(MAX(CASE WHEN sub.status = 'competent' THEN sub.dtc END), 0) AS slowest_days
                         FROM (
                             SELECT u.id,
                                 CASE WHEN SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END) = COUNT(ce.id) THEN 'competent' ELSE 'pending' END AS status,
                                 DATEDIFF(MAX(CASE WHEN ce.status='completed' THEN ce.completed_at END), u.hire_date) AS dtc
                             FROM users u
                             LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.tenant_id = u.tenant_id
                             LEFT JOIN courses c ON ce.course_id = c.course_id AND c.tenant_id = ce.tenant_id
                             WHERE u.tenant_id = ? AND u.hire_date IS NOT NULL
                               AND u.hire_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                               {$onbCond}
                             GROUP BY u.id, u.hire_date
                         ) sub
                         JOIN users u ON sub.id = u.id";
        $sStmt = $this->conn->prepare($summaryQuery);
        $sStmt->bind_param("i", $this->tenant_id);
        $sStmt->execute();
        $summary = $sStmt->get_result()->fetch_assoc();

        $countQuery = "SELECT COUNT(DISTINCT u.id) AS total
                       FROM users u
                       LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.tenant_id = u.tenant_id
                       LEFT JOIN courses c ON ce.course_id = c.course_id AND c.tenant_id = ce.tenant_id
                       WHERE u.tenant_id = ? AND u.hire_date IS NOT NULL
                         AND u.hire_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) {$onbCond}";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Time-to-Competency Report',
            'description' => 'Days from hire date until all mandatory onboarding courses completed',
            'chart_type'  => 'bar',
            'chart_config' => [
                'x_axis' => 'learner_name',
                'y_axes' => ['days_to_competency'],
                'color_by' => 'competency_status'
            ],
            'summary' => [
                'total_new_hires'          => (int)($summary['total_new_hires'] ?? 0),
                'competent_count'          => (int)($summary['competent_count'] ?? 0),
                'avg_days_to_competency'   => (float)($summary['avg_days_to_competency'] ?? 0),
                'fastest_days'             => (int)($summary['fastest_days'] ?? 0),
                'slowest_days'             => (int)($summary['slowest_days'] ?? 0)
            ],
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /* ══════════════════════════════════════════════════════════════════
     * 3. ONBOARDING COMPLETION BY ORG UNIT
     * Onboarding completion rates broken down by department, branch, or team.
     * ══════════════════════════════════════════════════════════════════ */
    public function onboardingCompletionByOrg(): array
    {
        $this->applyNewHireWindow();
        $onbCond   = $this->buildOnboardingCourseCondition();

        // Determine breakdown dimension
        $breakdownDim = $this->filters['breakdown'] ?? 'department';
        switch ($breakdownDim) {
            case 'branch':
                $selectCol = "b.branch_name AS org_unit_name, b.id AS org_unit_id";
                $groupCol  = "b.id, b.branch_name";
                $joinExtra = "LEFT JOIN branches b ON u.branch = b.id";
                break;
            case 'team':
                $selectCol = "t.team_name AS org_unit_name, t.id AS org_unit_id";
                $groupCol  = "t.id, t.team_name";
                $joinExtra = "LEFT JOIN teams t ON u.team = t.id";
                break;
            default: // department
                $selectCol = "d.name AS org_unit_name, d.id AS org_unit_id";
                $groupCol  = "d.id, d.name";
                $joinExtra = "LEFT JOIN departments d ON u.department = d.id";
                break;
        }

        $query = "SELECT
                    {$selectCol},
                    COUNT(DISTINCT u.id) AS total_new_hires,
                    COUNT(ce.id) AS total_enrollments,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    ROUND(
                        SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) * 100.0
                        / NULLIF(COUNT(ce.id), 0), 1
                    ) AS completion_rate,
                    ROUND(AVG(CASE WHEN ce.status = 'completed'
                        THEN DATEDIFF(ce.completed_at, u.hire_date) END), 1) AS avg_days_to_complete,
                    SUM(CASE WHEN ce.status != 'completed'
                             AND DATEDIFF(CURDATE(), u.hire_date) > {$this->newHireWindowDays}
                        THEN 1 ELSE 0 END) AS overdue_count
                  FROM users u
                  {$joinExtra}
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.tenant_id = u.tenant_id
                  LEFT JOIN courses c ON ce.course_id = c.cource_id AND c.tenant_id = ce.tenant_id
                  WHERE u.tenant_id = ?
                    AND u.hire_date IS NOT NULL
                    AND u.hire_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                    {$onbCond}
                  GROUP BY {$groupCol}
                  ORDER BY completion_rate DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'org_unit_id'          => (int)$row['org_unit_id'],
                'org_unit_name'        => $row['org_unit_name'] ?? 'Unassigned',
                'breakdown_type'       => $breakdownDim,
                'total_new_hires'      => (int)$row['total_new_hires'],
                'total_enrollments'    => (int)$row['total_enrollments'],
                'completed'            => (int)$row['completed'],
                'completion_rate'      => (float)($row['completion_rate'] ?? 0),
                'avg_days_to_complete' => (float)($row['avg_days_to_complete'] ?? 0),
                'overdue_count'        => (int)$row['overdue_count']
            ];
        }

        return [
            'report_name' => 'Onboarding Completion by ' . ucfirst($breakdownDim),
            'description' => "Onboarding completion rates broken down by {$breakdownDim}",
            'chart_type'  => 'horizontal_bar',
            'chart_config' => [
                'x_axis' => 'completion_rate',
                'y_axis' => 'org_unit_name',
                'color_by' => 'completion_rate'
            ],
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ══════════════════════════════════════════════════════════════════
     * 4. NEW HIRE TRAINING STATUS DASHBOARD
     * Real-time view of ALL current new hires (within window) and their
     * onboarding training status. This is the "manager's daily view."
     * ══════════════════════════════════════════════════════════════════ */
    public function newHireTrainingDashboard(): array
    {
        $this->applyNewHireWindow();
        $orgFilter    = $this->buildOrgFilter();
        $onbCond      = $this->buildOnboardingCourseCondition();
        $newHireCond  = $this->buildNewHireCondition();

        // Optional status filter: 'all', 'on_track', 'at_risk', 'overdue', 'completed'
        $statusFilter = '';
        $filterStatus = $this->filters['onboarding_status'] ?? 'all';

        $query = "SELECT
                    u.id AS user_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS learner_name,
                    u.email,
                    u.staffId AS staff_id,
                    u.hire_date,
                    d.name AS department_name,
                    DATEDIFF(CURDATE(), u.hire_date) AS days_since_hire,
                    COUNT(ce.id) AS total_courses,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                    SUM(CASE WHEN ce.status = 'not_started' OR ce.status IS NULL THEN 1 ELSE 0 END) AS not_started,
                    ROUND(
                        SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) * 100.0
                        / NULLIF(COUNT(ce.id), 0), 1
                    ) AS overall_progress,
                    MAX(ce.updated_at) AS last_activity,
                    CASE
                        WHEN SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) = COUNT(ce.id)
                            THEN 'completed'
                        WHEN DATEDIFF(CURDATE(), u.hire_date) > {$this->newHireWindowDays}
                             AND SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) < COUNT(ce.id)
                            THEN 'overdue'
                        WHEN DATEDIFF(CURDATE(), u.hire_date) > ({$this->newHireWindowDays} * 0.75)
                             AND SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) < COUNT(ce.id) * 0.75
                            THEN 'at_risk'
                        ELSE 'on_track'
                    END AS onboarding_status
                  FROM users u
                  LEFT JOIN departments d ON u.department = d.id
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.tenant_id = u.tenant_id
                  LEFT JOIN courses c ON ce.course_id = c.course_id AND c.tenant_id = ce.tenant_id
                  WHERE u.tenant_id = ?
                    {$newHireCond}
                    {$onbCond}
                    {$orgFilter}
                  GROUP BY u.id, u.first_name, u.last_name, u.email, u.staffId, u.hire_date, d.name
                  HAVING 1=1 {$statusFilter}
                  ORDER BY onboarding_status DESC, days_since_hire DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        $statusCounts = ['completed' => 0, 'on_track' => 0, 'at_risk' => 0, 'overdue' => 0];
        while ($row = $result->fetch_assoc()) {
            $status = $row['onboarding_status'];

            // Apply client-side status filter (cleaner than in HAVING)
            if ($filterStatus !== 'all' && $status !== $filterStatus) continue;

            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            $data[] = [
                'user_id'            => (int)$row['user_id'],
                'learner_name'       => $row['learner_name'],
                'email'              => $row['email'],
                'staff_id'           => $row['staff_id'],
                'hire_date'          => $row['hire_date'],
                'department_name'    => $row['department_name'] ?? 'Unassigned',
                'days_since_hire'    => (int)$row['days_since_hire'],
                'total_courses'      => (int)$row['total_courses'],
                'completed'          => (int)$row['completed'],
                'in_progress'        => (int)$row['in_progress'],
                'not_started'        => (int)$row['not_started'],
                'overall_progress'   => (float)($row['overall_progress'] ?? 0),
                'last_activity'      => $row['last_activity'],
                'onboarding_status'  => $status,
                'status_badge'       => $this->getOnboardingBadge($status)
            ];
        }

        // Overall dashboard summary
        $summaryQuery = "SELECT COUNT(DISTINCT u.id) AS total
                         FROM users u
                         WHERE u.tenant_id = ? {$newHireCond}";
        $totalNewHires = $this->getTotalCount($summaryQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'New Hire Training Status Dashboard',
            'description' => "Real-time onboarding status for new hires (last {$this->newHireWindowDays} days)",
            'chart_type'  => 'donut',
            'chart_config' => [
                'segments' => ['completed', 'on_track', 'at_risk', 'overdue'],
                'colors'   => ['#28a745', '#17a2b8', '#ffc107', '#dc3545']
            ],
            'summary' => [
                'total_new_hires' => $totalNewHires,
                'status_breakdown' => $statusCounts,
                'window_days'      => $this->newHireWindowDays
            ],
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ══════════════════════════════════════════════════════════════════
     * 5. ONBOARDING DROP-OFF ANALYSIS
     * Identifies WHERE in the onboarding flow new hires stall or disengage.
     * Shows each onboarding course as a "step" and the completion funnel.
     * ══════════════════════════════════════════════════════════════════ */
    public function onboardingDropOff(): array
    {
        $this->applyNewHireWindow();
        $orgFilter = $this->buildOrgFilter();
        $onbCond   = $this->buildOnboardingCourseCondition();

        // Get onboarding courses ordered by their typical enrollment order
        $query = "SELECT
                    c.course_id AS course_id,
                    c.title AS course_title,
                    COUNT(DISTINCT ce.user_id) AS total_enrolled,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) AS in_progress,
                    SUM(CASE WHEN ce.status = 'not_started' OR ce.status IS NULL THEN 1 ELSE 0 END) AS not_started,
                    ROUND(
                        SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) * 100.0
                        / NULLIF(COUNT(DISTINCT ce.user_id), 0), 1
                    ) AS completion_rate,
                    ROUND(AVG(ce.progress), 1) AS avg_progress,
                    ROUND(AVG(CASE WHEN ce.status != 'completed'
                        THEN DATEDIFF(CURDATE(), ce.enrolled_at) END), 0) AS avg_days_stalled
                  FROM courses c
                  INNER JOIN course_enrollments ce ON c.course_id = ce.course_id AND ce.tenant_id = c.tenant_id
                  INNER JOIN users u ON ce.user_id = u.id AND u.tenant_id = ce.tenant_id
                  WHERE c.tenant_id = ?
                    AND u.hire_date IS NOT NULL
                    AND u.hire_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                    {$onbCond}
                    {$orgFilter}
                  GROUP BY c.course_id, c.title
                  ORDER BY completion_rate DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        $stepNumber = 1;

        // Get total new hires for funnel calculation
        $totalQuery = "SELECT COUNT(DISTINCT u.id) AS total
                       FROM users u
                       WHERE u.tenant_id = ? AND u.hire_date IS NOT NULL
                         AND u.hire_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)";
        $totalStmt = $this->conn->prepare($totalQuery);
        $totalStmt->bind_param("i", $this->tenant_id);
        $totalStmt->execute();
        $totalNewHires = (int)$totalStmt->get_result()->fetch_assoc()['total'];

        while ($row = $result->fetch_assoc()) {
            $enrolled  = (int)$row['total_enrolled'];
            $completed = (int)$row['completed'];
            $prevDropOff = ($stepNumber > 1 && $totalNewHires > 0)
                ? round(($totalNewHires - $enrolled) * 100.0 / $totalNewHires, 1)
                : 0;

            $data[] = [
                'step'              => $stepNumber,
                'course_id'         => (int)$row['course_id'],
                'course_title'      => $row['course_title'],
                'total_enrolled'    => $enrolled,
                'completed'         => $completed,
                'in_progress'       => (int)$row['in_progress'],
                'not_started'       => (int)$row['not_started'],
                'completion_rate'   => (float)($row['completion_rate'] ?? 0),
                'avg_progress'      => (float)($row['avg_progress'] ?? 0),
                'avg_days_stalled'  => (int)($row['avg_days_stalled'] ?? 0),
                'funnel_pct'        => $totalNewHires > 0 ? round($enrolled * 100.0 / $totalNewHires, 1) : 0,
                'drop_off_pct'      => $enrolled > 0 ? round(($enrolled - $completed) * 100.0 / $enrolled, 1) : 0,
                'risk_level'        => $this->getDropOffRiskLevel((float)($row['completion_rate'] ?? 0))
            ];
            $stepNumber++;
        }

        return [
            'report_name' => 'Onboarding Drop-Off Analysis',
            'description' => 'Identifies where in the onboarding flow new hires stall or disengage',
            'chart_type'  => 'funnel',
            'chart_config' => [
                'label_field' => 'course_title',
                'value_field' => 'total_enrolled',
                'color_by'    => 'risk_level'
            ],
            'summary' => [
                'total_new_hires'        => $totalNewHires,
                'total_onboarding_steps' => count($data),
                'highest_drop_off'       => !empty($data) ? $data[array_key_last(
                    array_filter($data, fn($d) => $d['drop_off_pct'] === max(array_column($data, 'drop_off_pct')))
                )]['course_title'] ?? 'N/A' : 'N/A'
            ],
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /* ══════════════════════════════════════════════════════════════════
     * 6. PROBATION PERIOD TRAINING COMPLIANCE
     * Are new hires completing all required training before their probation
     * period ends? Uses probation_end_date if available, else hire_date +
     * defaultProbationDays.
     * ══════════════════════════════════════════════════════════════════ */
    public function probationTrainingCompliance(): array
    {
        $this->applyNewHireWindow();
        $orgFilter = $this->buildOrgFilter();
        $onbCond   = $this->buildOnboardingCourseCondition();

        $hasProbationCol = $this->columnExists('users', 'probation_end_date');
        $probEndExpr = $hasProbationCol
            ? "COALESCE(u.probation_end_date, DATE_ADD(u.hire_date, INTERVAL {$this->defaultProbationDays} DAY))"
            : "DATE_ADD(u.hire_date, INTERVAL {$this->defaultProbationDays} DAY)";

        $query = "SELECT
                    u.id AS user_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS learner_name,
                    u.email,
                    u.hire_date,
                    {$probEndExpr} AS probation_end_date,
                    d.name AS department_name,
                    DATEDIFF({$probEndExpr}, CURDATE()) AS days_remaining,
                    COUNT(ce.id) AS total_required,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) AS completed,
                    SUM(CASE WHEN ce.status = 'completed'
                             AND ce.completed_at <= {$probEndExpr}
                        THEN 1 ELSE 0 END) AS completed_before_deadline,
                    ROUND(
                        SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) * 100.0
                        / NULLIF(COUNT(ce.id), 0), 1
                    ) AS compliance_rate,
                    CASE
                        WHEN SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) = COUNT(ce.id)
                            THEN 'fully_compliant'
                        WHEN CURDATE() > {$probEndExpr}
                             AND SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) < COUNT(ce.id)
                            THEN 'probation_expired_incomplete'
                        WHEN DATEDIFF({$probEndExpr}, CURDATE()) <= 14
                             AND SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) < COUNT(ce.id)
                            THEN 'at_risk'
                        ELSE 'on_track'
                    END AS probation_status
                  FROM users u
                  LEFT JOIN departments d ON u.department = d.id
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.tenant_id = u.tenant_id
                  LEFT JOIN courses c ON ce.course_id = c.course_id AND c.tenant_id = ce.tenant_id
                  WHERE u.tenant_id = ?
                    AND u.hire_date IS NOT NULL
                    AND u.hire_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
                    {$onbCond}
                    {$orgFilter}
                  GROUP BY u.id, u.first_name, u.last_name, u.email, u.hire_date,
                           d.name, probation_end_date
                  ORDER BY days_remaining ASC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        $statusCounts = [
            'fully_compliant'              => 0,
            'on_track'                     => 0,
            'at_risk'                      => 0,
            'probation_expired_incomplete' => 0
        ];

        while ($row = $result->fetch_assoc()) {
            $status = $row['probation_status'];
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

            $data[] = [
                'user_id'                     => (int)$row['user_id'],
                'learner_name'                => $row['learner_name'],
                'email'                       => $row['email'],
                'hire_date'                   => $row['hire_date'],
                'probation_end_date'          => $row['probation_end_date'],
                'department_name'             => $row['department_name'] ?? 'Unassigned',
                'days_remaining'              => (int)$row['days_remaining'],
                'total_required'              => (int)$row['total_required'],
                'completed'                   => (int)$row['completed'],
                'completed_before_deadline'   => (int)$row['completed_before_deadline'],
                'compliance_rate'             => (float)($row['compliance_rate'] ?? 0),
                'probation_status'            => $status,
                'status_badge'                => $this->getProbationBadge($status)
            ];
        }

        $countQuery = "SELECT COUNT(DISTINCT u.id) AS total
                       FROM users u
                       LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.tenant_id = u.tenant_id
                       LEFT JOIN courses c ON ce.course_id = c.course_id AND c.tenant_id = ce.tenant_id
                       WHERE u.tenant_id = ? AND u.hire_date IS NOT NULL
                         AND u.hire_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) {$onbCond}";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Probation Period Training Compliance',
            'description' => 'Tracks whether new hires complete all required training before probation ends',
            'chart_type'  => 'donut',
            'chart_config' => [
                'segments' => array_keys($statusCounts),
                'colors'   => ['#28a745', '#17a2b8', '#ffc107', '#dc3545']
            ],
            'summary' => [
                'total_tracked'   => $total,
                'probation_days'  => $this->defaultProbationDays,
                'status_breakdown' => $statusCounts
            ],
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /* ──────────────────────────────────────────────
     * Helpers
     * ────────────────────────────────────────────── */

    private function getOnboardingBadge(string $status): array
    {
        return match ($status) {
            'completed' => ['label' => 'Completed', 'color' => 'success', 'icon' => 'bi-check-circle-fill'],
            'on_track'  => ['label' => 'On Track',  'color' => 'info',    'icon' => 'bi-arrow-right-circle'],
            'at_risk'   => ['label' => 'At Risk',   'color' => 'warning', 'icon' => 'bi-exclamation-triangle'],
            'overdue'   => ['label' => 'Overdue',   'color' => 'danger',  'icon' => 'bi-x-circle-fill'],
            default     => ['label' => ucfirst($status), 'color' => 'secondary', 'icon' => 'bi-question-circle']
        };
    }

    private function getProbationBadge(string $status): array
    {
        return match ($status) {
            'fully_compliant'              => ['label' => 'Fully Compliant',   'color' => 'success', 'icon' => 'bi-shield-check'],
            'on_track'                     => ['label' => 'On Track',          'color' => 'info',    'icon' => 'bi-clock'],
            'at_risk'                      => ['label' => 'At Risk (<14 days)','color' => 'warning', 'icon' => 'bi-exclamation-triangle'],
            'probation_expired_incomplete' => ['label' => 'Expired — Incomplete', 'color' => 'danger', 'icon' => 'bi-x-octagon'],
            default                        => ['label' => ucfirst($status),    'color' => 'secondary', 'icon' => 'bi-question-circle']
        };
    }

    private function getDropOffRiskLevel(float $completionRate): string
    {
        if ($completionRate >= 80) return 'low';
        if ($completionRate >= 50) return 'medium';
        return 'high';
    }
}
