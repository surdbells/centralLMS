<?php

declare(strict_types=1);

namespace Central\Api\Domain\Settings;

/**
 * Everything true of one setting: what it is, what it defaults to, and what
 * counts as a valid value.
 *
 * The last part is the one the legacy had nowhere to put. `updateSetting()`
 * cast to the `value_type` column and wrote — so a colour could be `banana`, a
 * password minimum could be `0`, and a timezone could be anything at all. The
 * value that comes back out is whatever went in.
 */
final readonly class SettingDefinition
{
    /**
     * @param list<string>|null $choices when set, the value must be one of these
     */
    public function __construct(
        public SettingKey $key,
        public SettingType $type,
        public string $label,
        public string|int|bool $default,
        public ?string $help = null,
        public ?int $min = null,
        public ?int $max = null,
        public ?array $choices = null,
        /**
         * What in the running system reads this.
         *
         * Not decoration. A setting nothing consults is a form field that lies
         * to whoever fills it in, and writing this line is the check that stops
         * one being added.
         */
        public string $honouredBy = '',
    ) {
    }

    public function category(): SettingCategory
    {
        return $this->key->category();
    }

    /**
     * Reads a stored string back as what it means.
     */
    public function parse(string $stored): string|int|bool
    {
        return match ($this->type) {
            SettingType::Boolean => filter_var($stored, FILTER_VALIDATE_BOOL),
            SettingType::Integer => (int) $stored,
            default              => $stored,
        };
    }

    /**
     * Checks a submitted value and returns it as a string to store.
     *
     * Null when it is not acceptable; `$reason` says why, in words meant for
     * whoever typed it.
     */
    public function accept(mixed $value, ?string &$reason = null): ?string
    {
        $reason = null;

        return match ($this->type) {
            SettingType::Boolean => filter_var($value, FILTER_VALIDATE_BOOL) ? 'true' : 'false',
            SettingType::Integer => $this->acceptInteger($value, $reason),
            SettingType::Colour  => $this->acceptColour($value, $reason),
            SettingType::Url     => $this->acceptUrl($value, $reason),
            SettingType::Choice  => $this->acceptChoice($value, $reason),
            SettingType::Text    => $this->acceptText($value, $reason),
        };
    }

    private function acceptInteger(mixed $value, ?string &$reason): ?string
    {
        if (!is_numeric($value)) {
            $reason = 'Give a number.';

            return null;
        }

        $number = (int) $value;

        if ($this->min !== null && $number < $this->min) {
            $reason = sprintf('The smallest allowed is %d.', $this->min);

            return null;
        }

        if ($this->max !== null && $number > $this->max) {
            $reason = sprintf('The largest allowed is %d.', $this->max);

            return null;
        }

        return (string) $number;
    }

    private function acceptColour(mixed $value, ?string &$reason): ?string
    {
        $colour = strtolower(trim((string) $value));

        // Three or six digits, with the hash. Anything else reaches a
        // stylesheet as an invalid declaration and is silently dropped by the
        // browser, so the tenant sees no colour and no error.
        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/', $colour) !== 1) {
            $reason = 'Give a colour as a hex code, like #c9a227.';

            return null;
        }

        return $colour;
    }

    private function acceptUrl(mixed $value, ?string &$reason): ?string
    {
        $url = trim((string) $value);

        if ($url === '') {
            // Clearing a logo is a thing somebody does.
            return '';
        }

        // A relative path is how an uploaded logo is referenced — the media
        // module serves them from /v1/media/{id}/file.
        if (str_starts_with($url, '/')) {
            return $url;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            $reason = 'Give a web address, or leave it empty.';

            return null;
        }

        if (!in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
            // `javascript:` in an image source is a stored cross-site script,
            // and a settings screen is a pleasant place to put one.
            $reason = 'Only http and https addresses are allowed.';

            return null;
        }

        return $url;
    }

    private function acceptChoice(mixed $value, ?string &$reason): ?string
    {
        $choice = trim((string) $value);

        if ($this->choices !== null && !in_array($choice, $this->choices, true)) {
            $reason = 'Choose one of: ' . implode(', ', $this->choices) . '.';

            return null;
        }

        return $choice;
    }

    private function acceptText(mixed $value, ?string &$reason): ?string
    {
        $text = trim((string) $value);

        if ($this->max !== null && mb_strlen($text) > $this->max) {
            $reason = sprintf('At most %d characters.', $this->max);

            return null;
        }

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter(
            [
                'key'         => $this->key->value,
                'category'    => $this->category()->value,
                'label'       => $this->label,
                'type'        => $this->type->value,
                'default'     => $this->default,
                'help'        => $this->help,
                'min'         => $this->min,
                'max'         => $this->max,
                'choices'     => $this->choices,
            ],
            static fn (mixed $v): bool => $v !== null,
        );
    }
}
