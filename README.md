# Central LMS

Monorepo for the Central LMS platform: a Slim 4 / PostgreSQL API, two Angular
front-ends, an Ionic mobile app, and the legacy system being migrated away from.

```
apps/
  api/        Slim 4 · PHP 8.3 · PostgreSQL 16 · Doctrine ORM 3 · Redis
  web/        Angular 19 — learner LMS            (to be populated from legacy/frontend)
  saas/       Angular 19 — tenant admin console   (to be populated from legacy/frontend)
  mobile/     Ionic + Angular + Capacitor         (to be scaffolded)
packages/
  api-client/     TypeScript client, generated from the API's OpenAPI document
  ui/             Angular components shared by web, saas and mobile
  shared-types/   Domain types shared across the front-ends
  tsconfig/       Shared TypeScript configuration
  eslint-config/  Shared lint configuration
infra/docker/     PostgreSQL 16 + Redis 7 + API + queue worker
docs/migration/   Findings and decisions from the legacy migration
legacy/           The old system, reference-only. Never built, never deployed.
```

## Getting started

```bash
pnpm install
```

```bash
docker compose -f infra/docker/docker-compose.yml up -d db redis
```

Then the API:

```bash
cd apps/api && composer install && cp .env.example .env
```

`.env` needs two generated secrets before the API will start — this is
deliberate, a placeholder secret means every token in the system is forgeable:

```bash
openssl rand -base64 48   # JWT_SECRET
```

```bash
openssl rand -base64 32   # CREDENTIALS_ENCRYPTION_KEY
```

Apply the schema and run it:

```bash
cd apps/api && php bin/console doctrine:migrations:migrate --no-interaction
```

```bash
cd apps/api && php -S 127.0.0.1:8081 -t public public/index.php
```

`GET /health` reports each dependency separately and returns 503 if any is
down. `GET /version` reports the running build and OPcache state — the fastest
way to tell whether a deploy actually took effect.

### Ports

This stack deliberately avoids the defaults, which are usually already taken:

| Service | Port |
| --- | --- |
| API | 8081 |
| PostgreSQL | 5442 |
| Redis | 6389 |

## Quality gate

```bash
cd apps/api && composer check
```

Runs PHP_CodeSniffer (PSR-12), PHPStan level 8, and PHPUnit. All three are
currently clean; keep them that way.

## Useful commands

```bash
cd apps/api && php bin/console ilt:check-providers --tenant=<uuid>
```

```bash
cd apps/api && php bin/console messenger:consume --time-limit=290
```

The worker is intentionally not a daemon — cron restarts it, because
long-lived PHP processes leak. See `ConsumeMessagesCommand`.

## The migration

**[`PROGRESS.md`](PROGRESS.md) is the live status** — current focus, module-by-
module port state, decisions log and open questions. Update it as work lands.

Five workstreams, in dependency order:

1. **Monorepo** — done: workspaces, Turborepo, shared tooling, Docker stack, CI layout.
2. **Backend rewrite** — Slim/PostgreSQL foundation is in place (routing, DI,
   auth, RBAC, errors, rate limiting, migrations, queue). The domain itself is
   being ported table by table.
3. **ILT** — root cause found and the replacement layer built. See
   [`docs/migration/ilt-rewrite.md`](docs/migration/ilt-rewrite.md).
4. **Deduplication** — measured against the frontend: 72% of legacy backend
   files are unreachable from the running app. See
   [`docs/migration/live-surface.md`](docs/migration/live-surface.md) for what
   must actually be reproduced, and
   [`docs/migration/backend-port-status.md`](docs/migration/backend-port-status.md)
   for where the port stands.

**The front-end is kept and improved, not rewritten.** The existing Angular 19 +
Taiga UI application is the starting point for `apps/web` and `apps/saas`.

## Conventions

- Nothing in `apps/` or `packages/` may import from `legacy/`. Port behaviour,
  never link the code.
- Every API response has one of two shapes: `{"data": …}` or
  `{"error": {"code", "message", "details"}}`.
- Authorisation is declared on routes in `apps/api/config/routes.php`, never
  inside actions, so that file is the access-control audit.
- Permissions live in one enum (`Domain\Authz\Permission`). If it is not a case
  there, it does not exist.
- Time is injected (`Support\Clock`). No `time()` or `new DateTimeImmutable()`
  in `src/`.
- Roles are data, permissions are code. Never branch on a role name — check a
  `Permission`. The one exception is `SystemRole::SuperAdmin`, whose
  cross-tenant reach is structural.
- Update [`PROGRESS.md`](PROGRESS.md) when a module changes state, a decision is
  made, or an open question is answered.
