<?php
class Tenant {
    public ?int $id = null;
    public ?int $plan_id = null;
    public string $name = '';
    public string $status = '';
    public string $users_limit = '';
    public string $plan_type = '';
    public ?int $max_users = null;
    public ?int $storage_limit_mb = null;
    public bool $is_active = true;
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'tenant_id';
    public string $sort_dir = 'asc';
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO tenants (name, status, plan_id, users_limit, storage_limit_mb, contact_email, api_key) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssii", $this->name, $this->status, $this->plan_id, $this->users_limit, $this->storage_limit_mb, $this->max_users, $this->max_storage_gb);
        return $stmt->execute();
    }

    public function update(): bool {
        $stmt = $this->conn->prepare("UPDATE tenants SET name=?, custom_domain=?, status=?, plan_type=?, max_users=?, max_storage_gb=?, is_active=? WHERE tenant_id=?");
        $stmt->bind_param("ssssiii", $this->name, $this->custom_domain, $this->status, $this->plan_type, $this->max_users, $this->max_storage_gb, $this->is_active, $this->id);
        return $stmt->execute();
    }

    public function delete(): bool {
        $stmt = $this->conn->prepare("UPDATE tenants SET is_active=? WHERE tenant_id=?");
        $stmt->bind_param("ii", $this->is_active, $this->id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT * FROM tenants WHERE tenant_id=?");
        $stmt->bind_param("i", $this->id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Tenant not found";
    }


    public function list(): array {
        $page = max(1, $this->page);
        $perPage = max(1, min(100, $this->per_page));
        $offset = ($page - 1) * $perPage;
        $where = "WHERE 1=1";
        $params = [];
        $types = "";
        if ($this->is_active ?? null) { $where .= " AND is_active=?"; $params[] = $this->is_active; $types .= "i"; }
        if ($this->status) { $where .= " AND status=?"; $params[] = $this->status; $types .= "s"; }
        $allowedColumns = ['tenant_id', 'name', 'subdomain', 'created_at'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'tenant_id';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'ASC';
        $countQuery = "SELECT COUNT(*) AS total FROM tenants $where";
        $countStmt = $this->conn->prepare($countQuery);
        if ($types) $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $query = "SELECT * FROM tenants $where ORDER BY $sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return ["data" => $rows, "meta" => ["total" => $total, "page" => $page, "per_page" => $perPage, "total_pages" => $totalPages, "sort_by" => $sortBy, "sort_dir" => $sortDir]];
    }
}