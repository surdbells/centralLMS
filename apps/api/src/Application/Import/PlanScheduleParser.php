<?php

declare(strict_types=1);

namespace Central\Api\Application\Import;

/**
 * Reads the provider and the planned dates out of one "provider & date" part of
 * a Learning Plan cell.
 *
 *   "Petroknowledge, UK (Dec 29 2025 - 2 Jan 2026)"
 *       → provider "Petroknowledge, UK", schedule "Dec 29 2025 - 2 Jan 2026",
 *         2025-12-29 … 2026-01-02
 *   "NOG, Abuja (June 28 - July 2, 2026)" → 2026-06-28 … 2026-07-02
 *   "Reliance Infosystems"                 → provider only
 *   "Glomacs (TBA)"                        → provider, schedule "TBA", no dates
 *
 * The date is looked for in the parenthesised groups first, then in the text
 * outside them. A year that is not written is the sheet's year. Nothing here
 * touches the database.
 */
final class PlanScheduleParser
{
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    private const FULL_NAMES = [
        'january', 'february', 'march', 'april', 'may', 'june', 'july',
        'august', 'september', 'october', 'november', 'december',
    ];

    private static ?string $monthRe = null;

    /**
     * @return array{provider: ?string, scheduleText: ?string, start: ?string, end: ?string, warnings: list<string>}
     */
    public static function parse(?string $providerPart, int $sheetYear): array
    {
        $result = ['provider' => null, 'scheduleText' => null, 'start' => null, 'end' => null, 'warnings' => []];

        if ($providerPart === null) {
            return $result;
        }

        $text = self::normalise($providerPart);
        if ($text === '') {
            return $result;
        }

        $groups = self::groups($text);
        $view   = self::mask($text);

        // 1) A date inside a parenthesised group. Day-level dates are looked
        //    for in every group before a month range, quarter or bare month,
        //    so "(August/September 2025) (18-19, Aug. 2025)" keeps the days.
        foreach ([true, false] as $precise) {
            foreach ($groups as [$open, $close]) {
                $inner = substr($view, $open + 1, $close - $open - 1);
                $match = self::findDate($inner, $sheetYear, $precise);

                if ($match !== null) {
                    $result['scheduleText'] = self::tidy(substr($text, $open + 1, $close - $open - 1));
                    $result['provider']     = self::tidyProvider(substr($text, 0, $open) . ' ' . substr($text, $close + 1));

                    return self::withDates($result, $match, $sheetYear);
                }
            }
        }

        // 2) A date in the text outside the groups ("LBS run: July 19, 2025",
        //    "Oct 21 (3 days)"). No group holds one, so a match starts outside.
        $match = self::findDate($view, $sheetYear);
        if ($match !== null) {
            $from = $match['offset'];
            $to   = $match['offset'] + $match['length'];

            $result['scheduleText'] = self::tidy(substr($text, $from, $to - $from));
            $result['provider']     = self::tidyProvider(substr($text, 0, $from) . ' ' . substr($text, $to));

            return self::withDates($result, $match, $sheetYear);
        }

        // 3) No date: keep the last group as text ("TBA", "eLearning") —
        //    unless it is just a contact address or link, which stays with
        //    the provider.
        $groups = array_values(array_filter(
            $groups,
            static fn (array $g): bool => trim(str_replace('#', '', substr($view, $g[0] + 1, $g[1] - $g[0] - 1))) !== '',
        ));

        if ($groups !== []) {
            [$open, $close] = $groups[count($groups) - 1];
            $inner = self::tidy(substr($text, $open + 1, $close - $open - 1));

            $result['scheduleText'] = $inner === '' ? null : $inner;
            $result['provider']     = self::tidyProvider(substr($text, 0, $open) . ' ' . substr($text, $close + 1));

            return $result;
        }

        $result['provider'] = self::tidyProvider($text);

        return $result;
    }

    /**
     * Normalised text: dashes unified, whitespace collapsed, "()" dropped,
     * "Augus" fixed, ordinal suffixes on dates removed ("1st August").
     */
    public static function normalise(string $text): string
    {
        $text = PlanCellSplitter::normaliseSpaces($text);
        $text = str_replace(["\u{2013}", "\u{2014}", "\u{2012}", "\u{2212}"], '-', $text);
        $text = (string) preg_replace('/\(\s*\)/', ' ', $text);
        $text = (string) preg_replace('/\bAugus\b/i', 'August', $text);
        $text = (string) preg_replace('/\bSepember\b/i', 'September', $text);

        // Ordinal suffixes go from day numbers in a date — after a month
        // ("July 2nd"), before a month ("1st of August") or before a range
        // dash ("23rd - 25 April") — but not from names ("21st Pipeline
        // Technology Conference").
        $m    = self::monthRe();
        $text = (string) preg_replace('/\b(' . $m . ')\.?\s+(\d{1,2})(?:st|nd|rd|th)\b/i', '$1 $2', $text);
        $text = (string) preg_replace(
            '/(?<!\d)(\d{1,2})(?:st|nd|rd|th)\b(?=\s*(?:-|to\b|till\b|until\b|,?\s*(?:of\s+)?(?:' . $m . ')\b))/i',
            '$1',
            $text,
        );
        $text = (string) preg_replace('/(?<!\d)(\d{1,2})\s+of\s+(?=(?:' . $m . ')\b)/i', '$1 ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    // --- finding a date --------------------------------------------------------

    /** Patterns that name a whole month, quarter or month range — no day. */
    private const COARSE_KINDS = ['m_m_y', 'quarter', 'my'];

    /**
     * The first pattern (in priority order) that matches anywhere in $s.
     * $precise true = only day-level patterns, false = only the coarse ones
     * (month range, quarter, month), null = all of them.
     *
     * @return array{offset: int, length: int, start: string, end: ?string, explicitYear: bool}|null
     */
    private static function findDate(string $s, int $year, ?bool $precise = null): ?array
    {
        $M  = '(' . self::monthRe() . ')\.?';
        $D  = '(?<!\d)(\d{1,2})(?!\d)';
        $Y  = '(\d{4})';
        $OY = '(?:,?\s*' . $Y . ')?';
        $to = '\s*(?:-|to|till|until)\s*';

        $patterns = [
            // 1. cross-year: "Dec 29 2025 - 2 Jan 2026", "29 Dec 2025 - 2 Jan 2026", "Dec 29, 2025 - Jan 2, 2026"
            ['/' . $M . '\s+' . $D . ',?\s+' . $Y . $to . $D . '\s+' . $M . ',?\s+' . $Y . '/i', 'cross_mdy_dmy'],
            ['/' . $D . '\s+' . $M . ',?\s+' . $Y . $to . $D . '\s+' . $M . ',?\s+' . $Y . '/i', 'cross_dmy_dmy'],
            ['/' . $M . '\s+' . $D . ',?\s+' . $Y . $to . $M . '\s+' . $D . ',?\s+' . $Y . '/i', 'cross_mdy_mdy'],
            // 2. "June 28 - July 2, 2026"
            ['/' . $M . '\s+' . $D . $to . $M . '\s+' . $D . $OY . '/i', 'md_md'],
            // 3. "28 June - 2 July 2026"
            ['/' . $D . '\s+' . $M . $to . $D . '\s+' . $M . $OY . '/i', 'dm_dm'],
            // 4. "July 28 - 1 August 2025"
            ['/' . $M . '\s+' . $D . $to . $D . '\s+' . $M . $OY . '/i', 'md_dm'],
            // 4b. "30 June - July 04, 2025"
            ['/' . $D . '\s+' . $M . $to . $M . '\s+' . $D . $OY . '/i', 'dm_md'],
            // 5. "June 15 - 19, 2025"
            ['/' . $M . '\s+' . $D . $to . $D . $OY . '/i', 'md_d'],
            // 6. "27 - 31 Oct 2025", "18-19, Aug. 2025"
            ['/' . $D . $to . $D . ',?\s*' . $M . $OY . '/i', 'd_dm'],
            // 7. "August / December 2025"
            ['/' . $M . '\s*(?:\/|-|to)\s*' . $M . ',?\s+' . $Y . '/i', 'm_m_y'],
            // 8. "Q4 2025"
            ['/\bQ([1-4])\s*,?\s*' . $Y . '/i', 'quarter'],
            // 9. "Oct 21, 2026" (+ "(3 days)")
            ['/' . $M . '\s+' . $D . $OY . '/i', 'md'],
            // 10. "21 Oct 2026"
            ['/' . $D . ',?\s+' . $M . $OY . '/i', 'dm'],
            // 11. "October 2026"
            ['/' . $M . ',?\s+' . $Y . '/i', 'my'],
        ];

        foreach ($patterns as [$re, $kind]) {
            if ($precise !== null && $precise === in_array($kind, self::COARSE_KINDS, true)) {
                continue;
            }
            if (preg_match_all($re, $s, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($all as $m) {
                $hit = self::interpret($kind, $m, $year, $s);
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, array{0: string, 1: int}> $m
     *
     * @return array{offset: int, length: int, start: string, end: ?string, explicitYear: bool}|null
     */
    private static function interpret(string $kind, array $m, int $sheetYear, string $s): ?array
    {
        $g = static fn (int $i): string => isset($m[$i]) && $m[$i][1] >= 0 ? $m[$i][0] : '';
        $y = static fn (string $v): ?int => $v === '' ? null : (int) $v;

        $offset = $m[0][1];
        $length = strlen($m[0][0]);
        $start  = null;
        $end    = null;
        $explicit = false;

        switch ($kind) {
            case 'cross_mdy_dmy':
                $start = self::ymd((int) $g(3), self::month($g(1)), (int) $g(2));
                $end   = self::ymd((int) $g(6), self::month($g(5)), (int) $g(4));
                $explicit = true;
                break;
            case 'cross_dmy_dmy':
                $start = self::ymd((int) $g(3), self::month($g(2)), (int) $g(1));
                $end   = self::ymd((int) $g(6), self::month($g(5)), (int) $g(4));
                $explicit = true;
                break;
            case 'cross_mdy_mdy':
                $start = self::ymd((int) $g(3), self::month($g(1)), (int) $g(2));
                $end   = self::ymd((int) $g(6), self::month($g(4)), (int) $g(5));
                $explicit = true;
                break;
            case 'md_md':
                $explicit = $g(5) !== '';
                [$sm, $em] = [self::month($g(1)), self::month($g(3))];
                [$sy, $ey] = self::rangeYears($y($g(5)), $sheetYear, $sm, $em);
                $start = self::ymd($sy, $sm, (int) $g(2));
                $end   = self::ymd($ey, $em, (int) $g(4));
                break;
            case 'dm_dm':
                $explicit = $g(5) !== '';
                [$sm, $em] = [self::month($g(2)), self::month($g(4))];
                [$sy, $ey] = self::rangeYears($y($g(5)), $sheetYear, $sm, $em);
                $start = self::ymd($sy, $sm, (int) $g(1));
                $end   = self::ymd($ey, $em, (int) $g(3));
                break;
            case 'md_dm':
                $explicit = $g(5) !== '';
                [$sm, $em] = [self::month($g(1)), self::month($g(4))];
                [$sy, $ey] = self::rangeYears($y($g(5)), $sheetYear, $sm, $em);
                $start = self::ymd($sy, $sm, (int) $g(2));
                $end   = self::ymd($ey, $em, (int) $g(3));
                break;
            case 'dm_md':
                $explicit = $g(5) !== '';
                [$sm, $em] = [self::month($g(2)), self::month($g(3))];
                [$sy, $ey] = self::rangeYears($y($g(5)), $sheetYear, $sm, $em);
                $start = self::ymd($sy, $sm, (int) $g(1));
                $end   = self::ymd($ey, $em, (int) $g(4));
                break;
            case 'md_d':
                $yr    = $y($g(4)) ?? $sheetYear;
                $explicit = $g(4) !== '';
                $start = self::ymd($yr, self::month($g(1)), (int) $g(2));
                $end   = self::ymd($yr, self::month($g(1)), (int) $g(3));
                break;
            case 'd_dm':
                $yr    = $y($g(4)) ?? $sheetYear;
                $explicit = $g(4) !== '';
                $start = self::ymd($yr, self::month($g(3)), (int) $g(1));
                $end   = self::ymd($yr, self::month($g(3)), (int) $g(2));
                break;
            case 'm_m_y':
                $yr    = (int) $g(3);
                $explicit = true;
                $start = self::ymd($yr, self::month($g(1)), 1);
                $end   = self::monthEnd($yr, self::month($g(2)));
                break;
            case 'quarter':
                $q     = (int) $g(1);
                $yr    = (int) $g(2);
                $explicit = true;
                $start = self::ymd($yr, ($q - 1) * 3 + 1, 1);
                $end   = self::monthEnd($yr, $q * 3);
                break;
            case 'md':
                $yr    = $y($g(3)) ?? $sheetYear;
                $explicit = $g(3) !== '';
                $start = self::ymd($yr, self::month($g(1)), (int) $g(2));
                break;
            case 'dm':
                $yr    = $y($g(3)) ?? $sheetYear;
                $explicit = $g(3) !== '';
                $start = self::ymd($yr, self::month($g(2)), (int) $g(1));
                break;
            case 'my':
                $yr    = (int) $g(2);
                $explicit = true;
                $start = self::ymd($yr, self::month($g(1)), 1);
                $end   = self::monthEnd($yr, self::month($g(1)));
                break;
        }

        if ($start === null) {
            return null;
        }

        // A single day followed by "(3 days)" / ", 3 days" runs that long.
        if ($end === null && ($kind === 'md' || $kind === 'dm')) {
            $rest = substr($s, $offset + $length);
            $daysRe = '/^(?:\s*\(\s*(\d{1,3})\s*(?:working\s+)?days?\s*\)|\s*,\s*(\d{1,3})\s*(?:working\s+)?days?\b)/i';
            if (preg_match($daysRe, $rest, $d) === 1) {
                $d[1] = $d[1] !== '' ? $d[1] : ($d[2] ?? '0');
                $days = (int) $d[1];
                if ($days > 1) {
                    $end = (new \DateTimeImmutable($start))->modify('+' . ($days - 1) . ' days')->format('Y-m-d');
                }
                $length += strlen($d[0]);
            }
        }

        // Pattern matched but a day was impossible (Feb 31) — not a date.
        if ($kind !== 'md' && $kind !== 'dm' && $kind !== 'my' && $end === null) {
            return null;
        }

        return ['offset' => $offset, 'length' => $length, 'start' => $start, 'end' => $end, 'explicitYear' => $explicit];
    }

    /**
     * @param array{provider: ?string, scheduleText: ?string, start: ?string, end: ?string, warnings: list<string>} $result
     * @param array{offset: int, length: int, start: string, end: ?string, explicitYear: bool}                    $match
     *
     * @return array{provider: ?string, scheduleText: ?string, start: ?string, end: ?string, warnings: list<string>}
     */
    private static function withDates(array $result, array $match, int $sheetYear): array
    {
        $result['start'] = $match['start'];
        $result['end']   = $match['end'];

        if ($result['end'] !== null && $result['end'] < $result['start']) {
            $result['end']        = null;
            $result['warnings'][] = 'end_before_start';
        }

        if ((int) substr($match['start'], 0, 4) !== $sheetYear) {
            $result['warnings'][] = 'date_year_mismatch';
        }

        return $result;
    }

    // --- helpers ---------------------------------------------------------------

    /**
     * Outermost parenthesised groups, as [openOffset, closeOffset].
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function groups(string $text): array
    {
        $groups = [];
        $depth  = 0;
        $open   = -1;
        $len    = strlen($text);

        for ($i = 0; $i < $len; $i++) {
            if ($text[$i] === '(') {
                if ($depth === 0) {
                    $open = $i;
                }
                $depth++;
            } elseif ($text[$i] === ')' && $depth > 0) {
                $depth--;
                if ($depth === 0) {
                    $groups[] = [$open, $i];
                }
            }
        }

        return $groups;
    }

    /** URLs and e-mail addresses blanked out (same length), so "…/may-2025" is not a date. */
    private static function mask(string $text): string
    {
        return (string) preg_replace_callback(
            '~(?:https?://|www\.)\S+|[^\s@()]+@[^\s@()]+~i',
            static fn (array $m): string => str_repeat('#', strlen($m[0])),
            $text,
        );
    }

    private static function tidy(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    private static function tidyProvider(string $s): ?string
    {
        $s = self::tidy($s);
        $s = (string) preg_replace('/\s+([,;:])/', '$1', $s);
        $s = trim($s, " ,;-:/\t");

        return $s === '' ? null : $s;
    }

    /**
     * The years of a two-month range with at most one year written. When the
     * end month comes before the start month the range crosses New Year:
     * "Dec 28 - Jan 3, 2026" is 2025-12-28 … 2026-01-03, and with no year
     * at all it starts in the sheet's year and ends in the next.
     *
     * @return array{0: int, 1: int}
     */
    private static function rangeYears(?int $written, int $sheetYear, int $startMonth, int $endMonth): array
    {
        if ($endMonth >= $startMonth) {
            $yr = $written ?? $sheetYear;

            return [$yr, $yr];
        }

        return $written !== null ? [$written - 1, $written] : [$sheetYear, $sheetYear + 1];
    }

    private static function month(string $name): int
    {
        return self::MONTHS[strtolower(substr($name, 0, 3))] ?? 0;
    }

    private static function ymd(int $year, int $month, int $day): ?string
    {
        if ($year < 2000 || $year > 2100 || !checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private static function monthEnd(int $year, int $month): ?string
    {
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            return null;
        }

        return (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('Y-m-t');
    }

    /** Any 3+ letter prefix of a month name ("Sep", "Sept", "Augus", "Novem"). */
    private static function monthRe(): string
    {
        if (self::$monthRe !== null) {
            return self::$monthRe;
        }

        $alts = [];
        foreach (self::FULL_NAMES as $full) {
            $re = '';
            for ($i = strlen($full); $i > 3; $i--) {
                $re = '(?:' . substr($full, $i - 1, 1) . $re . ')?';
            }
            $alts[] = substr($full, 0, 3) . $re;
        }

        return self::$monthRe = '(?:' . implode('|', $alts) . ')(?![a-z])';
    }
}
