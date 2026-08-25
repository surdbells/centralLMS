<?php

/**
 * Tracking API Endpoint
 * Handles learning sessions, gamification, and engagement tracking
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../class/LearningSession.php';
require_once __DIR__ . '/../class/Gamification.php';
require_once __DIR__ . '/../class/CourseRating.php';

// Authenticate request
$auth = authenticate();
if (!$auth['success']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user = $auth['user'];
$tenant_id = $user['tenant_id'];
$user_id = $user['id'];

// Initialize database
$db = new Database();
$conn = $db->getConnection();

// Get request method and resource
$method = $_SERVER['REQUEST_METHOD'];
$resource = $_GET['resource'] ?? '';
$action = $_GET['action'] ?? '';

try {
    switch ($resource) {
        // ==========================================
        // LEARNING SESSIONS
        // ==========================================
        case 'session':
            $sessionManager = new LearningSession($conn);
            $sessionManager->setTenant($tenant_id);

            switch ($action) {
                case 'start':
                    if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required'], 405);
                    $data = getJsonInput();
                    $result = $sessionManager->startSession(
                        $user_id,
                        $data['course_id'] ?? null,
                        $data['lesson_id'] ?? null
                    );
                    respond($result);
                    break;

                case 'end':
                    if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required'], 405);
                    $data = getJsonInput();
                    $result = $sessionManager->endSession($data['session_id'], $user_id);
                    respond($result);
                    break;

                case 'heartbeat':
                    if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required'], 405);
                    $data = getJsonInput();
                    $result = $sessionManager->heartbeat($data['session_id'], $user_id);
                    respond($result);
                    break;

                case 'update':
                    if ($method !== 'PUT') respond(['success' => false, 'message' => 'PUT required'], 405);
                    $data = getJsonInput();
                    $result = $sessionManager->updateSession(
                        $data['session_id'],
                        $user_id,
                        $data['course_id'] ?? null,
                        $data['lesson_id'] ?? null
                    );
                    respond($result);
                    break;

                case 'active':
                    $session = $sessionManager->getActiveSession($user_id);
                    respond(['success' => true, 'session' => $session]);
                    break;

                case 'total-time':
                    $minutes = $sessionManager->getUserTotalTime(
                        $user_id,
                        $_GET['date_from'] ?? null,
                        $_GET['date_to'] ?? null
                    );
                    respond(['success' => true, 'total_minutes' => $minutes]);
                    break;

                default:
                    respond(['success' => false, 'message' => 'Invalid session action'], 400);
            }
            break;

        // ==========================================
        // GAMIFICATION
        // ==========================================
        case 'gamification':
            $gamification = new Gamification($conn);
            $gamification->setTenant($tenant_id);

            switch ($action) {
                // Points
                case 'points':
                    $total = $gamification->getUserTotalPoints($user_id);
                    $breakdown = $gamification->getPointsBreakdown($user_id);
                    respond([
                        'success' => true,
                        'total_points' => $total,
                        'breakdown' => $breakdown
                    ]);
                    break;

                case 'points-period':
                    $period = $_GET['period'] ?? '30d';
                    $points = $gamification->getUserPointsForPeriod($user_id, $period);
                    respond(['success' => true, 'points' => $points, 'period' => $period]);
                    break;

                // Badges
                case 'badges':
                    $badges = $gamification->getUserBadges($user_id);
                    respond(['success' => true, 'badges' => $badges]);
                    break;

                case 'all-badges':
                    $badges = $gamification->getAllBadges();
                    respond(['success' => true, 'badges' => $badges]);
                    break;

                // Milestones
                case 'milestones':
                    $milestones = $gamification->getUserMilestones($user_id);
                    respond(['success' => true, 'milestones' => $milestones]);
                    break;

                // Streak
                case 'streak':
                    $streak = $gamification->getUserStreak($user_id);
                    respond(['success' => true, 'streak' => $streak]);
                    break;

                // Leaderboard
                case 'leaderboard':
                    $limit = (int) ($_GET['limit'] ?? 50);
                    $department = $_GET['department'] ?? null;
                    $leaderboard = $gamification->getLeaderboard($limit, $department);
                    respond(['success' => true, 'leaderboard' => $leaderboard]);
                    break;

                case 'rank':
                    $rank = $gamification->getUserRank($user_id);
                    respond(['success' => true, 'rank' => $rank]);
                    break;

                // Admin actions
                case 'award-points':
                    requireAdmin($user);
                    if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required'], 405);
                    $data = getJsonInput();
                    $result = $gamification->awardPoints(
                        $data['user_id'],
                        $data['activity_type'],
                        $data['points'] ?? null,
                        $data['source_type'] ?? null,
                        $data['source_id'] ?? null,
                        $data['description'] ?? null
                    );
                    respond($result);
                    break;

                case 'award-badge':
                    requireAdmin($user);
                    if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required'], 405);
                    $data = getJsonInput();
                    $result = $gamification->awardBadge(
                        $data['user_id'],
                        $data['badge_id'],
                        $user_id,
                        $data['notes'] ?? null
                    );
                    respond($result);
                    break;

                case 'create-badge':
                    requireAdmin($user);
                    if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required'], 405);
                    $data = getJsonInput();
                    $result = $gamification->createBadge($data);
                    respond($result);
                    break;

                default:
                    respond(['success' => false, 'message' => 'Invalid gamification action'], 400);
            }
            break;

        // ==========================================
        // COURSE RATINGS
        // ==========================================
        case 'rating':
            $ratingManager = new CourseRating($conn);
            $ratingManager->setTenant($tenant_id);

            switch ($action) {
                case 'submit':
                    if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required'], 405);
                    $data = getJsonInput();
                    $result = $ratingManager->submitRating(
                        $user_id,
                        $data['course_id'],
                        $data['rating'],
                        $data['review_text'] ?? null,
                        $data['is_anonymous'] ?? false
                    );
                    respond($result);
                    break;

                case 'my-rating':
                    $course_id = (int) $_GET['course_id'];
                    $rating = $ratingManager->getUserRating($user_id, $course_id);
                    respond(['success' => true, 'rating' => $rating]);
                    break;

                case 'course-ratings':
                    $course_id = (int) $_GET['course_id'];
                    $page = (int) ($_GET['page'] ?? 1);
                    $ratings = $ratingManager->getCourseRatings($course_id, true, $page);
                    $summary = $ratingManager->getCourseAverageRating($course_id);
                    respond([
                        'success' => true,
                        'ratings' => $ratings,
                        'summary' => $summary
                    ]);
                    break;

                case 'summary':
                    $course_id = (int) $_GET['course_id'];
                    $summary = $ratingManager->getCourseAverageRating($course_id);
                    respond(['success' => true, 'summary' => $summary]);
                    break;

                case 'delete':
                    if ($method !== 'DELETE') respond(['success' => false, 'message' => 'DELETE required'], 405);
                    $rating_id = (int) $_GET['rating_id'];
                    $result = $ratingManager->deleteRating($rating_id, $user_id);
                    respond(['success' => $result]);
                    break;

                // Admin actions
                case 'pending':
                    requireAdmin($user);
                    $pending = $ratingManager->getPendingRatings();
                    respond(['success' => true, 'ratings' => $pending]);
                    break;

                case 'moderate':
                    requireAdmin($user);
                    if ($method !== 'POST') respond(['success' => false, 'message' => 'POST required'], 405);
                    $data = getJsonInput();
                    $result = $ratingManager->moderateRating($data['rating_id'], $data['approved']);
                    respond(['success' => $result]);
                    break;

                case 'all-summaries':
                    requireAdmin($user);
                    $summaries = $ratingManager->getAllCoursesRatingSummary();
                    respond(['success' => true, 'summaries' => $summaries]);
                    break;

                default:
                    respond(['success' => false, 'message' => 'Invalid rating action'], 400);
            }
            break;

        default:
            respond(['success' => false, 'message' => 'Invalid resource'], 400);
    }
} catch (Exception $e) {
    error_log('Tracking API Error: ' . $e->getMessage());
    respond(['success' => false, 'message' => 'Server error', 'error' => $e->getMessage()], 500);
}

// ==========================================
// HELPER FUNCTIONS
// ==========================================

function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function getJsonInput(): array {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?? [];
}

function requireAdmin(array $user): void {
    if (!in_array($user['role'], ['admin', 'superadmin', 'hr'])) {
        respond(['success' => false, 'message' => 'Admin access required'], 403);
    }
}
