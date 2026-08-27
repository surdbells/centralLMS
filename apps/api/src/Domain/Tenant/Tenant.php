<?php

declare(strict_types=1);

namespace Central\Api\Domain\Tenant;

/**
 * An organisation's own record.
 *
 * Distinct from its settings, and deliberately so. A setting is a preference
 * with a default that most tenants never change; this is the organisation
 * itself — what it is called, how to reach it, what its mark looks like. The
 * legacy settings screen offered `company_name` and `company_email` as
 * key/value settings alongside the real columns, so there were two answers to
 * "what is this company called" and nothing kept them in step.
 *
 * The limits are read-only here. How many people an organisation may have and
 * how much it may store are what it is paying for, not something it sets.
 */
final readonly class Tenant
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
        public string $status,
        public ?string $contactEmail = null,
        public ?string $contactPhone = null,
        public ?string $address = null,
        public ?string $domain = null,
        public ?string $logoUrl = null,
        public ?string $industry = null,
        public ?string $companySize = null,
        /** Read-only: what the organisation is entitled to, not what it chose. */
        public ?int $usersLimit = null,
        public ?int $storageLimitMb = null,
        public ?string $subscriptionExpiresAt = null,
        public ?string $createdAt = null,
    ) {
    }

    /**
     * What an administrator sees and may change.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            // The organisation's short code, and the tail of its registration
            // and sign-in links. Not editable: changing it would silently
            // break every link already handed out.
            'code'         => $this->code,
            'status'       => $this->status,
            'contactEmail' => $this->contactEmail,
            'contactPhone' => $this->contactPhone,
            'address'      => $this->address,
            'domain'       => $this->domain,
            'logoUrl'      => $this->logoUrl,
            'industry'     => $this->industry,
            'companySize'  => $this->companySize,
            'limits'       => [
                'users'   => $this->usersLimit,
                'storage' => $this->storageLimitMb,
                'expires' => $this->subscriptionExpiresAt,
            ],
            'createdAt'    => $this->createdAt,
        ];
    }
}
