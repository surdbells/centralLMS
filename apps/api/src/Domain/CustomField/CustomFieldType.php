<?php

declare(strict_types=1);

namespace Central\Api\Domain\CustomField;

/**
 * The kinds of value a custom profile field can hold.
 *
 * Deliberately small. Each maps to one form control and one coercion rule, so
 * a tenant building a field chooses from a short, well-understood list rather
 * than a free-for-all that the value validator could not reason about.
 */
enum CustomFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Dropdown = 'dropdown';
    /** A yes/no — stored as a real boolean, not the string "false". */
    case Boolean = 'boolean';

    /** Whether this type draws its value from a fixed list of options. */
    public function usesOptions(): bool
    {
        return $this === self::Dropdown;
    }

    /**
     * Coerces and validates one raw input value for a field of this type.
     *
     * Returns the normalised value to store (string, float, bool or null), or
     * throws so the caller can attribute the message to the field. An empty
     * input is null — "not answered" — and the required check is the caller's,
     * not this method's.
     *
     * @param list<string> $options the field's allowed values, for a dropdown
     *
     * @throws \InvalidArgumentException with a human message
     */
    public function coerce(mixed $value, array $options = []): string|float|bool|null
    {
        if ($this === self::Boolean) {
            // A checkbox that was never ticked is "no", not "unanswered", so a
            // boolean has no null — it is always one of the two.
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }

        $text = trim((string) ($value ?? ''));

        if ($text === '') {
            return null;
        }

        // Boolean returned above, so these four are the only cases left — the
        // match is exhaustive without a default arm.
        return match ($this) {
            self::Text => mb_strlen($text) > 1000
                ? throw new \InvalidArgumentException('Keep this to 1000 characters or fewer.')
                : $text,
            self::Number => is_numeric($text)
                ? (float) $text
                : throw new \InvalidArgumentException('Enter a number.'),
            self::Date => self::isDate($text)
                ? $text
                : throw new \InvalidArgumentException('Use a date in the form 2026-03-01.'),
            self::Dropdown => in_array($text, $options, true)
                ? $text
                : throw new \InvalidArgumentException('Choose one of the listed options.'),
        };
    }

    private static function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d') === $value;
        } catch (\Exception) {
            return false;
        }
    }
}
