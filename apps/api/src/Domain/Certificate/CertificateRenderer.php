<?php

declare(strict_types=1);

namespace Central\Api\Domain\Certificate;

/**
 * Fills a template's placeholders with a certificate's values.
 *
 * Pure, like {@see \Central\Api\Domain\Assessment\Grader}: a template and a
 * certificate go in, HTML comes out.
 *
 * **Every substituted value is HTML-escaped.** A learner's name reaches this
 * from a registration form, and a certificate is rendered to PDF and then
 * shown to third parties who verify it — so an unescaped name is stored
 * cross-site scripting with an audience of exactly the people most likely to
 * trust the page. The template's own HTML is the author's and is left alone;
 * the values poured into it are not.
 */
final class CertificateRenderer
{
    /**
     * The placeholders a template may use.
     *
     * A closed list rather than "whatever is in the array": an unknown
     * placeholder should be a visible mistake at save time, not a literal
     * `{{leaner_name}}` printed on somebody's certificate.
     */
    public const PLACEHOLDERS = [
        'learner_name',
        'learner_email',
        'title',
        'certificate_number',
        'verification_code',
        'verification_url',
        'issue_date',
        'valid_from',
        'valid_until',
        'score',
        'percentage',
        'grade',
        'organisation',
        'source',
    ];

    /**
     * @param array<string, mixed> $values
     */
    public function render(string $template, array $values): string
    {
        $replacements = [];

        foreach (self::PLACEHOLDERS as $key) {
            $value = $values[$key] ?? null;

            $replacements['{{' . $key . '}}'] = $value === null || $value === ''
                // An absent value leaves nothing behind rather than the word
                // "null" or a naked placeholder.
                ? ''
                : htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $rendered = strtr($template, $replacements);

        // Custom fields, which a tenant may add for its own templates. Same
        // escaping, and confined to a namespace so they cannot shadow a
        // built-in placeholder.
        if (isset($values['custom']) && is_array($values['custom'])) {
            $custom = [];

            foreach ($values['custom'] as $key => $value) {
                if (!is_string($key) || !is_scalar($value)) {
                    continue;
                }

                $custom['{{custom.' . $key . '}}'] = htmlspecialchars(
                    (string) $value,
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8',
                );
            }

            $rendered = strtr($rendered, $custom);
        }

        return $rendered;
    }

    /**
     * Placeholders a template uses that this renderer does not know.
     *
     * Checked when a template is saved, so the author hears about a typo then
     * rather than from whoever receives the certificate.
     *
     * @return list<string>
     */
    public function unknownPlaceholders(string $template): array
    {
        if (preg_match_all('/\{\{\s*([a-z0-9_.]+)\s*\}\}/i', $template, $matches) === false) {
            return [];
        }

        $unknown = [];

        foreach ($matches[1] as $name) {
            $name = strtolower($name);

            // Custom fields are the tenant's own and cannot be validated here.
            if (str_starts_with($name, 'custom.')) {
                continue;
            }

            if (!in_array($name, self::PLACEHOLDERS, true)) {
                $unknown[] = $name;
            }
        }

        return array_values(array_unique($unknown));
    }
}
