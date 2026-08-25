<?php
require_once __DIR__ . '/BaseReport.php';
class CourseReports extends BaseReport
{
    public function generate(): array
    {
        return $this->courseCatalogReport();
    }
    public function courseCatalogReport(): array
    {
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT c.course_id, c.title, c.description, c.level, c.duration_minutes,
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
                  {$orgFilter}
                  GROUP BY c.course_id, c.title, c.description, c.level, c.duration_minutes,
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
                'duration_minutes' => (float)$row['duration_minutes'],
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
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT c.course_id, c.title, c.duration_minutes,
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
                  JOIN users u ON ce.user_id = u.id
                  WHERE c.tenant_id = ? AND c.is_active = 1
                  {$orgFilter}
                  GROUP BY c.course_id, c.title, c.duration_minutes
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
                'expected_hours' => (float)$row['duration_minutes'],
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

        $orgFilter = $this->buildOrgFilter();

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
                  JOIN users u ON ce.user_id = u.id
                  WHERE c.tenant_id = ? AND c.is_active = 1
                  {$orgFilter}
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
        $orgFilter = $this->buildOrgFilter();

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
                  JOIN users u ON lp.user_id = u.id
                  WHERE m.course_id = ? AND m.tenant_id = ? AND m.is_active = 1
                  {$orgFilter}
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
        $orgFilter = $this->buildOrgFilter();

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
                  JOIN users u ON lp.user_id = u.id
                  WHERE c.tenant_id = ? AND l.is_active = 1
                  {$orgFilter}
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
        $orgFilter = $this->buildOrgFilter();

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
                  JOIN users u ON cr.user_id = u.id
                  WHERE c.tenant_id = ? AND c.is_active = 1
                  {$orgFilter}
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
        $orgFilter = $this->buildOrgFilter();

        $query = "SELECT c.course_id, c.title, c.created_at, c.is_active,
                    CONCAT(u.first_name, ' ', u.last_name) as created_by,
                    COUNT(ce.enrollment_id) as total_enrollments,
                    MAX(ce.enrolled_at) as last_enrollment
                  FROM courses c
                  LEFT JOIN users u ON c.created_by = u.id
                  LEFT JOIN course_enrollments ce ON c.course_id = ce.course_id AND ce.is_active = 1
                  WHERE c.tenant_id = ?
                  {$orgFilter}
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
    /*public function courseVersionHistory(int $course_id): array
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
    }*/

    /**
     * Report: SCORM/xAPI Activity Report
     * Learning session activity grouped by course and content type
     */
    public function scormXapiActivityReport(): array
    {
        $dateFilter = $this->buildDateFilter('ls.started_at');
        $orgFilter = $this->buildOrgFilter();
        $orgJoins = $this->buildOrgJoins();

        $query = "SELECT c.course_id, c.title as course_title, c.course_type,
                    COUNT(ls.session_id) as total_sessions,
                    COUNT(DISTINCT ls.user_id) as unique_learners,
                    SUM(ls.time_spent_minutes) as total_time_minutes,
                    ROUND(AVG(ls.time_spent_minutes), 1) as avg_session_minutes,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completions,
                    ROUND(AVG(ce.final_score), 1) as avg_score,
                    MAX(ls.started_at) as last_activity
                  FROM learning_sessions ls
                  JOIN courses c ON ls.course_id = c.course_id
                  JOIN users u ON ls.user_id = u.id
                  LEFT JOIN course_enrollments ce ON ce.user_id = ls.user_id AND ce.course_id = ls.course_id AND ce.is_active = 1
                  {$orgJoins}
                  WHERE ls.tenant_id = ? {$dateFilter} {$orgFilter}
                  GROUP BY c.course_id, c.title, c.course_type
                  ORDER BY total_sessions DESC
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
                'course_title' => $row['course_title'],
                'course_type' => $row['course_type'],
                'total_sessions' => (int)$row['total_sessions'],
                'unique_learners' => (int)$row['unique_learners'],
                'total_time' => $this->formatDuration((int)$row['total_time_minutes']),
                'avg_session_minutes' => round((float)($row['avg_session_minutes'] ?? 0), 1),
                'completions' => (int)$row['completions'],
                'avg_score' => round((float)($row['avg_score'] ?? 0), 1),
                'last_activity' => $row['last_activity']
            ];
        }
        $stmt->close();

        $countQuery = "SELECT COUNT(DISTINCT c.course_id) as total
                       FROM learning_sessions ls
                       JOIN courses c ON ls.course_id = c.course_id
                       JOIN users u ON ls.user_id = u.id
                       {$orgJoins}
                       WHERE ls.tenant_id = ? {$dateFilter} {$orgFilter}";
        $total = $this->getTotalCount($countQuery, "i", [$this->tenant_id]);

        return [
            'report_name' => 'SCORM/xAPI Activity Report',
            'data' => $data,
            'meta' => $this->buildMeta($total),
            'summary' => [
                'total_sessions' => array_sum(array_column($data, 'total_sessions')),
                'unique_learners' => array_sum(array_column($data, 'unique_learners')),
                'total_completions' => array_sum(array_column($data, 'completions'))
            ]
        ];
    }

    // =========================================================================
    // Phase 3b: Course Profile & Course Learner Roster
    // =========================================================================

    /**
     * Course Profile — Complete single-course overview with org breakdowns
     *
     * Returns: course info, enrollment summary, completion stats,
     * breakdown by department, by branch, and top 20 recent learners.
     *
     * Usage: action=run&report=course_profile&course_id=10
     *   Optional: &department_id=5&branch_id=2&team_id=3
     */
    public function courseProfile(int $course_id): array
    {
        $orgFilter = $this->buildOrgFilter();
        $orgJoins = $this->buildOrgJoins('u', true);

        // ── 1. Course info ──
        $infoQuery = "SELECT c.course_id, c.title, c.description, c.level,
                        c.duration_minutes, c.is_active, c.created_at, c.course_type,
                        cat.name as category,
                        CONCAT(cr_by.first_name, ' ', cr_by.last_name) as created_by
                      FROM courses c
                      LEFT JOIN categories cat ON c.category_id = cat.category_id
                      LEFT JOIN users cr_by ON c.created_by = cr_by.id
                      WHERE c.course_id = ? AND c.tenant_id = ?";

        $stmt = $this->conn->prepare($infoQuery);
        $stmt->bind_param("ii", $course_id, $this->tenant_id);
        $stmt->execute();
        $courseInfo = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$courseInfo) {
            return ['report_name' => 'Course Profile', 'error' => 'Course not found'];
        }

        // ── 2. Enrollment summary ──
        $summaryQuery = "SELECT
                    COUNT(ce.enrollment_id) as total_enrolled,
                    COUNT(DISTINCT ce.user_id) as unique_learners,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                    SUM(CASE WHEN ce.status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    SUM(CASE WHEN ce.is_mandatory = 1 THEN 1 ELSE 0 END) as mandatory_count,
                    ROUND(AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score END), 1) as avg_score,
                    ROUND(AVG(CASE WHEN ce.status = 'completed' THEN ce.time_spent_minutes END), 0) as avg_time_minutes,
                    ROUND(AVG(CASE WHEN ce.status = 'completed' THEN DATEDIFF(ce.completed_at, ce.enrolled_at) END), 1) as avg_days_to_complete,
                    MIN(ce.enrolled_at) as first_enrollment,
                    MAX(ce.completed_at) as last_completion
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  {$orgJoins}
                  WHERE ce.course_id = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND u.is_active = 1
                  {$orgFilter}";

        $stmt = $this->conn->prepare($summaryQuery);
        $stmt->bind_param("ii", $course_id, $this->tenant_id);
        $stmt->execute();
        $s = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $totalEnrolled = (int)$s['total_enrolled'];
        $totalCompleted = (int)$s['completed'];

        $summary = [
            'total_enrolled' => $totalEnrolled,
            'unique_learners' => (int)$s['unique_learners'],
            'completed' => $totalCompleted,
            'in_progress' => (int)$s['in_progress'],
            'not_started' => (int)$s['not_started'],
            'overdue' => (int)$s['overdue'],
            'mandatory_count' => (int)$s['mandatory_count'],
            'completion_rate' => $totalEnrolled > 0 ? round(($totalCompleted / $totalEnrolled) * 100, 1) : 0,
            'avg_score' => round((float)($s['avg_score'] ?? 0), 1),
            'avg_time' => $this->formatDuration((int)($s['avg_time_minutes'] ?? 0)),
            'avg_days_to_complete' => round((float)($s['avg_days_to_complete'] ?? 0), 1),
            'first_enrollment' => $s['first_enrollment'],
            'last_completion' => $s['last_completion']
        ];

        // ── 3. By Department ──
        $deptQuery = "SELECT d.department_id, d.department_name,
                    COUNT(ce.enrollment_id) as enrolled,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    ROUND(AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score END), 1) as avg_score
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  LEFT JOIN departments d ON u.department = d.department_id
                  WHERE ce.course_id = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND u.is_active = 1
                  GROUP BY d.department_id, d.department_name
                  ORDER BY overdue DESC, completed DESC";

        $stmt = $this->conn->prepare($deptQuery);
        $stmt->bind_param("ii", $course_id, $this->tenant_id);
        $stmt->execute();
        $deptRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $byDepartment = [];
        foreach ($deptRows as $row) {
            $enrolled = (int)$row['enrolled'];
            $completed = (int)$row['completed'];
            $byDepartment[] = [
                'department_id' => (int)$row['department_id'],
                'department_name' => $row['department_name'] ?? 'Unassigned',
                'enrolled' => $enrolled,
                'completed' => $completed,
                'overdue' => (int)$row['overdue'],
                'completion_rate' => $enrolled > 0 ? round(($completed / $enrolled) * 100, 1) : 0,
                'avg_score' => round((float)($row['avg_score'] ?? 0), 1)
            ];
        }

        // ── 4. By Branch ──
        $branchQuery = "SELECT b.branch_id, b.branch_name,
                    COUNT(ce.enrollment_id) as enrolled,
                    SUM(CASE WHEN ce.status = 'completed' THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE() THEN 1 ELSE 0 END) as overdue,
                    ROUND(AVG(CASE WHEN ce.status = 'completed' THEN ce.final_score END), 1) as avg_score
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  LEFT JOIN branches b ON u.branch = b.branch_id
                  WHERE ce.course_id = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND u.is_active = 1
                  GROUP BY b.branch_id, b.branch_name
                  ORDER BY overdue DESC, completed DESC";

        $stmt = $this->conn->prepare($branchQuery);
        $stmt->bind_param("ii", $course_id, $this->tenant_id);
        $stmt->execute();
        $branchRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $byBranch = [];
        foreach ($branchRows as $row) {
            $enrolled = (int)$row['enrolled'];
            $completed = (int)$row['completed'];
            $byBranch[] = [
                'branch_id' => (int)$row['branch_id'],
                'branch_name' => $row['branch_name'] ?? 'Unassigned',
                'enrolled' => $enrolled,
                'completed' => $completed,
                'overdue' => (int)$row['overdue'],
                'completion_rate' => $enrolled > 0 ? round(($completed / $enrolled) * 100, 1) : 0,
                'avg_score' => round((float)($row['avg_score'] ?? 0), 1)
            ];
        }

        // ── 5. Recent learners (top 20) ──
        $learnerQuery = "SELECT u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, d.department_name,
                    ce.status, ce.enrolled_at, ce.completed_at,
                    ce.progress_percentage, ce.final_score, ce.time_spent_minutes, ce.due_date,
                    CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE()
                         THEN DATEDIFF(CURDATE(), ce.due_date) ELSE NULL END as days_overdue
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  LEFT JOIN departments d ON u.department = d.department_id
                  {$orgJoins}
                  WHERE ce.course_id = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND u.is_active = 1
                  {$orgFilter}
                  ORDER BY ce.enrolled_at DESC
                  LIMIT 20";

        $stmt = $this->conn->prepare($learnerQuery);
        $stmt->bind_param("ii", $course_id, $this->tenant_id);
        $stmt->execute();
        $learnerRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $recentLearners = [];
        foreach ($learnerRows as $row) {
            $recentLearners[] = [
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'department' => $row['department_name'] ?? 'Unassigned',
                'status' => $row['status'],
                'enrolled_at' => $row['enrolled_at'],
                'completed_at' => $row['completed_at'],
                'progress' => round((float)($row['progress_percentage'] ?? 0), 1),
                'score' => $row['final_score'] !== null ? round((float)$row['final_score'], 1) : null,
                'time_spent' => $this->formatDuration((int)$row['time_spent_minutes']),
                'due_date' => $row['due_date'],
                'days_overdue' => $row['days_overdue'] !== null ? (int)$row['days_overdue'] : null
            ];
        }

        return [
            'report_name' => 'Course Profile',
            'course_info' => [
                'course_id' => (int)$courseInfo['course_id'],
                'title' => $courseInfo['title'],
                'description' => $courseInfo['description'],
                'level' => $courseInfo['level'],
                'duration' => $this->formatDuration((int)($courseInfo['duration_minutes'] ?? 0)),
                'course_type' => $courseInfo['course_type'],
                'category' => $courseInfo['category'] ?? 'Uncategorized',
                'status' => $courseInfo['is_active'] ? 'active' : 'inactive',
                'created_at' => $courseInfo['created_at'],
                'created_by' => $courseInfo['created_by']
            ],
            'summary' => $summary,
            'by_department' => $byDepartment,
            'by_branch' => $byBranch,
            'recent_learners' => $recentLearners
        ];
    }

    /**
     * Course Learner Roster — Paginated flat table of every learner in a course
     *
     * Full detail per learner: name, email, staff ID, department, team, branch,
     * manager, status, dates, progress, score, time spent, due date.
     *
     * Usage: action=run&report=course_learner_roster&course_id=10
     *   Optional: &department_id=5&branch_id=2&page=1&per_page=50
     */
    public function courseLearnerRoster(int $course_id): array
    {
        $orgFilter = $this->buildOrgFilter();
        $dateFilter = $this->buildDateFilter('ce.enrolled_at');

        $query = "SELECT u.id as user_id,
                    CONCAT(u.first_name, ' ', u.last_name) as learner_name,
                    u.email, u.staffId, u.job_title,
                    d.department_name, t.team_name, b.branch_name,
                    CONCAT(mgr.first_name, ' ', mgr.last_name) as manager_name,
                    ce.enrollment_id, ce.status, ce.enrolled_at, ce.started_at,
                    ce.completed_at, ce.due_date,
                    ce.progress_percentage, ce.final_score,
                    ce.time_spent_minutes, ce.is_mandatory, ce.enrollment_source,
                    CASE WHEN ce.status != 'completed' AND ce.due_date < CURDATE()
                         THEN DATEDIFF(CURDATE(), ce.due_date) ELSE NULL END as days_overdue,
                    (SELECT COUNT(*) FROM assessment_attempts aa
                     WHERE aa.user_id = u.id AND aa.course_id = ce.course_id) as attempt_count
                  FROM course_enrollments ce
                  JOIN users u ON ce.user_id = u.id
                  LEFT JOIN departments d ON u.department = d.department_id
                  LEFT JOIN teams t ON u.team = t.team_id
                  LEFT JOIN branches b ON u.branch = b.branch_id
                  LEFT JOIN users mgr ON u.manager_id = mgr.id
                  WHERE ce.course_id = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND u.is_active = 1
                    {$dateFilter} {$orgFilter}
                  ORDER BY u.last_name ASC, u.first_name ASC
                  LIMIT ? OFFSET ?";

        $stmt = $this->conn->prepare($query);
        $offset = $this->getOffset();
        $stmt->bind_param("iiii", $course_id, $this->tenant_id, $this->per_page, $offset);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $daysOverdue = $row['days_overdue'] !== null ? (int)$row['days_overdue'] : null;
            $data[] = [
                'user_id' => (int)$row['user_id'],
                'learner_name' => $row['learner_name'],
                'email' => $row['email'],
                'staff_id' => $row['staffId'],
                'job_title' => $row['job_title'],
                'department_name' => $row['department_name'] ?? 'Unassigned',
                'team_name' => $row['team_name'] ?? 'Unassigned',
                'branch_name' => $row['branch_name'] ?? 'Unassigned',
                'manager_name' => $row['manager_name'] ?? null,
                'status' => $row['status'],
                'enrolled_at' => $row['enrolled_at'],
                'started_at' => $row['started_at'],
                'completed_at' => $row['completed_at'],
                'due_date' => $row['due_date'],
                'progress' => round((float)($row['progress_percentage'] ?? 0), 1),
                'score' => $row['final_score'] !== null ? round((float)$row['final_score'], 1) : null,
                'time_spent' => $this->formatDuration((int)$row['time_spent_minutes']),
                'time_spent_minutes' => (int)$row['time_spent_minutes'],
                'is_mandatory' => (bool)$row['is_mandatory'],
                'enrollment_source' => $row['enrollment_source'],
                'attempt_count' => (int)$row['attempt_count'],
                'days_overdue' => $daysOverdue,
                'risk_level' => $daysOverdue !== null
                    ? ($daysOverdue > 60 ? 'critical' : ($daysOverdue > 30 ? 'high' : ($daysOverdue > 7 ? 'medium' : 'low')))
                    : null
            ];
        }
        $stmt->close();

        $countQuery = "SELECT COUNT(*) as total
                       FROM course_enrollments ce
                       JOIN users u ON ce.user_id = u.id
                       WHERE ce.course_id = ? AND ce.tenant_id = ? AND ce.is_active = 1 AND u.is_active = 1
                         {$dateFilter} {$orgFilter}";
        $cstmt = $this->conn->prepare($countQuery);
        $cstmt->bind_param("ii", $course_id, $this->tenant_id);
        $cstmt->execute();
        $total = (int)$cstmt->get_result()->fetch_assoc()['total'];
        $cstmt->close();

        return [
            'report_name' => 'Course Learner Roster',
            'course_id' => $course_id,
            'data' => $data,
            'meta' => $this->buildMeta($total)
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
