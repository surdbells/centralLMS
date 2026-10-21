# Per-client build configuration

One repo, many tenants. Anything that differs per client — the API it talks to,
its name, its brand colour — is injected **at build time** from environment
variables, never committed. Each Cloudflare Pages project (web and PWA) sets the
variables for its tenant; a plain local build sets none and uses the committed
generic defaults, so the working tree never carries a client's values.

A static SPA can't read Cloudflare's *runtime* variables (its JS runs in the
browser, not on the edge), so the injection happens during the build. The single
injection point per app is `scripts/set-env.mjs`, wired into that app's `build`
script. It reads the env vars and stamps the committed source (environment file,
`index.html`, the PWA manifest); with a variable unset it leaves that file
untouched.

## The variables today

### Web (`apps/web`, Cloudflare Pages → the root domain)

| Variable | Injected into | Notes |
| --- | --- | --- |
| `API_BASE_URL` | `environment.ts` `api.baseUrl` | Absolute, includes `/v1`, no trailing slash |
| `PUBLIC_SITE_URL` | `environment.ts` `publicSiteUrl` | The marketing/site origin |
| `SITE_NAME` | `index.html` `<title>` | Pre-bootstrap tab title (runtime then shows the org name) |
| `FAVICON_URL` | `index.html` favicon | Absolute URL or root-relative path |
| `BRAND_PRIMARY` | inline `:root{--brand-primary}` in `index.html` | 6-digit hex; a branded **first paint**, before the API answers |

### PWA / mobile (`apps/mobile`, Cloudflare Pages → `app.` subdomain)

| Variable | Injected into | Notes |
| --- | --- | --- |
| `API_BASE_URL` | `environment.prod.ts` `apiBaseUrl` | Absolute, includes `/v1` |
| `INSTITUTION_CODE` | `environment.prod.ts` `institutionCode` | Pins a single-tenant build to one institution — the app resolves it at launch and the **institution-code screen is never shown** (PWA and native) |
| `CAP_APP_NAME` | manifest `name` + `index.html` `<title>` (and the native app name via `capacitor.config.ts`) | The installed app's name |
| `CAP_APP_SHORT_NAME` | manifest `short_name` | Optional; defaults to the first word of `CAP_APP_NAME` |
| `BRAND_COLOR` | manifest `theme`/`background` + `index` theme-color (and the native splash + icon field) | 6-digit hex |
| `CAP_APP_ID` | native `applicationId` via `capacitor.config.ts` | Reverse-DNS; only matters for the native/store build, not the PWA |

### API side (server `.env`, not a build var)

| Variable | Effect |
| --- | --- |
| `DEFAULT_TENANT_ID` | `GET /v1/settings/public` returns this org's branding before anyone signs in, so a single-tenant deployment is branded on first load |
| `CORS_ALLOWED_ORIGINS` | Must list every origin that calls the API: the web domain, the PWA `app.` domain, and `https://localhost` / `capacitor://localhost` for the native app |

## Adding a new tenant-specific default (the go-forward recipe)

1. **Name it** — a clear env var, e.g. `SUPPORT_EMAIL`, `HELP_URL`.
2. **Stamp it** in the relevant app's `scripts/set-env.mjs`: read
   `process.env.THE_VAR`, and when it is set, write it into the committed target
   (the environment file, `index.html`, the manifest, …). Guard it so an unset
   variable is a no-op.
3. **Keep the committed default generic** — the value used when the variable is
   not set must be the neutral/product default, so the repo stays client-free.
4. **Set it per tenant** in that tenant's Cloudflare Pages project (Settings →
   Environment variables), then redeploy.

That is the whole pattern: a new per-client knob is one env var + one guarded
stamp in `set-env.mjs`, and it is configured in Cloudflare exactly like
`API_BASE_URL`.

See also: [mobile-android-build.md](mobile-android-build.md) (PWA + native build)
and the Cloudflare Pages section of [deployment-runbook-aapanel.md](deployment-runbook-aapanel.md)
(web).
