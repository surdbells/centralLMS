<?php

declare(strict_types=1);

namespace Central\Api\Domain\People;

use Central\Api\Domain\Identity\UserStatus;

/**
 * The whole record of somebody in an organisation.
 *
 * The other half of {@see \Central\Api\Domain\Identity\User}, which is
 * deliberately narrow because authentication has no business loading a hire
 * date. This is the people-management view of the same row: profile, where
 * they sit in the organisation, and who they report to.
 *
 * The org-unit fields are ids into `lookups`, whose `kind` is enforced by a
 * generated column and a composite foreign key — so a department id cannot be
 * stored in the team column, which is the mistake the legacy's five separate
 * tables and five bare integer columns invited.
 */
final readonly class Person
{
    /**
     * @param list<string> $roleIds
     */
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public string $email,
        public string $firstName,
        public string $lastName,
        public UserStatus $status = UserStatus::Invited,
        public ?string $phone = null,
        public ?string $avatarUrl = null,
        /** Never null: the column is NOT NULL and defaults to 'en'. */
        public string $locale = 'en',
        public ?string $managerId = null,
        public ?string $branchId = null,
        public ?string $departmentId = null,
        public ?string $teamId = null,
        public ?string $levelId = null,
        public ?string $jobTitleId = null,
        public ?\DateTimeImmutable $hireDate = null,
        public ?\DateTimeImmutable $probationEndDate = null,
        public array $roleIds = [],
        public bool $twoFactorEnabled = false,
        public ?\DateTimeImmutable $lastLoginAt = null,
        public int $loginCount = 0,
        public ?\DateTimeImmutable $createdAt = null,
        /** Names resolved from `lookups`, for display. Read-only. */
        public ?PersonPlacement $placement = null,
        /** Counts derived from enrolments. Read-only, never stored. */
        public ?PersonActivity $activity = null,
    ) {
    }

    /**
     * The same person, at a different point in the account lifecycle.
     *
     * Readonly classes have no setter and twenty-odd constructor arguments, so
     * one named helper beats every caller restating the whole record to change
     * a single field — and getting one of them wrong.
     */
    public function withStatus(UserStatus $status): self
    {
        return new self(
            id: $this->id,
            tenantId: $this->tenantId,
            email: $this->email,
            firstName: $this->firstName,
            lastName: $this->lastName,
            status: $status,
            phone: $this->phone,
            avatarUrl: $this->avatarUrl,
            locale: $this->locale,
            managerId: $this->managerId,
            branchId: $this->branchId,
            departmentId: $this->departmentId,
            teamId: $this->teamId,
            levelId: $this->levelId,
            jobTitleId: $this->jobTitleId,
            hireDate: $this->hireDate,
            probationEndDate: $this->probationEndDate,
            roleIds: $this->roleIds,
            twoFactorEnabled: $this->twoFactorEnabled,
            lastLoginAt: $this->lastLoginAt,
            loginCount: $this->loginCount,
            createdAt: $this->createdAt,
            placement: $this->placement,
            activity: $this->activity,
        );
    }

    public function fullName(): string
    {
        $name = trim($this->firstName . ' ' . $this->lastName);

        // Falling back to the address keeps a list readable for accounts
        // invited but never completed.
        return $name !== '' ? $name : $this->email;
    }

    /**
     * Whether this person can be given a manager.
     *
     * Nobody manages themselves. Longer cycles are caught by the repository,
     * which can see the chain; this catches the common case at the edge with a
     * message that names what went wrong.
     */
    public function canReportTo(string $managerId): bool
    {
        return $this->id === null || $this->id !== $managerId;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'email'     => $this->email,
            'firstName' => $this->firstName,
            'lastName'  => $this->lastName,
            'fullName'  => $this->fullName(),
            'status'    => $this->status->value,
            'phone'     => $this->phone,
            'avatarUrl' => $this->avatarUrl,
            'locale'    => $this->locale,
            'managerId' => $this->managerId,

            'branchId'     => $this->branchId,
            'departmentId' => $this->departmentId,
            'teamId'       => $this->teamId,
            'levelId'      => $this->levelId,
            'jobTitleId'   => $this->jobTitleId,

            'hireDate'         => $this->hireDate?->format('Y-m-d'),
            'probationEndDate' => $this->probationEndDate?->format('Y-m-d'),

            'roleIds'     => $this->roleIds,
            'twoFactor'   => $this->twoFactorEnabled,
            'lastLoginAt' => $this->lastLoginAt?->format(\DateTimeInterface::ATOM),
            'loginCount'  => $this->loginCount,
            'createdAt'   => $this->createdAt?->format(\DateTimeInterface::ATOM),

            // Present only when the caller asked for a view that resolves
            // them. Absent is different from empty: a list that does not join
            // the lookups should not claim everybody is unassigned.
            'placement' => $this->placement?->toArray(),
            'activity'  => $this->activity?->toArray(),
        ];
    }
}
