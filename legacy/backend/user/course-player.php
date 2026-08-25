<?php
require_once __DIR__ . '/../config/Database.php';

$db = new Database();
$conn = $db->getConnection();

// Get request parameters
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Get authentication from request
$token = $_GET['token'] ?? $_POST['token'] ?? $_SERVER['HTTP_X_TOKEN'] ?? null;
$user_id = $_GET['user_id'] ?? $_POST['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;

// Handle JSON body
$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!$token && isset($input['token'])) $token = $input['token'];
    if (!$user_id && isset($input['user_id'])) $user_id = $input['user_id'];
}

// Verify token
if (!$token || !$user_id) {
    $db->send_response(401, 'error', null, 'Token and user_id required');
    exit;
}

$verification = $db->verify_user($token, $user_id);
if ($verification < 100) {
    $db->send_response(401, 'error', null, 'Invalid token');
    exit;
}

// Get context
$tenant_id = (int)($_GET['tenant_id'] ?? $input['tenant_id'] ?? 0);
$user_id = (int)$user_id;

if (!$tenant_id) {
    $db->send_response(400, 'error', null, 'tenant_id required');
    exit;
}

try {
    switch ($action) {
        // ==========================================
        // LOAD COURSE FOR PLAYER
        // ==========================================
        case 'load':
            $course_id = (int)($_GET['course_id'] ?? 0);
            if (!$course_id) {
                $db->send_response(400, 'error', null, 'course_id required');
                exit;
            }

            $data = loadCoursePlayer($conn, $tenant_id, $user_id, $course_id);
            $db->send_response(200, 'success', $data, 'Course loaded');
            break;

        // ==========================================
        // START LEARNING SESSION
        // ==========================================
        case 'start-session':
            $enrollment_id = (int)($input['enrollment_id'] ?? 0);
            $course_id = (int)($input['course_id'] ?? 0);

            if (!$enrollment_id || !$course_id) {
                $db->send_response(400, 'error', null, 'enrollment_id and course_id required');
                exit;
            }

            $session_id = startLearningSession($conn, $tenant_id, $user_id, $enrollment_id, $course_id);
            $db->send_response(200, 'success', ['session_id' => $session_id], 'Session started');
            break;

        // ==========================================
        // END LEARNING SESSION
        // ==========================================
        case 'end-session':
            $session_id = (int)($input['session_id'] ?? 0);

            if (!$session_id) {
                $db->send_response(400, 'error', null, 'session_id required');
                exit;
            }

            endLearningSession($conn, $session_id, $user_id);
            $db->send_response(200, 'success', null, 'Session ended');
            break;

        // ==========================================
        // TRACK PROGRESS (Heartbeat)
        // ==========================================
        case 'track':
            $enrollment_id = (int)($input['enrollment_id'] ?? 0);
            $lesson_id = (int)($input['lesson_id'] ?? 0);
            $session_id = (int)($input['session_id'] ?? 0);
            $progress = (int)($input['progress'] ?? 0);
            $time_spent = (int)($input['time_spent'] ?? 0);
            $video_position = (int)($input['video_position'] ?? 0);

            if (!$enrollment_id) {
                $db->send_response(400, 'error', null, 'enrollment_id required');
                exit;
            }

            $result = trackProgress($conn, $tenant_id, $user_id, $enrollment_id, $lesson_id, $session_id, $progress, $time_spent, $video_position);
            $db->send_response(200, 'success', $result, 'Progress tracked');
            break;

        // ==========================================
        // COMPLETE LESSON
        // ==========================================
        case 'complete-lesson':
            $enrollment_id = (int)($input['enrollment_id'] ?? 0);
            $lesson_id = (int)($input['lesson_id'] ?? 0);
            $time_spent = (int)($input['time_spent'] ?? 0);
            $score = isset($input['score']) ? (int)$input['score'] : null;

            if (!$enrollment_id || !$lesson_id) {
                $db->send_response(400, 'error', null, 'enrollment_id and lesson_id required');
                exit;
            }

            $result = completeLesson($conn, $tenant_id, $user_id, $enrollment_id, $lesson_id, $time_spent, $score);
            $db->send_response(200, 'success', $result, 'Lesson completed');
            break;

        // ==========================================
        // COMPLETE COURSE
        // ==========================================
        case 'complete-course':
            $enrollment_id = (int)($input['enrollment_id'] ?? 0);
            $final_score = isset($input['final_score']) ? (float)$input['final_score'] : null;

            if (!$enrollment_id) {
                $db->send_response(400, 'error', null, 'enrollment_id required');
                exit;
            }

            $result = completeCourse($conn, $tenant_id, $user_id, $enrollment_id, $final_score);
            $db->send_response(200, 'success', $result, 'Course completed');
            break;

        // ==========================================
        // SAVE BOOKMARK
        // ==========================================
        case 'bookmark':
            $enrollment_id = (int)($input['enrollment_id'] ?? 0);
            $lesson_id = (int)($input['lesson_id'] ?? 0);
            $position = (int)($input['position'] ?? 0);
            $note = $input['note'] ?? null;

            if (!$enrollment_id || !$lesson_id) {
                $db->send_response(400, 'error', null, 'enrollment_id and lesson_id required');
                exit;
            }

            $bookmark_id = saveBookmark($conn, $tenant_id, $user_id, $enrollment_id, $lesson_id, $position, $note);
            $db->send_response(200, 'success', ['bookmark_id' => $bookmark_id], 'Bookmark saved');
            break;

        // ==========================================
        // SAVE NOTE
        // ==========================================
        case 'save-note':
            $enrollment_id = (int)($input['enrollment_id'] ?? 0);
            $lesson_id = (int)($input['lesson_id'] ?? 0);
            $content = $input['content'] ?? '';
            $timestamp = (int)($input['timestamp'] ?? 0);
            $is_pinned = (bool)($input['is_pinned'] ?? false);

            if (!$enrollment_id || !$lesson_id || !$content) {
                $db->send_response(400, 'error', null, 'enrollment_id, lesson_id and content required');
                exit;
            }

            $note_id = saveNote($conn, $tenant_id, $user_id, $enrollment_id, $lesson_id, $content, $timestamp, $is_pinned);
            $db->send_response(200, 'success', ['note_id' => $note_id], 'Note saved');
            break;

        // ==========================================
        // GET NOTES
        // ==========================================
        case 'get-notes':
            $enrollment_id = (int)($_GET['enrollment_id'] ?? 0);
            $lesson_id = (int)($_GET['lesson_id'] ?? 0);

            if (!$enrollment_id) {
                $db->send_response(400, 'error', null, 'enrollment_id required');
                exit;
            }

            $notes = getNotes($conn, $tenant_id, $user_id, $enrollment_id, $lesson_id);
            $db->send_response(200, 'success', $notes, 'Notes retrieved');
            break;

        // ==========================================
        // LOAD QUIZ DATA
        // ==========================================
        case 'load-quiz':
            $assessment_id = (int)($_GET['assessment_id'] ?? 0);

            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'assessment_id required');
                exit;
            }

            $quizData = loadQuizData($conn, $tenant_id, $user_id, $assessment_id);
            $db->send_response(200, 'success', $quizData, 'Quiz loaded');
            break;

        // ==========================================
        // START QUIZ ATTEMPT
        // ==========================================
        case 'start-quiz':
            $enrollment_id = (int)($input['enrollment_id'] ?? 0);
            $assessment_id = (int)($input['assessment_id'] ?? 0);

            if (!$enrollment_id || !$assessment_id) {
                $db->send_response(400, 'error', null, 'enrollment_id and assessment_id required');
                exit;
            }

            $attemptData = startQuizAttempt($conn, $tenant_id, $user_id, $enrollment_id, $assessment_id);
            $db->send_response(200, 'success', $attemptData, 'Quiz attempt started');
            break;

        // ==========================================
        // SUBMIT QUIZ
        // ==========================================
        case 'submit-quiz':
            $enrollment_id = (int)($input['enrollment_id'] ?? 0);
            $assessment_id = (int)($input['assessment_id'] ?? 0);
            $attempt_id = (int)($input['attempt_id'] ?? 0);
            $answers = $input['answers'] ?? [];
            $time_taken = (int)($input['time_taken'] ?? 0);

            if (!$enrollment_id || !$assessment_id || !$attempt_id) {
                $db->send_response(400, 'error', null, 'enrollment_id, assessment_id and attempt_id required');
                exit;
            }

            $result = submitQuizAnswers($conn, $tenant_id, $user_id, $enrollment_id, $assessment_id, $attempt_id, $answers, $time_taken);
            $db->send_response(200, 'success', $result, 'Quiz submitted');
            break;

        // ==========================================
        // LOG INTERACTION
        // ==========================================
        case 'log-interaction':
            $enrollment_id = (int)($input['enrollment_id'] ?? 0);
            $lesson_id = (int)($input['lesson_id'] ?? 0);
            $interaction_type = $input['interaction_type'] ?? '';
            $interaction_data = $input['interaction_data'] ?? [];

            if (!$enrollment_id || !$interaction_type) {
                $db->send_response(400, 'error', null, 'enrollment_id and interaction_type required');
                exit;
            }

            logInteraction($conn, $tenant_id, $user_id, $enrollment_id, $lesson_id, $interaction_type, $interaction_data);
            $db->send_response(200, 'success', null, 'Interaction logged');
            break;

        // ==========================================
        // RATE COURSE
        // ==========================================
        case 'rate':
            $course_id = (int)($input['course_id'] ?? 0);
            $rating = (int)($input['rating'] ?? 0);
            $review = $input['review'] ?? null;

            if (!$course_id || $rating < 1 || $rating > 5) {
                $db->send_response(400, 'error', null, 'course_id and valid rating (1-5) required');
                exit;
            }

            $result = rateCourse($conn, $tenant_id, $user_id, $course_id, $rating, $review);
            $db->send_response(200, 'success', $result, 'Rating saved');
            break;

        // ==========================================
        // GET RESUME POSITION
        // ==========================================
        case 'resume':
            $enrollment_id = (int)($_GET['enrollment_id'] ?? 0);

            if (!$enrollment_id) {
                $db->send_response(400, 'error', null, 'enrollment_id required');
                exit;
            }

            $resume = getResumePosition($conn, $tenant_id, $user_id, $enrollment_id);
            $db->send_response(200, 'success', $resume, 'Resume position retrieved');
            break;

        // ==========================================
        // SCORM TRACKING
        // ==========================================
        case 'scorm-commit':
            $enrollment_id = (int)($input['enrollment_id'] ?? 0);
            $lesson_id = (int)($input['lesson_id'] ?? 0);
            $scorm_data = $input['scorm_data'] ?? [];

            if (!$enrollment_id || !$lesson_id) {
                $db->send_response(400, 'error', null, 'enrollment_id and lesson_id required');
                exit;
            }

            saveScormData($conn, $tenant_id, $user_id, $enrollment_id, $lesson_id, $scorm_data);
            $db->send_response(200, 'success', null, 'SCORM data saved');
            break;

        // ==========================================
        // SCORM LESSON COMPLETION
        // ==========================================
        case 'complete-scorm-lesson':
            $enrollment_id = (int)($input['enrollment_id'] ?? 0);
            $lesson_id = (int)($input['lesson_id'] ?? 0);
            $scorm_status = $input['scorm_status'] ?? '';
            $scorm_score = isset($input['scorm_score']) ? (float)$input['scorm_score'] : null;

            if (!$enrollment_id || !$lesson_id) {
                $db->send_response(400, 'error', null, 'enrollment_id and lesson_id required');
                exit;
            }

            // Only complete if status is passed or completed
            if (!in_array(strtolower($scorm_status), ['passed', 'completed'])) {
                $db->send_response(400, 'error', null, 'Invalid SCORM status for completion');
                exit;
            }

            $result = completeScormLesson($conn, $tenant_id, $user_id, $enrollment_id, $lesson_id, $scorm_status, $scorm_score);
            $db->send_response(200, 'success', $result, 'SCORM lesson completed');
            break;

        // ==========================================
        // CONTENT INTERACTION TRACKING
        // ==========================================
        case 'track-content-interaction':
            $course_id = (int)($input['course_id'] ?? 0);
            $lesson_id = (int)($input['lesson_id'] ?? 0);
            $interaction_type = $input['interaction_type'] ?? '';
            $position_seconds = isset($input['position_seconds']) ? (int)$input['position_seconds'] : null;
            $duration_seconds = isset($input['duration_seconds']) ? (int)$input['duration_seconds'] : null;

            if (!$course_id || !$lesson_id || !$interaction_type) {
                $db->send_response(400, 'error', null, 'course_id, lesson_id and interaction_type required');
                exit;
            }

            // Validate interaction type
            $valid_types = ['video_play', 'video_pause', 'video_complete', 'document_view', 'document_download', 'scorm_launch'];
            if (!in_array($interaction_type, $valid_types)) {
                $db->send_response(400, 'error', null, 'Invalid interaction type');
                exit;
            }

            trackContentInteraction($conn, $tenant_id, $user_id, $course_id, $lesson_id, $interaction_type, $position_seconds, $duration_seconds);
            $db->send_response(200, 'success', null, 'Interaction tracked');
            break;

        default:
            $db->send_response(400, 'error', null, 'Invalid action');
    }
} catch (Exception $e) {
    error_log('Course Player API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}


// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Load course data for player
 */
function loadCoursePlayer(mysqli $conn, int $tenant_id, int $user_id, int $course_id): array {
    // Get course info
    $courseQuery = "SELECT c.course_id, c.title, c.description,
                      c.duration_minutes, c.level, c.pass_percentage,
                      c.certificate_template_id, c.is_mandatory,
                      cat.name as category_name,
                      CONCAT(u.first_name, ' ', u.last_name) as instructor_name
                    FROM courses c
                    LEFT JOIN categories cat ON c.category_id = cat.category_id
                    LEFT JOIN users u ON c.instructor_id = u.id
                    WHERE c.course_id = ? AND c.tenant_id = ? AND c.is_active = 1";

    $stmt = $conn->prepare($courseQuery);
    $stmt->bind_param("ii", $course_id, $tenant_id);
    $stmt->execute();
    $course = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$course) {
        throw new Exception('Course not found');
    }

    // Get enrollment
    $enrollQuery = "SELECT enrollment_id, status, progress_percentage, 
                      enrolled_at, started_at, completed_at, due_date,
                      time_spent_minutes, last_activity_at, last_lesson_id,
                      final_score, attempts
                    FROM course_enrollments
                    WHERE course_id = ? AND user_id = ? AND tenant_id = ? AND is_active = 1";

    $stmt = $conn->prepare($enrollQuery);
    $stmt->bind_param("iii", $course_id, $user_id, $tenant_id);
    $stmt->execute();
    $enrollment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$enrollment) {
        throw new Exception('Not enrolled in this course');
    }

    // Get modules and lessons
    $modulesQuery = "SELECT module_id, title, description, sequence_order
                     FROM modules
                     WHERE course_id = ? AND is_active = 1
                     ORDER BY sequence_order";

    $stmt = $conn->prepare($modulesQuery);
    $stmt->bind_param("i", $course_id);
    $stmt->execute();
    $modulesResult = $stmt->get_result();

    $modules = [];
    while ($module = $modulesResult->fetch_assoc()) {
        $module['lessons'] = [];
        $module['assessments'] = [];
        $modules[$module['module_id']] = $module;
    }
    $stmt->close();

    // Get lessons with progress
    $lessonsQuery = "SELECT l.lesson_id, l.module_id, l.title,
                       l.content_type, l.content_url, l.duration_minutes,
                       l.sequence_order, l.is_mandatory,
                       COALESCE(lp.status, 'not_started') as status,
                       COALESCE(lp.progress_percentage, 0) as progress,
                       lp.completed_at, lp.time_spent_seconds, lp.score,
                       lp.last_position, lp.attempts
                     FROM lessons l
                     LEFT JOIN lesson_progress lp ON l.lesson_id = lp.lesson_id 
                       AND lp.user_id = ? AND lp.enrollment_id = ?
                     WHERE l.course_id = ? AND l.is_active = 1
                     ORDER BY l.sequence_order";

    $stmt = $conn->prepare($lessonsQuery);
    $stmt->bind_param("iii", $user_id, $enrollment['enrollment_id'], $course_id);
    $stmt->execute();
    $lessonsResult = $stmt->get_result();

    while ($lesson = $lessonsResult->fetch_assoc()) {
        $moduleId = $lesson['module_id'];
        if (isset($modules[$moduleId])) {
            $modules[$moduleId]['lessons'][] = $lesson;
        }
    }
    $stmt->close();

    // Get assessments with module assignment and user progress
    $assessmentsQuery = "SELECT a.assessment_id, a.module_id, a.title, a.description,
                           a.assessment_type, a.time_limit_minutes, a.pass_percentage,
                           a.max_attempts, a.shuffle_questions, a.show_correct_answers,
                           a.sequence_order, a.total_questions,
                           COUNT(aa.attempt_id) as attempts_used,
                           MAX(aa.percentage) as best_score,
                           MAX(aa.passed) as has_passed
                         FROM assessments a
                         LEFT JOIN assessment_attempts aa ON a.assessment_id = aa.assessment_id
                           AND aa.user_id = ? AND aa.status = 'completed'
                         WHERE a.course_id = ? AND a.tenant_id = ? AND a.is_active = 1
                         GROUP BY a.assessment_id
                         ORDER BY a.assessment_id";

    $stmt = $conn->prepare($assessmentsQuery);
    $stmt->bind_param("iii", $user_id, $course_id, $tenant_id);
    $stmt->execute();
    $assessmentsResult = $stmt->get_result();

    $allAssessments = [];
    while ($assessment = $assessmentsResult->fetch_assoc()) {
        $assessment['attempts_used'] = (int)$assessment['attempts_used'];
        $assessment['best_score'] = $assessment['best_score'] !== null ? (float)$assessment['best_score'] : null;
        $assessment['has_passed'] = (bool)$assessment['has_passed'];

        $allAssessments[] = $assessment;

        // Add to module if module_id is set
        $moduleId = $assessment['module_id'];
        if ($moduleId && isset($modules[$moduleId])) {
            $modules[$moduleId]['assessments'][] = $assessment;
        }
    }
    $stmt->close();

    // Get bookmarks
    $bookmarksQuery = "SELECT bookmark_id, lesson_id, position_seconds, note, created_at
                       FROM lesson_bookmarks
                       WHERE enrollment_id = ? AND user_id = ?
                       ORDER BY created_at DESC";

    $stmt = $conn->prepare($bookmarksQuery);
    $stmt->bind_param("ii", $enrollment['enrollment_id'], $user_id);
    $stmt->execute();
    $bookmarks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get user's rating if exists
    $ratingQuery = "SELECT rating, review_text FROM course_reviews WHERE course_id = ? AND user_id = ?";
    $stmt = $conn->prepare($ratingQuery);
    $stmt->bind_param("ii", $course_id, $user_id);
    $stmt->execute();
    $userRating = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Calculate course stats
    $totalLessons = 0;
    $completedLessons = 0;
    foreach ($modules as &$module) {
        $moduleCompleted = 0;
        foreach ($module['lessons'] as $lesson) {
            $totalLessons++;
            if ($lesson['status'] === 'completed') {
                $completedLessons++;
                $moduleCompleted++;
            }
        }
        $module['completed_lessons'] = $moduleCompleted;
        $module['total_lessons'] = count($module['lessons']);
    }

    return [
        'course' => $course,
        'enrollment' => $enrollment,
        'modules' => array_values($modules),
        'assessments' => $allAssessments,
        'bookmarks' => $bookmarks,
        'user_rating' => $userRating,
        'stats' => [
            'total_lessons' => $totalLessons,
            'completed_lessons' => $completedLessons,
            'completion_percentage' => $totalLessons > 0 ? round(($completedLessons / $totalLessons) * 100, 1) : 0
        ]
    ];
}

/**
 * Start a learning session
 */
function startLearningSession(mysqli $conn, int $tenant_id, int $user_id, int $enrollment_id, int $course_id): int {
    // End any existing active sessions
    $endQuery = "UPDATE learning_sessions SET ended_at = NOW(), is_active = 0 
                 WHERE user_id = ? AND tenant_id = ? AND is_active = 1";
    $stmt = $conn->prepare($endQuery);
    $stmt->bind_param("ii", $user_id, $tenant_id);
    $stmt->execute();
    $stmt->close();

    // Create new session
    $insertQuery = "INSERT INTO learning_sessions 
                    (user_id, tenant_id, enrollment_id, course_id, started_at, is_active,
                     device_type, browser, ip_address)
                    VALUES (?, ?, ?, ?, NOW(), 1, ?, ?, ?)";

    $deviceType = detectDeviceType();
    $browser = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    $stmt = $conn->prepare($insertQuery);
    $stmt->bind_param("iiiisss", $user_id, $tenant_id, $enrollment_id, $course_id,
        $deviceType, $browser, $ipAddress);
    $stmt->execute();
    $sessionId = $conn->insert_id;
    $stmt->close();

    // Update enrollment - mark as started if first time
    $updateQuery = "UPDATE course_enrollments 
                    SET last_accessed_at = NOW(),
                        started_at = COALESCE(started_at, NOW()),
                        status = CASE WHEN status = 'not_started' THEN 'in_progress' ELSE status END
                    WHERE enrollment_id = ?";
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("i", $enrollment_id);
    $stmt->execute();
    $stmt->close();

    // Update user streak
    updateLearningStreak($conn, $tenant_id, $user_id);

    return $sessionId;
}

/**
 * End learning session
 */
function endLearningSession(mysqli $conn, int $session_id, int $user_id): void {
    $query = "UPDATE learning_sessions 
              SET ended_at = NOW(), 
                  is_active = 0,
                  time_spent_minutes = TIMESTAMPDIFF(MINUTE, started_at, NOW())
              WHERE session_id = ? AND user_id = ?";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $session_id, $user_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Track progress (heartbeat)
 */
function trackProgress(mysqli $conn, int $tenant_id, int $user_id, int $enrollment_id,
                       int $lesson_id, int $session_id, int $progress, int $time_spent,
                       int $video_position): array {

    // Update lesson progress
    if ($lesson_id > 0) {
        $query = "INSERT INTO lesson_progress 
                  (user_id, tenant_id, enrollment_id, lesson_id, status, progress_percentage, 
                   time_spent_seconds, last_position, updated_at)
                  VALUES (?, ?, ?, ?, 'in_progress', ?, ?, ?, NOW())
                  ON DUPLICATE KEY UPDATE
                    progress_percentage = GREATEST(progress_percentage, VALUES(progress_percentage)),
                    time_spent_seconds = time_spent_seconds + ?,
                    last_position = VALUES(last_position),
                    status = CASE WHEN progress_percentage >= 100 THEN 'completed' ELSE 'in_progress' END,
                    updated_at = NOW()";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("iiiiiiii", $user_id, $tenant_id, $enrollment_id, $lesson_id,
            $progress, $time_spent, $video_position, $time_spent);
        $stmt->execute();
        $stmt->close();
    }

    // Update enrollment time and last accessed
    $updateEnroll = "UPDATE course_enrollments 
                     SET time_spent_minutes = time_spent_minutes + ?,
                         last_accessed_at = NOW(),
                         last_lesson_id = ?
                     WHERE enrollment_id = ?";

    $minutes = ceil($time_spent / 60);
    $stmt = $conn->prepare($updateEnroll);
    $stmt->bind_param("iii", $minutes, $lesson_id, $enrollment_id);
    $stmt->execute();
    $stmt->close();

    // Update session activity
    if ($session_id > 0) {
        $updateSession = "UPDATE learning_sessions 
                          SET last_activity_at = NOW(),
                              pages_viewed = pages_viewed + 1
                          WHERE session_id = ?";
        $stmt = $conn->prepare($updateSession);
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $stmt->close();
    }

    // Calculate overall course progress
    $progressQuery = "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN lp.status = 'completed' THEN 1 ELSE 0 END) as completed
                      FROM lessons l
                      LEFT JOIN lesson_progress lp ON l.lesson_id = lp.lesson_id 
                        AND lp.enrollment_id = ?
                      WHERE l.course_id = (SELECT course_id FROM course_enrollments WHERE enrollment_id = ?)
                        AND l.is_active = 1";

    $stmt = $conn->prepare($progressQuery);
    $stmt->bind_param("ii", $enrollment_id, $enrollment_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $overallProgress = $result['total'] > 0
        ? round(($result['completed'] / $result['total']) * 100, 1)
        : 0;

    // Update enrollment progress
    $updateProgress = "UPDATE course_enrollments SET progress_percentage = ? WHERE enrollment_id = ?";
    $stmt = $conn->prepare($updateProgress);
    $stmt->bind_param("di", $overallProgress, $enrollment_id);
    $stmt->execute();
    $stmt->close();

    return [
        'overall_progress' => $overallProgress,
        'lessons_completed' => (int)$result['completed'],
        'total_lessons' => (int)$result['total']
    ];
}

/**
 * Complete a lesson
 */
function completeLesson(mysqli $conn, int $tenant_id, int $user_id, int $enrollment_id,
                        int $lesson_id, int $time_spent, ?int $score): array {

    // Mark lesson as completed
    $query = "INSERT INTO lesson_progress 
              (user_id, tenant_id, enrollment_id, lesson_id, status, progress_percentage, 
               time_spent_seconds, score, completed_at, attempts)
              VALUES (?, ?, ?, ?, 'completed', 100, ?, ?, NOW(), 1)
              ON DUPLICATE KEY UPDATE
                status = 'completed',
                progress_percentage = 100,
                time_spent_seconds = time_spent_seconds + VALUES(time_spent_seconds),
                score = COALESCE(VALUES(score), score),
                completed_at = COALESCE(completed_at, NOW()),
                attempts = attempts + 1";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiiiii", $user_id, $tenant_id, $enrollment_id, $lesson_id, $time_spent, $score);
    $stmt->execute();
    $stmt->close();

    // Award points for lesson completion
    $points = awardPoints($conn, $tenant_id, $user_id, 'lesson_completion', $lesson_id, 10);

    // Check for badge eligibility
    $badges = checkBadgeEligibility($conn, $tenant_id, $user_id);

    // Get updated progress
    return trackProgress($conn, $tenant_id, $user_id, $enrollment_id, $lesson_id, 0, 100, 0, 0) + [
            'points_earned' => $points,
            'badges_earned' => $badges
        ];
}

/**
 * Complete course
 */
function completeCourse(mysqli $conn, int $tenant_id, int $user_id, int $enrollment_id,
                        ?float $final_score): array {

    // Check if all mandatory lessons are completed
    $checkQuery = "SELECT COUNT(*) as incomplete
                   FROM lessons l
                   LEFT JOIN lesson_progress lp ON l.lesson_id = lp.lesson_id 
                     AND lp.enrollment_id = ?
                   WHERE l.course_id = (SELECT course_id FROM course_enrollments WHERE enrollment_id = ?)
                     AND l.is_mandatory = 1 AND l.is_active = 1
                     AND (lp.status IS NULL OR lp.status != 'completed')";

    $stmt = $conn->prepare($checkQuery);
    $stmt->bind_param("ii", $enrollment_id, $enrollment_id);
    $stmt->execute();
    $incomplete = $stmt->get_result()->fetch_assoc()['incomplete'];
    $stmt->close();

    if ($incomplete > 0) {
        throw new Exception("Cannot complete course. {$incomplete} mandatory lessons remaining.");
    }

    // Get course info for certificate
    $courseQuery = "SELECT c.course_id, c.title, c.pass_mark, c.certificate_template_id
                    FROM course_enrollments ce
                    JOIN courses c ON ce.course_id = c.course_id
                    WHERE ce.enrollment_id = ?";
    $stmt = $conn->prepare($courseQuery);
    $stmt->bind_param("i", $enrollment_id);
    $stmt->execute();
    $course = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Calculate final score if not provided
    if ($final_score === null) {
        $scoreQuery = "SELECT AVG(lp.score) as avg_score
                       FROM lesson_progress lp
                       WHERE lp.enrollment_id = ? AND lp.score IS NOT NULL";
        $stmt = $conn->prepare($scoreQuery);
        $stmt->bind_param("i", $enrollment_id);
        $stmt->execute();
        $final_score = $stmt->get_result()->fetch_assoc()['avg_score'] ?? 0;
        $stmt->close();
    }

    // Check if passed
    $passed = ($course['pass_mark'] === null) || ($final_score >= $course['pass_mark']);

    // Update enrollment
    $updateQuery = "UPDATE course_enrollments 
                    SET status = 'completed',
                        progress_percentage = 100,
                        final_score = ?,
                        completed_at = NOW()
                    WHERE enrollment_id = ?";

    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("di", $final_score, $enrollment_id);
    $stmt->execute();
    $stmt->close();

    // Award completion points
    $points = awardPoints($conn, $tenant_id, $user_id, 'course_completion', $course['course_id'], 100);

    // Generate certificate if passed and template exists
    $certificate_id = null;
    if ($passed && $course['certificate_template_id']) {
        $certificate_id = generateCertificate($conn, $tenant_id, $user_id, $enrollment_id,
            $course['course_id'], $course['certificate_template_id']);
    }

    // Check badges
    $badges = checkBadgeEligibility($conn, $tenant_id, $user_id);

    return [
        'passed' => $passed,
        'final_score' => round($final_score, 1),
        'certificate_id' => $certificate_id,
        'points_earned' => $points,
        'badges_earned' => $badges
    ];
}

/**
 * Save bookmark
 */
function saveBookmark(mysqli $conn, int $tenant_id, int $user_id, int $enrollment_id,
                      int $lesson_id, int $position, ?string $note): int {

    $query = "INSERT INTO lesson_bookmarks 
              (user_id, tenant_id, enrollment_id, lesson_id, position_seconds, note, created_at)
              VALUES (?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiiiss", $user_id, $tenant_id, $enrollment_id, $lesson_id, $position, $note);
    $stmt->execute();
    $bookmarkId = $conn->insert_id;
    $stmt->close();

    return $bookmarkId;
}

/**
 * Save note
 */
function saveNote(mysqli $conn, int $tenant_id, int $user_id, int $enrollment_id,
                  int $lesson_id, string $content, int $timestamp, bool $is_pinned): int {

    $query = "INSERT INTO notes 
              (user_id, tenant_id, enrollment_id, lesson_id, content, video_timestamp, is_pinned, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($query);
    $pinnedInt = $is_pinned ? 1 : 0;
    $stmt->bind_param("iiiisii", $user_id, $tenant_id, $enrollment_id, $lesson_id,
        $content, $timestamp, $pinnedInt);
    $stmt->execute();
    $noteId = $conn->insert_id;
    $stmt->close();

    return $noteId;
}

/**
 * Get notes
 */
function getNotes(mysqli $conn, int $tenant_id, int $user_id, int $enrollment_id, int $lesson_id): array {
    $query = "SELECT note_id, lesson_id, content, video_timestamp, is_pinned, created_at, updated_at
              FROM notes
              WHERE user_id = ? AND tenant_id = ? AND enrollment_id = ?";

    $params = [$user_id, $tenant_id, $enrollment_id];
    $types = "iii";

    if ($lesson_id > 0) {
        $query .= " AND lesson_id = ?";
        $params[] = $lesson_id;
        $types .= "i";
    }

    $query .= " ORDER BY is_pinned DESC, created_at DESC";

    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $result;
}

/**
 * Submit quiz (legacy - redirects to submitQuizAnswers)
 * Kept for backward compatibility
 */
function submitQuiz(mysqli $conn, int $tenant_id, int $user_id, int $enrollment_id,
                    int $assessment_id, array $answers, int $time_taken): array {

    // Get assessment info using correct tables
    $assessQuery = "SELECT a.*, COUNT(q.question_id) as total_questions
                    FROM assessments a
                    LEFT JOIN questions q ON a.assessment_id = q.assessment_id AND q.is_active = 1
                    WHERE a.assessment_id = ? AND a.tenant_id = ?
                    GROUP BY a.assessment_id";
    $stmt = $conn->prepare($assessQuery);
    $stmt->bind_param("ii", $assessment_id, $tenant_id);
    $stmt->execute();
    $assessment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$assessment) {
        throw new Exception('Assessment not found');
    }

    // Check attempts
    $attemptsQuery = "SELECT COUNT(*) as attempts FROM assessment_attempts 
                      WHERE assessment_id = ? AND user_id = ? AND tenant_id = ?";
    $stmt = $conn->prepare($attemptsQuery);
    $stmt->bind_param("iii", $assessment_id, $user_id, $tenant_id);
    $stmt->execute();
    $attempts = (int)$stmt->get_result()->fetch_assoc()['attempts'];
    $stmt->close();

    if ($assessment['max_attempts'] > 0 && $attempts >= $assessment['max_attempts']) {
        throw new Exception('Maximum attempts reached');
    }

    // Get all questions
    $questionsQuery = "SELECT q.question_id, q.question_type, q.points, q.explanation
                       FROM questions q
                       WHERE q.assessment_id = ? AND q.is_active = 1";
    $stmt = $conn->prepare($questionsQuery);
    $stmt->bind_param("i", $assessment_id);
    $stmt->execute();
    $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Grade each answer using question_options table
    $score = 0;
    $maxScore = 0;
    $questionResults = [];

    foreach ($questions as $q) {
        $questionId = $q['question_id'];
        $points = (float)($q['points'] ?? 1);
        $maxScore += $points;

        $userAnswer = $answers[$questionId] ?? null;
        $isCorrect = false;

        // Get correct options from question_options table
        $optionsQuery = "SELECT option_id, option_text, is_correct 
                         FROM question_options 
                         WHERE question_id = ?";
        $stmt = $conn->prepare($optionsQuery);
        $stmt->bind_param("i", $questionId);
        $stmt->execute();
        $options = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Find correct answer(s)
        $correctOptionIds = [];
        $correctOptionTexts = [];
        foreach ($options as $opt) {
            if ($opt['is_correct']) {
                $correctOptionIds[] = (int)$opt['option_id'];
                $correctOptionTexts[] = $opt['option_text'];
            }
        }

        // Grade based on question type
        switch ($q['question_type']) {
            case 'single_choice':
                $correctAnswer = !empty($correctOptionIds) ? $correctOptionIds[0] : null;
                $isCorrect = ((int)$userAnswer === $correctAnswer);
                break;

            case 'true_false':
                // User might answer with 'true'/'false' string or option_id
                $correctAnswer = !empty($correctOptionIds) ? $correctOptionIds[0] : null;
                $correctText = !empty($correctOptionTexts) ? strtolower($correctOptionTexts[0]) : '';

                if (is_string($userAnswer) && in_array(strtolower($userAnswer), ['true', 'false'])) {
                    $isCorrect = (strtolower($userAnswer) === $correctText);
                } else {
                    $isCorrect = ((int)$userAnswer === $correctAnswer);
                }
                break;

            case 'multiple_choice':
                $correctAnswer = $correctOptionIds;
                if (is_array($userAnswer)) {
                    $userAnswerSorted = array_map('intval', $userAnswer);
                    sort($userAnswerSorted);
                    sort($correctAnswer);
                    $isCorrect = ($userAnswerSorted == $correctAnswer);
                }
                break;

            case 'short_answer':
            case 'fill_blank':
                $correctAnswer = !empty($correctOptionTexts) ? $correctOptionTexts[0] : '';
                $isCorrect = (strtolower(trim($userAnswer ?? '')) === strtolower(trim($correctAnswer)));
                break;

            case 'essay':
                // Essays need manual grading
                $correctAnswer = null;
                $isCorrect = null;
                break;

            default:
                $correctAnswer = !empty($correctOptionIds) ? $correctOptionIds[0] : null;
                $isCorrect = ($userAnswer == $correctAnswer);
        }

        if ($isCorrect === true) {
            $score += $points;
        }

        $questionResults[] = [
            'question_id' => $questionId,
            'user_answer' => $userAnswer,
            'correct_answer' => $correctAnswer,
            'is_correct' => $isCorrect,
            'points' => $isCorrect ? $points : 0,
            'explanation' => $q['explanation']
        ];
    }

    // Calculate percentage
    $percentage = $maxScore > 0 ? round(($score / $maxScore) * 100, 2) : 0;
    $passed = $percentage >= (float)$assessment['pass_percentage'];

    // Save attempt
    $insertQuery = "INSERT INTO assessment_attempts 
                    (user_id, tenant_id, enrollment_id, assessment_id, score, max_score,
                     percentage, passed, time_taken_seconds, answers, status, started_at, completed_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NOW(), NOW())";

    $answersJson = json_encode($questionResults);
    $passedInt = $passed ? 1 : 0;

    $stmt = $conn->prepare($insertQuery);
    $stmt->bind_param("iiiidddiss", $user_id, $tenant_id, $enrollment_id, $assessment_id,
        $score, $maxScore, $percentage, $passedInt, $time_taken, $answersJson);
    $stmt->execute();
    $attemptId = $conn->insert_id;
    $stmt->close();

    // Award points
    $pointsEarned = 0;
    if ($passed && function_exists('awardPoints')) {
        $pointsEarned = awardPoints($conn, $tenant_id, $user_id, 'quiz_passed', $assessment_id, 25);
    }

    return [
        'attempt_id' => $attemptId,
        'score' => $score,
        'max_score' => $maxScore,
        'percentage' => $percentage,
        'passed' => $passed,
        'passing_score' => (float)$assessment['pass_percentage'],
        'attempts_used' => $attempts + 1,
        'max_attempts' => (int)$assessment['max_attempts'],
        'points_earned' => $pointsEarned,
        'show_correct_answers' => (bool)$assessment['show_correct_answers'],
        'results' => $questionResults
    ];
}

/**
 * Load quiz data for player (assessment info + question count)
 */
function loadQuizData(mysqli $conn, int $tenant_id, int $user_id, int $assessment_id): array {
    // Get assessment details
    $assessQuery = "SELECT a.assessment_id, a.title, a.description, a.assessment_type,
                           a.time_limit_minutes, a.pass_percentage, a.max_attempts,
                           a.shuffle_questions, a.show_correct_answers, a.total_questions,
                           a.available_from, a.available_until
                    FROM assessments a
                    WHERE a.assessment_id = ? AND a.tenant_id = ? AND a.is_active = 1";

    $stmt = $conn->prepare($assessQuery);
    $stmt->bind_param("ii", $assessment_id, $tenant_id);
    $stmt->execute();
    $assessment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$assessment) {
        throw new Exception('Assessment not found');
    }

    // Count active questions if total_questions not set
    if (!$assessment['total_questions']) {
        $countQuery = "SELECT COUNT(*) as cnt FROM questions WHERE assessment_id = ? AND is_active = 1";
        $stmt = $conn->prepare($countQuery);
        $stmt->bind_param("i", $assessment_id);
        $stmt->execute();
        $assessment['total_questions'] = $stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();
    }

    // Get user's attempt history
    $attemptsQuery = "SELECT COUNT(*) as attempts_used, 
                             MAX(percentage) as best_score
                      FROM assessment_attempts 
                      WHERE assessment_id = ? AND user_id = ? AND tenant_id = ?";
    $stmt = $conn->prepare($attemptsQuery);
    $stmt->bind_param("iii", $assessment_id, $user_id, $tenant_id);
    $stmt->execute();
    $attemptInfo = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $attempts_used = (int)$attemptInfo['attempts_used'];
    $best_score = $attemptInfo['best_score'] !== null ? (float)$attemptInfo['best_score'] : 0;

    // Check if user can attempt
    $can_attempt = true;
    if ($assessment['max_attempts'] > 0 && $attempts_used >= $assessment['max_attempts']) {
        $can_attempt = false;
    }

    // Check availability window
    $now = date('Y-m-d H:i:s');
    if ($assessment['available_from'] && $now < $assessment['available_from']) {
        $can_attempt = false;
    }
    if ($assessment['available_until'] && $now > $assessment['available_until']) {
        $can_attempt = false;
    }

    // Get questions preview (without correct answers)
    $questionsQuery = "SELECT q.question_id, q.question_text, q.question_type, q.points, q.sequence_order
                       FROM questions q
                       WHERE q.assessment_id = ? AND q.is_active = 1
                       ORDER BY q.sequence_order";
    $stmt = $conn->prepare($questionsQuery);
    $stmt->bind_param("i", $assessment_id);
    $stmt->execute();
    $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get options for each question (without is_correct)
    foreach ($questions as &$question) {
        $optionsQuery = "SELECT option_id, option_text, sequence_order
                         FROM question_options
                         WHERE question_id = ?
                         ORDER BY sequence_order";
        $stmt = $conn->prepare($optionsQuery);
        $stmt->bind_param("i", $question['question_id']);
        $stmt->execute();
        $question['options'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    return [
        'assessment' => [
            'assessment_id' => (int)$assessment['assessment_id'],
            'title' => $assessment['title'],
            'description' => $assessment['description'],
            'assessment_type' => $assessment['assessment_type'],
            'time_limit_minutes' => (int)$assessment['time_limit_minutes'],
            'pass_percentage' => (float)$assessment['pass_percentage'],
            'max_attempts' => (int)$assessment['max_attempts'],
            'shuffle_questions' => (bool)$assessment['shuffle_questions'],
            'show_correct_answers' => (bool)$assessment['show_correct_answers'],
            'total_questions' => (int)$assessment['total_questions']
        ],
        'questions' => $questions,
        'attempts_used' => $attempts_used,
        'best_score' => $best_score,
        'can_attempt' => $can_attempt
    ];
}

/**
 * Start a quiz attempt
 */
function startQuizAttempt(mysqli $conn, int $tenant_id, int $user_id, int $enrollment_id, int $assessment_id): array {
    // Get assessment details
    $assessQuery = "SELECT assessment_id, title, time_limit_minutes, max_attempts, shuffle_questions
                    FROM assessments
                    WHERE assessment_id = ? AND tenant_id = ? AND is_active = 1";

    $stmt = $conn->prepare($assessQuery);
    $stmt->bind_param("ii", $assessment_id, $tenant_id);
    $stmt->execute();
    $assessment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$assessment) {
        throw new Exception('Assessment not found');
    }

    // Check attempts
    $attemptsQuery = "SELECT COUNT(*) as attempts FROM assessment_attempts 
                      WHERE assessment_id = ? AND user_id = ? AND tenant_id = ?";
    $stmt = $conn->prepare($attemptsQuery);
    $stmt->bind_param("iii", $assessment_id, $user_id, $tenant_id);
    $stmt->execute();
    $attempts = (int)$stmt->get_result()->fetch_assoc()['attempts'];
    $stmt->close();

    if ($assessment['max_attempts'] > 0 && $attempts >= $assessment['max_attempts']) {
        throw new Exception('Maximum attempts reached');
    }

    // Create attempt record
    $insertQuery = "INSERT INTO assessment_attempts 
                    (user_id, tenant_id, enrollment_id, assessment_id, started_at, status)
                    VALUES (?, ?, ?, ?, NOW(), 'in_progress')";
    $stmt = $conn->prepare($insertQuery);
    $stmt->bind_param("iiii", $user_id, $tenant_id, $enrollment_id, $assessment_id);
    $stmt->execute();
    $attempt_id = $conn->insert_id;
    $stmt->close();

    // Get questions
    $questionsQuery = "SELECT q.question_id, q.question_text, q.question_type, q.points, q.sequence_order
                       FROM questions q
                       WHERE q.assessment_id = ? AND q.is_active = 1
                       ORDER BY q.sequence_order";
    $stmt = $conn->prepare($questionsQuery);
    $stmt->bind_param("i", $assessment_id);
    $stmt->execute();
    $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get options for each question (without is_correct)
    foreach ($questions as &$question) {
        $optionsQuery = "SELECT option_id, option_text, sequence_order
                         FROM question_options
                         WHERE question_id = ?
                         ORDER BY sequence_order";
        $stmt = $conn->prepare($optionsQuery);
        $stmt->bind_param("i", $question['question_id']);
        $stmt->execute();
        $question['options'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Shuffle options for each question if shuffle is enabled
        if ($assessment['shuffle_questions']) {
            shuffle($question['options']);
        }
    }

    // Shuffle questions if enabled
    if ($assessment['shuffle_questions']) {
        shuffle($questions);
    }

    return [
        'attempt_id' => $attempt_id,
        'started_at' => date('Y-m-d H:i:s'),
        'time_limit_minutes' => (int)$assessment['time_limit_minutes'],
        'questions' => $questions
    ];
}

/**
 * Submit quiz answers and grade
 */
function submitQuizAnswers(mysqli $conn, int $tenant_id, int $user_id, int $enrollment_id,
                           int $assessment_id, int $attempt_id, array $answers, int $time_taken): array {

    // Verify attempt belongs to user and is in progress
    $attemptQuery = "SELECT attempt_id, started_at FROM assessment_attempts 
                     WHERE attempt_id = ? AND user_id = ? AND assessment_id = ? 
                     AND (status = 'in_progress' OR status IS NULL)";
    $stmt = $conn->prepare($attemptQuery);
    $stmt->bind_param("iii", $attempt_id, $user_id, $assessment_id);
    $stmt->execute();
    $attempt = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$attempt) {
        throw new Exception('Invalid attempt or already submitted');
    }

    // Get assessment info
    $assessQuery = "SELECT pass_percentage, max_attempts, show_correct_answers
                    FROM assessments WHERE assessment_id = ? AND tenant_id = ?";
    $stmt = $conn->prepare($assessQuery);
    $stmt->bind_param("ii", $assessment_id, $tenant_id);
    $stmt->execute();
    $assessment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$assessment) {
        throw new Exception('Assessment not found');
    }

    // Get all questions with correct answers
    $questionsQuery = "SELECT q.question_id, q.question_type, q.points, q.explanation
                       FROM questions q
                       WHERE q.assessment_id = ? AND q.is_active = 1";
    $stmt = $conn->prepare($questionsQuery);
    $stmt->bind_param("i", $assessment_id);
    $stmt->execute();
    $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Index questions by ID
    $questionMap = [];
    foreach ($questions as $q) {
        $questionMap[$q['question_id']] = $q;
    }

    // Get correct answers for all questions
    $correctAnswers = [];
    foreach ($questions as $q) {
        $optionsQuery = "SELECT option_id, is_correct FROM question_options 
                         WHERE question_id = ? AND is_correct = 1";
        $stmt = $conn->prepare($optionsQuery);
        $stmt->bind_param("i", $q['question_id']);
        $stmt->execute();
        $correctOpts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // For single choice / true_false: single option_id
        // For multiple choice: array of option_ids
        if ($q['question_type'] === 'multiple_choice') {
            $correctAnswers[$q['question_id']] = array_column($correctOpts, 'option_id');
        } else {
            $correctAnswers[$q['question_id']] = !empty($correctOpts) ? $correctOpts[0]['option_id'] : null;
        }
    }

    // Grade each answer
    $score = 0;
    $maxScore = 0;
    $questionResults = [];

    foreach ($questions as $q) {
        $questionId = $q['question_id'];
        $points = (float)$q['points'];
        $maxScore += $points;

        $userAnswer = $answers[$questionId] ?? null;
        $correctAnswer = $correctAnswers[$questionId] ?? null;
        $isCorrect = false;

        // Grade based on question type
        switch ($q['question_type']) {
            case 'single_choice':
            case 'true_false':
                // Handle true_false string answers
                if ($q['question_type'] === 'true_false') {
                    // Get the correct option text
                    $optQuery = "SELECT option_id, option_text FROM question_options WHERE question_id = ? AND is_correct = 1";
                    $stmt = $conn->prepare($optQuery);
                    $stmt->bind_param("i", $questionId);
                    $stmt->execute();
                    $correctOpt = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    // User might answer with 'true'/'false' string or option_id
                    if (is_string($userAnswer)) {
                        $correctText = strtolower($correctOpt['option_text'] ?? '');
                        $isCorrect = (strtolower($userAnswer) === $correctText);
                    } else {
                        $isCorrect = ((int)$userAnswer === (int)$correctAnswer);
                    }
                } else {
                    $isCorrect = ((int)$userAnswer === (int)$correctAnswer);
                }
                break;

            case 'multiple_choice':
                // User answer should be array of option_ids
                if (is_array($userAnswer) && is_array($correctAnswer)) {
                    sort($userAnswer);
                    sort($correctAnswer);
                    $isCorrect = ($userAnswer == $correctAnswer);
                }
                break;

            case 'short_answer':
            case 'fill_blank':
                // Get correct answer text
                $optQuery = "SELECT option_text FROM question_options WHERE question_id = ? AND is_correct = 1";
                $stmt = $conn->prepare($optQuery);
                $stmt->bind_param("i", $questionId);
                $stmt->execute();
                $correctOpt = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($correctOpt) {
                    // Case-insensitive comparison, trim whitespace
                    $isCorrect = (strtolower(trim($userAnswer)) === strtolower(trim($correctOpt['option_text'])));
                }
                break;

            case 'essay':
                // Essays need manual grading - mark as pending
                $isCorrect = null; // null = needs review
                break;
        }

        if ($isCorrect === true) {
            $score += $points;
        }

        $questionResults[] = [
            'question_id' => $questionId,
            'user_answer' => $userAnswer,
            'correct_answer' => $correctAnswer,
            'is_correct' => $isCorrect,
            'points' => $isCorrect ? $points : 0,
            'explanation' => $q['explanation']
        ];
    }

    // Calculate percentage
    $percentage = $maxScore > 0 ? round(($score / $maxScore) * 100, 2) : 0;
    $passed = $percentage >= (float)$assessment['pass_percentage'];

    // Update attempt record
    $updateQuery = "UPDATE assessment_attempts SET 
                    score = ?, max_score = ?, percentage = ?, passed = ?,
                    time_taken_seconds = ?, answers = ?, completed_at = NOW(), status = 'completed'
                    WHERE attempt_id = ?";

    $answersJson = json_encode($questionResults);
    $passedInt = $passed ? 1 : 0;

    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("dddisis", $score, $maxScore, $percentage, $passedInt, $time_taken, $answersJson, $attempt_id);
    $stmt->execute();
    $stmt->close();

    // Get updated attempts count
    $attemptsQuery = "SELECT COUNT(*) as attempts FROM assessment_attempts 
                      WHERE assessment_id = ? AND user_id = ? AND status = 'completed'";
    $stmt = $conn->prepare($attemptsQuery);
    $stmt->bind_param("ii", $assessment_id, $user_id);
    $stmt->execute();
    $attemptsUsed = (int)$stmt->get_result()->fetch_assoc()['attempts'];
    $stmt->close();

    // Award points for passing
    $pointsEarned = 0;
    if ($passed) {
        // Check if this is first time passing
        $firstPassQuery = "SELECT COUNT(*) as pass_count FROM assessment_attempts 
                           WHERE assessment_id = ? AND user_id = ? AND passed = 1 AND attempt_id < ?";
        $stmt = $conn->prepare($firstPassQuery);
        $stmt->bind_param("iii", $assessment_id, $user_id, $attempt_id);
        $stmt->execute();
        $previousPasses = (int)$stmt->get_result()->fetch_assoc()['pass_count'];
        $stmt->close();

        if ($previousPasses === 0) {
            // First time passing - award points
            if (function_exists('awardPoints')) {
                $pointsEarned = awardPoints($conn, $tenant_id, $user_id, 'quiz_passed', $assessment_id, 25);
            }
        }
    }

    return [
        'attempt_id' => $attempt_id,
        'score' => $score,
        'max_score' => $maxScore,
        'percentage' => $percentage,
        'passed' => $passed,
        'passing_score' => (float)$assessment['pass_percentage'],
        'attempts_used' => $attemptsUsed,
        'max_attempts' => (int)$assessment['max_attempts'],
        'points_earned' => $pointsEarned,
        'show_correct_answers' => (bool)$assessment['show_correct_answers'],
        'results' => $questionResults
    ];
}

/**
 * Log interaction
 */
function logInteraction(mysqli $conn, int $tenant_id, int $user_id, int $enrollment_id,
                        int $lesson_id, string $type, array $data): void {

    $query = "INSERT INTO learning_interactions 
              (user_id, tenant_id, enrollment_id, lesson_id, interaction_type, interaction_data, created_at)
              VALUES (?, ?, ?, ?, ?, ?, NOW())";

    $dataJson = json_encode($data);

    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiiiss", $user_id, $tenant_id, $enrollment_id, $lesson_id, $type, $dataJson);
    $stmt->execute();
    $stmt->close();
}

/**
 * Rate course
 */
function rateCourse(mysqli $conn, int $tenant_id, int $user_id, int $course_id,
                    int $rating, ?string $review): array {

    $query = "INSERT INTO course_reviews (user_id, tenant_id, course_id, rating, review_text, created_at)
              VALUES (?, ?, ?, ?, ?, NOW())
              ON DUPLICATE KEY UPDATE rating = VALUES(rating), review_text = VALUES(review_text), updated_at = NOW()";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiiis", $user_id, $tenant_id, $course_id, $rating, $review);
    $stmt->execute();
    $stmt->close();

    // Update course average rating
    $avgQuery = "UPDATE courses c SET 
                   average_rating = (SELECT AVG(rating) FROM course_reviews WHERE course_id = ?),
                   rating_count = (SELECT COUNT(*) FROM course_reviews WHERE course_id = ?)
                 WHERE course_id = ?";
    $stmt = $conn->prepare($avgQuery);
    $stmt->bind_param("iii", $course_id, $course_id, $course_id);
    $stmt->execute();
    $stmt->close();

    // Award points for rating
    awardPoints($conn, $tenant_id, $user_id, 'course_rated', $course_id, 5);

    return ['rating' => $rating, 'review' => $review];
}

/**
 * Get resume position
 */
function getResumePosition(mysqli $conn, int $tenant_id, int $user_id, int $enrollment_id): array {
    // Get last accessed lesson
    $query = "SELECT ce.last_lesson_id, l.title as lesson_title,
                lp.last_position, lp.progress_percentage
              FROM course_enrollments ce
              LEFT JOIN lessons l ON ce.last_lesson_id = l.lesson_id
              LEFT JOIN lesson_progress lp ON ce.last_lesson_id = lp.lesson_id 
                AND lp.enrollment_id = ce.enrollment_id
              WHERE ce.enrollment_id = ? AND ce.user_id = ?";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $enrollment_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // If no last lesson, get first incomplete lesson
    if (!$result['last_lesson_id']) {
        $firstQuery = "SELECT l.lesson_id, l.title
                       FROM lessons l
                       LEFT JOIN lesson_progress lp ON l.lesson_id = lp.lesson_id 
                         AND lp.enrollment_id = ?
                       WHERE l.course_id = (SELECT course_id FROM course_enrollments WHERE enrollment_id = ?)
                         AND l.is_active = 1
                         AND (lp.status IS NULL OR lp.status != 'completed')
                       ORDER BY l.sequence_order
                       LIMIT 1";
        $stmt = $conn->prepare($firstQuery);
        $stmt->bind_param("ii", $enrollment_id, $enrollment_id);
        $stmt->execute();
        $first = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return [
            'lesson_id' => $first['lesson_id'] ?? null,
            'lesson_title' => $first['title'] ?? null,
            'position' => 0,
            'progress' => 0
        ];
    }

    return [
        'lesson_id' => $result['last_lesson_id'],
        'lesson_title' => $result['lesson_title'],
        'position' => (int)($result['last_position'] ?? 0),
        'progress' => (int)($result['progress_percentage'] ?? 0)
    ];
}

/**
 * Save SCORM data
 * Note: This only saves the SCORM CMI data and updates progress.
 * Actual lesson completion should happen via complete-scorm-lesson endpoint
 * when SCORM content reports 'passed' or 'completed' status.
 */
function saveScormData(mysqli $conn, int $tenant_id, int $user_id, int $enrollment_id,
                       int $lesson_id, array $scormData): void {

    $query = "INSERT INTO scorm_tracking 
              (user_id, tenant_id, enrollment_id, lesson_id, cmi_data, updated_at)
              VALUES (?, ?, ?, ?, ?, NOW())
              ON DUPLICATE KEY UPDATE cmi_data = VALUES(cmi_data), updated_at = NOW()";

    $dataJson = json_encode($scormData);

    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiiis", $user_id, $tenant_id, $enrollment_id, $lesson_id, $dataJson);
    $stmt->execute();
    $stmt->close();

    // Extract progress from SCORM data (but don't auto-complete)
    $progress = 0;
    $score = null;
    $status = 'in_progress';

    // Calculate progress based on SCORM status
    if (isset($scormData['cmi.core.lesson_status'])) {
        $scormStatus = strtolower($scormData['cmi.core.lesson_status']);

        // Map SCORM status to progress percentage
        switch ($scormStatus) {
            case 'not attempted':
                $progress = 0;
                $status = 'not_started';
                break;
            case 'incomplete':
                $progress = 50; // In progress
                $status = 'in_progress';
                break;
            case 'completed':
            case 'passed':
                // Don't auto-complete here - let complete-scorm-lesson handle it
                // This allows proper score tracking and gamification
                $progress = 95; // Almost complete, waiting for explicit completion
                $status = 'in_progress';
                break;
            case 'failed':
                $progress = 100;
                $status = 'failed';
                break;
            default:
                $progress = 25;
                $status = 'in_progress';
        }
    }

    if (isset($scormData['cmi.core.score.raw'])) {
        $score = (int)$scormData['cmi.core.score.raw'];
    }

    // Update lesson progress (don't mark as completed - that's handled by complete-scorm-lesson)
    $updateQuery = "INSERT INTO lesson_progress 
                    (user_id, tenant_id, enrollment_id, lesson_id, status, progress_percentage, score, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                      status = CASE WHEN status = 'completed' THEN status ELSE VALUES(status) END,
                      progress_percentage = CASE WHEN status = 'completed' THEN progress_percentage ELSE GREATEST(progress_percentage, VALUES(progress_percentage)) END,
                      score = COALESCE(VALUES(score), score),
                      updated_at = NOW()";

    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("iiiisii", $user_id, $tenant_id, $enrollment_id, $lesson_id,
        $status, $progress, $score);
    $stmt->execute();
    $stmt->close();
}

/**
 * Award points
 */
function awardPoints(mysqli $conn, int $tenant_id, int $user_id, string $action,
                     int $reference_id, int $points): int {

    // Check if already awarded for this action/reference
    $checkQuery = "SELECT point_id FROM user_points 
                   WHERE user_id = ? AND tenant_id = ? AND action = ? AND reference_id = ?";
    $stmt = $conn->prepare($checkQuery);
    $stmt->bind_param("iisi", $user_id, $tenant_id, $action, $reference_id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $stmt->close();
        return 0; // Already awarded
    }
    $stmt->close();

    // Award points
    $insertQuery = "INSERT INTO user_points 
                    (user_id, tenant_id, points, action, reference_id, earned_at)
                    VALUES (?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($insertQuery);
    $stmt->bind_param("iiisi", $user_id, $tenant_id, $points, $action, $reference_id);
    $stmt->execute();
    $stmt->close();

    return $points;
}

/**
 * Check badge eligibility
 */
function checkBadgeEligibility(mysqli $conn, int $tenant_id, int $user_id): array {
    $earnedBadges = [];

    // Get user stats
    $statsQuery = "SELECT 
                     (SELECT COUNT(*) FROM course_enrollments WHERE user_id = ? AND tenant_id = ? AND status = 'completed') as courses_completed,
                     (SELECT COALESCE(SUM(points), 0) FROM user_points WHERE user_id = ? AND tenant_id = ?) as total_points,
                     (SELECT COUNT(*) FROM lesson_progress WHERE user_id = ? AND tenant_id = ? AND status = 'completed') as lessons_completed";

    $stmt = $conn->prepare($statsQuery);
    $stmt->bind_param("iiiiii", $user_id, $tenant_id, $user_id, $tenant_id, $user_id, $tenant_id);
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Get badges user hasn't earned yet
    $badgesQuery = "SELECT b.* FROM badges b
                    WHERE b.tenant_id = ? AND b.is_active = 1
                    AND b.badge_id NOT IN (SELECT badge_id FROM user_badges WHERE user_id = ?)";
    $stmt = $conn->prepare($badgesQuery);
    $stmt->bind_param("ii", $tenant_id, $user_id);
    $stmt->execute();
    $badges = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($badges as $badge) {
        $criteria = json_decode($badge['criteria'], true) ?? [];
        $eligible = false;

        // Check criteria
        if (isset($criteria['courses_completed']) && $stats['courses_completed'] >= $criteria['courses_completed']) {
            $eligible = true;
        }
        if (isset($criteria['points']) && $stats['total_points'] >= $criteria['points']) {
            $eligible = true;
        }
        if (isset($criteria['lessons_completed']) && $stats['lessons_completed'] >= $criteria['lessons_completed']) {
            $eligible = true;
        }

        if ($eligible) {
            // Award badge
            $awardQuery = "INSERT INTO user_badges (user_id, tenant_id, badge_id, earned_at)
                           VALUES (?, ?, ?, NOW())";
            $stmt = $conn->prepare($awardQuery);
            $stmt->bind_param("iii", $user_id, $tenant_id, $badge['badge_id']);
            $stmt->execute();
            $stmt->close();

            $earnedBadges[] = [
                'badge_id' => $badge['badge_id'],
                'badge_name' => $badge['badge_name'],
                'icon' => $badge['icon']
            ];
        }
    }

    return $earnedBadges;
}

/**
 * Update learning streak
 */
function updateLearningStreak(mysqli $conn, int $tenant_id, int $user_id): void {
    // Get last learning date
    $lastQuery = "SELECT DATE(MAX(started_at)) as last_date FROM learning_sessions
                  WHERE user_id = ? AND tenant_id = ? AND DATE(started_at) < CURDATE()";
    $stmt = $conn->prepare($lastQuery);
    $stmt->bind_param("ii", $user_id, $tenant_id);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $lastDate = $last['last_date'];
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    // Get current streak
    $streakQuery = "SELECT current_streak, longest_streak FROM user_streaks WHERE user_id = ? AND tenant_id = ?";
    $stmt = $conn->prepare($streakQuery);
    $stmt->bind_param("ii", $user_id, $tenant_id);
    $stmt->execute();
    $streak = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $currentStreak = $streak['current_streak'] ?? 0;
    $longestStreak = $streak['longest_streak'] ?? 0;

    if ($lastDate === $yesterday) {
        // Continue streak
        $currentStreak++;
    } elseif ($lastDate !== $today) {
        // Streak broken, start new
        $currentStreak = 1;
    }

    $longestStreak = max($longestStreak, $currentStreak);

    // Update streak
    $updateQuery = "INSERT INTO user_streaks (user_id, tenant_id, current_streak, longest_streak, last_activity_date)
                    VALUES (?, ?, ?, ?, CURDATE())
                    ON DUPLICATE KEY UPDATE
                      current_streak = VALUES(current_streak),
                      longest_streak = VALUES(longest_streak),
                      last_activity_date = VALUES(last_activity_date)";

    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("iiii", $user_id, $tenant_id, $currentStreak, $longestStreak);
    $stmt->execute();
    $stmt->close();
}

/**
 * Generate certificate
 */
function generateCertificate(mysqli $conn, int $tenant_id, int $user_id, int $enrollment_id,
                             int $course_id, int $template_id): int {

    $certNumber = 'CERT-' . strtoupper(substr(md5(uniqid()), 0, 8)) . '-' . date('Y');

    $query = "INSERT INTO certificates 
              (user_id, tenant_id, enrollment_id, course_id, template_id,
               certificate_number, issue_date, expiry_date)
              VALUES (?, ?, ?, ?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR))";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiiiss", $user_id, $tenant_id, $enrollment_id, $course_id, $template_id, $certNumber);
    $stmt->execute();
    $certId = $conn->insert_id;
    $stmt->close();

    return $certId;
}

/**
 * Detect device type
 */
function detectDeviceType(): string {
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
 * Complete SCORM lesson with status and score
 * This is called when SCORM content reports passed/completed status
 */
function completeScormLesson(mysqli $conn, int $tenant_id, int $user_id, int $enrollment_id,
                             int $lesson_id, string $scorm_status, ?float $scorm_score): array {

    // Get the SCORM tracking data for this lesson
    $scormQuery = "SELECT cmi_data FROM scorm_tracking 
                   WHERE enrollment_id = ? AND lesson_id = ? AND user_id = ?";
    $stmt = $conn->prepare($scormQuery);
    $stmt->bind_param("iii", $enrollment_id, $lesson_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $cmiData = $result ? json_decode($result['cmi_data'], true) : [];

    // Extract additional data from CMI if score not provided directly
    if ($scorm_score === null && isset($cmiData['cmi.core.score.raw'])) {
        $scorm_score = (float)$cmiData['cmi.core.score.raw'];
    }

    // Get max score if available
    $maxScore = 100;
    if (isset($cmiData['cmi.core.score.max'])) {
        $maxScore = (float)$cmiData['cmi.core.score.max'];
    }

    // Calculate percentage
    $percentage = $maxScore > 0 ? ($scorm_score / $maxScore) * 100 : 0;

    // Update lesson progress to completed
    $updateQuery = "INSERT INTO lesson_progress 
                    (user_id, tenant_id, enrollment_id, lesson_id, status, progress_percentage, score, completed_at, updated_at)
                    VALUES (?, ?, ?, ?, 'completed', 100, ?, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                      status = 'completed',
                      progress_percentage = 100,
                      score = VALUES(score),
                      completed_at = COALESCE(completed_at, NOW()),
                      updated_at = NOW()";

    $scoreInt = $scorm_score !== null ? (int)$scorm_score : null;
    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("iiiii", $user_id, $tenant_id, $enrollment_id, $lesson_id, $scoreInt);
    $stmt->execute();
    $stmt->close();

    // Update course enrollment progress
    updateCourseProgress($conn, $tenant_id, $enrollment_id);

    // Award points for SCORM completion
    awardPoints($conn, $tenant_id, $user_id, 'scorm_completion', $lesson_id, 15);

    // Check for badge eligibility
    checkBadgeEligibility($conn, $tenant_id, $user_id);

    // Store SCORM result in assessment_attempts if there's a score
    if ($scorm_score !== null) {
        $attemptQuery = "INSERT INTO assessment_attempts 
                         (user_id, tenant_id, enrollment_id, assessment_id, score, max_score, percentage, passed, completed_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE
                           score = VALUES(score),
                           max_score = VALUES(max_score),
                           percentage = VALUES(percentage),
                           passed = VALUES(passed),
                           completed_at = NOW()";

        $passed = in_array(strtolower($scorm_status), ['passed', 'completed']) ? 1 : 0;

        // Use lesson_id as assessment_id for SCORM content
        $stmt = $conn->prepare($attemptQuery);
        $stmt->bind_param("iiiidddi", $user_id, $tenant_id, $enrollment_id, $lesson_id,
            $scorm_score, $maxScore, $percentage, $passed);
        $stmt->execute();
        $stmt->close();
    }

    return [
        'lesson_id' => $lesson_id,
        'status' => 'completed',
        'scorm_status' => $scorm_status,
        'score' => $scorm_score,
        'max_score' => $maxScore,
        'percentage' => $percentage
    ];
}

/**
 * Update course enrollment progress based on completed lessons
 */
function updateCourseProgress(mysqli $conn, int $tenant_id, int $enrollment_id): void {
    // Get total lessons and completed lessons
    $query = "SELECT 
                ce.course_id,
                (SELECT COUNT(*) FROM lessons l 
                 JOIN modules m ON l.module_id = m.module_id 
                 WHERE m.course_id = ce.course_id AND l.is_active = 1 AND m.is_active = 1) as total_lessons,
                (SELECT COUNT(*) FROM lesson_progress lp 
                 JOIN lessons l ON lp.lesson_id = l.lesson_id
                 JOIN modules m ON l.module_id = m.module_id
                 WHERE lp.enrollment_id = ce.enrollment_id 
                 AND lp.status = 'completed'
                 AND m.course_id = ce.course_id) as completed_lessons
              FROM course_enrollments ce
              WHERE ce.enrollment_id = ?";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $enrollment_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$result) return;

    $totalLessons = $result['total_lessons'];
    $completedLessons = $result['completed_lessons'];
    $progress = $totalLessons > 0 ? ($completedLessons / $totalLessons) * 100 : 0;

    // Update enrollment progress
    $updateQuery = "UPDATE course_enrollments 
                    SET progress_percentage = ?,
                        status = CASE WHEN ? >= 100 THEN 'completed' ELSE status END,
                        completed_at = CASE WHEN ? >= 100 AND completed_at IS NULL THEN NOW() ELSE completed_at END,
                        updated_at = NOW()
                    WHERE enrollment_id = ?";

    $stmt = $conn->prepare($updateQuery);
    $stmt->bind_param("dddi", $progress, $progress, $progress, $enrollment_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Track content interaction (video, document, SCORM)
 */
function trackContentInteraction(mysqli $conn, int $tenant_id, int $user_id, int $course_id,
                                 int $lesson_id, string $interaction_type,
                                 ?int $position_seconds, ?int $duration_seconds): void {

    $query = "INSERT INTO content_interactions 
              (tenant_id, course_id, lesson_id, user_id, interaction_type, position_seconds, duration_seconds, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($query);
    $stmt->bind_param("iiiisii", $tenant_id, $course_id, $lesson_id, $user_id,
        $interaction_type, $position_seconds, $duration_seconds);
    $stmt->execute();
    $stmt->close();
}