<?php

class Branches {
    public ?int $id = null;
    public ?int $tenant_id = null;
    public string $branch_code = '';
    public string $branch_name = '';
    public string $address = '';
    public string $city = '';
    public string $state = '';
    public string $country = '';
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'branch_id';
    public string $sort_dir = 'asc';
    public bool $is_active = true;
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $sql = "INSERT INTO branches (tenant_id, branch_code, branch_name, address, city, state, country) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("issssss", $this->tenant_id, $this->branch_code, $this->branch_name, $this->address, $this->city, $this->state, $this->country);
        return $stmt->execute();
    }

    public function update(): bool {
        $sql = "UPDATE branches SET branch_name=?,branch_code=?, address=?, city=?, state=?, country=?, is_active=? WHERE branch_id=?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ssssssii", $this->branch_name,$this->branch_code, $this->address, $this->city, $this->state, $this->country, $this->is_active, $this->id);
        return $stmt->execute();
    }

    public function delete(): bool {
        $stmt = $this->conn->prepare("UPDATE branches SET is_active=? WHERE branch_id = ?");
        $stmt->bind_param("ii", $this->is_active, $this->id);
        return $stmt->execute();
    }
    public function active(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM branches WHERE tenant_id=? AND is_active=1");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT * FROM branches WHERE branch_id=?");
        $stmt->bind_param("i", $this->id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ?: "Branch not found";
    }

    public function list(): array {
        $page = max(1, $this->page);
        $perPage = max(1, min(100, $this->per_page));
        $offset = ($page - 1) * $perPage;
        $where = "WHERE 1=1";
        $params = []; $types = "";
        if ($this->is_active ?? null) { $where .= " AND is_active=?"; $params[] = $this->is_active; $types.="i"; }
        $allowedColumns = ['branch_id','branch_code','branch_name','city','state','created_at'];
        $allowedDirections = ['asc','desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns)?$this->sort_by:'branch_id';
        $sortDir = in_array(strtolower($this->sort_dir),$allowedDirections)?strtoupper($this->sort_dir):'ASC';
        $countQuery = "SELECT COUNT(*) AS total FROM branches $where";
        $countStmt = $this->conn->prepare($countQuery);
        if($types) $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total']??0);
        $totalPages = max(1,(int)ceil($total/$perPage));
        $query = "SELECT * FROM branches $where ORDER BY $sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types."ii"; $params2 = array_merge($params, [$perPage,$offset]);
        $stmt->bind_param($types2,...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return [
            "data"=>$rows,
            "meta"=>["total"=>$total,"page"=>$page,"per_page"=>$perPage,"total_pages"=>$totalPages,"sort_by"=>$sortBy,"sort_dir"=>$sortDir]
        ];
    }
}
