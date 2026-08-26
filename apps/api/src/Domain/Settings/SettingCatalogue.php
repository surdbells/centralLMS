<?php

declare(strict_types=1);

namespace Central\Api\Domain\Settings;

/**
 * The declared settings, with their defaults and their rules.
 *
 * One place, the same arrangement as {@see \Central\Api\Domain\Report\ReportCatalogue}.
 * A setting is defined here or it does not exist.
 *
 * ## What is not here
 *
 * `TenantSettings.php` declared eight categories and around seventy keys. Only
 * `branding` was ever written, and most of the rest were read by nothing at
 * all — a settings screen offering a value that no code consults is a form
 * that lies to whoever fills it in. Four categories are therefore not carried:
 *
 *  - **learning** — `quiz_passing_score`, `max_quiz_attempts` and
 *    `show_correct_answers` are per-assessment in this API and set where the
 *    assessment is authored, which is the right place for them: two exams in
 *    one organisation legitimately differ. `require_sequential_lessons`,
 *    `video_completion_threshold` and `auto_enroll_new_users` describe
 *    behaviour that does not exist yet; each needs building before it can be
 *    configured. `certificate_expiry_days` needs the certificate module to
 *    consult a tenant default at issue.
 *  - **storage** — the per-kind size limits live in {@see
 *    \Central\Api\Domain\Media\MediaKind}, which is an engineering limit rather
 *    than a preference, and the tenant's actual lever is `storage_limit_mb` on
 *    the tenant record, which the media module already enforces.
 *  - **notifications** — the per-type switches duplicate the per-person
 *    preferences the notification module already keeps. Tenant-wide defaults
 *    over the top are a real feature, and they need the preference resolver to
 *    take a second layer; until then, offering both would leave two switches
 *    for one lamp.
 *  - **integrations** — SSO, API access and webhooks are not built. There is
 *    nothing to configure.
 *
 * `general`'s `company_name`, `company_email`, `company_phone` and
 * `company_address` are not here either: those are the tenant's own record, and
 * keeping a second copy in a key/value table is how the two come to disagree.
 */
final class SettingCatalogue
{
    /** @var array<string, SettingDefinition> */
    private array $definitions;

    public function __construct()
    {
        $definitions = [];

        foreach (self::build() as $definition) {
            $definitions[$definition->key->value] = $definition;
        }

        $this->definitions = $definitions;
    }

    public function find(SettingKey $key): SettingDefinition
    {
        return $this->definitions[$key->value]
            ?? throw new \LogicException(sprintf('No definition for setting "%s".', $key->value));
    }

    public function findByName(string $key): ?SettingDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * @return list<SettingDefinition>
     */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    /**
     * @return list<SettingDefinition>
     */
    public function inCategory(SettingCategory $category): array
    {
        return array_values(array_filter(
            $this->definitions,
            static fn (SettingDefinition $d): bool => $d->category() === $category,
        ));
    }

    /**
     * Every default, as a tenant that has changed nothing would see them.
     *
     * @return array<string, string|int|bool>
     */
    public function defaults(): array
    {
        $defaults = [];

        foreach ($this->definitions as $key => $definition) {
            $defaults[$key] = $definition->default;
        }

        return $defaults;
    }

    /**
     * @return list<SettingDefinition>
     */
    private static function build(): array
    {
        return [
            // --- general --------------------------------------------------
            new SettingDefinition(
                key: SettingKey::Timezone,
                type: SettingType::Choice,
                label: 'Timezone',
                default: 'Africa/Lagos',
                help: 'Deadlines, session times and reports are shown in this zone.',
                choices: \DateTimeZone::listIdentifiers(),
                honouredBy: 'Due dates and ILT session times, wherever they are shown.',
            ),
            new SettingDefinition(
                key: SettingKey::Language,
                type: SettingType::Choice,
                label: 'Language',
                default: 'en',
                choices: ['en', 'fr', 'ar'],
                honouredBy: 'The default for a new account; each person may override it.',
            ),
            new SettingDefinition(
                key: SettingKey::DateFormat,
                type: SettingType::Choice,
                label: 'Date format',
                default: 'Y-m-d',
                choices: ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd M Y'],
                honouredBy: 'How the web app prints a date.',
            ),
            new SettingDefinition(
                key: SettingKey::TimeFormat,
                type: SettingType::Choice,
                label: 'Time format',
                default: 'H:i',
                choices: ['H:i', 'h:i A'],
                honouredBy: 'How the web app prints a time.',
            ),
            new SettingDefinition(
                key: SettingKey::Currency,
                type: SettingType::Choice,
                label: 'Currency',
                default: 'NGN',
                choices: ['NGN', 'USD', 'EUR', 'GBP', 'ZAR', 'KES', 'GHS'],
                honouredBy: 'Course prices and the cost columns in reports.',
            ),

            // --- branding -------------------------------------------------
            new SettingDefinition(
                key: SettingKey::LogoUrl,
                type: SettingType::Url,
                label: 'Logo',
                default: '',
                help: 'Shown in the header. Upload it to the media library and paste its address.',
                honouredBy: 'The web app header and the certificate templates.',
            ),
            new SettingDefinition(
                key: SettingKey::FaviconUrl,
                type: SettingType::Url,
                label: 'Favicon',
                default: '',
                honouredBy: 'The browser tab icon.',
            ),
            new SettingDefinition(
                key: SettingKey::PrimaryColour,
                type: SettingType::Colour,
                label: 'Primary colour',
                default: '#c9a227',
                honouredBy: 'The web app theme.',
            ),
            new SettingDefinition(
                key: SettingKey::SecondaryColour,
                type: SettingType::Colour,
                label: 'Secondary colour',
                default: '#1a1a2e',
                honouredBy: 'The web app theme.',
            ),
            new SettingDefinition(
                key: SettingKey::AccentColour,
                type: SettingType::Colour,
                label: 'Accent colour',
                // The legacy's default table said #e8b923 and both tenants hold
                // #2149e8. The stored value wins: it is what people have been
                // looking at.
                default: '#2149e8',
                honouredBy: 'The web app theme.',
            ),
            new SettingDefinition(
                key: SettingKey::LoginBackgroundUrl,
                type: SettingType::Url,
                label: 'Sign-in background',
                default: '',
                honouredBy: 'The sign-in page.',
            ),
            new SettingDefinition(
                key: SettingKey::EmailHeaderLogoUrl,
                type: SettingType::Url,
                label: 'Email logo',
                default: '',
                help: 'Falls back to the main logo when empty.',
                honouredBy: 'The header of every notification email.',
            ),
            new SettingDefinition(
                key: SettingKey::ShowPoweredBy,
                type: SettingType::Boolean,
                label: 'Show "powered by"',
                default: true,
                honouredBy: 'The web app footer.',
            ),

            // --- gamification ---------------------------------------------
            new SettingDefinition(
                key: SettingKey::GamificationEnabled,
                type: SettingType::Boolean,
                label: 'Points and badges',
                default: true,
                help: 'Turning this off stops new points being granted. Nothing already earned is lost.',
                honouredBy: 'Awarder — nothing is granted while this is off.',
            ),
            new SettingDefinition(
                key: SettingKey::PointsPerLesson,
                type: SettingType::Integer,
                label: 'Points for finishing a lesson',
                default: 10,
                min: 0,
                max: 10_000,
                honouredBy: 'Awarder, when a lesson is completed.',
            ),
            new SettingDefinition(
                key: SettingKey::PointsPerCourse,
                type: SettingType::Integer,
                label: 'Points for finishing a course',
                default: 100,
                min: 0,
                max: 10_000,
                honouredBy: 'Awarder, when a course is completed.',
            ),
            new SettingDefinition(
                key: SettingKey::PointsPerAssessment,
                type: SettingType::Integer,
                label: 'Points for passing an assessment',
                default: 25,
                min: 0,
                max: 10_000,
                honouredBy: 'Awarder, when an assessment is passed.',
            ),
            new SettingDefinition(
                key: SettingKey::PointsPerCertificate,
                type: SettingType::Integer,
                label: 'Points for earning a certificate',
                default: 50,
                min: 0,
                max: 10_000,
                honouredBy: 'Awarder, when a certificate is issued.',
            ),
            new SettingDefinition(
                key: SettingKey::LeaderboardEnabled,
                type: SettingType::Boolean,
                label: 'Leaderboard',
                default: true,
                honouredBy: 'The leaderboard endpoint, which is empty while this is off.',
            ),
            new SettingDefinition(
                key: SettingKey::LeaderboardSize,
                type: SettingType::Integer,
                label: 'People on the leaderboard',
                default: 10,
                min: 3,
                max: 100,
                honouredBy: 'The leaderboard endpoint, as its default page size.',
            ),
            new SettingDefinition(
                key: SettingKey::BadgesEnabled,
                type: SettingType::Boolean,
                label: 'Badges',
                default: true,
                honouredBy: 'Awarder — no badge is granted while this is off.',
            ),

            // --- security -------------------------------------------------
            new SettingDefinition(
                key: SettingKey::PasswordMinLength,
                type: SettingType::Integer,
                label: 'Shortest password',
                // Twelve, which is what the API already enforced. Length does
                // more for strength than any character-class rule.
                default: 12,
                // Never below eight, whatever a tenant would prefer: a setting
                // that can be turned down to 1 is not a policy.
                min: 8,
                max: 64,
                honouredBy: 'PasswordPolicy, wherever a password is set or changed.',
            ),
            new SettingDefinition(
                key: SettingKey::PasswordRequireUppercase,
                type: SettingType::Boolean,
                label: 'Require a capital letter',
                default: false,
                honouredBy: 'PasswordPolicy.',
            ),
            new SettingDefinition(
                key: SettingKey::PasswordRequireLowercase,
                type: SettingType::Boolean,
                label: 'Require a small letter',
                default: true,
                honouredBy: 'PasswordPolicy.',
            ),
            new SettingDefinition(
                key: SettingKey::PasswordRequireNumber,
                type: SettingType::Boolean,
                label: 'Require a number',
                default: true,
                honouredBy: 'PasswordPolicy.',
            ),
            new SettingDefinition(
                key: SettingKey::PasswordRequireSpecial,
                type: SettingType::Boolean,
                label: 'Require a symbol',
                default: false,
                honouredBy: 'PasswordPolicy.',
            ),
        ];
    }
}
