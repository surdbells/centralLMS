<?php

class SessionAttendance {
    public ?int $attendance_id = null;
    public ?int $tenant_id;
    public ?int $session_id;
    public ?int $user_id;
    public ?string $joined_at = null;
    public ?string $left_at = null;
    public ?int $duration_minutes = null;
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO session_attendance (tenant_id, session_id, user_id, joined_at) VALUES (?,?,?,?)");
        $stmt->bind_param("iiis", $this->tenant_id, $this->session_id, $this->user_id, $this->joined_at);
        return $stmt->execute();
    }

    public function mark_left(): bool {
        $stmt = $this->conn->prepare("UPDATE session_attendance SET left_at=?, duration_minutes=? WHERE attendance_id=? AND tenant_id=?");
        $stmt->bind_param("siii", $this->left_at, $this->duration_minutes, $this->attendance_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT sa.*, CONCAT(u.first_name, ' ', u.last_name) as learner_name, ls.title as session_title FROM session_attendance sa LEFT JOIN users u ON sa.user_id = u.id LEFT JOIN live_sessions ls ON sa.session_id = ls.session_id WHERE sa.attendance_id=? AND sa.tenant_id=?");
        $stmt->bind_param("ii", $this->attendance_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Attendance record not found";
    }

    public function check_attendance(): bool {
        $stmt = $this->conn->prepare("SELECT attendance_id FROM session_attendance WHERE session_id=? AND user_id=? AND tenant_id=?");
        $stmt->bind_param("iii", $this->session_id, $this->user_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->num_rows > 0;
    }
}