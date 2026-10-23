<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\CustomField;

use Central\Api\Domain\CustomField\CustomFieldDefinition;
use Central\Api\Domain\CustomField\CustomFieldType;
use Central\Api\Domain\CustomField\CustomFieldValues;
use Central\Api\Domain\Exception\ValidationFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a tenant's custom profile fields will and will not accept.
 */
#[CoversClass(CustomFieldDefinition::class)]
#[CoversClass(CustomFieldType::class)]
#[CoversClass(CustomFieldValues::class)]
final class CustomFieldTest extends TestCase
{
    private const TENANT = 'tenant-1';
    private const ID = '11111111-1111-4111-8111-111111111111';

    // --- defining a field --------------------------------------------------

    #[Test]
    public function a_key_is_derived_from_the_label(): void
    {
        $field = CustomFieldDefinition::fromInput(self::ID, self::TENANT, [
            'label' => 'Organization / Subsidiary',
        ]);

        self::assertSame('organization_subsidiary', $field->key);
        self::assertSame(CustomFieldType::Text, $field->type);
    }

    #[Test]
    public function a_field_without_a_label_is_refused(): void
    {
        $this->expectException(ValidationFailed::class);

        CustomFieldDefinition::fromInput(self::ID, self::TENANT, ['label' => '   ']);
    }

    #[Test]
    public function a_dropdown_needs_at_least_one_option(): void
    {
        $this->expectException(ValidationFailed::class);

        CustomFieldDefinition::fromInput(self::ID, self::TENANT, [
            'label'   => 'Region',
            'type'    => 'dropdown',
            'options' => [],
        ]);
    }

    #[Test]
    public function a_dropdown_keeps_distinct_trimmed_options(): void
    {
        $field = CustomFieldDefinition::fromInput(self::ID, self::TENANT, [
            'label'   => 'Region',
            'type'    => 'dropdown',
            'options' => [' North ', 'South', 'North', ''],
        ]);

        self::assertSame(['North', 'South'], $field->options);
    }

    #[Test]
    public function an_unknown_type_is_refused(): void
    {
        $this->expectException(ValidationFailed::class);

        CustomFieldDefinition::fromInput(self::ID, self::TENANT, ['label' => 'X', 'type' => 'colour']);
    }

    #[Test]
    public function the_key_is_fixed_on_update(): void
    {
        // Renaming a key would orphan every value stored under the old one.
        $field = CustomFieldDefinition::fromInput(
            self::ID,
            self::TENANT,
            ['label' => 'Renamed', 'key' => 'ignored'],
            existingKey: 'division',
        );

        self::assertSame('division', $field->key);
        self::assertSame('Renamed', $field->label);
    }

    #[Test]
    public function options_are_omitted_from_the_payload_for_a_text_field(): void
    {
        $field = CustomFieldDefinition::fromInput(self::ID, self::TENANT, ['label' => 'Notes']);

        self::assertArrayNotHasKey('options', $field->toArray());
    }

    // --- coercing a value --------------------------------------------------

    #[Test]
    public function a_number_field_rejects_non_numbers(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CustomFieldType::Number->coerce('lots');
    }

    #[Test]
    public function a_number_field_keeps_a_numeric_value_as_a_float(): void
    {
        self::assertSame(42.0, CustomFieldType::Number->coerce('42'));
    }

    #[Test]
    public function a_date_field_rejects_a_non_date(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CustomFieldType::Date->coerce('soon');
    }

    #[Test]
    public function a_dropdown_rejects_a_value_off_the_list(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        CustomFieldType::Dropdown->coerce('West', ['North', 'South']);
    }

    #[Test]
    public function a_boolean_is_never_null(): void
    {
        self::assertFalse(CustomFieldType::Boolean->coerce(''));
        self::assertTrue(CustomFieldType::Boolean->coerce('1'));
        self::assertFalse(CustomFieldType::Boolean->coerce('false'));
    }

    #[Test]
    public function an_empty_text_value_is_null(): void
    {
        self::assertNull(CustomFieldType::Text->coerce('  '));
    }

    // --- validating a submitted map ---------------------------------------

    #[Test]
    public function values_are_validated_against_the_definitions(): void
    {
        $defs = [
            $this->def('division', CustomFieldType::Text),
            $this->def('headcount', CustomFieldType::Number),
        ];

        $violations = [];
        $clean = CustomFieldValues::validate(
            $defs,
            ['division' => 'Retail', 'headcount' => '120', 'unknown' => 'dropped'],
            [],
            $violations,
        );

        self::assertSame([], $violations);
        // The field this tenant does not have is dropped, not stored.
        self::assertSame(['division' => 'Retail', 'headcount' => 120.0], $clean);
    }

    #[Test]
    public function a_required_field_left_blank_is_a_violation(): void
    {
        $violations = [];

        CustomFieldValues::validate(
            [$this->def('division', CustomFieldType::Text, required: true)],
            ['division' => ''],
            [],
            $violations,
        );

        self::assertArrayHasKey('division', $violations);
    }

    #[Test]
    public function a_required_field_omitted_entirely_is_a_violation_on_create(): void
    {
        // The form never sends an untouched field's key, and on create there is
        // no stored value to fall back on — required must still bite.
        $violations = [];

        CustomFieldValues::validate(
            [$this->def('division', CustomFieldType::Text, required: true)],
            [],
            [],
            $violations,
        );

        self::assertArrayHasKey('division', $violations);
    }

    #[Test]
    public function a_required_field_omitted_but_already_stored_is_kept(): void
    {
        $violations = [];

        $clean = CustomFieldValues::validate(
            [$this->def('division', CustomFieldType::Text, required: true)],
            [],
            ['division' => 'Retail'],
            $violations,
        );

        self::assertSame([], $violations);
        self::assertSame(['division' => 'Retail'], $clean);
    }

    #[Test]
    public function values_of_fields_with_no_active_definition_are_preserved(): void
    {
        // A field was deactivated or deleted: its definition is gone from the
        // list, but a value stored under it must survive an unrelated save
        // rather than being wiped — deactivation is reversible.
        $violations = [];

        $clean = CustomFieldValues::validate(
            [$this->def('division', CustomFieldType::Text)],
            ['division' => 'Retail'],
            ['division' => 'Old', 'legacy_entity' => 'EU Ltd'],
            $violations,
        );

        self::assertSame([], $violations);
        self::assertSame('Retail', $clean['division']);
        self::assertArrayHasKey('legacy_entity', $clean);
        self::assertSame('EU Ltd', $clean['legacy_entity']);
    }

    #[Test]
    public function the_type_is_fixed_on_update(): void
    {
        // A dropdown whose values are stored cannot become a number field that
        // would reject them. The submitted type is ignored when existing is given.
        $field = CustomFieldDefinition::fromInput(
            self::ID,
            self::TENANT,
            ['label' => 'Region', 'type' => 'number', 'options' => ['North', 'South']],
            existingKey: 'region',
            existingType: CustomFieldType::Dropdown,
        );

        self::assertSame(CustomFieldType::Dropdown, $field->type);
        self::assertSame(['North', 'South'], $field->options);
    }

    #[Test]
    public function an_absent_field_keeps_its_existing_value(): void
    {
        // A form showing three of ten fields must not blank the other seven.
        $violations = [];

        $clean = CustomFieldValues::validate(
            [$this->def('division', CustomFieldType::Text), $this->def('entity', CustomFieldType::Text)],
            ['division' => 'Retail'],
            ['entity' => 'EU Ltd'],
            $violations,
        );

        self::assertSame(['division' => 'Retail', 'entity' => 'EU Ltd'], $clean);
    }

    #[Test]
    public function clearing_an_optional_field_drops_it_from_the_map(): void
    {
        $violations = [];

        $clean = CustomFieldValues::validate(
            [$this->def('entity', CustomFieldType::Text)],
            ['entity' => ''],
            ['entity' => 'Old Value'],
            $violations,
        );

        self::assertSame([], $clean);
        self::assertSame([], $violations);
    }

    /**
     * @param list<string> $options
     */
    private function def(
        string $key,
        CustomFieldType $type,
        bool $required = false,
        array $options = [],
    ): CustomFieldDefinition {
        return new CustomFieldDefinition(
            id: $key,
            tenantId: self::TENANT,
            key: $key,
            label: ucfirst($key),
            type: $type,
            options: $options,
            required: $required,
        );
    }
}
