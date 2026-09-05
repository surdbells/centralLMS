import { Capacitor } from '@capacitor/core';

/**
 * Native shell wiring, run once from the root component. A no-op in a browser
 * (every call is behind isNativePlatform), with plugins imported lazily.
 */
export async function initNative(): Promise<void> {
  if (!Capacitor.isNativePlatform()) {
    return;
  }

  const [{ SplashScreen }, { StatusBar, Style }] = await Promise.all([
    import('@capacitor/splash-screen'),
    import('@capacitor/status-bar'),
  ]);

  try {
    await StatusBar.setStyle({ style: Style.Light });
    if (Capacitor.getPlatform() === 'android') {
      await StatusBar.setBackgroundColor({ color: '#ffffff' });
    }
  } catch {
    /* a shell without a status bar is not a startup failure */
  }

  void SplashScreen.hide();
}
