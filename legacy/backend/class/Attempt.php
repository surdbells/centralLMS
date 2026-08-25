<?php

/**
 * Attempt Manager
 * Handles assessment attempts, answers, auto-save, and results
 * For Multi-Tenant LMS - Learner Portal
 *
 * Following Skills.php pattern with MySQLi prepared statements
 */

class Attempt
{
    private mysqli $conn;
    private int $tenant_id;
    private int $user_id;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function setTenant(int $tenant_id): void
    {
        $this->tenant_id = $tenant_id;
    }

    public function setUser(int $user_id): void
    {
        $this->user_id = $user_id;
    }

    // ==========================================
    // LEARNER ASSESSMENTS
    // ==========================================

    /**
     * Get assessments available for the learner
     */
    public function getMyAssessments(array $filters = []): array
    {
        $status = $filters['status'] ?? null;
        $search = trim($filters['search'] ?? '');
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(50, max(1, (int) ($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        // Get assessments where user is assigned (directly or via group)
        $sql = "
            SELECT DISTINCT
                a.*,
                la.status as learner_status,
                la.total_attempts,
                la.remaining_attempts,
                la.best_score,
                la.last_score,
                la.due_date as learner_due_date,
                la.is_mandatory,
                la.first_attempted_at,
                la.last_attempted_at,
                la.completed_at,
                c.title as course_title,
                lt.title as training_title
            FROM proc_assessments a
            LEFT JOIN proc_learner_assessments la ON a.assessment_id = la.assessment_id AND la.user_id = ?
            LEFT JOIN courses c ON a.course_id = c.course_id
            LEFT JOIN live_trainings lt ON a.live_training_id = lt.training_id
            WHERE a.tenant_id = ? 
            AND a.status = 'published'
            AND (
                -- Check various assignment types
                EXISTS (SELECT 1 FROM proc_assessment_assignments aa WHERE aa.assessment_id = a.assessment_id AND aa.user_id = ?)
                OR EXISTS (SELECT 1 FROM proc_assessment_assignments aa WHERE aa.assessment_id = a.assessment_id AND aa.assign_all = 1)
                OR EXISTS (
                    SELECT 1 FROM proc_assessment_assignments aa 
                    INNER JOIN users u ON u.id = ?
                    WHERE aa.assessment_id = a.assessment_id 
                    AND (aa.department_id = u.department OR aa.team_id = u.team OR aa.branch_id = u.branch)
                )
            )
            AND (a.available_from IS NULL OR a.available_from <= NOW())
            AND (a.available_until IS NULL OR a.available_until >= NOW())
        ";

        $params = [$this->user_id, $this->tenant_id, $this->user_id, $this->user_id];
        $types = "iiii";

        if ($status && $status !== 'all') {
            if ($status === 'not_started') {
                $sql .= " AND (la.status IS NULL OR la.status = 'assigned')";
            } elseif ($status === 'in_progress') {
                $sql .= " AND la.status = 'in_progress'";
            } elseif ($status === 'completed') {
                $sql .= " AND la.status IN ('completed', 'passed', 'failed')";
            }
        }

        if (!empty($search)) {
            $sql .= " AND (a.title LIKE ? OR a.description LIKE ?)";
            $searchParam = "%{$search}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= "ss";
        }

        $sql .= " ORDER BY la.is_mandatory DESC, la.due_date ASC, a.title ASC";
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $assessments = [];
        while ($row = $result->fetch_assoc()) {
            $row['assessment_id'] = (int) $row['assessment_id'];
            $row['total_questions'] = (int) $row['total_questions'];
            $row['total_points'] = (float) $row['total_points'];
            $row['passing_score'] = (float) $row['passing_score'];
            $row['max_attempts'] = $row['max_attempts'] ? (int) $row['max_attempts'] : null;
            $row['total_attempts'] = (int) ($row['total_attempts'] ?? 0);
            $row['best_score'] = $row['best_score'] ? (float) $row['best_score'] : null;
            $row['last_score'] = $row['last_score'] ? (float) $row['last_score'] : null;
            $row['is_mandatory'] = (bool) ($row['is_mandatory'] ?? false);
            $row['can_attempt'] = $this->canAttempt($row);
            $assessments[] = $row;
        }
        $stmt->close();

        return $assessments;
    }

    /**
     * Check if user can start/continue an attempt
     */
    private function canAttempt(array $assessment): bool
    {
        // Check max attempts
        if ($assessment['max_attempts'] !== null) {
            if ($assessment['total_attempts'] >= $assessment['max_attempts']) {
                return false;
            }
        }

        // Check if already passed (if only one pass needed)
        if (isset($assessment['learner_status']) && $assessment['learner_status'] === 'passed') {
            return false;
        }

        return true;
    }

    /**
     * Get single assessment details for learner
     */
    public function getAssessmentForLearner(int $assessment_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                a.*,
                la.status as learner_status,
                la.total_attempts,
                la.best_score,
                la.last_score,
                la.due_date as learner_due_date,
                la.is_mandatory,
                la.last_attempt_id,
                c.title as course_title,
                lt.title as training_title
            FROM proc_assessments a
            LEFT JOIN proc_learner_assessments la ON a.assessment_id = la.assessment_id AND la.user_id = ?
            LEFT JOIN courses c ON a.course_id = c.course_id
            LEFT JOIN live_trainings lt ON a.live_training_id = lt.training_id
            WHERE a.assessment_id = ? AND a.tenant_id = ?
        ");
        $stmt->bind_param("iii", $this->user_id, $assessment_id, $this->tenant_id);
        $stmt->execute();
        $assessment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$assessment) return null;

        // Cast types
        $assessment['assessment_id'] = (int) $assessment['assessment_id'];
        $assessment['total_questions'] = (int) $assessment['total_questions'];
        $assessment['total_points'] = (float) $assessment['total_points'];
        $assessment['passing_score'] = (float) $assessment['passing_score'];
        $assessment['max_attempts'] = $assessment['max_attempts'] ? (int) $assessment['max_attempts'] : null;
        $assessment['total_attempts'] = (int) ($assessment['total_attempts'] ?? 0);
        $assessment['can_attempt'] = $this->canAttempt($assessment);

        // Check for in-progress attempt
        $assessment['in_progress_attempt'] = $this->getInProgressAttempt($assessment_id);

        return $assessment;
    }

    /**
     * Get in-progress attempt if exists
     */
    private function getInProgressAttempt(int $assessment_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT attempt_id, attempt_number, started_at, time_remaining_seconds, current_question_index
            FROM proc_attempts
            WHERE assessment_id = ? AND user_id = ? AND tenant_id = ? AND status = 'in_progress'
            ORDER BY started_at DESC LIMIT 1
        ");
        $stmt->bind_param("iii", $assessment_id, $this->user_id, $this->tenant_id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($attempt) {
            $attempt['attempt_id'] = (int) $attempt['attempt_id'];
            $attempt['attempt_number'] = (int) $attempt['attempt_number'];
            $attempt['time_remaining_seconds'] = $attempt['time_remaining_seconds'] ? (int) $attempt['time_remaining_seconds'] : null;
            $attempt['current_question_index'] = (int) $attempt['current_question_index'];
        }

        return $attempt;
    }

    // ==========================================
    // ATTEMPT MANAGEMENT
    // ==========================================

    /**
     * Start a new attempt
     */
    public function startAttempt(int $assessment_id, array $deviceInfo = []): array
    {
        // Get assessment
        $stmt = $this->conn->prepare("
            SELECT * FROM proc_assessments 
            WHERE assessment_id = ? AND tenant_id = ? AND status = 'published'
        ");
        $stmt->bind_param("ii", $assessment_id, $this->tenant_id);
        $stmt->execute();
        $assessment = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$assessment) {
            return ['success' => false, 'message' => 'Assessment not found or not available'];
        }

        // Check availability window
        $now = date('Y-m-d H:i:s');
        if ($assessment['available_from'] && $assessment['available_from'] > $now) {
            return ['success' => false, 'message' => 'Assessment is not yet available'];
        }
        if ($assessment['available_until'] && $assessment['available_until'] < $now) {
            return ['success' => false, 'message' => 'Assessment deadline has passed'];
        }

        // Check existing attempts
        $stmt = $this->conn->prepare("
            SELECT COUNT(*) as count FROM proc_attempts 
            WHERE assessment_id = ? AND user_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("iii", $assessment_id, $this->user_id, $this->tenant_id);
        $stmt->execute();
        $attemptCount = (int) $stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();

        // Check max attempts
        if ($assessment['max_attempts'] !== null && $attemptCount >= $assessment['max_attempts']) {
            return ['success' => false, 'message' => 'Maximum attempts reached'];
        }

        // Check for in-progress attempt
        $inProgress = $this->getInProgressAttempt($assessment_id);
        if ($inProgress) {
            return [
                'success' => true,
                'message' => 'Resuming existing attempt',
                'attempt_id' => $inProgress['attempt_id'],
                'is_resume' => true
            ];
        }

        // Check attempts gap
        if ($assessment['attempts_gap_hours'] > 0 && $attemptCount > 0) {
            $stmt = $this->conn->prepare("
                SELECT submitted_at FROM proc_attempts 
                WHERE assessment_id = ? AND user_id = ? AND tenant_id = ? AND status IN ('submitted', 'graded')
                ORDER BY submitted_at DESC LIMIT 1
            ");
            $stmt->bind_param("iii", $assessment_id, $this->user_id, $this->tenant_id);
            $stmt->execute();
            $lastAttempt = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($lastAttempt) {
                $gapSeconds = $assessment['attempts_gap_hours'] * 3600;
                $nextAllowed = strtotime($lastAttempt['submitted_at']) + $gapSeconds;
                if (time() < $nextAllowed) {
                    $waitTime = $nextAllowed - time();
                    $hours = floor($waitTime / 3600);
                    $minutes = floor(($waitTime % 3600) / 60);
                    return [
                        'success' => false,
                        'message' => "Please wait {$hours}h {$minutes}m before next attempt"
                    ];
                }
            }
        }

        // Get questions for this attempt
        $questions = $this->getQuestionsForAttempt($assessment);
        if (empty($questions)) {
            return ['success' => false, 'message' => 'No questions available for this assessment'];
        }

        // Calculate time limit in seconds
        $timeLimitSeconds = $assessment['time_limit_minutes'] ? $assessment['time_limit_minutes'] * 60 : null;

        // Prepare questions order (shuffle if needed)
        $questionIds = array_column($questions, 'question_id');
        if ($assessment['shuffle_questions']) {
            shuffle($questionIds);
        }

        // Device info
        $ipAddress = $deviceInfo['ip_address'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        $userAgent = $deviceInfo['user_agent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null;
        $browserName = $deviceInfo['browser_name'] ?? null;
        $browserVersion = $deviceInfo['browser_version'] ?? null;
        $osName = $deviceInfo['os_name'] ?? null;
        $deviceType = $deviceInfo['device_type'] ?? 'desktop';
        $screenWidth = !empty($deviceInfo['screen_width']) ? (int) $deviceInfo['screen_width'] : null;
        $screenHeight = !empty($deviceInfo['screen_height']) ? (int) $deviceInfo['screen_height'] : null;

        $attemptNumber = $attemptCount + 1;
        $totalQuestions = count($questions);
        $maxScore = array_sum(array_column($questions, 'points'));
        $questionsOrderJson = json_encode($questionIds);
        $requiresGrading = (int) $assessment['require_manual_grading'];
        $proctoringLevel = $assessment['proctoring_level'];
        $passingScore = (float) $assessment['passing_score'];

        $stmt = $this->conn->prepare("
            INSERT INTO proc_attempts (
                tenant_id, assessment_id, user_id, attempt_number, status,
                started_at, time_limit_seconds, time_remaining_seconds, last_activity_at,
                total_questions, max_score, requires_grading, proctoring_level, passing_score,
                questions_order, ip_address, user_agent, browser_name, browser_version,
                os_name, device_type, screen_width, screen_height, created_at
            ) VALUES (
                ?, ?, ?, ?, 'in_progress',
                NOW(), ?, ?, NOW(),
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?, ?, NOW()
            )
        ");

        $stmt->bind_param(
            "iiiiiiiidissssssssii",
            $this->tenant_id, $assessment_id, $this->user_id, $attemptNumber,
            $timeLimitSeconds, $timeLimitSeconds,
            $totalQuestions, $maxScore, $requiresGrading, $proctoringLevel, $passingScore,
            $questionsOrderJson, $ipAddress, $userAgent, $browserName, $browserVersion,
            $osName, $deviceType, $screenWidth, $screenHeight
        );

        if ($stmt->execute()) {
            $attemptId = $stmt->insert_id;
            $stmt->close();

            // Create answer records for each question
            $this->initializeAnswerRecords($attemptId, $questions);

            // Log timeline event
            $this->logTimelineEvent($attemptId, 'attempt_started');

            return [
                'success' => true,
                'message' => 'Attempt started successfully',
                'attempt_id' => $attemptId,
                'is_resume' => false
            ];
        }

        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to start attempt: ' . $error];
    }

    /**
     * Get questions for an attempt
     */
    private function getQuestionsForAttempt(array $assessment): array
    {
        if ($assessment['question_selection'] === 'manual') {
            // Get manually assigned questions
            $stmt = $this->conn->prepare("
                SELECT 
                    q.question_id,
                    q.question_type,
                    q.question_text,
                    q.question_html,
                    q.hint,
                    q.featured_image,
                    q.audio_url,
                    q.video_url,
                    COALESCE(aq.points_override, q.points) as points,
                    q.partial_credit,
                    q.case_sensitive,
                    q.allow_regex,
                    q.numeric_tolerance,
                    q.max_words,
                    q.min_words,
                    q.max_file_size_mb,
                    q.allowed_file_types,
                    aq.time_limit_override,
                    aq.required,
                    aq.section_id,
                    s.title as section_title
                FROM proc_assessment_questions aq
                JOIN proc_questions q ON aq.question_id = q.question_id
                LEFT JOIN proc_assessment_sections s ON aq.section_id = s.section_id
                WHERE aq.assessment_id = ? AND q.status = 'active'
                ORDER BY aq.sort_order ASC
            ");
            $stmt->bind_param("i", $assessment['assessment_id']);
            $stmt->execute();
            $result = $stmt->get_result();

            $questions = [];
            while ($row = $result->fetch_assoc()) {
                $row['question_id'] = (int) $row['question_id'];
                $row['points'] = (float) $row['points'];
                $row['partial_credit'] = (bool) $row['partial_credit'];
                $row['case_sensitive'] = (bool) $row['case_sensitive'];
                $row['required'] = (bool) $row['required'];
                $questions[] = $row;
            }
            $stmt->close();

            return $questions;
        }

        // TODO: Implement random selection from rules
        return [];
    }

    /**
     * Initialize answer records for attempt
     */
    private function initializeAnswerRecords(int $attemptId, array $questions): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO proc_attempt_answers 
            (attempt_id, question_id, tenant_id, question_type, question_text_snapshot, max_points, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");

        foreach ($questions as $q) {
            $stmt->bind_param(
                "iiissd",
                $attemptId, $q['question_id'], $this->tenant_id,
                $q['question_type'], $q['question_text'], $q['points']
            );
            $stmt->execute();
        }
        $stmt->close();
    }

    /**
     * Get attempt data for player
     */
    public function getAttemptForPlayer(int $attemptId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                att.*,
                a.title as assessment_title,
                a.instructions,
                a.questions_per_page,
                a.allow_backward_navigation,
                a.show_question_numbers,
                a.show_progress_bar,
                a.show_timer,
                a.allow_mark_for_review,
                a.shuffle_options
            FROM proc_attempts att
            JOIN proc_assessments a ON att.assessment_id = a.assessment_id
            WHERE att.attempt_id = ? AND att.user_id = ? AND att.tenant_id = ?
        ");
        $stmt->bind_param("iii", $attemptId, $this->user_id, $this->tenant_id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$attempt) return null;

        // Check if attempt is still active
        if (!in_array($attempt['status'], ['in_progress', 'not_started'])) {
            return ['error' => 'Attempt is no longer active', 'status' => $attempt['status']];
        }

        // Cast types
        $attempt['attempt_id'] = (int) $attempt['attempt_id'];
        $attempt['total_questions'] = (int) $attempt['total_questions'];
        $attempt['answered_questions'] = (int) $attempt['answered_questions'];
        $attempt['time_remaining_seconds'] = $attempt['time_remaining_seconds'] !== null ? (int) $attempt['time_remaining_seconds'] : null;
        $attempt['current_question_index'] = (int) $attempt['current_question_index'];
        $attempt['questions_order'] = json_decode($attempt['questions_order'], true) ?? [];
        $attempt['marked_for_review'] = json_decode($attempt['marked_for_review'], true) ?? [];
        $attempt['allow_backward_navigation'] = (bool) $attempt['allow_backward_navigation'];
        $attempt['show_question_numbers'] = (bool) $attempt['show_question_numbers'];
        $attempt['show_progress_bar'] = (bool) $attempt['show_progress_bar'];
        $attempt['show_timer'] = (bool) $attempt['show_timer'];
        $attempt['allow_mark_for_review'] = (bool) $attempt['allow_mark_for_review'];
        $attempt['shuffle_options'] = (bool) $attempt['shuffle_options'];

        // Calculate actual time remaining
        if ($attempt['time_limit_seconds'] !== null && $attempt['started_at']) {
            $elapsed = time() - strtotime($attempt['started_at']);
            $attempt['time_remaining_seconds'] = max(0, $attempt['time_limit_seconds'] - $elapsed);

            // Auto-submit if time expired
            if ($attempt['time_remaining_seconds'] <= 0) {
                $this->submitAttempt($attemptId, true);
                return ['error' => 'Time expired', 'status' => 'auto_submitted'];
            }
        }

        // Get questions with current answers
        $attempt['questions'] = $this->getQuestionsWithAnswers($attemptId, $attempt['shuffle_options']);

        return $attempt;
    }

    /**
     * Get questions with answers for player
     */
    private function getQuestionsWithAnswers(int $attemptId, bool $shuffleOptions = true): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                q.question_id,
                q.question_type,
                q.question_text,
                q.question_html,
                q.hint,
                q.featured_image,
                q.audio_url,
                q.video_url,
                aa.max_points as points,
                aa.answer_data,
                aa.is_answered,
                aa.marked_for_review,
                aa.time_spent_seconds,
                aa.first_viewed_at
            FROM proc_attempt_answers aa
            JOIN proc_questions q ON aa.question_id = q.question_id
            WHERE aa.attempt_id = ?
        ");
        $stmt->bind_param("i", $attemptId);
        $stmt->execute();
        $result = $stmt->get_result();

        $questions = [];
        while ($row = $result->fetch_assoc()) {
            $row['question_id'] = (int) $row['question_id'];
            $row['points'] = (float) $row['points'];
            $row['is_answered'] = (bool) $row['is_answered'];
            $row['marked_for_review'] = (bool) $row['marked_for_review'];
            $row['answer_data'] = $row['answer_data'] ? json_decode($row['answer_data'], true) : null;

            // Get options for relevant question types
            if (in_array($row['question_type'], ['multiple_choice_single', 'multiple_choice_multiple', 'true_false', 'matching', 'ordering'])) {
                $row['options'] = $this->getQuestionOptions($row['question_id'], $shuffleOptions);
            }

            $questions[$row['question_id']] = $row;
        }
        $stmt->close();

        return $questions;
    }

    /**
     * Get question options (without correct answers for learner)
     */
    private function getQuestionOptions(int $questionId, bool $shuffle = true): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                option_id, option_text, option_html, option_image,
                match_text, match_image, sort_order, feedback
            FROM proc_question_options
            WHERE question_id = ?
            ORDER BY sort_order ASC
        ");
        $stmt->bind_param("i", $questionId);
        $stmt->execute();
        $result = $stmt->get_result();

        $options = [];
        while ($row = $result->fetch_assoc()) {
            $row['option_id'] = (int) $row['option_id'];
            $options[] = $row;
        }
        $stmt->close();

        if ($shuffle) {
            shuffle($options);
        }

        return $options;
    }

    // ==========================================
    // ANSWER MANAGEMENT
    // ==========================================

    /**
     * Save an answer
     */
    public function saveAnswer(int $attemptId, int $questionId, array $answerData): array
    {
        // Verify attempt belongs to user and is in progress
        $stmt = $this->conn->prepare("
            SELECT status FROM proc_attempts 
            WHERE attempt_id = ? AND user_id = ? AND tenant_id = ? AND status = 'in_progress'
        ");
        $stmt->bind_param("iii", $attemptId, $this->user_id, $this->tenant_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $stmt->close();
            return ['success' => false, 'message' => 'Attempt not found or not active'];
        }
        $stmt->close();

        $answerJson = json_encode($answerData);
        $isAnswered = !empty($answerData) ? 1 : 0;

        $stmt = $this->conn->prepare("
            UPDATE proc_attempt_answers SET
                answer_data = ?,
                is_answered = ?,
                first_answered_at = COALESCE(first_answered_at, NOW()),
                last_modified_at = NOW(),
                answer_changes = answer_changes + 1
            WHERE attempt_id = ? AND question_id = ?
        ");
        $stmt->bind_param("siii", $answerJson, $isAnswered, $attemptId, $questionId);

        if ($stmt->execute()) {
            $stmt->close();

            // Update attempt last activity
            $this->updateLastActivity($attemptId);

            // Log timeline
            $this->logTimelineEvent($attemptId, 'question_answered', $questionId);

            return ['success' => true, 'message' => 'Answer saved'];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Failed to save answer'];
    }

    /**
     * Mark question for review
     */
    public function markForReview(int $attemptId, int $questionId, bool $marked): array
    {
        $stmt = $this->conn->prepare("
            UPDATE proc_attempt_answers SET marked_for_review = ?
            WHERE attempt_id = ? AND question_id = ?
        ");
        $markedInt = $marked ? 1 : 0;
        $stmt->bind_param("iii", $markedInt, $attemptId, $questionId);

        if ($stmt->execute()) {
            $stmt->close();

            // Update attempt marked_for_review array
            $this->updateMarkedForReview($attemptId);

            // Log timeline
            $eventType = $marked ? 'question_marked' : 'question_unmarked';
            $this->logTimelineEvent($attemptId, $eventType, $questionId);

            return ['success' => true, 'message' => $marked ? 'Marked for review' : 'Unmarked'];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Failed to update'];
    }

    /**
     * Update marked_for_review array on attempt
     */
    private function updateMarkedForReview(int $attemptId): void
    {
        $stmt = $this->conn->prepare("
            SELECT question_id FROM proc_attempt_answers 
            WHERE attempt_id = ? AND marked_for_review = 1
        ");
        $stmt->bind_param("i", $attemptId);
        $stmt->execute();
        $result = $stmt->get_result();

        $marked = [];
        while ($row = $result->fetch_assoc()) {
            $marked[] = (int) $row['question_id'];
        }
        $stmt->close();

        $markedJson = json_encode($marked);
        $stmt = $this->conn->prepare("UPDATE proc_attempts SET marked_for_review = ? WHERE attempt_id = ?");
        $stmt->bind_param("si", $markedJson, $attemptId);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update navigation position
     */
    public function updateNavigation(int $attemptId, int $questionIndex): array
    {
        $stmt = $this->conn->prepare("
            UPDATE proc_attempts SET current_question_index = ?, last_activity_at = NOW()
            WHERE attempt_id = ? AND user_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("iiii", $questionIndex, $attemptId, $this->user_id, $this->tenant_id);

        if ($stmt->execute()) {
            $stmt->close();
            return ['success' => true];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Failed to update navigation'];
    }

    /**
     * Update time remaining
     */
    public function updateTimeRemaining(int $attemptId, int $timeRemaining): array
    {
        $stmt = $this->conn->prepare("
            UPDATE proc_attempts SET time_remaining_seconds = ?, last_activity_at = NOW()
            WHERE attempt_id = ? AND user_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("iiii", $timeRemaining, $attemptId, $this->user_id, $this->tenant_id);
        $stmt->execute();
        $stmt->close();

        return ['success' => true];
    }

    /**
     * Update last activity timestamp
     */
    private function updateLastActivity(int $attemptId): void
    {
        $stmt = $this->conn->prepare("
            UPDATE proc_attempts SET last_activity_at = NOW() WHERE attempt_id = ?
        ");
        $stmt->bind_param("i", $attemptId);
        $stmt->execute();
        $stmt->close();
    }

    // ==========================================
    // AUTO-SAVE
    // ==========================================

    /**
     * Auto-save attempt state
     */
    public function autoSave(int $attemptId, array $draftData): array
    {
        $draftJson = json_encode($draftData);

        // Delete old drafts (keep only latest)
        $stmt = $this->conn->prepare("DELETE FROM proc_attempt_drafts WHERE attempt_id = ?");
        $stmt->bind_param("i", $attemptId);
        $stmt->execute();
        $stmt->close();

        // Insert new draft
        $stmt = $this->conn->prepare("
            INSERT INTO proc_attempt_drafts (attempt_id, tenant_id, draft_data, saved_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->bind_param("iis", $attemptId, $this->tenant_id, $draftJson);

        if ($stmt->execute()) {
            $stmt->close();
            $this->logTimelineEvent($attemptId, 'auto_save');
            return ['success' => true, 'saved_at' => date('Y-m-d H:i:s')];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Auto-save failed'];
    }

    /**
     * Recover draft data
     */
    public function recoverDraft(int $attemptId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT draft_data, saved_at FROM proc_attempt_drafts
            WHERE attempt_id = ? AND tenant_id = ?
            ORDER BY saved_at DESC LIMIT 1
        ");
        $stmt->bind_param("ii", $attemptId, $this->tenant_id);
        $stmt->execute();
        $draft = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($draft) {
            return [
                'data' => json_decode($draft['draft_data'], true),
                'saved_at' => $draft['saved_at']
            ];
        }

        return null;
    }

    // ==========================================
    // SUBMIT & GRADE
    // ==========================================

    /**
     * Submit attempt
     */
    public function submitAttempt(int $attemptId, bool $autoSubmit = false): array
    {
        // Get attempt
        $stmt = $this->conn->prepare("
            SELECT att.*, a.passing_score, a.passing_type, a.auto_publish_results
            FROM proc_attempts att
            JOIN proc_assessments a ON att.assessment_id = a.assessment_id
            WHERE att.attempt_id = ? AND att.user_id = ? AND att.tenant_id = ?
        ");
        $stmt->bind_param("iii", $attemptId, $this->user_id, $this->tenant_id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$attempt) {
            return ['success' => false, 'message' => 'Attempt not found'];
        }

        if (!in_array($attempt['status'], ['in_progress', 'not_started'])) {
            return ['success' => false, 'message' => 'Attempt already submitted'];
        }

        // Calculate time spent
        $timeSpent = 0;
        if ($attempt['started_at']) {
            $timeSpent = time() - strtotime($attempt['started_at']);
        }

        // Auto-grade questions
        $gradeResult = $this->gradeAttempt($attemptId);

        // Determine pass/fail
        $passingScore = (float) $attempt['passing_score'];
        $percentage = $gradeResult['percentage'];
        $passed = $percentage >= $passingScore ? 1 : 0;

        // Calculate letter grade
        $letterGrade = $this->calculateLetterGrade($percentage);

        $status = $autoSubmit ? 'auto_submitted' : 'submitted';
        if (!$attempt['requires_grading'] && $attempt['auto_publish_results']) {
            $status = 'graded';
        }

        $stmt = $this->conn->prepare("
            UPDATE proc_attempts SET
                status = ?,
                submitted_at = NOW(),
                time_spent_seconds = ?,
                answered_questions = ?,
                correct_answers = ?,
                partial_answers = ?,
                wrong_answers = ?,
                skipped_questions = ?,
                raw_score = ?,
                percentage_score = ?,
                letter_grade = ?,
                passed = ?,
                graded_at = IF(? = 'graded', NOW(), NULL),
                pending_grading = ?
            WHERE attempt_id = ?
        ");

        $stmt->bind_param(
            "siiiiiiddsisis",
            $status, $timeSpent,
            $gradeResult['answered'], $gradeResult['correct'], $gradeResult['partial'],
            $gradeResult['wrong'], $gradeResult['skipped'],
            $gradeResult['score'], $percentage, $letterGrade, $passed,
            $status, $gradeResult['pending_grading'], $attemptId
        );

        if ($stmt->execute()) {
            $stmt->close();

            // Log timeline
            $eventType = $autoSubmit ? 'attempt_auto_submitted' : 'attempt_submitted';
            $this->logTimelineEvent($attemptId, $eventType);

            // Clean up drafts
            $stmt = $this->conn->prepare("DELETE FROM proc_attempt_drafts WHERE attempt_id = ?");
            $stmt->bind_param("i", $attemptId);
            $stmt->execute();
            $stmt->close();

            return [
                'success' => true,
                'message' => $autoSubmit ? 'Attempt auto-submitted due to time expiry' : 'Attempt submitted successfully',
                'result' => [
                    'score' => $gradeResult['score'],
                    'max_score' => $gradeResult['max_score'],
                    'percentage' => $percentage,
                    'passed' => (bool) $passed,
                    'letter_grade' => $letterGrade,
                    'correct' => $gradeResult['correct'],
                    'wrong' => $gradeResult['wrong'],
                    'partial' => $gradeResult['partial'],
                    'skipped' => $gradeResult['skipped'],
                    'requires_grading' => $gradeResult['pending_grading'] > 0
                ]
            ];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Failed to submit attempt'];
    }

    /**
     * Grade attempt (auto-grade)
     */
    private function gradeAttempt(int $attemptId): array
    {
        // Get all answers with question details
        $stmt = $this->conn->prepare("
            SELECT 
                aa.*,
                q.question_type,
                q.case_sensitive,
                q.allow_regex,
                q.numeric_tolerance,
                q.partial_credit
            FROM proc_attempt_answers aa
            JOIN proc_questions q ON aa.question_id = q.question_id
            WHERE aa.attempt_id = ?
        ");
        $stmt->bind_param("i", $attemptId);
        $stmt->execute();
        $result = $stmt->get_result();

        $totalScore = 0;
        $maxScore = 0;
        $correct = 0;
        $wrong = 0;
        $partial = 0;
        $skipped = 0;
        $answered = 0;
        $pendingGrading = 0;

        while ($answer = $result->fetch_assoc()) {
            $maxScore += (float) $answer['max_points'];
            $answerData = $answer['answer_data'] ? json_decode($answer['answer_data'], true) : null;

            if (!$answer['is_answered'] || empty($answerData)) {
                $skipped++;
                continue;
            }

            $answered++;

            // Check if auto-gradable
            if (in_array($answer['question_type'], ['long_answer', 'file_upload'])) {
                $pendingGrading++;
                continue;
            }

            // Auto-grade
            $gradeResult = $this->autoGradeQuestion(
                $answer['question_id'],
                $answer['question_type'],
                $answerData,
                (float) $answer['max_points'],
                [
                    'case_sensitive' => (bool) $answer['case_sensitive'],
                    'allow_regex' => (bool) $answer['allow_regex'],
                    'numeric_tolerance' => (float) $answer['numeric_tolerance'],
                    'partial_credit' => (bool) $answer['partial_credit']
                ]
            );

            $totalScore += $gradeResult['points'];

            // Update answer record
            $updateStmt = $this->conn->prepare("
                UPDATE proc_attempt_answers SET
                    is_correct = ?, is_partial = ?, points_earned = ?, 
                    auto_graded = 1, graded_at = NOW()
                WHERE answer_id = ?
            ");
            $updateStmt->bind_param("iidi",
                $gradeResult['is_correct'], $gradeResult['is_partial'],
                $gradeResult['points'], $answer['answer_id']
            );
            $updateStmt->execute();
            $updateStmt->close();

            if ($gradeResult['is_correct']) {
                $correct++;
            } elseif ($gradeResult['is_partial']) {
                $partial++;
            } else {
                $wrong++;
            }
        }
        $stmt->close();

        $percentage = $maxScore > 0 ? round(($totalScore / $maxScore) * 100, 2) : 0;

        return [
            'score' => $totalScore,
            'max_score' => $maxScore,
            'percentage' => $percentage,
            'correct' => $correct,
            'wrong' => $wrong,
            'partial' => $partial,
            'skipped' => $skipped,
            'answered' => $answered,
            'pending_grading' => $pendingGrading
        ];
    }

    /**
     * Auto-grade a single question
     */
    private function autoGradeQuestion(int $questionId, string $type, array $answer, float $maxPoints, array $options): array
    {
        $isCorrect = 0;
        $isPartial = 0;
        $points = 0;

        // Get correct answers from options
        $stmt = $this->conn->prepare("SELECT * FROM proc_question_options WHERE question_id = ?");
        $stmt->bind_param("i", $questionId);
        $stmt->execute();
        $optionsResult = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        switch ($type) {
            case 'multiple_choice_single':
            case 'true_false':
                $selectedId = $answer['selected_option_id'] ?? null;
                foreach ($optionsResult as $opt) {
                    if ((int) $opt['option_id'] === (int) $selectedId && $opt['is_correct']) {
                        $isCorrect = 1;
                        $points = $maxPoints;
                        break;
                    }
                }
                break;

            case 'multiple_choice_multiple':
                $selectedIds = $answer['selected_option_ids'] ?? [];
                $correctIds = array_filter($optionsResult, fn($o) => $o['is_correct']);
                $correctIdList = array_column($correctIds, 'option_id');

                $correctSelected = count(array_intersect($selectedIds, $correctIdList));
                $wrongSelected = count(array_diff($selectedIds, $correctIdList));
                $totalCorrect = count($correctIdList);

                if ($correctSelected === $totalCorrect && $wrongSelected === 0) {
                    $isCorrect = 1;
                    $points = $maxPoints;
                } elseif ($options['partial_credit'] && $correctSelected > 0) {
                    $isPartial = 1;
                    $points = ($correctSelected / $totalCorrect) * $maxPoints;
                    $points = max(0, $points - ($wrongSelected * ($maxPoints / $totalCorrect)));
                }
                break;

            case 'fill_in_blanks':
            case 'short_answer':
                $userAnswer = trim($answer['text'] ?? $answer['blanks'][0] ?? '');
                foreach ($optionsResult as $opt) {
                    $acceptableAnswers = json_decode($opt['acceptable_answers'], true) ?? [$opt['option_text']];
                    foreach ($acceptableAnswers as $correct) {
                        $correct = trim($correct);
                        $matches = $options['case_sensitive']
                            ? ($userAnswer === $correct)
                            : (strtolower($userAnswer) === strtolower($correct));

                        if (!$matches && $options['allow_regex']) {
                            $matches = @preg_match('/' . $correct . '/i', $userAnswer);
                        }

                        if ($matches) {
                            $isCorrect = 1;
                            $points = $maxPoints;
                            break 2;
                        }
                    }
                }
                break;

            case 'matching':
                $matches = $answer['matches'] ?? [];
                $correctMatches = 0;
                foreach ($optionsResult as $opt) {
                    $optionId = (string) $opt['option_id'];
                    if (isset($matches[$optionId]) && $matches[$optionId] === $opt['match_text']) {
                        $correctMatches++;
                    }
                }
                if ($correctMatches === count($optionsResult)) {
                    $isCorrect = 1;
                    $points = $maxPoints;
                } elseif ($options['partial_credit'] && $correctMatches > 0) {
                    $isPartial = 1;
                    $points = ($correctMatches / count($optionsResult)) * $maxPoints;
                }
                break;

            case 'ordering':
                $userOrder = $answer['order'] ?? [];
                $correctOrder = array_column($optionsResult, 'correct_position', 'option_id');
                ksort($correctOrder);
                $expectedOrder = array_keys($correctOrder);

                if ($userOrder === $expectedOrder) {
                    $isCorrect = 1;
                    $points = $maxPoints;
                } elseif ($options['partial_credit']) {
                    $correctPositions = 0;
                    foreach ($userOrder as $idx => $optId) {
                        if (isset($correctOrder[$optId]) && $correctOrder[$optId] === $idx + 1) {
                            $correctPositions++;
                        }
                    }
                    if ($correctPositions > 0) {
                        $isPartial = 1;
                        $points = ($correctPositions / count($expectedOrder)) * $maxPoints;
                    }
                }
                break;

            case 'numeric':
                $userValue = (float) ($answer['value'] ?? 0);
                foreach ($optionsResult as $opt) {
                    $correctValue = (float) $opt['option_text'];
                    $tolerance = $options['numeric_tolerance'];
                    if (abs($userValue - $correctValue) <= $tolerance) {
                        $isCorrect = 1;
                        $points = $maxPoints;
                        break;
                    }
                }
                break;

            case 'hotspot':
                $clicked = $answer['clicked_coords'] ?? null;
                if ($clicked) {
                    foreach ($optionsResult as $opt) {
                        $coords = json_decode($opt['hotspot_coords'], true);
                        if ($this->isPointInHotspot($clicked, $coords)) {
                            $isCorrect = 1;
                            $points = $maxPoints;
                            break;
                        }
                    }
                }
                break;
        }

        return [
            'is_correct' => $isCorrect,
            'is_partial' => $isPartial,
            'points' => round($points, 2)
        ];
    }

    /**
     * Check if point is within hotspot
     */
    private function isPointInHotspot(array $point, array $hotspot): bool
    {
        $x = $point['x'];
        $y = $point['y'];

        if ($hotspot['shape'] === 'rect') {
            return $x >= $hotspot['x'] && $x <= $hotspot['x'] + $hotspot['width']
                && $y >= $hotspot['y'] && $y <= $hotspot['y'] + $hotspot['height'];
        }

        if ($hotspot['shape'] === 'circle') {
            $centerX = $hotspot['x'] + $hotspot['width'] / 2;
            $centerY = $hotspot['y'] + $hotspot['height'] / 2;
            $radius = $hotspot['width'] / 2;
            return pow($x - $centerX, 2) + pow($y - $centerY, 2) <= pow($radius, 2);
        }

        return false;
    }

    /**
     * Calculate letter grade
     */
    private function calculateLetterGrade(float $percentage): string
    {
        if ($percentage >= 97) return 'A+';
        if ($percentage >= 93) return 'A';
        if ($percentage >= 90) return 'A-';
        if ($percentage >= 87) return 'B+';
        if ($percentage >= 83) return 'B';
        if ($percentage >= 80) return 'B-';
        if ($percentage >= 77) return 'C+';
        if ($percentage >= 73) return 'C';
        if ($percentage >= 70) return 'C-';
        if ($percentage >= 67) return 'D+';
        if ($percentage >= 63) return 'D';
        if ($percentage >= 60) return 'D-';
        return 'F';
    }

    // ==========================================
    // RESULTS
    // ==========================================

    /**
     * Get attempt results
     */
    public function getAttemptResults(int $attemptId): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                att.*,
                a.title as assessment_title,
                a.show_correct_answers,
                a.show_explanations,
                a.show_score_breakdown,
                a.available_until
            FROM proc_attempts att
            JOIN proc_assessments a ON att.assessment_id = a.assessment_id
            WHERE att.attempt_id = ? AND att.user_id = ? AND att.tenant_id = ?
        ");
        $stmt->bind_param("iii", $attemptId, $this->user_id, $this->tenant_id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$attempt || !in_array($attempt['status'], ['submitted', 'auto_submitted', 'graded'])) {
            return null;
        }

        // Determine if we can show correct answers
        $showAnswers = $this->canShowCorrectAnswers($attempt);

        $result = [
            'attempt_id' => (int) $attempt['attempt_id'],
            'assessment_title' => $attempt['assessment_title'],
            'status' => $attempt['status'],
            'started_at' => $attempt['started_at'],
            'submitted_at' => $attempt['submitted_at'],
            'time_spent_seconds' => (int) $attempt['time_spent_seconds'],
            'total_questions' => (int) $attempt['total_questions'],
            'answered_questions' => (int) $attempt['answered_questions'],
            'correct_answers' => (int) $attempt['correct_answers'],
            'wrong_answers' => (int) $attempt['wrong_answers'],
            'partial_answers' => (int) $attempt['partial_answers'],
            'skipped_questions' => (int) $attempt['skipped_questions'],
            'raw_score' => (float) $attempt['raw_score'],
            'max_score' => (float) $attempt['max_score'],
            'percentage_score' => (float) $attempt['percentage_score'],
            'letter_grade' => $attempt['letter_grade'],
            'passed' => (bool) $attempt['passed'],
            'passing_score' => (float) $attempt['passing_score'],
            'show_answers' => $showAnswers,
            'show_explanations' => (bool) $attempt['show_explanations'],
            'show_score_breakdown' => (bool) $attempt['show_score_breakdown'],
            'requires_grading' => (bool) $attempt['requires_grading'],
            'pending_grading' => (int) $attempt['pending_grading']
        ];

        // Get detailed breakdown if allowed
        if ($attempt['show_score_breakdown']) {
            $result['answers'] = $this->getAnswersForReview($attemptId, $showAnswers);
        }

        return $result;
    }

    /**
     * Check if correct answers can be shown
     */
    private function canShowCorrectAnswers(array $attempt): bool
    {
        switch ($attempt['show_correct_answers']) {
            case 'immediately':
                return true;
            case 'after_deadline':
                return $attempt['available_until'] && strtotime($attempt['available_until']) < time();
            case 'after_all_attempts':
                // Check if user has exhausted all attempts
                $stmt = $this->conn->prepare("
                    SELECT max_attempts FROM proc_assessments WHERE assessment_id = ?
                ");
                $stmt->bind_param("i", $attempt['assessment_id']);
                $stmt->execute();
                $assessment = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($assessment['max_attempts'] === null) return false;

                $stmt = $this->conn->prepare("
                    SELECT COUNT(*) as count FROM proc_attempts 
                    WHERE assessment_id = ? AND user_id = ? AND status IN ('submitted', 'graded')
                ");
                $stmt->bind_param("ii", $attempt['assessment_id'], $this->user_id);
                $stmt->execute();
                $attemptCount = (int) $stmt->get_result()->fetch_assoc()['count'];
                $stmt->close();

                return $attemptCount >= $assessment['max_attempts'];
            default:
                return false;
        }
    }

    /**
     * Get answers for review
     */
    private function getAnswersForReview(int $attemptId, bool $showCorrect): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                aa.*,
                q.question_text,
                q.question_html,
                q.explanation
            FROM proc_attempt_answers aa
            JOIN proc_questions q ON aa.question_id = q.question_id
            WHERE aa.attempt_id = ?
        ");
        $stmt->bind_param("i", $attemptId);
        $stmt->execute();
        $result = $stmt->get_result();

        $answers = [];
        while ($row = $result->fetch_assoc()) {
            $answer = [
                'question_id' => (int) $row['question_id'],
                'question_type' => $row['question_type'],
                'question_text' => $row['question_text'],
                'answer_data' => $row['answer_data'] ? json_decode($row['answer_data'], true) : null,
                'is_correct' => $row['is_correct'] !== null ? (bool) $row['is_correct'] : null,
                'is_partial' => (bool) $row['is_partial'],
                'points_earned' => (float) $row['points_earned'],
                'max_points' => (float) $row['max_points'],
                'grader_feedback' => $row['grader_feedback']
            ];

            if ($showCorrect) {
                $answer['explanation'] = $row['explanation'];
                $answer['correct_answer'] = $this->getCorrectAnswer($row['question_id']);
            }

            $answers[] = $answer;
        }
        $stmt->close();

        return $answers;
    }

    /**
     * Get correct answer for a question
     */
    private function getCorrectAnswer(int $questionId): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM proc_question_options 
            WHERE question_id = ? AND is_correct = 1
        ");
        $stmt->bind_param("i", $questionId);
        $stmt->execute();
        $result = $stmt->get_result();

        $correct = [];
        while ($row = $result->fetch_assoc()) {
            $correct[] = [
                'option_id' => (int) $row['option_id'],
                'option_text' => $row['option_text'],
                'match_text' => $row['match_text'],
                'correct_position' => $row['correct_position']
            ];
        }
        $stmt->close();

        return $correct;
    }

    /**
     * Get attempt history for an assessment
     */
    public function getAttemptHistory(int $assessmentId): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                attempt_id, attempt_number, status, started_at, submitted_at,
                time_spent_seconds, percentage_score, letter_grade, passed,
                correct_answers, wrong_answers, skipped_questions
            FROM proc_attempts
            WHERE assessment_id = ? AND user_id = ? AND tenant_id = ?
            ORDER BY attempt_number DESC
        ");
        $stmt->bind_param("iii", $assessmentId, $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $history = [];
        while ($row = $result->fetch_assoc()) {
            $row['attempt_id'] = (int) $row['attempt_id'];
            $row['attempt_number'] = (int) $row['attempt_number'];
            $row['percentage_score'] = $row['percentage_score'] ? (float) $row['percentage_score'] : null;
            $row['passed'] = $row['passed'] !== null ? (bool) $row['passed'] : null;
            $history[] = $row;
        }
        $stmt->close();

        return $history;
    }

    // ==========================================
    // TIMELINE
    // ==========================================

    /**
     * Log timeline event
     */
    public function logTimelineEvent(int $attemptId, string $eventType, ?int $questionId = null, ?array $eventData = null): void
    {
        $questionIndex = null;
        $eventDataJson = $eventData ? json_encode($eventData) : null;

        $stmt = $this->conn->prepare("
            INSERT INTO proc_attempt_timeline 
            (attempt_id, tenant_id, event_type, question_id, question_index, event_data, timestamp)
            VALUES (?, ?, ?, ?, ?, ?, NOW(3))
        ");
        $stmt->bind_param("iissis", $attemptId, $this->tenant_id, $eventType, $questionId, $questionIndex, $eventDataJson);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get timeline for attempt (admin use)
     */
    public function getAttemptTimeline(int $attemptId): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM proc_attempt_timeline
            WHERE attempt_id = ?
            ORDER BY timestamp ASC
        ");
        $stmt->bind_param("i", $attemptId);
        $stmt->execute();
        $result = $stmt->get_result();

        $timeline = [];
        while ($row = $result->fetch_assoc()) {
            $row['timeline_id'] = (int) $row['timeline_id'];
            $row['question_id'] = $row['question_id'] ? (int) $row['question_id'] : null;
            $row['event_data'] = $row['event_data'] ? json_decode($row['event_data'], true) : null;
            $timeline[] = $row;
        }
        $stmt->close();

        return $timeline;
    }
}