<?php

class Leaderboard {
    public ?int $leaderboard_id = null;
    public ?int $tenant_id;
    public string $leaderboard_name = '';
    public string $description = '';
    public string $leaderboard_type = 'points';
    public string $time_period = 'all_time';
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'leaderboard_id';
    public string $sort_dir = 'asc';
    public bool $is_active = true;
    private mysqli $conn;

    public function __construct(mysqli $db) { $this->conn = $db; }

    public function create(): bool {
        $stmt = $this->conn->prepare("INSERT INTO leaderboards (tenant_id, leaderboard_name, description, leaderboard_type, time_period) VALUES (?,?,?,?,?)");
        $stmt->bind_param("issss", $this->tenant_id, $this->leaderboard_name, $this->description, $this->leaderboard_type, $this->time_period);
        return $stmt->execute();
    }

    public function update(): bool {
        $stmt = $this->conn->prepare("UPDATE leaderboards SET leaderboard_name=?, description=?, leaderboard_type=?, time_period=?, is_active=? WHERE leaderboard_id=? AND tenant_id=?");
        $stmt->bind_param("ssssiii", $this->leaderboard_name, $this->description, $this->leaderboard_type, $this->time_period, $this->is_active, $this->leaderboard_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function delete(): bool {
        $stmt = $this->conn->prepare("UPDATE leaderboards SET is_active=? WHERE leaderboard_id=? AND tenant_id=?");
        $stmt->bind_param("iii", $this->is_active, $this->leaderboard_id, $this->tenant_id);
        return $stmt->execute();
    }

    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT * FROM leaderboards WHERE leaderboard_id=? AND tenant_id=?");
        $stmt->bind_param("ii", $this->leaderboard_id, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: "Leaderboard not found";
    }

    public function get_rankings(int $limit = 10): array {
        $dateFilter = $this->get_date_filter();

        switch ($this->leaderboard_type) {
            case 'points':
                $query = "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.avatar_url, COALESCE(SUM(up.points), 0) as total_score FROM users u LEFT JOIN user_points up ON u.id = up.user_id $dateFilter WHERE u.tenant_id=? AND u.is_active=1 GROUP BY u.id ORDER BY total_score DESC LIMIT ?";
                break;

            case 'badges':
                $query = "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.avatar_url, COUNT(ub.user_badge_id) as total_score FROM users u LEFT JOIN user_badges ub ON u.id = ub.user_id $dateFilter WHERE u.tenant_id=? AND u.is_active=1 GROUP BY u.id ORDER BY total_score DESC LIMIT ?";
                break;

            case 'courses_completed':
                $query = "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.avatar_url, COUNT(ce.enrollment_id) as total_score FROM users u LEFT JOIN course_enrollments ce ON u.id = ce.user_id WHERE u.tenant_id=? AND u.is_active=1 AND ce.status='completed' $dateFilter GROUP BY u.id ORDER BY total_score DESC LIMIT ?";
                break;

            case 'assessments_passed':
                $query = "SELECT u.id, CONCAT(u.first_name, ' ', u.last_name) as learner_name, u.avatar_url, COUNT(aa.attempt_id) as total_score FROM users u LEFT JOIN assessment_attempts aa ON u.id = aa.user_id WHERE u.tenant_id=? AND u.is_active=1 AND aa.passed=1 $dateFilter GROUP BY u.id ORDER BY total_score DESC LIMIT ?";
                break;

            default:
                return [];
        }

        $stmt = $this->conn->prepare($query);
        $stmt->bind_param("ii", $this->tenant_id, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function get_date_filter(): string {
        switch ($this->time_period) {
            case 'daily':
                return " AND DATE(earned_at) = CURDATE()";
            case 'weekly':
                return " AND YEARWEEK(earned_at) = YEARWEEK(NOW())";
            case 'monthly':
                return " AND YEAR(earned_at) = YEAR(NOW()) AND MONTH(earned_at) = MONTH(NOW())";
            case 'all_time':
            default:
                return "";
        }
    }

    public function list(): array {
        $page = max(1, $this->page);
        $perPage = max(1, min(100, $this->per_page));
        $offset = ($page - 1) * $perPage;
        $where = "WHERE tenant_id=?";
        $params = [$this->tenant_id];
        $types = "i";

        $where .= " AND is_active=?";
        $params[] = $this->is_active;
        $types .= "i";

        if ($this->leaderboard_type) {
            $where .= " AND leaderboard_type=?";
            $params[] = $this->leaderboard_type;
            $types .= "s";
        }

        $allowedColumns = ['leaderboard_id', 'leaderboard_name', 'created_at'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'leaderboard_id';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'ASC';

        $countQuery = "SELECT COUNT(*) AS total FROM leaderboards $where";
        $countStmt = $this->conn->prepare($countQuery);
        if ($types) $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()['total'] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));

        $query = "SELECT * FROM leaderboards $where ORDER BY $sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return ["data" => $rows, "meta" => ["total" => $total, "page" => $page, "per_page" => $perPage, "total_pages" => $totalPages, "sort_by" => $sortBy, "sort_dir" => $sortDir]];
    }
}