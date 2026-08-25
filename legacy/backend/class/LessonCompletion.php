<?php

class LessonCompletion {
    public ?int $id = null;
    public ?int $tenant_id = null;
    public ?int $lesson_id = null;
    public ?int $user_id = null;
    public ?int $time_spent_seconds = null;
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO lesson_completions (tenant_id, lesson_id, user_id, time_spent_seconds) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiii", $this->tenant_id, $this->lesson_id, $this->user_id, $this->time_spent_seconds);
        return $stmt->execute();
    }

    public function check_completed(): bool {
        $stmt = $this->conn->prepare("SELECT completion_id FROM lesson_completions WHERE lesson_id=? AND user_id=? AND tenant_id=?");
        $stmt->bind_param("iii", $this->lesson_id, $this->user_id, $this->tenant_id);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

    public function get_user_completions_for_module(int $module_id): array {
        $stmt = $this->conn->prepare("SELECT lc.* FROM lesson_completions lc JOIN lessons l ON lc.lesson_id = l.lesson_id WHERE l.module_id=? AND lc.user_id=? AND lc.tenant_id=?");
        $stmt->bind_param("iii", $module_id, $this->user_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}