# Proctored-Assessment Migration — Reconciliation Inventory

The `apps/web/src/app/proctored/` module (~27 components, ~19.7k lines) is the
**last part of the frontend still calling the legacy PHP/MySQL backend**. It
talks to a handful of coarse dispatcher endpoints (`/user/assessment`,
`/user/attempt`, `/user/proctoring`, `/user/grading`, `/user/question-bank`,
`/user/certificate`), each multiplexing many operations through an `action`
field in a `FormData` body, with `token` / `user_id` / `tenant_id` passed in the
body and the base URL from `GlobalComponent.baseURL`.

The new Slim API already exposes a fine-grained, JWT-scoped REST surface for
**almost all** of this, and two Angular services already target it:
`apps/web/src/app/services/assessments.service.ts` (`AssessmentsService`) and
`apps/web/src/app/proctored/assessment-admin.service.ts`. Most **components**,
however, still call the legacy PHP directly. So this is a rewiring job, not a
green-field build — plus a short list of genuine backend gaps.

---

## A. Legacy → new-route mapping (already covered)

Route names/paths are from `apps/api/config/routes.php`; client paths from
`apps/web/src/app/core/api/api-endpoints.ts`.

### Assessments (`/user/assessment[.php]`)
| Purpose | Caller | New route |
|---|---|---|
| List / stats | assessment-list *(done)* | `assessments.list` GET `/v1/assessments`, `assessments.stats` |
| Show / preview | assessment-lobby, assessment-preview | `assessments.show` GET `/v1/assessments/{id}` |
| Create/update/delete/duplicate/status | via `AssessmentAdminService` | `assessments.create|update|delete|duplicate|status` |
| Sections list/create/update/delete/reorder | section-manager | `assessments.sections.*` `/v1/assessments/{id}/sections[...]` |

### Attempts (`/user/attempt`)
| Purpose | Caller | New route |
|---|---|---|
| Start / resume | assessment-lobby, assessment-player | `attempts.start` POST `/v1/assessments/{id}/attempts` |
| Load paper | assessment-player, assessment-results | `attempts.show` GET `/v1/attempts/{id}` |
| Save answer | assessment-player | `attempts.answer` PUT `/v1/attempts/{id}/answers/{questionId}` |
| Submit | assessment-player | `attempts.submit` POST `/v1/attempts/{id}/submit` |
| My history | assessment-history | `me.attempts` GET `/v1/me/attempts` |

### Proctoring (`/user/proctoring`)
| Purpose | Caller | New route |
|---|---|---|
| Open session / heartbeat | proctoring-monitor | `proctoring.session.open`, `proctoring.session.check` |
| Record violation / snapshot | proctoring-monitor, violation-viewer | `proctoring.violations.record`, `proctoring.snapshots.record` |
| Review queue (per-assessment / all) | session-review | `proctoring.review.queue`, `proctoring.queue` |
| Sitting detail | session-review, violation-viewer, snapshot-gallery | `proctoring.review.show` GET `/v1/attempts/{id}/proctoring` |
| Review/dismiss violation | violation-viewer | `proctoring.violations.review` |
| Config show/save | proctoring-settings | `proctoring.settings.show|save` |

### Grading (`/user/grading`)
| Purpose | Caller | New route |
|---|---|---|
| grade / grade_with_rubric | grading-interface | `attempts.grade` PUT `/v1/attempts/{id}/answers/{questionId}/grade` |
| Queue list | grading-queue | `grading.queue`, `attempts.queue`, `attempts.awaiting` |
| assign/release/skip/prioritise | grading-queue | `grading.assign|release|skip|prioritise` |

### Rubrics — **already migrated** (rubric-manager, rubric-form → `AssessmentsService`).

### Question banks (`/user/question-bank`, partial)
| Purpose | Caller | New route |
|---|---|---|
| List/CRUD banks | question-list | `banks.list|create|show|update|delete` |
| List/create/update questions | question-list, question-form | `questions.list|create|update` |

### Certificates (`/user/certificate`, mostly covered)
| Purpose | Caller | New route |
|---|---|---|
| My certificates | certificate-viewer | `me.certificates` |
| Detail / render | certificate-viewer | `certificates.show`, `certificates.render` |
| Verify | certificate-viewer | `certificates.verify|verifyByCode` |

---

## B. Genuine gaps (need new backend work)

1. **Question import** — question-import: `parse_import_file`, `import_questions`,
   `get_import_template`. No new route.
2. **Question-bank aux reads** — bank stats, tag list, CSV/blob export
   (question-list); categories, tags (question-form). Categories may map to
   `lookups` of kind `category`; the rest have no route.
3. **Assessment reporting** — assessment-reports: `get-learner-report`,
   `get-question-analytics`, `export-report`. Only a generic `/reports` engine
   and `assessments.stats` exist.
4. **Learner assessment catalogue** — assessment-catalog: no learner-facing
   assessments catalogue route (courses/paths have one; assessments do not).
5. **Richer grading** — grading-interface: quick-feedback templates, draft
   grades, per-answer comment threads, next/prev queue navigation, and a
   combined `get_grading_details` view. The grade write + queue are covered.
6. **Grading-scale settings** — assessment-settings grading scales (verify
   whether these fold into the assessment save).
7. **Proctoring presets library** — proctoring-settings loads a shared
   `ProctoringPreset[]`; only per-assessment show/save exists.
8. **Certificate download logging** — certificate-viewer `log_certificate_download`.
9. **Assessment dashboards** — assessment-dashboard, learner-dashboard post to
   `/user/assessment.php`; whether `dashboards.admin|me` carry these widgets is
   unverified.

---

## C. Recommended migration order (smallest / most independent first)

1. **Rubrics — done.** Reference pattern: component → `AssessmentsService` → `Endpoints`.
2. **Attempt player + lobby + results + history** (`/user/attempt` + lobby's
   show). Fully covered; zero backend work; `course-player.service.ts` proves
   the wiring. Watch the blob export in history.
3. **Section manager + preview + settings** (`/user/assessment`). Sections map
   1:1; preview → `assessments.show`. Defer the grading-scale piece.
4. **Proctoring monitor + review stack** (monitor, session-review,
   violation-viewer, snapshot-gallery, settings show/save). Covered; leave the
   preset library behind a follow-up.
5. **Grading queue**, then **grading-interface** (scope to grade/grade_with_rubric
   first; open tickets for quick-feedback, drafts, comments, next/prev).
6. **Question banks — CRUD only**; split out stats/tags/export and the whole
   **import** flow as new backend work (gaps 1, 2).
7. **Certificate viewer** — list/detail/verify; add a small download-logging
   route (gap 8).
8. **Needs backend first:** assessment-catalog (gap 4), assessment-reports
   (gap 3), assessment/learner dashboards (gap 9).

**Net:** steps 2–5 are almost pure frontend rewiring against routes and client
methods that already exist. Steps 6–8 are where new backend endpoints are
required.
