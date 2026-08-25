<?php
/**
 * RoleService — RBAC core for the LMS.
 * Manages roles, permissions, user↔role assignments, and authorization checks.
 *
 * Column mapping:
 *   users.id → user_roles.user_id
 *   roles.role_id → user_roles.role_id, role_permissions.role_id
 *   permissions.permission_id → role_permissions.permission_id
 */
class RoleService
{
    private mysqli $conn;
    private int $tenant_id;
    private int $actor_id;  // The user performing the action

    /** In-memory cache: user_id → permission_key[] */
    private array $permCache = [];

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    public function setContext(int $tenant_id, int $actor_id): self
    {
        $this->tenant_id = $tenant_id;
        $this->actor_id = $actor_id;
        return $this;
    }

    // ═══════════════════════════════════════════
    //  ROLE CRUD
    // ═══════════════════════════════════════════

    /** List all roles for this tenant with user counts */
    public function listRoles(): array
    {
        $sql = "SELECT r.role_id, r.role_name, r.role_key, r.description, r.is_system, r.is_active,
                       r.created_at, r.updated_at,
                       COUNT(ur.user_id) as user_count
                FROM roles r
                LEFT JOIN user_roles ur ON r.role_id = ur.role_id
                WHERE r.tenant_id = ?
                GROUP BY r.role_id
                ORDER BY r.is_system DESC, r.role_name ASC";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param('i', $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** Get single role with its permissions */
    public function getRole(int $roleId): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM roles WHERE role_id = ? AND tenant_id = ?"
        );
        $stmt->bind_param('ii', $roleId, $this->tenant_id);
        $stmt->execute();
        $role = $stmt->get_result()->fetch_assoc();
        if (!$role) return null;

        $role['permissions'] = $this->getRolePermissions($roleId);
        return $role;
    }

    /** Create a custom role */
    public function createRole(string $name, string $key, string $description = ''): array
    {
        // Sanitize key
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower($key));
        if (!$key) return ['success' => false, 'error' => 'Invalid role key'];

        $stmt = $this->conn->prepare(
            "INSERT INTO roles (tenant_id, role_name, role_key, description, is_system)
             VALUES (?, ?, ?, ?, 0)"
        );
        $stmt->bind_param('isss', $this->tenant_id, $name, $key, $description);

        if (!$stmt->execute()) {
            if ($this->conn->errno === 1062) {
                return ['success' => false, 'error' => "Role key '$key' already exists"];
            }
            return ['success' => false, 'error' => 'Failed to create role'];
        }

        $roleId = $this->conn->insert_id;
        $this->logActivity(0, 'role_created', ['role_id' => $roleId, 'role_key' => $key]);

        return ['success' => true, 'role_id' => $roleId];
    }

    /** Update a role (name/description only, key is immutable) */
    public function updateRole(int $roleId, string $name, string $description = ''): array
    {
        $stmt = $this->conn->prepare(
            "UPDATE roles SET role_name = ?, description = ? WHERE role_id = ? AND tenant_id = ?"
        );
        $stmt->bind_param('ssii', $name, $description, $roleId, $this->tenant_id);
        $stmt->execute();

        if ($stmt->affected_rows === 0) return ['success' => false, 'error' => 'Role not found'];

        $this->logActivity(0, 'role_updated', ['role_id' => $roleId]);
        return ['success' => true];
    }

    /** Delete a custom role (not system roles) */
    public function deleteRole(int $roleId): array
    {
        // Check if system role
        $stmt = $this->conn->prepare(
            "SELECT is_system, role_key FROM roles WHERE role_id = ? AND tenant_id = ?"
        );
        $stmt->bind_param('ii', $roleId, $this->tenant_id);
        $stmt->execute();
        $role = $stmt->get_result()->fetch_assoc();

        if (!$role) return ['success' => false, 'error' => 'Role not found'];
        if ($role['is_system']) return ['success' => false, 'error' => 'Cannot delete system roles'];

        // Remove all user_roles assignments first
        $stmt = $this->conn->prepare("DELETE FROM user_roles WHERE role_id = ?");
        $stmt->bind_param('i', $roleId);
        $stmt->execute();

        // Delete the role (cascades to role_permissions)
        $stmt = $this->conn->prepare("DELETE FROM roles WHERE role_id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $roleId, $this->tenant_id);
        $stmt->execute();

        $this->logActivity(0, 'role_deleted', ['role_id' => $roleId, 'role_key' => $role['role_key']]);
        return ['success' => true];
    }

    // ═══════════════════════════════════════════
    //  PERMISSIONS
    // ═══════════════════════════════════════════

    /** List all permissions grouped by module */
    public function listPermissions(): array
    {
        $stmt = $this->conn->prepare(
            "SELECT permission_id, permission_key, module, description FROM permissions ORDER BY module, permission_key"
        );
        $stmt->execute();
        $all = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Group by module
        $grouped = [];
        foreach ($all as $p) {
            $grouped[$p['module']][] = $p;
        }
        return $grouped;
    }

    /** Get permissions assigned to a role */
    public function getRolePermissions(int $roleId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT p.permission_id, p.permission_key, p.module, p.description
             FROM role_permissions rp
             JOIN permissions p ON rp.permission_id = p.permission_id
             WHERE rp.role_id = ?
             ORDER BY p.module, p.permission_key"
        );
        $stmt->bind_param('i', $roleId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /** Set permissions for a role (replaces all) */
    public function setRolePermissions(int $roleId, array $permissionIds): array
    {
        // Verify role belongs to tenant
        $stmt = $this->conn->prepare("SELECT role_id FROM roles WHERE role_id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $roleId, $this->tenant_id);
        $stmt->execute();
        if (!$stmt->get_result()->fetch_assoc()) {
            return ['success' => false, 'error' => 'Role not found'];
        }

        // Delete existing
        $stmt = $this->conn->prepare("DELETE FROM role_permissions WHERE role_id = ?");
        $stmt->bind_param('i', $roleId);
        $stmt->execute();

        // Insert new
        if (!empty($permissionIds)) {
            $stmt = $this->conn->prepare(
                "INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)"
            );
            foreach ($permissionIds as $pid) {
                $pid = (int)$pid;
                $stmt->bind_param('ii', $roleId, $pid);
                $stmt->execute();
            }
        }

        // Clear cache for all users with this role
        $this->permCache = [];

        $this->logActivity(0, 'role_permissions_updated', [
            'role_id' => $roleId, 'permission_count' => count($permissionIds)
        ]);

        return ['success' => true, 'count' => count($permissionIds)];
    }

    // ═══════════════════════════════════════════
    //  USER ↔ ROLE ASSIGNMENT
    // ═══════════════════════════════════════════

    /** Assign a role to a user */
    public function assignRole(int $userId, int $roleId): array
    {
        $stmt = $this->conn->prepare(
            "INSERT IGNORE INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)"
        );
        $stmt->bind_param('iii', $userId, $roleId, $this->actor_id);
        $stmt->execute();

        unset($this->permCache[$userId]);

        // Get role name for log
        $rStmt = $this->conn->prepare("SELECT role_key FROM roles WHERE role_id = ?");
        $rStmt->bind_param('i', $roleId);
        $rStmt->execute();
        $roleName = $rStmt->get_result()->fetch_assoc()['role_key'] ?? '';

        $this->logActivity($userId, 'role_assigned', ['role_id' => $roleId, 'role_key' => $roleName]);
        return ['success' => true];
    }

    /** Remove a role from a user */
    public function removeRole(int $userId, int $roleId): array
    {
        $stmt = $this->conn->prepare("DELETE FROM user_roles WHERE user_id = ? AND role_id = ?");
        $stmt->bind_param('ii', $userId, $roleId);
        $stmt->execute();

        unset($this->permCache[$userId]);

        $this->logActivity($userId, 'role_removed', ['role_id' => $roleId]);
        return ['success' => true];
    }

    /** Get all roles assigned to a user */
    public function getUserRoles(int $userId): array
    {
        $stmt = $this->conn->prepare(
            "SELECT r.role_id, r.role_name, r.role_key, r.description, r.is_system,
                    ur.assigned_at, ur.assigned_by
             FROM user_roles ur
             JOIN roles r ON ur.role_id = r.role_id
             WHERE ur.user_id = ? AND r.tenant_id = ?
             ORDER BY r.is_system DESC, r.role_name"
        );
        $stmt->bind_param('ii', $userId, $this->tenant_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // ═══════════════════════════════════════════
    //  AUTHORIZATION CHECKS
    // ═══════════════════════════════════════════

    /** Get all effective permission keys for a user (merged from all roles) */
    public function getUserPermissions(int $userId): array
    {
        if (isset($this->permCache[$userId])) return $this->permCache[$userId];

        $stmt = $this->conn->prepare(
            "SELECT DISTINCT p.permission_key
             FROM user_roles ur
             JOIN role_permissions rp ON ur.role_id = rp.role_id
             JOIN permissions p ON rp.permission_id = p.permission_id
             JOIN roles r ON ur.role_id = r.role_id
             WHERE ur.user_id = ? AND r.tenant_id = ? AND r.is_active = 1"
        );
        $stmt->bind_param('ii', $userId, $this->tenant_id);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        $keys = array_column($rows, 'permission_key');
        $this->permCache[$userId] = $keys;
        return $keys;
    }

    /** Check if a user has a specific permission */
    public function hasPermission(int $userId, string $permKey): bool
    {
        $perms = $this->getUserPermissions($userId);
        return in_array($permKey, $perms, true);
    }

    /** Check if a user has ANY of the given permissions */
    public function hasAnyPermission(int $userId, array $permKeys): bool
    {
        $perms = $this->getUserPermissions($userId);
        return !empty(array_intersect($permKeys, $perms));
    }

    /** Check if a user has a specific role */
    public function hasRole(int $userId, string $roleKey): bool
    {
        $stmt = $this->conn->prepare(
            "SELECT 1 FROM user_roles ur
             JOIN roles r ON ur.role_id = r.role_id
             WHERE ur.user_id = ? AND r.role_key = ? AND r.tenant_id = ?"
        );
        $stmt->bind_param('isi', $userId, $roleKey, $this->tenant_id);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

    /** Require a permission — throws JSON error response if denied */
    public function requirePermission(string $permKey): void
    {
        if (!$this->hasPermission($this->actor_id, $permKey)) {
            http_response_code(403);
            echo json_encode([
                'response_code' => 403, 'status' => 'error',
                'message' => "Permission denied: $permKey"
            ]);
            exit;
        }
    }

    // ═══════════════════════════════════════════
    //  ACTIVITY LOG HELPER
    // ═══════════════════════════════════════════

    public function logActivity(int $userId, string $action, array $details = []): void
    {
        $detailsJson = !empty($details) ? json_encode($details) : null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null;
        $targetUser = $userId ?: $this->actor_id;

        $stmt = $this->conn->prepare(
            "INSERT INTO user_activity_log (tenant_id, user_id, action, details, ip_address, user_agent, performed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iissssi',
            $this->tenant_id, $targetUser, $action, $detailsJson, $ip, $ua, $this->actor_id
        );
        $stmt->execute();
    }
}
