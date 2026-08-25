<?php

/**
 * User & Department Reports
 * Analyze user activity, login patterns, departmental training metrics, workforce coverage
 *
 * Supports scoping by: course, department, team, branch, learner
 */

require_once __DIR__ . '/BaseReport.php';

class UserReports extends BaseReport
{
    /**
     * 1. Active Users Report
     * Users actively using the LMS — last login, activity level
     */
    public function activeUsers(): array
    {
        $org = $this->buildOrgScopeWhere('u');

        $daysThreshold = (int) ($this->filters['active_days'] ?? 30);

        $baseSql = "SELECT u.id as user_id,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(t.team_name, '') as team_name,
            COALESCE(b.branch_name, '') as branch_name,
            u.last_login,
            DATEDIFF(NOW(), u.last_login) as days_since_login,
            (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.user_id = u.id AND ce.tenant_id = u.tenant_id AND ce.is_active = 1 AND ce.status = 'in_progress') as in_progress_courses,
            (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.user_id = u.id AND ce.tenant_id = u.tenant_id AND ce.is_active = 1 AND ce.status = 'completed') as completed_courses,
            (SELECT COALESCE(SUM(ce.time_spent_minutes), 0) FROM course_enrollments ce WHERE ce.user_id = u.id AND ce.tenant_id = u.tenant_id AND ce.is_active = 1) as total_time_minutes
            FROM users u
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            WHERE u.tenant_id = ? AND u.is_active = 1
            AND u.last_login IS NOT NULL AND u.last_login >= DATE_SUB(NOW(), INTERVAL ? DAY)
            {$org['where']}";

        $allTypes = 'ii' . $org['types'];
        $allParams = [$this->tenant_id, $daysThreshold, ...$org['params']];
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY u.last_login DESC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        foreach ($data as &$row) {
            $row['days_since_login'] = (int) $row['days_since_login'];
            $row['total_time_formatted'] = $this->formatMinutes((int) $row['total_time_minutes']);
            $row['activity_level'] = $row['days_since_login'] <= 3 ? 'high' : ($row['days_since_login'] <= 14 ? 'medium' : 'low');
        }

        return $this->paginatedResponse($data, $total);
    }

    /**
     * 2. Inactive Users Report
     * Users with no recent activity
     */
    public function inactiveUsers(): array
    {
        $org = $this->buildOrgScopeWhere('u');

        $inactiveDays = (int) ($this->filters['inactive_days'] ?? 30);

        $baseSql = "SELECT u.id as user_id,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(t.team_name, '') as team_name,
            COALESCE(b.branch_name, '') as branch_name,
            u.last_login,
            DATEDIFF(NOW(), COALESCE(u.last_login, u.created_at)) as days_inactive,
            (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.user_id = u.id AND ce.tenant_id = u.tenant_id AND ce.is_active = 1 AND ce.status != 'completed') as pending_courses,
            (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.user_id = u.id AND ce.tenant_id = u.tenant_id AND ce.is_active = 1 AND ce.is_mandatory = 1 AND ce.status != 'completed') as pending_mandatory
            FROM users u
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            WHERE u.tenant_id = ? AND u.is_active = 1
            AND (u.last_login IS NULL OR u.last_login < DATE_SUB(NOW(), INTERVAL ? DAY))
            {$org['where']}";

        $allTypes = 'ii' . $org['types'];
        $allParams = [$this->tenant_id, $inactiveDays, ...$org['params']];
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY days_inactive DESC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        foreach ($data as &$row) {
            $row['days_inactive'] = (int) $row['days_inactive'];
            $row['pending_courses'] = (int) $row['pending_courses'];
            $row['pending_mandatory'] = (int) $row['pending_mandatory'];
            $row['risk_level'] = $row['pending_mandatory'] > 0 ? 'high' : ($row['pending_courses'] > 0 ? 'medium' : 'low');
        }

        return $this->paginatedResponse($data, $total);
    }

    /**
     * 3. New Users Report
     * Recently added users with onboarding status
     */
    public function newUsers(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $date = $this->buildDateRangeWhere('u.created_at');
        $m = $this->mergeWhereClauses($org, $date);

        $baseSql = "SELECT u.id as user_id,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(t.team_name, '') as team_name,
            COALESCE(b.branch_name, '') as branch_name,
            u.created_at as join_date,
            u.last_login,
            DATEDIFF(NOW(), u.created_at) as days_since_joined,
            (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.user_id = u.id AND ce.tenant_id = u.tenant_id AND ce.is_active = 1) as enrolled_courses,
            (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.user_id = u.id AND ce.tenant_id = u.tenant_id AND ce.is_active = 1 AND ce.status = 'completed') as completed_courses,
            CASE WHEN u.last_login IS NOT NULL THEN 1 ELSE 0 END as has_logged_in
            FROM users u
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            WHERE u.tenant_id = ? AND u.is_active = 1 {$m['where']}";

        $allTypes = 'i' . $m['types'];
        $allParams = [$this->tenant_id, ...$m['params']];
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY u.created_at DESC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        foreach ($data as &$row) {
            $row['has_logged_in'] = (bool) $row['has_logged_in'];
            $row['onboarding_status'] = $row['completed_courses'] > 0 ? 'active' : ($row['has_logged_in'] ? 'logged_in' : 'never_logged_in');
        }

        return $this->paginatedResponse($data, $total);
    }

    /**
     * 4. User Activity Summary
     * Complete LMS activity profile for a specific user
     */
    public function userActivitySummary(): array
    {
        $lid = $this->learner_id;
        if (!$lid) return ['error' => 'learner_id required'];

        // User info
        $learner = $this->executeOne(
            "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) as name, u.email, u.created_at, u.last_login,
                COALESCE(d.department_name, '') as department, COALESCE(t.team_name, '') as team, COALESCE(b.branch_name, '') as branch
            FROM users u LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team LEFT JOIN branches b ON b.branch_id = u.branch
            WHERE u.id = ? AND u.tenant_id = ?", 'ii', [$lid, $this->tenant_id]
        );

        // Course enrollments summary
        $enrollSql = "SELECT status, COUNT(*) as count, AVG(progress_percentage) as avg_progress,
            SUM(time_spent_minutes) as total_time
            FROM course_enrollments WHERE user_id = ? AND tenant_id = ? AND is_active = 1
            GROUP BY status";
        $enrollData = $this->executeQuery($enrollSql, 'ii', [$lid, $this->tenant_id]);

        $enrollments = ['not_started' => 0, 'in_progress' => 0, 'completed' => 0, 'dropped' => 0, 'total_time' => 0];
        foreach ($enrollData as $e) {
            $enrollments[$e['status']] = (int) $e['count'];
            $enrollments['total_time'] += (int) $e['total_time'];
        }
        $enrollments['total'] = array_sum([$enrollments['not_started'], $enrollments['in_progress'], $enrollments['completed'], $enrollments['dropped']]);
        $enrollments['total_time_formatted'] = $this->formatMinutes($enrollments['total_time']);

        // Assessment summary
        $assessSql = "SELECT COUNT(*) as attempts, SUM(CASE WHEN passed = 1 THEN 1 ELSE 0 END) as passed,
            AVG(CASE WHEN max_score > 0 THEN (score/max_score)*100 ELSE NULL END) as avg_score
            FROM proc_attempts WHERE user_id = ? AND tenant_id = ?";
        $assess = $this->executeOne($assessSql, 'ii', [$lid, $this->tenant_id]) ?? [];

        // Certificates
        $certCount = $this->executeOne(
            "SELECT COUNT(*) as cnt FROM certificates WHERE user_id = ? AND tenant_id = ?",
            'ii', [$lid, $this->tenant_id]
        );

        // Points
        $points = $this->executeOne(
            "SELECT COALESCE(SUM(points), 0) as total FROM user_points WHERE user_id = ? AND tenant_id = ?",
            'ii', [$lid, $this->tenant_id]
        );

        // Recent activity (last 10 enrollments)
        $recentSql = "SELECT ce.course_id, c.title, ce.status, ce.progress_percentage, ce.final_score,
            ce.enrolled_at, ce.completed_at, ce.time_spent_minutes
            FROM course_enrollments ce JOIN courses c ON c.course_id = ce.course_id
            WHERE ce.user_id = ? AND ce.tenant_id = ? AND ce.is_active = 1
            ORDER BY COALESCE(ce.completed_at, ce.enrolled_at) DESC LIMIT 10";
        $recent = $this->executeQuery($recentSql, 'ii', [$lid, $this->tenant_id]);

        // Login history (last 20 if table exists)
        $logins = [];
        if ($this->tableExists('user_login_history')) {
            $logins = $this->executeQuery(
                "SELECT login_at, logout_at, ip_address, device_type, browser
                FROM user_login_history WHERE user_id = ? AND tenant_id = ? ORDER BY login_at DESC LIMIT 20",
                'ii', [$lid, $this->tenant_id]
            );
        }

        return [
            'learner' => $learner,
            'enrollments' => $enrollments,
            'assessments' => [
                'total_attempts' => (int) ($assess['attempts'] ?? 0),
                'passed' => (int) ($assess['passed'] ?? 0),
                'avg_score' => round((float) ($assess['avg_score'] ?? 0), 1),
            ],
            'certificates' => (int) ($certCount['cnt'] ?? 0),
            'points' => (int) ($points['total'] ?? 0),
            'recent_activity' => $recent,
            'login_history' => $logins
        ];
    }

    /**
     * 5. Department Training Summary
     * Training overview by department — the main department report
     */
    public function departmentTrainingSummary(): array
    {
        $course = $this->buildCourseScopeWhere('ce');
        $date = $this->buildDateRangeWhere('ce.enrolled_at');
        $m = $this->mergeWhereClauses($course, $date);

        $sql = "SELECT d.department_id, COALESCE(d.department_name, 'Unassigned') as department_name,
            COUNT(DISTINCT u.id) as total_users,
            COUNT(DISTINCT ce.enrollment_id) as total_enrollments,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
            SUM(CASE WHEN ce.due_date < CURDATE() AND ce.status != 'completed' THEN 1 ELSE 0 END) as overdue,
            SUM(CASE WHEN ce.is_mandatory = 1 THEN 1 ELSE 0 END) as mandatory_enrollments,
            SUM(CASE WHEN ce.is_mandatory = 1 AND ce.status = 'completed' THEN 1 ELSE 0 END) as mandatory_completed,
            AVG(ce.progress_percentage) as avg_progress,
            AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score ELSE NULL END) as avg_score,
            SUM(ce.time_spent_minutes) as total_time_minutes,
            AVG(ce.time_spent_minutes) as avg_time_minutes
            FROM users u
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN course_enrollments ce ON ce.user_id = u.id AND ce.tenant_id = u.tenant_id AND ce.is_active = 1 {$m['where']}
            WHERE u.tenant_id = ? AND u.is_active = 1
            GROUP BY d.department_id, d.department_name
            ORDER BY total_enrollments DESC";

        $rows = $this->executeQuery($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        foreach ($rows as &$r) {
            $r['completion_rate'] = $this->safePercent((int) $r['completed'], max((int) $r['total_enrollments'], 1));
            $mandTotal = max((int) $r['mandatory_enrollments'], 1);
            $r['mandatory_compliance_rate'] = $this->safePercent((int) $r['mandatory_completed'], $mandTotal);
            $r['avg_progress'] = round((float) $r['avg_progress'], 1);
            $r['avg_score'] = round((float) $r['avg_score'], 1);
            $r['total_time_formatted'] = $this->formatMinutes((int) $r['total_time_minutes']);
            $r['avg_time_formatted'] = $this->formatMinutes((int) $r['avg_time_minutes']);
        }
        return $rows;
    }

    /**
     * 6. Manager's Team Report
     * Training status of a manager's direct reports
     */
    public function managersTeamReport(): array
    {
        // Use dept/team/branch scope to represent a manager's team
        $org = $this->buildOrgScopeWhere('u');
        $course = $this->buildCourseScopeWhere('ce');
        $m = $this->mergeWhereClauses($org, $course);

        if (!$this->department_id && !$this->team_id && !$this->branch_id) {
            return ['error' => 'department_id, team_id, or branch_id required to identify team'];
        }

        $sql = "SELECT u.id as user_id,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(t.team_name, '') as team_name,
            COUNT(ce.enrollment_id) as total_assigned,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
            SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
            SUM(CASE WHEN ce.due_date < CURDATE() AND ce.status != 'completed' THEN 1 ELSE 0 END) as overdue,
            AVG(ce.progress_percentage) as avg_progress,
            AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score ELSE NULL END) as avg_score,
            SUM(ce.time_spent_minutes) as total_time_minutes
            FROM users u
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN course_enrollments ce ON ce.user_id = u.id AND ce.tenant_id = u.tenant_id AND ce.is_active = 1 {$course['where']}
            WHERE u.tenant_id = ? AND u.is_active = 1 {$org['where']}
            GROUP BY u.id, u.first_name, u.last_name, u.email, d.department_name, t.team_name
            ORDER BY overdue DESC, completed ASC";

        $allTypes = 'i' . $m['types'];
        $allParams = [$this->tenant_id, ...$m['params']];
        $rows = $this->executeQuery($sql, $allTypes, $allParams);

        foreach ($rows as &$r) {
            $r['completion_rate'] = $this->safePercent((int) $r['completed'], max((int) $r['total_assigned'], 1));
            $r['avg_progress'] = round((float) $r['avg_progress'], 1);
            $r['avg_score'] = round((float) $r['avg_score'], 1);
            $r['total_time_formatted'] = $this->formatMinutes((int) $r['total_time_minutes']);
            $r['status_flag'] = $r['overdue'] > 0 ? 'needs_attention' : ($r['not_started'] > 0 ? 'pending' : 'on_track');
        }
        return $rows;
    }

    /**
     * 7. User Login History (paginated)
     * Login patterns and frequency
     */
    public function userLoginHistory(): array
    {
        if (!$this->tableExists('user_login_history')) {
            return $this->paginatedResponse([], 0);
        }

        $org = $this->buildOrgScopeWhere('u');
        $date = $this->buildDateRangeWhere('lh.login_at');
        $m = $this->mergeWhereClauses($org, $date);

        $baseSql = "SELECT lh.id, lh.user_id,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            lh.login_at, lh.logout_at, lh.ip_address, lh.device_type, lh.browser,
            TIMESTAMPDIFF(MINUTE, lh.login_at, COALESCE(lh.logout_at, NOW())) as session_minutes
            FROM user_login_history lh
            JOIN users u ON u.id = lh.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            WHERE lh.tenant_id = ? {$m['where']}";

        $allTypes = 'i' . $m['types'];
        $allParams = [$this->tenant_id, ...$m['params']];
        $total = $this->getCount($baseSql, $allTypes, $allParams);
        $sql = $baseSql . " ORDER BY lh.login_at DESC" . $this->getLimitClause();
        $data = $this->executeQuery($sql, $allTypes, $allParams);

        foreach ($data as &$row) {
            $row['session_formatted'] = $this->formatMinutes((int) $row['session_minutes']);
        }

        return $this->paginatedResponse($data, $total);
    }

    /**
     * 8. Role-based Training Report
     * Training completion by job role/position
     */
    public function roleBasedTraining(): array
    {
        $course = $this->buildCourseScopeWhere('ce');

        // Group by department as proxy for role (your schema uses department for organizational grouping)
        $sql = "SELECT COALESCE(d.department_name, 'Unassigned') as role_group,
            COUNT(DISTINCT u.id) as total_users,
            COUNT(ce.enrollment_id) as total_assigned,
            SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN ce.is_mandatory = 1 THEN 1 ELSE 0 END) as mandatory_total,
            SUM(CASE WHEN ce.is_mandatory = 1 AND ce.status = 'completed' THEN 1 ELSE 0 END) as mandatory_completed,
            AVG(ce.progress_percentage) as avg_progress,
            AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score ELSE NULL END) as avg_score
            FROM users u
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN course_enrollments ce ON ce.user_id = u.id AND ce.tenant_id = u.tenant_id AND ce.is_active = 1 {$course['where']}
            WHERE u.tenant_id = ? AND u.is_active = 1
            GROUP BY d.department_id, d.department_name
            ORDER BY total_assigned DESC";

        $rows = $this->executeQuery($sql, 'i' . $course['types'], [$this->tenant_id, ...$course['params']]);
        foreach ($rows as &$r) {
            $r['completion_rate'] = $this->safePercent((int) $r['completed'], max((int) $r['total_assigned'], 1));
            $r['mandatory_compliance'] = $this->safePercent((int) $r['mandatory_completed'], max((int) $r['mandatory_total'], 1));
            $r['avg_progress'] = round((float) $r['avg_progress'], 1);
            $r['avg_score'] = round((float) $r['avg_score'], 1);
        }
        return $rows;
    }

    public function getExportColumns(string $report): array
    {
        return match ($report) {
            'active_users', 'inactive_users', 'new_users' => [
                ['key' => 'learner_name', 'label' => 'Learner'], ['key' => 'email', 'label' => 'Email'],
                ['key' => 'department_name', 'label' => 'Department'], ['key' => 'team_name', 'label' => 'Team'],
                ['key' => 'branch_name', 'label' => 'Branch'],
            ],
            'managers_team_report' => [
                ['key' => 'learner_name', 'label' => 'Learner'], ['key' => 'email', 'label' => 'Email'],
                ['key' => 'department_name', 'label' => 'Department'], ['key' => 'team_name', 'label' => 'Team'],
                ['key' => 'total_assigned', 'label' => 'Assigned'], ['key' => 'completed', 'label' => 'Completed'],
                ['key' => 'in_progress', 'label' => 'In Progress'], ['key' => 'overdue', 'label' => 'Overdue'],
                ['key' => 'completion_rate', 'label' => 'Completion %'], ['key' => 'avg_score', 'label' => 'Avg Score'],
            ],
            default => []
        };
    }
}
