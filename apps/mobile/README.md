# @central/mobile

The Central LMS as an installable mobile app.

This is a thin [Capacitor](https://capacitorjs.com) shell around **@central/web**.
It bundles the web app's production build inside the native package and runs it
in the device WebView; the app talks to the production API over HTTPS, and the
tenant is resolved from whoever signs in — there is no subdomain on a device and
none is needed. Keeping the web app as the single source means every screen the
browser has, the app has, with no second UI to maintain.

- **App id:** `com.centrallms.app`
- **App name:** Central LMS
- **What ships in it:** `apps/web/dist/central/browser` (see `webDir` in
  `capacitor.config.ts`)
- **API it talks to:** whatever `apps/web/src/environments/environment.ts` sets
  for a production build (`https://api.centrallms.com/v1`)

## The native projects are generated, not committed

`android/` and `ios/` are build artefacts (the repo's root `.gitignore` excludes
them). They are recreated from this package's config, so after cloning you
generate them once:

```bash
pnpm install
pnpm --filter @central/mobile exec cap add android   # needs the Android SDK to later build
pnpm --filter @central/mobile exec cap add ios        # needs macOS + Xcode to later build
```

## Build & open

From the repo root:

```bash
# Builds @central/web and copies it into the native projects
pnpm --filter @central/mobile build

# Open the native project in its IDE to run on a device/emulator or make a release
pnpm --filter @central/mobile exec cap open android   # Android Studio
pnpm --filter @central/mobile exec cap open ios        # Xcode (macOS only)
```

`pnpm --filter @central/mobile exec cap sync` re-copies the web build and native
plugins without a full rebuild. `... cap doctor` checks the toolchain.

## Tooling you need to produce a build

Scaffolding, config and the web bundle are all cross-platform, but the final
native build is not:

- **Android:** JDK + the Android SDK / Android Studio. `ANDROID_HOME` must point
  at the SDK. Then build/run from Android Studio, or `cap run android`.
- **iOS:** macOS with Xcode (and CocoaPods). iOS cannot be built on Windows or
  Linux.

## Native niceties

The web app wires these up when it detects it is running natively (see
`apps/web/src/app/core/mobile/native.ts`, a no-op in a browser): the status bar
is matched to the light chrome, the Android hardware-back button follows the
router's history (only leaving the app when there is nowhere left to go), and
the launch splash is dismissed once Angular has started.

## Not done yet

- **App icon & splash art** use Capacitor's defaults. To brand them, add
  `@capacitor/assets` back as a dev dependency (approve its `sharp` build in
  `pnpm-workspace.yaml`), drop a source icon/splash in `assets/`, and run
  `capacitor-assets generate`.
- **Push notifications, deep links, live-updates** are not configured.
