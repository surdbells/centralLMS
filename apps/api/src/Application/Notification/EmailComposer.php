<?php

declare(strict_types=1);

namespace Central\Api\Application\Notification;

use Central\Api\Domain\Notification\EmailBranding;
use Central\Api\Domain\Notification\EmailLayout;
use Central\Api\Domain\Notification\EmailTemplate;
use Central\Api\Domain\Notification\EmailTemplateDefaults;
use Central\Api\Domain\Notification\EmailTemplateRenderer;
use Central\Api\Domain\Notification\EmailTemplateRepository;
use Central\Api\Domain\Notification\NotificationComposer;
use Central\Api\Domain\Notification\NotificationType;
use Central\Api\Domain\Notification\PendingDelivery;

/**
 * Turns a notification into the email that is actually sent.
 *
 * Runs at send time, not when the notification is raised, so the bell, SMS and
 * push keep the plain sentence while email gets the full treatment:
 *
 *  1. the content is the organisation's own template where it has written
 *     one (Settings > Email templates), otherwise the platform's rich
 *     default ({@see EmailTemplateDefaults});
 *  2. the facts are filled in, HTML-escaped ({@see EmailTemplateRenderer::renderEmail()});
 *  3. the result is framed in the organisation's branded layout
 *     ({@see EmailLayout}) — logo, colour, name, footer, link home.
 *
 * Templates saved before the layout existed were copies of the old default,
 * which carried its own navy frame. Framing those again would put a card
 * inside a card, so {@see contentOf()} recognises the old frame and keeps only
 * what was inside it (re-colouring its button to the brand). A template that
 * is a whole HTML document of its own (`<html>` / `<body>`) is sent as written:
 * whoever wrote that has already decided how it looks.
 */
final readonly class EmailComposer
{
    /** Marks a delivery whose body is ready-made content to frame, not a notification to compose. */
    public const LAYOUT_ONLY = 'layout';

    /** Marks a delivery that is already a finished email. */
    public const PREBUILT = 'none';

    public function __construct(
        private EmailTemplateRepository $templates,
        private EmailTemplateRenderer $renderer,
        private EmailTemplateDefaults $defaults,
        private NotificationComposer $composer,
        private EmailBrandingResolver $branding,
        private EmailLayout $layout,
    ) {
    }

    /**
     * The email for a queued delivery.
     *
     * @return array{subject: string, html: string, text: string}
     */
    public function forDelivery(PendingDelivery $delivery): array
    {
        $mode = $delivery->data['emailLayout'] ?? null;

        if ($mode === self::PREBUILT) {
            return [
                'subject' => $delivery->title,
                'html'    => $delivery->body,
                'text'    => is_string($delivery->data['textBody'] ?? null)
                    ? $delivery->data['textBody']
                    : EmailLayout::plainText($delivery->body),
            ];
        }

        if ($mode === self::LAYOUT_ONLY) {
            return $this->frame($delivery->tenantId, $delivery->title, $delivery->body);
        }

        return $this->compose(
            $delivery->tenantId,
            $delivery->type,
            $delivery->data,
            $this->templates->find($delivery->tenantId, $delivery->type),
        );
    }

    /**
     * One type's email, with the given template (or the default).
     *
     * @param  array<string, mixed> $data
     * @return array{subject: string, html: string, text: string}
     */
    public function compose(
        string $tenantId,
        NotificationType $type,
        array $data,
        ?EmailTemplate $template,
        ?EmailBranding $brand = null,
    ): array {
        $brand ??= $this->branding->forTenant($tenantId);

        // An announcement is about itself: {{subject}} is its title.
        if ($type === NotificationType::Announcement && is_string($data['title'] ?? null) && $data['title'] !== '') {
            $data['subjectTitle'] = $data['title'];
        }

        $organisation = $brand->organisation !== '' ? $brand->organisation : $brand->platformName;
        $words        = $this->composer->compose($type, ['organisation' => $organisation, ...$data]);
        $custom       = $template !== null && $template->overrides() ? $template : null;

        // A custom subject is a template; the default line already holds the
        // facts (a course title may itself contain "{{...}}"), so it goes in
        // literally rather than through the placeholder filler.
        $subject = trim((string) $custom?->subject) !== '' ? (string) $custom?->subject : '';

        $frame = true;

        if (trim((string) $custom?->body) !== '') {
            ['html' => $body, 'frame' => $frame] = $this->contentOf((string) $custom?->body);
        } else {
            $body = $this->defaults->body($type);
        }

        $recipient = $data['recipientName'] ?? null;

        $filled = $this->renderer->renderEmail($subject, $body, $data, [
            'recipient_name'   => is_string($recipient) && trim($recipient) !== '' ? trim($recipient) : 'there',
            'organisation'     => $organisation,
            'default_body'     => $words['body'],
            // Every email may link somewhere; with nothing more specific, home.
            'link'             => $brand->siteUrl,
            'brand_color'      => $brand->primary,
            'brand_text_color' => $brand->onPrimary,
            'logo_url'         => $brand->logoUrl ?? '',
            'site_url'         => $brand->siteUrl,
        ]);

        if ($filled['title'] === '') {
            $filled['title'] = trim(preg_replace('/\s+/', ' ', $words['title']) ?? $words['title']);
        }

        $preheader = $this->preheader($words['body']);

        return [
            'subject' => $filled['title'],
            'html'    => $frame
                ? $this->layout->wrap($filled['body'], $brand, $filled['title'], $preheader)
                : $filled['body'],
            'text'    => $frame
                ? $this->layout->text($filled['body'], $brand)
                : EmailLayout::plainText($filled['body']),
        ];
    }

    /**
     * Ready-made HTML content framed in the organisation's layout — for mail
     * that is not a notification type, such as a scheduled report.
     *
     * @return array{subject: string, html: string, text: string}
     */
    public function frame(string $tenantId, string $subject, string $innerHtml, string $preheader = ''): array
    {
        $brand = $this->branding->forTenant($tenantId);

        return [
            'subject' => $subject,
            'html'    => $this->layout->wrap($innerHtml, $brand, $subject, $preheader),
            'text'    => $this->layout->text($innerHtml, $brand),
        ];
    }

    /**
     * The original body framed with the layout, escaped as text — the
     * last-resort email when composing fails.
     *
     * @return array{subject: string, html: string, text: string}
     */
    public function fallback(PendingDelivery $delivery): array
    {
        $mode = $delivery->data['emailLayout'] ?? null;

        // Only server-built mail (a finished email, or content made to be
        // framed) carries HTML. A notification's body is its plain sentence,
        // which can hold user-supplied text, so it is always escaped.
        if ($mode === self::PREBUILT) {
            return [
                'subject' => $delivery->title,
                'html'    => $delivery->body,
                'text'    => EmailLayout::plainText($delivery->body),
            ];
        }

        $inner = $mode === self::LAYOUT_ONLY
            ? $delivery->body
            : EmailLayout::paragraph(nl2br(
                htmlspecialchars($delivery->body, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8'),
                false,
            ));

        try {
            $brand = $this->branding->forTenant($delivery->tenantId);
        } catch (\Throwable) {
            $brand = EmailBranding::fallback();
        }

        return [
            'subject' => $delivery->title,
            'html'    => $this->layout->wrap($inner, $brand, $delivery->title),
            'text'    => EmailLayout::plainText($delivery->body),
        ];
    }

    /**
     * What a saved template contributes, and whether to frame it.
     *
     * @return array{html: string, frame: bool}
     */
    public function contentOf(string $saved): array
    {
        // A whole document: its author has decided how it looks.
        if (preg_match('/<\s*(html|body)\b/i', $saved) === 1) {
            return ['html' => $saved, 'frame' => false];
        }

        // The old default's frame: a 560px card with a #1f4e79 header. Keep
        // only the inside, and give its button the brand colour.
        if (str_contains($saved, 'max-width:560px') && stripos($saved, '#1f4e79') !== false) {
            $inner = preg_match(
                '#<div style="padding:28px;">(.*)</div>\s*<div style="padding:18px 28px;#s',
                $saved,
                $m,
            ) === 1 ? $m[1] : null;

            if ($inner === null) {
                // Recognisably the old card, but edited out of shape: send it
                // as written rather than risk a card inside a card.
                return ['html' => $this->recolour($saved), 'frame' => false];
            }

            return ['html' => $this->recolour($inner), 'frame' => true];
        }

        // Plain text, saved before the rich editor existed: blank lines
        // separate paragraphs and single line breaks are kept. Placeholders
        // are filled (and escaped) afterwards; escaping the words here leaves
        // the {{...}} markers untouched.
        if ($saved === strip_tags($saved)) {
            $paragraphs = preg_split('/\R{2,}/', trim($saved)) ?: [trim($saved)];

            return [
                'html'  => implode('', array_map(
                    static fn (string $p): string => EmailLayout::paragraph(nl2br(
                        htmlspecialchars(trim($p), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8', false),
                        false,
                    )),
                    $paragraphs,
                )),
                'frame' => true,
            ];
        }

        return ['html' => $saved, 'frame' => true];
    }

    private function recolour(string $html): string
    {
        return str_ireplace(
            ['background:#1f4e79', 'color:#ffffff;text-decoration:none'],
            ['background:{{brand_color}}', 'color:{{brand_text_color}};text-decoration:none'],
            $html,
        );
    }

    /** The plain sentence, without the raw links some of them carry. */
    private function preheader(string $sentence): string
    {
        $text = preg_replace('#\bhttps?://\S+#i', '', $sentence) ?? $sentence;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }
}
