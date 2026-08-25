<?php
require_once '../../config/Database.php';
require_once '../../class/CourseInsights.php';
include_once '../../class/User.php';


// Get input
$input = json_decode(file_get_contents('php://input'), true);
// If JSON parsing failed, try POST data
if (empty($input)) { $input = $_POST;}
// Validate required fields
$required = ['token', 'user_id', 'tenant_id', 'course_id'];
foreach ($required as $field) {
    if (empty($input[$field])) {
        echo json_encode([
            'code' => 400,
            'status' => 'failed',
            'message' => "Missing required field: {$field}"
        ]);
        exit;
    }
}
// Extract parameters
$token = $input['token'];
$userId = (int) $input['user_id'];
$tenantId = (int) $input['tenant_id'];
$courseId = (int) $input['course_id'];
$dateFrom = $input['date_from'] ?? null;
$dateTo = $input['date_to'] ?? null;
$period = $input['period'] ?? 'daily';

// Validate period
$validPeriods = ['daily', 'weekly', 'monthly'];
if (!in_array($period, $validPeriods)) {
    $period = 'daily';
}

try {
    // Initialize database
    $database = new Database();
    $db = $database->getConnection();
    $user = new User($db);
    // Verify user authentication
    if (!$database->verify_user($token, $userId)) {
        echo json_encode([
            'code' => 401,
            'status' => 'failed',
            'message' => 'Authentication failed'
        ]);
        exit;
    }
    $token_check = $database->verify_user($token, $userId);
    if ($token_check != 100 || !$user->is_admin($userId)) {
        http_response_code(401);
        $database->send_response(401, "failed", [], "Unauthorized request");
        exit;
    }
    // Verify course exists and belongs to tenant
    $query = "SELECT course_id FROM courses WHERE course_id = ? AND tenant_id = ? AND is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->bind_param("ii", $courseId, $tenantId);
    $stmt->execute();

    if (!$stmt->get_result()->fetch_assoc()) {
        echo json_encode([
            'code' => 404,
            'status' => 'failed',
            'message' => 'Course not found'
        ]);
        exit;
    }
    // Initialize CourseInsights
    $insights = new CourseInsights($db);
    $insights->setParams($courseId, $tenantId, $dateFrom, $dateTo, $period);
    // Get all insights data
    $data = $insights->getInsights();
    if ($data['course'] === null) {
        echo json_encode([
            'code' => 404,
            'status' => 'failed',
            'message' => 'Course not found or access denied'
        ]);
        exit;
    }
    // Return success response
  echo json_encode([
        'code' => 200,
        'status' => 'success',
        'data' => $data,
        'message' => 'Course insights retrieved successfully'
    ]);
} catch (Exception $e) {
    error_log("Course Insights Error: " . $e->getMessage());

    echo json_encode([
        'code' => 500,
        'status' => 'failed',
        'message' => 'An error occurred while retrieving course insights'
    ]);
}