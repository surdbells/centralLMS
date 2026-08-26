<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\People;

use Central\Api\Domain\People\PeopleCsv;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PeopleCsv::class)]
final class PeopleCsvTest extends TestCase
{
    #[Test]
    public function it_reads_a_plain_file(): void
    {
        $csv = PeopleCsv::read("email,firstName,lastName\nada@x.test,Ada,Lovelace\n");

        self::assertCount(1, $csv->rows);
        self::assertSame('ada@x.test', $csv->rows[0]['values']['email']);
        self::assertSame('Ada', $csv->rows[0]['values']['firstName']);
        // Line 1 is the header, so the first person is on line 2 — which is
        // the number somebody will be looking at in their spreadsheet.
        self::assertSame(2, $csv->rows[0]['line']);
    }

    #[Test]
    public function a_column_can_be_called_what_a_person_would_call_it(): void
    {
        // "E-mail Address" reading as nothing is how an import silently
        // creates four hundred people with no address.
        $csv = PeopleCsv::read("E-mail Address,First Name,Surname,Reports To\nada@x.test,Ada,Lovelace,boss@x.test\n");

        self::assertSame('E-mail Address', $csv->mapping['email']);
        self::assertSame('First Name', $csv->mapping['firstName']);
        self::assertSame('Surname', $csv->mapping['lastName']);
        self::assertSame('Reports To', $csv->mapping['manager']);
        self::assertSame('boss@x.test', $csv->rows[0]['values']['manager']);
    }

    #[Test]
    public function it_says_which_columns_it_ignored(): void
    {
        // So somebody can see that the payroll number did not come across
        // before they import, rather than afterwards.
        $csv = PeopleCsv::read("email,Cost Centre,Payroll No\nada@x.test,CC1,42\n");

        self::assertSame(['Cost Centre', 'Payroll No'], $csv->unmapped);
    }

    #[Test]
    public function it_reads_a_semicolon_file(): void
    {
        // What a spreadsheet saves in a locale that uses the comma as a
        // decimal separator. The legacy read these as a single column.
        $csv = PeopleCsv::read("email;firstName;lastName\nada@x.test;Ada;Lovelace\n");

        self::assertSame('ada@x.test', $csv->rows[0]['values']['email']);
        self::assertSame('Lovelace', $csv->rows[0]['values']['lastName']);
    }

    #[Test]
    public function it_survives_the_byte_order_mark_excel_writes(): void
    {
        $csv = PeopleCsv::read("\xEF\xBB\xBFemail,firstName\nada@x.test,Ada\n");

        self::assertArrayHasKey('email', $csv->mapping);
        self::assertSame('ada@x.test', $csv->rows[0]['values']['email']);
    }

    #[Test]
    public function blank_lines_are_not_people(): void
    {
        $csv = PeopleCsv::read("email,firstName\nada@x.test,Ada\n\n,\ngrace@x.test,Grace\n");

        self::assertCount(2, $csv->rows);
        self::assertSame('grace@x.test', $csv->rows[1]['values']['email']);
    }

    #[Test]
    public function a_file_with_no_address_column_is_refused(): void
    {
        // Without one there is no way to tell one person from another, so
        // importing would mean creating duplicates on the next run.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/email address/');

        PeopleCsv::read("Name,Department\nAda,Engineering\n");
    }

    #[Test]
    public function an_empty_file_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);

        PeopleCsv::read("   \n  ");
    }

    #[Test]
    public function it_stops_at_the_limit_and_says_so(): void
    {
        $lines = ['email'];

        for ($i = 0; $i < PeopleCsv::MAX_ROWS + 10; $i++) {
            $lines[] = sprintf('person%d@x.test', $i);
        }

        $csv = PeopleCsv::read(implode("\n", $lines));

        self::assertCount(PeopleCsv::MAX_ROWS, $csv->rows);
        // Reported rather than silent: an import that quietly dropped the last
        // ten people would be found out weeks later.
        self::assertTrue($csv->truncated);
    }

    #[Test]
    public function the_same_column_twice_does_not_overwrite_the_first(): void
    {
        $csv = PeopleCsv::read("email,Email Address\nada@x.test,other@x.test\n");

        self::assertSame('email', $csv->mapping['email']);
        self::assertSame('ada@x.test', $csv->rows[0]['values']['email']);
        self::assertSame(['Email Address'], $csv->unmapped);
    }
}
