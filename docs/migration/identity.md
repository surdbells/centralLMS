# Identity: tenants, users, roles

The first migrated slice. Everything else is blocked behind it, because every
endpoint filters by tenant and authorises by user.

## What exists

| Piece | Where |
| --- | --- |
| `tenants`, `users`, `roles`, `role_permissions`, `user_roles`, `refresh_tokens` | `migrations/Version20260825130000.php` |
| `User`, `UserStatus`, `UserRepository`, `RefreshTokenRepository` | `src/Domain/Identity/` |
| `Role`, `SystemRole`, `Permission`, `RoleRepository` | `src/Domain/Authz/` |
| `DbalUserRepository`, `DbalRefreshTokenRepository`, `DbalRoleRepository` | `src/Infrastructure/` |
| `AuthenticationService`, `PasswordHasher`, `JwtTokenService`, `TokenAuthenticator` | `src/Application/Auth/`, `src/Infrastructure/Security/` |
| `POST /v1/auth/login`, `/refresh`, `/logout`, `GET /v1/me` | `config/routes.php` |
| `php bin/console tenant:provision` | bootstraps the first tenant and admin |

Verified end to end against the Docker stack: sign in, resolve `/me`, rotate a
refresh token, reject a replayed one, revoke on sign-out.

## The import script

`php -d extension=pdo_mysql bin/console legacy:import-users --dry-run`

Read-only against MySQL, idempotent against PostgreSQL — every row carries its
`legacy_id`, so a re-run updates rather than duplicates. Run it as often as
needed while reconciling.

Verified against the real legacy data (18 tenants, 14 users, 160 roles,
22 assignments imported and re-imported cleanly).

### ⚠ The rule escalates four users

The agreed rule is "`user_roles` wins over the boolean flags". Against the real
data that produced four disagreements, and every one of them **grants more
access than the flags did**:

| Legacy id | From `user_roles` | From flags | Effect |
| --- | --- | --- | --- |
| 119 | `tenant_admin, learner` | `org_admin, learner` | **+6 permissions** |
| 123 | `tenant_admin, learner` | `org_admin, learner` | **+6 permissions** |
| 125 | `tenant_admin, hr, content_creator, learner` | `org_admin, content_creator, hr, learner` | **+6 permissions** |
| 131 | `tenant_admin, learner` | `org_admin, learner` | **+6 permissions** |

The cause is consistent: legacy `superadmin` (which maps to `tenant_admin`)
against `is_admin` (which maps to `org_admin`). These four people were
administrators either way, but the import promotes them from organisation
admin to tenant admin.

**Confirm those four before running without `--dry-run` in production.** The
command prints this table and refuses to be quiet about it.

## What the legacy data actually contained

Two corrections to assumptions made before the data was read:

**There are eight roles, not five.** The legacy seed creates
`superadmin, admin, hr_manager, hr, manager, instructor, content_creator,
learner` for every tenant. `SystemRole` originally had a five-rung ladder that
was invented rather than observed. It now carries all eight — the four extra
ones (`content_creator`, `hr`, `hr_manager`, `manager`) are *lateral*: they
inherit `learner` and nothing else, because a content creator is not "more
than" an instructor, just different.

**Legacy `superadmin` is per-tenant.** It is seeded once for each tenant, so it
is this system's `tenant_admin`, not a cross-tenant super admin. Mapping it to
`super_admin` would have handed every tenant's administrator access to every
other tenant.

## Bugs the verification caught

Three, all found by checking what actually landed in PostgreSQL rather than
trusting the command's own summary:

1. **Role assignments were silently dropped.** `SystemRole` gained four cases
   but the `roles` table still had five rows, so assignments to the new roles
   matched nothing and vanished. Reported 22, wrote 20. Fixed by
   `Version20260825150000`, and `assignRoles()` now returns what it could not
   assign instead of skipping quietly.
2. **Every user imported as `invited`.** The rule used `approved` and
   `login_count`, both of which are `NULL` in the real data even for users with
   a recent `last_login_at`. Status now derives from whether the account has a
   usable password, which is what `invited` actually means.
3. **Passwords are bcrypt cost 10**, this system uses 12 — so all 14 will be
   transparently rehashed on their next sign-in. Working as intended, and now
   reported in the summary.

## ⚠ Previously open: four role mechanisms

The legacy `users` table records a user's role in **four** different ways, all
in live use:

| Mechanism | Live references |
| --- | --- |
| Boolean flags — `is_admin`, `is_instructor`, `is_learner`, `is_hr`, `is_content` | 86 |
| `role_id` column | 67 |
| `user_roles` + `role_permissions` join tables | 25 |
| `role` column (integer) | — |

Only `user_roles` survives the port. The data migration therefore needs a rule
for collapsing the other three, and **that rule is not yet agreed**.

Proposed mapping, to be confirmed against production data before anything is
imported:

| Legacy | New role |
| --- | --- |
| `is_learner` | `learner` |
| `is_instructor` | `instructor` |
| `is_content` | `instructor` (content authoring is a subset of what instructors do) |
| `is_hr` | custom per-tenant role, seeded with `user.manage` + `report.view` |
| `is_admin` | `org_admin` |
| `is_admin` **and** the tenant's `admin_user_id` | `tenant_admin` |
| `roles`/`user_roles` rows | imported as-is, `is_system` respected |

Two questions to settle first:

1. **When the flags and `user_roles` disagree, which wins?** Recommendation:
   `user_roles`, because it is the newer mechanism and the only one that can
   express a custom role — but a per-user report of disagreements should be
   produced and reviewed before the import, not after.
2. **Is `is_hr` a real role or a reporting flag?** It has no obvious permission
   set in the legacy code, and guessing it into existence would grant people
   access they never had.

Until these are answered the import script should not be written. The schema is
ready for it either way.

## Design notes

**Permissions are code, roles are data.** The legacy `permissions` table is not
ported. `role_permissions.permission` holds a `Domain\Authz\Permission` value
and the application validates it, so a tenant cannot grant a capability by
inserting a row naming one that does not exist.

**Built-in roles are rows with code-defined permissions.** They exist in
`roles` (so `user_roles` can reference them) with `tenant_id IS NULL`, but
`DbalRoleRepository` resolves their permissions from
`SystemRole::effectivePermissions()`. A tenant cannot redefine what
"Instructor" means, and adding a permission to the enum reaches every tenant
with no data migration. Custom roles are the opposite: entirely data, flat, and
filtered through the `Permission` enum on read.

**Tokens carry role ids, never permissions.** Permissions are resolved per
request through the cached `RoleRepository`, so editing a role takes effect on
the next request rather than whenever each holder's token happens to refresh.

**Refresh tokens are stored as SHA-256 hashes and rotated on every use.** The
old token is revoked and linked to its replacement, which makes theft
detectable: a stolen token used after the real client has refreshed is already
dead. The legacy `users.token` column was one opaque token per user, with no
expiry, no rotation — and was never actually checked.

**Sign-in failures are indistinguishable.** Unknown email and wrong password
return the same message, and an unknown email is still verified against a decoy
hash so the timing matches.

> A test caught this being wrong in an instructive way. The decoy was
> originally a hardcoded cost-12 literal; against cost-4 accounts that made the
> *unknown*-email path **230× slower** than the known one — an enumeration
> oracle just as usable as the one it was meant to close. The decoy is now
> generated from the configured hasher so the cost always matches.

**bcrypt is retained**, so migrated hashes verify unchanged and no user is
forced to reset. `needsRehash()` upgrades the work factor transparently on the
next successful sign-in.

## Schema differences worth knowing

- `email` is `citext`, unique per tenant among non-deleted rows. MySQL's
  `utf8mb4_unicode_ci` was case-insensitive; PostgreSQL is not by default, and
  without `citext` the port would start admitting `Ada@x.com` alongside
  `ada@x.com`.
- Account state is one `status` column with a check constraint, replacing
  `is_active` + `account_locked` + `approved` + `suspended` + `deleted_at` —
  five columns that could disagree, and did.
- Tenant `code` and `domain` are unique among live tenants only, so a
  soft-deleted tenant does not block reuse of its code.
- `legacy_id` columns exist to make the data migration re-runnable and
  auditable. They are dropped once cutover is signed off.

## Next

The frontend `Auth` module still points at the legacy backend. Flipping it
needs the **Phase 0 bridge**: replacing `Database::verify_user()` in the legacy
PHP with real JWT verification, so both backends accept the same token. That is
roughly 30 lines in one file, it keeps all 248 legacy call sites working
unchanged, and it closes the authentication bypass at the same time.
