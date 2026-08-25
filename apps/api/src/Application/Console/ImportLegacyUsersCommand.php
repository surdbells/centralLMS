<?php

declare(strict_types=1);

namespace Central\Api\Application\Console;

use Central\Api\Domain\Authz\SystemRole;
use Central\Api\Infrastructure\Security\PasswordHasher;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Imports tenants, roles and users from the legacy MySQL database.
 *
 * Read-only against MySQL, idempotent against PostgreSQL: every row carries
 * its `legacy_id`, so a re-run updates rather than duplicates. Safe to run as
 * many times as needed while reconciling.
 *
 *   php -d extension=pdo_mysql bin/console legacy:import-users \
 *       --mysql-host=… --mysql-db=central_lxp --mysql-user=… --mysql-password=… \
 *       --dry-run
 *
 * ---------------------------------------------------------------------------
 * THE ROLE RECONCILIATION
 * ---------------------------------------------------------------------------
 * The legacy `users` table records a user's role four ways, all in live use:
 * the `user_roles` join table, five boolean flags (`is_admin`, `is_learner`,
 * `is_content`, `is_hr`, `is_instructor`), a `role_id` column and a `role`
 * column.
 *
 * The rule applied here, agreed with the team:
 *
 *   1. If the user has `user_roles` rows, those win — it is the newest
 *      mechanism and the only one that can express a custom role.
 *   2. Otherwise the boolean flags are used.
 *   3. Every disagreement between the two is reported, whichever won.
 *
 * Run with `--dry-run` first and read the reconciliation table. Nothing is
 * written in that mode.
 */
#[AsCommand(
    name: 'legacy:import-users',
    description: 'Import tenants, roles and users from the legacy MySQL database',
)]
final class ImportLegacyUsersCommand extends Command
{
    /**
     * Legacy `roles.role_key` to the platform role it becomes.
     *
     * Note `superadmin`: the legacy seed creates it **per tenant**, so it is
     * the tenant's top role — this system's `tenant_admin` — not a
     * cross-tenant super admin. Mapping it to `super_admin` would hand every
     * tenant's administrator access to every other tenant.
     */
    private const ROLE_MAP = [
        'superadmin'      => SystemRole::TenantAdmin,
        'admin'           => SystemRole::OrgAdmin,
        'instructor'      => SystemRole::Instructor,
        'learner'         => SystemRole::Learner,
        'content_creator' => SystemRole::ContentCreator,
        'hr'              => SystemRole::Hr,
        'hr_manager'      => SystemRole::HrManager,
        'manager'         => SystemRole::Manager,
    ];

    /** Boolean flag column to the role it implies, used only as a fallback. */
    private const FLAG_MAP = [
        'is_admin'      => SystemRole::OrgAdmin,
        'is_instructor' => SystemRole::Instructor,
        'is_content'    => SystemRole::ContentCreator,
        'is_hr'         => SystemRole::Hr,
        'is_learner'    => SystemRole::Learner,
    ];

    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('mysql-host', null, InputOption::VALUE_REQUIRED, 'Legacy MySQL host', '127.0.0.1')
            ->addOption('mysql-port', null, InputOption::VALUE_REQUIRED, 'Legacy MySQL port', '3306')
            ->addOption('mysql-db', null, InputOption::VALUE_REQUIRED, 'Legacy database name', 'central_lxp')
            ->addOption('mysql-user', null, InputOption::VALUE_REQUIRED, 'Legacy MySQL user')
            ->addOption('mysql-password', null, InputOption::VALUE_REQUIRED, 'Legacy MySQL password', '')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Import only this legacy tenant_id')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change; write nothing')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after this many users', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if (!extension_loaded('pdo_mysql')) {
            $io->error([
                'The pdo_mysql extension is not loaded.',
                'Run with: php -d extension=pdo_mysql bin/console legacy:import-users …',
            ]);

            return Command::FAILURE;
        }

        try {
            $legacy = $this->connectLegacy($input);
        } catch (\Throwable $e) {
            $io->error('Could not connect to the legacy database: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $target = $this->container->get(Connection::class);
        $hasher = $this->container->get(PasswordHasher::class);

        $io->title($dryRun ? 'Legacy import — DRY RUN (nothing will be written)' : 'Legacy import');

        $onlyTenant = $input->getOption('tenant');
        $limit = (int) $input->getOption('limit');

        $stats = [
            'tenants' => 0, 'roles' => 0, 'users' => 0, 'assignments' => 0,
            'skipped' => 0, 'rehash' => 0,
        ];
        $issues = [];
        $reconciliation = [];
        $escalations = 0;

        // --- tenants --------------------------------------------------------
        $tenantMap = [];

        foreach ($this->fetchTenants($legacy, $onlyTenant) as $row) {
            $tenantMap[(int) $row['tenant_id']] = $this->upsertTenant($target, $row, $dryRun);
            ++$stats['tenants'];
        }

        if ($tenantMap === []) {
            $io->warning('No tenants matched. Nothing to import.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('  tenants: %d', $stats['tenants']));

        // --- roles ----------------------------------------------------------
        // Legacy roles are seeded per tenant but are identical everywhere, and
        // all eight map onto platform roles. So the import resolves each
        // legacy role_id to a system role rather than creating 160 rows.
        $roleMap = [];

        foreach ($this->fetchRoles($legacy, array_keys($tenantMap)) as $row) {
            $key = strtolower(trim((string) $row['role_key']));
            $systemRole = self::ROLE_MAP[$key] ?? null;

            if ($systemRole === null) {
                // A tenant-defined role. Created as a custom role, with no
                // permissions until someone assigns them — guessing what a
                // role called "auditor" may do would be inventing access.
                $issues[] = sprintf(
                    'Unmapped legacy role "%s" (id %d, tenant %d) — imported with no permissions.',
                    $key,
                    $row['role_id'],
                    $row['tenant_id'],
                );
                continue;
            }

            $roleMap[(int) $row['role_id']] = $systemRole;
            ++$stats['roles'];
        }

        $io->writeln(sprintf('  roles mapped: %d', $stats['roles']));

        // --- users ----------------------------------------------------------
        $systemRoleIds = $this->systemRoleIds($target);

        foreach ($this->fetchUsers($legacy, array_keys($tenantMap), $limit) as $row) {
            $legacyUserId = (int) $row['id'];
            $tenantId = $tenantMap[(int) $row['tenant_id']] ?? null;

            if ($tenantId === null) {
                ++$stats['skipped'];
                $issues[] = sprintf('User %d references tenant %d, which was not imported.', $legacyUserId, $row['tenant_id']);
                continue;
            }

            $email = strtolower(trim((string) $row['email']));

            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                ++$stats['skipped'];
                $issues[] = sprintf('User %d has no usable email ("%s") — skipped.', $legacyUserId, $row['email']);
                continue;
            }

            $hash = (string) ($row['password'] ?? '');

            if ($hash !== '' && !$hasher->isSupported($hash)) {
                // md5/sha1 or similar. Imported without a password so the
                // account cannot be signed into until it is reset — silently
                // keeping an unusable hash would look like a broken login.
                $issues[] = sprintf('User %d has a non-bcrypt password hash — imported without one; requires a reset.', $legacyUserId);
                $hash = '';
            } elseif ($hash !== '' && $hasher->needsRehash($hash)) {
                // Expected: the legacy hashes are cost 10 and this system uses
                // 12. They are upgraded transparently on next sign-in.
                ++$stats['rehash'];
            }

            $userId = $this->upsertUser($target, $tenantId, $row, $hash, $dryRun);

            if ($userId === null) {
                ++$stats['skipped'];
                continue;
            }

            ++$stats['users'];

            // --- role reconciliation ---------------------------------------
            $fromJoin = $this->rolesFromJoinTable($legacy, $legacyUserId, $roleMap);
            $fromFlags = $this->rolesFromFlags($row);

            $winner = $fromJoin !== [] ? $fromJoin : $fromFlags;
            $source = $fromJoin !== [] ? 'user_roles' : 'flags';

            if ($this->disagree($fromJoin, $fromFlags) && $fromJoin !== []) {
                // Whether applying user_roles grants MORE than the flags did.
                // This is the case that needs a human to confirm: the import
                // would be handing someone access they did not previously
                // exercise through the flag mechanism.
                $gained = $this->permissionCount($fromJoin) - $this->permissionCount($fromFlags);
                $effect = $gained > 0
                    ? sprintf('ESCALATES (+%d perms)', $gained)
                    : ($gained < 0 ? sprintf('reduces (%d perms)', $gained) : 'same access');

                if ($gained > 0) {
                    ++$escalations;
                }

                $reconciliation[] = [
                    $legacyUserId,
                    $email,
                    implode(', ', array_map(static fn (SystemRole $r): string => $r->value, $fromJoin)) ?: '—',
                    implode(', ', array_map(static fn (SystemRole $r): string => $r->value, $fromFlags)) ?: '—',
                    $effect,
                ];
            }

            if ($winner === []) {
                // Never leave an account with no role: it would authenticate
                // and then be able to do nothing, which reads as a broken
                // login rather than a permissions problem.
                $winner = [SystemRole::Learner];
                $issues[] = sprintf('User %d had no roles by either mechanism — defaulted to learner.', $legacyUserId);
            }

            if (!$dryRun) {
                $dropped = $this->assignRoles($target, $userId, $winner, $systemRoleIds);

                foreach ($dropped as $missing) {
                    $issues[] = sprintf(
                        'User %d: role "%s" has no row in the roles table — assignment dropped.',
                        $legacyUserId,
                        $missing,
                    );
                }
            }

            $stats['assignments'] += count($winner);
            unset($source);
        }

        // --- report ---------------------------------------------------------
        $io->newLine();
        $io->section('Imported');
        $io->definitionList(
            ['Tenants' => $stats['tenants']],
            ['Roles mapped' => $stats['roles']],
            ['Users' => $stats['users']],
            ['Role assignments' => $stats['assignments']],
            ['Skipped' => $stats['skipped']],
            ['Will rehash on next sign-in' => $stats['rehash']],
        );

        if ($reconciliation !== []) {
            $io->section(sprintf('Role disagreements (%d)', count($reconciliation)));
            $io->writeln('Where user_roles and the boolean flags disagree. user_roles won.');
            $io->newLine();
            $io->table(['Legacy id', 'Email', 'From user_roles', 'From flags', 'Effect'], $reconciliation);

            if ($escalations > 0) {
                $io->warning(sprintf(
                    '%d user(s) GAIN access under this rule. Confirm each one before running without --dry-run.',
                    $escalations,
                ));
            }
        } else {
            $io->success('No disagreements between user_roles and the boolean flags.');
        }

        if ($issues !== []) {
            $io->section(sprintf('Issues (%d)', count($issues)));

            foreach (array_slice($issues, 0, 40) as $issue) {
                $io->writeln('  • ' . $issue);
            }

            if (count($issues) > 40) {
                $io->writeln(sprintf('  … and %d more', count($issues) - 40));
            }
        }

        if ($dryRun) {
            $io->newLine();
            $io->note('Dry run — nothing was written. Re-run without --dry-run to apply.');
        }

        return Command::SUCCESS;
    }

    // -----------------------------------------------------------------------

    private function connectLegacy(InputInterface $input): Connection
    {
        $connection = DriverManager::getConnection([
            'driver'   => 'pdo_mysql',
            'host'     => (string) $input->getOption('mysql-host'),
            'port'     => (int) $input->getOption('mysql-port'),
            'dbname'   => (string) $input->getOption('mysql-db'),
            'user'     => (string) $input->getOption('mysql-user'),
            'password' => (string) $input->getOption('mysql-password'),
            'charset'  => 'utf8mb4',
        ], new \Doctrine\DBAL\Configuration());

        $connection->executeQuery('SELECT 1');

        return $connection;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchTenants(Connection $legacy, mixed $onlyTenant): array
    {
        $sql = 'SELECT tenant_id, name, tenant_code, domain, contact_email, contact_phone,
                       users_limit, storage_limit_mb, subscription_expires_at, logo_url,
                       industry, company_size, notes, is_active, is_deleted
                FROM tenants
                WHERE (is_deleted IS NULL OR is_deleted = 0)';

        $params = [];

        if ($onlyTenant !== null && $onlyTenant !== '') {
            $sql .= ' AND tenant_id = :tenant';
            $params['tenant'] = (int) $onlyTenant;
        }

        return $legacy->fetchAllAssociative($sql . ' ORDER BY tenant_id', $params);
    }

    /**
     * @param list<int> $tenantIds
     *
     * @return list<array<string, mixed>>
     */
    private function fetchRoles(Connection $legacy, array $tenantIds): array
    {
        return $legacy->fetchAllAssociative(
            'SELECT role_id, tenant_id, role_name, role_key, description, is_system
             FROM roles WHERE tenant_id IN (:ids) ORDER BY role_id',
            ['ids' => $tenantIds],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
    }

    /**
     * @param list<int> $tenantIds
     *
     * @return list<array<string, mixed>>
     */
    private function fetchUsers(Connection $legacy, array $tenantIds, int $limit): array
    {
        $sql = 'SELECT id, tenant_id, email, password, first_name, last_name, phone, avatar,
                       is_2fa, is_active, is_admin, is_learner, is_content, is_hr, is_instructor,
                       account_locked, login_count, last_login_at, hire_date, probation_end_date,
                       approved, deleted_at, suspended, language,
                       privacy_consent, marketing_consent, data_processing_consent
                FROM users
                WHERE tenant_id IN (:ids) AND deleted_at IS NULL
                ORDER BY id';

        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        return $legacy->fetchAllAssociative(
            $sql,
            ['ids' => $tenantIds],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertTenant(Connection $target, array $row, bool $dryRun): string
    {
        if ($dryRun) {
            $existing = $target->fetchOne('SELECT id FROM tenants WHERE legacy_id = :id', ['id' => $row['tenant_id']]);

            return $existing === false ? '00000000-0000-0000-0000-000000000000' : (string) $existing;
        }

        $code = trim((string) ($row['tenant_code'] ?? '')) ?: ('tenant-' . $row['tenant_id']);

        $id = $target->fetchOne(
            'INSERT INTO tenants (legacy_id, name, code, domain, status, contact_email, contact_phone,
                                  users_limit, storage_limit_mb, subscription_expires_at, logo_url,
                                  industry, company_size, notes)
             VALUES (:legacyId, :name, :code, :domain, :status, :email, :phone,
                     :usersLimit, :storageLimit, :expires, :logo, :industry, :size, :notes)
             ON CONFLICT (legacy_id) DO UPDATE SET
                name = EXCLUDED.name, code = EXCLUDED.code, domain = EXCLUDED.domain,
                status = EXCLUDED.status, contact_email = EXCLUDED.contact_email,
                updated_at = NOW()
             RETURNING id',
            [
                'legacyId'     => (int) $row['tenant_id'],
                'name'         => (string) $row['name'],
                'code'         => $code,
                'domain'       => $this->nullable($row['domain'] ?? null),
                'status'       => ((int) ($row['is_active'] ?? 1)) === 1 ? 'active' : 'suspended',
                'email'        => $this->nullable($row['contact_email'] ?? null),
                'phone'        => $this->nullable($row['contact_phone'] ?? null),
                'usersLimit'   => $row['users_limit'] ?? null,
                'storageLimit' => $row['storage_limit_mb'] ?? null,
                'expires'      => $this->nullable($row['subscription_expires_at'] ?? null),
                'logo'         => $this->nullable($row['logo_url'] ?? null),
                'industry'     => $this->nullable($row['industry'] ?? null),
                'size'         => $this->nullable($row['company_size'] ?? null),
                'notes'        => $this->nullable($row['notes'] ?? null),
            ],
        );

        return (string) $id;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function upsertUser(
        Connection $target,
        string $tenantId,
        array $row,
        string $hash,
        bool $dryRun,
    ): ?string {
        if ($dryRun) {
            return '00000000-0000-0000-0000-000000000000';
        }

        try {
            $id = $target->fetchOne(
                'INSERT INTO users (legacy_id, tenant_id, email, password_hash, first_name, last_name,
                                    phone, avatar_url, status, two_factor_enabled, locale,
                                    login_count, last_login_at, hire_date, probation_end_date, consents)
                 VALUES (:legacyId, :tenant, :email, :hash, :first, :last, :phone, :avatar,
                         :status, :twoFactor, :locale, :loginCount, :lastLogin, :hireDate,
                         :probationEnd, CAST(:consents AS JSONB))
                 ON CONFLICT (legacy_id) DO UPDATE SET
                    email = EXCLUDED.email, password_hash = EXCLUDED.password_hash,
                    first_name = EXCLUDED.first_name, last_name = EXCLUDED.last_name,
                    status = EXCLUDED.status, updated_at = NOW()
                 RETURNING id',
                [
                    'legacyId'     => (int) $row['id'],
                    'tenant'       => $tenantId,
                    'email'        => strtolower(trim((string) $row['email'])),
                    'hash'         => $hash === '' ? null : $hash,
                    'first'        => (string) ($row['first_name'] ?? ''),
                    'last'         => (string) ($row['last_name'] ?? ''),
                    'phone'        => $this->nullable($row['phone'] ?? null),
                    // The legacy avatar column holds base64 image data inline;
                    // it is not a URL and is not carried across.
                    'avatar'       => null,
                    'status'       => $this->statusFor($row),
                    'twoFactor'    => ((int) ($row['is_2fa'] ?? 0)) === 1 ? 'true' : 'false',
                    'locale'       => (string) ($row['language'] ?? 'en') ?: 'en',
                    'loginCount'   => (int) ($row['login_count'] ?? 0),
                    'lastLogin'    => $this->nullable($row['last_login_at'] ?? null),
                    'hireDate'     => $this->nullable($row['hire_date'] ?? null),
                    'probationEnd' => $this->nullable($row['probation_end_date'] ?? null),
                    'consents'     => json_encode([
                        'privacy'        => (bool) ($row['privacy_consent'] ?? false),
                        'marketing'      => (bool) ($row['marketing_consent'] ?? false),
                        'dataProcessing' => (bool) ($row['data_processing_consent'] ?? false),
                    ], JSON_THROW_ON_ERROR),
                ],
            );

            return (string) $id;
        } catch (\Throwable) {
            // Most likely a duplicate email within the tenant — the legacy
            // schema did not enforce it.
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function statusFor(array $row): string
    {
        if ((int) ($row['account_locked'] ?? 0) === 1) {
            return 'locked';
        }

        if (($row['suspended'] ?? null) !== null || (int) ($row['is_active'] ?? 1) === 0) {
            return 'suspended';
        }

        // "Invited" means the account cannot be signed into yet, which in
        // practice means it has no usable password. The legacy `approved` and
        // `login_count` columns are unreliable for this — both are NULL even
        // for users with a recent `last_login_at`.
        $hash = (string) ($row['password'] ?? '');

        return $hash === '' ? 'invited' : 'active';
    }

    /**
     * @param array<int, SystemRole> $roleMap
     *
     * @return list<SystemRole>
     */
    private function rolesFromJoinTable(Connection $legacy, int $userId, array $roleMap): array
    {
        $ids = $legacy->fetchFirstColumn(
            'SELECT role_id FROM user_roles WHERE user_id = :id',
            ['id' => $userId],
        );

        $roles = [];

        foreach ($ids as $id) {
            $role = $roleMap[(int) $id] ?? null;

            if ($role !== null) {
                $roles[$role->value] = $role;
            }
        }

        return array_values($roles);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return list<SystemRole>
     */
    private function rolesFromFlags(array $row): array
    {
        $roles = [];

        foreach (self::FLAG_MAP as $column => $role) {
            if ((int) ($row[$column] ?? 0) === 1) {
                $roles[$role->value] = $role;
            }
        }

        return array_values($roles);
    }

    /**
     * Total distinct permissions a set of roles resolves to — used to say
     * whether the reconciliation rule grants more or less than it replaces.
     *
     * @param list<SystemRole> $roles
     */
    private function permissionCount(array $roles): int
    {
        $permissions = [];

        foreach ($roles as $role) {
            foreach ($role->effectivePermissions() as $permission) {
                $permissions[$permission->value] = true;
            }
        }

        return count($permissions);
    }

    /**
     * @param list<SystemRole> $a
     * @param list<SystemRole> $b
     */
    private function disagree(array $a, array $b): bool
    {
        $left = array_map(static fn (SystemRole $r): string => $r->value, $a);
        $right = array_map(static fn (SystemRole $r): string => $r->value, $b);
        sort($left);
        sort($right);

        return $left !== $right;
    }

    /**
     * @return array<string, string> role key => uuid
     */
    private function systemRoleIds(Connection $target): array
    {
        $map = [];

        foreach ($target->fetchAllAssociative('SELECT id, key FROM roles WHERE tenant_id IS NULL') as $row) {
            $map[(string) $row['key']] = (string) $row['id'];
        }

        return $map;
    }

    /**
     * @param list<SystemRole>      $roles
     * @param array<string, string> $systemRoleIds
     *
     * @return list<string> role keys that had no row and were not assigned
     */
    private function assignRoles(Connection $target, string $userId, array $roles, array $systemRoleIds): array
    {
        $dropped = [];

        // Replaced wholesale so a re-run cannot accumulate stale assignments.
        $target->executeStatement('DELETE FROM user_roles WHERE user_id = :user', ['user' => $userId]);

        foreach ($roles as $role) {
            $roleId = $systemRoleIds[$role->value] ?? null;

            if ($roleId === null) {
                // No row for this role. Silently skipping would quietly strip
                // access during an import, which is exactly the kind of thing
                // nobody notices until someone cannot do their job.
                $dropped[] = $role->value;
                continue;
            }

            $target->executeStatement(
                'INSERT INTO user_roles (user_id, role_id) VALUES (:user, :role)
                 ON CONFLICT (user_id, role_id) DO NOTHING',
                ['user' => $userId, 'role' => $roleId],
            );
        }

        return $dropped;
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' || $value === '0000-00-00' ? null : $value;
    }
}
