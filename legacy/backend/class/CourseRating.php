<?php

/**
 * CourseRating - Manages course ratings and reviews
 * Used for course effectiveness and feedback reports
 */
class CourseRating {
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
     * Submit or update a course rating
     */
    public function submitRating(
        int $user_id,
        int $course_id,
        int $rating,
        ?string $review_text = null,
        bool $is_anonymous = false
    ): array {
        // Validate rating
        if ($rating < 1 || $rating > 5) {
            return ['success' => false, 'message' => 'Rating must be between 1 and 5'];
        }

        // Check if user has completed the course
        if (!$this->hasCompletedCourse($user_id, $course_id)) {
            return ['success' => false, 'message' => 'You must complete the course before rating'];
        }

        // Check for existing rating
        $existing = $this->getUserRating($user_id, $course_id);
        $is_anon_int = $is_anonymous ? 1 : 0;

        if ($existing) {
            // Update existing rating
            $query = "UPDATE course_reviews 
                      SET rating = ?, review_text = ?, is_anonymous = ?, updated_at = NOW()
                      WHERE user_id = ? AND course_id = ? AND tenant_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("isiiii", $rating, $review_text, $is_anon_int, $user_id, $course_id, $this->tenant_id);
        } else {
            // Insert new rating
            $query = "INSERT INTO course_reviews 
                      (tenant_id, course_id, user_id, rating, review_text, is_anonymous, is_approved, created_at)
                      VALUES (?, ?, ?, ?, ?, ?, 1, NOW())";
            $stmt = $this->conn->prepare($query);
            $stmt->bind_param("iiiisi", $this->tenant_id, $course_id, $user_id, $rating, $review_text, $is_anon_int);
        }

        if ($stmt->execute()) {
            // Update course average rating cache if exists
            $this->updateCourseAverageRating($course_id);
            
            return [
                'success' => true,
                'rating_id' => $existing['id'] ?? $this->conn->insert_id,
                'average_rating' => $this->getCourseAverageRating($course_id)
            ];
        }

        return ['success' => false, 'message' => 'Failed to submit rating'];
    }

    /**
     * Get user's rating for a course
     */
    public function getUserRating(int $user_id, int $course_id): ?array {
        $query = "SELECT * FROM course_reviews 
                  WHERE user_id = ? AND course_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $user_id, $course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    /**
     * Get course ratings
     */
    public function getCourseRatings(int $course_id, bool $approved_only = true, int $page = 1, int $per_page = 20): array {
        $query = "SELECT cr.review_id, cr.rating, cr.review_text, cr.is_anonymous, cr.created_at,
                         CASE WHEN cr.is_anonymous = 1 THEN 'Anonymous' 
                              ELSE CONCAT(u.first_name, ' ', u.last_name) END as reviewer_name,
                         CASE WHEN cr.is_anonymous = 1 THEN NULL ELSE u.avatar END as avatar_url
                  FROM course_reviews cr
                  JOIN users u ON cr.user_id = u.id
                  WHERE cr.course_id = ? AND cr.tenant_id = ?";
        
        if ($approved_only) {
            $query .= " AND cr.is_approved = 1";
        }

        $query .= " ORDER BY cr.created_at DESC LIMIT ? OFFSET ?";
        
        $offset = ($page - 1) * $per_page;
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iiii", $course_id, $this->tenant_id, $per_page, $offset);
        $stmt->execute();
        
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get course average rating
     */
    public function getCourseAverageRating(int $course_id): array {
        $query = "SELECT 
                    COUNT(*) as total_ratings,
                    AVG(rating) as average_rating,
                    SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star,
                    SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_star,
                    SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_star,
                    SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_star,
                    SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star
                  FROM course_reviews 
                  WHERE course_id = ? AND tenant_id = ? AND is_approved = 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();

        return [
            'total_ratings' => (int) $result['total_ratings'],
            'average_rating' => round((float) $result['average_rating'], 1),
            'distribution' => [
                '5_star' => (int) $result['five_star'],
                '4_star' => (int) $result['four_star'],
                '3_star' => (int) $result['three_star'],
                '2_star' => (int) $result['two_star'],
                '1_star' => (int) $result['one_star']
            ]
        ];
    }

    /**
     * Update course's cached average rating
     */
    private function updateCourseAverageRating(int $course_id): void {
        $avg = $this->getCourseAverageRating($course_id);
        
        // Update courses table if it has rating columns
        $query = "UPDATE courses SET 
                    average_rating = ?, 
                    total_ratings = ?
                  WHERE course_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        if ($stmt) {
            $stmt->bind_param("diii", $avg['average_rating'], $avg['total_ratings'], $course_id, $this->tenant_id);
            $stmt->execute();
        }
    }

    /**
     * Approve/reject a rating (admin function)
     */
    public function moderateRating(int $rating_id, bool $approved): bool {
        $query = "UPDATE course_reviews SET is_approved = ? WHERE review_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $approved_int = $approved ? 1 : 0;
        $stmt->bind_param("iii", $approved_int, $rating_id, $this->tenant_id);
        return $stmt->execute();
    }

    /**
     * Delete a rating
     */
    public function deleteRating(int $rating_id, int $user_id): bool {
        $query = "DELETE FROM course_reviews WHERE review_id = ? AND user_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $rating_id, $user_id, $this->tenant_id);
        return $stmt->execute() && $stmt->affected_rows > 0;
    }

    /**
     * Get ratings pending moderation
     */
    public function getPendingRatings(): array {
        $query = "SELECT cr.*, c.title as course_title,
                         CONCAT(u.first_name, ' ', u.last_name) as reviewer_name
                  FROM course_reviews cr
                  JOIN courses c ON cr.course_id = c.course_id
                  JOIN users u ON cr.user_id = u.id
                  WHERE cr.tenant_id = ? AND cr.is_approved = 0
                  ORDER BY cr.created_at ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Get all course ratings summary (for reports)
     */
    public function getAllCoursesRatingSummary(): array {
        $query = "SELECT c.course_id, c.title as course_title, c.category_id,
                         COUNT(cr.review_id) as total_ratings,
                         AVG(cr.rating) as average_rating,
                         MIN(cr.rating) as min_rating,
                         MAX(cr.rating) as max_rating
                  FROM courses c
                  LEFT JOIN course_reviews cr ON c.course_id = cr.course_id AND cr.is_approved = 1
                  WHERE c.tenant_id = ? AND c.is_active = 1
                  GROUP BY c.course_id
                  ORDER BY average_rating DESC";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Check if user has completed the course
     */
    private function hasCompletedCourse(int $user_id, int $course_id): bool {
        $query = "SELECT enrollment_id FROM course_enrollments 
                  WHERE user_id = ? AND course_id = ? AND tenant_id = ? AND status = 'completed'";
        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("iii", $user_id, $course_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }
}
