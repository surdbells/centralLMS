<?php

declare(strict_types=1);

namespace Central\Api\Domain\Library;

use Central\Api\Domain\Exception\ValidationFailed;

/**
 * Reading a category id off a request, before it goes anywhere near a UUID
 * column. Pure: whether the category exists in the tenant is the caller's
 * question, asked of the repository.
 */
final class LibraryCategoryFilter
{
    /**
     * The `categoryId` list filter: null for no filter, `none` for the
     * uncategorised items, or a category id.
     *
     * @throws ValidationFailed for anything else
     */
    public static function fromQuery(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        if (!is_string($value)) {
            throw self::invalid();
        }

        $value = trim($value);
        if (strtolower($value) === DigitalLibraryRepository::UNCATEGORISED) {
            return DigitalLibraryRepository::UNCATEGORISED;
        }

        if (!LibraryCategory::looksLikeId($value)) {
            throw self::invalid();
        }

        return strtolower($value);
    }

    /**
     * The `categoryId` an item is filed under, from an upload or edit body:
     * null for uncategorised (empty, `null` or `none`, as a multipart form may
     * send it), or a well-formed category id.
     *
     * @throws ValidationFailed for anything else
     */
    public static function fromBody(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_string($value)) {
            throw self::invalid();
        }

        $value = trim($value);
        if ($value === '' || in_array(strtolower($value), ['null', DigitalLibraryRepository::UNCATEGORISED], true)) {
            return null;
        }

        if (!LibraryCategory::looksLikeId($value)) {
            throw self::invalid();
        }

        return strtolower($value);
    }

    private static function invalid(): ValidationFailed
    {
        return ValidationFailed::fromArray(['categoryId' => 'Choose a category from the list.']);
    }
}
