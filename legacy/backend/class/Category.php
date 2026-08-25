<?php

class Category {
    public ?int $id = null;
    public ?int $tenant_id = null;
    public string $name = '';
    public string $description = '';
    public ?int $parent_id = null;
    public int $sort_order = 1;
    public bool $is_active = true;
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'sort_order';
    public string $sort_dir = 'asc';
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO categories (tenant_id, name, description, parent_id, sort_order) VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param("issii", $this->tenant_id, $this->name, $this->description, $this->parent_id, $this->sort_order);
        return $stmt->execute();
    }

    public function update(): bool {
        $stmt = $this->conn->prepare("UPDATE categories SET name=?, description=?, parent_id=?, sort_order=?, is_active=? WHERE category_id=? AND tenant_id=?");
        $stmt->bind_param("ssiiii", $this->name, $this->description, $this->parent_id, $this->sort_order, $this->is_active, $this->id, $this->tenant_id);
        return $stmt->execute();
    }
    public function active(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM categories WHERE tenant_id=? AND is_active=1");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function delete(): bool {
        $stmt = $this->conn->prepare("UPDATE categories SET is_active=? WHERE category_id=? AND tenant_id=?");
        $stmt->bind_param("iii", $this->is_active, $this->id, $this->tenant_id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT * FROM categories WHERE category_id=? AND tenant_id=?");
        $stmt->bind_param("ii", $this->id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Category not found";
    }

    public function get_subcategories(): array {
        $stmt = $this->conn->prepare("SELECT * FROM categories WHERE parent_id=? AND tenant_id=? AND is_active=1 ORDER BY sort_order ASC");
        $stmt->bind_param("ii", $this->id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function list(): array {
        $page = max(1, $this->page);
        $perPage = max(1, min(100, $this->per_page));
        $offset = ($page - 1) * $perPage;
        $where = "WHERE tenant_id=?";
        $params = [$this->tenant_id];
        $types = "i";
      //  if ($this->is_active ?? null) { $where .= " AND is_active=?"; $params[] = $this->is_active; $types .= "i"; }
        if ($this->parent_id !== null) { $where .= " AND parent_id=?"; $params[] = $this->parent_id; $types .= "i"; } else { $where .= " AND parent_id IS NULL"; }
        $allowedColumns = ['category_id', 'name', 'sort_order', 'created_at'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'sort_order';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'ASC';
        $countQuery = "SELECT COUNT(*) AS total FROM categories $where";
        $countStmt = $this->conn->prepare($countQuery);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $query = "SELECT * FROM categories $where ORDER BY $sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $rows = [];
        foreach ($result as $record) {
            $rows[] = [
                "category_id" => (int)$record["category_id"],
                "name" => $record["name"],
                "description" => $record["description"],
                "status" => (bool)$record["is_active"],
                "created_at" => $record["created_at"],
                "updated_at" => $record["updated_at"],
            ];
        }

        return ["data" => $rows, "meta" => ["total" => $total, "page" => $page, "per_page" => $perPage, "total_pages" => $totalPages, "sort_by" => $sortBy, "sort_dir" => $sortDir]];
    }
}