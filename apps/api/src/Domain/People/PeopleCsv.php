<?php

declare(strict_types=1);

namespace Central\Api\Domain\People;

/**
 * Reads a spreadsheet of people into fields this application knows.
 *
 * Pure, and separate from the action, because header-mapping is where an
 * import quietly goes wrong: a file whose first column is called "E-mail
 * Address" should not import four hundred people with no address. Every
 * mapping decision this makes is reported back so somebody can see it before
 * anything is written.
 *
 * Delimiters are sniffed rather than assumed. A comma is the common case, but
 * a spreadsheet saved in a locale that uses the comma as a decimal separator
 * writes semicolons, and the legacy importer read those files as one column.
 */
final readonly class PeopleCsv
{
    /**
     * More than this in one file and it is a migration, not an import.
     *
     * The limit exists because the whole file is held in memory to be shown
     * back for checking. A larger load should be a console command with a
     * stream, not a browser tab waiting on a request.
     */
    public const MAX_ROWS = 2000;

    /**
     * What a column may be called, per field.
     *
     * Compared after lowercasing and stripping everything that is not a
     * letter, so "First Name", "first_name", "FirstName" and "firstname" are
     * one entry rather than four.
     *
     * @var array<string, list<string>>
     */
    private const COLUMNS = [
        'email'      => ['email', 'emailaddress', 'mail', 'workemail'],
        'firstName'  => ['firstname', 'givenname', 'forename'],
        'lastName'   => ['lastname', 'surname', 'familyname'],
        'phone'      => ['phone', 'phonenumber', 'mobile', 'telephone'],
        'branch'     => ['branch', 'branchname', 'office', 'location'],
        'department' => ['department', 'departmentname'],
        'team'       => ['team', 'teamname'],
        'level'      => ['level', 'levelname', 'grade'],
        'jobTitle'   => ['jobtitle', 'title', 'position', 'role'],
        'manager'    => ['manager', 'manageremail', 'reportsto', 'linemanager'],
        'hireDate'   => ['hiredate', 'startdate', 'joined', 'joindate'],
        'roles'      => ['roles', 'accessroles', 'permissions', 'usertype'],
        'locale'     => ['locale', 'language'],
    ];

    /**
     * @param list<array{line: int, values: array<string, string>}> $rows
     * @param array<string, string>                                 $mapping field to the column it was read from
     * @param list<string>                                          $unmapped columns nothing was read from
     */
    private function __construct(
        public array $rows,
        public array $mapping,
        public array $unmapped,
        public bool $truncated,
    ) {
    }

    /**
     * @throws \RuntimeException when the file has no usable header row
     */
    public static function read(string $contents): self
    {
        // A byte-order mark on the first header turns "email" into "\xEF\xBB\xBFemail",
        // which matches nothing. Excel writes one by default.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $contents = trim($contents);

        if ($contents === '') {
            throw new \RuntimeException('That file is empty.');
        }

        $handle = fopen('php://memory', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('The file could not be read.');
        }

        fwrite($handle, $contents);
        rewind($handle);

        $delimiter = self::sniff($contents);
        $header    = fgetcsv($handle, escape: '');

        if ($header === false || $header === [null]) {
            fclose($handle);

            throw new \RuntimeException('That file has no header row.');
        }

        // fgetcsv cannot be told the delimiter after the fact, so the header
        // is re-read with the sniffed one.
        rewind($handle);
        $header = fgetcsv($handle, separator: $delimiter, escape: '') ?: [];

        [$mapping, $unmapped] = self::map($header);

        if (!array_key_exists('email', $mapping)) {
            fclose($handle);

            throw new \RuntimeException(
                'No column in that file looks like an email address, and an import needs one to '
                . 'tell people apart.',
            );
        }

        $rows      = [];
        $line      = 1;
        $truncated = false;

        while (($values = fgetcsv($handle, separator: $delimiter, escape: '')) !== false) {
            ++$line;

            if ($values === [null]) {
                continue; // A blank line, which spreadsheets leave at the end.
            }

            if (count($rows) >= self::MAX_ROWS) {
                $truncated = true;

                break;
            }

            $row = self::readRow($header, $values, $mapping);

            // Every field empty is a spacer row, not a person with no name.
            if (implode('', $row) !== '') {
                $rows[] = ['line' => $line, 'values' => $row];
            }
        }

        fclose($handle);

        return new self($rows, $mapping, $unmapped, $truncated);
    }

    /**
     * The delimiter, from whichever candidate appears most on the first line.
     */
    private static function sniff(string $contents): string
    {
        $firstLine = strtok($contents, "\r\n") ?: $contents;
        $counts    = [];

        foreach ([',', ';', "\t", '|'] as $candidate) {
            $counts[$candidate] = substr_count($firstLine, $candidate);
        }

        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] === 0 ? ',' : (string) $best;
    }

    /**
     * @param list<string|null> $header
     *
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private static function map(array $header): array
    {
        $mapping  = [];
        $unmapped = [];

        foreach ($header as $column) {
            $label     = trim((string) $column);
            $canonical = self::normalise($label);
            $matched   = null;

            foreach (self::COLUMNS as $field => $aliases) {
                if (in_array($canonical, $aliases, true) && !array_key_exists($field, $mapping)) {
                    $matched = $field;

                    break;
                }
            }

            if ($matched === null) {
                if ($label !== '') {
                    $unmapped[] = $label;
                }

                continue;
            }

            $mapping[$matched] = $label;
        }

        return [$mapping, $unmapped];
    }

    /**
     * @param list<string|null>     $header
     * @param list<string|null>     $values
     * @param array<string, string> $mapping
     *
     * @return array<string, string>
     */
    private static function readRow(array $header, array $values, array $mapping): array
    {
        $byColumn = [];

        foreach ($header as $index => $column) {
            $byColumn[trim((string) $column)] = trim((string) ($values[$index] ?? ''));
        }

        $row = [];

        foreach ($mapping as $field => $column) {
            $row[$field] = $byColumn[$column] ?? '';
        }

        return $row;
    }

    private static function normalise(string $label): string
    {
        return strtolower((string) preg_replace('/[^a-z]/i', '', $label));
    }
}
