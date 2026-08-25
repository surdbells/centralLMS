<?php
/**
 * Users & RBAC API Endpoint
 * Pattern: GET/POST /api/users.php?action=<action>&token=<token>&user_id=<id>&tenant_id=<tid>
 */

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../class/users/RoleService.php';
require_once __DIR__ . '/../class/users/UserService.php';
require_once __DIR__ . '/../class/users/UserImportService.php';
require_once __DIR__ . '/../class/users/AccountService.php';

session_start();

$db   = new Database();
$conn = $db->getConnection();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ── Authentication ──
$token   = $_GET['token'] ?? $_POST['token'] ?? $_SERVER['HTTP_X_TOKEN'] ?? null;
$user_id = $_GET['user_id'] ?? $_POST['user_id'] ?? $_SERVER['HTTP_X_USER_ID'] ?? null;

$input = [];
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
    if (!$token && isset($input['token'])) $token = $input['token'];
    if (!$user_id && isset($input['user_id'])) $user_id = $input['user_id'];
}

if (!$token || !$user_id) { $db->send_response(401, 'error', null, 'Token and user_id required'); exit; }
$verification = $db->verify_user($token, $user_id);
if ($verification < 100) { $db->send_response(401, 'error', null, 'Invalid token'); exit; }

$tenant_id = (int)($_GET['tenant_id'] ?? $input['tenant_id'] ?? 0);
$user_id   = (int)$user_id;
if (!$tenant_id) { $db->send_response(400, 'error', null, 'tenant_id required'); exit; }

// ── Services ──
$roleSvc   = new RoleService($conn);
$userSvc   = new UserService($conn, $roleSvc);
$importSvc = new UserImportService($conn, $userSvc);

$roleSvc->setContext($tenant_id, $user_id);
$userSvc->setContext($tenant_id, $user_id);
$importSvc->setContext($tenant_id, $user_id);

$accountSvc = new AccountService($conn, $roleSvc);
$accountSvc->setContext($tenant_id, $user_id);

// ═══════════════════════════════════════════
switch ($action) {

    // ─────────── USER CRUD ───────────

    case 'list':
        $roleSvc->requirePermission('users.view');
        $params = array_filter(array_intersect_key($_GET, array_flip([
            'search','department_id','branch_id','team_id','manager_id','is_active',
            'status','role_key','page','per_page','sort_by','sort_dir'
        ])), fn($v) => $v !== '');
        $db->send_response(200, 'success', $userSvc->listUsers($params), 'Users retrieved');
        break;

    case 'get':
        $roleSvc->requirePermission('users.view');
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) { $db->send_response(400, 'error', null, 'User ID required'); exit; }
        $user = $userSvc->getUser($id);
        if (!$user) { $db->send_response(404, 'error', null, 'User not found'); exit; }
        $db->send_response(200, 'success', $user, 'User retrieved');
        break;

    case 'create':
        $roleSvc->requirePermission('users.create');
        $r = $userSvc->createUser($input);
        $db->send_response($r['success'] ? 201 : 400, $r['success'] ? 'success' : 'error', $r,
            $r['success'] ? 'User created' : ($r['error'] ?? 'Failed'));
        break;

    case 'update':
        $roleSvc->requirePermission('users.edit');
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        if (!$id) { $db->send_response(400, 'error', null, 'User ID required'); exit; }
        $r = $userSvc->updateUser($id, $input);
        $db->send_response(200, $r['success'] ? 'success' : 'error', $r,
            $r['success'] ? 'User updated' : ($r['error'] ?? 'Failed'));
        break;

    case 'delete':
        $roleSvc->requirePermission('users.delete');
        $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
        $r = $userSvc->deleteUser($id);
        $db->send_response(200, $r['success'] ? 'success' : 'error', $r,
            $r['success'] ? 'User deleted' : ($r['error'] ?? 'Failed'));
        break;

    // ─────────── STATUS ACTIONS ───────────

    case 'activate':
        $roleSvc->requirePermission('users.activate');
        $db->send_response(200, 'success', $userSvc->activateUser((int)($input['id'] ?? 0)), 'Activated');
        break;

    case 'suspend':
        $roleSvc->requirePermission('users.activate');
        $db->send_response(200, 'success', $userSvc->suspendUser((int)($input['id'] ?? 0)), 'Suspended');
        break;

    case 'unlock':
        $roleSvc->requirePermission('users.activate');
        $db->send_response(200, 'success', $userSvc->unlockAccount((int)($input['id'] ?? 0)), 'Unlocked');
        break;

    case 'reset-password':
        $roleSvc->requirePermission('users.reset_password');
        $db->send_response(200, 'success', $userSvc->resetPassword((int)($input['id'] ?? 0)), 'Password reset');
        break;

    // ─────────── BULK ───────────

    case 'bulk-action':
        $permMap = [
            'activate' => 'users.activate', 'suspend' => 'users.activate', 'delete' => 'users.delete',
            'unlock' => 'users.activate', 'assign_role' => 'users.assign_role',
            'remove_role' => 'users.assign_role', 'assign_department' => 'users.edit',
            'assign_branch' => 'users.edit', 'assign_team' => 'users.edit',
        ];
        $ba = $input['bulk_action'] ?? '';
        $roleSvc->requirePermission($permMap[$ba] ?? 'users.edit');
        $r = $userSvc->bulkAction($ba, $input['user_ids'] ?? [], $input['extra'] ?? []);
        $db->send_response(200, 'success', $r, 'Bulk action completed');
        break;

    // ─────────── IMPORT ───────────

    case 'import-template':
        $roleSvc->requirePermission('users.import');
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="user_import_template.csv"');
        echo $importSvc->getImportTemplate();
        exit;

    case 'import-preview':
        $roleSvc->requirePermission('users.import');
        if (empty($_FILES['file']['tmp_name'])) { $db->send_response(400, 'error', null, 'No file uploaded'); exit; }
        $r = $importSvc->validateImport($_FILES['file']['tmp_name']);
        $full = $r['_full_data'] ?? [];
        unset($r['_full_data']);
        $_SESSION['import_data'] = $full;
        $db->send_response(200, $r['success'] ? 'success' : 'error', $r,
            $r['success'] ? 'Preview ready' : ($r['error'] ?? 'Validation failed'));
        break;

    case 'import-execute':
        $roleSvc->requirePermission('users.import');
        $data = $_SESSION['import_data'] ?? $input['rows'] ?? [];
        if (empty($data)) { $db->send_response(400, 'error', null, 'No import data — run preview first'); exit; }
        $opts = ['skip_errors' => $input['skip_errors'] ?? true, 'update_existing' => $input['update_existing'] ?? true];
        $r = $importSvc->executeImport($data, $opts);
        unset($_SESSION['import_data']);
        $db->send_response(200, 'success', $r, 'Import completed');
        break;

    // ─────────── STATS & ACTIVITY ───────────

    case 'stats':
        $roleSvc->requirePermission('users.view');
        $db->send_response(200, 'success', $userSvc->getUserStats(), 'Stats retrieved');
        break;

    case 'activity':
        // Users can view own activity; system.audit_log for all
        $targetUserId = (int)($_GET['target_user_id'] ?? 0);
        if ($targetUserId && $targetUserId !== $user_id) {
            $roleSvc->requirePermission('system.audit_log');
        }
        $params = array_filter(array_intersect_key($_GET, array_flip([
            'action','date_from','date_to','page','per_page'
        ])), fn($v) => $v !== '');
        if ($targetUserId) $params['user_id'] = $targetUserId;
        $db->send_response(200, 'success', $userSvc->getActivityLog($params), 'Activity log');
        break;

    // ─────────── ROLES CRUD ───────────

    case 'roles':
        $roleSvc->requirePermission('roles.view');
        $db->send_response(200, 'success', $roleSvc->listRoles(), 'Roles retrieved');
        break;

    case 'role-get':
        $roleSvc->requirePermission('roles.view');
        $rid = (int)($_GET['role_id'] ?? 0);
        $role = $roleSvc->getRole($rid);
        if (!$role) { $db->send_response(404, 'error', null, 'Role not found'); exit; }
        $db->send_response(200, 'success', $role, 'Role retrieved');
        break;

    case 'role-create':
        $roleSvc->requirePermission('roles.create');
        $r = $roleSvc->createRole($input['name'] ?? '', $input['key'] ?? '', $input['description'] ?? '');
        $db->send_response($r['success'] ? 201 : 400, $r['success'] ? 'success' : 'error', $r,
            $r['success'] ? 'Role created' : ($r['error'] ?? 'Failed'));
        break;

    case 'role-update':
        $roleSvc->requirePermission('roles.edit');
        $rid = (int)($input['role_id'] ?? 0);
        $r = $roleSvc->updateRole($rid, $input['name'] ?? '', $input['description'] ?? '');
        $db->send_response(200, $r['success'] ? 'success' : 'error', $r,
            $r['success'] ? 'Role updated' : ($r['error'] ?? 'Failed'));
        break;

    case 'role-delete':
        $roleSvc->requirePermission('roles.delete');
        $rid = (int)($input['role_id'] ?? 0);
        $r = $roleSvc->deleteRole($rid);
        $db->send_response(200, $r['success'] ? 'success' : 'error', $r,
            $r['success'] ? 'Role deleted' : ($r['error'] ?? 'Failed'));
        break;

    // ─────────── PERMISSIONS ───────────

    case 'permissions':
        $roleSvc->requirePermission('roles.view');
        $db->send_response(200, 'success', $roleSvc->listPermissions(), 'Permissions retrieved');
        break;

    case 'role-permissions':
        $roleSvc->requirePermission('roles.edit');
        $rid = (int)($input['role_id'] ?? 0);
        $pids = $input['permission_ids'] ?? [];
        $r = $roleSvc->setRolePermissions($rid, $pids);
        $db->send_response(200, $r['success'] ? 'success' : 'error', $r,
            $r['success'] ? 'Permissions updated' : ($r['error'] ?? 'Failed'));
        break;

    case 'assign-role':
        $roleSvc->requirePermission('roles.assign');
        $r = $roleSvc->assignRole((int)($input['target_user_id'] ?? 0), (int)($input['role_id'] ?? 0));
        $db->send_response(200, 'success', $r, 'Role assigned');
        break;

    case 'remove-role':
        $roleSvc->requirePermission('roles.assign');
        $r = $roleSvc->removeRole((int)($input['target_user_id'] ?? 0), (int)($input['role_id'] ?? 0));
        $db->send_response(200, 'success', $r, 'Role removed');
        break;

    // ─────────── AUTH CHECK ───────────

    case 'check-permission':
        $perm = $_GET['permission'] ?? '';
        $has = $roleSvc->hasPermission($user_id, $perm);
        $db->send_response(200, 'success', ['has_permission' => $has, 'permission' => $perm], '');
        break;

    case 'my-permissions':
        $perms = $roleSvc->getUserPermissions($user_id);
        $roles = $roleSvc->getUserRoles($user_id);
        $db->send_response(200, 'success', ['permissions' => $perms, 'roles' => $roles], '');
        break;

    // ─────────── MY ACCOUNT (self-service) ───────────

    case 'my-profile':
        $profile = $accountSvc->getMyProfile();
        if (!$profile) { $db->send_response(404, 'error', null, 'Profile not found'); exit; }
        $db->send_response(200, 'success', $profile, 'Profile retrieved');
        break;

    case 'update-profile':
        $r = $accountSvc->updateProfile($input);
        $db->send_response(200, $r['success'] ? 'success' : 'error', $r,
            $r['success'] ? 'Profile updated' : ($r['error'] ?? 'Failed'));
        break;

    case 'change-password':
        $r = $accountSvc->changePassword($input['current_password'] ?? '', $input['new_password'] ?? '');
        $db->send_response(200, $r['success'] ? 'success' : 'error', $r,
            $r['success'] ? 'Password changed' : ($r['error'] ?? 'Failed'));
        break;

    case 'upload-avatar':
        // Support both file upload and base64
        if (!empty($_FILES['avatar'])) {
            $r = $accountSvc->uploadAvatarFile($_FILES['avatar']);
        } elseif (!empty($input['avatar'])) {
            $r = $accountSvc->uploadAvatar($input['avatar']);
        } else {
            $r = ['success' => false, 'error' => 'No image provided'];
        }
        $db->send_response(200, $r['success'] ? 'success' : 'error', $r,
            $r['success'] ? 'Avatar uploaded' : ($r['error'] ?? 'Failed'));
        break;

    case 'remove-avatar':
        $r = $accountSvc->removeAvatar();
        $db->send_response(200, 'success', $r, 'Avatar removed');
        break;

    case 'update-consent':
        $r = $accountSvc->updateConsent($input);
        $db->send_response(200, 'success', $r, 'Consent updated');
        break;

    case 'login-history':
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $db->send_response(200, 'success', $accountSvc->getLoginHistory($limit), 'Login history');
        break;

    default:
        $db->send_response(400, 'error', null,
            'Invalid action. Available: list, get, create, update, delete, activate, suspend, unlock, ' .
            'reset-password, bulk-action, import-template, import-preview, import-execute, stats, activity, ' .
            'roles, role-get, role-create, role-update, role-delete, permissions, role-permissions, ' .
            'assign-role, remove-role, check-permission, my-permissions');

}