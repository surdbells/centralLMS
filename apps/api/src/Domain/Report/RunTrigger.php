<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * What caused a report to run.
 *
 * Kept apart because a manual run must not satisfy a schedule. Somebody
 * clicking "run now" on a Monday morning report should not stop the Monday
 * morning delivery — and without this the two runs are indistinguishable
 * afterwards, so it would.
 */
enum RunTrigger: string
{
    case Schedule = 'schedule';
    case Manual   = 'manual';
}
