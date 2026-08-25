<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Infrastructure\Security;

use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Authz\Role;
use Central\Api\Domain\Authz\RoleRepository;
use Central\Api\Domain\Authz\SystemRole;
use Central\Api\Infrastructure\Authz\SystemRoleRepository;
use Central\Api\Infrastructure\Security\JwtTokenService;
use Central\Api\Infrastructure\Security\TokenAuthenticator;
use Central\Api\Support\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Resolution of a token into a caller — the point where tenant isolation and
 * custom roles meet.
 */
#[CoversClass(TokenAuthenticator::class)]
#[CoversClass(SystemRoleRepository::class)]
final class TokenAuthenticatorTest extends TestCase
{
    private FrozenClock $clock;
    private JwtTokenService $tokens;

    protected function setUp(): void
    {
        $this->clock  = new FrozenClock('2026-06-01T12:00:00+00:00');
        $this->tokens = new JwtTokenService([
            'secret'      => 'a-test-secret-that-is-definitely-long-enough',
            'algorithm'   => 'HS256',
            'issuer'      => 'central-lms',
            'audience'    => 'central-clients',
            'access_ttl'  => 900,
            'refresh_ttl' => 2592000,
            'leeway'      => 10,
        ], $this->clock);
    }

    #[Test]
    public function it_resolves_permissions_from_roles_including_inherited_ones(): void
    {
        $user = $this->authenticate([SystemRole::Instructor], 'tenant-1');

        // Granted directly by Instructor.
        self::assertTrue($user->can(Permission::IltSessionManage));
        // Inherited from Learner.
        self::assertTrue($user->can(Permission::CourseView));
        // Belongs to OrgAdmin, which Instructor does not inherit.
        self::assertFalse($user->can(Permission::UserManage));
    }

    #[Test]
    public function a_custom_role_grants_exactly_what_it_was_given(): void
    {
        $reviewer = Role::custom(
            id: 'role-reviewer',
            tenantId: 'tenant-1',
            slug: 'reviewer',
            name: 'Reviewer',
            permissions: [Permission::CourseView, Permission::ReportView],
        );

        $user = $this->authenticate(['role-reviewer'], 'tenant-1', [$reviewer]);

        self::assertTrue($user->can(Permission::CourseView));
        self::assertTrue($user->can(Permission::ReportView));
        self::assertFalse($user->can(Permission::CourseManage));
        // Custom roles are flat — no inheritance ladder to climb.
        self::assertCount(2, $user->permissions);
    }

    #[Test]
    public function a_role_belonging_to_another_tenant_is_dropped(): void
    {
        $otherTenantsRole = Role::custom(
            id: 'role-elsewhere',
            tenantId: 'tenant-2',
            slug: 'admin-ish',
            name: 'Admin-ish',
            permissions: [Permission::TenantManage],
        );

        // A tampered or stale token naming another tenant's role must gain
        // nothing from it.
        $user = $this->authenticate(['role-elsewhere'], 'tenant-1', [$otherTenantsRole]);

        self::assertSame([], $user->roles);
        self::assertFalse($user->can(Permission::TenantManage));
    }

    #[Test]
    public function an_unknown_role_id_is_dropped_rather_than_failing_the_request(): void
    {
        // A role deleted while a token was live should cost the holder that
        // role, not lock them out entirely.
        $user = $this->authenticate([SystemRole::Learner, 'role-since-deleted'], 'tenant-1');

        self::assertCount(1, $user->roles);
        self::assertTrue($user->hasSystemRole(SystemRole::Learner));
    }

    #[Test]
    public function permissions_from_several_roles_are_merged_without_duplicates(): void
    {
        $extra = Role::custom(
            id: 'role-extra',
            tenantId: 'tenant-1',
            slug: 'extra',
            name: 'Extra',
            // CourseView overlaps with Learner; ReportExport does not.
            permissions: [Permission::CourseView, Permission::ReportExport],
        );

        $user = $this->authenticate([SystemRole::Learner, 'role-extra'], 'tenant-1', [$extra]);

        $values = array_map(static fn (Permission $p): string => $p->value, $user->permissions);

        self::assertSame(count($values), count(array_unique($values)));
        self::assertTrue($user->can(Permission::ReportExport));
        self::assertTrue($user->can(Permission::CourseView));
    }

    #[Test]
    public function only_a_super_admin_reaches_across_tenants(): void
    {
        $tenantAdmin = $this->authenticate([SystemRole::TenantAdmin], 'tenant-1');

        // Tenant admin holds every permission, yet still cannot cross tenants —
        // isolation is structural, not a grant.
        self::assertTrue($tenantAdmin->can(Permission::TenantManage));
        self::assertTrue($tenantAdmin->canAccessTenant('tenant-1'));
        self::assertFalse($tenantAdmin->canAccessTenant('tenant-2'));

        $superAdmin = $this->authenticate([SystemRole::SuperAdmin], 'tenant-1');
        self::assertTrue($superAdmin->canAccessTenant('tenant-2'));
    }

    #[Test]
    public function a_user_with_no_roles_can_do_nothing(): void
    {
        $user = $this->authenticate([], 'tenant-1');

        self::assertSame([], $user->permissions);
        self::assertFalse($user->can(Permission::CourseView));
    }

    /**
     * @param list<SystemRole|string> $roleIdentifiers
     * @param list<Role>              $customRoles
     */
    private function authenticate(
        array $roleIdentifiers,
        ?string $tenantId,
        array $customRoles = [],
    ): \Central\Api\Domain\Authz\AuthenticatedUser {
        $pair = $this->tokens->issue('user-1', 'user@example.com', $roleIdentifiers, $tenantId);

        $repository = $customRoles === []
            ? new SystemRoleRepository()
            : $this->repositoryWith($customRoles);

        return (new TokenAuthenticator($this->tokens, $repository))->authenticate($pair->accessToken);
    }

    /**
     * Built-ins plus the given custom roles, with the same tenant-scoping rules
     * the real repository must apply.
     *
     * @param list<Role> $customRoles
     */
    private function repositoryWith(array $customRoles): RoleRepository
    {
        return new class ($customRoles) implements RoleRepository {
            /** @var array<string, Role> */
            private array $roles;

            /** @param list<Role> $customRoles */
            public function __construct(array $customRoles)
            {
                $roles = [];

                foreach (SystemRole::cases() as $case) {
                    $roles[$case->value] = $case->toRole();
                }
                foreach ($customRoles as $role) {
                    $roles[$role->id] = $role;
                }

                $this->roles = $roles;
            }

            public function findForTenant(?string $tenantId, array $identifiers): array
            {
                $found = [];

                foreach ($identifiers as $identifier) {
                    $role = $this->roles[$identifier] ?? null;

                    if ($role !== null && $role->isAvailableTo($tenantId)) {
                        $found[$role->id] = $role;
                    }
                }

                return array_values($found);
            }

            public function findAllForTenant(string $tenantId): array
            {
                return array_values(array_filter(
                    $this->roles,
                    static fn (Role $r): bool => $r->isAvailableTo($tenantId),
                ));
            }

            public function find(?string $tenantId, string $identifier): ?Role
            {
                $role = $this->roles[$identifier] ?? null;

                return $role !== null && $role->isAvailableTo($tenantId) ? $role : null;
            }

            public function save(Role $role): void
            {
                $this->roles[$role->id] = $role;
            }

            public function delete(string $tenantId, string $roleId): void
            {
                unset($this->roles[$roleId]);
            }
        };
    }
}
