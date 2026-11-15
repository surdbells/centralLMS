<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Application\Import;

use Central\Api\Application\Import\PlacementWorkbook;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Reading a placement sheet. Every workbook here is synthetic and built in the
 * test — fake names, example.test addresses.
 */
#[CoversClass(PlacementWorkbook::class)]
final class PlacementWorkbookTest extends TestCase
{
    private ?string $path = null;

    protected function tearDown(): void
    {
        if ($this->path !== null) {
            @unlink($this->path);
        }
    }

    #[Test]
    public function it_finds_the_placement_sheet_and_maps_columns_by_header(): void
    {
        $parsed = PlacementWorkbook::read($this->workbook([
            'Plan 2026' => [
                ['S/N', 'NAMES', 'Email Address', 'Functional Learning'],
                [1, 'Ada Test', 'ada@example.test', 'Excel'],
            ],
            'Staff List - Placement' => [
                ['S/N', 'Names', 'Email address', 'Staff ID', 'Job Group', 'Salary Group', 'Job Category', 'Job Title', 'Department', 'Function / Division', 'Check'],
                [1, 'Ada Test', ' Ada@Example.TEST ', 'XX-0001', 'JG 13', 'SG 13', 'Professional', "Analyst\n", "Commercial &\u{00A0}New  Business", 'Midsun', 'OK'],
                [2, 'Bo Test', 'bo@example.test', '', '', '', '', '', '', '', ''],
                [null, null, null, null, null, null, null, null, null, null, null],
                [3, 'Cy Test', 'ada@example.test', 'XX-0003', '', '', '', '', '', '', ''],
                [4, 'Di Test', '', 'XX-0004', '', '', '', '', '', '', ''],
                [5, 'Ed Test', 'ed@example.test', 1024, '', '', '', '', '', '', ''],
            ],
            'Sheet5' => [],
        ]));

        self::assertSame('Staff List - Placement', $parsed['sheet']);
        self::assertSame(
            ['other', 'placement', 'empty'],
            array_column($parsed['sheets'], 'kind'),
        );
        self::assertSame('Staff ID', $parsed['columns']['employeeId']);
        self::assertSame('Function / Division', $parsed['columns']['division']);
        self::assertSame('Job Title', $parsed['columns']['jobTitle']);
        self::assertSame('Department', $parsed['columns']['department']);
        self::assertSame('Job Category', $parsed['columns']['jobCategory']);
        self::assertSame(['S/N', 'Check'], $parsed['ignored']);

        $rows = $parsed['rows'];
        self::assertCount(5, $rows, 'the blank row is skipped silently');

        self::assertSame(2, $rows[0]['row']);
        self::assertSame('ada@example.test', $rows[0]['email']);
        self::assertSame([
            'employeeId'  => 'XX-0001',
            'department'  => 'Commercial & New Business',
            'jobTitle'    => 'Analyst',
            'division'    => 'Midsun',
            'jobGroup'    => 'JG 13',
            'salaryGroup' => 'SG 13',
            'jobCategory' => 'Professional',
        ], $rows[0]['values']);
        self::assertNull($rows[0]['error']);

        self::assertSame([], $rows[1]['values'], 'blank cells are no change, not values');

        self::assertSame(5, $rows[2]['row'], 'row numbers survive the blank row');
        self::assertStringContainsString('row 2', (string) $rows[2]['error']);

        self::assertSame('No email address on this row.', $rows[3]['error']);
        self::assertSame('1024', $rows[4]['values']['employeeId'], 'a numeric staff ID is not "1024.0"');
    }

    #[Test]
    public function a_named_sheet_is_used_and_an_unknown_one_is_refused(): void
    {
        $path = $this->workbook([
            'First' => [['Email', 'Staff ID'], ['a@example.test', 'A1']],
            'Second' => [['E-mail', 'Employee ID', 'Department'], ['b@example.test', 'B1', 'Finance']],
        ]);

        self::assertSame('First', PlacementWorkbook::read($path)['sheet']);
        self::assertSame('Finance', PlacementWorkbook::read($path, 'Second')['rows'][0]['values']['department']);

        $this->expectException(\InvalidArgumentException::class);
        PlacementWorkbook::read($path, 'Missing');
    }

    #[Test]
    public function naming_a_sheet_that_is_not_a_placement_sheet_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PlacementWorkbook::parse([
            ['name' => 'Staff', 'rows' => [['Email', 'Staff ID'], ['a@example.test', 'A1']]],
            ['name' => '2026 Learning Plan - Test', 'rows' => [
                ['Names', 'Email Address', 'DESIGNATION', 'Department', 'Division', 'Functional Learning'],
                ['Ada Test', 'a@example.test', 'Officer', 'Information Technology', 'Ops', 'Course A'],
            ]],
        ], '2026 Learning Plan - Test');
    }

    #[Test]
    public function a_workbook_without_a_placement_sheet_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PlacementWorkbook::parse([['name' => 'Plan', 'rows' => [['Names', 'Email Address'], ['Ada', 'a@example.test']]]]);
    }

    /**
     * @param array<string, list<list<mixed>>> $sheets
     */
    private function workbook(array $sheets): string
    {
        $this->path = tempnam(sys_get_temp_dir(), 'plc_test_') . '.xlsx';

        $writer = new Writer();
        $writer->openToFile($this->path);
        $first = true;

        foreach ($sheets as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($name);
            $first = false;

            foreach ($rows as $row) {
                $writer->addRow(Row::fromValues($row));
            }
        }

        $writer->close();

        return $this->path;
    }
}
