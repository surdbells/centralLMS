<?php

class LiveSession {
    public ?int $session_id = null;
    public ?int $tenant_id;
    public ?int $course_id;
    public string $title = '';
    public string $description = '';
    public string $scheduled_at = '';
    public int $duration_minutes = 60;
    public string $meeting_url = '';
    public string $meeting_id = '';
    public string $status = 'scheduled';
    public ?int $max_participants = null;
    public string $recording_url = '';
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'session_id';
    public string $sort_dir = 'asc';
    public bool $is_active = true;
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO live_sessions (tenant_id, course_id, title, description, scheduled_at, duration_minutes, meeting_url, meeting_id, status, max_participants) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param("iisssisssi", $this->tenant_id, $this->course_id, $this->title, $this->description, $this->scheduled_at, $this->duration_minutes, $this->meeting_url, $this->meeting_id, $this->status, $this->max_participants);
        return $stmt->execute();
    }

    public function update(): bool {
        $stmt = $this->conn->prepare("UPDATE live_sessions SET title=?, description=?, scheduled_at=?, duration_minutes=?, meeting_url=?, meeting_id=?, status=?, max_participants=?, recording_url=?, is_active=? WHERE session_id=? AND tenant_id=?");
        $stmt->bind_param("sssississiii", $this->title, $this->description, $this->scheduled_at, $this->duration_minutes, $this->meeting_url, $this->meeting_id, $this->status, $this->max_participants, $this->recording_url, $this->is_active, $this->session_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function delete(): bool {
        $stmt = $this->conn->prepare("UPDATE live_sessions SET is_active=? WHERE session_id=? AND tenant_id=?");
        $stmt->bind_param("iii", $this->is_active, $this->session_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT ls.*, c.title as course_title, CONCAT(u.first_name, ' ', u.last_name) as instructor_name FROM live_sessions ls LEFT JOIN courses c ON ls.course_id = c.course_id LEFT JOIN users u ON c.instructor_id = u.id WHERE ls.session_id=? AND ls.tenant_id=?");
        $stmt->bind_param("ii", $this->session_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Live session not found";
    }

    public function update_status(string $new_status): bool {
        $stmt = $this->conn->prepare("UPDATE live_sessions SET status=? WHERE session_id=? AND tenant_id=?");
        $stmt->bind_param("sii", $new_status, $this->session_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function add_recording_url(string $url): bool {
        $stmt = $this->conn->prepare("UPDATE live_sessions SET recording_url=?, status='completed' WHERE session_id=? AND tenant_id=?");
        $stmt->bind_param("sii", $url, $this->session_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function get_attendees(): array {
        $stmt = $this->conn->prepare("SELECT sa.*, CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.email FROM session_attendance sa LEFT JOIN users u ON sa.user_id = u.id WHERE sa.session_id=? AND sa.tenant_id=?");
        $stmt->bind_param("ii", $this->session_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function list(): array {
        $page = max(1, $this->page);
        $perPage = max(1, min(100, $this->per_page));
        $offset = ($page - 1) * $perPage;
        $where = "WHERE ls.tenant_id=?";
        $params = [$this->tenant_id];
        $types = "i";

        $where .= " AND ls.is_active=?";
        $params[] = $this->is_active;
        $types .= "i";

        if ($this->course_id) {
            $where .= " AND ls.course_id=?";
            $params[] = $this->course_id;
            $types .= "i";
        }

        if ($this->status) {
            $where .= " AND ls.status=?";
            $params[] = $this->status;
            $types .= "s";
        }

        $allowedColumns = ['session_id', 'title', 'scheduled_at', 'status', 'created_at'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'session_id';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'ASC';

        $countQuery = "SELECT COUNT(*) AS total FROM live_sessions ls $where";
        $countStmt = $this->conn->prepare($countQuery);
        if ($types) $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));

        $query = "SELECT ls.*, c.title as course_title, CONCAT(u.first_name, ' ', u.last_name) as instructor_name, (SELECT COUNT(*) FROM session_attendance WHERE session_id = ls.session_id) as attendee_count FROM live_sessions ls LEFT JOIN courses c ON ls.course_id = c.course_id LEFT JOIN users u ON c.instructor_id = u.id $where ORDER BY ls.$sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return ["data" => $rows, "meta" => ["total" => $total, "page" => $page, "per_page" => $perPage, "total_pages" => $totalPages, "sort_by" => $sortBy, "sort_dir" => $sortDir]];
    }
}