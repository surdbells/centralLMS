<?php

declare(strict_types=1);

namespace Central\Api\Domain\Identity;

/**
 * A user account, as the identity layer needs it.
 *
 * Deliberately narrow: this is what authentication and authorisation read, not
 * the full HR record. Department, job title, team, branch, hire date and the
 * rest belong to the people module and are loaded when something actually
 * needs them — the legacy `users` table had 42 columns and every endpoint
 * selected all of them.
 */
final readonly class User
{
    /**
     * @param list<string> $roleIds ids of the roles assigned to this user
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $email,
        public UserStatus $status,
        public string $firstName = '',
        public string $lastName = '',
        public ?string $passwordHash = null,
        public array $roleIds = [],
        public bool $twoFactorEnabled = false,
        public ?\DateTimeImmutable $lastLoginAt = null,
    ) {
    }

    public function fullName(): string
    {
        $name = trim($this->firstName . ' ' . $this->lastName);

        // Falling back to the email keeps lists readable for accounts invited
        // but never completed.
        return $name !== '' ? $name : $this->email;
    }

    /**
     * Whether this account can complete a password sign-in.
     *
     * An invited user has no password hash yet; an SSO-only user never will.
     * Both must fail closed rather than being compared against null.
     */
    public function canAuthenticateWithPassword(): bool
    {
        return $this->status->canSignIn()
            && $this->passwordHash !== null
            && $this->passwordHash !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'tenantId'    => $this->tenantId,
            'email'       => $this->email,
            'firstName'   => $this->firstName,
            'lastName'    => $this->lastName,
            'fullName'    => $this->fullName(),
            'status'      => $this->status->value,
            'twoFactor'   => $this->twoFactorEnabled,
            'lastLoginAt' => $this->lastLoginAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
