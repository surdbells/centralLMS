<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Saas\PlatformRepository;
use Doctrine\DBAL\Connection;

final class DbalPlatformRepository implements PlatformRepository
{
    /** The statuses an organisation may be in — the tenants table's own CHECK. */
    private const STATUSES = ['trial', 'active', 'suspended', 'cancelled'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function tenants(?string $search, ?string $status, int $page, int $perPage): array
    {
        $where = ['t.deleted_at IS NULL'];
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $where[] = '(t.name ILIKE :search OR t.code ILIKE :search OR t.contact_email ILIKE :search OR t.domain ILIKE :search)';
            $params['search'] = '%' . trim($search) . '%';
        }
        if ($status !== null && in_array($status, self::STATUSES, true)) {
            $where[] = 't.status = :status';
            $params['status'] = $status;
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM tenants t WHERE {$whereSql}",
            $params,
        );

        $rows = $this->connection->fetchAllAssociative(
            "SELECT t.id, t.name, t.code, t.domain, t.status, t.contact_email,
                    t.users_limit, t.storage_limit_mb, t.subscription_expires_at, t.created_at,
                    p.id AS plan_id, p.code AS plan_code, p.name AS plan_name,
                    (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.deleted_at IS NULL) AS user_count
             FROM tenants t
             LEFT JOIN plans p ON p.id = t.plan_id
             WHERE {$whereSql}
             ORDER BY t.created_at DESC, t.name ASC
             LIMIT :limit OFFSET :offset",
            [...$params, 'limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return [
            'items' => array_map(fn (array $r): array => $this->row($r), $rows),
            'total' => $total,
        ];
    }

    public function findTenant(string $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            "SELECT t.id, t.name, t.code, t.domain, t.status, t.contact_email, t.contact_phone,
                    t.industry, t.company_size, t.logo_url, t.address,
                    t.users_limit, t.storage_limit_mb, t.subscription_expires_at,
                    t.provisioned_at, t.created_at, t.admin_user_id,
                    p.id AS plan_id, p.code AS plan_code, p.name AS plan_name,
                    (SELECT COUNT(*) FROM users u WHERE u.tenant_id = t.id AND u.deleted_at IS NULL) AS user_count,
                    (SELECT COUNT(*) FROM courses c WHERE c.tenant_id = t.id AND c.deleted_at IS NULL) AS course_count,
                    COALESCE((SELECT SUM(m.file_size) FROM media m WHERE m.tenant_id = t.id AND m.deleted_at IS NULL), 0) AS storage_bytes,
                    au.email AS admin_email,
                    NULLIF(btrim(COALESCE(au.first_name, '') || ' ' || COALESCE(au.last_name, '')), '') AS admin_name
             FROM tenants t
             LEFT JOIN plans p ON p.id = t.plan_id
             LEFT JOIN users au ON au.id = t.admin_user_id
             WHERE t.id = :id AND t.deleted_at IS NULL",
            ['id' => $id],
        );

        if ($row === false) {
            return null;
        }

        return $this->row($row) + [
            'contactPhone'  => $row['contact_phone'] !== null ? (string) $row['contact_phone'] : null,
            'industry'      => $row['industry'] !== null ? (string) $row['industry'] : null,
            'companySize'   => $row['company_size'] !== null ? (string) $row['company_size'] : null,
            'logoUrl'       => $row['logo_url'] !== null ? (string) $row['logo_url'] : null,
            'address'       => $row['address'] !== null ? (string) $row['address'] : null,
            'provisionedAt' => $this->atom($row['provisioned_at'] ?? null),
            'courseCount'   => (int) $row['course_count'],
            'storageMb'     => (int) round(((int) $row['storage_bytes']) / 1_048_576),
            'admin'         => $row['admin_user_id'] !== null ? [
                'id'    => (string) $row['admin_user_id'],
                'name'  => $row['admin_name'] !== null ? (string) $row['admin_name'] : null,
                'email' => $row['admin_email'] !== null ? (string) $row['admin_email'] : null,
            ] : null,
        ];
    }

    public function metrics(): array
    {
        $byStatus = $this->connection->fetchAllKeyValue(
            'SELECT status, COUNT(*) FROM tenants WHERE deleted_at IS NULL GROUP BY status',
        );

        $totals = $this->connection->fetchAssociative(
            "SELECT
                 (SELECT COUNT(*) FROM tenants WHERE deleted_at IS NULL) AS tenants,
                 (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) AS users,
                 COALESCE((SELECT SUM(file_size) FROM media WHERE deleted_at IS NULL), 0) AS storage_bytes",
        ) ?: [];

        $byPlan = $this->connection->fetchAllAssociative(
            "SELECT COALESCE(p.name, 'No plan') AS plan, COUNT(t.*) AS tenants
             FROM tenants t LEFT JOIN plans p ON p.id = t.plan_id
             WHERE t.deleted_at IS NULL
             GROUP BY p.name ORDER BY tenants DESC",
        );

        // Monthly recurring revenue from the active subscriptions, on the
        // amount each captured when it was set — not re-read from the plan, so a
        // re-price does not rewrite the figure — with a yearly price normalised
        // to a month. The same computation the billing overview uses, so the two
        // operator screens never disagree.
        $mrr = (int) $this->connection->fetchOne(
            "SELECT COALESCE(SUM(
                 CASE WHEN billing_cycle = 'yearly' THEN round(amount / 12.0) ELSE amount END
             ), 0)
             FROM subscriptions
             WHERE status = 'active' AND amount > 0",
        );

        return [
            'tenants' => [
                'total'     => (int) ($totals['tenants'] ?? 0),
                'active'    => (int) ($byStatus['active'] ?? 0),
                'trial'     => (int) ($byStatus['trial'] ?? 0),
                'suspended' => (int) ($byStatus['suspended'] ?? 0),
                'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
            ],
            'users'      => (int) ($totals['users'] ?? 0),
            'storageMb'  => (int) round(((int) ($totals['storage_bytes'] ?? 0)) / 1_048_576),
            'mrr'        => $mrr,
            'byPlan'     => array_map(
                static fn (array $r): array => ['plan' => (string) $r['plan'], 'tenants' => (int) $r['tenants']],
                $byPlan,
            ),
        ];
    }

    public function updateStatus(string $tenantId, string $status): bool
    {
        if (!in_array($status, self::STATUSES, true)) {
            return false;
        }

        return $this->connection->executeStatement(
            'UPDATE tenants SET status = :status, updated_at = now() WHERE id = :id AND deleted_at IS NULL',
            ['status' => $status, 'id' => $tenantId],
        ) > 0;
    }

    public function updateLimits(string $tenantId, ?int $usersLimit, ?int $storageLimitMb): bool
    {
        return $this->connection->executeStatement(
            'UPDATE tenants SET users_limit = :users, storage_limit_mb = :storage, updated_at = now()
             WHERE id = :id AND deleted_at IS NULL',
            ['users' => $usersLimit, 'storage' => $storageLimitMb, 'id' => $tenantId],
        ) > 0;
    }

    public function assignPlan(string $tenantId, ?string $planId): bool
    {
        return $this->connection->transactional(
            function (Connection $c) use ($tenantId, $planId): bool {
                if ($planId === null) {
                    return $c->executeStatement(
                        'UPDATE tenants SET plan_id = NULL, updated_at = now() WHERE id = :id AND deleted_at IS NULL',
                        ['id' => $tenantId],
                    ) > 0;
                }

                $plan = $c->fetchAssociative(
                    'SELECT users_limit, storage_limit_mb FROM plans WHERE id = :id AND deleted_at IS NULL',
                    ['id' => $planId],
                );
                if ($plan === false) {
                    return false;
                }

                // Copy the plan's allowances onto the tenant as its limits, so
                // the entitlement is read from one place afterwards.
                return $c->executeStatement(
                    'UPDATE tenants SET plan_id = :plan, users_limit = :users, storage_limit_mb = :storage,
                                        updated_at = now()
                     WHERE id = :id AND deleted_at IS NULL',
                    [
                        'plan'    => $planId,
                        'users'   => $plan['users_limit'],
                        'storage' => $plan['storage_limit_mb'],
                        'id'      => $tenantId,
                    ],
                ) > 0;
            },
        );
    }

    // --- internals ------------------------------------------------------------

    /**
     * @param  array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function row(array $r): array
    {
        return [
            'id'                    => (string) $r['id'],
            'name'                  => (string) $r['name'],
            'code'                  => (string) $r['code'],
            'domain'                => $r['domain'] !== null ? (string) $r['domain'] : null,
            'status'                => (string) $r['status'],
            'contactEmail'          => $r['contact_email'] !== null ? (string) $r['contact_email'] : null,
            'usersLimit'            => $r['users_limit'] !== null ? (int) $r['users_limit'] : null,
            'storageLimitMb'        => $r['storage_limit_mb'] !== null ? (int) $r['storage_limit_mb'] : null,
            'userCount'             => (int) ($r['user_count'] ?? 0),
            'plan'                  => $r['plan_id'] !== null ? [
                'id'   => (string) $r['plan_id'],
                'code' => (string) $r['plan_code'],
                'name' => (string) $r['plan_name'],
            ] : null,
            'subscriptionExpiresAt' => $this->atom($r['subscription_expires_at'] ?? null),
            'createdAt'             => $this->atom($r['created_at'] ?? null),
        ];
    }

    private function atom(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Exception) {
            return null;
        }
    }
}
