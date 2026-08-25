<?php

class AssessmentAttempt {
    public ?int $id = null;
    public ?int $tenant_id = null;
    public ?int $assessment_id = null;
    public ?int $user_id = null;
    public ?float $score = null;
    public ?bool $passed = null;
    public int $attempt_number = 1;
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'started_at';
    public string $sort_dir = 'desc';
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $this->attempt_number = $this->get_next_attempt_number();
        $stmt = $this->conn->prepare("INSERT INTO assessment_attempts (tenant_id, assessment_id, user_id, attempt_number) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiii", $this->tenant_id, $this->assessment_id, $this->user_id, $this->attempt_number);
        if ($stmt->execute()) {
            $this->id = $this->conn->insert_id;
            return true;
        }
        return false;
    }

    public function submit(float $score, bool $passed): bool {
        $stmt = $this->conn->prepare("UPDATE assessment_attempts SET submitted_at=NOW(), score=?, passed=? WHERE attempt_id=? AND tenant_id=?");
        $stmt->bind_param("diii", $score, $passed, $this->id, $this->tenant_id);
        return $stmt->execute();
    }

    private function get_next_attempt_number(): int {
        $stmt = $this->conn->prepare("SELECT MAX(attempt_number) as max_attempt FROM assessment_attempts WHERE assessment_id=? AND user_id=? AND tenant_id=?");
        $stmt->bind_param("iii", $this->assessment_id, $this->user_id, $this->tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return ($result['max_attempt'] ?? 0) + 1;
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT aa.*, a.title as assessment_title, u.first_name, u.last_name FROM assessment_attempts aa LEFT JOIN assessments a ON aa.assessment_id = a.assessment_id LEFT JOIN users u ON aa.user_id = u.id WHERE aa.attempt_id=? AND aa.tenant_id=?");
        $stmt->bind_param("ii", $this->id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Attempt not found";
    }

    public function get_user_attempts(): array {
        $stmt = $this->conn->prepare("SELECT * FROM assessment_attempts WHERE assessment_id=? AND user_id=? AND tenant_id=? ORDER BY attempt_number DESC");
        $stmt->bind_param("iii", $this->assessment_id, $this->user_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function list(): array {
        $page = max(1, $this->page);
        $perPage = max(1, min(100, $this->per_page));
        $offset = ($page - 1) * $perPage;
        $where = "WHERE aa.tenant_id=?";
        $params = [$this->tenant_id];
        $types = "i";
        if ($this->assessment_id) { $where .= " AND aa.assessment_id=?"; $params[] = $this->assessment_id; $types .= "i"; }
        if ($this->user_id) { $where .= " AND aa.user_id=?"; $params[] = $this->user_id; $types .= "i"; }
        $allowedColumns = ['attempt_id', 'started_at', 'submitted_at', 'score'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'started_at';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'DESC';
        $countQuery = "SELECT COUNT(*) AS total FROM assessment_attempts aa $where";
        $countStmt = $this->conn->prepare($countQuery);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $query = "SELECT aa.*, a.title as assessment_title, u.first_name, u.last_name FROM assessment_attempts aa LEFT JOIN assessments a ON aa.assessment_id = a.assessment_id LEFT JOIN users u ON aa.user_id = u.id $where ORDER BY aa.$sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return ["data" => $rows, "meta" => ["total" => $total, "page" => $page, "per_page" => $perPage, "total_pages" => $totalPages, "sort_by" => $sortBy, "sort_dir" => $sortDir]];
    }
}