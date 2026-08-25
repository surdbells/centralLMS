<?php

/**
 * Time & Engagement Reports
 * Track learning time, session duration, peak usage, engagement scores
 *
 * Supports scoping by: course, department, team, branch, learner
 */

require_once __DIR__ . '/BaseReport.php';

class TimeEngagementReports extends BaseReport
{
    /**
     * 1. Time Spent Report
     * Total learning time by user/course/department
     */
    public function timeSpent(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('ce');
        $date = $this->buildDateRangeWhere('ce.enrolled_at');
        $m = $this->mergeWhereClauses($org, $course, $date);

        $groupBy = $this->filters['group_by'] ?? 'learner';

        if ($groupBy === 'course') {
            $sql = "SELECT c.course_id, c.title as group_name,
                COUNT(DISTINCT ce.user_id) as learner_count,
                SUM(ce.time_spent_minutes) as total_minutes,
                AVG(ce.time_spent_minutes) as avg_minutes,
                MIN(ce.time_spent_minutes) as min_minutes,
                MAX(ce.time_spent_minutes) as max_minutes
                FROM course_enrollments ce
                JOIN users u ON u.id = ce.user_id
                JOIN courses c ON c.course_id = ce.course_id AND c.tenant_id = ce.tenant_id
                WHERE ce.tenant_id = ? AND ce.is_active = 1 {$m['where']}
                GROUP BY c.course_id, c.title ORDER BY total_minutes DESC";
        } elseif ($groupBy === 'department') {
            $sql = "SELECT d.department_id, COALESCE(d.department_name, 'Unassigned') as group_name,
                COUNT(DISTINCT ce.user_id) as learner_count,
                SUM(ce.time_spent_minutes) as total_minutes,
                AVG(ce.time_spent_minutes) as avg_minutes,
                MIN(ce.time_spent_minutes) as min_minutes,
                MAX(ce.time_spent_minutes) as max_minutes
                FROM course_enrollments ce
                JOIN users u ON u.id = ce.user_id
                LEFT JOIN departments d ON d.department_id = u.department
                WHERE ce.tenant_id = ? AND ce.is_active = 1 {$m['where']}
                GROUP BY d.department_id, d.department_name ORDER BY total_minutes DESC";
        } else {
            // by learner
            $sql = "SELECT u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as group_name, u.email,
                COALESCE(d.department_name, '') as department_name,
                COALESCE(t.team_name, '') as team_name,
                COALESCE(b.branch_name, '') as branch_name,
                COUNT(DISTINCT ce.course_id) as course_count,
                SUM(ce.time_spent_minutes) as total_minutes,
                AVG(ce.time_spent_minutes) as avg_minutes
                FROM course_enrollments ce
                JOIN users u ON u.id = ce.user_id
                LEFT JOIN departments d ON d.department_id = u.department
                LEFT JOIN teams t ON t.team_id = u.team
                LEFT JOIN branches b ON b.branch_id = u.branch
                WHERE ce.tenant_id = ? AND ce.is_active = 1 {$m['where']}
                GROUP BY u.id, u.first_name, u.last_name, u.email, d.department_name, t.team_name, b.branch_name
                ORDER BY total_minutes DESC";
        }

        $rows = $this->executeQuery($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        $grandTotal = 0;
        foreach ($rows as &$r) {
            $r['total_minutes'] = (int) $r['total_minutes'];
            $r['avg_minutes'] = round((float) $r['avg_minutes'], 1);
            $r['total_formatted'] = $this->formatMinutes($r['total_minutes']);
            $r['avg_formatted'] = $this->formatMinutes((int) $r['avg_minutes']);
            $grandTotal += $r['total_minutes'];
        }

        return [
            'group_by' => $groupBy,
            'grand_total_formatted' => $this->formatMinutes($grandTotal),
            'grand_total_minutes' => $grandTotal,
            'data' => $rows
        ];
    }

    /**
     * 2. Peak Usage Report
     * When the LMS is most used — by hour and day of week
     */
    public function peakUsage(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $date = $this->buildDateRangeWhere('ls.started_at');
        $m = $this->mergeWhereClauses($org, $date);

        $hasTable = $this->tableExists('learning_sessions');

        if ($hasTable) {
            // By hour of day
            $hourSql = "SELECT HOUR(ls.started_at) as hour_of_day,
                COUNT(*) as session_count,
                COUNT(DISTINCT ls.user_id) as unique_users,
                SUM(ls.duration_minutes) as total_minutes
                FROM learning_sessions ls
                JOIN users u ON u.id = ls.user_id
                WHERE ls.tenant_id = ? {$m['where']}
                GROUP BY HOUR(ls.started_at) ORDER BY hour_of_day";

            $byHour = $this->executeQuery($hourSql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);

            // By day of week
            $daySql = "SELECT DAYNAME(ls.started_at) as day_name,
                DAYOFWEEK(ls.started_at) as day_num,
                COUNT(*) as session_count,
                COUNT(DISTINCT ls.user_id) as unique_users,
                SUM(ls.duration_minutes) as total_minutes
                FROM learning_sessions ls
                JOIN users u ON u.id = ls.user_id
                WHERE ls.tenant_id = ? {$m['where']}
                GROUP BY DAYNAME(ls.started_at), DAYOFWEEK(ls.started_at)
                ORDER BY day_num";

            $byDay = $this->executeQuery($daySql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        } else {
            // Fallback: use enrollment dates
            $hourSql = "SELECT HOUR(ce.enrolled_at) as hour_of_day,
                COUNT(*) as session_count,
                COUNT(DISTINCT ce.user_id) as unique_users
                FROM course_enrollments ce
                JOIN users u ON u.id = ce.user_id
                WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.enrolled_at IS NOT NULL {$m['where']}
                GROUP BY HOUR(ce.enrolled_at) ORDER BY hour_of_day";
            $byHour = $this->executeQuery($hourSql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);

            $daySql = "SELECT DAYNAME(ce.enrolled_at) as day_name,
                DAYOFWEEK(ce.enrolled_at) as day_num,
                COUNT(*) as session_count
                FROM course_enrollments ce
                JOIN users u ON u.id = ce.user_id
                WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.enrolled_at IS NOT NULL {$m['where']}
                GROUP BY DAYNAME(ce.enrolled_at), DAYOFWEEK(ce.enrolled_at) ORDER BY day_num";
            $byDay = $this->executeQuery($daySql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        }

        foreach ($byHour as &$h) {
            $hr = (int) $h['hour_of_day'];
            $h['label'] = ($hr < 12 ? $hr : ($hr - 12 ?: 12)) . ($hr < 12 ? 'AM' : 'PM');
        }

        return ['by_hour' => $byHour, 'by_day' => $byDay, 'source' => $hasTable ? 'learning_sessions' : 'enrollments'];
    }

    /**
     * 3. Session Duration Report
     * Average session length and patterns
     */
    public function sessionDuration(): array
    {
        if (!$this->tableExists('learning_sessions')) {
            return ['info' => 'learning_sessions table not available — use enrollment time_spent instead'];
        }

        $org = $this->buildOrgScopeWhere('u');
        $date = $this->buildDateRangeWhere('ls.started_at');
        $m = $this->mergeWhereClauses($org, $date);

        $courseWhere = $this->course_id ? " AND ls.course_id = ?" : "";
        $courseTypes = $this->course_id ? "i" : "";
        $courseParams = $this->course_id ? [$this->course_id] : [];

        // Summary
        $summSql = "SELECT
            COUNT(*) as total_sessions,
            COUNT(DISTINCT ls.user_id) as unique_users,
            AVG(ls.duration_minutes) as avg_duration,
            MIN(ls.duration_minutes) as min_duration,
            MAX(ls.duration_minutes) as max_duration,
            SUM(ls.duration_minutes) as total_minutes,
            STDDEV(ls.duration_minutes) as std_dev
            FROM learning_sessions ls
            JOIN users u ON u.id = ls.user_id
            WHERE ls.tenant_id = ? {$m['where']}{$courseWhere}";

        $allTypes = 'i' . $m['types'] . $courseTypes;
        $allParams = [$this->tenant_id, ...$m['params'], ...$courseParams];
        $summary = $this->executeOne($summSql, $allTypes, $allParams);

        // Duration buckets: 0-5, 6-15, 16-30, 31-60, 60+
        $bucketSql = "SELECT
            CASE
                WHEN ls.duration_minutes <= 5 THEN '0-5 min'
                WHEN ls.duration_minutes <= 15 THEN '6-15 min'
                WHEN ls.duration_minutes <= 30 THEN '16-30 min'
                WHEN ls.duration_minutes <= 60 THEN '31-60 min'
                ELSE '60+ min'
            END as duration_bucket,
            COUNT(*) as sessions
            FROM learning_sessions ls
            JOIN users u ON u.id = ls.user_id
            WHERE ls.tenant_id = ? {$m['where']}{$courseWhere}
            GROUP BY duration_bucket
            ORDER BY FIELD(duration_bucket, '0-5 min','6-15 min','16-30 min','31-60 min','60+ min')";

        $buckets = $this->executeQuery($bucketSql, $allTypes, $allParams);

        return [
            'summary' => [
                'total_sessions' => (int) $summary['total_sessions'],
                'unique_users' => (int) $summary['unique_users'],
                'avg_duration' => $this->formatMinutes((int) $summary['avg_duration']),
                'min_duration' => $this->formatMinutes((int) $summary['min_duration']),
                'max_duration' => $this->formatMinutes((int) $summary['max_duration']),
                'total_time' => $this->formatMinutes((int) $summary['total_minutes']),
            ],
            'distribution' => $buckets
        ];
    }

    /**
     * 4. Engagement Score Report
     * Composite engagement metrics per learner
     */
    public function engagementScore(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('ce');
        $m = $this->mergeWhereClauses($org, $course);

        $sql = "SELECT u.id as user_id,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(t.team_name, '') as team_name,
            COALESCE(b.branch_name, '') as branch_name,
            COUNT(DISTINCT ce.course_id) as enrolled_courses,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            AVG(ce.progress_percentage) as avg_progress,
            SUM(ce.time_spent_minutes) as total_time,
            DATEDIFF(NOW(), u.last_login) as days_since_login,
            (SELECT COUNT(*) FROM proc_attempts pa WHERE pa.user_id = u.id AND pa.tenant_id = u.tenant_id) as assessment_attempts
            FROM users u
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            LEFT JOIN course_enrollments ce ON ce.user_id = u.id AND ce.tenant_id = u.tenant_id AND ce.is_active = 1 {$course['where']}
            WHERE u.tenant_id = ? AND u.is_active = 1 {$org['where']}
            GROUP BY u.id, u.first_name, u.last_name, u.email, d.department_name, t.team_name, b.branch_name, u.last_login
            HAVING enrolled_courses > 0
            ORDER BY total_time DESC";

        $allTypes = 'i' . $m['types'];
        $allParams = [$this->tenant_id, ...$m['params']];
        $rows = $this->executeQuery($sql, $allTypes, $allParams);

        foreach ($rows as &$r) {
            $enrolled = max((int) $r['enrolled_courses'], 1);
            $completionScore = min(((int) $r['completed'] / $enrolled) * 30, 30);
            $progressScore = min(((float) $r['avg_progress'] / 100) * 25, 25);
            $timeScore = min(((int) $r['total_time'] / 600) * 20, 20); // 10hrs = max
            $loginScore = max(0, 15 - ((int) $r['days_since_login'] * 0.5));
            $assessScore = min(((int) $r['assessment_attempts'] / 5) * 10, 10);

            $r['engagement_score'] = round($completionScore + $progressScore + $timeScore + $loginScore + $assessScore, 1);
            $r['engagement_level'] = $r['engagement_score'] >= 70 ? 'high' : ($r['engagement_score'] >= 40 ? 'medium' : 'low');
            $r['avg_progress'] = round((float) $r['avg_progress'], 1);
            $r['total_time_formatted'] = $this->formatMinutes((int) $r['total_time']);
            $r['completion_rate'] = $this->safePercent((int) $r['completed'], $enrolled);
        }

        // Sort by engagement score
        usort($rows, fn($a, $b) => $b['engagement_score'] <=> $a['engagement_score']);

        return $rows;
    }

    /**
     * 5. Daily/Weekly Activity Report
     * Activity volume over time
     */
    public function activityReport(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('ce');
        $date = $this->buildDateRangeWhere('ce.enrolled_at');
        $m = $this->mergeWhereClauses($org, $course, $date);

        $groupBy = $this->filters['group_by'] ?? 'daily';
        $df = match ($groupBy) { 'weekly' => '%x-W%v', 'monthly' => '%Y-%m', default => '%Y-%m-%d' };
        $lf = match ($groupBy) { 'weekly' => 'W%v %Y', 'monthly' => '%b %Y', default => '%b %d' };

        $sql = "SELECT DATE_FORMAT(ce.enrolled_at, '{$df}') as period_key,
            DATE_FORMAT(ce.enrolled_at, '{$lf}') as period_label,
            COUNT(DISTINCT ce.user_id) as active_users,
            COUNT(*) as activities,
            SUM(ce.time_spent_minutes) as total_minutes
            FROM course_enrollments ce
            JOIN users u ON u.id = ce.user_id
            WHERE ce.tenant_id = ? AND ce.is_active = 1 AND ce.enrolled_at IS NOT NULL {$m['where']}
            GROUP BY period_key, period_label ORDER BY period_key ASC";

        $rows = $this->executeQuery($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        foreach ($rows as &$r) {
            $r['total_time_formatted'] = $this->formatMinutes((int) $r['total_minutes']);
        }
        return $rows;
    }

    /**
     * 6. Mobile vs Desktop Usage
     * Platform usage breakdown (requires login history)
     */
    public function platformUsage(): array
    {
        if (!$this->tableExists('user_login_history')) {
            return ['info' => 'user_login_history table required for platform usage data'];
        }

        $org = $this->buildOrgScopeWhere('u');
        $date = $this->buildDateRangeWhere('lh.login_at');
        $m = $this->mergeWhereClauses($org, $date);

        $sql = "SELECT COALESCE(lh.device_type, 'Unknown') as device_type,
            COUNT(*) as login_count,
            COUNT(DISTINCT lh.user_id) as unique_users,
            AVG(TIMESTAMPDIFF(MINUTE, lh.login_at, COALESCE(lh.logout_at, NOW()))) as avg_session_minutes
            FROM user_login_history lh
            JOIN users u ON u.id = lh.user_id
            WHERE lh.tenant_id = ? {$m['where']}
            GROUP BY lh.device_type ORDER BY login_count DESC";

        $rows = $this->executeQuery($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        $total = max(array_sum(array_column($rows, 'login_count')), 1);
        foreach ($rows as &$r) {
            $r['percentage'] = $this->safePercent((int) $r['login_count'], $total);
            $r['avg_session_formatted'] = $this->formatMinutes((int) $r['avg_session_minutes']);
        }
        return $rows;
    }

    public function getExportColumns(string $report): array
    {
        return match ($report) {
            'time_spent' => [
                ['key' => 'group_name', 'label' => 'Name'], ['key' => 'total_formatted', 'label' => 'Total Time'],
                ['key' => 'avg_formatted', 'label' => 'Avg Time'], ['key' => 'learner_count', 'label' => 'Learners'],
            ],
            'engagement_score' => [
                ['key' => 'learner_name', 'label' => 'Learner'], ['key' => 'email', 'label' => 'Email'],
                ['key' => 'department_name', 'label' => 'Department'], ['key' => 'team_name', 'label' => 'Team'],
                ['key' => 'engagement_score', 'label' => 'Score'], ['key' => 'engagement_level', 'label' => 'Level'],
                ['key' => 'completion_rate', 'label' => 'Completion %'], ['key' => 'total_time_formatted', 'label' => 'Time'],
            ],
            default => []
        };
    }
}
