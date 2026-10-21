# Mobile — building the Android app for a client (e.g. Midwestern)

The learner app (`apps/mobile`, `@central/mobile`) is one multi-tenant Ionic +
Angular app in a Capacitor shell. A **client build** differs only in three
committed inputs, all driven by build-time environment variables so the repo
stays client-agnostic — the same idea as the web's `scripts/set-env.mjs`:

| What | Source (committed) | Env var | Generic default |
| --- | --- | --- | --- |
| API it talks to | `src/environments/environment.prod.ts` (written by `scripts/set-env.mjs`) | `API_BASE_URL` | `https://api.centrallms.com/v1` |
| App id / name | `capacitor.config.ts` | `CAP_APP_ID`, `CAP_APP_NAME` | `com.centrallms.app` / `Central LMS` |
| Icon / splash colour | `scripts/generate-source-art.mjs` (the graduation-cap mark) | `BRAND_COLOR`, `BRAND_COLOR_LIGHT` | `#3316a6` / `#5a3ff0` |

The native `android/` and `ios/` projects are **generated, not committed**
(root `.gitignore`), so every setting above is re-applied from these committed
sources each time you `cap add`. Never hand-edit the native project for identity
or branding — it will not survive regeneration.

> **This cannot be built on the dev Windows box.** `ANDROID_HOME` is unset (no
> Android SDK) and the JDK there is Java 26, newer than the Android Gradle
> toolchain supports. Build on a machine (or CI) with the toolchain below.
> **iOS** builds only on macOS + Xcode — see the bottom.

---

## 1. Build-machine prerequisites

- **JDK 17** (recommended) or 21 — *not* 26. `java -version` to confirm.
- **Android SDK** command-line tools. Set `ANDROID_HOME` (e.g. `~/Android/sdk`),
  put `platform-tools` and `cmdline-tools/latest/bin` on `PATH`, then:
  ```bash
  sdkmanager "platform-tools" "platforms;android-35" "build-tools;35.0.0"
  sdkmanager --licenses   # accept all
  ```
  (Capacitor 8 compiles against Android 15 / API 35.)
- **Node 20+** and **pnpm**. From the repo root: `pnpm install`.

## 2. The Midwestern build profile

Set these in the shell (or as CI variables) before building:

```bash
export API_BASE_URL="https://api-midwesternlearning.midwesternog.com/v1"
export CAP_APP_ID="com.midwesternog.learning"
export CAP_APP_NAME="Midwestern Learning"
# Optional — only if Midwestern supplies an official brand colour:
# export BRAND_COLOR="#RRGGBB"
# export BRAND_COLOR_LIGHT="#RRGGBB"
```

With nothing set, you get the generic Central LMS app on `api.centrallms.com`.

## 3. Build the web bundle and generate the native project

```bash
# 1. Bake API_BASE_URL into environment.prod.ts, then ng build -> apps/mobile/www
pnpm --filter @central/mobile build

# 2. Fresh native project; bakes CAP_APP_ID/CAP_APP_NAME from capacitor.config.ts
pnpm --filter @central/mobile exec cap add android

# 3. Regenerate icon + splash (honours BRAND_COLOR) into the native project + PWA
pnpm --filter @central/mobile brand

# 4. Copy the web build and plugins into the native project
pnpm --filter @central/mobile exec cap sync android
```

Verify the identity took (it is set at `cap add` time):

- `apps/mobile/android/app/build.gradle` → `applicationId "com.midwesternog.learning"`
- `apps/mobile/android/app/src/main/res/values/strings.xml` → `<string name="app_name">Midwestern Learning</string>`

> If you change the profile, re-run from **step 2** with a clean project:
> `rm -rf apps/mobile/android` then `cap add android` again — identity is only
> written on `cap add`, not on `cap sync`.

## 4. Version

The regenerated project starts at `versionCode 1` / `versionName "1.0"`. For a
real release, edit `apps/mobile/android/app/build.gradle` `defaultConfig`:

```gradle
versionCode 1          // an integer; increment on EVERY Play upload
versionName "1.0.0"    // the human version shown in the store
```

## 5. Signing (release)

Generate an **upload keystore once** and keep it safe off the build machine —
losing it means you can never update the app again:

```bash
keytool -genkey -v -keystore midwestern-upload.jks \
  -alias midwestern -keyalg RSA -keysize 2048 -validity 10000
```

Create `apps/mobile/android/key.properties` (do **not** commit it):

```properties
storeFile=/absolute/path/to/midwestern-upload.jks
storePassword=********
keyAlias=midwestern
keyPassword=********
```

Because the native project is regenerated, add the signing wiring to
`apps/mobile/android/app/build.gradle` after `cap add` (top of file):

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

## 6. Produce the artifact

```bash
cd apps/mobile/android
./gradlew bundleRelease     # AAB (Play Store) -> app/build/outputs/bundle/release/app-release.aab
./gradlew assembleRelease   # APK (direct install) -> app/build/outputs/apk/release/app-release.apk
```

## 7. REQUIRED API-side change — CORS

The app uses Angular `HttpClient` (XHR), so every API call is subject to CORS.
The Capacitor **Android** WebView origin is `https://localhost` (iOS is
`capacitor://localhost`). Add them to the Midwestern API's
`CORS_ALLOWED_ORIGINS` and reload php-fpm — otherwise sign-in and every request
fail from the app even though the web works:

```
CORS_ALLOWED_ORIGINS=https://midwesternlearning.midwesternog.com,https://localhost,capacitor://localhost
```

## 8. Distribute

- **Play Store:** create the app in Google Play Console (Midwestern's Google
  account), upload `app-release.aab`, complete the listing and data-safety form,
  roll out to a track. Account + submission are Midwestern's to do.
- **Direct / internal:** hand out the signed `.apk`, or use Play **Internal
  testing** or Firebase **App Distribution** for testers.

## iOS (reference)

Needs **macOS + Xcode** — not buildable on Windows/Linux. Same profile env vars,
then `cap add ios`, open in Xcode, set the bundle id to `CAP_APP_ID`, choose the
signing team, archive, and upload to App Store Connect. Add
`capacitor://localhost` to the API CORS list (step 7) for the iOS origin.
