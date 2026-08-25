<?php

use Random\RandomException;

class User {
    private mysqli $conn;
    public ?int $id = null;
    public string $token;
    public string $password;
    public ?int $tenant_id = null;
    public string $first_name = '';
    public string $last_name = '';
    public string $email = '';
    public ?string $phone = null;
    public ?int $department = null;
    public ?int $job_title = null;
    public ?int $level = null;
    public ?int $team = null;
    public ?int $branch = null;
    public ?string $staffId = null;
    public ?int $line_manager = null;
    public ?string $avatar = null;
    public bool $is_2fa = true;
    public bool $is_active = false;
    public bool $is_admin = false;
    public bool $is_learner = false;
    public bool $is_content = false;
    public bool $is_hr = false;
    public bool $is_instructor = false;
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'id';
    public string $sort_dir = 'asc';
    public string $updated = '';
    public function __construct(mysqli $db) {
        $this->conn = $db;
    }
    public function create(string $password): bool {
        try {
            $token = bin2hex(random_bytes(20));
        } catch (RandomException $e) {        }
        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $this->conn->prepare("INSERT INTO users 
            (token, tenant_id, first_name, last_name, email, password, phone, department, job_title, level, team, branch, staffId, is_2fa, is_active, is_admin, is_learner, is_content, is_hr, is_instructor)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param(
            "sisssssiiiiissiiiiii",
            $token,
            $this->tenant_id,
            $this->first_name,
            $this->last_name,
            $this->email,
            $hashedPassword,
            $this->phone,
            $this->department,
            $this->job_title,
            $this->level,
            $this->team,
            $this->branch,
            $this->staffId,
            $this->is_2fa,
            $this->is_active,
            $this->is_admin,
            $this->is_learner,
            $this->is_content,
            $this->is_hr,
            $this->is_instructor
        );

        return $stmt->execute();
    }
    public function getById(int $id): ?array {
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ?: null;
    }

    public function update(): bool {
        $stmt = $this->conn->prepare("
            UPDATE users SET
                first_name = ?, last_name = ?, email = ?, phone = ?, department = ?, job_title = ?, level = ?, team = ?, branch = ?, staffId = ?,
                is_2fa = ?, is_active = ?, is_admin = ?, is_learner = ?, is_content = ?, is_hr = ?, is_instructor = ?
            WHERE id = ?
        ");

        $stmt->bind_param(
            "sssiiiiissiiiiiiii",
            $this->first_name,
            $this->last_name,
            $this->email,
            $this->phone,
            $this->department,
            $this->job_title,
            $this->level,
            $this->team,
            $this->branch,
            $this->staffId,
            $this->is_2fa,
            $this->is_active,
            $this->is_admin,
            $this->is_learner,
            $this->is_content,
            $this->is_hr,
            $this->is_instructor,
            $this->id
        );

        return $stmt->execute();
    }

    public function delete(int $id): bool {
        $stmt = $this->conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }
    public function status(): bool {
        $stmt = $this->conn->prepare("UPDATE users SET is_active = ?, updated = ? WHERE id = ?
        ");
        $stmt->bind_param("isi",  $this->is_active, $this->updated, $this->id);
        return $stmt->execute();
    }
    public function users_list(): array{
        // Pagination setup
        $page = max(1, (int)$this->page);
        $perPage = max(1, min(100, (int)$this->per_page));
        $offset = ($page - 1) * $perPage;
        // Build WHERE clause dynamically
        $where = "WHERE 1=1";
        $params = [];
        $types = "";
        $filters = [
            'id' => $this->id,
            'department' => $this->department,
            'job_title' => $this->job_title,
            'level' => $this->level,
            'team' => $this->team,
            'branch' => $this->branch,
            'is_active' => $this->is_active,
            'is_admin' => $this->is_admin,
            'is_learner' => $this->is_learner,
            'is_content' => $this->is_content,
            'is_hr' => $this->is_hr,
            'is_instructor' => $this->is_instructor
        ];
        foreach ($filters as $column => $value) {
            if ($value !== null) {
                $where .= " AND $column = ?";
                $types .= "i"; // all filterable columns are integers/booleans
                $params[] = $value;
            }
        }

        // Sorting whitelist
        $allowedColumns = ['id', 'first_name', 'last_name', 'email', 'phone', 'last_login'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'id';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'ASC';

        // Get total count
        $countQuery = "SELECT COUNT(*) AS total FROM users $where";
        $countStmt = $this->conn->prepare($countQuery);
        if ($types) $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()["total"] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));

        // Fetch user data
        $query = "SELECT * FROM users $where ORDER BY $sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);

        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Build pagination links
        $links = $this->buildPaginationLinks($page, $perPage, $totalPages);

        return [
            "data" => $rows,
            "meta" => [
                "total" => $total,
                "page" => $page,
                "per_page" => $perPage,
                "total_pages" => $totalPages,
                "sort_by" => $sortBy,
                "sort_dir" => $sortDir
            ],
            "links" => $links
        ];
    }

    public function buildPaginationLinks(int $page, int $perPage, int $totalPages): array{
        // Parse base URL without page/per_page duplicates
        $query = $_GET;
        unset($query['page'], $query['per_page']);
        $base = strtok($_SERVER["REQUEST_URI"], '?');

        $makeUrl = function(int $p) use ($query, $base, $perPage) {
            return $base . '?' . http_build_query(array_merge($query, [
                    'page' => $p,
                    'per_page' => $perPage
                ]));
        };
        return [
            "self"  => $makeUrl($page),
            "first" => $makeUrl(1),
            "last"  => $makeUrl($totalPages),
            "next"  => ($page < $totalPages) ? $makeUrl($page + 1) : null,
            "prev"  => ($page > 1) ? $makeUrl($page - 1) : null
        ];
    }
    public function getUsersByFilter(int $tenant_id, array $filters = []): array {
            $page = max(1, (int)$this->page);
            $perPage = max(1, min(100, (int)$this->per_page));
            $offset = ($page - 1) * $perPage;

            $where = ['tenant_id = ?'];
            $types = 'i';
            $params = [$tenant_id];

            foreach ($filters as $key => $value) {
                if ($value !== null && $key !== 'tenant_id') {
                    $where[] = "$key = ?";
                    $types .= is_int($value) ? 'i' : 's';
                    $params[] = $value;
                }
            }

            $whereClause = "WHERE " . implode(' AND ', $where);

            // Sorting whitelist
            $allowedColumns = ['id','first_name','last_name','email','phone','last_login'];
            $allowedDirections = ['asc','desc'];
            $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'id';
            $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'desc';

            // Total count - always bind params (tenant_id is always present)
            $countQuery = "SELECT COUNT(*) AS total FROM users $whereClause";
            $countStmt = $this->conn->prepare($countQuery);
            $countStmt->bind_param($types, ...$params);
            $countStmt->execute();
            $total = (int)($countStmt->get_result()->fetch_assoc()["total"] ?? 0);
            $totalPages = max(1, (int)ceil($total / $perPage));

            // Fetch rows
            $query = "SELECT * FROM users $whereClause ORDER BY $sortBy $sortDir LIMIT ? OFFSET ?";
            $stmt = $this->conn->prepare($query);
            $types2 = $types . "ii";
            $params2 = array_merge($params, [$perPage, $offset]);
            $stmt->bind_param($types2, ...$params2);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            // Pagination links
            $links = $this->buildPaginationLinks($page, $perPage, $totalPages);


        return [
            "data" => $rows,
            "meta" => [
                "total" => $total,
                "page" => $page,
                "per_page" => $perPage,
                "total_pages" => $totalPages,
                "sort_by" => $sortBy,
                "sort_dir" => $sortDir
            ],
            "links" => $links
        ];
    }
    public function read_single(): array|string {
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE id=?");
        $stmt->bind_param("i", $this->id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result ?: "User not found";
    }
    private function getNameById(string $columnName, string $columnId, string $table, int $id): string {
        $stmt = $this->conn->prepare("SELECT $columnName FROM $table WHERE $columnId = ? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return $result[$columnName] ?? '';
    }
    public function getDepartmentName(int $departmentId): string {
        return $this->getNameById('department_name','department_id','departments', $departmentId);
    }
    public function getJobTitleName(int $jobTitleId): string {
        return $this->getNameById('title_name','job_title_id','job_titles', $jobTitleId);
    }
    public function getLevelName(int $levelId): string {
        return $this->getNameById('level_name','level_id','levels', $levelId);
    }
    public function getTeamName(int $teamId): string {
        return $this->getNameById('team_name', 'team_id','teams', $teamId);
    }
    public function getBranchName(int $branchId): string {
        return $this->getNameById('branch_name','branch_id','branches', $branchId);
    }
    public function is_admin(int $userId): bool{
        $stmt = $this->conn->prepare("SELECT is_admin FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return !empty($result) && $result['is_admin'];
    }

    public function getBranchesCount($tenant_id): int{
        $sql = "SELECT COUNT(*) as count FROM branches WHERE tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return (int) $result['count'];
    }

    public function getDepartmentsCount($tenant_id): int{
        $sql = "SELECT COUNT(*) as count FROM departments WHERE tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return (int) $result['count'];
    }

    public function getTeamsCount($tenant_id): int{
        $sql = "SELECT COUNT(*) as count FROM teams WHERE tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return (int) $result['count'];
    }

    public function getLevelsCount($tenant_id): int{
        $sql = "SELECT COUNT(*) as count FROM levels WHERE tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return (int) $result['count'];
    }

    public function getJobTitlesCount($tenant_id): int{
        $sql = "SELECT COUNT(*) as count FROM job_titles WHERE tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return (int) $result['count'];
    }
    public function getUsersCount($tenant_id): int{
        $sql = "SELECT COUNT(*) as count FROM users WHERE tenant_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("i", $tenant_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return (int) $result['count'];
    }

    public function formatLastActivityHuman(?string $dateString): string{
        if (empty($dateString)) {
            return 'Never';
        }
        $lastActivity = DateTime::createFromFormat('D M d, Y, h:i a',$dateString);
        if (!$lastActivity) {return 'Never';}
        $now = new DateTime();
        $seconds = $now->getTimestamp() - $lastActivity->getTimestamp();
        if ($seconds < 60) {
            return 'Just now';
        }
        if ($seconds < 3600) {
            $minutes = (int) floor($seconds / 60);
            return $minutes . ' minute' . ($minutes !== 1 ? 's' : '') . ' ago';
        }
        if ($seconds < 86400) {
            $hours = (int) floor($seconds / 3600);
            return $hours . ' hour' . ($hours !== 1 ? 's' : '') . ' ago';
        }
        $lastActivity->setTime(0, 0, 0);
        $now->setTime(0, 0, 0);
        $daysDiff = (int) $now->diff($lastActivity)->days;
        return match (true) {
            $daysDiff === 1 => 'Yesterday',
            default => $daysDiff . ' days ago',
        };
    }

    public function getUserCourseCount($tenant_id, $userId): int{
        $sql = "SELECT COUNT(enrollment_id) as count FROM course_enrollments WHERE tenant_id = ? and user_id = ? AND is_active = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $tenant_id, $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return (int) $result['count'];
    }
    public function getUserActivityCount($tenant_id, $userId): int{
        $sql = "SELECT COUNT(log_id) as count FROM user_activity_logs WHERE tenant_id = ? and user_id = ?";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $tenant_id, $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return (int) $result['count'];
    }
    public function getUserCourseCompleted($tenant_id, $userId): int{
        $sql = "SELECT COUNT(enrollment_id) as count FROM course_enrollments WHERE tenant_id = ? and user_id = ? AND is_completed = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $tenant_id, $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return (int) $result['count'];
    }
    public function getUserCourseStarted($tenant_id, $userId): int{
        $sql = "SELECT COUNT(enrollment_id) as count FROM course_enrollments WHERE tenant_id = ? and user_id = ? AND is_started = 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ii", $tenant_id, $userId);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        return (int) $result['count'];
    }
    public function instructor(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE tenant_id=? AND is_instructor = 1 OR is_admin = 1");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function hr(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE tenant_id=? AND is_hr = 1");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function content(): false|mysqli_result{
        $stmt = $this->conn->prepare("SELECT * FROM users WHERE tenant_id=? AND is_content = 1");
        $stmt->bind_param("i", $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result();
    }
    public function calculateCompletionRate(?int $started, ?int $completed): float{
        if (empty($started) || $started <= 0 || empty($completed) || $completed < 0) { return 0.0; }
        return round(($completed / $started) * 100, 2);
    }
}
