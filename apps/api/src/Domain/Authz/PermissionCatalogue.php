<?php

declare(strict_types=1);

namespace Central\Api\Domain\Authz;

/**
 * The permission list, arranged for a human ticking boxes.
 *
 * Entirely derived from {@see Permission}. The legacy carried a `permissions`
 * table of 61 rows that the role editor read and the authorisation code did
 * not — so a permission could be granted in the UI and mean nothing, and a
 * check could name a permission no administrator had ever seen. There is one
 * list now, it is the enum, and this presents it.
 */
final readonly class PermissionCatalogue
{
    /**
     * Every permission, grouped by module, in the order the enum declares
     * them — which is the order somebody reading the enum would expect.
     *
     * @return list<array{
     *     module: string,
     *     label: string,
     *     permissions: list<array{value: string, label: string, description: string}>
     * }>
     */
    public static function grouped(): array
    {
        $groups = [];

        foreach (Permission::cases() as $permission) {
            $groups[$permission->group()][] = [
                'value'       => $permission->value,
                'label'       => self::actionLabel($permission),
                'description' => $permission->describe(),
            ];
        }

        $catalogue = [];

        foreach ($groups as $module => $permissions) {
            $catalogue[] = [
                'module'      => $module,
                'label'       => self::moduleLabel($module),
                'permissions' => $permissions,
            ];
        }

        return $catalogue;
    }

    /**
     * The part after the module, as words: `ilt.session.manage` is "Session
     * manage" under "Instructor-led training".
     */
    private static function actionLabel(Permission $permission): string
    {
        $parts = explode('.', $permission->value);
        array_shift($parts);

        return ucfirst(str_replace('_', ' ', implode(' ', $parts)));
    }

    /**
     * Module names a person would recognise.
     *
     * The few that do not read well as a bare word are named; the rest are
     * capitalised, so a new module does not have to be added here to appear.
     */
    private static function moduleLabel(string $module): string
    {
        return match ($module) {
            'ilt'          => 'Instructor-led training',
            'lookup'       => 'Reference data',
            'user'         => 'People',
            'role'         => 'Roles',
            'audit'        => 'Audit',
            default        => ucfirst($module),
        };
    }
}
