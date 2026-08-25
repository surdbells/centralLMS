<?php

/**
 * Gamification & Achievement Reports
 * Monitor gamification program: points, badges, leaderboards, streaks, milestones
 *
 * Supports scoping by: department, team, branch, learner
 */

require_once __DIR__ . '/BaseReport.php';

class GamificationReports extends BaseReport
{
    /**
     * 1. Leaderboard Report
     * Top learners by points/completions
     */
    public function leaderboard(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $limit = (int) ($this->filters['limit'] ?? 50);

        $sql = "SELECT u.id as user_id,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            COALESCE(t.team_name, '') as team_name,
            COALESCE(b.branch_name, '') as branch_name,
            COALESCE(up.total_points, 0) as total_points,
            COALESCE(ub_cnt.badge_count, 0) as badges_earned,
            COALESCE(ce_cnt.completed, 0) as courses_completed,
            COALESCE(ce_cnt.total_time, 0) as total_time_minutes,
            COALESCE(us.current_streak, 0) as current_streak
            FROM users u
            LEFT JOIN departments d ON d.department_id = u.department
            LEFT JOIN teams t ON t.team_id = u.team
            LEFT JOIN branches b ON b.branch_id = u.branch
            LEFT JOIN (SELECT user_id, tenant_id, SUM(points) as total_points FROM user_points GROUP BY user_id, tenant_id) up
                ON up.user_id = u.id AND up.tenant_id = u.tenant_id
            LEFT JOIN (SELECT user_id, tenant_id, COUNT(*) as badge_count FROM user_badges GROUP BY user_id, tenant_id) ub_cnt
                ON ub_cnt.user_id = u.id AND ub_cnt.tenant_id = u.tenant_id
            LEFT JOIN (SELECT user_id, tenant_id, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) as completed,
                SUM(time_spent_minutes) as total_time FROM course_enrollments WHERE is_active=1 GROUP BY user_id, tenant_id) ce_cnt
                ON ce_cnt.user_id = u.id AND ce_cnt.tenant_id = u.tenant_id
            LEFT JOIN user_streaks us ON us.user_id = u.id AND us.tenant_id = u.tenant_id
            WHERE u.tenant_id = ? AND u.is_active = 1 {$org['where']}
            ORDER BY total_points DESC, courses_completed DESC
            LIMIT {$limit}";

        $rows = $this->executeQuery($sql, 'i' . $org['types'], [$this->tenant_id, ...$org['params']]);
        $rank = 1;
        foreach ($rows as &$r) {
            $r['rank'] = $rank++;
            $r['total_points'] = (int) $r['total_points'];
            $r['badges_earned'] = (int) $r['badges_earned'];
            $r['courses_completed'] = (int) $r['courses_completed'];
            $r['total_time_formatted'] = $this->formatMinutes((int) $r['total_time_minutes']);
            $r['current_streak'] = (int) $r['current_streak'];
        }
        return $rows;
    }

    /**
     * 2. Badge Distribution Report
     * Badges earned across the organization
     */
    public function badgeDistribution(): array
    {
        $org = $this->buildOrgScopeWhere('u');

        $sql = "SELECT b.badge_id, b.badge_name, b.description, b.icon,
            COUNT(ub.id) as times_earned,
            COUNT(DISTINCT ub.user_id) as unique_earners,
            MIN(ub.earned_at) as first_earned,
            MAX(ub.earned_at) as last_earned
            FROM badges b
            LEFT JOIN user_badges ub ON ub.badge_id = b.badge_id AND ub.tenant_id = b.tenant_id
            LEFT JOIN users u ON u.id = ub.user_id
            WHERE b.tenant_id = ? {$org['where']}
            GROUP BY b.badge_id, b.badge_name, b.description, b.icon
            ORDER BY times_earned DESC";

        $rows = $this->executeQuery($sql, 'i' . $org['types'], [$this->tenant_id, ...$org['params']]);

        // Total users for percentage
        $totalUsers = $this->executeOne(
            "SELECT COUNT(*) as cnt FROM users WHERE tenant_id = ? AND is_active = 1", 'i', [$this->tenant_id]
        );
        $total = max((int) $totalUsers['cnt'], 1);

        foreach ($rows as &$r) {
            $r['times_earned'] = (int) $r['times_earned'];
            $r['unique_earners'] = (int) $r['unique_earners'];
            $r['earn_rate'] = $this->safePercent($r['unique_earners'], $total);
        }
        return $rows;
    }

    /**
     * 3. Points Summary Report
     * Points earned by activity type
     */
    public function pointsSummary(): array
    {
        $org = $this->buildOrgScopeWhere('u');
        $date = $this->buildDateRangeWhere('up.created_at');
        $m = $this->mergeWhereClauses($org, $date);

        // By activity type
        $sql = "SELECT COALESCE(up.activity_type, 'other') as activity_type,
            COUNT(*) as transactions,
            SUM(up.points) as total_points,
            COUNT(DISTINCT up.user_id) as unique_users,
            AVG(up.points) as avg_points_per_transaction
            FROM user_points up
            JOIN users u ON u.id = up.user_id
            WHERE up.tenant_id = ? {$m['where']}
            GROUP BY up.activity_type
            ORDER BY total_points DESC";

        $byType = $this->executeQuery($sql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);
        $grandTotal = 0;
        foreach ($byType as &$r) {
            $r['total_points'] = (int) $r['total_points'];
            $r['avg_points_per_transaction'] = round((float) $r['avg_points_per_transaction'], 1);
            $grandTotal += $r['total_points'];
        }
        foreach ($byType as &$r) {
            $r['percentage_of_total'] = $this->safePercent($r['total_points'], max($grandTotal, 1));
        }

        // Monthly trend
        $trendSql = "SELECT DATE_FORMAT(up.created_at, '%Y-%m') as month_key,
            DATE_FORMAT(up.created_at, '%b %Y') as label,
            SUM(up.points) as points, COUNT(DISTINCT up.user_id) as users
            FROM user_points up
            JOIN users u ON u.id = up.user_id
            WHERE up.tenant_id = ? AND up.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) {$m['where']}
            GROUP BY month_key, label ORDER BY month_key ASC";

        $trend = $this->executeQuery($trendSql, 'i' . $m['types'], [$this->tenant_id, ...$m['params']]);

        return [
            'grand_total' => $grandTotal,
            'by_activity_type' => $byType,
            'monthly_trend' => $trend
        ];
    }

    /**
     * 4. Milestone Achievement Report
     */
    public function milestoneAchievements(): array
    {
        if (!$this->tableExists('achievement_milestones') || !$this->tableExists('user_achievements')) {
            return ['info' => 'Achievement tables not available'];
        }

        $sql = "SELECT am.milestone_id, am.title, am.description, am.points_reward,
            COUNT(ua.id) as times_achieved,
            COUNT(DISTINCT ua.user_id) as unique_achievers,
            MAX(ua.achieved_at) as last_achieved
            FROM achievement_milestones am
            LEFT JOIN user_achievements ua ON ua.milestone_id = am.milestone_id AND ua.tenant_id = am.tenant_id
            WHERE am.tenant_id = ?
            GROUP BY am.milestone_id, am.title, am.description, am.points_reward
            ORDER BY times_achieved DESC";

        return $this->executeQuery($sql, 'i', [$this->tenant_id]);
    }

    /**
     * 5. Gamification Participation
     * Engagement with gamification features
     */
    public function gamificationParticipation(): array
    {
        $org = $this->buildOrgScopeWhere('u');

        // Users with any gamification activity
        $sql = "SELECT
            (SELECT COUNT(*) FROM users WHERE tenant_id = ? AND is_active = 1) as total_users,
            (SELECT COUNT(DISTINCT user_id) FROM user_points WHERE tenant_id = ?) as users_with_points,
            (SELECT COUNT(DISTINCT user_id) FROM user_badges WHERE tenant_id = ?) as users_with_badges,
            (SELECT COUNT(DISTINCT user_id) FROM user_streaks WHERE tenant_id = ? AND current_streak > 0) as users_with_streaks,
            (SELECT SUM(points) FROM user_points WHERE tenant_id = ?) as total_points_awarded,
            (SELECT COUNT(*) FROM user_badges WHERE tenant_id = ?) as total_badges_awarded,
            (SELECT AVG(current_streak) FROM user_streaks WHERE tenant_id = ? AND current_streak > 0) as avg_streak";

        $t = $this->tenant_id;
        $row = $this->executeOne($sql, 'iiiiiii', [$t, $t, $t, $t, $t, $t, $t]);

        $totalUsers = max((int) $row['total_users'], 1);

        return [
            'total_users' => (int) $row['total_users'],
            'users_with_points' => (int) $row['users_with_points'],
            'users_with_badges' => (int) $row['users_with_badges'],
            'users_with_streaks' => (int) $row['users_with_streaks'],
            'points_participation_rate' => $this->safePercent((int) $row['users_with_points'], $totalUsers),
            'badge_participation_rate' => $this->safePercent((int) $row['users_with_badges'], $totalUsers),
            'total_points_awarded' => (int) $row['total_points_awarded'],
            'total_badges_awarded' => (int) $row['total_badges_awarded'],
            'avg_streak' => round((float) $row['avg_streak'], 1),
        ];
    }

    /**
     * 6. Streak Report
     * Learning streak statistics
     */
    public function streakReport(): array
    {
        $org = $this->buildOrgScopeWhere('u');

        $sql = "SELECT u.id as user_id,
            CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email,
            COALESCE(d.department_name, '') as department_name,
            us.current_streak, us.longest_streak, us.last_activity_date
            FROM user_streaks us
            JOIN users u ON u.id = us.user_id
            LEFT JOIN departments d ON d.department_id = u.department
            WHERE us.tenant_id = ? AND u.is_active = 1 AND us.current_streak > 0 {$org['where']}
            ORDER BY us.current_streak DESC LIMIT 50";

        $rows = $this->executeQuery($sql, 'i' . $org['types'], [$this->tenant_id, ...$org['params']]);
        foreach ($rows as &$r) {
            $r['current_streak'] = (int) $r['current_streak'];
            $r['longest_streak'] = (int) $r['longest_streak'];
        }
        return $rows;
    }

    public function getExportColumns(string $report): array
    {
        return match ($report) {
            'leaderboard' => [
                ['key' => 'rank', 'label' => 'Rank'], ['key' => 'learner_name', 'label' => 'Learner'],
                ['key' => 'email', 'label' => 'Email'], ['key' => 'department_name', 'label' => 'Department'],
                ['key' => 'total_points', 'label' => 'Points'], ['key' => 'badges_earned', 'label' => 'Badges'],
                ['key' => 'courses_completed', 'label' => 'Completed'], ['key' => 'current_streak', 'label' => 'Streak'],
            ],
            default => []
        };
    }
}
