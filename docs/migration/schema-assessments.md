# Unified assessment schema

**Decision:** proctored assessment is an *advanced module* on top of the same
assessment core that courses and ILT use — not a separate product with its own
tables. The two legacy families merge onto one core; proctoring becomes an
extension gated by entitlement.

This replaces ~50 legacy tables (`assessments` + `questions` + `proc_*`) with
one core of 9 tables plus a 5-table proctoring extension.

## Why this merge is safe

The legacy schema already argues for it:

| Evidence | Where |
| --- | --- |
| `proc_questions.question_type` is a **strict superset** of `questions.question_type` | every generic type maps 1:1, see below |
| `proctoring_level` already has a `'none'` value | `proc_assessments.proctoring_level enum('none','basic','standard','advanced')` |
| The proctored table already attaches to courses and ILT | `proc_assessments.course_id`, `.live_training_id`, `.is_standalone` |
| The proctored side is the actively developed one | 26 backend files reference `proc_*` vs 14 for the generic tables; every frontend service (`assessment`, `attempt`, `question-bank`, `grading`, `proctoring`) targets it |

So the generic assessment is expressible as a proctored assessment with
`proctoring_level = 'none'`, and nothing is lost in the merge. The reverse is
not true — the generic tables cannot represent matching, ordering, hotspot,
numeric-tolerance or file-upload questions.

## Target model

### Core — every assessment, everywhere

| Table | From | Notes |
| --- | --- | --- |
| `assessments` | `proc_assessments` ∪ `assessments` | one row per assessment, whatever its context |
| `assessment_sections` | `proc_assessment_sections` | optional; a single-section assessment needs no row |
| `assessment_questions` | `proc_assessment_questions` | join with `points_override`, `required`, `sort_order` |
| `assessment_random_rules` | `proc_assessment_random_rules` | pool/random selection |
| `question_banks` | `proc_question_banks` | **the key win** — questions become reusable |
| `questions` | `proc_questions` ∪ `questions` | bank-scoped, versioned, with statistics |
| `question_options` | `proc_question_options` | covers all 11 types via `answer_data`-style columns |
| `attempts` | `proc_attempts` ∪ `assessment_attempts` | proctoring columns removed — see below |
| `attempt_answers` | `proc_attempt_answers` ∪ `user_answers` | `answer_data jsonb` handles every type |

### Proctoring extension — advanced module only

| Table | From |
| --- | --- |
| `proctoring_settings` | `proc_proctoring_settings` (per assessment) |
| `proctoring_sessions` | `proc_proctoring_sessions` (per attempt) |
| `proctoring_violations` | `proc_proctoring_violations` |
| `proctoring_snapshots` | `proc_proctoring_snapshots` |
| `proctoring_presets` | `proc_proctoring_presets` |

**The boundary rule:** a row in a `proctoring_*` table exists only when
`assessments.proctoring_level <> 'none'`. Core code never joins to them, never
selects from them, and never imports their types. That is what keeps the module
optional — and what stops it from quietly becoming mandatory the way most
"optional" subsystems do.

### Fields that move out of `attempts`

`proc_attempts` carries four proctoring columns that `proc_proctoring_sessions`
already duplicates:

| On `proc_attempts` | Already on `proc_proctoring_sessions` |
| --- | --- |
| `proctoring_level` | (derivable from the assessment) |
| `proctoring_score` | `violation_score`, `trust_score` |
| `flagged_for_review` | `flagged_for_review` |
| `violation_count` | `total_violations` |

These live on `proctoring_sessions` only. Two copies of a violation count is
how they end up disagreeing, and the answer to "which is right?" is then
nobody's to give.

## Question type mapping

Every legacy generic type maps onto a proctored type with no loss:

| `questions.question_type` | → `questions.type` (unified) |
| --- | --- |
| `single_choice` | `multiple_choice_single` |
| `multiple_choice` | `multiple_choice_multiple` |
| `true_false` | `true_false` |
| `short_answer` | `short_answer` |
| `essay` | `long_answer` |
| `fill_blank` | `fill_in_blanks` |

Types with no generic equivalent, carried over unchanged: `matching`,
`ordering`, `hotspot`, `numeric`, `file_upload`.

This mapping is encoded once, in
`apps/api/src/Domain/Assessment/QuestionType.php::fromLegacy()`, and covered by
a test that fails if any legacy value stops mapping.

## Migration path for existing rows

The generic tables have real data, so the merge is a data migration, not just a
schema one:

1. Create the unified core.
2. For each tenant, create one question bank per legacy `assessments` row —
   named after the assessment — and move its `questions` into it. Legacy
   generic questions were assessment-bound, so this preserves meaning exactly
   while making them reusable from then on.
3. Insert `assessments` rows with `proctoring_level = 'none'`,
   `question_selection = 'manual'`, `is_standalone = false`.
4. Populate `assessment_questions` from the old `questions.sequence_order`.
5. Migrate `assessment_attempts` → `attempts`.
6. Migrate answers → `attempt_answers`. **Note both legacy sources**:
   `assessment_attempts.answers` is a `longtext` JSON blob *and* `user_answers`
   is a table. Establish which one production actually writes before migrating;
   if both, reconcile per attempt and log every disagreement rather than
   silently preferring one.
7. Copy `proc_*` rows across largely 1:1, moving the four proctoring columns to
   `proctoring_sessions`.

Step 6 is the one that will bite. Budget for it.

## Entitlement

Proctoring is a paid module, so it needs a gate at three levels:

- **Tenant** — a module entitlement on the tenant record. Without it,
  `proctoring_level` cannot be set above `none`.
- **Permission** — `Permission::AssessmentManage` covers authoring; proctoring
  configuration and violation review need their own permissions so a course
  author cannot see webcam recordings.
- **API** — `GET /v1/assessments` returns the proctoring block only when the
  tenant is entitled, so the UI cannot offer a control that would fail on save.

## Blocked on

The unified core references `tenants`, `users`, `courses` and `live_trainings`,
none of which are migrated yet. Build order:

```
tenants → users (+ roles) → courses → assessments core → proctoring extension
```

Writing the assessment migrations before `tenants` and `users` exist would mean
tables full of dangling references and foreign keys added later "when we get to
it" — exactly how the legacy schema ended up with relationships that are
enforced only by convention.

**Landed ahead of the tables:** the shared vocabulary — `QuestionType`,
`ProctoringLevel`, `AssessmentStatus`, `AttemptStatus`,
`QuestionSelectionMode`, `Difficulty`, `PassingType` — in
`apps/api/src/Domain/Assessment/`, each with a `fromLegacy()` mapping and
tests. Defining these once, before any table exists, is what stops the two
vocabularies from being re-invented during the port.
