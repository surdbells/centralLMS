<?php
require_once '../config/Database.php';
require_once '../class/LearnerDashboard.php';
// Get request body
$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
$required = ['token', 'user_id', 'tenant_id'];
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

// Initialize database
$database = new Database();
$db = $database->getConnection();

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

try {
    // Initialize LearnerDashboard
    $dashboard = new LearnerDashboard($db);
    $dashboard->setContext($tenantId, $userId);

    // Get dashboard data
    $data = $dashboard->getDashboardData();

    echo json_encode([
        'code' => 200,
        'status' => 'success',
        'data' => $data,
        'message' => 'Dashboard data retrieved successfully'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'code' => 500,
        'status' => 'error',
        'message' => 'Failed to load dashboard: ' . $e->getMessage()
    ]);
}