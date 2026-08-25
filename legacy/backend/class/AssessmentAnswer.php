<?php

class AssessmentAnswer {
    public ?int $answer_id = null;
    public ?int $tenant_id;
    public ?int $attempt_id;
    public ?int $question_id;
    public ?int $selected_option_id = null;
    public string $answer_text = '';
    public ?bool $is_correct = null;
    public ?float $points_earned = null;
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO assessment_answers (tenant_id, attempt_id, question_id, selected_option_id, answer_text, is_correct, points_earned) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("iiiisid", $this->tenant_id, $this->attempt_id, $this->question_id, $this->selected_option_id, $this->answer_text, $this->is_correct, $this->points_earned);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT aa.*, aq.question_text, aq.question_type, ao.option_text as selected_option FROM assessment_answers aa LEFT JOIN assessment_questions aq ON aa.question_id = aq.question_id LEFT JOIN assessment_options ao ON aa.selected_option_id = ao.option_id WHERE aa.answer_id=? AND aa.tenant_id=?");
        $stmt->bind_param("ii", $this->answer_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Answer not found";
    }

    public function get_attempt_answers(int $attempt_id): array {
        $stmt = $this->conn->prepare("SELECT aa.*, aq.question_text, aq.question_type, ao.option_text as selected_option FROM assessment_answers aa LEFT JOIN assessment_questions aq ON aa.question_id = aq.question_id LEFT JOIN assessment_options ao ON aa.selected_option_id = ao.option_id WHERE aa.attempt_id=? AND aa.tenant_id=?");
        $stmt->bind_param("ii", $attempt_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}