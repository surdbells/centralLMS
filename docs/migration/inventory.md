# Legacy inventory — first pass

Survey of `legacy/` as dropped on 2026-08-25. Numbers are from the code as it
stands, not estimates.

| Area | Size |
| --- | --- |
| Database | 194 tables, MySQL 5.7, phpMyAdmin dump (`central_lxp.sql`, 1.0 MB) |
| Backend | 603 PHP files (excluding vendor), 90 classes in `class/`, ~3,355 SQL statements |
| Frontend | Angular 19.2 + Taiga UI 4, ~360 files, 17 feature areas |

---

## 🔴 Act before committing anything

### 1. Live production database credentials in the repo

`legacy/backend/config/Database.php` hardcodes:

- host `<production-mysql-host>` — MySQL reachable on a public IP
- user `central_lxp`, database `central_lxp`, password in plaintext

The same file sends `Access-Control-Allow-Origin: *` on an API that accepts an
`Authorization` header.

`legacy/frontend/src/environments/environment.development.ts` carries a Zoom API
key.

**Status:** both paths are excluded in `.gitignore`, and `legacy/` was still
untracked when this was found — git history is clean.

**Required:** rotate the database password and the Zoom key, restrict MySQL to
known source IPs, then commit redacted copies of both files so the structure
stays available for reference.

---

## 🎯 Why ILT does not work

The cause is specific and small, and it is not what it looks like from the
outside.

A complete, competent integration layer already exists in
`legacy/backend/class/VideoIntegrations.php` (896 lines):

- `VideoProviderInterface` — authenticate, refreshToken, createMeeting,
  updateMeeting, deleteMeeting, getMeeting, getAttendanceReport
- `ZoomProvider`, `MicrosoftTeamsProvider`, `GoogleMeetProvider`
- `VideoProviderFactory` and `VideoIntegrationManager`, the latter handling
  per-tenant tokens and automatic refresh

**None of it is ever called.** `VideoIntegrationManager::createSessionMeeting()`
and `syncSessionAttendance()` have zero call sites in the entire codebase.

What actually runs when someone schedules a virtual session is
`LiveTraining::createVirtualMeeting()` at `legacy/backend/class/LiveTraining.php:1628`:

```php
// This would call the appropriate platform API
// For now, just store placeholder data
$meeting_url = "https://meet.example.com/" . bin2hex(random_bytes(8));
```

Every virtual session in production has been handed a fake `meet.example.com`
link. The only part of the integration that is wired up is the OAuth callback
(`user/integration-callback.php`), so a tenant can *connect* Zoom and then get a
dead link anyway.

**Consequences for the rewrite:**

1. This is a wiring gap, not a missing feature. The provider code is worth
   porting rather than rewriting from scratch — read it before writing ours.
2. Credentials are **per tenant**, held in `tenant_video_integrations`
   (`client_id`, `client_secret`, `access_token`, `refresh_token`,
   `token_expires_at`, `settings` JSON), obtained via the OAuth
   authorization-code flow. They are **not** global environment settings.
3. Attendance auto-marking (`getAttendanceReport` →
   `live_training_attendance`) is part of the feature and must survive the port.
4. The platform identifiers in the schema are `zoom`, `google_meet`,
   `microsoft_teams`.

See [`ilt-rewrite.md`](ilt-rewrite.md) for the resulting plan.

---

> **Superseded in part.** The duplication below was found by reading the
> backend. [`live-surface.md`](live-surface.md) later measured it against the
> frontend and found 72% of files unreachable — read that first, it is the
> authoritative scope.

## 🔁 Duplication (requirement 4)

The duplication is structural, not stylistic. Porting the code as-is would
carry all of it forward, so each pair below needs an explicit "which one wins"
decision **before** the corresponding port starts.

### Two of everything, in the database

| Concern | Competing tables |
| --- | --- |
| Assessments | `assessments`, `assessment_attempts` vs `proc_assessments`, `proc_attempts` |
| Questions | `questions`, `question_options`, `question_answers` vs `proc_questions`, `proc_question_options`, `proc_attempt_answers` |
| Certificates | `certificates`, `certificate_templates` vs `proc_certificates`, `proc_certificate_templates` |
| ILT | `live_sessions` vs `live_trainings` + `live_training_sessions` |
| Audit | `audit_log` vs `audit_logs` |
| Activity | `user_activity_log` vs `user_activity_logs` |
| Reporting | `saved_reports`, `custom_reports`, `scheduled_reports` vs `proc_report_definitions`, `proc_generated_reports`, `proc_scheduled_reports` |

The `proc_*` family (≈40 tables) is the newer proctored-assessment system. The
unprefixed originals appear to be its predecessor. **Confirm which is live
before porting either** — this single decision removes roughly a fifth of the
schema.

`live_sessions` is the clearest case: 14 columns, course-attached, superseded in
every respect by `live_training_sessions` (50+ columns, venues, QR attendance,
cost tracking, hybrid support).

### Duplicated directories

`enrollment/` + `enrollments/` · `tenant/` + `tenants/` + `tanants/` (typo) ·
`backend/codebase/` is an entire second nested backend (34 PHP files with its
own `student/`, `instructor/`, `public/`, `vendor/`).

### Duplicated classes

`CerticateTemplates.php` (typo) + `CertificateTemplate.php` +
`CertificateTemplates.php` — three classes for one concern.
`Course.php` + `Courses.php` + `CourseService.php`.
`Attempt.php` + `AssessmentAttempt.php`.

---

## 🗄 Database migration notes (MySQL 5.7 → PostgreSQL 16)

- **Primary keys**: `bigint(20)` with per-table names (`session_id`,
  `training_id`). Target: `bigint` identity, or `uuid` where an id is exposed to
  a client. Do not carry the `<entity>_id` naming into the new schema.
- **Booleans**: `tinyint(1)` throughout → real `boolean`.
- **Enums**: MySQL inline enums (`training_type`, `status`, `video_platform`)
  → PostgreSQL enum types, or a `text` column with a check constraint. Prefer
  the latter: adding a value to a PostgreSQL enum needs a migration lock.
- **JSON**: several `json` columns (`tags`, `reminder_channels`, `settings`)
  → `jsonb`, with GIN indexes where queried.
- **Timestamps**: mixed `timestamp` and `datetime` → `timestamptz` everywhere.
  Decide the storage timezone once (UTC) and convert on the way in.
- **Views**: 9 `vw_*`/`v_*` views to recreate or replace with queries.
- **Foreign keys**: verify which are actually enforced — several relationships
  appear to be by convention only.
- **Collation**: `utf8mb4_unicode_ci` is case-insensitive. PostgreSQL is
  case-sensitive by default, so email and slug columns need `citext`
  (already enabled in `infra/docker/initdb/01-extensions.sql`) or explicit
  `lower()` indexes, otherwise duplicate accounts become possible.

## 🔐 Security observations

- ~2,184 prepared statements against ~20 string-interpolated queries. Audit
  those 20 during the port; the rest are in reasonable shape.
- `Database.php` is instantiated per request and opens a raw `mysqli`
  connection — no pooling, no retry.
- CORS is wide open (`*`) at the database-config layer, which is also where
  headers are emitted. Both concerns move to middleware in the new API.

## 🖥 Frontend (kept and improved, not rewritten)

Angular 19.2, standalone-ready, Taiga UI 4 component library, with jQuery 3.7 +
DataTables and CKEditor 5 alongside it.

| Area | Files | Destination |
| --- | --- | --- |
| `admin/` | 110 | `apps/saas` |
| `learner/` | 50 | `apps/web` |
| `proctored/` | 37 | split — learner sitting vs admin authoring |
| `live-training/` | 31 | both |
| `partials/`, `shared/` | 46 | `packages/ui` |
| `reports/` | 20 | `apps/saas` |
| `public/`, `website/` | 34 | `apps/web` |
| `course-player/`, `messaging/` | 20 | `apps/web` |
| `services/` | 11 | `packages/api-client` (generated) |
| `tenant-settings/` | 5 | `apps/saas` |

Notes:

- `services/crud.service.ts` is a thin `HttpClient` wrapper with no typing —
  `post_request(data: any, endpoint: string): Observable<any>`. Every caller
  passes a raw URL string. This is what the generated client replaces.
- Errors are handled by `console.log` and a re-thrown string.
- `environment.development.ts` has no `production` twin worth keeping — the
  production file is `export const environment = {};`, so the API base URL is
  currently only defined in development.
- jQuery/DataTables is used for admin tables. Replacing it with the Taiga UI
  table is an improvement worth scheduling, not a blocker.

---

## Decisions — answered 2026-08-25

### 1. Assessments: unify, do not pick a winner

Proctored assessment is **not** a duplicate of the generic assessment used in
courses and ILT — it is an **advanced module** layered on top of it. So the two
table families merge onto one core, with proctoring as an extension gated by
module entitlement, rather than one being deleted.

The evidence supports this cleanly: `proc_questions.question_type` is a strict
superset of `questions.question_type`, `proc_assessments.proctoring_level`
already has a `'none'` value, and `proc_assessments` already carries
`course_id`, `live_training_id` and `is_standalone`.

Design: [`schema-assessments.md`](schema-assessments.md).

### 2. `live_sessions` — drop

Superseded by `live_trainings` + `live_training_sessions`. Not ported.

### 3. `backend/codebase/` — still deployed, keep

Currently deployed internally with no client on it, but **a client is awaiting
deployment**, so it is live code, not a dead experiment. Do not delete it and
do not treat its absence from the main tree as evidence it is unused. It needs
its own consolidation pass once the client's requirements are known — track it
separately rather than folding it into the main port.

### 4. `tanants/` — drop

Typo directory. Redundant with `tenants/`.

> The team notes that redundant files and folders throughout the backend are
> expected. Treat an unreferenced file as a candidate for deletion, not proof —
> `backend/codebase/` is the reminder of why.

## Still open

- `certificates` / `certificate_templates` vs `proc_certificates` /
  `proc_certificate_templates` — the same "generic vs advanced module" question
  as assessments. Likely the same answer (unify with the proctored features as
  an extension), but confirm before porting.
- Reporting: `saved_reports`, `custom_reports`, `scheduled_reports` vs the
  three `proc_report_*` tables.
- `audit_log` vs `audit_logs`, `user_activity_log` vs `user_activity_logs` —
  almost certainly straightforward merges; check which has live rows.
