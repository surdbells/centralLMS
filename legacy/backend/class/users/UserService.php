<?php
/**
 * UserService — User CRUD, search, bulk operations, stats.
 * Uses RoleService for permission checks and activity logging.
 *
 * Column mapping (users table):
 *   u.department → departments.department_id
 *   u.team       → teams.team_id
 *   u.branch     → branches.branch_id
 *   u.staffId    → camelCase
 *   u.job_title  → stored as INT (FK or literal)
 */
class UserService
{
    private mysqli $conn;
    private int $tenant_id;
    private int $actor_id;
    private RoleService $roles;

    public function __construct(mysqli $conn, RoleService $roleService)
    {
        $this->conn = $conn;
        $this->roles = $roleService;
    }

    public function setContext(int $tenant_id, int $actor_id): self
    {
        $this->tenant_id = $tenant_id;
        $this->actor_id = $actor_id;
        $this->roles->setContext($tenant_id, $actor_id);
        return $this;
    }

    // ═══════════════════════════════════════════
    //  LIST / SEARCH
    // ═══════════════════════════════════════════

    /**
     * List users with filters, search, pagination, sorting.
     * Returns paginated result with role info.
     */
    public function listUsers(array $params = []): array
    {
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(1, (int)($params['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;
        $sortBy  = $params['sort_by'] ?? 'u.first_name';
        $sortDir = strtoupper($params['sort_dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

        // Whitelist sort columns
        $sortWhitelist = [
            'first_name' => 'u.first_name', 'last_name' => 'u.last_name',
            'email' => 'u.email', 'department' => 'd.department_name',
            'created' => 'u.created', 'last_login_at' => 'u.last_login_at',
            'staffId' => 'u.staffId', 'is_active' => 'u.is_active'
        ];
        $sortCol = $sortWhitelist[$sortBy] ?? 'u.first_name';

        // Build WHERE
        $where = ["u.tenant_id = ?", "u.deleted_at IS NULL"];
        $types = 'i';
        $binds = [$this->tenant_id];

        // Search
        if (!empty($params['search'])) {
            $term = '%' . $params['search'] . '%';
            $where[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.staffId LIKE ?
                         OR CONCAT(u.first_name, ' ', u.last_name) LIKE ?)";
            $types .= 'sssss';
            array_push($binds, $term, $term, $term, $term, $term);
        }

        // Filters
        if (!empty($params['department_id'])) {
            $where[] = "u.department = ?"; $types .= 'i'; $binds[] = (int)$params['department_id'];
        }
        if (!empty($params['branch_id'])) {
            $where[] = "u.branch = ?"; $types .= 'i'; $binds[] = (int)$params['branch_id'];
        }
        if (!empty($params['team_id'])) {
            $where[] = "u.team = ?"; $types .= 'i'; $binds[] = (int)$params['team_id'];
        }
        if (!empty($params['manager_id'])) {
            $where[] = "u.manager_id = ?"; $types .= 'i'; $binds[] = (int)$params['manager_id'];
        }
        if (isset($params['is_active']) && $params['is_active'] !== '') {
            $where[] = "u.is_active = ?"; $types .= 'i'; $binds[] = (int)$params['is_active'];
        }
        if (!empty($params['status'])) {
            switch ($params['status']) {
                case 'active':    $where[] = "u.is_active = 1 AND u.suspended IS NULL AND u.account_locked = 0"; break;
                case 'suspended': $where[] = "u.suspended IS NOT NULL"; break;
                case 'locked':    $where[] = "u.account_locked = 1"; break;
                case 'inactive':  $where[] = "u.is_active = 0"; break;
            }
        }
        if (!empty($params['role_key'])) {
            $where[] = "EXISTS (SELECT 1 FROM user_roles ur2 JOIN roles r2 ON ur2.role_id = r2.role_id
                        WHERE ur2.user_id = u.id AND r2.role_key = ? AND r2.tenant_id = u.tenant_id)";
            $types .= 's';
            $binds[] = $params['role_key'];
        }

        $whereSQL = implode(' AND ', $where);

        // Count
        $countSQL = "SELECT COUNT(*) as total FROM users u WHERE $whereSQL";
        $countStmt = $this->conn->prepare($countSQL);
        $countStmt->bind_param($types, ...$binds);
        $countStmt->execute();
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];

        // Data
        $sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.staffId,
                       u.department, u.team, u.branch, u.job_title, u.manager_id,
                       u.avatar, u.is_active, u.account_locked,
                       u.last_login_at, u.login_count, u.created, u.hire_date,
                       u.suspended, u.language, u.preferred_learning_mode,
                       d.department_name, t.team_name, b.branch_name,
                       GROUP_CONCAT(DISTINCT r.role_key ORDER BY r.is_system DESC SEPARATOR ',') as role_keys,
                       GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.is_system DESC SEPARATOR ', ') as role_names
                FROM users u
                LEFT JOIN departments d ON u.department = d.department_id
                LEFT JOIN teams t ON u.team = t.team_id
                LEFT JOIN branches b ON u.branch = b.branch_id
                LEFT JOIN user_roles ur ON u.id = ur.user_id
                LEFT JOIN roles r ON ur.role_id = r.role_id AND r.tenant_id = u.tenant_id
                WHERE $whereSQL
                GROUP BY u.id
                ORDER BY $sortCol $sortDir
                LIMIT $perPage OFFSET $offset";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Add computed fields
        foreach ($users as &$u) {
            $u['full_name'] = trim($u['first_name'] . ' ' . ($u['last_name'] ?? ''));
            $u['status'] = $this->computeStatus($u);
            $u['role_list'] = $u['role_keys'] ? explode(',', $u['role_keys']) : [];
        }

        return [
            'data' => $users,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => max(1, ceil($total / $perPage))
            ]
        ];
    }

    // ═══════════════════════════════════════════
    //  GET SINGLE USER
    // ═══════════════════════════════════════════

    public function getUser(int $userId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT u.*, d.department_name, t.team_name, b.branch_name,
                    CONCAT(m.first_name, ' ', m.last_name) as manager_name
             FROM users u
             LEFT JOIN departments d ON u.department = d.department_id
             LEFT JOIN teams t ON u.team = t.team_id
             LEFT JOIN branches b ON u.branch = b.branch_id
             LEFT JOIN users m ON u.manager_id = m.id
             WHERE u.id = ? AND u.tenant_id = ? AND u.deleted_at IS NULL"
        );
        $stmt->bind_param('ii', $userId, $this->tenant_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if (!$user) return null;

        $user['full_name'] = trim($user['first_name'] . ' ' . ($user['last_name'] ?? ''));
        $user['status'] = $this->computeStatus($user);
        $user['roles'] = $this->roles->getUserRoles($userId);
        $user['permissions'] = $this->roles->getUserPermissions($userId);

        // Strip sensitive fields
        unset($user['password'], $user['token'], $user['is_2fa']);

        return $user;
    }

    // ═══════════════════════════════════════════
    //  CREATE USER
    // ═══════════════════════════════════════════

    public function createUser(array $data): array
    {
        // Validate required
        if (empty($data['first_name'])) return ['success' => false, 'error' => 'First name is required'];
        if (empty($data['email'])) return ['success' => false, 'error' => 'Email is required'];
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) return ['success' => false, 'error' => 'Invalid email format'];

        // Check unique email within tenant
        $stmt = $this->conn->prepare(
            "SELECT id FROM users WHERE email = ? AND tenant_id = ? AND deleted_at IS NULL"
        );
        $stmt->bind_param('si', $data['email'], $this->tenant_id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            return ['success' => false, 'error' => 'Email already exists'];
        }

        // Generate token and password
        $token = bin2hex(random_bytes(32));
        $tempPassword = $data['password'] ?? bin2hex(random_bytes(8));
        $hashedPassword = password_hash($tempPassword, PASSWORD_BCRYPT);

        $stmt = $this->conn->prepare(
            "INSERT INTO users (tenant_id, token, first_name, last_name, email, password, phone,
                               department, team, branch, staffId, job_title, manager_id,
                               hire_date, language, preferred_learning_mode,
                               is_active, is_learner, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)"
        );

        $firstName  = $data['first_name'];
        $lastName   = $data['last_name'] ?? null;
        $email      = $data['email'];
        $phone      = $data['phone'] ?? null;
        $dept       = !empty($data['department_id']) ? (int)$data['department_id'] : null;
        $team       = !empty($data['team_id']) ? (int)$data['team_id'] : null;
        $branch     = !empty($data['branch_id']) ? (int)$data['branch_id'] : null;
        $staffId    = $data['staffId'] ?? null;
        $jobTitle   = !empty($data['job_title']) ? (int)$data['job_title'] : null;
        $managerId  = !empty($data['manager_id']) ? (int)$data['manager_id'] : null;
        $hireDate   = $data['hire_date'] ?? null;
        $language   = $data['language'] ?? 'en';
        $learnMode  = $data['preferred_learning_mode'] ?? null;
        $isActive   = (int)($data['is_active'] ?? 1);

        $stmt->bind_param('issssssiiisiisssis',
            $this->tenant_id, $token, $firstName, $lastName, $email, $hashedPassword, $phone,
            $dept, $team, $branch, $staffId, $jobTitle, $managerId,
            $hireDate, $language, $learnMode, $isActive, $this->actor_id
        );

        if (!$stmt->execute()) {
            return ['success' => false, 'error' => 'Failed to create user: ' . $this->conn->error];
        }

        $newUserId = $this->conn->insert_id;

        // Assign roles
        $roleIds = $data['role_ids'] ?? [];
        if (empty($roleIds)) {
            // Default: assign learner role
            $lStmt = $this->conn->prepare(
                "SELECT role_id FROM roles WHERE tenant_id = ? AND role_key = 'learner'"
            );
            $lStmt->bind_param('i', $this->tenant_id);
            $lStmt->execute();
            $learnerRole = $lStmt->get_result()->fetch_assoc();
            if ($learnerRole) $roleIds[] = $learnerRole['role_id'];
        }
        foreach ($roleIds as $rid) {
            $this->roles->assignRole($newUserId, (int)$rid);
        }

        $this->roles->logActivity($newUserId, 'user_created', [
            'email' => $email, 'created_by' => $this->actor_id
        ]);

        return [
            'success' => true,
            'user_id' => $newUserId,
            'temp_password' => !isset($data['password']) ? $tempPassword : null
        ];
    }

    // ═══════════════════════════════════════════
    //  UPDATE USER
    // ═══════════════════════════════════════════

    public function updateUser(int $userId, array $data): array
    {
        // Verify user exists
        $existing = $this->getUser($userId);
        if (!$existing) return ['success' => false, 'error' => 'User not found'];

        // Build dynamic SET clause
        $sets = [];
        $types = '';
        $binds = [];
        $changes = [];

        $fieldMap = [
            'first_name' => ['col' => 'first_name', 'type' => 's'],
            'last_name'  => ['col' => 'last_name',  'type' => 's'],
            'email'      => ['col' => 'email',      'type' => 's'],
            'phone'      => ['col' => 'phone',      'type' => 's'],
            'department_id' => ['col' => 'department', 'type' => 'i'],
            'team_id'    => ['col' => 'team',        'type' => 'i'],
            'branch_id'  => ['col' => 'branch',      'type' => 'i'],
            'staffId'    => ['col' => 'staffId',     'type' => 's'],
            'job_title'  => ['col' => 'job_title',   'type' => 'i'],
            'manager_id' => ['col' => 'manager_id',  'type' => 'i'],
            'hire_date'  => ['col' => 'hire_date',   'type' => 's'],
            'language'   => ['col' => 'language',    'type' => 's'],
            'preferred_learning_mode' => ['col' => 'preferred_learning_mode', 'type' => 's'],
            'is_active'  => ['col' => 'is_active',   'type' => 'i'],
        ];

        foreach ($fieldMap as $inputKey => $cfg) {
            if (array_key_exists($inputKey, $data)) {
                $sets[] = "{$cfg['col']} = ?";
                $types .= $cfg['type'];
                $val = $data[$inputKey];
                $binds[] = ($cfg['type'] === 'i' && $val !== null) ? (int)$val : $val;

                // Track changes
                $oldKey = ($inputKey === 'department_id') ? 'department' :
                          ($inputKey === 'team_id' ? 'team' :
                          ($inputKey === 'branch_id' ? 'branch' : $inputKey));
                if (($existing[$oldKey] ?? null) != $val) {
                    $changes[$inputKey] = ['from' => $existing[$oldKey] ?? null, 'to' => $val];
                }
            }
        }

        if (empty($sets)) return ['success' => true, 'message' => 'No changes'];

        // Email uniqueness check
        if (isset($data['email']) && $data['email'] !== $existing['email']) {
            $stmt = $this->conn->prepare(
                "SELECT id FROM users WHERE email = ? AND tenant_id = ? AND id != ? AND deleted_at IS NULL"
            );
            $stmt->bind_param('sii', $data['email'], $this->tenant_id, $userId);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                return ['success' => false, 'error' => 'Email already in use by another user'];
            }
        }

        $sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE id = ? AND tenant_id = ?";
        $types .= 'ii';
        $binds[] = $userId;
        $binds[] = $this->tenant_id;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();

        // Update roles if provided
        if (isset($data['role_ids']) && is_array($data['role_ids'])) {
            // Remove existing roles
            $delStmt = $this->conn->prepare("DELETE FROM user_roles WHERE user_id = ?");
            $delStmt->bind_param('i', $userId);
            $delStmt->execute();

            // Assign new roles
            foreach ($data['role_ids'] as $rid) {
                $this->roles->assignRole($userId, (int)$rid);
            }
            $changes['roles'] = $data['role_ids'];
        }

        if (!empty($changes)) {
            $this->roles->logActivity($userId, 'user_updated', $changes);
        }

        return ['success' => true, 'changes' => $changes];
    }

    // ═══════════════════════════════════════════
    //  STATUS ACTIONS
    // ═══════════════════════════════════════════

    public function deleteUser(int $userId): array
    {
        $stmt = $this->conn->prepare(
            "UPDATE users SET deleted_at = NOW() WHERE id = ? AND tenant_id = ?"
        );
        $stmt->bind_param('ii', $userId, $this->tenant_id);
        $stmt->execute();
        if ($stmt->affected_rows === 0) return ['success' => false, 'error' => 'User not found'];

        $this->roles->logActivity($userId, 'user_deleted', []);
        return ['success' => true];
    }

    public function activateUser(int $userId): array
    {
        $stmt = $this->conn->prepare(
            "UPDATE users SET is_active = 1, suspended = NULL, account_locked = 0
             WHERE id = ? AND tenant_id = ?"
        );
        $stmt->bind_param('ii', $userId, $this->tenant_id);
        $stmt->execute();

        $this->roles->logActivity($userId, 'user_activated', []);
        return ['success' => true];
    }

    public function suspendUser(int $userId): array
    {
        $stmt = $this->conn->prepare(
            "UPDATE users SET suspended = NOW() WHERE id = ? AND tenant_id = ?"
        );
        $stmt->bind_param('ii', $userId, $this->tenant_id);
        $stmt->execute();

        $this->roles->logActivity($userId, 'user_suspended', []);
        return ['success' => true];
    }

    public function unlockAccount(int $userId): array
    {
        $stmt = $this->conn->prepare(
            "UPDATE users SET account_locked = 0 WHERE id = ? AND tenant_id = ?"
        );
        $stmt->bind_param('ii', $userId, $this->tenant_id);
        $stmt->execute();

        $this->roles->logActivity($userId, 'account_unlocked', []);
        return ['success' => true];
    }

    public function resetPassword(int $userId): array
    {
        $tempPassword = bin2hex(random_bytes(8));
        $hashed = password_hash($tempPassword, PASSWORD_BCRYPT);

        $stmt = $this->conn->prepare(
            "UPDATE users SET password = ? WHERE id = ? AND tenant_id = ?"
        );
        $stmt->bind_param('sii', $hashed, $userId, $this->tenant_id);
        $stmt->execute();

        $this->roles->logActivity($userId, 'password_reset', ['reset_by' => $this->actor_id]);
        return ['success' => true, 'temp_password' => $tempPassword];
    }

    // ═══════════════════════════════════════════
    //  BULK OPERATIONS
    // ═══════════════════════════════════════════

    public function bulkAction(string $action, array $userIds, array $extra = []): array
    {
        if (empty($userIds)) return ['success' => false, 'error' => 'No users selected'];

        $results = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($userIds as $uid) {
            $uid = (int)$uid;
            $res = match ($action) {
                'activate'   => $this->activateUser($uid),
                'suspend'    => $this->suspendUser($uid),
                'delete'     => $this->deleteUser($uid),
                'unlock'     => $this->unlockAccount($uid),
                'assign_role' => isset($extra['role_id']) ? $this->roles->assignRole($uid, (int)$extra['role_id']) : ['success' => false, 'error' => 'role_id required'],
                'remove_role' => isset($extra['role_id']) ? $this->roles->removeRole($uid, (int)$extra['role_id']) : ['success' => false, 'error' => 'role_id required'],
                'assign_department' => $this->updateUser($uid, ['department_id' => $extra['department_id'] ?? null]),
                'assign_branch'     => $this->updateUser($uid, ['branch_id' => $extra['branch_id'] ?? null]),
                'assign_team'       => $this->updateUser($uid, ['team_id' => $extra['team_id'] ?? null]),
                default => ['success' => false, 'error' => "Unknown action: $action"]
            };

            if ($res['success'] ?? false) $results['success']++;
            else { $results['failed']++; $results['errors'][] = "User $uid: " . ($res['error'] ?? 'Failed'); }
        }

        return $results;
    }

    // ═══════════════════════════════════════════
    //  STATS
    // ═══════════════════════════════════════════

    public function getUserStats(): array
    {
        $t = $this->tenant_id;

        $sql = "SELECT
                    COUNT(*) as total_users,
                    SUM(is_active = 1 AND suspended IS NULL AND account_locked = 0) as active,
                    SUM(is_active = 0) as inactive,
                    SUM(suspended IS NOT NULL) as suspended,
                    SUM(account_locked = 1) as locked,
                    SUM(last_login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) as active_30d,
                    SUM(last_login_at IS NULL) as never_logged_in
                FROM users WHERE tenant_id = ? AND deleted_at IS NULL";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $t);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();

        // By role
        $roleStmt = $this->conn->prepare(
            "SELECT r.role_key, r.role_name, COUNT(ur.user_id) as count
             FROM roles r
             LEFT JOIN user_roles ur ON r.role_id = ur.role_id
             LEFT JOIN users u ON ur.user_id = u.id AND u.deleted_at IS NULL
             WHERE r.tenant_id = ?
             GROUP BY r.role_id ORDER BY count DESC"
        );
        $roleStmt->bind_param('i', $t);
        $roleStmt->execute();
        $stats['by_role'] = $roleStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // By department (top 10)
        $deptStmt = $this->conn->prepare(
            "SELECT d.department_name, COUNT(u.id) as count
             FROM users u
             JOIN departments d ON u.department = d.department_id
             WHERE u.tenant_id = ? AND u.deleted_at IS NULL
             GROUP BY u.department ORDER BY count DESC LIMIT 10"
        );
        $deptStmt->bind_param('i', $t);
        $deptStmt->execute();
        $stats['by_department'] = $deptStmt->get_result()->fetch_all(MYSQLI_ASSOC);

        return $stats;
    }

    // ═══════════════════════════════════════════
    //  ACTIVITY LOG
    // ═══════════════════════════════════════════

    public function getActivityLog(array $params = []): array
    {
        $page    = max(1, (int)($params['page'] ?? 1));
        $perPage = min(100, max(1, (int)($params['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        $where = ["al.tenant_id = ?"];
        $types = 'i';
        $binds = [$this->tenant_id];

        if (!empty($params['user_id'])) {
            $where[] = "al.user_id = ?"; $types .= 'i'; $binds[] = (int)$params['user_id'];
        }
        if (!empty($params['action'])) {
            $where[] = "al.action = ?"; $types .= 's'; $binds[] = $params['action'];
        }
        if (!empty($params['date_from'])) {
            $where[] = "al.created_at >= ?"; $types .= 's'; $binds[] = $params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $where[] = "al.created_at <= ?"; $types .= 's'; $binds[] = $params['date_to'] . ' 23:59:59';
        }

        $whereSQL = implode(' AND ', $where);

        // Count
        $countStmt = $this->conn->prepare("SELECT COUNT(*) as total FROM user_activity_log al WHERE $whereSQL");
        $countStmt->bind_param($types, ...$binds);
        $countStmt->execute();
        $total = (int)$countStmt->get_result()->fetch_assoc()['total'];

        // Data
        $sql = "SELECT al.log_id, al.user_id, al.action, al.details, al.ip_address,
                       al.performed_by, al.created_at,
                       CONCAT(u.first_name, ' ', u.last_name) as user_name, u.email as user_email,
                       CONCAT(p.first_name, ' ', p.last_name) as performed_by_name
                FROM user_activity_log al
                LEFT JOIN users u ON al.user_id = u.id
                LEFT JOIN users p ON al.performed_by = p.id
                WHERE $whereSQL
                ORDER BY al.created_at DESC
                LIMIT $perPage OFFSET $offset";

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Parse JSON details
        foreach ($data as &$row) {
            $row['details'] = $row['details'] ? json_decode($row['details'], true) : [];
        }

        return [
            'data' => $data,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => max(1, ceil($total / $perPage))
            ]
        ];
    }

    // ═══════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════

    private function computeStatus(array $user): string
    {
        if (!empty($user['suspended'])) return 'suspended';
        if (!empty($user['account_locked'])) return 'locked';
        if (empty($user['is_active'])) return 'inactive';
        return 'active';
    }
}
