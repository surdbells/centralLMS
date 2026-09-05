import type { CapacitorConfig } from '@capacitor/cli';

/**
 * The mobile app is the tenant web app in a native shell.
 *
 * It bundles the built @central/web app (webDir below) and runs it offline-
 * capable inside the WebView; the app talks to the production API over HTTPS,
 * and the tenant is resolved from whoever signs in — there is no subdomain on a
 * device, and none is needed. Keeping the web app as the single source means
 * every screen the browser has, the app has, with no second UI to maintain.
 */
const config: CapacitorConfig = {
  appId: 'com.centrallms.app',
  appName: 'Central LMS',
  // The Angular build output. `pnpm build` (in this package) builds the web app
  // and then `cap sync` copies this directory into the native projects.
  webDir: '../web/dist/central/browser',
  plugins: {
    SplashScreen: {
      // The app hides this as soon as Angular has bootstrapped (see the web
      // app's native initializer), for a snappy start. autoHide stays on as a
      // safety net with a short cap, so a JS failure early in boot can never
      // leave the splash stuck on screen.
      launchAutoHide: true,
      launchShowDuration: 1500,
      backgroundColor: '#ffffff',
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
