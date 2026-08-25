<?php
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../class/SecurityLogger.php';
require_once __DIR__ . '/../class/AuditLogger.php';

$db = new Database();
$conn = $db->getConnection();

// Initialize loggers
$securityLogger = new SecurityLogger($conn);
$auditLogger = new AuditLogger($conn);

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = getJsonInput();

switch ($action) {
    // ==========================================
    // LOGIN
    // ==========================================
    case 'login':
        $email = $db->sanitize_input($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $tenant_id = isset($input['tenant_id']) ? (int)$input['tenant_id'] : null;

        if (empty($email) || empty($password)) {
            $db->send_response(400, 'error', null, 'Email and password required');
            exit;
        }

        $user = verifyCredentials($email, $password, $tenant_id);

        if (!$user) {
            // Log failed login attempt
            if ($tenant_id) {
                $securityLogger->setTenant($tenant_id);
            }
            $securityLogger->logLoginAttempt($email, false, 'Invalid credentials');

            $db->send_response(401, 'error', null, 'Invalid email or password');
            exit;
        }

        // Set tenant for loggers
        $securityLogger->setTenant($user['tenant_id']);
        $auditLogger->setContext($user['tenant_id'], $user['id']);

        // Log successful login attempt
        $securityLogger->logLoginAttempt($email, true);

        // Record login in history
        $loginHistoryId = $securityLogger->recordLogin($user['id'], 'password');

        // Log audit trail
        $auditLogger->log(
            'login',
            'security',
            'user',
            (string)$user['id'],
            $user['first_name'] . ' ' . $user['last_name']
        );

        $db->send_response(200, 'success', [
            'user_id' => $user['id'],
            'token' => $user['token'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'role' => $user['role'],
            'department' => $user['department'],
            'avatar_url' => $user['avatar_url'],
            'tenant_id' => $user['tenant_id'],
            'login_history_id' => $loginHistoryId
        ], 'Login successful');
        break;

    // ==========================================
    // LOGOUT
    // ==========================================
    case 'logout':
        $auth = authenticate();

        if ($auth['success']) {
            $user = $auth['user'];

            // Set tenant for loggers
            $securityLogger->setTenant($user['tenant_id']);
            $auditLogger->setContext($user['tenant_id'], $user['id']);

            // Record logout if login_history_id provided
            $loginHistoryId = $input['login_history_id'] ?? null;
            if ($loginHistoryId) {
                $securityLogger->recordLogout($loginHistoryId);
            }

            // Log audit trail
            $auditLogger->log(
                'logout',
                'security',
                'user',
                (string)$user['id'],
                $user['first_name'] . ' ' . $user['last_name']
            );

            // Invalidate token
            invalidateToken($user['id']);
        }

        $db->send_response(200, 'success', null, 'Logged out successfully');
        break;

    // ==========================================
    // VERIFY TOKEN
    // ==========================================
    case 'verify':
        $auth = authenticate();

        if (!$auth['success']) {
            $db->send_response(401, 'error', null, $auth['message']);
            exit;
        }

        $user = $auth['user'];
        $db->send_response(200, 'success', [
            'user_id' => $user['id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'role' => $user['role'],
            'tenant_id' => $user['tenant_id']
        ], 'Token valid');
        break;

    // ==========================================
    // GET CURRENT USER
    // ==========================================
    case 'me':
        $auth = authenticate();

        if (!$auth['success']) {
            $db->send_response(401, 'error', null, $auth['message']);
            exit;
        }

        $user = $auth['user'];
        unset($user['token']); // Don't expose token

        $db->send_response(200, 'success', $user, 'User retrieved');
        break;

    // ==========================================
    // CHANGE PASSWORD
    // ==========================================
    case 'change-password':
        $auth = authenticate();
        if (!$auth['success']) {
            $db->send_response(401, 'error', null, 'Unauthorized');
            exit;
        }

        $user = $auth['user'];
        $current_password = $input['current_password'] ?? '';
        $new_password = $input['new_password'] ?? '';

        if (empty($current_password) || empty($new_password)) {
            $db->send_response(400, 'error', null, 'Current and new password required');
            exit;
        }

        // Set tenant for loggers
        $securityLogger->setTenant($user['tenant_id']);
        $auditLogger->setContext($user['tenant_id'], $user['id']);

        // Get user with password
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->bind_param("i", $user['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $userData = $result->fetch_assoc();
        $stmt->close();

        // Verify current password
        $validPassword = false;
        if (password_get_info($userData['password'])['algo'] !== 0) {
            $validPassword = password_verify($current_password, $userData['password']);
        } else {
            $validPassword = ($current_password === $userData['password']);
        }

        if (!$validPassword) {
            // Log failed password change attempt
            $auditLogger->log(
                'password_change_failed',
                'security',
                'user',
                (string)$user['id'],
                $user['first_name'] . ' ' . $user['last_name'],
                null,
                ['reason' => 'Invalid current password']
            );

            $db->send_response(400, 'error', null, 'Current password is incorrect');
            exit;
        }

        // Validate new password
        if (strlen($new_password) < 8) {
            $db->send_response(400, 'error', null, 'Password must be at least 8 characters');
            exit;
        }

        // Update password (hashed)
        $newHash = hashPassword($new_password);
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $newHash, $user['id']);

        if ($stmt->execute()) {
            // Log password change in security log
            $securityLogger->logPasswordChange($user['id'], 'user_initiated');

            // Log audit trail
            $auditLogger->log(
                'password_changed',
                'security',
                'user',
                (string)$user['id'],
                $user['first_name'] . ' ' . $user['last_name']
            );

            $db->send_response(200, 'success', null, 'Password changed successfully');
        } else {
            $db->send_response(500, 'error', null, 'Failed to change password');
        }
        $stmt->close();
        break;

    // ==========================================
    // REGISTER (Admin creates user)
    // ==========================================
    case 'register':
        $auth = authenticate();
        if (!$auth['success']) {
            $db->send_response(401, 'error', null, 'Unauthorized');
            exit;
        }

        $admin = $auth['user'];
        if (!isAdmin($admin)) {
            $db->send_response(403, 'error', null, 'Admin access required');
            exit;
        }

        // Set tenant for loggers
        $securityLogger->setTenant($admin['tenant_id']);
        $auditLogger->setContext($admin['tenant_id'], $admin['id']);

        $email = $db->sanitize_input($input['email'] ?? '');
        $password = $input['password'] ?? $db->generate_password(10);
        $first_name = $db->sanitize_input($input['first_name'] ?? '');
        $last_name = $db->sanitize_input($input['last_name'] ?? '');
        $role = $db->sanitize_input($input['role'] ?? 'learner');
        $department = $db->sanitize_input($input['department'] ?? '');
        $tenant_id = $admin['tenant_id'];

        if (empty($email) || empty($first_name)) {
            $db->send_response(400, 'error', null, 'Email and first name required');
            exit;
        }

        // Check if email exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND tenant_id = ?");
        $stmt->bind_param("si", $email, $tenant_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $db->send_response(400, 'error', null, 'Email already exists');
            exit;
        }
        $stmt->close();

        // Create user
        $hashedPassword = hashPassword($password);
        $token = $db->generate_token();

        $stmt = $conn->prepare("INSERT INTO users (tenant_id, email, password, first_name, last_name, role, department, token, is_active, created) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");
        $stmt->bind_param("isssssss", $tenant_id, $email, $hashedPassword, $first_name, $last_name, $role, $department, $token);

        if ($stmt->execute()) {
            $new_user_id = $conn->insert_id;

            // Log user creation in audit trail
            $auditLogger->logCreate(
                'user',
                $new_user_id,
                $first_name . ' ' . $last_name,
                [
                    'email' => $email,
                    'role' => $role,
                    'department' => $department,
                    'created_by' => $admin['id']
                ]
            );

            // Log admin activity
            $auditLogger->logAdminActivity(
                'user_created',
                'user_management',
                'user',
                (string)$new_user_id,
                $first_name . ' ' . $last_name,
                [
                    'email' => $email,
                    'role' => $role,
                    'department' => $department
                ]
            );

            $db->send_response(200, 'success', [
                'user_id' => $new_user_id,
                'email' => $email,
                'temporary_password' => $password // Send back so admin can share with user
            ], 'User created successfully');
        } else {
            $db->send_response(500, 'error', null, 'Failed to create user');
        }
        $stmt->close();
        break;

    // ==========================================
    // ADMIN RESET PASSWORD (Admin resets user password)
    // ==========================================
    case 'reset-password':
        $auth = authenticate();
        if (!$auth['success']) {
            $db->send_response(401, 'error', null, 'Unauthorized');
            exit;
        }

        $admin = $auth['user'];
        if (!isAdmin($admin)) {
            $db->send_response(403, 'error', null, 'Admin access required');
            exit;
        }

        $target_user_id = (int)($input['user_id'] ?? 0);
        if (!$target_user_id) {
            $db->send_response(400, 'error', null, 'user_id required');
            exit;
        }

        // Set tenant for loggers
        $securityLogger->setTenant($admin['tenant_id']);
        $auditLogger->setContext($admin['tenant_id'], $admin['id']);

        // Verify target user exists and is in same tenant
        $stmt = $conn->prepare("SELECT id, email, first_name, last_name, tenant_id FROM users WHERE id = ?");
        $stmt->bind_param("i", $target_user_id);
        $stmt->execute();
        $targetUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$targetUser || $targetUser['tenant_id'] != $admin['tenant_id']) {
            $db->send_response(404, 'error', null, 'User not found');
            exit;
        }

        // Generate new password
        $newPassword = $input['new_password'] ?? $db->generate_password(10);
        $newHash = hashPassword($newPassword);

        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->bind_param("si", $newHash, $target_user_id);

        if ($stmt->execute()) {
            // Log password reset in security log
            $securityLogger->logPasswordChange($target_user_id, 'admin_reset');

            // Log audit trail
            $auditLogger->log(
                'password_reset',
                'security',
                'user',
                (string)$target_user_id,
                $targetUser['first_name'] . ' ' . $targetUser['last_name'],
                null,
                ['reset_by' => $admin['id']]
            );

            // Log admin activity
            $auditLogger->logAdminActivity(
                'password_reset',
                'user_management',
                'user',
                (string)$target_user_id,
                $targetUser['first_name'] . ' ' . $targetUser['last_name'],
                ['target_email' => $targetUser['email']]
            );

            $db->send_response(200, 'success', [
                'user_id' => $target_user_id,
                'temporary_password' => $newPassword
            ], 'Password reset successfully');
        } else {
            $db->send_response(500, 'error', null, 'Failed to reset password');
        }
        $stmt->close();
        break;

    // ==========================================
    // UPDATE USER (Admin updates user)
    // ==========================================
    case 'update-user':
        $auth = authenticate();
        if (!$auth['success']) {
            $db->send_response(401, 'error', null, 'Unauthorized');
            exit;
        }

        $admin = $auth['user'];
        if (!isAdmin($admin)) {
            $db->send_response(403, 'error', null, 'Admin access required');
            exit;
        }

        $target_user_id = (int)($input['user_id'] ?? 0);
        if (!$target_user_id) {
            $db->send_response(400, 'error', null, 'user_id required');
            exit;
        }

        // Set tenant for loggers
        $securityLogger->setTenant($admin['tenant_id']);
        $auditLogger->setContext($admin['tenant_id'], $admin['id']);

        // Get current user data
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param("ii", $target_user_id, $admin['tenant_id']);
        $stmt->execute();
        $oldData = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$oldData) {
            $db->send_response(404, 'error', null, 'User not found');
            exit;
        }

        // Build update query dynamically
        $updates = [];
        $params = [];
        $types = '';
        $newData = [];

        $allowedFields = ['first_name', 'last_name', 'email', 'role', 'department', 'job_title', 'manager_id', 'avatar_url'];

        foreach ($allowedFields as $field) {
            if (isset($input[$field]) && $input[$field] !== $oldData[$field]) {
                $updates[] = "$field = ?";
                $params[] = $db->sanitize_input($input[$field]);
                $types .= 's';
                $newData[$field] = $input[$field];
            }
        }

        if (empty($updates)) {
            $db->send_response(400, 'error', null, 'No fields to update');
            exit;
        }

        // Check for role change (security sensitive)
        if (isset($input['role']) && $input['role'] !== $oldData['role']) {
            $securityLogger->logPermissionChange(
                $target_user_id,
                $admin['id'],
                ['role' => $oldData['role']],
                ['role' => $input['role']]
            );
        }

        $params[] = $target_user_id;
        $types .= 'i';

        $query = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);

        if ($stmt->execute()) {
            // Log update in audit trail
            $auditLogger->logUpdate(
                'user',
                $target_user_id,
                $oldData['first_name'] . ' ' . $oldData['last_name'],
                $oldData,
                array_merge($oldData, $newData)
            );

            // Log admin activity
            $auditLogger->logAdminActivity(
                'user_updated',
                'user_management',
                'user',
                (string)$target_user_id,
                $oldData['first_name'] . ' ' . $oldData['last_name'],
                ['changed_fields' => array_keys($newData)]
            );

            $db->send_response(200, 'success', ['user_id' => $target_user_id, 'updated_fields' => array_keys($newData)], 'User updated successfully');
        } else {
            $db->send_response(500, 'error', null, 'Failed to update user');
        }
        $stmt->close();
        break;

    // ==========================================
    // DEACTIVATE USER
    // ==========================================
    case 'deactivate-user':
        $auth = authenticate();
        if (!$auth['success']) {
            $db->send_response(401, 'error', null, 'Unauthorized');
            exit;
        }

        $admin = $auth['user'];
        if (!isAdmin($admin)) {
            $db->send_response(403, 'error', null, 'Admin access required');
            exit;
        }

        $target_user_id = (int)($input['user_id'] ?? 0);
        if (!$target_user_id) {
            $db->send_response(400, 'error', null, 'user_id required');
            exit;
        }

        // Prevent self-deactivation
        if ($target_user_id == $admin['id']) {
            $db->send_response(400, 'error', null, 'Cannot deactivate your own account');
            exit;
        }

        // Set tenant for loggers
        $securityLogger->setTenant($admin['tenant_id']);
        $auditLogger->setContext($admin['tenant_id'], $admin['id']);

        // Verify target user
        $stmt = $conn->prepare("SELECT id, email, first_name, last_name, tenant_id, is_active FROM users WHERE id = ?");
        $stmt->bind_param("i", $target_user_id);
        $stmt->execute();
        $targetUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$targetUser || $targetUser['tenant_id'] != $admin['tenant_id']) {
            $db->send_response(404, 'error', null, 'User not found');
            exit;
        }

        if (!$targetUser['is_active']) {
            $db->send_response(400, 'error', null, 'User is already deactivated');
            exit;
        }

        $stmt = $conn->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
        $stmt->bind_param("i", $target_user_id);

        if ($stmt->execute()) {
            // Invalidate user's token
            invalidateToken($target_user_id);

            // Log deactivation
            $auditLogger->logUpdate(
                'user',
                $target_user_id,
                $targetUser['first_name'] . ' ' . $targetUser['last_name'],
                ['is_active' => 1],
                ['is_active' => 0]
            );

            // Log admin activity
            $auditLogger->logAdminActivity(
                'user_deactivated',
                'user_management',
                'user',
                (string)$target_user_id,
                $targetUser['first_name'] . ' ' . $targetUser['last_name'],
                ['reason' => $input['reason'] ?? 'Not specified']
            );

            // Create security alert
            $securityLogger->createAlert(
                'account_deactivated',
                'medium',
                "User account deactivated: " . $targetUser['email'],
                ['deactivated_by' => $admin['id'], 'reason' => $input['reason'] ?? null],
                $target_user_id
            );

            $db->send_response(200, 'success', ['user_id' => $target_user_id], 'User deactivated successfully');
        } else {
            $db->send_response(500, 'error', null, 'Failed to deactivate user');
        }
        $stmt->close();
        break;

    // ==========================================
    // ACTIVATE USER
    // ==========================================
    case 'activate-user':
        $auth = authenticate();
        if (!$auth['success']) {
            $db->send_response(401, 'error', null, 'Unauthorized');
            exit;
        }

        $admin = $auth['user'];
        if (!isAdmin($admin)) {
            $db->send_response(403, 'error', null, 'Admin access required');
            exit;
        }

        $target_user_id = (int)($input['user_id'] ?? 0);
        if (!$target_user_id) {
            $db->send_response(400, 'error', null, 'user_id required');
            exit;
        }

        // Set tenant for loggers
        $securityLogger->setTenant($admin['tenant_id']);
        $auditLogger->setContext($admin['tenant_id'], $admin['id']);

        // Verify target user
        $stmt = $conn->prepare("SELECT id, email, first_name, last_name, tenant_id, is_active FROM users WHERE id = ?");
        $stmt->bind_param("i", $target_user_id);
        $stmt->execute();
        $targetUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$targetUser || $targetUser['tenant_id'] != $admin['tenant_id']) {
            $db->send_response(404, 'error', null, 'User not found');
            exit;
        }

        if ($targetUser['is_active']) {
            $db->send_response(400, 'error', null, 'User is already active');
            exit;
        }

        $stmt = $conn->prepare("UPDATE users SET is_active = 1 WHERE id = ?");
        $stmt->bind_param("i", $target_user_id);

        if ($stmt->execute()) {
            // Log activation
            $auditLogger->logUpdate(
                'user',
                $target_user_id,
                $targetUser['first_name'] . ' ' . $targetUser['last_name'],
                ['is_active' => 0],
                ['is_active' => 1]
            );

            // Log admin activity
            $auditLogger->logAdminActivity(
                'user_activated',
                'user_management',
                'user',
                (string)$target_user_id,
                $targetUser['first_name'] . ' ' . $targetUser['last_name'],
                ['reason' => $input['reason'] ?? 'Not specified']
            );

            $db->send_response(200, 'success', ['user_id' => $target_user_id], 'User activated successfully');
        } else {
            $db->send_response(500, 'error', null, 'Failed to activate user');
        }
        $stmt->close();
        break;

    // ==========================================
    // REFRESH TOKEN
    // ==========================================
    case 'refresh-token':
        $auth = authenticate();
        if (!$auth['success']) {
            $db->send_response(401, 'error', null, 'Unauthorized');
            exit;
        }

        $user = $auth['user'];

        // Generate new token
        $newToken = $db->generate_token();

        $stmt = $conn->prepare("UPDATE users SET token = ? WHERE id = ?");
        $stmt->bind_param("si", $newToken, $user['id']);

        if ($stmt->execute()) {
            $db->send_response(200, 'success', [
                'user_id' => $user['id'],
                'token' => $newToken
            ], 'Token refreshed');
        } else {
            $db->send_response(500, 'error', null, 'Failed to refresh token');
        }
        $stmt->close();
        break;

    default:
        $db->send_response(400, 'error', null, 'Invalid action. Use: login, logout, verify, me, change-password, refresh-token, register, reset-password, update-user, deactivate-user, activate-user');
}