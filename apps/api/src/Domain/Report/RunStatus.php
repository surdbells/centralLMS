<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

enum RunStatus: string
{
    case Running   = 'running';
    case Completed = 'completed';
    case Failed    = 'failed';

    public function isFinished(): bool
    {
        return $this !== self::Running;
    }
}
