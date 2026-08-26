# Progress

Living status for the Central LMS modernisation. Updated as work lands — see
[Update log](#update-log) at the bottom.

**Legend:** ✅ done · 🟡 in progress · ⬜ not started · 🚧 blocked · ⚠ needs attention

---

## Right now

| | |
| --- | --- |
| **Current focus** | Frontend cutover. **The backend is complete** — every module the legacy had, ported and tested |
| **Blocking the team** | 🔴 Legacy API authentication is bypassed — see action 0 |
| **Blocking the next slice** | Nothing. The frontend can now drop the legacy backend entirely |
| **API quality gate** | ✅ 639 unit + 477 integration tests / 3,685 assertions · PHPStan level 8 clean · 0 style errors · 968 live checks |
| **Web quality gate** | ✅ 75 / 75 specs · production build passes |

### ⚠ Outstanding actions

| # | Action | Owner | Status |
| --- | --- | --- | --- |
| 0 | 🔴 **Authentication is bypassed on the entire legacy API.** `Database::verify_user()` hardcodes `return 100`; every caller checks `!= 100`, so the check never fails. 248 live files. Also SQL-injectable on `user_id`. → **Fix written and tested: [`infra/legacy-bridge/`](infra/legacy-bridge/README.md). Awaiting deployment to the live host.** | — | 🟡 ready to deploy |
| 0b | 🔴 **Cross-tenant data leak in the live reference-data endpoints.** `dictionary/*/list.php` runs `SELECT * FROM branches` with no tenant filter, and `Branches::update()` / `setStatus()` run `WHERE branch_id = ?` with no tenant column — so any caller can read every organisation's structure and edit or deactivate another's records by id. Affects branches, departments, job titles, levels, teams. Only the `active` dropdown query is scoped. Fixed in the new API; **the live PHP still has it.** | — | ⬜ |
| 0c | 🔴 **The reporting API trusts the caller for which organisation to report on.** `api/reports.php` reads `tenant_id` straight from the request body and passes it to `setTenant()`; nothing ties it to the authenticated user. All 190 report queries scope correctly — to the tenant they were handed. So one valid user can read another organisation's entire reporting surface: every learner's name, email, scores and completion, across 84 reports. Action 0 does not close this — it restores authentication, and this needs the tenant to come from the session instead. Separately, the `audience` field on each report is a display filter on the catalogue listing and is **never checked when a report runs**, so any authenticated user can run the executive and audit reports. Both are fixed in the new API. | — | ⬜ |
| 0d | 🟠 **One organisation can rewrite another's settings.** `user/tenant-settings.php` reads `tenant_id` from the request body, hands it to `setTenant()`, and checks only `is_admin()` — whether the caller administers *something*, not whether they administer the organisation they just named. The endpoint writes: `update-setting`, `update-tenant-info` and `import` are all reachable this way, so any tenant administrator can repaint another organisation's branding, rename it, or replace its whole settings file. The same shape as 0c, on the write side. Fixed in the new API; **the live PHP still has it.** | — | ⬜ |
| 1 | 🔴 **Delete `webhooks/noon-payments.php` from the live backend.** A payment webhook we do not use and never will — removed from this repo. If it is still deployed it is a reachable endpoint that writes to `orders` and holds a second database's credentials, so deleting the file is the fix; rotating its secret is not. | — | ⬜ |
| 2 | Rotate **three** database passwords found hardcoded in legacy source: `central_lxp` (`config/Database.php`), `sql_ifitness_cen` (`codebase/public/config.php`), and `sql_3bayti_com` — that last one came from the payment webhook in action 1. Deleting the file removes our copy, not the credential: rotate it too. | — | ✅ done 2026-08-26 |
| 3 | Rotate the Zoom API key — it appears in `environment.ts`, `environment.development.ts` **and** hardcoded as a fallback in `video-integration.service.ts` | — | ✅ done 2026-08-26 |
| 4 | Restrict the production MySQL host to known source IPs — all three databases sit on the same public address | — | ⬜ |
| 5 | Commit redacted copies of the four excluded files so their structure stays available for the port | — | ⬜ |
| 6 | Delete `legacy/backend/class/earningPathEnrollment.php` from the **live** backend — duplicate class name, one careless `require` from swapping the implementation | — | ⬜ |

**Items 2 and 3 are closed**: the credentials were rotated on 2026-08-26.
Every file holding one was git-ignored and `legacy/` was untracked when found,
so git history was clean throughout.

Items 1 and 4 are **not** rotations and remain open — one is a file to delete
from the live host, the other a firewall change. Items 0, 0b and 0c are code
defects in the live PHP; rotating secrets does not touch them.

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
| 2 | Backend rewrite (Slim/PostgreSQL) | ✅ | Every module ported. **232 routes** covering the 96 live legacy endpoints, and finishing what they never did |
| 3 | ILT (Zoom / Meet / Teams) | ✅ | Wired and tested; needs a tenant to connect an account and platform credentials in settings |
| 4 | Prevent repetition | ✅ | 72% of backend files unreachable. Reference data 52 files → 6 routes; skills 25 actions → 16; notes 11 files → 7; messaging 11 tables → 3 |
| 5 | Frontend (keep + improve) | 🟡 | Sign-in migrated. **Next up**: 81 files still on `GlobalComponent`, 29 on `CrudService` |

---

## Backend port

**All 96 live endpoints ported, as 232 routes.** Scope measured against the
frontend, not guessed — see [`live-surface.md`](docs/migration/live-surface.md).
The route count is higher than the endpoint count because a legacy endpoint
that switched on an `action` field in its body becomes one route per thing it
did.

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
| 6 | enrolments + progress | ✅ | **9** | Bulk assign by department/team/branch; progress derived in-transaction |
| 7 | assessments | ✅ | **34** | Banks, questions, sections, all 3 selection modes, attempts, marking |
| 7b | proctoring (advanced module) | ✅ | **8** | Entitlement-gated; settings, sessions, violations, snapshots, review |
| 8 | ILT + attendance | ✅ | **24** | Programmes, sessions, real meeting booking, QR check-in, provider attendance sync |
| 9 | certificates | ✅ | **12** | Templates, issuance, revocation, public verification |
| 10 | notifications | ✅ | **11** | One delivery ledger; four legacy queues, all empty, not ported |
| 11 | reporting | ✅ | **11** | 57 reports; tenant from the token, `audience` enforced |
| 12 | people | ✅ | **9** | Placement and activity resolved in the list query, not per row |
| 13 | gamification (points, badges, levels, streaks) | ✅ | **9** | Totals derived from the ledger; `user_level_progress` not ported |
| 14 | media / SCORM | ✅ | **9** | Versions as rows; `is_latest_version` derived; bytes served through the API |
| 15 | dashboards | ✅ | **2** | Assembled from the reports; the learner's own takes no id |
| 16 | notes | ✅ | **7** | 11 legacy files, 8 of which could not run — the class writes columns the table lacks |
| 17 | learning paths | ✅ | **24** | 5 tables of 10 + 2 views; progress derived from the courses, not stored |
| 18 | messaging | ✅ | **12** | 3 tables of 11 + a view; one content column where there were three |
| 19 | skills | ✅ | **16** | Derived from completed courses; the two acquisition tables were empty *and* unread |
| 20 | tenant settings | ✅ | **6** | 4 categories of 8 kept — the other four were read by nothing |

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

### 2026-08-30 — Messaging, and the backend is complete

Twelve routes, three tables where the legacy had eleven plus a view, and one
content column where it had three.

**`content`, `content_html` and `content_plain`** hold the same string,
character for character, in all nine messages. Nothing ever rendered one
differently from another and nothing decided which to read. One column.

**Eight tables with nothing in them**, and the reason matters in each case.
`message_attachments` is a real feature that belongs on the media module — an
attachment is an uploaded file, and there is now one place that stores those.
`message_read_receipts` is a row per person per message, where what anybody
wants from a conversation is "what have I not read", which is one timestamp per
participant. `broadcast_messages` **is** `notification.send`, which the
notification module already does with delivery tracking and per-person
preferences. The rest — reactions, mentions, drafts, saved messages, reports —
each need a screen that does not exist.

**A pair can no longer end up with two conversations.** The legacy had four
direct conversations and nothing stopping a fifth between a pair that already
had one, which splits a conversation in half and hides the older one. A sorted
pair key under a unique index makes it impossible, and starting a chat is
find-or-create, so two taps produce one conversation.

**The six `can_*` flags on a participant are zero on every row, including every
owner's.** A permission model that says an owner may not post is not one
anybody consulted. The role decides.

**Counters gone:** `conversations.message_count`, `last_message_id`,
`last_message_at`, and `conversation_participants.unread_count` — one write per
participant, per message, to say what the messages already say. Unread is now
"since I last looked", from one timestamp.

Also not carried, all null or zero in every row: threads, forwarding, pinning
messages, priorities and scheduling. `reply_to_id` **is** used, so replies stay
— and a reply to a message since deleted shows the same placeholder the message
itself would, rather than the words that were taken down.

Every route is under `/me` and resolves the conversation through the caller's
own membership. A conversation that is not theirs answers 404 rather than 403:
saying one exists but is not yours says who is talking.

- 18 unit + 28 integration tests; **60 live checks**
- 1,116 tests / 3,685 assertions · PHPStan level 8 clean · 0 style errors

---

**That is the last module.** Every part of the legacy backend now has a ported
equivalent, and the frontend can drop it entirely. What the port removed along
the way, module by module: 19 stored counters on learning paths, 6 more on its
enrolments, 3 on conversations, 2 notification queues, 4 legacy delivery
queues, `user_level_progress`, `user_streaks`, `media.is_latest_version`,
certificate template `usage_count`, `scheduled_reports.next_run_at`,
`live_training_reminders` — every one of them a value stored beside the rows it
summarised, and every one of them found to have drifted.

### 2026-08-30 — Learning paths

Twenty-four routes, five tables where the legacy had ten plus two views, and
one stored value in place of nineteen.

**The counters disagreed with each other.** The single path in the database
says `total_items = 2`. It has **three** item rows, all deactivated, so the
true count is **zero**. Every one of its **thirty-three** enrolments carries
`items_total = 4`, and the path's own `total_enrollments` says **19**. Three
stored answers to one question and none of them right, on a path that is
published with nothing in it. All nineteen counters on `learning_paths` and the
six on the enrolment are gone.

**An item's progress is the progress of the thing it points at.**
`learning_path_item_progress` stored `status`, `progress_percentage`,
`best_score`, `passed` and `attempts` *beside* the `course_enrollment_id` and
`assessment_attempt_id` that already held all five — and every one of its
fourteen rows says `locked` with both foreign keys null. Nothing ever advanced
one.

So a course item is finished when the learner's enrolment on that course is
complete; an assessment item when they have a graded attempt that passed; a
session item when the register says they attended. One query, four sources, no
table to keep in step. It also means **a path credits work finished before
somebody joined it**, which the legacy could not express at all: its item
progress began at `locked` on enrolment.

`learning_path_item_completions` remains for the two kinds that produce no
evidence of their own — a link and a piece of media — and refuses to accept a
course, because marking one done here would be a second opinion about the
course.

**A path enrols its members on its courses.** Joining a path now puts you on
the courses in it, and adding a course to a path puts the existing members on
that too — both idempotent, so somebody already taking one keeps the progress
that makes the path credit it. Found by the live suite: a learner on a path
could not open any of its steps.

**Not carried:** `learning_path_reviews` (0 rows) with `avg_rating` and
`total_ratings`; `learning_path_prerequisites` (0); `learning_path_section_progress`
(0); `learning_path_activity_log` (3 rows, an audit trail of the progress the
progress rows already carry); `learning_path_assignments` (1 row — an enrolment
is how somebody is put on a path); both views; pricing, in a platform with no
commerce, where the one path is free and priced in USD; and the per-item unlock
engine — six `unlock_type` values, a JSON list of item ids, an unlock date and
a minimum score, where every row in the database says `previous_item` with all
four of the others null.

Unlocking follows from the path's own pacing instead: one after another, any
order, or section by section. Three rules, and they are the ones anybody used.

Two things the legacy allowed that the schema now refuses: an item pointing at
two things at once (it had five nullable ids and nothing stopping three being
set), and a published path with nothing in it.

- 26 unit + 28 integration tests; **64 live checks**
- 1,070 tests / 3,576 assertions · PHPStan level 8 clean · 0 style errors

### 2026-08-29 — Skills

Sixteen routes for twenty-five legacy actions, and two tables that are not
carried because nothing wrote to them *or* read them.

**`user_skills_acquired` and `user_skills_log` hold zero rows and were never
read.** That second half is what makes them different from the other empty
tables in this port. `Skills::getLearnerSkills()` derives a person's skills by
joining their completed enrolments to `course_skills` — it never touches the
acquisition table. So the feature was built at both ends and wired at neither,
and the end that works is the derived one. Finishing the fire-safety course is
what makes somebody fire-safety trained; a second table saying so can only
disagree with it.

Two things derivation cannot express, recorded in the migration with what each
would need: a skill **granted by hand** (`source_type` allowed `manual` and
`external`, and no endpoint ever granted one) and a skill that **expires**
(`expires_at` was never written; expiry should follow the certificate that
evidences the skill rather than a date typed into a second place). Instructor-led
training teaching a skill is a third gap — `live_training` was an allowed
source and `course_skills` links only courses.

**Five endpoints collapsed into two.** `my-skills`, `my-skills-by-category`,
`my-skill-progress`, `user-skills` and `user-skill-progress` were near-identical
queries whose only real difference was `status = 'completed'` — and they
disagreed about whether an in-progress course counted towards proficiency. One
query with a flag now answers all five, and the grouping happens once.

**An enrolment on an expert course does not make somebody an expert.** The
proficiency is the highest level demonstrated by a course actually finished,
worked out in SQL rather than by `GROUP_CONCAT`ing the levels and walking a
hardcoded list of four strings in PHP, twice, in two places that disagreed.

`Proficiency` is deliberately not `Difficulty`. They share two words and
measure different things: difficulty describes the material, proficiency
describes the person. A beginner course can leave somebody expert at the one
narrow thing it covers.

Two constraints the legacy had no equivalent of: a skill cannot be filed under
another organisation's category (it joined on `category_id` alone), and a course
can have only one primary skill (every row could set the flag, so a course could
be "really about" four things).

- 9 unit + 25 integration tests; **59 live checks**
- 1,016 tests / 3,456 assertions · PHPStan level 8 clean · 0 style errors

### 2026-08-29 — Tenant settings

Six routes, four categories of the legacy's eight, and the first time any of
these values does anything.

**Only branding was ever written, and nothing was ever read.**
`TenantSettings.php` declared eight categories and around seventy keys. The
table holds sixteen rows: eight branding keys, two tenants, saved once in
January and never touched. Of the rest, `password_min_length` and its four
neighbours are the clearest case — declared, offered on a settings screen, and
consulted by no line of backend code. `change_password.php` accepts a single
character.

**A setting that nothing reads is a form field that lies to whoever fills it
in.** So four categories are not carried, and the catalogue says why for each:
`learning` is mostly per-assessment settings that belong where the assessment
is authored, plus three behaviours that do not exist yet; `storage` is
superseded by the per-kind limits in `MediaKind` and by `storage_limit_mb`,
which the media module already enforces; `notifications` would duplicate the
per-person preferences already kept, and needs the resolver to take a second
layer first; `integrations` configures SSO and webhooks that are not built.
Every remaining key carries a line naming what consults it, and a test asserts
that line is not empty.

**The four that stayed now do something.** Branding and the general display
settings are read by the web app. The gamification values are read by the
`Awarder`: `points_per_lesson` and its neighbours finally decide what an award
is worth, and turning gamification off stops new points without touching
anything already earned. The security keys drive a new `PasswordPolicy`, which
replaces the rule that was hardcoded in `PersonInput::checkPassword` — the
default is exactly what that rule was, so nothing changed for a tenant that
configures nothing.

Two gamification keys were dropped for the same honesty rule:
`points_per_session` and `points_per_streak` have nothing granting them.
Attendance would need the ILT register to call the `Awarder`; a streak needs
something to notice one.

**Lesson completions now award points.** `lesson_completion` is one of the
eight spellings in the legacy's 122 point events, and this port had defined the
reason with nothing granting it — found by writing a setting for it and having
nothing to point at.

**One organisation could rewrite another's settings.** `user/tenant-settings.php`
reads `tenant_id` from the body and checks only `is_admin()`, which asks whether
the caller administers *something*. It writes. Recorded as action 0d. Here the
tenant comes from the token, and a body key that is not a declared setting is a
422 rather than a row.

A new permission, `settings.manage`, sits with the org admin. `tenant.manage`
stays what it is: the platform's control over a tenant record, not an
organisation configuring its own colours.

`/v1/settings/public` is deliberately unauthenticated and deliberately narrow —
the sign-in page needs a logo and the password rules before there is a token,
and it returns those two things and nothing else.

- 27 unit + 13 integration tests; **47 live checks**
- 982 tests / 3,378 assertions · PHPStan level 8 clean · 0 style errors

### 2026-08-29 — Notes

Seven routes for eleven legacy files, eight of which could not run.

**The notes feature was never connected to its own table.**
`class/Note.php` — the class behind all eleven endpoints under `user/notes/` —
inserts `title` and `lesson_timestamp`. The `notes` table has neither column.
So `create`, `update` and `search_notes` fail against the shipped schema, every
time, and always have. The Angular `Note` interface matches the broken class,
down to both field names, so the notes screen was written against an API that
cannot answer it.

All five notes in the database came from somewhere else:
`user/course-player.php` carries its own `saveNote()` and `getNotes()`, which
write `content` and `video_timestamp` and do match the table. That half works
and can only create and read — no edit, no delete, no pin, no search. Two
implementations of one feature, and the one with the endpoints is the one that
does not work.

The new table carries both fields under one name each. `video_timestamp` and
`lesson_timestamp` were the same thing under two names, and it is a position in
a recording, so it is `position_seconds` — the same name `lesson_progress`
already uses for a resume point.

**`enrollment_id` is not carried.** The legacy kept it and queried by it, so a
note lived and died with an enrolment: unenrol somebody and re-enrol them and
their notes are orphaned from the course they are reading. A note belongs to a
person and a lesson. "The notes for this enrolment" is the same set of rows as
"this person's notes on this course", and it survives re-enrolment.

**Everything is under `/me`.** All eleven legacy endpoints took `user_id` from
the request body, which with `verify_user()` returning 100 is a note-reading
API for the whole organisation. There is no user id in any of the new paths.

**A note may only be written against a lesson the writer can open.** Checked
against the enrolment, and a lesson that does not exist, one in another tenant
and one on a course they are not taking are all the same 404 — telling them
apart turns the endpoint into a catalogue enumerator.

**An edit touches only what it mentioned.** A form that sends the one field it
changed must not clear the other three; found by the live suite, which searched
for a note by its title after an unrelated edit had silently wiped it. Pinning
is set explicitly rather than `SET is_pinned = NOT is_pinned`, which is what
`toggle_pin.php` did — two taps racing each other land wherever the second
write computed, and neither client is told which.

- 12 unit + 18 integration tests; **48 live checks**
- 942 tests / 3,082 assertions · PHPStan level 8 clean · 0 style errors

### 2026-08-29 — Dashboards

Two routes and 1,877 lines of legacy SQL that mostly did not need porting.

**The admin dashboard is assembled from reports that already exist.**
`AdminDashboard.php` was 842 lines, and almost every figure in it had a twin in
`class/reports/` — written separately and disagreeing in places. The clearest
case is "mandatory": one report counted the enrolment's flag, the other the
course's, and the dashboard was a third opinion. Nothing in the new one writes
a query a report already answers. Eleven of its thirteen sections are report
runs; what is left — the course and people mixes, the deadline queue and the
activity feed — is a 400-line repository.

The scope comes free with it. A line manager holding `report.view` and nothing
else gets a dashboard covering themselves and their direct reports, because the
report queries already narrow that way.

**The learner dashboard takes no id.** `learner/dashboard.php` read `user_id`
and `tenant_id` from the request body, and with `verify_user()` returning 100
unconditionally that is two ids a caller picks for themselves. `/v1/dashboards/me`
is about whoever holds the token. It does not go through the reporting layer
either — a learner holds no report permission, and routing their own progress
through it would mean granting one.

**The streak is derived.** `user_streaks` stored `current_streak` and
`longest_streak` as columns, kept up to date by whatever wrote
`learning_sessions` — a table holding **three rows for the entire platform**.
Eight people therefore carried a number nothing could decrement and almost
nothing had incremented. A streak is a fact about a list of dates, and
`LearningStreak` is that function: current run, longest run, and whether it
survives today not having started yet, which the legacy's "activity today or
yesterday" check got right and its stored counter did not.

The resolution is honest rather than flattering: one progress row per lesson
means the finest the schema carries is "the last day you touched each lesson",
plus the day each attempt was submitted. Somebody re-reading one lesson every
morning counts once. Finer would need an append-only activity log —
`learning_sessions` was meant to be one, and never ran. Recorded on
`DashboardRepository::activeDays()` rather than papered over.

**Growth is null when there is nothing to grow from.** The legacy compared
`MONTH(created_at) = MONTH(NOW()) - 1`, which is 0 every January, and divided by
a previous month that was empty — so every tenant's first month showed +100%.
The window here is the same length as the one before it and immediately
preceding, and a comparison against nothing returns null for the client to draw
as a dash.

- 14 unit + 19 integration tests; **53 live checks**, including doing a lesson
  and watching both dashboards move
- 912 tests / 2,999 assertions · PHPStan level 8 clean · 0 style errors

### 2026-08-29 — Media library

Nine routes, one table where the legacy had four, and the first module whose
bytes never leave the API.

**Three of the four legacy tables are not ported.** `media_thumbnails` and
`media_processing_queue` hold zero rows between them: transcoding, thumbnailing
and SCORM validation were designed and never run, the same story as the four
notification queues. `media_version_history` is not a table at all — it is a
view joining `media` to `users`, which is to say a query, and it lives in the
repository as one. `media.is_processed` goes with them: a flag whose only
writer never existed.

**`is_latest_version` is derived.** It duplicated what `root_media_id` and
`version_number` already say, and keeping it true took two writes on every
upload — set the new row, clear the old one. A crash between them leaves a file
with two current versions or none, and nothing in the legacy noticed. It is now
a `MAX()` over the chain, computed in the same pass that counts the versions,
and a partial unique index makes a duplicate version number impossible rather
than merely unlikely.

**A version number is allocated under a lock.** The first row of a chain is the
lock for the whole chain; without it two people replacing the same file at the
same moment both read two, both write three, and the second upload is rejected
after its bytes are already stored.

**The bytes are served through the API, never through a link to storage.** The
legacy answered a media request with a `302` to a signed S3 URL — so the check
on who was asking happened once, and produced a URL that then worked for
anybody it was forwarded to, including somebody since removed from the
organisation. `GET /v1/media/{id}/file` checks the token on every fetch. It
also answers range requests, because a player seeking into a two-hour recording
sends one and a server that ignores it makes the player download the whole file
to skip five minutes.

Getting that right needed `ByteRangeStream`: the emitter rewinds a seekable
body before writing it, so a `206` built by seeking the underlying stream emits
the right number of bytes from the wrong place — the beginning of the file,
every time somebody drags the scrubber. Found by the live suite, not by the
unit tests, which is the same lesson as the CORS preflight.

**What may be uploaded is decided by the extension.** The legacy trusted the
`Content-Type` the browser sends, which is chosen by whatever is doing the
sending. `report.pdf.exe` is an exe, a filename carrying a path keeps only its
last segment, and the storage key is built from the tenant, the kind and 16
random bytes — never from anything the caller sent.

**`tenants.storage_limit_mb` is read.** It has been on the table since the
first schema and was never once consulted. An upload that would exceed the
allowance is refused, and the size is measured as the bytes are written rather
than taken from the `Content-Length` the client claimed.

Storage is now swappable — local disk by default, S3 when `STORAGE_DRIVER=s3`,
the same pattern as `MAIL_PROVIDER=log`. A deployment with no object storage
configured stores files somewhere real instead of failing every upload against
an empty endpoint.

Two permissions added: `media.view` for playing what a lesson embeds,
`media.manage` for replacing it.

- 16 unit + 24 integration tests; **54 live checks** against real uploads,
  real bytes on disk, and real range requests
- 879 tests / 2,905 assertions · PHPStan level 8 clean · 0 style errors

### 2026-08-28 — Reporting

Fifty-seven reports, eleven routes, and the last backend module.

Reporting is the one part of the legacy that was actually finished. All 84
catalogued reports have a method behind them, all 190 queries name a tenant,
and the code is organised sensibly. It also contains the worst security defect
found in this port so far.

**`class/report/` is dead.** Two complete forks of the reporting layer exist —
`class/report/` and `class/reporting/` — around seven thousand lines each, and
every one of the fifteen shared files differs between them. Only
`class/reporting/` is reachable: `api/reports.php` requires it, and nothing in
the codebase references the other. The fork is not ported.

**The reporting API trusts the caller for which organisation to report on.**
`api/reports.php` reads `tenant_id` from the request body and passes it to
`setTenant()`. Nothing ties it to the authenticated user. Every one of the 190
queries then scopes faithfully — to whichever organisation the caller named. One
valid user can read another's entire reporting surface: every learner's name,
email, score and completion, across 84 reports. Recorded as action 0c, and it
survives action 0: restoring authentication does not connect the token to the
tenant.

**The `audience` field is never checked when a report runs.** Each catalogue
entry carries one — `['admin', 'hr']` — which `getCatalog()` uses to filter the
*listing*. `runReport()` does not consult it at all, so naming a key is enough
to run the executive dashboard or the audit trail.

Both are structural here rather than conventional.

**The tenant is not a parameter.** It arrives inside a `ReportScope` built from
the authenticated caller, and `ReportQuery::tenant()` is the only way to obtain
a WHERE clause. There is no setter and nowhere to pass a different one. A live
check POSTs another tenant's id and watches the figures not move.

**The permission is on the definition and checked at run time**, and the
catalogue listing is derived from the same field — so a report cannot appear in
someone's list and then refuse to run, nor the reverse. `proctoring_violations`
carries `proctoring.review` rather than `report.view`: its rows describe a named
person suspected of cheating, sourced from webcam and screen monitoring, and the
permission gating the recordings gates the report about them.

**Row-level scope is the third tier the legacy did not have.** `setScope()` took
`department_id` and `learner_id` from the request body and used them as
conveniences for narrowing a view — nothing stopped a caller omitting them and
seeing everybody. Here someone holding `report.view` alone sees themselves and
their direct reports; `report.export`, `user.manage` or `enrollment.manage`
widen it to the organisation. Without that middle tier the only choices are
everything and nothing, and a line manager gets one of the two. The response
says which they got, because a completion rate of 40% means something different
over eleven people than over four hundred.

**Three tables become two.** `saved_reports` carried a `schedule` JSON column,
`scheduled_reports` was a separate table with the same job in columns, and
`scheduled_report_runs` — the log for both — has a `saved_report_id` foreign
key, so a row created by the second path points at an id from the first.

**`next_run_at` is not stored**, for the same reason
`live_training_reminders.scheduled_at` should not have been: it sat beside the
frequency it derived from and nothing recomputed it, so a weekly report changed
to monthly keeps arriving weekly. Nor are `last_run_at` and `last_run_status` —
they are the newest row of `report_runs`, which is a join.

**A manual run does not satisfy a schedule.** Somebody clicking "run now" on a
Monday morning report must not cancel Monday's delivery, and without a trigger
column the two runs are indistinguishable afterwards. Nor does a failed one: a
provider outage on Monday would otherwise mean no report until the following
Monday, with nobody told.

**The CSV writer is its own class for two reasons.** A cell beginning `=`, `+`,
`-` or `@` is executed as a formula when the file opens; report rows carry
names and course titles typed by people, so `=HYPERLINK("http://…"&A1)` in a
course title is a live exfiltration link in an administrator's spreadsheet.
Every text cell is prefixed with a tab, which the spreadsheet strips on display
and which stops the parse. And Excel on Windows reads a UTF-8 file without a
byte-order mark as the local codepage, mangling every accented name.

**Found by running it.** Paging had no tiebreaker, so rows tying on the sort
column could appear twice across pages and others not at all — the kind of
wrong that looks like missing data. Two reports disagreed about what
"mandatory" means, one counting only the enrolment flag and the other the
course's too; there is now one constant. And an unknown `category` filter was
silently ignored and returned the whole catalogue, which is how somebody asks
for "just compliance" and exports the lot.

**Twenty-seven reports are not here**, each waiting on a module this port has
not reached: gamification (6), audit (6), system (6), plus ratings, SCORM,
versioning, login history and the four needing cost data. They are listed by
name in `ReportCatalogue` with what each one waits for, rather than dropped
silently.

**Verification**

- 54 unit tests over the catalogue, the criteria, the scope, the CSV writer and
  the schedule arithmetic.
- 42 integration tests against real PostgreSQL: every report run against a
  schema, figures checked against a tenant worked out by hand, a second tenant
  that must never appear in any of them, and a manager who must see three
  people rather than five.
- 80 live checks.

Gate: 743 tests / 2,583 assertions, PHPStan level 8, no style errors. All nine
live suites run together: **583 checks, none failing**.

### 2026-08-27 — Notifications

Nineteen routes, a console command, four transports, and the piece none of the
four legacy queue tables ever had: something that reads them.

The evidence this was designed and abandoned is unusually complete.
`notifications` holds ten rows, all hand-seeded for one user, every `data`
column NULL. `live_training_reminders` holds two, both `pending`, both due in
January 2026, both `sent_at` NULL. `live_training_notification_queue` holds
one, `pending`. `messaging_notification_queue` holds nineteen, all `pending`.
Nothing has ever been sent by this system.

**The bell has never worked, and it is worth saying why.** Both Angular top
bars call `utility/shared/notifications.php` and `mark_notifications.php`. Both
files do `new Notifications($db)` — plural — while `class/Notifications.php`
defines `class Notification`, singular. They then call `user_notifications()`,
`notification_count()` and `is_read_all()`, none of which exist on it, and read
columns `notifications_id`, `notification_message` and `created` where the
table has `notification_id`, `message` and `created_at`. Every request is a
fatal error. The frontend even carries a `Notifications` model class shaped to
match the response that endpoint would have returned had it worked.

**Four tables replace six.** The two queues were the same table written twice
with different enum members, and a third would have appeared the first time
certificates wanted an email; they become `notification_deliveries`.
`live_training_reminder_log` was a per-recipient send record with a channel and
a status — which is what a delivery row is — and keeping it separate is
precisely what forced `recipients_count`, `successful_count` and `failed_count`
onto its parent: three columns holding what `GROUP BY status` already knew, and
drifting the moment a retry succeeded.

**Reminders do not store when they are due.** The point worth reading twice.
`live_training_reminders.scheduled_at` was the session's start minus the
offset, and nothing recomputed it — so moving a session left its reminders
pointing at the old hour, and a learner would be told "starts in 1 hour" about
something that finished the day before. Here a schedule stores the offset and
the due time is computed from the session's *current* start every time it is
asked for. Moving the session moves the reminder by construction. A unit test
pins it, and the live suite moves a session and checks its reminders followed.

**The decisions are all in one pure class.** `ChannelRouter` answers which
channels a notification goes out on and when, with no clock, no database and no
transport, so every rule is a case someone can read and disagree with:

- SMS is in no type's defaults. It is billed per message and the tenant pays;
  opting *in* is a decision someone should make on purpose. It stays off until
  an entitlement says otherwise.
- Four types cannot be silenced in-app — session cancelled, session moved,
  certificate withdrawn, enrolment overdue. Each tells somebody that a
  commitment they already hold has changed underneath them. A learner who muted
  "cancelled" travels to a room nobody is in. What is protected is that they
  are told, not how many times: the email can still be switched off.
- Quiet hours apply to SMS and push only. Holding an in-app notification until
  morning makes the bell lie about what has happened; an SMS at 3am wakes
  someone up. Urgent overrides them, because a session cancelled at 06:00 for a
  09:00 start has to arrive before the learner sets off.
- Every candidate channel produces a decision, skips included, with the reason
  on the row. "Why didn't I get the email" is the question a notification
  system exists to answer.

**`QuietHours` is a class because the obvious implementation is wrong.**
22:00–07:00 is what people set, and `$now >= $start && $now <= $end` is false
for every minute of it. That bug does nothing visible — quiet hours simply
never apply — which is indistinguishable from working, to anyone testing during
the day.

**The claim is what makes two workers safe.** `FOR UPDATE SKIP LOCKED` in one
statement, and not a theoretical concern: the compose file restarts the worker
every five minutes and the old and new processes overlap. Without it that is a
duplicate email every five minutes. The retry arithmetic is done in SQL from
the row's own counters for the same reason. A worker that dies mid-send has its
claim released rather than leaving the row in `sending` forever — without which
notifications stop silently.

**In-app deliveries are born sent.** There is nothing to transmit; the
notification row is on the person's bell the instant it is written. This came
out of running the live suite: recording it as `pending` puts a queue entry in
the delivery log for something already delivered, and anyone reading that log
to answer "did it go out" draws the wrong conclusion.

**Two bugs found by running it.** Timestamps were being sent as
`DateTimeInterface::ATOM`, which truncates to whole seconds while the columns
are compared against `now()`, which does not — so a delivery written at
07:28:13.9 was invisible to a dispatcher asking for everything due by
07:28:13.0, for a whole second, on every run. And three bound parameters had no
inferred type where the other side of the expression was a `timestamptz`.

**Wired into what already existed.** Assigning a course, completing one,
issuing a certificate, withdrawing one, scheduling a session, moving one,
cancelling one. Each fires once and only on the transition — the live suite
re-assigns a course and replays a completed lesson to prove it. Raising never
throws: a course completion that cannot send an email is still a completed
course.

**Not built:** messaging. `messages`, `conversations`, reactions, mentions,
drafts and read receipts are a chat feature, not a notification one, and
`broadcast_messages` is covered by announcements.
`user_messaging_settings.email_digest_frequency` waits for it — quiet hours
came across because they belong to the person rather than to chat.

**Verification**

- 55 unit tests over the router, quiet hours, the schedule arithmetic and the
  wording, including every type's message and the wrapping-midnight window.
- 43 integration tests against real PostgreSQL: the claim, the retry, the
  release, and `syncSchedules` staying idempotent across repeated saves.
- 88 live checks, ending with a real `notifications:dispatch` run.
- 35 constraint probes.

Gate: 647 tests / 1,762 assertions, PHPStan level 8, no style errors. All eight
live suites run together: **503 checks, none failing**.

`MAIL_PROVIDER=log` and `SMS_PROVIDER=log` are the development setting: the
whole path runs — route, queue, claim, send, mark sent — with no account
anywhere, and a message that would have been wrong is visible in the log rather
than in somebody's inbox.

### 2026-08-27 — Certificates

Sixteen routes, closing the loop on three modules that already promised a
certificate and could not produce one: `courses.issues_certificate`,
`live_trainings.issues_certificate` and the assessment core's
`certificate_template_id`.

Same story as proctoring and ILT. `certificate_templates` holds 3 rows and
`proc_certificate_templates` 1, while **`certificates` and `proc_certificates`
are both empty**. Templates were designed; issuance never was.

**One table, three sources.** The legacy had two families describing the same
object — one bound to a course, one to an assessment attempt. They merge, with
`source` naming what earned it and a check constraint making exactly one of
`course_id`, `training_id` and `assessment_id` present.

**Eligibility is the substance of issuing.** `POST /v1/certificates` does not
write what the caller sends: it goes back to the owning module and asks whether
the learner actually earned it — enrolment complete, attendance met, assessment
passed. A certificate that can be conjured by POSTing an id and a name is not
evidence of anything, and a verification endpoint that vouches for one is worse
than having none. `force: true` allows an override for paper records and
exemptions, and the history records it as an override rather than leaving it
indistinguishable from an earned one.

**The learner's name and the title are copied onto the row, deliberately.** The
one place in this schema where denormalisation is the requirement rather than a
shortcut: a certificate is a record of a moment. If the holder marries or an
author retitles the course, what was issued last year must still read as it did.
A test pins it — renaming the user does not rewrite the certificate.

**The public verification endpoints are the only unauthenticated ones**, and
that is the point: an employer checking a certificate has no account here. That
makes them the most exposed surface in the codebase, so:

- Three identifiers, not one. The **number** is printed and quoted, the
  **code** is short enough to read down a phone line — both readable and
  therefore guessable — and the **hash** is 32 random bytes and the only one a
  URL carries. A verification endpoint keyed on a sequential number lets anyone
  walk the register by adding one.
- The payload is name, title, and whether it stands. No email, no score, no
  ids — a verification endpoint must not become a directory. A live check
  asserts each of those absences.
- A malformed reference is rejected before the database is touched, and every
  miss returns the same 200 with the same shape: different statuses or
  messages would teach a scanner the format.
- Every check is logged, which is what lets an issuer notice one of their
  certificates being verified unusually often.

**Escaping.** `CertificateRenderer` escapes every substituted value. A learner's
name comes from a registration form, and a certificate is rendered and then
shown to third parties who trust it — an unescaped name is stored XSS with the
most credulous possible audience. The template's own HTML is the author's and is
left alone. A typo in a placeholder is a save-time error, not a literal
`{{leaner_name}}` printed on somebody's certificate.

**Duplication removed.** `is_active` and `expired` both duplicated `status`
(and `expired` also duplicated `valid_until`); `download_count`,
`verification_count` and their `last_*` partners were counters beside the log
recording the same events; and `proc_certificate_templates.usage_count` was
maintained by an `AFTER INSERT` trigger — the least visible form of the mistake,
since nothing in the application code shows the number exists.
`certificate_reprint_history` and `certificate_verification_log` become one
`certificate_events` table.

**Not built:** `proc_certificate_fields`, which positions each field by absolute
millimetre coordinates for a drag-and-drop designer. The HTML template covers
rendering; the designer is a product decision, not a porting one.

**Verification**

- 22 unit tests over the renderer and the identity scheme, including the
  escaping and that the hash is not derived from the number.
- 26 integration tests against real PostgreSQL: superseding, the public
  lookup without a tenant, revocation and reinstatement clearing together.
- 55 live checks, including every field the public payload must *not* contain.
- 11 constraint probes.

Gate: 549 tests / 1,550 assertions, PHPStan level 8, no style errors. All seven
live suites re-run together: **415 checks, none failing**.

**Worth knowing about the harness.** Running the suites back to back tripped the
rate limiter — 120 requests per minute, and each suite fires 60–90 — which
surfaced as clustered empty responses that looked like a bug in whichever module
ran second. It was not; the limiter was working. The suites now run against a
locally raised limit, and the limiter's real behaviour is unchanged.

### 2026-08-27 — Instructor-led training

Twenty-four routes. This is the module the original brief named as not
working, and the legacy data says exactly why.

**The diagnosis, confirmed in the data.** `live_training_sessions` row 2 is
marked `session_type = 'virtual'`, and every one of its meeting columns —
`video_platform`, `meeting_id`, `meeting_url`, `join_url`, `host_url`,
`external_meeting_id` — is NULL. No meeting was ever created for it, because
`LiveTraining::createVirtualMeeting()` returned `https://meet.example.com/`
plus random hex while a complete 896-line provider layer sat beside it with
zero call sites.

**`SessionScheduler` is the wiring that was missing.** Creating an online
session now books a real meeting through the ported providers. It is
deliberately allowed to fail *without failing the session*: a provider outage
should not stop an organiser building next month's timetable, so the session
saves with `meetingError` set, shows as needing attention, and can be retried.
Refusing the whole operation is how one expired OAuth token blocks a day of
planning. A live check proves both halves — the session is created, and the
reason for the failed booking is recorded rather than lost.

**Two live-data defects the schema now refuses**

- The single generated QR token expires *before* it was generated: created
  `2026-01-12 23:19:33`, expiring `2026-01-12 09:15:00`. It was born expired
  and nothing noticed. There is now a check constraint, and the window is
  derived from the session's own start time.
- `reminder_channels` on training 1 holds
  `"\"[\\\"email\\\", \\\"in_app\\\"]\""` — a JSON array encoded three times
  over. Row 2 holds a clean array. `LiveTraining::channels()` unwraps until it
  reaches a list rather than decoding once.

**Resolved from the legacy shape.** `session_date` + `start_time` + `end_time`
+ `timezone` become two `timestamptz` columns — a date, two times and a zone
name in four columns is how a session moves by an hour twice a year. The zone
survives as its own column because it is still needed to *display* the session
and to pass to the provider, but it no longer defines the instant. Dropped:
`meeting_url` and `meeting_id` (duplicating `join_url` and
`external_meeting_id`), `is_active` on both tables, the three cost columns
(NULL everywhere, and `training_costs` is its own table), and `skills_count` —
a stored count, the same mistake as the assessment `total_questions` that was
wrong in 16 of 19 rows.

**Deliberate design points**

- **Attendance is a pure function.** `AttendanceCalculator` decides whether a
  programme was attended, and it decides certification, so it is testable with
  no database in the way. Late counts as attended — a rule that says otherwise
  turns a traffic jam into a failed certification. An excused absence comes
  out of the *denominator* rather than into the numerator: neither rewarded
  nor punished. A cancelled session is not one anybody missed. A programme
  with nothing to attend is met rather than failed, because dividing by
  nothing and calling it 0% fails a learner for a programme that never ran.
- **Completion waits for every session to happen.** Meeting the bar halfway
  through is not finishing.
- **Virtual attendance is read back from the provider**, not from the
  learner's browser: a self-reported duration on something that decides a
  certification is a number the learner controls. Records are merged by
  participant first, because providers report one row per join and a dropped
  connection otherwise appears three times.
- **A hand-made mark must carry a reason** and is surfaced as an override.
  Indistinguishable from a scan is exactly what an auditor asks about.
- **The host URL is not in a learner's payload.** Whoever holds it can start
  the meeting, admit strangers and end it.
- **A wrong QR code and an expired one give the same message**, so nobody can
  test codes until one is accepted.

**Fixed while building:** a check constraint requiring `override_by` fought
`ON DELETE SET NULL`, making the person who made a mark undeletable. The
timestamp and reason carry the audit; the name may legitimately leave.

Assessments can now hang off a training — the column the assessment core
deliberately left out because the table did not exist yet.

**Verification**

- 14 unit tests over the attendance calculator, including the excused-absence
  rule and the rounding boundary.
- 27 integration tests against real PostgreSQL, covering the failed-booking
  path, QR windows and the completion rule.
- 69 live checks: the full organiser-to-learner path, meeting booking against
  an unconnected provider, capacity and waitlist, QR check-in, overrides, and
  every cross-tenant call.
- 11 constraint probes, including the exact legacy row that was born expired.

Gate: 501 tests / 1,337 assertions, PHPStan level 8, no style errors. All six
live suites re-run together: 360 checks, none failing.

**Still needed for ILT to run in production:** a tenant has to connect a Zoom,
Google or Teams account through `/v1/ilt/integrations/{platform}/connect`, and
the deployment needs client credentials for that platform in `settings.php`.
Until then booking fails cleanly and says so, which the live checks exercise.

### 2026-08-26 — Sections, random selection, and the proctoring extension

Twenty-two more routes. The assessment module is now complete, and proctoring
— the module the legacy system never finished — works end to end.

**A correction to how I was reading the legacy data.** I skipped
`assessment_sections` and `assessment_random_rules` in the previous slice
because their legacy tables were empty, calling them features with "no caller
to serve". That was wrong: the legacy proctored assessment was never finished,
so an empty table means an *unbuilt* feature, not an unwanted one. Everything
here follows from correcting that reading.

**`attempt_questions` is the load-bearing addition.** As soon as two learners
can be shown different questions, `assessment_questions` stops being "the
paper" and becomes "what the paper may be drawn from". Three things break
unless each attempt records its own draw: a learner reloading mid-sitting gets
a fresh hand; `max_score` taken from the assessment does not match what they
saw, so a perfect score can read as 60%; and marking has no way to know which
questions were asked. The draw now happens once, at the start, and is written
down. Manual assessments write the same rows every time, which keeps one code
path for all three modes rather than a special case only the untested branch
exercises.

**`PaperBuilder`** decides what each learner is dealt — pure, like `Grader`,
and seeded from the attempt id so the same attempt always produces the same
paper. Manual asks everything; random draws N; pool draws per rule from banks.
Sections are honoured in the first two, so "two from each topic" needs no rule
at all. A random *selection* keeps the author's order — a random *ordering* is
what `shuffleQuestions` is for. Overlapping rules never ask the same question
twice, because asking it twice doubles its weight silently.

**Publishing now refuses every way a paper can come out empty**: no questions;
a random count larger than the pool; a rule asking for more than its bank
holds; pool selection with no rules. Each of those failed silently at exam
time before, and an empty paper scores everyone zero out of zero — which the
pass rules read as a pass.

**The proctoring extension** is an advanced module on the same core, gated
twice: the tenant must be entitled (`tenants.entitlements`, a column of its
own rather than a key in generic settings) and the caller must hold the
permission. A missing entitlement is a 409, not a 403 — nothing is wrong with
the caller, and "you lack permission" sends an administrator to the wrong
person.

- **Settings are one `jsonb` column, not thirty-five booleans.** They are
  configuration, read as a unit, never filtered on; the legacy schema already
  conceded this by storing the same fields as JSON in its presets table.
  `ProctoringSettings` validates combinations that cannot work — face
  detection without a webcam, recording audio without a microphone — because
  each would fail silently at exam time.
- **The counters are gone.** `proc_proctoring_sessions` cached
  `total_violations`, `trust_score` and five per-type tallies alongside the
  rows they summarised. `TrustScore` computes them, which also means a
  reviewer marking something a false positive genuinely removes it from the
  score — the point of reviewing at all.
- **Severity is ours, not the client's.** A browser that could set its own
  would report everything as trivial. A second person in frame is critical and
  ends the sitting in the same transaction that records why; looking away is
  not. Four minor events deliberately do not add up to one critical.
- **The learner is warned about what they can fix and not about what they
  would hide.** Telling them the camera saw a second person invites them to
  fix the evidence. The trust score is never returned to them: a learner who
  can watch it fall can find the threshold by experiment.
- **`emotion_detected` is not ported.** Inferring a learner's emotional state
  from a webcam frame is biometric profiling of a person under examination. No
  violation type depends on it, and building it because a column existed would
  be the wrong default.

**A recurring bug, third occurrence.** `randomQuestionCount` was silently
dropped on create, because `SaveAssessmentAction` rebuilt the entity by
restating twenty-odd constructor arguments by hand. The same hand-maintained
field list lost `durationMinutes` on lessons and reset the course pass mark
earlier this week. Fixed the same way as the others — and this time the
pattern is gone rather than patched: `Assessment::withCreatedBy()` copies the
object, so nothing restates the constructor. **Any future action that writes
an entity should use a wither, never `new self(...)` at the call site.**

**Verification**

- 18 unit tests over `PaperBuilder`, including that the same attempt always
  draws the same questions, that different attempts do not, and that the
  global random generator is left unseeded afterwards — a builder that left
  `mt_srand` set would make everything downstream predictable, including token
  generation.
- 26 unit tests over proctoring settings, severity weighting and trust
  scoring.
- 27 integration tests against real PostgreSQL, including that the level on
  `assessments` and on `proctoring_settings` are written together, and that
  `updateSession()` writes only allow-listed columns.
- 70 live checks covering sections, all three selection modes, the entitlement
  gate, a full proctored sitting, review, and a critical event ending it.

Gate: 460 tests / 1,212 assertions, PHPStan level 8, no style errors. All five
live suites re-run together: 291 checks, none failing.

**Credential rotations: done** (confirmed 2026-08-26) — outstanding actions 2
and 3 are closed. Actions 1 and 4 are not rotations and stay open: one is a
file to delete from the live host, the other a firewall change.

### 2026-08-26 — Assessments (core)

Twenty routes: reusable question banks, assessments assembled from them, the
attempt lifecycle, and marking. Seven tables replace the parts of ~50 legacy
ones that carry data.

**The reuse win.** Legacy `questions.assessment_id` bound a question to one
assessment, so the same question was retyped for every quiz that wanted it.
Questions now live in banks; an assessment attaches them, optionally with a
`points_override`. The same question is worth 1 in a practice quiz and 10 in
the final without being copied.

**Two corrections to
[`schema-assessments.md`](docs/migration/schema-assessments.md)**, both found
by counting rows before writing the migration:

- **Every `proc_*` table is empty.** That document called the proctored side
  "the actively developed one" — a count of files (26 vs 14), not of rows. No
  proctored assessment has ever been created. The `proc_*` *schema* is still
  the better shape and the core follows it, but the data migration is only
  from the generic tables.
- **The answer store is `question_answers` (40 rows)**, which the document
  never mentioned. It planned to reconcile `user_answers` (0 rows) against
  `assessment_attempts.answers` — a longtext column that is NULL in 8 of 14
  rows and the string `'0'` in the other 6. There is nothing to reconcile.

**Grading is a pure function.** `Domain\Assessment\Grader` takes a question and
an answer and returns points — no database, no clock, no randomness. Every
rule is exercised in isolation, because grading is where being quietly wrong
costs the most:

- Selecting two options on a single-choice question earns nothing. It is not a
  partial answer; it is not an answer to the question asked.
- Multiple-choice credit is right-answers-found minus wrong-answers-chosen,
  floored at zero. Without the penalty, ticking every box scores full marks on
  every multiple-answer question ever written.
- Ordering and matching give credit per item in place. Fill-in-the-blanks
  marks each blank separately, and two options sharing a `sortOrder` are two
  acceptable answers to the same blank.
- Short answers match on case- and whitespace-insensitive equality and nothing
  cleverer. The legacy code used `similar_text()`: a near-miss marked wrong is
  a complaint, a wrong answer marked right is a certificate issued in error.
- Essays, file uploads and hotspots go to a human rather than being marked
  zero.

**Fixed while writing it — a floating-point bug in tolerance checks.** An
answer of 3.13 to "3.14 ± 0.01" produces a difference of 0.010000000000000231
and was marked wrong for being exactly right. Now compared with an epsilon
scaled to the magnitudes involved. Caught by a test written before the code.

**Duplication removed, from evidence.** `total_questions` disagrees with
reality in **16 of the 19** live assessments — rows claim 10, 20 or 25
questions when only 21 questions exist in the whole database. It, along with
`total_points`, `total_attempts`, `total_completions`, `avg_score` and
`pass_rate`, is now a subquery. `has_time_limit` is gone (`NULL` already meant
no limit, as its own comment said), as are the dead `answers` blob and
`attempt_number`, which was NULL in all 14 rows.

**Kept deliberately: `attempts.passed`.** It looks like the enrolments
`is_completed` mistake and is not. `is_completed` duplicated `status` — both
described the same instant, so recomputing always agreed. `passed` depends on
the pass mark, which an author can change afterwards; recomputing would turn
last year's pass into a fail. It is stamped once, at grading, and a live check
proves an earlier attempt survives the bar being raised to 100.

**Access control.** Added `Permission::AssessmentTake`, held by learners, so
sitting an assessment is distinct from authoring one. The paper a learner
receives is built by `toArrayForLearner()`, which omits `isCorrect`,
`explanation`, `modelAnswer` and `numericAnswer` — two serialisers rather than
one, because a single one is how the answer key ends up in the browser of the
person being tested. A live check asserts the key is absent.

**Verification**

- 33 unit tests over the grader alone, including that every enum case is
  handled and that no type can earn more than it is worth.
- 23 integration tests against real PostgreSQL: submission totals from stored
  answers, essays holding an attempt at `grading` with no score, marking the
  last answer totalling the attempt, and points-based versus percentage passes.
- 67 live checks: the full author-to-learner-to-marker path, every validation
  refusal, attempt limits, and every cross-tenant call.
- 14 constraint probes run directly against the database.

Gate: 389 tests / 1,033 assertions, PHPStan level 8, no style errors.

**Not built:** `assessment_sections` and `assessment_random_rules` — both
empty in the legacy data with no generic equivalent, so there is nothing to
port and no caller to serve. `question_selection` is constrained to `manual`
until the rules table exists, rather than accepting a value the engine cannot
honour. The proctoring extension is next; `proctoring_level` is already on
`assessments`, defaulting to `none`.

### 2026-08-26 — Enrolments and progress

Nine routes. Administrators assign people to courses and see who is on them;
learners see their own courses and report progress as they work through them.

**Bulk assignment is the headline.** `POST /v1/courses/{id}/enrollments` takes
a list of learners *or* a department, team or branch, and enrols everyone in
one statement. The legacy `CourseEnrollment::enrollUsers()` looped one query
per learner, so a 200-person branch was 200 round trips that could half-fail.
Partial success is reported rather than hidden: already-enrolled learners are
skipped, previously dropped ones are reinstated with their progress intact,
and ids that are not users in this tenant come back under `notFound` instead
of failing the batch.

This required restoring the five organisational columns on `users` —
`branch`, `department`, `team`, `level`, `job_title`. The legacy table had
them, the identity slice dropped them, and without them there is nothing for a
group assignment to resolve. They return as composite foreign keys into
`lookups`, so a user's "department" cannot point at a job title.

**Progress is derived, never asserted.** Recording a lesson report and
recalculating the enrolment happen in one transaction. The percentage is
`completed mandatory lessons / mandatory lessons`, computed in SQL; nothing
else may write it. Optional lessons do not hold completion hostage, a course
of only optional lessons still has a denominator rather than dividing by zero,
and deactivating or adding a lesson moves everyone on the course.

Reports are hardened against what players actually send: progress never goes
backwards (30% after 80% is a re-watch, not a regression), the first
completion date stands, the best score stands, time is an increment and
accumulates, and a report with no position does not rewind the video. Status
is derived from the percentage and a client-sent `status` is ignored.

**Duplication removed, from evidence.** `is_started` and `is_completed` are
dropped: 16 of the 159 live enrolments say `completed` while `is_completed` is
0, so the booleans were never maintained. `last_accessed_at` duplicated
`last_activity_at`. `time_spent_minutes` is 0 in all 116 progress rows while
`time_spent_seconds` is populated in all 116. `last_position` duplicated
`last_position_seconds`. `started_at` is NULL in all 116 rows including the
completed ones. `attempts` is NULL everywhere. **`module_progress` is not
ported at all** — every column in it is a `COUNT` over `lesson_progress`, so
storing it is the `is_completed` mistake one level up.

Check constraints now make the drift unrepresentable: a completed enrolment
must have a completion date and 100%, anything else must have no completion
date, and progress implies a start.

**Two access-control and privacy bugs found by the live check**

- **Any learner could read a course roster.** The roster, its figures and the
  per-learner history were gated on `progress.view` — which every role holds,
  because it is what lets someone see their *own* progress. A learner could
  therefore list every colleague on a course with names, emails, completion
  state and who was overdue. Now gated on holding any of `progress.manage`,
  `enrollment.manage` or `report.view` — instructors, administrators, HR and
  line managers, but not learners.
- **A dropped course stayed in the learner's own list.** Being taken off a
  course left it in "my courses" because the list applied no default status
  filter.

**One integrity gap found by writing a test**

The test asserting that a tenant cannot assign its people to another tenant's
course *passed the wrong way* — the row was created. `enrollments.course_id`
referenced `courses (id)` alone, so a row with one tenant's `tenant_id` and
another's `course_id` was legal. Unreachable through the API, which resolves
the course within the caller's tenant first, but not unrepresentable — and an
import script bypassing the action layer would not have been stopped. Fixed
with composite keys: `(id, tenant_id)` is now unique on `courses`, `users` and
`lessons`, and enrolments, progress and events all reference the pair.

**Also fixed:** `composer migrate` invoked `doctrine:migrations:migrate`, which
is not the command name; it has never worked. And `Enrollment::isOverdue()`
compared instants rather than dates, so every learner was overdue for the whole
of their last day — caught by a test written before the code.

**Verification**

- 52 unit tests over the enums, the assignment request and the progress report
  contract.
- 37 integration tests against real PostgreSQL, covering the recalculation
  invariant directly: after every report, the course percentage is asserted to
  equal its completed lessons and the status to agree with it.
- 62 live checks against the running stack — assignment and its partial
  outcomes, progress accumulating to completion, unenrolment preserving work,
  reinstatement restoring it, the audit trail, self-enrolment gates, and every
  cross-tenant call.
- Ten constraint probes run directly against the database, each trying to
  write the row the constraint forbids.

Gate: 333 tests / 837 assertions, PHPStan level 8, no style errors.

**Not included:** certificates on completion, and the reminder job for overdue
work. Both belong to their own slices; the data they need is now in place.

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

*(Still open after the enrolments slice, which hit it again: every new table
cascades from `tenants`, so `users` is now the only one that does not.)*

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
