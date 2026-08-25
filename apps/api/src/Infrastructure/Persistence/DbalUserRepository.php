<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Identity\User;
use Central\Api\Domain\Identity\UserRepository;
use Central\Api\Domain\Identity\UserStatus;
use Doctrine\DBAL\Connection;

/**
 * User lookups for the identity layer.
 *
 * DBAL rather than the ORM: these run on the authentication path, select a
 * fixed handful of columns, and want no identity map or change tracking. The
 * ORM earns its keep on the richer write-side aggregates (courses,
 * assessments), not here.
 */
final class DbalUserRepository implements UserRepository
{
    /**
     * Roles are aggregated in the same round trip. Fetching them separately
     * would mean two queries on every single authenticated request.
     */
    private const SELECT = <<<'SQL'
        SELECT u.id, u.tenant_id, u.email, u.password_hash, u.first_name, u.last_name,
               u.status, u.two_factor_enabled, u.last_login_at,
               COALESCE(
                   ARRAY_AGG(ur.role_id::text) FILTER (WHERE ur.role_id IS NOT NULL),
                   '{}'
               ) AS role_ids
        FROM users u
        LEFT JOIN user_roles ur ON ur.user_id = u.id
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findForSignIn(string $email, ?string $tenantId = null): ?User
    {
        $email = trim($email);

        if ($email === '') {
            return null;
        }

        if ($tenantId !== null) {
            return $this->one(
                self::SELECT . ' WHERE u.email = :email AND u.tenant_id = :tenant AND u.deleted_at IS NULL
                                 GROUP BY u.id',
                ['email' => $email, 'tenant' => $tenantId],
            );
        }

        // No tenant context: accept the email only if it identifies exactly one
        // account. Picking the first of several would sign the user into an
        // arbitrary organisation.
        $rows = $this->connection->fetchAllAssociative(
            self::SELECT . ' WHERE u.email = :email AND u.deleted_at IS NULL GROUP BY u.id LIMIT 2',
            ['email' => $email],
        );

        return count($rows) === 1 ? $this->hydrate($rows[0]) : null;
    }

    public function find(string $id): ?User
    {
        return $this->one(
            self::SELECT . ' WHERE u.id = :id AND u.deleted_at IS NULL GROUP BY u.id',
            ['id' => $id],
        );
    }

    public function findByTenant(string $tenantId, string $id): ?User
    {
        return $this->one(
            self::SELECT . ' WHERE u.id = :id AND u.tenant_id = :tenant AND u.deleted_at IS NULL
                             GROUP BY u.id',
            ['id' => $id, 'tenant' => $tenantId],
        );
    }

    public function recordSuccessfulLogin(string $userId, \DateTimeImmutable $at): void
    {
        $this->connection->executeStatement(
            'UPDATE users SET last_login_at = :at, login_count = login_count + 1 WHERE id = :id',
            ['at' => $at->format('c'), 'id' => $userId],
        );
    }

    public function updatePasswordHash(string $userId, string $hash): void
    {
        $this->connection->executeStatement(
            'UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id',
            ['hash' => $hash, 'id' => $userId],
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function one(string $sql, array $params): ?User
    {
        $row = $this->connection->fetchAssociative($sql, $params);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): User
    {
        $status = UserStatus::tryFrom((string) $row['status']);

        if ($status === null) {
            // The check constraint makes this unreachable; an unknown status
            // must never be silently treated as Active.
            throw new \RuntimeException(sprintf('Unknown user status "%s".', $row['status']));
        }

        return new User(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            email: (string) $row['email'],
            status: $status,
            firstName: (string) ($row['first_name'] ?? ''),
            lastName: (string) ($row['last_name'] ?? ''),
            passwordHash: $this->nullableString($row['password_hash'] ?? null),
            roleIds: $this->parseIdArray($row['role_ids'] ?? null),
            twoFactorEnabled: (bool) $row['two_factor_enabled'],
            lastLoginAt: $this->asDate($row['last_login_at'] ?? null),
        );
    }

    /**
     * PostgreSQL returns an aggregated array as its literal form,
     * `{a,b}` — DBAL does not decode it for us.
     *
     * @return list<string>
     */
    private function parseIdArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_map(strval(...), $value));
        }

        if (!is_string($value) || $value === '' || $value === '{}') {
            return [];
        }

        $inner = trim($value, '{}');

        if ($inner === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $v): string => trim($v, '"'), explode(',', $inner)),
            static fn (string $v): bool => $v !== '',
        ));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function asDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
