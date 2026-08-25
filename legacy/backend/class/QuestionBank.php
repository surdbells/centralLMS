<?php

/**
 * Question Bank Manager
 * Handles question banks, categories, questions, options, and tags
 * For Multi-Tenant LMS - Admin Portal
 *
 * Following Skills.php pattern with MySQLi prepared statements
 */

class QuestionBank
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
    // QUESTION BANKS - CRUD
    // ==========================================

    /**
     * Create a question bank
     */
    public function createBank(array $data): array
    {
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $color = trim($data['color'] ?? '#3b82f6');
        $icon = trim($data['icon'] ?? 'folder');
        $is_shared = isset($data['is_shared']) ? (int) $data['is_shared'] : 0;

        if (empty($name)) {
            return ['success' => false, 'message' => 'Question bank name is required'];
        }

        // Check duplicate
        $stmt = $this->conn->prepare("
            SELECT bank_id FROM proc_question_banks 
            WHERE tenant_id = ? AND name = ? AND status = 'active'
        ");
        $stmt->bind_param("is", $this->tenant_id, $name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            return ['success' => false, 'message' => 'Question bank name already exists'];
        }
        $stmt->close();

        $stmt = $this->conn->prepare("
            INSERT INTO proc_question_banks 
            (tenant_id, name, description, color, icon, is_shared, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("issssii",
            $this->tenant_id, $name, $description, $color, $icon, $is_shared, $this->user_id
        );

        if ($stmt->execute()) {
            $bank_id = $stmt->insert_id;
            $stmt->close();
            return [
                'success' => true,
                'message' => 'Question bank created successfully',
                'bank_id' => $bank_id
            ];
        }

        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to create question bank: ' . $error];
    }

    /**
     * Update a question bank
     */
    public function updateBank(int $bank_id, array $data): array
    {
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $color = trim($data['color'] ?? '#3b82f6');
        $icon = trim($data['icon'] ?? 'folder');
        $is_shared = isset($data['is_shared']) ? (int) $data['is_shared'] : 0;

        if (empty($name)) {
            return ['success' => false, 'message' => 'Question bank name is required'];
        }

        // Check duplicate (exclude current)
        $stmt = $this->conn->prepare("
            SELECT bank_id FROM proc_question_banks 
            WHERE tenant_id = ? AND name = ? AND bank_id != ? AND status = 'active'
        ");
        $stmt->bind_param("isi", $this->tenant_id, $name, $bank_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            return ['success' => false, 'message' => 'Question bank name already exists'];
        }
        $stmt->close();

        $stmt = $this->conn->prepare("
            UPDATE proc_question_banks 
            SET name = ?, description = ?, color = ?, icon = ?, is_shared = ?
            WHERE bank_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("ssssiis", $name, $description, $color, $icon, $is_shared, $bank_id, $this->tenant_id);

        if ($stmt->execute() && $stmt->affected_rows >= 0) {
            $stmt->close();
            return ['success' => true, 'message' => 'Question bank updated successfully'];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Question bank not found'];
    }

    /**
     * Delete (archive) a question bank
     */
    public function deleteBank(int $bank_id): array
    {
        $stmt = $this->conn->prepare("
            UPDATE proc_question_banks 
            SET status = 'archived'
            WHERE bank_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("ii", $bank_id, $this->tenant_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            return ['success' => true, 'message' => 'Question bank archived successfully'];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Question bank not found'];
    }

    /**
     * Get a single question bank with stats
     */
    public function getBank(int $bank_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                qb.*,
                CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
                (SELECT COUNT(*) FROM proc_question_categories WHERE bank_id = qb.bank_id) as category_count
            FROM proc_question_banks qb
            LEFT JOIN users u ON qb.created_by = u.id
            WHERE qb.bank_id = ? AND qb.tenant_id = ?
        ");
        $stmt->bind_param("ii", $bank_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($result) {
            $result['bank_id'] = (int) $result['bank_id'];
            $result['total_questions'] = (int) $result['total_questions'];
            $result['is_shared'] = (bool) $result['is_shared'];
            $result['category_count'] = (int) $result['category_count'];
        }

        return $result;
    }

    /**
     * Get all question banks with filters
     */
    public function getBanks(array $filters = []): array
    {
        $status = $filters['status'] ?? 'active';
        $search = trim($filters['search'] ?? '');

        $sql = "
            SELECT 
                qb.*,
                CONCAT(u.first_name, ' ', u.last_name) as created_by_name,
                (SELECT COUNT(*) FROM proc_question_categories WHERE bank_id = qb.bank_id) as category_count
            FROM proc_question_banks qb
            LEFT JOIN users u ON qb.created_by = u.id
            WHERE qb.tenant_id = ?
        ";

        $params = [$this->tenant_id];
        $types = "i";

        if ($status !== 'all') {
            $sql .= " AND qb.status = ?";
            $params[] = $status;
            $types .= "s";
        }

        if (!empty($search)) {
            $sql .= " AND (qb.name LIKE ? OR qb.description LIKE ?)";
            $searchParam = "%{$search}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= "ss";
        }

        $sql .= " ORDER BY qb.name ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $banks = [];
        while ($row = $result->fetch_assoc()) {
            $row['bank_id'] = (int) $row['bank_id'];
            $row['total_questions'] = (int) $row['total_questions'];
            $row['is_shared'] = (bool) $row['is_shared'];
            $row['category_count'] = (int) $row['category_count'];
            $banks[] = $row;
        }
        $stmt->close();

        return $banks;
    }

    // ==========================================
    // QUESTION CATEGORIES - CRUD
    // ==========================================

    /**
     * Create a category within a bank
     */
    public function createCategory(array $data): array
    {
        $bank_id = (int) ($data['bank_id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $parent_id = !empty($data['parent_id']) ? (int) $data['parent_id'] : null;
        $sort_order = (int) ($data['sort_order'] ?? 0);

        if (empty($bank_id) || empty($name)) {
            return ['success' => false, 'message' => 'Bank ID and category name are required'];
        }

        // Verify bank exists and belongs to tenant
        $stmt = $this->conn->prepare("SELECT bank_id FROM proc_question_banks WHERE bank_id = ? AND tenant_id = ?");
        $stmt->bind_param("ii", $bank_id, $this->tenant_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $stmt->close();
            return ['success' => false, 'message' => 'Question bank not found'];
        }
        $stmt->close();

        // Check duplicate in same bank
        $stmt = $this->conn->prepare("
            SELECT category_id FROM proc_question_categories 
            WHERE bank_id = ? AND name = ? AND (parent_id = ? OR (parent_id IS NULL AND ? IS NULL))
        ");
        $stmt->bind_param("isii", $bank_id, $name, $parent_id, $parent_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            return ['success' => false, 'message' => 'Category name already exists in this bank'];
        }
        $stmt->close();

        $stmt = $this->conn->prepare("
            INSERT INTO proc_question_categories 
            (tenant_id, bank_id, parent_id, name, description, sort_order, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iiissi", $this->tenant_id, $bank_id, $parent_id, $name, $description, $sort_order);

        if ($stmt->execute()) {
            $category_id = $stmt->insert_id;
            $stmt->close();
            return [
                'success' => true,
                'message' => 'Category created successfully',
                'category_id' => $category_id
            ];
        }

        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to create category: ' . $error];
    }

    /**
     * Update a category
     */
    public function updateCategory(int $category_id, array $data): array
    {
        $name = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $parent_id = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
        $sort_order = (int) ($data['sort_order'] ?? 0);

        if (empty($name)) {
            return ['success' => false, 'message' => 'Category name is required'];
        }

        // Prevent self-reference
        if ($parent_id === $category_id) {
            return ['success' => false, 'message' => 'Category cannot be its own parent'];
        }

        $stmt = $this->conn->prepare("
            UPDATE proc_question_categories 
            SET name = ?, description = ?, parent_id = ?, sort_order = ?
            WHERE category_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("ssiiii", $name, $description, $parent_id, $sort_order, $category_id, $this->tenant_id);

        if ($stmt->execute() && $stmt->affected_rows >= 0) {
            $stmt->close();
            return ['success' => true, 'message' => 'Category updated successfully'];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Category not found'];
    }

    /**
     * Delete a category (moves questions to uncategorized)
     */
    public function deleteCategory(int $category_id): array
    {
        // Move questions to uncategorized (null)
        $stmt = $this->conn->prepare("
            UPDATE proc_questions SET category_id = NULL WHERE category_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("ii", $category_id, $this->tenant_id);
        $stmt->execute();
        $stmt->close();

        // Move child categories to parent
        $stmt = $this->conn->prepare("
            SELECT parent_id FROM proc_question_categories WHERE category_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("ii", $category_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $parent_id = $result ? $result['parent_id'] : null;
        $stmt->close();

        $stmt = $this->conn->prepare("
            UPDATE proc_question_categories SET parent_id = ? WHERE parent_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("iii", $parent_id, $category_id, $this->tenant_id);
        $stmt->execute();
        $stmt->close();

        // Delete category
        $stmt = $this->conn->prepare("
            DELETE FROM proc_question_categories WHERE category_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("ii", $category_id, $this->tenant_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            return ['success' => true, 'message' => 'Category deleted successfully'];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Category not found'];
    }

    /**
     * Get categories for a bank (hierarchical)
     */
    public function getCategories(int $bank_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM proc_question_categories 
            WHERE bank_id = ? AND tenant_id = ?
            ORDER BY sort_order ASC, name ASC
        ");
        $stmt->bind_param("ii", $bank_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $categories = [];
        while ($row = $result->fetch_assoc()) {
            $row['category_id'] = (int) $row['category_id'];
            $row['bank_id'] = (int) $row['bank_id'];
            $row['parent_id'] = $row['parent_id'] ? (int) $row['parent_id'] : null;
            $row['question_count'] = (int) $row['question_count'];
            $row['sort_order'] = (int) $row['sort_order'];
            $categories[] = $row;
        }
        $stmt->close();

        return $this->buildCategoryTree($categories);
    }

    /**
     * Build hierarchical category tree
     */
    private function buildCategoryTree(array $categories, ?int $parentId = null): array
    {
        $tree = [];
        foreach ($categories as $category) {
            if ($category['parent_id'] === $parentId) {
                $category['children'] = $this->buildCategoryTree($categories, $category['category_id']);
                $tree[] = $category;
            }
        }
        return $tree;
    }

    // ==========================================
    // QUESTION TAGS - CRUD
    // ==========================================

    /**
     * Create a tag
     */
    public function createTag(array $data): array
    {
        $name = trim($data['name'] ?? '');
        $color = trim($data['color'] ?? '#6c757d');

        if (empty($name)) {
            return ['success' => false, 'message' => 'Tag name is required'];
        }

        // Check duplicate
        $stmt = $this->conn->prepare("
            SELECT tag_id FROM proc_question_tags WHERE tenant_id = ? AND name = ?
        ");
        $stmt->bind_param("is", $this->tenant_id, $name);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $stmt->close();
            return ['success' => false, 'message' => 'Tag already exists'];
        }
        $stmt->close();

        $stmt = $this->conn->prepare("
            INSERT INTO proc_question_tags (tenant_id, name, color, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->bind_param("iss", $this->tenant_id, $name, $color);

        if ($stmt->execute()) {
            $tag_id = $stmt->insert_id;
            $stmt->close();
            return [
                'success' => true,
                'message' => 'Tag created successfully',
                'tag_id' => $tag_id
            ];
        }

        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to create tag: ' . $error];
    }

    /**
     * Get all tags
     */
    public function getTags(): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM proc_question_tags 
            WHERE tenant_id = ?
            ORDER BY name ASC
        ");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $tags = [];
        while ($row = $result->fetch_assoc()) {
            $row['tag_id'] = (int) $row['tag_id'];
            $row['usage_count'] = (int) $row['usage_count'];
            $tags[] = $row;
        }
        $stmt->close();

        return $tags;
    }

    /**
     * Delete a tag
     */
    public function deleteTag(int $tag_id): array
    {
        $stmt = $this->conn->prepare("
            DELETE FROM proc_question_tags WHERE tag_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("ii", $tag_id, $this->tenant_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            return ['success' => true, 'message' => 'Tag deleted successfully'];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Tag not found'];
    }

    // ==========================================
    // QUESTIONS - CRUD
    // ==========================================

    /**
     * Create a question
     */
    public function createQuestion(array $data): array
    {
        $bank_id = (int) ($data['bank_id'] ?? 0);
        $category_id = !empty($data['category_id']) ? (int) $data['category_id'] : null;
        $question_type = trim($data['question_type'] ?? '');
        $question_text = trim($data['question_text'] ?? '');
        $question_html = $data['question_html'] ?? null;
        $explanation = $data['explanation'] ?? null;
        $hint = $data['hint'] ?? null;
        $featured_image = $data['featured_image'] ?? null;
        $audio_url = $data['audio_url'] ?? null;
        $video_url = $data['video_url'] ?? null;
        $points = (float) ($data['points'] ?? 1.00);
        $negative_points = (float) ($data['negative_points'] ?? 0.00);
        $partial_credit = isset($data['partial_credit']) ? (int) $data['partial_credit'] : 0;
        $difficulty = $data['difficulty'] ?? 'medium';
        $estimated_time_seconds = (int) ($data['estimated_time_seconds'] ?? 60);
        $case_sensitive = isset($data['case_sensitive']) ? (int) $data['case_sensitive'] : 0;
        $allow_regex = isset($data['allow_regex']) ? (int) $data['allow_regex'] : 0;
        $numeric_tolerance = (float) ($data['numeric_tolerance'] ?? 0);
        $max_file_size_mb = (int) ($data['max_file_size_mb'] ?? 10);
        $allowed_file_types = $data['allowed_file_types'] ?? 'pdf,doc,docx,jpg,png';
        $max_words = !empty($data['max_words']) ? (int) $data['max_words'] : null;
        $min_words = !empty($data['min_words']) ? (int) $data['min_words'] : null;

        // Validate required fields
        if (empty($bank_id) || empty($question_type) || empty($question_text)) {
            return ['success' => false, 'message' => 'Bank ID, question type, and question text are required'];
        }

        // Validate question type
        $validTypes = [
            'multiple_choice_single', 'multiple_choice_multiple', 'true_false',
            'fill_in_blanks', 'short_answer', 'long_answer', 'matching',
            'ordering', 'hotspot', 'numeric', 'file_upload'
        ];
        if (!in_array($question_type, $validTypes)) {
            return ['success' => false, 'message' => 'Invalid question type'];
        }

        // Verify bank exists
        $stmt = $this->conn->prepare("SELECT bank_id FROM proc_question_banks WHERE bank_id = ? AND tenant_id = ?");
        $stmt->bind_param("ii", $bank_id, $this->tenant_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            $stmt->close();
            return ['success' => false, 'message' => 'Question bank not found'];
        }
        $stmt->close();

        $stmt = $this->conn->prepare("
            INSERT INTO proc_questions 
            (tenant_id, bank_id, category_id, question_type, question_text, question_html,
             explanation, hint, featured_image, audio_url, video_url, points, negative_points,
             partial_credit, difficulty, estimated_time_seconds, case_sensitive, allow_regex,
             numeric_tolerance, max_file_size_mb, allowed_file_types, max_words, min_words,
             created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");

        $stmt->bind_param(
            "iiissssssssddisissdiisii",
            $this->tenant_id, $bank_id, $category_id, $question_type, $question_text, $question_html,
            $explanation, $hint, $featured_image, $audio_url, $video_url, $points, $negative_points,
            $partial_credit, $difficulty, $estimated_time_seconds, $case_sensitive, $allow_regex,
            $numeric_tolerance, $max_file_size_mb, $allowed_file_types, $max_words, $min_words,
            $this->user_id
        );

        if ($stmt->execute()) {
            $question_id = $stmt->insert_id;
            $stmt->close();

            // Add options if provided
            if (!empty($data['options']) && is_array($data['options'])) {
                $this->saveQuestionOptions($question_id, $data['options']);
            }

            // Add tags if provided
            if (!empty($data['tag_ids']) && is_array($data['tag_ids'])) {
                $this->saveQuestionTags($question_id, $data['tag_ids']);
            }

            return [
                'success' => true,
                'message' => 'Question created successfully',
                'question_id' => $question_id
            ];
        }

        $error = $stmt->error;
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to create question: ' . $error];
    }

    /**
     * Update a question
     */
    public function updateQuestion(int $question_id, array $data): array
    {
        $category_id = isset($data['category_id']) ? (int) $data['category_id'] : null;
        $question_text = trim($data['question_text'] ?? '');
        $question_html = $data['question_html'] ?? null;
        $explanation = $data['explanation'] ?? null;
        $hint = $data['hint'] ?? null;
        $featured_image = $data['featured_image'] ?? null;
        $audio_url = $data['audio_url'] ?? null;
        $video_url = $data['video_url'] ?? null;
        $points = (float) ($data['points'] ?? 1.00);
        $negative_points = (float) ($data['negative_points'] ?? 0.00);
        $partial_credit = isset($data['partial_credit']) ? (int) $data['partial_credit'] : 0;
        $difficulty = $data['difficulty'] ?? 'medium';
        $estimated_time_seconds = (int) ($data['estimated_time_seconds'] ?? 60);
        $case_sensitive = isset($data['case_sensitive']) ? (int) $data['case_sensitive'] : 0;
        $allow_regex = isset($data['allow_regex']) ? (int) $data['allow_regex'] : 0;
        $numeric_tolerance = (float) ($data['numeric_tolerance'] ?? 0);
        $max_file_size_mb = (int) ($data['max_file_size_mb'] ?? 10);
        $allowed_file_types = $data['allowed_file_types'] ?? 'pdf,doc,docx,jpg,png';
        $max_words = !empty($data['max_words']) ? (int) $data['max_words'] : null;
        $min_words = !empty($data['min_words']) ? (int) $data['min_words'] : null;

        if (empty($question_text)) {
            return ['success' => false, 'message' => 'Question text is required'];
        }

        $stmt = $this->conn->prepare("
            UPDATE proc_questions SET
                category_id = ?, question_text = ?, question_html = ?, explanation = ?, hint = ?,
                featured_image = ?, audio_url = ?, video_url = ?, points = ?, negative_points = ?,
                partial_credit = ?, difficulty = ?, estimated_time_seconds = ?, case_sensitive = ?,
                allow_regex = ?, numeric_tolerance = ?, max_file_size_mb = ?, allowed_file_types = ?,
                max_words = ?, min_words = ?, version = version + 1
            WHERE question_id = ? AND tenant_id = ?
        ");

        $stmt->bind_param(
            "isssssssddisisidissiii",
            $category_id, $question_text, $question_html, $explanation, $hint,
            $featured_image, $audio_url, $video_url, $points, $negative_points,
            $partial_credit, $difficulty, $estimated_time_seconds, $case_sensitive,
            $allow_regex, $numeric_tolerance, $max_file_size_mb, $allowed_file_types,
            $max_words, $min_words, $question_id, $this->tenant_id
        );

        if ($stmt->execute() && $stmt->affected_rows >= 0) {
            $stmt->close();

            // Update options if provided
            if (isset($data['options']) && is_array($data['options'])) {
                $this->deleteQuestionOptions($question_id);
                $this->saveQuestionOptions($question_id, $data['options']);
            }

            // Update tags if provided
            if (isset($data['tag_ids']) && is_array($data['tag_ids'])) {
                $this->deleteQuestionTags($question_id);
                $this->saveQuestionTags($question_id, $data['tag_ids']);
            }

            return ['success' => true, 'message' => 'Question updated successfully'];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Question not found'];
    }

    /**
     * Delete (archive) a question
     */
    public function deleteQuestion(int $question_id): array
    {
        $stmt = $this->conn->prepare("
            UPDATE proc_questions SET status = 'archived'
            WHERE question_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("ii", $question_id, $this->tenant_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            return ['success' => true, 'message' => 'Question archived successfully'];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Question not found'];
    }

    /**
     * Permanently delete a question
     */
    public function permanentDeleteQuestion(int $question_id): array
    {
        $stmt = $this->conn->prepare("
            DELETE FROM proc_questions WHERE question_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("ii", $question_id, $this->tenant_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            return ['success' => true, 'message' => 'Question deleted permanently'];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Question not found'];
    }

    /**
     * Get a single question with options and tags
     */
    public function getQuestion(int $question_id): ?array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                q.*,
                qb.name as bank_name,
                qc.name as category_name,
                CONCAT(u.first_name, ' ', u.last_name) as created_by_name
            FROM proc_questions q
            LEFT JOIN proc_question_banks qb ON q.bank_id = qb.bank_id
            LEFT JOIN proc_question_categories qc ON q.category_id = qc.category_id
            LEFT JOIN users u ON q.created_by = u.id
            WHERE q.question_id = ? AND q.tenant_id = ?
        ");
        $stmt->bind_param("ii", $question_id, $this->tenant_id);
        $stmt->execute();
        $question = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$question) {
            return null;
        }

        // Cast types
        $question['question_id'] = (int) $question['question_id'];
        $question['bank_id'] = (int) $question['bank_id'];
        $question['category_id'] = $question['category_id'] ? (int) $question['category_id'] : null;
        $question['points'] = (float) $question['points'];
        $question['negative_points'] = (float) $question['negative_points'];
        $question['partial_credit'] = (bool) $question['partial_credit'];
        $question['case_sensitive'] = (bool) $question['case_sensitive'];
        $question['allow_regex'] = (bool) $question['allow_regex'];
        $question['numeric_tolerance'] = (float) $question['numeric_tolerance'];
        $question['usage_count'] = (int) $question['usage_count'];
        $question['times_answered'] = (int) $question['times_answered'];
        $question['times_correct'] = (int) $question['times_correct'];

        // Get options
        $question['options'] = $this->getQuestionOptions($question_id);

        // Get tags
        $question['tags'] = $this->getQuestionTags($question_id);

        // Get media
        $question['media'] = $this->getQuestionMedia($question_id);

        return $question;
    }

    /**
     * Get questions with filters and pagination
     */
    public function getQuestions(array $filters = []): array
    {
        $bank_id = !empty($filters['bank_id']) ? (int) $filters['bank_id'] : null;
        $category_id = isset($filters['category_id']) ? (int) $filters['category_id'] : null;
        $question_type = $filters['question_type'] ?? null;
        $difficulty = $filters['difficulty'] ?? null;
        $status = $filters['status'] ?? 'active';
        $search = trim($filters['search'] ?? '');
        $tag_ids = $filters['tag_ids'] ?? [];
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $sql = "
            SELECT 
                q.*,
                qb.name as bank_name,
                qc.name as category_name,
                CONCAT(u.first_name, ' ', u.last_name) as created_by_name
            FROM proc_questions q
            LEFT JOIN proc_question_banks qb ON q.bank_id = qb.bank_id
            LEFT JOIN proc_question_categories qc ON q.category_id = qc.category_id
            LEFT JOIN users u ON q.created_by = u.id
            WHERE q.tenant_id = ?
        ";

        $countSql = "SELECT COUNT(*) as total FROM proc_questions q WHERE q.tenant_id = ?";

        $params = [$this->tenant_id];
        $types = "i";

        if ($bank_id) {
            $sql .= " AND q.bank_id = ?";
            $countSql .= " AND q.bank_id = ?";
            $params[] = $bank_id;
            $types .= "i";
        }

        if ($category_id !== null) {
            if ($category_id === 0) {
                $sql .= " AND q.category_id IS NULL";
                $countSql .= " AND q.category_id IS NULL";
            } else {
                $sql .= " AND q.category_id = ?";
                $countSql .= " AND q.category_id = ?";
                $params[] = $category_id;
                $types .= "i";
            }
        }

        if ($question_type) {
            $sql .= " AND q.question_type = ?";
            $countSql .= " AND q.question_type = ?";
            $params[] = $question_type;
            $types .= "s";
        }

        if ($difficulty) {
            $sql .= " AND q.difficulty = ?";
            $countSql .= " AND q.difficulty = ?";
            $params[] = $difficulty;
            $types .= "s";
        }

        if ($status !== 'all') {
            $sql .= " AND q.status = ?";
            $countSql .= " AND q.status = ?";
            $params[] = $status;
            $types .= "s";
        }

        if (!empty($search)) {
            $sql .= " AND (q.question_text LIKE ? OR q.explanation LIKE ?)";
            $countSql .= " AND (q.question_text LIKE ? OR q.explanation LIKE ?)";
            $searchParam = "%{$search}%";
            $params[] = $searchParam;
            $params[] = $searchParam;
            $types .= "ss";
        }

        // Filter by tags (questions that have ALL specified tags)
        if (!empty($tag_ids)) {
            $tagPlaceholders = implode(',', array_fill(0, count($tag_ids), '?'));
            $tagCount = count($tag_ids);
            $sql .= " AND q.question_id IN (
                SELECT question_id FROM proc_question_tag_map 
                WHERE tag_id IN ($tagPlaceholders) 
                GROUP BY question_id 
                HAVING COUNT(DISTINCT tag_id) = $tagCount
            )";
            $countSql .= " AND q.question_id IN (
                SELECT question_id FROM proc_question_tag_map 
                WHERE tag_id IN ($tagPlaceholders) 
                GROUP BY question_id 
                HAVING COUNT(DISTINCT tag_id) = $tagCount
            )";
            foreach ($tag_ids as $tag_id) {
                $params[] = $tag_id;
                $types .= "i";
            }
        }

        // Get total count
        $countParams = $params;
        $countTypes = $types;
        $stmt = $this->conn->prepare($countSql);
        $stmt->bind_param($countTypes, ...$countParams);
        $stmt->execute();
        $total = (int) $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        // Add pagination
        $sql .= " ORDER BY q.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();

        $questions = [];
        while ($row = $result->fetch_assoc()) {
            $row['question_id'] = (int) $row['question_id'];
            $row['bank_id'] = (int) $row['bank_id'];
            $row['category_id'] = $row['category_id'] ? (int) $row['category_id'] : null;
            $row['points'] = (float) $row['points'];
            $row['partial_credit'] = (bool) $row['partial_credit'];
            $row['usage_count'] = (int) $row['usage_count'];
            $row['tags'] = $this->getQuestionTags($row['question_id']);
            $questions[] = $row;
        }
        $stmt->close();

        return [
            'questions' => $questions,
            'pagination' => [
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
                'total_pages' => ceil($total / $limit)
            ]
        ];
    }

    /**
     * Clone a question
     */
    public function cloneQuestion(int $question_id, ?int $target_bank_id = null): array
    {
        $original = $this->getQuestion($question_id);
        if (!$original) {
            return ['success' => false, 'message' => 'Question not found'];
        }

        $newData = $original;
        $newData['bank_id'] = $target_bank_id ?? $original['bank_id'];
        $newData['question_text'] = $original['question_text'] . ' (Copy)';
        unset($newData['question_id'], $newData['created_at'], $newData['updated_at'],
            $newData['usage_count'], $newData['times_answered'], $newData['times_correct'],
            $newData['avg_score'], $newData['bank_name'], $newData['category_name'],
            $newData['created_by_name']);

        // Prepare tag_ids from tags array
        $newData['tag_ids'] = array_column($original['tags'], 'tag_id');

        return $this->createQuestion($newData);
    }

    // ==========================================
    // QUESTION OPTIONS - HELPERS
    // ==========================================

    /**
     * Save question options
     */
    private function saveQuestionOptions(int $question_id, array $options): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO proc_question_options 
            (question_id, option_text, option_html, option_image, is_correct, points_if_selected,
             match_text, match_image, correct_position, hotspot_coords, acceptable_answers, 
             sort_order, feedback)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($options as $index => $option) {
            $option_text = $option['option_text'] ?? '';
            $option_html = $option['option_html'] ?? null;
            $option_image = $option['option_image'] ?? null;
            $is_correct = isset($option['is_correct']) ? (int) $option['is_correct'] : 0;
            $points_if_selected = (float) ($option['points_if_selected'] ?? 0);
            $match_text = $option['match_text'] ?? null;
            $match_image = $option['match_image'] ?? null;
            $correct_position = isset($option['correct_position']) ? (int) $option['correct_position'] : null;
            $hotspot_coords = isset($option['hotspot_coords']) ? json_encode($option['hotspot_coords']) : null;
            $acceptable_answers = isset($option['acceptable_answers']) ? json_encode($option['acceptable_answers']) : null;
            $sort_order = (int) ($option['sort_order'] ?? $index);
            $feedback = $option['feedback'] ?? null;

            $stmt->bind_param(
                "isssiidssssis",
                $question_id, $option_text, $option_html, $option_image, $is_correct, $points_if_selected,
                $match_text, $match_image, $correct_position, $hotspot_coords, $acceptable_answers,
                $sort_order, $feedback
            );
            $stmt->execute();
        }
        $stmt->close();
    }

    /**
     * Delete question options
     */
    private function deleteQuestionOptions(int $question_id): void
    {
        $stmt = $this->conn->prepare("DELETE FROM proc_question_options WHERE question_id = ?");
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get question options
     */
    private function getQuestionOptions(int $question_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM proc_question_options WHERE question_id = ? ORDER BY sort_order ASC
        ");
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $options = [];
        while ($row = $result->fetch_assoc()) {
            $row['option_id'] = (int) $row['option_id'];
            $row['is_correct'] = (bool) $row['is_correct'];
            $row['points_if_selected'] = (float) $row['points_if_selected'];
            $row['correct_position'] = $row['correct_position'] ? (int) $row['correct_position'] : null;
            $row['hotspot_coords'] = $row['hotspot_coords'] ? json_decode($row['hotspot_coords'], true) : null;
            $row['acceptable_answers'] = $row['acceptable_answers'] ? json_decode($row['acceptable_answers'], true) : null;
            $options[] = $row;
        }
        $stmt->close();

        return $options;
    }

    // ==========================================
    // QUESTION TAGS - HELPERS
    // ==========================================

    /**
     * Save question tags
     */
    private function saveQuestionTags(int $question_id, array $tag_ids): void
    {
        $stmt = $this->conn->prepare("
            INSERT INTO proc_question_tag_map (question_id, tag_id) VALUES (?, ?)
        ");

        foreach ($tag_ids as $tag_id) {
            $stmt->bind_param("ii", $question_id, $tag_id);
            $stmt->execute();
        }
        $stmt->close();
    }

    /**
     * Delete question tags
     */
    private function deleteQuestionTags(int $question_id): void
    {
        $stmt = $this->conn->prepare("DELETE FROM proc_question_tag_map WHERE question_id = ?");
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Get question tags
     */
    private function getQuestionTags(int $question_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT t.* FROM proc_question_tags t
            INNER JOIN proc_question_tag_map tm ON t.tag_id = tm.tag_id
            WHERE tm.question_id = ?
        ");
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $tags = [];
        while ($row = $result->fetch_assoc()) {
            $row['tag_id'] = (int) $row['tag_id'];
            $tags[] = $row;
        }
        $stmt->close();

        return $tags;
    }

    // ==========================================
    // QUESTION MEDIA - HELPERS
    // ==========================================

    /**
     * Add media to question
     */
    public function addQuestionMedia(int $question_id, array $data): array
    {
        $media_type = $data['media_type'] ?? 'image';
        $file_name = $data['file_name'] ?? '';
        $file_path = $data['file_path'] ?? '';
        $file_size = (int) ($data['file_size'] ?? 0);
        $mime_type = $data['mime_type'] ?? '';
        $sort_order = (int) ($data['sort_order'] ?? 0);

        if (empty($file_path)) {
            return ['success' => false, 'message' => 'File path is required'];
        }

        $stmt = $this->conn->prepare("
            INSERT INTO proc_question_media 
            (tenant_id, question_id, media_type, file_name, file_path, file_size, mime_type, sort_order, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->bind_param("iissssii",
            $this->tenant_id, $question_id, $media_type, $file_name, $file_path, $file_size, $mime_type, $sort_order
        );

        if ($stmt->execute()) {
            $media_id = $stmt->insert_id;
            $stmt->close();
            return ['success' => true, 'message' => 'Media added', 'media_id' => $media_id];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Failed to add media'];
    }

    /**
     * Delete question media
     */
    public function deleteQuestionMedia(int $media_id): array
    {
        $stmt = $this->conn->prepare("
            DELETE FROM proc_question_media WHERE media_id = ? AND tenant_id = ?
        ");
        $stmt->bind_param("ii", $media_id, $this->tenant_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            $stmt->close();
            return ['success' => true, 'message' => 'Media deleted'];
        }

        $stmt->close();
        return ['success' => false, 'message' => 'Media not found'];
    }

    /**
     * Get question media
     */
    private function getQuestionMedia(int $question_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT * FROM proc_question_media WHERE question_id = ? ORDER BY sort_order ASC
        ");
        $stmt->bind_param("i", $question_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $media = [];
        while ($row = $result->fetch_assoc()) {
            $row['media_id'] = (int) $row['media_id'];
            $row['file_size'] = (int) $row['file_size'];
            $media[] = $row;
        }
        $stmt->close();

        return $media;
    }

    // ==========================================
    // BULK OPERATIONS
    // ==========================================

    /**
     * Bulk delete questions
     */
    public function bulkDeleteQuestions(array $question_ids): array
    {
        if (empty($question_ids)) {
            return ['success' => false, 'message' => 'No questions specified'];
        }

        $placeholders = implode(',', array_fill(0, count($question_ids), '?'));
        $types = str_repeat('i', count($question_ids)) . 'i';
        $params = array_merge($question_ids, [$this->tenant_id]);

        $stmt = $this->conn->prepare("
            UPDATE proc_questions SET status = 'archived'
            WHERE question_id IN ($placeholders) AND tenant_id = ?
        ");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return [
            'success' => true,
            'message' => "$affected question(s) archived",
            'affected_count' => $affected
        ];
    }

    /**
     * Bulk move questions to category
     */
    public function bulkMoveQuestions(array $question_ids, ?int $category_id): array
    {
        if (empty($question_ids)) {
            return ['success' => false, 'message' => 'No questions specified'];
        }

        $placeholders = implode(',', array_fill(0, count($question_ids), '?'));
        $types = 'i' . str_repeat('i', count($question_ids)) . 'i';
        $params = array_merge([$category_id], $question_ids, [$this->tenant_id]);

        $stmt = $this->conn->prepare("
            UPDATE proc_questions SET category_id = ?
            WHERE question_id IN ($placeholders) AND tenant_id = ?
        ");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        return [
            'success' => true,
            'message' => "$affected question(s) moved",
            'affected_count' => $affected
        ];
    }

    // ==========================================
    // STATISTICS
    // ==========================================

    /**
     * Get bank statistics
     */
    public function getBankStats(int $bank_id): array
    {
        $stmt = $this->conn->prepare("
            SELECT 
                COUNT(*) as total_questions,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_questions,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_questions,
                SUM(CASE WHEN difficulty = 'easy' THEN 1 ELSE 0 END) as easy_count,
                SUM(CASE WHEN difficulty = 'medium' THEN 1 ELSE 0 END) as medium_count,
                SUM(CASE WHEN difficulty = 'hard' THEN 1 ELSE 0 END) as hard_count
            FROM proc_questions
            WHERE bank_id = ? AND tenant_id = ? AND status != 'archived'
        ");
        $stmt->bind_param("ii", $bank_id, $this->tenant_id);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        // Get question type breakdown
        $stmt = $this->conn->prepare("
            SELECT question_type, COUNT(*) as count
            FROM proc_questions
            WHERE bank_id = ? AND tenant_id = ? AND status != 'archived'
            GROUP BY question_type
        ");
        $stmt->bind_param("ii", $bank_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $byType = [];
        while ($row = $result->fetch_assoc()) {
            $byType[$row['question_type']] = (int) $row['count'];
        }
        $stmt->close();

        return [
            'total_questions' => (int) $stats['total_questions'],
            'active_questions' => (int) $stats['active_questions'],
            'draft_questions' => (int) $stats['draft_questions'],
            'by_difficulty' => [
                'easy' => (int) $stats['easy_count'],
                'medium' => (int) $stats['medium_count'],
                'hard' => (int) $stats['hard_count']
            ],
            'by_type' => $byType
        ];
    }
}