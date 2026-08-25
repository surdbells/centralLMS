<?php
/**
 * Assessment Model
 * Handles all assessment-related database operations
 */

namespace App\Models\ProctoredAssessment;

class Assessment
{
    private $db;
    
    public function __construct($db)
    {
        $this->db = $db;
    }

    // ==================== CRUD OPERATIONS ====================

    public function getAll($tenantId, $filters = [], $page = 1, $limit = 20)
    {
        $offset = ($page - 1) * $limit;
        $where = ["a.tenant_id = :tenant_id"];
        $params = ['tenant_id' => $tenantId];

        if (!empty($filters['status'])) {
            $where[] = "a.status = :status";
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['course_id'])) {
            $where[] = "a.course_id = :course_id";
            $params['course_id'] = $filters['course_id'];
        }
        if (!empty($filters['live_training_id'])) {
            $where[] = "a.live_training_id = :training_id";
            $params['training_id'] = $filters['live_training_id'];
        }
        if (isset($filters['is_standalone'])) {
            $where[] = "a.is_standalone = :is_standalone";
            $params['is_standalone'] = $filters['is_standalone'] ? 1 : 0;
        }
        if (!empty($filters['proctoring_level'])) {
            $where[] = "a.proctoring_level = :proctoring_level";
            $params['proctoring_level'] = $filters['proctoring_level'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(a.title LIKE :search OR a.description LIKE :search OR a.code LIKE :search)";
            $params['search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['created_by'])) {
            $where[] = "a.created_by = :created_by";
            $params['created_by'] = $filters['created_by'];
        }

        $whereClause = implode(' AND ', $where);

        // Count
        $countSql = "SELECT COUNT(*) FROM proc_assessments a WHERE $whereClause";
        $stmt = $this->db->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        // Get assessments
        $sql = "SELECT a.*,
                       c.title as course_title,
                       lt.title as training_title,
                       u.first_name as creator_first_name, u.last_name as creator_last_name,
                       (SELECT COUNT(*) FROM proc_assessment_questions WHERE assessment_id = a.id) as question_count
                FROM proc_assessments a
                LEFT JOIN courses c ON a.course_id = c.course_id
                LEFT JOIN live_trainings lt ON a.live_training_id = lt.id
                LEFT JOIN users u ON a.created_by = u.id
                WHERE $whereClause
                ORDER BY a.created_at DESC
                LIMIT $limit OFFSET $offset";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $assessments = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return [
            'data' => $assessments,
            'pagination' => [
                'total' => (int)$total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($total / $limit)
            ]
        ];
    }

    public function getById($id, $tenantId, $withDetails = true)
    {
        $sql = "SELECT a.*,
                       c.title as course_title,
                       lt.title as training_title,
                       ct.name as certificate_template_name
                FROM proc_assessments a
                LEFT JOIN courses c ON a.course_id = c.course_id
                LEFT JOIN live_trainings lt ON a.live_training_id = lt.training_id
                LEFT JOIN proc_certificate_templates ct ON a.certificate_template_id = ct.template_id
                WHERE a.assessment_id = :id AND a.tenant_id = :tenant_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        $assessment = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($assessment && $withDetails) {
            $assessment['sections'] = $this->getSections($id);
            $assessment['questions'] = $this->getQuestions($id);
            $assessment['proctor_settings'] = $this->getProctorSettings($id);
            $assessment['grade_boundaries'] = $assessment['grade_boundaries'] ? json_decode($assessment['grade_boundaries'], true) : null;
        }

        return $assessment;
    }

    public function create($data)
    {
        // Generate unique code
        $code = $this->generateCode();

        $sql = "INSERT INTO proc_assessments (
                    tenant_id, title, code, description, instructions, thumbnail,
                    course_id, live_training_id, is_standalone, is_prerequisite, is_final_assessment,
                    time_limit_minutes, time_limit_per_question_seconds, has_time_limit, auto_submit_on_timeout,
                    available_from, available_until, grace_period_minutes, timezone,
                    max_attempts, attempts_gap_hours, passing_score, passing_type,
                    show_score_immediately, score_display, enable_grading, grade_boundaries,
                    question_selection, random_question_count, shuffle_questions, shuffle_options,
                    questions_per_page, allow_backward_navigation, show_question_numbers,
                    show_progress_bar, show_timer, allow_mark_for_review,
                    show_correct_answers, show_explanations, show_score_breakdown, allow_review_attempt,
                    issue_certificate, certificate_template_id, certificate_requires_course_completion,
                    certificate_requires_training_attendance, certificate_expiry_days,
                    proctoring_level, auto_publish_results, require_manual_grading,
                    access_type, access_code, notify_on_submission, status, created_by
                ) VALUES (
                    :tenant_id, :title, :code, :description, :instructions, :thumbnail,
                    :course_id, :live_training_id, :is_standalone, :is_prerequisite, :is_final_assessment,
                    :time_limit_minutes, :time_limit_per_question_seconds, :has_time_limit, :auto_submit_on_timeout,
                    :available_from, :available_until, :grace_period_minutes, :timezone,
                    :max_attempts, :attempts_gap_hours, :passing_score, :passing_type,
                    :show_score_immediately, :score_display, :enable_grading, :grade_boundaries,
                    :question_selection, :random_question_count, :shuffle_questions, :shuffle_options,
                    :questions_per_page, :allow_backward_navigation, :show_question_numbers,
                    :show_progress_bar, :show_timer, :allow_mark_for_review,
                    :show_correct_answers, :show_explanations, :show_score_breakdown, :allow_review_attempt,
                    :issue_certificate, :certificate_template_id, :certificate_requires_course_completion,
                    :certificate_requires_training_attendance, :certificate_expiry_days,
                    :proctoring_level, :auto_publish_results, :require_manual_grading,
                    :access_type, :access_code, :notify_on_submission, 'draft', :created_by
                )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'tenant_id' => $data['tenant_id'],
            'title' => $data['title'],
            'code' => $code,
            'description' => $data['description'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'thumbnail' => $data['thumbnail'] ?? null,
            'course_id' => $data['course_id'] ?? null,
            'live_training_id' => $data['live_training_id'] ?? null,
            'is_standalone' => $data['is_standalone'] ?? 1,
            'is_prerequisite' => $data['is_prerequisite'] ?? 0,
            'is_final_assessment' => $data['is_final_assessment'] ?? 0,
            'time_limit_minutes' => $data['time_limit_minutes'] ?? null,
            'time_limit_per_question_seconds' => $data['time_limit_per_question_seconds'] ?? null,
            'has_time_limit' => $data['has_time_limit'] ?? 1,
            'auto_submit_on_timeout' => $data['auto_submit_on_timeout'] ?? 1,
            'available_from' => $data['available_from'] ?? null,
            'available_until' => $data['available_until'] ?? null,
            'grace_period_minutes' => $data['grace_period_minutes'] ?? 5,
            'timezone' => $data['timezone'] ?? 'UTC',
            'max_attempts' => $data['max_attempts'] ?? 1,
            'attempts_gap_hours' => $data['attempts_gap_hours'] ?? 0,
            'passing_score' => $data['passing_score'] ?? 70,
            'passing_type' => $data['passing_type'] ?? 'percentage',
            'show_score_immediately' => $data['show_score_immediately'] ?? 1,
            'score_display' => $data['score_display'] ?? 'both',
            'enable_grading' => $data['enable_grading'] ?? 0,
            'grade_boundaries' => isset($data['grade_boundaries']) ? json_encode($data['grade_boundaries']) : null,
            'question_selection' => $data['question_selection'] ?? 'manual',
            'random_question_count' => $data['random_question_count'] ?? null,
            'shuffle_questions' => $data['shuffle_questions'] ?? 1,
            'shuffle_options' => $data['shuffle_options'] ?? 1,
            'questions_per_page' => $data['questions_per_page'] ?? 'one',
            'allow_backward_navigation' => $data['allow_backward_navigation'] ?? 1,
            'show_question_numbers' => $data['show_question_numbers'] ?? 1,
            'show_progress_bar' => $data['show_progress_bar'] ?? 1,
            'show_timer' => $data['show_timer'] ?? 1,
            'allow_mark_for_review' => $data['allow_mark_for_review'] ?? 1,
            'show_correct_answers' => $data['show_correct_answers'] ?? 'after_all_attempts',
            'show_explanations' => $data['show_explanations'] ?? 1,
            'show_score_breakdown' => $data['show_score_breakdown'] ?? 1,
            'allow_review_attempt' => $data['allow_review_attempt'] ?? 1,
            'issue_certificate' => $data['issue_certificate'] ?? 0,
            'certificate_template_id' => $data['certificate_template_id'] ?? null,
            'certificate_requires_course_completion' => $data['certificate_requires_course_completion'] ?? 0,
            'certificate_requires_training_attendance' => $data['certificate_requires_training_attendance'] ?? 0,
            'certificate_expiry_days' => $data['certificate_expiry_days'] ?? null,
            'proctoring_level' => $data['proctoring_level'] ?? 'none',
            'auto_publish_results' => $data['auto_publish_results'] ?? 1,
            'require_manual_grading' => $data['require_manual_grading'] ?? 0,
            'access_type' => $data['access_type'] ?? 'assigned',
            'access_code' => $data['access_code'] ?? null,
            'notify_on_submission' => $data['notify_on_submission'] ?? 1,
            'created_by' => $data['created_by'] ?? null
        ]);

        $assessmentId = $this->db->lastInsertId();

        // Create proctor settings if proctoring enabled
        if (!empty($data['proctoring_level']) && $data['proctoring_level'] !== 'none') {
            $this->createProctorSettings($assessmentId, $data['proctoring_level']);
        }

        return $assessmentId;
    }

    public function update($id, $data)
    {
        $sets = [];
        $params = ['id' => $id, 'tenant_id' => $data['tenant_id']];

        $fields = [
            'title', 'description', 'instructions', 'thumbnail',
            'course_id', 'live_training_id', 'is_standalone', 'is_prerequisite', 'is_final_assessment',
            'time_limit_minutes', 'time_limit_per_question_seconds', 'has_time_limit', 'auto_submit_on_timeout',
            'available_from', 'available_until', 'grace_period_minutes', 'timezone',
            'max_attempts', 'attempts_gap_hours', 'passing_score', 'passing_type',
            'show_score_immediately', 'score_display', 'enable_grading',
            'question_selection', 'random_question_count', 'shuffle_questions', 'shuffle_options',
            'questions_per_page', 'allow_backward_navigation', 'show_question_numbers',
            'show_progress_bar', 'show_timer', 'allow_mark_for_review',
            'show_correct_answers', 'show_explanations', 'show_score_breakdown', 'allow_review_attempt',
            'issue_certificate', 'certificate_template_id', 'certificate_requires_course_completion',
            'certificate_requires_training_attendance', 'certificate_expiry_days',
            'proctoring_level', 'auto_publish_results', 'require_manual_grading',
            'access_type', 'access_code', 'notify_on_submission'
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = :$field";
                $params[$field] = $data[$field];
            }
        }

        if (isset($data['grade_boundaries'])) {
            $sets[] = "grade_boundaries = :grade_boundaries";
            $params['grade_boundaries'] = json_encode($data['grade_boundaries']);
        }

        if (isset($data['updated_by'])) {
            $sets[] = "updated_by = :updated_by";
            $params['updated_by'] = $data['updated_by'];
        }

        $sets[] = "updated_at = NOW()";

        $sql = "UPDATE proc_assessments SET " . implode(', ', $sets) . " WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        // Update proctor settings if proctoring level changed
        if (!empty($data['proctoring_level'])) {
            $this->updateProctorSettings($id, $data['proctoring_level']);
        }

        return true;
    }

    public function delete($id, $tenantId)
    {
        $sql = "UPDATE proc_assessments SET status = 'archived' WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
    }

    public function publish($id, $tenantId)
    {
        // Validate before publishing
        $assessment = $this->getById($id, $tenantId);
        if (!$assessment) {
            throw new \Exception('Assessment not found');
        }

        $questionCount = count($assessment['questions'] ?? []);
        if ($questionCount === 0) {
            throw new \Exception('Assessment must have at least one question');
        }

        // Calculate total points
        $totalPoints = 0;
        foreach ($assessment['questions'] as $q) {
            $totalPoints += $q['points_override'] ?? $q['points'] ?? 0;
        }

        $sql = "UPDATE proc_assessments SET 
                    status = 'published', 
                    published_at = NOW(),
                    total_points = :total_points
                WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            'id' => $id, 
            'tenant_id' => $tenantId,
            'total_points' => $totalPoints
        ]);
    }

    public function clone($id, $tenantId, $userId)
    {
        $original = $this->getById($id, $tenantId);
        if (!$original) return null;

        // Clone assessment
        $original['title'] = $original['title'] . ' (Copy)';
        $original['tenant_id'] = $tenantId;
        $original['created_by'] = $userId;
        $original['status'] = 'draft';
        unset($original['id'], $original['code'], $original['created_at'], $original['updated_at']);
        unset($original['total_attempts'], $original['total_completions'], $original['avg_score'], $original['pass_rate']);
        unset($original['published_at'], $original['sections'], $original['questions'], $original['proctor_settings']);

        $newId = $this->create($original);

        // Clone sections
        foreach ($this->getSections($id) as $section) {
            $section['assessment_id'] = $newId;
            unset($section['id']);
            $this->createSection($section);
        }

        // Clone question assignments
        $questions = $this->getQuestions($id);
        $this->addQuestions($newId, $questions);

        // Clone random rules
        foreach ($this->getRandomRules($id) as $rule) {
            $rule['assessment_id'] = $newId;
            unset($rule['id']);
            $this->createRandomRule($rule);
        }

        return $newId;
    }

    // ==================== SECTIONS ====================

    public function getSections($assessmentId)
    {
        $sql = "SELECT * FROM proc_assessment_sections WHERE assessment_id = :id ORDER BY sort_order";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $assessmentId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function createSection($data)
    {
        $sql = "INSERT INTO proc_assessment_sections (
                    assessment_id, title, description, instructions, time_limit_minutes,
                    sort_order, selection_type, random_count
                ) VALUES (
                    :assessment_id, :title, :description, :instructions, :time_limit_minutes,
                    :sort_order, :selection_type, :random_count
                )";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'assessment_id' => $data['assessment_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'instructions' => $data['instructions'] ?? null,
            'time_limit_minutes' => $data['time_limit_minutes'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
            'selection_type' => $data['selection_type'] ?? 'all',
            'random_count' => $data['random_count'] ?? null
        ]);
        return $this->db->lastInsertId();
    }

    // ==================== QUESTIONS ====================

    public function getQuestions($assessmentId)
    {
        $sql = "SELECT aq.*, q.question_type, q.question_text, q.question_html,
                       q.points, q.difficulty, q.estimated_time_seconds,
                       s.title as section_title
                FROM proc_assessment_questions aq
                INNER JOIN proc_questions q ON aq.question_id = q.question_id
                LEFT JOIN proc_assessment_sections s ON aq.section_id = s.section_id
                WHERE aq.assessment_id = :id
                ORDER BY aq.sort_order";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $assessmentId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function addQuestions($assessmentId, $questions)
    {
        $sql = "INSERT INTO proc_assessment_questions (
                    assessment_id, section_id, question_id, points_override,
                    time_limit_override, required, sort_order
                ) VALUES (
                    :assessment_id, :section_id, :question_id, :points_override,
                    :time_limit_override, :required, :sort_order
                )";
        $stmt = $this->db->prepare($sql);

        // Get current max sort order
        $maxOrder = $this->db->query("SELECT MAX(sort_order) FROM proc_assessment_questions WHERE assessment_id = $assessmentId")->fetchColumn() ?? -1;

        foreach ($questions as $q) {
            $maxOrder++;
            $stmt->execute([
                'assessment_id' => $assessmentId,
                'section_id' => $q['section_id'] ?? null,
                'question_id' => $q['question_id'] ?? $q['id'],
                'points_override' => $q['points_override'] ?? null,
                'time_limit_override' => $q['time_limit_override'] ?? null,
                'required' => $q['required'] ?? 0,
                'sort_order' => $q['sort_order'] ?? $maxOrder
            ]);
        }

        $this->recalculateTotalPoints($assessmentId);
    }

    public function removeQuestion($assessmentId, $questionId)
    {
        $sql = "DELETE FROM proc_assessment_questions WHERE assessment_id = :assessment_id AND question_id = :question_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['assessment_id' => $assessmentId, 'question_id' => $questionId]);
        $this->recalculateTotalPoints($assessmentId);
    }

    public function reorderQuestions($assessmentId, $questionIds)
    {
        $sql = "UPDATE proc_assessment_questions SET sort_order = :sort_order 
                WHERE assessment_id = :assessment_id AND question_id = :question_id";
        $stmt = $this->db->prepare($sql);

        foreach ($questionIds as $order => $questionId) {
            $stmt->execute([
                'assessment_id' => $assessmentId,
                'question_id' => $questionId,
                'sort_order' => $order
            ]);
        }
    }

    private function recalculateTotalPoints($assessmentId)
    {
        $sql = "UPDATE proc_assessments SET total_points = (
                    SELECT COALESCE(SUM(COALESCE(aq.points_override, q.points)), 0)
                    FROM proc_assessment_questions aq
                    INNER JOIN proc_questions q ON aq.question_id = q.question_id
                    WHERE aq.assessment_id = :id
                ) WHERE assessment_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $assessmentId]);
    }

    // ==================== RANDOM RULES ====================

    public function getRandomRules($assessmentId)
    {
        $sql = "SELECT rr.*, qb.name as question_bank_name, c.name as category_name
                FROM proc_assessment_random_rules rr
                LEFT JOIN proc_question_banks qb ON rr.bank_id = qb.bank_id
                LEFT JOIN proc_question_categories c ON rr.category_id = c.category_id
                WHERE rr.assessment_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $assessmentId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function createRandomRule($data)
    {
        $sql = "INSERT INTO proc_assessment_random_rules (
                    assessment_id, section_id, bank_id, category_id,
                    difficulty, question_count, points_per_question, tenant_id
                ) VALUES (
                    :assessment_id, :section_id, :question_bank_id, :category_id,
                    :difficulty, :question_count, :points_per_question, :tenant_id
                )";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'assessment_id' => $data['assessment_id'],
            'section_id' => $data['section_id'] ?? null,
            'question_bank_id' => $data['question_bank_id'],
            'category_id' => $data['category_id'] ?? null,
            'difficulty' => $data['difficulty'] ?? 'any',
            'question_count' => $data['question_count'],
            'points_per_question' => $data['points_per_question'] ?? 1,
            'tenant_id' => $data['tenant_id'],
        ]);
        return $this->db->lastInsertId();
    }

    // ==================== PROCTOR SETTINGS ====================

    public function getProctorSettings($assessmentId)
    {
        $sql = "SELECT * FROM proc_proctoring_settings WHERE assessment_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $assessmentId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public function createProctorSettings($assessmentId, $level)
    {
        $defaults = $this->getProctorDefaults($level);
        $defaults['assessment_id'] = $assessmentId;

        $columns = array_keys($defaults);
        $placeholders = array_map(fn($c) => ":$c", $columns);

        $sql = "INSERT INTO proc_proctor_settings (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($defaults);
    }

    public function updateProctorSettings($assessmentId, $level, $customSettings = [])
    {
        $existing = $this->getProctorSettings($assessmentId);
        
        if (!$existing) {
            $this->createProctorSettings($assessmentId, $level);
            return;
        }

        $defaults = $this->getProctorDefaults($level);
        $settings = array_merge($defaults, $customSettings);
        unset($settings['assessment_id']);

        $sets = [];
        $params = ['id' => $existing['id']];
        foreach ($settings as $key => $value) {
            $sets[] = "$key = :$key";
            $params[$key] = $value;
        }

        $sql = "UPDATE proc_proctor_settings SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    private function getProctorDefaults($level)
    {
        $basic = [
            'require_system_check' => 1, 'require_webcam' => 0, 'require_microphone' => 0,
            'require_id_verification' => 0, 'require_face_registration' => 0, 'require_environment_scan' => 0,
            'require_terms_acceptance' => 1, 'enable_webcam_monitoring' => 0, 'snapshot_interval_seconds' => 60,
            'detect_face_absence' => 0, 'detect_multiple_faces' => 0, 'detect_face_mismatch' => 0,
            'enable_fullscreen_mode' => 1, 'detect_tab_switch' => 1, 'detect_browser_focus_loss' => 1,
            'detect_copy_paste' => 1, 'disable_right_click' => 1, 'detect_screenshot' => 0,
            'detect_dev_tools' => 1, 'enable_screen_recording' => 0, 'enable_ai_detection' => 0,
            'warning_threshold' => 5, 'termination_threshold' => 10
        ];

        $standard = array_merge($basic, [
            'require_webcam' => 1, 'require_face_registration' => 1, 'enable_webcam_monitoring' => 1,
            'snapshot_interval_seconds' => 30, 'detect_face_absence' => 1, 'detect_multiple_faces' => 1,
            'detect_screenshot' => 1, 'enable_ai_detection' => 1, 'detect_looking_away' => 1,
            'detect_phone_in_frame' => 1, 'warning_threshold' => 3, 'termination_threshold' => 5
        ]);

        $advanced = array_merge($standard, [
            'require_microphone' => 1, 'require_id_verification' => 1, 'require_environment_scan' => 1,
            'snapshot_interval_seconds' => 15, 'detect_face_mismatch' => 1, 'enable_audio_monitoring' => 1,
            'enable_screen_recording' => 1, 'enable_audio_recording' => 1, 'detect_person_in_frame' => 1,
            'auto_terminate_on_critical' => 1, 'warning_threshold' => 2, 'termination_threshold' => 3
        ]);

        switch ($level) {
            case 'advanced': return $advanced;
            case 'standard': return $standard;
            default: return $basic;
        }
    }

    // ==================== ASSIGNMENTS ====================

    public function assign($assessmentId, $data)
    {
        $count = 0;
        $sql = "INSERT INTO proc_assessment_assignments (
                    tenant_id, assessment_id, user_id, team_id, department_id,
                    available_from_override, available_until_override, max_attempts_override,
                    assigned_by
                ) VALUES (
                    :tenant_id, :assessment_id, :user_id, :group_id, :department_id,
                    :available_from_override, :available_until_override, :max_attempts_override,
                    :assigned_by
                )";
        $stmt = $this->db->prepare($sql);

        // Assign to users
        if (!empty($data['user_ids'])) {
            foreach ($data['user_ids'] as $userId) {
                $stmt->execute([
                    'tenant_id' => $data['tenant_id'],
                    'assessment_id' => $assessmentId,
                    'user_id' => $userId,
                    'group_id' => null,
                    'department_id' => null,
                    'available_from_override' => $data['available_from_override'] ?? null,
                    'available_until_override' => $data['available_until_override'] ?? null,
                    'max_attempts_override' => $data['max_attempts_override'] ?? null,
                    'assigned_by' => $data['assigned_by']
                ]);
                $count++;
            }
        }

        // Assign to groups
        if (!empty($data['group_ids'])) {
            foreach ($data['group_ids'] as $groupId) {
                $stmt->execute([
                    'tenant_id' => $data['tenant_id'],
                    'assessment_id' => $assessmentId,
                    'user_id' => null,
                    'group_id' => $groupId,
                    'department_id' => null,
                    'available_from_override' => $data['available_from_override'] ?? null,
                    'available_until_override' => $data['available_until_override'] ?? null,
                    'max_attempts_override' => $data['max_attempts_override'] ?? null,
                    'assigned_by' => $data['assigned_by']
                ]);
                $count++;
            }
        }

        return $count;
    }

    public function canUserAccess($assessmentId, $userId, $tenantId)
    {
        $assessment = $this->getById($assessmentId, $tenantId, false);
        if (!$assessment || $assessment['status'] !== 'published') {
            return ['can_access' => false, 'reason' => 'Assessment not available'];
        }

        // Check availability dates
        $now = new \DateTime();
        if ($assessment['available_from'] && $now < new \DateTime($assessment['available_from'])) {
            return ['can_access' => false, 'reason' => 'Assessment not yet available'];
        }
        if ($assessment['available_until'] && $now > new \DateTime($assessment['available_until'])) {
            return ['can_access' => false, 'reason' => 'Assessment deadline has passed'];
        }

        // Check access type
        if ($assessment['access_type'] === 'public') {
            return ['can_access' => true, 'reason' => null];
        }

        // Check direct assignment
        $sql = "SELECT * FROM proc_assessment_assignments 
                WHERE assessment_id = :assessment_id AND tenant_id = :tenant_id
                AND (user_id = :user_id OR user_id IS NULL)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['assessment_id' => $assessmentId, 'tenant_id' => $tenantId, 'user_id' => $userId]);
        $assignment = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($assignment) {
            return ['can_access' => true, 'reason' => null];
        }

        return ['can_access' => false, 'reason' => 'You are not assigned to this assessment'];
    }

    public function getUserAttemptCount($assessmentId, $userId)
    {
        $sql = "SELECT COUNT(*) FROM proc_attempts WHERE assessment_id = :id AND user_id = :user_id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $assessmentId, 'user_id' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    // ==================== STATISTICS ====================

    public function updateStats($assessmentId)
    {
        $sql = "UPDATE proc_assessments SET
                    total_attempts = (SELECT COUNT(*) FROM proc_attempts WHERE assessment_id = :id AND status IN ('submitted', 'grading', 'graded')),
                    total_completions = (SELECT COUNT(*) FROM proc_attempts WHERE assessment_id = :id AND status = 'graded'),
                    avg_score = (SELECT AVG(percentage_score) FROM proc_attempts WHERE assessment_id = :id AND status = 'graded'),
                    pass_rate = (SELECT AVG(passed) * 100 FROM proc_attempts WHERE assessment_id = :id AND status = 'graded')
                WHERE assessment_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $assessmentId]);
    }

    // ==================== HELPERS ====================

    private function generateCode()
    {
        return 'ASM-' . strtoupper(substr(md5(uniqid()), 0, 6));
    }
}
