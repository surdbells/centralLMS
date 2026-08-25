<?php

class CertificateTemplates {
    public ?int $id = null;
    public ?int $tenant_id = null;
    public string $template_name = '';
    public string $description = '';
    public string $template_html = '';
    public string $template_css = '';
    public string $orientation = '';
    public string $page_size = '';
    public bool $is_default = false;
    public bool $is_active = true;
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'template_id';
    public string $sort_dir = 'asc';
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $sql = "INSERT INTO certificate_templates (tenant_id, template_name, description, template_html, template_css) 
                VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("issss", $this->tenant_id, $this->template_name, $this->description, $this->template_html, $this->template_css);
        return $stmt->execute();
    }

    public function update(): bool {
        $sql = "UPDATE certificate_templates SET template_name = ?,description = ?,template_html = ?, template_css = ?, orientation = ?, page_size = ?, is_default = ?, is_active=? WHERE template_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ssssssiii", $this->template_name,$this->description,$this->template_html, $this->template_css, $this->orientation, $this->page_size, $this->is_default, $this->is_active, $this->id);
        return $stmt->execute();
    }

    public function delete(): bool {
        $stmt = $this->conn->prepare("UPDATE certificate_templates SET is_active=? WHERE template_id = ?");
        $stmt->bind_param("ii", $this->is_active, $this->id);
        return $stmt->execute();
    }
    public function active(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM certificate_templates WHERE tenant_id = ? AND is_active = 1");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT * FROM certificate_templates WHERE template_id=?");
        $stmt->bind_param("i", $this->id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ?: "Templates not found";
    }

    public function list(): array {
        $page = max(1, $this->page);
        $perPage = max(1, min(100, $this->per_page));
        $offset = ($page - 1) * $perPage;
        $where = "WHERE 1=1";
        $params = []; $types = "";
        if ($this->is_active ?? null) { $where .= " AND is_active=?"; $params[] = $this->is_active; $types.="i"; }
        $allowedColumns = ['template_id','template_name','description','template_html','template_css','orientation', 'page_size','is_default','is_active','created_at'];
        $allowedDirections = ['asc','desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns)?$this->sort_by:'template_id';
        $sortDir = in_array(strtolower($this->sort_dir),$allowedDirections)?strtoupper($this->sort_dir):'ASC';
        $countQuery = "SELECT COUNT(*) AS total FROM certificate_templates $where";
        $countStmt = $this->conn->prepare($countQuery);
        if($types) $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total']??0);
        $totalPages = max(1,(int)ceil($total/$perPage));
        $query = "SELECT * FROM certificate_templates $where ORDER BY $sortBy $sortDir LIMIT ? OFFSET ?";
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
