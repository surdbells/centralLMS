<?php
/**
 * Learning Path Item Progress Model
 * Multi-Tenant LMS
 *
 * Handles tracking user progress through individual items in a learning path.
 */

class LearningPathProgress {
    private mysqli $conn;

    // Properties
    public ?int $progress_id = null;
    public int $enrollment_id;
    public int $item_id;
    public int $user_id;
    public int $tenant_id;
    public string $status = 'locked'; // locked, available, in_progress, completed, failed, skipped
    public float $progress_percentage = 0.00;
    public ?string $unlocked_at = null;
    public ?string $started_at = null;
    public ?string $completed_at = null;
    public ?string $due_date = null;
    public int $time_spent_minutes = 0;
    public int $attempts = 0;
    public ?float $best_score = null;
    public ?float $last_score = null;
    public ?bool $passed = null;
    public int $points_earned = 0;
    public int $bonus_earned = 0;
    public ?int $course_enrollment_id = null;
    public ?int $assessment_attempt_id = null;
    public string $notes = '';
    public string $feedback = '';

    public function __construct(mysqli $db) {
        $this->conn = $db;
    }

    /**
     * Get item progress by enrollment and item
     */
    public function getProgress(int $enrollment_id, int $item_id): ?array {
        $sql = "SELECT pip.*, 
                lpi.item_type, lpi.completion_type, lpi.passing_score, lpi.max_attempts,
                lpi.points_value, lpi.bonus_points, lpi.bonus_deadline,
                lpi.course_id, lpi.assessment_id, lpi.external_url,
                c.title as course_title,
                a.title as assessment_title
                FROM learning_path_item_progress pip
                JOIN learning_path_items lpi ON pip.item_id = lpi.item_id
                LEFT JOIN courses c ON lpi.course_id = c.course_id
                LEFT JOIN assessments a ON lpi.assessment_id = a.assessment_id
                WHERE pip.enrollment_id = ? AND pip.item_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $enrollment_id, $item_id);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Get all item progress for an enrollment
     */
    public function getAllProgress(int $enrollment_id): array {
        $sql = "SELECT pip.*, 
                lpi.item_type, lpi.completion_type, lpi.passing_score, lpi.points_value,
                lpi.sort_order, lpi.section_id, lpi.external_url, lpi.external_title,
                lpi.course_id, lpi.assessment_id, lpi.is_required,
                c.title as course_title, c.thumbnail_url as course_thumbnail,
                a.title as assessment_title,
                lps.title as section_title, lps.sort_order as section_sort_order
                FROM learning_path_item_progress pip
                JOIN learning_path_items lpi ON pip.item_id = lpi.item_id
                LEFT JOIN courses c ON lpi.course_id = c.course_id
                LEFT JOIN assessments a ON lpi.assessment_id = a.assessment_id
                LEFT JOIN learning_path_sections lps ON lpi.section_id = lps.section_id
                WHERE pip.enrollment_id = ?
                ORDER BY COALESCE(lps.sort_order, 999), lpi.sort_order";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $enrollment_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $progress = [];
        while ($row = $result->fetch_assoc()) {
            // Determine display title
            switch ($row['item_type']) {
                case 'course':
                    $row['display_title'] = $row['course_title'];
                    $row['display_thumbnail'] = $row['course_thumbnail'];
                    break;
                case 'assessment':
                    $row['display_title'] = $row['assessment_title'];
                    break;
                default:
                    $row['display_title'] = $row['external_title'];
            }
            $progress[] = $row;
        }

        return $progress;
    }

    /**
     * Get progress grouped by sections
     */
    public function getProgressBySection(int $enrollment_id): array {
        // Get section progress
        $sql = "SELECT lsp.*, lps.title as section_title, lps.sort_order
                FROM learning_path_section_progress lsp
                JOIN learning_path_sections lps ON lsp.section_id = lps.section_id
                WHERE lsp.enrollment_id = ?
                ORDER BY lps.sort_order";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $enrollment_id);
        $stmt->execute();
        $sectionsResult = $stmt->get_result();

        $sections = [];
        while ($section = $sectionsResult->fetch_assoc()) {
            $sections[$section['section_id']] = [
                'section' => $section,
                'items' => []
            ];
        }

        // Get all item progress
        $itemProgress = $this->getAllProgress($enrollment_id);

        $unsectionedItems = [];
        foreach ($itemProgress as $item) {
            if ($item['section_id'] && isset($sections[$item['section_id']])) {
                $sections[$item['section_id']]['items'][] = $item;
            } else {
                $unsectionedItems[] = $item;
            }
        }

        return [
            'sections' => array_values($sections),
            'unsectioned_items' => $unsectionedItems
        ];
    }

    /**
     * Start an item
     */
    public function startItem(): array {
        // Check if item is available
        $current = $this->getProgress($this->enrollment_id, $this->item_id);

        if (!$current) {
            return ['success' => false, 'error' => 'Progress record not found'];
        }

        if ($current['status'] === 'locked') {
            return ['success' => false, 'error' => 'This item is locked'];
        }

        if ($current['status'] === 'completed') {
            return ['success' => false, 'error' => 'This item has already been completed'];
        }

        // Check max attempts
        if ($current['max_attempts'] && $current['attempts'] >= $current['max_attempts']) {
            return ['success' => false, 'error' => 'Maximum attempts reached'];
        }

        $sql = "UPDATE learning_path_item_progress SET 
                status = 'in_progress',
                started_at = COALESCE(started_at, NOW()),
                last_accessed_at = NOW(),
                attempts = attempts + 1,
                updated_at = NOW()
                WHERE enrollment_id = ? AND item_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->enrollment_id, $this->item_id);

        if ($stmt->execute()) {
            // Update enrollment last accessed
            $this->updateEnrollmentAccess();

            // Log activity
            $this->logActivity('item_started');

            return ['success' => true];
        }

        return ['success' => false, 'error' => $stmt->error];
    }

    /**
     * Complete an item
     */
    public function completeItem(?float $score = null, ?int $time_spent = null): array {
        $current = $this->getProgress($this->enrollment_id, $this->item_id);

        if (!$current) {
            return ['success' => false, 'error' => 'Progress record not found'];
        }

        // Check if passing score is required
        if ($current['completion_type'] === 'pass' && $current['passing_score']) {
            if ($score === null || $score < $current['passing_score']) {
                // Update attempt but don't complete
                $this->updateAttempt($score, $time_spent);
                return [
                    'success' => false,
                    'error' => 'Passing score not achieved',
                    'required_score' => $current['passing_score'],
                    'your_score' => $score
                ];
            }
        }

        // Calculate points earned
        $pointsEarned = $current['points_value'];
        $bonusEarned = 0;

        // Check for bonus points (before deadline)
        if ($current['bonus_points'] && $current['bonus_deadline']) {
            if (time() < strtotime($current['bonus_deadline'])) {
                $bonusEarned = $current['bonus_points'];
            }
        }

        // Mark as completed
        $sql = "UPDATE learning_path_item_progress SET 
                status = 'completed',
                completed_at = NOW(),
                progress_percentage = 100,
                time_spent_minutes = time_spent_minutes + COALESCE(?, 0),
                last_score = ?,
                best_score = GREATEST(COALESCE(best_score, 0), COALESCE(?, 0)),
                passed = 1,
                points_earned = ?,
                bonus_earned = ?,
                last_accessed_at = NOW(),
                updated_at = NOW()
                WHERE enrollment_id = ? AND item_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iddiii", $time_spent, $score, $score, $pointsEarned, $bonusEarned, $this->enrollment_id, $this->item_id);

        if ($stmt->execute()) {
            // Unlock next items
            $this->unlockNextItems();

            // Update enrollment progress
            $this->updateEnrollmentProgress();

            // Check if path is completed
            $pathCompleted = $this->checkPathCompletion();

            // Log activity
            $this->logActivity('item_completed', [
                'score' => $score,
                'points_earned' => $pointsEarned + $bonusEarned
            ]);

            return [
                'success' => true,
                'points_earned' => $pointsEarned,
                'bonus_earned' => $bonusEarned,
                'path_completed' => $pathCompleted
            ];
        }

        return ['success' => false, 'error' => $stmt->error];
    }

    /**
     * Skip an item (for optional items or allowed by path settings)
     */
    public function skipItem(): array {
        $current = $this->getProgress($this->enrollment_id, $this->item_id);

        if (!$current) {
            return ['success' => false, 'error' => 'Progress record not found'];
        }

        if ($current['is_required']) {
            return ['success' => false, 'error' => 'Cannot skip required items'];
        }

        $sql = "UPDATE learning_path_item_progress SET 
                status = 'skipped',
                completed_at = NOW(),
                updated_at = NOW()
                WHERE enrollment_id = ? AND item_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->enrollment_id, $this->item_id);

        if ($stmt->execute()) {
            $this->unlockNextItems();
            $this->updateEnrollmentProgress();

            return ['success' => true];
        }

        return ['success' => false, 'error' => $stmt->error];
    }

    /**
     * Update time spent on an item
     */
    public function updateTimeSpent(int $minutes): bool {
        $sql = "UPDATE learning_path_item_progress SET 
                time_spent_minutes = time_spent_minutes + ?,
                last_accessed_at = NOW(),
                updated_at = NOW()
                WHERE enrollment_id = ? AND item_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $minutes, $this->enrollment_id, $this->item_id);

        return $stmt->execute();
    }

    /**
     * Reset item progress
     */
    public function resetProgress(): bool {
        $sql = "UPDATE learning_path_item_progress SET 
                status = 'available',
                progress_percentage = 0,
                started_at = NULL,
                completed_at = NULL,
                time_spent_minutes = 0,
                attempts = 0,
                best_score = NULL,
                last_score = NULL,
                passed = NULL,
                points_earned = 0,
                bonus_earned = 0,
                updated_at = NOW()
                WHERE enrollment_id = ? AND item_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->enrollment_id, $this->item_id);

        if ($stmt->execute()) {
            $this->updateEnrollmentProgress();
            return true;
        }
        return false;
    }

    /**
     * Unlock an item manually (admin action)
     */
    public function unlockItem(): bool {
        $sql = "UPDATE learning_path_item_progress SET 
                status = 'available',
                unlocked_at = NOW(),
                updated_at = NOW()
                WHERE enrollment_id = ? AND item_id = ? AND status = 'locked'";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->enrollment_id, $this->item_id);

        return $stmt->execute();
    }

    /**
     * Mark item as failed (for items with max attempts)
     */
    public function failItem(): bool {
        $sql = "UPDATE learning_path_item_progress SET 
                status = 'failed',
                passed = 0,
                updated_at = NOW()
                WHERE enrollment_id = ? AND item_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->enrollment_id, $this->item_id);

        return $stmt->execute();
    }

    /**
     * Unlock next items based on learning path type
     */
    private function unlockNextItems(): void {
        // Get the path type
        $sql = "SELECT lp.path_type FROM learning_paths lp
                JOIN learning_path_enrollments lpe ON lp.path_id = lpe.path_id
                WHERE lpe.enrollment_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->enrollment_id);
        $stmt->execute();
        $pathType = $stmt->get_result()->fetch_assoc()['path_type'];

        if ($pathType === 'sequential') {
            // Unlock the next item in sequence
            $sql = "UPDATE learning_path_item_progress pip
                    JOIN learning_path_items lpi ON pip.item_id = lpi.item_id
                    SET pip.status = 'available', pip.unlocked_at = NOW(), pip.updated_at = NOW()
                    WHERE pip.enrollment_id = ?
                    AND pip.status = 'locked'
                    AND lpi.sort_order = (
                        SELECT sort_order + 1 FROM learning_path_items 
                        WHERE item_id = ? AND is_active = 1
                    )";

            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ii", $this->enrollment_id, $this->item_id);
            $stmt->execute();
        } else {
            // For flexible paths, unlock items that depend on this item
            $sql = "UPDATE learning_path_item_progress pip
                    JOIN learning_path_items lpi ON pip.item_id = lpi.item_id
                    SET pip.status = 'available', pip.unlocked_at = NOW(), pip.updated_at = NOW()
                    WHERE pip.enrollment_id = ?
                    AND pip.status = 'locked'
                    AND JSON_CONTAINS(lpi.unlock_after_item_ids, CAST(? AS JSON))";

            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ii", $this->enrollment_id, $this->item_id);
            $stmt->execute();
        }

        // Update section progress
        $this->updateSectionProgress();
    }

    /**
     * Update section progress
     */
    private function updateSectionProgress(): void {
        $sql = "UPDATE learning_path_section_progress lsp
                SET 
                    items_completed = (
                        SELECT COUNT(*) 
                        FROM learning_path_item_progress pip
                        JOIN learning_path_items lpi ON pip.item_id = lpi.item_id
                        WHERE pip.enrollment_id = lsp.enrollment_id
                        AND lpi.section_id = lsp.section_id
                        AND pip.status = 'completed'
                    ),
                    progress_percentage = (
                        SELECT (COUNT(CASE WHEN pip.status = 'completed' THEN 1 END) / COUNT(*)) * 100
                        FROM learning_path_item_progress pip
                        JOIN learning_path_items lpi ON pip.item_id = lpi.item_id
                        WHERE pip.enrollment_id = lsp.enrollment_id
                        AND lpi.section_id = lsp.section_id
                    ),
                    status = CASE 
                        WHEN (
                            SELECT COUNT(*) = COUNT(CASE WHEN pip.status = 'completed' THEN 1 END)
                            FROM learning_path_item_progress pip
                            JOIN learning_path_items lpi ON pip.item_id = lpi.item_id
                            WHERE pip.enrollment_id = lsp.enrollment_id
                            AND lpi.section_id = lsp.section_id
                        ) THEN 'completed'
                        WHEN lsp.status = 'locked' THEN 'locked'
                        ELSE 'in_progress'
                    END,
                    completed_at = CASE 
                        WHEN (
                            SELECT COUNT(*) = COUNT(CASE WHEN pip.status = 'completed' THEN 1 END)
                            FROM learning_path_item_progress pip
                            JOIN learning_path_items lpi ON pip.item_id = lpi.item_id
                            WHERE pip.enrollment_id = lsp.enrollment_id
                            AND lpi.section_id = lsp.section_id
                        ) THEN NOW()
                        ELSE completed_at
                    END,
                    updated_at = NOW()
                WHERE lsp.enrollment_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->enrollment_id);
        $stmt->execute();
    }

    /**
     * Update enrollment progress
     */
    private function updateEnrollmentProgress(): void {
        $sql = "UPDATE learning_path_enrollments lpe
                SET 
                    items_completed = (
                        SELECT COUNT(*) FROM learning_path_item_progress 
                        WHERE enrollment_id = lpe.enrollment_id AND status = 'completed'
                    ),
                    progress_percentage = (
                        SELECT (COUNT(CASE WHEN status IN ('completed', 'skipped') THEN 1 END) / COUNT(*)) * 100
                        FROM learning_path_item_progress 
                        WHERE enrollment_id = lpe.enrollment_id
                    ),
                    total_time_spent_minutes = (
                        SELECT COALESCE(SUM(time_spent_minutes), 0)
                        FROM learning_path_item_progress 
                        WHERE enrollment_id = lpe.enrollment_id
                    ),
                    total_points_earned = (
                        SELECT COALESCE(SUM(points_earned + bonus_earned), 0)
                        FROM learning_path_item_progress 
                        WHERE enrollment_id = lpe.enrollment_id
                    ),
                    current_item_id = ?,
                    last_accessed_at = NOW(),
                    updated_at = NOW()
                WHERE lpe.enrollment_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->item_id, $this->enrollment_id);
        $stmt->execute();
    }

    /**
     * Update enrollment last access time
     */
    private function updateEnrollmentAccess(): void {
        $sql = "UPDATE learning_path_enrollments SET 
                current_item_id = ?,
                last_accessed_at = NOW()
                WHERE enrollment_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->item_id, $this->enrollment_id);
        $stmt->execute();
    }

    /**
     * Update attempt without completing
     */
    private function updateAttempt(?float $score, ?int $time_spent): void {
        $sql = "UPDATE learning_path_item_progress SET 
                time_spent_minutes = time_spent_minutes + COALESCE(?, 0),
                last_score = ?,
                best_score = GREATEST(COALESCE(best_score, 0), COALESCE(?, 0)),
                last_accessed_at = NOW(),
                updated_at = NOW()
                WHERE enrollment_id = ? AND item_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iddii", $time_spent, $score, $score, $this->enrollment_id, $this->item_id);
        $stmt->execute();
    }

    /**
     * Check if learning path is completed
     */
    private function checkPathCompletion(): bool {
        $sql = "SELECT 
                COUNT(*) as total_required,
                SUM(CASE WHEN pip.status IN ('completed', 'skipped') THEN 1 ELSE 0 END) as completed
                FROM learning_path_items lpi
                JOIN learning_path_item_progress pip ON lpi.item_id = pip.item_id
                WHERE pip.enrollment_id = ? AND lpi.is_required = 1 AND lpi.is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->enrollment_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result['completed'] >= $result['total_required']) {
            // Complete the enrollment
            $this->completeEnrollment();
            return true;
        }

        return false;
    }

    /**
     * Complete the enrollment
     */
    private function completeEnrollment(): void {
        $sql = "UPDATE learning_path_enrollments SET 
                status = 'completed',
                completed_at = NOW(),
                completion_type = 'automatic',
                updated_at = NOW()
                WHERE enrollment_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->enrollment_id);
        $stmt->execute();

        // Update path completion count
        $sql = "UPDATE learning_paths SET total_completions = total_completions + 1 
                WHERE path_id = (SELECT path_id FROM learning_path_enrollments WHERE enrollment_id = ?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->enrollment_id);
        $stmt->execute();

        $this->logActivity('path_completed');
    }

    /**
     * Log activity
     */
    private function logActivity(string $activityType, array $data = []): void {
        $sql = "SELECT path_id FROM learning_path_enrollments WHERE enrollment_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->enrollment_id);
        $stmt->execute();
        $pathId = $stmt->get_result()->fetch_assoc()['path_id'];

        $sql = "INSERT INTO learning_path_activity_log (path_id, enrollment_id, item_id, user_id, tenant_id, activity_type, activity_data, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $this->conn->prepare($sql);
        $activityData = !empty($data) ? json_encode($data) : null;

        $stmt->bind_param("iiiisss", $pathId, $this->enrollment_id, $this->item_id, $this->user_id, $this->tenant_id, $activityType, $activityData);
        $stmt->execute();
    }
}