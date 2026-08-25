<?php

class Departments {
    public ?int $id = null;
    public ?int $tenant_id = null;
    public string $department_code = '';
    public string $department_name = '';
    public string $description = '';
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'department_id';
    public string $sort_dir = 'asc';
    public bool $is_active = true;
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO departments (tenant_id, department_code, department_name, description) VALUES (?,?,?,?)");
        $stmt->bind_param("isss",$this->tenant_id,$this->department_code,$this->department_name,$this->description);
        return $stmt->execute();
    }

    public function update(): bool {
        $stmt = $this->conn->prepare("UPDATE departments SET department_name=?, description=?, is_active=? WHERE department_id=?");
        $stmt->bind_param("ssii",$this->department_name,$this->description,$this->is_active,$this->id);
        return $stmt->execute();
    }

    public function delete(): bool {
        $stmt = $this->conn->prepare("UPDATE departments SET is_active=? WHERE department_id = ?");
        $stmt->bind_param("ii", $this->is_active, $this->id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT * FROM departments WHERE department_code=?");
        $stmt->bind_param("s",$this->department_code);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Department not found";
    }

    public function list(): array {
        $page = max(1,$this->page); $perPage = max(1,min(100,$this->per_page)); $offset = ($page-1)*$perPage;
        $where="WHERE 1=1"; $params=[]; $types="";
        if($this->is_active ?? null){$where.=" AND is_active=?";$params[]=$this->is_active;$types.="i";}
        $allowedColumns=['department_id','department_code','department_name','created_at'];
        $allowedDirections=['asc','desc'];
        $sortBy = in_array($this->sort_by,$allowedColumns)?$this->sort_by:'department_id';
        $sortDir = in_array(strtolower($this->sort_dir),$allowedDirections)?strtoupper($this->sort_dir):'ASC';
        $countQuery = "SELECT COUNT(*) AS total FROM departments $where";
        $countStmt = $this->conn->prepare($countQuery);
        if($types) $countStmt->bind_param($types,...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total']??0);
        $totalPages = max(1,(int)ceil($total/$perPage));
        $query = "SELECT * FROM departments $where ORDER BY $sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2=$types."ii"; $params2=array_merge($params,[$perPage,$offset]);
        $stmt->bind_param($types2,...$params2);
        $stmt->execute();
        $rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return ["data"=>$rows,"meta"=>["total"=>$total,"page"=>$page,"per_page"=>$perPage,"total_pages"=>$totalPages,"sort_by"=>$sortBy,"sort_dir"=>$sortDir]];
    }

    public function active(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM departments WHERE tenant_id=? AND is_active=1");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result();
    }
}
