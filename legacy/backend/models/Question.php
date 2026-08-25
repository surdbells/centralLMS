<?php
/**
 * Question Model
 * Handles all question-related database operations and auto-grading
 */

namespace App\Models\ProctoredAssessment;

class Question
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
        $where = ["q.tenant_id = :tenant_id", "q.status != 'archived'"];
        $params = ['tenant_id' => $tenantId];

        if (!empty($filters['question_bank_id'])) {
            $where[] = "q.question_bank_id = :bank_id";
            $params['bank_id'] = $filters['question_bank_id'];
        }
        if (!empty($filters['category_id'])) {
            $where[] = "q.category_id = :category_id";
            $params['category_id'] = $filters['category_id'];
        }
        if (!empty($filters['question_type'])) {
            $where[] = "q.question_type = :type";
            $params['type'] = $filters['question_type'];
        }
        if (!empty($filters['difficulty'])) {
            $where[] = "q.difficulty = :difficulty";
            $params['difficulty'] = $filters['difficulty'];
        }
        if (!empty($filters['status'])) {
            $where[] = "q.status = :status";
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = "(q.question_text LIKE :search OR q.explanation LIKE :search)";
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = implode(' AND ', $where);

        // Count total
        $countSql = "SELECT COUNT(*) FROM proc_questions q WHERE $whereClause";
        $stmt = $this->db->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        // Get questions
        $sql = "SELECT q.*, 
                       qb.name as question_bank_name,
                       c.name as category_name
                FROM proc_questions q
                LEFT JOIN proc_question_banks qb ON q.question_bank_id = qb.id
                LEFT JOIN proc_question_bank_categories c ON q.category_id = c.id
                WHERE $whereClause
                ORDER BY q.created_at DESC
                LIMIT $limit OFFSET $offset";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $questions = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Get tags for each question
        foreach ($questions as &$q) {
            $q['tags'] = $this->getTags($q['id']);
        }

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

    public function getById($id, $tenantId)
    {
        $sql = "SELECT q.*, 
                       qb.name as question_bank_name,
                       c.name as category_name
                FROM proc_questions q
                LEFT JOIN proc_question_banks qb ON q.bank_id = qb.bank_id
                LEFT JOIN proc_question_categories c ON q.category_id = c.category_id
                WHERE q.question_id = :id AND q.tenant_id = :tenant_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
        $question = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($question) {
            $question['options'] = $this->getOptions($id);
            $question['tags'] = $this->getTags($id);
            $question['media'] = $this->getMedia($id);
        }

        return $question;
    }

    public function create($data)
    {
        $sql = "INSERT INTO proc_questions (
                    tenant_id, question_bank_id, category_id, question_type, question_text, question_html,
                    explanation, hint, featured_image, audio_url, video_url, points, negative_points,
                    partial_credit, difficulty, estimated_time_seconds, case_sensitive, allow_regex,
                    numeric_tolerance, numeric_unit, max_file_size_mb, allowed_file_types,
                    max_words, min_words, blank_count, hotspot_image, hotspot_shape, status, created_by
                ) VALUES (
                    :tenant_id, :question_bank_id, :category_id, :question_type, :question_text, :question_html,
                    :explanation, :hint, :featured_image, :audio_url, :video_url, :points, :negative_points,
                    :partial_credit, :difficulty, :estimated_time_seconds, :case_sensitive, :allow_regex,
                    :numeric_tolerance, :numeric_unit, :max_file_size_mb, :allowed_file_types,
                    :max_words, :min_words, :blank_count, :hotspot_image, :hotspot_shape, :status, :created_by
                )";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'tenant_id' => $data['tenant_id'],
            'question_bank_id' => $data['question_bank_id'],
            'category_id' => $data['category_id'] ?? null,
            'question_type' => $data['question_type'],
            'question_text' => $data['question_text'],
            'question_html' => $data['question_html'] ?? null,
            'explanation' => $data['explanation'] ?? null,
            'hint' => $data['hint'] ?? null,
            'featured_image' => $data['featured_image'] ?? null,
            'audio_url' => $data['audio_url'] ?? null,
            'video_url' => $data['video_url'] ?? null,
            'points' => $data['points'] ?? 1,
            'negative_points' => $data['negative_points'] ?? 0,
            'partial_credit' => $data['partial_credit'] ?? 0,
            'difficulty' => $data['difficulty'] ?? 'medium',
            'estimated_time_seconds' => $data['estimated_time_seconds'] ?? 60,
            'case_sensitive' => $data['case_sensitive'] ?? 0,
            'allow_regex' => $data['allow_regex'] ?? 0,
            'numeric_tolerance' => $data['numeric_tolerance'] ?? 0,
            'numeric_unit' => $data['numeric_unit'] ?? null,
            'max_file_size_mb' => $data['max_file_size_mb'] ?? 10,
            'allowed_file_types' => $data['allowed_file_types'] ?? 'pdf,doc,docx,jpg,png',
            'max_words' => $data['max_words'] ?? null,
            'min_words' => $data['min_words'] ?? null,
            'blank_count' => $data['blank_count'] ?? 0,
            'hotspot_image' => $data['hotspot_image'] ?? null,
            'hotspot_shape' => $data['hotspot_shape'] ?? 'rectangle',
            'status' => $data['status'] ?? 'active',
            'created_by' => $data['created_by'] ?? null
        ]);

        $questionId = $this->db->lastInsertId();

        // Save options
        if (!empty($data['options'])) {
            $this->saveOptions($questionId, $data['options']);
        }

        // Save tags
        if (!empty($data['tags'])) {
            $this->saveTags($questionId, $data['tags']);
        }

        // Update bank count
        $this->updateBankCount($data['question_bank_id']);
        if ($data['category_id']) {
            $this->updateCategoryCount($data['category_id']);
        }

        return $questionId;
    }

    public function update($id, $data)
    {
        // Save version history
        $this->saveVersion($id, $data['updated_by'] ?? null);

        $sql = "UPDATE proc_questions SET
                    category_id = :category_id,
                    question_text = :question_text,
                    question_html = :question_html,
                    explanation = :explanation,
                    hint = :hint,
                    featured_image = :featured_image,
                    audio_url = :audio_url,
                    video_url = :video_url,
                    points = :points,
                    negative_points = :negative_points,
                    partial_credit = :partial_credit,
                    difficulty = :difficulty,
                    estimated_time_seconds = :estimated_time_seconds,
                    case_sensitive = :case_sensitive,
                    allow_regex = :allow_regex,
                    numeric_tolerance = :numeric_tolerance,
                    numeric_unit = :numeric_unit,
                    max_file_size_mb = :max_file_size_mb,
                    allowed_file_types = :allowed_file_types,
                    max_words = :max_words,
                    min_words = :min_words,
                    blank_count = :blank_count,
                    hotspot_image = :hotspot_image,
                    hotspot_shape = :hotspot_shape,
                    status = :status,
                    version = version + 1,
                    updated_by = :updated_by,
                    updated_at = NOW()
                WHERE id = :id AND tenant_id = :tenant_id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'tenant_id' => $data['tenant_id'],
            'category_id' => $data['category_id'] ?? null,
            'question_text' => $data['question_text'],
            'question_html' => $data['question_html'] ?? null,
            'explanation' => $data['explanation'] ?? null,
            'hint' => $data['hint'] ?? null,
            'featured_image' => $data['featured_image'] ?? null,
            'audio_url' => $data['audio_url'] ?? null,
            'video_url' => $data['video_url'] ?? null,
            'points' => $data['points'] ?? 1,
            'negative_points' => $data['negative_points'] ?? 0,
            'partial_credit' => $data['partial_credit'] ?? 0,
            'difficulty' => $data['difficulty'] ?? 'medium',
            'estimated_time_seconds' => $data['estimated_time_seconds'] ?? 60,
            'case_sensitive' => $data['case_sensitive'] ?? 0,
            'allow_regex' => $data['allow_regex'] ?? 0,
            'numeric_tolerance' => $data['numeric_tolerance'] ?? 0,
            'numeric_unit' => $data['numeric_unit'] ?? null,
            'max_file_size_mb' => $data['max_file_size_mb'] ?? 10,
            'allowed_file_types' => $data['allowed_file_types'] ?? 'pdf,doc,docx,jpg,png',
            'max_words' => $data['max_words'] ?? null,
            'min_words' => $data['min_words'] ?? null,
            'blank_count' => $data['blank_count'] ?? 0,
            'hotspot_image' => $data['hotspot_image'] ?? null,
            'hotspot_shape' => $data['hotspot_shape'] ?? 'rectangle',
            'status' => $data['status'] ?? 'active',
            'updated_by' => $data['updated_by'] ?? null
        ]);

        // Update options
        if (isset($data['options'])) {
            $this->deleteOptions($id);
            $this->saveOptions($id, $data['options']);
        }

        // Update tags
        if (isset($data['tags'])) {
            $this->deleteTags($id);
            $this->saveTags($id, $data['tags']);
        }

        return true;
    }

    public function delete($id, $tenantId)
    {
        $sql = "UPDATE proc_questions SET status = 'archived' WHERE id = :id AND tenant_id = :tenant_id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(['id' => $id, 'tenant_id' => $tenantId]);
    }

    // ==================== OPTIONS ====================

    public function getOptions($questionId)
    {
        $sql = "SELECT * FROM proc_question_options WHERE question_id = :id ORDER BY sort_order";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $questionId]);
        $options = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($options as &$opt) {
            if ($opt['hotspot_coords']) {
                $opt['hotspot_coords'] = json_decode($opt['hotspot_coords'], true);
            }
            if ($opt['acceptable_answers']) {
                $opt['acceptable_answers'] = json_decode($opt['acceptable_answers'], true);
            }
        }

        return $options;
    }

    private function saveOptions($questionId, $options)
    {
        $sql = "INSERT INTO proc_question_options (
                    question_id, option_text, option_html, option_image, is_correct, points_if_selected,
                    match_text, match_image, correct_position, hotspot_coords, acceptable_answers,
                    blank_index, sort_order, feedback
                ) VALUES (
                    :question_id, :option_text, :option_html, :option_image, :is_correct, :points_if_selected,
                    :match_text, :match_image, :correct_position, :hotspot_coords, :acceptable_answers,
                    :blank_index, :sort_order, :feedback
                )";

        $stmt = $this->db->prepare($sql);

        foreach ($options as $i => $opt) {
            $stmt->execute([
                'question_id' => $questionId,
                'option_text' => $opt['option_text'],
                'option_html' => $opt['option_html'] ?? null,
                'option_image' => $opt['option_image'] ?? null,
                'is_correct' => $opt['is_correct'] ?? 0,
                'points_if_selected' => $opt['points_if_selected'] ?? 0,
                'match_text' => $opt['match_text'] ?? null,
                'match_image' => $opt['match_image'] ?? null,
                'correct_position' => $opt['correct_position'] ?? null,
                'hotspot_coords' => isset($opt['hotspot_coords']) ? json_encode($opt['hotspot_coords']) : null,
                'acceptable_answers' => isset($opt['acceptable_answers']) ? json_encode($opt['acceptable_answers']) : null,
                'blank_index' => $opt['blank_index'] ?? null,
                'sort_order' => $opt['sort_order'] ?? $i,
                'feedback' => $opt['feedback'] ?? null
            ]);
        }
    }

    private function deleteOptions($questionId)
    {
        $sql = "DELETE FROM proc_question_options WHERE question_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $questionId]);
    }

    // ==================== TAGS ====================

    public function getTags($questionId)
    {
        $sql = "SELECT t.* FROM proc_question_tags t
                INNER JOIN proc_question_tag_map tm ON t.id = tm.tag_id
                WHERE tm.question_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $questionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function saveTags($questionId, $tagIds)
    {
        $sql = "INSERT INTO proc_question_tag_map (question_id, tag_id) VALUES (:question_id, :tag_id)";
        $stmt = $this->db->prepare($sql);

        foreach ($tagIds as $tagId) {
            $stmt->execute(['question_id' => $questionId, 'tag_id' => $tagId]);
            $this->updateTagUsage($tagId, 1);
        }
    }

    private function deleteTags($questionId)
    {
        // Get current tags first for usage update
        $tags = $this->getTags($questionId);
        foreach ($tags as $tag) {
            $this->updateTagUsage($tag['id'], -1);
        }

        $sql = "DELETE FROM proc_question_tag_map WHERE question_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $questionId]);
    }

    private function updateTagUsage($tagId, $delta)
    {
        $sql = "UPDATE proc_question_tags SET usage_count = usage_count + :delta WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $tagId, 'delta' => $delta]);
    }

    // ==================== MEDIA ====================

    public function getMedia($questionId)
    {
        $sql = "SELECT * FROM proc_question_media WHERE question_id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $questionId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ==================== AUTO-GRADING ====================

    public function gradeAnswer($questionId, $answerData, $tenantId)
    {
        $question = $this->getById($questionId, $tenantId);
        if (!$question) {
            return ['is_correct' => null, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => false];
        }

        $points = $question['points'];

        switch ($question['question_type']) {
            case 'multiple_choice_single':
                return $this->gradeMcqSingle($question, $answerData);
            case 'multiple_choice_multiple':
                return $this->gradeMcqMultiple($question, $answerData);
            case 'true_false':
                return $this->gradeTrueFalse($question, $answerData);
            case 'fill_in_blanks':
                return $this->gradeFillBlanks($question, $answerData);
            case 'short_answer':
                return $this->gradeShortAnswer($question, $answerData);
            case 'matching':
                return $this->gradeMatching($question, $answerData);
            case 'ordering':
                return $this->gradeOrdering($question, $answerData);
            case 'hotspot':
                return $this->gradeHotspot($question, $answerData);
            case 'numeric':
                return $this->gradeNumeric($question, $answerData);
            case 'long_answer':
            case 'file_upload':
                return ['is_correct' => null, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => false, 'feedback' => 'Requires manual grading'];
            default:
                return ['is_correct' => null, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => false];
        }
    }

    private function gradeMcqSingle($question, $answerData)
    {
        $selectedId = $answerData['selected_option_id'] ?? null;
        if (!$selectedId) {
            return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
        }

        foreach ($question['options'] as $opt) {
            if ($opt['id'] == $selectedId) {
                if ($opt['is_correct']) {
                    return ['is_correct' => true, 'is_partial' => false, 'points_earned' => $question['points'], 'auto_graded' => true, 'feedback' => $opt['feedback']];
                } else {
                    $penalty = $question['negative_points'] ?? 0;
                    return ['is_correct' => false, 'is_partial' => false, 'points_earned' => -$penalty, 'auto_graded' => true, 'feedback' => $opt['feedback']];
                }
            }
        }

        return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
    }

    private function gradeMcqMultiple($question, $answerData)
    {
        $selectedIds = $answerData['selected_option_ids'] ?? [];
        if (empty($selectedIds)) {
            return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
        }

        $correctIds = [];
        foreach ($question['options'] as $opt) {
            if ($opt['is_correct']) {
                $correctIds[] = $opt['id'];
            }
        }

        $correctSelected = array_intersect($selectedIds, $correctIds);
        $incorrectSelected = array_diff($selectedIds, $correctIds);

        if (count($correctSelected) == count($correctIds) && empty($incorrectSelected)) {
            return ['is_correct' => true, 'is_partial' => false, 'points_earned' => $question['points'], 'auto_graded' => true];
        }

        if ($question['partial_credit'] && count($correctSelected) > 0) {
            $ratio = count($correctSelected) / count($correctIds);
            $penalty = count($incorrectSelected) * ($question['negative_points'] ?? 0);
            $earned = max(0, ($question['points'] * $ratio) - $penalty);
            return ['is_correct' => false, 'is_partial' => true, 'points_earned' => $earned, 'auto_graded' => true];
        }

        return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
    }

    private function gradeTrueFalse($question, $answerData)
    {
        $answer = $answerData['answer'] ?? null;
        if ($answer === null) {
            return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
        }

        $correctOption = null;
        foreach ($question['options'] as $opt) {
            if ($opt['is_correct']) {
                $correctOption = $opt;
                break;
            }
        }

        $correctAnswer = strtolower($correctOption['option_text']) === 'true';
        $isCorrect = ($answer === $correctAnswer);

        return [
            'is_correct' => $isCorrect,
            'is_partial' => false,
            'points_earned' => $isCorrect ? $question['points'] : 0,
            'auto_graded' => true
        ];
    }

    private function gradeFillBlanks($question, $answerData)
    {
        $blanks = $answerData['blanks'] ?? [];
        if (empty($blanks)) {
            return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
        }

        $totalBlanks = count($question['options']);
        $correctCount = 0;

        foreach ($question['options'] as $opt) {
            $blankIndex = $opt['blank_index'];
            $userAnswer = $blanks[$blankIndex] ?? '';
            $acceptableAnswers = $opt['acceptable_answers'] ?? [$opt['option_text']];

            $isMatch = false;
            foreach ($acceptableAnswers as $acceptable) {
                if ($question['case_sensitive']) {
                    if ($userAnswer === $acceptable) $isMatch = true;
                } else {
                    if (strtolower($userAnswer) === strtolower($acceptable)) $isMatch = true;
                }
            }

            if ($isMatch) $correctCount++;
        }

        if ($correctCount == $totalBlanks) {
            return ['is_correct' => true, 'is_partial' => false, 'points_earned' => $question['points'], 'auto_graded' => true];
        }

        if ($question['partial_credit'] && $correctCount > 0) {
            $ratio = $correctCount / $totalBlanks;
            return ['is_correct' => false, 'is_partial' => true, 'points_earned' => $question['points'] * $ratio, 'auto_graded' => true];
        }

        return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
    }

    private function gradeShortAnswer($question, $answerData)
    {
        $answer = trim($answerData['text'] ?? '');
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
                    $match = $question['case_sensitive'] ? ($answer === $acc) : (strtolower($answer) === strtolower($acc));
                    if ($match) {
                        return ['is_correct' => true, 'is_partial' => false, 'points_earned' => $question['points'], 'auto_graded' => true];
                    }
                }
            }
        }

        return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
    }

    private function gradeMatching($question, $answerData)
    {
        $matches = $answerData['matches'] ?? [];
        if (empty($matches)) {
            return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
        }

        $totalPairs = count($question['options']);
        $correctCount = 0;

        foreach ($question['options'] as $opt) {
            $userMatch = $matches[$opt['id']] ?? null;
            if ($userMatch && strtolower($userMatch) === strtolower($opt['match_text'])) {
                $correctCount++;
            }
        }

        if ($correctCount == $totalPairs) {
            return ['is_correct' => true, 'is_partial' => false, 'points_earned' => $question['points'], 'auto_graded' => true];
        }

        if ($question['partial_credit'] && $correctCount > 0) {
            $ratio = $correctCount / $totalPairs;
            return ['is_correct' => false, 'is_partial' => true, 'points_earned' => $question['points'] * $ratio, 'auto_graded' => true];
        }

        return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
    }

    private function gradeOrdering($question, $answerData)
    {
        $order = $answerData['order'] ?? [];
        if (empty($order)) {
            return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
        }

        $correctOrder = [];
        foreach ($question['options'] as $opt) {
            $correctOrder[$opt['correct_position']] = $opt['id'];
        }
        ksort($correctOrder);
        $correctOrder = array_values($correctOrder);

        if ($order === $correctOrder) {
            return ['is_correct' => true, 'is_partial' => false, 'points_earned' => $question['points'], 'auto_graded' => true];
        }

        if ($question['partial_credit']) {
            $correctCount = 0;
            foreach ($order as $i => $id) {
                if (isset($correctOrder[$i]) && $correctOrder[$i] == $id) {
                    $correctCount++;
                }
            }
            if ($correctCount > 0) {
                $ratio = $correctCount / count($correctOrder);
                return ['is_correct' => false, 'is_partial' => true, 'points_earned' => $question['points'] * $ratio, 'auto_graded' => true];
            }
        }

        return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
    }

    private function gradeHotspot($question, $answerData)
    {
        $clickedCoords = $answerData['clicked_coords'] ?? null;
        if (!$clickedCoords) {
            return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
        }

        foreach ($question['options'] as $opt) {
            if ($opt['is_correct'] && $opt['hotspot_coords']) {
                $coords = $opt['hotspot_coords'];
                $inArea = false;

                switch ($question['hotspot_shape']) {
                    case 'rectangle':
                        $inArea = $clickedCoords['x'] >= $coords['x1'] && $clickedCoords['x'] <= $coords['x2']
                               && $clickedCoords['y'] >= $coords['y1'] && $clickedCoords['y'] <= $coords['y2'];
                        break;
                    case 'circle':
                        $distance = sqrt(pow($clickedCoords['x'] - $coords['cx'], 2) + pow($clickedCoords['y'] - $coords['cy'], 2));
                        $inArea = $distance <= $coords['radius'];
                        break;
                    case 'polygon':
                        $inArea = $this->pointInPolygon($clickedCoords, $coords['points']);
                        break;
                }

                if ($inArea) {
                    return ['is_correct' => true, 'is_partial' => false, 'points_earned' => $question['points'], 'auto_graded' => true];
                }
            }
        }

        return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
    }

    private function gradeNumeric($question, $answerData)
    {
        $value = $answerData['value'] ?? null;
        if ($value === null) {
            return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
        }

        foreach ($question['options'] as $opt) {
            if ($opt['is_correct']) {
                $correctValue = floatval($opt['option_text']);
                $tolerance = $question['numeric_tolerance'] ?? 0;
                
                if (abs($value - $correctValue) <= $tolerance) {
                    return ['is_correct' => true, 'is_partial' => false, 'points_earned' => $question['points'], 'auto_graded' => true];
                }
            }
        }

        return ['is_correct' => false, 'is_partial' => false, 'points_earned' => 0, 'auto_graded' => true];
    }

    private function pointInPolygon($point, $polygon)
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

    // ==================== RANDOM SELECTION ====================

    public function getRandomQuestions($tenantId, $criteria)
    {
        $where = ["tenant_id = :tenant_id", "status = 'active'"];
        $params = ['tenant_id' => $tenantId];

        if (!empty($criteria['question_bank_id'])) {
            $where[] = "question_bank_id = :bank_id";
            $params['bank_id'] = $criteria['question_bank_id'];
        }
        if (!empty($criteria['category_id'])) {
            $where[] = "category_id = :cat_id";
            $params['cat_id'] = $criteria['category_id'];
        }
        if (!empty($criteria['difficulty']) && $criteria['difficulty'] !== 'any') {
            $where[] = "difficulty = :difficulty";
            $params['difficulty'] = $criteria['difficulty'];
        }
        if (!empty($criteria['exclude_ids'])) {
            $placeholders = implode(',', array_fill(0, count($criteria['exclude_ids']), '?'));
            $where[] = "id NOT IN ($placeholders)";
        }

        $whereClause = implode(' AND ', $where);
        $count = $criteria['count'] ?? 1;

        $sql = "SELECT id FROM proc_questions WHERE $whereClause ORDER BY RAND() LIMIT $count";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    // ==================== HELPERS ====================

    private function saveVersion($questionId, $userId)
    {
        $question = $this->getById($questionId, null);
        if (!$question) return;

        $sql = "INSERT INTO proc_question_versions (question_id, version, question_data, changed_by)
                VALUES (:question_id, :version, :question_data, :changed_by)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'question_id' => $questionId,
            'version' => $question['version'],
            'question_data' => json_encode($question),
            'changed_by' => $userId
        ]);
    }

    private function updateBankCount($bankId)
    {
        $sql = "UPDATE proc_question_banks SET total_questions = (
                    SELECT COUNT(*) FROM proc_questions WHERE question_bank_id = :id AND status != 'archived'
                ) WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $bankId]);
    }

    private function updateCategoryCount($categoryId)
    {
        $sql = "UPDATE proc_question_bank_categories SET question_count = (
                    SELECT COUNT(*) FROM proc_questions WHERE category_id = :id AND status != 'archived'
                ) WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['id' => $categoryId]);
    }
}
