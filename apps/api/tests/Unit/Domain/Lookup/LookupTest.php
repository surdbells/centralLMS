<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Lookup;

use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Lookup\Lookup;
use Central\Api\Domain\Lookup\LookupKind;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Lookup::class)]
#[CoversClass(LookupKind::class)]
final class LookupTest extends TestCase
{
    private const TENANT = 'tenant-1';
    private const ID = 'lookup-1';

    // --- kind resolution ----------------------------------------------------

    /**
     * @return iterable<string, array{string, LookupKind}>
     */
    public static function slugs(): iterable
    {
        yield 'singular'        => ['branch', LookupKind::Branch];
        yield 'plural'          => ['branches', LookupKind::Branch];
        yield 'underscored'     => ['job_title', LookupKind::JobTitle];
        yield 'hyphenated'      => ['job-title', LookupKind::JobTitle];
        yield 'plural job'      => ['job_titles', LookupKind::JobTitle];
        yield 'legacy jobtitles' => ['jobtitles', LookupKind::JobTitle];
        yield 'legacy categories' => ['categories', LookupKind::Category];
        yield 'mixed case'      => ['Departments', LookupKind::Department];
    }

    #[Test]
    #[DataProvider('slugs')]
    public function it_resolves_every_url_form_of_a_kind(string $slug, LookupKind $expected): void
    {
        // The legacy paths used /dictionary/jobtitles and /dictionary/team, so
        // both old and new spellings must resolve during the migration.
        self::assertSame($expected, LookupKind::fromSlug($slug));
    }

    #[Test]
    public function an_unknown_slug_resolves_to_null_rather_than_a_default(): void
    {
        // Defaulting would let /v1/lookups/nonsense quietly list branches.
        self::assertNull(LookupKind::fromSlug('nonsense'));
        self::assertNull(LookupKind::fromSlug(''));
    }

    // --- validation ---------------------------------------------------------

    #[Test]
    public function it_builds_a_valid_entry(): void
    {
        $lookup = Lookup::fromInput(self::ID, self::TENANT, LookupKind::Branch, [
            'name'        => '  Lagos HQ  ',
            'code'        => ' LOS ',
            'description' => 'Head office',
            'attributes'  => ['city' => 'Lagos', 'country' => 'Nigeria'],
        ]);

        self::assertSame('Lagos HQ', $lookup->name, 'Name should be trimmed.');
        self::assertSame('LOS', $lookup->code);
        self::assertSame(['city' => 'Lagos', 'country' => 'Nigeria'], $lookup->attributes);
        self::assertTrue($lookup->isActive);
    }

    #[Test]
    public function a_name_is_required(): void
    {
        foreach (['', '   ', null] as $name) {
            try {
                Lookup::fromInput(self::ID, self::TENANT, LookupKind::Team, ['name' => $name]);
                self::fail('Expected a blank name to be rejected.');
            } catch (ValidationFailed $e) {
                self::assertArrayHasKey('name', $e->details()['violations']);
            }
        }
    }

    #[Test]
    public function a_code_is_rejected_for_kinds_that_have_none(): void
    {
        // Job titles and categories never had a code column.
        $this->expectException(ValidationFailed::class);

        Lookup::fromInput(self::ID, self::TENANT, LookupKind::JobTitle, [
            'name' => 'Engineer',
            'code' => 'ENG',
        ]);
    }

    #[Test]
    public function an_empty_code_is_treated_as_absent_not_as_a_violation(): void
    {
        // A form that submits an empty string must not fail for a kind that
        // does not use codes.
        $lookup = Lookup::fromInput(self::ID, self::TENANT, LookupKind::JobTitle, [
            'name' => 'Engineer',
            'code' => '',
        ]);

        self::assertNull($lookup->code);
    }

    #[Test]
    public function a_parent_is_rejected_for_kinds_that_do_not_nest(): void
    {
        // Only categories form a tree; a nested branch would render nowhere.
        $this->expectException(ValidationFailed::class);

        Lookup::fromInput(self::ID, self::TENANT, LookupKind::Branch, [
            'name'     => 'Abuja',
            'parentId' => 'some-other-id',
        ]);
    }

    #[Test]
    public function a_category_may_have_a_parent(): void
    {
        $lookup = Lookup::fromInput(self::ID, self::TENANT, LookupKind::Category, [
            'name'     => 'Safety',
            'parentId' => 'parent-1',
        ]);

        self::assertSame('parent-1', $lookup->parentId);
    }

    #[Test]
    public function an_entry_cannot_be_its_own_parent(): void
    {
        // Would make the row its own ancestor and hang any tree walk.
        $this->expectException(ValidationFailed::class);

        Lookup::fromInput(self::ID, self::TENANT, LookupKind::Category, [
            'name'     => 'Loop',
            'parentId' => self::ID,
        ]);
    }

    #[Test]
    public function unknown_attributes_are_rejected_rather_than_silently_stored(): void
    {
        // A typo'd field that persists is a bug nobody finds until a report is
        // wrong.
        try {
            Lookup::fromInput(self::ID, self::TENANT, LookupKind::Branch, [
                'name'       => 'Lagos',
                'attributes' => ['city' => 'Lagos', 'postcode' => '100001'],
            ]);
            self::fail('Expected an unknown attribute to be rejected.');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('attributes', $e->details()['violations']);
        }
    }

    #[Test]
    public function kinds_with_no_attributes_reject_all_of_them(): void
    {
        $this->expectException(ValidationFailed::class);

        Lookup::fromInput(self::ID, self::TENANT, LookupKind::Department, [
            'name'       => 'Engineering',
            'attributes' => ['anything' => 'at all'],
        ]);
    }

    #[Test]
    public function over_long_values_are_rejected(): void
    {
        try {
            Lookup::fromInput(self::ID, self::TENANT, LookupKind::Team, [
                'name'        => str_repeat('a', 151),
                'description' => str_repeat('b', 501),
            ]);
            self::fail('Expected over-long values to be rejected.');
        } catch (ValidationFailed $e) {
            $violations = $e->details()['violations'];
            self::assertArrayHasKey('name', $violations);
            self::assertArrayHasKey('description', $violations);
        }
    }

    // --- serialisation ------------------------------------------------------

    #[Test]
    public function the_payload_omits_fields_the_kind_does_not_use(): void
    {
        // So a client never renders an empty "Code" box for a job title.
        $jobTitle = Lookup::fromInput(self::ID, self::TENANT, LookupKind::JobTitle, ['name' => 'Engineer'])
            ->toArray();

        self::assertArrayNotHasKey('code', $jobTitle);
        self::assertArrayNotHasKey('parentId', $jobTitle);

        $branch = Lookup::fromInput(self::ID, self::TENANT, LookupKind::Branch, ['name' => 'Lagos'])
            ->toArray();

        self::assertArrayHasKey('code', $branch);

        $category = Lookup::fromInput(self::ID, self::TENANT, LookupKind::Category, ['name' => 'Safety'])
            ->toArray();

        self::assertArrayHasKey('parentId', $category);
    }

    #[Test]
    public function the_payload_never_leaks_the_tenant_id(): void
    {
        // The caller already knows their tenant; echoing it back is one more
        // place an id could be picked up from.
        $payload = Lookup::fromInput(self::ID, self::TENANT, LookupKind::Branch, ['name' => 'Lagos'])->toArray();

        self::assertArrayNotHasKey('tenantId', $payload);
    }

    #[Test]
    public function every_kind_has_a_distinct_plural_and_label(): void
    {
        $plurals = array_map(static fn (LookupKind $k): string => $k->plural(), LookupKind::cases());
        $labels = array_map(static fn (LookupKind $k): string => $k->label(), LookupKind::cases());

        self::assertSame(count($plurals), count(array_unique($plurals)));
        self::assertSame(count($labels), count(array_unique($labels)));
    }
}
