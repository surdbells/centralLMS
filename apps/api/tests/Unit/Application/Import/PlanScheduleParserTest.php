<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Application\Import;

use Central\Api\Application\Import\PlanScheduleParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlanScheduleParserTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int, ?string, ?string, ?string, ?string}>
     */
    public static function dated(): iterable
    {
        // text, sheet year, provider, scheduleText, start, end
        yield 'cross-year Mon D YYYY - D Mon YYYY' => ['Acme Training, UK (Dec 29 2025 - 2 Jan 2026)', 2025, 'Acme Training, UK', 'Dec 29 2025 - 2 Jan 2026', '2025-12-29', '2026-01-02'];
        yield 'cross-year D Mon YYYY - D Mon YYYY' => ['Acme (29 Dec 2025 - 2 Jan 2026)', 2025, 'Acme', '29 Dec 2025 - 2 Jan 2026', '2025-12-29', '2026-01-02'];
        yield 'Mon D - Mon D, YYYY' => ["Acme, Abuja\n(June 28 \u{2013} July 2, 2026)", 2026, 'Acme, Abuja', 'June 28 - July 2, 2026', '2026-06-28', '2026-07-02'];
        yield 'D Mon - D Mon YYYY' => ['Acme (28 June - 2 July 2026)', 2026, 'Acme', '28 June - 2 July 2026', '2026-06-28', '2026-07-02'];
        yield 'Mon D - 1st Mon YYYY' => ["Acme (July 28 \u{2013} 1st August 2025)", 2025, 'Acme', 'July 28 - 1 August 2025', '2025-07-28', '2025-08-01'];
        yield 'Mon D - D, YYYY' => ['Acme Institute, Berlin Germany, (April 27 - 30, 2026)', 2026, 'Acme Institute, Berlin Germany', 'April 27 - 30, 2026', '2026-04-27', '2026-04-30'];
        yield 'D - D Mon YYYY' => ['Dubai (27 - 31 Oct 2025)', 2025, 'Dubai', '27 - 31 Oct 2025', '2025-10-27', '2025-10-31'];
        yield 'month range' => ['Acme (August / December 2025)', 2025, 'Acme', 'August / December 2025', '2025-08-01', '2025-12-31'];
        yield 'quarter' => ['Acme (Q4 2025)', 2025, 'Acme', 'Q4 2025', '2025-10-01', '2025-12-31'];
        yield 'single day with days' => ['Acme, Lagos Oct 21 (3 days)', 2026, 'Acme, Lagos', 'Oct 21 (3 days)', '2026-10-21', '2026-10-23'];
        yield 'D Mon, no year' => ['Acme (12 March)', 2026, 'Acme', '12 March', '2026-03-12', null];
        yield 'whole month' => ['Acme (March 2026)', 2026, 'Acme', 'March 2026', '2026-03-01', '2026-03-31'];
        yield 'Sept, sheet year default' => ['Acme (Sept 7 - 11)', 2027, 'Acme', 'Sept 7 - 11', '2027-09-07', '2027-09-11'];
        yield 'typo Augus' => ['Acme (Augus 28 - 29, 2025)', 2025, 'Acme', 'August 28 - 29, 2025', '2025-08-28', '2025-08-29'];
        yield 'date outside parentheses' => ['Business School run: July 19, 2025 (25 days over 5 months)', 2025, 'Business School run: (25 days over 5 months)', 'July 19, 2025', '2025-07-19', null];
        yield 'ordinal start day of a D - D Mon range' => ['Acme (23rd - 25 April, 2025)', 2025, 'Acme', '23 - 25 April, 2025', '2025-04-23', '2025-04-25'];
        yield 'ordinal start day, other month' => ['Acme (9th - 13 March, 2026)', 2026, 'Acme', '9 - 13 March, 2026', '2026-03-09', '2026-03-13'];
        yield 'ordinal in a name is kept' => ['Acme 21st Forum (May 4, 2026)', 2026, 'Acme 21st Forum', 'May 4, 2026', '2026-05-04', null];
        yield 'D Mon - Mon D, YYYY' => ['Acme, London (30 June - July 04, 2025)', 2025, 'Acme, London', '30 June - July 04, 2025', '2025-06-30', '2025-07-04'];
        yield 'day range wins over an earlier month range' => ['Acme (August/September 2025) (18-19, Aug. 2025)', 2025, 'Acme (August/September 2025)', '18-19, Aug. 2025', '2025-08-18', '2025-08-19'];
        yield 'New Year range, year on the end' => ['Acme (Dec 28 - Jan 3, 2026)', 2026, 'Acme', 'Dec 28 - Jan 3, 2026', '2025-12-28', '2026-01-03'];
        yield 'New Year range, no year' => ['Acme (December 29 - 2 January)', 2026, 'Acme', 'December 29 - 2 January', '2026-12-29', '2027-01-02'];
        yield 'New Year D Mon range, no year' => ['Acme (28 Dec - 3 Jan)', 2026, 'Acme', '28 Dec - 3 Jan', '2026-12-28', '2027-01-03'];
        yield 'url not mistaken for a date' => ["Dubai, UAE\nhttps://example.test/course-may-2025 , \nDubai (27 - 31 Oct 2025)", 2025, 'Dubai, UAE https://example.test/course-may-2025, Dubai', '27 - 31 Oct 2025', '2025-10-27', '2025-10-31'];
    }

    #[DataProvider('dated')]
    public function testParsesDates(string $text, int $year, ?string $provider, ?string $schedule, ?string $start, ?string $end): void
    {
        $r = PlanScheduleParser::parse($text, $year);

        self::assertSame($provider, $r['provider']);
        self::assertSame($schedule, $r['scheduleText']);
        self::assertSame($start, $r['start']);
        self::assertSame($end, $r['end']);
    }

    /**
     * @return iterable<string, array{string, ?string, ?string}>
     */
    public static function undated(): iterable
    {
        yield 'TBA' => ['Acme (TBA)', 'Acme', 'TBA'];
        yield 'Date TBA' => ['Acme (Date TBA)', 'Acme', 'Date TBA'];
        yield 'yet to be published' => ['Acme (Date yet to be published)', 'Acme', 'Date yet to be published'];
        yield 'empty parentheses' => ['Acme ()', 'Acme', null];
        yield 'elearning' => ['Acme (eLearning)', 'Acme', 'eLearning'];
        yield 'online hours' => ['Acme (Online 6-8 hours)', 'Acme', 'Online 6-8 hours'];
        yield 'provider only' => ["Reliance Learning\n", 'Reliance Learning', null];
        yield 'email stays with provider' => ['Acme (trainer@example.test)', 'Acme (trainer@example.test)', null];
    }

    #[DataProvider('undated')]
    public function testKeepsTextWhenThereIsNoDate(string $text, ?string $provider, ?string $schedule): void
    {
        $r = PlanScheduleParser::parse($text, 2026);

        self::assertSame($provider, $r['provider']);
        self::assertSame($schedule, $r['scheduleText']);
        self::assertNull($r['start']);
        self::assertNull($r['end']);
    }

    public function testWarnsWhenTheYearDiffersFromTheSheet(): void
    {
        $r = PlanScheduleParser::parse('Acme (June 15 - 19, 2025)', 2026);

        self::assertSame('2025-06-15', $r['start']);
        self::assertSame(['date_year_mismatch'], $r['warnings']);
        self::assertSame([], PlanScheduleParser::parse('Acme (June 15 - 19)', 2026)['warnings']);
    }

    public function testKeepsOnlyTheStartWhenTheEndIsBeforeIt(): void
    {
        $r = PlanScheduleParser::parse('Acme (Nov 3 - 1, 2026)', 2026);

        self::assertSame('2026-11-03', $r['start']);
        self::assertNull($r['end']);
        self::assertSame(['end_before_start'], $r['warnings']);
    }

    public function testNullAndBlankGiveNothing(): void
    {
        self::assertNull(PlanScheduleParser::parse(null, 2026)['provider']);
        self::assertNull(PlanScheduleParser::parse("  \n ", 2026)['scheduleText']);
    }
}
