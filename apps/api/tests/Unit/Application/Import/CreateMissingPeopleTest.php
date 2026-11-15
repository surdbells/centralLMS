<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Application\Import;

use Central\Api\Application\Import\ImportedPersonCreator;
use Central\Api\Application\Import\LearningPlanImporter;
use Central\Api\Application\Import\LearningPlanWorkbook;
use Central\Api\Application\Import\PlacementImporter;
use Central\Api\Application\Import\PlacementWorkbook;
use Central\Api\Domain\Identity\UserStatus;
use Central\Api\Domain\Lookup\LookupKind;
use Central\Api\Domain\People\Person;
use Central\Api\Tests\Doubles\FakeRoleRepository;
use Central\Api\Tests\Doubles\InMemoryCustomFieldRepository;
use Central\Api\Tests\Doubles\InMemoryLookupRepository;
use Central\Api\Tests\Doubles\InMemoryPersonRepository;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `createMissing` in both workbook importers, against in-memory stores and
 * synthetic workbooks only: who would be created, who cannot be and why, that
 * nobody is created twice, and that leaving it off changes nothing.
 */
final class CreateMissingPeopleTest extends TestCase
{
    private const T = '11111111-1111-4111-8111-111111111111';

    private InMemoryPersonRepository $people;
    private InMemoryLookupRepository $lookups;
    private InMemoryCustomFieldRepository $fields;
    private ImportedPersonCreator $creator;
    private string $path = '';

    protected function setUp(): void
    {
        $this->people  = new InMemoryPersonRepository();
        $this->lookups = new InMemoryLookupRepository();
        $this->fields  = new InMemoryCustomFieldRepository();
        $this->creator = new ImportedPersonCreator($this->people, $this->lookups, $this->fields, new FakeRoleRepository());

        $this->people->add(new Person(null, self::T, 'ada@example.test', 'Ada', 'Test', UserStatus::Active));
    }

    protected function tearDown(): void
    {
        if ($this->path !== '') {
            @unlink($this->path);
        }
    }

    // --- placement ------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function placement(): array
    {
        return PlacementWorkbook::parse([[
            'name' => 'Staff List - Placement',
            'rows' => [
                ['S/N', 'Names', 'Email address', 'Staff ID', 'Job Title', 'Department', 'Function / Division', 'Job Group'],
                ['1', 'Ada Test', 'ada@example.test', 'X-1', '', '', '', ''],
                ['2', 'BOLA ADE-OBI', 'Bola@Example.test', 'X-2', 'Analyst', 'Finance', 'Upstream', 'JG 10'],
                ['3', 'Mononym', 'mono@example.test', 'X-3', '', '', '', ''],
                ['4', 'Bad Email', 'bad-at-example', 'X-4', '', '', '', ''],
                ['5', 'No Mail', '', 'X-5', '', '', '', ''],
            ],
        ]]);
    }

    private function placementImporter(): PlacementImporter
    {
        return new PlacementImporter($this->people, $this->lookups, $this->fields, $this->creator);
    }

    #[Test]
    public function placement_preview_lists_who_would_be_created_and_who_cannot(): void
    {
        $preview = $this->placementImporter()->preview(self::T, $this->placement());

        self::assertSame(['bola@example.test'], array_column($preview['toCreate'], 'email'));
        self::assertSame('Bola', $preview['toCreate'][0]['firstName']);
        self::assertSame('Ade-Obi', $preview['toCreate'][0]['lastName']);
        self::assertSame('Analyst', $preview['toCreate'][0]['jobTitle']);
        self::assertSame('Finance', $preview['toCreate'][0]['department']);
        self::assertSame(
            ['incomplete_name', 'invalid_email', 'missing_email'],
            array_column($preview['cannotCreate'], 'reason'),
        );
        self::assertSame(['toCreate' => 1, 'cannotCreate' => 3], $preview['totals']);
        // Without createMissing the rows stay unmatched.
        self::assertSame(0, $preview['summary']['create']);

        $creating = $this->placementImporter()->preview(self::T, $this->placement(), true);
        self::assertSame(1, $creating['summary']['create']);
        self::assertSame(['Staff ID', 'Department', 'Job title', 'Function / Division', 'Job group'], array_column(
            $creating['rows'][1]['changes'],
            'label',
        ));
        self::assertContains('Finance', $creating['lookupsToCreate']['department']);
    }

    #[Test]
    public function placement_commit_without_create_missing_creates_nobody(): void
    {
        $result = $this->placementImporter()->commit(self::T, $this->placement());

        self::assertSame(0, $result['created']);
        self::assertSame([], $result['createdPeople']);
        self::assertCount(1, $this->people->people);
        self::assertSame('unmatched', $result['rows'][1]['outcome']);
    }

    #[Test]
    public function placement_commit_creates_then_places_and_a_rerun_creates_nothing(): void
    {
        $result = $this->placementImporter()->commit(self::T, $this->placement(), true, null, 'actor');

        self::assertSame(1, $result['created']);
        self::assertSame('created', $result['rows'][1]['outcome']);
        self::assertTrue($result['rows'][1]['created']);

        $bola = $this->people->findByEmail(self::T, 'bola@example.test');
        self::assertNotNull($bola);
        self::assertSame($bola->id, $result['createdPeople'][0]['userId']);
        self::assertSame(UserStatus::Invited, $bola->status);
        self::assertSame('Ade-Obi', $bola->lastName);
        self::assertSame(['learner'], $bola->roleIds);
        // Placed by the same path as an existing person.
        self::assertSame('X-2', $bola->employeeId);
        self::assertNotNull($bola->departmentId);
        self::assertNotNull($bola->jobTitleId);
        self::assertSame(['division' => 'Upstream', 'job_group' => 'JG 10'], $this->people->custom[(string) $bola->id]);

        $again = $this->placementImporter()->commit(self::T, $this->placement(), true, null, 'actor');

        self::assertSame(0, $again['created']);
        self::assertSame('unchanged', $again['rows'][1]['outcome']);
        self::assertCount(2, $this->people->people);
    }

    #[Test]
    public function placement_email_with_inner_space_is_created_and_placed_under_one_address(): void
    {
        $sheet = PlacementWorkbook::parse([[
            'name' => 'Staff List - Placement',
            'rows' => [
                ['S/N', 'Names', 'Email address', 'Staff ID', 'Job Title', 'Department'],
                ['1', 'TEST CY', "Cy .Test\u{00A0}@Example.test", 'X-9', 'Analyst', 'Finance'],
            ],
        ]]);

        self::assertSame('cy.test@example.test', $sheet['rows'][0]['email']);

        $result = $this->placementImporter()->commit(self::T, $sheet, true, null, 'actor');

        self::assertSame(1, $result['created']);
        self::assertSame('created', $result['rows'][0]['outcome']);

        $cy = $this->people->findByEmail(self::T, 'cy.test@example.test');
        self::assertNotNull($cy);
        // Surname-first capitals read against the address.
        self::assertSame(['Cy', 'Test'], [$cy->firstName, $cy->lastName]);
        self::assertSame('X-9', $cy->employeeId);
        self::assertNotNull($cy->departmentId);
        self::assertNotNull($cy->jobTitleId);
    }

    #[Test]
    public function plan_creation_skips_entity_code_departments_and_checks_names_against_addresses(): void
    {
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lpc_' . bin2hex(random_bytes(6)) . '.xlsx';

        $writer = new Writer();
        $writer->openToFile($this->path);
        $writer->getCurrentSheet()->setName('2026 Learning Plan - ACME');
        $writer->addRow(Row::fromValues(['S/N', 'NAMES', 'Email Address', 'DESIGNATION', 'Department', 'Division', 'GRADE', 'Entity', 'Functional Learning', 'Recommended Provider & Date']));
        // Department is the sheet's entity; the name was copied from another row.
        $writer->addRow(Row::fromValues([1, 'Bola Eve Obi', 'eve.test@example.test', 'Clerk', 'ACME', 'BETA', 'Officer', 'BETA', 'Excel', '']));
        $writer->addNewSheetAndMakeItCurrent()->setName('2025 Learning Plan - ACME');
        $writer->addRow(Row::fromValues(['S/N', 'NAMES', 'Email Address', 'DESIGNATION', 'Department', 'Division', 'GRADE', 'Entity', 'Functional Learning', 'Recommended Provider & Date']));
        $writer->addRow(Row::fromValues([1, 'Eve Test', 'eve.test@example.test', 'Clerk', 'ACME', 'Upstream', 'Officer', 'JV', 'Excel', '']));
        $writer->close();

        $importer = $this->planImporter(new InMemoryPlans([]));
        $preview  = $importer->preview(self::T, $this->path, 'plans.xlsx');
        $eve      = $preview['sheets'][0]['toCreate'][0];

        // The older sheet's name agrees with the address; it wins.
        self::assertSame(['Eve', 'Test'], [$eve['firstName'], $eve['lastName']]);
        // Entity codes are not departments; a real division from 2025 fills in.
        self::assertNull($eve['department']);
        self::assertSame('Upstream', $eve['division']);
        self::assertContains('department_entity_code', array_column($preview['sheets'][0]['warnings'], 'code'));
        self::assertNotContains('name_email_mismatch', array_column($preview['sheets'][0]['warnings'], 'code'));

        $profiles = LearningPlanImporter::bestPeople($importer->read($this->path));
        self::assertSame(['department' => 'ACME'], $profiles['eve.test@example.test']['entityCodes']);
    }

    #[Test]
    public function plan_preview_warns_when_no_name_matches_the_address(): void
    {
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lpc_' . bin2hex(random_bytes(6)) . '.xlsx';

        $writer = new Writer();
        $writer->openToFile($this->path);
        $writer->getCurrentSheet()->setName('2026 Learning Plan - ACME');
        $writer->addRow(Row::fromValues(['S/N', 'NAMES', 'Email Address', 'DESIGNATION', 'Department', 'Functional Learning', 'Recommended Provider & Date']));
        $writer->addRow(Row::fromValues([1, 'Bola Obi', 'eve.test@example.test', 'Clerk', 'Ops', 'Excel', '']));
        $writer->close();

        $preview = $this->planImporter(new InMemoryPlans([]))->preview(self::T, $this->path, 'plans.xlsx');

        self::assertSame('Bola', $preview['sheets'][0]['toCreate'][0]['firstName']);
        self::assertSame('Ops', $preview['sheets'][0]['toCreate'][0]['department']);
        self::assertSame(['name_email_mismatch'], array_column($preview['sheets'][0]['warnings'], 'code'));
    }

    // --- learning plans -------------------------------------------------------

    private function planImporter(InMemoryPlans $plans): LearningPlanImporter
    {
        $plans->extraUsers = fn (): array => $this->people->emailIndex();

        return new LearningPlanImporter($plans, new LearningPlanWorkbook(), $this->creator);
    }

    private function writePlans(): string
    {
        $this->path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'lpc_' . bin2hex(random_bytes(6)) . '.xlsx';

        $writer = new Writer();
        $writer->openToFile($this->path);

        $writer->getCurrentSheet()->setName('2026 Learning Plan - ACME');
        $writer->addRow(Row::fromValues(['S/N', 'NAMES', 'Email Address', 'DESIGNATION', 'Department / Division', 'GRADE', 'Entity', 'Functional Learning', 'Recommended Provider & Date']));
        $writer->addRow(Row::fromValues([1, 'Ada Test', 'ada@example.test', 'Lead', 'Ops', 'Manager', 'JV', 'Safety', 'Acme (Q3 2026)']));
        $writer->addRow(Row::fromValues([2, 'CY TEST-NEW', 'cy@example.test', 'Senior Analyst', 'Finance', 'Senior Officer', 'JV', 'Excel', 'Acme (Q2 2026)']));
        $writer->addRow(Row::fromValues([3, 'Mononym', 'mono@example.test', '', '', '', '', 'Excel', '']));
        $writer->addRow(Row::fromValues([4, 'Bad Email', 'not-an-email', '', '', '', '', 'Excel', '']));
        $writer->addRow(Row::fromValues([5, 'No Mail', '', '', '', '', '', 'Excel', '']));

        $writer->addNewSheetAndMakeItCurrent()->setName('2025 ACME Training Plan ');
        $writer->addRow(Row::fromValues(['S/N', 'NAMES', 'Email Address', 'DESIGNATION', 'Department', 'Division', 'GRADE', 'Entity', 'Functional Learning', 'Training Provider & Date']));
        $writer->addRow(Row::fromValues([1, 'Cy Test-New', 'CY@example.test', 'Analyst', 'Finance', 'Upstream', 'Officer', '', 'Ledgers', 'Acme (Q4 2025)']));
        $writer->addRow(Row::fromValues([2, 'Dee Test', 'dee@example.test', 'Clerk', 'Admin', '', 'Officer', 'Sole', 'Filing', '']));

        $writer->close();

        return $this->path;
    }

    #[Test]
    public function plan_preview_reports_people_to_create_once_across_sheets(): void
    {
        $plans    = new InMemoryPlans([]);
        $importer = $this->planImporter($plans);
        $preview  = $importer->preview(self::T, $this->writePlans(), 'plans.xlsx');

        $y26 = $preview['sheets'][0];
        $y25 = $preview['sheets'][1];

        self::assertSame(['cy@example.test'], array_column($y26['toCreate'], 'email'));
        // The 2026 sheet's details win; blanks are filled from 2025.
        self::assertSame(
            ['row' => 3, 'email' => 'cy@example.test', 'firstName' => 'Cy', 'lastName' => 'Test-New', 'jobTitle' => 'Senior Analyst', 'department' => 'Finance', 'division' => 'Upstream', 'level' => 'Senior Officer', 'entity' => 'JV', 'previouslyDeleted' => false],
            $y26['toCreate'][0],
        );
        $reasons = array_column($y26['cannotCreate'], 'reason', 'row');
        ksort($reasons);
        self::assertSame([4 => 'incomplete_name', 5 => 'invalid_email', 6 => 'missing_email'], $reasons);
        self::assertSame(['cy@example.test', 'dee@example.test'], array_column($y25['toCreate'], 'email'));
        self::assertSame('Senior Analyst', $y25['toCreate'][0]['jobTitle']);

        // Cy is on both sheets but is one account.
        self::assertSame(2, $preview['totals']['toCreate']);
        self::assertSame(3, $preview['totals']['cannotCreate']);
        self::assertFalse($y25['importable']);

        $creating = $importer->preview(self::T, $this->path, 'plans.xlsx', true);
        self::assertTrue($creating['sheets'][1]['importable']);
        self::assertSame(2, $creating['sheets'][1]['items']['create']);
        self::assertSame(0, $creating['sheets'][1]['people']['matched']);
    }

    #[Test]
    public function plan_commit_without_create_missing_is_unchanged(): void
    {
        $plans    = new InMemoryPlans([]);
        $importer = $this->planImporter($plans);
        $result   = $importer->commit(self::T, $importer->read($this->writePlans()), 'plans.xlsx', ['2026 Learning Plan - ACME', '2025 ACME Training Plan '], false, false);

        self::assertSame(0, $result['totals']['peopleCreated']);
        self::assertSame([], $result['createdPeople']);
        self::assertCount(1, $this->people->people);
        // Only Ada matched, on 2026; 2025 has nobody and is not imported.
        self::assertNull($result['sheets'][0]['written']['error']);
        self::assertNotNull($result['sheets'][1]['written']['error']);
    }

    #[Test]
    public function plan_commit_creates_each_person_once_and_makes_them_members_with_items(): void
    {
        $plans    = new InMemoryPlans([]);
        $importer = $this->planImporter($plans);
        $sheets   = ['2026 Learning Plan - ACME', '2025 ACME Training Plan '];
        $result   = $importer->commit(self::T, $importer->read($this->writePlans()), 'plans.xlsx', $sheets, false, false, true, null, 'actor');

        $w26 = $result['sheets'][0]['written'];
        $w25 = $result['sheets'][1]['written'];

        self::assertNull($w26['error']);
        self::assertNull($w25['error']);
        self::assertSame(1, $w26['peopleCreated']);
        self::assertSame(1, $w25['peopleCreated']); // Dee; Cy already exists by then
        self::assertSame(2, $result['totals']['peopleCreated']);
        self::assertSame(['cy@example.test', 'dee@example.test'], array_column($result['createdPeople'], 'email'));
        self::assertCount(3, $this->people->people);

        $cy = $this->people->findByEmail(self::T, 'cy@example.test');
        self::assertNotNull($cy);
        self::assertSame('Cy', $cy->firstName);
        self::assertSame(UserStatus::Invited, $cy->status);
        self::assertSame(['division' => 'Upstream', 'entity' => 'JV'], $this->people->custom[(string) $cy->id]);
        self::assertContains('Senior Officer', $this->lookups->names(LookupKind::Level));
        self::assertContains('Senior Analyst', $this->lookups->names(LookupKind::JobTitle));

        // Members of both plans, with their items.
        self::assertSame(2, $w26['membersAdded']);
        self::assertSame(2, $w25['membersAdded']);
        self::assertTrue($plans->members[(string) $w26['planId']][(string) $cy->id] ?? false);
        self::assertTrue($plans->members[(string) $w25['planId']][(string) $cy->id] ?? false);
        self::assertSame(2, $w26['itemsCreated']);
        self::assertSame(2, $w25['itemsCreated']);
        self::assertContains((string) $cy->id, array_column($plans->items, 'user_id'));

        // A re-run creates nobody and changes nothing.
        $again = $importer->commit(self::T, $importer->read($this->path), 'plans.xlsx', $sheets, false, false, true, null, 'actor');
        self::assertSame(0, $again['totals']['peopleCreated']);
        self::assertSame(0, $again['totals']['itemsCreated']);
        self::assertCount(3, $this->people->people);
    }
}
