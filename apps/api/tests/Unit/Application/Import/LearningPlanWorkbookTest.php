<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Application\Import;

use Central\Api\Application\Import\LearningPlanWorkbook;
use PHPUnit\Framework\TestCase;

/**
 * Builds a tiny synthetic workbook (fake people only) and reads it back.
 */
final class LearningPlanWorkbookTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = PlanWorkbookFixture::write();
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testClassifiesEverySheet(): void
    {
        $sheets = (new LearningPlanWorkbook())->read($this->path);
        $kinds  = array_column($sheets, 'kind', 'sheet');

        self::assertSame([
            'Staff List - Placement'   => 'placement',
            '2026 Learning Plan - ACME' => 'plan',
            '2025 ACME Training Plan '  => 'plan',
            'Notes'                     => 'unrecognised',
            'Sheet5'                    => 'empty',
        ], $kinds);
    }

    public function testDerivesYearEntityAndTitle(): void
    {
        [, $plan26, $plan25] = (new LearningPlanWorkbook())->read($this->path);

        self::assertSame(2026, $plan26['year']);
        self::assertSame('ACME', $plan26['entity']);
        self::assertSame("2026 Learning Plan \u{2013} ACME", $plan26['planTitle']);
        self::assertSame("2025 Learning Plan \u{2013} ACME", $plan25['planTitle']);
    }

    public function testFindsCategoryColumnsByHeader(): void
    {
        [, $plan26] = (new LearningPlanWorkbook())->read($this->path);

        $cols = array_map(static fn (array $c): array => [$c['category'], $c['providerIndex']], $plan26['columns']);

        self::assertSame([['functional', 4], ['conference', 6], ['behavioural', 8]], $cols);
    }

    public function testSplitsPairsAndDatesItems(): void
    {
        [, $plan26] = (new LearningPlanWorkbook())->read($this->path);

        self::assertCount(3, $plan26['people']);
        $ada = $plan26['people'][0];

        // E-mail is trimmed and lower-cased; row numbers are Excel's.
        self::assertSame('ada@example.test', $ada['email']);
        self::assertSame(2, $ada['row']);

        $titles = array_map(static fn (array $i): string => $i['category'] . ':' . $i['title'], $ada['items']);
        self::assertSame([
            'functional:Pipeline Integrity',
            'functional:Technical/Operations Writing',
            'certification:Project Management Certification',
            'behavioural:Team Culture Workshop',
            'behavioural:Digital Adoption Learning',
        ], $titles);

        // Two titles, two providers: zipped, each with its own dates.
        self::assertSame('Acme Institute, Lagos', $ada['items'][0]['provider']);
        self::assertSame('2026-06-28', $ada['items'][0]['start']);
        self::assertSame('2026-07-02', $ada['items'][0]['end']);
        self::assertSame('Beta Academy', $ada['items'][1]['provider']);
        self::assertSame('TBA', $ada['items'][1]['scheduleText']);
        self::assertNull($ada['items'][1]['start']);

        // One provider for two titles: both get it.
        self::assertSame('Gamma Learning', $ada['items'][3]['provider']);
        self::assertSame('Gamma Learning', $ada['items'][4]['provider']);

        // Sort order is category-major.
        self::assertSame([0, 1, 200, 300, 301], array_column($ada['items'], 'sortOrder'));
        self::assertSame(40, strlen($ada['items'][0]['key']));
    }

    public function testMismatchedProvidersAreJoinedWithAWarning(): void
    {
        [, $plan26] = (new LearningPlanWorkbook())->read($this->path);
        $bo = $plan26['people'][1];

        self::assertCount(2, $bo['items']);
        self::assertSame('Delta / Epsilon / Zeta', $bo['items'][0]['provider']);
        self::assertContains('provider_count_mismatch', array_column($plan26['warnings'], 'code'));
        self::assertSame(1, $plan26['mismatchedCells']);
    }

    public function testAmbiguousProvidersGiveNoTrainingADate(): void
    {
        $sheet = (new LearningPlanWorkbook())->parseSheet('2026 Learning Plan - TEST', 0, [
            ['NAMES', 'Email Address', 'Functional Learning', 'Provider & Date'],
            ['Ada Test', 'ada@example.test', 'One / Two / Three', 'Acme (May 11 - 15, 2026) / Beta (June 1 - 5, 2026)'],
        ]);

        $items = $sheet['people'][0]['items'];
        self::assertCount(3, $items);
        foreach ($items as $item) {
            self::assertNull($item['start']);
            self::assertNull($item['end']);
            self::assertNull($item['scheduleText']);
            self::assertSame('Acme (May 11 - 15, 2026) / Beta (June 1 - 5, 2026)', $item['provider']);
        }
        self::assertContains('provider_count_mismatch', array_column($sheet['warnings'], 'code'));
    }

    public function testModeAndPlaceProviderPartsFoldIntoThePreviousProvider(): void
    {
        $sheet = (new LearningPlanWorkbook())->parseSheet('2025 Learning Plan - TEST', 0, [
            ['NAMES', 'Email Address', 'Leadership Learning', 'Provider & Date'],
            ['Ada Test', 'ada@example.test', 'Leading Teams / Retirement Workshop', 'Acme UAE (15 -19 Dec, 2025) / Virtual / Beta (May 20 - 22, 2025)'],
        ]);

        [$a, $b] = $sheet['people'][0]['items'];
        self::assertSame('Acme UAE / Virtual', $a['provider']);
        self::assertSame('2025-12-15', $a['start']);
        self::assertSame('Beta', $b['provider']);
        self::assertSame('2025-05-20', $b['start']);
        self::assertSame(0, $sheet['mismatchedCells']);
    }

    public function testOneTrainingPerLineIsSplitWhenProvidersMatchLineForLine(): void
    {
        $sheet = (new LearningPlanWorkbook())->parseSheet('2025 Learning Plan - TEST', 0, [
            ['NAMES', 'Email Address', 'Functional Learning', 'Provider & Date'],
            ['Ada Test', 'ada@example.test', "Analysis Certificate (CBA);\nReimbursement Seminar", "Acme (June 21, 2025);\nBeta (June 4 - 5, 2025)"],
            ['Bo Test', 'bo@example.test', "A long title that\nwraps onto two lines", 'Acme (June 21, 2025)'],
        ]);

        $ada = $sheet['people'][0]['items'];
        self::assertSame(['Analysis Certificate (CBA)', 'Reimbursement Seminar'], array_column($ada, 'title'));
        self::assertSame(['2025-06-21', '2025-06-04'], array_column($ada, 'start'));

        self::assertSame(['A long title that wraps onto two lines'], array_column($sheet['people'][1]['items'], 'title'));
    }

    public function testConferencesAreCategorisedByTitle(): void
    {
        $sheet = (new LearningPlanWorkbook())->parseSheet('2026 Learning Plan - TEST', 0, [
            ['NAMES', 'Email Address', 'Organisational Learning', 'Provider & Date', 'Conference / Certification', 'Provider & Date'],
            ['Ada Test', 'ada@example.test', 'Ethics / Annual Energy Conference', '', 'Lab Management / Pipeline Summit / FMP Certification', ''],
        ]);

        $cats = array_map(static fn (array $i): string => $i['category'] . ':' . $i['title'], $sheet['people'][0]['items']);
        self::assertSame([
            'organisational:Ethics',
            'conference:Annual Energy Conference',
            'functional:Lab Management',
            'conference:Pipeline Summit',
            'certification:FMP Certification',
        ], $cats);
    }

    public function testSkipsRowsWithoutOrRepeatingAnEmail(): void
    {
        [, $plan26] = (new LearningPlanWorkbook())->read($this->path);

        self::assertSame([
            ['row' => 5, 'name' => 'No Mail Test', 'reason' => 'no_email'],
            ['row' => 7, 'name' => 'Ada Again', 'reason' => 'duplicate_email'],
        ], $plan26['skipped']);

        $codes = array_column($plan26['warnings'], 'code');
        self::assertContains('no_email', $codes);
        self::assertContains('duplicate_email', $codes);
        self::assertContains('provider_without_title', $codes);
        self::assertContains('duplicate_item', $codes);
    }

    public function testIgnoresTrailingEmptyAndBlankHeaderColumns(): void
    {
        [, , $plan25] = (new LearningPlanWorkbook())->read($this->path);

        self::assertSame(['organisational', 'leadership'], array_column($plan25['columns'], 'category'));
        self::assertCount(1, $plan25['people']);
        self::assertSame('2025-10-01', $plan25['people'][0]['items'][0]['start']);
        self::assertSame('2025-12-31', $plan25['people'][0]['items'][0]['end']);
    }

    public function testTheSameItemGetsTheSameKey(): void
    {
        self::assertSame(
            LearningPlanWorkbook::importKey('a@example.test', 'functional', 'Pipeline  Integrity.'),
            LearningPlanWorkbook::importKey('a@example.test', 'functional', "pipeline integrity\n"),
        );
        self::assertNotSame(
            LearningPlanWorkbook::importKey('a@example.test', 'functional', 'Pipeline Integrity'),
            LearningPlanWorkbook::importKey('a@example.test', 'leadership', 'Pipeline Integrity'),
        );
    }
}
