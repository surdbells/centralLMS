# Legacy database structure

Drop the **schema** of the old database here.

## What belongs here

- `schema.sql` — full `CREATE TABLE` / index / constraint dump (no rows)
- `procedures.sql`, `triggers.sql`, `views.sql`
- `lookup-data.sql` — small reference tables (countries, roles, statuses)
- `erd.png` / `erd.drawio` — diagrams
- `notes.md` — anything you know that the schema does not say: which tables are
  dead, which columns lie about their name, which "foreign keys" are not
  enforced

## What must NOT be committed

- Production dumps with learner, staff or payment data (`*.dump`, `*.tar` and
  `data/` are git-ignored on purpose)
- Anything containing passwords, tokens or API keys

## Producing a structure-only dump

MySQL/MariaDB source:

```bash
mysqldump --no-data --routines --triggers --skip-add-drop-table DBNAME > schema.sql
```

PostgreSQL source:

```bash
pg_dump --schema-only --no-owner --no-privileges DBNAME > schema.sql
```

## Why this matters

The target schema is PostgreSQL 16 with `jsonb` columns and real foreign keys,
mapped through Doctrine ORM 3 attributes in `apps/api/src/Domain`. The legacy
schema is the *input* to that design, never the output — do not recreate its
mistakes. Record each mapping decision in
`docs/migration/schema-mapping.md` as you go.
