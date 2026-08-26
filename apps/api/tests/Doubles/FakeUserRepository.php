<?php

declare(strict_types=1);

namespace Central\Api\Tests\Doubles;

use Central\Api\Domain\Identity\User;
use Central\Api\Domain\Identity\UserRepository;

/** @internal */
final class FakeUserRepository implements UserRepository
{
    /** @var array<string, User> */
    private array $byId = [];

    /** @var array<string, string> */
    public array $rehashed = [];

    /** @var array<string, \DateTimeImmutable> */
    public array $lastLogin = [];

    public function add(User $user): void
    {
        $this->byId[$user->id] = $user;
    }

    public function findForSignIn(string $email, ?string $tenantId = null): ?User
    {
        foreach ($this->byId as $user) {
            if (
                strcasecmp($user->email, $email) === 0
                && ($tenantId === null || $user->tenantId === $tenantId)
            ) {
                return $user;
            }
        }

        return null;
    }

    public function find(string $id): ?User
    {
        return $this->byId[$id] ?? null;
    }

    public function findByTenant(string $tenantId, string $id): ?User
    {
        $user = $this->find($id);

        return $user?->tenantId === $tenantId ? $user : null;
    }

    public function recordSuccessfulLogin(string $userId, \DateTimeImmutable $at): void
    {
        $this->lastLogin[$userId] = $at;
    }

    public function updatePasswordHash(string $userId, string $hash): void
    {
        $this->rehashed[$userId] = $hash;
    }

    /**
     * @return list<string>
     */
    public function activeIds(string $tenantId): array
    {
        return array_values(array_map(
            static fn (User $user): string => $user->id,
            array_filter(
                $this->byId,
                static fn (User $user): bool => $user->tenantId === $tenantId,
            ),
        ));
    }
}
