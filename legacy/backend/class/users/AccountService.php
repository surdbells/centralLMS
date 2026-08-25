<?php
/**
 * AccountService — Self-service account management.
 * For the logged-in user only (no admin elevation needed).
 *
 * Handles: profile updates, password change, avatar upload, notification prefs, session info.
 */
class AccountService
{
    private mysqli $conn;
    private int $tenant_id;
    private int $user_id;
    private RoleService $roles;

    /** Max avatar dimensions and file size */
    private const AVATAR_MAX_WIDTH  = 400;
    private const AVATAR_MAX_HEIGHT = 400;
    private const AVATAR_MAX_BYTES  = 2 * 1024 * 1024; // 2MB
    private const AVATAR_QUALITY    = 85;

    public function __construct(mysqli $conn, RoleService $roleService)
    {
        $this->conn  = $conn;
        $this->roles = $roleService;
    }

    public function setContext(int $tenant_id, int $user_id): self
    {
        $this->tenant_id = $tenant_id;
        $this->user_id   = $user_id;
        $this->roles->setContext($tenant_id, $user_id);
        return $this;
    }

    // ═══════════════════════════════════════════
    //  GET MY PROFILE
    // ═══════════════════════════════════════════

    public function getMyProfile(): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.staffId,
                    u.department, u.team, u.branch, u.job_title, u.manager_id,
                    u.avatar, u.is_active, u.account_locked, u.suspended,
                    u.language, u.preferred_learning_mode, u.hire_date,
                    u.last_login_at, u.login_count, u.created, u.probation_end_date,
                    u.privacy_consent, u.marketing_consent, u.data_processing_consent,
                    d.department_name, t.team_name, b.branch_name,
                    CONCAT(m.first_name, ' ', m.last_name) as manager_name
             FROM users u
             LEFT JOIN departments d ON u.department = d.department_id
             LEFT JOIN teams t ON u.team = t.team_id
             LEFT JOIN branches b ON u.branch = b.branch_id
             LEFT JOIN users m ON u.manager_id = m.id
             WHERE u.id = ? AND u.tenant_id = ? AND u.deleted_at IS NULL"
        );
        $stmt->bind_param('ii', $this->user_id, $this->tenant_id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        if (!$user) return null;

        $user['full_name']   = trim($user['first_name'] . ' ' . ($user['last_name'] ?? ''));
        $user['roles']       = $this->roles->getUserRoles($this->user_id);
        $user['permissions'] = $this->roles->getUserPermissions($this->user_id);
        $user['has_avatar']  = !empty($user['avatar']);

        // Don't send full base64 avatar in profile response — use separate endpoint
        $user['avatar_preview'] = $user['avatar']
            ? 'data:image/jpeg;base64,' . substr($user['avatar'], 0, 200) . '...'
            : null;
        // Actually, let's send it since the frontend needs it for display
        $user['avatar_url'] = $user['avatar'] ? 'data:image/jpeg;base64,' . $user['avatar'] : null;
        unset($user['avatar']); // Don't send raw longtext

        unset($user['password'], $user['token'], $user['is_2fa']);

        return $user;
    }

    // ═══════════════════════════════════════════
    //  UPDATE PROFILE
    // ═══════════════════════════════════════════

    public function updateProfile(array $data): array
    {
        $allowed = ['first_name', 'last_name', 'phone', 'language', 'preferred_learning_mode'];
        $sets  = [];
        $types = '';
        $binds = [];
        $changes = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "$field = ?";
                $types .= 's';
                $binds[] = $data[$field];
                $changes[$field] = $data[$field];
            }
        }

        if (empty($sets)) return ['success' => true, 'message' => 'No changes'];

        $sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE id = ? AND tenant_id = ?";
        $types .= 'ii';
        $binds[] = $this->user_id;
        $binds[] = $this->tenant_id;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();

        $this->roles->logActivity($this->user_id, 'profile_updated', $changes);

        return ['success' => true, 'changes' => $changes];
    }

    // ═══════════════════════════════════════════
    //  CHANGE PASSWORD
    // ═══════════════════════════════════════════

    public function changePassword(string $currentPassword, string $newPassword): array
    {
        if (strlen($newPassword) < 8) {
            return ['success' => false, 'error' => 'New password must be at least 8 characters'];
        }

        // Get current hash
        $stmt = $this->conn->prepare("SELECT password FROM users WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $this->user_id, $this->tenant_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) return ['success' => false, 'error' => 'User not found'];

        // Verify current password
        if (!password_verify($currentPassword, $row['password'])) {
            return ['success' => false, 'error' => 'Current password is incorrect'];
        }

        // Don't allow reusing same password
        if (password_verify($newPassword, $row['password'])) {
            return ['success' => false, 'error' => 'New password must be different from current password'];
        }

        // Update
        $hashed = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $this->conn->prepare("UPDATE users SET password = ? WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('sii', $hashed, $this->user_id, $this->tenant_id);
        $stmt->execute();

        $this->roles->logActivity($this->user_id, 'password_changed', []);

        return ['success' => true];
    }

    // ═══════════════════════════════════════════
    //  AVATAR UPLOAD
    // ═══════════════════════════════════════════

    /**
     * Upload and process avatar image.
     * Accepts base64 data URI or $_FILES upload.
     * Resizes to max 400x400, converts to JPEG, stores as base64 in users.avatar (longtext).
     */
    public function uploadAvatar(string $imageData): array
    {
        // Handle base64 data URI
        if (str_starts_with($imageData, 'data:image/')) {
            $parts = explode(',', $imageData, 2);
            if (count($parts) !== 2) return ['success' => false, 'error' => 'Invalid image data'];
            $raw = base64_decode($parts[1]);
        } else {
            // Raw base64
            $raw = base64_decode($imageData);
        }

        if (!$raw || strlen($raw) > self::AVATAR_MAX_BYTES) {
            return ['success' => false, 'error' => 'Image too large (max 2MB)'];
        }

        // Validate it's actually an image
        $img = @imagecreatefromstring($raw);
        if (!$img) return ['success' => false, 'error' => 'Invalid image format'];

        $w = imagesx($img);
        $h = imagesy($img);

        // Resize if needed (maintain aspect ratio, fit within 400x400)
        if ($w > self::AVATAR_MAX_WIDTH || $h > self::AVATAR_MAX_HEIGHT) {
            $ratio = min(self::AVATAR_MAX_WIDTH / $w, self::AVATAR_MAX_HEIGHT / $h);
            $newW  = (int)round($w * $ratio);
            $newH  = (int)round($h * $ratio);
            $resized = imagecreatetruecolor($newW, $newH);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $w, $h);
            imagedestroy($img);
            $img = $resized;
        }

        // Convert to JPEG base64
        ob_start();
        imagejpeg($img, null, self::AVATAR_QUALITY);
        $jpegData = ob_get_clean();
        imagedestroy($img);

        $base64 = base64_encode($jpegData);

        // Store in database
        $stmt = $this->conn->prepare("UPDATE users SET avatar = ? WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('sii', $base64, $this->user_id, $this->tenant_id);
        $stmt->execute();

        $this->roles->logActivity($this->user_id, 'avatar_updated', []);

        return [
            'success'    => true,
            'avatar_url' => 'data:image/jpeg;base64,' . $base64
        ];
    }

    /** Upload from $_FILES */
    public function uploadAvatarFile(array $file): array
    {
        if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => 'No file uploaded'];
        }
        if ($file['size'] > self::AVATAR_MAX_BYTES) {
            return ['success' => false, 'error' => 'File too large (max 2MB)'];
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowedTypes, true)) {
            return ['success' => false, 'error' => 'Invalid file type. Allowed: JPEG, PNG, GIF, WebP'];
        }

        $raw = file_get_contents($file['tmp_name']);
        return $this->uploadAvatar('data:' . $mime . ';base64,' . base64_encode($raw));
    }

    /** Remove avatar */
    public function removeAvatar(): array
    {
        $stmt = $this->conn->prepare("UPDATE users SET avatar = NULL WHERE id = ? AND tenant_id = ?");
        $stmt->bind_param('ii', $this->user_id, $this->tenant_id);
        $stmt->execute();

        $this->roles->logActivity($this->user_id, 'avatar_removed', []);
        return ['success' => true];
    }

    // ═══════════════════════════════════════════
    //  CONSENT / PREFERENCES
    // ═══════════════════════════════════════════

    public function updateConsent(array $data): array
    {
        $fields = ['privacy_consent', 'marketing_consent', 'data_processing_consent'];
        $sets  = [];
        $types = '';
        $binds = [];

        foreach ($fields as $f) {
            if (isset($data[$f])) {
                $sets[] = "$f = ?";
                $types .= 'i';
                $binds[] = (int)$data[$f];
            }
        }

        if (empty($sets)) return ['success' => true];

        $sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE id = ? AND tenant_id = ?";
        $types .= 'ii';
        $binds[] = $this->user_id;
        $binds[] = $this->tenant_id;

        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();

        $this->roles->logActivity($this->user_id, 'consent_updated', $data);
        return ['success' => true];
    }

    // ═══════════════════════════════════════════
    //  ACTIVE SESSIONS / SECURITY
    // ═══════════════════════════════════════════

    public function getLoginHistory(int $limit = 20): array
    {
        $stmt = $this->conn->prepare(
            "SELECT log_id, action, ip_address, user_agent, created_at
             FROM user_activity_log
             WHERE tenant_id = ? AND user_id = ? AND action IN ('login', 'logout', 'password_changed', 'avatar_updated')
             ORDER BY created_at DESC LIMIT ?"
        );
        $stmt->bind_param('iii', $this->tenant_id, $this->user_id, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
}
