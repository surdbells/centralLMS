<?php

class Badge {
    public ?int $badge_id = null;
    public ?int $tenant_id;
    public string $badge_name = '';
    public string $description = '';
    public string $badge_icon_url = '';
    public string $badge_type = 'achievement';
    public int $points_value = 0;
    public string $criteria_type = '';
    public string $criteria_value = '';
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'badge_id';
    public string $sort_dir = 'asc';
    public bool $is_active = true;
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO badges (tenant_id, badge_name, description, badge_icon_url, badge_type, points_value, criteria_type, criteria_value) VALUES (?,?,?,?,?,?,?,?)");
        $stmt->bind_param("issssiss", $this->tenant_id, $this->badge_name, $this->description, $this->badge_icon_url, $this->badge_type, $this->points_value, $this->criteria_type, $this->criteria_value);
        return $stmt->execute();
    }

    public function update(): bool {
        $stmt = $this->conn->prepare("UPDATE badges SET badge_name=?, description=?, badge_icon_url=?, badge_type=?, points_value=?, criteria_type=?, criteria_value=?, is_active=? WHERE badge_id=? AND tenant_id=?");
        $stmt->bind_param("ssssissiii", $this->badge_name, $this->description, $this->badge_icon_url, $this->badge_type, $this->points_value, $this->criteria_type, $this->criteria_value, $this->is_active, $this->badge_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function delete(): bool {
        $stmt = $this->conn->prepare("UPDATE badges SET is_active=? WHERE badge_id=? AND tenant_id=?");
        $stmt->bind_param("iii", $this->is_active, $this->badge_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT * FROM badges WHERE badge_id=? AND tenant_id=?");
        $stmt->bind_param("ii", $this->badge_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Badge not found";
    }

    public function get_earned_count(): int {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM user_badges WHERE badge_id=? AND tenant_id=?");
        $stmt->bind_param("ii", $this->badge_id, $this->tenant_id);
        $stmt->execute();
        return (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0);
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

        if ($this->badge_type) {
            $where .= " AND badge_type=?";
            $params[] = $this->badge_type;
            $types .= "s";
        }

        $allowedColumns = ['badge_id', 'badge_name', 'badge_type', 'points_value', 'created_at'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'badge_id';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'ASC';

        $countQuery = "SELECT COUNT(*) AS total FROM badges $where";
        $countStmt = $this->conn->prepare($countQuery);
        if ($types) $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));

        $query = "SELECT b.*, (SELECT COUNT(*) FROM user_badges WHERE badge_id = b.badge_id) as earned_count FROM badges b $where ORDER BY $sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return ["data" => $rows, "meta" => ["total" => $total, "page" => $page, "per_page" => $perPage, "total_pages" => $totalPages, "sort_by" => $sortBy, "sort_dir" => $sortDir]];
    }
}