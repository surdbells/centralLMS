<?php
/**
 * Grading API Endpoint - Review & Manual Grading
 * Multi-Tenant LMS - Admin Portal
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../class/Grading.php';

$db = new Database();
$conn = $db->getConnection();

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) { $db->send_response(400, 'error', null, 'Invalid JSON'); exit; }

    $token = $input['token'] ?? null;
    $user_id = (int) ($input['user_id'] ?? 0);
    $tenant_id = (int) ($input['tenant_id'] ?? 0);
    $action = $input['action'] ?? '';

    if (!$token || !$tenant_id || !$user_id) { $db->send_response(401, 'error', null, 'Auth required'); exit; }
    if ($db->verify_user($token, $user_id) != 100) { $db->send_response(401, 'error', null, 'Invalid token'); exit; }

    $grading = new Grading($conn);
    $grading->setTenant($tenant_id);
    $grading->setUser($user_id);

    switch ($action) {
        // RUBRICS
        case 'create-rubric':
            $result = $grading->createRubric($input);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', $result['success'] ? ['rubric_id' => $result['rubric_id']] : null, $result['message']);
            break;
        case 'update-rubric':
            $result = $grading->updateRubric((int) ($input['rubric_id'] ?? 0), $input);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', null, $result['message']);
            break;
        case 'delete-rubric':
            $result = $grading->deleteRubric((int) ($input['rubric_id'] ?? 0));
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', null, $result['message']);
            break;
        case 'get-rubric':
            $rubric = $grading->getRubric((int) ($input['rubric_id'] ?? 0));
            $rubric ? $db->send_response(200, 'success', $rubric, 'Retrieved') : $db->send_response(404, 'error', null, 'Not found');
            break;
        case 'get-rubrics':
            $rubrics = $grading->getRubrics($input);
            $db->send_response(200, 'success', $rubrics, 'Retrieved');
            break;
        case 'clone-rubric':
            $result = $grading->cloneRubric((int) ($input['rubric_id'] ?? 0));
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', $result['success'] ? ['rubric_id' => $result['rubric_id']] : null, $result['message']);
            break;

        // CRITERIA
        case 'create-criterion':
            $result = $grading->createCriterion($input);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', $result['success'] ? ['criterion_id' => $result['criterion_id']] : null, $result['message'] ?? '');
            break;
        case 'delete-criterion':
            $result = $grading->deleteCriterion((int) ($input['criterion_id'] ?? 0));
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', null, $result['message'] ?? '');
            break;

        // LEVELS
        case 'create-level':
            $result = $grading->createLevel($input);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', $result['success'] ? ['level_id' => $result['level_id']] : null, $result['message'] ?? '');
            break;

        // GRADING QUEUE
        case 'get-grading-queue':
            $queue = $grading->getGradingQueue($input);
            $db->send_response(200, 'success', $queue, 'Retrieved');
            break;
        case 'assign-queue-item':
            $result = $grading->assignQueueItem((int) ($input['queue_id'] ?? 0), $input['grader_id'] ?? null);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', null, $result['message'] ?? 'Assigned');
            break;
        case 'get-next-item':
            $item = $grading->getNextQueueItem($input['assessment_id'] ?? null);
            $item ? $db->send_response(200, 'success', $item, 'Retrieved') : $db->send_response(404, 'error', null, 'No items');
            break;
        case 'get-answer-for-grading':
            $item = $grading->getAnswerForGrading((int) ($input['queue_id'] ?? 0));
            $item ? $db->send_response(200, 'success', $item, 'Retrieved') : $db->send_response(404, 'error', null, 'Not found');
            break;

        // GRADING
        case 'grade-answer':
            $result = $grading->gradeAnswer((int) ($input['answer_id'] ?? 0), $input);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', $result['success'] ? ['points' => $result['points']] : null, $result['message']);
            break;
        case 'grade-with-rubric':
            $result = $grading->gradeWithRubric((int) ($input['answer_id'] ?? 0), (int) ($input['rubric_id'] ?? 0), $input['scores'] ?? []);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', $result['success'] ? ['points' => $result['points']] : null, $result['message']);
            break;
        case 'batch-grade':
            $result = $grading->batchGrade($input['answer_ids'] ?? [], (float) ($input['points'] ?? 0), $input['feedback'] ?? null);
            $db->send_response(200, 'success', $result, 'Batch graded');
            break;
        case 'release-grades':
            $result = $grading->releaseGrades((int) ($input['assessment_id'] ?? 0));
            $db->send_response(200, 'success', $result, 'Grades released');
            break;

        // COMMENTS
        case 'add-comment':
            $result = $grading->addComment((int) ($input['answer_id'] ?? 0), $input);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', $result['success'] ? ['comment_id' => $result['comment_id']] : null, $result['message'] ?? '');
            break;
        case 'get-comments':
            $comments = $grading->getAnswerComments((int) ($input['answer_id'] ?? 0));
            $db->send_response(200, 'success', $comments, 'Retrieved');
            break;
        case 'delete-comment':
            $result = $grading->deleteComment((int) ($input['comment_id'] ?? 0));
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', null, $result['message'] ?? '');
            break;

        // QUICK FEEDBACK
        case 'get-quick-feedback':
            $feedback = $grading->getQuickFeedback($input['category'] ?? null);
            $db->send_response(200, 'success', $feedback, 'Retrieved');
            break;
        case 'create-quick-feedback':
            $result = $grading->createQuickFeedback($input);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', $result['success'] ? ['feedback_id' => $result['feedback_id']] : null, $result['message'] ?? '');
            break;

        // ADJUSTMENTS
        case 'create-adjustment':
            $result = $grading->createAdjustment((int) ($input['attempt_id'] ?? 0), $input);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', $result['success'] ? ['adjustment_id' => $result['adjustment_id'], 'adjusted_score' => $result['adjusted_score']] : null, $result['message'] ?? '');
            break;
        case 'get-adjustments':
            $adjustments = $grading->getAdjustments((int) ($input['attempt_id'] ?? 0));
            $db->send_response(200, 'success', $adjustments, 'Retrieved');
            break;

        // QUESTION RUBRICS
        case 'assign-rubric-to-question':
            $result = $grading->assignRubricToQuestion((int) ($input['question_id'] ?? 0), (int) ($input['rubric_id'] ?? 0), $input['assessment_id'] ?? null);
            $db->send_response($result['success'] ? 200 : 400, $result['success'] ? 'success' : 'error', null, $result['message']);
            break;
        case 'get-question-rubric':
            $rubric = $grading->getQuestionRubric((int) ($input['question_id'] ?? 0), $input['assessment_id'] ?? null);
            $db->send_response(200, 'success', $rubric, 'Retrieved');
            break;

        // HISTORY & STATS
        case 'get-grading-history':
            $history = $grading->getGradingHistory((int) ($input['answer_id'] ?? 0));
            $db->send_response(200, 'success', $history, 'Retrieved');
            break;
        case 'get-grading-stats':
            $stats = $grading->getGradingStats($input['assessment_id'] ?? null);
            $db->send_response(200, 'success', $stats, 'Retrieved');
            break;

        default:
            $db->send_response(400, 'error', null, 'Invalid action');
    }
} catch (Exception $e) {
    error_log('Grading API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, 'Server error: ' . $e->getMessage());
}
