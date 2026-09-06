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

  // Keep the status bar in step with the app's theme: a light bar with dark
  // icons in Studio, a deep-indigo bar with light icons in Bold. Style.Light =
  // dark text (for light backgrounds), Style.Dark = light text (for dark ones).
  const isAndroid = Capacitor.getPlatform() === 'android';
  const paintStatusBar = async (): Promise<void> => {
    try {
      const dark = effectiveDark();
      await StatusBar.setStyle({ style: dark ? Style.Dark : Style.Light });
      if (isAndroid) {
        await StatusBar.setBackgroundColor({ color: dark ? '#0e0b1f' : '#ffffff' });
      }
    } catch {
      /* a shell without a status bar is not a startup failure */
    }
  };

  await paintStatusBar();

  // Re-paint when the in-app theme toggle stamps `data-theme`, or the OS scheme
  // flips — the same effective-theme signal BrandingService follows.
  new MutationObserver(() => void paintStatusBar())
    .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
  window.matchMedia?.('(prefers-color-scheme: dark)')
    .addEventListener?.('change', () => void paintStatusBar());

  void SplashScreen.hide();
}

/** The theme actually in effect: the in-app choice (`data-theme`) wins, else the OS. */
function effectiveDark(): boolean {
  const attr = document.documentElement.getAttribute('data-theme');
  if (attr === 'dark') {
    return true;
  }
  if (attr === 'light') {
    return false;
  }
  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;
}
