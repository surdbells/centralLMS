<?php
/**
 * Courses API Endpoint — Course information CRUD.
 * Pattern: GET/POST /api/courses.php?action=<action>&token=<token>&user_id=<id>&tenant_id=<tid>
 */

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../class/users/RoleService.php';
require_once __DIR__ . '/../class/CourseService.php';

$db     = new Database();
$conn   = $db->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── Auth ──
$token   = $_GET['token'] ?? $_POST['token'] ?? $_SERVER['HTTP_X_TOKEN'] ?? null;
$user_id = $_GET['user_id'] ?? $_POST['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;

$input = [];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    if (!$token && isset($input['token'])) $token = $input['token'];
    if (!$user_id && isset($input['user_id'])) $user_id = $input['user_id'];
}

if (!$token || !$user_id) { $db->send_response(401, 'error', null, 'Token and user_id required'); exit; }
$verification = $db->verify_user($token, $user_id);
if ($verification < 100) { $db->send_response(401, 'error', null, 'Invalid token'); exit; }

$tenant_id = (int)($_GET['tenant_id'] ?? $input['tenant_id'] ?? 0);
$user_id   = (int)$user_id;
if (!$tenant_id) { $db->send_response(400, 'error', null, 'tenant_id required'); exit; }

// ── Services ──
$roleSvc    = new RoleService($conn);
$courseSvc  = new CourseService($conn);
$roleSvc->setContext($tenant_id, $user_id);
$courseSvc->setContext($tenant_id, $user_id);

// ═══════════════════════════════════════════
switch ($action) {

    case 'list':
        $roleSvc->requirePermission('courses.view');
        $params = array_filter(array_intersect_key($_GET, array_flip([
            'search','status','category_id','is_mandatory','is_active','instructor_id',
            'level','course_type','page','per_page','sort_by','sort_dir'
        ])), fn($v) => $v !== '');
        $db->send_response(200, 'success', $courseSvc->listCourses($params), 'Courses retrieved');
        break;

    case 'get':
        $roleSvc->requirePermission('courses.view');
        $id = (int)($_GET['course_id'] ?? 0);
        if (!$id) { $db->send_response(400, 'error', null, 'course_id required'); exit; }
        $course = $courseSvc->getCourse($id);
        if (!$course) { $db->send_response(404, 'error', null, 'Course not found'); exit; }
        $db->send_response(200, 'success', $course, 'Course retrieved');
        break;

    case 'create':
        $roleSvc->requirePermission('courses.create');
        $r = $courseSvc->createCourse($input);
        $db->send_response($r['success'] ? 201 : 400, $r['success'] ? 'success' : 'error', $r,
            $r['success'] ? 'Course created' : ($r['error'] ?? 'Failed'));
        break;

    case 'update':
        $roleSvc->requirePermission('courses.edit');
        $id = (int)($input['course_id'] ?? $_GET['course_id'] ?? 0);
        if (!$id) { $db->send_response(400, 'error', null, 'course_id required'); exit; }
        $r = $courseSvc->updateCourse($id, $input);
        $db->send_response(200, $r['success'] ? 'success' : 'error', $r,
            $r['success'] ? 'Course updated' : ($r['error'] ?? 'Failed'));
        break;

    case 'delete':
        $roleSvc->requirePermission('courses.delete');
        $id = (int)($input['course_id'] ?? $_GET['course_id'] ?? 0);
        $r = $courseSvc->deleteCourse($id);
        $db->send_response(200, $r['success'] ? 'success' : 'error', $r,
            $r['success'] ? 'Course archived' : ($r['error'] ?? 'Failed'));
        break;

    case 'duplicate':
        $roleSvc->requirePermission('courses.create');
        $id = (int)($input['course_id'] ?? 0);
        $r = $courseSvc->duplicateCourse($id);
        $db->send_response($r['success'] ? 201 : 400, $r['success'] ? 'success' : 'error', $r,
            $r['success'] ? 'Course duplicated' : ($r['error'] ?? 'Failed'));
        break;

    case 'dropdowns':
        $roleSvc->requirePermission('courses.view');
        $db->send_response(200, 'success', $courseSvc->getDropdowns(), 'Dropdowns retrieved');
        break;

    case 'stats':
        $roleSvc->requirePermission('courses.view');
        $db->send_response(200, 'success', $courseSvc->getStats(), 'Stats retrieved');
        break;

    default:
        $db->send_response(400, 'error', null,
            'Invalid action. Available: list, get, create, update, delete, duplicate, dropdowns, stats');
}
