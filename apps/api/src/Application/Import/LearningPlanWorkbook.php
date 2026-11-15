<?php

declare(strict_types=1);

namespace Central\Api\Application\Import;

use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Reads a yearly Learning Plan workbook into plain arrays: which sheets are
 * plans, which columns hold which category of training, and per person the
 * items they are down for — split, paired with providers and dated.
 *
 * A plan sheet is one row per person: NAMES, Email Address, then pairs of
 * columns — a category ("Functional Learning", "Conference", "Leadership
 * Learning" …) followed by its "Provider & Date". Columns are found by their
 * header text, never by position, because every entity's sheet differs.
 *
 * Pure: no database. {@see LearningPlanImporter} matches people and writes.
 *
 * @phpstan-type PlanItem array{category: string, title: string, provider: ?string, scheduleText: ?string, start: ?string, end: ?string, key: string, sortOrder: int}
 * @phpstan-type PlanPerson array{row: int, email: string, name: ?string, items: list<PlanItem>}
 * @phpstan-type SheetWarning array{row: ?int, code: string, message: string}
 * @phpstan-type PlanColumn array{index: int, header: string, category: string, providerIndex: ?int, mixed: bool}
 * @phpstan-type SkippedRow array{row: int, name: ?string, reason: string}
 * @phpstan-type ParsedSheet array{sheet: string, index: int, kind: string, year: ?int, entity: ?string, planTitle: ?string, columns: list<PlanColumn>, rows: int, people: list<PlanPerson>, skipped: list<SkippedRow>, warnings: list<SheetWarning>, splitCells: int, mismatchedCells: int}
 */
final class LearningPlanWorkbook
{
    public const MAX_ROWS = 5000;
    public const MAX_ITEM_COLUMNS = 30;

    /** Display/sort order of the categories. */
    public const CATEGORY_ORDER = [
        'functional'     => 0,
        'conference'     => 1,
        'certification'  => 2,
        'behavioural'    => 3,
        'organisational' => 4,
        'leadership'     => 5,
        'other'          => 6,
    ];

    /**
     * @return list<ParsedSheet>
     */
    public function read(string $path): array
    {
        // Empty rows are kept so the row numbers reported match Excel's.
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));
        $reader->open($path);

        $sheets = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];
                $n    = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $rows[] = array_values(array_map(self::cell(...), $row->toArray()));
                    // Header + MAX_ROWS data rows, plus one to know we overflowed.
                    if (++$n > self::MAX_ROWS + 1) {
                        break;
                    }
                }

                $sheets[] = $this->parseSheet($sheet->getName(), $sheet->getIndex(), $rows);
            }
        } finally {
            $reader->close();
        }

        return $sheets;
    }

    /**
     * Classifies and parses one sheet from its rows (row 1 = header).
     *
     * @param list<list<string>> $rows
     *
     * @return ParsedSheet
     */
    public function parseSheet(string $name, int $index, array $rows): array
    {
        $sheet = [
            'sheet'           => $name,
            'index'           => $index,
            'kind'            => 'unrecognised',
            'year'            => null,
            'entity'          => null,
            'planTitle'       => null,
            'columns'         => [],
            'rows'            => 0,
            'people'          => [],
            'skipped'         => [],
            'warnings'        => [],
            'splitCells'      => 0,
            'mismatchedCells' => 0,
        ];

        $nonBlank = array_values(array_filter($rows, static fn (array $r): bool => !self::blank($r)));
        if ($nonBlank === []) {
            $sheet['kind'] = 'empty';
            $sheet['warnings'][] = self::warn(null, 'sheet_empty', 'The sheet has no rows.');

            return $sheet;
        }

        $header   = $rows[0];
        $headerAt = static function (string $re) use ($header): ?int {
            foreach ($header as $i => $h) {
                if ($h !== '' && preg_match($re, $h) === 1) {
                    return $i;
                }
            }

            return null;
        };

        $emailCol = $headerAt('/e-?mail/i');
        $nameCol  = $headerAt('/^names?$/i');
        $sheet['rows'] = max(0, count($nonBlank) - (self::blank($header) ? 0 : 1));

        if ($emailCol !== null && $headerAt('/staff\s*id/i') !== null) {
            $sheet['kind'] = 'placement';

            return $sheet;
        }

        $looksLikePlan = preg_match('/\b(20\d{2})\b/', $name, $ym) === 1
            && preg_match('/(learning|training)\s+plan/i', $name) === 1;

        if (!$looksLikePlan) {
            return $sheet;
        }

        $columns = $this->categoryColumns($header);
        if ($columns === [] || $emailCol === null) {
            $sheet['warnings'][] = self::warn(null, 'no_category_columns', 'No learning-category or e-mail column was found in the header row.');

            return $sheet;
        }

        $year   = (int) $ym[1];
        $entity = $this->entity($name);

        $sheet['kind']      = 'plan';
        $sheet['year']      = $year;
        $sheet['entity']    = $entity;
        $sheet['planTitle'] = $entity === '' ? $year . ' Learning Plan' : $year . " Learning Plan \u{2013} " . $entity;
        $sheet['columns']   = $columns;

        $dataRows = array_slice($rows, 1, null, true);
        if (count($dataRows) > self::MAX_ROWS) {
            $dropped  = array_slice($dataRows, self::MAX_ROWS);
            $dataRows = array_slice($dataRows, 0, self::MAX_ROWS, true);

            if (array_filter($dropped, static fn (array $r): bool => !self::blank($r)) !== []) {
                $sheet['warnings'][] = self::warn(null, 'row_limit', 'Only the first ' . self::MAX_ROWS . ' rows were read.');
            }
        }

        $seen = [];

        foreach ($dataRows as $i => $cells) {
            if (self::blank($cells)) {
                continue;
            }

            $rowNo = $i + 1;
            $name  = $nameCol !== null ? self::oneLine($cells[$nameCol] ?? '') : '';
            $name  = $name === '' ? null : $name;
            $email = mb_strtolower(trim((string) preg_replace('/\s+/u', '', $cells[$emailCol] ?? '')));

            if ($email === '') {
                $sheet['skipped'][]  = ['row' => $rowNo, 'name' => $name, 'reason' => 'no_email'];
                $sheet['warnings'][] = self::warn($rowNo, 'no_email', 'Row has no e-mail address and was skipped.');

                continue;
            }

            if (isset($seen[$email])) {
                $sheet['skipped'][]  = ['row' => $rowNo, 'name' => $name, 'reason' => 'duplicate_email'];
                $sheet['warnings'][] = self::warn($rowNo, 'duplicate_email', sprintf('%s already appears on row %d; this row was skipped.', $email, $seen[$email]));

                continue;
            }
            $seen[$email] = $rowNo;

            $sheet['people'][] = [
                'row'   => $rowNo,
                'email' => $email,
                'name'  => $name,
                'items' => $this->items($cells, $columns, $email, $year, $rowNo, $sheet),
            ];
        }

        if ($sheet['people'] === [] && $sheet['skipped'] === []) {
            $sheet['warnings'][] = self::warn(null, 'sheet_empty', 'The sheet has a header but no people.');
        }

        return $sheet;
    }

    /**
     * The category columns of a plan header, each with its provider column.
     *
     * @param list<string> $header
     *
     * @return list<PlanColumn>
     */
    public function categoryColumns(array $header): array
    {
        $columns = [];

        foreach ($header as $i => $h) {
            if ($h === '' || preg_match('/provider|date/i', $h) === 1) {
                continue;
            }

            $category = self::categoryOf($h);
            if ($category === null) {
                continue;
            }

            $next = $header[$i + 1] ?? '';

            $columns[] = [
                'index'         => $i,
                'header'        => self::oneLine($h),
                'category'      => $category,
                'providerIndex' => $next !== '' && preg_match('/provider|date/i', $next) === 1 ? $i + 1 : null,
                'mixed'         => preg_match('/conference/i', $h) === 1 && preg_match('/certification/i', $h) === 1,
            ];

            if (count($columns) >= self::MAX_ITEM_COLUMNS) {
                break;
            }
        }

        return $columns;
    }

    public static function categoryOf(string $header): ?string
    {
        return match (true) {
            preg_match('/functional/i', $header) === 1                  => 'functional',
            preg_match('/conference/i', $header) === 1                  => 'conference',
            preg_match('/certification/i', $header) === 1               => 'certification',
            preg_match('/behaviou?ral|culture/i', $header) === 1        => 'behavioural',
            preg_match('/organi[sz]ational/i', $header) === 1           => 'organisational',
            preg_match('/leadership/i', $header) === 1                  => 'leadership',
            default                                                     => null,
        };
    }

    /** "2025 MART Training Plan " → "MART"; "2026 Learning Plan - MWOG" → "MWOG". */
    public function entity(string $sheetName): string
    {
        $e = (string) preg_replace('/\b20\d{2}\b/', ' ', $sheetName);
        $e = (string) preg_replace('/(learning|training)\s+plan/i', ' ', $e);
        $e = str_replace(['-', '_', "\u{2013}", "\u{2014}"], ' ', $e);

        return mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $e)));
    }

    /** Lower-case, dashes unified, whitespace collapsed, trailing punctuation trimmed. */
    public static function normTitle(string $title): string
    {
        $t = mb_strtolower(PlanCellSplitter::normaliseSpaces($title));
        $t = str_replace(["\u{2013}", "\u{2014}"], '-', $t);
        $t = trim((string) preg_replace('/\s+/u', ' ', $t));

        return rtrim($t, '.,;:/ ');
    }

    public static function importKey(string $email, string $category, string $title): string
    {
        return sha1($email . "\x1F" . $category . "\x1F" . self::normTitle($title));
    }

    // --- one person's items ----------------------------------------------------

    /**
     * @param list<string>     $cells
     * @param list<PlanColumn> $columns
     * @param ParsedSheet      $sheet   counters and warnings are added here
     *
     * @return list<PlanItem>
     */
    private function items(array $cells, array $columns, string $email, int $year, int $rowNo, array &$sheet): array
    {
        $items    = [];
        $keys     = [];
        $position = [];

        foreach ($columns as $col) {
            $rawTitle    = $cells[$col['index']] ?? '';
            $rawProvider = $col['providerIndex'] !== null ? ($cells[$col['providerIndex']] ?? '') : '';
            $titles      = PlanCellSplitter::split($rawTitle);
            $providers   = $col['providerIndex'] !== null ? PlanCellSplitter::split($rawProvider) : [];

            if ($titles === []) {
                if ($providers !== []) {
                    $sheet['warnings'][] = self::warn($rowNo, 'provider_without_title', sprintf('"%s" has a provider but no training.', $col['header']));
                }

                continue;
            }

            // Some cells list one training per line (or "…;\n…") with no "/".
            // Only when the training and provider cells both have the same
            // number of lines are they read that way, so a title that merely
            // wraps onto a second line stays whole.
            if (count($titles) === 1 && $col['providerIndex'] !== null) {
                $titleLines    = PlanCellSplitter::splitLines($rawTitle);
                $providerLines = PlanCellSplitter::splitLines($rawProvider);

                if (count($titleLines) > 1 && count($titleLines) === count($providerLines)) {
                    $titles    = $titleLines;
                    $providers = $providerLines;
                }
            }

            if (count($titles) > 1) {
                $sheet['splitCells']++;
            }

            $tc = count($titles);
            if (count($providers) > 1 && count($providers) !== $tc) {
                $providers = self::foldProviderNotes($providers, $year);
            }
            $pc = count($providers);

            // Several trainings with a different number of providers: which
            // date belongs to which training is unknown, so no training gets
            // one — better none than another training's.
            $ambiguous = $tc > 1 && $pc > 1 && $pc !== $tc;

            if ($pc > 1 && $pc !== $tc) {
                $sheet['mismatchedCells']++;
                $sheet['warnings'][] = self::warn($rowNo, 'provider_count_mismatch', sprintf(
                    $ambiguous
                        ? '"%s": %d trainings but %d providers; every training keeps all providers and no dates were set.'
                        : '"%s": %d trainings but %d providers; every training keeps all providers.',
                    $col['header'],
                    $tc,
                    $pc,
                ));
            }

            foreach ($titles as $k => $title) {
                $provider = match (true) {
                    $pc === 0   => null,
                    $pc === $tc => $providers[$k],
                    $pc === 1   => $providers[0],
                    default     => implode(' / ', $providers),
                };

                $category = self::itemCategory($col, $title);

                $title = mb_substr($title, 0, 500);
                $key   = self::importKey($email, $category, $title);

                if (isset($keys[$key])) {
                    $sheet['warnings'][] = self::warn($rowNo, 'duplicate_item', sprintf('"%s" is listed twice; kept once.', $title));

                    continue;
                }
                $keys[$key] = true;

                $schedule = $ambiguous
                    ? ['provider' => $provider === null ? null : PlanCellSplitter::clean($provider), 'scheduleText' => null, 'start' => null, 'end' => null, 'warnings' => []]
                    : PlanScheduleParser::parse($provider, $year);
                foreach ($schedule['warnings'] as $code) {
                    $sheet['warnings'][] = self::warn($rowNo, $code, $code === 'end_before_start'
                        ? sprintf('"%s": the end date is before the start; only the start was kept.', $schedule['scheduleText'] ?? '')
                        : sprintf('"%s" is dated outside %d.', $schedule['scheduleText'] ?? '', $year));
                }

                $pos = $position[$category] ?? 0;
                $position[$category] = $pos + 1;

                $items[] = [
                    'category'     => $category,
                    'title'        => $title,
                    'provider'     => $schedule['provider'],
                    'scheduleText' => $schedule['scheduleText'],
                    'start'        => $schedule['start'],
                    'end'          => $schedule['end'],
                    'key'          => $key,
                    'sortOrder'    => self::CATEGORY_ORDER[$category] * 100 + min($pos, 99),
                ];
            }
        }

        return $items;
    }

    /** Delivery modes that, as a provider part of their own, only qualify the one before. */
    private const MODE_RE = '/^(?:virtual|online|live\s+online|hybrid|physical|in[- ]person|e-?learning|classroom|self[- ]paced|on[- ]?site)$/i';

    /** Places that, as a provider part of their own, only say where the previous one runs. */
    private const PLACES = [
        'lagos', 'abuja', 'port harcourt', 'ibadan', 'nigeria', 'accra', 'ghana', 'nairobi', 'kenya', 'kigali', 'rwanda',
        'johannesburg', 'cape town', 'south africa', 'cairo', 'egypt', 'marrakech', 'morocco',
        'dubai', 'abu dhabi', 'uae', 'doha', 'qatar', 'riyadh', 'saudi arabia', 'istanbul', 'turkey',
        'london', 'uk', 'united kingdom', 'aberdeen', 'scotland', 'paris', 'france', 'berlin', 'germany',
        'amsterdam', 'netherlands', 'barcelona', 'spain', 'rome', 'italy', 'geneva', 'switzerland',
        'houston', 'texas', 'usa', 'us', 'united states', 'canada', 'toronto',
        'singapore', 'kuala lumpur', 'malaysia',
    ];

    /**
     * Provider parts that are only a note on the part before them — a mode
     * ("Virtual"), a place ("Dubai"), a price ("£3,500") or a bare date
     * ("June 9 - 10, 2026") — are folded back into it, so "X (Dec 15-19) /
     * Virtual / Y (May 20-22)" is two providers, not three.
     *
     * @param list<string> $providers
     *
     * @return list<string>
     */
    private static function foldProviderNotes(array $providers, int $year): array
    {
        $out = [];

        foreach ($providers as $part) {
            if ($out !== [] && self::isProviderNote($part, $year)) {
                $out[count($out) - 1] .= ' / ' . $part;

                continue;
            }
            $out[] = $part;
        }

        return $out;
    }

    private static function isProviderNote(string $part, int $year): bool
    {
        $p = trim($part);

        if (preg_match(self::MODE_RE, $p) === 1 || preg_match('/^(?:[£$€₦]|(?:ngn|usd|gbp|eur)\s?\d)/iu', $p) === 1) {
            return true;
        }

        $places = array_map(static fn (string $s): string => mb_strtolower(trim($s, " .\t")), explode(',', $p));
        if (array_filter($places, static fn (string $s): bool => !in_array($s, self::PLACES, true)) === []) {
            return true;
        }

        $parsed = PlanScheduleParser::parse($p, $year);

        return $parsed['start'] !== null && $parsed['provider'] === null;
    }

    /**
     * The category of one training. The column decides, except that a
     * conference listed under another heading is a conference, and in a mixed
     * "Conference / Certification" column a certification is a certification
     * and anything that is neither is a functional training.
     *
     * @param PlanColumn $col
     */
    private static function itemCategory(array $col, string $title): string
    {
        $isConference = preg_match('/\b(?:conference|summit|congress|exhibition|expo|forum|assembly)\b/i', $title) === 1;

        if ($col['mixed']) {
            return match (true) {
                preg_match('/certif/i', $title) === 1 => 'certification',
                $isConference                         => 'conference',
                default                               => 'functional',
            };
        }

        return $isConference ? 'conference' : $col['category'];
    }

    // --- cells -----------------------------------------------------------------

    /** Every cell as a trimmed string: dates as Y-m-d, CR/LF as LF, NBSP as space. */
    private static function cell(mixed $v): string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        if ($v instanceof \DateInterval) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'TRUE' : 'FALSE';
        }
        if ($v === null) {
            return '';
        }
        if (is_float($v) && floor($v) === $v) {
            $v = (int) $v;
        }

        return trim(PlanCellSplitter::normaliseSpaces(is_scalar($v) ? (string) $v : ''));
    }

    private static function oneLine(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** @param list<string> $cells */
    private static function blank(array $cells): bool
    {
        foreach ($cells as $c) {
            if ($c !== '') {
                return false;
            }
        }

        return true;
    }

    /** @return SheetWarning */
    private static function warn(?int $row, string $code, string $message): array
    {
        return ['row' => $row, 'code' => $code, 'message' => $message];
    }
}
