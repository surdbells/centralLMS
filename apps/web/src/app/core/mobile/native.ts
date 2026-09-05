import { Capacitor } from '@capacitor/core';

/**
 * Wake the app up inside the native shell.
 *
 * A no-op in a browser: everything is behind {@link Capacitor.isNativePlatform},
 * and the plugins are imported lazily so a plain web build never pulls them in.
 * Run once, from an app initializer.
 *
 * What it does on a device: matches the status bar to the app's light chrome,
 * hands the Android hardware-back button to the router's own history (only
 * leaving the app when there is nowhere left to go back to), and drops the
 * launch splash now that Angular has started.
 */
export async function initNative(): Promise<void> {
  if (!Capacitor.isNativePlatform()) {
    return;
  }

  const [{ SplashScreen }, { StatusBar, Style }, { App }] = await Promise.all([
    import('@capacitor/splash-screen'),
    import('@capacitor/status-bar'),
    import('@capacitor/app'),
  ]);

  // Dark text on the light bar; not every platform/shell supports every call,
  // so each is guarded rather than allowed to reject the whole initializer.
  try {
    await StatusBar.setStyle({ style: Style.Light });
    if (Capacitor.getPlatform() === 'android') {
      await StatusBar.setBackgroundColor({ color: '#ffffff' });
    }
  } catch {
    // A shell that doesn't implement the status bar is not a startup failure.
  }

  try {
    await App.addListener('backButton', ({ canGoBack }) => {
      if (canGoBack) {
        window.history.back();
      } else {
        void App.exitApp();
      }
    });
  } catch {
    // Same: a missing App plugin must not stop the app from starting.
  }

  // The app is ready to paint; fire-and-forget so the fade doesn't hold up boot.
  void SplashScreen.hide();
}
