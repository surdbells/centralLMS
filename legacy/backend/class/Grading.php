<?php
/**
 * Grading Manager - Review & Manual Grading
 * Multi-Tenant LMS - Admin Portal
 */

class Grading
{
    private mysqli $conn;
    private int $tenant_id;
    private int $user_id;

    public function __construct(mysqli $conn) { $this->conn = $conn; }
    public function setTenant(int $tenant_id): void { $this->tenant_id = $tenant_id; }
    public function setUser(int $user_id): void { $this->user_id = $user_id; }

    // ==========================================
    // RUBRICS
    // ==========================================

    public function createRubric(array $data): array
    {
        $title = trim($data['title'] ?? '');
        if (empty($title)) return ['success' => false, 'message' => 'Title is required'];

        $description = $data['description'] ?? null;
        $rubric_type = $data['rubric_type'] ?? 'analytic';
        $max_points = (float) ($data['max_points'] ?? 100);
        $allow_partial = isset($data['allow_partial_points']) ? (int) $data['allow_partial_points'] : 1;
        $is_template = isset($data['is_template']) ? (int) $data['is_template'] : 0;
        $status = $data['status'] ?? 'draft';

        $stmt = $this->conn->prepare("INSERT INTO proc_rubrics (tenant_id, title, description, rubric_type, max_points, allow_partial_points, is_template, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("isssdisis", $this->tenant_id, $title, $description, $rubric_type, $max_points, $allow_partial, $is_template, $status, $this->user_id);

        if ($stmt->execute()) {
            $rubric_id = $stmt->insert_id;
            $stmt->close();
            if (!empty($data['criteria'])) {
                foreach ($data['criteria'] as $i => $c) {
                    $c['rubric_id'] = $rubric_id;
                    $c['sort_order'] = $i;
                    $this->createCriterion($c);
                }
            }
            return ['success' => true, 'rubric_id' => $rubric_id, 'message' => 'Rubric created'];
        }
        $stmt->close();
        return ['success' => false, 'message' => 'Failed to create rubric'];
    }

    public function updateRubric(int $rubric_id, array $data): array
    {
        $fields = []; $params = []; $types = "";
        if (isset($data['title'])) { $fields[] = "title = ?"; $params[] = trim($data['title']); $types .= "s"; }
        if (array_key_exists('description', $data)) { $fields[] = "description = ?"; $params[] = $data['description']; $types .= "s"; }
        if (isset($data['rubric_type'])) { $fields[] = "rubric_type = ?"; $params[] = $data['rubric_type']; $types .= "s"; }
        if (isset($data['max_points'])) { $fields[] = "max_points = ?"; $params[] = (float) $data['max_points']; $types .= "d"; }
        if (isset($data['status'])) { $fields[] = "status = ?"; $params[] = $data['status']; $types .= "s"; }
        if (empty($fields)) return ['success' => false, 'message' => 'No fields to update'];

        $params[] = $rubric_id; $params[] = $this->tenant_id; $types .= "ii";
        $stmt = $this->conn->prepare("UPDATE proc_rubrics SET " . implode(", ", $fields) . " WHERE rubric_id = ? AND tenant_id = ?");
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
        return ['success' => true, 'message' => 'Rubric updated'];
    }

    public function deleteRubric(int $rubric_id): array
    {
        $stmt = $this->conn->prepare("DELETE FROM proc_rubrics WHERE rubric_id = ? AND tenant_id = ?");
        $stmt->bind_param("ii", $rubric_id, $this->tenant_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected > 0 ? ['success' => true, 'message' => 'Rubric deleted'] : ['success' => false, 'message' => 'Not found'];
    }

    public function getRubric(int $rubric_id): ?array
    {
        $stmt = $this->conn->prepare("SELECT r.*, u.first_name, u.last_name FROM proc_rubrics r LEFT JOIN users u ON r.created_by = u.id WHERE r.rubric_id = ? AND r.tenant_id = ?");
        $stmt->bind_param("ii", $rubric_id, $this->tenant_id);
        $stmt->execute();
        $rubric = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$rubric) return null;
        $rubric['rubric_id'] = (int) $rubric['rubric_id'];
        $rubric['max_points'] = (float) $rubric['max_points'];
        $rubric['is_template'] = (bool) $rubric['is_template'];
        $rubric['criteria'] = $this->getRubricCriteria($rubric_id);
        return $rubric;
    }

    public function getRubrics(array $filters = []): array
    {
        $sql = "SELECT * FROM proc_rubrics WHERE tenant_id = ?";
        $params = [$this->tenant_id]; $types = "i";
        if (!empty($filters['status'])) { $sql .= " AND status = ?"; $params[] = $filters['status']; $types .= "s"; }
        if (!empty($filters['template_only'])) { $sql .= " AND is_template = 1"; }
        $sql .= " ORDER BY title ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rubrics = [];
        while ($row = $result->fetch_assoc()) {
            $row['rubric_id'] = (int) $row['rubric_id'];
            $row['max_points'] = (float) $row['max_points'];
            $rubrics[] = $row;
        }
        $stmt->close();
        return $rubrics;
    }

    public function cloneRubric(int $rubric_id): array
    {
        $original = $this->getRubric($rubric_id);
        if (!$original) return ['success' => false, 'message' => 'Not found'];
        $data = ['title' => $original['title'] . ' (Copy)', 'description' => $original['description'], 'rubric_type' => $original['rubric_type'], 'max_points' => $original['max_points'], 'status' => 'draft', 'criteria' => []];
        foreach ($original['criteria'] as $c) {
            $criterionData = ['title' => $c['title'], 'description' => $c['description'], 'weight' => $c['weight'], 'max_points' => $c['max_points'], 'levels' => []];
            foreach ($c['levels'] as $l) { $criterionData['levels'][] = ['title' => $l['title'], 'description' => $l['description'], 'points' => $l['points'], 'color' => $l['color']]; }
            $data['criteria'][] = $criterionData;
        }
        return $this->createRubric($data);
    }

    // ==========================================
    // CRITERIA & LEVELS
    // ==========================================

    public function createCriterion(array $data): array
    {
        $rubric_id = (int) ($data['rubric_id'] ?? 0);
        $title = trim($data['title'] ?? '');
        if (!$rubric_id || empty($title)) return ['success' => false, 'message' => 'Rubric ID and title required'];
        $description = $data['description'] ?? null;
        $weight = (float) ($data['weight'] ?? 1.0);
        $max_points = (float) ($data['max_points'] ?? 0);
        $sort_order = (int) ($data['sort_order'] ?? 0);

        $stmt = $this->conn->prepare("INSERT INTO proc_rubric_criteria (rubric_id, tenant_id, title, description, weight, max_points, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("iissddi", $rubric_id, $this->tenant_id, $title, $description, $weight, $max_points, $sort_order);
        if ($stmt->execute()) {
            $criterion_id = $stmt->insert_id;
            $stmt->close();
            if (!empty($data['levels'])) {
                foreach ($data['levels'] as $i => $l) {
                    $l['criterion_id'] = $criterion_id;
                    $l['rubric_id'] = $rubric_id;
                    $l['sort_order'] = $i;
                    $this->createLevel($l);
                }
            }
            return ['success' => true, 'criterion_id' => $criterion_id];
        }
        $stmt->close();
        return ['success' => false, 'message' => 'Failed'];
    }

    public function deleteCriterion(int $criterion_id): array
    {
        $stmt = $this->conn->prepare("DELETE FROM proc_rubric_criteria WHERE criterion_id = ? AND tenant_id = ?");
        $stmt->bind_param("ii", $criterion_id, $this->tenant_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected > 0 ? ['success' => true] : ['success' => false, 'message' => 'Not found'];
    }

    public function getRubricCriteria(int $rubric_id): array
    {
        $stmt = $this->conn->prepare("SELECT * FROM proc_rubric_criteria WHERE rubric_id = ? ORDER BY sort_order ASC");
        $stmt->bind_param("i", $rubric_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $criteria = [];
        while ($row = $result->fetch_assoc()) {
            $row['criterion_id'] = (int) $row['criterion_id'];
            $row['weight'] = (float) $row['weight'];
            $row['max_points'] = (float) $row['max_points'];
            $row['levels'] = $this->getCriterionLevels($row['criterion_id']);
            $criteria[] = $row;
        }
        $stmt->close();
        return $criteria;
    }

    public function createLevel(array $data): array
    {
        $criterion_id = (int) ($data['criterion_id'] ?? 0);
        $rubric_id = (int) ($data['rubric_id'] ?? 0);
        $title = trim($data['title'] ?? '');
        if (!$criterion_id || !$rubric_id || empty($title)) return ['success' => false, 'message' => 'Required fields missing'];
        $description = $data['description'] ?? null;
        $points = (float) ($data['points'] ?? 0);
        $percentage = isset($data['percentage']) ? (float) $data['percentage'] : null;
        $sort_order = (int) ($data['sort_order'] ?? 0);
        $color = $data['color'] ?? null;

        $stmt = $this->conn->prepare("INSERT INTO proc_rubric_levels (criterion_id, rubric_id, tenant_id, title, description, points, percentage, sort_order, color) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iiissddis", $criterion_id, $rubric_id, $this->tenant_id, $title, $description, $points, $percentage, $sort_order, $color);
        if ($stmt->execute()) { $id = $stmt->insert_id; $stmt->close(); return ['success' => true, 'level_id' => $id]; }
        $stmt->close();
        return ['success' => false, 'message' => 'Failed'];
    }

    public function getCriterionLevels(int $criterion_id): array
    {
        $stmt = $this->conn->prepare("SELECT * FROM proc_rubric_levels WHERE criterion_id = ? ORDER BY sort_order ASC");
        $stmt->bind_param("i", $criterion_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $levels = [];
        while ($row = $result->fetch_assoc()) {
            $row['level_id'] = (int) $row['level_id'];
            $row['points'] = (float) $row['points'];
            $levels[] = $row;
        }
        $stmt->close();
        return $levels;
    }

    // ==========================================
    // GRADING QUEUE
    // ==========================================

    public function getGradingQueue(array $filters = []): array
    {
        $status = $filters['status'] ?? 'pending';
        $assessment_id = !empty($filters['assessment_id']) ? (int) $filters['assessment_id'] : null;
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $sql = "SELECT gq.*, u.first_name, u.last_name, a.title as assessment_title, aa.answer_data
                FROM proc_grading_queue gq
                JOIN users u ON gq.user_id = u.id
                JOIN proc_assessments a ON gq.assessment_id = a.assessment_id
                JOIN proc_attempt_answers aa ON gq.answer_id = aa.answer_id
                WHERE gq.tenant_id = ?";
        $params = [$this->tenant_id]; $types = "i";
        if ($status && $status !== 'all') { $sql .= " AND gq.status = ?"; $params[] = $status; $types .= "s"; }
        if ($assessment_id) { $sql .= " AND gq.assessment_id = ?"; $params[] = $assessment_id; $types .= "i"; }
        $sql .= " ORDER BY gq.priority DESC, gq.created_at ASC LIMIT ? OFFSET ?";
        $params[] = $limit; $params[] = $offset; $types .= "ii";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $items = [];
        while ($row = $result->fetch_assoc()) {
            $row['queue_id'] = (int) $row['queue_id'];
            $row['max_points'] = (float) $row['max_points'];
            $row['answer_data'] = $row['answer_data'] ? json_decode($row['answer_data'], true) : null;
            $row['learner_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
            $items[] = $row;
        }
        $stmt->close();
        return ['items' => $items, 'counts' => $this->getQueueCounts($assessment_id)];
    }

    public function getQueueCounts(?int $assessment_id = null): array
    {
        $sql = "SELECT status, COUNT(*) as count FROM proc_grading_queue WHERE tenant_id = ?";
        $params = [$this->tenant_id]; $types = "i";
        if ($assessment_id) { $sql .= " AND assessment_id = ?"; $params[] = $assessment_id; $types .= "i"; }
        $sql .= " GROUP BY status";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $counts = ['pending' => 0, 'in_progress' => 0, 'graded' => 0, 'skipped' => 0, 'total' => 0];
        while ($row = $result->fetch_assoc()) { $counts[$row['status']] = (int) $row['count']; $counts['total'] += (int) $row['count']; }
        $stmt->close();
        return $counts;
    }

    public function assignQueueItem(int $queue_id, ?int $grader_id = null): array
    {
        $grader_id = $grader_id ?? $this->user_id;
        $stmt = $this->conn->prepare("UPDATE proc_grading_queue SET assigned_to = ?, assigned_at = NOW(), status = 'in_progress' WHERE queue_id = ? AND tenant_id = ? AND status = 'pending'");
        $stmt->bind_param("iii", $grader_id, $queue_id, $this->tenant_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected > 0 ? ['success' => true] : ['success' => false, 'message' => 'Not available'];
    }

    public function getNextQueueItem(?int $assessment_id = null): ?array
    {
        $sql = "SELECT queue_id FROM proc_grading_queue WHERE tenant_id = ? AND status = 'pending'";
        $params = [$this->tenant_id]; $types = "i";
        if ($assessment_id) { $sql .= " AND assessment_id = ?"; $params[] = $assessment_id; $types .= "i"; }
        $sql .= " ORDER BY priority DESC, created_at ASC LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($item) {
            $this->assignQueueItem((int) $item['queue_id']);
            return $this->getAnswerForGrading((int) $item['queue_id']);
        }
        return null;
    }

    public function getAnswerForGrading(int $queue_id): ?array
    {
        $stmt = $this->conn->prepare("SELECT gq.*, aa.answer_data, aa.points_earned, aa.grader_feedback, u.first_name, u.last_name, u.email, a.title as assessment_title, q.question_html, q.explanation FROM proc_grading_queue gq JOIN proc_attempt_answers aa ON gq.answer_id = aa.answer_id JOIN users u ON gq.user_id = u.id JOIN proc_assessments a ON gq.assessment_id = a.assessment_id JOIN proc_questions q ON gq.question_id = q.question_id WHERE gq.queue_id = ? AND gq.tenant_id = ?");
        $stmt->bind_param("ii", $queue_id, $this->tenant_id);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$item) return null;
        $item['queue_id'] = (int) $item['queue_id'];
        $item['max_points'] = (float) $item['max_points'];
        $item['answer_data'] = $item['answer_data'] ? json_decode($item['answer_data'], true) : null;
        $item['learner_name'] = trim($item['first_name'] . ' ' . $item['last_name']);
        $item['rubric'] = $this->getQuestionRubric($item['question_id'], $item['assessment_id']);
        $item['comments'] = $this->getAnswerComments($item['answer_id']);
        return $item;
    }

    // ==========================================
    // MANUAL GRADING
    // ==========================================

    public function gradeAnswer(int $answer_id, array $data): array
    {
        $points = (float) ($data['points'] ?? 0);
        $feedback = $data['feedback'] ?? null;
        $is_correct = isset($data['is_correct']) ? (int) $data['is_correct'] : ($points > 0 ? 1 : 0);
        $is_partial = isset($data['is_partial']) ? (int) $data['is_partial'] : 0;

        $stmt = $this->conn->prepare("SELECT max_points FROM proc_attempt_answers WHERE answer_id = ?");
        $stmt->bind_param("i", $answer_id);
        $stmt->execute();
        $answer = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$answer) return ['success' => false, 'message' => 'Answer not found'];
        $points = min($points, (float) $answer['max_points']);

        $stmt = $this->conn->prepare("UPDATE proc_attempt_answers SET points_earned = ?, is_correct = ?, is_partial = ?, manually_graded = 1, graded_by = ?, graded_at = NOW(), grader_feedback = ? WHERE answer_id = ?");
        $stmt->bind_param("diiisi", $points, $is_correct, $is_partial, $this->user_id, $feedback, $answer_id);
        if ($stmt->execute()) {
            $stmt->close();
            $this->updateQueueStatus($answer_id, 'graded');
            return ['success' => true, 'message' => 'Answer graded', 'points' => $points];
        }
        $stmt->close();
        return ['success' => false, 'message' => 'Failed'];
    }

    public function gradeWithRubric(int $answer_id, int $rubric_id, array $scores): array
    {
        $totalPoints = 0; $maxPoints = 0;
        foreach ($scores as $criterion_id => $score) {
            $points_awarded = (float) ($score['points'] ?? 0);
            $level_id = !empty($score['level_id']) ? (int) $score['level_id'] : null;
            $feedback = $score['feedback'] ?? null;

            $stmt = $this->conn->prepare("SELECT max_points FROM proc_rubric_criteria WHERE criterion_id = ?");
            $stmt->bind_param("i", $criterion_id);
            $stmt->execute();
            $criterion = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$criterion) continue;

            $criterion_max = (float) $criterion['max_points'];
            $maxPoints += $criterion_max;
            $totalPoints += min($points_awarded, $criterion_max);

            $stmt = $this->conn->prepare("INSERT INTO proc_grading_rubric_scores (answer_id, rubric_id, criterion_id, level_id, tenant_id, points_awarded, max_points, feedback, graded_by, graded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE level_id = VALUES(level_id), points_awarded = VALUES(points_awarded), feedback = VALUES(feedback), graded_by = VALUES(graded_by), graded_at = NOW()");
            $stmt->bind_param("iiiiddssi", $answer_id, $rubric_id, $criterion_id, $level_id, $this->tenant_id, $points_awarded, $criterion_max, $feedback, $this->user_id);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $this->conn->prepare("SELECT max_points FROM proc_attempt_answers WHERE answer_id = ?");
        $stmt->bind_param("i", $answer_id);
        $stmt->execute();
        $answer = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $answerMaxPoints = (float) $answer['max_points'];
        $scaledPoints = $maxPoints > 0 ? ($totalPoints / $maxPoints) * $answerMaxPoints : 0;

        return $this->gradeAnswer($answer_id, ['points' => $scaledPoints, 'is_correct' => $scaledPoints >= ($answerMaxPoints * 0.7) ? 1 : 0, 'is_partial' => ($scaledPoints > 0 && $scaledPoints < $answerMaxPoints) ? 1 : 0]);
    }

    private function updateQueueStatus(int $answer_id, string $status): void
    {
        $stmt = $this->conn->prepare("UPDATE proc_grading_queue SET status = ?, graded_by = ?, graded_at = NOW() WHERE answer_id = ? AND tenant_id = ?");
        $stmt->bind_param("siii", $status, $this->user_id, $answer_id, $this->tenant_id);
        $stmt->execute();
        $stmt->close();
    }

    // ==========================================
    // FEEDBACK COMMENTS
    // ==========================================

    public function addComment(int $answer_id, array $data): array
    {
        $comment_type = $data['comment_type'] ?? 'general';
        $comment_text = trim($data['comment_text'] ?? '');
        if (empty($comment_text)) return ['success' => false, 'message' => 'Comment text required'];
        $start_position = isset($data['start_position']) ? (int) $data['start_position'] : null;
        $end_position = isset($data['end_position']) ? (int) $data['end_position'] : null;
        $selected_text = $data['selected_text'] ?? null;
        $visible_to_learner = isset($data['visible_to_learner']) ? (int) $data['visible_to_learner'] : 1;

        $stmt = $this->conn->prepare("INSERT INTO proc_feedback_comments (answer_id, tenant_id, comment_type, comment_text, start_position, end_position, selected_text, visible_to_learner, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("iissiiisi", $answer_id, $this->tenant_id, $comment_type, $comment_text, $start_position, $end_position, $selected_text, $visible_to_learner, $this->user_id);
        if ($stmt->execute()) { $id = $stmt->insert_id; $stmt->close(); return ['success' => true, 'comment_id' => $id]; }
        $stmt->close();
        return ['success' => false, 'message' => 'Failed'];
    }

    public function getAnswerComments(int $answer_id): array
    {
        $stmt = $this->conn->prepare("SELECT c.*, u.first_name, u.last_name FROM proc_feedback_comments c LEFT JOIN users u ON c.created_by = u.id WHERE c.answer_id = ? ORDER BY c.created_at ASC");
        $stmt->bind_param("i", $answer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $comments = [];
        while ($row = $result->fetch_assoc()) { $row['comment_id'] = (int) $row['comment_id']; $row['visible_to_learner'] = (bool) $row['visible_to_learner']; $comments[] = $row; }
        $stmt->close();
        return $comments;
    }

    public function deleteComment(int $comment_id): array
    {
        $stmt = $this->conn->prepare("DELETE FROM proc_feedback_comments WHERE comment_id = ? AND tenant_id = ?");
        $stmt->bind_param("ii", $comment_id, $this->tenant_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return $affected > 0 ? ['success' => true] : ['success' => false, 'message' => 'Not found'];
    }

    // ==========================================
    // QUICK FEEDBACK
    // ==========================================

    public function getQuickFeedback(?string $category = null): array
    {
        $sql = "SELECT * FROM proc_quick_feedback WHERE (tenant_id = ? OR tenant_id = 0)";
        $params = [$this->tenant_id]; $types = "i";
        if ($category) { $sql .= " AND category = ?"; $params[] = $category; $types .= "s"; }
        $sql .= " ORDER BY usage_count DESC, title ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $feedback = [];
        while ($row = $result->fetch_assoc()) { $row['feedback_id'] = (int) $row['feedback_id']; $feedback[] = $row; }
        $stmt->close();
        return $feedback;
    }

    public function createQuickFeedback(array $data): array
    {
        $title = trim($data['title'] ?? '');
        $content = trim($data['content'] ?? '');
        if (empty($title) || empty($content)) return ['success' => false, 'message' => 'Title and content required'];
        $category = $data['category'] ?? 'general';
        $feedback_type = $data['feedback_type'] ?? 'neutral';
        $is_global = isset($data['is_global']) ? (int) $data['is_global'] : 0;

        $stmt = $this->conn->prepare("INSERT INTO proc_quick_feedback (tenant_id, title, content, category, feedback_type, is_global, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("issssii", $this->tenant_id, $title, $content, $category, $feedback_type, $is_global, $this->user_id);
        if ($stmt->execute()) { $id = $stmt->insert_id; $stmt->close(); return ['success' => true, 'feedback_id' => $id]; }
        $stmt->close();
        return ['success' => false, 'message' => 'Failed'];
    }

    // ==========================================
    // GRADE ADJUSTMENTS
    // ==========================================

    public function createAdjustment(int $attempt_id, array $data): array
    {
        $scope = $data['scope'] ?? 'attempt';
        $answer_id = !empty($data['answer_id']) ? (int) $data['answer_id'] : null;
        $adjustment_type = $data['adjustment_type'] ?? 'bonus';
        $adjustment_value = (float) ($data['adjustment_value'] ?? 0);
        $adjustment_unit = $data['adjustment_unit'] ?? 'points';
        $reason = trim($data['reason'] ?? '');
        if (empty($reason)) return ['success' => false, 'message' => 'Reason required'];

        if ($scope === 'attempt') {
            $stmt = $this->conn->prepare("SELECT raw_score FROM proc_attempts WHERE attempt_id = ?");
            $stmt->bind_param("i", $attempt_id);
        } else {
            $stmt = $this->conn->prepare("SELECT points_earned as raw_score FROM proc_attempt_answers WHERE answer_id = ?");
            $stmt->bind_param("i", $answer_id);
        }
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $original_score = (float) ($result['raw_score'] ?? 0);

        $adjusted_score = $original_score;
        if ($adjustment_type === 'bonus') { $adjusted_score = $adjustment_unit === 'percentage' ? $original_score * (1 + $adjustment_value / 100) : $original_score + $adjustment_value; }
        elseif ($adjustment_type === 'penalty') { $adjusted_score = $adjustment_unit === 'percentage' ? $original_score * (1 - $adjustment_value / 100) : $original_score - $adjustment_value; }
        elseif ($adjustment_type === 'override') { $adjusted_score = $adjustment_value; }

        $stmt = $this->conn->prepare("INSERT INTO proc_grade_adjustments (attempt_id, tenant_id, scope, answer_id, adjustment_type, adjustment_value, adjustment_unit, original_score, adjusted_score, reason, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt->bind_param("iisissddssi", $attempt_id, $this->tenant_id, $scope, $answer_id, $adjustment_type, $adjustment_value, $adjustment_unit, $original_score, $adjusted_score, $reason, $this->user_id);
        if ($stmt->execute()) {
            $adjustment_id = $stmt->insert_id;
            $stmt->close();
            if ($scope === 'attempt') {
                $upd = $this->conn->prepare("UPDATE proc_attempts SET raw_score = ? WHERE attempt_id = ?");
                $upd->bind_param("di", $adjusted_score, $attempt_id);
                $upd->execute();
                $upd->close();
            } else {
                $upd = $this->conn->prepare("UPDATE proc_attempt_answers SET points_earned = ? WHERE answer_id = ?");
                $upd->bind_param("di", $adjusted_score, $answer_id);
                $upd->execute();
                $upd->close();
            }
            return ['success' => true, 'adjustment_id' => $adjustment_id, 'adjusted_score' => $adjusted_score];
        }
        $stmt->close();
        return ['success' => false, 'message' => 'Failed'];
    }

    public function getAdjustments(int $attempt_id): array
    {
        $stmt = $this->conn->prepare("SELECT a.*, u.first_name, u.last_name FROM proc_grade_adjustments a LEFT JOIN users u ON a.created_by = u.id WHERE a.attempt_id = ? AND a.tenant_id = ? ORDER BY a.created_at DESC");
        $stmt->bind_param("ii", $attempt_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $adjustments = [];
        while ($row = $result->fetch_assoc()) { $row['adjustment_id'] = (int) $row['adjustment_id']; $adjustments[] = $row; }
        $stmt->close();
        return $adjustments;
    }

    // ==========================================
    // QUESTION RUBRICS
    // ==========================================

    public function assignRubricToQuestion(int $question_id, int $rubric_id, ?int $assessment_id = null): array
    {
        $stmt = $this->conn->prepare("INSERT INTO proc_question_rubrics (question_id, rubric_id, tenant_id, assessment_id, created_at) VALUES (?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE rubric_id = VALUES(rubric_id)");
        $stmt->bind_param("iiii", $question_id, $rubric_id, $this->tenant_id, $assessment_id);
        $stmt->execute();
        $stmt->close();
        return ['success' => true, 'message' => 'Rubric assigned'];
    }

    public function getQuestionRubric(int $question_id, ?int $assessment_id = null): ?array
    {
        $stmt = $this->conn->prepare("SELECT rubric_id FROM proc_question_rubrics WHERE question_id = ? AND tenant_id = ? AND (assessment_id = ? OR assessment_id IS NULL) ORDER BY assessment_id DESC LIMIT 1");
        $stmt->bind_param("iii", $question_id, $this->tenant_id, $assessment_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $result ? $this->getRubric((int) $result['rubric_id']) : null;
    }

    // ==========================================
    // BATCH & STATS
    // ==========================================

    public function batchGrade(array $answer_ids, float $points, ?string $feedback = null): array
    {
        $graded = 0; $failed = 0;
        foreach ($answer_ids as $answer_id) {
            $result = $this->gradeAnswer((int) $answer_id, ['points' => $points, 'feedback' => $feedback]);
            $result['success'] ? $graded++ : $failed++;
        }
        return ['success' => true, 'graded' => $graded, 'failed' => $failed];
    }

    public function releaseGrades(int $assessment_id): array
    {
        $stmt = $this->conn->prepare("UPDATE proc_attempts SET status = 'graded' WHERE assessment_id = ? AND tenant_id = ? AND status IN ('submitted', 'auto_submitted', 'grading') AND pending_grading = 0");
        $stmt->bind_param("ii", $assessment_id, $this->tenant_id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        return ['success' => true, 'released' => $affected];
    }

    public function getGradingStats(?int $assessment_id = null): array
    {
        $sql = "SELECT COUNT(*) as total, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN status = 'graded' THEN 1 ELSE 0 END) as graded, AVG(grading_time_seconds) as avg_time FROM proc_grading_queue WHERE tenant_id = ?";
        $params = [$this->tenant_id]; $types = "i";
        if ($assessment_id) { $sql .= " AND assessment_id = ?"; $params[] = $assessment_id; $types .= "i"; }
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return ['total_items' => (int) $stats['total'], 'pending' => (int) $stats['pending'], 'graded' => (int) $stats['graded'], 'avg_grading_time_seconds' => $stats['avg_time'] ? round((float) $stats['avg_time']) : null, 'completion_rate' => $stats['total'] > 0 ? round(((int) $stats['graded'] / (int) $stats['total']) * 100, 1) : 0];
    }

    public function getGradingHistory(int $answer_id): array
    {
        $stmt = $this->conn->prepare("SELECT h.*, u.first_name, u.last_name FROM proc_grading_history h LEFT JOIN users u ON h.performed_by = u.id WHERE h.answer_id = ? ORDER BY h.performed_at DESC");
        $stmt->bind_param("i", $answer_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $history = [];
        while ($row = $result->fetch_assoc()) { $row['history_id'] = (int) $row['history_id']; $history[] = $row; }
        $stmt->close();
        return $history;
    }
}
