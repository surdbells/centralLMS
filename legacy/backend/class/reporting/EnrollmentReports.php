<?php

/**
 * Enrollment Reports
 * Tracks how learners engage with the LMS: enrollments, self-registrations, waitlists, trends
 *
 * Supports scoping by: course, department, team, branch, learner
 */

require_once __DIR__ . '/BaseReport.php';

class EnrollmentReports extends BaseReport
{
    /**
     * 1. Enrollment Summary
     * Overall enrollment statistics with breakdown by status
     */
    public function enrollmentSummary(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('ce');
        $date = $this->buildDateRangeWhere('ce.enrolled_at');
        $m = $this->mergeWhereClauses($org, $course, $date);

        $sql = "SELECT
            COUNT(*) as total_enrollments,
            SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
            SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.status = 'dropped' THEN 1 ELSE 0 END) as dropped,
            SUM(CASE WHEN ce.is_mandatory = 1 THEN 1 ELSE 0 END) as mandatory_enrollments,
            COUNT(DISTINCT ce.user_id) as unique_learners,
            COUNT(DISTINCT ce.course_id) as unique_courses,
            AVG(ce.progress_percentage) as avg_progress,
            AVG(CASE WHEN ce.status = 'completed' AND ce.final_score IS NOT NULL THEN ce.final_score ELSE NULL END) as avg_score,
            AVG(CASE WHEN ce.status = 'completed' THEN ce.time_spent_minutes ELSE NULL END) as avg_time_minutes,
            SUM(ce.time_spent_minutes) as total_time_minutes
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 {$m['where']}";

        $row = $this->executeOne($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);

        // Status breakdown for chart
        $total = max((int) $row['total_enrollments'], 1);
        $summary = [
            'total_enrollments' => (int) $row['total_enrollments'],
            'unique_learners' => (int) $row['unique_learners'],
            'unique_courses' => (int) $row['unique_courses'],
            'mandatory_enrollments' => (int) $row['mandatory_enrollments'],
            'avg_progress' => round((float) $row['avg_progress'], 1),
            'avg_score' => round((float) $row['avg_score'], 1),
            'avg_time_formatted' => $this->formatMinutes((int) $row['avg_time_minutes']),
            'total_time_formatted' => $this->formatMinutes((int) $row['total_time_minutes']),
            'status_breakdown' => [
                ['status' => 'not_started', 'count' => (int) $row['not_started'], 'percent' => $this->safePercent((int) $row['not_started'], $total)],
                ['status' => 'in_progress', 'count' => (int) $row['in_progress'], 'percent' => $this->safePercent((int) $row['in_progress'], $total)],
                ['status' => 'completed', 'count' => (int) $row['completed'], 'percent' => $this->safePercent((int) $row['completed'], $total)],
                ['status' => 'dropped', 'count' => (int) $row['dropped'], 'percent' => $this->safePercent((int) $row['dropped'], $total)],
            ]
        ];

        // By department breakdown
        $deptSql = "SELECT COALESCE(d.department_name, 'Unassigned') as department,
            COUNT(*) as total, SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END) as completed
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            WHERE ce.tenant_id = ? AND ce.is_active = 1 {$m['where']}
            GROUP BY d.department_id, d.department_name ORDER BY total DESC LIMIT 15";
        $summary['by_department'] = $this->executeQuery($deptSql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);

        return $summary;
    }

    /**
     * 2. Enrollment Trends
     * Enrollment volume over time (daily/weekly/monthly)
     */
    public function enrollmentTrends(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('ce');
        $date = $this->buildDateRangeWhere('ce.enrolled_at');
        $m = $this->mergeWhereClauses($org, $course, $date);

        $groupBy = $this->filters['group_by'] ?? 'monthly';
        $dateFormat = match ($groupBy) {
            'daily' => '%Y-%m-%d',
            'weekly' => '%x-W%v',
            default => '%Y-%m'
        };
        $labelFormat = match ($groupBy) {
            'daily' => '%b %d',
            'weekly' => 'W%v %Y',
            default => '%b %Y'
        };

        $sql = "SELECT DATE_FORMAT(ce.enrolled_at, '{$dateFormat}') as period_key,
            DATE_FORMAT(ce.enrolled_at, '{$labelFormat}') as period_label,
            COUNT(*) as enrollments,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completions,
            SUM(CASE WHEN ce.status = 'dropped' THEN 1 ELSE 0 END) as drops,
            COUNT(DISTINCT ce.user_id) as unique_learners
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.enrolled_at IS NOT NULL {$m['where']}
            GROUP BY period_key, period_label ORDER BY period_key ASC";

        return $this->executeQuery($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
    }

    /**
     * 3. Enrollment Detail List (paginated)
     * Detailed enrollment records for tables
     */
    public function enrollmentDetailList(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('ce');
        $date = $this->buildDateRangeWhere('ce.enrolled_at');
        $m = $this->mergeWhereClauses($org, $course, $date);

        // Status filter
        $statusWhere = '';
        $statusTypes = '';
        $statusParams = [];
        if (!empty($this->filters['status'])) {
            $statusWhere = " AND ce.status = ?";
            $statusTypes = 's';
            $statusParams[] = $this->filters['status'];
        }
        if (!empty($this->filters['is_mandatory'])) {
            $statusWhere .= " AND ce.is_mandatory = 1";
        }
        if (!empty($this->filters['search'])) {
            $search = '%' . $this->filters['search'] . '%';
            $statusWhere .= " AND (CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.email LIKE ? OR c.title LIKE ?)";
            $statusTypes .= 'sss';
            $statusParams = array_merge($statusParams, [$search, $search, $search]);
        }

        $baseSql = "SELECT ce.enrollment_id, ce.course_id, ce.user_id, ce.status,
            ce.progress_percentage, ce.final_score, ce.time_spent_minutes,
            ce.enrolled_at, ce.started_at, ce.completed_at, ce.due_date,
            ce.is_mandatory, ce.enrollment_source,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(t.team_name, '') as team_name,
            COALESCE(b.branch_name, '') as branch_name,
            c.title as course_title,
            COALESCE(cat.name, '') as category_name
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            JOIN courses c ON c.course_id = ce.course_id AND c.tenant_id = ce.tenant_id
            LEFT JOIN categories cat ON cat.category_id = c.category_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 {$m['where']}{$statusWhere}";

        $allTypes = 'i' . $m['types'] . $statusTypes;
        $allParams = [$this->tenant_id, ...$m['params'], ...$statusParams];

        $total = $this->getCount($baseSql, $allTypes, $allParams);

        $sortCol = $this->sort_by ?: 'ce.enrolled_at';
        $allowedSorts = ['learner_name', 'course_title', 'status', 'progress_percentage',
            'final_score', 'enrolled_at', 'completed_at', 'time_spent_minutes', 'department_name'];
        if (!in_array($this->sort_by, $allowedSorts)) $sortCol = 'ce.enrolled_at';
        if ($this->sort_by === 'learner_name') $sortCol = 'learner_name';
        if ($this->sort_by === 'course_title') $sortCol = 'c.title';

        $sql = $baseSql . " ORDER BY {$sortCol} {$this->sort_dir}" . $this->getLimitClause();

        $data = $this->executeQuery($sql, $allTypes, $allParams);

        // Format
        foreach ($data as &$row) {
            $row['progress_percentage'] = (float) $row['progress_percentage'];
            $row['final_score'] = $row['final_score'] !== null ? (float) $row['final_score'] : null;
            $row['time_spent_formatted'] = $this->formatMinutes((int) $row['time_spent_minutes']);
            $row['is_mandatory'] = (bool) $row['is_mandatory'];
            $row['is_overdue'] = $row['due_date'] && $row['status'] !== 'completed' && strtotime($row['due_date']) < time();
        }

        return $this->paginatedResponse($data, $total);
    }

    /**
     * 4. Pending / Not Started Report
     * Users enrolled but haven't started their courses
     */
    public function pendingEnrollments(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('ce');
        $m = $this->mergeWhereClauses($org, $course);

        $baseSql = "SELECT ce.enrollment_id, ce.course_id, ce.user_id,
            ce.enrolled_at, ce.due_date, ce.is_mandatory,
            DATEDIFF(NOW(), ce.enrolled_at) as days_since_enrollment,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(t.team_name, '') as team_name,
            COALESCE(b.branch_name, '') as branch_name,
            c.title as course_title
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            JOIN courses c ON c.course_id = ce.course_id AND c.tenant_id = ce.tenant_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.status = 'not_started' {$m['where']}";

        $allTypes = 'i' . $m['types'];
        $allParams = [$this->tenant_id, ...$m['params']];
        $total = $this->getCount($baseSql, $allTypes, $allParams);

        $sql = $baseSql . " ORDER BY ce.enrolled_at ASC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        foreach ($data as &$row) {
            $row['days_since_enrollment'] = (int) $row['days_since_enrollment'];
            $row['is_mandatory'] = (bool) $row['is_mandatory'];
            $row['is_overdue'] = $row['due_date'] && strtotime($row['due_date']) < time();
        }

        return $this->paginatedResponse($data, $total);
    }

    /**
     * 5. Enrollment by Source
     * How learners were enrolled (auto-assign, manual, self, import)
     */
    public function enrollmentBySource(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('ce');
        $date = $this->buildDateRangeWhere('ce.enrolled_at');
        $m = $this->mergeWhereClauses($org, $course, $date);

        $sql = "SELECT COALESCE(ce.enrollment_source, 'Unknown') as source,
            COUNT(*) as total,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
            SUM(CASE WHEN ce.status = 'dropped' THEN 1 ELSE 0 END) as dropped,
            AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score ELSE NULL END) as avg_score,
            AVG(ce.progress_percentage) as avg_progress
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 {$m['where']}
            GROUP BY source ORDER BY total DESC";

        $rows = $this->executeQuery($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        $grand = 0;
        foreach ($rows as &$r) {
            $r['total'] = (int) $r['total'];
            $r['completed'] = (int) $r['completed'];
            $r['completion_rate'] = $this->safePercent((int) $r['completed'], (int) $r['total']);
            $r['avg_score'] = round((float) $r['avg_score'], 1);
            $r['avg_progress'] = round((float) $r['avg_progress'], 1);
            $grand += $r['total'];
        }
        foreach ($rows as &$r) {
            $r['percentage_of_total'] = $this->safePercent($r['total'], $grand);
        }
        return $rows;
    }

    /**
     * 6. Drop-off Report
     * Learners who enrolled but dropped / never progressed
     */
    public function dropOffReport(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('ce');
        $date = $this->buildDateRangeWhere('ce.enrolled_at');
        $m = $this->mergeWhereClauses($org, $course, $date);

        $baseSql = "SELECT ce.enrollment_id, ce.course_id, ce.user_id, ce.status,
            ce.progress_percentage, ce.time_spent_minutes,
            ce.enrolled_at, ce.started_at, ce.due_date,
            DATEDIFF(NOW(), COALESCE(ce.started_at, ce.enrolled_at)) as days_inactive,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(b.branch_name, '') as branch_name,
            c.title as course_title
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN branches b ON b.branch_id = u.branch
            JOIN courses c ON c.course_id = ce.course_id AND c.tenant_id = ce.tenant_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1
            AND ce.status IN ('dropped', 'not_started', 'in_progress')
            AND (ce.status = 'dropped'
                 OR (ce.status = 'not_started' AND ce.enrolled_at < DATE_SUB(NOW(), INTERVAL 14 DAY))
                 OR (ce.status = 'in_progress' AND ce.last_activity_at < DATE_SUB(NOW(), INTERVAL 14 DAY)))
            {$m['where']}";

        $allTypes = 'i' . $m['types'];
        $allParams = [$this->tenant_id, ...$m['params']];
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY days_inactive DESC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        foreach ($data as &$row) {
            $row['progress_percentage'] = (float) $row['progress_percentage'];
            $row['days_inactive'] = (int) $row['days_inactive'];
            $row['time_spent_formatted'] = $this->formatMinutes((int) $row['time_spent_minutes']);
        }

        return $this->paginatedResponse($data, $total);
    }

    /**
     * 7. Enrollment by Course (Aggregate)
     * Per-course enrollment breakdown — useful when no course filter applied
     */
    public function enrollmentByCourse(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $date = $this->buildDateRangeWhere('ce.enrolled_at');
        $m = $this->mergeWhereClauses($org, $date);

        $sql = "SELECT c.course_id, c.title as course_title,
            COALESCE(cat.name, 'Uncategorized') as category,
            COUNT(*) as total_enrolled,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
            SUM(CASE WHEN ce.status = 'dropped' THEN 1 ELSE 0 END) as dropped,
            AVG(ce.progress_percentage) as avg_progress,
            AVG(CASE WHEN ce.status = 'completed' AND ce.final_score IS NOT NULL THEN ce.final_score ELSE NULL END) as avg_score,
            SUM(ce.time_spent_minutes) as total_time_minutes
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            JOIN courses c ON c.course_id = ce.course_id AND c.tenant_id = ce.tenant_id
            LEFT JOIN categories cat ON cat.category_id = c.category_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 {$m['where']}
            GROUP BY c.course_id, c.title, cat.name
            ORDER BY total_enrolled DESC";

        $rows = $this->executeQuery($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        foreach ($rows as &$r) {
            $r['course_id'] = (int) $r['course_id'];
            $r['total_enrolled'] = (int) $r['total_enrolled'];
            $r['completed'] = (int) $r['completed'];
            $r['completion_rate'] = $this->safePercent((int) $r['completed'], (int) $r['total_enrolled']);
            $r['avg_progress'] = round((float) $r['avg_progress'], 1);
            $r['avg_score'] = round((float) $r['avg_score'], 1);
            $r['total_time_formatted'] = $this->formatMinutes((int) $r['total_time_minutes']);
        }
        return $rows;
    }
    /**
     * Report 6: Enrollment by Source
     */
    // CSV column definitions
    public function getExportColumns(string $report): array
    {
        return match ($report) {
            'enrollment_detail_list' => [
                ['key' => 'learner_name', 'label' => 'Learner'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'course_title', 'label' => 'Course'],
                ['key' => 'department_name', 'label' => 'Department'],
                ['key' => 'team_name', 'label' => 'Team'],
                ['key' => 'branch_name', 'label' => 'Branch'],
                ['key' => 'status', 'label' => 'Status'],
                ['key' => 'progress_percentage', 'label' => 'Progress %'],
                ['key' => 'final_score', 'label' => 'Score'],
                ['key' => 'time_spent_formatted', 'label' => 'Time Spent'],
                ['key' => 'enrolled_at', 'label' => 'Enrolled'],
                ['key' => 'completed_at', 'label' => 'Completed'],
                ['key' => 'is_mandatory', 'label' => 'Mandatory'],
                ['key' => 'enrollment_source', 'label' => 'Source'],
            ],
            default => []
        };
    }
}
