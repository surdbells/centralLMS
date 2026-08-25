<?php
/**
 * TenantProvisioningService
 * Self-service tenant creation and provisioning.
 *
 * Handles:
 *  - Onboarding code validation
 *  - Plan listing
 *  - Content package catalog
 *  - Tenant + admin user creation
 *  - Default setup (departments, roles, categories, branding)
 *  - Content package installation
 *  - Subdomain availability check
 */
class TenantProvisioningService
{
    private mysqli $conn;

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

    // ═══════════════════════════════════════════
    //  ONBOARDING CODE VALIDATION
    // ═══════════════════════════════════════════

    public function validateOnboardingCode(string $code): array
    {
        $code = trim($code);
        if (empty($code)) {
            return ['valid' => false, 'error' => 'Onboarding code is required.'];
        }

        $stmt = $this->conn->prepare(
            "SELECT code_id, code, description, max_uses, used_count, is_active,
                    valid_from, valid_until, allowed_plan_ids
             FROM onboarding_codes WHERE code = ?"
        );
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            return ['valid' => false, 'error' => 'Invalid onboarding code.'];
        }
        if (!$row['is_active']) {
            return ['valid' => false, 'error' => 'This onboarding code has been deactivated.'];
        }
        if ($row['used_count'] >= $row['max_uses']) {
            return ['valid' => false, 'error' => 'This onboarding code has reached its usage limit.'];
        }
        if ($row['valid_from'] && strtotime($row['valid_from']) > time()) {
            return ['valid' => false, 'error' => 'This onboarding code is not yet active.'];
        }
        if ($row['valid_until'] && strtotime($row['valid_until']) < time()) {
            return ['valid' => false, 'error' => 'This onboarding code has expired.'];
        }

        return [
            'valid' => true,
            'code_id' => (int)$row['code_id'],
            'description' => $row['description'],
            'allowed_plan_ids' => $row['allowed_plan_ids']
                ? array_map('intval', explode(',', $row['allowed_plan_ids']))
                : null // null = all plans allowed
        ];
    }

    // ═══════════════════════════════════════════
    //  PLANS & CONTENT PACKAGES (Public)
    // ═══════════════════════════════════════════

    public function getPlans(): array
    {
        $result = $this->conn->query(
            "SELECT plan_id, name, users_limit, storage_limit_mb, price, billing_cycle
             FROM plans ORDER BY price ASC"
        );
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function getContentPackages(): array
    {
        $result = $this->conn->query(
            "SELECT package_id, slug, name, description, icon, color,
                    courses_count, assessments_count, certificates_count,
                    price, tags, is_popular
             FROM content_packages WHERE is_active = 1 ORDER BY sort_order ASC"
        );
        $packages = $result->fetch_all(MYSQLI_ASSOC);
        foreach ($packages as &$pkg) {
            $pkg['tags'] = $pkg['tags'] ? explode(',', $pkg['tags']) : [];
            $pkg['is_popular'] = (bool)$pkg['is_popular'];
        }
        return $packages;
    }

    // ═══════════════════════════════════════════
    //  SUBDOMAIN CHECK
    // ═══════════════════════════════════════════

    public function checkSubdomain(string $subdomain): array
    {
        $subdomain = $this->sanitizeSubdomain($subdomain);
        if (strlen($subdomain) < 3) {
            return ['available' => false, 'error' => 'Subdomain must be at least 3 characters.'];
        }

        // Reserved subdomains
        $reserved = ['www', 'api', 'admin', 'app', 'mail', 'ftp', 'test', 'staging',
            'dev', 'demo', 'help', 'support', 'docs', 'status', 'blog', 'cdn'];
        if (in_array($subdomain, $reserved)) {
            return ['available' => false, 'error' => 'This subdomain is reserved.', 'subdomain' => $subdomain];
        }

        $stmt = $this->conn->prepare(
            "SELECT tenant_id FROM tenants WHERE domain = ? OR tenant_code = ?"
        );
        $stmt->bind_param('ss', $subdomain, $subdomain);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();

        return [
            'available' => !$exists,
            'subdomain' => $subdomain,
            'error' => $exists ? 'This subdomain is already taken.' : null
        ];
    }

    // ═══════════════════════════════════════════
    //  FULL PROVISIONING
    // ═══════════════════════════════════════════

    /**
     * Provision a new tenant end-to-end.
     *
     * @param array $data {
     *   onboarding_code, company_name, admin_email, admin_first_name, admin_last_name,
     *   password, company_size, industry, phone, plan_id, content_packages[]
     * }
     * @return array {success, tenant_id, subdomain, admin_user_id, error?}
     */
    public function provision(array $data): array
    {
        // ── Validate required fields ──
        $required = ['onboarding_code', 'company_name', 'admin_email', 'admin_first_name', 'admin_last_name', 'password', 'plan_id'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return ['success' => false, 'error' => ucfirst(str_replace('_', ' ', $field)) . ' is required.'];
            }
        }

        // ── Validate onboarding code ──
        $codeCheck = $this->validateOnboardingCode($data['onboarding_code']);
        if (!$codeCheck['valid']) {
            return ['success' => false, 'error' => $codeCheck['error']];
        }
        $codeId = $codeCheck['code_id'];

        // Check if plan is allowed by code
        $planId = (int)$data['plan_id'];
        if ($codeCheck['allowed_plan_ids'] && !in_array($planId, $codeCheck['allowed_plan_ids'])) {
            return ['success' => false, 'error' => 'This onboarding code does not permit the selected plan.'];
        }

        // ── Validate email format ──
        if (!filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email format.'];
        }

        // ── Check email uniqueness (global) ──
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param('s', $data['admin_email']);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            return ['success' => false, 'error' => 'An account with this email already exists.'];
        }

        // ── Validate password ──
        if (strlen($data['password']) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters.'];
        }

        // ── Validate plan ──
        $stmt = $this->conn->prepare("SELECT plan_id, users_limit, storage_limit_mb FROM plans WHERE plan_id = ?");
        $stmt->bind_param('i', $planId);
        $stmt->execute();
        $plan = $stmt->get_result()->fetch_assoc();
        if (!$plan) {
            return ['success' => false, 'error' => 'Invalid plan selected.'];
        }

        // ── Generate subdomain ──
        $subdomain = $this->sanitizeSubdomain($data['company_name']);
        $subCheck = $this->checkSubdomain($subdomain);
        if (!$subCheck['available']) {
            // Append random suffix
            $subdomain .= '-' . random_int(100, 999);
            $subCheck = $this->checkSubdomain($subdomain);
            if (!$subCheck['available']) {
                $subdomain .= '-' . random_int(10, 99);
            }
        }

        // ── Begin transaction ──
        $this->conn->begin_transaction();
        try {
            // STEP 1: Create tenant record
            $this->logStep(0, 'create_tenant', 'started');
            $apiKey = bin2hex(random_bytes(32));
            $tenantCode = strtoupper(substr($subdomain, 0, 10)) . random_int(100, 999);

            $stmt = $this->conn->prepare(
                "INSERT INTO tenants (name, status, api_key, users_limit, storage_limit_mb, plan_id,
                                      contact_email, contact_phone, domain, tenant_code,
                                      company_size, industry, onboarding_code, is_active)
                 VALUES (?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)"
            );
            $companyName = trim($data['company_name']);
            $usersLimit = (int)$plan['users_limit'];
            $storageLimit = (int)$plan['storage_limit_mb'];
            $email = $data['admin_email'];
            $phone = $data['phone'] ?? null;
            $companySize = $data['company_size'] ?? null;
            $industry = $data['industry'] ?? null;
            $onboardingCode = $data['onboarding_code'];

            $stmt->bind_param('ssiiisissssss',
                $companyName, $apiKey, $usersLimit, $storageLimit, $planId,
                $email, $phone, $subdomain, $tenantCode,
                $companySize, $industry, $onboardingCode
            );

            if (!$stmt->execute()) {
                throw new Exception('Failed to create tenant: ' . $this->conn->error);
            }
            $tenantId = $this->conn->insert_id;
            $this->logStep($tenantId, 'create_tenant', 'completed', "tenant_id=$tenantId, subdomain=$subdomain");

            // STEP 2: Create admin user
            $this->logStep($tenantId, 'create_admin', 'started');
            $token = bin2hex(random_bytes(32));
            $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);

            $stmt = $this->conn->prepare(
                "INSERT INTO users (tenant_id, token, first_name, last_name, email, password, phone,
                                    is_active, is_admin, is_learner, language)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1, 1, 'en')"
            );
            $firstName = trim($data['admin_first_name']);
            $lastName = trim($data['admin_last_name']);
            $stmt->bind_param('issssss',
                $tenantId, $token, $firstName, $lastName, $email, $hashedPassword, $phone
            );

            if (!$stmt->execute()) {
                throw new Exception('Failed to create admin user: ' . $this->conn->error);
            }
            $adminUserId = $this->conn->insert_id;
            $this->logStep($tenantId, 'create_admin', 'completed', "user_id=$adminUserId");

            // Update tenant with admin_user_id
            $stmt = $this->conn->prepare("UPDATE tenants SET admin_user_id = ? WHERE tenant_id = ?");
            $stmt->bind_param('ii', $adminUserId, $tenantId);
            $stmt->execute();

            // STEP 3: Create default departments
            $this->logStep($tenantId, 'default_departments', 'started');
            $depts = ['General', 'Human Resources', 'IT / Technology', 'Operations', 'Finance'];
            $deptStmt = $this->conn->prepare(
                "INSERT INTO departments (tenant_id, department_name, is_active) VALUES (?, ?, 1)"
            );
            foreach ($depts as $dept) {
                $deptStmt->bind_param('is', $tenantId, $dept);
                $deptStmt->execute();
            }
            $this->logStep($tenantId, 'default_departments', 'completed', count($depts) . ' departments');

            // STEP 4: Create default roles (if roles table exists and RoleService pattern is used)
            $this->logStep($tenantId, 'default_roles', 'started');
            $this->seedDefaultRoles($tenantId, $adminUserId);
            $this->logStep($tenantId, 'default_roles', 'completed');

            // STEP 5: Create default course categories
            $this->logStep($tenantId, 'default_categories', 'started');
            $cats = ['General Training', 'Compliance Training', 'Onboarding', 'Leadership & Management',
                'Technical Skills', 'Soft Skills', 'Health & Safety', 'Product Knowledge'];
            $catStmt = $this->conn->prepare(
                "INSERT INTO categories (tenant_id, name, is_active) VALUES (?, ?, 1)"
            );
            foreach ($cats as $cat) {
                $catStmt->bind_param('is', $tenantId, $cat);
                $catStmt->execute();
            }
            $this->logStep($tenantId, 'default_categories', 'completed', count($cats) . ' categories');

            // STEP 6: Create default branch
            $this->logStep($tenantId, 'default_branch', 'started');
            $branchName = 'Headquarters';
            $branchStmt = $this->conn->prepare(
                "INSERT INTO branches (tenant_id, branch_name, is_active) VALUES (?, ?, 1)"
            );
            $branchStmt->bind_param('is', $tenantId, $branchName);
            $branchStmt->execute();
            $this->logStep($tenantId, 'default_branch', 'completed');

            // STEP 7: Install content packages
            $packageIds = $data['content_packages'] ?? [];
            if (!empty($packageIds)) {
                $this->logStep($tenantId, 'install_packages', 'started', json_encode($packageIds));
                foreach ($packageIds as $pkgSlug) {
                    $this->installContentPackage($tenantId, $pkgSlug, $adminUserId);
                }
                $this->logStep($tenantId, 'install_packages', 'completed', count($packageIds) . ' packages');
            }

            // STEP 8: Set provisioned timestamp
            $stmt = $this->conn->prepare("UPDATE tenants SET provisioned_at = NOW() WHERE tenant_id = ?");
            $stmt->bind_param('i', $tenantId);
            $stmt->execute();

            // STEP 9: Increment onboarding code usage
            $stmt = $this->conn->prepare("UPDATE onboarding_codes SET used_count = used_count + 1 WHERE code_id = ?");
            $stmt->bind_param('i', $codeId);
            $stmt->execute();

            $this->conn->commit();

            $this->logStep($tenantId, 'provisioning_complete', 'completed');

            return [
                'success' => true,
                'tenant_id' => $tenantId,
                'subdomain' => $subdomain,
                'domain' => $subdomain . '.lmsplatform.com',
                'admin_user_id' => $adminUserId,
                'admin_email' => $email,
                'tenant_code' => $tenantCode,
                'plan' => $plan,
            ];

        } catch (Exception $e) {
            $this->conn->rollback();
            $this->logStep($tenantId ?? 0, 'provisioning_failed', 'failed', $e->getMessage());
            return ['success' => false, 'error' => 'Provisioning failed: ' . $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════
    //  DEFAULT ROLES SEEDER
    // ═══════════════════════════════════════════

    private function seedDefaultRoles(int $tenantId, int $adminUserId): void
    {
        // Check if roles table exists
        $check = $this->conn->query("SHOW TABLES LIKE 'roles'");
        if ($check->num_rows === 0) return;

        $roles = [
            ['superadmin', 'Super Admin',  'Full system access',      1],
            ['admin',      'Administrator','Administrative access',   1],
            ['hr',         'HR Manager',   'HR and people management',1],
            ['instructor', 'Instructor',   'Course creation & delivery',1],
            ['content',    'Content Manager','Content management',    1],
            ['manager',    'Manager',      'Team management & reports',1],
            ['learner',    'Learner',      'Learning access',         1],
        ];

        $stmt = $this->conn->prepare(
            "INSERT IGNORE INTO roles (tenant_id, role_key, role_name, description, is_system) VALUES (?, ?, ?, ?, ?)"
        );
        $superadminRoleId = null;
        foreach ($roles as $r) {
            $stmt->bind_param('isssi', $tenantId, $r[0], $r[1], $r[2], $r[3]);
            $stmt->execute();
            if ($r[0] === 'superadmin') $superadminRoleId = $this->conn->insert_id;
        }

        // Assign superadmin role to admin user
        if ($superadminRoleId) {
            $check2 = $this->conn->query("SHOW TABLES LIKE 'user_roles'");
            if ($check2->num_rows > 0) {
                $stmt = $this->conn->prepare(
                    "INSERT IGNORE INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)"
                );
                $stmt->bind_param('iii', $adminUserId, $superadminRoleId, $adminUserId);
                $stmt->execute();
            }
        }
    }

    // ═══════════════════════════════════════════
    //  CONTENT PACKAGE INSTALLATION
    // ═══════════════════════════════════════════

    private function installContentPackage(int $tenantId, string $pkgSlug, int $userId): void
    {
        // Find package
        $stmt = $this->conn->prepare("SELECT package_id FROM content_packages WHERE slug = ? AND is_active = 1");
        $stmt->bind_param('s', $pkgSlug);
        $stmt->execute();
        $pkg = $stmt->get_result()->fetch_assoc();
        if (!$pkg) return;

        $pkgId = (int)$pkg['package_id'];

        // Record in junction table
        $stmt = $this->conn->prepare(
            "INSERT IGNORE INTO tenant_content_packages (tenant_id, package_id, installed_by, status)
             VALUES (?, ?, ?, 'installed')"
        );
        $stmt->bind_param('iii', $tenantId, $pkgId, $userId);
        $stmt->execute();

        // TODO: In production, this would:
        // 1. Copy SCORM packages from master storage to tenant storage
        // 2. Create course records in tenant's courses table
        // 3. Create assessment records
        // 4. Create certificate templates
        // 5. Create learning path records
        // For now, we just record the installation.
    }

    // ═══════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════

    private function sanitizeSubdomain(string $name): string
    {
        $sub = strtolower(trim($name));
        $sub = preg_replace('/[^a-z0-9]+/', '-', $sub);
        $sub = trim($sub, '-');
        return substr($sub, 0, 30);
    }

    private function logStep(int $tenantId, string $step, string $status, ?string $details = null): void
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO tenant_provisioning_log (tenant_id, step, status, details) VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param('isss', $tenantId, $step, $status, $details);
        $stmt->execute();
    }
}