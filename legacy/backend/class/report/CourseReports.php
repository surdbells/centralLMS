<?php

namespace report;

require_once __DIR__ . '/BaseReport.php';
class CourseReports extends BaseReport
{
    public function generate(): array
    {
        return $this->courseCatalogReport();
    }
    public function courseCatalogReport(): array
    {
        $query = "SELECT c.course_id, c.title, c.description, c.level, c.duration_hours,
                    c.is_active, c.created_at, cat.name as category,
                    COUNT(DISTINCT ce.user_id) as total_enrolled,
                    AVG(cr.rating) as avg_rating,
                    COUNT(cr.review_id) as review_count,
                    CONCAT(u.first_name, ' ', u.last_name) as created_by
                  FROM courses c
                  LEFT JOIN categories cat ON c.category_id = cat.category_id
                  LEFT JOIN course_enrollments ce ON c.course_id = ce.course_id AND ce.is_active = 1
                  LEFT JOIN course_reviews cr ON c.course_id = cr.course_id
                  LEFT JOIN users u ON c.created_by = u.id
                  WHERE c.tenant_id = ?
                  GROUP BY c.course_id, c.title, c.description, c.level, c.duration_hours,
                           c.is_active, c.created_at, cat.name, u.first_name, u.last_name
                  ORDER BY c.created_at DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'course_id' => (int)$row['course_id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'category' => $row['category'] ?? 'Uncategorized',
                'level' => $row['level'],
                'duration_hours' => (float)$row['duration_hours'],
                'is_active' => (bool)$row['is_active'],
                'status' => $row['is_active'] ? 'Published' : 'Draft',
                'created_at' => $row['created_at'],
                'created_by' => $row['created_by'] ?? 'System',
                'total_enrolled' => (int)$row['total_enrolled'],
                'avg_rating' => round((float)($row['avg_rating'] ?? 0), 1),
                'review_count' => (int)$row['review_count']
            ];
        }

        $countQuery = "SELECT COUNT(*) as total FROM courses WHERE tenant_id = ?";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'Course Catalog Report',
            'data' => $data,
            'meta' => $this->buildMeta($total)
        ];
    }

    /**
     * Report 37: Course Effectiveness Report
     */
    public function courseEffectivenessReport(): array
    {
        $query = "SELECT c.course_id, c.title, c.duration_hours,
                    COUNT(ce.enrollment_id) as total_enrolled,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    AVG(ce.final_score) as avg_score,
                    AVG(ce.time_spent_minutes) as avg_time_spent,
                    AVG(CASE WHEN ce.status = 'completed' THEN DATEDIFF(ce.completed_at, ce.enrolled_at) END) as avg_days_to_complete,
                    AVG(cr.rating) as avg_rating,
                    COUNT(DISTINCT cr.review_id) as feedback_count,
                    SUM(CASE WHEN ce.status = 'in_progress' AND ce.progress_percentage < 25 THEN 1 ELSE 0 END) as early_dropoffs
                  FROM courses c
                  LEFT JOIN course_enrollments ce ON c.course_id = ce.course_id AND ce.is_active = 1
                  LEFT JOIN course_reviews cr ON c.course_id = cr.course_id
                  WHERE c.tenant_id = ? AND c.is_active = 1
                  GROUP BY c.course_id, c.title, c.duration_hours
                  HAVING total_enrolled >= 5
                  ORDER BY avg_score DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $enrolled = (int)$row['total_enrolled'];
            $completed = (int)$row['completed'];
            $dropoffs = (int)$row['early_dropoffs'];

            // Calculate effectiveness score
            $completionRate = $enrolled > 0 ? ($completed / $enrolled) * 100 : 0;
            $avgScore = (float)($row['avg_score'] ?? 0);
            $rating = (float)($row['avg_rating'] ?? 0);
            $effectivenessScore = ($completionRate * 0.4) + ($avgScore * 0.4) + ($rating * 4); // Weighted score

            $data[] = [
                'course_id' => (int)$row['course_id'],
                'title' => $row['title'],
                'expected_hours' => (float)$row['duration_hours'],
                'total_enrolled' => $enrolled,
                'completed' => $completed,
                'completion_rate' => round($completionRate, 1),
                'avg_score' => round($avgScore, 1),
                'avg_rating' => round($rating, 1),
                'avg_time_spent' => $this->formatDuration((int)($row['avg_time_spent'] ?? 0)),
                'avg_days_to_complete' => round((float)($row['avg_days_to_complete'] ?? 0), 1),
                'feedback_count' => (int)$row['feedback_count'],
                'early_dropoff_rate' => $enrolled > 0 ? round(($dropoffs / $enrolled) * 100, 1) : 0,
                'effectiveness_score' => round($effectivenessScore, 1)
            ];
        }

        // Sort by effectiveness score
        usort($data, fn($a, $b) => $b['effectiveness_score'] <=> $a['effectiveness_score']);

        return [
            'report_name' => 'Course Effectiveness Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 38: Course Usage Report
     */
    public function courseUsageReport(): array
    {
        $dateFilter = $this->buildDateFilter('ls.started_at');

        $query = "SELECT c.course_id, c.title,
                    COUNT(DISTINCT ce.user_id) as unique_learners,
                    COUNT(DISTINCT ls.session_id) as total_sessions,
                    SUM(ls.duration_minutes) as total_time,
                    AVG(ls.duration_minutes) as avg_session_duration,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completions,
                    SUM(CASE WHEN ce.status IN ('not_started', 'in_progress') AND ce.progress_percentage < 10 THEN 1 ELSE 0 END) as abandoned
                  FROM courses c
                  LEFT JOIN course_enrollments ce ON c.course_id = ce.course_id AND ce.is_active = 1
                  LEFT JOIN learning_sessions ls ON c.course_id = ls.course_id $dateFilter
                  WHERE c.tenant_id = ? AND c.is_active = 1
                  GROUP BY c.course_id, c.title
                  ORDER BY total_sessions DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $enrolled = (int)$row['unique_learners'];
            $completed = (int)$row['completions'];
            $abandoned = (int)$row['abandoned'];

            $data[] = [
                'course_id' => (int)$row['course_id'],
                'title' => $row['title'],
                'unique_learners' => $enrolled,
                'total_sessions' => (int)$row['total_sessions'],
                'total_learning_time' => $this->formatDuration((int)$row['total_time']),
                'avg_session_duration' => $this->formatDuration((int)($row['avg_session_duration'] ?? 0)),
                'completions' => $completed,
                'completion_rate' => $enrolled > 0 ? round(($completed / $enrolled) * 100, 1) : 0,
                'abandoned' => $abandoned,
                'abandonment_rate' => $enrolled > 0 ? round(($abandoned / $enrolled) * 100, 1) : 0
            ];
        }

        return [
            'report_name' => 'Course Usage Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 39: Module Performance Report
     */
    public function modulePerformanceReport(int $course_id): array
    {
        $query = "SELECT m.module_id, m.title as module_title,
                    COUNT(DISTINCT lp.user_id) as users_started,
                    SUM(CASE WHEN lp.status = 'completed' THEN 1 ELSE 0 END) as users_completed,
                    AVG(lp.progress_percentage) as avg_progress,
                    SUM(lp.time_spent_minutes) as total_time,
                    AVG(lp.time_spent_minutes) as avg_time
                  FROM modules m
                  LEFT JOIN lesson_progress lp ON m.module_id = (
                    SELECT module_id FROM lessons WHERE lesson_id = lp.lesson_id
                  )
                  WHERE m.course_id = ? AND m.tenant_id = ? AND m.is_active = 1
                  GROUP BY m.module_id, m.title
                  ORDER BY m.module_id";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $course_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        $prevCompleted = null;
        while ($row = $result->fetch_assoc()) {
            $started = (int)$row['users_started'];
            $completed = (int)$row['users_completed'];

            // Calculate drop-off from previous module
            $dropoff = 0;
            if ($prevCompleted !== null && $prevCompleted > 0) {
                $dropoff = round((($prevCompleted - $started) / $prevCompleted) * 100, 1);
            }
            $prevCompleted = $completed;

            $data[] = [
                'module_id' => (int)$row['module_id'],
                'module_title' => $row['module_title'],
                'users_started' => $started,
                'users_completed' => $completed,
                'completion_rate' => $started > 0 ? round(($completed / $started) * 100, 1) : 0,
                'avg_progress' => round((float)($row['avg_progress'] ?? 0), 1),
                'total_time' => $this->formatDuration((int)$row['total_time']),
                'avg_time' => $this->formatDuration((int)($row['avg_time'] ?? 0)),
                'dropoff_rate' => max(0, $dropoff)
            ];
        }

        // Get course info
        $courseQuery = "SELECT title FROM courses WHERE course_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($courseQuery);
        $stmt->bind_param("ii", $course_id, $this->tenant_id);
        $stmt->execute();
        $course = $stmt->get_result()->fetch_assoc();

        return [
            'report_name' => 'Module Performance Report',
            'course' => [
                'course_id' => $course_id,
                'title' => $course['title'] ?? 'Unknown'
            ],
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 40: Content Engagement Report
     */
    public function contentEngagementReport(): array
    {
        $query = "SELECT l.lesson_id, l.title as lesson_title, l.content_type,
                    m.title as module_title, c.title as course_title,
                    COUNT(DISTINCT lp.user_id) as views,
                    SUM(lp.time_spent_minutes) as total_time,
                    AVG(lp.time_spent_minutes) as avg_time,
                    SUM(CASE WHEN lp.status = 'completed' THEN 1 ELSE 0 END) as completions,
                    SUM(lp.replay_count) as replay_count
                  FROM lessons l
                  JOIN modules m ON l.module_id = m.module_id
                  JOIN courses c ON m.course_id = c.course_id
                  LEFT JOIN lesson_progress lp ON l.lesson_id = lp.lesson_id
                  WHERE c.tenant_id = ? AND l.is_active = 1
                  GROUP BY l.lesson_id, l.title, l.content_type, m.title, c.title
                  ORDER BY views DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $views = (int)$row['views'];
            $completions = (int)$row['completions'];

            $data[] = [
                'lesson_id' => (int)$row['lesson_id'],
                'lesson_title' => $row['lesson_title'],
                'content_type' => $row['content_type'],
                'module_title' => $row['module_title'],
                'course_title' => $row['course_title'],
                'views' => $views,
                'completions' => $completions,
                'completion_rate' => $views > 0 ? round(($completions / $views) * 100, 1) : 0,
                'total_time' => $this->formatDuration((int)$row['total_time']),
                'avg_time' => $this->formatDuration((int)($row['avg_time'] ?? 0)),
                'replay_count' => (int)($row['replay_count'] ?? 0)
            ];
        }

        return [
            'report_name' => 'Content Engagement Report',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 41: Course Ratings & Feedback
     */
    public function courseRatingsFeedback(): array
    {
        $query = "SELECT c.course_id, c.title,
                    COUNT(cr.review_id) as total_reviews,
                    AVG(cr.rating) as avg_rating,
                    SUM(CASE WHEN cr.rating = 5 THEN 1 ELSE 0 END) as five_star,
                    SUM(CASE WHEN cr.rating = 4 THEN 1 ELSE 0 END) as four_star,
                    SUM(CASE WHEN cr.rating = 3 THEN 1 ELSE 0 END) as three_star,
                    SUM(CASE WHEN cr.rating = 2 THEN 1 ELSE 0 END) as two_star,
                    SUM(CASE WHEN cr.rating = 1 THEN 1 ELSE 0 END) as one_star
                  FROM courses c
                  LEFT JOIN course_reviews cr ON c.course_id = cr.course_id
                  WHERE c.tenant_id = ? AND c.is_active = 1
                  GROUP BY c.course_id, c.title
                  HAVING total_reviews > 0
                  ORDER BY avg_rating DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iii", $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $total = (int)$row['total_reviews'];
            $data[] = [
                'course_id' => (int)$row['course_id'],
                'title' => $row['title'],
                'total_reviews' => $total,
                'avg_rating' => round((float)$row['avg_rating'], 1),
                'rating_distribution' => [
                    '5_star' => (int)$row['five_star'],
                    '4_star' => (int)$row['four_star'],
                    '3_star' => (int)$row['three_star'],
                    '2_star' => (int)$row['two_star'],
                    '1_star' => (int)$row['one_star']
                ],
                'positive_rate' => $total > 0 ? round(((int)$row['five_star'] + (int)$row['four_star']) / $total * 100, 1) : 0
            ];
        }

        return [
            'report_name' => 'Course Ratings & Feedback',
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 42: Unused Courses Report
     */
    public function unusedCoursesReport(int $days = 90): array
    {
        $query = "SELECT c.course_id, c.title, c.created_at, c.is_active,
                    CONCAT(u.first_name, ' ', u.last_name) as created_by,
                    COUNT(ce.enrollment_id) as total_enrollments,
                    MAX(ce.enrolled_at) as last_enrollment
                  FROM courses c
                  LEFT JOIN users u ON c.created_by = u.id
                  LEFT JOIN course_enrollments ce ON c.course_id = ce.course_id AND ce.is_active = 1
                  WHERE c.tenant_id = ?
                  GROUP BY c.course_id, c.title, c.created_at, c.is_active, u.first_name, u.last_name
                  HAVING total_enrollments = 0 
                  OR last_enrollment < DATE_SUB(CURDATE(), INTERVAL ? DAY)
                  ORDER BY last_enrollment ASC, c.created_at ASC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $this->tenant_id, $days, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $lastEnroll = $row['last_enrollment'];
            $daysSinceEnroll = $lastEnroll ?
                (new DateTime())->diff(new DateTime($lastEnroll))->days : null;

            $data[] = [
                'course_id' => (int)$row['course_id'],
                'title' => $row['title'],
                'created_at' => $row['created_at'],
                'created_by' => $row['created_by'] ?? 'System',
                'is_active' => (bool)$row['is_active'],
                'total_enrollments' => (int)$row['total_enrollments'],
                'last_enrollment' => $lastEnroll ?? 'Never',
                'days_since_enrollment' => $daysSinceEnroll ?? 'N/A',
                'recommendation' => (int)$row['total_enrollments'] === 0 ? 'Consider removing' : 'Review content'
            ];
        }

        return [
            'report_name' => 'Unused Courses Report',
            'inactive_threshold_days' => $days,
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    /**
     * Report 43: Course Version History
     */
    public function courseVersionHistory(int $course_id): array
    {
        $query = "SELECT cv.version_id, cv.version_number, cv.change_summary,
                    cv.created_at, CONCAT(u.first_name, ' ', u.last_name) as updated_by,
                    cv.changes_json
                  FROM course_versions cv
                  LEFT JOIN users u ON cv.updated_by = u.id
                  WHERE cv.course_id = ? AND cv.tenant_id = ?
                  ORDER BY cv.version_number DESC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $course_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'version_id' => (int)$row['version_id'],
                'version_number' => $row['version_number'],
                'change_summary' => $row['change_summary'],
                'updated_at' => $row['created_at'],
                'updated_by' => $row['updated_by'] ?? 'System',
                'changes' => json_decode($row['changes_json'] ?? '{}', true)
            ];
        }

        // Get course info
        $courseQuery = "SELECT title FROM courses WHERE course_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($courseQuery);
        $stmt->bind_param("ii", $course_id, $this->tenant_id);
        $stmt->execute();
        $course = $stmt->get_result()->fetch_assoc();

        return [
            'report_name' => 'Course Version History',
            'course' => [
                'course_id' => $course_id,
                'title' => $course['title'] ?? 'Unknown'
            ],
            'data' => $data,
            'meta' => $this->buildMeta(count($data))
        ];
    }

    public function getSummary(): array
    {
        $query = "SELECT 
                    COUNT(*) as total_courses,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as published,
                    SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as drafts
                  FROM courses WHERE tenant_id = ?";

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
}
