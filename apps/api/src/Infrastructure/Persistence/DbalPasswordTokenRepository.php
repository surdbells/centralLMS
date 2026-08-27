<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Identity\PasswordToken;
use Central\Api\Domain\Identity\PasswordTokenPurpose;
use Central\Api\Domain\Identity\PasswordTokenRepository;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

final class DbalPasswordTokenRepository implements PasswordTokenRepository
{
    private const COLUMNS = 'id, tenant_id, user_id, purpose, expires_at, used_at, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function issue(
        string $tenantId,
        string $userId,
        PasswordTokenPurpose $purpose,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
        ?string $ip = null,
        ?string $userAgent = null,
    ): PasswordToken {
        $id = Uuid::uuid4()->toString();

        $this->connection->executeStatement(
            'INSERT INTO password_tokens
                 (id, tenant_id, user_id, purpose, token_hash, expires_at, requested_ip, requested_ua)
             VALUES (:id, :tenant, :user, :purpose, :hash, :expires, :ip, :ua)',
            [
                'id'      => $id,
                'tenant'  => $tenantId,
                'user'    => $userId,
                'purpose' => $purpose->value,
                'hash'    => $tokenHash,
                'expires' => $expiresAt->format(\DateTimeInterface::ATOM),
                // A malformed address would fail the INET cast and take the
                // whole request down over a field nothing reads.
                'ip'      => $this->address($ip),
                'ua'      => $userAgent,
            ],
        );

        return new PasswordToken(
            id: $id,
            tenantId: $tenantId,
            userId: $userId,
            purpose: $purpose,
            expiresAt: $expiresAt,
        );
    }

    public function findByHash(string $tokenHash): ?PasswordToken
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT %s FROM password_tokens WHERE token_hash = :hash', self::COLUMNS),
            ['hash' => $tokenHash],
        );

        return $row === false ? null : $this->hydrate($row);
    }

    public function claim(string $id, \DateTimeImmutable $at): bool
    {
        // `used_at IS NULL` in the WHERE is what makes this single-use under
        // two simultaneous requests: the second UPDATE matches nothing.
        return $this->connection->executeStatement(
            'UPDATE password_tokens SET used_at = :at WHERE id = :id AND used_at IS NULL',
            ['id' => $id, 'at' => $at->format(\DateTimeInterface::ATOM)],
        ) === 1;
    }

    public function retireFor(string $userId, ?PasswordTokenPurpose $purpose, \DateTimeImmutable $at): int
    {
        $sql = 'UPDATE password_tokens SET used_at = :at WHERE user_id = :user AND used_at IS NULL';
        $params = ['user' => $userId, 'at' => $at->format(\DateTimeInterface::ATOM)];

        if ($purpose !== null) {
            $sql .= ' AND purpose = :purpose';
            $params['purpose'] = $purpose->value;
        }

        return (int) $this->connection->executeStatement($sql, $params);
    }

    public function countRecent(
        string $userId,
        PasswordTokenPurpose $purpose,
        \DateTimeImmutable $since,
    ): int {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM password_tokens
             WHERE user_id = :user AND purpose = :purpose AND created_at >= :since',
            [
                'user'    => $userId,
                'purpose' => $purpose->value,
                'since'   => $since->format(\DateTimeInterface::ATOM),
            ],
        );
    }

    // --- internals --------------------------------------------------------

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): PasswordToken
    {
        return new PasswordToken(
            id: (string) $row['id'],
            tenantId: (string) $row['tenant_id'],
            userId: (string) $row['user_id'],
            purpose: PasswordTokenPurpose::from((string) $row['purpose']),
            expiresAt: new \DateTimeImmutable((string) $row['expires_at']),
            usedAt: $this->date($row['used_at'] ?? null),
            createdAt: $this->date($row['created_at'] ?? null),
        );
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new \DateTimeImmutable($value) : null;
    }

    private function address(?string $ip): ?string
    {
        return $ip !== null && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }
}
