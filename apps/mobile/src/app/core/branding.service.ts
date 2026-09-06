import { Injectable, computed, effect, inject, signal } from '@angular/core';
import { Observable, catchError, map, of } from 'rxjs';
import { ApiClient } from './api';
import { SessionStore } from './session';

interface PublicBranding {
  /** The resolved tenant — null when a code named no organisation, or none is set. */
  tenantId: string | null;
  organisation: string | null;
  branding: Partial<Record<string, string | boolean>>;
}

interface StoredBrand {
  tenantId: string | null;
  /** The institution code the learner typed, so the brand refreshes on relaunch. */
  code: string | null;
  organisation: string | null;
  logoUrl: string | null;
  loginBackgroundUrl: string | null;
  primary: string | null;
  accent: string | null;
  secondary: string | null;
}

const STORAGE_KEY = 'central.mobile.brand';

/** The Ionic accent family we paint per-tenant — cleared to fall back to defaults. */
const PAINTED = [
  '--ion-color-primary', '--ion-color-primary-rgb', '--ion-color-primary-shade',
  '--ion-color-primary-tint', '--ion-color-primary-contrast', '--brand-primary', '--brand-primary-shade',
];

const BLANK: StoredBrand = {
  tenantId: null, code: null, organisation: null, logoUrl: null,
  loginBackgroundUrl: null, primary: null, accent: null, secondary: null,
};

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

  /** The tenant the app is currently wearing (from a code, a default, or a session). */
  readonly tenantId = signal<string | null>(null);
  /** The institution code that resolved this tenant, if the learner named one. */
  readonly code = signal<string | null>(null);
  /** True once we know the tenant — either resolved, defaulted, or confirmed absent. */
  readonly resolved = signal(false);
  /** Whether an organisation is in hand; when false, the app needs an institution code. */
  readonly hasTenant = computed(() => this.tenantId() !== null);

  private applied: string | null = null;
  private markReady!: () => void;
  private readonly readyPromise = new Promise<void>((resolve) => (this.markReady = resolve));

  constructor() {
    // Paint the remembered brand immediately, so sign-in is already branded.
    this.applyStored();

    // Signed out (the sign-in screen): refresh the brand. If the learner has
    // already named their institution, ask by that code; otherwise ask for the
    // deployment's default (on-prem/single-tenant returns it, a multi-tenant
    // cloud with no default returns nothing — which sends them to name one).
    if (!this.session.session()) {
      const code = this.code();
      this.load(code ? { code } : undefined);
    } else {
      this.ready();
    }

    // Once a session's tenant is known (or changes), fetch it fresh.
    effect(() => {
      const tenantId = this.session.session()?.tenantId ?? null;
      if (!tenantId || tenantId === this.applied) {
        return;
      }
      this.applied = tenantId;
      this.load({ tenantId });
    });

    // Re-tint when the effective theme changes so the brand accent stays
    // readable on either surface — both when the OS scheme flips…
    window.matchMedia?.('(prefers-color-scheme: dark)')
      .addEventListener?.('change', () => this.paintColours(this.primary(), this.accent()));
    // …and when the in-app theme toggle stamps `data-theme` on the root, which
    // overrides the OS scheme (a dark choice under a light OS, or vice-versa).
    new MutationObserver(() => this.paintColours(this.primary(), this.accent()))
      .observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
  }

  /**
   * The effective dark state: the in-app theme toggle wins (it stamps
   * `data-theme` on the root), and with no explicit choice we follow the OS.
   */
  private isDark(): boolean {
    const attr = document.documentElement.getAttribute('data-theme');
    if (attr === 'dark') {
      return true;
    }
    if (attr === 'light') {
      return false;
    }
    return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;
  }

  /**
   * Resolves an institution from the code a learner typed, wearing its brand on
   * success. `true` when the code named an organisation, `false` when it named
   * none (unknown code) or the request failed — the screen keeps them there to
   * try again.
   */
  resolveByCode(code: string): Observable<boolean> {
    const trimmed = code.trim();
    if (!trimmed) {
      return of(false);
    }
    return this.api.get<PublicBranding>('/settings/public', { code: trimmed }).pipe(
      map((s) => {
        if (!s.tenantId) {
          return false;
        }
        this.set(s, trimmed);
        return true;
      }),
      catchError(() => of(false)),
    );
  }

  /** Forget the chosen institution — "not your organisation?" — back to a blank slate. */
  clearTenant(): void {
    this.applyBrand(BLANK);
    try {
      localStorage.removeItem(STORAGE_KEY);
    } catch {
      /* the in-memory reset still stands */
    }
  }

  /** Resolves once the first public-settings load has settled, for route guards. */
  whenReady(): Promise<void> {
    return this.readyPromise;
  }

  private ready(): void {
    if (!this.resolved()) {
      this.resolved.set(true);
      this.markReady();
    }
  }

  /** Fetch public settings by code, by tenant id, or — with neither — the default. */
  private load(by?: { tenantId?: string; code?: string }): void {
    const params = by?.code ? { code: by.code } : by?.tenantId ? { tenantId: by.tenantId } : undefined;
    this.api.get<PublicBranding>('/settings/public', params).subscribe({
      next: (s) => {
        this.set(s, by?.code ?? this.code());
        this.ready();
      },
      // Silent: an org that set no branding looks the same as one whose fetch
      // failed, and the defaults are perfectly usable. Still "ready" — the
      // guards must not hang on a flaky network.
      error: () => this.ready(),
    });
  }

  private set(s: PublicBranding, code: string | null): void {
    const branding = s.branding ?? {};
    const str = (key: string): string | null => {
      const v = branding[key];
      return typeof v === 'string' && v.trim() !== '' ? v.trim() : null;
    };
    const colour = (key: string): string | null => {
      const v = str(key);
      return v && /^#[0-9a-f]{3,8}$/i.test(v) ? v : null;
    };

    const brand: StoredBrand = {
      tenantId: s.tenantId ?? null,
      // Only remember the code once it actually named a tenant.
      code: s.tenantId ? code : null,
      organisation: s.organisation,
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
        this.applyBrand({ ...BLANK, ...(JSON.parse(raw) as Partial<StoredBrand>) });
      }
    } catch {
      /* no stored brand, or unreadable — fall back to the defaults */
    }
  }

  private applyBrand(brand: StoredBrand): void {
    this.tenantId.set(brand.tenantId);
    this.code.set(brand.code);
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
      // A dark brand colour reads well as a hero background but poorly as an
      // accent on a dark surface, so the Ionic accent is lightened in dark mode
      // while the raw colour is kept for the hero gradients.
      const ui = this.isDark() ? mix(primary, '#ffffff', 0.34) : primary;

      root.style.setProperty('--ion-color-primary', ui);
      root.style.setProperty('--ion-color-primary-rgb', toRgb(ui) ?? '51, 22, 166');
      root.style.setProperty('--ion-color-primary-shade', mix(ui, '#000000', 0.12));
      root.style.setProperty('--ion-color-primary-tint', mix(ui, '#ffffff', 0.1));
      root.style.setProperty('--ion-color-primary-contrast', contrastOf(ui));
      // The app's own gradients and hero fields read the raw colour.
      root.style.setProperty('--brand-primary', primary);
      root.style.setProperty('--brand-primary-shade', mix(primary, '#000000', 0.28));
    } else {
      // No brand colour (cleared, or an org that set none) — drop the overrides
      // so the product's own default accent from the stylesheet shows through.
      for (const property of PAINTED) {
        root.style.removeProperty(property);
      }
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
