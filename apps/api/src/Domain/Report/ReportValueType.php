<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * What kind of value a column holds.
 */
enum ReportValueType: string
{
    case Text     = 'text';
    case Number   = 'number';
    case Percent  = 'percent';
    case Date     = 'date';
    /** Seconds. Formatted for reading at the edge, never stored formatted. */
    case Duration = 'duration';
    case Status   = 'status';

    /**
     * Whether a CSV cell should be left unquoted.
     *
     * Numbers only. A date written unquoted is re-interpreted by whichever
     * spreadsheet opens it, and a status like `not_started` is text.
     */
    public function isNumeric(): bool
    {
        return $this === self::Number || $this === self::Percent;
    }
}
