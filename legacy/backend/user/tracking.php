<?php

/**
 * Tracking API Endpoint
 * Handles learning sessions, gamification, and course ratings
 * Uses existing Database class pattern
 */

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../class/LearningSession.php';
require_once __DIR__ . '/../class/Gamification.php';
require_once __DIR__ . '/../class/CourseRating.php';

$db = new Database();
$conn = $db->getConnection();

// Authenticate
$auth = authenticate();
if (!$auth['success']) {
    $db->send_response(401, 'error', null, $auth['message']);
    exit;
}

$user = $auth['user'];
$tenant_id = $user['tenant_id'];
$user_id = $user['id'];

// Get request parameters
$resource = $_GET['resource'] ?? $_POST['resource'] ?? '';
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = getJsonInput();

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
                    $result = $sessionManager->startSession(
                        $user_id,
                        $input['course_id'] ?? null,
                        $input['lesson_id'] ?? null
                    );
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'Session started' : $result['message']);
                    break;

                case 'end':
                    $result = $sessionManager->endSession($input['session_id'], $user_id);
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'Session ended' : $result['message']);
                    break;

                case 'heartbeat':
                    $result = $sessionManager->heartbeat($input['session_id'], $user_id);
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'Heartbeat recorded' : $result['message']);
                    break;

                case 'update':
                    $result = $sessionManager->updateSession(
                        $input['session_id'],
                        $user_id,
                        $input['course_id'] ?? null,
                        $input['lesson_id'] ?? null
                    );
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'Session updated' : $result['message']);
                    break;

                case 'active':
                    $session = $sessionManager->getActiveSession($user_id);
                    $db->send_response(200, 'success', ['session' => $session], 'Active session retrieved');
                    break;

                case 'total-time':
                    $minutes = $sessionManager->getUserTotalTime(
                        $user_id,
                        $_GET['date_from'] ?? null,
                        $_GET['date_to'] ?? null
                    );
                    $db->send_response(200, 'success', ['total_minutes' => $minutes], 'Total time retrieved');
                    break;

                default:
                    $db->send_response(400, 'error', null, 'Invalid session action');
            }
            break;

        // ==========================================
        // GAMIFICATION
        // ==========================================
        case 'gamification':
            $gamification = new Gamification($conn);
            $gamification->setTenant($tenant_id);

            switch ($action) {
                case 'points':
                    $total = $gamification->getUserTotalPoints($user_id);
                    $breakdown = $gamification->getPointsBreakdown($user_id);
                    $db->send_response(200, 'success', [
                        'total_points' => $total,
                        'breakdown' => $breakdown
                    ], 'Points retrieved');
                    break;

                case 'points-period':
                    $period = $_GET['period'] ?? '30d';
                    $points = $gamification->getUserPointsForPeriod($user_id, $period);
                    $db->send_response(200, 'success', ['points' => $points, 'period' => $period], 'Period points retrieved');
                    break;

                case 'badges':
                    $badges = $gamification->getUserBadges($user_id);
                    $db->send_response(200, 'success', ['badges' => $badges], 'Badges retrieved');
                    break;

                case 'all-badges':
                    $badges = $gamification->getAllBadges();
                    $db->send_response(200, 'success', ['badges' => $badges], 'All badges retrieved');
                    break;

                case 'milestones':
                    $milestones = $gamification->getUserMilestones($user_id);
                    $db->send_response(200, 'success', ['milestones' => $milestones], 'Milestones retrieved');
                    break;

                case 'streak':
                    $streak = $gamification->getUserStreak($user_id);
                    $db->send_response(200, 'success', ['streak' => $streak], 'Streak retrieved');
                    break;

                case 'leaderboard':
                    $limit = (int) ($_GET['limit'] ?? 50);
                    $department = $_GET['department'] ?? null;
                    $leaderboard = $gamification->getLeaderboard($limit, $department);
                    $db->send_response(200, 'success', ['leaderboard' => $leaderboard], 'Leaderboard retrieved');
                    break;

                case 'rank':
                    $rank = $gamification->getUserRank($user_id);
                    $db->send_response(200, 'success', ['rank' => $rank], 'Rank retrieved');
                    break;

                // Admin actions
                case 'award-points':
                    if (!isAdmin($user)) {
                        $db->send_response(403, 'error', null, 'Admin access required');
                        exit;
                    }
                    $result = $gamification->awardPoints(
                        $input['user_id'],
                        $input['activity_type'],
                        $input['points'] ?? null,
                        $input['source_type'] ?? null,
                        $input['source_id'] ?? null,
                        $input['description'] ?? null
                    );
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'Points awarded' : $result['message']);
                    break;

                case 'award-badge':
                    if (!isAdmin($user)) {
                        $db->send_response(403, 'error', null, 'Admin access required');
                        exit;
                    }
                    $result = $gamification->awardBadge(
                        $input['user_id'],
                        $input['badge_id'],
                        $user_id,
                        $input['notes'] ?? null
                    );
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'Badge awarded' : $result['message']);
                    break;

                case 'create-badge':
                    if (!isAdmin($user)) {
                        $db->send_response(403, 'error', null, 'Admin access required');
                        exit;
                    }
                    $result = $gamification->createBadge($input);
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'Badge created' : $result['message']);
                    break;

                default:
                    $db->send_response(400, 'error', null, 'Invalid gamification action');
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
                    $result = $ratingManager->submitRating(
                        $user_id,
                        $input['course_id'],
                        $input['rating'],
                        $input['review_text'] ?? null,
                        $input['is_anonymous'] ?? false
                    );
                    $db->send_response(200, $result['success'] ? 'success' : 'error', $result,
                        $result['success'] ? 'Rating submitted' : $result['message']);
                    break;

                case 'my-rating':
                    $course_id = (int) $_GET['course_id'];
                    $rating = $ratingManager->getUserRating($user_id, $course_id);
                    $db->send_response(200, 'success', ['rating' => $rating], 'Rating retrieved');
                    break;

                case 'course-ratings':
                    $course_id = (int) $_GET['course_id'];
                    $page = (int) ($_GET['page'] ?? 1);
                    $ratings = $ratingManager->getCourseRatings($course_id, true, $page);
                    $summary = $ratingManager->getCourseAverageRating($course_id);
                    $db->send_response(200, 'success', [
                        'ratings' => $ratings,
                        'summary' => $summary
                    ], 'Course ratings retrieved');
                    break;

                case 'summary':
                    $course_id = (int) $_GET['course_id'];
                    $summary = $ratingManager->getCourseAverageRating($course_id);
                    $db->send_response(200, 'success', ['summary' => $summary], 'Summary retrieved');
                    break;

                case 'delete':
                    $rating_id = (int) ($_GET['rating_id'] ?? $input['rating_id']);
                    $result = $ratingManager->deleteRating($rating_id, $user_id);
                    $db->send_response(200, $result ? 'success' : 'error', null,
                        $result ? 'Rating deleted' : 'Failed to delete rating');
                    break;

                // Admin actions
                case 'pending':
                    if (!isHR($user)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $pending = $ratingManager->getPendingRatings();
                    $db->send_response(200, 'success', ['ratings' => $pending], 'Pending ratings retrieved');
                    break;

                case 'moderate':
                    if (!isHR($user)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $result = $ratingManager->moderateRating($input['rating_id'], $input['approved']);
                    $db->send_response(200, $result ? 'success' : 'error', null,
                        $result ? 'Rating moderated' : 'Failed to moderate rating');
                    break;

                case 'all-summaries':
                    if (!isHR($user)) {
                        $db->send_response(403, 'error', null, 'HR access required');
                        exit;
                    }
                    $summaries = $ratingManager->getAllCoursesRatingSummary();
                    $db->send_response(200, 'success', ['summaries' => $summaries], 'Summaries retrieved');
                    break;

                default:
                    $db->send_response(400, 'error', null, 'Invalid rating action');
            }
            break;

        default:
            $db->send_response(400, 'error', null, 'Invalid resource. Use: session, gamification, rating');
    }
} catch (Exception $e) {
    error_log('Tracking API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}