<?php
require_once '../../config/Database.php';
require_once '../../class/CourseDuplicate.php';
require_once '../../class/User.php';
// Get request body
$input = json_decode(file_get_contents('php://input'), true);
// Validate required fields
$required = ['token', 'user_id', 'tenant_id', 'course_id'];
foreach ($required as $field) {
    if (!isset($input[$field])) {
        echo json_encode([
            'code' => 400,
            'status' => 'error',
            'message' => "Missing required field: {$field}"
        ]);
        exit();
    }
}
// Extract parameters
$token = $input['token'];
$userId = (int) $input['user_id'];
$tenantId = (int) $input['tenant_id'];
$courseId = (int) $input['course_id'];
$isPreview = isset($input['preview']) && $input['preview'] === true;
// Initialize database
$database = new Database();
$db = $database->getConnection();
$user = new User($db);

// Verify user authentication
$authResult = $database->verify_user($token, $userId);
if ($authResult != 100) {
    echo json_encode([
        'code' => 401,
        'status' => 'error',
        'message' => 'Unauthorized: Invalid token or user'
    ]);
    exit();
}
// Check if user is admin
if (!$user->is_admin($userId, $tenantId)) {
    echo json_encode(['code' => 403,'status' => 'error','message' => 'Forbidden: Admin access required']);
    exit();
}
// Initialize CourseDuplicate
$duplicator = new CourseDuplicate($db);
$duplicator->setContext($tenantId, $userId);
// Handle preview request
if ($isPreview) {
    $result = $duplicator->preview($courseId);
    if ($result['success']) {
        echo json_encode([
            'code' => 200,
            'status' => 'success',
            'data' => $result['data'],
            'message' => 'Preview generated successfully'
        ]);
    } else {
        echo json_encode([
            'code' => 404,
            'status' => 'error',
            'message' => $result['message']
        ]);
    }
    exit();
}
// Build options array for duplication
$options = [
    'created_by' => $userId
];

// Optional parameters
if (!empty($input['new_title'])) {
    $options['new_title'] = trim($input['new_title']);
}
if (isset($input['title_suffix'])) {
    $options['title_suffix'] = $input['title_suffix'];
}
if (isset($input['copy_modules'])) {
    $options['copy_modules'] = (bool) $input['copy_modules'];
}
if (isset($input['copy_lessons'])) {
    $options['copy_lessons'] = (bool) $input['copy_lessons'];
}
if (isset($input['copy_assessments'])) {
    $options['copy_assessments'] = (bool) $input['copy_assessments'];
}
if (isset($input['copy_questions'])) {
    $options['copy_questions'] = (bool) $input['copy_questions'];
}
if (isset($input['set_as_draft'])) {
    $options['set_as_draft'] = (bool) $input['set_as_draft'];
}

// Perform duplication
$result = $duplicator->duplicate($courseId, $options);

if ($result['success']) {
    echo json_encode([
        'code' => 201,
        'status' => 'success',
        'data' => $result['data'],
        'message' => $result['message']
    ]);
} else {
    echo json_encode([
        'code' => 500,
        'status' => 'error',
        'message' => $result['message']
    ]);
}