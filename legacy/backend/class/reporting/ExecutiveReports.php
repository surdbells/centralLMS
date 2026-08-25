<?php

/**
 * Executive & Summary Reports
 * High-level dashboards for leadership: KPIs, ROI, monthly/yearly comparisons
 */

require_once __DIR__ . '/BaseReport.php';

class ExecutiveReports extends BaseReport
{
    /**
     * 1. Executive Training Dashboard
     * Single-page KPI overview for executives
     */
    public function executiveDashboard(): array
    {
        $t = $this->tenant_id;
        $course = $this->buildCourseScopeWhere('ce');

        // Core KPIs
        $kpiSql = "SELECT
            COUNT(DISTINCT ce.user_id) as total_learners,
            COUNT(*) as total_enrollments,
            SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END) as completions,
            SUM(CASE WHEN ce.is_mandatory=1 THEN 1 ELSE 0 END) as mandatory_total,
            SUM(CASE WHEN ce.is_mandatory=1 AND ce.status='completed' THEN 1 ELSE 0 END) as mandatory_completed,
            SUM(CASE WHEN ce.due_date < CURDATE() AND ce.status != 'completed' THEN 1 ELSE 0 END) as overdue,
            AVG(CASE WHEN ce.status='completed' THEN ce.final_score ELSE NULL END) as avg_score,
            SUM(ce.time_spent_minutes) as total_time_minutes,
            AVG(ce.progress_percentage) as avg_progress
            FROM course_enrollments ce
            WHERE ce.tenant_id = ? AND ce.is_active = 1 {$course['where']}";

        $kpi = $this->executeOne($kpiSql, 'i' . $course['types'], [$t, ...$course['params']]);
        $totalEnroll = max((int) $kpi['total_enrollments'], 1);
        $mandTotal = max((int) $kpi['mandatory_total'], 1);

        // Previous period (30 days ago) for trend comparison
        $prevSql = "SELECT
            SUM(CASE WHEN ce.status='completed' AND ce.completed_at >= DATE_SUB(NOW(), INTERVAL 60 DAY) AND ce.completed_at < DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as prev_completions,
            SUM(CASE WHEN ce.status='completed' AND ce.completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as curr_completions
            FROM course_enrollments ce
            WHERE ce.tenant_id = ? AND ce.is_active = 1 {$course['where']}";
        $trend = $this->executeOne($prevSql, 'i' . $course['types'], [$t, ...$course['params']]);
        $prevComp = max((int) $trend['prev_completions'], 1);
        $currComp = (int) $trend['curr_completions'];
        $completionTrend = round((($currComp - $prevComp) / $prevComp) * 100, 1);

        // Monthly completion trend (last 6 months)
        $monthlySql = "SELECT DATE_FORMAT(ce.completed_at, '%Y-%m') as month_key,
            DATE_FORMAT(ce.completed_at, '%b %Y') as label,
            COUNT(*) as completions,
            COUNT(DISTINCT ce.user_id) as learners,
            AVG(ce.final_score) as avg_score
            FROM course_enrollments ce
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.status='completed'
            AND ce.completed_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) {$course['where']}
            GROUP BY month_key, label ORDER BY month_key";
        $monthly = $this->executeQuery($monthlySql, 'i' . $course['types'], [$t, ...$course['params']]);

        // Top 5 departments by completion
        $deptSql = "SELECT COALESCE(d.department_name, 'Unassigned') as department,
            COUNT(*) as enrollments,
            SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END) as completed
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            WHERE ce.tenant_id = ? AND ce.is_active = 1 {$course['where']}
            GROUP BY d.department_id, d.department_name ORDER BY completed DESC LIMIT 5";
        $topDepts = $this->executeQuery($deptSql, 'i' . $course['types'], [$t, ...$course['params']]);
        foreach ($topDepts as &$td) {
            $td['completion_rate'] = $this->safePercent((int) $td['completed'], max((int) $td['enrollments'], 1));
        }

        return [
            'kpis' => [
                'total_learners' => (int) $kpi['total_learners'],
                'total_enrollments' => (int) $kpi['total_enrollments'],
                'completion_rate' => $this->safePercent((int) $kpi['completions'], $totalEnroll),
                'compliance_rate' => $this->safePercent((int) $kpi['mandatory_completed'], $mandTotal),
                'overdue_count' => (int) $kpi['overdue'],
                'avg_score' => round((float) $kpi['avg_score'], 1),
                'total_learning_hours' => round((int) $kpi['total_time_minutes'] / 60, 1),
                'avg_progress' => round((float) $kpi['avg_progress'], 1),
                'completion_trend_pct' => $completionTrend,
            ],
            'monthly_trend' => $monthly,
            'top_departments' => $topDepts,
        ];
    }

    /**
     * 2. Monthly Training Summary
     */
    public function monthlyTrainingSummary(): array
    {
        $course = $this->buildCourseScopeWhere('ce');
        $month = $this->filters['month'] ?? date('Y-m');

        $sql = "SELECT
            COUNT(DISTINCT ce.user_id) as active_learners,
            COUNT(CASE WHEN DATE_FORMAT(ce.enrolled_at,'%Y-%m')=? THEN 1 END) as new_enrollments,
            SUM(CASE WHEN ce.status='completed' AND DATE_FORMAT(ce.completed_at,'%Y-%m')=? THEN 1 ELSE 0 END) as completions,
            SUM(CASE WHEN ce.is_mandatory=1 AND ce.status='completed' AND DATE_FORMAT(ce.completed_at,'%Y-%m')=? THEN 1 ELSE 0 END) as mandatory_completed,
            AVG(CASE WHEN ce.status='completed' AND DATE_FORMAT(ce.completed_at,'%Y-%m')=? THEN ce.final_score ELSE NULL END) as avg_score,
            SUM(CASE WHEN DATE_FORMAT(ce.enrolled_at,'%Y-%m')=? OR (ce.status='in_progress') THEN ce.time_spent_minutes ELSE 0 END) as total_time
            FROM course_enrollments ce
            WHERE ce.tenant_id = ? AND ce.is_active = 1 {$course['where']}";

        $allTypes = 'sssssi' . $course['types'];
        $allParams = [$month, $month, $month, $month, $month, $this->tenant_id, ...$course['params']];
        $row = $this->executeOne($sql, $allTypes, $allParams);

        return [
            'month' => $month,
            'active_learners' => (int) $row['active_learners'],
            'new_enrollments' => (int) $row['new_enrollments'],
            'completions' => (int) $row['completions'],
            'mandatory_completed' => (int) $row['mandatory_completed'],
            'avg_score' => round((float) $row['avg_score'], 1),
            'total_learning_hours' => round((int) $row['total_time'] / 60, 1),
        ];
    }

    /**
     * 3. Training ROI Report (basic — no cost tables, uses time-based proxy)
     */
    public function trainingROI(): array
    {
        $course = $this->buildCourseScopeWhere('ce');

        $sql = "SELECT c.course_id, c.title, c.duration_hours,
            COUNT(DISTINCT ce.user_id) as learners,
            SUM(CASE WHEN ce.status='completed' THEN 1 ELSE 0 END) as completions,
            SUM(ce.time_spent_minutes) as total_time_minutes,
            AVG(CASE WHEN ce.status='completed' THEN ce.final_score ELSE NULL END) as avg_score,
            AVG(CASE WHEN ce.status='completed' THEN DATEDIFF(ce.completed_at, ce.enrolled_at) ELSE NULL END) as avg_days
            FROM course_enrollments ce
            JOIN courses c ON c.course_id = ce.course_id AND c.tenant_id = ce.tenant_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 {$course['where']}
            GROUP BY c.course_id, c.title, c.duration_hours
            HAVING completions > 0
            ORDER BY completions DESC";

        $rows = $this->executeQuery($sql, 'i' . $course['types'], [$this->tenant_id, ...$course['params']]);
        foreach ($rows as &$r) {
            $r['completion_rate'] = $this->safePercent((int) $r['completions'], max((int) $r['learners'], 1));
            $r['avg_score'] = round((float) $r['avg_score'], 1);
            $r['total_hours'] = round((int) $r['total_time_minutes'] / 60, 1);
            $r['avg_days'] = round((float) $r['avg_days'], 1);
            // Efficiency: score per hour invested
            $r['score_per_hour'] = $r['total_hours'] > 0 ? round($r['avg_score'] / ($r['total_hours'] / max((int) $r['completions'], 1)), 1) : 0;
        }
        return $rows;
    }

    /**
     * 4. Year-over-Year Comparison
     */
    public function yearOverYear(): array
    {
        $currentYear = (int) ($this->filters['year'] ?? date('Y'));
        $prevYear = $currentYear - 1;

        $sql = "SELECT
            SUM(CASE WHEN YEAR(ce.enrolled_at)=? THEN 1 ELSE 0 END) as curr_enrollments,
            SUM(CASE WHEN YEAR(ce.enrolled_at)=? THEN 1 ELSE 0 END) as prev_enrollments,
            SUM(CASE WHEN ce.status='completed' AND YEAR(ce.completed_at)=? THEN 1 ELSE 0 END) as curr_completions,
            SUM(CASE WHEN ce.status='completed' AND YEAR(ce.completed_at)=? THEN 1 ELSE 0 END) as prev_completions,
            AVG(CASE WHEN ce.status='completed' AND YEAR(ce.completed_at)=? THEN ce.final_score ELSE NULL END) as curr_avg_score,
            AVG(CASE WHEN ce.status='completed' AND YEAR(ce.completed_at)=? THEN ce.final_score ELSE NULL END) as prev_avg_score,
            SUM(CASE WHEN YEAR(ce.enrolled_at)=? THEN ce.time_spent_minutes ELSE 0 END) as curr_time,
            SUM(CASE WHEN YEAR(ce.enrolled_at)=? THEN ce.time_spent_minutes ELSE 0 END) as prev_time
            FROM course_enrollments ce
            WHERE ce.tenant_id = ? AND ce.is_active = 1";

        $row = $this->executeOne($sql, 'iiiiiiiis', [$currentYear, $prevYear, $currentYear, $prevYear, $currentYear, $prevYear, $currentYear, $prevYear, $this->tenant_id]);

        $calcGrowth = fn($curr, $prev) => $prev > 0 ? round((($curr - $prev) / $prev) * 100, 1) : ($curr > 0 ? 100 : 0);

        return [
            'current_year' => $currentYear,
            'previous_year' => $prevYear,
            'enrollments' => [
                'current' => (int) $row['curr_enrollments'], 'previous' => (int) $row['prev_enrollments'],
                'growth' => $calcGrowth((int) $row['curr_enrollments'], (int) $row['prev_enrollments']),
            ],
            'completions' => [
                'current' => (int) $row['curr_completions'], 'previous' => (int) $row['prev_completions'],
                'growth' => $calcGrowth((int) $row['curr_completions'], (int) $row['prev_completions']),
            ],
            'avg_score' => [
                'current' => round((float) $row['curr_avg_score'], 1), 'previous' => round((float) $row['prev_avg_score'], 1),
            ],
            'learning_hours' => [
                'current' => round((int) $row['curr_time'] / 60, 1), 'previous' => round((int) $row['prev_time'] / 60, 1),
                'growth' => $calcGrowth((int) $row['curr_time'], (int) $row['prev_time']),
            ],
        ];
    }

    /**
     * 5. Training Budget Utilization (proxy — no cost table)
     */
    public function budgetUtilization(): array
    {
        return ['info' => 'Training budget/cost table not yet implemented. Add a training_costs table to enable full ROI and budget tracking.'];
    }

    public function getExportColumns(string $report): array { return []; }
}
