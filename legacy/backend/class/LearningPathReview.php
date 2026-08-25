<?php
/**
 * Learning Path Review Model
 * Multi-Tenant LMS
 *
 * Handles ratings and reviews for learning paths.
 */

class LearningPathReview {
    private mysqli $conn;

    // Properties
    public ?int $review_id = null;
    public int $path_id;
    public int $user_id;
    public ?int $enrollment_id = null;
    public int $tenant_id;
    public int $rating; // 1-5
    public string $title = '';
    public string $review_text = '';
    public ?int $content_rating = null;
    public ?int $difficulty_rating = null;
    public ?int $practical_value_rating = null;
    public string $status = 'pending'; // pending, approved, rejected, flagged
    public ?int $moderated_by = null;
    public ?string $moderated_at = null;
    public string $rejection_reason = '';
    public int $helpful_count = 0;
    public int $reported_count = 0;
    public string $response_text = '';
    public ?int $response_by = null;
    public ?string $response_at = null;
    public bool $is_active = true;

    // Pagination
    public int $page = 1;
    public int $per_page = 10;

    public function __construct(mysqli $db) {
        $this->conn = $db;
    }

    /**
     * Create review
     */
    public function create(): array {
        // Check if user has completed the path
        $sql = "SELECT enrollment_id FROM learning_path_enrollments 
                WHERE path_id = ? AND user_id = ? AND tenant_id = ? AND status = 'completed'";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $this->path_id, $this->user_id, $this->tenant_id);
        $stmt->execute();
        $enrollment = $stmt->get_result()->fetch_assoc();

        if (!$enrollment) {
            return ['success' => false, 'error' => 'You must complete the learning path before leaving a review'];
        }

        $this->enrollment_id = $enrollment['enrollment_id'];

        // Check if already reviewed
        $sql = "SELECT review_id FROM learning_path_reviews WHERE path_id = ? AND user_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->path_id, $this->user_id);
        $stmt->execute();

        if ($stmt->get_result()->num_rows > 0) {
            return ['success' => false, 'error' => 'You have already reviewed this learning path'];
        }

        // Validate rating
        if ($this->rating < 1 || $this->rating > 5) {
            return ['success' => false, 'error' => 'Rating must be between 1 and 5'];
        }

        $sql = "INSERT INTO learning_path_reviews (
            path_id, user_id, enrollment_id, tenant_id,
            rating, title, review_text,
            content_rating, difficulty_rating, practical_value_rating,
            status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "iiiiiissii",
            $this->path_id, $this->user_id, $this->enrollment_id, $this->tenant_id,
            $this->rating, $this->title, $this->review_text,
            $this->content_rating, $this->difficulty_rating, $this->practical_value_rating
        );

        if ($stmt->execute()) {
            $this->review_id = $this->conn->insert_id;
            return ['success' => true, 'review_id' => $this->review_id];
        }

        return ['success' => false, 'error' => $stmt->error];
    }

    /**
     * Update review
     */
    public function update(): bool {
        $sql = "UPDATE learning_path_reviews SET 
                rating = ?, title = ?, review_text = ?,
                content_rating = ?, difficulty_rating = ?, practical_value_rating = ?,
                status = 'pending', updated_at = NOW()
                WHERE review_id = ? AND user_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "issiiiiii",
            $this->rating, $this->title, $this->review_text,
            $this->content_rating, $this->difficulty_rating, $this->practical_value_rating,
            $this->review_id, $this->user_id, $this->tenant_id
        );

        return $stmt->execute();
    }

    /**
     * Get review by ID
     */
    public function getById(int $review_id): ?array {
        $sql = "SELECT r.*, 
                u.first_name, u.last_name, u.avatar as avatar,
                lp.title as path_title
                FROM learning_path_reviews r
                JOIN users u ON r.user_id = u.id
                JOIN learning_paths lp ON r.path_id = lp.path_id
                WHERE r.review_id = ? AND r.tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $review_id, $this->tenant_id);
        $stmt->execute();

        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Get reviews for a path
     */
    public function getByPathId(int $path_id, bool $approved_only = true): array {
        $where = ["r.path_id = ?", "r.tenant_id = ?", "r.is_active = 1"];
        $params = [$path_id, $this->tenant_id];
        $types = "ii";

        if ($approved_only) {
            $where[] = "r.status = 'approved'";
        }

        $whereClause = implode(" AND ", $where);

        // Count
        $countSql = "SELECT COUNT(*) as total FROM learning_path_reviews r WHERE $whereClause";
        $stmt = $this->conn->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        // Get data
        $offset = ($this->page - 1) * $this->per_page;
        $sql = "SELECT r.*, 
                u.first_name, u.last_name, u.avatar as profile_image_url,
                rb.first_name as response_by_first_name, rb.last_name as response_by_last_name
                FROM learning_path_reviews r
                JOIN users u ON r.user_id = u.id
                LEFT JOIN users rb ON r.response_by = rb.id
                WHERE $whereClause
                ORDER BY r.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $this->per_page;
        $params[] = $offset;
        $types .= "ii";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $reviews = [];
        while ($row = $result->fetch_assoc()) {
            $reviews[] = $row;
        }

        return [
            'data' => $reviews,
            'pagination' => [
                'total' => (int)$total,
                'page' => $this->page,
                'per_page' => $this->per_page,
                'total_pages' => ceil($total / $this->per_page)
            ]
        ];
    }

    /**
     * Get rating summary for a path
     */
    public function getRatingSummary(int $path_id): array {
        $sql = "SELECT 
                COUNT(*) as total_reviews,
                AVG(rating) as avg_rating,
                AVG(content_rating) as avg_content_rating,
                AVG(difficulty_rating) as avg_difficulty_rating,
                AVG(practical_value_rating) as avg_practical_value_rating,
                SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star,
                SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_star,
                SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_star,
                SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_star,
                SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star
                FROM learning_path_reviews
                WHERE path_id = ? AND status = 'approved' AND is_active = 1";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $path_id);
        $stmt->execute();

        $summary = $stmt->get_result()->fetch_assoc();

        // Calculate percentages
        $total = (int)$summary['total_reviews'];
        if ($total > 0) {
            $summary['five_star_pct'] = round(($summary['five_star'] / $total) * 100, 1);
            $summary['four_star_pct'] = round(($summary['four_star'] / $total) * 100, 1);
            $summary['three_star_pct'] = round(($summary['three_star'] / $total) * 100, 1);
            $summary['two_star_pct'] = round(($summary['two_star'] / $total) * 100, 1);
            $summary['one_star_pct'] = round(($summary['one_star'] / $total) * 100, 1);
        } else {
            $summary['five_star_pct'] = 0;
            $summary['four_star_pct'] = 0;
            $summary['three_star_pct'] = 0;
            $summary['two_star_pct'] = 0;
            $summary['one_star_pct'] = 0;
        }

        return $summary;
    }

    /**
     * Approve review
     * Uses moderated_by property if set, otherwise uses user_id
     */
    public function approve(?int $moderated_by = null): bool {
        $moderator = $moderated_by ?? $this->moderated_by ?? $this->user_id;

        $sql = "UPDATE learning_path_reviews SET 
                status = 'approved', moderated_by = ?, moderated_at = NOW(), updated_at = NOW()
                WHERE review_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $moderator, $this->review_id, $this->tenant_id);

        if ($stmt->execute()) {
            $this->updatePathRating();
            return true;
        }
        return false;
    }

    /**
     * Reject review
     * Uses moderated_by property if set, otherwise uses user_id
     */
    public function reject(?string $reason = null, ?int $moderated_by = null): bool {
        $moderator = $moderated_by ?? $this->moderated_by ?? $this->user_id;
        $rejectionReason = $reason ?? $this->rejection_reason ?? '';

        $sql = "UPDATE learning_path_reviews SET 
                status = 'rejected', moderated_by = ?, moderated_at = NOW(), 
                rejection_reason = ?, updated_at = NOW()
                WHERE review_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("isii", $moderator, $rejectionReason, $this->review_id, $this->tenant_id);

        return $stmt->execute();
    }

    /**
     * Add response to review (alias for addResponse)
     */
    public function respond(string $response, ?int $response_by = null): bool {
        return $this->addResponse($response, $response_by ?? $this->response_by ?? $this->user_id);
    }

    /**
     * Add response to review
     */
    public function addResponse(string $response, int $response_by): bool {
        $sql = "UPDATE learning_path_reviews SET 
                response_text = ?, response_by = ?, response_at = NOW(), updated_at = NOW()
                WHERE review_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("siii", $response, $response_by, $this->review_id, $this->tenant_id);

        return $stmt->execute();
    }

    /**
     * Mark review as helpful
     */
    public function markHelpful(): bool {
        $sql = "UPDATE learning_path_reviews SET helpful_count = helpful_count + 1 WHERE review_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->review_id, $this->tenant_id);
        return $stmt->execute();
    }

    /**
     * Report review
     */
    public function report(): bool {
        $sql = "UPDATE learning_path_reviews SET 
                reported_count = reported_count + 1,
                status = CASE WHEN reported_count >= 3 THEN 'flagged' ELSE status END,
                updated_at = NOW()
                WHERE review_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->review_id, $this->tenant_id);
        return $stmt->execute();
    }

    /**
     * Delete review (soft delete)
     */
    public function delete(): bool {
        // Get path_id before deleting for rating update
        $sql = "SELECT path_id FROM learning_path_reviews WHERE review_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->review_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        if ($result) {
            $this->path_id = $result['path_id'];
        }

        $sql = "UPDATE learning_path_reviews SET is_active = 0, updated_at = NOW() WHERE review_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $this->review_id, $this->tenant_id);

        if ($stmt->execute()) {
            $this->updatePathRating();
            return true;
        }
        return false;
    }

    /**
     * Get pending reviews for moderation
     */
    public function getPendingReviews(): array {
        $offset = ($this->page - 1) * $this->per_page;

        $countSql = "SELECT COUNT(*) as total FROM learning_path_reviews 
                     WHERE status = 'pending' AND tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($countSql);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];

        $sql = "SELECT r.*, 
                u.first_name, u.last_name, u.email, u.avatar as avatar,
                lp.title as path_title
                FROM learning_path_reviews r
                JOIN users u ON r.user_id = u.id
                JOIN learning_paths lp ON r.path_id = lp.path_id
                WHERE r.status = 'pending' AND r.tenant_id = ? AND r.is_active = 1
                ORDER BY r.created_at ASC
                LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $reviews = [];
        while ($row = $result->fetch_assoc()) {
            $reviews[] = $row;
        }

        return [
            'data' => $reviews,
            'pagination' => [
                'total' => (int)$total,
                'page' => $this->page,
                'per_page' => $this->per_page,
                'total_pages' => ceil($total / $this->per_page)
            ]
        ];
    }

    /**
     * Get flagged reviews (reported 3+ times)
     */
    public function getFlaggedReviews(): array {
        $offset = ($this->page - 1) * $this->per_page;

        $sql = "SELECT r.*, 
                u.first_name, u.last_name, u.email,
                lp.title as path_title
                FROM learning_path_reviews r
                JOIN users u ON r.user_id = u.id
                JOIN learning_paths lp ON r.path_id = lp.path_id
                WHERE r.status = 'flagged' AND r.tenant_id = ? AND r.is_active = 1
                ORDER BY r.reported_count DESC, r.created_at DESC
                LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $reviews = [];
        while ($row = $result->fetch_assoc()) {
            $reviews[] = $row;
        }

        return $reviews;
    }

    /**
     * Update path rating after review changes
     */
    private function updatePathRating(): void {
        $sql = "UPDATE learning_paths SET 
                avg_rating = (SELECT AVG(rating) FROM learning_path_reviews WHERE path_id = ? AND status = 'approved' AND is_active = 1),
                total_ratings = (SELECT COUNT(*) FROM learning_path_reviews WHERE path_id = ? AND status = 'approved' AND is_active = 1),
                updated_at = NOW()
                WHERE path_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iii", $this->path_id, $this->path_id, $this->path_id);
        $stmt->execute();
    }
}