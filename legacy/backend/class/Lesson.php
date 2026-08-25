<?php

class Lesson {
    public ?int $id = null;
    public ?int $tenant_id = null;
    public ?int $module_id = null;
    public string $title = '';
    public string $content_type = '';
    public string $content_url = '';
    public string $content_text = '';
    public ?int $duration_minutes = null;
    public int $sort_order = 1;
    public bool $is_preview = false;
    public bool $is_active = true;
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'sort_order';
    public string $sort_dir = 'asc';
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO lessons (tenant_id, module_id, title, content_type, content_url, content_text, duration_minutes, sort_order, is_preview) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissssiis", $this->tenant_id, $this->module_id, $this->title, $this->content_type, $this->content_url, $this->content_text, $this->duration_minutes, $this->sort_order, $this->is_preview);
        return $stmt->execute();
    }

    public function update(): bool {
        $stmt = $this->conn->prepare("UPDATE lessons SET title=?, content_type=?, content_url=?, content_text=?, duration_minutes=?, sort_order=?, is_preview=?, is_active=? WHERE lesson_id=? AND tenant_id=?");
        $stmt->bind_param("ssssiiiii", $this->title, $this->content_type, $this->content_url, $this->content_text, $this->duration_minutes, $this->sort_order, $this->is_preview, $this->is_active, $this->id, $this->tenant_id);
        return $stmt->execute();
    }

    public function delete(): bool {
        $stmt = $this->conn->prepare("UPDATE lessons SET is_active=? WHERE lesson_id=? AND tenant_id=?");
        $stmt->bind_param("iii", $this->is_active, $this->id, $this->tenant_id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT * FROM lessons WHERE lesson_id=? AND tenant_id=?");
        $stmt->bind_param("ii", $this->id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Lesson not found";
    }

    public function list(): array {
        $page = max(1, $this->page);
        $perPage = max(1, min(100, $this->per_page));
        $offset = ($page - 1) * $perPage;
        $where = "WHERE tenant_id=?";
        $params = [$this->tenant_id];
        $types = "i";
        if ($this->module_id) { $where .= " AND module_id=?"; $params[] = $this->module_id; $types .= "i"; }
        if ($this->is_active ?? null) { $where .= " AND is_active=?"; $params[] = $this->is_active; $types .= "i"; }
        $allowedColumns = ['lesson_id', 'title', 'sort_order', 'created_at'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'sort_order';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'ASC';
        $countQuery = "SELECT COUNT(*) AS total FROM lessons $where";
        $countStmt = $this->conn->prepare($countQuery);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $query = "SELECT * FROM lessons $where ORDER BY $sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return ["data" => $rows, "meta" => ["total" => $total, "page" => $page, "per_page" => $perPage, "total_pages" => $totalPages, "sort_by" => $sortBy, "sort_dir" => $sortDir]];
    }
}