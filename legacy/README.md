# `legacy/` — reference-only drop zone

Everything in this folder is **old code kept for reading, not for running**.
It is the source material for the Slim/PostgreSQL rewrite and the Angular
clean-up. Nothing here is installed, built, linted, tested or deployed.

## Guarantees this folder makes

| Property | Status |
| --- | --- |
| Committed to git | ✅ yes — the team needs to consult it |
| A pnpm workspace | ❌ no — excluded in `pnpm-workspace.yaml` |
| Built by Turborepo | ❌ no — no `package.json` tasks are registered |
| Linted / formatted | ❌ no — excluded in `.prettierignore` and the ESLint config |
| Analysed by PHPStan | ❌ no — excluded in `apps/api/phpstan.neon.dist` |
| Run in CI | ❌ no — `.github/workflows/ci.yml` filters it out |
| Deployed anywhere | ❌ never |

## Where to drop what

```
legacy/
├── db/         Legacy database structure: schema dumps, CREATE TABLE files,
│               ER diagrams, stored procedures, seed/lookup data.
│               ⚠ Structure only — no production data, no PII.
├── backend/    The old PHP backend, as-is. Expected to contain dead files,
│               vendored copies and unrelated code — drop it all, sort later.
├── frontend/   The legacy Angular application files.
├── docs/       Whatever documentation exists: specs, Postman collections,
│               screenshots, API notes, tickets worth keeping.
└── _inbox/     Unsorted dumps you have not triaged yet.
                Git-ignored: move things out of here before committing.
```

## Rules

1. **Never import from `legacy/` in `apps/` or `packages/`.** Not a single
   `require`, `import` or `use`. Port the behaviour, do not link the code.
2. **Never edit legacy files to "fix" them.** If it is worth fixing, it is
   worth porting. Edits here create a second codebase to maintain.
3. **Strip secrets before committing.** Old configs are full of live
   credentials. Check `.env`, `config/*.php`, `database.php` and any
   connection strings. Rotate anything that was ever committed.
4. **Structure-only for the database.** Dumps with real learner records do not
   belong in git — `legacy/db/*.dump` and `legacy/db/data/` are ignored.
5. **Delete a subfolder once it is fully ported.** This folder should shrink
   over time and eventually be removed entirely. That is the finish line.

## Triage workflow

The first-pass survey is already written up in
[`docs/migration/inventory.md`](../docs/migration/inventory.md) — read it before
porting anything, and record new findings there as you go.

Read [`docs/migration/README.md`](../docs/migration/README.md) for the full
port procedure, and
[`docs/migration/duplication-policy.md`](../docs/migration/duplication-policy.md)
for the rules that stop the old duplication from being copied into the rewrite.
