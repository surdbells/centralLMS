<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * What a report returns, and therefore how a client should draw it.
 *
 * Named for what the *data* looks like rather than for a chart type. The
 * legacy's `'type' => 'chart'` left the client guessing between a bar, a line
 * and a pie; a series over time and a breakdown by category are different
 * shapes and only one of them is a line.
 */
enum ReportShape: string
{
    /** Scalar totals. A row of figures at the top of a page. */
    case Summary = 'summary';

    /** Rows and columns, paginated. */
    case Table = 'table';

    /** A value per period, in order. A line. */
    case Series = 'series';

    /** A value per category, unordered. Bars, or a pie. */
    case Breakdown = 'breakdown';

    /** Everything about one subject — a learner, a course. */
    case Detail = 'detail';

    /**
     * Whether a page of results makes sense.
     *
     * A summary has one row and a breakdown has as many as there are
     * categories; paginating either produces a second page that is always
     * empty, and a client that dutifully renders pagination controls for it.
     */
    public function isPaginated(): bool
    {
        return $this === self::Table;
    }
}
