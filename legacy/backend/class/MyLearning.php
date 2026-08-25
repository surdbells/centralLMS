<?php

/**
 * MyLearning Manager
 * Handles learner-specific course and learning management
 * For Multi-Tenant LMS Learner Portal
 */

class MyLearning
{
    private mysqli $conn;
    private int $tenant_id;
    private int $user_id;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function setTenant(int $tenant_id): void
    {
        $this->tenant_id = $tenant_id;
    }

    public function setUser(int $user_id): void
    {
        $this->user_id = $user_id;
    }

    // ==========================================
    // DASHBOARD STATS
    // ==========================================

    /**
     * Get learner's learning statistics
     */
    public function getLearningStats(): array
    {
        $stats = [
            'total_enrolled' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'not_started' => 0,
            'overdue' => 0,
            'certificates_earned' => 0,
            'total_points' => 0,
            'current_streak' => 0,
            'total_learning_time_minutes' => 0
        ];

        // Enrollment stats
        $stmt = $this->conn->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'not_started' THEN 1 ELSE 0 END) as not_started,
                SUM(CASE WHEN status != 'completed' AND due_date < CURDATE() THEN 1 ELSE 0 END) as overdue
            FROM course_enrollments
            WHERE tenant_id = ? AND user_id = ? AND is_active = 1 AND status != 'dropped'
        ");
        $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['total_enrolled'] = (int) $result['total'];
        $stats['in_progress'] = (int) $result['in_progress'];
        $stats['completed'] = (int) $result['completed'];
        $stats['not_started'] = (int) $result['not_started'];
        $stats['overdue'] = (int) $result['overdue'];
        $stmt->close();

        // Certificates count
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as total
            FROM certificates
            WHERE tenant_id = ? AND user_id = ?
        ");
        $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['certificates_earned'] = (int) ($result['total'] ?? 0);
        $stmt->close();

        // Gamification stats (if table exists)
        $checkTable = $this->conn->query("SHOW TABLES LIKE 'user_points'");
        if ($checkTable->num_rows > 0) {
            $stmt = $this->conn->prepare("
                SELECT COALESCE(SUM(points), 0) as total_points
                FROM user_points
                WHERE tenant_id = ? AND user_id = ?
            ");
            $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stats['total_points'] = (int) ($result['total_points'] ?? 0);
            $stmt->close();
        }

        // Learning streak (if table exists)
        $checkTable = $this->conn->query("SHOW TABLES LIKE 'user_streaks'");
        if ($checkTable->num_rows > 0) {
            $stmt = $this->conn->prepare("
                SELECT current_streak
                FROM user_streaks
                WHERE tenant_id = ? AND user_id = ?
            ");
            $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stats['current_streak'] = (int) ($result['current_streak'] ?? 0);
            $stmt->close();
        }

        // Total learning time
        $stmt = $this->conn->prepare("
            SELECT COALESCE(SUM(time_spent_seconds), 0) / 60 as total_minutes
            FROM lesson_progress
            WHERE tenant_id = ? AND user_id = ?
        ");
        $stmt->bind_param("ii", $this->tenant_id, $this->user_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stats['total_learning_time_minutes'] = (int) ($result['total_minutes'] ?? 0);
        $stmt->close();

        return $stats;
    }

    // ==========================================
    // ENROLLED COURSES
    // ==========================================

    /**
     * Get all enrolled courses with progress
     * FIXED: Removed correlated subquery, now fetches lesson counts separately
     */
    public function getEnrolledCourses(?string $status = null, ?string $search = null): array
    {
        $sql = "
            SELECT 
                ce.enrollment_id,
                ce.course_id,
                ce.status,
                ce.progress_percentage,
                ce.enrolled_at,
                ce.started_at,
                ce.completed_at,
                ce.due_date,
                ce.is_mandatory,
                ce.last_accessed_at,
                c.title,
                c.description,
                c.duration_minutes,
                c.level,
                cat.name as category_name,
                (SELECT COUNT(*) FROM lessons cl WHERE cl.course_id = c.course_id AND cl.is_active = 1) as total_lessons
            FROM course_enrollments ce
            INNER JOIN courses c ON ce.course_id = c.course_id AND c.is_active = 1
            LEFT JOIN categories cat ON c.category_id = cat.category_id
            WHERE ce.tenant_id = ? AND ce.user_id = ? AND ce.is_active = 1 AND ce.status != 'dropped'
        ";
        $params = [$this->tenant_id, $this->user_id];
        $types = "ii";

        if ($status && $status !== 'all') {
            $sql .= " AND ce.status = ?";
            $params[] = $status;
            $types .= "s";
        }

        if ($search) {
            $sql .= " AND (c.title LIKE ? OR c.description LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= "ss";
        }

        $sql .= " ORDER BY 
            CASE 
                WHEN ce.status = 'in_progress' THEN 1
                WHEN ce.status = 'not_started' AND ce.is_mandatory = 1 THEN 2
                WHEN ce.status = 'not_started' THEN 3
                WHEN ce.status = 'completed' THEN 4
            END,
            ce.last_accessed_at DESC,
            ce.enrolled_at DESC
        ";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $courses = [];
        while ($row = $result->fetch_assoc()) {
            $row['progress_percentage'] = (float) $row['progress_percentage'];
            $row['is_mandatory'] = (bool) $row['is_mandatory'];
            $row['total_lessons'] = (int) $row['total_lessons'];

            // Get completed lessons count for this specific course
            $row['completed_lessons'] = $this->getCompletedLessonsCount($row['course_id']);

            $row['duration_minutes'] = (int) $row['duration_minutes'];
            $row['is_overdue'] = $row['due_date'] && $row['status'] !== 'completed'
                && strtotime($row['due_date']) < strtotime('today');
            $row['days_until_due'] = $row['due_date']
                ? (int) ceil((strtotime($row['due_date']) - time()) / 86400)
                : null;
            $courses[] = $row;
        }
        $stmt->close();

        return $courses;
    }

    /**
     * Get count of completed lessons for a specific course
     */
    private function getCompletedLessonsCount(int $course_id): int
    {
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as completed
            FROM lesson_progress lp
            INNER JOIN lessons l ON lp.lesson_id = l.lesson_id
            WHERE lp.tenant_id = ? AND lp.user_id = ? 
                AND l.course_id = ? AND lp.status = 'completed'
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $course_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($result['completed'] ?? 0);
    }

    /**
     * Get courses currently in progress
     * FIXED: Removed correlated subquery for next lesson
     */
    public function getInProgressCourses(int $limit = 5): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                ce.enrollment_id,
                ce.course_id,
                ce.progress_percentage,
                ce.due_date,
                ce.is_mandatory,
                ce.last_accessed_at,
                c.title,
                c.duration_minutes
            FROM course_enrollments ce
            INNER JOIN courses c ON ce.course_id = c.course_id AND c.is_active = 1
            WHERE ce.tenant_id = ? AND ce.user_id = ? AND ce.is_active = 1 
                AND ce.status = 'in_progress'
            ORDER BY ce.last_accessed_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $courses = [];
        while ($row = $result->fetch_assoc()) {
            $row['progress_percentage'] = (float) $row['progress_percentage'];
            $row['is_mandatory'] = (bool) $row['is_mandatory'];
            $row['is_overdue'] = $row['due_date'] && strtotime($row['due_date']) < strtotime('today');

            // Get next lesson for this course
            $nextLesson = $this->getNextLessonForCourse($row['course_id']);
            $row['next_lesson_id'] = $nextLesson['lesson_id'] ?? null;
            $row['next_lesson_title'] = $nextLesson['title'] ?? null;

            $courses[] = $row;
        }
        $stmt->close();

        return $courses;
    }
    /**
     * Get next incomplete lesson for a course
     */
    private function getNextLessonForCourse(int $course_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT cl.lesson_id, cl.title
            FROM lessons cl
            LEFT JOIN lesson_progress lp ON cl.lesson_id = lp.lesson_id 
                AND lp.user_id = ? AND lp.tenant_id = ?
            WHERE cl.course_id = ? AND cl.is_active = 1 
                AND (lp.progress_id IS NULL OR lp.status != 'completed')
            ORDER BY cl.sequence_order
            LIMIT 1
        ");
        $stmt->bind_param("iii", $this->user_id, $this->tenant_id, $course_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $lesson = $result->fetch_assoc();
        $stmt->close();

        return $lesson;
    }

    /**
     * Get recently completed courses
     * FIXED: Removed correlated subquery for certificate
     */
    public function getCompletedCourses(int $limit = 10): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                ce.enrollment_id,
                ce.course_id,
                ce.completed_at,
                c.title,
                c.duration_minutes,
                cat.name as category_name
            FROM course_enrollments ce
            INNER JOIN courses c ON ce.course_id = c.course_id
            LEFT JOIN categories cat ON c.category_id = cat.category_id
            WHERE ce.tenant_id = ? AND ce.user_id = ? AND ce.is_active = 1 
                AND ce.status = 'completed'
            ORDER BY ce.completed_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $courses = [];
        while ($row = $result->fetch_assoc()) {
            // Get certificate for this course separately
            $certificate = $this->getCourseCertificateId($row['course_id']);
            $row['certificate_id'] = $certificate;
            $row['has_certificate'] = !empty($certificate);
            $courses[] = $row;
        }
        $stmt->close();

        return $courses;
    }

    /**
     * Get certificate ID for a course
     */
    private function getCourseCertificateId(int $course_id): ?int
    {
        $stmt = $this->conn->prepare("
            SELECT certificate_id
            FROM certificates
            WHERE tenant_id = ? AND user_id = ? AND course_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $course_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $result ? (int) $result['certificate_id'] : null;
    }

    // ==========================================
    // COURSE DETAILS
    // ==========================================

    /**
     * Get detailed course info with lessons
     */
    public function getCourseDetails(int $course_id): ?array
    {
        // Get enrollment info
        $stmt = $this->conn->prepare("
            SELECT 
                ce.enrollment_id,
                ce.status,
                ce.progress_percentage,
                ce.enrolled_at,
                ce.started_at,
                ce.completed_at,
                ce.due_date,
                ce.is_mandatory,
                ce.last_accessed_at,
                c.course_id,
                c.title,
                c.description,
                c.duration_minutes,
                c.level,
                c.objectives,
                cat.name as category_name
            FROM course_enrollments ce
            INNER JOIN courses c ON ce.course_id = c.course_id
            LEFT JOIN categories cat ON c.category_id = cat.category_id
            WHERE ce.tenant_id = ? AND ce.user_id = ? AND ce.course_id = ? 
                AND ce.is_active = 1 AND ce.status != 'dropped'
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $course_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $course = $result->fetch_assoc();
        $stmt->close();

        if (!$course) {
            return null;
        }

        $course['progress_percentage'] = (float) $course['progress_percentage'];
        $course['is_mandatory'] = (bool) $course['is_mandatory'];
        $course['is_overdue'] = $course['due_date'] && $course['status'] !== 'completed'
            && strtotime($course['due_date']) < strtotime('today');

        // Get course modules/sections with lessons
        $course['modules'] = $this->getCourseModules($course_id);

        // Get certificate if completed
        if ($course['status'] === 'completed') {
            $course['certificate'] = $this->getCourseCertificate($course_id);
        }

        return $course;
    }

    /**
     * Get course modules with lessons and progress
     */
    private function getCourseModules(int $course_id): array
    {
        // Check if modules table exists
        $checkTable = $this->conn->query("SHOW TABLES LIKE 'modules'");
        $hasModules = $checkTable->num_rows > 0;

        if ($hasModules) {
            // Get modules
            $stmt = $this->conn->prepare("
                SELECT module_id, title, description, sequence_order
                FROM modules
                WHERE course_id = ? AND is_active = 1
                ORDER BY sequence_order
            ");
            $stmt->bind_param("i", $course_id);
            $stmt->execute();
            $result = $stmt->get_result();

            $modules = [];
            while ($row = $result->fetch_assoc()) {
                $row['lessons'] = $this->getModuleLessons($course_id, $row['module_id']);
                $modules[] = $row;
            }
            $stmt->close();

            // Add lessons without module
            $unassignedLessons = $this->getModuleLessons($course_id, null);
            if (!empty($unassignedLessons)) {
                array_unshift($modules, [
                    'module_id' => null,
                    'title' => 'Lessons',
                    'description' => null,
                    'sequence_order' => 0,
                    'lessons' => $unassignedLessons
                ]);
            }

            return $modules;
        } else {
            // No modules, just return lessons as single module
            return [[
                'module_id' => null,
                'title' => 'Course Content',
                'description' => null,
                'sequence_order' => 0,
                'lessons' => $this->getModuleLessons($course_id, null)
            ]];
        }
    }

    /**
     * Get lessons for a module (or all if module_id is null)
     * This method correctly uses bound parameters for user_id and tenant_id
     */
    private function getModuleLessons(int $course_id, ?int $module_id): array
    {
        $sql = "
            SELECT 
                cl.lesson_id,
                cl.title,
                cl.content_type,
                cl.duration_minutes,
                cl.sequence_order,
                COALESCE(lp.status, 'not_started') as lesson_status,
                COALESCE(lp.progress_percentage, 0) as progress_percentage,
                lp.last_position,
                lp.completed_at
            FROM lessons cl
            LEFT JOIN lesson_progress lp ON cl.lesson_id = lp.lesson_id 
                AND lp.user_id = ? AND lp.tenant_id = ?
            WHERE cl.course_id = ? AND cl.is_active = 1
        ";
        $params = [$this->user_id, $this->tenant_id, $course_id];
        $types = "iii";

        // Check if module_id column exists
        $checkColumn = $this->conn->query("SHOW COLUMNS FROM lessons LIKE 'module_id'");
        if ($checkColumn->num_rows > 0) {
            if ($module_id !== null) {
                $sql .= " AND cl.module_id = ?";
                $params[] = $module_id;
                $types .= "i";
            } else {
                $sql .= " AND (cl.module_id IS NULL OR cl.module_id = 0)";
            }
        }

        $sql .= " ORDER BY cl.sequence_order";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $lessons = [];
        while ($row = $result->fetch_assoc()) {
            $row['is_completed'] = ($row['lesson_status'] === 'completed');
            $row['progress_percentage'] = (float) $row['progress_percentage'];
            $row['duration_minutes'] = (int) $row['duration_minutes'];
            $lessons[] = $row;
        }
        $stmt->close();

        return $lessons;
    }

    /**
     * Get certificate for completed course
     */
    private function getCourseCertificate(int $course_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT certificate_id, certificate_number, issue_date, expiry_date, pdf_url
            FROM certificates
            WHERE tenant_id = ? AND user_id = ? AND course_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $course_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $cert = $result->fetch_assoc();
        $stmt->close();

        return $cert;
    }

    // ==========================================
    // LEARNING ACTIONS
    // ==========================================

    /**
     * Start a course (mark as in_progress)
     */
    public function startCourse(int $course_id): array
    {
        // Verify enrollment
        $stmt = $this->conn->prepare("
            SELECT enrollment_id, status
            FROM course_enrollments
            WHERE tenant_id = ? AND user_id = ? AND course_id = ? 
                AND is_active = 1 AND status != 'dropped'
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $course_id);
        $stmt->execute();
        $enrollment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$enrollment) {
            return ['success' => false, 'message' => 'Not enrolled in this course'];
        }

        if ($enrollment['status'] !== 'not_started') {
            return ['success' => false, 'message' => 'Course already started'];
        }

        // Update enrollment status
        $stmt = $this->conn->prepare("
            UPDATE course_enrollments
            SET status = 'in_progress', started_at = NOW(), last_accessed_at = NOW()
            WHERE enrollment_id = ?
        ");
        $stmt->bind_param("i", $enrollment['enrollment_id']);
        $stmt->execute();
        $stmt->close();

        // Get first lesson
        $stmt = $this->conn->prepare("
            SELECT lesson_id, title
            FROM lessons
            WHERE course_id = ? AND is_active = 1
            ORDER BY sequence_order
            LIMIT 1
        ");
        $stmt->bind_param("i", $course_id);
        $stmt->execute();
        $firstLesson = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return [
            'success' => true,
            'message' => 'Course started',
            'first_lesson' => $firstLesson
        ];
    }

    /**
     * Update last accessed time for a course
     */
    public function updateLastAccessed(int $course_id): bool
    {
        $stmt = $this->conn->prepare("
            UPDATE course_enrollments
            SET last_accessed_at = NOW()
            WHERE tenant_id = ? AND user_id = ? AND course_id = ? AND is_active = 1
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $course_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Get next lesson to continue
     * FIXED: Added check for NULL progress record
     */
    public function getNextLesson(int $course_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                cl.lesson_id,
                cl.title,
                cl.content_type,
                cl.duration_minutes,
                COALESCE(lp.progress_percentage, 0) as progress_percentage,
                lp.last_position
            FROM lessons cl
            LEFT JOIN lesson_progress lp ON cl.lesson_id = lp.lesson_id 
                AND lp.user_id = ? AND lp.tenant_id = ?
            WHERE cl.course_id = ? AND cl.is_active = 1
                AND (lp.progress_id IS NULL OR lp.status != 'completed')
            ORDER BY cl.sequence_order
            LIMIT 1
        ");
        $stmt->bind_param("iii", $this->user_id, $this->tenant_id, $course_id);
        $stmt->execute();
        $lesson = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $lesson;
    }

    // ==========================================
    // RECOMMENDED / AVAILABLE COURSES
    // ==========================================

    /**
     * Get available courses for self-enrollment
     */
    public function getAvailableCourses(?string $search = null, ?int $category_id = null): array
    {
        $sql = "
            SELECT 
                c.course_id,
                c.title,
                c.description,
                c.duration_minutes,
                c.level,
                cat.name as category_name,
                (SELECT COUNT(*) FROM course_enrollments ce 
                 WHERE ce.course_id = c.course_id AND ce.tenant_id = c.tenant_id 
                 AND ce.is_active = 1 AND ce.status != 'dropped') as enrollment_count
            FROM courses c
            LEFT JOIN categories cat ON c.category_id = cat.category_id
            WHERE c.tenant_id = ? AND c.is_active = 1 AND c.status = 'published'
                AND c.allow_self_enrollment = 1
                AND c.course_id NOT IN (
                    SELECT course_id FROM course_enrollments 
                    WHERE tenant_id = ? AND user_id = ? AND is_active = 1 AND status != 'dropped'
                )
        ";
        $params = [$this->tenant_id, $this->tenant_id, $this->user_id];
        $types = "iii";

        if ($search) {
            $sql .= " AND (c.title LIKE ? OR c.description LIKE ?)";
            $searchTerm = "%{$search}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $types .= "ss";
        }

        if ($category_id) {
            $sql .= " AND c.category_id = ?";
            $params[] = $category_id;
            $types .= "i";
        }

        $sql .= " ORDER BY c.created_at DESC LIMIT 50";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $courses = [];
        while ($row = $result->fetch_assoc()) {
            $row['duration_minutes'] = (int) $row['duration_minutes'];
            $row['enrollment_count'] = (int) $row['enrollment_count'];
            $courses[] = $row;
        }
        $stmt->close();

        return $courses;
    }

    /**
     * Self-enroll in a course
     */
    public function selfEnroll(int $course_id): array
    {
        // Check if course allows self-enrollment
        $stmt = $this->conn->prepare("
            SELECT course_id, title, allow_self_enrollment
            FROM courses
            WHERE course_id = ? AND tenant_id = ? AND is_active = 1 AND status = 'published'
        ");
        $stmt->bind_param("ii", $course_id, $this->tenant_id);
        $stmt->execute();
        $course = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$course) {
            return ['success' => false, 'message' => 'Course not found'];
        }

        if (!$course['allow_self_enrollment']) {
            return ['success' => false, 'message' => 'Self-enrollment not allowed for this course'];
        }

        // Check if already enrolled
        $stmt = $this->conn->prepare("
            SELECT enrollment_id, status
            FROM course_enrollments
            WHERE tenant_id = ? AND user_id = ? AND course_id = ? AND is_active = 1
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $course_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing && $existing['status'] !== 'dropped') {
            return ['success' => false, 'message' => 'Already enrolled in this course'];
        }

        // Create enrollment
        $stmt = $this->conn->prepare("
            INSERT INTO course_enrollments 
                (tenant_id, user_id, course_id, status, is_mandatory, enrollment_source, enrolled_at, created_at)
            VALUES (?, ?, ?, 'not_started', 0, 'self_enrollment', NOW(), NOW())
        ");
        $stmt->bind_param("iii", $this->tenant_id, $this->user_id, $course_id);
        $success = $stmt->execute();
        $enrollment_id = $stmt->insert_id;
        $stmt->close();

        return [
            'success' => $success,
            'message' => $success ? 'Successfully enrolled' : 'Failed to enroll',
            'enrollment_id' => $enrollment_id
        ];
    }

    // ==========================================
    // CATEGORIES
    // ==========================================

    /**
     * Get course categories
     */
    public function getCategories(): array
    {
        $stmt = $this->conn->prepare("
            SELECT category_id, name 
            FROM categories 
            WHERE tenant_id = ? AND is_active = 1 
            ORDER BY name
        ");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $categories = [];
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row;
        }
        $stmt->close();

        return $categories;
    }
}