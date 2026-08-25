<?php

class Notification {
    public ?int $notification_id = null;
    public ?int $tenant_id;
    public ?int $user_id;
    public string $title = '';
    public string $message = '';
    public string $type = '';
    public string $entity_type = '';
    public ?int $entity_id = null;
    public bool $is_read = false;
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'notification_id';
    public string $sort_dir = 'desc';
    public bool $is_active = true;
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO notifications (tenant_id, user_id, title, message, type, entity_type, entity_id) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("iissssi", $this->tenant_id, $this->user_id, $this->title, $this->message, $this->type, $this->entity_type, $this->entity_id);
        return $stmt->execute();
    }

    public function mark_as_read(): bool {
        $stmt = $this->conn->prepare("UPDATE notifications SET is_read=1 WHERE notification_id=? AND tenant_id=?");
        $stmt->bind_param("ii", $this->notification_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function mark_all_as_read(): bool {
        $stmt = $this->conn->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND tenant_id=? AND is_read=0");
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function delete(): bool {
        $stmt = $this->conn->prepare("UPDATE notifications SET is_active=? WHERE notification_id=? AND tenant_id=?");
        $stmt->bind_param("iii", $this->is_active, $this->notification_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT n.*, CONCAT(u.first_name, ' ', u.last_name) as learner_name FROM notifications n LEFT JOIN users u ON n.user_id = u.id WHERE n.notification_id=? AND n.tenant_id=?");
        $stmt->bind_param("ii", $this->notification_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Notification not found";
    }

    public function get_unread_count(int $user_id): int {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM notifications WHERE user_id=? AND tenant_id=? AND is_read=0 AND is_active=1");
        $stmt->bind_param("ii", $user_id, $this->tenant_id);
        $stmt->execute();
        return (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0);
    }

    public function list(): array {
        $page = max(1, $this->page);
        $perPage = max(1, min(100, $this->per_page));
        $offset = ($page - 1) * $perPage;
        $where = "WHERE n.tenant_id=?";
        $params = [$this->tenant_id];
        $types = "i";

        if ($this->user_id) {
            $where .= " AND n.user_id=?";
            $params[] = $this->user_id;
            $types .= "i";
        }

        if ($this->is_read !== null) {
            $where .= " AND n.is_read=?";
            $params[] = $this->is_read;
            $types .= "i";
        }

        if ($this->is_active !== null) {
            $where .= " AND n.is_active=?";
            $params[] = $this->is_active;
            $types .= "i";
        }

        if ($this->type) {
            $where .= " AND n.type=?";
            $params[] = $this->type;
            $types .= "s";
        }

        $allowedColumns = ['notification_id', 'created_at'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'notification_id';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'DESC';

        $countQuery = "SELECT COUNT(*) AS total FROM notifications n $where";
        $countStmt = $this->conn->prepare($countQuery);
        if ($types) $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));

        $query = "SELECT n.* FROM notifications n $where ORDER BY n.$sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return ["data" => $rows, "meta" => ["total" => $total, "page" => $page, "per_page" => $perPage, "total_pages" => $totalPages, "sort_by" => $sortBy, "sort_dir" => $sortDir]];
    }
}
