<?php

declare(strict_types=1);

namespace Central\Api\Support;

final class SystemClock implements Clock
{
    private readonly \DateTimeZone $timezone;

    // An object default (`= new \DateTimeZone(...)`) cannot be compiled by
    // PHP-DI, so the container fails to build in production where it compiles.
    // Default inside the body instead, which autowires and compiles cleanly.
    public function __construct(?\DateTimeZone $timezone = null)
    {
        $this->timezone = $timezone ?? new \DateTimeZone('UTC');
    }

    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', $this->timezone);
    }

    public function timestamp(): int
    {
        return $this->now()->getTimestamp();
    }
}
