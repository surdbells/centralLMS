import type { CapacitorConfig } from '@capacitor/cli';

/**
 * The Central LMS learner app in a native shell.
 *
 * It bundles this package's own Ionic + Angular build (webDir below) and runs
 * it inside the WebView. The app talks to the production API over HTTPS, and the
 * tenant is resolved from whoever signs in — there is no subdomain on a device,
 * and none is needed.
 *
 * **Per-client builds.** The identity below is overridable by build-time
 * environment variables so one repo ships a differently-named, separately-
 * installable app per client — the same build-time-injection idea as the web's
 * scripts/set-env.mjs. `cap add`/`cap sync` bake these into the generated
 * (git-ignored) native project, so they must be set before those run. With
 * nothing set, the generic Central LMS identity is used. See
 * docs/mobile-android-build.md for the Midwestern profile.
 */
const brandColor = process.env.BRAND_COLOR ?? '#3316a6';

const config: CapacitorConfig = {
  appId: process.env.CAP_APP_ID ?? 'com.centrallms.app',
  appName: process.env.CAP_APP_NAME ?? 'Central LMS',
  // This package's Angular build output (see angular.json). `pnpm run mobile`
  // builds it and then `cap sync` copies it into the native projects.
  webDir: 'www',
  plugins: {
    SplashScreen: {
      // The app hides this as soon as Angular has bootstrapped (see the app's
      // native initializer), for a snappy start. autoHide stays on as a safety
      // net with a short cap, so a JS failure early in boot can never leave the
      // splash stuck on screen. The brand indigo matches the generated splash
      // art so there is no flash of a different colour behind it.
      launchAutoHide: true,
      launchShowDuration: 1500,
      // Matches the generated splash art, so there is no flash of a different
      // colour behind it. Follows BRAND_COLOR for a per-client build.
      backgroundColor: brandColor,
      showSpinner: false,
      androidScaleType: 'CENTER_CROP',
    },
  },
  android: {
    // Reject cleartext: the app only ever talks to the HTTPS production API.
    allowMixedContent: false,
  },
};

export default config;
