<?php

declare(strict_types=1);

namespace Central\Api\Domain\Saas;

/**
 * A code that lets someone create an organisation from the public site.
 *
 * Platform-level, issued by the operator: it says how many organisations it may
 * still create, an optional window it is good for, and — optionally — the plans
 * it unlocks. Sign-up validates one and, on success, spends a use.
 *
 * The rules live here as pure checks so the sign-up action and the operator
 * console agree on what "valid" means, and so they are testable without a
 * database or a clock of their own.
 */
final readonly class OnboardingCode
{
    /**
     * @param list<string>|null $allowedPlanIds plan ids this code unlocks; null = any plan
     */
    public function __construct(
        public ?string $id,
        public string $code,
        public ?string $description,
        public ?int $maxUses,
        public int $usedCount,
        public ?array $allowedPlanIds,
        public ?\DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validUntil,
        public bool $isActive,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
    }

    /**
     * Why the code cannot be used right now, or null when it can.
     *
     * One place, so validation at sign-up and the "usable?" the console shows
     * never drift. The clock is passed in rather than read here.
     */
    public function reasonUnusable(\DateTimeImmutable $now): ?string
    {
        if (!$this->isActive) {
            return 'This code is no longer active.';
        }
        if ($this->maxUses !== null && $this->usedCount >= $this->maxUses) {
            return 'This code has already been used the maximum number of times.';
        }
        if ($this->validFrom !== null && $now < $this->validFrom) {
            return 'This code is not valid yet.';
        }
        if ($this->validUntil !== null && $now > $this->validUntil) {
            return 'This code has expired.';
        }

        return null;
    }

    public function isUsable(\DateTimeImmutable $now): bool
    {
        return $this->reasonUnusable($now) === null;
    }

    /** Whether this code unlocks a given plan (null allow-list = every plan). */
    public function allowsPlan(string $planId): bool
    {
        return $this->allowedPlanIds === null || in_array($planId, $this->allowedPlanIds, true);
    }

    /** Uses left, or null when unlimited. */
    public function remainingUses(): ?int
    {
        return $this->maxUses === null ? null : max(0, $this->maxUses - $this->usedCount);
    }

    /**
     * The operator's view of the code — the code string included, since this is
     * only ever sent to a super admin managing the catalogue, never to the
     * public sign-up (which only ever learns valid / not).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'code'           => $this->code,
            'description'    => $this->description,
            'maxUses'        => $this->maxUses,
            'usedCount'      => $this->usedCount,
            'remainingUses'  => $this->remainingUses(),
            'allowedPlanIds' => $this->allowedPlanIds,
            'validFrom'      => $this->validFrom?->format(\DateTimeInterface::ATOM),
            'validUntil'     => $this->validUntil?->format(\DateTimeInterface::ATOM),
            'isActive'       => $this->isActive,
            'createdAt'      => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
