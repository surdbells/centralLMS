<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * A report as a CSV a spreadsheet will open correctly.
 *
 * Pure, and worth its own class for two reasons that are easy to get wrong.
 *
 * **Formula injection.** A cell beginning `=`, `+`, `-` or `@` is executed as
 * a formula by Excel, LibreOffice and Sheets when the file is opened. Report
 * rows carry names, course titles and free-text reasons — all typed by people,
 * some of them by the learner being reported on. `=HYPERLINK("http://…"&A1)`
 * in a course title becomes a live exfiltration link in an administrator's
 * spreadsheet. Every text cell is prefixed with a tab, which the spreadsheet
 * strips on display but which stops the parse.
 *
 * **The byte-order mark.** Excel on Windows reads a UTF-8 file without one as
 * the local codepage, so every accented name in the export is mangled. One
 * three-byte prefix is the whole fix.
 */
final readonly class CsvWriter
{
    private const BOM = "\xEF\xBB\xBF";

    /** The characters a spreadsheet treats as the start of a formula. */
    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public function write(ReportResult $result): string
    {
        $columns = $result->columns;

        // A summary or a breakdown has no columns; its figures are still
        // worth exporting, so they become a two-column key/value sheet.
        if ($columns === []) {
            return self::BOM . $this->fromTotals($result);
        }

        $out = fopen('php://temp', 'r+');

        if ($out === false) {
            throw new \RuntimeException('Could not open a buffer to write the export.');
        }

        fputcsv($out, array_map(
            static fn (ReportColumn $c): string => $c->label,
            $columns,
        ), escape: '');

        foreach ($result->rows as $row) {
            $line = [];

            foreach ($columns as $column) {
                $line[] = $this->cell($row[$column->key] ?? null, $column->type);
            }

            fputcsv($out, $line, escape: '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return self::BOM . $csv;
    }

    /**
     * A filename that says what it is and when it was taken.
     *
     * The date matters: two exports of the same report a month apart are
     * different documents, and `report.csv` twice in a downloads folder is one
     * of them.
     */
    public function filename(ReportDefinition $definition, \DateTimeImmutable $at): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '-', $definition->name));

        return sprintf('%s-%s.csv', trim($slug, '-'), $at->format('Y-m-d'));
    }

    private function fromTotals(ReportResult $result): string
    {
        $out = fopen('php://temp', 'r+');

        if ($out === false) {
            throw new \RuntimeException('Could not open a buffer to write the export.');
        }

        fputcsv($out, ['Measure', 'Value'], escape: '');

        foreach ($result->totals as $label => $value) {
            fputcsv($out, [$this->humanise($label), $this->cell($value, ReportValueType::Text)], escape: '');
        }

        foreach ($result->series as $point) {
            $label = (string) ($point['label'] ?? '');
            $value = $point['value'] ?? '';

            fputcsv($out, [$this->cell($label, ReportValueType::Text), $this->cell($value, ReportValueType::Number)], escape: '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }

    private function cell(mixed $value, ReportValueType $type): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        // A number cannot start a formula, and quoting it would make the
        // spreadsheet treat it as text and refuse to sum the column.
        if ($type->isNumeric() && is_numeric($value)) {
            return (string) $value;
        }

        return $this->defuse((string) $value);
    }

    /**
     * Stops a spreadsheet reading a cell as a formula.
     */
    private function defuse(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return in_array($value[0], self::FORMULA_PREFIXES, true) ? "\t" . $value : $value;
    }

    private function humanise(string $key): string
    {
        return ucfirst(strtolower(trim(
            (string) preg_replace('/(?<!^)[A-Z]/', ' $0', $key),
        )));
    }
}
