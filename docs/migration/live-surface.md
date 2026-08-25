# The live surface — what the new backend must actually reproduce

The Angular frontend is the authority on what is in use. Everything below is
derived from it, not from reading the backend and guessing.

**Method.** Every API path the frontend references was extracted from
`global-component.ts` and the feature services, resolved to a backend file
through the `.htaccess` rewrite (`/path` → `path.php`), then `require_once`
chains were followed transitively. Cron entry points (`cron/*.php`) and
webhooks were added as separate roots.

## Headline

| | Files | LOC |
| --- | --- | --- |
| Reachable from the frontend, cron or webhooks | **169** | **62,288** |
| Orphaned | **434** | **58,798** |
| Total (excluding vendor) | 603 | 120,803 |

**72% of the backend files, and 49% of its lines, are unreachable from the
running frontend.** The port is roughly half the size it looks.

Raw data, regenerable:

- [`data/frontend-endpoints.txt`](data/frontend-endpoints.txt) — 96 API paths the frontend calls
- [`data/reachable.txt`](data/reachable.txt) — 169 files backing them
- [`data/orphans.txt`](data/orphans.txt) — 434 files nothing reaches

## ⚠ Treat orphaned as *candidate*, not *dead*

Three known blind spots, and the reason nothing here should be deleted on this
evidence alone:

1. **`codebase/`** (34 orphaned files under `codebase/public/`) is the separately
   deployed app with a client awaiting go-live. It has its own entry points that
   this analysis does not model. **Not dead.**
2. **Dynamic includes.** The closure follows literal
   `require_once __DIR__ . '...'`. Any variable include is invisible to it.
3. **Non-frontend clients.** Mobile, integrations, or anything calling the API
   directly would not appear.

The list is a *ranked starting point for triage*, not a delete list.

## Correction to an earlier finding

`class/reporting/` was previously recorded as dead duplicate code. That was
right for the wrong reason, and the precise position matters:

- `class/reports/` ← loaded by `user/reports.php` **and** by
  `cron/run_scheduled_reports.php`. **Live.**
- `class/reporting/` ← loaded by `api/reports.php`, which *is* a real endpoint.
  But `GlobalComponent.reporting` (`/api/reports`) is **declared and never used
  by any component**. So the code is reachable in principle and dead in
  practice.

Same for `GlobalComponent.procreports` (`/user/procreports` → `class/Reports.php`)
— declared, never consumed.

There are therefore **three parallel reporting implementations**, of which one
is in use:

| Endpoint | Backing code | Frontend usage |
| --- | --- | --- |
| `/user/reports` | `class/reports/` (28 files) | ✅ `reports/services/report.service.ts`, admin user-form |
| `/user/learner-report` | `class/LearnerReport.php` | ✅ learner report service |
| `/api/reports` | `class/reporting/` (17 files) | ❌ constant declared, never used |
| `/user/procreports` | `class/Reports.php` | ❌ constant declared, never used |

**Port `class/reports/` and `class/LearnerReport.php`. Ignore the other two.**
That `reports/` has 11 files with no `reporting/` counterpart
(`CustomReportBuilder`, `ManagerReports`, `RetentionReports`, `SkillsReports`,
`OnboardingReports`, `CohortCostReports`, …) confirms which branch kept moving.

## Wholly orphaned directories

Whole areas with **zero** reachable files — the strongest signal in the dataset,
since a live area shows a mix:

| Directory | Files | Note |
| --- | --- | --- |
| `customer/` + `customer/settings/` | 41 | frontend has no `/customer/*` call at all |
| `utility/` + `utility/shared/` | 25 | |
| `admin/` (`assessment/`, `common/`, `customers/`) | 34 | superseded by `/user/admin/*` |
| `class/reporting/` | 17 | see above |
| `api/auth/` | 15 | superseded by `/authentication/*` |
| `user/report/` | 16 | superseded by `/user/reports` |
| `enrollments/`, `lessons/`, `media/`, `user_management/`, `auth/`, `controllers/` | 6 each | top-level twins of live `user/*` equivalents |
| `badges/`, `courses/`, `tenants/`, `categories/`, `certificate_templates/` | ~5 each | ditto — the frontend calls `/user/courses/*`, never `/courses/*` |

The pattern is consistent: a top-level directory was superseded by a `user/`
equivalent and left behind. `/user/courses/list` is live; `/courses/list` is not.

## The live surface, by area

96 endpoints. Newer areas POST to one endpoint with an `action` in the body, so
the endpoint count understates the operation count — those are given as *ops*.

| Area | Endpoints | Backing classes | Ops |
| --- | --- | --- | --- |
| `dictionary/*` (branches, categories, departments, jobtitles, levels, team, templates) | 42 | inline SQL | — |
| `user/courses/*` | 9 | `CourseService`, `RoleService`, `CourseInsights`, `CourseDuplicate` | — |
| `authentication/*` (login, register, validate, validate-email, reset, confirm) | 6 | inline | — |
| `user/media/*` | 5 | inline / `Media` | 3 |
| `user/learning-paths` | 1 | `LearningPath`, `LearningPathSection`, `LearningPathItem`, `LearningPathEnrollment`, `LearningPathProgress`, `LearningPathReview`, `LearningPathAssignment` | 70 |
| `user/assessment` | 1 | `Assessment`, `User` | 33 |
| `user/grading` | 1 | `Grading` | 28 |
| `user/skills` | 1 | `Skills` | 25 |
| `user/question-bank` | 1 | `QuestionBank` | 24 |
| `user/certificate` | 1 | `Certificate` | 21 |
| `user/proctoring` | 1 | `Proctoring` | 17 |
| `user/attempt` | 1 | `Attempt`, `User` | 15 |
| `user/learning-path-enrollment` | 1 | `LearningPathEnrollment`, `User` | 8 |
| `user/live-training` | 1 | `LiveTraining` | — |
| `user/messaging` | 1 | `Messaging` | — |
| `user/my-learning` | 1 | `MyLearning`, `User` | — |
| `user/course-enrollment` | 1 | `CourseEnrollment`, `User` | — |
| `user/reports`, `user/learner-report` | 2 | `class/reports/*`, `LearnerReport` | — |
| `user/*` (create, update, status, single, user_list, instructor, statistics, change_password, notes) | 9 | inline | — |
| `user/admin/dashboard`, `user/learner/dashboard` | 2 | `AdminDashboard`, `LearnerDashboard` | — |
| `user/tenant-settings` | 1 | `TenantSettings` | — |
| `user/integration-callback` | 1 | `VideoIntegrations` | — |
| `user/course-content`, `user/course-player`, `user/uploads` | 3 | inline | — |

Two structural notes for the rewrite:

- **`dictionary/*` is 42 of the 96 endpoints and is pure CRUD** over seven
  lookup tables, with inline SQL and no class layer. Seven near-identical
  six-file sets (create/update/list/single/status/active). In the new API this
  is one generic resource controller plus seven configurations — the single
  largest reduction available, and a textbook case of the duplication the
  rewrite exists to remove.
- **`learning-paths` is 70 operations behind one endpoint**, backed by seven
  classes totalling ~5,000 LOC. It is the largest single module and deserves
  its own slice.

## How to use this

1. Before porting anything, check whether the file is in
   [`data/reachable.txt`](data/reachable.txt). If it is not, find out why before
   spending time on it.
2. Port against the **frontend's** call, not the backend's capability. An
   endpoint that accepts twelve actions where the frontend only ever sends three
   should expose three.
3. When two implementations exist, the frontend picks the winner — that is how
   `reports/` beat `reporting/`.

## Regenerating

Re-run after any frontend change; the numbers should only ever go down.
The extraction reads `global-component.ts` plus `${baseUrl}/…` literals across
`frontend/src/app`, resolves each through the `.htaccess` rewrite, then walks
`require_once __DIR__ . '…'` transitively from those roots plus `cron/` and
`webhooks/`.
