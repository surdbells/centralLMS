<?php

class Note {
    public ?int $note_id = null;
    public ?int $tenant_id = null;
    public ?int $user_id = null;
    public ?int $lesson_id = null;
    public string $title = '';
    public string $content = '';
    public ?int $lesson_timestamp = null;
    public bool $is_pinned = false;
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'note_id';
    public string $sort_dir = 'desc';
    public bool $is_active = true;
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO notes (tenant_id, user_id, lesson_id, title, content, lesson_timestamp, is_pinned) VALUES (?,?,?,?,?,?,?)");
        $stmt->bind_param("iiissii", $this->tenant_id, $this->user_id, $this->lesson_id, $this->title, $this->content, $this->lesson_timestamp, $this->is_pinned);
        return $stmt->execute();
    }

    public function update(): bool {
        $stmt = $this->conn->prepare("UPDATE notes SET title=?, content=?, lesson_timestamp=?, is_pinned=?, is_active=? WHERE note_id=? AND user_id=? AND tenant_id=?");
        $stmt->bind_param("ssiiiiii", $this->title, $this->content, $this->lesson_timestamp, $this->is_pinned, $this->is_active, $this->note_id, $this->user_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function delete(): bool {
        // Soft delete - only user who created the note can delete it
        $stmt = $this->conn->prepare("UPDATE notes SET is_active=? WHERE note_id=? AND user_id=? AND tenant_id=?");
        $stmt->bind_param("iiii", $this->is_active, $this->note_id, $this->user_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function hard_delete(): bool {
        // Permanent delete - only user who created the note can delete it
        $stmt = $this->conn->prepare("DELETE FROM notes WHERE note_id=? AND user_id=? AND tenant_id=?");
        $stmt->bind_param("iii", $this->note_id, $this->user_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT n.*, l.title as lesson_title, m.title as module_title, c.title as course_title, CONCAT(u.first_name, ' ', u.last_name) as author_name FROM notes n LEFT JOIN lessons l ON n.lesson_id = l.lesson_id LEFT JOIN modules m ON l.module_id = m.module_id LEFT JOIN courses c ON m.course_id = c.course_id LEFT JOIN users u ON n.user_id = u.id WHERE n.note_id=? AND n.user_id=? AND n.tenant_id=?");
        $stmt->bind_param("iii", $this->note_id, $this->user_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Note not found";
    }

    public function toggle_pin(): bool {
        $stmt = $this->conn->prepare("UPDATE notes SET is_pinned = NOT is_pinned WHERE note_id=? AND user_id=? AND tenant_id=?");
        $stmt->bind_param("iii", $this->note_id, $this->user_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function get_lesson_notes(int $lesson_id): array {
        $stmt = $this->conn->prepare("SELECT n.*, CONCAT(u.first_name, ' ', u.last_name) as author_name FROM notes n LEFT JOIN users u ON n.user_id = u.id WHERE n.lesson_id=? AND n.user_id=? AND n.tenant_id=? AND n.is_active=1 ORDER BY n.is_pinned DESC, n.lesson_timestamp ASC, n.created_at DESC");
        $stmt->bind_param("iii", $lesson_id, $this->user_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function get_course_notes(int $course_id): array {
        $stmt = $this->conn->prepare("SELECT n.*, l.title as lesson_title, m.title as module_title FROM notes n LEFT JOIN lessons l ON n.lesson_id = l.lesson_id LEFT JOIN modules m ON l.module_id = m.module_id WHERE m.course_id=? AND n.user_id=? AND n.tenant_id=? AND n.is_active=1 ORDER BY n.is_pinned DESC, n.created_at DESC");
        $stmt->bind_param("iii", $course_id, $this->user_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function search_notes(string $search_term): array {
        $search = "%{$search_term}%";
        $stmt = $this->conn->prepare("SELECT n.*, l.title as lesson_title, m.title as module_title, c.title as course_title FROM notes n LEFT JOIN lessons l ON n.lesson_id = l.lesson_id LEFT JOIN modules m ON l.module_id = m.module_id LEFT JOIN courses c ON m.course_id = c.course_id WHERE n.user_id=? AND n.tenant_id=? AND n.is_active=1 AND (n.title LIKE ? OR n.content LIKE ?) ORDER BY n.is_pinned DESC, n.created_at DESC LIMIT 50");
        $stmt->bind_param("iiss", $this->user_id, $this->tenant_id, $search, $search);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function get_pinned_notes(): array {
        $stmt = $this->conn->prepare("SELECT n.*, l.title as lesson_title, m.title as module_title, c.title as course_title FROM notes n LEFT JOIN lessons l ON n.lesson_id = l.lesson_id LEFT JOIN modules m ON l.module_id = m.module_id LEFT JOIN courses c ON m.course_id = c.course_id WHERE n.user_id=? AND n.tenant_id=? AND n.is_active=1 AND n.is_pinned=1 ORDER BY n.created_at DESC");
        $stmt->bind_param("ii", $this->user_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function get_recent_notes(int $limit = 10): array {
        $stmt = $this->conn->prepare("SELECT n.*, l.title as lesson_title, m.title as module_title, c.title as course_title FROM notes n LEFT JOIN lessons l ON n.lesson_id = l.lesson_id LEFT JOIN modules m ON l.module_id = m.module_id LEFT JOIN courses c ON m.course_id = c.course_id WHERE n.user_id=? AND n.tenant_id=? AND n.is_active=1 ORDER BY n.updated_at DESC LIMIT ?");
        $stmt->bind_param("iii", $this->user_id, $this->tenant_id, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function list(): array {
        $page = max(1, $this->page);
        $perPage = max(1, min(100, $this->per_page));
        $offset = ($page - 1) * $perPage;
        $where = "WHERE n.tenant_id=? AND n.user_id=?";
        $params = [$this->tenant_id, $this->user_id];
        $types = "ii";

        if ($this->is_active !== null) {
            $where .= " AND n.is_active=?";
            $params[] = $this->is_active;
            $types .= "i";
        }

        if ($this->lesson_id) {
            $where .= " AND n.lesson_id=?";
            $params[] = $this->lesson_id;
            $types .= "i";
        }

        if ($this->is_pinned !== null) {
            $where .= " AND n.is_pinned=?";
            $params[] = $this->is_pinned;
            $types .= "i";
        }

        $allowedColumns = ['note_id', 'title', 'created_at', 'updated_at', 'is_pinned'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'note_id';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'DESC';

        $countQuery = "SELECT COUNT(*) AS total FROM notes n $where";
        $countStmt = $this->conn->prepare($countQuery);
        if ($types) $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));

        $query = "SELECT n.*, l.title as lesson_title, m.title as module_title, c.title as course_title FROM notes n LEFT JOIN lessons l ON n.lesson_id = l.lesson_id LEFT JOIN modules m ON l.module_id = m.module_id LEFT JOIN courses c ON m.course_id = c.course_id $where ORDER BY n.is_pinned DESC, n.$sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return ["data" => $rows, "meta" => ["total" => $total, "page" => $page, "per_page" => $perPage, "total_pages" => $totalPages, "sort_by" => $sortBy, "sort_dir" => $sortDir]];
    }
}