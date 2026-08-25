<?php

declare(strict_types=1);

namespace Central\Api\Support;

/**
 * Test double for {@see Clock}. Lives in src/ rather than tests/ so that
 * fixtures and console commands can use it too.
 */
final class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(\DateTimeImmutable|string $now = '2026-01-01T00:00:00+00:00')
    {
        $this->now = $now instanceof \DateTimeImmutable
            ? $now
            : new \DateTimeImmutable($now, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function timestamp(): int
    {
        return $this->now->getTimestamp();
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }

    public function set(\DateTimeImmutable $now): void
    {
        $this->now = $now;
    }
}
