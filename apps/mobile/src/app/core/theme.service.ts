import { Injectable, signal } from '@angular/core';

export type ThemeMode = 'system' | 'light' | 'dark';

const STORAGE_KEY = 'central.mobile.theme';

/**
 * Chooses which face of the design system the app wears — Studio (light) or
 * Bold (dark) — by stamping `data-theme` on the document root, which the
 * `variables.scss` cascade keys off. `system` removes the attribute and lets
 * `prefers-color-scheme` decide, so the three states are honest: an explicit
 * light choice beats a dark OS, and vice-versa.
 */
@Injectable({ providedIn: 'root' })
export class ThemeService {
  readonly mode = signal<ThemeMode>(read());

  constructor() {
    this.paint(this.mode());
  }

  set(mode: ThemeMode): void {
    this.mode.set(mode);
    try {
      localStorage.setItem(STORAGE_KEY, mode);
    } catch {
      /* a storage failure must never break the toggle */
    }
    this.paint(mode);
  }

  private paint(mode: ThemeMode): void {
    const root = document.documentElement;
    if (mode === 'system') {
      root.removeAttribute('data-theme');
    } else {
      root.setAttribute('data-theme', mode);
    }
  }
}

function read(): ThemeMode {
  try {
    const stored = localStorage.getItem(STORAGE_KEY);
    if (stored === 'light' || stored === 'dark' || stored === 'system') {
      return stored;
    }
  } catch {
    /* fall through to the default */
  }
  return 'system';
}
