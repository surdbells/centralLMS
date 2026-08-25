# Progress

Living status for the Central LMS modernisation. Updated as work lands — see
[Update log](#update-log) at the bottom.

**Legend:** ✅ done · 🟡 in progress · ⬜ not started · 🚧 blocked · ⚠ needs attention

---

## Right now

| | |
| --- | --- |
| **Current focus** | Catalogue complete (courses, modules, lessons). Phase 0 bridge still awaiting deployment; then the first module flip |
| **Blocking the team** | 🔴 Legacy API authentication is bypassed — see action 0 |
| **Blocking the next slice** | Nothing — enrolments are next |
| **API quality gate** | ✅ 187 unit + 45 integration tests / 662 assertions · PHPStan level 8 clean · 0 style errors · 90 live checks |
| **Web quality gate** | ✅ 75 / 75 specs · production build passes |

### ⚠ Outstanding actions

| # | Action | Owner | Status |
| --- | --- | --- | --- |
| 0 | 🔴 **Authentication is bypassed on the entire legacy API.** `Database::verify_user()` hardcodes `return 100`; every caller checks `!= 100`, so the check never fails. 248 live files. Also SQL-injectable on `user_id`. → **Fix written and tested: [`infra/legacy-bridge/`](infra/legacy-bridge/README.md). Awaiting deployment to the live host.** | — | 🟡 ready to deploy |
| 0b | 🔴 **Cross-tenant data leak in the live reference-data endpoints.** `dictionary/*/list.php` runs `SELECT * FROM branches` with no tenant filter, and `Branches::update()` / `setStatus()` run `WHERE branch_id = ?` with no tenant column — so any caller can read every organisation's structure and edit or deactivate another's records by id. Affects branches, departments, job titles, levels, teams. Only the `active` dropdown query is scoped. Fixed in the new API; **the live PHP still has it.** | — | ⬜ |
| 1 | 🔴 **Delete `webhooks/noon-payments.php` from the live backend.** A payment webhook we do not use and never will — removed from this repo. If it is still deployed it is a reachable endpoint that writes to `orders` and holds a second database's credentials, so deleting the file is the fix; rotating its secret is not. | — | ⬜ |
| 2 | Rotate **three** database passwords found hardcoded in legacy source: `central_lxp` (`config/Database.php`), `sql_ifitness_cen` (`codebase/public/config.php`), and `sql_3bayti_com` — that last one came from the payment webhook in action 1. Deleting the file removes our copy, not the credential: rotate it too. | — | ⬜ |
| 3 | Rotate the Zoom API key — it appears in `environment.ts`, `environment.development.ts` **and** hardcoded as a fallback in `video-integration.service.ts` | — | ⬜ |
| 4 | Restrict the production MySQL host to known source IPs — all three databases sit on the same public address | — | ⬜ |
| 5 | Commit redacted copies of the four excluded files so their structure stays available for the port | — | ⬜ |
| 6 | Delete `legacy/backend/class/earningPathEnrollment.php` from the **live** backend — duplicate class name, one careless `require` from swapping the implementation | — | ⬜ |

Items 1–4 are **contained but not fixed**. Every file holding a live credential
is git-ignored and `legacy/` was untracked when found, so git history is clean —
but the secrets are still valid in production.

Found by a pre-commit scan rather than by reading:

| File | Holds | Status |
| --- | --- | --- |
| `legacy/backend/config/Database.php` | `central_lxp` DB password | ignored |
| `legacy/backend/codebase/public/config.php` | `sql_ifitness_cen` password | ignored |
| ~~`legacy/backend/webhooks/noon-payments.php`~~ | `sql_3bayti_com` password | **deleted** — unused integration |
| `legacy/frontend/src/environments/environment*.ts` | Zoom API key | ignored |
| `apps/web/.../video-integration.service.ts` | Zoom API key, hardcoded fallback | **fixed in code** |

---

## Workstreams

| # | Workstream | Status | Notes |
| --- | --- | --- | --- |
| 1 | Monorepo adoption | ✅ | pnpm + Turborepo, 4 apps, 5 packages, Docker stack, legacy drop zone |
| 2 | Backend rewrite (Slim/PostgreSQL) | 🟡 | Foundation + identity + reference data; **36 of 96 live endpoints ported** |
| 3 | ILT (Zoom / Meet / Teams) | 🟡 | Root cause found, provider layer built; needs wiring + live credentials |
| 4 | Prevent repetition | 🟡 | 72% of backend files unreachable; reference data collapsed 52 files → 6 routes |
| 5 | Frontend (keep + improve) | 🟡 | `apps/web` live with a strangler-ready API layer; 81 files still on `GlobalComponent` |

---

## Backend port

**36 of 96 live endpoints ported.** Scope measured against the frontend, not
guessed — see [`live-surface.md`](docs/migration/live-surface.md).

| | Files | LOC |
| --- | --- | --- |
| Live (reachable from frontend/cron/webhooks) | 169 | 62,288 |
| Orphaned | 434 | 58,798 |

### Foundation

| Component | Status |
| --- | --- |
| Slim 4 + PHP-DI + PSR-15 middleware stack | ✅ |
| JSON envelope + domain exception → HTTP mapping | ✅ |
| JWT access/refresh, type separation, `jti` revocation hook | ✅ |
| RBAC — permissions enum, roles as data, custom roles per tenant | ✅ |
| Password hashing (bcrypt, rehash-on-login) | ✅ |
| CORS, request IDs, rate limiting, JSON body parsing | ✅ |
| Doctrine migrations, DBAL, Redis cache, Messenger worker | ✅ |
| Health/version probes, OpenAPI generation, console commands | ✅ |
| Credential encryption at rest (AES-256-GCM) | ✅ |
| Identity schema + refresh-token rotation and revocation | ✅ |
| Tenant isolation enforced structurally in repositories | ✅ |

### Modules

Dependency order. Everything below `enrolments` is blocked on the four
foundations above it.

| # | Module | Status | Endpoints | Notes |
| --- | --- | --- | --- | --- |
| 1 | tenants | ✅ | — | schema + `tenant:provision` |
| 2 | users + roles | ✅ | ~9 | custom roles per tenant; `DbalRoleRepository` live |
| 3 | auth endpoints | 🟡 | 6 | login/refresh/logout/me ✅; register, reset, OTP ⬜ |
| 4 | dictionary | ✅ | **36** | 52 legacy PHP files → **6 routes**; tenant isolation fixed |
| 5 | courses + modules + lessons | ✅ | **19** | 6 course + 13 structure routes; 20 duplicate legacy files → 10 |
| 6 | enrolments | ⬜ | 2 | |
| 7 | assessments core | ⬜ | 3 | Design done — [`schema-assessments.md`](docs/migration/schema-assessments.md) |
| 8 | proctoring extension | ⬜ | 1 | Advanced module, entitlement-gated |
| 9 | ILT | 🟡 | 1 | Provider layer ✅; scheduling + attendance wiring ⬜ |
| 10 | learning paths | ⬜ | 2 | **70 operations**, ~5,000 LOC, 7 classes — own slice |
| 11 | certificates | ⬜ | 1 | ⚠ confirm same unify decision as assessments |
| 12 | media / SCORM | ⬜ | 8 | |
| 13 | messaging | ⬜ | 1 | |
| 14 | gamification (points, badges, levels, streaks) | ⬜ | — | |
| 15 | skills | ⬜ | 1 | 25 ops |
| 16 | reporting | ⬜ | 2 | **Last** — reads everything above |

---

## ILT detail

Root cause: `LiveTraining::createVirtualMeeting()` was a stub returning a fake
`meet.example.com` URL, while a complete 896-line provider layer sat with zero
call sites. Full write-up: [`ilt-rewrite.md`](docs/migration/ilt-rewrite.md).

| Piece | Status |
| --- | --- |
| Provider contract, VOs, OAuth flow, attendance records | ✅ |
| Zoom / Google Meet / Teams adapters | ✅ built, ⚠ untested against live accounts |
| Per-tenant credential storage, encrypted | ✅ |
| `video_integrations` migration | ✅ applied, round-trip verified |
| `GET /v1/ilt/providers`, `ilt:check-providers` | ✅ |
| OAuth connect endpoints (authorize + callback) | 🚧 needs `tenants` |
| Session scheduling wired to the provider | 🚧 needs `live_training_sessions` |
| Attendance sync job | 🚧 needs `live_training_attendance` |
| Zoom webhooks | ⬜ |

---

## Frontend

**Kept and improved, not rewritten.** Angular 19.2 + Taiga UI 4.

| App | Status | Source |
| --- | --- | --- |
| `apps/web` — learner LMS | 🟡 | Angular 19 app moved in; API layer rebuilt, `GlobalComponent` being retired |
| `apps/saas` — platform back-office | ⬜ | **Greenfield.** No legacy equivalent exists — see below |
| `apps/mobile` — Ionic + Capacitor | ⬜ | Scaffold not yet generated |
| `packages/ui` | ⬜ | From `partials/` + `shared/` (46 files) |
| `packages/api-client` | ⬜ | Generated from OpenAPI; replaces untyped `crud.service.ts` |

### `apps/saas` is greenfield

Verified against the legacy frontend: there is **no SaaS back-office**. The
legacy `admin/` area (110 files) is *tenant-scoped* administration — courses,
users, dictionary, learning paths, media, branding — i.e. what an org admin
does inside one tenant.

What does not exist anywhere:

- tenant list / lifecycle / provisioning UI (`backend/tenants/*` CRUD exists but
  is **orphaned** — nothing calls it)
- plans, billing, invoices (tables exist but are skeletal: `plans` 7 columns,
  `invoices` 7)
- cross-tenant support tooling
- `superadmin` appears in exactly 4 frontend files, all as a role string

So `apps/saas` is a build, not a port, and it needs a product conversation
before it needs a sprint.

---

## Decisions

| Date | Decision | Rationale |
| --- | --- | --- |
| 2026-08-25 | Mobile = Ionic + Angular + Capacitor | Maximum reuse of existing Angular skills |
| 2026-08-25 | `web` = learner LMS, `saas` = tenant/platform admin | |
| 2026-08-25 | `legacy/` committed, reference-only, excluded from build/lint/CI | Team needs to consult it; nothing there ships |
| 2026-08-25 | Ports 8081 / 5442 / 6389 | Machine already had the defaults taken |
| 2026-08-25 | **Unify assessments**; proctored = advanced module on a shared core | `proc_*` types are a superset, `proctoring_level` already has `none` |
| 2026-08-25 | Drop `live_sessions` | Superseded by `live_training_sessions` |
| 2026-08-25 | Keep `backend/codebase/` | Deployed, client awaiting go-live — own consolidation pass |
| 2026-08-25 | Drop `tanants/` | Typo directory |
| 2026-08-25 | **bcrypt retained** for passwords | Legacy hashes verify unchanged — no forced reset. Upgrade path via `needsRehash()` |
| 2026-08-25 | **Roles are data, not code** — tenants define custom roles | `Role` is now a class; `SystemRole` enum holds the 5 built-ins |
| 2026-08-25 | Tokens carry role **ids**, never permissions | A role edit takes effect next request, not next token refresh |
| 2026-08-25 | Custom roles are **flat** (no inheritance) | A tenant-editable inheritance graph will eventually contain a cycle |
| 2026-08-25 | Naming is **Central**, not Bayti | Repo `centralLMS`, db `central_lxp`, domain `centrallms.com` and Angular project `central` all said Central. `Bayti\Api\` came from the original stack spec but had been extended well beyond it |
| 2026-08-25 | Legacy bridge accepts **both** a new-API JWT and the existing `users.token` | Closes the bypass without signing anyone out; branch 2 is deleted once all clients use the new login |
| 2026-08-25 | **Strangler: per-module cutover**, not a shared database | Legacy is MySQL, target is PostgreSQL — they cannot share. Avoids a big-bang cutover at maximum codebase size |
| 2026-08-25 | Frontend routes per module via `ApiRouter`, default Legacy | Migrating a module is one line in the environment file, revertible on its own |
| 2026-08-25 | `ApiClient` normalises both API dialects | Legacy returns HTTP 200 for every failure; callers must not have to care |
| 2026-08-25 | Karma retained for web tests | Angular 19 supports it without extra tooling; move to Vitest with the Angular 20 upgrade |
| 2026-08-25 | ILT credentials are **per tenant**, OAuth authorization-code | Matches `tenant_video_integrations`; corrected an earlier env-based design |

---

## Open questions

| # | Question | Blocks |
| --- | --- | --- |
| 1 | During the strangler period: shared database, or per-module data cutover? | First slice. Recommendation: per-module cutover |
| 2 | `certificates` vs `proc_certificates` — same unify decision as assessments? | Module 11 |
| 3 | Reporting: `saved_reports`/`custom_reports`/`scheduled_reports` vs `proc_report_*` | Module 16 |
| 4 | `audit_log` vs `audit_logs`, `user_activity_log` vs `user_activity_logs` — which has live rows? | Low priority |
| 5 | What should `apps/saas` actually do? Needs product scope | `apps/saas` |
| 6 | Live Zoom / Google / Microsoft credentials for integration testing | ILT sign-off |

---

## Update log

### 2026-08-26 — Modules and lessons

Thirteen routes completing module 5. Modules and lessons are addressed through
their parent — `/v1/courses/{courseId}/modules/{id}` and
`…/modules/{moduleId}/lessons/{id}` — so a module id from another course is a
404 even inside the right tenant. Every action walks tenant → course → module
→ lesson and stops at the first link that does not hold.

**Deduplication.** The legacy backend had `modules/` and `user/modules/`,
`lessons/` and `user/lessons/` — 20 files, all ten pairs byte-identical.

**`GET /v1/courses/{id}/outline`** returns the whole structure in two queries.
The legacy player fetched the modules and then one lesson list per module, so
a twelve-module course cost thirteen round trips. What it returns depends on
the caller: with `course.manage`, everything including drafts and deactivated
rows; otherwise published courses only, active rows only, and an unpublished
course is a 404 rather than a 403 so its existence cannot be probed.

**Deliberate design points**

- Reordering is one endpoint (`PUT …/modules/order`) taking the full list, not
  one PUT per row. The list must name every item exactly once — a partial list
  silently leaves the rest at their old positions, which is how two rows end
  up sharing one. It applies in a single statement, so it either all lands or
  none does.
- A new module or lesson goes to the end of its parent. Defaulting to 0 would
  stack every new row on top of the first and let the tie-break decide.
- Content rules the database cannot express: a video, audio, PDF or SCORM
  lesson needs a URL; a text or HTML lesson needs a body. A quiz needs neither
  — it points at an assessment the assessments module will own.
- Deleting a module soft-deletes its lessons in the same transaction. A lesson
  whose module is gone is unreachable but would still be counted by anything
  reading lessons directly.
- Structure can only change while the course itself is editable. Otherwise
  archiving would freeze a course's details but leave its lessons editable,
  which is the more surprising half.
- `courseId` on a lesson comes from the resolved module, never the request
  body. The composite foreign key would reject a mismatched pair anyway; this
  makes the rejection unnecessary.

**Fixed — a partial `PUT` silently wiped fields.** Sending
`{"title": "…"}` to a lesson reset `durationMinutes` and `description` to
null. The same bug was already live in courses, and worse: any partial edit
reset the pass mark to 70, the level to null and every boolean flag to false.
The cause was a hand-written list of fields to carry over, which drops
whichever field it forgets. All three update actions now rebuild from
`[...$existing->toArray(), ...$body]`, so the carry-over is derived from the
entity and cannot fall behind as fields are added.
[`PartialUpdateTest`](apps/api/tests/Unit/Domain/Catalog/PartialUpdateTest.php)
pins it, including a no-op round trip that fails if `toArray()` and
`fromInput()` ever disagree about a key. Found by the live check — every unit
test passed throughout.

**Verification**

- 39 unit tests over `CourseModule`, `Lesson`, `LessonContentType` and the
  partial-update round trip.
- 20 integration tests against real PostgreSQL, including the case a fake
  cannot show: right tenant, wrong parent.
- 57 live checks against the running stack — reordering and its four refusal
  cases, content rules, the author/learner split on the outline, deletion
  cascading to lessons, an archived course refusing structural edits, and
  every cross-tenant and cross-course call returning 404.

Gate: 242 tests / 662 assertions, PHPStan level 8, no style errors.

**Not included:** delivering a lesson to an enrolled learner, and
`lessons/complete.php`. Both need an enrolment check and a progress write, so
they belong to module 6 rather than the catalogue.

### 2026-08-26 — Courses

Six routes under `/v1/courses`: list, catalogue, show, create, update, set
status. Backed by `courses`, `course_modules` and `lessons`
(`Version20260825160000`).

**Referential integrity is structural, not advisory.** Two composite foreign
keys, both verified by trying to break them:

- `courses (category_id, category_kind) → lookups (id, kind)` with
  `category_kind` a generated column fixed at `'category'`. A course cannot
  point at a branch as its category. The FK is `NO ACTION` rather than
  `SET NULL` because PostgreSQL cannot null a generated column.
- `lessons (module_id, course_id) → course_modules (id, course_id)`. A lesson
  whose `course_id` disagrees with its module's is rejected outright, so the
  two paths to a lesson's course can never diverge.

**Deliberate design points**

- The learner catalogue is its own repository method, not a `status` filter on
  the admin list. A caller cannot forget to filter and expose drafts.
- `POST /v1/courses` always creates a draft; a `status` in the request body is
  ignored. Publishing needs `course.publish`, which instructors do not hold.
- `PUT` never changes status, so an update cannot bypass that permission.
- Publishing a course with no active lessons is refused (409 `course_empty`) —
  otherwise a learner enrols and finds nothing to complete, and it counts
  against them in every completion report.
- `published_at` is stamped on first publish and never cleared. "First
  published on" is a fact about the course, not its current state.
- Courses are soft-deleted. Enrolments, progress and certificates reference
  them, and a certificate for a course that no longer exists is unverifiable.

**Dropped from the legacy table:** `pass_mark` (duplicated `pass_percentage`),
`total_ratings` (duplicated `rating_count`), `column_name` (unused).
`course_type` survives as `kind`.

**Fixed along the way — malformed ids returned 500.** `/v1/courses/not-a-uuid`
reached PostgreSQL, raised `SQLSTATE[22P02]`, and returned a driver exception
in the response body. `/v1/lookups` had the same hole. Path ids now carry a
UUID pattern in the route, so the router 404s before anything reaches the
database. Found by the live check, not by a unit test.

**Also fixed — the style gate was failing for everyone on Windows.**
`core.autocrlf=true` rewrites the working tree to CRLF on checkout, so
`Generic.Files.LineEndings.InvalidEOLChar` fired on 105 files that were LF in
the repository. `.gitattributes` now pins `eol=lf`, which also matches the
deploy target. `phpcs.xml.dist` sets `ignore_warnings_on_exit`, since the
line-length rule was declared as a warning specifically so it could not fail
the build, and phpcs was failing on it anyway.

**Verification**

- 32 unit tests over `Course::fromInput()` and `CourseStatus`, including the
  boundaries (255-character title, pass mark 0 and 100, same start and end
  date) and that `''` for an enrolment limit means *no limit* rather than
  *nobody may enrol*.
- 15 integration tests against real PostgreSQL: every cross-tenant read,
  update, publish and delete, plus catalogue visibility and the ORDER BY
  allow-list.
- 33 live checks against the running stack covering the full sequence —
  login, create, publish-empty → 409, add a lesson, publish → 200, catalogue
  excludes drafts, learner is refused create and publish, and every
  cross-tenant call returns 404.

**Noted, not changed:** `users.tenant_id` does not cascade from `tenants`,
while `courses` and `lookups` do. Deleting a tenant with users still in it
fails on the foreign key. That is arguably the safer behaviour, but it is an
inconsistency worth a decision before the first production tenant is removed.

### 2026-08-25 — Legacy user import

`legacy:import-users` — read-only against MySQL, idempotent against PostgreSQL,
`--dry-run` by choice. Verified against the real legacy data: **18 tenants,
14 users, 160 roles, 22 assignments**, imported and re-imported cleanly.

**Two corrections the real data forced:**

- **Eight roles, not five.** The legacy seed creates `superadmin, admin,
  hr_manager, hr, manager, instructor, content_creator, learner` per tenant.
  `SystemRole` had a five-rung ladder that was invented rather than observed.
  The four extra roles are now *lateral* — they inherit `learner` and nothing
  else.
- **Legacy `superadmin` is per-tenant**, so it maps to `tenant_admin`, not
  `super_admin`. Mapping it the obvious way would have given every tenant's
  administrator access to every other tenant.

**⚠ The reconciliation rule escalates four users.** "`user_roles` wins" produced
four disagreements and every one grants *more* than the flags did
(`superadmin`→`tenant_admin` vs `is_admin`→`org_admin`, +6 permissions each).
The command prints this as an explicit warning. **Confirm those four before
running for real in production.**

**Three bugs caught by checking PostgreSQL rather than the command's own
summary:**

1. Role assignments silently dropped — the enum gained four cases but the
   `roles` table still had five rows. Reported 22, wrote 20. Fixed by a seed
   migration; `assignRoles()` now returns what it could not assign.
2. Every user imported as `invited` — the rule read `approved` and
   `login_count`, both `NULL` in real data even for users with a recent
   `last_login_at`.
3. Passwords are bcrypt **cost 10** against this system's 12, so all 14 rehash
   transparently on next sign-in. Working as designed, now reported.

### 2026-08-25 — Bridge rehearsed against the real legacy code

The bridge cannot be deployed from here — it is a live authentication path on
a host with no access from this environment. What was done instead is a full
rehearsal, so production is not the first place it runs.

`infra/legacy-bridge/rehearsal/` builds a throwaway MySQL 5.7 + PHP 8.2 stack
running an isolated copy of `legacy/backend`, with the production database
credentials rewritten out and asserted absent before anything starts.

**The bypass reproduced exactly.** With `token: "nonsense"` the unmodified
legacy code returned `200 success` and real course rows.

After applying the bridge, all eight checks behave:

| Request | Before | After |
| --- | --- | --- |
| garbage token | 200 | **401** |
| wrong token, real user | 200 | **401** |
| near-miss token | 200 | **401** |
| SQL injection in `id` | 200 | **401** |
| valid `users.token` | 200 | **200** — nobody signed out |
| new-API JWT, matching `sub` | — | **200** |
| new-API JWT, wrong `id` | — | **401** |
| refresh token as bearer | — | **401** |

Two real deployment problems the rehearsal caught, both now fixed:

1. **`Database.php` never loads Composer's autoloader**, so the first JWT would
   have fataled with *Class Firebase\JWT\JWT not found*. The verifier now loads
   it itself when the class is absent.
2. **The host runs PHP 8.x, not 7.4.** The dump header says 7.4.33, but that is
   phpMyAdmin's runtime — `Database.php` uses `match()`, which is 8.0+.

Also added `infra/legacy-bridge/verify.sh` for the post-deploy check against
production.

### 2026-08-25 — Reference data: 52 files → 6 routes

The largest single deduplication available, and the first module where the
**path shape** changes on migration rather than just the host.

Six legacy tables (branches, departments, teams, levels, job titles,
categories) were the same thing wearing different column prefixes —
`branch_name`, `department_name`, `title_name`, `level_name`, `team_name`,
`name`. That duplication is what produced 36 near-identical PHP endpoints
across 52 files, each with its own class.

Now: one `lookups` table with a `kind` discriminator, one repository, one set
of six routes.

| | Legacy | New |
| --- | --- | --- |
| Tables | 6 | 1 |
| PHP files | 52 | 6 actions + 1 base |
| Endpoints | 36 | 6 |
| Classes | 6 | 1 repository |

Adding a seventh kind is now an enum case and a check-constraint value.

🔴 **Found a second live security defect while porting** — recorded as action
0b. The legacy reference-data queries are not tenant-scoped: `SELECT * FROM
branches` with no WHERE at all, and `UPDATE branches … WHERE branch_id = ?`
with no tenant column. Any caller can read every organisation's structure and
edit or deactivate another's records by id. Only the `active` dropdown query
was scoped.

In the replacement, isolation is structural: `tenant_id` is NOT NULL, every
index is composite on it, and `LookupRepository` has no method that omits it —
the mistake cannot be written. Ten integration tests against a real PostgreSQL
assert it from both directions, including that two tenants *may* use the same
name.

Verified live across two tenants: list, read-by-id, update and deactivate on
another tenant's row all return 404, and the target row is untouched.

94 → 116 unit tests, plus a new integration suite (10) that skips cleanly when
Docker is not running.

### 2026-08-25 — Renamed Bayti → Central

`Bayti\Api\` came from the original stack spec, but the name had been extended
into the pnpm scope, JWT issuer/audience, cache namespace, session key, class
names and log prefix — none of that was specified, and all of it contradicted
`centralLMS` / `central_lxp` / `centrallms.com`.

101 files rewritten: `Central\Api\` namespace, `@central/*` packages,
`central-lms` / `central-clients` JWT identity, `central.session`,
`CentralTokenVerifier`, `[central-auth]` logs.

Two things the rename breaks that are **not in code**, both handled:

- **`doctrine_migration_versions` stores fully-qualified class names.** After
  the rename Doctrine considered both migrations unexecuted and would have
  tried to re-run them against existing tables. The ledger rows were rewritten;
  **any other environment needs the same `UPDATE` before deploying.**
- **JWT `iss` and `aud` changed**, so every previously issued token is invalid.
  Harmless while nothing is deployed, but the legacy bridge's environment
  variables must carry the new values.

Left alone deliberately, because they are yours and not mine to change:
`TERMII_SENDER_ID=Bayti` (a registered SMS sender id — renaming it breaks
delivery) and `title = 'ThreeBayti'` in `app.component.ts` (a brand string, and
a product decision).

### 2026-08-25 — Phase 0 bridge: the auth bypass fix

[`infra/legacy-bridge/`](infra/legacy-bridge/README.md) — a drop-in replacement
for `Database::verify_user()`. **Written and tested; not yet deployed.**

It accepts **either** credential, which is what lets it close the bypass
without signing anyone out:

- a JWT from the new API — signature, `iss`, `aud`, `exp`, `typ=access`, and
  `sub` matching the caller's claimed id
- the existing `users.token` — prepared statement, `hash_equals`; the check the
  original was meant to perform

Three defects fixed in one file: the unconditional `return 100`, the SQL
injection on `user_id`, and the use of `similar_text()` (a *fuzzy* match, so a
near-miss token would have passed even with the return restored).

The signature and the `100` contract are unchanged, so **all 248 call sites
keep working untouched** — no other legacy file needs editing.

13 tests, run against the real `JwtTokenService` so the two implementations
cannot drift apart on signing. `LEGACY_AUTH_AUDIT_ONLY` exists for a short
measurement window and is off by default.

81 → 94 tests.

### 2026-08-25 — Identity slice: tenants, users, roles, auth

First real module ported. Detail: [`identity.md`](docs/migration/identity.md).

- Schema: `tenants`, `users`, `roles`, `role_permissions`, `user_roles`,
  `refresh_tokens` — applied to the Docker Postgres
- `POST /v1/auth/login` · `/refresh` · `/logout` · `GET /v1/me`
- Refresh tokens stored as SHA-256 hashes, **rotated on every use**, revocable
  individually or per user
- `DbalRoleRepository` — built-in roles are rows with code-defined permissions;
  custom tenant roles are data, filtered through the `Permission` enum
- `php bin/console tenant:provision` bootstraps the first tenant and admin
- 67 → 81 tests

Verified end to end against the running stack: sign in, `/me`, rotate, replay
rejected, sign out revokes.

**A test caught a real security flaw.** The failed-login timing decoy was a
hardcoded cost-12 hash; against cost-4 accounts that made the *unknown*-email
path **230× slower** than the known one — an account-enumeration oracle just as
usable as the one it was meant to close. The decoy is now derived from the
configured hasher so costs always match.

⚠ **Open before any data import:** the legacy `users` table records roles four
ways (boolean flags 86 refs, `role_id` 67, `user_roles` 25, `role` column).
Only `user_roles` survives; the collapse rule is proposed but unconfirmed — see
[`identity.md`](docs/migration/identity.md#-the-open-decision-four-role-mechanisms).

### 2026-08-25 — Frontend API layer; `GlobalComponent` retired

Angular 19 app moved into `apps/web` and given a strangler-ready API layer.
Full detail: [`frontend-api-layer.md`](docs/migration/frontend-api-layer.md).

- `ApiModule` / `ApiBackend` / `ApiRouter` — migrating a module is one line in
  the environment file; anything unlisted defaults to Legacy
- `ApiClient` normalises both dialects, including the legacy habit of returning
  **HTTP 200 for every failure**
- `authInterceptor` speaks both credential schemes (bearer header vs body
  fields), so callers do not change when a module migrates
- `SessionStore` (signals), `Endpoints` registry, `decodeJwt`, `Cipher`-free
  `decodeSessionBlob`
- `MediaService` migrated as the reference; **81 files still on
  `GlobalComponent`**, 29 on `CrudService`

Pre-existing defects found by actually building and running it:

| Defect | Impact |
| --- | --- |
| `JSON.parse(atob(sessionStorage…))` unguarded in **57 components, 67 sites** | any user with cleared or corrupt session storage got a blank page on those screens |
| Stray space in an import path (`'./ training-enrollment/…'`) | **broke the production build**; live-training route could never load |
| `ngModel` in a form with no `name` (`NG01352`) | user-profile avatar field threw at runtime |
| `src/styles.css` `@import`ed a `.scss` file | SCSS went through the plain-CSS pipeline |
| `ng-apexcharts` resolved to 2.x, which requires Angular 20 | build failure; the legacy lockfile pinned it too |
| `MediaPreviewComponent` spec imported a class that does not exist | spec had never run |
| `statistics.total_points` dereferenced before load | template threw |

Test suite went from **never runnable** (no karma config, windowed Chrome only)
to **75/75 green** with headless Chrome. Production build passes.

### 2026-08-25 — Roles as data, bcrypt, SaaS gap confirmed

- `Role` refactored from enum to a persisted model; `SystemRole` enum keeps the
  five built-ins with their inheritance ladder
- `RoleRepository` + `SystemRoleRepository` (in-memory until the `roles` table
  lands); `TokenAuthenticator` composes token verification with role resolution
- Tokens now carry role ids only — permissions resolved per request
- `PasswordHasher` (bcrypt, cost 12, 72-byte guard, rehash-on-login)
- Confirmed `apps/saas` is greenfield
- 47 → 67 tests

### 2026-08-25 — Live-surface analysis

- Measured the backend against the frontend: 169 live files vs 434 orphaned
- Corrected the `class/reporting/` finding — reachable via `api/reports.php`,
  but its frontend constant is declared and never used
- Found `class/reporting/` is a stale twin of `class/reports/` (17 files), and
  `earningPathEnrollment.php` declares a duplicate class name
- [`live-surface.md`](docs/migration/live-surface.md),
  [`backend-port-status.md`](docs/migration/backend-port-status.md)

### 2026-08-25 — Assessment unification

- Designed the shared core + proctoring extension
  ([`schema-assessments.md`](docs/migration/schema-assessments.md))
- 7 domain enums with `fromLegacy()` mappings and tests
- Split `proctoring.configure` / `proctoring.review` out of `assessment.manage`

### 2026-08-25 — Legacy exploration

- 🔴 Found live production DB credentials and a Zoom key in the working tree;
  git-ignored both, history clean
- Found the ILT root cause; rebuilt the provider layer for per-tenant OAuth
- [`inventory.md`](docs/migration/inventory.md),
  [`ilt-rewrite.md`](docs/migration/ilt-rewrite.md)

### 2026-08-25 — Monorepo

- pnpm workspaces + Turborepo, `apps/{api,web,saas,mobile}`, `packages/*`
- Slim 4 API foundation, Docker stack (Postgres 16 + Redis 7), legacy drop zone
