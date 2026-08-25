<?php
/**
 * Learning Path Item Model
 * Multi-Tenant LMS
 *
 * Handles learning path items (courses, assessments, etc.) within a learning path.
 */

class LearningPathItem {
    /**
     * @var mixed|null
     */
    public ?int $unlock_after_item_id;
    /**
     * @var mixed|null
     */
    public ?int $unlock_score;
    private mysqli $conn;

    // Properties
    public ?int $item_id = null;
    public int $path_id;
    public ?int $section_id = null;
    public int $tenant_id;
    public string $item_type = 'course'; // course, assessment, live_session, external_link, document, video, milestone, checkpoint
    public ?int $course_id = null;
    public ?int $assessment_id = null;
    public ?int $live_session_id = null;
    public ?int $media_id = null;
    public string $external_url = '';
    public string $external_title = '';
    public string $external_description = '';
    public int $sort_order = 0;
    public bool $is_required = true;
    public bool $is_locked = false;
    public string $unlock_type = 'previous_item'; // immediate, previous_item, specific_items, date, score, manual
    public ?array $unlock_after_item_ids = null;
    public ?string $unlock_date = null;
    public ?float $unlock_min_score = null;
    public string $completion_type = 'complete'; // view, complete, pass, time_spent, manual
    public ?int $min_time_spent_minutes = null;
    public ?float $passing_score = null;
    public ?int $max_attempts = null;
    public int $points_value = 0;
    public int $bonus_points = 0;
    public ?string $bonus_deadline = null;
    public ?string $due_date = null;
    public ?int $due_days_from_enrollment = null;
    public ?int $estimated_duration_minutes = null;
    public string $instructor_notes = '';
    public string $learner_notes = '';
    public bool $is_active = true;

    public function __construct(mysqli $db) {
        $this->conn = $db;
    }

    /**
     * Create new learning path item
     */
    public function create(): array {
        // Get next sort order if not specified
        if ($this->sort_order === 0) {
            $this->sort_order = $this->getNextSortOrder();
        }

        $sql = "INSERT INTO learning_path_items (
            path_id, section_id, tenant_id, item_type,
            course_id, assessment_id, live_session_id, media_id,
            external_url, external_title, external_description,
            sort_order, is_required, is_locked, unlock_type,
            unlock_after_item_ids, unlock_date, unlock_min_score,
            completion_type, min_time_spent_minutes, passing_score, max_attempts,
            points_value, bonus_points, bonus_deadline,
            due_date, due_days_from_enrollment, estimated_duration_minutes,
            instructor_notes, learner_notes, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $this->conn->prepare($sql);

        $unlock_after_json = $this->unlock_after_item_ids ? json_encode($this->unlock_after_item_ids) : null;
        $is_required = $this->is_required ? 1 : 0;
        $is_locked = $this->is_locked ? 1 : 0;

        $stmt->bind_param(
            "iiisiiiisssiiisssdsdiiiissiiss",
            $this->path_id, $this->section_id, $this->tenant_id, $this->item_type,
            $this->course_id, $this->assessment_id, $this->live_session_id, $this->media_id,
            $this->external_url, $this->external_title, $this->external_description,
            $this->sort_order, $is_required, $is_locked, $this->unlock_type,
            $unlock_after_json, $this->unlock_date, $this->unlock_min_score,
            $this->completion_type, $this->min_time_spent_minutes, $this->passing_score, $this->max_attempts,
            $this->points_value, $this->bonus_points, $this->bonus_deadline,
            $this->due_date, $this->due_days_from_enrollment, $this->estimated_duration_minutes,
            $this->instructor_notes, $this->learner_notes
        );

        if ($stmt->execute()) {
            $this->item_id = $this->conn->insert_id;
            $this->updatePathTotals();
            return ['success' => true, 'item_id' => $this->item_id];
        }

        return ['success' => false, 'error' => $stmt->error];
    }

    /**
     * Update learning path item
     */
    public function update(): bool {
        $sql = "UPDATE learning_path_items SET 
            section_id = ?, item_type = ?,
            course_id = ?, assessment_id = ?, live_session_id = ?, media_id = ?,
            external_url = ?, external_title = ?, external_description = ?,
            sort_order = ?, is_required = ?, is_locked = ?, unlock_type = ?,
            unlock_after_item_ids = ?, unlock_date = ?, unlock_min_score = ?,
            completion_type = ?, min_time_spent_minutes = ?, passing_score = ?, max_attempts = ?,
            points_value = ?, bonus_points = ?, bonus_deadline = ?,
            due_date = ?, due_days_from_enrollment = ?, estimated_duration_minutes = ?,
            instructor_notes = ?, learner_notes = ?, updated_at = NOW()
            WHERE item_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);

        $unlock_after_json = $this->unlock_after_item_ids ? json_encode($this->unlock_after_item_ids) : null;
        $is_required = $this->is_required ? 1 : 0;
        $is_locked = $this->is_locked ? 1 : 0;

        $stmt->bind_param(
            "isiiiiisssiiisssdsdiiiissiissii",
            $this->section_id, $this->item_type,
            $this->course_id, $this->assessment_id, $this->live_session_id, $this->media_id,
            $this->external_url, $this->external_title, $this->external_description,
            $this->sort_order, $is_required, $is_locked, $this->unlock_type,
            $unlock_after_json, $this->unlock_date, $this->unlock_min_score,
            $this->completion_type, $this->min_time_spent_minutes, $this->passing_score, $this->max_attempts,
            $this->points_value, $this->bonus_points, $this->bonus_deadline,
            $this->due_date, $this->due_days_from_enrollment, $this->estimated_duration_minutes,
            $this->instructor_notes, $this->learner_notes,
            $this->item_id, $this->tenant_id
        );

        return $stmt->execute();
    }

    /**
     * Get item by ID
     */
    public function getById(int $item_id): ?array {
        $sql = "SELECT lpi.*,
                c.title as course_title, c.thumbnail_url as course_thumbnail, c.duration_minutes as course_duration,
                a.title as assessment_title, a.time_limit_minutes as assessment_duration,
                ls.title as session_title,
                m.title as media_name, m.file_url as media_url
                FROM learning_path_items lpi
                LEFT JOIN courses c ON lpi.course_id = c.course_id
                LEFT JOIN assessments a ON lpi.assessment_id = a.assessment_id
                LEFT JOIN live_sessions ls ON lpi.live_session_id = ls.session_id
                LEFT JOIN media m ON lpi.media_id = m.media_id
                WHERE lpi.item_id = ? AND lpi.tenant_id = ? AND lpi.is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $item_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $row['unlock_after_item_ids'] = $row['unlock_after_item_ids'] ? json_decode($row['unlock_after_item_ids'], true) : [];
            return $row;
        }

        return null;
    }

    /**
     * Get all items for a learning path
     */
    public function getByPathId(int $path_id): array {
        $sql = "SELECT lpi.*,
                c.title as course_title, c.thumbnail_url as course_thumbnail, c.duration_minutes as course_duration,
                a.title as assessment_title, a.time_limit_minutes as assessment_duration,
                ls.title as session_title, ls.scheduled_at as session_start,
                m.title as media_name, m.file_url as media_url,
                lps.title as section_title
                FROM learning_path_items lpi
                LEFT JOIN courses c ON lpi.course_id = c.course_id
                LEFT JOIN assessments a ON lpi.assessment_id = a.assessment_id
                LEFT JOIN live_sessions ls ON lpi.live_session_id = ls.session_id
                LEFT JOIN media m ON lpi.media_id = m.media_id
                LEFT JOIN learning_path_sections lps ON lpi.section_id = lps.section_id
                WHERE lpi.path_id = ? AND lpi.tenant_id = ? AND lpi.is_active = 1
                ORDER BY COALESCE(lps.sort_order, 0), lpi.sort_order";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $path_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $row['unlock_after_item_ids'] = $row['unlock_after_item_ids'] ? json_decode($row['unlock_after_item_ids'], true) : [];

            // Determine title based on item type
            switch ($row['item_type']) {
                case 'course':
                    $row['display_title'] = $row['course_title'];
                    $row['display_thumbnail'] = $row['course_thumbnail'];
                    $row['display_duration'] = $row['course_duration'];
                    break;
                case 'assessment':
                    $row['display_title'] = $row['assessment_title'];
                    $row['display_duration'] = $row['assessment_duration'];
                    break;
                case 'live_session':
                    $row['display_title'] = $row['session_title'];
                    break;
                case 'document':
                case 'video':
                    $row['display_title'] = $row['media_name'];
                    break;
                default:
                    $row['display_title'] = $row['external_title'];
            }

            $items[] = $row;
        }

        return $items;
    }

    /**
     * Delete item (soft delete)
     */
    public function delete(): bool {
        $sql = "UPDATE learning_path_items SET is_active = 0, updated_at = NOW() WHERE item_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->item_id, $this->tenant_id);

        if ($stmt->execute()) {
            $this->updatePathTotals();
            $this->reorderItems();
            return true;
        }
        return false;
    }

    /**
     * Reorder items
     */
    public function reorder(array $itemOrders): bool {
        $this->conn->begin_transaction();

        try {
            foreach ($itemOrders as $order) {
                $sql = "UPDATE learning_path_items SET sort_order = ?, section_id = ?, updated_at = NOW() WHERE item_id = ? AND tenant_id = ?";
                $stmt = $this->conn->prepare($sql);
                $sectionId = $order['section_id'] ?? null;
                $stmt->bind_param("iiii", $order['sort_order'], $sectionId, $order['item_id'], $this->tenant_id);
                $stmt->execute();
            }

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            return false;
        }
    }

    /**
     * Get next sort order
     */
    private function getNextSortOrder(): int {
        $sql = "SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order 
                FROM learning_path_items 
                WHERE path_id = ? AND is_active = 1";

        if ($this->section_id) {
            $sql .= " AND section_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ii", $this->path_id, $this->section_id);
        } else {
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $this->path_id);
        }

        $stmt->execute();
        return $stmt->get_result()->fetch_assoc()['next_order'];
    }

    /**
     * Update path totals after item changes
     */
    private function updatePathTotals(): void {
        $sql = "UPDATE learning_paths SET 
                total_items = (SELECT COUNT(*) FROM learning_path_items WHERE path_id = ? AND is_active = 1),
                total_courses = (SELECT COUNT(*) FROM learning_path_items WHERE path_id = ? AND item_type = 'course' AND is_active = 1),
                total_assessments = (SELECT COUNT(*) FROM learning_path_items WHERE path_id = ? AND item_type = 'assessment' AND is_active = 1),
                total_points = (SELECT COALESCE(SUM(points_value), 0) FROM learning_path_items WHERE path_id = ? AND is_active = 1),
                estimated_duration_hours = (SELECT COALESCE(SUM(estimated_duration_minutes), 0) / 60 FROM learning_path_items WHERE path_id = ? AND is_active = 1),
                updated_at = NOW()
                WHERE path_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiiiii", $this->path_id, $this->path_id, $this->path_id, $this->path_id, $this->path_id, $this->path_id);
        $stmt->execute();
    }

    /**
     * Reorder remaining items after deletion
     */
    private function reorderItems(): void {
        // Get all active items for the path ordered by current sort_order
        $sql = "SELECT item_id FROM learning_path_items WHERE path_id = ? AND is_active = 1 ORDER BY sort_order";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->path_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $order = 1;
        while ($row = $result->fetch_assoc()) {
            $updateSql = "UPDATE learning_path_items SET sort_order = ? WHERE item_id = ?";
            $updateStmt = $this->conn->prepare($updateSql);
            $updateStmt->bind_param("ii", $order, $row['item_id']);
            $updateStmt->execute();
            $order++;
        }
    }

    /**
     * Add course to learning path
     */
    public function addCourse(int $course_id, ?int $section_id = null): array {
        $this->item_type = 'course';
        $this->course_id = $course_id;
        $this->section_id = $section_id;

        // Get course details for duration
        $stmt = $this->conn->prepare("SELECT duration_minutes FROM courses WHERE course_id = ?");
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $course = $stmt->get_result()->fetch_assoc();

        if ($course) {
            $this->estimated_duration_minutes = $course['duration_minutes'];
        }

        return $this->create();
    }

    /**
     * Add assessment to learning path
     */
    public function addAssessment(int $assessment_id, ?int $section_id = null): array {
        $this->item_type = 'assessment';
        $this->assessment_id = $assessment_id;
        $this->section_id = $section_id;
        $this->completion_type = 'pass';

        // Get assessment details
        $stmt = $this->conn->prepare("SELECT time_limit_minutes, pass_percentage FROM assessments WHERE assessment_id = ?");
        $stmt->bind_param("i", $assessment_id);
        $stmt->execute();
        $assessment = $stmt->get_result()->fetch_assoc();

        if ($assessment) {
            $this->estimated_duration_minutes = $assessment['time_limit_minutes'];
            $this->passing_score = $assessment['pass_percentage'];
        }

        return $this->create();
    }

    /**
     * Add external link to learning path
     */
    public function addExternalLink(string $url, string $title, string $description = '', ?int $section_id = null): array {
        $this->item_type = 'external_link';
        $this->external_url = $url;
        $this->external_title = $title;
        $this->external_description = $description;
        $this->section_id = $section_id;
        $this->completion_type = 'view';

        return $this->create();
    }
}