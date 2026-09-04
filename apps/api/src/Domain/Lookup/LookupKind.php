<?php

declare(strict_types=1);

namespace Central\Api\Domain\Lookup;

/**
 * The kinds of tenant reference data.
 *
 * Each of these was a separate legacy table with its own six PHP endpoints and
 * its own class — 36 files that differed only in a column prefix. They are one
 * concept: a tenant-scoped named list.
 *
 * Adding a kind here plus a value to the `lookups_kind_check` constraint is the
 * entire cost of a new reference type. No new table, endpoint or controller.
 */
enum LookupKind: string
{
    case Branch = 'branch';
    case Department = 'department';
    case Team = 'team';
    case Level = 'level';
    case JobTitle = 'job_title';
    case Category = 'category';
    // An assessment cycle — the period an exercise runs for, "2026" or
    // "2026 H1". Competency stores it by name on each assessment, so unlike the
    // others it is referenced by value rather than by id (see the repository's
    // rename cascade).
    case Cycle = 'cycle';

    /**
     * Accepts the plural URL segment as well as the enum value, so
     * `/v1/lookups/branches` and `/v1/lookups/branch` both resolve.
     */
    public static function fromSlug(string $slug): ?self
    {
        $slug = strtolower(str_replace('-', '_', trim($slug)));

        foreach (self::cases() as $kind) {
            if ($slug === $kind->value || $slug === $kind->plural()) {
                return $kind;
            }
        }

        // The legacy paths used these; accepted so a client can migrate
        // without changing its URLs on the same day.
        return match ($slug) {
            'jobtitles', 'jobtitle' => self::JobTitle,
            'categories'            => self::Category,
            default                 => null,
        };
    }

    public function plural(): string
    {
        return match ($this) {
            self::Branch     => 'branches',
            self::Department => 'departments',
            self::Team       => 'teams',
            self::Level      => 'levels',
            self::JobTitle   => 'job_titles',
            self::Category   => 'categories',
            self::Cycle      => 'cycles',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Branch     => 'Branch',
            self::Department => 'Department',
            self::Team       => 'Team',
            self::Level      => 'Level',
            self::JobTitle   => 'Job title',
            self::Category   => 'Category',
            self::Cycle      => 'Cycle',
        };
    }

    /**
     * Whether this kind forms a tree.
     *
     * Only categories nest in the legacy data. Rejecting a parent on the
     * others stops a tenant building a hierarchy the UI cannot render.
     */
    public function supportsHierarchy(): bool
    {
        return $this === self::Category;
    }

    /**
     * Whether a short code is expected alongside the name.
     *
     * Job titles and categories never had one in the legacy schema.
     */
    public function usesCode(): bool
    {
        return match ($this) {
            self::JobTitle, self::Category, self::Cycle => false,
            default                                     => true,
        };
    }

    /**
     * Free-form fields this kind keeps in `attributes`, with no shared meaning
     * across kinds. Anything not listed is rejected rather than stored.
     *
     * @return list<string>
     */
    public function allowedAttributes(): array
    {
        return match ($this) {
            self::Branch => ['address', 'city', 'state', 'country'],
            default      => [],
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $k): string => $k->value, self::cases());
    }
}
