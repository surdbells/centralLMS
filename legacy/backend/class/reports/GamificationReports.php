<?php
require_once __DIR__ . '/BaseReport.php';
class GamificationReports extends BaseReport{
    public function generate(): array
    {
        return $this->leaderboardReport();
    }
    public function leaderboardReport(int $limit = 50): array
    {
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, u.avatar, d.department_name,
                    COALESCE(SUM(up.points), 0) as total_points,
                    COUNT(DISTINCT ub.badge_id) as badges_earned,
                    COUNT(DISTINCT CASE WHEN ce.status = 'completed' THEN ce.course_id END) as courses_completed,
                    (SELECT COALESCE(SUM(points), 0) FROM user_points 
                     WHERE user_id = u.id AND tenant_id = ? 
                     AND earned_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)) as points_this_month
                  FROM users u
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN user_points up ON u.id = up.user_id AND up.tenant_id = ?
                  LEFT JOIN user_badges ub ON u.id = ub.user_id AND ub.tenant_id = ?
                  LEFT JOIN course_enrollments ce ON u.id = ce.user_id AND ce.tenant_id = ? AND ce.is_active = 1
                  WHERE u.tenant_id = ? AND u.is_active = 1
                  {$orgFilter}
                  GROUP BY u.id, u.first_name, u.last_name, u.email, u.avatar, d.department_name
                  ORDER BY total_points DESC
                  LIMIT ?";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiiiii", $this->tenant_id, $this->tenant_id, $this->tenant_id,
            $this->tenant_id, $this->tenant_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        $rank = 1;
        $prevPoints = null;
        $actualRank = 1;

        while ($row = $result->fetch_assoc()) {
            $points = (int)$row['total_points'];

            // Handle tied ranks
            if ($prevPoints !== null && $points < $prevPoints) {
                $actualRank = $rank;
            }
            $prevPoints = $points;

            $data[] = [
                'rank' => $actualRank,
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'avatar_url' => $row['avatar_url'],
                'department' => $row['department_name'] ?? 'N/A',
                'total_points' => $points,
                'points_this_month' => (int)$row['points_this_month'],
                'badges_earned' => (int)$row['badges_earned'],
                'courses_completed' => (int)$row['courses_completed']
            ];
            $rank++;
        }

        return [
            'report_name' => 'Leaderboard Report',
            'data' => $data,
            'meta' => ['total' => count($data), 'limit' => $limit]
        ];
    }

    /**
     * Report 51: Badge Earned Report
     */
    public function badgeEarnedReport(): array
    {
        $dateFilter = $this->buildDateFilter('ub.earned_at');

        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT ub.id, u.id as user_id, 
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name,
                    b.badge_id, b.badge_name, b.description, b.icon, b.points_value,
                    c.title as course_title,
                    ub.earned_at
                  FROM user_badges ub
                  JOIN users u ON ub.user_id = u.id
                  JOIN badges b ON ub.badge_id = b.badge_id
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN courses c ON b.course_id = c.course_id
                  WHERE ub.tenant_id = ? $dateFilter
                  {$orgFilter}
                  ORDER BY ub.earned_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'id' => (int)$row['id'],
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'badge_id' => (int)$row['badge_id'],
                'badge_name' => $row['badge_name'],
                'badge_description' => $row['description'],
                'badge_icon' => $row['icon'],
                'points_value' => (int)$row['points_value'],
                'course_title' => $row['course_title'],
                'earned_at' => $row['earned_at']
            ];
        }

        return [
            'report_name' => 'Badge Earned Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 52: Points Summary Report
     */
    public function pointsSummaryReport(): array
    {
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT u.id as user_id, CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name,
                    COALESCE(SUM(up.points), 0) as total_points,
                    COALESCE(SUM(CASE WHEN up.earned_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN up.points ELSE 0 END), 0) as points_this_month,
                    COALESCE(SUM(CASE WHEN up.earned_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN up.points ELSE 0 END), 0) as points_this_week,
                    COUNT(up.point_id) as point_transactions
                  FROM users u
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN user_points up ON u.id = up.user_id AND up.tenant_id = ?
                  WHERE u.tenant_id = ? AND u.is_active = 1
                  {$orgFilter}
                  GROUP BY u.id, u.first_name, u.last_name, u.email, d.department_name
                  HAVING total_points > 0
                  ORDER BY total_points DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->tenant_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        $rank = $this->getOffset() + 1;
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'rank' => $rank++,
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'N/A',
                'total_points' => (int)$row['total_points'],
                'points_this_month' => (int)$row['points_this_month'],
                'points_this_week' => (int)$row['points_this_week'],
                'point_transactions' => (int)$row['point_transactions']
            ];
        }

        return [
            'report_name' => 'Points Summary Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data)),
            'summary' => $this->getPointsSummary()
        ];
    }

    /**
     * Report 53: Achievement Milestones
     */
    public function achievementMilestones(): array
    {
        $dateFilter = $this->buildDateFilter('ua.achieved_at');

        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT am.milestone_id, am.name as milestone_name, am.description,
                    am.points_required, am.badge_id, b.badge_name, b.icon,
                    COUNT(ua.id) as users_achieved,
                    MIN(ua.achieved_at) as first_achieved,
                    MAX(ua.achieved_at) as last_achieved
                  FROM achievement_milestones am
                  LEFT JOIN badges b ON am.badge_id = b.badge_id
                  LEFT JOIN user_achievements ua ON am.milestone_id = ua.milestone_id $dateFilter
                  JOIN users u ON ua.user_id = u.id
                  WHERE am.tenant_id = ?
                  {$orgFilter}
                  GROUP BY am.milestone_id, am.name, am.description, am.points_required, 
                           am.badge_id, b.badge_name, b.icon
                  ORDER BY am.points_required ASC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'milestone_id' => (int)$row['milestone_id'],
                'milestone_name' => $row['milestone_name'],
                'description' => $row['description'],
                'points_required' => (int)$row['points_required'],
                'badge_name' => $row['badge_name'],
                'badge_icon' => $row['icon'],
                'users_achieved' => (int)$row['users_achieved'],
                'first_achieved' => $row['first_achieved'],
                'last_achieved' => $row['last_achieved']
            ];
        }

        return [
            'report_name' => 'Achievement Milestones',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 54: Gamification Participation
     */
    public function gamificationParticipation(): array
    {
        // Overall stats
        $orgFilter = $this->buildOrgFilter();

        $statsQuery = "SELECT 
                         COUNT(DISTINCT up.user_id) as users_with_points,
                         COALESCE(SUM(up.points), 0) as total_points_awarded,
                         COALESCE(AVG(user_totals.total), 0) as avg_points_per_user,
                         COUNT(DISTINCT ub.user_id) as users_with_badges
                       FROM user_points up
                       LEFT JOIN (
                         SELECT user_id, SUM(points) as total 
                         FROM user_points WHERE tenant_id = ? GROUP BY user_id
                       ) user_totals ON up.user_id = user_totals.user_id
                       LEFT JOIN user_badges ub ON up.tenant_id = ub.tenant_id
                       WHERE up.tenant_id = ?";

        $stmt = $this->conn->prepare($statsQuery);
        $stmt->bind_param("ii", $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();

        // Total active users
        $userQuery = "SELECT COUNT(*) as total FROM users u WHERE u.tenant_id = ? AND u.is_active = 1
                    {$orgFilter}";
        $stmt = $this->conn->prepare($userQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $totalUsers = (int)$stmt->get_result()->fetch_assoc()['total'];

        // Badge distribution
        $badgeQuery = "SELECT b.badge_name, b.icon, COUNT(ub.badge_id) as times_earned
                       FROM badges b
                       LEFT JOIN user_badges ub ON b.badge_id = ub.badge_id AND ub.tenant_id = ?
                       WHERE b.tenant_id = ?
                       GROUP BY b.badge_id, b.badge_name, b.icon
                       ORDER BY times_earned DESC
                       LIMIT 10";

        $stmt = $this->conn->prepare($badgeQuery);
        $stmt->bind_param("ii", $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $badges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Points by activity type
        $activityQuery = "SELECT activity_type, SUM(points) as total_points, COUNT(*) as transactions
                          FROM user_points WHERE tenant_id = ?
                          GROUP BY activity_type
                          ORDER BY total_points DESC";

        $stmt = $this->conn->prepare($activityQuery);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $activities = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $usersWithPoints = (int)($stats['users_with_points'] ?? 0);

        return [
            'report_name' => 'Gamification Participation',
            'summary' => [
                'total_users' => $totalUsers,
                'users_participating' => $usersWithPoints,
                'participation_rate' => $totalUsers > 0 ? round(($usersWithPoints / $totalUsers) * 100, 1) : 0,
                'total_points_awarded' => (int)($stats['total_points_awarded'] ?? 0),
                'avg_points_per_user' => round((float)($stats['avg_points_per_user'] ?? 0), 1),
                'users_with_badges' => (int)($stats['users_with_badges'] ?? 0)
            ],
            'badge_distribution' => $badges,
            'points_by_activity' => $activities
        ];
    }

    /**
     * Leaderboard by Department
     */
    public function departmentLeaderboard(): array
    {
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT d.department_id, d.department_name,
                    COUNT(DISTINCT u.id) as total_users,
                    COALESCE(SUM(up.points), 0) as total_points,
                    COALESCE(AVG(user_points.user_total), 0) as avg_points_per_user,
                    COUNT(DISTINCT ub.user_id) as users_with_badges,
                    COUNT(DISTINCT ub.badge_id) as unique_badges
                  FROM departments d
                  LEFT JOIN users u ON d.department_id = u.department AND u.tenant_id = ? AND u.is_active = 1
                  LEFT JOIN user_points up ON u.id = up.user_id AND up.tenant_id = ?
                  LEFT JOIN (
                    SELECT user_id, SUM(points) as user_total FROM user_points 
                    {$orgFilter}
                    WHERE tenant_id = ? GROUP BY user_id
                  ) user_points ON u.id = user_points.user_id
                  LEFT JOIN user_badges ub ON u.id = ub.user_id AND ub.tenant_id = ?
                  WHERE d.tenant_id = ?
                  GROUP BY d.department_id, d.department_name
                  ORDER BY total_points DESC";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiiii", $this->tenant_id, $this->tenant_id, $this->tenant_id,
            $this->tenant_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        $rank = 1;
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'rank' => $rank++,
                'department_id' => $row['department'],
                'department_name' => $row['department_name'],
                'total_users' => (int)$row['total_users'],
                'total_points' => (int)$row['total_points'],
                'avg_points_per_user' => round((float)$row['avg_points_per_user'], 1),
                'users_with_badges' => (int)$row['users_with_badges'],
                'unique_badges' => (int)$row['unique_badges']
            ];
        }

        return [
            'report_name' => 'Department Leaderboard',
            'data' => $data
        ];
    }

    private function getPointsSummary(): array
    {
        $query = "SELECT 
                    COALESCE(SUM(points), 0) as total_points,
                    COALESCE(SUM(CASE WHEN earned_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN points ELSE 0 END), 0) as points_30d,
                    COUNT(DISTINCT user_id) as users_with_points
                  FROM user_points WHERE tenant_id = ?";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Report: Streak Report — learning streak rankings with status tiers
     */
    public function streakReport(): array
    {
        $orgFilter = $this->buildOrgFilter();
        $orgJoins = $this->buildOrgJoins('u', true);
        $orgCols = $this->buildOrgSelectColumns();

        $query = "SELECT u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email {$orgCols},
                    us.current_streak, us.longest_streak,
                    us.last_activity_date,
                    CASE 
                        WHEN us.current_streak >= 30 THEN 'Legendary'
                        WHEN us.current_streak >= 14 THEN 'Champion'
                        WHEN us.current_streak >= 7 THEN 'Active'
                        WHEN us.current_streak >= 3 THEN 'Building'
                        ELSE 'Inactive'
                    END as streak_status
                  FROM user_streaks us
                  JOIN users u ON us.user_id = u.id
                  {$orgJoins}
                  WHERE us.tenant_id = ? {$orgFilter}
                  ORDER BY us.current_streak DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department_name' => $row['department_name'] ?? null,
                'team_name' => $row['team_name'] ?? null,
                'branch_name' => $row['branch_name'] ?? null,
                'current_streak' => (int)$row['current_streak'],
                'longest_streak' => (int)$row['longest_streak'],
                'last_activity_date' => $row['last_activity_date'],
                'streak_status' => $row['streak_status']
            ];
        }
        $stmt->close();

        $countQuery = "SELECT COUNT(*) as total FROM user_streaks us
                       JOIN users u ON us.user_id = u.id
                       {$orgJoins}
                       WHERE us.tenant_id = ? {$orgFilter}";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Streak Report',
            'data' => $data,
            'meta' => $this->buildMeta($total),
            'summary' => [
                'total_users' => $total,
                'avg_current_streak' => count($data) > 0 ? round(array_sum(array_column($data, 'current_streak')) / count($data), 1) : 0,
                'max_streak' => !empty($data) ? max(array_column($data, 'longest_streak')) : 0,
                'active_streakers' => count(array_filter($data, fn($r) => $r['current_streak'] >= 3))
            ]
        ];
    }

    public function getSummary(): array
    {
        return $this->getPointsSummary();
    }
}
