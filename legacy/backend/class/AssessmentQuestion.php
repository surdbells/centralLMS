<?php

class AssessmentQuestion {
    public ?int $id = null;
    public ?int $tenant_id = null;
    public ?int $assessment_id = null;
    public string $question_text = '';
    public string $question_type = '';
    public float $points = 1;
    public int $sort_order = 1;
    public bool $is_active = true;
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'sort_order';
    public string $sort_dir = 'asc';
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO assessment_questions (tenant_id, assessment_id, question_text, question_type, points, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissdi", $this->tenant_id, $this->assessment_id, $this->question_text, $this->question_type, $this->points, $this->sort_order);
        return $stmt->execute();
    }

    public function update(): bool {
        $stmt = $this->conn->prepare("UPDATE assessment_questions SET question_text=?, question_type=?, points=?, sort_order=?, is_active=? WHERE question_id=? AND tenant_id=?");
        $stmt->bind_param("ssdiii", $this->question_text, $this->question_type, $this->points, $this->sort_order, $this->is_active, $this->id, $this->tenant_id);
        return $stmt->execute();
    }

    public function delete(): bool {
        $stmt = $this->conn->prepare("UPDATE assessment_questions SET is_active=? WHERE question_id=? AND tenant_id=?");
        $stmt->bind_param("iii", $this->is_active, $this->id, $this->tenant_id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT * FROM assessment_questions WHERE question_id=? AND tenant_id=?");
        $stmt->bind_param("ii", $this->id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Question not found";
    }

    public function get_options(): array {
        $stmt = $this->conn->prepare("SELECT * FROM assessment_options WHERE question_id=? AND tenant_id=? ORDER BY sort_order ASC");
        $stmt->bind_param("ii", $this->id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function list(): array {
        $page = max(1, $this->page);
        $perPage = max(1, min(100, $this->per_page));
        $offset = ($page - 1) * $perPage;
        $where = "WHERE tenant_id=? AND assessment_id=?";
        $params = [$this->tenant_id, $this->assessment_id];
        $types = "ii";
        if ($this->is_active ?? null) { $where .= " AND is_active=?"; $params[] = $this->is_active; $types .= "i"; }
        $allowedColumns = ['question_id', 'sort_order', 'points'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'sort_order';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'ASC';
        $countQuery = "SELECT COUNT(*) AS total FROM assessment_questions $where";
        $countStmt = $this->conn->prepare($countQuery);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $query = "SELECT * FROM assessment_questions $where ORDER BY $sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return ["data" => $rows, "meta" => ["total" => $total, "page" => $page, "per_page" => $perPage, "total_pages" => $totalPages, "sort_by" => $sortBy, "sort_dir" => $sortDir]];
    }
}