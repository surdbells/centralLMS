<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * How one organisation's mail looks: its name, its logo, its colour.
 *
 * Built once per tenant from the Branding settings (see
 * {@see \Central\Api\Application\Notification\EmailBrandingResolver}) and
 * handed to {@see EmailLayout}. Everything in here has already been checked,
 * because it is about to be written into an email nobody can recall:
 *
 *  - the logo is only kept when an inbox can actually fetch it — an absolute
 *    https address with no short-lived `ticket=` in it. A media-library link
 *    pasted into the logo field expires in minutes and would show as a broken
 *    image in every email sent after that, so it is dropped and the
 *    organisation's name is shown as text instead;
 *  - the colour is a real hex colour or the platform default, never whatever
 *    string happened to be stored (it lands inside a `style` attribute);
 *  - the colours that sit *on* or *beside* the brand colour are worked out for
 *    contrast, so a pale yellow brand gets dark button text rather than white
 *    on yellow nobody can read.
 */
final readonly class EmailBranding
{
    public const DEFAULT_COLOUR = '#1f4e79';

    /** Text on the brand colour (button labels). */
    public string $onPrimary;

    /**
     * The brand colour, darkened only as far as needed to read as text on
     * white — for links and the organisation name when there is no logo.
     */
    public string $ink;

    public string $primary;

    public ?string $logoUrl;

    public string $siteUrl;

    public function __construct(
        public string $organisation,
        string $primary,
        ?string $logoUrl = null,
        string $siteUrl = '',
        public bool $showPoweredBy = false,
        public string $platformName = 'Central LMS',
    ) {
        $this->primary   = self::colour($primary);
        $this->logoUrl   = self::publicImageUrl($logoUrl);
        $this->siteUrl   = self::webAddress($siteUrl);
        $this->onPrimary = self::textOn($this->primary);
        $this->ink       = self::inkFor($this->primary);
    }

    /** The platform look, for when an organisation's settings cannot be read. */
    public static function fallback(string $organisation = '', string $siteUrl = ''): self
    {
        return new self($organisation, self::DEFAULT_COLOUR, null, $siteUrl);
    }

    /**
     * A #rgb or #rrggbb colour, normalised to lower-case #rrggbb; anything
     * else becomes the fallback.
     */
    public static function colour(string $value, string $fallback = self::DEFAULT_COLOUR): string
    {
        $value = strtolower(trim($value));

        if (preg_match('/^#([0-9a-f]{3})$/', $value, $m) === 1) {
            return '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }

        if (preg_match('/^#([0-9a-f]{6})([0-9a-f]{2})?$/', $value, $m) === 1) {
            // An alpha channel means nothing on a solid email background.
            return '#' . $m[1];
        }

        return strtolower(trim($fallback)) === self::DEFAULT_COLOUR
            ? self::DEFAULT_COLOUR
            : self::colour($fallback);
    }

    /**
     * A logo address an email client will actually load, or null.
     *
     * Must be absolute https (mail clients proxy images and many refuse plain
     * http), and must not carry a signed `ticket=` — those are the media
     * library's minute-long links.
     */
    public static function publicImageUrl(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            return null;
        }

        $query = (string) parse_url($url, PHP_URL_QUERY);

        if (preg_match('/(^|&)(ticket|token|signature|X-Amz-Signature)=/i', $query) === 1) {
            return null;
        }

        return $url;
    }

    /** WCAG relative luminance of a #rrggbb colour. */
    public static function luminance(string $hex): float
    {
        $hex = self::colour($hex);
        $channels = [
            hexdec(substr($hex, 1, 2)),
            hexdec(substr($hex, 3, 2)),
            hexdec(substr($hex, 5, 2)),
        ];

        $linear = array_map(static function (int|float $c): float {
            $c = $c / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $channels);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }

    /** WCAG contrast ratio between two colours (1 to 21). */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** White or near-black, whichever reads better on the colour. */
    public static function textOn(string $background): string
    {
        return self::contrast($background, '#ffffff') >= self::contrast($background, '#111827')
            ? '#ffffff'
            : '#111827';
    }

    /**
     * The brand colour as text on white: kept as-is when it already reads
     * (contrast of 4.5:1), otherwise darkened step by step until it does.
     */
    public static function inkFor(string $colour): string
    {
        $colour = self::colour($colour);

        for ($i = 0; $i < 20 && self::contrast($colour, '#ffffff') < 4.5; $i++) {
            $colour = self::darken($colour, 0.12);
        }

        return $colour;
    }

    private static function darken(string $hex, float $by): string
    {
        $out = '#';

        foreach ([1, 3, 5] as $offset) {
            $channel = (int) round(hexdec(substr($hex, $offset, 2)) * (1 - $by));
            $out    .= str_pad(dechex(max(0, $channel)), 2, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    private static function webAddress(string $url): string
    {
        $url = rtrim(trim($url), '/');

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }

        return in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : '';
    }
}
