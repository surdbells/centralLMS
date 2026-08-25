<?php

class UserActivityLog {
    public ?int $log_id = null;
    public ?int $tenant_id;
    public ?int $user_id;
    public string $activity_type = '';
    public string $entity_type = '';
    public ?int $entity_id = null;
    public string $metadata = '';
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'log_id';
    public string $sort_dir = 'desc';
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO user_activity_logs (tenant_id, user_id, activity_type, entity_type, entity_id, metadata) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param("iissis", $this->tenant_id, $this->user_id, $this->activity_type, $this->entity_type, $this->entity_id, $this->metadata);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT ual.*, CONCAT(u.first_name, ' ', u.last_name) as learner_name FROM user_activity_logs ual LEFT JOIN users u ON ual.user_id = u.id WHERE ual.log_id=? AND ual.tenant_id=?");
        $stmt->bind_param("ii", $this->log_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Activity log not found";
    }

    public function get_user_recent_activity(int $user_id, int $limit = 10): array {
        $stmt = $this->conn->prepare("SELECT * FROM user_activity_logs WHERE user_id=? AND tenant_id=? ORDER BY created_at DESC LIMIT ?");
        $stmt->bind_param("iii", $user_id, $this->tenant_id, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function list(): array {
        $page = max(1, $this->page);
        $perPage = max(1, min(100, $this->per_page));
        $offset = ($page - 1) * $perPage;
        $where = "WHERE ual.tenant_id=?";
        $params = [$this->tenant_id];
        $types = "i";

        if ($this->user_id) {
            $where .= " AND ual.user_id=?";
            $params[] = $this->user_id;
            $types .= "i";
        }

        if ($this->activity_type) {
            $where .= " AND ual.activity_type=?";
            $params[] = $this->activity_type;
            $types .= "s";
        }

        if ($this->entity_type) {
            $where .= " AND ual.entity_type=?";
            $params[] = $this->entity_type;
            $types .= "s";
        }

        $allowedColumns = ['log_id', 'created_at', 'activity_type'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'log_id';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'DESC';

        $countQuery = "SELECT COUNT(*) AS total FROM user_activity_logs ual $where";
        $countStmt = $this->conn->prepare($countQuery);
        if ($types) $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));

        $query = "SELECT ual.*, CONCAT(u.first_name, ' ', u.last_name) as learner_name FROM user_activity_logs ual LEFT JOIN users u ON ual.user_id = u.id $where ORDER BY ual.$sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return ["data" => $rows, "meta" => ["total" => $total, "page" => $page, "per_page" => $perPage, "total_pages" => $totalPages, "sort_by" => $sortBy, "sort_dir" => $sortDir]];
    }
}