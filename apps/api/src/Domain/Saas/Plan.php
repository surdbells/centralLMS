<?php

declare(strict_types=1);

namespace Central\Api\Domain\Saas;

/**
 * One plan in the SaaS catalogue — the product an organisation subscribes to.
 *
 * Platform-level, not tenant-scoped: the same catalogue every organisation
 * chooses from. Prices are in minor units (kobo / cents) so money is never a
 * float; a null limit is unlimited.
 */
final readonly class Plan
{
    /**
     * @param array<string, mixed> $features feature flags the plan grants
     */
    public function __construct(
        public ?string $id,
        public string $code,
        public string $name,
        public ?string $description,
        public int $priceMonthly,
        public int $priceYearly,
        public string $currency,
        public ?int $usersLimit,
        public ?int $storageLimitMb,
        public array $features,
        public int $trialDays,
        public bool $isActive,
        public bool $isPublic,
        public int $sortOrder,
        public ?\DateTimeImmutable $createdAt = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'code'           => $this->code,
            'name'           => $this->name,
            'description'    => $this->description,
            'priceMonthly'   => $this->priceMonthly,
            'priceYearly'    => $this->priceYearly,
            'currency'       => $this->currency,
            'usersLimit'     => $this->usersLimit,
            'storageLimitMb' => $this->storageLimitMb,
            'features'       => (object) $this->features,
            'trialDays'      => $this->trialDays,
            'isActive'       => $this->isActive,
            'isPublic'       => $this->isPublic,
            'sortOrder'      => $this->sortOrder,
            'createdAt'      => $this->createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }
}
