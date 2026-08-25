<?php

class UserLevel {
    public ?int $level_id = null;
    public ?int $tenant_id;
    public int $level_number = 1;
    public string $level_name = '';
    public int $required_points = 0;
    public string $level_icon_url = '';
    public string $rewards_description = '';
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'level_number';
    public string $sort_dir = 'asc';
    public bool $is_active = true;
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO user_levels (tenant_id, level_number, level_name, required_points, level_icon_url, rewards_description) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("iisiss", $this->tenant_id, $this->level_number, $this->level_name, $this->required_points, $this->level_icon_url, $this->rewards_description);
        return $stmt->execute();
    }

    public function update(): bool {
        $stmt = $this->conn->prepare("UPDATE user_levels SET level_name=?, required_points=?, level_icon_url=?, rewards_description=?, is_active=? WHERE level_id=? AND tenant_id=?");
        $stmt->bind_param("sissiii", $this->level_name, $this->required_points, $this->level_icon_url, $this->rewards_description, $this->is_active, $this->level_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function delete(): bool {
        $stmt = $this->conn->prepare("UPDATE user_levels SET is_active=? WHERE level_id=? AND tenant_id=?");
        $stmt->bind_param("iii", $this->is_active, $this->level_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT * FROM user_levels WHERE level_id=? AND tenant_id=?");
        $stmt->bind_param("ii", $this->level_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Level not found";
    }

    public function get_level_by_points(int $points): array|string {
        $stmt = $this->conn->prepare("SELECT * FROM user_levels WHERE tenant_id=? AND required_points <= ? AND is_active=1 ORDER BY required_points DESC LIMIT 1");
        $stmt->bind_param("ii", $this->tenant_id, $points);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "No level found";
    }

    public function get_next_level(int $current_level_number): array|string {
        $stmt = $this->conn->prepare("SELECT * FROM user_levels WHERE tenant_id=? AND level_number > ? AND is_active=1 ORDER BY level_number ASC LIMIT 1");
        $stmt->bind_param("ii", $this->tenant_id, $current_level_number);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "No next level";
    }

    public function list(): array {
        $page = max(1, $this->page);
        $perPage = max(1, min(100, $this->per_page));
        $offset = ($page - 1) * $perPage;
        $where = "WHERE tenant_id=?";
        $params = [$this->tenant_id];
        $types = "i";

        if ($this->is_active !== null) {
            $where .= " AND is_active=?";
            $params[] = $this->is_active;
            $types .= "i";
        }

        $allowedColumns = ['level_id', 'level_number', 'level_name', 'required_points', 'created_at'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'level_number';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'ASC';

        $countQuery = "SELECT COUNT(*) AS total FROM user_levels $where";
        $countStmt = $this->conn->prepare($countQuery);
        if ($types) $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));

        $query = "SELECT * FROM user_levels $where ORDER BY $sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return ["data" => $rows, "meta" => ["total" => $total, "page" => $page, "per_page" => $perPage, "total_pages" => $totalPages, "sort_by" => $sortBy, "sort_dir" => $sortDir]];
    }
}