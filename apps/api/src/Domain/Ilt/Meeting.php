<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * A scheduled meeting, normalised across providers.
 *
 * `raw` keeps the untouched provider response. It is persisted to a `jsonb`
 * column so that a field we did not think to normalise is still recoverable
 * without re-calling the vendor — but nothing in the domain may read from it.
 * If a value matters, promote it to a real property here.
 */
final readonly class Meeting
{
    /**
     * @param array<string, mixed> $raw
     */
    public function __construct(
        public MeetingProviderId $provider,
        public string $externalId,
        public string $joinUrl,
        public \DateTimeImmutable $startsAt,
        public int $durationMinutes,
        public ?string $hostUrl = null,
        public ?string $password = null,
        public array $raw = [],
    ) {
    }

    /**
     * Safe to hand to a learner: never includes the host/start URL, which
     * grants host controls to whoever opens it.
     *
     * @return array<string, mixed>
     */
    public function toLearnerArray(): array
    {
        return [
            'provider'        => $this->provider->value,
            'providerLabel'   => $this->provider->label(),
            'joinUrl'         => $this->joinUrl,
            'password'        => $this->password,
            'startsAt'        => $this->startsAt->format(\DateTimeInterface::ATOM),
            'durationMinutes' => $this->durationMinutes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toHostArray(): array
    {
        return [...$this->toLearnerArray(), 'externalId' => $this->externalId, 'hostUrl' => $this->hostUrl];
    }
}
