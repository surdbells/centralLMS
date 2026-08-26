<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Persistence;

use Central\Api\Domain\Identity\RefreshTokenRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

final class DbalRefreshTokenRepository implements RefreshTokenRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function store(
        string $userId,
        string $token,
        \DateTimeImmutable $expiresAt,
        ?string $userAgent = null,
        ?string $ipAddress = null,
    ): string {
        $id = $this->connection->fetchOne(
            'INSERT INTO refresh_tokens (user_id, token_hash, expires_at, user_agent, ip_address)
             VALUES (:user, :hash, :expires, :agent, :ip)
             RETURNING id',
            [
                'user'    => $userId,
                'hash'    => self::hash($token),
                'expires' => $expiresAt->format('c'),
                // Truncated: this is a diagnostic aid, not a field to query on.
                'agent'   => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
                'ip'      => $ipAddress,
            ],
        );

        return (string) $id;
    }

    public function isActive(string $token, \DateTimeImmutable $now): bool
    {
        $found = $this->connection->fetchOne(
            'SELECT 1 FROM refresh_tokens
             WHERE token_hash = :hash AND revoked_at IS NULL AND expires_at > :now',
            ['hash' => self::hash($token), 'now' => $now->format('c')],
        );

        return $found !== false;
    }

    public function rotate(
        string $oldToken,
        string $newToken,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
    ): void {
        $this->connection->transactional(function (Connection $c) use ($oldToken, $newToken, $expiresAt, $now): void {
            $userId = $c->fetchOne(
                'SELECT user_id FROM refresh_tokens WHERE token_hash = :hash',
                ['hash' => self::hash($oldToken)],
            );

            if ($userId === false) {
                // Rotating an unknown token means the caller never validated
                // it. Failing loudly beats silently minting a session.
                throw new \RuntimeException('Cannot rotate an unknown refresh token.');
            }

            $newId = $c->fetchOne(
                'INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
                 VALUES (:user, :hash, :expires)
                 RETURNING id',
                [
                    'user'    => $userId,
                    'hash'    => self::hash($newToken),
                    'expires' => $expiresAt->format('c'),
                ],
            );

            // Link old to new so a replayed old token is traceable to the
            // session that superseded it.
            $c->executeStatement(
                'UPDATE refresh_tokens
                 SET revoked_at = :now, replaced_by = :new
                 WHERE token_hash = :hash AND revoked_at IS NULL',
                ['now' => $now->format('c'), 'new' => $newId, 'hash' => self::hash($oldToken)],
            );
        });
    }

    public function revoke(string $token, \DateTimeImmutable $now): void
    {
        $this->connection->executeStatement(
            'UPDATE refresh_tokens SET revoked_at = :now WHERE token_hash = :hash AND revoked_at IS NULL',
            ['now' => $now->format('c'), 'hash' => self::hash($token)],
        );
    }

    public function sessionsFor(string $userId, \DateTimeImmutable $now, int $limit = 20): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, issued_at, expires_at, revoked_at, user_agent, host(ip_address) AS ip_address,
                    (revoked_at IS NULL AND expires_at > :now) AS active
               FROM refresh_tokens
              WHERE user_id = :user
              ORDER BY issued_at DESC
              LIMIT :limit',
            ['user' => $userId, 'now' => $now->format(\DateTimeInterface::ATOM), 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );

        return array_map(
            static fn (array $row): array => [
                'id'        => (string) $row['id'],
                'issuedAt'  => (string) $row['issued_at'],
                'expiresAt' => (string) $row['expires_at'],
                'revokedAt' => $row['revoked_at'] === null ? null : (string) $row['revoked_at'],
                'userAgent' => $row['user_agent'] === null ? null : (string) $row['user_agent'],
                'ipAddress' => $row['ip_address'] === null ? null : (string) $row['ip_address'],
                'active'    => (bool) $row['active'],
            ],
            $rows,
        );
    }

    public function revokeSession(string $userId, string $sessionId, \DateTimeImmutable $now): bool
    {
        return $this->connection->executeStatement(
            'UPDATE refresh_tokens SET revoked_at = :now
              WHERE id = :id AND user_id = :user AND revoked_at IS NULL',
            ['id' => $sessionId, 'user' => $userId, 'now' => $now->format(\DateTimeInterface::ATOM)],
        ) > 0;
    }

    public function revokeAllForUser(string $userId, \DateTimeImmutable $now): int
    {
        return (int) $this->connection->executeStatement(
            'UPDATE refresh_tokens SET revoked_at = :now WHERE user_id = :user AND revoked_at IS NULL',
            ['now' => $now->format('c'), 'user' => $userId],
        );
    }

    public function deleteExpiredBefore(\DateTimeImmutable $cutoff): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM refresh_tokens WHERE expires_at < :cutoff',
            ['cutoff' => $cutoff->format('c')],
        );
    }

    /**
     * The token itself is never stored, so a leaked backup yields nothing
     * replayable. SHA-256 without a salt is correct here: the input is already
     * 256 bits of entropy, so there is nothing to brute-force, and an
     * unsalted digest is what makes the lookup a single indexed read.
     */
    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
