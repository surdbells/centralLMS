# Central LMS — Deployment Guide & Runbook

The complete, surface-by-surface deployment reference: every option for the API,
the three web apps, and the mobile app, plus the day-two runbook (health checks,
routine deploys, rollback, troubleshooting).

For the **detailed, step-by-step Ubuntu + aaPanel production install** of the API
and web, see [`deployment-runbook-aapanel.md`](./deployment-runbook-aapanel.md).
This guide is the umbrella: it covers that path **and** the alternatives, and it
is the only document that covers **mobile**.

---

## 0. Deployment matrix

| Surface | Package | Build command | Output | Recommended host | Alternatives |
|---|---|---|---|---|---|
| **API** | `apps/api` (Slim, PHP 8.3) | `composer install --no-dev -o` | PHP app | aaPanel + PHP-FPM + Nginx | Docker Compose, any PHP-FPM host |
| **Web app** (learner + admin) | `apps/web` | `pnpm --filter @central/web build` | `apps/web/dist/central/browser` | Nginx static (aaPanel) | Netlify / Vercel / Cloudflare Pages / S3+CloudFront / Docker+Nginx |
| **Operator console** | `apps/saas` | `pnpm --filter @central/saas build` | `apps/saas/dist/**/browser` | Static host (own subdomain) | same as web |
| **Public sign-up site** | `apps/public` | `pnpm --filter @central/public build` | `apps/public/dist/**/browser` | Static host (apex/marketing domain) | same as web |
| **Mobile** (learner) | `apps/mobile` (Ionic + Capacitor) | `pnpm --filter @central/mobile mobile` | `www/` → native | App Store + Play Store | PWA (host `www/` like a web app) |

The API is the shared dependency of **every** front end. Deploy and configure it
first; each front end only needs the API's HTTPS URL and a CORS allowance.

**Monorepo**: pnpm 11 workspaces + Turborepo. `pnpm build` builds everything;
`pnpm --filter @central/<app> build` builds one. `legacy/**` is reference-only and
must never be built or deployed.

---

## 1. Architecture & surfaces

```
                         ┌─────────────────────────────┐
   Learner mobile app ──▶│                             │
   (iOS / Android / PWA) │   API  (apps/api)           │──▶ PostgreSQL 16
                         │   Slim 4, PHP 8.3-FPM        │──▶ Redis 7 (cache + queue)
   Web app  ────────────▶│   /v1/*  +  /version        │
   (apps/web)            │                             │──▶ Queue worker
   Operator (apps/saas) ▶│                             │    (messenger:consume)
   Public   (apps/public)└─────────────────────────────┘
```

- **API** — Slim 4 (`Central\Api\`). PHP 8.3 with `pdo_pgsql, redis, intl, gd, bcmath, zip, opcache`. Postgres 16, Redis 7. A **queue worker** (`messenger:consume`) is a required second process. Health: `GET {api_root}/version`; the client API lives under `/v1`.
- **Web app** (`apps/web`) — Angular 19 SPA, learner **and** tenant-admin console. Dev port 4200.
- **Operator console** (`apps/saas`) — Angular SPA for platform operators. Dev port 4500.
- **Public site** (`apps/public`) — Angular sign-up/marketing site. Dev port 4400.
- **Mobile** (`apps/mobile`) — Ionic 8 + Angular 19 in a Capacitor 8 shell (`appId com.centrallms.app`, `appName "Central LMS"`). Ships to the App Store and Play Store, or as a PWA. Multi-tenant by institution code (no subdomain on a device).

---

## 2. Prerequisites

**Build machine / CI**
- Node ≥ 20, `pnpm@11.5.0` (`corepack enable`).
- PHP 8.3 + Composer (for the API).
- For mobile: the toolchain of whatever you target — **Xcode 15+** (iOS, macOS only), **Android Studio / JDK 17 + Gradle** (Android). `@capacitor/cli` is already a dependency.

**Runtime**
- API host: PHP 8.3-FPM with the extensions above, Nginx (or Apache), PostgreSQL 16, Redis 7, a process supervisor (systemd/supervisor) for the worker.
- Web hosts: anything that serves static files with SPA fallback + HTTPS.

**Accounts / secrets** (only what you use): domain + TLS, an Agora project (App ID + Certificate) for in-app video, ZeptoMail (email), Termii (SMS/OTP), an S3-compatible bucket (uploads), Paystack (payments), Firebase (push), Sentry (errors), and Apple Developer + Google Play Console for the stores.

---

## 3. API deployment (do this first)

### 3.1 Configure `apps/api/.env`
Copy `apps/api/.env.example` → `.env` and set at minimum:

| Var | Notes |
|---|---|
| `APP_ENV` | `prod` in production (`dev` locally). |
| `APP_URL` | Public API origin, e.g. `https://api.centrallms.com`. |
| `CORS_ALLOWED_ORIGINS` | **Comma-separated list of every front-end origin** — the web/saas/public domains **and** the Capacitor origins for the mobile app (see §5.3). A missing origin = the browser/WebView cannot call the API. |
| `DB_*` | Postgres host/port/name/user/password. Inside Docker, compose overrides host to `db` and port to `5432`. |
| `REDIS_DSN`, `MESSENGER_TRANSPORT_DSN` | Redis for cache and the queue. |
| `JWT_SECRET` | `openssl rand -base64 48`. Rotating it signs everyone out. |
| `CREDENTIALS_ENCRYPTION_KEY` | `openssl rand -base64 32`. Encrypts tenant OAuth creds at rest — **losing it makes stored video integrations unreadable.** |
| `AGORA_ENABLED`, `AGORA_APP_ID`, `AGORA_APP_CERTIFICATE` | In-app live video. Without them the live room shows "not set up yet". `AGORA_TOKEN_TTL` optional (default 14400s). |
| `MAIL_PROVIDER`/`ZEPTOMAIL_TOKEN`, `SMS_PROVIDER`/`TERMII_*`, `S3_*`, `PAYSTACK_*`, `FCM_*`, `SENTRY_DSN` | Enable the integrations you use. `log` providers just write to the log. |

A full variable list is in §9.

### 3.2 Run migrations
```bash
cd apps/api
php bin/console migrations:migrate --no-interaction
```
The DB extensions bootstrap is `infra/docker/initdb/01-extensions.sql` (pgcrypto etc.); on a hand-built Postgres ensure `gen_random_uuid()` is available (`CREATE EXTENSION pgcrypto`).

### 3.3 The queue worker (required)
Emails, notifications, document conversion and other async jobs run here. Run it as a supervised, self-restarting process:
```bash
php bin/console messenger:consume --time-limit=290   # wrap in a while-true loop / systemd
```
It exits every ~5 min by design so a long-lived leak can't accumulate — the supervisor restarts it.

### 3.4 Option A — aaPanel + PHP-FPM (production)
The reference path. Full steps (PHP-FPM pool, Nginx site, `/v1` routing, worker unit, SSL, cron) are in **[deployment-runbook-aapanel.md §3–§10](./deployment-runbook-aapanel.md)**.

### 3.5 Option B — Docker Compose (self-host / staging)
```bash
pnpm up      # docker compose -f infra/docker/docker-compose.yml up -d  (db, redis, api, worker)
pnpm logs    # follow
pnpm down    # stop (add -v to wipe data volumes)
```
Default host ports: API `8081`, Postgres `5442`, Redis `6389` (override with `API_PORT`/`DB_PORT`/`REDIS_PORT`). The image is `infra/docker/api.Dockerfile`; the `api` and `worker` services share it. Put a TLS-terminating reverse proxy in front for anything public.

### 3.6 Option C — any PHP-FPM + Nginx host
The extension contract is `infra/docker/api.Dockerfile` — match it exactly. Point the web root at `apps/api/public`, route everything to `public/index.php`, run `composer install --no-dev --optimize-autoloader`, run migrations, and supervise the worker. Never let the web server serve `apps/api/var`, `config`, or upload dirs directly — the app streams files.

### 3.7 First run — provision the first tenant
The admin UI needs a tenant admin, which is created here:
```bash
php bin/console tenant:provision \
  --name="Acme Corp" --code=acme --email=admin@acme.test
# omit --password to have one generated and printed once.
```
`--code` is the **institution code** learners type in the mobile app. Add `--super-admin` for a cross-tenant operator.

---

## 4. Web apps (`web`, `saas`, `public` — all Angular SPAs)

Same shape for all three; only ports, output dirs and target domains differ.

### 4.1 Build
```bash
pnpm install --frozen-lockfile
pnpm --filter @central/web build          # → apps/web/dist/central/browser
```
The **production** API URL and public-site URL live in `src/environments/environment.ts`
(the dev build swaps in `environment.development.ts`). Set the real values there before
building, e.g. `apps/web/src/environments/environment.ts`:
```ts
api: { baseUrl: 'https://api.centrallms.com/v1' },
publicSiteUrl: 'https://centrallms.com',
```

### 4.2 Three golden rules (any host)
1. **SPA fallback** — every unknown path must serve `index.html` (client-side routing). A 404 on deep links means this is missing.
2. **API base URL** — baked in at build time (§4.1). To point an existing build at a different API you must rebuild.
3. **CORS** — the API's `CORS_ALLOWED_ORIGINS` must include this app's exact origin (scheme + host + port). A blocked call is almost always a missing origin here.

### 4.3 Option A — Nginx / Apache static (aaPanel)
Serve `dist/<app>/browser` with a `try_files $uri $uri/ /index.html;` fallback. Detailed Nginx site in **[runbook §7](./deployment-runbook-aapanel.md)**.

### 4.4 Option B — Static host / CDN
Netlify, Vercel, Cloudflare Pages, or S3 + CloudFront. Two settings each:
- **Build**: `pnpm install && pnpm --filter @central/web build`; **publish dir**: `apps/web/dist/central/browser`.
- **SPA rewrite**: redirect `/*` → `/index.html` (200). (Netlify `_redirects`: `/* /index.html 200`; Vercel `rewrites`; Cloudflare Pages: automatic; S3: set the error document to `index.html` or use a CloudFront function.)
Then add the site's origin to the API CORS list.

### 4.5 Option C — Docker + Nginx image
Multi-stage: build with Node, copy `dist/<app>/browser` into an `nginx:alpine` image whose config has the SPA fallback. Good for Kubernetes / self-managed clusters.

### 4.6 Per-app specifics
| App | Dev port | Output dir | Typical domain |
|---|---|---|---|
| `apps/web` | 4200 | `dist/central/browser` | `app.centrallms.com` |
| `apps/saas` | 4500 | `dist/**/browser` | `operator.centrallms.com` |
| `apps/public` | 4400 | `dist/**/browser` | `centrallms.com` |

Each domain you deploy must be in the API `CORS_ALLOWED_ORIGINS`.

---

## 5. Mobile (`apps/mobile` — Ionic + Angular + Capacitor)

The learner app. It ships the Angular build inside a native WebView and talks to
the **production API over HTTPS**; the tenant is resolved from the institution
code at sign-in (no subdomain on a device).

### 5.1 Build pipeline
```bash
pnpm --filter @central/mobile mobile   # = ng build → www/  then  cap sync
# or step by step:
pnpm --filter @central/mobile build    # ng build → apps/mobile/www
pnpm --filter @central/mobile sync     # cap sync → copies www + plugins into native projects
```
The production API URL is `apps/mobile/src/environments/environment.prod.ts`
(`apiBaseUrl: 'https://api.centrallms.com/v1'`); the dev build uses `127.0.0.1:8081`.

Icons/splash are generated from source art: `pnpm --filter @central/mobile brand`
(runs `art` + `capacitor-assets` + manifest finalize).

### 5.2 Native projects are generated, not committed
`apps/mobile/android` and `apps/mobile/ios` are git-ignored. On a fresh checkout,
using the package's own scripts:
```bash
pnpm --filter @central/mobile build
pnpm --filter @central/mobile add:ios       # cap add ios
pnpm --filter @central/mobile add:android   # cap add android
pnpm --filter @central/mobile sync          # cap sync
```
Re-run `sync` (cap sync) after every web build or dependency change.

### 5.3 REQUIRED native configuration

**a) Camera + microphone (for in-app Agora live classes).** These are **not**
in the generated projects yet — add them or the live room can never capture
video/audio (it will fall back to a permission error, or "View only" if the
learner joins without them):

- **iOS** — `apps/mobile/ios/App/App/Info.plist`:
  ```xml
  <key>NSCameraUsageDescription</key><string>Join live classes with your camera.</string>
  <key>NSMicrophoneUsageDescription</key><string>Speak in live classes.</string>
  ```
- **Android** — `apps/mobile/android/app/src/main/AndroidManifest.xml`:
  ```xml
  <uses-permission android:name="android.permission.CAMERA"/>
  <uses-permission android:name="android.permission.RECORD_AUDIO"/>
  <uses-permission android:name="android.permission.MODIFY_AUDIO_SETTINGS"/>
  ```

**b) CORS / WebView origin.** A Capacitor WebView calls the API from
`capacitor://localhost` (iOS) or `http://localhost` (Android). Add these to the
API `CORS_ALLOWED_ORIGINS`, or the app cannot reach the API:
```
capacitor://localhost,http://localhost,https://localhost
```

**c) App identity & versioning.** `appId com.centrallms.app` must match the App
Store / Play Console records. Bump the marketing version and build number for
every store upload (iOS `CFBundleShortVersionString`/`CFBundleVersion`; Android
`versionName`/`versionCode` in `android/app/build.gradle`).

### 5.4 Option A — PWA / web (fastest)
`apps/mobile` is a normal web build (`www/`) with `src/manifest.webmanifest`
(`display: standalone`). Host `www/` exactly like a web app (§4.2 rules: SPA
fallback, HTTPS, add its origin to CORS). Users "Add to Home Screen". No store
review — but **no native camera/mic on iOS Safari PWAs is limited**, so treat the
PWA as the reach channel and the native builds as the full-feature channel.

### 5.5 Option B — iOS App Store
1. `pnpm --filter @central/mobile open:ios` (opens Xcode).
2. Set the Team/signing (Apple Developer account), confirm bundle id `com.centrallms.app`, add the Info.plist keys from §5.3a.
3. Product → Archive → Distribute App → App Store Connect.
4. In App Store Connect: TestFlight for internal/external testing, then submit for review. Provide a demo tenant + login for the reviewer (a real institution code + learner account).

### 5.6 Option C — Android Play Store
1. `pnpm --filter @central/mobile open:android` (Android Studio), or build from the CLI in `apps/mobile/android`.
2. Add the manifest permissions (§5.3a). Create/keep an **upload keystore** (store it in your secret manager — losing it blocks future updates).
3. Build a signed **App Bundle**: Android Studio → Build → Generate Signed Bundle (`.aab`), or `./gradlew bundleRelease`.
4. Play Console: upload the `.aab` to Internal testing → Closed → Production. Fill the data-safety form (camera/mic usage) and provide reviewer credentials.

### 5.7 Updating a shipped app
- **Web-layer change** (Angular/Ionic only): rebuild → `cap sync` → ship a new store build. (No official OTA is configured; if you add one, e.g. a live-update service, document it here.)
- **Native change** (plugin, permission, native version): rebump versions and resubmit to the stores.

---

## 6. Post-deploy runbook (ops)

### 6.1 Health checks & smoke test
```bash
curl -fsS https://api.centrallms.com/version        # → {"data":{"name":"Central LMS",...}}
```
Then, end to end: open the web app → sign in as the provisioned admin → the
dashboard loads. On mobile: enter the institution code → sign in → Home loads.
For live video, open a live-class room and confirm it either connects (keys set)
or shows a clean "not set up yet" (keys absent) — not a crash.

### 6.2 Routine update / redeploy
- **API**: pull, `composer install --no-dev -o`, `php bin/console migrations:migrate --no-interaction`, reload PHP-FPM, **recycle the worker** so it runs new code. (Runbook §11.)
- **Web/saas/public**: rebuild the changed app and swap the static dir.
- **Mobile**: rebuild → `cap sync` → new store build (§5.7).

### 6.3 Rollback
- **Code**: redeploy the previous commit/tag.
- **DB**: `php bin/console migrations:migrate prev --no-interaction` (down-migrate to a specific version) — verify the target migration has a real `down()`. Back up first (§6.4).
- **Static apps**: repoint the host at the previous `browser` build (keep the last known-good artifact).

### 6.4 Backups
Nightly `pg_dump` + the uploads bucket. Restore drill documented in **[runbook §12](./deployment-runbook-aapanel.md)**. Redis is cache + queue — losing the cache is fine; drain the queue before a Redis wipe.

### 6.5 Common tasks
- Add a tenant: `php bin/console tenant:provision …` (§3.7).
- Check meeting providers: `php bin/console ilt:check-providers`.
- Restart the worker after a config change (it caches nothing important, but new env needs a fresh process).

---

## 7. Troubleshooting

| Symptom | Cause & fix |
|---|---|
| Web/mobile calls fail with CORS error | The app's origin isn't in `CORS_ALLOWED_ORIGINS`. Add it (mobile needs the Capacitor origins, §5.3b) and restart the API. |
| Signed in, then bounced to login mid-session | The access token expired and refresh failed (bad `JWT_SECRET` mismatch across API instances, or Redis down). Ensure all API nodes share `JWT_SECRET`. |
| Deep link 404s on the web app | Missing SPA fallback to `index.html` (§4.2). |
| Live room: "In-app video is not set up for your organisation yet" | `AGORA_ENABLED/APP_ID/APP_CERTIFICATE` not set (or worker/API didn't reload after setting them). Set them and recreate/reload the API. |
| Live room: "We couldn't start the video… check permissions" | Camera/mic denied or missing native permission strings (§5.3a). The app now stays connected **view-only** in that case; grant permission to publish. |
| Live room won't book a meeting (500) | Ensure DB migrations are current — `agora` must be an allowed `training_sessions.platform` (migration `Version20260927000000`). |
| Mobile app blank / can't reach API | Wrong `apiBaseUrl` for the environment, or the device can't reach the API host over HTTPS (App Transport Security blocks cleartext — use HTTPS). |
| Migration fails midway | It ran in a transaction; fix the cause and re-run. Never edit an applied migration — add a new one. |

---

## 8. Security checklist

Base list is **[runbook §14](./deployment-runbook-aapanel.md)**; on top of it:

- [ ] `JWT_SECRET` and `CREDENTIALS_ENCRYPTION_KEY` are strong, unique per environment, and **stored in a secret manager** (never committed).
- [ ] `APP_ENV=prod`, `APP_DEBUG=false` in production.
- [ ] HTTPS everywhere; the mobile app talks only to `https://` (no cleartext exception).
- [ ] `CORS_ALLOWED_ORIGINS` is an explicit allow-list — no `*`.
- [ ] Agora **App Certificate** is server-side only (it mints tokens); it is never shipped in a client build.
- [ ] Rate limiting on (`RATE_LIMIT_ENABLED=true`).
- [ ] The web server never serves `apps/api/var`, `config`, `.env`, or upload directories directly.
- [ ] Android upload keystore and iOS signing assets are backed up in the secret manager.

---

## 9. API environment variable reference

Grouped as in `apps/api/.env.example`. Set what you use; `log`-provider defaults are safe for non-prod.

- **App**: `APP_ENV`, `APP_DEBUG`, `APP_NAME`, `APP_URL`, `APP_TIMEZONE`, `CORS_ALLOWED_ORIGINS`.
- **Database**: `DB_DRIVER`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`, `DB_SCHEMA`.
- **Redis / queue**: `REDIS_DSN`, `CACHE_NAMESPACE`, `MESSENGER_TRANSPORT_DSN`.
- **Auth**: `JWT_SECRET`, `JWT_ISSUER`, `JWT_AUDIENCE`, `JWT_ACCESS_TTL`, `JWT_REFRESH_TTL`.
- **Rate limiting**: `RATE_LIMIT_ENABLED`, `RATE_LIMIT_MAX`, `RATE_LIMIT_WINDOW`.
- **Mail**: `MAIL_PROVIDER`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`, `ZEPTOMAIL_TOKEN`, `ZEPTOMAIL_BASE_URL`.
- **SMS/OTP**: `SMS_PROVIDER`, `TERMII_API_KEY`, `TERMII_SENDER_ID`, `TERMII_BASE_URL`.
- **Push**: `FCM_PROJECT_ID`, `FCM_CREDENTIALS_PATH`.
- **Storage**: `S3_ENDPOINT`, `S3_REGION`, `S3_BUCKET`, `S3_KEY`, `S3_SECRET`, `S3_USE_PATH_STYLE`, `S3_PUBLIC_BASE_URL`.
- **Live video (Agora)**: `AGORA_ENABLED`, `AGORA_APP_ID`, `AGORA_APP_CERTIFICATE`, `AGORA_TOKEN_TTL`, `AGORA_ROOM_BASE_URL`.
- **ILT OAuth providers** (optional external meetings): `ILT_REDIRECT_BASE_URL`, `ZOOM_*`, `GOOGLE_*`, `TEAMS_*`/`MS_*`.
- **Secrets at rest**: `CREDENTIALS_ENCRYPTION_KEY`.
- **Payments**: `PAYMENTS_PROVIDER`, `PAYSTACK_SECRET_KEY`, `PAYSTACK_BASE_URL`, `PAYMENTS_CALLBACK_URL`.
- **Observability**: `SENTRY_DSN`, `SENTRY_TRACES_SAMPLE_RATE`, `LOG_LEVEL`, `LOG_PATH`.
