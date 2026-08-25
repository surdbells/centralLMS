<?php

declare(strict_types=1);

namespace Central\Api\Support;

/**
 * Time as a dependency.
 *
 * Scheduling, token expiry and ILT session windows are all time-sensitive and
 * all need deterministic tests. Nothing in src/ may call time() or `new
 * DateTimeImmutable()` directly — inject this instead.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;

    public function timestamp(): int;
}
