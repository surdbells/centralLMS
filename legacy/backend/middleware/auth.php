<?php

/**
 * Authentication Middleware
 * Works with existing Database class token-based authentication
 */

require_once __DIR__ . '/../config/Database.php';

/**
 * Main authentication function
 * Returns user data if authenticated, error otherwise
 */
function authenticate(): array {
    $headers = getAuthHeaders();

    $token = $headers['token'] ?? null;
    $user_id = $headers['user_id'] ?? null;

    if (!$token || !$user_id) {
        return ['success' => false, 'message' => 'Token and user_id required'];
    }

    $db = new Database();
    $percent = $db->verify_user($token, $user_id);

    if ($percent < 100) {
        return ['success' => false, 'message' => 'Invalid or expired token'];
    }

    // Get user data
    $user = getUserById($user_id);

    if (!$user) {
        return ['success' => false, 'message' => 'User not found'];
    }

    if (isset($user['is_active']) && !$user['is_active']) {
        return ['success' => false, 'message' => 'User account is inactive'];
    }

    return [
        'success' => true,
        'user' => $user
    ];
}

/**
 * Get authentication headers (token and user_id)
 */
function getAuthHeaders(): array {
    $headers = [];

    // Check Authorization header (Bearer token format: "user_id:token")
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['Authorization'] ?? null;

    if ($authHeader && preg_match('/Bearer\s+(\d+):(.+)/', $authHeader, $matches)) {
        $headers['user_id'] = $matches[1];
        $headers['token'] = $matches[2];
        return $headers;
    }

    // Check custom headers
    $headers['token'] = $_SERVER['HTTP_X_TOKEN'] ?? $_SERVER['HTTP_TOKEN'] ?? $_GET['token'] ?? $_POST['token'] ?? null;
    $headers['user_id'] = $_SERVER['HTTP_X_USER_ID'] ?? $_SERVER['HTTP_USER_ID'] ?? $_GET['user_id'] ?? $_POST['user_id'] ?? null;

    // Check JSON body
    if (!$headers['token'] || !$headers['user_id']) {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        if ($data) {
            $headers['token'] = $headers['token'] ?? ($data['token'] ?? null);
            $headers['user_id'] = $headers['user_id'] ?? ($data['user_id'] ?? null);
        }
    }

    return $headers;
}

/**
 * Get user by ID from database
 */
function getUserById($user_id): ?array {
    $db = new Database();
    $conn = $db->getConnection();

    $user_id = (int) $user_id;
    $stmt = $conn->prepare("SELECT id, tenant_id, email, first_name, last_name, role, department, 
                                   job_title, manager_id, avatar, is_active, last_login_at, token
                            FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $user = $result->fetch_assoc();
    $stmt->close();

    return $user;
}

/**
 * Verify user credentials and generate new token
 */
function verifyCredentials(string $email, string $password, ?int $tenant_id = null): ?array {
    $db = new Database();
    $conn = $db->getConnection();

    $email = $db->sanitize_input($email);

    $query = "SELECT id, tenant_id, email, password, first_name, last_name, 
                     role, department, job_title, manager_id, avatar, is_active
              FROM users 
              WHERE email = ? AND is_active = 1";

    if ($tenant_id) {
        $query .= " AND tenant_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("si", $email, $tenant_id);
    } else {
        $stmt = $conn->prepare($query);
        $stmt->bind_param("s", $email);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        return null;
    }

    // Verify password (supports both plain and hashed)
    $validPassword = false;
    if (password_get_info($user['password'])['algo'] !== 0) {
        // Hashed password
        $validPassword = password_verify($password, $user['password']);
    } else {
        // Plain text password (legacy)
        $validPassword = ($password === $user['password']);
    }

    if (!$validPassword) {
        return null;
    }

    // Generate new token
    $newToken = $db->generate_token();

    // Update token in database
    $updateStmt = $conn->prepare("UPDATE users SET token = ?, last_login_at = NOW() WHERE id = ?");
    $updateStmt->bind_param("si", $newToken, $user['id']);
    $updateStmt->execute();
    $updateStmt->close();

    // Remove password from return data, add token
    unset($user['password']);
    $user['token'] = $newToken;

    return $user;
}

/**
 * Hash password for storage
 */
function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

/**
 * Check if user has required role
 */
function hasRole(array $user, array $allowed_roles): bool {
    return in_array($user['role'] ?? '', $allowed_roles);
}

/**
 * Check if user is admin
 */
function isAdmin(array $user): bool {
    return in_array($user['role'] ?? '', ['admin', 'superadmin']);
}

/**
 * Check if user is HR
 */
function isHR(array $user): bool {
    return in_array($user['role'] ?? '', ['admin', 'superadmin', 'hr']);
}

/**
 * Check if user is manager
 */
function isManager(array $user): bool {
    return in_array($user['role'] ?? '', ['admin', 'superadmin', 'hr', 'manager']);
}

/**
 * Require authentication - exits if not authenticated
 */
function requireAuth(): array {
    $auth = authenticate();

    if (!$auth['success']) {
        $db = new Database();
        $db->send_response(401, 'error', null, $auth['message']);
        exit;
    }

    return $auth['user'];
}

/**
 * Require specific role - exits if not authorized
 */
function requireRoleAccess(array $user, array $allowed_roles): void {
    if (!hasRole($user, $allowed_roles)) {
        $db = new Database();
        $db->send_response(403, 'error', null, 'Access denied');
        exit;
    }
}

/**
 * Require admin role
 */
function requireAdmin(array $user): void {
    requireRoleAccess($user, ['admin', 'superadmin']);
}

/**
 * Require HR or admin role
 */
function requireHR(array $user): void {
    requireRoleAccess($user, ['admin', 'superadmin', 'hr']);
}

/**
 * Require manager or higher role
 */
function requireManager(array $user): void {
    requireRoleAccess($user, ['admin', 'superadmin', 'hr', 'manager']);
}

/**
 * Invalidate user token (logout)
 */
function invalidateToken(int $user_id): bool {
    $db = new Database();
    $conn = $db->getConnection();

    $newToken = $db->generate_token(); // Generate new token to invalidate old one
    $stmt = $conn->prepare("UPDATE users SET token = ? WHERE id = ?");
    $stmt->bind_param("si", $newToken, $user_id);
    $result = $stmt->execute();
    $stmt->close();

    return $result;
}

/**
 * Get current authenticated user (convenience function)
 */
function getCurrentUser(): ?array {
    $auth = authenticate();
    return $auth['success'] ? $auth['user'] : null;
}

/**
 * Check if request is from same tenant
 */
function isSameTenant(array $user, int $target_tenant_id): bool {
    return ((int)($user['tenant_id'] ?? 0)) === $target_tenant_id;
}

/**
 * Validate tenant access
 */
function validateTenantAccess(array $user, int $target_tenant_id): void {
    if (!isSameTenant($user, $target_tenant_id)) {
        $db = new Database();
        $db->send_response(403, 'error', null, 'Cross-tenant access denied');
        exit;
    }
}

/**
 * Send standardized API response and exit
 */
function sendResponse(int $code, string $status, $data, string $message): void {
    $db = new Database();
    $db->send_response($code, $status, $data, $message);
    exit;
}

/**
 * Get JSON input from request body
 */
function getJsonInput(): array {
    $input = file_get_contents('php://input');
    return json_decode($input, true) ?? [];
}