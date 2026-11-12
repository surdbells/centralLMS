<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * Fills an organisation's own wording in, where it has any.
 *
 * The default wording comes from {@see NotificationComposer} and is unchanged.
 * This runs after it: where a tenant has written its own subject or body, that
 * is used instead, with the same facts substituted into it.
 *
 * Substitution is by a closed list of placeholders, the same approach
 * {@see \Central\Api\Domain\Certificate\CertificateRenderer} takes and for the
 * same reason: an unknown placeholder should be a refusal when the template is
 * saved, not a literal `{{leaner_name}}` in somebody's inbox.
 */
final class EmailTemplateRenderer
{
    /**
     * What a template may refer to.
     *
     * Deliberately small, and deliberately not "whatever happens to be in the
     * data array": that array differs per notification type, so a list drawn
     * from it would let a template compile against one type and render blank
     * against another. Everything here is either always present or renders as
     * nothing, which is the only two behaviours worth having.
     */
    public const PLACEHOLDERS = [
        // Who it is going to, and from whom.
        'recipient_name',
        'organisation',
        // What it is about — a course, a session, an assessment.
        'subject',
        // The default wording, so a template can wrap rather than replace it.
        'default_body',
        // Dates and links, where the type has them.
        'due_date',
        'starts_at',
        'link',
        'expires_in',
        'certificate_number',
        'percentage',
        'reason',
        // The organisation's branding, filled at send time from Settings >
        // Branding, so a template can colour its own button or link home.
        'brand_color',
        'brand_text_color',
        'logo_url',
        'site_url',
    ];

    /**
     * The wording to send.
     *
     * @param  array<string, mixed>          $data      what the notification was raised with
     * @param  array{title: string, body: string} $default the composer's wording
     * @return array{title: string, body: string}
     */
    public function render(
        ?EmailTemplate $template,
        array $default,
        array $data,
        ?string $organisation,
        ?string $recipientName,
    ): array {
        if ($template === null || !$template->overrides()) {
            return $default;
        }

        $values = [
            'recipient_name'     => $recipientName ?? '',
            'organisation'       => $organisation ?? '',
            'subject'            => $this->text($data, 'subjectTitle'),
            'default_body'       => $default['body'],
            'due_date'           => $this->text($data, 'dueDate'),
            'starts_at'          => $this->text($data, 'startsAt'),
            'link'               => $this->text($data, 'link'),
            'expires_in'         => $this->text($data, 'expiresIn'),
            'certificate_number' => $this->text($data, 'certificateNumber'),
            'percentage'         => $this->text($data, 'percentage'),
            'reason'             => $this->text($data, 'reason'),
        ];

        return [
            // Each half falls back on its own. An organisation that wanted its
            // own subject line and the standard body gets exactly that.
            'title' => $template->subject === null || trim($template->subject) === ''
                ? $default['title']
                : $this->fill($template->subject, $values),
            'body'  => $template->body === null || trim($template->body) === ''
                ? $default['body']
                : $this->fill($template->body, $values),
        ];
    }

    /**
     * The subject and HTML body of an email.
     *
     * Unlike {@see render()}, which fills a plain sentence, this writes into
     * HTML, so every value is escaped: a course called "Health & Safety <L2>"
     * must arrive as those words, not as markup, and a learner's name must
     * never be able to inject any. {{default_body}} is escaped too, keeping
     * its line breaks and web addresses. The subject is plain text: values go
     * in as-is and any tags are stripped.
     *
     * `$body` is the HTML to fill — the organisation's own or the platform's
     * rich default — chosen by the caller. Sections marked
     * `<!--if:name-->…<!--/if:name-->` are kept only when that placeholder has
     * a value, so "Due date:" does not appear on a course without one.
     *
     * @param  array<string, mixed>  $data    what the notification was raised with
     * @param  array<string, string> $context recipient_name, organisation,
     *         default_body (plain text), link (fallback) and the brand values
     * @return array{title: string, body: string}
     */
    public function renderEmail(string $subject, string $body, array $data, array $context): array
    {
        $values = $this->emailValues($data, $context);

        // Tags are stripped from the template only, before the values go in:
        // a course called "Using <table> in HTML" keeps its words. An empty
        // subject means the caller supplies its own literal line.
        $title = $subject === '' ? '' : $this->fill(strip_tags($subject), $values);
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);

        $escaped = [];

        foreach ($values as $name => $value) {
            $escaped[$name] = $name === 'default_body'
                ? $this->richText($value)
                : htmlspecialchars($value, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        }

        return [
            'title' => $title,
            'body'  => $this->fill($this->conditionals($body, $values), $escaped),
        ];
    }

    /**
     * Every placeholder's raw (unescaped) value for an email.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string> $context
     * @return array<string, string>
     */
    public function emailValues(array $data, array $context): array
    {
        $subject = $this->text($data, 'subjectTitle');
        // {{link}} lands in href="": only an absolute http(s) address will do.
        $link     = self::webLink($this->text($data, 'link'));
        $fallback = self::webLink($context['link'] ?? '');

        return [
            'recipient_name'     => $context['recipient_name'] ?? '',
            'organisation'       => $context['organisation'] ?? '',
            // An announcement's subject is its title.
            'subject'            => $subject !== '' ? $subject : $this->text($data, 'title'),
            'default_body'       => $context['default_body'] ?? '',
            'due_date'           => $this->text($data, 'dueDate'),
            'starts_at'          => $this->text($data, 'startsAt'),
            'link'               => $link !== '' ? $link : $fallback,
            'expires_in'         => $this->text($data, 'expiresIn'),
            'certificate_number' => $this->text($data, 'certificateNumber'),
            'percentage'         => $this->percentage($data),
            'reason'             => $this->text($data, 'reason'),
            'brand_color'        => $context['brand_color'] ?? '',
            'brand_text_color'   => $context['brand_text_color'] ?? '',
            'logo_url'           => $context['logo_url'] ?? '',
            'site_url'           => $context['site_url'] ?? '',
        ];
    }

    /** The address when it is absolute http(s) with a host, otherwise ''. */
    private static function webLink(string $url): string
    {
        $url    = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host   = (string) parse_url($url, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true) && $host !== '' ? $url : '';
    }

    /**
     * Keeps or drops each `<!--if:name-->` section by whether `name` has a value.
     *
     * @param array<string, string> $values
     */
    private function conditionals(string $html, array $values): string
    {
        $resolved = preg_replace_callback(
            '/<!--\s*if:([a-z_]+)\s*-->(.*?)<!--\s*\/if:\1\s*-->/s',
            static fn (array $m): string => trim($values[$m[1]] ?? '') === '' ? '' : $m[2],
            $html,
        );

        return $resolved ?? $html;
    }

    /** Plain text made safe for HTML, keeping its line breaks and links. */
    private function richText(string $text): string
    {
        $html   = htmlspecialchars(trim($text), ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $linked = preg_replace(
            '#\bhttps?://[^\s<>"\']+#i',
            '<a href="$0" target="_blank" rel="noopener">$0</a>',
            $html,
        ) ?? $html;

        return nl2br($linked, false);
    }

    /**
     * "88%" rather than "88.0%".
     *
     * @param array<string, mixed> $data
     */
    private function percentage(array $data): string
    {
        $value = $data['percentage'] ?? null;

        if (!is_numeric($value)) {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 1, '.', ''), '0'), '.');
    }

    /**
     * Which placeholders a template uses that are not in the catalogue.
     *
     * Checked when the template is saved, so the mistake is caught by the
     * person making it rather than by whoever receives the email.
     *
     * @return list<string>
     */
    public function unknownPlaceholders(string $template): array
    {
        if (preg_match_all('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', $template, $matches) === false) {
            return [];
        }

        $unknown = [];

        foreach ($matches[1] as $name) {
            if (!in_array($name, self::PLACEHOLDERS, true) && !in_array($name, $unknown, true)) {
                $unknown[] = $name;
            }
        }

        return $unknown;
    }

    /**
     * @param array<string, string> $values
     */
    private function fill(string $template, array $values): string
    {
        // Whitespace inside the braces is tolerated, because somebody will
        // write `{{ subject }}` and be right to expect it to work. A callback
        // rather than a replacement string, so a value containing "$1" or a
        // backslash goes in literally.
        $filled = preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static fn (array $m): string => in_array($m[1], self::PLACEHOLDERS, true)
                ? ($values[$m[1]] ?? '')
                : $m[0],
            $template,
        );

        return $filled ?? $template;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function text(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }
}
