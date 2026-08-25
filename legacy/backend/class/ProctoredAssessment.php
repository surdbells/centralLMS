<?php

/**
 * ProctoredAssessment Class
 *
 * Handles all Proctored Assessment operations including:
 * - Question Bank & Question CRUD operations
 * - Assessment CRUD operations
 * - Attempt management
 * - Auto-grading for 11 question types
 * - Proctoring session management
 * - Certificate issuance
 *
 * @package LMS
 * @version 1.0.0
 */

class ProctoredAssessment
{
    private $conn;
    private int $tenant_id;
    private int $user_id;

    public function __construct($db_connection, int $tenant_id, int $user_id)
    {
        $this->conn = $db_connection;
        $this->tenant_id = $tenant_id;
        $this->user_id = $user_id;
    }

    /**
     * Helper method to bind parameters dynamically
     * Handles the reference issue with bind_param
     */
    private function bindParams($stmt, $types, $params)
    {
        $refs = [];
        $refs[] = $types;

        foreach ($params as $key => $value) {
            $refs[] = &$params[$key];
        }

        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    // ==========================================
    // QUESTION BANK OPERATIONS
    // ==========================================

    /**
     * Create a new question bank
     *
     * @param array $data Bank data
     * @return array Created bank
     */
    public function createQuestionBank(array $data): array
    {
        $sql = "INSERT INTO proc_question_banks (
            tenant_id, name, description, status, created_by
        ) VALUES (?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);

        $name = $data['name'];
        $description = $data['description'] ?? null;
        $status = $data['status'] ?? 'active';

        $stmt->bind_param(
            "isssi",
            $this->tenant_id,
            $name,
            $description,
            $status,
            $this->user_id
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to create question bank: " . $stmt->error);
        }

        $bank_id = $this->conn->insert_id;
        $stmt->close();

        return $this->getQuestionBank($bank_id);
    }

    /**
     * Get single question bank
     *
     * @param int $bank_id Question bank ID
     * @return array|null Bank data
     */
    public function getQuestionBank(int $bank_id): ?array
    {
        $sql = "SELECT qb.*,
                u.first_name as creator_first_name,
                u.last_name as creator_last_name,
                (SELECT COUNT(*) FROM proc_questions WHERE question_bank_id = qb.bank_id AND status != 'archived') as question_count
            FROM proc_question_banks qb
            LEFT JOIN users u ON qb.created_by = u.id
            WHERE qb.bank_id = ? AND qb.tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $bank_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $bank = $result->fetch_assoc();
        $stmt->close();

        return $bank;
    }

    /**
     * Get all question banks
     *
     * @param array $filters Optional filters
     * @return array List of banks
     */
    public function getQuestionBanks(array $filters = []): array
    {
        $sql = "SELECT qb.*,
                u.first_name as creator_first_name,
                u.last_name as creator_last_name,
                (SELECT COUNT(*) FROM proc_questions WHERE question_bank_id = qb.bank_id AND status != 'archived') as question_count
            FROM proc_question_banks qb
            LEFT JOIN users u ON qb.created_by = u.id
            WHERE qb.tenant_id = ?";

        $params = [$this->tenant_id];
        $types = "i";

        if (!empty($filters['status'])) {
            $sql .= " AND qb.status = ?";
            $params[] = $filters['status'];
            $types .= "s";
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (qb.name LIKE ? OR qb.description LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $types .= "ss";
        }

        $sql .= " ORDER BY qb.name ASC";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $banks = [];
        while ($row = $result->fetch_assoc()) {
            $banks[] = $row;
        }
        $stmt->close();

        return $banks;
    }

    /**
     * Update question bank
     *
     * @param int $bank_id Bank ID
     * @param array $data Update data
     * @return array Updated bank
     */
    public function updateQuestionBank(int $bank_id, array $data): array
    {
        $fields = [];
        $params = [];
        $types = "";

        $allowedFields = [
            'name' => 's',
            'description' => 's',
            'status' => 's'
        ];

        foreach ($allowedFields as $field => $type) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
                $types .= $type;
            }
        }

        $fields[] = "updated_at = NOW()";

        $params[] = $bank_id;
        $params[] = $this->tenant_id;
        $types .= "ii";

        $sql = "UPDATE proc_question_banks SET " . implode(", ", $fields) .
            " WHERE bank_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);

        if (!$stmt->execute()) {
            throw new Exception("Failed to update question bank: " . $stmt->error);
        }
        $stmt->close();

        return $this->getQuestionBank($bank_id);
    }

    /**
     * Delete question bank (soft delete)
     *
     * @param int $bank_id Bank ID
     * @return bool Success
     */
    public function deleteQuestionBank(int $bank_id): bool
    {
        $sql = "UPDATE proc_question_banks SET status = 'archived' WHERE bank_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $bank_id, $this->tenant_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    // ==========================================
    // QUESTION CATEGORY OPERATIONS
    // ==========================================

    /**
     * Create category in question bank
     *
     * @param int $bank_id Bank ID
     * @param array $data Category data
     * @return array Created category
     */
    public function createCategory(int $bank_id, array $data): array
    {
        $sql = "INSERT INTO proc_question_bank_categories (
            tenant_id, bank_id, parent_id, name, description
        ) VALUES (?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);

        $parent_id = $data['parent_id'] ?? null;
        $name = $data['name'];
        $description = $data['description'] ?? null;

        $stmt->bind_param(
            "iiiss",
            $this->tenant_id,
            $bank_id,
            $parent_id,
            $name,
            $description
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to create category: " . $stmt->error);
        }

        $category_id = $this->conn->insert_id;
        $stmt->close();

        return $this->getCategory($category_id);
    }

    /**
     * Get category by ID
     *
     * @param int $category_id Category ID
     * @return array|null Category data
     */
    public function getCategory(int $category_id): ?array
    {
        $sql = "SELECT c.*,
                (SELECT COUNT(*) FROM proc_questions WHERE category_id = c.category_id AND status != 'archived') as question_count
            FROM proc_question_bank_categories c
            WHERE c.category_id = ? AND c.tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $category_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $category = $result->fetch_assoc();
        $stmt->close();

        return $category;
    }

    /**
     * Get categories for a question bank
     *
     * @param int $bank_id Bank ID
     * @return array List of categories
     */
    public function getBankCategories(int $bank_id): array
    {
        $sql = "SELECT c.*,
                (SELECT COUNT(*) FROM proc_questions WHERE category_id = c.category_id AND status != 'archived') as question_count
            FROM proc_question_bank_categories c
            WHERE c.bank_id = ? AND c.tenant_id = ?
            ORDER BY c.name ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $bank_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $categories = [];
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row;
        }
        $stmt->close();

        return $categories;
    }

    // ==========================================
    // QUESTION CRUD OPERATIONS
    // ==========================================

    /**
     * Create a new question
     *
     * @param array $data Question data
     * @return array Created question
     */
    public function createQuestion(array $data): array
    {
        $sql = "INSERT INTO proc_questions (
            tenant_id, question_bank_id, category_id, question_type, question_text, question_html,
            explanation, hint, featured_image, audio_url, video_url, points, negative_points,
            partial_credit, difficulty, estimated_time_seconds, case_sensitive, allow_regex,
            numeric_tolerance, numeric_unit, max_file_size_mb, allowed_file_types,
            max_words, min_words, blank_count, hotspot_image, hotspot_shape, status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);

        $question_bank_id = $data['question_bank_id'];
        $category_id = $data['category_id'] ?? null;
        $question_type = $data['question_type'];
        $question_text = $data['question_text'];
        $question_html = $data['question_html'] ?? null;
        $explanation = $data['explanation'] ?? null;
        $hint = $data['hint'] ?? null;
        $featured_image = $data['featured_image'] ?? null;
        $audio_url = $data['audio_url'] ?? null;
        $video_url = $data['video_url'] ?? null;
        $points = $data['points'] ?? 1;
        $negative_points = $data['negative_points'] ?? 0;
        $partial_credit = $data['partial_credit'] ?? 0;
        $difficulty = $data['difficulty'] ?? 'medium';
        $estimated_time_seconds = $data['estimated_time_seconds'] ?? 60;
        $case_sensitive = $data['case_sensitive'] ?? 0;
        $allow_regex = $data['allow_regex'] ?? 0;
        $numeric_tolerance = $data['numeric_tolerance'] ?? 0;
        $numeric_unit = $data['numeric_unit'] ?? null;
        $max_file_size_mb = $data['max_file_size_mb'] ?? 10;
        $allowed_file_types = $data['allowed_file_types'] ?? 'pdf,doc,docx,jpg,png';
        $max_words = $data['max_words'] ?? null;
        $min_words = $data['min_words'] ?? null;
        $blank_count = $data['blank_count'] ?? 0;
        $hotspot_image = $data['hotspot_image'] ?? null;
        $hotspot_shape = $data['hotspot_shape'] ?? 'rectangle';
        $status = $data['status'] ?? 'active';

        $stmt->bind_param(
            "iiissssssssddisiiidsiisiissi",
            $this->tenant_id,
            $question_bank_id,
            $category_id,
            $question_type,
            $question_text,
            $question_html,
            $explanation,
            $hint,
            $featured_image,
            $audio_url,
            $video_url,
            $points,
            $negative_points,
            $partial_credit,
            $difficulty,
            $estimated_time_seconds,
            $case_sensitive,
            $allow_regex,
            $numeric_tolerance,
            $numeric_unit,
            $max_file_size_mb,
            $allowed_file_types,
            $max_words,
            $min_words,
            $blank_count,
            $hotspot_image,
            $hotspot_shape,
            $status,
            $this->user_id
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to create question: " . $stmt->error);
        }

        $question_id = $this->conn->insert_id;
        $stmt->close();

        // Save options if provided
        if (!empty($data['options'])) {
            $this->saveQuestionOptions($question_id, $data['options']);
        }

        // Save tags if provided
        if (!empty($data['tags'])) {
            $this->saveQuestionTags($question_id, $data['tags']);
        }

        // Update bank question count
        $this->updateBankQuestionCount($question_bank_id);

        return $this->getQuestion($question_id);
    }

    /**
     * Get single question with details
     *
     * @param int $question_id Question ID
     * @return array|null Question data
     */
    public function getQuestion(int $question_id): ?array
    {
        $sql = "SELECT q.*,
                qb.name as question_bank_name,
                c.name as category_name
            FROM proc_questions q
            LEFT JOIN proc_question_banks qb ON q.question_bank_id = qb.bank_id
            LEFT JOIN proc_question_bank_categories c ON q.category_id = c.category_id
            WHERE q.question_id = ? AND q.tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $question_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $question = $result->fetch_assoc();
        $stmt->close();

        if ($question) {
            $question['options'] = $this->getQuestionOptions($question_id);
            $question['tags'] = $this->getQuestionTags($question_id);
        }

        return $question;
    }

    /**
     * Get questions with filters and pagination
     *
     * @param array $filters Filter options
     * @return array Questions with pagination
     */
    public function getQuestions(array $filters = []): array
    {
        $page = (int)($filters['page'] ?? 1);
        $limit = (int)($filters['limit'] ?? 20);
        $offset = ($page - 1) * $limit;

        $where = ["q.tenant_id = ?", "q.status != 'archived'"];
        $params = [$this->tenant_id];
        $types = "i";

        if (!empty($filters['question_bank_id'])) {
            $where[] = "q.question_bank_id = ?";
            $params[] = (int)$filters['question_bank_id'];
            $types .= "i";
        }

        if (!empty($filters['category_id'])) {
            $where[] = "q.category_id = ?";
            $params[] = (int)$filters['category_id'];
            $types .= "i";
        }

        if (!empty($filters['question_type'])) {
            $where[] = "q.question_type = ?";
            $params[] = $filters['question_type'];
            $types .= "s";
        }

        if (!empty($filters['difficulty'])) {
            $where[] = "q.difficulty = ?";
            $params[] = $filters['difficulty'];
            $types .= "s";
        }

        if (!empty($filters['status'])) {
            $where[] = "q.status = ?";
            $params[] = $filters['status'];
            $types .= "s";
        }

        if (!empty($filters['search'])) {
            $where[] = "(q.question_text LIKE ? OR q.explanation LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $types .= "ss";
        }

        $whereClause = implode(' AND ', $where);

        // Count total
        $countSql = "SELECT COUNT(*) as total FROM proc_questions q WHERE $whereClause";
        $stmt = $this->conn->prepare($countSql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        // Get questions
        $sql = "SELECT q.*,
                qb.name as question_bank_name,
                c.name as category_name
            FROM proc_questions q
            LEFT JOIN proc_question_banks qb ON q.question_bank_id = qb.bank_id
            LEFT JOIN proc_question_bank_categories c ON q.category_id = c.category_id
            WHERE $whereClause
            ORDER BY q.created_at DESC
            LIMIT $limit OFFSET $offset";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $questions = [];
        while ($row = $result->fetch_assoc()) {
            $row['tags'] = $this->getQuestionTags($row['question_id']);
            $questions[] = $row;
        }
        $stmt->close();

        return [
            'data' => $questions,
            'pagination' => [
                'total' => (int)$total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($total / $limit)
            ]
        ];
    }

    /**
     * Update a question
     *
     * @param int $question_id Question ID
     * @param array $data Update data
     * @return array Updated question
     */
    public function updateQuestion(int $question_id, array $data): array
    {
        // Save version before update
        $this->saveQuestionVersion($question_id);

        $fields = [];
        $params = [];
        $types = "";

        $allowedFields = [
            'category_id' => 'i',
            'question_text' => 's',
            'question_html' => 's',
            'explanation' => 's',
            'hint' => 's',
            'featured_image' => 's',
            'audio_url' => 's',
            'video_url' => 's',
            'points' => 'd',
            'negative_points' => 'd',
            'partial_credit' => 'i',
            'difficulty' => 's',
            'estimated_time_seconds' => 'i',
            'case_sensitive' => 'i',
            'allow_regex' => 'i',
            'numeric_tolerance' => 'd',
            'numeric_unit' => 's',
            'max_file_size_mb' => 'i',
            'allowed_file_types' => 's',
            'max_words' => 'i',
            'min_words' => 'i',
            'blank_count' => 'i',
            'hotspot_image' => 's',
            'hotspot_shape' => 's',
            'status' => 's'
        ];

        foreach ($allowedFields as $field => $type) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
                $types .= $type;
            }
        }

        $fields[] = "version = version + 1";
        $fields[] = "updated_by = ?";
        $fields[] = "updated_at = NOW()";
        $params[] = $this->user_id;
        $types .= "i";

        $params[] = $question_id;
        $params[] = $this->tenant_id;
        $types .= "ii";

        $sql = "UPDATE proc_questions SET " . implode(", ", $fields) .
            " WHERE question_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);

        if (!$stmt->execute()) {
            throw new Exception("Failed to update question: " . $stmt->error);
        }
        $stmt->close();

        // Update options if provided
        if (isset($data['options'])) {
            $this->deleteQuestionOptions($question_id);
            $this->saveQuestionOptions($question_id, $data['options']);
        }

        // Update tags if provided
        if (isset($data['tags'])) {
            $this->deleteQuestionTags($question_id);
            $this->saveQuestionTags($question_id, $data['tags']);
        }

        return $this->getQuestion($question_id);
    }

    /**
     * Delete a question (soft delete)
     *
     * @param int $question_id Question ID
     * @return bool Success
     */
    public function deleteQuestion(int $question_id): bool
    {
        // Get bank ID before delete
        $question = $this->getQuestion($question_id);
        if (!$question) {
            return false;
        }

        $sql = "UPDATE proc_questions SET status = 'archived' WHERE question_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $question_id, $this->tenant_id);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->updateBankQuestionCount($question['question_bank_id']);
        }

        return $result;
    }

    /**
     * Clone a question
     *
     * @param int $question_id Question ID to clone
     * @return array Cloned question
     */
    public function cloneQuestion(int $question_id): array
    {
        $original = $this->getQuestion($question_id);
        if (!$original) {
            throw new Exception("Question not found");
        }

        // Prepare clone data
        $cloneData = $original;
        $cloneData['question_text'] = $original['question_text'] . ' (Copy)';
        unset($cloneData['question_id'], $cloneData['created_at'], $cloneData['updated_at']);
        unset($cloneData['version'], $cloneData['times_used'], $cloneData['question_bank_name']);
        unset($cloneData['category_name']);

        return $this->createQuestion($cloneData);
    }

    // ==========================================
    // QUESTION OPTIONS HELPERS
    // ==========================================

    /**
     * Get options for a question
     *
     * @param int $question_id Question ID
     * @return array Options
     */
    private function getQuestionOptions(int $question_id): array
    {
        $sql = "SELECT * FROM proc_question_options WHERE question_id = ? ORDER BY sort_order";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $options = [];
        while ($row = $result->fetch_assoc()) {
            if ($row['hotspot_coords']) {
                $row['hotspot_coords'] = json_decode($row['hotspot_coords'], true);
            }
            if ($row['acceptable_answers']) {
                $row['acceptable_answers'] = json_decode($row['acceptable_answers'], true);
            }
            $options[] = $row;
        }
        $stmt->close();

        return $options;
    }

    /**
     * Save options for a question
     *
     * @param int $question_id Question ID
     * @param array $options Options to save
     */
    private function saveQuestionOptions(int $question_id, array $options): void
    {
        $sql = "INSERT INTO proc_question_options (
            question_id, option_text, option_html, option_image, is_correct, points_if_selected,
            match_text, match_image, correct_position, hotspot_coords, acceptable_answers,
            blank_index, sort_order, feedback
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);

        foreach ($options as $i => $opt) {
            $option_text = $opt['option_text'];
            $option_html = $opt['option_html'] ?? null;
            $option_image = $opt['option_image'] ?? null;
            $is_correct = $opt['is_correct'] ?? 0;
            $points_if_selected = $opt['points_if_selected'] ?? 0;
            $match_text = $opt['match_text'] ?? null;
            $match_image = $opt['match_image'] ?? null;
            $correct_position = $opt['correct_position'] ?? null;
            $hotspot_coords = isset($opt['hotspot_coords']) ? json_encode($opt['hotspot_coords']) : null;
            $acceptable_answers = isset($opt['acceptable_answers']) ? json_encode($opt['acceptable_answers']) : null;
            $blank_index = $opt['blank_index'] ?? null;
            $sort_order = $opt['sort_order'] ?? $i;
            $feedback = $opt['feedback'] ?? null;

            $stmt->bind_param(
                "isssidssississs",
                $question_id,
                $option_text,
                $option_html,
                $option_image,
                $is_correct,
                $points_if_selected,
                $match_text,
                $match_image,
                $correct_position,
                $hotspot_coords,
                $acceptable_answers,
                $blank_index,
                $sort_order,
                $feedback
            );

            $stmt->execute();
        }
        $stmt->close();
    }

    /**
     * Delete options for a question
     *
     * @param int $question_id Question ID
     */
    private function deleteQuestionOptions(int $question_id): void
    {
        $sql = "DELETE FROM proc_question_options WHERE question_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        $stmt->close();
    }

    // ==========================================
    // QUESTION TAGS HELPERS
    // ==========================================

    /**
     * Get tags for a question
     *
     * @param int $question_id Question ID
     * @return array Tags
     */
    private function getQuestionTags(int $question_id): array
    {
        $sql = "SELECT t.* FROM proc_question_tags t
            INNER JOIN proc_question_tag_map tm ON t.tag_id = tm.tag_id
            WHERE tm.question_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $tags = [];
        while ($row = $result->fetch_assoc()) {
            $tags[] = $row;
        }
        $stmt->close();

        return $tags;
    }

    /**
     * Save tags for a question
     *
     * @param int $question_id Question ID
     * @param array $tag_ids Tag IDs to associate
     */
    private function saveQuestionTags(int $question_id, array $tag_ids): void
    {
        $sql = "INSERT INTO proc_question_tag_map (question_id, tag_id) VALUES (?, ?)";
        $stmt = $this->conn->prepare($sql);

        foreach ($tag_ids as $tag_id) {
            $stmt->bind_param("ii", $question_id, $tag_id);
            $stmt->execute();

            // Update tag usage count
            $this->conn->query("UPDATE proc_question_tags SET usage_count = usage_count + 1 WHERE tag_id = $tag_id");
        }
        $stmt->close();
    }

    /**
     * Delete tags for a question
     *
     * @param int $question_id Question ID
     */
    private function deleteQuestionTags(int $question_id): void
    {
        // Decrement usage counts
        $tags = $this->getQuestionTags($question_id);
        foreach ($tags as $tag) {
            $this->conn->query("UPDATE proc_question_tags SET usage_count = usage_count - 1 WHERE tag_id = {$tag['tag_id']}");
        }

        $sql = "DELETE FROM proc_question_tag_map WHERE question_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Save question version for history
     *
     * @param int $question_id Question ID
     */
    private function saveQuestionVersion(int $question_id): void
    {
        $question = $this->getQuestion($question_id);
        if (!$question) {
            return;
        }

        $sql = "INSERT INTO proc_question_versions (question_id, version, question_data, changed_by)
            VALUES (?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);

        $version = $question['version'];
        $question_data = json_encode($question);

        $stmt->bind_param("iisi", $question_id, $version, $question_data, $this->user_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Update question bank total count
     *
     * @param int $bank_id Bank ID
     */
    private function updateBankQuestionCount(int $bank_id): void
    {
        $sql = "UPDATE proc_question_banks SET total_questions = (
            SELECT COUNT(*) FROM proc_questions WHERE question_bank_id = ? AND status != 'archived'
        ) WHERE bank_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $bank_id, $bank_id);
        $stmt->execute();
        $stmt->close();
    }

    // ==========================================
    // TAG MANAGEMENT
    // ==========================================

    /**
     * Create a new tag
     *
     * @param array $data Tag data
     * @return array Created tag
     */
    public function createTag(array $data): array
    {
        $sql = "INSERT INTO proc_question_tags (tenant_id, name, color) VALUES (?, ?, ?)";
        $stmt = $this->conn->prepare($sql);

        $name = $data['name'];
        $color = $data['color'] ?? '#6c757d';

        $stmt->bind_param("iss", $this->tenant_id, $name, $color);

        if (!$stmt->execute()) {
            throw new Exception("Failed to create tag: " . $stmt->error);
        }

        $tag_id = $this->conn->insert_id;
        $stmt->close();

        return $this->getTag($tag_id);
    }

    /**
     * Get tag by ID
     *
     * @param int $tag_id Tag ID
     * @return array|null Tag data
     */
    public function getTag(int $tag_id): ?array
    {
        $sql = "SELECT * FROM proc_question_tags WHERE tag_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $tag_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $tag = $result->fetch_assoc();
        $stmt->close();

        return $tag;
    }

    /**
     * Get all tags
     *
     * @return array List of tags
     */
    public function getTags(): array
    {
        $sql = "SELECT * FROM proc_question_tags WHERE tenant_id = ? ORDER BY name ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $tags = [];
        while ($row = $result->fetch_assoc()) {
            $tags[] = $row;
        }
        $stmt->close();

        return $tags;
    }

    // ==========================================
    // AUTO-GRADING ENGINE
    // ==========================================

    /**
     * Grade an answer automatically
     *
     * @param int $question_id Question ID
     * @param array $answer_data Answer data
     * @return array Grading result
     */
    public function gradeAnswer(int $question_id, array $answer_data): array
    {
        $question = $this->getQuestion($question_id);
        if (!$question) {
            return ['is_correct' => null, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => false];
        }

        switch ($question['question_type']) {
            case 'multiple_choice_single':
                return $this->gradeMcqSingle($question, $answer_data);

            case 'multiple_choice_multiple':
                return $this->gradeMcqMultiple($question, $answer_data);

            case 'true_false':
                return $this->gradeTrueFalse($question, $answer_data);

            case 'fill_in_blanks':
                return $this->gradeFillBlanks($question, $answer_data);

            case 'short_answer':
                return $this->gradeShortAnswer($question, $answer_data);

            case 'matching':
                return $this->gradeMatching($question, $answer_data);

            case 'ordering':
                return $this->gradeOrdering($question, $answer_data);

            case 'hotspot':
                return $this->gradeHotspot($question, $answer_data);

            case 'numeric':
                return $this->gradeNumeric($question, $answer_data);

            case 'long_answer':
            case 'file_upload':
                return [
                    'is_correct' => null,
                    'is_partial' => false,
                    'points_earned' => 0,
                    'auto_graded' => false,
                    'feedback' => 'Requires manual grading'
                ];

            default:
                return ['is_correct' => null, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => false];
        }
    }

    /**
     * Grade MCQ Single Answer
     */
    private function gradeMcqSingle(array $question, array $answer_data): array
    {
        $selected_id = $answer_data['selected_option_id'] ?? null;
        if (!$selected_id) {
            return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
        }

        foreach ($question['options'] as $opt) {
            if ($opt['option_id'] == $selected_id) {
                if ($opt['is_correct']) {
                    return [
                        'is_correct' => true,
                        'is_partial' => false,
                        'points_earned' => $question['points'],
                        'auto_graded' => true,
                        'feedback' => $opt['feedback']
                    ];
                } else {
                    $penalty = $question['negative_points'] ?? 0;
                    return [
                        'is_correct' => false,
                        'is_partial' => false,
                        'points_earned' => -$penalty,
                        'auto_graded' => true,
                        'feedback' => $opt['feedback']
                    ];
                }
            }
        }

        return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
    }

    /**
     * Grade MCQ Multiple Answers
     */
    private function gradeMcqMultiple(array $question, array $answer_data): array
    {
        $selected_ids = $answer_data['selected_option_ids'] ?? [];
        if (empty($selected_ids)) {
            return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
        }

        $correct_ids = [];
        foreach ($question['options'] as $opt) {
            if ($opt['is_correct']) {
                $correct_ids[] = $opt['option_id'];
            }
        }

        $correct_selected = array_intersect($selected_ids, $correct_ids);
        $incorrect_selected = array_diff($selected_ids, $correct_ids);

        // Perfect answer
        if (count($correct_selected) == count($correct_ids) && empty($incorrect_selected)) {
            return ['is_correct' => true, 'is_partial' => false, 'points_earned' => $question['points'], 'auto_graded' => true];
        }

        // Partial credit
        if ($question['partial_credit'] && count($correct_selected) > 0) {
            $ratio = count($correct_selected) / count($correct_ids);
            $penalty = count($incorrect_selected) * ($question['negative_points'] ?? 0);
            $earned = max(0, ($question['points'] * $ratio) - $penalty);
            return ['is_correct' => false, 'is_partial' => true, 'points_earned' => $earned, 'auto_graded' => true];
        }

        return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
    }

    /**
     * Grade True/False
     */
    private function gradeTrueFalse(array $question, array $answer_data): array
    {
        $answer = $answer_data['answer'] ?? null;
        if ($answer === null) {
            return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
        }

        $correct_option = null;
        foreach ($question['options'] as $opt) {
            if ($opt['is_correct']) {
                $correct_option = $opt;
                break;
            }
        }

        $correct_answer = strtolower($correct_option['option_text']) === 'true';
        $is_correct = ($answer === $correct_answer);

        return [
            'is_correct' => $is_correct,
            'is_partial' => false,
            'points_earned' => $is_correct ? $question['points'] : 0,
            'auto_graded' => true
        ];
    }

    /**
     * Grade Fill in Blanks
     */
    private function gradeFillBlanks(array $question, array $answer_data): array
    {
        $blanks = $answer_data['blanks'] ?? [];
        if (empty($blanks)) {
            return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
        }

        $total_blanks = count($question['options']);
        $correct_count = 0;

        foreach ($question['options'] as $opt) {
            $blank_index = $opt['blank_index'];
            $user_answer = $blanks[$blank_index] ?? '';
            $acceptable = $opt['acceptable_answers'] ?? [$opt['option_text']];

            $is_match = false;
            foreach ($acceptable as $acc) {
                if ($question['case_sensitive']) {
                    if ($user_answer === $acc) $is_match = true;
                } else {
                    if (strtolower(trim($user_answer)) === strtolower(trim($acc))) $is_match = true;
                }
            }

            if ($is_match) $correct_count++;
        }

        if ($correct_count == $total_blanks) {
            return ['is_correct' => true, 'is_partial' => false, 'points_earned' => $question['points'], 'auto_graded' => true];
        }

        if ($question['partial_credit'] && $correct_count > 0) {
            $ratio = $correct_count / $total_blanks;
            return ['is_correct' => false, 'is_partial' => true, 'points_earned' => $question['points'] * $ratio, 'auto_graded' => true];
        }

        return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
    }

    /**
     * Grade Short Answer
     */
    private function gradeShortAnswer(array $question, array $answer_data): array
    {
        $answer = trim($answer_data['text'] ?? '');
        if (empty($answer)) {
            return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
        }

        foreach ($question['options'] as $opt) {
            $acceptable = $opt['acceptable_answers'] ?? [$opt['option_text']];
            foreach ($acceptable as $acc) {
                if ($question['allow_regex']) {
                    if (preg_match('/' . $acc . '/i', $answer)) {
                        return ['is_correct' => true, 'is_partial' => false, 'points_earned' => $question['points'], 'auto_graded' => true];
                    }
                } else {
                    $match = $question['case_sensitive']
                        ? ($answer === $acc)
                        : (strtolower($answer) === strtolower($acc));
                    if ($match) {
                        return ['is_correct' => true, 'is_partial' => false, 'points_earned' => $question['points'], 'auto_graded' => true];
                    }
                }
            }
        }

        return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
    }

    /**
     * Grade Matching
     */
    private function gradeMatching(array $question, array $answer_data): array
    {
        $matches = $answer_data['matches'] ?? [];
        if (empty($matches)) {
            return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
        }

        $total_pairs = count($question['options']);
        $correct_count = 0;

        foreach ($question['options'] as $opt) {
            $user_match = $matches[$opt['option_id']] ?? null;
            if ($user_match && strtolower(trim($user_match)) === strtolower(trim($opt['match_text']))) {
                $correct_count++;
            }
        }

        if ($correct_count == $total_pairs) {
            return ['is_correct' => true, 'is_partial' => false, 'points_earned' => $question['points'], 'auto_graded' => true];
        }

        if ($question['partial_credit'] && $correct_count > 0) {
            $ratio = $correct_count / $total_pairs;
            return ['is_correct' => false, 'is_partial' => true, 'points_earned' => $question['points'] * $ratio, 'auto_graded' => true];
        }

        return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
    }

    /**
     * Grade Ordering
     */
    private function gradeOrdering(array $question, array $answer_data): array
    {
        $order = $answer_data['order'] ?? [];
        if (empty($order)) {
            return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
        }

        // Build correct order
        $correct_order = [];
        foreach ($question['options'] as $opt) {
            $correct_order[$opt['correct_position']] = $opt['option_id'];
        }
        ksort($correct_order);
        $correct_order = array_values($correct_order);

        if ($order === $correct_order) {
            return ['is_correct' => true, 'is_partial' => false, 'points_earned' => $question['points'], 'auto_graded' => true];
        }

        if ($question['partial_credit']) {
            $correct_count = 0;
            foreach ($order as $i => $id) {
                if (isset($correct_order[$i]) && $correct_order[$i] == $id) {
                    $correct_count++;
                }
            }
            if ($correct_count > 0) {
                $ratio = $correct_count / count($correct_order);
                return ['is_correct' => false, 'is_partial' => true, 'points_earned' => $question['points'] * $ratio, 'auto_graded' => true];
            }
        }

        return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
    }

    /**
     * Grade Hotspot
     */
    private function gradeHotspot(array $question, array $answer_data): array
    {
        $clicked_coords = $answer_data['clicked_coords'] ?? null;
        if (!$clicked_coords) {
            return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
        }

        foreach ($question['options'] as $opt) {
            if ($opt['is_correct'] && $opt['hotspot_coords']) {
                $coords = $opt['hotspot_coords'];
                $in_area = false;

                switch ($question['hotspot_shape']) {
                    case 'rectangle':
                        $in_area = $clicked_coords['x'] >= $coords['x1'] && $clicked_coords['x'] <= $coords['x2']
                            && $clicked_coords['y'] >= $coords['y1'] && $clicked_coords['y'] <= $coords['y2'];
                        break;

                    case 'circle':
                        $distance = sqrt(pow($clicked_coords['x'] - $coords['cx'], 2) + pow($clicked_coords['y'] - $coords['cy'], 2));
                        $in_area = $distance <= $coords['radius'];
                        break;

                    case 'polygon':
                        $in_area = $this->pointInPolygon($clicked_coords, $coords['points']);
                        break;
                }

                if ($in_area) {
                    return ['is_correct' => true, 'is_partial' => false, 'points_earned' => $question['points'], 'auto_graded' => true];
                }
            }
        }

        return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
    }

    /**
     * Grade Numeric
     */
    private function gradeNumeric(array $question, array $answer_data): array
    {
        $value = $answer_data['value'] ?? null;
        if ($value === null) {
            return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
        }

        foreach ($question['options'] as $opt) {
            if ($opt['is_correct']) {
                $correct_value = floatval($opt['option_text']);
                $tolerance = $question['numeric_tolerance'] ?? 0;

                if (abs($value - $correct_value) <= $tolerance) {
                    return ['is_correct' => true, 'is_partial' => false, 'points_earned' => $question['points'], 'auto_graded' => true];
                }
            }
        }

        return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
    }

    /**
     * Check if point is inside polygon (ray casting algorithm)
     */
    private function pointInPolygon(array $point, array $polygon): bool
    {
        $n = count($polygon);
        $inside = false;
        $j = $n - 1;

        for ($i = 0; $i < $n; $j = $i++) {
            $xi = $polygon[$i]['x'];
            $yi = $polygon[$i]['y'];
            $xj = $polygon[$j]['x'];
            $yj = $polygon[$j]['y'];

            if ((($yi > $point['y']) != ($yj > $point['y'])) &&
                ($point['x'] < ($xj - $xi) * ($point['y'] - $yi) / ($yj - $yi) + $xi)) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    // ==========================================
    // ASSESSMENT CRUD OPERATIONS
    // ==========================================

    /**
     * Create a new assessment
     *
     * @param array $data Assessment data
     * @return array Created assessment
     */
    public function createAssessment(array $data): array
    {
        // Generate unique code
        $code = 'ASM-' . strtoupper(substr(md5(uniqid()), 0, 6));

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
            issue_certificate, certificate_template_id, certificate_expiry_days,
            proctoring_level, auto_publish_results, access_type, access_code,
            notify_on_submission, status, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);

        // Prepare all values
        $title = $data['title'];
        $description = $data['description'] ?? null;
        $instructions = $data['instructions'] ?? null;
        $thumbnail = $data['thumbnail'] ?? null;
        $course_id = $data['course_id'] ?? null;
        $live_training_id = $data['live_training_id'] ?? null;
        $is_standalone = $data['is_standalone'] ?? 1;
        $is_prerequisite = $data['is_prerequisite'] ?? 0;
        $is_final_assessment = $data['is_final_assessment'] ?? 0;
        $time_limit_minutes = $data['time_limit_minutes'] ?? null;
        $time_limit_per_question_seconds = $data['time_limit_per_question_seconds'] ?? null;
        $has_time_limit = $data['has_time_limit'] ?? 1;
        $auto_submit_on_timeout = $data['auto_submit_on_timeout'] ?? 1;
        $available_from = $data['available_from'] ?? null;
        $available_until = $data['available_until'] ?? null;
        $grace_period_minutes = $data['grace_period_minutes'] ?? 5;
        $timezone = $data['timezone'] ?? 'UTC';
        $max_attempts = $data['max_attempts'] ?? 1;
        $attempts_gap_hours = $data['attempts_gap_hours'] ?? 0;
        $passing_score = $data['passing_score'] ?? 70;
        $passing_type = $data['passing_type'] ?? 'percentage';
        $show_score_immediately = $data['show_score_immediately'] ?? 1;
        $score_display = $data['score_display'] ?? 'both';
        $enable_grading = $data['enable_grading'] ?? 0;
        $grade_boundaries = isset($data['grade_boundaries']) ? json_encode($data['grade_boundaries']) : null;
        $question_selection = $data['question_selection'] ?? 'manual';
        $random_question_count = $data['random_question_count'] ?? null;
        $shuffle_questions = $data['shuffle_questions'] ?? 1;
        $shuffle_options = $data['shuffle_options'] ?? 1;
        $questions_per_page = $data['questions_per_page'] ?? 'one';
        $allow_backward_navigation = $data['allow_backward_navigation'] ?? 1;
        $show_question_numbers = $data['show_question_numbers'] ?? 1;
        $show_progress_bar = $data['show_progress_bar'] ?? 1;
        $show_timer = $data['show_timer'] ?? 1;
        $allow_mark_for_review = $data['allow_mark_for_review'] ?? 1;
        $show_correct_answers = $data['show_correct_answers'] ?? 'after_all_attempts';
        $show_explanations = $data['show_explanations'] ?? 1;
        $show_score_breakdown = $data['show_score_breakdown'] ?? 1;
        $allow_review_attempt = $data['allow_review_attempt'] ?? 1;
        $issue_certificate = $data['issue_certificate'] ?? 0;
        $certificate_template_id = $data['certificate_template_id'] ?? null;
        $certificate_expiry_days = $data['certificate_expiry_days'] ?? null;
        $proctoring_level = $data['proctoring_level'] ?? 'none';
        $auto_publish_results = $data['auto_publish_results'] ?? 1;
        $access_type = $data['access_type'] ?? 'assigned';
        $access_code = $data['access_code'] ?? null;
        $notify_on_submission = $data['notify_on_submission'] ?? 1;
        $status = 'draft';

        $stmt->bind_param(
            "isssssiiiiiiiiisssiidsisssiiiisiiiiisiiiiiiisisssi",
            $this->tenant_id,
            $title,
            $code,
            $description,
            $instructions,
            $thumbnail,
            $course_id,
            $live_training_id,
            $is_standalone,
            $is_prerequisite,
            $is_final_assessment,
            $time_limit_minutes,
            $time_limit_per_question_seconds,
            $has_time_limit,
            $auto_submit_on_timeout,
            $available_from,
            $available_until,
            $grace_period_minutes,
            $timezone,
            $max_attempts,
            $attempts_gap_hours,
            $passing_score,
            $passing_type,
            $show_score_immediately,
            $score_display,
            $enable_grading,
            $grade_boundaries,
            $question_selection,
            $random_question_count,
            $shuffle_questions,
            $shuffle_options,
            $questions_per_page,
            $allow_backward_navigation,
            $show_question_numbers,
            $show_progress_bar,
            $show_timer,
            $allow_mark_for_review,
            $show_correct_answers,
            $show_explanations,
            $show_score_breakdown,
            $allow_review_attempt,
            $issue_certificate,
            $certificate_template_id,
            $certificate_expiry_days,
            $proctoring_level,
            $auto_publish_results,
            $access_type,
            $access_code,
            $notify_on_submission,
            $status,
            $this->user_id
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to create assessment: " . $stmt->error);
        }

        $assessment_id = $this->conn->insert_id;
        $stmt->close();

        // Create proctor settings if proctoring enabled
        if ($proctoring_level !== 'none') {
            $this->createProctorSettings($assessment_id, $proctoring_level);
        }

        return $this->getAssessment($assessment_id);
    }

    /**
     * Get single assessment with details
     *
     * @param int $assessment_id Assessment ID
     * @return array|null Assessment data
     */
    public function getAssessment(int $assessment_id): ?array
    {
        $sql = "SELECT a.*,
                u.first_name as creator_first_name,
                u.last_name as creator_last_name,
                c.title as course_title,
                lt.title as training_title,
                (SELECT COUNT(*) FROM proc_assessment_questions WHERE assessment_id = a.assessment_id) as question_count
            FROM proc_assessments a
            LEFT JOIN users u ON a.created_by = u.id
            LEFT JOIN courses c ON a.course_id = c.id
            LEFT JOIN live_trainings lt ON a.live_training_id = lt.training_id
            WHERE a.assessment_id = ? AND a.tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $assessment_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $assessment = $result->fetch_assoc();
        $stmt->close();

        if ($assessment) {
            $assessment['grade_boundaries'] = $assessment['grade_boundaries']
                ? json_decode($assessment['grade_boundaries'], true) : null;
            $assessment['sections'] = $this->getAssessmentSections($assessment_id);
            $assessment['proctor_settings'] = $this->getProctorSettings($assessment_id);
        }

        return $assessment;
    }

    /**
     * Get assessments with filters
     *
     * @param array $filters Filter options
     * @return array Assessments with pagination
     */
    public function getAssessments(array $filters = []): array
    {
        $page = (int)($filters['page'] ?? 1);
        $limit = (int)($filters['limit'] ?? 20);
        $offset = ($page - 1) * $limit;

        $where = ["a.tenant_id = ?"];
        $params = [$this->tenant_id];
        $types = "i";

        if (!empty($filters['status'])) {
            $where[] = "a.status = ?";
            $params[] = $filters['status'];
            $types .= "s";
        }

        if (!empty($filters['course_id'])) {
            $where[] = "a.course_id = ?";
            $params[] = (int)$filters['course_id'];
            $types .= "i";
        }

        if (!empty($filters['live_training_id'])) {
            $where[] = "a.live_training_id = ?";
            $params[] = (int)$filters['live_training_id'];
            $types .= "i";
        }

        if (isset($filters['is_standalone'])) {
            $where[] = "a.is_standalone = ?";
            $params[] = (int)$filters['is_standalone'];
            $types .= "i";
        }

        if (!empty($filters['proctoring_level'])) {
            $where[] = "a.proctoring_level = ?";
            $params[] = $filters['proctoring_level'];
            $types .= "s";
        }

        if (!empty($filters['search'])) {
            $where[] = "(a.title LIKE ? OR a.description LIKE ? OR a.code LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $types .= "sss";
        }

        $whereClause = implode(' AND ', $where);

        // Count
        $countSql = "SELECT COUNT(*) as total FROM proc_assessments a WHERE $whereClause";
        $stmt = $this->conn->prepare($countSql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $total = $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        // Get assessments
        $sql = "SELECT a.*,
                u.first_name as creator_first_name,
                u.last_name as creator_last_name,
                (SELECT COUNT(*) FROM proc_assessment_questions WHERE assessment_id = a.assessment_id) as question_count
            FROM proc_assessments a
            LEFT JOIN users u ON a.created_by = u.id
            WHERE $whereClause
            ORDER BY a.created_at DESC
            LIMIT $limit OFFSET $offset";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $assessments = [];
        while ($row = $result->fetch_assoc()) {
            $assessments[] = $row;
        }
        $stmt->close();

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

    /**
     * Update an assessment
     *
     * @param int $assessment_id Assessment ID
     * @param array $data Update data
     * @return array Updated assessment
     */
    public function updateAssessment(int $assessment_id, array $data): array
    {
        $fields = [];
        $params = [];
        $types = "";

        $allowedFields = [
            'title' => 's',
            'description' => 's',
            'instructions' => 's',
            'thumbnail' => 's',
            'course_id' => 'i',
            'live_training_id' => 'i',
            'is_standalone' => 'i',
            'is_prerequisite' => 'i',
            'is_final_assessment' => 'i',
            'time_limit_minutes' => 'i',
            'time_limit_per_question_seconds' => 'i',
            'has_time_limit' => 'i',
            'auto_submit_on_timeout' => 'i',
            'available_from' => 's',
            'available_until' => 's',
            'grace_period_minutes' => 'i',
            'timezone' => 's',
            'max_attempts' => 'i',
            'attempts_gap_hours' => 'i',
            'passing_score' => 'd',
            'passing_type' => 's',
            'show_score_immediately' => 'i',
            'score_display' => 's',
            'enable_grading' => 'i',
            'question_selection' => 's',
            'random_question_count' => 'i',
            'shuffle_questions' => 'i',
            'shuffle_options' => 'i',
            'questions_per_page' => 's',
            'allow_backward_navigation' => 'i',
            'show_question_numbers' => 'i',
            'show_progress_bar' => 'i',
            'show_timer' => 'i',
            'allow_mark_for_review' => 'i',
            'show_correct_answers' => 's',
            'show_explanations' => 'i',
            'show_score_breakdown' => 'i',
            'allow_review_attempt' => 'i',
            'issue_certificate' => 'i',
            'certificate_template_id' => 'i',
            'certificate_expiry_days' => 'i',
            'proctoring_level' => 's',
            'auto_publish_results' => 'i',
            'access_type' => 's',
            'access_code' => 's',
            'notify_on_submission' => 'i',
            'status' => 's'
        ];

        foreach ($allowedFields as $field => $type) {
            if (isset($data[$field])) {
                $fields[] = "$field = ?";
                $params[] = $data[$field];
                $types .= $type;
            }
        }

        if (isset($data['grade_boundaries'])) {
            $fields[] = "grade_boundaries = ?";
            $params[] = json_encode($data['grade_boundaries']);
            $types .= "s";
        }

        $fields[] = "updated_by = ?";
        $params[] = $this->user_id;
        $types .= "i";

        $params[] = $assessment_id;
        $params[] = $this->tenant_id;
        $types .= "ii";

        $sql = "UPDATE proc_assessments SET " . implode(", ", $fields) .
            " WHERE assessment_id = ? AND tenant_id = ?";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);

        if (!$stmt->execute()) {
            throw new Exception("Failed to update assessment: " . $stmt->error);
        }
        $stmt->close();

        // Update published_at if status changed to published
        if (isset($data['status']) && $data['status'] === 'published') {
            $this->conn->query("UPDATE proc_assessments SET published_at = NOW()
                WHERE assessment_id = $assessment_id AND published_at IS NULL");
        }

        return $this->getAssessment($assessment_id);
    }

    /**
     * Delete assessment (soft delete)
     *
     * @param int $assessment_id Assessment ID
     * @return bool Success
     */
    public function deleteAssessment(int $assessment_id): bool
    {
        $sql = "UPDATE proc_assessments SET status = 'archived' WHERE assessment_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $assessment_id, $this->tenant_id);
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    /**
     * Publish assessment
     *
     * @param int $assessment_id Assessment ID
     * @return array Published assessment
     */
    public function publishAssessment(int $assessment_id): array
    {
        // Validate before publishing
        $assessment = $this->getAssessment($assessment_id);
        if (!$assessment) {
            throw new Exception("Assessment not found");
        }

        if ($assessment['question_count'] == 0) {
            throw new Exception("Assessment must have at least one question");
        }

        // Calculate total points
        $sql = "SELECT COALESCE(SUM(COALESCE(aq.points_override, q.points)), 0) as total_points
            FROM proc_assessment_questions aq
            INNER JOIN proc_questions q ON aq.question_id = q.question_id
            WHERE aq.assessment_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $assessment_id);
        $stmt->execute();
        $total_points = $stmt->get_result()->fetch_assoc()['total_points'];
        $stmt->close();

        // Update status
        $sql = "UPDATE proc_assessments SET status = 'published', published_at = NOW(), total_points = ?
            WHERE assessment_id = ? AND tenant_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("dii", $total_points, $assessment_id, $this->tenant_id);
        $stmt->execute();
        $stmt->close();

        return $this->getAssessment($assessment_id);
    }

    // ==========================================
    // ASSESSMENT SECTIONS
    // ==========================================

    /**
     * Get assessment sections
     *
     * @param int $assessment_id Assessment ID
     * @return array Sections
     */
    public function getAssessmentSections(int $assessment_id): array
    {
        $sql = "SELECT * FROM proc_assessment_sections WHERE assessment_id = ? ORDER BY sort_order";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $assessment_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $sections = [];
        while ($row = $result->fetch_assoc()) {
            $sections[] = $row;
        }
        $stmt->close();

        return $sections;
    }

    /**
     * Create assessment section
     *
     * @param int $assessment_id Assessment ID
     * @param array $data Section data
     * @return array Created section
     */
    public function createAssessmentSection(int $assessment_id, array $data): array
    {
        $sql = "INSERT INTO proc_assessment_sections (
            assessment_id, title, description, instructions, time_limit_minutes,
            sort_order, selection_type, random_count
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);

        $title = $data['title'];
        $description = $data['description'] ?? null;
        $instructions = $data['instructions'] ?? null;
        $time_limit_minutes = $data['time_limit_minutes'] ?? null;
        $sort_order = $data['sort_order'] ?? 0;
        $selection_type = $data['selection_type'] ?? 'all';
        $random_count = $data['random_count'] ?? null;

        $stmt->bind_param(
            "isssisi",
            $assessment_id,
            $title,
            $description,
            $instructions,
            $time_limit_minutes,
            $sort_order,
            $selection_type,
            $random_count
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to create section: " . $stmt->error);
        }

        $section_id = $this->conn->insert_id;
        $stmt->close();

        // Return created section
        $sql = "SELECT * FROM proc_assessment_sections WHERE section_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $section_id);
        $stmt->execute();
        $section = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $section;
    }

    // ==========================================
    // ASSESSMENT QUESTIONS
    // ==========================================

    /**
     * Get questions assigned to assessment
     *
     * @param int $assessment_id Assessment ID
     * @return array Questions
     */
    public function getAssessmentQuestions(int $assessment_id): array
    {
        $sql = "SELECT aq.*, q.question_type, q.question_text, q.question_html,
                q.points, q.difficulty, q.estimated_time_seconds,
                s.title as section_title
            FROM proc_assessment_questions aq
            INNER JOIN proc_questions q ON aq.question_id = q.question_id
            LEFT JOIN proc_assessment_sections s ON aq.section_id = s.section_id
            WHERE aq.assessment_id = ?
            ORDER BY aq.sort_order";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $assessment_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $questions = [];
        while ($row = $result->fetch_assoc()) {
            $questions[] = $row;
        }
        $stmt->close();

        return $questions;
    }

    /**
     * Add questions to assessment
     *
     * @param int $assessment_id Assessment ID
     * @param array $questions Questions to add
     * @return int Number added
     */
    public function addAssessmentQuestions(int $assessment_id, array $questions): int
    {
        $sql = "INSERT INTO proc_assessment_questions (
            assessment_id, section_id, question_id, points_override,
            time_limit_override, required, sort_order
        ) VALUES (?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);

        // Get current max sort order
        $max_order_sql = "SELECT MAX(sort_order) as max_order FROM proc_assessment_questions WHERE assessment_id = ?";
        $max_stmt = $this->conn->prepare($max_order_sql);
        $max_stmt->bind_param("i", $assessment_id);
        $max_stmt->execute();
        $max_order = $max_stmt->get_result()->fetch_assoc()['max_order'] ?? -1;
        $max_stmt->close();

        $count = 0;
        foreach ($questions as $q) {
            $max_order++;
            $section_id = $q['section_id'] ?? null;
            $question_id = $q['question_id'];
            $points_override = $q['points_override'] ?? null;
            $time_limit_override = $q['time_limit_override'] ?? null;
            $required = $q['required'] ?? 0;
            $sort_order = $q['sort_order'] ?? $max_order;

            $stmt->bind_param(
                "iiidiii",
                $assessment_id,
                $section_id,
                $question_id,
                $points_override,
                $time_limit_override,
                $required,
                $sort_order
            );

            if ($stmt->execute()) {
                $count++;
            }
        }
        $stmt->close();

        $this->recalculateTotalPoints($assessment_id);

        return $count;
    }

    /**
     * Remove question from assessment
     *
     * @param int $assessment_id Assessment ID
     * @param int $question_id Question ID
     * @return bool Success
     */
    public function removeAssessmentQuestion(int $assessment_id, int $question_id): bool
    {
        $sql = "DELETE FROM proc_assessment_questions WHERE assessment_id = ? AND question_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $assessment_id, $question_id);
        $result = $stmt->execute();
        $stmt->close();

        if ($result) {
            $this->recalculateTotalPoints($assessment_id);
        }

        return $result;
    }

    /**
     * Recalculate assessment total points
     *
     * @param int $assessment_id Assessment ID
     */
    private function recalculateTotalPoints(int $assessment_id): void
    {
        $sql = "UPDATE proc_assessments SET total_points = (
            SELECT COALESCE(SUM(COALESCE(aq.points_override, q.points)), 0)
            FROM proc_assessment_questions aq
            INNER JOIN proc_questions q ON aq.question_id = q.question_id
            WHERE aq.assessment_id = ?
        ) WHERE assessment_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $assessment_id, $assessment_id);
        $stmt->execute();
        $stmt->close();
    }

    // ==========================================
    // PROCTOR SETTINGS
    // ==========================================

    /**
     * Get proctor settings for assessment
     *
     * @param int $assessment_id Assessment ID
     * @return array|null Proctor settings
     */
    public function getProctorSettings(int $assessment_id): ?array
    {
        $sql = "SELECT * FROM proc_proctor_settings WHERE assessment_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $assessment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $settings = $result->fetch_assoc();
        $stmt->close();

        return $settings;
    }

    /**
     * Create proctor settings with level-based defaults
     *
     * @param int $assessment_id Assessment ID
     * @param string $level Proctoring level
     */
    private function createProctorSettings(int $assessment_id, string $level): void
    {
        $defaults = $this->getProctorDefaults($level);

        $sql = "INSERT INTO proc_proctor_settings (
            assessment_id, require_system_check, require_webcam, require_microphone,
            require_id_verification, require_face_registration, require_environment_scan,
            require_terms_acceptance, enable_webcam_monitoring, snapshot_interval_seconds,
            detect_face_absence, detect_multiple_faces, detect_face_mismatch,
            enable_fullscreen_mode, detect_tab_switch, detect_browser_focus_loss,
            detect_copy_paste, disable_right_click, detect_screenshot, detect_dev_tools,
            enable_screen_recording, enable_ai_detection, warning_threshold, termination_threshold
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "iiiiiiiiiiiiiiiiiiiiiiii",
            $assessment_id,
            $defaults['require_system_check'],
            $defaults['require_webcam'],
            $defaults['require_microphone'],
            $defaults['require_id_verification'],
            $defaults['require_face_registration'],
            $defaults['require_environment_scan'],
            $defaults['require_terms_acceptance'],
            $defaults['enable_webcam_monitoring'],
            $defaults['snapshot_interval_seconds'],
            $defaults['detect_face_absence'],
            $defaults['detect_multiple_faces'],
            $defaults['detect_face_mismatch'],
            $defaults['enable_fullscreen_mode'],
            $defaults['detect_tab_switch'],
            $defaults['detect_browser_focus_loss'],
            $defaults['detect_copy_paste'],
            $defaults['disable_right_click'],
            $defaults['detect_screenshot'],
            $defaults['detect_dev_tools'],
            $defaults['enable_screen_recording'],
            $defaults['enable_ai_detection'],
            $defaults['warning_threshold'],
            $defaults['termination_threshold']
        );

        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get default proctor settings by level
     *
     * @param string $level Proctoring level
     * @return array Settings
     */
    private function getProctorDefaults(string $level): array
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
            'detect_screenshot' => 1, 'enable_ai_detection' => 1,
            'warning_threshold' => 3, 'termination_threshold' => 5
        ]);

        $advanced = array_merge($standard, [
            'require_microphone' => 1, 'require_id_verification' => 1, 'require_environment_scan' => 1,
            'snapshot_interval_seconds' => 15, 'detect_face_mismatch' => 1, 'enable_screen_recording' => 1,
            'warning_threshold' => 2, 'termination_threshold' => 3
        ]);

        switch ($level) {
            case 'advanced':
                return $advanced;
            case 'standard':
                return $standard;
            default:
                return $basic;
        }
    }

    // ==========================================
    // LEARNER ASSESSMENTS
    // ==========================================

    /**
     * Get assessments assigned to current user
     *
     * @param array $filters Optional filters
     * @return array Assessments
     */
    public function getMyAssessments(array $filters = []): array
    {
        $sql = "SELECT DISTINCT a.*,
                (SELECT COUNT(*) FROM proc_assessment_attempts WHERE assessment_id = a.assessment_id AND user_id = ?) as my_attempts,
                (SELECT MAX(percentage_score) FROM proc_assessment_attempts WHERE assessment_id = a.assessment_id AND user_id = ? AND status = 'graded') as best_score,
                (SELECT status FROM proc_assessment_attempts WHERE assessment_id = a.assessment_id AND user_id = ? ORDER BY created_at DESC LIMIT 1) as last_attempt_status
            FROM proc_assessments a
            LEFT JOIN proc_assessment_assignments aa ON a.assessment_id = aa.assessment_id
            WHERE a.tenant_id = ?
            AND a.status = 'published'
            AND (
                a.access_type = 'public'
                OR aa.user_id = ?
                OR aa.group_id IN (SELECT group_id FROM user_groups WHERE user_id = ?)
                OR aa.department_id IN (SELECT department_id FROM users WHERE id = ?)
            )";

        $params = [
            $this->user_id,
            $this->user_id,
            $this->user_id,
            $this->tenant_id,
            $this->user_id,
            $this->user_id,
            $this->user_id
        ];
        $types = "iiiiiii";

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'completed') {
                $sql .= " AND EXISTS (SELECT 1 FROM proc_assessment_attempts WHERE assessment_id = a.assessment_id AND user_id = ? AND status = 'graded')";
                $params[] = $this->user_id;
                $types .= "i";
            } elseif ($filters['status'] === 'in_progress') {
                $sql .= " AND EXISTS (SELECT 1 FROM proc_assessment_attempts WHERE assessment_id = a.assessment_id AND user_id = ? AND status = 'in_progress')";
                $params[] = $this->user_id;
                $types .= "i";
            } elseif ($filters['status'] === 'not_started') {
                $sql .= " AND NOT EXISTS (SELECT 1 FROM proc_assessment_attempts WHERE assessment_id = a.assessment_id AND user_id = ?)";
                $params[] = $this->user_id;
                $types .= "i";
            }
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (a.title LIKE ? OR a.description LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $types .= "ss";
        }

        $sql .= " ORDER BY a.available_until ASC, a.title ASC";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $assessments = [];
        while ($row = $result->fetch_assoc()) {
            // Check if can attempt
            $row['can_attempt'] = $this->canAttempt($row);
            $row['attempts_remaining'] = max(0, $row['max_attempts'] - $row['my_attempts']);
            $assessments[] = $row;
        }
        $stmt->close();

        return $assessments;
    }

    /**
     * Check if user can start a new attempt
     *
     * @param array $assessment Assessment data
     * @return bool Can attempt
     */
    private function canAttempt(array $assessment): bool
    {
        // Check attempts
        if ($assessment['my_attempts'] >= $assessment['max_attempts']) {
            return false;
        }

        // Check availability dates
        $now = time();
        if ($assessment['available_from'] && strtotime($assessment['available_from']) > $now) {
            return false;
        }
        if ($assessment['available_until'] && strtotime($assessment['available_until']) < $now) {
            return false;
        }

        // Check attempt gap
        if ($assessment['attempts_gap_hours'] > 0 && $assessment['my_attempts'] > 0) {
            $sql = "SELECT MAX(created_at) as last_attempt FROM proc_assessment_attempts
                WHERE assessment_id = ? AND user_id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ii", $assessment['assessment_id'], $this->user_id);
            $stmt->execute();
            $last = $stmt->get_result()->fetch_assoc()['last_attempt'];
            $stmt->close();

            if ($last) {
                $gap_seconds = $assessment['attempts_gap_hours'] * 3600;
                if (strtotime($last) + $gap_seconds > $now) {
                    return false;
                }
            }
        }

        return true;
    }

    // ==========================================
    // ATTEMPT MANAGEMENT
    // ==========================================

    /**
     * Start a new assessment attempt
     *
     * @param int $assessment_id Assessment ID
     * @return array Attempt data with questions
     */
    public function startAttempt(int $assessment_id): array
    {
        $assessment = $this->getAssessment($assessment_id);
        if (!$assessment) {
            throw new Exception("Assessment not found");
        }

        // Check if can attempt
        $assessment['my_attempts'] = $this->getUserAttemptCount($assessment_id);
        if (!$this->canAttempt($assessment)) {
            throw new Exception("Cannot start new attempt");
        }

        // Generate unique attempt code
        $attempt_code = 'ATT-' . strtoupper(substr(md5(uniqid()), 0, 8));

        // Calculate end time
        $end_time = null;
        if ($assessment['has_time_limit'] && $assessment['time_limit_minutes']) {
            $end_time = date('Y-m-d H:i:s', time() + ($assessment['time_limit_minutes'] * 60));
        }

        // Create attempt
        $sql = "INSERT INTO proc_assessment_attempts (
            tenant_id, assessment_id, user_id, attempt_code, attempt_number,
            started_at, scheduled_end_time, status, ip_address, user_agent
        ) VALUES (?, ?, ?, ?, ?, NOW(), ?, 'in_progress', ?, ?)";

        $stmt = $this->conn->prepare($sql);

        $attempt_number = $assessment['my_attempts'] + 1;
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;

        $stmt->bind_param(
            "iiisisss",
            $this->tenant_id,
            $assessment_id,
            $this->user_id,
            $attempt_code,
            $attempt_number,
            $end_time,
            $ip_address,
            $user_agent
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to start attempt: " . $stmt->error);
        }

        $attempt_id = $this->conn->insert_id;
        $stmt->close();

        // Get questions for attempt
        $questions = $this->prepareQuestionsForAttempt($assessment);

        // Create empty answer records
        $this->createAttemptAnswers($attempt_id, $questions);

        // Log timeline event
        $this->logTimelineEvent($attempt_id, 'attempt_started', ['attempt_number' => $attempt_number]);

        return [
            'attempt_id' => $attempt_id,
            'attempt_code' => $attempt_code,
            'attempt_number' => $attempt_number,
            'started_at' => date('Y-m-d H:i:s'),
            'scheduled_end_time' => $end_time,
            'time_limit_minutes' => $assessment['time_limit_minutes'],
            'questions' => $questions,
            'total_questions' => count($questions),
            'total_points' => $assessment['total_points']
        ];
    }

    /**
     * Get user's attempt count for assessment
     *
     * @param int $assessment_id Assessment ID
     * @return int Attempt count
     */
    private function getUserAttemptCount(int $assessment_id): int
    {
        $sql = "SELECT COUNT(*) as count FROM proc_assessment_attempts
            WHERE assessment_id = ? AND user_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $assessment_id, $this->user_id);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();

        return (int)$count;
    }

    /**
     * Prepare questions for attempt (handles random selection, shuffling)
     *
     * @param array $assessment Assessment data
     * @return array Prepared questions
     */
    private function prepareQuestionsForAttempt(array $assessment): array
    {
        $questions = $this->getAssessmentQuestions($assessment['assessment_id']);

        // Shuffle questions if enabled
        if ($assessment['shuffle_questions']) {
            shuffle($questions);
        }

        // Prepare each question (remove correct answers, shuffle options)
        foreach ($questions as &$q) {
            $q['options'] = $this->getQuestionOptions($q['question_id']);

            // Remove correct answer indicators
            foreach ($q['options'] as &$opt) {
                unset($opt['is_correct']);
                unset($opt['feedback']);
                unset($opt['acceptable_answers']);
            }

            // Shuffle options if enabled
            if ($assessment['shuffle_options']) {
                shuffle($q['options']);
            }

            // Remove hint if not allowed
            if (empty($assessment['show_hints'])) {
                unset($q['hint']);
            }
        }

        return $questions;
    }

    /**
     * Create empty answer records for attempt
     *
     * @param int $attempt_id Attempt ID
     * @param array $questions Questions
     */
    private function createAttemptAnswers(int $attempt_id, array $questions): void
    {
        $sql = "INSERT INTO proc_attempt_answers (attempt_id, question_id, sort_order) VALUES (?, ?, ?)";
        $stmt = $this->conn->prepare($sql);

        foreach ($questions as $i => $q) {
            $stmt->bind_param("iii", $attempt_id, $q['question_id'], $i);
            $stmt->execute();
        }
        $stmt->close();
    }

    /**
     * Save answer for a question
     *
     * @param int $attempt_id Attempt ID
     * @param int $question_id Question ID
     * @param array $answer_data Answer data
     * @return array Grading result
     */
    public function saveAnswer(int $attempt_id, int $question_id, array $answer_data): array
    {
        // Verify attempt belongs to user and is in progress
        $attempt = $this->getAttemptForUser($attempt_id);
        if (!$attempt || $attempt['status'] !== 'in_progress') {
            throw new Exception("Invalid attempt");
        }

        // Check time limit
        if ($attempt['scheduled_end_time'] && strtotime($attempt['scheduled_end_time']) < time()) {
            throw new Exception("Time limit exceeded");
        }

        // Grade the answer
        $grading_result = $this->gradeAnswer($question_id, $answer_data);

        // Update answer record
        $sql = "UPDATE proc_attempt_answers SET
            answer_text = ?,
            answer_data = ?,
            is_correct = ?,
            is_partial = ?,
            points_earned = ?,
            grading_status = ?,
            answered_at = NOW()
            WHERE attempt_id = ? AND question_id = ?";

        $stmt = $this->conn->prepare($sql);

        $answer_text = $answer_data['text'] ?? null;
        $answer_data_json = json_encode($answer_data);
        $is_correct = $grading_result['is_correct'] === true ? 1 : ($grading_result['is_correct'] === false ? 0 : null);
        $is_partial = $grading_result['is_partial'] ? 1 : 0;
        $points_earned = $grading_result['points_earned'];
        $grading_status = $grading_result['auto_graded'] ? 'graded' : 'pending';

        $stmt->bind_param(
            "ssiiisii",
            $answer_text,
            $answer_data_json,
            $is_correct,
            $is_partial,
            $points_earned,
            $grading_status,
            $attempt_id,
            $question_id
        );

        $stmt->execute();
        $stmt->close();

        // Update attempt progress
        $this->updateAttemptProgress($attempt_id);

        // Log timeline event
        $this->logTimelineEvent($attempt_id, 'answer_saved', ['question_id' => $question_id]);

        return $grading_result;
    }

    /**
     * Get attempt for current user
     *
     * @param int $attempt_id Attempt ID
     * @return array|null Attempt data
     */
    private function getAttemptForUser(int $attempt_id): ?array
    {
        $sql = "SELECT * FROM proc_assessment_attempts WHERE attempt_id = ? AND user_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $attempt_id, $this->user_id);
        $stmt->execute();
        $attempt = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $attempt;
    }

    /**
     * Update attempt progress
     *
     * @param int $attempt_id Attempt ID
     */
    private function updateAttemptProgress(int $attempt_id): void
    {
        $sql = "UPDATE proc_assessment_attempts SET
            questions_answered = (SELECT COUNT(*) FROM proc_attempt_answers WHERE attempt_id = ? AND answered_at IS NOT NULL),
            questions_correct = (SELECT COUNT(*) FROM proc_attempt_answers WHERE attempt_id = ? AND is_correct = 1),
            questions_incorrect = (SELECT COUNT(*) FROM proc_attempt_answers WHERE attempt_id = ? AND is_correct = 0),
            questions_partial = (SELECT COUNT(*) FROM proc_attempt_answers WHERE attempt_id = ? AND is_partial = 1),
            last_activity_at = NOW()
            WHERE attempt_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiiii", $attempt_id, $attempt_id, $attempt_id, $attempt_id, $attempt_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Submit attempt for grading
     *
     * @param int $attempt_id Attempt ID
     * @return array Results
     */
    public function submitAttempt(int $attempt_id): array
    {
        $attempt = $this->getAttemptForUser($attempt_id);
        if (!$attempt || $attempt['status'] !== 'in_progress') {
            throw new Exception("Invalid attempt");
        }

        // Calculate final scores
        $scores = $this->calculateFinalScores($attempt_id);

        // Get assessment for passing criteria
        $assessment = $this->getAssessment($attempt['assessment_id']);

        // Determine pass/fail
        $passed = false;
        if ($assessment['passing_type'] === 'percentage') {
            $passed = $scores['percentage_score'] >= $assessment['passing_score'];
        } else {
            $passed = $scores['raw_score'] >= $assessment['passing_score'];
        }

        // Determine grade if grading enabled
        $grade = null;
        if ($assessment['enable_grading'] && $assessment['grade_boundaries']) {
            $grade = $this->calculateGrade($scores['percentage_score'], $assessment['grade_boundaries']);
        }

        // Check if manual grading needed
        $has_pending = $scores['questions_pending'] > 0;
        $status = $has_pending ? 'grading' : 'graded';

        // Update attempt
        $sql = "UPDATE proc_assessment_attempts SET
            status = ?,
            submitted_at = NOW(),
            raw_score = ?,
            percentage_score = ?,
            passed = ?,
            grade = ?,
            questions_correct = ?,
            questions_incorrect = ?,
            questions_partial = ?,
            questions_skipped = ?,
            questions_pending = ?
            WHERE attempt_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "sddieiiiiii",
            $status,
            $scores['raw_score'],
            $scores['percentage_score'],
            $passed,
            $grade,
            $scores['questions_correct'],
            $scores['questions_incorrect'],
            $scores['questions_partial'],
            $scores['questions_skipped'],
            $scores['questions_pending'],
            $attempt_id
        );
        $stmt->execute();
        $stmt->close();

        // Log timeline event
        $this->logTimelineEvent($attempt_id, 'attempt_submitted', $scores);

        // Update assessment stats
        $this->updateAssessmentStats($attempt['assessment_id']);

        // Issue certificate if applicable
        if ($passed && $assessment['issue_certificate'] && !$has_pending) {
            $this->issueCertificateForAttempt($attempt_id);
        }

        return [
            'attempt_id' => $attempt_id,
            'status' => $status,
            'raw_score' => $scores['raw_score'],
            'percentage_score' => $scores['percentage_score'],
            'passed' => $passed,
            'grade' => $grade,
            'questions_correct' => $scores['questions_correct'],
            'questions_incorrect' => $scores['questions_incorrect'],
            'questions_partial' => $scores['questions_partial'],
            'questions_skipped' => $scores['questions_skipped'],
            'questions_pending' => $scores['questions_pending'],
            'requires_manual_grading' => $has_pending
        ];
    }

    /**
     * Calculate final scores for attempt
     *
     * @param int $attempt_id Attempt ID
     * @return array Scores
     */
    private function calculateFinalScores(int $attempt_id): array
    {
        $sql = "SELECT
            COALESCE(SUM(points_earned), 0) as raw_score,
            COUNT(*) as total_questions,
            SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as questions_correct,
            SUM(CASE WHEN is_correct = 0 AND answered_at IS NOT NULL THEN 1 ELSE 0 END) as questions_incorrect,
            SUM(CASE WHEN is_partial = 1 THEN 1 ELSE 0 END) as questions_partial,
            SUM(CASE WHEN answered_at IS NULL THEN 1 ELSE 0 END) as questions_skipped,
            SUM(CASE WHEN grading_status = 'pending' THEN 1 ELSE 0 END) as questions_pending
            FROM proc_attempt_answers WHERE attempt_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $attempt_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Get total possible points
        $sql = "SELECT COALESCE(SUM(COALESCE(aq.points_override, q.points)), 0) as total_points
            FROM proc_attempt_answers aa
            INNER JOIN proc_questions q ON aa.question_id = q.question_id
            LEFT JOIN proc_assessment_questions aq ON aa.question_id = aq.question_id AND aq.assessment_id = (
                SELECT assessment_id FROM proc_assessment_attempts WHERE attempt_id = ?
            )
            WHERE aa.attempt_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $attempt_id, $attempt_id);
        $stmt->execute();
        $total_points = $stmt->get_result()->fetch_assoc()['total_points'];
        $stmt->close();

        $percentage = $total_points > 0 ? ($result['raw_score'] / $total_points) * 100 : 0;

        return [
            'raw_score' => (float)$result['raw_score'],
            'total_points' => (float)$total_points,
            'percentage_score' => round($percentage, 2),
            'total_questions' => (int)$result['total_questions'],
            'questions_correct' => (int)$result['questions_correct'],
            'questions_incorrect' => (int)$result['questions_incorrect'],
            'questions_partial' => (int)$result['questions_partial'],
            'questions_skipped' => (int)$result['questions_skipped'],
            'questions_pending' => (int)$result['questions_pending']
        ];
    }

    /**
     * Calculate grade from percentage
     *
     * @param float $percentage Percentage score
     * @param array $boundaries Grade boundaries
     * @return string|null Grade
     */
    private function calculateGrade(float $percentage, array $boundaries): ?string
    {
        // Sort by min descending
        usort($boundaries, fn($a, $b) => $b['min'] - $a['min']);

        foreach ($boundaries as $boundary) {
            if ($percentage >= $boundary['min']) {
                return $boundary['grade'];
            }
        }

        return null;
    }

    /**
     * Update assessment statistics
     *
     * @param int $assessment_id Assessment ID
     */
    private function updateAssessmentStats(int $assessment_id): void
    {
        $sql = "UPDATE proc_assessments SET
            total_attempts = (SELECT COUNT(*) FROM proc_assessment_attempts WHERE assessment_id = ? AND status IN ('submitted', 'grading', 'graded')),
            total_completions = (SELECT COUNT(*) FROM proc_assessment_attempts WHERE assessment_id = ? AND status = 'graded'),
            avg_score = (SELECT AVG(percentage_score) FROM proc_assessment_attempts WHERE assessment_id = ? AND status = 'graded'),
            pass_rate = (SELECT AVG(passed) * 100 FROM proc_assessment_attempts WHERE assessment_id = ? AND status = 'graded')
            WHERE assessment_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("iiiii", $assessment_id, $assessment_id, $assessment_id, $assessment_id, $assessment_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Log timeline event for attempt
     *
     * @param int $attempt_id Attempt ID
     * @param string $event_type Event type
     * @param array $event_data Event data
     */
    private function logTimelineEvent(int $attempt_id, string $event_type, array $event_data = []): void
    {
        $sql = "INSERT INTO proc_attempt_timeline (attempt_id, event_type, event_data, ip_address)
            VALUES (?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);

        $event_data_json = json_encode($event_data);
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;

        $stmt->bind_param("isss", $attempt_id, $event_type, $event_data_json, $ip_address);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Issue certificate for attempt (placeholder)
     *
     * @param int $attempt_id Attempt ID
     */
    private function issueCertificateForAttempt(int $attempt_id): void
    {
        // Certificate issuance logic
        // This would integrate with your existing certificate system
    }

    // ==========================================
    // ATTEMPT RESULTS
    // ==========================================

    /**
     * Get attempt results
     *
     * @param int $attempt_id Attempt ID
     * @return array Results
     */
    public function getAttemptResults(int $attempt_id): array
    {
        $attempt = $this->getAttemptForUser($attempt_id);
        if (!$attempt) {
            throw new Exception("Attempt not found");
        }

        $assessment = $this->getAssessment($attempt['assessment_id']);

        // Get answers with questions
        $sql = "SELECT aa.*, q.question_text, q.question_type, q.explanation, q.points as question_points
            FROM proc_attempt_answers aa
            INNER JOIN proc_questions q ON aa.question_id = q.question_id
            WHERE aa.attempt_id = ?
            ORDER BY aa.sort_order";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $attempt_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $answers = [];
        while ($row = $result->fetch_assoc()) {
            $row['answer_data'] = $row['answer_data'] ? json_decode($row['answer_data'], true) : null;

            // Determine if we should show correct answers
            $show_correct = $this->shouldShowCorrectAnswers($assessment, $attempt);
            if ($show_correct) {
                $row['options'] = $this->getQuestionOptions($row['question_id']);
            } else {
                unset($row['explanation']);
            }

            $answers[] = $row;
        }
        $stmt->close();

        return [
            'attempt' => $attempt,
            'assessment' => [
                'title' => $assessment['title'],
                'passing_score' => $assessment['passing_score'],
                'passing_type' => $assessment['passing_type'],
                'show_score_immediately' => $assessment['show_score_immediately'],
                'show_explanations' => $assessment['show_explanations'],
                'show_score_breakdown' => $assessment['show_score_breakdown']
            ],
            'answers' => $answers,
            'show_correct_answers' => $this->shouldShowCorrectAnswers($assessment, $attempt)
        ];
    }

    /**
     * Determine if correct answers should be shown
     *
     * @param array $assessment Assessment settings
     * @param array $attempt Attempt data
     * @return bool Show correct answers
     */
    private function shouldShowCorrectAnswers(array $assessment, array $attempt): bool
    {
        switch ($assessment['show_correct_answers']) {
            case 'immediately':
                return true;

            case 'after_deadline':
                return $assessment['available_until'] && strtotime($assessment['available_until']) < time();

            case 'after_all_attempts':
                $attempts = $this->getUserAttemptCount($assessment['assessment_id']);
                return $attempts >= $assessment['max_attempts'];

            case 'never':
            default:
                return false;
        }
    }

    // ==========================================
    // GRADING QUEUE (Admin)
    // ==========================================

    /**
     * Get answers pending manual grading
     *
     * @param array $filters Optional filters
     * @return array Pending answers
     */
    public function getGradingQueue(array $filters = []): array
    {
        $sql = "SELECT aa.*, q.question_text, q.question_type, q.points as question_points,
                a.title as assessment_title,
                att.attempt_code, att.submitted_at,
                u.first_name as learner_first_name, u.last_name as learner_last_name
            FROM proc_attempt_answers aa
            INNER JOIN proc_assessment_attempts att ON aa.attempt_id = att.attempt_id
            INNER JOIN proc_assessments a ON att.assessment_id = a.assessment_id
            INNER JOIN proc_questions q ON aa.question_id = q.question_id
            INNER JOIN users u ON att.user_id = u.id
            WHERE a.tenant_id = ?
            AND aa.grading_status = 'pending'
            AND att.status IN ('grading', 'submitted')";

        $params = [$this->tenant_id];
        $types = "i";

        if (!empty($filters['assessment_id'])) {
            $sql .= " AND a.assessment_id = ?";
            $params[] = (int)$filters['assessment_id'];
            $types .= "i";
        }

        if (!empty($filters['question_type'])) {
            $sql .= " AND q.question_type = ?";
            $params[] = $filters['question_type'];
            $types .= "s";
        }

        $sql .= " ORDER BY att.submitted_at ASC";

        $stmt = $this->conn->prepare($sql);
        $this->bindParams($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $row['answer_data'] = $row['answer_data'] ? json_decode($row['answer_data'], true) : null;
            $items[] = $row;
        }
        $stmt->close();

        return $items;
    }

    /**
     * Grade an answer manually
     *
     * @param int $answer_id Answer ID
     * @param array $grading Grading data
     * @return array Updated answer
     */
    public function gradeAnswerManually(int $answer_id, array $grading): array
    {
        $sql = "UPDATE proc_attempt_answers SET
            is_correct = ?,
            is_partial = ?,
            points_earned = ?,
            grading_status = 'graded',
            grader_feedback = ?,
            grader_notes = ?,
            graded_by = ?,
            graded_at = NOW()
            WHERE answer_id = ?";

        $stmt = $this->conn->prepare($sql);

        $is_correct = $grading['is_correct'] ?? null;
        $is_partial = $grading['is_partial'] ?? 0;
        $points_earned = $grading['points_earned'] ?? 0;
        $grader_feedback = $grading['feedback'] ?? null;
        $grader_notes = $grading['grader_notes'] ?? null;

        $stmt->bind_param(
            "iidsii",
            $is_correct,
            $is_partial,
            $points_earned,
            $grader_feedback,
            $grader_notes,
            $this->user_id,
            $answer_id
        );

        if (!$stmt->execute()) {
            throw new Exception("Failed to grade answer: " . $stmt->error);
        }
        $stmt->close();

        // Get the attempt ID to check if all grading is complete
        $sql = "SELECT attempt_id FROM proc_attempt_answers WHERE answer_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $answer_id);
        $stmt->execute();
        $attempt_id = $stmt->get_result()->fetch_assoc()['attempt_id'];
        $stmt->close();

        // Check if all answers are graded
        $sql = "SELECT COUNT(*) as pending FROM proc_attempt_answers WHERE attempt_id = ? AND grading_status = 'pending'";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $attempt_id);
        $stmt->execute();
        $pending = $stmt->get_result()->fetch_assoc()['pending'];
        $stmt->close();

        if ($pending == 0) {
            // Recalculate scores and finalize attempt
            $this->finalizeAttempt($attempt_id);
        }

        return ['success' => true, 'pending_count' => (int)$pending];
    }

    /**
     * Finalize attempt after all manual grading complete
     *
     * @param int $attempt_id Attempt ID
     */
    private function finalizeAttempt(int $attempt_id): void
    {
        $scores = $this->calculateFinalScores($attempt_id);

        // Get attempt and assessment
        $sql = "SELECT att.*, a.passing_score, a.passing_type, a.enable_grading, a.grade_boundaries, a.issue_certificate
            FROM proc_assessment_attempts att
            INNER JOIN proc_assessments a ON att.assessment_id = a.assessment_id
            WHERE att.attempt_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $attempt_id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Determine pass/fail
        $passed = false;
        if ($data['passing_type'] === 'percentage') {
            $passed = $scores['percentage_score'] >= $data['passing_score'];
        } else {
            $passed = $scores['raw_score'] >= $data['passing_score'];
        }

        // Calculate grade
        $grade = null;
        if ($data['enable_grading'] && $data['grade_boundaries']) {
            $boundaries = json_decode($data['grade_boundaries'], true);
            $grade = $this->calculateGrade($scores['percentage_score'], $boundaries);
        }

        // Update attempt
        $sql = "UPDATE proc_assessment_attempts SET
            status = 'graded',
            graded_at = NOW(),
            raw_score = ?,
            percentage_score = ?,
            passed = ?,
            grade = ?,
            questions_correct = ?,
            questions_incorrect = ?,
            questions_partial = ?,
            questions_pending = 0
            WHERE attempt_id = ?";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param(
            "ddigiiii",
            $scores['raw_score'],
            $scores['percentage_score'],
            $passed,
            $grade,
            $scores['questions_correct'],
            $scores['questions_incorrect'],
            $scores['questions_partial'],
            $attempt_id
        );
        $stmt->execute();
        $stmt->close();

        // Update assessment stats
        $this->updateAssessmentStats($data['assessment_id']);

        // Issue certificate if applicable
        if ($passed && $data['issue_certificate']) {
            $this->issueCertificateForAttempt($attempt_id);
        }
    }
}
