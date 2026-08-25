<?php

declare(strict_types=1);

namespace Central\Api\Support;

final class SystemClock implements Clock
{
    public function __construct(private readonly \DateTimeZone $timezone = new \DateTimeZone('UTC'))
    {
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
