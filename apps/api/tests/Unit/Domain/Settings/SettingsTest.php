<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Settings;

use Central\Api\Domain\Gamification\PointReason;
use Central\Api\Domain\Settings\PasswordPolicy;
use Central\Api\Domain\Settings\SettingCatalogue;
use Central\Api\Domain\Settings\SettingCategory;
use Central\Api\Domain\Settings\SettingDefinition;
use Central\Api\Domain\Settings\SettingKey;
use Central\Api\Domain\Settings\SettingType;
use Central\Api\Domain\Settings\TenantSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What a tenant may configure, and what happens when they do.
 *
 * `TenantSettings.php` had no validation at all: `updateSetting()` cast the
 * value to whatever the `value_type` column said and wrote it, so a colour
 * could be `banana` and a password minimum could be zero. And `value_type` was
 * never consulted on the way back out, so a boolean saved as `"false"` came
 * back as the string `"false"`, which is true.
 */
#[CoversClass(SettingCatalogue::class)]
#[CoversClass(SettingDefinition::class)]
#[CoversClass(TenantSettings::class)]
#[CoversClass(PasswordPolicy::class)]
#[CoversClass(SettingKey::class)]
#[CoversClass(SettingCategory::class)]
final class SettingsTest extends TestCase
{
    private SettingCatalogue $catalogue;

    protected function setUp(): void
    {
        $this->catalogue = new SettingCatalogue();
    }

    // --- the catalogue ----------------------------------------------------

    #[Test]
    public function every_declared_key_has_a_definition(): void
    {
        // The enum is the identity and the catalogue is the description, so a
        // key added to one and forgotten in the other is a fatal error the
        // first time somebody opens the settings screen.
        foreach (SettingKey::cases() as $key) {
            self::assertSame($key, $this->catalogue->find($key)->key);
        }
    }

    #[Test]
    public function every_setting_says_what_reads_it(): void
    {
        // The check that stops a setting being added that nothing consults —
        // which is what seven-eighths of the legacy's catalogue was.
        foreach ($this->catalogue->all() as $definition) {
            self::assertNotSame('', $definition->honouredBy, $definition->key->value);
        }
    }

    #[Test]
    public function every_category_holds_something(): void
    {
        foreach (SettingCategory::cases() as $category) {
            self::assertNotSame([], $this->catalogue->inCategory($category), $category->value);
        }
    }

    #[Test]
    public function a_default_is_a_value_its_own_rules_would_accept(): void
    {
        // A default that fails its own validation is a settings screen that
        // refuses to save until you change something you never touched.
        foreach ($this->catalogue->all() as $definition) {
            $reason = null;

            self::assertNotNull(
                $definition->accept($definition->default, $reason),
                sprintf('%s: %s', $definition->key->value, (string) $reason),
            );
        }
    }

    #[Test]
    public function an_unknown_key_has_no_definition(): void
    {
        self::assertNull($this->catalogue->findByName('primary_colour'));
        self::assertNotNull($this->catalogue->findByName('primary_color'));
    }

    // --- validation -------------------------------------------------------

    #[Test]
    public function a_colour_must_be_a_colour(): void
    {
        $colour = $this->catalogue->find(SettingKey::PrimaryColour);
        $reason = null;

        self::assertSame('#c9a227', $colour->accept('#C9A227', $reason));
        self::assertSame('#abc', $colour->accept('#abc', $reason));
        self::assertNull($colour->accept('banana', $reason));
        self::assertNull($colour->accept('c9a227', $reason));
        // Reaches a stylesheet as an invalid declaration, which the browser
        // drops in silence — so the tenant sees no colour and no error.
        self::assertNull($colour->accept('rgb(201, 162, 39)', $reason));
    }

    #[Test]
    public function a_url_may_be_empty_or_relative_but_not_a_script(): void
    {
        $logo   = $this->catalogue->find(SettingKey::LogoUrl);
        $reason = null;

        self::assertSame('', $logo->accept('', $reason));
        self::assertSame('/v1/media/abc/file', $logo->accept('/v1/media/abc/file', $reason));
        self::assertSame('https://cdn.example/logo.png', $logo->accept('https://cdn.example/logo.png', $reason));

        // A stored cross-site script, and a settings screen is a pleasant
        // place to put one.
        self::assertNull($logo->accept('javascript:alert(1)', $reason));
        self::assertNull($logo->accept('data:text/html;base64,PHNjcmlwdD4=', $reason));
        self::assertNull($logo->accept('not a url', $reason));
    }

    #[Test]
    public function a_number_is_held_between_its_bounds(): void
    {
        $minimum = $this->catalogue->find(SettingKey::PasswordMinLength);
        $reason  = null;

        self::assertSame('16', $minimum->accept('16', $reason));
        // Never below eight, whatever a tenant would prefer: a policy that can
        // be turned down to one is not a policy.
        self::assertNull($minimum->accept(4, $reason));
        self::assertNull($minimum->accept(500, $reason));
        self::assertNull($minimum->accept('eight', $reason));
    }

    #[Test]
    public function a_choice_must_be_one_of_the_choices(): void
    {
        $timezone = $this->catalogue->find(SettingKey::Timezone);
        $reason   = null;

        self::assertSame('Europe/London', $timezone->accept('Europe/London', $reason));
        self::assertNull($timezone->accept('Middle/Earth', $reason));
    }

    #[Test]
    public function a_boolean_survives_the_round_trip(): void
    {
        // The legacy's bug exactly: `"false"` stored and read back as a string
        // is true, so a switch turned off stayed on.
        $switch = $this->catalogue->find(SettingKey::ShowPoweredBy);

        self::assertSame('false', $switch->accept(false));
        self::assertSame('false', $switch->accept('false'));
        self::assertSame('true', $switch->accept('1'));

        self::assertFalse($switch->parse('false'));
        self::assertTrue($switch->parse('true'));
    }

    // --- resolution -------------------------------------------------------

    #[Test]
    public function a_tenant_that_has_changed_nothing_gets_the_defaults(): void
    {
        $settings = TenantSettings::resolve('tenant-1', [], $this->catalogue);

        self::assertSame('#c9a227', $settings->string(SettingKey::PrimaryColour));
        self::assertTrue($settings->bool(SettingKey::ShowPoweredBy));
        self::assertSame(100, $settings->int(SettingKey::PointsPerCourse));
        self::assertFalse($settings->isOverridden(SettingKey::PrimaryColour));
    }

    #[Test]
    public function an_override_wins_and_says_that_it_did(): void
    {
        $settings = TenantSettings::resolve(
            'tenant-1',
            ['primary_color' => '#112233', 'show_powered_by' => 'false'],
            $this->catalogue,
        );

        self::assertSame('#112233', $settings->string(SettingKey::PrimaryColour));
        self::assertFalse($settings->bool(SettingKey::ShowPoweredBy));
        self::assertTrue($settings->isOverridden(SettingKey::PrimaryColour));
        // Untouched keys are still defaults, and still say so.
        self::assertFalse($settings->isOverridden(SettingKey::AccentColour));
    }

    #[Test]
    public function a_stored_key_that_is_no_longer_declared_is_ignored(): void
    {
        // A setting removed from the catalogue leaves rows behind. They must
        // not reappear as values nothing knows how to read.
        $settings = TenantSettings::resolve(
            'tenant-1',
            ['sso_provider' => 'okta', 'primary_color' => '#112233'],
            $this->catalogue,
        );

        self::assertArrayNotHasKey('sso_provider', $settings->all());
        self::assertSame('#112233', $settings->string(SettingKey::PrimaryColour));
    }

    #[Test]
    public function point_values_come_from_the_tenant(): void
    {
        // `points_per_lesson` and its neighbours were declared in the legacy
        // and read by nothing; the Awarder is the first thing to consult them.
        $settings = TenantSettings::resolve('tenant-1', ['points_per_lesson' => '3'], $this->catalogue);

        self::assertSame(3, $settings->pointsFor(PointReason::LessonCompleted));
        self::assertSame(100, $settings->pointsFor(PointReason::CourseCompleted));
    }

    #[Test]
    public function a_manual_award_is_not_configurable(): void
    {
        // It carries the amount the person granting it typed; defaulting it
        // from a setting would quietly overwrite that.
        $settings = TenantSettings::resolve('tenant-1', [], $this->catalogue);

        self::assertSame(
            PointReason::Manual->defaultPoints(),
            $settings->pointsFor(PointReason::Manual),
        );
    }

    #[Test]
    public function every_point_reason_resolves_to_a_number(): void
    {
        $settings = TenantSettings::resolve('tenant-1', [], $this->catalogue);

        foreach (PointReason::cases() as $reason) {
            self::assertGreaterThanOrEqual(0, $settings->pointsFor($reason), $reason->value);
        }
    }

    // --- the password policy ----------------------------------------------

    #[Test]
    public function the_default_policy_is_what_the_api_already_enforced(): void
    {
        $policy = PasswordPolicy::default();

        self::assertTrue($policy->accepts('correct horse battery 9'));
        self::assertFalse($policy->accepts('short1'));
        self::assertFalse($policy->accepts('correcthorsebattery'));
    }

    #[Test]
    public function a_refusal_lists_every_reason_at_once(): void
    {
        // One at a time means three submissions and an abandoned form.
        $policy = PasswordPolicy::from(TenantSettings::resolve(
            'tenant-1',
            ['password_require_uppercase' => 'true', 'password_require_special' => 'true'],
            $this->catalogue,
        ));

        self::assertCount(4, $policy->reasonsToRefuse('abc'));
    }

    #[Test]
    public function the_rules_are_the_tenants(): void
    {
        $strict = PasswordPolicy::from(TenantSettings::resolve(
            'tenant-1',
            ['password_min_length' => '30', 'password_require_uppercase' => 'true'],
            $this->catalogue,
        ));

        // Fine under the defaults, too short and too lowercase under these.
        self::assertTrue(PasswordPolicy::default()->accepts('correct horse battery 9'));
        self::assertFalse($strict->accepts('correct horse battery 9'));
        self::assertTrue($strict->accepts('Correct horse battery staple 9'));
    }

    #[Test]
    public function a_space_counts_as_a_symbol(): void
    {
        // Deliberate. "Include a symbol" should be satisfiable by a
        // passphrase; a rule that forces punctuation is a rule that produces
        // `Passw0rd!` on a sticky note.
        $policy = PasswordPolicy::from(TenantSettings::resolve(
            'tenant-1',
            ['password_require_special' => 'true'],
            $this->catalogue,
        ));

        self::assertTrue($policy->accepts('correct horse battery 9'));
        self::assertFalse($policy->accepts('correcthorsebattery9'));
    }

    #[Test]
    public function the_rules_count_characters_not_bytes(): void
    {
        // A passphrase in Arabic is not three times longer than one in
        // English, and strlen() would say it is.
        $policy = PasswordPolicy::from(TenantSettings::resolve(
            'tenant-1',
            ['password_min_length' => '12', 'password_require_lowercase' => 'false'],
            $this->catalogue,
        ));

        self::assertFalse($policy->accepts('كلمةالسر1'));
    }

    #[Test]
    public function letters_outside_the_latin_alphabet_still_count(): void
    {
        $policy = PasswordPolicy::from(TenantSettings::resolve(
            'tenant-1',
            ['password_require_uppercase' => 'true'],
            $this->catalogue,
        ));

        // `[A-Z]` would refuse this; `\p{Lu}` accepts it.
        self::assertTrue($policy->accepts('Ωμέγα passphrase 9'));
    }

    #[Test]
    public function the_policy_can_describe_itself(): void
    {
        // The same values that do the checking, so the form cannot print one
        // rule and enforce another.
        self::assertSame(
            'At least 12 characters, including a small letter and a number.',
            PasswordPolicy::default()->describe(),
        );

        $everything = PasswordPolicy::from(TenantSettings::resolve(
            'tenant-1',
            ['password_require_uppercase' => 'true', 'password_require_special' => 'true'],
            $this->catalogue,
        ));

        self::assertSame(
            'At least 12 characters, including a capital letter, a small letter, a number and a symbol.',
            $everything->describe(),
        );
    }

    #[Test]
    public function a_policy_with_no_character_rules_still_reads_as_a_sentence(): void
    {
        $lax = PasswordPolicy::from(TenantSettings::resolve(
            'tenant-1',
            [
                'password_require_uppercase' => 'false',
                'password_require_lowercase' => 'false',
                'password_require_number'    => 'false',
                'password_require_special'   => 'false',
            ],
            $this->catalogue,
        ));

        self::assertSame('At least 12 characters.', $lax->describe());
    }

    // --- presentation -----------------------------------------------------

    #[Test]
    public function the_screen_is_grouped_by_category(): void
    {
        $payload = TenantSettings::resolve('tenant-1', [], $this->catalogue)->toArray($this->catalogue);

        self::assertSame('tenant-1', $payload['tenantId']);
        self::assertCount(count(SettingCategory::cases()), $payload['categories']);

        foreach ($payload['categories'] as $category) {
            self::assertArrayHasKey('label', $category);
            self::assertNotSame([], $category['settings']);

            foreach ($category['settings'] as $setting) {
                self::assertArrayHasKey('value', $setting);
                self::assertArrayHasKey('isCustom', $setting);
                self::assertSame($category['category'], $setting['category']);
            }
        }
    }

    #[Test]
    public function a_type_is_declared_once_and_belongs_to_the_key(): void
    {
        // Not to the row, which is where the legacy kept it — and then never
        // read it.
        self::assertSame(SettingType::Colour, $this->catalogue->find(SettingKey::AccentColour)->type);
        self::assertSame(SettingType::Boolean, $this->catalogue->find(SettingKey::BadgesEnabled)->type);
        self::assertSame(SettingType::Integer, $this->catalogue->find(SettingKey::LeaderboardSize)->type);
    }

    #[Test]
    public function a_key_belongs_to_exactly_one_category(): void
    {
        $seen = [];

        foreach (SettingCategory::cases() as $category) {
            foreach (SettingKey::inCategory($category) as $key) {
                self::assertArrayNotHasKey($key->value, $seen, $key->value);
                $seen[$key->value] = $category;
            }
        }

        self::assertCount(count(SettingKey::cases()), $seen);
    }
}
