<?php

class UserPoint {
    public ?int $point_id = null;
    public ?int $tenant_id;
    public ?int $user_id;
    public int $points = 0;
    public string $point_type = '';
    public string $description = '';
    public string $entity_type = '';
    public ?int $entity_id = null;
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'point_id';
    public string $sort_dir = 'desc';
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO user_points (tenant_id, user_id, points, point_type, description, entity_type, entity_id) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("iiisssi", $this->tenant_id, $this->user_id, $this->points, $this->point_type, $this->description, $this->entity_type, $this->entity_id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT up.*, CONCAT(u.first_name, ' ', u.last_name) as learner_name FROM user_points up LEFT JOIN users u ON up.user_id = u.id WHERE up.point_id=? AND up.tenant_id=?");
        $stmt->bind_param("ii", $this->point_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "User point record not found";
    }

    public function get_user_total_points(int $user_id): int {
        $stmt = $this->conn->prepare("SELECT COALESCE(SUM(points), 0) as total FROM user_points WHERE user_id=? AND tenant_id=?");
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        return (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    }

    public function get_user_points_by_type(int $user_id, string $point_type): int {
        $stmt = $this->conn->prepare("SELECT COALESCE(SUM(points), 0) as total FROM user_points WHERE user_id=? AND point_type=? AND tenant_id=?");
        $stmt->bind_param("isi", $user_id, $point_type, $this->tenant_id);
        $stmt->execute();
        return (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    }

    public function list(): array {
        $page = max(1, $this->page);
        $perPage = max(1, min(100, $this->per_page));
        $offset = ($page - 1) * $perPage;
        $where = "WHERE up.tenant_id=?";
        $params = [$this->tenant_id];
        $types = "i";

        if ($this->user_id) {
            $where .= " AND up.user_id=?";
            $params[] = $this->user_id;
            $types .= "i";
        }

        if ($this->point_type) {
            $where .= " AND up.point_type=?";
            $params[] = $this->point_type;
            $types .= "s";
        }

        $allowedColumns = ['point_id', 'points', 'earned_at'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'point_id';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'DESC';

        $countQuery = "SELECT COUNT(*) AS total FROM user_points up $where";
        $countStmt = $this->conn->prepare($countQuery);
        if ($types) $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));

        $query = "SELECT up.*, CONCAT(u.first_name, ' ', u.last_name) as learner_name FROM user_points up LEFT JOIN users u ON up.user_id = u.id $where ORDER BY up.$sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return ["data" => $rows, "meta" => ["total" => $total, "page" => $page, "per_page" => $perPage, "total_pages" => $totalPages, "sort_by" => $sortBy, "sort_dir" => $sortDir]];
    }
}