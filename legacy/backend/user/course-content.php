<?php
/**
 * Course Content Management API
 * Handles modules, lessons, assessments, and questions
 */

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
        // GET MEDIA FILES FOR CONTENT SELECTION
        // ==========================================
        case 'get-media':
            $media_type = $_GET['media_type'] ?? null;
            $media = getMediaFiles($conn, $tenant_id, $media_type);
            $db->send_response(200, 'success', $media, 'Media files retrieved');
            break;

        // ==========================================
        // LOAD COURSE CONTENT
        // ==========================================
        case 'load':
            $course_id = (int)($_GET['course_id'] ?? 0);
            if (!$course_id) {
                $db->send_response(400, 'error', null, 'course_id required');
                exit;
            }
            $data = loadCourseContent($conn, $tenant_id, $course_id);
            $db->send_response(200, 'success', $data, 'Course content loaded');
            break;

        // ==========================================
        // MODULE OPERATIONS
        // ==========================================
        case 'get-modules':
            $course_id = (int)($_GET['course_id'] ?? 0);
            if (!$course_id) {
                $db->send_response(400, 'error', null, 'course_id required');
                exit;
            }
            $modules = getModules($conn, $tenant_id, $course_id);
            $db->send_response(200, 'success', $modules, 'Modules retrieved');
            break;

        case 'create-module':
            $course_id = (int)($input['course_id'] ?? 0);
            $title = trim($input['title'] ?? '');
            $description = trim($input['description'] ?? '');
            $is_active = (bool)($input['is_active'] ?? true);

            if (!$course_id || !$title) {
                $db->send_response(400, 'error', null, 'course_id and title required');
                exit;
            }

            $module = createModule($conn, $tenant_id, $course_id, $title, $description, $is_active);
            $db->send_response(200, 'success', $module, 'Module created');
            break;

        case 'update-module':
            $module_id = (int)($input['module_id'] ?? 0);
            $title = trim($input['title'] ?? '');
            $description = trim($input['description'] ?? '');
            $is_active = (bool)($input['is_active'] ?? true);

            if (!$module_id || !$title) {
                $db->send_response(400, 'error', null, 'module_id and title required');
                exit;
            }

            $module = updateModule($conn, $tenant_id, $module_id, $title, $description, $is_active);
            $db->send_response(200, 'success', $module, 'Module updated');
            break;

        case 'delete-module':
            $module_id = (int)($input['module_id'] ?? 0);
            if (!$module_id) {
                $db->send_response(400, 'error', null, 'module_id required');
                exit;
            }
            deleteModule($conn, $tenant_id, $module_id);
            $db->send_response(200, 'success', null, 'Module deleted');
            break;

        case 'reorder-modules':
            $course_id = (int)($input['course_id'] ?? 0);
            $module_ids = $input['module_ids'] ?? [];
            if (!$course_id || empty($module_ids)) {
                $db->send_response(400, 'error', null, 'course_id and module_ids required');
                exit;
            }
            reorderModules($conn, $tenant_id, $course_id, $module_ids);
            $db->send_response(200, 'success', null, 'Modules reordered');
            break;

        case 'duplicate-module':
            $module_id = (int)($input['module_id'] ?? 0);
            if (!$module_id) {
                $db->send_response(400, 'error', null, 'module_id required');
                exit;
            }
            $module = duplicateModule($conn, $tenant_id, $module_id);
            $db->send_response(200, 'success', $module, 'Module duplicated');
            break;

        // ==========================================
        // LESSON OPERATIONS
        // ==========================================
        case 'get-lessons':
            $module_id = (int)($_GET['module_id'] ?? 0);
            if (!$module_id) {
                $db->send_response(400, 'error', null, 'module_id required');
                exit;
            }
            $lessons = getLessons($conn, $tenant_id, $module_id);
            $db->send_response(200, 'success', $lessons, 'Lessons retrieved');
            break;

        case 'create-lesson':
            $module_id = (int)($input['module_id'] ?? 0);
            $title = trim($input['title'] ?? '');

            if (!$module_id || !$title) {
                $db->send_response(400, 'error', null, 'module_id and title required');
                exit;
            }

            $lesson = createLesson($conn, $tenant_id, $input);
            $db->send_response(200, 'success', $lesson, 'Lesson created');
            break;

        case 'update-lesson':
            $lesson_id = (int)($input['lesson_id'] ?? 0);
            $title = trim($input['title'] ?? '');

            if (!$lesson_id || !$title) {
                $db->send_response(400, 'error', null, 'lesson_id and title required');
                exit;
            }

            $lesson = updateLesson($conn, $tenant_id, $lesson_id, $input);
            $db->send_response(200, 'success', $lesson, 'Lesson updated');
            break;

        case 'delete-lesson':
            $lesson_id = (int)($input['lesson_id'] ?? 0);
            if (!$lesson_id) {
                $db->send_response(400, 'error', null, 'lesson_id required');
                exit;
            }
            deleteLesson($conn, $tenant_id, $lesson_id);
            $db->send_response(200, 'success', null, 'Lesson deleted');
            break;

        case 'reorder-lessons':
            $module_id = (int)($input['module_id'] ?? 0);
            $lesson_ids = $input['lesson_ids'] ?? [];
            if (!$module_id || empty($lesson_ids)) {
                $db->send_response(400, 'error', null, 'module_id and lesson_ids required');
                exit;
            }
            reorderLessons($conn, $tenant_id, $module_id, $lesson_ids);
            $db->send_response(200, 'success', null, 'Lessons reordered');
            break;

        case 'move-lesson':
            $lesson_id = (int)($input['lesson_id'] ?? 0);
            $target_module_id = (int)($input['target_module_id'] ?? 0);
            if (!$lesson_id || !$target_module_id) {
                $db->send_response(400, 'error', null, 'lesson_id and target_module_id required');
                exit;
            }
            moveLesson($conn, $tenant_id, $lesson_id, $target_module_id);
            $db->send_response(200, 'success', null, 'Lesson moved');
            break;

        case 'duplicate-lesson':
            $lesson_id = (int)($input['lesson_id'] ?? 0);
            if (!$lesson_id) {
                $db->send_response(400, 'error', null, 'lesson_id required');
                exit;
            }
            $lesson = duplicateLesson($conn, $tenant_id, $lesson_id);
            $db->send_response(200, 'success', $lesson, 'Lesson duplicated');
            break;

        // ==========================================
        // ASSESSMENT OPERATIONS
        // ==========================================
        case 'get-assessments':
            $module_id = isset($_GET['module_id']) ? (int)$_GET['module_id'] : null;
            $course_id = isset($_GET['course_id']) ? (int)$_GET['course_id'] : null;
            $assessments = getAssessments($conn, $tenant_id, $module_id, $course_id);
            $db->send_response(200, 'success', $assessments, 'Assessments retrieved');
            break;

        case 'create-assessment':
            $course_id = (int)($input['course_id'] ?? 0);
            $title = trim($input['title'] ?? '');

            if (!$course_id || !$title) {
                $db->send_response(400, 'error', null, 'course_id and title required');
                exit;
            }

            $assessment = createAssessment($conn, $tenant_id, $input);
            $db->send_response(200, 'success', $assessment, 'Assessment created');
            break;

        case 'update-assessment':
            $assessment_id = (int)($input['assessment_id'] ?? 0);
            $title = trim($input['title'] ?? '');

            if (!$assessment_id || !$title) {
                $db->send_response(400, 'error', null, 'assessment_id and title required');
                exit;
            }

            $assessment = updateAssessment($conn, $tenant_id, $assessment_id, $input);
            $db->send_response(200, 'success', $assessment, 'Assessment updated');
            break;

        case 'delete-assessment':
            $assessment_id = (int)($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'assessment_id required');
                exit;
            }
            deleteAssessment($conn, $tenant_id, $assessment_id);
            $db->send_response(200, 'success', null, 'Assessment deleted');
            break;

        case 'duplicate-assessment':
            $assessment_id = (int)($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'assessment_id required');
                exit;
            }
            $assessment = duplicateAssessment($conn, $tenant_id, $assessment_id);
            $db->send_response(200, 'success', $assessment, 'Assessment duplicated');
            break;

        // ==========================================
        // QUESTION OPERATIONS
        // ==========================================
        case 'get-questions':
            $assessment_id = (int)($_GET['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'assessment_id required');
                exit;
            }
            $questions = getQuestions($conn, $tenant_id, $assessment_id);
            $db->send_response(200, 'success', $questions, 'Questions retrieved');
            break;

        case 'create-question':
            $assessment_id = (int)($input['assessment_id'] ?? 0);
            $question_text = trim($input['question_text'] ?? '');

            if (!$assessment_id || !$question_text) {
                $db->send_response(400, 'error', null, 'assessment_id and question_text required');
                exit;
            }

            $question = createQuestion($conn, $tenant_id, $input);
            $db->send_response(200, 'success', $question, 'Question created');
            break;

        case 'update-question':
            $question_id = (int)($input['question_id'] ?? 0);
            $question_text = trim($input['question_text'] ?? '');

            if (!$question_id || !$question_text) {
                $db->send_response(400, 'error', null, 'question_id and question_text required');
                exit;
            }

            $question = updateQuestion($conn, $tenant_id, $question_id, $input);
            $db->send_response(200, 'success', $question, 'Question updated');
            break;

        case 'delete-question':
            $question_id = (int)($input['question_id'] ?? 0);
            if (!$question_id) {
                $db->send_response(400, 'error', null, 'question_id required');
                exit;
            }
            deleteQuestion($conn, $tenant_id, $question_id);
            $db->send_response(200, 'success', null, 'Question deleted');
            break;

        case 'reorder-questions':
            $assessment_id = (int)($input['assessment_id'] ?? 0);
            $question_ids = $input['question_ids'] ?? [];
            if (!$assessment_id || empty($question_ids)) {
                $db->send_response(400, 'error', null, 'assessment_id and question_ids required');
                exit;
            }
            reorderQuestions($conn, $tenant_id, $assessment_id, $question_ids);
            $db->send_response(200, 'success', null, 'Questions reordered');
            break;

        default:
            $db->send_response(400, 'error', null, 'Invalid action');
    }
} catch (Exception $e) {
    error_log('Course Content API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}


// ============================================================================
// HELPER FUNCTIONS
// ============================================================================

/**
 * Get media files for content selection
 */
function getMediaFiles(mysqli $conn, int $tenant_id, ?string $media_type = null): array {
    $query = "SELECT media_id, tenant_id, title, media_type, file_url, launch_url, 
                     file_size, duration, created_at
              FROM media 
              WHERE tenant_id = ? AND is_active = 1 AND is_latest_version = 1";

    $params = [$tenant_id];
    $types = "i";

    if ($media_type) {
        $query .= " AND media_type = ?";
        $params[] = strtoupper($media_type);
        $types .= "s";
    }

    $query .= " ORDER BY created_at DESC";

    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $media = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $media;
}

/**
 * Load complete course content
 */
function loadCourseContent(mysqli $conn, int $tenant_id, int $course_id): array {
    // Get course info
    $stmt = $conn->prepare("SELECT * FROM courses WHERE course_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $course_id, $tenant_id);
    $stmt->execute();
    $course = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$course) {
        throw new Exception('Course not found');
    }

    // Get modules with lessons and assessments
    $modules = getModules($conn, $tenant_id, $course_id);

    // Calculate stats
    $totalLessons = 0;
    $totalAssessments = 0;
    $totalDuration = 0;
    $totalQuestions = 0;

    foreach ($modules as $module) {
        $totalLessons += count($module['lessons'] ?? []);
        $totalAssessments += count($module['assessments'] ?? []);
        foreach ($module['lessons'] ?? [] as $lesson) {
            $totalDuration += $lesson['duration_minutes'] ?? 0;
        }
        foreach ($module['assessments'] ?? [] as $assessment) {
            $totalQuestions += $assessment['question_count'] ?? 0;
        }
    }

    return [
        'course' => $course,
        'modules' => $modules,
        'stats' => [
            'total_modules' => count($modules),
            'total_lessons' => $totalLessons,
            'total_assessments' => $totalAssessments,
            'total_questions' => $totalQuestions,
            'total_duration_minutes' => $totalDuration
        ]
    ];
}

/**
 * Get modules with lessons and assessments
 */
function getModules(mysqli $conn, int $tenant_id, int $course_id): array {
    $query = "SELECT * FROM modules 
              WHERE course_id = ? AND tenant_id = ? 
              ORDER BY sequence_order ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $course_id, $tenant_id);
    $stmt->execute();
    $modules = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get lessons and assessments for each module
    foreach ($modules as &$module) {
        $module['lessons'] = getLessons($conn, $tenant_id, $module['module_id']);
        $module['assessments'] = getAssessments($conn, $tenant_id, $module['module_id'], null);
    }

    return $modules;
}

/**
 * Create module
 */
function createModule(mysqli $conn, int $tenant_id, int $course_id, string $title,
                      string $description, bool $is_active): array {
    // Get next sequence order
    $stmt = $conn->prepare("SELECT COALESCE(MAX(sequence_order), 0) + 1 as next_order 
                            FROM modules WHERE course_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $course_id, $tenant_id);
    $stmt->execute();
    $next = $stmt->get_result()->fetch_assoc();
    $sequence_order = $next['next_order'];
    $stmt->close();

    $query = "INSERT INTO modules (tenant_id, course_id, title, description, sequence_order, is_active, created_at)
              VALUES (?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $conn->prepare($query);
    $is_active_int = $is_active ? 1 : 0;
    $stmt->bind_param("iissii", $tenant_id, $course_id, $title, $description, $sequence_order, $is_active_int);
    $stmt->execute();
    $module_id = $conn->insert_id;
    $stmt->close();

    return [
        'module_id' => $module_id,
        'course_id' => $course_id,
        'tenant_id' => $tenant_id,
        'title' => $title,
        'description' => $description,
        'sequence_order' => $sequence_order,
        'is_active' => $is_active
    ];
}

/**
 * Update module
 */
function updateModule(mysqli $conn, int $tenant_id, int $module_id, string $title,
                      string $description, bool $is_active): array {
    $query = "UPDATE modules SET title = ?, description = ?, is_active = ?, updated_at = NOW()
              WHERE module_id = ? AND tenant_id = ?";
    $stmt = $conn->prepare($query);
    $is_active_int = $is_active ? 1 : 0;
    $stmt->bind_param("ssiii", $title, $description, $is_active_int, $module_id, $tenant_id);
    $stmt->execute();
    $stmt->close();

    // Return updated module
    $stmt = $conn->prepare("SELECT * FROM modules WHERE module_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $module_id, $tenant_id);
    $stmt->execute();
    $module = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $module;
}

/**
 * Delete module (soft delete)
 */
function deleteModule(mysqli $conn, int $tenant_id, int $module_id): void {
    // Soft delete module
    $stmt = $conn->prepare("UPDATE modules SET is_active = 0, updated_at = NOW() 
                            WHERE module_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $module_id, $tenant_id);
    $stmt->execute();
    $stmt->close();

    // Soft delete lessons in module
    $stmt = $conn->prepare("UPDATE lessons SET is_active = 0, updated_at = NOW() 
                            WHERE module_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $module_id, $tenant_id);
    $stmt->execute();
    $stmt->close();

    // Soft delete assessments in module
    $stmt = $conn->prepare("UPDATE assessments SET is_active = 0, updated_at = NOW() 
                            WHERE module_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $module_id, $tenant_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Reorder modules
 */
function reorderModules(mysqli $conn, int $tenant_id, int $course_id, array $module_ids): void {
    $stmt = $conn->prepare("UPDATE modules SET sequence_order = ? WHERE module_id = ? AND tenant_id = ?");

    foreach ($module_ids as $order => $module_id) {
        $sequence = $order + 1;
        $stmt->bind_param("iii", $sequence, $module_id, $tenant_id);
        $stmt->execute();
    }
    $stmt->close();
}

/**
 * Duplicate module with lessons and assessments
 */
function duplicateModule(mysqli $conn, int $tenant_id, int $module_id): array {
    // Get original module
    $stmt = $conn->prepare("SELECT * FROM modules WHERE module_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $module_id, $tenant_id);
    $stmt->execute();
    $original = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$original) {
        throw new Exception('Module not found');
    }

    // Create copy
    $newModule = createModule(
        $conn,
        $tenant_id,
        $original['course_id'],
        $original['title'] . ' (Copy)',
        $original['description'] ?? '',
        (bool)$original['is_active']
    );

    // Duplicate lessons
    $lessons = getLessons($conn, $tenant_id, $module_id);
    foreach ($lessons as $lesson) {
        $lessonData = $lesson;
        $lessonData['module_id'] = $newModule['module_id'];
        unset($lessonData['lesson_id']);
        createLesson($conn, $tenant_id, $lessonData);
    }

    // Duplicate assessments
    $assessments = getAssessments($conn, $tenant_id, $module_id, null);
    foreach ($assessments as $assessment) {
        duplicateAssessmentToModule($conn, $tenant_id, $assessment['assessment_id'], $newModule['module_id']);
    }

    return $newModule;
}

/**
 * Get lessons for a module
 */
function getLessons(mysqli $conn, int $tenant_id, int $module_id): array {
    $query = "SELECT * FROM lessons 
              WHERE module_id = ? AND tenant_id = ? AND is_active = 1
              ORDER BY sequence_order ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $module_id, $tenant_id);
    $stmt->execute();
    $lessons = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $lessons;
}

/**
 * Create lesson
 */
function createLesson(mysqli $conn, int $tenant_id, array $data): array {
    // Get next sequence order
    $stmt = $conn->prepare("SELECT COALESCE(MAX(sequence_order), 0) + 1 as next_order 
                            FROM lessons WHERE module_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $data['module_id'], $tenant_id);
    $stmt->execute();
    $next = $stmt->get_result()->fetch_assoc();
    $sequence_order = $next['next_order'];
    $stmt->close();

    $query = "INSERT INTO lessons (tenant_id, module_id,course_id, title, description, content_type, 
              content_url, content_text, duration_minutes, sequence_order, is_mandatory, 
              is_active, pass_percentage, created_at)
              VALUES (?, ?,?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($query);
    $is_mandatory = isset($data['is_mandatory']) && $data['is_mandatory'] ? 1 : 0;
    $is_active = isset($data['is_active']) && $data['is_active'] ? 1 : 0;
    $duration = (int)($data['duration_minutes'] ?? 0);
    $pass_percentage = (int)($data['pass_percentage'] ?? 70);

    $stmt->bind_param("iiisssssiiiii",
        $tenant_id,
        $data['module_id'],
        $data['course_id'],
        $data['title'],
        $data['description'],
        $data['content_type'],
        $data['content_url'],
        $data['content_text'],
        $duration,
        $sequence_order,
        $is_mandatory,
        $is_active,
        $pass_percentage
    );
    $stmt->execute();
    $lesson_id = $conn->insert_id;
    $stmt->close();

    // Return created lesson
    $stmt = $conn->prepare("SELECT * FROM lessons WHERE lesson_id = ?");
    $stmt->bind_param("i", $lesson_id);
    $stmt->execute();
    $lesson = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $lesson;
}

/**
 * Update lesson
 */
function updateLesson(mysqli $conn, int $tenant_id, int $lesson_id, array $data): array {
    $query = "UPDATE lessons SET 
              module_id = ?,
              title = ?, 
              description = ?, 
              content_type = ?,
              content_url = ?,
              content_text = ?,
              duration_minutes = ?,
              is_mandatory = ?,
              is_active = ?,
              pass_percentage = ?,
              updated_at = NOW()
              WHERE lesson_id = ? AND tenant_id = ?";

    $stmt = $conn->prepare($query);
    $is_mandatory = isset($data['is_mandatory']) && $data['is_mandatory'] ? 1 : 0;
    $is_active = isset($data['is_active']) && $data['is_active'] ? 1 : 0;
    $duration = (int)($data['duration_minutes'] ?? 0);
    $pass_percentage = (int)($data['pass_percentage'] ?? 70);

    $stmt->bind_param("isssssiiiiii",
        $data['module_id'],
        $data['title'],
        $data['description'],
        $data['content_type'],
        $data['content_url'],
        $data['content_text'],
        $duration,
        $is_mandatory,
        $is_active,
        $pass_percentage,
        $lesson_id,
        $tenant_id
    );
    $stmt->execute();
    $stmt->close();

    // Return updated lesson
    $stmt = $conn->prepare("SELECT * FROM lessons WHERE lesson_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $lesson_id, $tenant_id);
    $stmt->execute();
    $lesson = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $lesson;
}

/**
 * Delete lesson (soft delete)
 */
function deleteLesson(mysqli $conn, int $tenant_id, int $lesson_id): void {
    $stmt = $conn->prepare("UPDATE lessons SET is_active = 0, updated_at = NOW() 
                            WHERE lesson_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $lesson_id, $tenant_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Reorder lessons
 */
function reorderLessons(mysqli $conn, int $tenant_id, int $module_id, array $lesson_ids): void {
    $stmt = $conn->prepare("UPDATE lessons SET sequence_order = ? WHERE lesson_id = ? AND tenant_id = ?");

    foreach ($lesson_ids as $order => $lesson_id) {
        $sequence = $order + 1;
        $stmt->bind_param("iii", $sequence, $lesson_id, $tenant_id);
        $stmt->execute();
    }
    $stmt->close();
}

/**
 * Move lesson to different module
 */
function moveLesson(mysqli $conn, int $tenant_id, int $lesson_id, int $target_module_id): void {
    // Get next sequence order in target module
    $stmt = $conn->prepare("SELECT COALESCE(MAX(sequence_order), 0) + 1 as next_order 
                            FROM lessons WHERE module_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $target_module_id, $tenant_id);
    $stmt->execute();
    $next = $stmt->get_result()->fetch_assoc();
    $sequence_order = $next['next_order'];
    $stmt->close();

    // Move lesson
    $stmt = $conn->prepare("UPDATE lessons SET module_id = ?, sequence_order = ?, updated_at = NOW() 
                            WHERE lesson_id = ? AND tenant_id = ?");
    $stmt->bind_param("iiii", $target_module_id, $sequence_order, $lesson_id, $tenant_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Duplicate lesson
 */
function duplicateLesson(mysqli $conn, int $tenant_id, int $lesson_id): array {
    $stmt = $conn->prepare("SELECT * FROM lessons WHERE lesson_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $lesson_id, $tenant_id);
    $stmt->execute();
    $original = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$original) {
        throw new Exception('Lesson not found');
    }

    $original['title'] = $original['title'] . ' (Copy)';
    unset($original['lesson_id']);

    return createLesson($conn, $tenant_id, $original);
}

/**
 * Get assessments
 */
function getAssessments(mysqli $conn, int $tenant_id, ?int $module_id, ?int $course_id): array {
    $query = "SELECT a.*, 
              (SELECT COUNT(*) FROM questions q WHERE q.assessment_id = a.assessment_id AND q.is_active = 1) as question_count
              FROM assessments a
              WHERE a.tenant_id = ? AND a.is_active = 1";

    $params = [$tenant_id];
    $types = "i";

    if ($module_id) {
        $query .= " AND a.module_id = ?";
        $params[] = $module_id;
        $types .= "i";
    }

    if ($course_id) {
        $query .= " AND a.course_id = ?";
        $params[] = $course_id;
        $types .= "i";
    }

    $query .= " ORDER BY a.sequence_order ASC";

    $stmt = $conn->prepare($query);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $assessments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $assessments;
}

/**
 * Create assessment
 */
function createAssessment(mysqli $conn, int $tenant_id, array $data): array {
    // Get next sequence order
    $module_id = $data['module_id'] ?? null;

    if ($module_id) {
        $stmt = $conn->prepare("SELECT COALESCE(MAX(sequence_order), 0) + 1 as next_order 
                                FROM assessments WHERE module_id = ? AND tenant_id = ?");
        $stmt->bind_param("ii", $module_id, $tenant_id);
    } else {
        $stmt = $conn->prepare("SELECT COALESCE(MAX(sequence_order), 0) + 1 as next_order 
                                FROM assessments WHERE course_id = ? AND tenant_id = ?");
        $stmt->bind_param("ii", $data['course_id'], $tenant_id);
    }
    $stmt->execute();
    $next = $stmt->get_result()->fetch_assoc();
    $sequence_order = $next['next_order'];
    $stmt->close();

    $query = "INSERT INTO assessments (tenant_id, course_id, module_id, title, description, 
              assessment_type, time_limit_minutes, pass_percentage, max_attempts, 
              shuffle_questions, show_correct_answers, sequence_order, is_active, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($query);
    $shuffle = isset($data['shuffle_questions']) && $data['shuffle_questions'] ? 1 : 0;
    $show_correct = isset($data['show_correct_answers']) && $data['show_correct_answers'] ? 1 : 0;
    $is_active = isset($data['is_active']) && $data['is_active'] ? 1 : 0;

    $stmt->bind_param("iiisssiiiiiii",
        $tenant_id,
        $data['course_id'],
        $module_id,
        $data['title'],
        $data['description'],
        $data['assessment_type'],
        $data['time_limit_minutes'],
        $data['pass_percentage'],
        $data['max_attempts'],
        $shuffle,
        $show_correct,
        $sequence_order,
        $is_active
    );
    $stmt->execute();
    $assessment_id = $conn->insert_id;
    $stmt->close();

    // Return created assessment
    $stmt = $conn->prepare("SELECT * FROM assessments WHERE assessment_id = ?");
    $stmt->bind_param("i", $assessment_id);
    $stmt->execute();
    $assessment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $assessment;
}

/**
 * Update assessment
 */
function updateAssessment(mysqli $conn, int $tenant_id, int $assessment_id, array $data): array {
    $query = "UPDATE assessments SET 
              module_id = ?,
              title = ?, 
              description = ?, 
              assessment_type = ?,
              time_limit_minutes = ?,
              pass_percentage = ?,
              max_attempts = ?,
              shuffle_questions = ?,
              show_correct_answers = ?,
              is_active = ?,
              updated_at = NOW()
              WHERE assessment_id = ? AND tenant_id = ?";

    $stmt = $conn->prepare($query);
    $module_id = $data['module_id'] ?? null;
    $shuffle = isset($data['shuffle_questions']) && $data['shuffle_questions'] ? 1 : 0;
    $show_correct = isset($data['show_correct_answers']) && $data['show_correct_answers'] ? 1 : 0;
    $is_active = isset($data['is_active']) && $data['is_active'] ? 1 : 0;

    $stmt->bind_param("isssiiiiiii",
        $module_id,
        $data['title'],
        $data['description'],
        $data['assessment_type'],
        $data['time_limit_minutes'],
        $data['pass_percentage'],
        $data['max_attempts'],
        $shuffle,
        $show_correct,
        $is_active,
        $assessment_id,
        $tenant_id
    );
    $stmt->execute();
    $stmt->close();

    // Return updated assessment
    $stmt = $conn->prepare("SELECT * FROM assessments WHERE assessment_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $assessment_id, $tenant_id);
    $stmt->execute();
    $assessment = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $assessment;
}

/**
 * Delete assessment (soft delete)
 */
function deleteAssessment(mysqli $conn, int $tenant_id, int $assessment_id): void {
    $stmt = $conn->prepare("UPDATE assessments SET is_active = 0, updated_at = NOW() 
                            WHERE assessment_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $assessment_id, $tenant_id);
    $stmt->execute();
    $stmt->close();

    // Soft delete questions
    $stmt = $conn->prepare("UPDATE questions SET is_active = 0, updated_at = NOW() 
                            WHERE assessment_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $assessment_id, $tenant_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Duplicate assessment with questions
 */
function duplicateAssessment(mysqli $conn, int $tenant_id, int $assessment_id): array {
    $stmt = $conn->prepare("SELECT * FROM assessments WHERE assessment_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $assessment_id, $tenant_id);
    $stmt->execute();
    $original = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$original) {
        throw new Exception('Assessment not found');
    }

    $original['title'] = $original['title'] . ' (Copy)';
    unset($original['assessment_id']);

    $newAssessment = createAssessment($conn, $tenant_id, $original);

    // Duplicate questions
    $questions = getQuestions($conn, $tenant_id, $assessment_id);
    foreach ($questions as $question) {
        $question['assessment_id'] = $newAssessment['assessment_id'];
        unset($question['question_id']);
        createQuestion($conn, $tenant_id, $question);
    }

    return $newAssessment;
}

/**
 * Duplicate assessment to a new module
 */
function duplicateAssessmentToModule(mysqli $conn, int $tenant_id, int $assessment_id, int $new_module_id): array {
    $stmt = $conn->prepare("SELECT * FROM assessments WHERE assessment_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $assessment_id, $tenant_id);
    $stmt->execute();
    $original = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$original) {
        throw new Exception('Assessment not found');
    }

    $original['module_id'] = $new_module_id;
    unset($original['assessment_id']);

    $newAssessment = createAssessment($conn, $tenant_id, $original);

    // Duplicate questions
    $questions = getQuestions($conn, $tenant_id, $assessment_id);
    foreach ($questions as $question) {
        $question['assessment_id'] = $newAssessment['assessment_id'];
        unset($question['question_id']);
        createQuestion($conn, $tenant_id, $question);
    }

    return $newAssessment;
}

/**
 * Get questions for assessment
 */
function getQuestions(mysqli $conn, int $tenant_id, int $assessment_id): array {
    $query = "SELECT * FROM questions 
              WHERE assessment_id = ? AND tenant_id = ? AND is_active = 1
              ORDER BY sequence_order ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $assessment_id, $tenant_id);
    $stmt->execute();
    $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Get options for each question
    foreach ($questions as &$question) {
        $question['options'] = getQuestionOptions($conn, $question['question_id']);
    }

    return $questions;
}

/**
 * Get options for a question
 */
function getQuestionOptions(mysqli $conn, int $question_id): array {
    $query = "SELECT * FROM question_options 
              WHERE question_id = ? 
              ORDER BY sequence_order ASC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $question_id);
    $stmt->execute();
    $options = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $options;
}

/**
 * Create question with options
 */
function createQuestion(mysqli $conn, int $tenant_id, array $data): array {
    // Get next sequence order
    $stmt = $conn->prepare("SELECT COALESCE(MAX(sequence_order), 0) + 1 as next_order 
                            FROM questions WHERE assessment_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $data['assessment_id'], $tenant_id);
    $stmt->execute();
    $next = $stmt->get_result()->fetch_assoc();
    $sequence_order = $next['next_order'];
    $stmt->close();

    $query = "INSERT INTO questions (tenant_id, assessment_id, question_text, question_type, 
              points, sequence_order, explanation, is_active, created_at)
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";

    $stmt = $conn->prepare($query);
    $is_active = isset($data['is_active']) && $data['is_active'] ? 1 : 0;
    $points = (int)($data['points'] ?? 1);

    $stmt->bind_param("iissiisi",
        $tenant_id,
        $data['assessment_id'],
        $data['question_text'],
        $data['question_type'],
        $points,
        $sequence_order,
        $data['explanation'],
        $is_active
    );
    $stmt->execute();
    $question_id = $conn->insert_id;
    $stmt->close();

    // Create options if provided
    if (isset($data['options']) && is_array($data['options'])) {
        saveQuestionOptions($conn, $question_id, $data['options']);
    }

    // Return created question with options
    $stmt = $conn->prepare("SELECT * FROM questions WHERE question_id = ?");
    $stmt->bind_param("i", $question_id);
    $stmt->execute();
    $question = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $question['options'] = getQuestionOptions($conn, $question_id);

    return $question;
}

/**
 * Update question with options
 */
function updateQuestion(mysqli $conn, int $tenant_id, int $question_id, array $data): array {
    $query = "UPDATE questions SET 
              question_text = ?, 
              question_type = ?, 
              points = ?,
              explanation = ?,
              is_active = ?,
              updated_at = NOW()
              WHERE question_id = ? AND tenant_id = ?";

    $stmt = $conn->prepare($query);
    $is_active = isset($data['is_active']) && $data['is_active'] ? 1 : 0;
    $points = (int)($data['points'] ?? 1);

    $stmt->bind_param("ssisiii",
        $data['question_text'],
        $data['question_type'],
        $points,
        $data['explanation'],
        $is_active,
        $question_id,
        $tenant_id
    );
    $stmt->execute();
    $stmt->close();

    // Update options if provided
    if (isset($data['options']) && is_array($data['options'])) {
        // Delete existing options
        $stmt = $conn->prepare("DELETE FROM question_options WHERE question_id = ?");
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        $stmt->close();

        // Create new options
        saveQuestionOptions($conn, $question_id, $data['options']);
    }

    // Return updated question with options
    $stmt = $conn->prepare("SELECT * FROM questions WHERE question_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $question_id, $tenant_id);
    $stmt->execute();
    $question = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $question['options'] = getQuestionOptions($conn, $question_id);

    return $question;
}

/**
 * Save question options
 */
function saveQuestionOptions(mysqli $conn, int $question_id, array $options): void {
    $query = "INSERT INTO question_options (question_id, option_text, is_correct, sequence_order, feedback)
              VALUES (?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($query);

    foreach ($options as $order => $option) {
        $is_correct = isset($option['is_correct']) && $option['is_correct'] ? 1 : 0;
        $sequence = $order + 1;
        $stmt->bind_param("isiss",
            $question_id,
            $option['option_text'],
            $is_correct,
            $sequence,
            $option['feedback']
        );
        $stmt->execute();
    }
    $stmt->close();
}

/**
 * Delete question (soft delete)
 */
function deleteQuestion(mysqli $conn, int $tenant_id, int $question_id): void {
    $stmt = $conn->prepare("UPDATE questions SET is_active = 0, updated_at = NOW() 
                            WHERE question_id = ? AND tenant_id = ?");
    $stmt->bind_param("ii", $question_id, $tenant_id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Reorder questions
 */
function reorderQuestions(mysqli $conn, int $tenant_id, int $assessment_id, array $question_ids): void {
    $stmt = $conn->prepare("UPDATE questions SET sequence_order = ? WHERE question_id = ? AND tenant_id = ?");

    foreach ($question_ids as $order => $question_id) {
        $sequence = $order + 1;
        $stmt->bind_param("iii", $sequence, $question_id, $tenant_id);
        $stmt->execute();
    }
    $stmt->close();
}