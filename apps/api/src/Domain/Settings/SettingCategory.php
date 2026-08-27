<?php

declare(strict_types=1);

namespace Central\Api\Domain\Settings;

/**
 * The tabs on the settings screen.
 *
 * Five, where the legacy declared eight. The three that are still missing —
 * learning, storage, integrations — are absent for the same reason the other
 * four were: nothing reads them. See {@see SettingCatalogue} for which, and
 * for what each would need before it could be offered honestly.
 */
enum SettingCategory: string
{
    case General      = 'general';
    case Branding     = 'branding';
    case Gamification = 'gamification';
    case Security     = 'security';
    case Notifications = 'notifications';

    public function label(): string
    {
        return match ($this) {
            self::General      => 'General',
            self::Branding     => 'Branding',
            self::Gamification => 'Gamification',
            self::Security     => 'Security',
            self::Notifications => 'Notifications',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::General      => 'Language, timezone, and how dates and money are shown.',
            self::Branding     => 'Your logo and colours, wherever the platform shows them.',
            self::Gamification => 'Whether points and badges are used, and what each is worth.',
            self::Security     => 'What a password must look like.',
            self::Notifications => 'Who your email appears to come from, and whether it is sent at all.',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
