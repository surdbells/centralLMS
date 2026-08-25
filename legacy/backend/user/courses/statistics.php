<?php
require_once '../../config/Database.php';
require_once '../../class/Course.php';
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit();}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405);echo json_encode(['error' => 'Method not allowed']);exit();
}
try {
    $database = new Database();
    $db = $database->getConnection();
    $user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : null;
    $tenant_id = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : null;
    // Validate required parameters
    if (!$user_id || !$tenant_id) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Missing required parameters: user_id and tenant_id'
        ]);
        exit();
    }


    $courses = new Course($db);
    // Get course count draft
    $draft_course = $courses->get_tenant_coursesStatus($tenant_id, 'draft');
    $draft_course_count = count($draft_course);

    // Get course count published
    $published_course = $courses->get_tenant_coursesStatus($tenant_id, 'published');
    $published_course_count = count($published_course);

    // Get course count total
    $course = $courses->get_tenant_coursesTotal($tenant_id);
    $course_count = count($course);


    // Prepare response
    $response = [
        'success' => true,
        'data' => [
            'user_id' => $user_id,
            'tenant_id' => $tenant_id,
            'statistics' => [
                'total' => $course_count,
                'draft' => $draft_course_count,
                'published' => $published_course_count
            ]
        ]
    ];
    http_response_code(200);
    echo json_encode($response, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => $e->getMessage() // Remove in production
    ]);
}