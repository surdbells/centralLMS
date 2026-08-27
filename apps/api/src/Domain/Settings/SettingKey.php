<?php

declare(strict_types=1);

namespace Central\Api\Domain\Settings;

/**
 * Every setting a tenant may change.
 *
 * A closed enum, so a key that is not here does not exist. The legacy's
 * `updateSetting($category, $key, $value)` took both from the request body and
 * wrote whatever it was given, which is how a settings table ends up holding
 * `primary_colour` next to `primary_color` with nothing to say which is read.
 */
enum SettingKey: string
{
    // --- general ----------------------------------------------------------
    case Timezone   = 'timezone';
    case Language   = 'language';
    case DateFormat = 'date_format';
    case TimeFormat = 'time_format';
    case Currency   = 'currency';

    // --- branding ---------------------------------------------------------
    // The only category with any data in the legacy: eight keys, two tenants,
    // written once and never touched again.
    case LogoUrl             = 'logo_url';
    case FaviconUrl          = 'favicon_url';
    case PrimaryColour       = 'primary_color';
    case SecondaryColour     = 'secondary_color';
    case AccentColour        = 'accent_color';
    case LoginBackgroundUrl  = 'login_background_url';
    case EmailHeaderLogoUrl  = 'email_header_logo_url';
    case ShowPoweredBy       = 'show_powered_by';

    // --- gamification -----------------------------------------------------
    case GamificationEnabled    = 'gamification_enabled';
    case PointsPerLesson        = 'points_per_lesson';
    case PointsPerCourse        = 'points_per_course';
    case PointsPerAssessment    = 'points_per_assessment';
    case PointsPerCertificate   = 'points_per_certificate';
    case LeaderboardEnabled     = 'leaderboard_enabled';
    case LeaderboardSize        = 'leaderboard_size';
    case BadgesEnabled          = 'badges_enabled';

    // --- security ---------------------------------------------------------
    case PasswordMinLength        = 'password_min_length';
    case PasswordRequireUppercase = 'password_require_uppercase';
    case PasswordRequireLowercase = 'password_require_lowercase';
    case PasswordRequireNumber    = 'password_require_number';
    case PasswordRequireSpecial   = 'password_require_special';

    // Who may make themselves an account here.
    case PublicRegistration       = 'public_registration';
    case RegistrationDomains      = 'registration_domains';

    public function category(): SettingCategory
    {
        return match ($this) {
            self::Timezone, self::Language, self::DateFormat,
            self::TimeFormat, self::Currency => SettingCategory::General,

            self::LogoUrl, self::FaviconUrl, self::PrimaryColour,
            self::SecondaryColour, self::AccentColour, self::LoginBackgroundUrl,
            self::EmailHeaderLogoUrl, self::ShowPoweredBy => SettingCategory::Branding,

            self::GamificationEnabled, self::PointsPerLesson, self::PointsPerCourse,
            self::PointsPerAssessment, self::PointsPerCertificate,
            self::LeaderboardEnabled, self::LeaderboardSize,
            self::BadgesEnabled => SettingCategory::Gamification,

            self::PasswordMinLength, self::PasswordRequireUppercase,
            self::PasswordRequireLowercase, self::PasswordRequireNumber,
            self::PasswordRequireSpecial,
            self::PublicRegistration, self::RegistrationDomains => SettingCategory::Security,
        };
    }

    /**
     * @return list<self>
     */
    public static function inCategory(SettingCategory $category): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $key): bool => $key->category() === $category,
        ));
    }
}
