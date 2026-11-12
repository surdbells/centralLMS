<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * The frame every email goes out in: the organisation's logo, its colour, its
 * name in the footer, and a link back to its site.
 *
 * Applied at send time, around whatever the message says — the platform's
 * default wording or an organisation's own — so that changing a logo or a
 * colour in Settings changes every email from then on, including templates
 * written before the change. The content itself never carries the frame.
 *
 * Written the way email has to be written, which is not the way the web is:
 * nested tables rather than flex or grid (Outlook renders with Word), every
 * style inline (Gmail drops most `<style>` blocks), a fluid card capped at 600px that
 * narrows on a phone, a solid colour bar rather than a background image, and
 * `bgcolor` attributes beside the CSS so a client that rewrites colours for
 * dark mode rewrites them consistently. A hidden preheader gives the inbox a
 * useful line of preview text instead of "Hi Ada,".
 */
final class EmailLayout
{
    public const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    private const CANVAS = '#f3f4f6';
    private const BORDER = '#e5e7eb';
    private const MUTED  = '#6b7280';

    /** Outlook-only fixed-width wrapper around a fluid table. */
    private const MSO_OPEN  = '<!--[if mso]><table role="presentation" width="600" align="center" cellpadding="0" '
        . 'cellspacing="0" border="0"><tr><td><![endif]-->';
    private const MSO_CLOSE = '<!--[if mso]></td></tr></table><![endif]-->';

    /**
     * The whole email: a complete HTML document around the content.
     *
     * @param string $innerHtml already-rendered content (placeholders filled)
     * @param string $subject   used as the document title
     * @param string $preheader the line inboxes show after the subject
     */
    public function wrap(string $innerHtml, EmailBranding $brand, string $subject = '', string $preheader = ''): string
    {
        $org      = self::e($brand->organisation !== '' ? $brand->organisation : $brand->platformName);
        $primary  = $brand->primary;
        $font     = self::FONT;
        $canvas   = self::CANVAS;
        $border   = self::BORDER;

        return '<!DOCTYPE html>'
            . '<html lang="en" xmlns="http://www.w3.org/1999/xhtml">'
            . '<head>'
            . '<meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta http-equiv="X-UA-Compatible" content="IE=edge">'
            . '<meta name="x-apple-disable-message-reformatting">'
            // A light, branded email: asking clients not to auto-invert keeps
            // the logo on the white it was designed for.
            . '<meta name="color-scheme" content="light">'
            . '<meta name="supported-color-schemes" content="light">'
            . '<title>' . self::e($subject) . '</title>'
            . '<style>'
            . 'body{margin:0!important;padding:0!important;width:100%!important;}'
            . 'a{color:' . $brand->ink . ';}'
            . 'img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;}'
            . '@media only screen and (max-width:620px){'
            . '.em-card{width:100%!important;max-width:100%!important;}'
            . '.em-pad{padding-left:22px!important;padding-right:22px!important;}'
            . '.em-outer{padding:16px 8px!important;}'
            . '}'
            . '</style>'
            . '</head>'
            . '<body style="margin:0;padding:0;background:' . $canvas . ';" bgcolor="' . $canvas . '">'
            . $this->preheader($preheader)
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="background:' . $canvas . ';" bgcolor="' . $canvas . '">'
            . '<tr><td class="em-outer" align="center" style="padding:32px 12px;">'

            // --- the card ------------------------------------------------
            // Fluid inline (100% up to 600px), so it fits a phone even where
            // the <style> block is dropped; Outlook, which ignores max-width,
            // gets a fixed 600px ghost table instead.
            . self::MSO_OPEN
            . '<table role="presentation" class="em-card" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:100%;max-width:600px;background:#ffffff;border:1px solid ' . $border . ';'
            . 'border-radius:12px;border-collapse:separate;" bgcolor="#ffffff">'

            // The brand bar: a solid cell, not an image, so it survives
            // images-off and every client's dark mode.
            . '<tr><td height="6" bgcolor="' . $primary . '" style="height:6px;line-height:6px;font-size:0;'
            . 'mso-line-height-rule:exactly;background:' . $primary . ';border-radius:12px 12px 0 0;">&nbsp;</td></tr>'

            . '<tr><td class="em-pad" align="left" style="padding:26px 40px 6px;font-family:' . $font . ';">'
            . $this->masthead($brand, $org)
            . '</td></tr>'

            . '<tr><td class="em-pad" align="left" style="padding:18px 40px 34px;font-family:' . $font . ';'
            . 'font-size:15px;line-height:1.6;color:#374151;">'
            . $innerHtml
            . '</td></tr>'

            . '<tr><td class="em-pad" align="left" style="padding:22px 40px 28px;border-top:1px solid ' . $border . ';'
            . 'font-family:' . $font . ';font-size:12px;line-height:1.6;color:' . self::MUTED . ';">'
            . $this->footer($brand, $org)
            . '</td></tr>'
            . '</table>'
            . self::MSO_CLOSE

            . $this->poweredBy($brand)
            . '</td></tr></table>'
            . '</body></html>';
    }

    /**
     * A plain-text alternative, for clients and people who prefer it.
     *
     * Built from the content rather than the whole document, so it does not
     * open with a stylesheet. Links keep their address in brackets.
     */
    public function text(string $innerHtml, EmailBranding $brand): string
    {
        $org  = $brand->organisation !== '' ? $brand->organisation : $brand->platformName;
        $text = self::plainText($innerHtml);

        $footer = "--\nSent by " . $org;

        if ($brand->siteUrl !== '') {
            $footer .= "\n" . $brand->siteUrl;
        }

        return trim($text) . "\n\n" . $footer;
    }

    /**
     * Readable text from HTML: links keep their address, block tags become
     * line breaks, the rest is stripped and entities decoded.
     */
    public static function plainText(string $html): string
    {
        // Head, styles and hidden preview text are not content.
        $html = preg_replace('#<(head|style|script)\b.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<div[^>]*display:\s*none[^>]*>.*?</div>#is', '', $html) ?? $html;
        $html = preg_replace('#<!--.*?-->#s', '', $html) ?? $html;

        $html = preg_replace_callback(
            '#<a\b[^>]*href="([^"]*)"[^>]*>(.*?)</a>#is',
            static function (array $m): string {
                $href  = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $label = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if ($href === '' || $href === $label || str_starts_with($href, 'mailto:')) {
                    return $label;
                }

                return $label === '' ? $href : $label . ' (' . $href . ')';
            },
            $html,
        ) ?? $html;

        $text = preg_replace('#</(?:p|h[1-6]|table)\s*>#i', "\n\n", $html) ?? $html;
        $text = preg_replace('#<(?:br|/div|/tr|/li)\s*/?>#i', "\n", $text) ?? $text;
        // A fact row reads "Course: Fire Safety".
        $text = preg_replace('#</td>\s*<td\b#i', ':</td> <td', $text) ?? $text;
        $text = preg_replace('#</td>#i', ' ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $text = str_replace("\u{00a0}", ' ', $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/^ +| +$/m', '', $text) ?? $text;

        // Collapse the runs of blank lines the stripping leaves behind.
        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    // --- building blocks the default wording uses -------------------------

    /**
     * A call-to-action button that survives Outlook: the colour is on the
     * table cell as well as the link, so the whole button is coloured even
     * where the link's padding is ignored.
     */
    public static function button(string $label, string $href, string $background, string $text): string
    {
        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:26px 0 10px;">'
            // mso-padding-alt gives Outlook (which drops the link's padding)
            // a full-size button; other clients use the link's own padding.
            . '<tr><td align="center" bgcolor="' . $background . '" style="border-radius:8px;background:' . $background
            . ';mso-padding-alt:13px 28px;">'
            . '<a href="' . $href . '" target="_blank" rel="noopener" style="display:inline-block;padding:13px 28px;'
            . 'font-family:' . self::FONT . ';font-size:15px;font-weight:600;line-height:20px;color:' . $text . ';'
            . 'text-decoration:none;border-radius:8px;border:1px solid ' . $background . ';">'
            . $label . '</a></td></tr></table>';
    }

    /**
     * A small table of the facts that matter — the course, the date, the time.
     *
     * @param list<array{0: string, 1: string, 2?: string}> $rows label, value,
     *        and optionally the placeholder the row depends on: when that
     *        placeholder renders empty the whole row is dropped.
     */
    public static function facts(array $rows): string
    {
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
            . 'style="margin:6px 0 22px;background:#f9fafb;border:1px solid ' . self::BORDER . ';border-radius:8px;'
            . 'border-collapse:separate;" bgcolor="#f9fafb"><tr><td style="padding:8px 20px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';

        foreach ($rows as $row) {
            $line = '<tr>'
                . '<td valign="top" style="padding:9px 12px 9px 0;width:36%;font-family:' . self::FONT . ';'
                . 'font-size:13px;line-height:1.5;color:' . self::MUTED . ';">' . $row[0] . '</td>'
                . '<td valign="top" style="padding:9px 0;font-family:' . self::FONT . ';font-size:14px;'
                . 'line-height:1.5;color:#111827;font-weight:600;">' . $row[1] . '</td>'
                . '</tr>';

            $html .= isset($row[2]) ? self::when($row[2], $line) : $line;
        }

        return $html . '</table></td></tr></table>';
    }

    /**
     * Content kept only when a placeholder has a value.
     *
     * The marker is an HTML comment, so a client that never sees it rendered
     * loses nothing; {@see EmailTemplateRenderer} resolves it before sending.
     */
    public static function when(string $placeholder, string $html): string
    {
        return '<!--if:' . $placeholder . '-->' . $html . '<!--/if:' . $placeholder . '-->';
    }

    public static function heading(string $text): string
    {
        return '<h1 style="margin:0 0 18px;font-family:' . self::FONT . ';font-size:22px;line-height:1.3;'
            . 'font-weight:700;color:#111827;">' . $text . '</h1>';
    }

    public static function paragraph(string $html, bool $muted = false): string
    {
        return '<p style="margin:0 0 16px;font-family:' . self::FONT . ';font-size:' . ($muted ? '13px' : '15px')
            . ';line-height:1.6;color:' . ($muted ? self::MUTED : '#374151') . ';">' . $html . '</p>';
    }

    // --- internals --------------------------------------------------------

    private function masthead(EmailBranding $brand, string $org): string
    {
        if ($brand->logoUrl !== null) {
            // width="140" is for Outlook (Word ignores CSS sizing); every
            // other client sizes it by CSS: natural size, scaled down
            // proportionally to fit 140x56.
            $img = '<img src="' . self::e($brand->logoUrl) . '" alt="' . $org . '" width="140" '
                . 'style="display:block;width:auto;height:auto;max-width:140px;max-height:56px;border:0;outline:none;'
                . 'font-family:' . self::FONT . ';font-size:18px;font-weight:700;color:' . $brand->ink . ';">';

            return $brand->siteUrl !== ''
                ? '<a href="' . self::e($brand->siteUrl) . '" target="_blank" style="text-decoration:none;">' . $img . '</a>'
                : $img;
        }

        // No logo an inbox can load: the name, set in the brand colour.
        $name = '<span style="font-family:' . self::FONT . ';font-size:20px;line-height:1.3;font-weight:700;'
            . 'color:' . $brand->ink . ';">' . $org . '</span>';

        return $brand->siteUrl !== ''
            ? '<a href="' . self::e($brand->siteUrl) . '" target="_blank" style="text-decoration:none;color:'
                . $brand->ink . ';">' . $name . '</a>'
            : $name;
    }

    private function footer(EmailBranding $brand, string $org): string
    {
        $html = '<p style="margin:0 0 6px;font-family:' . self::FONT . ';font-size:12px;line-height:1.6;color:'
            . self::MUTED . ';">Sent by <strong style="color:#374151;">' . $org . '</strong>'
            . ' because you have an account with us.</p>';

        if ($brand->siteUrl !== '') {
            $site  = self::e($brand->siteUrl);
            $host  = self::e((string) (parse_url($brand->siteUrl, PHP_URL_HOST) ?? $brand->siteUrl));
            $link  = 'style="color:' . $brand->ink . ';text-decoration:underline;"';

            $html .= '<p style="margin:0;font-family:' . self::FONT . ';font-size:12px;line-height:1.6;color:'
                . self::MUTED . ';">'
                . '<a href="' . $site . '" target="_blank" ' . $link . '>' . $host . '</a>'
                . ' &nbsp;&middot;&nbsp; '
                . '<a href="' . $site . '/mysettings" target="_blank" ' . $link . '>Notification settings</a>'
                . '</p>';
        }

        return $html;
    }

    private function poweredBy(EmailBranding $brand): string
    {
        if (!$brand->showPoweredBy) {
            return '';
        }

        return self::MSO_OPEN
            . '<table role="presentation" class="em-card" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:100%;max-width:600px;"><tr><td align="center" style="padding:16px 0 0;font-family:'
            . self::FONT . ';font-size:11px;color:#9ca3af;">Powered by ' . self::e($brand->platformName)
            . '</td></tr></table>'
            . self::MSO_CLOSE;
    }

    private function preheader(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        // The padding after it stops the inbox pulling body text into the
        // preview once the preheader runs out.
        return '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;'
            . 'overflow:hidden;mso-hide:all;color:' . self::CANVAS . ';">'
            . self::e(mb_substr($text, 0, 140))
            . str_repeat('&#8199;&#847;&zwnj;&nbsp;', 30)
            . '</div>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
