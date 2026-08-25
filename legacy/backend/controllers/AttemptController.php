<?php
/**
 * Attempt Controller
 * Handles assessment attempts and learner actions
 */

namespace App\Controllers\ProctoredAssessment;

use App\Models\ProctoredAssessment\Assessment;
use App\Models\ProctoredAssessment\Question;

class AttemptController extends BaseController
{
    private $assessmentModel;
    private $questionModel;

    public function __construct()
    {
        parent::__construct();
        $this->assessmentModel = new Assessment($this->db);
        $this->questionModel = new Question($this->db);
    }

    // ==================== LEARNER ENDPOINTS ====================

    /**
     * GET /api/proc/learner/assessments
     * List assessments available to learner
     */
    public function myAssessments()
    {
        try {
            $status = $this->input('status');
            $search = $this->input('search');
            $page = (int)($this->input('page') ?? 1);
            $limit = (int)($this->input('limit') ?? 20);
            $offset = ($page - 1) * $limit;

            // Get assigned assessments
            $sql = "SELECT DISTINCT a.*,
                           aa.id as assignment_id,
                           aa.available_from_override,
                           aa.available_until_override,
                           aa.max_attempts_override,
                           (SELECT COUNT(*) FROM proc_assessment_attempts 
                            WHERE assessment_id = a.id AND user_id = :user_id) as attempts_used,
                           (SELECT MAX(percentage_score) FROM proc_assessment_attempts 
                            WHERE assessment_id = a.id AND user_id = :user_id AND status = 'graded') as best_score,
                           (SELECT MAX(submitted_at) FROM proc_assessment_attempts 
                            WHERE assessment_id = a.id AND user_id = :user_id) as last_attempt_at
                    FROM proc_assessments a
                    LEFT JOIN proc_assessment_assignments aa ON a.id = aa.assessment_id 
                        AND (aa.user_id = :user_id OR aa.user_id IS NULL)
                    WHERE a.tenant_id = :tenant_id
                    AND a.status = 'published'
                    AND (aa.user_id = :user_id OR a.access_type = 'public' 
                         OR (a.course_id IS NOT NULL AND EXISTS (
                             SELECT 1 FROM course_enrollments ce 
                             WHERE ce.course_id = a.course_id AND ce.user_id = :user_id
                         )))";

            if ($search) {
                $sql .= " AND (a.title LIKE :search OR a.description LIKE :search)";
            }

            $sql .= " ORDER BY a.created_at DESC LIMIT $limit OFFSET $offset";

            $params = [
                'tenant_id' => $this->tenantId,
                'user_id' => $this->userId
            ];
            if ($search) {
                $params['search'] = '%' . $search . '%';
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $assessments = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Determine can_attempt for each
            foreach ($assessments as &$assessment) {
                $result = $this->canAttempt($assessment);
                $assessment['can_attempt'] = $result['can'];
                $assessment['reason'] = $result['reason'];
                $assessment['attempts_remaining'] = $this->getAttemptsRemaining($assessment);
            }

            // Apply status filter
            if ($status) {
                $assessments = array_filter($assessments, function($a) use ($status) {
                    if ($status === 'pending') return $a['attempts_used'] == 0;
                    if ($status === 'in_progress') return $a['attempts_used'] > 0 && $a['can_attempt'];
                    if ($status === 'completed') return !$a['can_attempt'] && $a['attempts_used'] > 0;
                    return true;
                });
            }

            return $this->json([
                'data' => array_values($assessments),
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit
                ]
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * GET /api/proc/learner/assessments/{id}
     * Get assessment details for learner
     */
    public function assessmentDetails($id)
    {
        try {
            $assessment = $this->assessmentModel->getById($id, $this->tenantId, false);
            if (!$assessment) {
                return $this->notFound('Assessment not found');
            }

            // Check access
            $access = $this->assessmentModel->canUserAccess($id, $this->userId, $this->tenantId);
            
            // Get attempts
            $sql = "SELECT * FROM proc_assessment_attempts 
                    WHERE assessment_id = :id AND user_id = :user_id AND tenant_id = :tenant_id
                    ORDER BY attempt_number DESC";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $id, 'user_id' => $this->userId, 'tenant_id' => $this->tenantId]);
            $attempts = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $attemptsUsed = count($attempts);
            $maxAttempts = $assessment['max_attempts'];
            $attemptsRemaining = max(0, $maxAttempts - $attemptsUsed);

            // Get best score
            $bestScore = null;
            $lastAttempt = null;
            foreach ($attempts as $attempt) {
                if ($attempt['status'] === 'graded') {
                    if ($bestScore === null || $attempt['percentage_score'] > $bestScore) {
                        $bestScore = $attempt['percentage_score'];
                    }
                }
                if ($lastAttempt === null || $attempt['attempt_number'] > $lastAttempt['attempt_number']) {
                    $lastAttempt = $attempt;
                }
            }

            // Check if can attempt
            $canAttempt = $access['can_access'] && $attemptsRemaining > 0;
            $reason = $access['reason'];

            if ($canAttempt && $lastAttempt && $lastAttempt['status'] === 'in_progress') {
                $canAttempt = true;
                $reason = 'You have an attempt in progress';
            }

            return $this->success([
                'assessment' => $assessment,
                'can_attempt' => $canAttempt,
                'reason' => $reason,
                'attempts_used' => $attemptsUsed,
                'attempts_remaining' => $attemptsRemaining,
                'best_score' => $bestScore,
                'last_attempt' => $lastAttempt
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * POST /api/proc/learner/assessments/{id}/start
     * Start new assessment attempt
     */
    public function startAttempt($assessmentId)
    {
        try {
            // Get assessment
            $assessment = $this->assessmentModel->getById($assessmentId, $this->tenantId);
            if (!$assessment) {
                return $this->notFound('Assessment not found');
            }

            // Check access
            $access = $this->assessmentModel->canUserAccess($assessmentId, $this->userId, $this->tenantId);
            if (!$access['can_access']) {
                return $this->forbidden($access['reason']);
            }

            // Check attempts
            $attemptsUsed = $this->assessmentModel->getUserAttemptCount($assessmentId, $this->userId);
            if ($attemptsUsed >= $assessment['max_attempts']) {
                return $this->forbidden('Maximum attempts reached');
            }

            // Check for existing in-progress attempt
            $sql = "SELECT * FROM proc_assessment_attempts 
                    WHERE assessment_id = :id AND user_id = :user_id AND status = 'in_progress'";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $assessmentId, 'user_id' => $this->userId]);
            $existingAttempt = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($existingAttempt) {
                // Return existing attempt
                return $this->resumeAttempt($existingAttempt['id']);
            }

            // Get questions
            $questions = $this->getQuestionsForAttempt($assessment);

            // Create attempt
            $sql = "INSERT INTO proc_assessment_attempts (
                        tenant_id, assessment_id, user_id, attempt_number, status,
                        total_questions, selected_question_ids, question_order,
                        time_remaining_seconds, started_at, ip_address, user_agent
                    ) VALUES (
                        :tenant_id, :assessment_id, :user_id, :attempt_number, 'in_progress',
                        :total_questions, :selected_question_ids, :question_order,
                        :time_remaining_seconds, NOW(), :ip_address, :user_agent
                    )";

            $questionIds = array_column($questions, 'id');
            $questionOrder = $assessment['shuffle_questions'] ? $this->shuffleArray($questionIds) : $questionIds;

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'tenant_id' => $this->tenantId,
                'assessment_id' => $assessmentId,
                'user_id' => $this->userId,
                'attempt_number' => $attemptsUsed + 1,
                'total_questions' => count($questions),
                'selected_question_ids' => json_encode($questionIds),
                'question_order' => json_encode($questionOrder),
                'time_remaining_seconds' => $assessment['time_limit_minutes'] ? $assessment['time_limit_minutes'] * 60 : null,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);

            $attemptId = $this->db->lastInsertId();

            // Create empty answer records
            foreach ($questions as $question) {
                $sql = "INSERT INTO proc_attempt_answers (attempt_id, question_id, points_possible)
                        VALUES (:attempt_id, :question_id, :points)";
                $stmt = $this->db->prepare($sql);
                $stmt->execute([
                    'attempt_id' => $attemptId,
                    'question_id' => $question['id'],
                    'points' => $question['points_override'] ?? $question['points']
                ]);
            }

            // Log timeline event
            $this->logTimelineEvent($attemptId, 'attempt_started');

            // Get attempt with questions
            $sql = "SELECT * FROM proc_assessment_attempts WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $attemptId]);
            $attempt = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Prepare questions for response (shuffle options if needed)
            $preparedQuestions = $this->prepareQuestionsForLearner($questions, $assessment);

            return $this->success([
                'attempt' => $attempt,
                'questions' => $preparedQuestions,
                'time_limit_seconds' => $assessment['time_limit_minutes'] ? $assessment['time_limit_minutes'] * 60 : null
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * GET /api/proc/learner/attempts/{id}/resume
     * Resume existing attempt
     */
    public function resumeAttempt($attemptId)
    {
        try {
            // Get attempt
            $sql = "SELECT * FROM proc_assessment_attempts 
                    WHERE id = :id AND user_id = :user_id AND tenant_id = :tenant_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $attemptId, 'user_id' => $this->userId, 'tenant_id' => $this->tenantId]);
            $attempt = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$attempt) {
                return $this->notFound('Attempt not found');
            }

            if ($attempt['status'] !== 'in_progress') {
                return $this->error('Attempt is not in progress');
            }

            // Get assessment
            $assessment = $this->assessmentModel->getById($attempt['assessment_id'], $this->tenantId);

            // Get questions
            $questionIds = json_decode($attempt['selected_question_ids'], true);
            $questions = $this->getQuestionsByIds($questionIds);

            // Get existing answers
            $sql = "SELECT * FROM proc_attempt_answers WHERE attempt_id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $attemptId]);
            $answers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Update last activity
            $sql = "UPDATE proc_assessment_attempts SET last_activity_at = NOW() WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $attemptId]);

            // Log resume
            $this->logTimelineEvent($attemptId, 'attempt_resumed');

            // Calculate remaining time
            $timeRemaining = null;
            if ($attempt['time_remaining_seconds'] !== null) {
                $started = new \DateTime($attempt['started_at']);
                $now = new \DateTime();
                $elapsed = $now->getTimestamp() - $started->getTimestamp();
                $totalTime = $assessment['time_limit_minutes'] * 60;
                $timeRemaining = max(0, $totalTime - $elapsed);
            }

            return $this->success([
                'attempt' => $attempt,
                'questions' => $this->prepareQuestionsForLearner($questions, $assessment),
                'answers' => $answers,
                'time_remaining_seconds' => $timeRemaining
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * POST /api/proc/learner/attempts/{id}/answer
     * Save answer for a question
     */
    public function saveAnswer($attemptId)
    {
        try {
            // Verify attempt belongs to user
            $attempt = $this->getAttemptForUser($attemptId);
            if (!$attempt) {
                return $this->notFound('Attempt not found');
            }

            if ($attempt['status'] !== 'in_progress') {
                return $this->error('Attempt is not in progress');
            }

            $data = $this->getJsonInput();
            $questionId = $data['question_id'];
            $answerData = $data['answer_data'];

            // Get question
            $question = $this->questionModel->getById($questionId, $this->tenantId);
            if (!$question) {
                return $this->error('Question not found');
            }

            // Auto-grade if possible
            $gradeResult = $this->questionModel->gradeAnswer($questionId, $answerData, $this->tenantId);

            // Update answer
            $sql = "UPDATE proc_attempt_answers SET
                        answer_data = :answer_data,
                        answer_text = :answer_text,
                        is_correct = :is_correct,
                        is_partial = :is_partial,
                        points_earned = :points_earned,
                        auto_graded = :auto_graded,
                        grading_status = :grading_status,
                        answered_at = NOW(),
                        last_modified_at = NOW(),
                        change_count = change_count + 1,
                        skipped = 0
                    WHERE attempt_id = :attempt_id AND question_id = :question_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'attempt_id' => $attemptId,
                'question_id' => $questionId,
                'answer_data' => json_encode($answerData),
                'answer_text' => $answerData['text'] ?? null,
                'is_correct' => $gradeResult['is_correct'] ? 1 : ($gradeResult['is_correct'] === false ? 0 : null),
                'is_partial' => $gradeResult['is_partial'] ? 1 : 0,
                'points_earned' => $gradeResult['points_earned'],
                'auto_graded' => $gradeResult['auto_graded'] ? 1 : 0,
                'grading_status' => $gradeResult['auto_graded'] ? 'auto_graded' : 'pending'
            ]);

            // Update attempt stats
            $this->updateAttemptProgress($attemptId);

            // Log event
            $this->logTimelineEvent($attemptId, 'question_answered', ['question_id' => $questionId]);

            return $this->success([
                'saved' => true,
                'auto_graded' => $gradeResult['auto_graded'],
                'is_correct' => $gradeResult['is_correct'],
                'points_earned' => $gradeResult['points_earned']
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * DELETE /api/proc/learner/attempts/{id}/answer/{questionId}
     * Clear answer for a question
     */
    public function clearAnswer($attemptId, $questionId)
    {
        try {
            $attempt = $this->getAttemptForUser($attemptId);
            if (!$attempt || $attempt['status'] !== 'in_progress') {
                return $this->error('Invalid attempt');
            }

            $sql = "UPDATE proc_attempt_answers SET
                        answer_data = NULL,
                        answer_text = NULL,
                        is_correct = NULL,
                        is_partial = 0,
                        points_earned = 0,
                        grading_status = 'pending',
                        last_modified_at = NOW()
                    WHERE attempt_id = :attempt_id AND question_id = :question_id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute(['attempt_id' => $attemptId, 'question_id' => $questionId]);

            $this->updateAttemptProgress($attemptId);
            $this->logTimelineEvent($attemptId, 'answer_cleared', ['question_id' => $questionId]);

            return $this->success(null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * POST /api/proc/learner/attempts/{id}/mark-review
     * Mark question for review
     */
    public function markForReview($attemptId)
    {
        try {
            $attempt = $this->getAttemptForUser($attemptId);
            if (!$attempt || $attempt['status'] !== 'in_progress') {
                return $this->error('Invalid attempt');
            }

            $data = $this->getJsonInput();
            $questionId = $data['question_id'];
            $marked = $data['marked'] ? 1 : 0;

            $sql = "UPDATE proc_attempt_answers SET marked_for_review = :marked 
                    WHERE attempt_id = :attempt_id AND question_id = :question_id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['attempt_id' => $attemptId, 'question_id' => $questionId, 'marked' => $marked]);

            $eventType = $marked ? 'marked_for_review' : 'unmarked_for_review';
            $this->logTimelineEvent($attemptId, $eventType, ['question_id' => $questionId]);

            return $this->success(null);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * POST /api/proc/learner/attempts/{id}/submit
     * Submit attempt
     */
    public function submitAttempt($attemptId)
    {
        try {
            $attempt = $this->getAttemptForUser($attemptId);
            if (!$attempt) {
                return $this->notFound('Attempt not found');
            }

            if ($attempt['status'] !== 'in_progress') {
                return $this->error('Attempt is not in progress');
            }

            // Calculate final scores
            $this->calculateFinalScores($attemptId);

            // Get updated attempt
            $sql = "SELECT * FROM proc_assessment_attempts WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $attemptId]);
            $attempt = $stmt->fetch(\PDO::FETCH_ASSOC);

            // Get assessment for passing score
            $assessment = $this->assessmentModel->getById($attempt['assessment_id'], $this->tenantId, false);

            // Determine if passed
            $passed = false;
            if ($assessment['passing_type'] === 'percentage') {
                $passed = $attempt['percentage_score'] >= $assessment['passing_score'];
            } else {
                $passed = $attempt['raw_score'] >= $assessment['passing_score'];
            }

            // Calculate grade if grading enabled
            $grade = null;
            if ($assessment['enable_grading'] && $assessment['grade_boundaries']) {
                $boundaries = json_decode($assessment['grade_boundaries'], true);
                foreach ($boundaries as $boundary) {
                    if ($attempt['percentage_score'] >= $boundary['min']) {
                        $grade = $boundary['grade'];
                        break;
                    }
                }
            }

            // Determine final status
            $hasPendingGrading = $attempt['questions_pending_grade'] > 0;
            $status = $hasPendingGrading ? 'grading' : 'graded';

            // Update attempt
            $sql = "UPDATE proc_assessment_attempts SET
                        status = :status,
                        submitted_at = NOW(),
                        graded_at = :graded_at,
                        passed = :passed,
                        grade = :grade
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                'id' => $attemptId,
                'status' => $status,
                'graded_at' => $hasPendingGrading ? null : date('Y-m-d H:i:s'),
                'passed' => $passed ? 1 : 0,
                'grade' => $grade
            ]);

            // Log event
            $this->logTimelineEvent($attemptId, 'submitted');

            // Update assessment stats
            $this->assessmentModel->updateStats($attempt['assessment_id']);

            // Issue certificate if applicable
            $certificateIssued = false;
            $certificateId = null;
            if ($passed && $assessment['issue_certificate'] && !$hasPendingGrading) {
                $certResult = $this->issueCertificate($attempt, $assessment);
                $certificateIssued = $certResult['issued'];
                $certificateId = $certResult['certificate_id'];
            }

            // Get final attempt data
            $sql = "SELECT * FROM proc_assessment_attempts WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['id' => $attemptId]);
            $finalAttempt = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $this->success([
                'attempt' => $finalAttempt,
                'results' => [
                    'raw_score' => $finalAttempt['raw_score'],
                    'total_possible' => $finalAttempt['total_possible'],
                    'percentage_score' => $finalAttempt['percentage_score'],
                    'passed' => $passed,
                    'grade' => $grade,
                    'questions_correct' => $finalAttempt['questions_correct'],
                    'questions_incorrect' => $finalAttempt['questions_incorrect'],
                    'questions_partial' => $finalAttempt['questions_partial'],
                    'time_spent_seconds' => $finalAttempt['time_spent_seconds']
                ],
                'certificate_issued' => $certificateIssued,
                'certificate_id' => $certificateId
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * GET /api/proc/learner/attempts/{id}/results
     * Get attempt results
     */
    public function getResults($attemptId)
    {
        try {
            $attempt = $this->getAttemptForUser($attemptId);
            if (!$attempt) {
                return $this->notFound('Attempt not found');
            }

            if (!in_array($attempt['status'], ['submitted', 'grading', 'graded'])) {
                return $this->error('Attempt not yet submitted');
            }

            $assessment = $this->assessmentModel->getById($attempt['assessment_id'], $this->tenantId, false);

            // Get answers with questions
            $sql = "SELECT aa.*, 
                           q.question_type, q.question_text, q.question_html, 
                           q.explanation, q.points
                    FROM proc_attempt_answers aa
                    INNER JOIN proc_questions q ON aa.question_id = q.id
                    WHERE aa.attempt_id = :attempt_id
                    ORDER BY aa.id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute(['attempt_id' => $attemptId]);
            $answers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            // Determine what to show
            $showCorrectAnswers = $this->shouldShowCorrectAnswers($assessment, $attempt);
            $showExplanations = $assessment['show_explanations'] && $showCorrectAnswers;

            // Add correct answers and options if allowed
            foreach ($answers as &$answer) {
                $question = $this->questionModel->getById($answer['question_id'], $this->tenantId);
                $answer['question'] = $question;
                
                if (!$showCorrectAnswers) {
                    // Remove correct answer indicators
                    if ($answer['question']['options']) {
                        foreach ($answer['question']['options'] as &$opt) {
                            unset($opt['is_correct']);
                            unset($opt['acceptable_answers']);
                        }
                    }
                    unset($answer['question']['explanation']);
                }

                $answer['answer_data'] = json_decode($answer['answer_data'], true);
            }

            return $this->success([
                'attempt' => $attempt,
                'assessment' => $assessment,
                'answers' => $answers,
                'show_correct_answers' => $showCorrectAnswers,
                'show_explanations' => $showExplanations
            ]);
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }
    }

    // ==================== HELPER METHODS ====================

    private function getAttemptForUser($attemptId)
    {
        $sql = "SELECT * FROM proc_assessment_attempts 
                WHERE id = :id AND user_id = :user_id AND tenant_id = :tenant_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $attemptId, 'user_id' => $this->userId, 'tenant_id' => $this->tenantId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    private function getQuestionsForAttempt($assessment)
    {
        if ($assessment['question_selection'] === 'random') {
            return $this->getRandomQuestions($assessment);
        }
        return $assessment['questions'];
    }

    private function getRandomQuestions($assessment)
    {
        $questions = [];
        $rules = $this->assessmentModel->getRandomRules($assessment['id']);

        foreach ($rules as $rule) {
            $selected = $this->questionModel->getRandomQuestions($this->tenantId, [
                'question_bank_id' => $rule['question_bank_id'],
                'category_id' => $rule['category_id'],
                'difficulty' => $rule['difficulty'],
                'count' => $rule['question_count'],
                'exclude_ids' => array_column($questions, 'id')
            ]);

            foreach ($selected as $qId) {
                $q = $this->questionModel->getById($qId, $this->tenantId);
                if ($q) {
                    $q['points_override'] = $rule['points_per_question'];
                    $questions[] = $q;
                }
            }
        }

        return $questions;
    }

    private function getQuestionsByIds($ids)
    {
        $questions = [];
        foreach ($ids as $id) {
            $q = $this->questionModel->getById($id, $this->tenantId);
            if ($q) $questions[] = $q;
        }
        return $questions;
    }

    private function prepareQuestionsForLearner($questions, $assessment)
    {
        foreach ($questions as &$q) {
            // Remove correct answers
            if ($q['options']) {
                foreach ($q['options'] as &$opt) {
                    unset($opt['is_correct']);
                    unset($opt['acceptable_answers']);
                    unset($opt['match_text']); // For matching, will be provided separately
                    unset($opt['correct_position']);
                }

                // Shuffle options if enabled
                if ($assessment['shuffle_options']) {
                    shuffle($q['options']);
                }
            }

            // Remove hints if not allowed
            if (!isset($assessment['show_hints']) || !$assessment['show_hints']) {
                unset($q['hint']);
            }

            unset($q['explanation']);
        }

        return $questions;
    }

    private function updateAttemptProgress($attemptId)
    {
        $sql = "UPDATE proc_assessment_attempts SET
                    questions_answered = (SELECT COUNT(*) FROM proc_attempt_answers WHERE attempt_id = :id AND answer_data IS NOT NULL),
                    last_activity_at = NOW()
                WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $attemptId]);
    }

    private function calculateFinalScores($attemptId)
    {
        // Get all answers
        $sql = "SELECT * FROM proc_attempt_answers WHERE attempt_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $attemptId]);
        $answers = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $totalPossible = 0;
        $rawScore = 0;
        $correct = 0;
        $incorrect = 0;
        $partial = 0;
        $skipped = 0;
        $pendingGrade = 0;

        foreach ($answers as $answer) {
            $totalPossible += $answer['points_possible'];

            if ($answer['answer_data'] === null) {
                $skipped++;
            } elseif ($answer['grading_status'] === 'pending') {
                $pendingGrade++;
            } else {
                $rawScore += $answer['points_earned'];
                
                if ($answer['is_correct']) {
                    $correct++;
                } elseif ($answer['is_partial']) {
                    $partial++;
                } else {
                    $incorrect++;
                }
            }
        }

        // Calculate time spent
        $sql = "SELECT TIMESTAMPDIFF(SECOND, started_at, NOW()) as elapsed FROM proc_assessment_attempts WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $attemptId]);
        $timeSpent = $stmt->fetch(\PDO::FETCH_ASSOC)['elapsed'];

        // Update attempt
        $percentage = $totalPossible > 0 ? ($rawScore / $totalPossible) * 100 : 0;

        $sql = "UPDATE proc_assessment_attempts SET
                    raw_score = :raw_score,
                    total_possible = :total_possible,
                    percentage_score = :percentage_score,
                    questions_correct = :correct,
                    questions_incorrect = :incorrect,
                    questions_partial = :partial,
                    questions_skipped = :skipped,
                    questions_pending_grade = :pending,
                    time_spent_seconds = :time_spent
                WHERE id = :id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $attemptId,
            'raw_score' => $rawScore,
            'total_possible' => $totalPossible,
            'percentage_score' => round($percentage, 2),
            'correct' => $correct,
            'incorrect' => $incorrect,
            'partial' => $partial,
            'skipped' => $skipped,
            'pending' => $pendingGrade,
            'time_spent' => $timeSpent
        ]);
    }

    private function shouldShowCorrectAnswers($assessment, $attempt)
    {
        switch ($assessment['show_correct_answers']) {
            case 'never':
                return false;
            case 'immediately':
                return true;
            case 'after_deadline':
                if ($assessment['available_until']) {
                    return new \DateTime() > new \DateTime($assessment['available_until']);
                }
                return true;
            case 'after_all_attempts':
                $attemptsUsed = $this->assessmentModel->getUserAttemptCount(
                    $assessment['id'], 
                    $this->userId
                );
                return $attemptsUsed >= $assessment['max_attempts'];
            default:
                return false;
        }
    }

    private function canAttempt($assessment)
    {
        $attemptsUsed = $assessment['attempts_used'] ?? 0;
        $maxAttempts = $assessment['max_attempts_override'] ?? $assessment['max_attempts'];

        if ($attemptsUsed >= $maxAttempts) {
            return ['can' => false, 'reason' => 'Maximum attempts reached'];
        }

        // Check availability dates
        $now = new \DateTime();
        $availFrom = $assessment['available_from_override'] ?? $assessment['available_from'];
        $availUntil = $assessment['available_until_override'] ?? $assessment['available_until'];

        if ($availFrom && $now < new \DateTime($availFrom)) {
            return ['can' => false, 'reason' => 'Assessment not yet available'];
        }

        if ($availUntil && $now > new \DateTime($availUntil)) {
            return ['can' => false, 'reason' => 'Assessment deadline has passed'];
        }

        return ['can' => true, 'reason' => null];
    }

    private function getAttemptsRemaining($assessment)
    {
        $maxAttempts = $assessment['max_attempts_override'] ?? $assessment['max_attempts'];
        $used = $assessment['attempts_used'] ?? 0;
        return max(0, $maxAttempts - $used);
    }

    private function logTimelineEvent($attemptId, $eventType, $data = null)
    {
        $sql = "INSERT INTO proc_attempt_timeline (attempt_id, event_type, event_data, ip_address, timestamp)
                VALUES (:attempt_id, :event_type, :event_data, :ip_address, NOW(3))";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'attempt_id' => $attemptId,
            'event_type' => $eventType,
            'event_data' => $data ? json_encode($data) : null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null
        ]);
    }

    private function issueCertificate($attempt, $assessment)
    {
        // Certificate issuance logic would go here
        // For now, return placeholder
        return ['issued' => false, 'certificate_id' => null];
    }

    private function shuffleArray($array)
    {
        $shuffled = $array;
        shuffle($shuffled);
        return $shuffled;
    }
}
