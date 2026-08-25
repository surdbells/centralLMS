<?php

/**
 * Completion & Progress Reports
 * Monitor course completion rates, learner progress, overdue training, time-to-completion
 *
 * Supports scoping by: course, department, team, branch, learner
 */

require_once __DIR__ . '/BaseReport.php';

class CompletionReports extends BaseReport
{
    /**
     * 1. Course Completion Summary
     * Overall completion statistics — the most-used report
     */
    public function completionSummary(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('ce');
        $date = $this->buildDateRangeWhere('ce.completed_at');
        $m = $this->mergeWhereClauses($org, $course, $date);

        // Summary stats
        $sql = "SELECT
            COUNT(*) as total_enrollments,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
            SUM(CASE WHEN ce.status = 'dropped' THEN 1 ELSE 0 END) as dropped,
            SUM(CASE WHEN ce.due_date IS NOT NULL AND ce.due_date < CURDATE() AND ce.status != 'completed' THEN 1 ELSE 0 END) as overdue,
            AVG(CASE WHEN ce.status = 'completed' AND ce.final_score IS NOT NULL THEN ce.final_score ELSE NULL END) as avg_score,
            AVG(CASE WHEN ce.status = 'completed' THEN ce.time_spent_minutes ELSE NULL END) as avg_completion_time,
            AVG(CASE WHEN ce.status = 'completed' AND ce.enrolled_at IS NOT NULL AND ce.completed_at IS NOT NULL
                THEN DATEDIFF(ce.completed_at, ce.enrolled_at) ELSE NULL END) as avg_days_to_complete
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 {$m['where']}";

        $row = $this->executeOne($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        $total = max((int) $row['total_enrollments'], 1);

        $summary = [
            'total_enrollments' => (int) $row['total_enrollments'],
            'completed' => (int) $row['completed'],
            'in_progress' => (int) $row['in_progress'],
            'not_started' => (int) $row['not_started'],
            'dropped' => (int) $row['dropped'],
            'overdue' => (int) $row['overdue'],
            'completion_rate' => $this->safePercent((int) $row['completed'], $total),
            'avg_score' => round((float) $row['avg_score'], 1),
            'avg_completion_time' => $this->formatMinutes((int) $row['avg_completion_time']),
            'avg_days_to_complete' => round((float) $row['avg_days_to_complete'], 1),
        ];

        // Completion trend (last 6 months)
        $trendSql = "SELECT DATE_FORMAT(ce.completed_at, '%Y-%m') as month_key,
            DATE_FORMAT(ce.completed_at, '%b %Y') as label,
            COUNT(*) as completions,
            AVG(ce.final_score) as avg_score
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.status = 'completed'
            AND ce.completed_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) {$m['where']}
            GROUP BY month_key, label ORDER BY month_key ASC";
        $summary['completion_trend'] = $this->executeQuery($trendSql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);

        return $summary;
    }

    /**
     * 2. Individual Progress Report
     * Detailed progress for a specific learner across all courses
     */
    public function individualProgress(): array
    {
        $lid = $this->learner_id;
        if (!$lid) return ['error' => 'learner_id required'];

        $course = $this->buildCourseScopeWhere('ce');

        $sql = "SELECT ce.enrollment_id, ce.course_id, ce.status,
            ce.progress_percentage, ce.final_score, ce.time_spent_minutes,
            ce.enrolled_at, ce.started_at, ce.completed_at, ce.due_date,
            ce.is_mandatory, ce.enrollment_source,
            c.title as course_title, c.duration_hours,
            COALESCE(cat.name, '') as category_name,
            CASE WHEN ce.due_date IS NOT NULL AND ce.due_date < CURDATE() AND ce.status != 'completed'
                THEN 1 ELSE 0 END as is_overdue,
            CASE WHEN ce.status = 'completed' AND ce.enrolled_at IS NOT NULL
                THEN DATEDIFF(ce.completed_at, ce.enrolled_at) ELSE NULL END as days_to_complete
            FROM course_enrollments ce
            JOIN courses c ON c.course_id = ce.course_id AND c.tenant_id = ce.tenant_id
            LEFT JOIN categories cat ON cat.category_id = c.category_id
            WHERE ce.tenant_id = ? AND ce.user_id = ? AND ce.is_active = 1 {$course['where']}
            ORDER BY ce.enrolled_at DESC";

        $data = $this->executeQuery($sql, 'ii' . $course['types'], [$this->tenant_id, $lid, ...$course['params']]);

        // Learner info
        $learner = $this->executeOne(
            "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) as name, u.email,
                COALESCE(d.department_name, '') as department,
                COALESCE(t.team_name, '') as team,
                COALESCE(b.branch_name, '') as branch
            FROM users u
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            WHERE u.id = ? AND u.tenant_id = ?",
            'ii', [$lid, $this->tenant_id]
        );

        // Compute summary
        $totalCourses = count($data);
        $completed = 0; $totalScore = 0; $scoreCount = 0; $totalTime = 0; $overdue = 0;
        foreach ($data as &$row) {
            $row['progress_percentage'] = (float) $row['progress_percentage'];
            $row['final_score'] = $row['final_score'] !== null ? (float) $row['final_score'] : null;
            $row['time_spent_formatted'] = $this->formatMinutes((int) $row['time_spent_minutes']);
            $row['is_overdue'] = (bool) $row['is_overdue'];
            $row['is_mandatory'] = (bool) $row['is_mandatory'];
            $totalTime += (int) $row['time_spent_minutes'];
            if ($row['status'] === 'completed') {
                $completed++;
                if ($row['final_score'] !== null) { $totalScore += $row['final_score']; $scoreCount++; }
            }
            if ($row['is_overdue']) $overdue++;
        }

        return [
            'learner' => $learner,
            'summary' => [
                'total_courses' => $totalCourses,
                'completed' => $completed,
                'completion_rate' => $this->safePercent($completed, max($totalCourses, 1)),
                'avg_score' => $scoreCount > 0 ? round($totalScore / $scoreCount, 1) : 0,
                'total_time' => $this->formatMinutes($totalTime),
                'overdue' => $overdue
            ],
            'courses' => $data
        ];
    }

    /**
     * 3. Completion by Department
     */
    public function completionByDepartment(): array
    {
        $course = $this->buildCourseScopeWhere('ce');
        $date = $this->buildDateRangeWhere('ce.enrolled_at');
        $m = $this->mergeWhereClauses($course, $date);

        $sql = "SELECT d.department_id, COALESCE(d.department_name, 'Unassigned') as department_name,
            COUNT(DISTINCT ce.user_id) as total_learners,
            COUNT(*) as total_enrollments,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
            SUM(CASE WHEN ce.due_date < CURDATE() AND ce.status != 'completed' THEN 1 ELSE 0 END) as overdue,
            AVG(ce.progress_percentage) as avg_progress,
            AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score ELSE NULL END) as avg_score,
            SUM(ce.time_spent_minutes) as total_time_minutes
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            WHERE ce.tenant_id = ? AND ce.is_active = 1 {$m['where']}
            GROUP BY d.department_id, d.department_name
            ORDER BY completed DESC";

        $rows = $this->executeQuery($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        foreach ($rows as &$r) {
            $r['completion_rate'] = $this->safePercent((int) $r['completed'], max((int) $r['total_enrollments'], 1));
            $r['avg_progress'] = round((float) $r['avg_progress'], 1);
            $r['avg_score'] = round((float) $r['avg_score'], 1);
            $r['total_time_formatted'] = $this->formatMinutes((int) $r['total_time_minutes']);
        }
        return $rows;
    }

    /**
     * 4. Completion by Team
     */
    public function completionByTeam(): array
    {
        $course = $this->buildCourseScopeWhere('ce');
        $date = $this->buildDateRangeWhere('ce.enrolled_at');
        $org = $this->buildOrgScopeWhere('u'); // allows dept filtering
        $m = $this->mergeWhereClauses($course, $date, $org);

        $sql = "SELECT t.team_id, COALESCE(t.team_name, 'Unassigned') as team_name,
            COALESCE(d.department_name, '') as department_name,
            COUNT(DISTINCT ce.user_id) as total_learners,
            COUNT(*) as total_enrollments,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN ce.due_date < CURDATE() AND ce.status != 'completed' THEN 1 ELSE 0 END) as overdue,
            AVG(ce.progress_percentage) as avg_progress,
            AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score ELSE NULL END) as avg_score
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN departments d ON d.department_id = u.department
            WHERE ce.tenant_id = ? AND ce.is_active = 1 {$m['where']}
            GROUP BY t.team_id, t.team_name, d.department_name
            ORDER BY completed DESC";

        $rows = $this->executeQuery($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        foreach ($rows as &$r) {
            $r['completion_rate'] = $this->safePercent((int) $r['completed'], max((int) $r['total_enrollments'], 1));
            $r['avg_progress'] = round((float) $r['avg_progress'], 1);
            $r['avg_score'] = round((float) $r['avg_score'], 1);
        }
        return $rows;
    }

    /**
     * 5. Completion by Branch
     */
    public function completionByBranch(): array
    {
        $course = $this->buildCourseScopeWhere('ce');
        $date = $this->buildDateRangeWhere('ce.enrolled_at');
        $m = $this->mergeWhereClauses($course, $date);

        $sql = "SELECT b.branch_id, COALESCE(b.branch_name, 'Unassigned') as branch_name,
            COALESCE(b.city, '') as city,
            COUNT(DISTINCT ce.user_id) as total_learners,
            COUNT(*) as total_enrollments,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN ce.due_date < CURDATE() AND ce.status != 'completed' THEN 1 ELSE 0 END) as overdue,
            AVG(ce.progress_percentage) as avg_progress,
            AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score ELSE NULL END) as avg_score
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            LEFT JOIN branches b ON b.branch_id = u.branch
            WHERE ce.tenant_id = ? AND ce.is_active = 1 {$m['where']}
            GROUP BY b.branch_id, b.branch_name, b.city
            ORDER BY completed DESC";

        $rows = $this->executeQuery($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        foreach ($rows as &$r) {
            $r['completion_rate'] = $this->safePercent((int) $r['completed'], max((int) $r['total_enrollments'], 1));
            $r['avg_progress'] = round((float) $r['avg_progress'], 1);
            $r['avg_score'] = round((float) $r['avg_score'], 1);
        }
        return $rows;
    }

    /**
     * 6. Overdue Training Report
     * Training past due dates — filterable by org unit
     */
    public function overdueTraining(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('ce');
        $m = $this->mergeWhereClauses($org, $course);

        $baseSql = "SELECT ce.enrollment_id, ce.course_id, ce.user_id, ce.status,
            ce.progress_percentage, ce.due_date, ce.is_mandatory,
            ce.enrolled_at, ce.started_at, ce.time_spent_minutes,
            DATEDIFF(CURDATE(), ce.due_date) as days_overdue,
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
            WHERE ce.tenant_id = ? AND ce.is_active = 1
            AND ce.status != 'completed' AND ce.due_date IS NOT NULL AND ce.due_date < CURDATE()
            {$m['where']}";

        $allTypes = 'i' . $m['types'];
        $allParams = [$this->tenant_id, ...$m['params']];
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY days_overdue DESC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        foreach ($data as &$row) {
            $row['days_overdue'] = (int) $row['days_overdue'];
            $row['progress_percentage'] = (float) $row['progress_percentage'];
            $row['is_mandatory'] = (bool) $row['is_mandatory'];
            $row['time_spent_formatted'] = $this->formatMinutes((int) $row['time_spent_minutes']);
            $row['urgency'] = $row['days_overdue'] > 30 ? 'critical' : ($row['days_overdue'] > 7 ? 'high' : 'medium');
        }

        // Summary
        $mandatoryOverdue = array_filter($data, fn($r) => $r['is_mandatory']);
        $summary = [
            'total_overdue' => $total,
            'mandatory_overdue' => count($mandatoryOverdue),
            'avg_days_overdue' => $total > 0 ? round(array_sum(array_column($data, 'days_overdue')) / count($data), 1) : 0
        ];

        return ['summary' => $summary, ...$this->paginatedResponse($data, $total)];
    }

    /**
     * 7. Time to Completion Analysis
     * How long users take to complete courses
     */
    public function timeToCompletion(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('ce');
        $date = $this->buildDateRangeWhere('ce.completed_at');
        $m = $this->mergeWhereClauses($org, $course, $date);

        $sql = "SELECT c.course_id, c.title as course_title,
            COUNT(*) as completions,
            AVG(DATEDIFF(ce.completed_at, ce.enrolled_at)) as avg_days,
            MIN(DATEDIFF(ce.completed_at, ce.enrolled_at)) as min_days,
            MAX(DATEDIFF(ce.completed_at, ce.enrolled_at)) as max_days,
            AVG(ce.time_spent_minutes) as avg_time_minutes,
            MIN(ce.time_spent_minutes) as min_time_minutes,
            MAX(ce.time_spent_minutes) as max_time_minutes,
            AVG(ce.final_score) as avg_score
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            JOIN courses c ON c.course_id = ce.course_id AND c.tenant_id = ce.tenant_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.status = 'completed'
            AND ce.enrolled_at IS NOT NULL AND ce.completed_at IS NOT NULL {$m['where']}
            GROUP BY c.course_id, c.title
            ORDER BY avg_days DESC";

        $rows = $this->executeQuery($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        foreach ($rows as &$r) {
            $r['avg_days'] = round((float) $r['avg_days'], 1);
            $r['avg_time_formatted'] = $this->formatMinutes((int) $r['avg_time_minutes']);
            $r['min_time_formatted'] = $this->formatMinutes((int) $r['min_time_minutes']);
            $r['max_time_formatted'] = $this->formatMinutes((int) $r['max_time_minutes']);
            $r['avg_score'] = round((float) $r['avg_score'], 1);
        }
        return $rows;
    }

    /**
     * 8. Completion Trend
     * Completion volume over time
     */
    public function completionTrend(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('ce');
        $date = $this->buildDateRangeWhere('ce.completed_at');
        $m = $this->mergeWhereClauses($org, $course, $date);

        $groupBy = $this->filters['group_by'] ?? 'monthly';
        $df = match ($groupBy) { 'daily' => '%Y-%m-%d', 'weekly' => '%x-W%v', default => '%Y-%m' };
        $lf = match ($groupBy) { 'daily' => '%b %d', 'weekly' => 'W%v %Y', default => '%b %Y' };

        $sql = "SELECT DATE_FORMAT(ce.completed_at, '{$df}') as period_key,
            DATE_FORMAT(ce.completed_at, '{$lf}') as period_label,
            COUNT(*) as completions,
            COUNT(DISTINCT ce.user_id) as unique_learners,
            AVG(ce.final_score) as avg_score,
            AVG(ce.time_spent_minutes) as avg_time_minutes
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.status = 'completed'
            AND ce.completed_at IS NOT NULL {$m['where']}
            GROUP BY period_key, period_label ORDER BY period_key ASC";

        $rows = $this->executeQuery($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        foreach ($rows as &$r) {
            $r['completions'] = (int) $r['completions'];
            $r['avg_score'] = round((float) $r['avg_score'], 1);
            $r['avg_time_formatted'] = $this->formatMinutes((int) $r['avg_time_minutes']);
        }
        return $rows;
    }

    public function getExportColumns(string $report): array
    {
        return match ($report) {
            'overdue_training' => [
                ['key' => 'learner_name', 'label' => 'Learner'], ['key' => 'email', 'label' => 'Email'],
                ['key' => 'course_title', 'label' => 'Course'], ['key' => 'department_name', 'label' => 'Department'],
                ['key' => 'team_name', 'label' => 'Team'], ['key' => 'branch_name', 'label' => 'Branch'],
                ['key' => 'status', 'label' => 'Status'], ['key' => 'progress_percentage', 'label' => 'Progress %'],
                ['key' => 'due_date', 'label' => 'Due Date'], ['key' => 'days_overdue', 'label' => 'Days Overdue'],
                ['key' => 'is_mandatory', 'label' => 'Mandatory'], ['key' => 'urgency', 'label' => 'Urgency'],
            ],
            default => []
        };
    }
}
