# Backend port — status

**Short answer: the foundation is done, the domain port has not started.**

Zero of the 406 legacy HTTP endpoints have been ported. What exists is the
platform they will be ported *onto* — routing, DI, auth, RBAC, error handling,
migrations, queue, plus two domain slices built to prove the shape (ILT
providers, assessment vocabulary).

That is roughly the right order — porting endpoints before the auth and error
model exists produces 406 endpoints that each invent their own — but it should
not be mistaken for progress through the backlog.

## Real scope

| Measure | Count |
| --- | --- |
| Non-vendor PHP | **120,803 LOC** |
| HTTP endpoint files | **406** |
| Business-logic classes (`class/`) | 90 files, **39,784 LOC** |
| Largest single class | `ProctoredAssessment.php`, 3,050 LOC |
| Database tables | 194 |

## Scope reduction — the port is about half this size

Measured against the frontend rather than guessed. Full method and caveats in
[`live-surface.md`](live-surface.md).

| | Files | LOC |
| --- | --- | --- |
| Reachable from the frontend, cron or webhooks | **169** | **62,288** |
| Orphaned | **434** | **58,798** |

**72% of files and 49% of lines are unreachable from the running frontend.**

Whole directories have zero reachable files — `customer/` (41), `utility/` (25),
`admin/` (34), `api/auth/` (15), `user/report/` (16), plus top-level
`courses/`, `enrollments/`, `lessons/`, `media/`, `badges/`, `tenants/`,
`categories/` which were each superseded by a `user/*` equivalent. The frontend
calls `/user/courses/list`; it never calls `/courses/list`.

Individually confirmed:

| What | LOC | Evidence |
| --- | --- | --- |
| `class/reporting/` | 6,489 | Reachable only from `api/reports.php`, whose frontend constant `GlobalComponent.reporting` is **declared but never used**. `class/reports/` is the live branch — loaded by `user/reports.php` *and* `cron/run_scheduled_reports.php`, and has 11 files with no `reporting/` twin |
| `class/Reports.php` + `user/procreports.php` | — | A third reporting implementation; its constant is also declared and never used |
| `class/earningPathEnrollment.php` | 1,076 | Declares `class LearningPathEnrollment` — the **same class name** as `LearningPathEnrollment.php`. Nothing requires it |
| `class/reports/DrilldownReportsOLD.php` | 200 | Named `OLD` |
| `tanants/` | 42 | Typo directory, confirmed drop |
| `user/proctoring (1).php` | — | A literal "copy of" filename |

**Orphaned means candidate, not dead.** `codebase/` is orphaned by this
analysis but is live with a client awaiting deployment; dynamic includes are
invisible to it; and a non-frontend client would not show up. Triage before
deleting.

### ⚠ `earningPathEnrollment.php` is a live hazard, not just clutter

Two files in the same directory declare `class LearningPathEnrollment`. Only
one can win, and which one depends on include order. Today both call sites
(`user/learning-path-enrollment.php`, `user/learning-paths.php`) require the
correct file, so the dead one is inert — but it is one careless `require` away
from silently swapping the implementation. The two versions differ in ~229
lines, including property visibility (`private $tenant_id` vs
`public $tenant_id`) and method signatures.

**Worth deleting from the live backend now**, independently of the migration.

`class/reports/` also has files with no `reporting/` twin
(`CustomReportBuilder`, `ManagerReports`, `RetentionReports`, `SkillsReports`,
`OnboardingReports`, `CohortCostReports`, …) which confirms `reports/` is the
live one and `reporting/` the abandoned branch.

## What is actually built

| Layer | Status |
| --- | --- |
| Slim 4 + PHP-DI + PSR-15 middleware stack | ✅ |
| JSON response/error envelope, domain exception mapping | ✅ |
| JWT auth, access/refresh separation, RBAC permission model | ✅ |
| CORS, request IDs, rate limiting, body parsing | ✅ |
| Doctrine migrations, DBAL, Redis cache, Messenger queue | ✅ |
| Health/version probes, OpenAPI generation, console commands | ✅ |
| Credential encryption at rest | ✅ |
| ILT meeting providers (Zoom / Meet / Teams) + `video_integrations` | ✅ built, ⚠ untested against live accounts |
| Assessment vocabulary (7 enums + legacy mappings) | ✅ |
| **Any ported endpoint** | ❌ **0 of 406** |
| Entities for tenants, users, courses, enrolments, … | ❌ |

## Port order

Everything is blocked behind the same four foundations. Nothing further can be
ported until they land, because essentially every endpoint filters by tenant
and authorises by user.

```
1. tenants          ─┐
2. users + roles     ├─ blocks everything below
3. courses/modules   │
4. enrolments       ─┘
5. assessments core  → proctoring extension (advanced module)
6. ILT               → wire scheduling + attendance to the provider layer
7. learning paths
8. certificates      → (confirm same unify decision as assessments)
9. media / SCORM
10. messaging
11. gamification (points, badges, levels, leaderboards, streaks)
12. reporting        → last: it reads everything above
```

Reporting last is deliberate. It is the largest single area (~11,000 LOC in
`class/reports/` alone) and it only aggregates other modules — porting it early
means rewriting it as each upstream module changes shape.

## Suggested approach

**Vertical slices, not layers.** One module at a time, taken end to end:
migration → entity → repository → actions → routes → tests → the Angular screens
repointed at the new endpoints. A half-ported module behind a feature flag is
worth more than twelve modules with entities but no endpoints.

**Strangler pattern.** Both backends run side by side. `apps/api` takes the
modules that are ported; anything else keeps hitting the legacy PHP. That needs
one decision from you:

> Do the two share a database during the transition, or does each ported module
> migrate its data on cutover?

Sharing means dual-writing and two schemas disagreeing. Per-module cutover
means a freeze window per module. I would take per-module cutover, module by
module, starting with the ones with the least write traffic.

**Port against the frontend, not the backend.** [`live-surface.md`](live-surface.md)
lists the 96 endpoints actually called. An endpoint that accepts twelve actions
where the frontend only ever sends three should expose three. When two
implementations exist, the frontend picks the winner — that is how `reports/`
beat `reporting/`.

**Collapse `dictionary/*` first.** It is 42 of the 96 live endpoints — seven
near-identical six-file CRUD sets (branches, categories, departments,
jobtitles, levels, team, templates) over seven lookup tables, all inline SQL.
In the new API that is one generic resource controller plus seven
configurations. Largest single reduction available, lowest risk, and it retires
44% of the live endpoint count.

## Honest estimate

~62,000 LOC of genuinely live code across 12 modules and 96 endpoints. Still a
**multi-month port**, but roughly half what the raw 120k suggested. The
foundation is perhaps 10–15% of total effort and it is done.

Order of the first three slices, by value per unit of risk:

1. **tenants + users + auth** — unblocks everything and lets the Angular app
   authenticate against the new API. This is where the strangler starts.
2. **`dictionary/*`** — 42 endpoints retired by one generic controller.
3. **courses + enrolments** — the core learner path.

`learning-paths` (70 operations, ~5,000 LOC across seven classes) is the
largest single module and should get its own slice, not be folded into another.

## Immediate next step

`tenants` and `users`, together: the schema migration, entities, repositories,
and the auth endpoints (`login`, `refresh`, `logout`, password reset, OTP via
Termii) that `routes.php` currently leaves deliberately absent.

Two things needed before starting:

1. **The `users` table shape** — the legacy `users` table plus `user_roles`,
   `roles`, `role_permissions`, `permissions` need mapping onto the
   `Domain\Authz` model already built. Roles there are currently a fixed enum;
   if tenants define custom roles, that becomes a table.
2. **Password hashing** — what the legacy `users` table stores. If it is
   anything other than bcrypt/argon2, the port needs a rehash-on-login path
   rather than a one-off conversion.
