<?php

declare(strict_types=1);

namespace Central\Api\Support;

/**
 * The kind of device a user-agent string describes: desktop, mobile or tablet.
 *
 * Deliberately coarse. Analytics ask "phone or computer", not the model, and a
 * full device database is a dependency and a maintenance burden for an answer
 * nobody drills into. Order matters: a tablet UA often also carries the mobile
 * markers, so tablets are decided first.
 */
final class DeviceDetector
{
    public const DESKTOP = 'desktop';
    public const MOBILE  = 'mobile';
    public const TABLET  = 'tablet';

    public static function classify(?string $userAgent): string
    {
        $ua = strtolower(trim((string) $userAgent));

        if ($ua === '') {
            return self::DESKTOP;
        }

        // The Capacitor shell the mobile apps run in is always a phone, and its
        // Android WebView UA may omit the "mobile" token that the tablet rule
        // below keys on — so decide it before that rule can misfile it.
        if (str_contains($ua, 'capacitor')) {
            return self::MOBILE;
        }

        // Tablets first: an iPad or an Android tablet carries "mobile"-ish
        // markers too, so deciding mobile first would misfile every tablet.
        // Modern iPadOS reports a desktop Safari UA, which we cannot tell from
        // a Mac — that one unavoidably counts as desktop.
        if (
            str_contains($ua, 'ipad')
            || str_contains($ua, 'tablet')
            || str_contains($ua, 'kindle')
            || str_contains($ua, 'silk')
            || str_contains($ua, 'playbook')
            // Android without "mobile" is, by Google's own convention, a tablet.
            || (str_contains($ua, 'android') && !str_contains($ua, 'mobile'))
        ) {
            return self::TABLET;
        }

        if (
            str_contains($ua, 'mobi')          // covers "Mobile" and "Opera Mobi"
            || str_contains($ua, 'iphone')
            || str_contains($ua, 'ipod')
            || str_contains($ua, 'windows phone')
            || str_contains($ua, 'blackberry')
            || str_contains($ua, 'bb10')
        ) {
            return self::MOBILE;
        }

        return self::DESKTOP;
    }
}
