<?php

declare(strict_types=1);

namespace Central\Api\Application\Import;

use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Reads a staff placement sheet: who sits where, keyed by email.
 *
 * Pure parsing — no database, no lookups. {@see PlacementImporter} decides
 * what the rows mean against the organisation's people.
 *
 * Columns are found by their header text, never by position, so a sheet whose
 * columns were reordered or that gained a "Check" column still reads the same.
 * Row 1 is the header. A blank cell is "no change", which is why a value that
 * is absent from `values` and a value that is empty are the same thing here.
 */
final class PlacementWorkbook
{
    public const MAX_ROWS = 5000;

    /**
     * Header rules, checked in order; a header takes the first rule it matches
     * and a field takes the first header that matches it.
     *
     * @var array<string, string>
     */
    private const COLUMNS = [
        'email'       => '/e-?mail/i',
        'employeeId'  => '/staff\s*id|employee\s*id/i',
        'jobGroup'    => '/job\s*group/i',
        'salaryGroup' => '/salary\s*group/i',
        'jobCategory' => '/job\s*category/i',
        'jobTitle'    => '/job\s*title|designation/i',
        'department'  => '/^department$/i',
        'division'    => '/function|division/i',
        'name'        => '/^names?$/i',
    ];

    /** Read and deliberately dropped: a serial number and a reviewer's tick. */
    private const IGNORED = '/^(s\s*\/\s*n|s\.?\s*n\.?|serial(\s*no\.?)?|check)$/i';

    /** The fields a row can change. Email and name only identify it. */
    public const FIELDS = ['employeeId', 'department', 'jobTitle', 'division', 'jobGroup', 'salaryGroup', 'jobCategory'];

    /**
     * Opens a workbook and parses one placement sheet out of it.
     *
     * @throws \InvalidArgumentException when the file cannot be read, the named
     *                                   sheet does not exist, or no sheet looks
     *                                   like a placement list
     *
     * @return array<string, mixed> see {@see parse()}
     */
    public static function read(string $path, ?string $sheet = null): array
    {
        return self::parse(self::load($path), $sheet);
    }

    /**
     * Every sheet of the workbook, as rows of normalised cell text.
     *
     * @return list<array{name: string, rows: list<list<string>>}>
     */
    public static function load(string $path): array
    {
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));

        try {
            $reader->open($path);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('The workbook could not be opened as .xlsx.', 0, $e);
        }

        $sheets = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = [];

                foreach ($sheet->getRowIterator() as $row) {
                    // The header plus the row limit, and one more so a sheet
                    // over the limit is reported as truncated.
                    if (count($rows) > self::MAX_ROWS + 1) {
                        break;
                    }

                    $rows[] = array_map(self::cell(...), array_values($row->toArray()));
                }

                $sheets[] = ['name' => $sheet->getName(), 'rows' => $rows];
            }
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('The workbook could not be read.', 0, $e);
        } finally {
            $reader->close();
        }

        return $sheets;
    }

    /**
     * Parses already-loaded sheets. Split from {@see read()} so the rules can
     * be exercised without a file.
     *
     * @param list<array{name: string, rows: list<list<string>>}> $sheets
     *
     * @return array{
     *     sheets: list<array{name: string, index: int, kind: string, rows: int}>,
     *     sheet: string,
     *     columns: array<string, string>,
     *     ignored: list<string>,
     *     unmapped: list<string>,
     *     truncated: bool,
     *     rows: list<array{row: int, email: string, name: string, values: array<string, string>, error: ?string}>
     * }
     */
    public static function parse(array $sheets, ?string $wanted = null): array
    {
        $summaries = [];
        $chosen    = null;

        foreach ($sheets as $index => $sheet) {
            $kind = self::classify($sheet['rows']);

            $summaries[] = [
                'name'  => $sheet['name'],
                'index' => $index,
                'kind'  => $kind,
                'rows'  => self::dataRowCount($sheet['rows']),
            ];

            if ($wanted !== null && $wanted !== '') {
                if ($sheet['name'] === $wanted || trim($sheet['name']) === trim($wanted)) {
                    // A named sheet must still be a placement sheet: a plan
                    // sheet's DESIGNATION / Department / Division columns
                    // must never be written to profiles.
                    if ($kind !== 'placement') {
                        throw new \InvalidArgumentException(sprintf(
                            'The sheet "%s" is not a staff placement sheet (it needs an email and a staff ID column).',
                            $sheet['name'],
                        ));
                    }
                    $chosen ??= $sheet;
                }
            } elseif ($kind === 'placement') {
                $chosen ??= $sheet;
            }
        }

        if ($chosen === null) {
            throw new \InvalidArgumentException($wanted !== null && $wanted !== ''
                ? sprintf('There is no sheet called "%s" in this workbook.', $wanted)
                : 'No sheet in this workbook has both an email and a staff ID column.');
        }

        $rows   = $chosen['rows'];
        $header = $rows[0] ?? [];
        $map    = self::mapColumns($header);

        if (!isset($map['columns']['email'])) {
            throw new \InvalidArgumentException(sprintf('The sheet "%s" has no email column.', $chosen['name']));
        }

        $truncated = count($rows) - 1 > self::MAX_ROWS;
        $parsed    = [];
        $seen      = [];

        foreach (array_slice($rows, 1, self::MAX_ROWS) as $offset => $cells) {
            if (self::isBlank($cells)) {
                continue;
            }

            $line   = $offset + 2;
            // Normalised exactly as ImportedPersonCreator::assess() and the
            // learning-plan reader do (all whitespace out, multibyte lower-case),
            // so a created person and their placement row share one key.
            $email  = mb_strtolower(trim((string) preg_replace('/\s+/u', '', self::value($cells, $map['columns']['email']))));
            $name   = isset($map['columns']['name']) ? self::value($cells, $map['columns']['name']) : '';
            $values = [];

            foreach (self::FIELDS as $field) {
                if (!isset($map['columns'][$field])) {
                    continue;
                }

                $value = self::value($cells, $map['columns'][$field]);

                if ($value !== '') {
                    $values[$field] = $value;
                }
            }

            $error = null;

            if ($email === '') {
                $error = 'No email address on this row.';
            } elseif (isset($seen[$email])) {
                // The first occurrence wins; a second row for the same person
                // would otherwise overwrite the first with whichever came last.
                $error = sprintf('This address is already on row %d of the sheet.', $seen[$email]);
            } else {
                $seen[$email] = $line;
            }

            $parsed[] = ['row' => $line, 'email' => $email, 'name' => $name, 'values' => $values, 'error' => $error];
        }

        $columns = [];

        foreach ($map['columns'] as $field => $index) {
            $columns[$field] = $header[$index] ?? '';
        }

        return [
            'sheets'    => $summaries,
            'sheet'     => $chosen['name'],
            'columns'   => $columns,
            'ignored'   => $map['ignored'],
            'unmapped'  => $map['unmapped'],
            'truncated' => $truncated,
            'rows'      => $parsed,
        ];
    }

    /**
     * @param list<list<string>> $rows
     */
    public static function classify(array $rows): string
    {
        if (self::dataRowCount($rows) === 0 && self::isBlank($rows[0] ?? [])) {
            return 'empty';
        }

        $header = implode(' | ', $rows[0] ?? []);

        if (preg_match('/staff\s*id|employee\s*id/i', $header) === 1 && preg_match('/e-?mail/i', $header) === 1) {
            return 'placement';
        }

        return 'other';
    }

    /**
     * @param list<string> $header
     *
     * @return array{columns: array<string, int>, ignored: list<string>, unmapped: list<string>}
     */
    private static function mapColumns(array $header): array
    {
        $columns  = [];
        $ignored  = [];
        $unmapped = [];

        foreach ($header as $index => $text) {
            if ($text === '') {
                continue;
            }

            if (preg_match(self::IGNORED, $text) === 1) {
                $ignored[] = $text;

                continue;
            }

            $taken = false;

            foreach (self::COLUMNS as $field => $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    if (!isset($columns[$field])) {
                        $columns[$field] = $index;
                    } else {
                        $ignored[] = $text;
                    }
                    $taken = true;

                    break;
                }
            }

            if (!$taken) {
                $unmapped[] = $text;
            }
        }

        return ['columns' => $columns, 'ignored' => $ignored, 'unmapped' => $unmapped];
    }

    /**
     * @param list<list<string>> $rows
     */
    private static function dataRowCount(array $rows): int
    {
        $count = 0;

        foreach (array_slice($rows, 1) as $cells) {
            if (!self::isBlank($cells)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param list<string> $cells
     */
    private static function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * One cell as single-line text: placement values are names and codes, so
     * a stray line break or double space is noise, not meaning.
     *
     * @param list<string> $cells
     */
    private static function value(array $cells, int $index): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $cells[$index] ?? ''));
    }

    /**
     * A raw cell as text: dates as Y-m-d, whole numbers without a decimal
     * point (a numeric staff ID must not become "1024.0"), line endings and
     * non-breaking spaces normalised.
     */
    public static function cell(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return floor($value) === $value && abs($value) < 1e15
                ? number_format($value, 0, '.', '')
                : (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'TRUE' : 'FALSE';
        }

        if (!is_string($value)) {
            return '';
        }

        $value = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], $value);

        return trim($value);
    }
}
