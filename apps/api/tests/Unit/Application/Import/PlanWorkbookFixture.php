<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Application\Import;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * The synthetic workbook shared by the workbook and importer tests.
 */
final class PlanWorkbookFixture
{
    public static function write(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lpw_' . bin2hex(random_bytes(6)) . '.xlsx';

        $writer = new Writer();
        $writer->openToFile($path);

        $writer->getCurrentSheet()->setName('Staff List - Placement');
        $writer->addRow(Row::fromValues(['S/N', 'Names', 'Email address', 'Staff ID', 'Job Group']));
        $writer->addRow(Row::fromValues([1, 'Ada Test', 'ada@example.test', 'X-0001', 'JG 10']));

        $writer->addNewSheetAndMakeItCurrent()->setName('2026 Learning Plan - ACME');
        $writer->addRow(Row::fromValues([
            'S/N', 'NAMES', 'Email Address', 'Functional Learning', 'Recommended Provider & Date',
            'Conference / Certification', 'Recommended Provider & Date', 'Behavioural/ Culture Learning',
            'Recommended Provider', '',
        ]));
        $writer->addRow(Row::fromValues([
            1, 'Ada Test', '  Ada@Example.TEST ',
            "Pipeline Integrity /\nTechnical/Operations Writing \n",
            "Acme Institute, Lagos (June 28 \u{2013} July 2, 2026)\n/\nBeta Academy (TBA)",
            "Project Management Certification\n", '',
            "Team Culture Workshop / Digital Adoption Learning", 'Gamma Learning', '',
        ]));
        $writer->addRow(Row::fromValues([
            50, 'Bo Test', 'bo@example.test',
            "Drilling Basics / Well Control", 'Delta / Epsilon / Zeta',
            '', 'Orphan Provider (May 4, 2026)', '', '', '82000',
        ]));
        $writer->addRow(Row::fromValues([2, 'Cy Test', 'cy@example.test', 'Safety / Safety', 'Omega (Q3 2026)']));
        $writer->addRow(Row::fromValues([3, 'No Mail Test', '', 'Something']));
        $writer->addRow(Row::fromValues(['', '', '', '']));
        $writer->addRow(Row::fromValues([4, 'Ada Again', 'ada@example.test', 'Other Course']));

        $writer->addNewSheetAndMakeItCurrent()->setName('2025 ACME Training Plan ');
        $writer->addRow(Row::fromValues([
            'S/N', 'NAMES', 'Email Address', 'Organizational Learning', 'Training Provider & Date',
            'Leadership Learning', 'Training Provider & Date', '', '',
        ]));
        $writer->addRow(Row::fromValues([1, 'Ada Test', 'ada@example.test', 'Ethics', 'Acme (Q4 2025)', '', '', '', '']));

        $writer->addNewSheetAndMakeItCurrent()->setName('Notes');
        $writer->addRow(Row::fromValues(['Anything', 'else']));

        $writer->addNewSheetAndMakeItCurrent()->setName('Sheet5');

        $writer->close();

        return $path;
    }
}
