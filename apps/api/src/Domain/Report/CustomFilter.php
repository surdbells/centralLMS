<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * One way a custom report's rows can be narrowed.
 *
 * Four kinds, each telling the client which control to draw:
 *
 *   * `date`   — the start or end of a range over the source's date column
 *                (`from` / `to`), validated by {@see ReportCriteria}
 *   * `lookup` — one of the organisation's reference values (a department,
 *                a team, a branch, a course category); `lookupKind` names it
 *   * `course` — one course
 *   * `select` — one of a fixed list of `options`
 *
 * Filters only ever narrow: every one is ANDed onto a WHERE clause that
 * already carries the tenant and the caller's row scope.
 */
final readonly class CustomFilter
{
    public const DATE   = 'date';
    public const LOOKUP = 'lookup';
    public const COURSE = 'course';
    public const SELECT = 'select';

    /**
     * @param list<array{value: string, label: string}> $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $type,
        public ?string $lookupKind = null,
        public array $options = [],
    ) {
    }

    public static function from(string $label = 'From'): self
    {
        return new self('from', $label, self::DATE);
    }

    public static function to(string $label = 'To'): self
    {
        return new self('to', $label, self::DATE);
    }

    public static function lookup(string $key, string $label, string $kind): self
    {
        return new self($key, $label, self::LOOKUP, $kind);
    }

    public static function course(string $label = 'Course'): self
    {
        return new self('courseId', $label, self::COURSE);
    }

    /**
     * @param array<string, string> $options value => label
     */
    public static function select(string $key, string $label, array $options): self
    {
        $list = [];

        foreach ($options as $value => $optionLabel) {
            $list[] = ['value' => (string) $value, 'label' => $optionLabel];
        }

        return new self($key, $label, self::SELECT, null, $list);
    }

    public function isDate(): bool
    {
        return $this->type === self::DATE;
    }

    /**
     * The value, if it is one this filter accepts.
     *
     * Null for anything else — the caller decides whether that is an error.
     */
    public function accept(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return match ($this->type) {
            self::DATE   => $value,
            // Not a validity check on the row — the query finds nothing for an
            // id that does not exist. It keeps rubbish out of a bound value.
            self::LOOKUP, self::COURSE => preg_match('/^[0-9a-f-]{36}$/i', $value) === 1 ? strtolower($value) : null,
            self::SELECT => in_array($value, array_column($this->options, 'value'), true) ? $value : null,
            default      => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'key'        => $this->key,
            'label'      => $this->label,
            'type'       => $this->type,
            'lookupKind' => $this->lookupKind,
            'options'    => $this->type === self::SELECT ? $this->options : null,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
