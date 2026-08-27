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
        $replacements = [];

        foreach (self::PLACEHOLDERS as $name) {
            // Whitespace inside the braces is tolerated, because somebody will
            // write `{{ subject }}` and be right to expect it to work.
            $replacements['/\{\{\s*' . $name . '\s*\}\}/'] = $values[$name] ?? '';
        }

        $filled = preg_replace(
            array_keys($replacements),
            array_values($replacements),
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
