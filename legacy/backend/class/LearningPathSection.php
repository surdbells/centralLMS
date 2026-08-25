<?php
/**
 * Learning Path Section Model
 * Multi-Tenant LMS
 *
 * Handles sections/groups within learning paths for organizing items.
 */

class LearningPathSection {
    private mysqli $conn;

    // Properties
    public ?int $section_id = null;
    public int $path_id;
    public int $tenant_id;
    public string $title = '';
    public string $description = '';
    public int $sort_order = 0;
    public bool $is_milestone = false;
    public ?string $milestone_title = null;
    public string $unlock_type = 'previous_section'; // immediate, previous_section, specific_section, date, manual
    public ?int $unlock_after_section_id = null;
    public ?string $unlock_date = null;
    public bool $completion_required = true;
    public ?int $min_items_to_complete = null;
    public bool $is_active = true;

    public function __construct(mysqli $db) {
        $this->conn = $db;
    }

    /**
     * Create new section
     */
    public function create(): array {
        if ($this->sort_order === 0) {
            $this->sort_order = $this->getNextSortOrder();
        }

        $sql = "INSERT INTO learning_path_sections (
            path_id, tenant_id, title, description, sort_order,
            is_milestone, milestone_title, unlock_type, unlock_after_section_id, 
            unlock_date, completion_required, min_items_to_complete, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

        $stmt = $this->conn->prepare($sql);

        $is_milestone = $this->is_milestone ? 1 : 0;
        $completion_required = $this->completion_required ? 1 : 0;

        $stmt->bind_param(
            "iissiissisii",
            $this->path_id, $this->tenant_id, $this->title, $this->description, $this->sort_order,
            $is_milestone, $this->milestone_title, $this->unlock_type, $this->unlock_after_section_id,
            $this->unlock_date, $completion_required, $this->min_items_to_complete
        );

        if ($stmt->execute()) {
            $this->section_id = $this->conn->insert_id;
            return [
                'success' => true,
                'data' => [
                    'section_id' => $this->section_id
                ]
            ];
        }

        return ['success' => false, 'error' => $stmt->error];
    }

    /**
     * Update section
     */
    public function update(): bool {
        $sql = "UPDATE learning_path_sections SET 
            title = ?, description = ?, sort_order = ?,
            is_milestone = ?, milestone_title = ?, unlock_type = ?, 
            unlock_after_section_id = ?, unlock_date = ?,
            completion_required = ?, min_items_to_complete = ?,
            updated_at = NOW()
            WHERE section_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);

        $is_milestone = $this->is_milestone ? 1 : 0;
        $completion_required = $this->completion_required ? 1 : 0;

        $stmt->bind_param(
            "ssisssissiii",
            $this->title, $this->description, $this->sort_order,
            $is_milestone, $this->milestone_title, $this->unlock_type,
            $this->unlock_after_section_id, $this->unlock_date,
            $completion_required, $this->min_items_to_complete,
            $this->section_id, $this->tenant_id
        );

        return $stmt->execute();
    }

    /**
     * Get section by ID
     */
    public function getById(int $section_id): ?array {
        $sql = "SELECT s.*, lp.title as path_title,
                (SELECT COUNT(*) FROM learning_path_items WHERE section_id = s.section_id AND is_active = 1) as items_count
                FROM learning_path_sections s
                JOIN learning_paths lp ON s.path_id = lp.path_id
                WHERE s.section_id = ? AND s.tenant_id = ? AND s.is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $section_id, $this->tenant_id);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Get all sections for a path
     */
    public function getByPathId(int $path_id): array {
        $sql = "SELECT s.*,
                (SELECT COUNT(*) FROM learning_path_items WHERE section_id = s.section_id AND is_active = 1) as items_count,
                (SELECT COALESCE(SUM(estimated_duration_minutes), 0) FROM learning_path_items WHERE section_id = s.section_id AND is_active = 1) as total_duration_minutes
                FROM learning_path_sections s
                WHERE s.path_id = ? AND s.tenant_id = ? AND s.is_active = 1
                ORDER BY s.sort_order";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $path_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $sections = [];
        while ($row = $result->fetch_assoc()) {
            $sections[] = $row;
        }

        return $sections;
    }

    /**
     * Get sections with items (alias for endpoint compatibility)
     */
    public function getWithItems(int $path_id): array {
        return $this->getSectionsWithItems($path_id);
    }

    /**
     * Get sections with items
     */
    public function getSectionsWithItems(int $path_id): array {
        $sections = $this->getByPathId($path_id);

        // Get items for each section
        foreach ($sections as &$section) {
            $sql = "SELECT lpi.*,
                    c.title as course_title, c.thumbnail_url as course_thumbnail, c.duration_minutes as course_duration,
                    a.title as assessment_title, a.time_limit_minutes as assessment_duration,
                    ls.title as session_title,
                    m.title as media_title, m.file_url as media_url
                    FROM learning_path_items lpi
                    LEFT JOIN courses c ON lpi.course_id = c.course_id
                    LEFT JOIN assessments a ON lpi.assessment_id = a.assessment_id
                    LEFT JOIN live_sessions ls ON lpi.live_session_id = ls.session_id
                    LEFT JOIN media m ON lpi.media_id = m.media_id
                    WHERE lpi.section_id = ? AND lpi.is_active = 1
                    ORDER BY lpi.sort_order";

            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("i", $section['section_id']);
            $stmt->execute();
            $result = $stmt->get_result();

            $section['items'] = [];
            while ($item = $result->fetch_assoc()) {
                // Set display title based on item type
                switch ($item['item_type']) {
                    case 'course':
                        $item['display_title'] = $item['course_title'];
                        $item['display_thumbnail'] = $item['course_thumbnail'];
                        $item['display_duration'] = $item['course_duration'];
                        break;
                    case 'assessment':
                        $item['display_title'] = $item['assessment_title'];
                        $item['display_duration'] = $item['assessment_duration'];
                        break;
                    case 'live_session':
                        $item['display_title'] = $item['session_title'];
                        break;
                    case 'document':
                    case 'video':
                        $item['display_title'] = $item['media_title'];
                        break;
                    default:
                        $item['display_title'] = $item['external_title'];
                }
                $section['items'][] = $item;
            }
        }

        // Also get unsectioned items
        $sql = "SELECT lpi.*,
                c.title as course_title, c.thumbnail_url as course_thumbnail,
                a.title as assessment_title
                FROM learning_path_items lpi
                LEFT JOIN courses c ON lpi.course_id = c.course_id
                LEFT JOIN assessments a ON lpi.assessment_id = a.assessment_id
                WHERE lpi.path_id = ? AND lpi.section_id IS NULL AND lpi.is_active = 1
                ORDER BY lpi.sort_order";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $path_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $unsectionedItems = [];
        while ($item = $result->fetch_assoc()) {
            switch ($item['item_type']) {
                case 'course':
                    $item['display_title'] = $item['course_title'];
                    $item['display_thumbnail'] = $item['course_thumbnail'];
                    break;
                case 'assessment':
                    $item['display_title'] = $item['assessment_title'];
                    break;
                default:
                    $item['display_title'] = $item['external_title'];
            }
            $unsectionedItems[] = $item;
        }

        return [
            'sections' => $sections,
            'unsectioned_items' => $unsectionedItems
        ];
    }

    /**
     * Delete section (soft delete)
     */
    public function delete(): bool {
        // Move items to unsectioned (null section_id)
        $sql = "UPDATE learning_path_items SET section_id = NULL, updated_at = NOW() WHERE section_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->section_id, $this->tenant_id);
        $stmt->execute();

        // Soft delete section
        $sql = "UPDATE learning_path_sections SET is_active = 0, updated_at = NOW() WHERE section_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->section_id, $this->tenant_id);

        if ($stmt->execute()) {
            $this->reorderSections();
            return true;
        }
        return false;
    }

    /**
     * Reorder sections
     */
    public function reorder(array $sectionOrders): bool {
        $this->conn->begin_transaction();

        try {
            foreach ($sectionOrders as $order) {
                $sql = "UPDATE learning_path_sections SET sort_order = ?, updated_at = NOW() WHERE section_id = ? AND tenant_id = ?";
                $stmt = $this->conn->prepare($sql);
                $stmt->bind_param("iii", $order['sort_order'], $order['section_id'], $this->tenant_id);
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
     * Move item to this section
     */
    public function addItem(int $item_id): bool {
        // Get next sort order for this section
        $sql = "SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM learning_path_items WHERE section_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->section_id);
        $stmt->execute();
        $nextOrder = $stmt->get_result()->fetch_assoc()['next_order'];

        $sql = "UPDATE learning_path_items SET 
                section_id = ?,
                sort_order = ?,
                updated_at = NOW()
                WHERE item_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiii", $this->section_id, $nextOrder, $item_id, $this->tenant_id);

        if ($stmt->execute()) {
            $this->updateSectionStats();
            return true;
        }
        return false;
    }

    /**
     * Remove item from section
     */
    public function removeItem(int $item_id): bool {
        $sql = "UPDATE learning_path_items SET section_id = NULL, updated_at = NOW() WHERE item_id = ? AND section_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $item_id, $this->section_id, $this->tenant_id);

        if ($stmt->execute()) {
            $this->updateSectionStats();
            return true;
        }
        return false;
    }

    /**
     * Get next sort order
     */
    private function getNextSortOrder(): int {
        $sql = "SELECT COALESCE(MAX(sort_order), 0) + 1 as next_order FROM learning_path_sections WHERE path_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->path_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc()['next_order'];
    }

    /**
     * Reorder remaining sections after deletion
     */
    private function reorderSections(): void {
        // Get all active sections for the path ordered by current sort_order
        $sql = "SELECT section_id FROM learning_path_sections WHERE path_id = ? AND is_active = 1 ORDER BY sort_order";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->path_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $order = 1;
        while ($row = $result->fetch_assoc()) {
            $updateSql = "UPDATE learning_path_sections SET sort_order = ? WHERE section_id = ?";
            $updateStmt = $this->conn->prepare($updateSql);
            $updateStmt->bind_param("ii", $order, $row['section_id']);
            $updateStmt->execute();
            $order++;
        }
    }

    /**
     * Update section statistics
     */
    private function updateSectionStats(): void {
        $sql = "UPDATE learning_path_sections SET 
                estimated_duration_hours = (
                    SELECT COALESCE(SUM(estimated_duration_minutes), 0) / 60 
                    FROM learning_path_items 
                    WHERE section_id = ? AND is_active = 1
                ),
                updated_at = NOW()
                WHERE section_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->section_id, $this->section_id);
        $stmt->execute();
    }
}