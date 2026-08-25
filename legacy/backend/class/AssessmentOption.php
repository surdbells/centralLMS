<?php

class AssessmentOption {
    public ?int $id = null;
    public ?int $tenant_id = null;
    public ?int $question_id = null;
    public string $option_text = '';
    public bool $is_correct = false;
    public int $sort_order = 1;
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO assessment_options (tenant_id, question_id, option_text, is_correct, sort_order) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("iisii", $this->tenant_id, $this->question_id, $this->option_text, $this->is_correct, $this->sort_order);
        return $stmt->execute();
    }

    public function update(): bool {
        $stmt = $this->conn->prepare("UPDATE assessment_options SET option_text=?, is_correct=?, sort_order=? WHERE option_id=? AND tenant_id=?");
        $stmt->bind_param("siiii", $this->option_text, $this->is_correct, $this->sort_order, $this->id, $this->tenant_id);
        return $stmt->execute();
    }

    public function delete(): bool {
        $stmt = $this->conn->prepare("DELETE FROM assessment_options WHERE option_id=? AND tenant_id=?");
        $stmt->bind_param("ii", $this->id, $this->tenant_id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT * FROM assessment_options WHERE option_id=? AND tenant_id=?");
        $stmt->bind_param("ii", $this->id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Option not found";
    }
}