<?php

class UserBadge {
    public ?int $user_badge_id = null;
    public ?int $tenant_id;
    public ?int $user_id;
    public ?int $badge_id;
    public string $entity_type = '';
    public ?int $entity_id = null;
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'user_badge_id';
    public string $sort_dir = 'desc';
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO user_badges (tenant_id, user_id, badge_id, entity_type, entity_id) VALUES (?,?,?,?,?)");
        $stmt->bind_param("iiisi", $this->tenant_id, $this->user_id, $this->badge_id, $this->entity_type, $this->entity_id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT ub.*, b.badge_name, b.icon, b.points_value, CONCAT(u.first_name, ' ', u.last_name) as learner_name FROM user_badges ub LEFT JOIN badges b ON ub.badge_id = b.badge_id LEFT JOIN users u ON ub.user_id = u.id WHERE ub.user_badge_id=? AND ub.tenant_id=?");
        $stmt->bind_param("ii", $this->user_badge_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "User badge not found";
    }

    public function check_badge_earned(): bool {
        $stmt = $this->conn->prepare("SELECT user_badge_id FROM user_badges WHERE user_id=? AND badge_id=? AND tenant_id=?");
        $stmt->bind_param("iii", $this->user_id, $this->badge_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }

    public function get_user_badges(int $user_id): array {
        $stmt = $this->conn->prepare("SELECT ub.*, b.badge_name, b.icon, b.badge_type, b.points_value FROM user_badges ub LEFT JOIN badges b ON ub.badge_id = b.badge_id WHERE ub.user_id=? AND ub.tenant_id=? ORDER BY ub.earned_at DESC");
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function list(): array {
        $page = max(1, $this->page);
        $perPage = max(1, min(100, $this->per_page));
        $offset = ($page - 1) * $perPage;
        $where = "WHERE ub.tenant_id=?";
        $params = [$this->tenant_id];
        $types = "i";

        if ($this->user_id) {
            $where .= " AND ub.user_id=?";
            $params[] = $this->user_id;
            $types .= "i";
        }

        if ($this->badge_id) {
            $where .= " AND ub.badge_id=?";
            $params[] = $this->badge_id;
            $types .= "i";
        }

        $allowedColumns = ['user_badge_id', 'earned_at'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'user_badge_id';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'DESC';

        $countQuery = "SELECT COUNT(*) AS total FROM user_badges ub $where";
        $countStmt = $this->conn->prepare($countQuery);
        if ($types) $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));

        $query = "SELECT ub.*, b.badge_name, b.badge_icon_url, b.points_value, CONCAT(u.first_name, ' ', u.last_name) as learner_name FROM user_badges ub LEFT JOIN badges b ON ub.badge_id = b.badge_id LEFT JOIN users u ON ub.user_id = u.id $where ORDER BY ub.$sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return ["data" => $rows, "meta" => ["total" => $total, "page" => $page, "per_page" => $perPage, "total_pages" => $totalPages, "sort_by" => $sortBy, "sort_dir" => $sortDir]];
    }
}