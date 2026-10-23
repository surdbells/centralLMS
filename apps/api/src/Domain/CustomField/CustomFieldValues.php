<?php

declare(strict_types=1);

namespace Central\Api\Domain\CustomField;

/**
 * Turns a submitted map of custom-field answers into one worth storing.
 *
 * The definitions are the authority: an answer for a field this tenant does
 * not have is dropped, a required field left blank is a violation, and every
 * value is coerced to its field's type. The result is a clean map keyed by
 * `field_key`, ready for `users.custom_fields`.
 */
final class CustomFieldValues
{
    /**
     * @param list<CustomFieldDefinition> $definitions the tenant's active fields
     * @param array<string, mixed>        $submitted   what the client sent, by key
     * @param array<string, mixed>        $existing    values already stored, kept
     *                                                 for a field the client did
     *                                                 not mention (a partial save)
     * @param array<string, string>       $violations  filled with field_key => message
     *
     * @return array<string, string|float|bool> the map to store
     */
    public static function validate(
        array $definitions,
        array $submitted,
        array $existing,
        array &$violations,
    ): array {
        $clean    = [];
        $governed = [];

        foreach ($definitions as $field) {
            $governed[$field->key] = true;

            // Absent means "leave alone": a form showing three of a tenant's
            // ten fields must not blank the other seven. But a required field
            // with nothing on record is still a violation — that is what
            // enforces required on create, and on a field added as required
            // after the account already existed. (When the key is simply never
            // sent the client is not editing custom fields at all; the action
            // guards that case before calling here.)
            if (!array_key_exists($field->key, $submitted)) {
                if (array_key_exists($field->key, $existing)) {
                    $clean[$field->key] = $existing[$field->key];
                } elseif ($field->required) {
                    $violations[$field->key] = sprintf('%s is required.', $field->label);
                }

                continue;
            }

            try {
                $value = $field->type->coerce($submitted[$field->key], $field->options);
            } catch (\InvalidArgumentException $error) {
                $violations[$field->key] = $error->getMessage();

                continue;
            }

            $isBlank = $value === null || $value === '';

            if ($isBlank) {
                if ($field->required) {
                    $violations[$field->key] = sprintf('%s is required.', $field->label);
                }

                // A cleared optional field drops out of the map rather than
                // sitting there as null.
                continue;
            }

            $clean[$field->key] = $value;
        }

        // Values whose definition is inactive or gone are carried through
        // untouched. Deactivating a field is reversible and a removed field's
        // value is meant to cost nothing where it sits — neither should be
        // wiped the next time an unrelated part of the profile is saved. The
        // value comes from what is stored, never from the submission, so a
        // field the form no longer renders cannot be written through this path.
        foreach ($existing as $key => $value) {
            if (!array_key_exists($key, $governed) && !array_key_exists($key, $clean)) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
