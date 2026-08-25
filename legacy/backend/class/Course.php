<?php

class Course {
    public ?int $id = null;
    public ?int $tenant_id = null;
    public string $title = '';
    public string $description = '';
    public string $objectives = '';
    public string $summary = '';
    public ?int $category_id = null;
    public ?int $pass_percentage = null;
    public bool $selfEnroll = false;
    public bool $is_mandatory = false;
    public ?int $instructor_id = null;
    public ?int $certificate_template_id = null;
    public string $status = 'draft';
    public string $level = '';
    public ?float $duration_minutes = null;
    public bool $is_featured = false;
    public bool $is_active = true;
    public ?int $enrollment_limit = null;
    public ?string $start_date = null;
    public ?string $end_date = null;
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'course_id';
    public string $sort_dir = 'desc';
    public string $search;
    private mysqli $conn;


    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO courses (
                     tenant_id, 
                     title, 
                     description, 
                     objectives,
                     summary, 
                     category_id, 
                     instructor_id, 
                     certificate_template_id,
                     level, 
                     duration_minutes, 
                     enrollment_limit, 
                     selfEnroll, 
                     is_mandatory, 
                     start_date, 
                     end_date,
                     pass_percentage) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,?, ?, ?,?,?)");
        $stmt->bind_param("issssiiisiiiissi",

            $this->tenant_id,
            $this->title,
            $this->description,
            $this->objectives,
            $this->summary,
            $this->category_id,
            $this->instructor_id,
            $this->certificate_template_id,
            $this->level,
            $this->duration_minutes,
            $this->enrollment_limit,
            $this->selfEnroll,
            $this->is_mandatory,
            $this->start_date,
            $this->end_date,
            $this->pass_percentage);
        return $stmt->execute();
    }

    public function update(): bool {
        $stmt = $this->conn->prepare("UPDATE courses SET title=?, description=?, objectives=?, summary=?, category_id=?, instructor_id=?, certificate_template_id=?, status=?, level=?, duration_minutes=?, is_featured=?, enrollment_limit=?, start_date=?, end_date=?, is_active=? WHERE course_id=? AND tenant_id=?");
        $stmt->bind_param("sssiiissdiiissii", $this->title, $this->description, $this->objectives, $this->summary, $this->category_id, $this->instructor_id, $this->certificate_template_id, $this->status, $this->level, $this->duration_minutes, $this->is_featured, $this->enrollment_limit, $this->start_date, $this->end_date, $this->is_active, $this->id, $this->tenant_id);
        return $stmt->execute();
    }

    public function status(): bool {
        $stmt = $this->conn->prepare("UPDATE courses SET is_active = ?, status = ? WHERE course_id=? AND tenant_id=?");
        $stmt->bind_param("isii", $this->is_active,$this->status, $this->id, $this->tenant_id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT c.*, u.first_name, u.last_name, cat.name as category_name FROM courses c LEFT JOIN users u ON c.instructor_id = u.id LEFT JOIN categories cat ON c.category_id = cat.category_id WHERE c.course_id=? AND c.tenant_id=?");
        $stmt->bind_param("ii", $this->id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Course not found";
    }
    public function get_enrollment_count(): int {
        $stmt = $this->conn->prepare("SELECT COUNT(*) as count FROM course_enrollments WHERE course_id=? AND tenant_id=? AND is_active=1");
        $stmt->bind_param("ii", $this->id, $this->tenant_id);
        $stmt->execute();
        return (int)($stmt->get_result()->fetch_assoc()['count'] ?? 0);
    }
    public function get_tenant_coursesStatus(int $tenant_id, string $status): array {
        $stmt = $this->conn->prepare("SELECT course_id FROM courses WHERE tenant_id = ? and status = ?");
        $stmt->bind_param("is", $tenant_id, $status);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    public function get_tenant_coursesTotal(int $tenant_id): array {
        $stmt = $this->conn->prepare("SELECT course_id FROM courses WHERE tenant_id = ?");
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    public function list(): array {
        $page = max(1, $this->page);
        $perPage = max(1, min(100, $this->per_page));
        $offset = ($page - 1) * $perPage;
        $where = "WHERE c.tenant_id=?";
        $params = [$this->tenant_id];
        $types = "i";
        if ($this->is_active ?? null) { $where .= " AND c.is_active=?"; $params[] = $this->is_active; $types .= "i"; }
        if ($this->status ?? null) { $where .= " AND c.status=?"; $params[] = $this->status; $types .= "s"; }
        if (!empty($this->search)) {  $where .= " AND c.title LIKE ?"; $params[] = '%' . $this->search . '%'; $types .= "s";}
        if ($this->category_id) { $where .= " AND c.category_id=?"; $params[] = $this->category_id; $types .= "i"; }
        if ($this->is_featured) { $where .= " AND c.is_featured=?"; $params[] = $this->is_featured; $types .= "i"; }
        $allowedColumns = ['course_id', 'title', 'created_at', 'start_date'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'course_id';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'DESC';
        $countQuery = "SELECT COUNT(*) AS total FROM courses c $where";
        $countStmt = $this->conn->prepare($countQuery);
        $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $query = "SELECT c.*, u.first_name, u.last_name, cat.name as category_name FROM courses c LEFT JOIN users u ON c.instructor_id = u.id LEFT JOIN categories cat ON c.category_id = cat.category_id $where ORDER BY c.$sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return ["data" => $rows, "meta" => ["total" => $total, "page" => $page, "per_page" => $perPage, "total_pages" => $totalPages, "sort_by" => $sortBy, "sort_dir" => $sortDir]];
    }
}