# Mobile build runbook — Android & PWA, per client

The learner app (`apps/mobile`, package `@central/mobile`) is **one** multi-tenant
Ionic + Angular app in a Capacitor shell. You ship it two ways, both branded per
client from the same repo:

- **PWA** — the `www/` web build, hosted on Cloudflare Pages. No Android SDK, no
  app store, installs to the home screen, auto-updates on deploy. Fastest path.
  → [§10](#10-pwa--cloudflare-pages).
- **Native Android** — a signed `.apk` / `.aab` for the Play Store or direct
  install. → [§3](#3-native-prerequisites-the-toolchain) onward.

Everything client-specific (API, institution, name, colour) is injected at
**build time** from environment variables; nothing per-client is committed.

## Contents

1. [The per-client config model](#1-the-per-client-config-model)
2. [Setting environment variables (PowerShell vs bash)](#2-setting-environment-variables-powershell-vs-bash)
3. [Native prerequisites (the toolchain)](#3-native-prerequisites-the-toolchain)
4. [Native build — step by step](#4-native-build--step-by-step)
5. [Run it on a device/emulator (debug)](#5-run-it-on-a-deviceemulator-debug)
6. [Versioning](#6-versioning)
7. [Signing (release)](#7-signing-release)
8. [Build the release artifact](#8-build-the-release-artifact)
9. [Distribute + update](#9-distribute--update)
10. [PWA — Cloudflare Pages](#10-pwa--cloudflare-pages)
11. [REQUIRED API-side changes](#11-required-api-side-changes)
12. [iOS (reference)](#12-ios-reference)
13. [Troubleshooting — every scenario](#13-troubleshooting--every-scenario)

---

## 1. The per-client config model

A client build differs only in these committed inputs, each driven by an env var.
With **no** variable set, you get the generic Central LMS defaults, so the repo
stays client-agnostic and a plain build never carries a client's values.

| What | Committed source | Env var(s) | Generic default |
| --- | --- | --- | --- |
| API it talks to | `environment.prod.ts` (written by `scripts/set-env.mjs`) | `API_BASE_URL` (incl. `/v1`) | `https://api.centrallms.com/v1` |
| Pinned institution | `environment.prod.ts` `institutionCode` | `INSTITUTION_CODE` | `''` (shows the institution screen) |
| App id / name | `capacitor.config.ts` | `CAP_APP_ID`, `CAP_APP_NAME`, `CAP_APP_SHORT_NAME` | `com.centrallms.app` / `Central LMS` |
| Icon / splash colour | `scripts/generate-source-art.mjs` | `BRAND_COLOR`, `BRAND_COLOR_LIGHT` | `#3316a6` / `#5a3ff0` |

`set-env.mjs` runs as the first step of `pnpm build`; `cap add`/`cap sync` read
`capacitor.config.ts`; `pnpm brand` reads `BRAND_COLOR`. Because the native
`android/` and `ios/` projects are **generated, not committed** (root
`.gitignore`), every one of these is re-applied from the committed sources each
time you regenerate — **never hand-edit the native project for identity or
branding; it will not survive a fresh `cap add`.**

> ### Midwestern — the concrete values
>
> | | |
> | --- | --- |
> | Institution code | `midwestern` |
> | Tenant ID | `97dd8913-d6e1-4f8e-9127-6ddc933b726d` |
> | API base URL | `https://api-midwesternlearning.midwesternog.com/v1` |
> | App id | `com.midwesternog.learning` |
> | App name | `Midwestern Learning` |
> | Brand colour | `#2400a0` |
>
> The logo, favicon and login background are read at runtime from the tenant's
> settings — they don't need baking. Verify the institution code any time with:
> ```
> curl "https://api-midwesternlearning.midwesternog.com/v1/settings/public?code=midwestern"
> ```
> A non-null `tenantId` in the response means the code resolves.

---

## 2. Setting environment variables (PowerShell vs bash)

**This is the #1 gotcha.** The syntax differs by shell, and the variables only
live for the current shell session.

**Windows PowerShell:**
```powershell
$env:API_BASE_URL     = "https://api-midwesternlearning.midwesternog.com/v1"
$env:INSTITUTION_CODE = "midwestern"
$env:CAP_APP_ID       = "com.midwesternog.learning"
$env:CAP_APP_NAME     = "Midwestern Learning"
$env:BRAND_COLOR      = "#2400a0"
```

**macOS / Linux / Git Bash:**
```bash
export API_BASE_URL="https://api-midwesternlearning.midwesternog.com/v1"
export INSTITUTION_CODE="midwestern"
export CAP_APP_ID="com.midwesternog.learning"
export CAP_APP_NAME="Midwestern Learning"
export BRAND_COLOR="#2400a0"
```

- `export …` in PowerShell gives *"The term 'export' is not recognized"* — use `$env:`.
- Set them **once per terminal session**, and set them **before** `pnpm build`,
  `cap add` and `pnpm brand` (that's when they are read and baked in). Opening a
  new terminal clears them — re-set them.
- Check they are set: PowerShell `$env:CAP_APP_NAME`, bash `echo $CAP_APP_NAME`.

---

## 3. Native prerequisites (the toolchain)

You need a Java toolchain, the Android SDK, and Node — none of which ship on a
bare machine. Two ways:

**Easy path — Android Studio** (recommended): install
[Android Studio](https://developer.android.com/studio); it bundles the Android
SDK **and** a compatible JDK (17/21), and runs the app on a device/emulator with
one click. After install, open it once so it downloads the SDK, then set
`ANDROID_HOME` to the SDK path it reports (Settings → Languages & Frameworks →
Android SDK).

**CLI-only path:**
- **JDK 17 or 21** — *not* 26. Install [Temurin 17](https://adoptium.net/),
  set `JAVA_HOME` to it, confirm with `java -version`.
  (Java 24+/26 is newer than the Gradle version here supports and fails the build.)
- **Android SDK command-line tools.** Set `ANDROID_HOME`, put `platform-tools`
  and `cmdline-tools/latest/bin` on `PATH`, then:
  ```bash
  sdkmanager "platform-tools" "platforms;android-35" "build-tools;35.0.0"
  sdkmanager --licenses     # accept all — unaccepted licences fail the build
  ```
  (Capacitor 8 compiles against Android 15 / API 35.)

**Always needed:**
- **Node 24** (the repo pins it in `.nvmrc`) and **pnpm** (`corepack enable`).
  From the **repo root**: `pnpm install`.
- The **Capacitor CLI is local, not global.** Run it as `npx cap …` (from
  `apps/mobile`) or `pnpm exec cap …`. A bare `cap …` gives *"The term 'cap' is
  not recognized"* / *"command not found"*.

---

## 4. Native build — step by step

Run these from **`apps/mobile`**. (From the repo root, the equivalents are
`pnpm --filter @central/mobile <script>` and `pnpm --filter @central/mobile exec cap …`.)

> **Order matters, and `brand` is easy to forget.** The one correct order is:
> **set env → `pnpm build` → `cap add` → `pnpm brand` → `cap sync` → Gradle.**
> Skipping `brand` leaves the default Ionic app icon (see [§13](#13-troubleshooting--every-scenario)).

**Step 0 — clean regenerate (only if the native project already exists with a
different identity).** Identity is written at `cap add` time, so to change it you
start from a fresh project:
```powershell
# from apps/mobile
Remove-Item -Recurse -Force android     # bash: rm -rf android
```

**Step 1 — set the profile** (see [§2](#2-setting-environment-variables-powershell-vs-bash); the Midwestern block).

**Step 2 — build the web bundle** (runs `set-env.mjs`, then `ng build` → `www/`):
```powershell
pnpm build
```
Expect `[set-env] environment.prod.ts — apiBaseUrl=… institutionCode=midwestern`
in the output. That confirms the profile baked in.

**Step 3 — generate the native Android project** (bakes `CAP_APP_ID` /
`CAP_APP_NAME` into it):
```powershell
npx cap add android
```

**Step 4 — generate the branded icon + splash** (reads `BRAND_COLOR`; writes
every Android icon size into `android/app/src/main/res/`):
```powershell
pnpm brand
```

**Step 5 — copy the web build + plugins into the native project:**
```powershell
npx cap sync android
```

**Step 6 — verify the identity and branding took:**
- `apps/mobile/android/app/build.gradle` → `applicationId "com.midwesternog.learning"`
- `apps/mobile/android/app/src/main/res/values/strings.xml` → `<string name="app_name">Midwestern Learning</string>`
- `apps/mobile/android/app/src/main/res/` has `mipmap-*` icons that are the
  graduation-cap mark on `#2400a0`, not the default Capacitor mark.

If any of these are still the generic Central LMS values, the env vars were not
set before `cap add`/`brand` — redo from Step 0 with the variables set.

---

## 5. Run it on a device/emulator (debug)

To test before worrying about signing (debug builds self-sign):

- **Android Studio:** `npx cap open android`, then press Run with a device or
  emulator selected.
- **One-shot CLI:** with a device/emulator connected (`adb devices` lists it):
  ```powershell
  npx cap run android
  ```
- **Build a debug APK by hand:**
  ```powershell
  cd android
  .\gradlew assembleDebug      # bash/mac: ./gradlew assembleDebug
  # -> app/build/outputs/apk/debug/app-debug.apk
  adb install -r app/build/outputs/apk/debug/app-debug.apk
  ```

On launch, a Midwestern build should open **straight on sign-in** (no
institution screen) and wear Midwestern's colours/logo once the API answers.

---

## 6. Versioning

A freshly generated project starts at `versionCode 1` / `versionName "1.0"`.
For every release, edit `apps/mobile/android/app/build.gradle` `defaultConfig`:

```gradle
versionCode 1          // integer; MUST increase on every Play upload
versionName "1.0.0"    // the human version shown in the store
```

Because `android/` is regenerated, set this after `cap add` (or script it).

---

## 7. Signing (release)

**Generate an upload keystore once** and keep it safe **off** the build machine —
losing it means you can never update the app on the Play Store again:

```bash
keytool -genkey -v -keystore midwestern-upload.jks \
  -alias midwestern -keyalg RSA -keysize 2048 -validity 10000
```

Create `apps/mobile/android/key.properties` (**do not commit**):

```properties
storeFile=C:/keys/midwestern-upload.jks
storePassword=********
keyAlias=midwestern
keyPassword=********
```

Add the signing wiring to `apps/mobile/android/app/build.gradle` after `cap add`
(it is regenerated, so this is re-applied each clean build). At the top:

```gradle
def keystoreProperties = new Properties()
def keystorePropertiesFile = rootProject.file('key.properties')
if (keystorePropertiesFile.exists()) {
    keystoreProperties.load(new FileInputStream(keystorePropertiesFile))
}
```

and inside `android { }`:

```gradle
signingConfigs {
    release {
        storeFile file(keystoreProperties['storeFile'])
        storePassword keystoreProperties['storePassword']
        keyAlias keystoreProperties['keyAlias']
        keyPassword keystoreProperties['keyPassword']
    }
}
buildTypes {
    release {
        signingConfig signingConfigs.release
        minifyEnabled false
    }
}
```

---

## 8. Build the release artifact

```powershell
cd android
.\gradlew bundleRelease      # AAB for the Play Store -> app/build/outputs/bundle/release/app-release.aab
.\gradlew assembleRelease    # APK for direct install -> app/build/outputs/apk/release/app-release.apk
```
(bash/mac: `./gradlew …`.) The first run downloads Gradle + dependencies and is
slow; later runs are fast.

---

## 9. Distribute + update

**Distribute**
- **Play Store:** create the app in Google Play Console (Midwestern's Google
  account), upload `app-release.aab`, complete the listing + data-safety form,
  roll out to a track. The account and submission are Midwestern's to do.
- **Direct / internal:** hand out the signed `.apk`, or use Play **Internal
  testing** or Firebase **App Distribution**.

**Ship an update** — bump `versionCode`/`versionName` ([§6](#6-versioning)),
rebuild ([§8](#8-build-the-release-artifact)), re-upload. If you regenerated
`android/` (`cap add`), **re-run `pnpm brand`** and re-apply the signing block
([§7](#7-signing-release)) first — they live in the non-committed native project.

---

## 10. PWA — Cloudflare Pages

The `www/` build is also an **installable PWA** (manifest + service worker + SPA
fallback). No Android SDK, no store — the quickest way onto learners' home
screens. Host it on a Midwestern subdomain such as
`app.midwesternlearning.midwesternog.com`.

**Build** (from `apps/mobile`; same profile vars):
```powershell
pnpm build        # -> apps/mobile/www (index.html, manifest.webmanifest, sw.js, _redirects, _headers + bundles)
```
`set-env.mjs` stamps the API, the institution code, the manifest
(`name`/`short_name`/colours) and the index `<title>`, so `www/` ships as
"Midwestern Learning".

**Deploy — manual (one-off):**
```bash
npx wrangler login                                                          # once
npx wrangler pages project create midwestern-app --production-branch main   # once
npx wrangler pages deploy apps/mobile/www --project-name midwestern-app
```
Then add the custom domain in the Pages project → Custom domains (Cloudflare
issues the TLS cert).

**Deploy — Git auto-deploy (recommended, same as the web).** Dashboard → Workers
& Pages → Create → Pages → connect this repo:
- **Production branch:** `main`
- **Build command:** `pnpm install && pnpm --filter @central/mobile build`
- **Build output directory:** `apps/mobile/www`
- **Root directory:** `/`
- **Environment variables:**
  - `NODE_VERSION=24` (matches `.nvmrc`; Pages reads it automatically)
  - `API_BASE_URL=https://api-midwesternlearning.midwesternog.com/v1`
  - `INSTITUTION_CODE=midwestern`
  - `CAP_APP_NAME=Midwestern Learning`
  - *(optional)* `BRAND_COLOR=#2400a0`

Keep this a **separate** Pages project from the web's (different build command,
output and `app.` custom domain). A push to `main` then redeploys both. The
service worker is network-first for navigations, so installed clients pick up
each new deploy on next launch.

See also [per-client-build-config.md](per-client-build-config.md) for the full
variable reference and how to add a new tenant-specific default.

---

## 11. REQUIRED API-side changes

**CORS (required, or the app can't reach the API).** The app uses Angular
`HttpClient` (XHR), so every call is subject to CORS. The Capacitor **Android**
WebView origin is `https://localhost`; **iOS** is `capacitor://localhost`; the
**PWA** is its own web origin. Add all that apply to the Midwestern API's
`CORS_ALLOWED_ORIGINS` (comma-separated, full origin, no trailing slash) and
reload php-fpm:

```
CORS_ALLOWED_ORIGINS=https://midwesternlearning.midwesternog.com,https://app.midwesternlearning.midwesternog.com,https://localhost,capacitor://localhost
```

Without this, sign-in and every request fail from the app even though the web works.

**DEFAULT_TENANT_ID (optional).** Setting `DEFAULT_TENANT_ID=97dd8913-d6e1-4f8e-9127-6ddc933b726d`
in the API `.env` makes `/settings/public` return Midwestern with no code at all,
so any build (even one without `INSTITUTION_CODE`) resolves the tenant on first
load and the web/app brand before anyone signs in. After editing `.env`, clear
the container cache (`rm -rf var/cache/container var/cache/proxies`) and reload
php-fpm.

---

## 12. iOS (reference)

Needs **macOS + Xcode** — not buildable on Windows/Linux. Same profile env vars,
then:
```bash
pnpm build
pnpm exec cap add ios
pnpm brand
pnpm exec cap sync ios
pnpm exec cap open ios        # Xcode: set bundle id = CAP_APP_ID, signing team, archive, upload
```
Add `capacitor://localhost` to the API CORS list ([§11](#11-required-api-side-changes)).

---

## 13. Troubleshooting — every scenario

| Symptom | Cause | Fix |
| --- | --- | --- |
| `export : The term 'export' is not recognized` | bash syntax in PowerShell | Use `$env:NAME="value"` ([§2](#2-setting-environment-variables-powershell-vs-bash)) |
| `cap : The term 'cap' is not recognized` / `cap: command not found` | Capacitor CLI is a local dependency, not global | `npx cap …` (from `apps/mobile`) or `pnpm exec cap …` |
| **App icon is the default Ionic/Capacitor icon** | `pnpm brand` was not run (or was run before `cap add`, so the fresh project overwrote it) | Set `$env:BRAND_COLOR`, run `pnpm brand` **after** `cap add`, then rebuild. Order: build → cap add → **brand** → sync → Gradle |
| New icon doesn't show after reinstall | Android launcher caches icons | Uninstall the app first (or reboot the device/launcher), then reinstall |
| Icon/splash is indigo `#3316a6`, not Midwestern `#2400a0` | `BRAND_COLOR` wasn't set when `pnpm brand` ran | Set `$env:BRAND_COLOR="#2400a0"`, re-run `pnpm brand`, rebuild |
| Gradle: `Unsupported class file major version` / JDK/toolchain error | Wrong JDK (e.g. Java 24/26) | Install JDK 17 or 21, point `JAVA_HOME` at it ([§3](#3-native-prerequisites-the-toolchain)) |
| `SDK location not found` / `ANDROID_HOME is not set` | Android SDK missing or unconfigured | Install the SDK or Android Studio; set `ANDROID_HOME` |
| Gradle: `You have not accepted the license agreements` | SDK licences unaccepted | `sdkmanager --licenses` and accept all |
| `cap add android` says the platform already exists | `android/` is already there | `Remove-Item -Recurse -Force android` (bash `rm -rf android`), then `cap add android` |
| App id is still `com.centrallms.app` / name "Central LMS" | Env vars weren't set before `cap add` | Re-set the profile, remove `android/`, redo from Step 0 ([§4](#4-native-build--step-by-step)) |
| **Institution-code screen still shows** on a Midwestern build | `INSTITUTION_CODE` not set at build, wrong code, or the launch `/settings/public?code=…` failed (no network / CORS) | Confirm `environment.prod.ts` shows `institutionCode: "midwestern"` after `pnpm build`; verify the code resolves (the curl in [§1](#1-the-per-client-config-model)); ensure CORS allows `https://localhost` |
| White screen, or sign-in does nothing | Wrong `API_BASE_URL`, or CORS not allowing the app origin | Check `environment.prod.ts` baked the right API; add `https://localhost` to `CORS_ALLOWED_ORIGINS` ([§11](#11-required-api-side-changes)); check the device WebView console |
| `[set-env] no client vars set …` during build | No env vars in this shell | Set them in the **same** terminal before `pnpm build` ([§2](#2-setting-environment-variables-powershell-vs-bash)) |
| `@capacitor/assets`: `Unable to parse color from string: '#…'` (so no icons generate) | An old `assets` npm script single-quoted the colour flags; cmd.exe on Windows passed the quotes through literally | Pull latest — `brand` now runs `@capacitor/assets` via `scripts/generate-assets.mjs` with an argv array (no shell quoting), honouring `BRAND_COLOR` |
| `pnpm brand` fails (sharp / `@capacitor/assets`) | Native dep not built for this machine | `pnpm install` (or `pnpm rebuild sharp`) at the repo root, retry |
| Release APK installs but won't update over an existing one | `versionCode` not increased, or different signing key | Bump `versionCode` ([§6](#6-versioning)); use the same keystore ([§7](#7-signing-release)) |
| `wrangler` not found (PWA) | Not installed | `npx wrangler …` downloads it on first use; or use Git auto-deploy ([§10](#10-pwa--cloudflare-pages)) |

If something here isn't covered, capture the exact command + full output — most
build failures name the missing piece (JDK version, SDK path, a licence) on the
first error line.
