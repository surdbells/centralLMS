<?php

/**
 * Gamification - Manages points, badges, achievements, and streaks
 * Used for gamification reports and learner engagement
 */
class Gamification {
    private mysqli $conn;
    private int $tenant_id;

    // Point values for different activities
    private array $pointValues = [
        'course_start' => 10,
        'lesson_complete' => 20,
        'course_complete' => 100,
        'assessment_pass' => 50,
        'assessment_perfect' => 100,
        'certificate_earned' => 75,
        'streak_7_days' => 50,
        'streak_30_days' => 200,
        'first_course' => 50,
        'daily_login' => 5
    ];

    public function __construct(mysqli $db) {
        $this->conn = $db;
    }

    public function setTenant(int $tenant_id): self {
        $this->tenant_id = $tenant_id;
        return $this;
    }

    /**
     * Set custom point values
     */
    public function setPointValues(array $values): self {
        $this->pointValues = array_merge($this->pointValues, $values);
        return $this;
    }

    // ==========================================
    // POINTS MANAGEMENT
    // ==========================================

    /**
     * Award points to user
     */
    public function awardPoints(
        int $user_id, 
        string $activity_type, 
        ?int $points = null,
        ?string $source_type = null,
        ?int $source_id = null,
        ?string $description = null
    ): array {
        $points = $points ?? ($this->pointValues[$activity_type] ?? 10);
        
        $query = "INSERT INTO user_points 
                  (tenant_id, user_id, points, activity_type, entity_type, entity_id, description, earned_at)
                  VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiissis", 
            $this->tenant_id,
            $user_id,
            $points,
            $activity_type,
            $source_type,
            $source_id,
            $description
        );

        if ($stmt->execute()) {
            // Check for milestone achievements
            $this->checkMilestones($user_id);
            
            return [
                'success' => true,
                'points_awarded' => $points,
                'total_points' => $this->getUserTotalPoints($user_id)
            ];
        }

        return ['success' => false, 'message' => 'Failed to award points'];
    }

    /**
     * Get user's total points
     */
    public function getUserTotalPoints(int $user_id): int {
        $query = "SELECT COALESCE(SUM(points), 0) as total 
                  FROM user_points 
                  WHERE user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return (int) $row['total'];
    }

    /**
     * Get user's points for a period
     */
    public function getUserPointsForPeriod(int $user_id, string $period = '30d'): int {
        $date = match($period) {
            '7d' => date('Y-m-d', strtotime('-7 days')),
            '30d' => date('Y-m-d', strtotime('-30 days')),
            'month' => date('Y-m-01'),
            'year' => date('Y-01-01'),
            default => date('Y-m-d', strtotime('-30 days'))
        };

        $query = "SELECT COALESCE(SUM(points), 0) as total 
                  FROM user_points 
                  WHERE user_id = ? AND tenant_id = ? AND earned_at >= ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iis", $user_id, $this->tenant_id, $date);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return (int) $row['total'];
    }

    /**
     * Get points breakdown by activity
     */
    public function getPointsBreakdown(int $user_id): array {
        $query = "SELECT activity_type, SUM(points) as total_points, COUNT(*) as occurrences
                  FROM user_points 
                  WHERE user_id = ? AND tenant_id = ?
                  GROUP BY activity_type
                  ORDER BY total_points DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // ==========================================
    // BADGE MANAGEMENT
    // ==========================================

    /**
     * Create a new badge
     */
    public function createBadge(array $data): array {
        $query = "INSERT INTO badges 
                  (tenant_id, badge_name, description, icon, image_url, points_value, badge_type, course_id, criteria, is_active)
                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)";
        
        $stmt = $this->conn->prepare($query);
        $criteria = json_encode($data['criteria'] ?? []);
        
        $stmt->bind_param("issssisis",
            $this->tenant_id,
            $data['badge_name'],
            $data['description'],
            $data['icon'],
            $data['image_url'],
            $data['points_value'],
            $data['badge_type'],
            $data['course_id'],
            $criteria
        );

        if ($stmt->execute()) {
            return ['success' => true, 'badge_id' => $this->conn->insert_id];
        }

        return ['success' => false, 'message' => 'Failed to create badge'];
    }

    /**
     * Award badge to user
     */
    public function awardBadge(int $user_id, int $badge_id, ?int $awarded_by = null, ?string $notes = null): array {
        // Check if already has badge
        $query = "SELECT badge_id FROM user_badges WHERE user_id = ? AND badge_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $badge_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            return ['success' => false, 'message' => 'User already has this badge'];
        }

        // Get badge info for points
        $badge = $this->getBadge($badge_id);
        if (!$badge) {
            return ['success' => false, 'message' => 'Badge not found'];
        }

        // Award badge
        $query = "INSERT INTO user_badges (tenant_id, user_id, badge_id, earned_at, awarded_by)
                  VALUES (?, ?, ?, NOW(), ?)";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiii", $this->tenant_id, $user_id, $badge_id, $awarded_by);

        if ($stmt->execute()) {
            // Award points for badge
            if ($badge['points_value'] > 0) {
                $this->awardPoints(
                    $user_id, 
                    'badge_earned', 
                    $badge['points_value'],
                    'badge',
                    $badge_id,
                    "Earned badge: " . $badge['badge_name']
                );
            }

            return [
                'success' => true,
                'badge' => $badge,
                'points_awarded' => $badge['points_value']
            ];
        }

        return ['success' => false, 'message' => 'Failed to award badge'];
    }

    /**
     * Get badge details
     */
    public function getBadge(int $badge_id): ?array {
        $query = "SELECT * FROM badges WHERE badge_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $badge_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    /**
     * Get all badges for tenant
     */
    public function getAllBadges(bool $active_only = true): array {
        $query = "SELECT * FROM badges WHERE tenant_id = ?";
        if ($active_only) {
            $query .= " AND is_active = 1";
        }
        $query .= " ORDER BY badge_type, badge_name";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get user's badges
     */
    public function getUserBadges(int $user_id): array {
        $query = "SELECT b.*, ub.earned_at
                  FROM user_badges ub
                  JOIN badges b ON ub.badge_id = b.badge_id
                  WHERE ub.user_id = ? AND ub.tenant_id = ?
                  ORDER BY ub.earned_at DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Check and award course-specific badges
     */
    public function checkCourseBadges(int $user_id, int $course_id, ?float $score = null): void {
        $query = "SELECT * FROM badges 
                  WHERE tenant_id = ? AND course_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->tenant_id, $course_id);
        $stmt->execute();
        $badges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($badges as $badge) {
            $criteria = json_decode($badge['criteria'], true) ?? [];
            $shouldAward = true;

            if (isset($criteria['min_score']) && $score !== null) {
                $shouldAward = $score >= $criteria['min_score'];
            }

            if ($shouldAward) {
                $this->awardBadge($user_id, $badge['badge_id']);
            }
        }
    }

    // ==========================================
    // ACHIEVEMENT MILESTONES
    // ==========================================

    /**
     * Check and award milestone achievements
     */
    public function checkMilestones(int $user_id): array {
        $totalPoints = $this->getUserTotalPoints($user_id);
        $awarded = [];

        // Get milestones not yet achieved
        $query = "SELECT m.* FROM achievement_milestones m
                  LEFT JOIN user_achievements ua ON m.milestone_id = ua.milestone_id AND ua.user_id = ?
                  WHERE m.tenant_id = ? AND m.is_active = 1 AND ua.id IS NULL
                  AND m.points_required <= ?
                  ORDER BY m.points_required";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $user_id, $this->tenant_id, $totalPoints);
        $stmt->execute();
        $milestones = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($milestones as $milestone) {
            // Award milestone
            $query = "INSERT INTO user_achievements (tenant_id, user_id, milestone_id, achieved_at)
                      VALUES (?, ?, ?, NOW())";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iii", $this->tenant_id, $user_id, $milestone['milestone_id']);
            
            if ($stmt->execute()) {
                $awarded[] = $milestone;
                
                // Award associated badge if any
                if ($milestone['badge_id']) {
                    $this->awardBadge($user_id, $milestone['badge_id']);
                }
            }
        }

        return $awarded;
    }

    /**
     * Get user's achieved milestones
     */
    public function getUserMilestones(int $user_id): array {
        $query = "SELECT m.*, ua.achieved_at
                  FROM user_achievements ua
                  JOIN achievement_milestones m ON ua.milestone_id = m.milestone_id
                  WHERE ua.user_id = ? AND ua.tenant_id = ?
                  ORDER BY m.points_required";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // ==========================================
    // STREAK MANAGEMENT
    // ==========================================

    /**
     * Get user's streak info
     */
    public function getUserStreak(int $user_id): array {
        $query = "SELECT current_streak, longest_streak, last_activity_date
                  FROM user_streaks 
                  WHERE user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            return $row;
        }

        return [
            'current_streak' => 0,
            'longest_streak' => 0,
            'last_activity_date' => null
        ];
    }

    // ==========================================
    // LEADERBOARD
    // ==========================================

    /**
     * Get leaderboard
     */
    public function getLeaderboard(int $limit = 50, ?string $department = null): array {
        $query = "SELECT u.id as user_id, 
                         CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                         u.email, u.avatar, u.department,
                         COALESCE(SUM(up.points), 0) as total_points,
                         (SELECT COUNT(*) FROM user_badges ub WHERE ub.user_id = u.id) as badges_count
                  FROM users u
                  LEFT JOIN user_points up ON u.id = up.user_id
                  WHERE u.tenant_id = ? AND u.is_active = 1";
        
        $params = [$this->tenant_id];
        $types = "i";

        if ($department) {
            $query .= " AND u.department = ?";
            $params[] = $department;
            $types .= "s";
        }

        $query .= " GROUP BY u.id
                    ORDER BY total_points DESC
                    LIMIT ?";
        $params[] = $limit;
        $types .= "i";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Add rank
        $rank = 1;
        foreach ($results as &$row) {
            $row['rank'] = $rank++;
        }

        return $results;
    }

    /**
     * Get user's leaderboard position
     */
    public function getUserRank(int $user_id): array {
        $totalPoints = $this->getUserTotalPoints($user_id);

        // Overall rank
        $query = "SELECT COUNT(*) + 1 as user_rank FROM (
                    SELECT user_id, SUM(points) as total
                    FROM user_points
                    WHERE tenant_id = ?
                    GROUP BY user_id
                    HAVING total > ?
                  ) as higher_scores";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->tenant_id, $totalPoints);
        $stmt->execute();
        $result = $stmt->get_result();
        $overallRank = $result->fetch_assoc()['rank'];

        // Department rank
        $query = "SELECT u.department FROM users u WHERE u.id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $userDept = $stmt->get_result()->fetch_assoc()['department'] ?? '';

        $query = "SELECT COUNT(*) + 1 as user_rank FROM (
                    SELECT up.user_id, SUM(up.points) as total
                    FROM user_points up
                    JOIN users u ON up.user_id = u.id
                    WHERE up.tenant_id = ? AND u.department = ?
                    GROUP BY up.user_id
                    HAVING total > ?
                  ) as higher_scores";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("isi", $this->tenant_id, $userDept, $totalPoints);
        $stmt->execute();
        $deptRank = $stmt->get_result()->fetch_assoc()['rank'];

        return [
            'user_id' => $user_id,
            'total_points' => $totalPoints,
            'overall_rank' => $overallRank,
            'department' => $userDept,
            'department_rank' => $deptRank
        ];
    }
}
