<?php
require_once '../config/Database.php';
require_once '../class/UserPoint.php';
require_once '../class/UserBadge.php';
require_once '../class/Certificate.php';
require_once '../class/UserLevelProgress.php';
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
    // Initialize model classes
    $userPoint = new UserPoint($db);
    $userPoint->tenant_id = $tenant_id;

    $userBadge = new UserBadge($db);
    $userBadge->tenant_id = $tenant_id;

    $certificate = new Certificate($db);
    $certificate->tenant_id = $tenant_id;
    $certificate->user_id = $user_id;
    $certificate->course_id = null; // ADD THIS LINE
    $certificates_result = $certificate->list();

    $userLevelProgress = new UserLevelProgress($db);
    $userLevelProgress->tenant_id = $tenant_id;
    $userLevelProgress->user_id = $user_id;

    // Get total points
    $total_points = $userPoint->get_user_total_points($user_id);

    // Get badges count
    $badges = $userBadge->get_user_badges($user_id);
    $badges_count = count($badges);

    // Get certificates count
    $certificate->user_id = $user_id;
    $certificates_result = $certificate->list();
    $certificates_count = $certificates_result['meta']['total'];

    // Get level progress
    $level_progress = $userLevelProgress->read_single();
    $current_level = is_array($level_progress) ? [
        'level_number' => $level_progress['level_number'] ?? 1,
        'level_name' => $level_progress['level_name'] ?? 'Beginner',
        'level_icon_url' => $level_progress['level_icon_url'] ?? '',
        'progress_percentage' => $userLevelProgress->get_progress_percentage()
    ] : null;

    // Get points breakdown by type (optional detailed info)
    $points_by_type = [
        'course_completion' => $userPoint->get_user_points_by_type($user_id, 'course_completion'),
        'assessment_pass' => $userPoint->get_user_points_by_type($user_id, 'assessment_pass'),
        'badge_earned' => $userPoint->get_user_points_by_type($user_id, 'badge_earned'),
        'other' => $userPoint->get_user_points_by_type($user_id, 'other')
    ];

    // Get badge breakdown by type (optional detailed info)
    $badges_by_type = [
        'achievement' => 0,
        'milestone' => 0,
        'completion' => 0,
        'streak' => 0,
        'participation' => 0
    ];

    foreach ($badges as $badge) {
        $type = $badge['badge_type'] ?? 'other';
        if (isset($badges_by_type[$type])) {
            $badges_by_type[$type]++;
        }
    }

    // Prepare response
    $response = [
        'success' => true,
        'data' => [
            'user_id' => $user_id,
            'tenant_id' => $tenant_id,
            'statistics' => [
                'total_points' => $total_points,
                'total_badges' => $badges_count,
                'total_certificates' => $certificates_count
            ],
            'level' => $current_level,
            'breakdown' => [
                'points_by_type' => $points_by_type,
                'badges_by_type' => $badges_by_type
            ],
            'recent_badges' => array_slice($badges, 0, 5), // Last 5 badges
            'generated_at' => date('Y-m-d H:i:s')
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