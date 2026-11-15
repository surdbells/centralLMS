<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Application\Import;

use Central\Api\Application\Import\PlacementImporter;
use Central\Api\Domain\CustomField\CustomFieldDefinition;
use Central\Api\Domain\CustomField\CustomFieldType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The diff a placement import makes, without a database: synthetic people,
 * lookups and field definitions in, a verdict per row out.
 */
#[CoversClass(PlacementImporter::class)]
final class PlacementImporterTest extends TestCase
{
    private const ADA = '00000000-0000-4000-8000-00000000000a';
    private const BO  = '00000000-0000-4000-8000-00000000000b';

    #[Test]
    public function it_reports_only_what_changes_and_reuses_lookups_by_normalised_name(): void
    {
        $plan = PlacementImporter::plan(
            [
                $this->row(2, 'ada@example.test', [
                    'employeeId' => 'XX-1',
                    'department' => 'Commercial & New Business Development',
                    'jobTitle'   => 'Analyst',
                    'division'   => 'Upstream',
                ]),
                $this->row(3, 'bo@example.test', [
                    'employeeId' => 'XX-1',
                    'department' => 'Brand New Unit',
                    'jobTitle'   => 'Analyst',
                    'jobGroup'   => 'JG 10',
                    'salaryGroup' => 'SG 9',
                    'jobCategory' => 'Management',
                ]),
                $this->row(4, 'nobody@example.test', ['department' => 'Another Unit']),
                ['row' => 5, 'email' => 'ada@example.test', 'name' => 'Dup', 'values' => [], 'error' => 'Already on row 2.'],
                $this->row(6, 'ada2@example.test', ['jobGroup' => 'EM-B', 'department' => 'brand new unit']),
            ],
            [
                $this->person(self::ADA, 'ada@example.test', 'XX-1', 'd1', 'j1', ['division' => 'Upstream']),
                $this->person(self::BO, 'BO@example.test', null, null, null, [], 'suspended'),
                $this->person('00000000-0000-4000-8000-0000000000a2', 'ada2@example.test', null, null, null, []),
            ],
            [
                'department' => [
                    ['id' => 'd1', 'name' => 'Commercial and New Business Development', 'isActive' => true],
                ],
                'job_title' => [
                    ['id' => 'j1', 'name' => 'Analyst', 'isActive' => false],
                ],
            ],
            [
                new CustomFieldDefinition('f1', 't', 'division', 'Division', CustomFieldType::Text),
                new CustomFieldDefinition('f2', 't', 'job_group', 'Job group', CustomFieldType::Dropdown, ['JG 11']),
            ],
        );

        [$ada, $bo, $nobody, $dup, $ada2] = $plan['rows'];

        self::assertSame('unchanged', $ada['action'], '"&" and "and" are the same department');
        self::assertSame([], $ada['changes']);
        self::assertStringContainsString('inactive', implode(' ', $ada['warnings']));

        self::assertSame('update', $bo['action']);
        self::assertSame(
            ['employeeId', 'department', 'jobTitle', 'jobGroup', 'salaryGroup', 'jobCategory'],
            array_column($bo['changes'], 'field'),
        );
        self::assertSame(['field' => 'department', 'label' => 'Department', 'old' => null, 'new' => 'Brand New Unit'], $bo['changes'][1]);
        $warnings = implode(' ', $bo['warnings']);
        self::assertStringContainsString('suspended', $warnings);
        self::assertStringContainsString('already held by another person', $warnings);
        self::assertStringContainsString('also on row 2', $warnings);

        self::assertSame('unmatched', $nobody['action']);
        self::assertNull($nobody['userId']);
        self::assertSame('error', $dup['action']);

        // Only matched rows create reference data; the first spelling names it.
        self::assertSame(['department' => ['Brand New Unit'], 'job_title' => []], $plan['lookupsToCreate']);
        self::assertSame('Brand New Unit', $ada2['changes'][0]['new']);
        self::assertSame(['field' => 'jobGroup', 'label' => 'Job group', 'old' => null, 'new' => 'EM-B'], $ada2['changes'][1]);

        self::assertSame(
            [['key' => 'salary_group', 'label' => 'Salary group', 'type' => 'dropdown'], ['key' => 'job_category', 'label' => 'Job category', 'type' => 'dropdown']],
            $plan['customFieldsToCreate'],
        );
        self::assertSame(['JG 10', 'EM-B'], $plan['optionsToAdd']['job_group'], 'numbered bands first, then the rest');
        self::assertSame(['SG 9'], $plan['optionsToAdd']['salary_group']);

        self::assertSame(['update' => 2, 'unchanged' => 1, 'unmatched' => 1, 'error' => 1, 'warnings' => 2], $plan['summary']);
    }

    #[Test]
    public function a_dropdown_value_reuses_an_existing_option_that_differs_only_in_case(): void
    {
        $plan = PlacementImporter::plan(
            [$this->row(2, 'ada@example.test', ['division' => 'Midsun'])],
            [$this->person(self::ADA, 'ada@example.test', null, null, null, ['division' => 'MidSun'])],
            ['department' => [], 'job_title' => []],
            [new CustomFieldDefinition('f1', 't', 'division', 'Division', CustomFieldType::Dropdown, ['MidSun', 'JV'])],
        );

        self::assertSame('unchanged', $plan['rows'][0]['action']);
        self::assertSame([], $plan['optionsToAdd']['division'] ?? []);
    }

    #[Test]
    public function names_normalise_and_bands_sort_naturally(): void
    {
        self::assertSame(
            PlacementImporter::normalise('Commercial and New Business Development'),
            PlacementImporter::normalise(' commercial &  new business-development '),
        );
        self::assertSame(['JG 2', 'JG 10', 'JG 20', 'EM-B'], PlacementImporter::naturalSort(['EM-B', 'JG 20', 'JG 10', 'JG 2']));
    }

    /**
     * @param array<string, string> $values
     *
     * @return array{row: int, email: string, name: string, values: array<string, string>, error: null}
     */
    private function row(int $row, string $email, array $values): array
    {
        return ['row' => $row, 'email' => $email, 'name' => 'Test Person', 'values' => $values, 'error' => null];
    }

    /**
     * @param array<string, string|float|bool> $custom
     *
     * @return array{id: string, email: string, name: string, status: string, employeeId: ?string, departmentId: ?string, jobTitleId: ?string, customFields: array<string, string|float|bool>}
     */
    private function person(string $id, string $email, ?string $staffId, ?string $dept, ?string $title, array $custom, string $status = 'active'): array
    {
        return [
            'id'           => $id,
            'email'        => $email,
            'name'         => 'Test Person',
            'status'       => $status,
            'employeeId'   => $staffId,
            'departmentId' => $dept,
            'jobTitleId'   => $title,
            'customFields' => $custom,
        ];
    }
}
