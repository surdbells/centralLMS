# Deployment Runbook — Central LMS on Ubuntu + aaPanel

This runbook deploys Central LMS to a single Ubuntu server managed with
[aaPanel](https://www.aapanel.com/). It is the production topology the codebase
assumes: the API runs under **PHP-FPM** (not the dev Docker image), the web app
is a **static Angular build served by Nginx**, and **PostgreSQL 16** and
**Redis** run as native services. A long-running **queue worker** consumes
background jobs.

> This is **one** deployment path (the reference production topology). For every
> option across all surfaces — alternative web hosting (Netlify/Vercel/Cloudflare/
> S3/Docker) and the complete **mobile** deployment (PWA, iOS App Store, Android
> Play Store) — see the umbrella [Deployment Guide & Runbook](./deployment-guide.md).

> Conventions: commands run as a sudo-capable user unless noted. Replace
> `lms.example.com` / `api.lms.example.com` with your own hostnames and every
> `CHANGE_ME` with a real secret. Never commit real secrets to git.

---

## 1. Architecture at a glance

| Component | What it is | Served as |
|-----------|------------|-----------|
| API | Slim 4 / PHP 8.3 (`apps/api`, entry `public/index.php`) | PHP-FPM pool behind Nginx at `api.lms.example.com` |
| Web | Angular 19 build (`apps/web`) | Static files behind Nginx at `lms.example.com` |
| Database | PostgreSQL 16 | Local service, port 5432 |
| Cache / queue | Redis 7 | Local service, port 6379 |
| Worker | `bin/console messenger:consume` | systemd service (or aaPanel Supervisor) |

Data flow: the browser loads the static Angular app from `lms.example.com`,
which calls the API at `https://api.lms.example.com/v1`. The API reads/writes
PostgreSQL, uses Redis for cache and the message queue, and the worker drains
the queue (emails, notifications, etc.).

---

## 2. Server prerequisites

- Ubuntu 22.04 LTS (or 24.04), 2 vCPU / 4 GB RAM minimum, 40 GB disk.
- A sudo user and SSH access.
- DNS A-records for `lms.example.com` and `api.lms.example.com` pointing at the server.

Install aaPanel (if not already):

```bash
URL=https://www.aapanel.com/script/install_7.0_en.sh && curl -sSO "$URL" && bash install_7.0_en.sh aapanel
```

Log in to the panel on the printed URL and complete the first-run wizard.

---

## 3. Install the stack through aaPanel

In **App Store**, install:

- **Nginx** (latest stable).
- **PM2 Manager** *or* **Supervisor Manager** (to keep the queue worker running).
- **Redis** (7.x).

PHP and PostgreSQL notes:

- **PHP 8.3** — install from the App Store, then in *Settings → Install
  extensions* enable: `bcmath`, `curl`, `fileinfo`, `mbstring`, `openssl`,
  `pdo`, `pdo_pgsql`, `redis`, `intl`, `zip`, `gd`. `pdo_pgsql` is essential —
  the app will not boot without it.
- **PostgreSQL 16** — aaPanel's App Store ships PostgreSQL; install 16. If only
  an older major is offered, add the PGDG repo and install `postgresql-16`
  manually, then register the data dir with aaPanel or manage it via `systemctl`.

Verify:

```bash
php8.3 -v
php8.3 -m | grep -E 'pdo_pgsql|redis|mbstring|intl'
psql --version        # expect 16.x
redis-cli ping        # expect PONG
node -v && npm -v      # if building on the server (Node 20+)
```

---

## 4. Get the code

```bash
sudo mkdir -p /www/wwwroot/central-lms
sudo chown -R www:www /www/wwwroot/central-lms
cd /www/wwwroot/central-lms
git clone <YOUR_REPO_URL> .
```

`www` is aaPanel's web user. Keep the whole monorepo here; Nginx will point at
the two subdirectories that matter (`apps/api/public` and the web build).

---

## 5. Database

Create the role and database (as the `postgres` superuser):

```bash
sudo -u postgres psql <<'SQL'
CREATE ROLE central_lms LOGIN PASSWORD 'CHANGE_ME_DB_PASSWORD';
CREATE DATABASE central_lms OWNER central_lms ENCODING 'UTF8' LC_COLLATE 'C' LC_CTYPE 'C' TEMPLATE template0;
GRANT ALL PRIVILEGES ON DATABASE central_lms TO central_lms;
SQL
```

> `LC_COLLATE 'C'` matches the dev stack so index ordering is identical across
> environments. The app also relies on `gen_random_uuid()` — provided by
> `pgcrypto`, enabled per-database by the first migration; if a migration
> complains, run `CREATE EXTENSION IF NOT EXISTS pgcrypto;` in the database.

---

## 6. Deploy the API (PHP-FPM)

### 6.1 Install dependencies

```bash
cd /www/wwwroot/central-lms/apps/api
composer install --no-dev --optimize-autoloader
```

### 6.2 Configure the environment

```bash
cp .env.example .env
```

Edit `apps/api/.env` — the keys that must be set for production:

```dotenv
APP_ENV=prod
APP_DEBUG=false
APP_URL=https://api.lms.example.com
APP_TIMEZONE=Africa/Lagos
CORS_ALLOWED_ORIGINS=https://lms.example.com

DB_DRIVER=pdo_pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_NAME=central_lms
DB_USER=central_lms
DB_PASSWORD=CHANGE_ME_DB_PASSWORD
DB_SCHEMA=public

REDIS_DSN=redis://127.0.0.1:6379
MESSENGER_TRANSPORT_DSN=redis://127.0.0.1:6379/messages

JWT_SECRET=CHANGE_ME_LONG_RANDOM_64_CHARS
JWT_ISSUER=central-lms
JWT_AUDIENCE=central-clients

MAIL_PROVIDER=zeptomail        # or 'log' to disable real sending
MAIL_FROM_ADDRESS=no-reply@lms.example.com
MAIL_FROM_NAME=Central LMS
# ...plus provider tokens (ZEPTOMAIL_TOKEN, S3_*, ZOOM_*, etc.) as needed.
```

Generate the JWT secret with `openssl rand -hex 32`. Lock the file down:
`chmod 600 .env && chown www:www .env`.

### 6.3 Run migrations

```bash
php8.3 bin/console migrations:migrate --no-interaction
```

Re-run this on every deploy that adds migrations. It is idempotent.

### 6.4 PHP-FPM pool + Nginx site

In aaPanel, create a website for `api.lms.example.com` with **PHP 8.3** and no
database (already made). Then set its **document root** to
`/www/wwwroot/central-lms/apps/api/public` and replace the site's Nginx config
(*Website → Config*) rewrite section with:

```nginx
location / {
    try_files $uri /index.php$is_args$args;
}

location ~ \.php$ {
    include enable-php-83.conf;                 # aaPanel's FPM include
    fastcgi_param SCRIPT_FILENAME $document_root/index.php;
}

# Uploaded files are streamed by the app; do not let Nginx serve app dirs.
location ~ /\.(?!well-known) { deny all; }
client_max_body_size 128m;                       # course resources up to 100 MB
```

Reload Nginx. Confirm the API is up:

```bash
curl -s https://api.lms.example.com/version
```

---

## 7. Deploy the web (Angular static build)

Build the app — on the server (Node 20+) or in CI, whichever you prefer. Point
production at the API host first:

`apps/web/src/environments/environment.ts`

```ts
export const environment: AppEnvironment = {
  production: true,
  api: { baseUrl: 'https://api.lms.example.com/v1' },
  publicSiteUrl: 'https://lms.example.com',
};
```

Build:

```bash
cd /www/wwwroot/central-lms/apps/web
npm ci
npx ng build --configuration production
# output: apps/web/dist/central/browser
```

In aaPanel, create a website for `lms.example.com` (PHP: **Static/Pure HTML**),
set its document root to `.../apps/web/dist/central/browser`, and set the Nginx
rewrite so the SPA handles its own routes:

```nginx
location / {
    try_files $uri $uri/ /index.html;
}
location ~* \.(?:js|css|woff2?|png|jpe?g|svg|ico)$ {
    expires 30d;
    add_header Cache-Control "public, immutable";
}
```

Reload Nginx and load `https://lms.example.com`.

### 7b. Alternative: host the web on Cloudflare Pages

Use this instead of §7 when the SPA should be served from Cloudflare's edge (global
CDN, free TLS) rather than the aaPanel box — e.g. Midwestern, where the API server is a
VPN-only private VM and only the frontend is public.

**Prerequisites**
- The domain's zone (e.g. `midwesternog.com`) is on Cloudflare (DNS managed there).
- Node 22+ and pnpm (locally, or let Cloudflare's build runner do it).
- The API must be reachable at a **public HTTPS URL** — the browser calls it directly.
  A private (VPN-only) API needs a Cloudflare Tunnel first (see §7c).

**1. Point the build at the API and its own origin** — `apps/web/src/environments/environment.ts`:

```ts
export const environment: AppEnvironment = {
  production: true,
  api: { baseUrl: 'https://api-midwesternlearning.midwesternog.com/v1' },
  publicSiteUrl: 'https://midwesternlearning.midwesternog.com',
};
```

The API URL is baked in at build time. Either edit this file before building, or keep
the repo generic and inject it from a Pages env var in the build command (see 2b).
`apps/web/public/_redirects` already ships the SPA fallback (`/* /index.html 200`) so
deep links resolve on Pages.

**2a. Direct upload (manual / one-off)**

```bash
# repo root, on any machine with Node 22 + pnpm
pnpm install
pnpm --filter @central/web build            # -> apps/web/dist/central/browser
npx wrangler login                          # once
npx wrangler pages project create midwestern-lms --production-branch main   # once
npx wrangler pages deploy apps/web/dist/central/browser --project-name midwestern-lms
```

**2b. Git integration (CI on every push)**

Cloudflare dashboard → Workers & Pages → Create → Pages → connect the repo:
- **Production branch:** `main`
- **Build command:**
  `pnpm install && sed -i "s#https://api.centrallms.com/v1#$API_BASE_URL#" apps/web/src/environments/environment.ts && pnpm --filter @central/web build`
- **Build output directory:** `apps/web/dist/central/browser`
- **Root directory:** `/`
- **Env vars:** `NODE_VERSION=22`, `API_BASE_URL=https://api-midwesternlearning.midwesternog.com/v1`

The `sed` keeps the repo's generic default and injects the per-deployment API URL, so a
tenant's URL is never committed.

**3. Custom domain** — Pages project → Custom domains → add
`midwesternlearning.midwesternog.com`. Cloudflare issues the cert automatically.

**4. Wire the API side** — in the API `.env`, allow the Pages origin (full origin:
scheme, host, no trailing slash) and reload PHP-FPM:

```
CORS_ALLOWED_ORIGINS=https://midwesternlearning.midwesternog.com
```

Confirm in the browser Network tab that login and a data call hit the API and return 200.

### 7c. Reaching a VPN-only / private API from Cloudflare (Cloudflare Tunnel)

When the API server has no public IP (e.g. a private Azure VM reachable only over VPN),
a public Pages frontend cannot call it directly. Run a Cloudflare Tunnel on the API box:
it dials **out** to Cloudflare, and Cloudflare forwards the API hostname to it — no
inbound exposure, no public IP.

Requires: the zone on Cloudflare, and outbound HTTPS from the box
(`curl -sI https://cloudflare.com` returns a status line).

```bash
# on the aaPanel API server
curl -L https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-linux-amd64.deb -o cloudflared.deb
sudo dpkg -i cloudflared.deb
cloudflared tunnel login                       # opens a URL to authorize the zone
cloudflared tunnel create midwestern-api
cloudflared tunnel route dns midwestern-api api-midwesternlearning.midwesternog.com
```

`/etc/cloudflared/config.yml`:

```yaml
tunnel: <TUNNEL-ID-from-create>
credentials-file: /root/.cloudflared/<TUNNEL-ID>.json
ingress:
  - hostname: api-midwesternlearning.midwesternog.com
    service: http://localhost:80        # aaPanel Nginx routes by the Host header
  - service: http_status:404
```

```bash
sudo cloudflared service install
sudo systemctl enable --now cloudflared
```

Gotcha: point the tunnel at the API over **plain HTTP locally** (Cloudflare does the
public TLS). If the aaPanel API site force-redirects http→https you get a loop — either
turn that redirect off on the API site, or use `service: https://localhost:443` with
`originRequest: { originServerName: api-midwesternlearning.midwesternog.com, noTLSVerify: true }`.

---

## 8. The queue worker

Background jobs (emails, notifications) run through Symfony Messenger over
Redis. Keep one consumer alive.

**Option A — systemd (recommended):** create `/etc/systemd/system/central-lms-worker.service`:

```ini
[Unit]
Description=Central LMS queue worker
After=network.target redis-server.service postgresql.service

[Service]
Type=simple
User=www
WorkingDirectory=/www/wwwroot/central-lms/apps/api
ExecStart=/usr/bin/php8.3 bin/console messenger:consume --time-limit=290 --memory-limit=256M
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now central-lms-worker
sudo systemctl status central-lms-worker
```

The `--time-limit` makes the process exit every ~5 minutes so `Restart=always`
recycles it — this releases memory and picks up new code without a manual
restart. **Option B — aaPanel Supervisor:** add a program with the same
`ExecStart`, `numprocs=1`, `autorestart=true`, user `www`.

---

## 9. SSL

In aaPanel, for each site (*Website → SSL*) issue a **Let's Encrypt**
certificate and turn on **Force HTTPS**. Renewal is automatic. Ensure both
hostnames resolve publicly before requesting certificates.

---

## 10. Scheduled tasks (cron)

If session reminders / due-soon notifications are enabled, run the scheduler
every minute via aaPanel **Cron** (or system cron) as user `www`:

```bash
* * * * * cd /www/wwwroot/central-lms/apps/api && /usr/bin/php8.3 bin/console scheduler:run >> /dev/null 2>&1
```

> Check `apps/api/bin/console list` for the exact command name before enabling;
> only add this if a scheduler command exists in your build.

---

## 11. Updating (routine deploy)

```bash
cd /www/wwwroot/central-lms
git pull --ff-only

# API
cd apps/api
composer install --no-dev --optimize-autoloader
php8.3 bin/console migrations:migrate --no-interaction

# Web (rebuild only if apps/web changed)
cd ../web
npm ci
npx ng build --configuration production

# Recycle the worker so it runs the new code
sudo systemctl restart central-lms-worker
```

PHP-FPM picks up API changes immediately (no restart needed for code; restart
`php-fpm-83` only if you changed `.env` or PHP config). The static build swaps
in place; a hard refresh clears the browser cache thanks to hashed filenames.

**Rollback:** `git checkout <previous-tag>`, re-run `composer install` and the
web build. Database migrations have `down()` methods but rolling schema back in
production is risky — prefer forward fixes; take a backup before every deploy.

---

## 12. Backups

Nightly database dump via aaPanel Cron (user `www`):

```bash
0 2 * * * PGPASSWORD='CHANGE_ME_DB_PASSWORD' pg_dump -U central_lms -h 127.0.0.1 central_lms | gzip > /www/backup/central_lms_$(date +\%F).sql.gz
```

Also back up `apps/api/.env` (secrets) and the uploaded-files location
(local disk unless `S3_*` is configured, in which case the bucket is the store).
aaPanel's built-in backup can ship these to S3/FTP.

Restore: `gunzip -c dump.sql.gz | psql -U central_lms -h 127.0.0.1 central_lms`.

---

## 13. Health checks & troubleshooting

| Symptom | Check |
|---------|-------|
| `500` from API | `apps/api/var/log/` and the site's Nginx/PHP error log; confirm `pdo_pgsql` is loaded (`php8.3 -m`). |
| API boots but every call is `unauthenticated` | `JWT_SECRET` set and identical across restarts; server clock in sync (`timedatectl`). |
| CORS errors in the browser | `CORS_ALLOWED_ORIGINS` matches the web origin exactly (scheme + host, no trailing slash). |
| Emails never arrive | worker running (`systemctl status central-lms-worker`); `MAIL_PROVIDER` and its token set; check the worker log. |
| Migrations "already at latest" but a column is missing | a migration is recorded but did not run — inspect `doctrine_migration_versions`; never hand-edit unless you know the SQL ran. |
| Uploads fail at ~1 MB | raise `client_max_body_size` (Nginx) and `upload_max_filesize` / `post_max_size` (PHP) to 128M. |
| SPA deep links 404 | the web site's `try_files … /index.html` rewrite is missing. |

Quick smoke test after any deploy:

```bash
curl -s https://api.lms.example.com/version        # API up
curl -sI https://lms.example.com | head -1          # web 200
sudo systemctl is-active central-lms-worker         # worker active
```

---

## 14. Security checklist

- `APP_DEBUG=false` in production.
- `.env` is `chmod 600`, owned by `www`, never in git.
- PostgreSQL and Redis bound to `127.0.0.1` only (not exposed publicly).
- Force HTTPS on both sites; HSTS on once you are confident.
- Rotate `JWT_SECRET` only during a maintenance window (it invalidates all
  active tokens — everyone is signed out).
- Keep the OS, PHP, PostgreSQL and Nginx patched via aaPanel.
