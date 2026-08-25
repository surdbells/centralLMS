<?php

/**
 * LearningSession - Tracks user learning time and sessions
 * Used for time tracking reports
 */
class LearningSession {
    private mysqli $conn;
    private int $tenant_id;

    public function __construct(mysqli $db) {
        $this->conn = $db;
    }

    public function setTenant(int $tenant_id): self {
        $this->tenant_id = $tenant_id;
        return $this;
    }

    /**
     * Start a new learning session
     */
    public function startSession(int $user_id, ?int $course_id = null, ?int $lesson_id = null): array {
        // End any existing active session first
        $this->endActiveSession($user_id);

        $query = "INSERT INTO learning_sessions 
                  (tenant_id, user_id, course_id, lesson_id, started_at, device_type, browser, ip_address, is_active)
                  VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, 1)";
        
        $stmt = $this->conn->prepare($query);
        $device_type = $this->detectDeviceType();
        $browser = $this->detectBrowser();
        $ip_address = $this->getClientIP();
        
        $stmt->bind_param("iiiisss", 
            $this->tenant_id, 
            $user_id,
            $course_id, 
            $lesson_id,
            $device_type,
            $browser,
            $ip_address
        );

        if ($stmt->execute()) {
            $session_id = $this->conn->insert_id;
            
            // Update user streak
            $this->updateUserStreak($user_id);
            
            return [
                'success' => true,
                'session_id' => $session_id,
                'started_at' => date('Y-m-d H:i:s')
            ];
        }

        return ['success' => false, 'message' => 'Failed to start session'];
    }

    /**
     * End a learning session
     */
    public function endSession(int $session_id, int $user_id): array {
        $query = "UPDATE learning_sessions 
                  SET ended_at = NOW(),
                      time_spent_minutes = TIMESTAMPDIFF(MINUTE, started_at, NOW()),
                      is_active = 0
                  WHERE session_id = ? AND user_id = ? AND tenant_id = ? AND is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $session_id, $user_id, $this->tenant_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            // Get duration for response
            $duration = $this->getSessionDuration($session_id);
            
            return [
                'success' => true,
                'session_id' => $session_id,
                'duration_minutes' => $duration
            ];
        }

        return ['success' => false, 'message' => 'Session not found or already ended'];
    }

    /**
     * End any active session for user
     */
    public function endActiveSession(int $user_id): bool {
        $query = "UPDATE learning_sessions 
                  SET ended_at = NOW(),
                      time_spent_minutes = TIMESTAMPDIFF(MINUTE, started_at, NOW()),
                      is_active = 0
                  WHERE user_id = ? AND tenant_id = ? AND is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        return $stmt->execute();
    }

    /**
     * Update current session with new lesson/course
     */
    public function updateSession(int $session_id, int $user_id, ?int $course_id, ?int $lesson_id): array {
        $query = "UPDATE learning_sessions 
                  SET course_id = COALESCE(?, course_id),
                      lesson_id = COALESCE(?, lesson_id)
                  WHERE session_id = ? AND user_id = ? AND tenant_id = ? AND is_active = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiiii", $course_id, $lesson_id, $session_id, $user_id, $this->tenant_id);

        if ($stmt->execute()) {
            return ['success' => true];
        }

        return ['success' => false, 'message' => 'Failed to update session'];
    }

    /**
     * Heartbeat to keep session alive and track time
     */
    public function heartbeat(int $session_id, int $user_id): array {
        // Verify session exists and is active
        $query = "SELECT session_id, started_at FROM learning_sessions 
                  WHERE session_id = ? AND user_id = ? AND tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $session_id, $user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            return ['success' => false, 'message' => 'Session not found or inactive'];
        }

        // Update duration
        $query = "UPDATE learning_sessions 
                  SET time_spent_minutes = TIMESTAMPDIFF(MINUTE, started_at, NOW())
                  WHERE session_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $session_id);
        $stmt->execute();

        return [
            'success' => true,
            'session_id' => $session_id,
            'duration_minutes' => $this->getSessionDuration($session_id)
        ];
    }

    /**
     * Get active session for user
     */
    public function getActiveSession(int $user_id): ?array {
        $query = "SELECT session_id, course_id, lesson_id, started_at,
                         TIMESTAMPDIFF(MINUTE, started_at, NOW()) as duration_minutes
                  FROM learning_sessions 
                  WHERE user_id = ? AND tenant_id = ? AND is_active = 1
                  ORDER BY started_at DESC LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            return $row;
        }

        return null;
    }

    /**
     * Get user's total learning time
     */
    public function getUserTotalTime(int $user_id, ?string $date_from = null, ?string $date_to = null): int {
        $query = "SELECT COALESCE(SUM(time_spent_minutes), 0) as total_minutes
                  FROM learning_sessions 
                  WHERE user_id = ? AND tenant_id = ?";
        $params = [$user_id, $this->tenant_id];
        $types = "ii";

        if ($date_from) {
            $query .= " AND started_at >= ?";
            $params[] = $date_from;
            $types .= "s";
        }
        if ($date_to) {
            $query .= " AND started_at <= ?";
            $params[] = $date_to . ' 23:59:59';
            $types .= "s";
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return (int) $row['total_minutes'];
    }

    /**
     * Get session duration
     */
    private function getSessionDuration(int $session_id): int {
        $query = "SELECT time_spent_minutes FROM learning_sessions WHERE session_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return (int) ($row['duration_minutes'] ?? 0);
    }

    /**
     * Update user streak
     */
    private function updateUserStreak(int $user_id): void {
        $today = date('Y-m-d');
        
        // Check if streak record exists
        $query = "SELECT streak_id, current_streak, longest_streak, last_activity_date 
                  FROM user_streaks 
                  WHERE user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $lastDate = $row['last_activity_date'];
            $currentStreak = $row['current_streak'];
            $longestStreak = $row['longest_streak'];

            if ($lastDate === $today) {
                // Already logged today
                return;
            }

            $yesterday = date('Y-m-d', strtotime('-1 day'));
            if ($lastDate === $yesterday) {
                // Consecutive day - increment streak
                $currentStreak++;
            } else {
                // Streak broken - reset to 1
                $currentStreak = 1;
            }

            $longestStreak = max($longestStreak, $currentStreak);

            $query = "UPDATE user_streaks 
                      SET current_streak = ?, longest_streak = ?, last_activity_date = ?
                      WHERE streak_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iisi", $currentStreak, $longestStreak, $today, $row['id']);
            $stmt->execute();
        } else {
            // Create new streak record
            $query = "INSERT INTO user_streaks (tenant_id, user_id, current_streak, longest_streak, last_activity_date)
                      VALUES (?, ?, 1, 1, ?)";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iis", $this->tenant_id, $user_id, $today);
            $stmt->execute();
        }
    }

    /**
     * Detect device type from user agent
     */
    private function detectDeviceType(): string {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if (preg_match('/mobile|android|iphone|ipad|ipod/i', $userAgent)) {
            if (preg_match('/ipad|tablet/i', $userAgent)) {
                return 'tablet';
            }
            return 'mobile';
        }
        return 'desktop';
    }

    /**
     * Detect browser from user agent
     */
    private function detectBrowser(): string {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        if (strpos($userAgent, 'Chrome') !== false) return 'Chrome';
        if (strpos($userAgent, 'Firefox') !== false) return 'Firefox';
        if (strpos($userAgent, 'Safari') !== false) return 'Safari';
        if (strpos($userAgent, 'Edge') !== false) return 'Edge';
        if (strpos($userAgent, 'MSIE') !== false || strpos($userAgent, 'Trident') !== false) return 'IE';
        
        return 'Unknown';
    }

    /**
     * Get client IP address
     */
    private function getClientIP(): string {
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
