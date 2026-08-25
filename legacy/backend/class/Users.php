<?php

use Random\RandomException;

class Users {
    public ?int $id;
    public string $token;
    public string $title;
    public string $first_name;
    public string $last_name;
    public int $page = 1;
    public int $per_page = 20;
    public string $sort_by = 'id';
    public string $sort_dir = 'asc';
    public string $department;
    public string $job_role;
    public string $userType;
    public string $email;
    public string $phone;
    public string $password;
    public string $last_login;
    public bool $is_2fa;
    public bool $is_active;
    public bool $is_admin;
    public bool $is_learner;
    public bool $is_hr;
    public bool $is_content;
    public bool $is_instructor;
    public string $updated;
    public string $created;
    private mysqli $conn;

    public function __construct(mysqli $db) {
        $this->conn = $db;
    }

    /**
     * @throws RandomException
     */
    private function generate_token(): string {
        return bin2hex(random_bytes(20));
    }

    public function host_seed($user_id): void{
        $sql = "UPDATE users SET token = ? WHERE id = ?";
        if($stmt = mysqli_prepare($this->conn, $sql)){
            mysqli_stmt_bind_param($stmt, "ss", $host_token, $user_id);
            $host_token = $this->generate_token();
            mysqli_stmt_execute($stmt);
        }
    }
    public function user_login(){
        $sql = "SELECT id, password FROM users WHERE email = ?";
        if ($stmt = mysqli_prepare($this->conn, $sql)) {
            mysqli_stmt_bind_param($stmt, "s", $this->email);
            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_store_result($stmt);
                if (mysqli_stmt_num_rows($stmt) == 1) {
                    mysqli_stmt_bind_result($stmt, $id, $hashed_password);
                    if (mysqli_stmt_fetch($stmt)) {
                        if (password_verify($this->password, $hashed_password)) {
                            $this->host_seed($id);
                            $stmt = $this->conn->prepare("SELECT * FROM users WHERE id = ?");
                            $stmt->bind_param("i", $id);
                            $stmt->execute();
                            return $stmt->get_result();
                        }else{
                            return "Invalid password provided.";
                        }
                    }
                }else {
                    return "Invalid user account.";
                }
            }
        }
    }
    public function user_logout(): bool{
        $this->host_seed($this->id);
        return true;
    }
    public function change_status(): bool{
        $stmt = $this->conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
        $stmt->bind_param("ii", $this->is_active, $this->id);
        if($stmt->execute()){ return true; }return false;
    }
    public function change_2fa(): bool{
        $stmt = $this->conn->prepare("UPDATE users SET is_2fa = ? WHERE id = ?");
        $stmt->bind_param("ii", $this->is_2fa, $this->id);
        if($stmt->execute()){ return true; } return false;
    }
    public function reset_password(): bool{
        $stmt = $this->conn->prepare("UPDATE users SET password = ? WHERE email = ?");
        $stmt->bind_param("ss", $this->password, $this->email);
        if($stmt->execute()){ return true; } return false;
    }
    public function update_last_login(): void{
        $this->last_login = date("D M j, Y, g:i a");
        $stmt = $this->conn->prepare("UPDATE users SET last_login = ? WHERE id = ?");
        $stmt->bind_param("si", $this->last_login, $this->id);
        $stmt->execute();
    }
    public function users_list(): array
    {
        $page = max(1, (int)$this->page);
        $perPage = max(1, min(100, (int)$this->per_page));
        $offset = ($page - 1) * $perPage;

        $where = "WHERE 1=1";
        $params = [];
        $types = "";

        if ($this->id) {
            $where .= " AND id = ?";
            $params[] = $this->id; $types .= "i";
        }
        if ($this->is_learner) {
            $where .= " AND is_learner = ?";
            $params[] = $this->is_learner; $types .= "i";
        }
        if ($this->is_content) {
            $where .= " AND is_content = ?";
            $params[] = $this->is_content; $types .= "i";
        }
        if ($this->is_instructor) {
            $where .= " AND is_instructor = ?";
            $params[] = $this->is_instructor; $types .= "i";
        }
        if ($this->is_hr) {
            $where .= " AND is_hr = ?";
            $params[] = $this->is_hr; $types .= "i";
        }

        // 🔐 Sorting whitelist for safety
        $allowedColumns = ['id', 'first_name', 'last_name', 'email', 'phone', 'last_login'];
        $allowedDirections = ['asc', 'desc'];
        $sortBy = in_array($this->sort_by, $allowedColumns) ? $this->sort_by : 'id';
        $sortDir = in_array(strtolower($this->sort_dir), $allowedDirections) ? strtoupper($this->sort_dir) : 'ASC';
        $countQuery = "SELECT COUNT(*) AS total FROM users $where";
        $countStmt = $this->conn->prepare($countQuery);
        if ($types) $countStmt->bind_param($types, ...$params);
        $countStmt->execute();
        $total = (int)($countStmt->get_result()->fetch_assoc()["total"] ?? 0);
        $totalPages = max(1, (int)ceil($total / $perPage));
        $query = "SELECT * FROM users $where ORDER BY $sortBy $sortDir LIMIT ? OFFSET ?";
        $stmt = $this->conn->prepare($query);
        $types2 = $types . "ii";
        $params2 = array_merge($params, [$perPage, $offset]);
        $stmt->bind_param($types2, ...$params2);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
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
    private function buildPaginationLinks(int $page, int $perPage, int $totalPages): array{
        $query = $_GET;
        unset($query['page'], $query['per_page']);
        $base = strtok($_SERVER["REQUEST_URI"], '?');
        $makeUrl = function(int $p) use ($query, $base, $perPage) {
            return $base . '?' . http_build_query(array_merge($query, ['page' => $p,'per_page' => $perPage]));
        };
        return [
            "self"  => $makeUrl($page),
            "first" => $makeUrl(1),
            "last"  => $makeUrl($totalPages),
            "next"  => ($page < $totalPages) ? $makeUrl($page + 1) : null,
            "prev"  => ($page > 1) ? $makeUrl($page - 1) : null
        ];
    }
    public function register_learner(): bool{
        $stmt = $this->conn->prepare("INSERT INTO users 
        (first_name, last_name, email, phone, password, token) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss",  $this->first_name, $this->last_name, $this->email, $this->phone,$this->password, $this->token);
        if($stmt->execute()){ return true; } return false;
    }
    public function activate_account(): bool{
        $stmt = $this->conn->prepare("UPDATE users SET is_active = ?, is_learner = ? WHERE email = ?");
        $stmt->bind_param("iis", $this->is_active,$this->is_learner, $this->email);
        if($stmt->execute()){ return true; }return false;
    }
}
