import { Injectable, effect, inject, signal } from '@angular/core';
import { ApiClient } from './api';
import { SessionStore } from './session';

interface PublicBranding {
  organisation: string | null;
  branding: Partial<Record<string, string | boolean>>;
}

interface StoredBrand {
  organisation: string | null;
  logoUrl: string | null;
  loginBackgroundUrl: string | null;
  primary: string | null;
  accent: string | null;
  secondary: string | null;
}

const STORAGE_KEY = 'central.mobile.brand';

/**
 * Wears the organisation's identity — its name, logo and colours — the way the
 * web app does, from `GET /v1/settings/public`.
 *
 * The colours are pushed onto Ionic's own `--ion-color-primary` family so every
 * button, tab, ring and accent in the app turns the organisation's colour at
 * once. The name and logo are signals the screens read. The last organisation's
 * branding is remembered, so a returning user already sees it on the sign-in
 * screen — before any network call, and before we even know their tenant again.
 */
@Injectable({ providedIn: 'root' })
export class BrandingService {
  private readonly api = inject(ApiClient);
  private readonly session = inject(SessionStore);

  readonly organisation = signal<string | null>(null);
  readonly logoUrl = signal<string | null>(null);
  readonly loginBackgroundUrl = signal<string | null>(null);
  readonly primary = signal<string | null>(null);
  readonly accent = signal<string | null>(null);

  private applied: string | null = null;

  constructor() {
    // Paint the remembered brand immediately, so sign-in is already branded.
    this.applyStored();

    // Once a session's tenant is known (or changes), fetch it fresh.
    effect(() => {
      const tenantId = this.session.session()?.tenantId ?? null;
      if (!tenantId || tenantId === this.applied) {
        return;
      }
      this.applied = tenantId;
      this.load(tenantId);
    });
  }

  private load(tenantId: string): void {
    this.api.get<PublicBranding>('/settings/public', { tenantId }).subscribe({
      next: (s) => this.set(s.organisation, s.branding),
      // Silent: an org that set no branding looks the same as one whose fetch
      // failed, and the defaults are perfectly usable.
      error: () => undefined,
    });
  }

  private set(organisation: string | null, branding: Partial<Record<string, string | boolean>>): void {
    const str = (key: string): string | null => {
      const v = branding[key];
      return typeof v === 'string' && v.trim() !== '' ? v.trim() : null;
    };
    const colour = (key: string): string | null => {
      const v = str(key);
      return v && /^#[0-9a-f]{3,8}$/i.test(v) ? v : null;
    };

    const brand: StoredBrand = {
      organisation,
      logoUrl: str('logo_url'),
      loginBackgroundUrl: str('login_background_url'),
      primary: colour('primary_color'),
      accent: colour('accent_color'),
      secondary: colour('secondary_color'),
    };

    this.applyBrand(brand);
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(brand));
    } catch {
      /* storage may be unavailable; the in-memory paint still stands */
    }
  }

  private applyStored(): void {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      if (raw) {
        this.applyBrand(JSON.parse(raw) as StoredBrand);
      }
    } catch {
      /* no stored brand, or unreadable — fall back to the defaults */
    }
  }

  private applyBrand(brand: StoredBrand): void {
    this.organisation.set(brand.organisation);
    this.logoUrl.set(brand.logoUrl);
    this.loginBackgroundUrl.set(brand.loginBackgroundUrl);
    this.primary.set(brand.primary);
    this.accent.set(brand.accent);
    this.paintColours(brand.primary, brand.accent);
  }

  /** Push the brand primary onto Ionic's colour family so it reaches everything. */
  private paintColours(primary: string | null, accent: string | null): void {
    const root = document.documentElement;
    if (primary) {
      root.style.setProperty('--ion-color-primary', primary);
      root.style.setProperty('--ion-color-primary-rgb', toRgb(primary) ?? '51, 22, 166');
      root.style.setProperty('--ion-color-primary-shade', mix(primary, '#000000', 0.12));
      root.style.setProperty('--ion-color-primary-tint', mix(primary, '#ffffff', 0.1));
      root.style.setProperty('--ion-color-primary-contrast', contrastOf(primary));
      // The app's own gradients and hero fields read these.
      root.style.setProperty('--brand-primary', primary);
      root.style.setProperty('--brand-primary-shade', mix(primary, '#000000', 0.28));
    }
    root.style.setProperty('--brand-accent', accent ?? primary ?? '#2149e8');
  }
}

function toRgb(hex: string): string | null {
  const [r, g, b] = channels(hex);
  return [r, g, b].some(Number.isNaN) ? null : `${r}, ${g}, ${b}`;
}

function channels(hex: string): [number, number, number] {
  let h = hex.replace('#', '');
  if (h.length === 3) {
    h = h.split('').map((c) => c + c).join('');
  }
  return [parseInt(h.slice(0, 2), 16), parseInt(h.slice(2, 4), 16), parseInt(h.slice(4, 6), 16)];
}

/** Mix `hex` toward `towards` by `weight` (0..1), returning a hex string. */
function mix(hex: string, towards: string, weight: number): string {
  const a = channels(hex);
  const b = channels(towards);
  const c = a.map((v, i) => Math.round(v + (b[i] - v) * weight));
  return '#' + c.map((v) => Math.max(0, Math.min(255, v)).toString(16).padStart(2, '0')).join('');
}

/** White on a dark brand colour, near-black on a light one. */
function contrastOf(hex: string): string {
  const [r, g, b] = channels(hex);
  const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  return luminance < 0.6 ? '#ffffff' : '#10131c';
}
