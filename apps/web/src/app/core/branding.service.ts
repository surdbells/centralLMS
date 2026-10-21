import { Injectable, effect, inject, signal } from '@angular/core';
import { ApiClient, Endpoints } from './api';
import { SessionStore } from './auth/session.store';

/** The branding half of `GET /v1/settings/public`. */
interface PublicBranding {
  organisation: string | null;
  branding: Partial<Record<string, string | boolean>>;
}

/**
 * Paints the application in the organisation's own colours, logo and favicon —
 * and its name into the browser tab.
 *
 * Every one of these settings has been in the catalogue since it was written,
 * `primary_color` says in as many words that it is honoured by "the web app
 * theme", and the web app honoured none of them: each stylesheet declared its
 * own `$primary` — a different one per screen — so an organisation could
 * choose a colour, save it, see it saved, and watch nothing change anywhere.
 *
 * The colours become custom properties on the root element, which is what
 * makes them usable from a stylesheet compiled long before anybody signed in.
 * `styles.scss` maps Bootstrap's primary onto `--brand-primary`, so a saved
 * colour reaches every button, link and accent at once. The logo and
 * organisation name are exposed as signals for the navigation bars to read;
 * the favicon is swapped directly.
 *
 * **No flash of the default look.** Painting only once the API answers meant a
 * corporate saw the neutral product defaults first, then its own brand — poor
 * for a white-labelled deployment. Two things stop that: the last-applied
 * branding is cached in `localStorage` and re-painted synchronously in the
 * constructor, before the first paint, so a returning visitor never sees the
 * default; and fresh public branding is fetched at construction for the
 * remembered tenant (or the deployment's default), so even a cold first load of
 * any screen brands itself without waiting for that screen to ask.
 */
@Injectable({ providedIn: 'root' })
export class BrandingService {
  private readonly api = inject(ApiClient);
  private readonly session = inject(SessionStore);

  /** The organisation's logo, or null to fall back to the default mark. */
  readonly logoUrl = signal<string | null>(null);
  readonly organisation = signal<string | null>(null);
  /** The sign-in background image, or null to fall back to the default. */
  readonly loginBackgroundUrl = signal<string | null>(null);

  /**
   * The current route's own title (set by {@see BrandingTitleStrategy}), which
   * is composed with the organisation name into the tab title below.
   */
  readonly pageTitle = signal<string | null>(null);

  /** The tenant whose branding is currently applied, so it is fetched once. */
  private applied: string | null = null;

  /**
   * The tab title to wear when no organisation is known yet — whatever the
   * (build-time-injected) index.html shipped, captured before anything changes
   * it.
   */
  private readonly defaultTitle = typeof document !== 'undefined' ? document.title : '';

  /**
   * Where the last-branded tenant is remembered, so the sign-in screen — which
   * has no session and no tenant of its own — can still wear the organisation's
   * logo on the way back in.
   */
  static readonly LAST_TENANT_KEY = 'central.brandTenant';

  /** Where the last-applied branding payload is cached, to paint it instantly on the next load. */
  static readonly BRAND_CACHE_KEY = 'central.brand';

  constructor() {
    // 1. Repaint the last-known branding immediately — before the first paint —
    //    so a returning visitor never sees the neutral default flash.
    this.applyCached();

    // 2. Fetch fresh public branding for the remembered tenant (or, with none,
    //    the deployment default resolved server-side from DEFAULT_TENANT_ID),
    //    so a cold load of any page is branded without a screen having to ask.
    //    This does not claim `applied`, so a session naming the real tenant
    //    still supersedes it below.
    this.load(this.rememberedTenant());

    // 3. Signing in, signing out and switching organisation all move this.
    effect(() => {
      const tenantId = this.session.session()?.tenantId ?? null;

      if (tenantId === null || tenantId === this.applied) {
        return;
      }

      this.applied = tenantId;
      this.remember(tenantId);
      this.load(tenantId);
    });

    // 4. Keep the tab title in step with the organisation and the current page,
    //    whichever changes — a late-arriving brand updates an already-shown page.
    effect(() => {
      const appName = this.organisation()?.trim() || this.defaultTitle;
      const page = this.pageTitle()?.trim();
      document.title = page ? `${page} · ${appName}` : appName;
    });
  }

  /**
   * Applies a public-settings payload directly — for the sign-in screen, which
   * fetches branding itself (it has no session for the effect above to watch)
   * but still wants the colours, logo and favicon painted.
   */
  apply(organisation: string | null, branding: Partial<Record<string, string | boolean>>): void {
    this.organisation.set(organisation);
    this.paint(branding);
    this.cache(organisation, branding);
  }

  private load(tenantId: string): void {
    const params = this.isTenant(tenantId) ? { tenantId } : {};

    this.api.get<PublicBranding>(Endpoints.settings.public, params).subscribe({
      next: (settings) => {
        this.organisation.set(settings.organisation);
        this.paint(settings.branding);
        this.cache(settings.organisation, settings.branding);
      },
      // Silently: an organisation that has set no colours looks exactly like
      // one whose settings failed to load, and the defaults are perfectly
      // usable. A toast here would fire on every page load of every tenant
      // that has never opened the branding tab.
      error: () => undefined,
    });
  }

  /** Re-paints whatever branding was last cached, synchronously, so it is on screen before the first API answer. */
  private applyCached(): void {
    try {
      const raw = localStorage.getItem(BrandingService.BRAND_CACHE_KEY);

      if (raw === null) {
        return;
      }

      const cached = JSON.parse(raw) as PublicBranding;
      this.organisation.set(cached.organisation ?? null);
      this.paint(cached.branding ?? {});
    } catch {
      // Corrupt or unavailable storage — the defaults are fine.
    }
  }

  private cache(organisation: string | null, branding: Partial<Record<string, string | boolean>>): void {
    try {
      localStorage.setItem(
        BrandingService.BRAND_CACHE_KEY,
        JSON.stringify({ organisation, branding }),
      );
    } catch {
      // Private mode or storage disabled — only the next load's flash returns.
    }
  }

  private rememberedTenant(): string {
    try {
      return localStorage.getItem(BrandingService.LAST_TENANT_KEY) ?? '';
    } catch {
      return '';
    }
  }

  private remember(tenantId: string): void {
    try {
      localStorage.setItem(BrandingService.LAST_TENANT_KEY, tenantId);
    } catch {
      // Private mode or storage disabled — the logo simply falls back.
    }
  }

  private isTenant(tenantId: string): boolean {
    return /^[0-9a-f-]{36}$/i.test(tenantId);
  }

  private paint(branding: Partial<Record<string, string | boolean>>): void {
    const root = document.documentElement;

    const colours: Record<string, string> = {
      '--brand-primary': 'primary_color',
      '--brand-secondary': 'secondary_color',
      '--brand-accent': 'accent_color',
    };

    for (const [property, key] of Object.entries(colours)) {
      const value = branding[key];

      // Only a colour we recognise. The value reaches a stylesheet, and a
      // setting is text somebody typed.
      if (typeof value === 'string' && /^#[0-9a-f]{3,8}$/i.test(value.trim())) {
        const hex = value.trim();
        root.style.setProperty(property, hex);
        // The RGB triplet lets a stylesheet build translucent tints of the
        // brand colour — focus rings, hovers — with rgba(var(--…-rgb), a).
        const rgb = this.toRgb(hex);
        if (rgb !== null) {
          root.style.setProperty(`${property}-rgb`, rgb);
        }
      }
    }

    const logo = branding['logo_url'];
    this.logoUrl.set(typeof logo === 'string' && logo.trim() !== '' ? logo.trim() : null);

    const loginBg = branding['login_background_url'];
    this.loginBackgroundUrl.set(
      typeof loginBg === 'string' && loginBg.trim() !== '' ? loginBg.trim() : null,
    );

    const favicon = branding['favicon_url'];

    if (typeof favicon === 'string' && favicon.trim() !== '') {
      this.setFavicon(favicon.trim());
    }
  }

  /** "#2400a0" → "36, 0, 160"; handles the #rgb short form. Null if unparsable. */
  private toRgb(hex: string): string | null {
    let h = hex.replace('#', '');
    if (h.length === 3) {
      h = h.split('').map((c) => c + c).join('');
    }
    if (h.length < 6) {
      return null;
    }
    const r = parseInt(h.slice(0, 2), 16);
    const g = parseInt(h.slice(2, 4), 16);
    const b = parseInt(h.slice(4, 6), 16);
    return [r, g, b].some(Number.isNaN) ? null : `${r}, ${g}, ${b}`;
  }

  private setFavicon(href: string): void {
    const link = document.querySelector<HTMLLinkElement>('link[rel~="icon"]')
      ?? document.head.appendChild(Object.assign(document.createElement('link'), { rel: 'icon' }));

    link.href = href;
  }
}
