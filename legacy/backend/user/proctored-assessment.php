<?php

/**
 * Proctored Assessment API Endpoint
 *
 * Handles all Proctored Assessment operations via RESTful API
 * 
 * Features:
 * - Question Banks & Questions CRUD
 * - Assessment CRUD with 11 question types
 * - Attempt management with auto-grading
 * - Proctoring session management
 * - Certificate issuance
 * - Manual grading queue
 *
 * @package LMS
 * @version 1.0.0
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../class/ProctoredAssessment.php';

$db = new Database();
$conn = $db->getConnection();

try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        $db->send_response(400, 'error', null, 'Invalid JSON input');
        exit;
    }

    // Authentication
    $token = $input['token'] ?? null;
    $tenant_id = (int) ($input['tenant_id'] ?? 0);
    $user_id = (int) ($input['user_id'] ?? 0);
    $action = $input['action'] ?? '';

    if (!$token || !$tenant_id || !$user_id) {
        $db->send_response(401, 'error', null, 'Authentication required');
        exit;
    }

    // Validate token here (your existing auth logic)
    // ...

    $assessment = new ProctoredAssessment($conn, $tenant_id, $user_id);

    switch ($action) {
        // ==========================================
        // QUESTION BANK CRUD
        // ==========================================

        case 'create-question-bank':
            $data = $assessment->createQuestionBank($input);
            $db->send_response(201, 'success', $data, 'Question bank created successfully');
            break;

        case 'update-question-bank':
            $bank_id = (int) ($input['bank_id'] ?? 0);
            if (!$bank_id) {
                $db->send_response(400, 'error', null, 'Bank ID is required');
                break;
            }
            $data = $assessment->updateQuestionBank($bank_id, $input);
            $db->send_response(200, 'success', $data, 'Question bank updated successfully');
            break;

        case 'get-question-bank':
            $bank_id = (int) ($input['bank_id'] ?? 0);
            if (!$bank_id) {
                $db->send_response(400, 'error', null, 'Bank ID is required');
                break;
            }
            $data = $assessment->getQuestionBank($bank_id);
            if (!$data) {
                $db->send_response(404, 'error', null, 'Question bank not found');
                break;
            }
            $db->send_response(200, 'success', $data, 'Question bank retrieved');
            break;

        case 'get-question-banks':
            $filters = [
                'status' => $input['status'] ?? null,
                'search' => $input['search'] ?? null
            ];
            $data = $assessment->getQuestionBanks(array_filter($filters, fn($v) => $v !== null));
            $db->send_response(200, 'success', $data, 'Question banks retrieved');
            break;

        case 'delete-question-bank':
            $bank_id = (int) ($input['bank_id'] ?? 0);
            if (!$bank_id) {
                $db->send_response(400, 'error', null, 'Bank ID is required');
                break;
            }
            $assessment->deleteQuestionBank($bank_id);
            $db->send_response(200, 'success', null, 'Question bank deleted');
            break;

        // ==========================================
        // CATEGORY MANAGEMENT
        // ==========================================

        case 'create-category':
            $bank_id = (int) ($input['bank_id'] ?? 0);
            if (!$bank_id) {
                $db->send_response(400, 'error', null, 'Bank ID is required');
                break;
            }
            $data = $assessment->createCategory($bank_id, $input);
            $db->send_response(201, 'success', $data, 'Category created successfully');
            break;

        case 'get-category':
            $category_id = (int) ($input['category_id'] ?? 0);
            if (!$category_id) {
                $db->send_response(400, 'error', null, 'Category ID is required');
                break;
            }
            $data = $assessment->getCategory($category_id);
            if (!$data) {
                $db->send_response(404, 'error', null, 'Category not found');
                break;
            }
            $db->send_response(200, 'success', $data, 'Category retrieved');
            break;

        case 'get-bank-categories':
            $bank_id = (int) ($input['bank_id'] ?? 0);
            if (!$bank_id) {
                $db->send_response(400, 'error', null, 'Bank ID is required');
                break;
            }
            $data = $assessment->getBankCategories($bank_id);
            $db->send_response(200, 'success', $data, 'Categories retrieved');
            break;

        // ==========================================
        // TAG MANAGEMENT
        // ==========================================

        case 'create-tag':
            $data = $assessment->createTag($input);
            $db->send_response(201, 'success', $data, 'Tag created successfully');
            break;

        case 'get-tags':
            $data = $assessment->getTags();
            $db->send_response(200, 'success', $data, 'Tags retrieved');
            break;

        // ==========================================
        // QUESTION CRUD
        // ==========================================

        case 'create-question':
            if (empty($input['question_bank_id'])) {
                $db->send_response(400, 'error', null, 'Question bank ID is required');
                break;
            }
            if (empty($input['question_type'])) {
                $db->send_response(400, 'error', null, 'Question type is required');
                break;
            }
            if (empty($input['question_text'])) {
                $db->send_response(400, 'error', null, 'Question text is required');
                break;
            }
            $data = $assessment->createQuestion($input);
            $db->send_response(201, 'success', $data, 'Question created successfully');
            break;

        case 'update-question':
            $question_id = (int) ($input['question_id'] ?? 0);
            if (!$question_id) {
                $db->send_response(400, 'error', null, 'Question ID is required');
                break;
            }
            $data = $assessment->updateQuestion($question_id, $input);
            $db->send_response(200, 'success', $data, 'Question updated successfully');
            break;

        case 'get-question':
            $question_id = (int) ($input['question_id'] ?? 0);
            if (!$question_id) {
                $db->send_response(400, 'error', null, 'Question ID is required');
                break;
            }
            $data = $assessment->getQuestion($question_id);
            if (!$data) {
                $db->send_response(404, 'error', null, 'Question not found');
                break;
            }
            $db->send_response(200, 'success', $data, 'Question retrieved');
            break;

        case 'get-questions':
            $filters = [
                'question_bank_id' => $input['question_bank_id'] ?? null,
                'category_id' => $input['category_id'] ?? null,
                'question_type' => $input['question_type'] ?? null,
                'difficulty' => $input['difficulty'] ?? null,
                'status' => $input['status'] ?? null,
                'search' => $input['search'] ?? null,
                'page' => $input['page'] ?? 1,
                'limit' => $input['limit'] ?? 20
            ];
            $data = $assessment->getQuestions(array_filter($filters, fn($v) => $v !== null));
            $db->send_response(200, 'success', $data, 'Questions retrieved');
            break;

        case 'delete-question':
            $question_id = (int) ($input['question_id'] ?? 0);
            if (!$question_id) {
                $db->send_response(400, 'error', null, 'Question ID is required');
                break;
            }
            $assessment->deleteQuestion($question_id);
            $db->send_response(200, 'success', null, 'Question deleted');
            break;

        case 'clone-question':
            $question_id = (int) ($input['question_id'] ?? 0);
            if (!$question_id) {
                $db->send_response(400, 'error', null, 'Question ID is required');
                break;
            }
            $data = $assessment->cloneQuestion($question_id);
            $db->send_response(201, 'success', $data, 'Question cloned successfully');
            break;

        case 'bulk-delete-questions':
            $question_ids = $input['question_ids'] ?? [];
            if (empty($question_ids) || !is_array($question_ids)) {
                $db->send_response(400, 'error', null, 'Question IDs array is required');
                break;
            }
            $deleted = 0;
            foreach ($question_ids as $qid) {
                if ($assessment->deleteQuestion((int)$qid)) {
                    $deleted++;
                }
            }
            $db->send_response(200, 'success', ['deleted' => $deleted], "$deleted questions deleted");
            break;

        case 'test-grade-answer':
            // For testing auto-grading without saving
            $question_id = (int) ($input['question_id'] ?? 0);
            $answer_data = $input['answer_data'] ?? [];
            if (!$question_id) {
                $db->send_response(400, 'error', null, 'Question ID is required');
                break;
            }
            $data = $assessment->gradeAnswer($question_id, $answer_data);
            $db->send_response(200, 'success', $data, 'Grading result');
            break;

        // ==========================================
        // ASSESSMENT CRUD
        // ==========================================

        case 'create-assessment':
            if (empty($input['title'])) {
                $db->send_response(400, 'error', null, 'Assessment title is required');
                break;
            }
            $data = $assessment->createAssessment($input);
            $db->send_response(201, 'success', $data, 'Assessment created successfully');
            break;

        case 'update-assessment':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                break;
            }
            $data = $assessment->updateAssessment($assessment_id, $input);
            $db->send_response(200, 'success', $data, 'Assessment updated successfully');
            break;

        case 'get-assessment':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                break;
            }
            $data = $assessment->getAssessment($assessment_id);
            if (!$data) {
                $db->send_response(404, 'error', null, 'Assessment not found');
                break;
            }
            $db->send_response(200, 'success', $data, 'Assessment retrieved');
            break;

        case 'get-assessments':
            $filters = [
                'status' => $input['status'] ?? null,
                'course_id' => $input['course_id'] ?? null,
                'live_training_id' => $input['live_training_id'] ?? null,
                'is_standalone' => isset($input['is_standalone']) ? (int)$input['is_standalone'] : null,
                'proctoring_level' => $input['proctoring_level'] ?? null,
                'search' => $input['search'] ?? null,
                'page' => $input['page'] ?? 1,
                'limit' => $input['limit'] ?? 20
            ];
            $data = $assessment->getAssessments(array_filter($filters, fn($v) => $v !== null));
            $db->send_response(200, 'success', $data, 'Assessments retrieved');
            break;

        case 'delete-assessment':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                break;
            }
            $assessment->deleteAssessment($assessment_id);
            $db->send_response(200, 'success', null, 'Assessment deleted');
            break;

        case 'publish-assessment':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                break;
            }
            try {
                $data = $assessment->publishAssessment($assessment_id);
                $db->send_response(200, 'success', $data, 'Assessment published successfully');
            } catch (Exception $e) {
                $db->send_response(400, 'error', null, $e->getMessage());
            }
            break;

        // ==========================================
        // ASSESSMENT SECTIONS
        // ==========================================

        case 'create-section':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                break;
            }
            if (empty($input['title'])) {
                $db->send_response(400, 'error', null, 'Section title is required');
                break;
            }
            $data = $assessment->createAssessmentSection($assessment_id, $input);
            $db->send_response(201, 'success', $data, 'Section created successfully');
            break;

        case 'get-assessment-sections':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                break;
            }
            $data = $assessment->getAssessmentSections($assessment_id);
            $db->send_response(200, 'success', $data, 'Sections retrieved');
            break;

        // ==========================================
        // ASSESSMENT QUESTIONS
        // ==========================================

        case 'add-assessment-questions':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            $questions = $input['questions'] ?? [];
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                break;
            }
            if (empty($questions) || !is_array($questions)) {
                $db->send_response(400, 'error', null, 'Questions array is required');
                break;
            }
            $count = $assessment->addAssessmentQuestions($assessment_id, $questions);
            $db->send_response(200, 'success', ['added' => $count], "$count questions added to assessment");
            break;

        case 'remove-assessment-question':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            $question_id = (int) ($input['question_id'] ?? 0);
            if (!$assessment_id || !$question_id) {
                $db->send_response(400, 'error', null, 'Assessment ID and Question ID are required');
                break;
            }
            $assessment->removeAssessmentQuestion($assessment_id, $question_id);
            $db->send_response(200, 'success', null, 'Question removed from assessment');
            break;

        case 'get-assessment-questions':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                break;
            }
            $data = $assessment->getAssessmentQuestions($assessment_id);
            $db->send_response(200, 'success', $data, 'Assessment questions retrieved');
            break;

        // ==========================================
        // PROCTOR SETTINGS
        // ==========================================

        case 'get-proctor-settings':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                break;
            }
            $data = $assessment->getProctorSettings($assessment_id);
            $db->send_response(200, 'success', $data, 'Proctor settings retrieved');
            break;

        case 'update-proctor-settings':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                break;
            }
            $data = $assessment->updateProctorSettings($assessment_id, $input);
            $db->send_response(200, 'success', $data, 'Proctor settings updated');
            break;

        // ==========================================
        // LEARNER - MY ASSESSMENTS
        // ==========================================

        case 'get-my-assessments':
            $filters = [
                'status' => $input['status'] ?? null,
                'search' => $input['search'] ?? null
            ];
            $data = $assessment->getMyAssessments(array_filter($filters, fn($v) => $v !== null));
            $db->send_response(200, 'success', $data, 'My assessments retrieved');
            break;

        // ==========================================
        // ATTEMPT MANAGEMENT
        // ==========================================

        case 'start-attempt':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                break;
            }
            try {
                $data = $assessment->startAttempt($assessment_id);
                $db->send_response(201, 'success', $data, 'Attempt started successfully');
            } catch (Exception $e) {
                $db->send_response(400, 'error', null, $e->getMessage());
            }
            break;

        case 'save-answer':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            $question_id = (int) ($input['question_id'] ?? 0);
            $answer_data = $input['answer_data'] ?? [];
            if (!$attempt_id || !$question_id) {
                $db->send_response(400, 'error', null, 'Attempt ID and Question ID are required');
                break;
            }
            try {
                $data = $assessment->saveAnswer($attempt_id, $question_id, $answer_data);
                $db->send_response(200, 'success', $data, 'Answer saved');
            } catch (Exception $e) {
                $db->send_response(400, 'error', null, $e->getMessage());
            }
            break;

        case 'submit-attempt':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            if (!$attempt_id) {
                $db->send_response(400, 'error', null, 'Attempt ID is required');
                break;
            }
            try {
                $data = $assessment->submitAttempt($attempt_id);
                $db->send_response(200, 'success', $data, 'Attempt submitted successfully');
            } catch (Exception $e) {
                $db->send_response(400, 'error', null, $e->getMessage());
            }
            break;

        case 'get-attempt-results':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            if (!$attempt_id) {
                $db->send_response(400, 'error', null, 'Attempt ID is required');
                break;
            }
            try {
                $data = $assessment->getAttemptResults($attempt_id);
                $db->send_response(200, 'success', $data, 'Attempt results retrieved');
            } catch (Exception $e) {
                $db->send_response(400, 'error', null, $e->getMessage());
            }
            break;

        // ==========================================
        // GRADING QUEUE (Admin)
        // ==========================================

        case 'get-grading-queue':
            $filters = [
                'assessment_id' => $input['assessment_id'] ?? null,
                'question_type' => $input['question_type'] ?? null
            ];
            $data = $assessment->getGradingQueue(array_filter($filters, fn($v) => $v !== null));
            $db->send_response(200, 'success', $data, 'Grading queue retrieved');
            break;

        case 'grade-answer':
            $answer_id = (int) ($input['answer_id'] ?? 0);
            if (!$answer_id) {
                $db->send_response(400, 'error', null, 'Answer ID is required');
                break;
            }
            $grading = [
                'is_correct' => $input['is_correct'] ?? null,
                'is_partial' => $input['is_partial'] ?? 0,
                'points_earned' => $input['points_earned'] ?? 0,
                'feedback' => $input['feedback'] ?? null,
                'grader_notes' => $input['grader_notes'] ?? null
            ];
            try {
                $data = $assessment->gradeAnswerManually($answer_id, $grading);
                $db->send_response(200, 'success', $data, 'Answer graded successfully');
            } catch (Exception $e) {
                $db->send_response(400, 'error', null, $e->getMessage());
            }
            break;

        case 'bulk-grade-answers':
            $answers = $input['answers'] ?? [];
            if (empty($answers) || !is_array($answers)) {
                $db->send_response(400, 'error', null, 'Answers array is required');
                break;
            }
            $graded = 0;
            $errors = [];
            foreach ($answers as $answer) {
                try {
                    $assessment->gradeAnswerManually((int)$answer['answer_id'], $answer);
                    $graded++;
                } catch (Exception $e) {
                    $errors[] = ['answer_id' => $answer['answer_id'], 'error' => $e->getMessage()];
                }
            }
            $db->send_response(200, 'success', ['graded' => $graded, 'errors' => $errors], "$graded answers graded");
            break;

        // ==========================================
        // PROCTORING SESSION
        // ==========================================

        case 'start-proctor-session':
            $attempt_id = (int) ($input['attempt_id'] ?? 0);
            if (!$attempt_id) {
                $db->send_response(400, 'error', null, 'Attempt ID is required');
                break;
            }
            try {
                $data = $assessment->startProctorSession($attempt_id, $input);
                $db->send_response(201, 'success', $data, 'Proctor session started');
            } catch (Exception $e) {
                $db->send_response(400, 'error', null, $e->getMessage());
            }
            break;

        case 'end-proctor-session':
            $session_id = (int) ($input['session_id'] ?? 0);
            if (!$session_id) {
                $db->send_response(400, 'error', null, 'Session ID is required');
                break;
            }
            try {
                $data = $assessment->endProctorSession($session_id);
                $db->send_response(200, 'success', $data, 'Proctor session ended');
            } catch (Exception $e) {
                $db->send_response(400, 'error', null, $e->getMessage());
            }
            break;

        case 'record-violation':
            $session_id = (int) ($input['session_id'] ?? 0);
            $violation_type = $input['violation_type'] ?? '';
            if (!$session_id || !$violation_type) {
                $db->send_response(400, 'error', null, 'Session ID and violation type are required');
                break;
            }
            try {
                $data = $assessment->recordViolation($session_id, $violation_type, $input);
                $db->send_response(201, 'success', $data, 'Violation recorded');
            } catch (Exception $e) {
                $db->send_response(400, 'error', null, $e->getMessage());
            }
            break;

        case 'upload-snapshot':
            $session_id = (int) ($input['session_id'] ?? 0);
            $image_data = $input['image_data'] ?? '';
            if (!$session_id || !$image_data) {
                $db->send_response(400, 'error', null, 'Session ID and image data are required');
                break;
            }
            try {
                $data = $assessment->uploadSnapshot($session_id, $image_data, $input);
                $db->send_response(201, 'success', $data, 'Snapshot uploaded');
            } catch (Exception $e) {
                $db->send_response(400, 'error', null, $e->getMessage());
            }
            break;

        case 'get-proctor-sessions':
            $filters = [
                'assessment_id' => $input['assessment_id'] ?? null,
                'status' => $input['status'] ?? null,
                'flagged' => isset($input['flagged']) ? (int)$input['flagged'] : null,
                'page' => $input['page'] ?? 1,
                'limit' => $input['limit'] ?? 20
            ];
            $data = $assessment->getProctorSessions(array_filter($filters, fn($v) => $v !== null));
            $db->send_response(200, 'success', $data, 'Proctor sessions retrieved');
            break;

        case 'get-session-violations':
            $session_id = (int) ($input['session_id'] ?? 0);
            if (!$session_id) {
                $db->send_response(400, 'error', null, 'Session ID is required');
                break;
            }
            $data = $assessment->getSessionViolations($session_id);
            $db->send_response(200, 'success', $data, 'Session violations retrieved');
            break;

        case 'get-session-snapshots':
            $session_id = (int) ($input['session_id'] ?? 0);
            if (!$session_id) {
                $db->send_response(400, 'error', null, 'Session ID is required');
                break;
            }
            $data = $assessment->getSessionSnapshots($session_id);
            $db->send_response(200, 'success', $data, 'Session snapshots retrieved');
            break;

        case 'review-proctor-session':
            $session_id = (int) ($input['session_id'] ?? 0);
            if (!$session_id) {
                $db->send_response(400, 'error', null, 'Session ID is required');
                break;
            }
            try {
                $data = $assessment->reviewProctorSession($session_id, $input);
                $db->send_response(200, 'success', $data, 'Session review saved');
            } catch (Exception $e) {
                $db->send_response(400, 'error', null, $e->getMessage());
            }
            break;

        // ==========================================
        // CERTIFICATE TEMPLATES
        // ==========================================

        case 'create-certificate-template':
            if (empty($input['name'])) {
                $db->send_response(400, 'error', null, 'Template name is required');
                break;
            }
            $data = $assessment->createCertificateTemplate($input);
            $db->send_response(201, 'success', $data, 'Certificate template created');
            break;

        case 'get-certificate-templates':
            $status = $input['status'] ?? null;
            $data = $assessment->getCertificateTemplates($status);
            $db->send_response(200, 'success', $data, 'Certificate templates retrieved');
            break;

        case 'get-certificate-template':
            $template_id = (int) ($input['template_id'] ?? 0);
            if (!$template_id) {
                $db->send_response(400, 'error', null, 'Template ID is required');
                break;
            }
            $data = $assessment->getCertificateTemplate($template_id);
            if (!$data) {
                $db->send_response(404, 'error', null, 'Template not found');
                break;
            }
            $db->send_response(200, 'success', $data, 'Certificate template retrieved');
            break;

        // ==========================================
        // ISSUED CERTIFICATES
        // ==========================================

        case 'get-my-certificates':
            $status = $input['status'] ?? null;
            $data = $assessment->getMyCertificates($status);
            $db->send_response(200, 'success', $data, 'My certificates retrieved');
            break;

        case 'verify-certificate':
            $code = $input['verification_code'] ?? '';
            if (!$code) {
                $db->send_response(400, 'error', null, 'Verification code is required');
                break;
            }
            $data = $assessment->verifyCertificate($code);
            $db->send_response(200, 'success', $data, 'Certificate verification result');
            break;

        // ==========================================
        // ANALYTICS & REPORTS
        // ==========================================

        case 'get-assessment-analytics':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                break;
            }
            $data = $assessment->getAssessmentAnalytics($assessment_id);
            $db->send_response(200, 'success', $data, 'Assessment analytics retrieved');
            break;

        case 'get-question-analytics':
            $question_id = (int) ($input['question_id'] ?? 0);
            if (!$question_id) {
                $db->send_response(400, 'error', null, 'Question ID is required');
                break;
            }
            $data = $assessment->getQuestionAnalytics($question_id);
            $db->send_response(200, 'success', $data, 'Question analytics retrieved');
            break;

        case 'export-results':
            $assessment_id = (int) ($input['assessment_id'] ?? 0);
            if (!$assessment_id) {
                $db->send_response(400, 'error', null, 'Assessment ID is required');
                break;
            }
            $format = $input['format'] ?? 'csv';
            $data = $assessment->exportResults($assessment_id, $format);
            $db->send_response(200, 'success', $data, 'Export generated');
            break;

        // ==========================================
        // DEFAULT
        // ==========================================

        default:
            $db->send_response(400, 'error', null, 
                'Invalid action. Available actions: ' .
                // Question Banks
                'create-question-bank, update-question-bank, get-question-bank, get-question-banks, delete-question-bank, ' .
                // Categories & Tags
                'create-category, get-category, get-bank-categories, create-tag, get-tags, ' .
                // Questions
                'create-question, update-question, get-question, get-questions, delete-question, clone-question, bulk-delete-questions, test-grade-answer, ' .
                // Assessments
                'create-assessment, update-assessment, get-assessment, get-assessments, delete-assessment, publish-assessment, ' .
                // Assessment Structure
                'create-section, get-assessment-sections, add-assessment-questions, remove-assessment-question, get-assessment-questions, ' .
                // Proctor Settings
                'get-proctor-settings, update-proctor-settings, ' .
                // Learner
                'get-my-assessments, start-attempt, save-answer, submit-attempt, get-attempt-results, ' .
                // Grading
                'get-grading-queue, grade-answer, bulk-grade-answers, ' .
                // Proctoring
                'start-proctor-session, end-proctor-session, record-violation, upload-snapshot, get-proctor-sessions, get-session-violations, get-session-snapshots, review-proctor-session, ' .
                // Certificates
                'create-certificate-template, get-certificate-templates, get-certificate-template, get-my-certificates, verify-certificate, ' .
                // Analytics
                'get-assessment-analytics, get-question-analytics, export-results'
            );
    }

} catch (Exception $e) {
    error_log('Proctored Assessment API Error: ' . $e->getMessage());
    $db->send_response(500, 'error', null, $e->getMessage());
}
