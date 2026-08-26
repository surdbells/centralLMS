<?php

declare(strict_types=1);

namespace Central\Api\Tests\Doubles;

use Central\Api\Domain\Identity\RefreshTokenRepository;

/** @internal */
final class FakeRefreshTokenRepository implements RefreshTokenRepository
{
    /** @var array<string, array{userId: string, expiresAt: \DateTimeImmutable, revokedAt: ?\DateTimeImmutable}> */
    private array $tokens = [];

    public function store(
        string $userId,
        string $token,
        \DateTimeImmutable $expiresAt,
        ?string $userAgent = null,
        ?string $ipAddress = null,
    ): string {
        $this->tokens[$this->hash($token)] = [
            'userId'    => $userId,
            'expiresAt' => $expiresAt,
            'revokedAt' => null,
        ];

        return 'token-' . count($this->tokens);
    }

    public function isActive(string $token, \DateTimeImmutable $now): bool
    {
        $record = $this->tokens[$this->hash($token)] ?? null;

        return $record !== null
            && $record['revokedAt'] === null
            && $record['expiresAt'] > $now;
    }

    public function rotate(
        string $oldToken,
        string $newToken,
        \DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
    ): void {
        $old = $this->tokens[$this->hash($oldToken)] ?? null;

        if ($old === null) {
            throw new \RuntimeException('Cannot rotate an unknown refresh token.');
        }

        $this->store($old['userId'], $newToken, $expiresAt);
        $this->revoke($oldToken, $now);
    }

    public function revoke(string $token, \DateTimeImmutable $now): void
    {
        $key = $this->hash($token);

        if (isset($this->tokens[$key])) {
            $this->tokens[$key]['revokedAt'] = $now;
        }
    }

    public function sessionsFor(string $userId, \DateTimeImmutable $now, int $limit = 20): array
    {
        $sessions = [];

        foreach ($this->tokens as $key => $record) {
            if ($record['userId'] !== $userId) {
                continue;
            }

            $sessions[] = [
                'id'        => $key,
                'issuedAt'  => $now->format(\DateTimeInterface::ATOM),
                'expiresAt' => $record['expiresAt']->format(\DateTimeInterface::ATOM),
                'revokedAt' => $record['revokedAt']?->format(\DateTimeInterface::ATOM),
                'userAgent' => null,
                'ipAddress' => null,
                'active'    => $record['revokedAt'] === null && $record['expiresAt'] > $now,
            ];
        }

        return array_slice($sessions, 0, $limit);
    }

    public function revokeSession(string $userId, string $sessionId, \DateTimeImmutable $now): bool
    {
        $record = $this->tokens[$sessionId] ?? null;

        if ($record === null || $record['userId'] !== $userId || $record['revokedAt'] !== null) {
            return false;
        }

        $this->tokens[$sessionId]['revokedAt'] = $now;

        return true;
    }

    public function revokeAllForUser(string $userId, \DateTimeImmutable $now): int
    {
        $count = 0;

        foreach ($this->tokens as $key => $record) {
            if ($record['userId'] === $userId && $record['revokedAt'] === null) {
                $this->tokens[$key]['revokedAt'] = $now;
                ++$count;
            }
        }

        return $count;
    }

    public function deleteExpiredBefore(\DateTimeImmutable $cutoff): int
    {
        return 0;
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
