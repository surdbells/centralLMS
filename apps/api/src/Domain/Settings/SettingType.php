<?php

declare(strict_types=1);

namespace Central\Api\Domain\Settings;

/**
 * How a setting's value is written down, and what the form should show.
 *
 * The legacy stored this beside every row as `value_type` and then never read
 * it — `getAllSettings()` returns strings whatever the column says, so a
 * boolean saved as `"false"` came back as the string `"false"`, which is true.
 * The type belongs to the key, not to the row.
 */
enum SettingType: string
{
    case Text    = 'text';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Colour  = 'colour';
    case Url     = 'url';
    case Choice  = 'choice';
}
