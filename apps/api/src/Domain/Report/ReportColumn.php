<?php

declare(strict_types=1);

namespace Central\Api\Domain\Report;

/**
 * One column of a report.
 *
 * Carries a type, which is what lets a client right-align a number, format a
 * date to the reader's locale and render a percentage as a bar — and what lets
 * the CSV writer quote a string but not a figure. The legacy returned bare
 * associative arrays and every consumer guessed.
 */
final readonly class ReportColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public ReportValueType $type = ReportValueType::Text,
    ) {
    }

    public static function text(string $key, string $label): self
    {
        return new self($key, $label, ReportValueType::Text);
    }

    public static function number(string $key, string $label): self
    {
        return new self($key, $label, ReportValueType::Number);
    }

    public static function percent(string $key, string $label): self
    {
        return new self($key, $label, ReportValueType::Percent);
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, ReportValueType::Date);
    }

    public static function duration(string $key, string $label): self
    {
        return new self($key, $label, ReportValueType::Duration);
    }

    public static function status(string $key, string $label): self
    {
        return new self($key, $label, ReportValueType::Status);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'type' => $this->type->value];
    }
}
