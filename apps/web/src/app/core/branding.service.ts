import { Injectable, effect, inject, signal } from '@angular/core';
import { ApiClient, Endpoints } from './api';
import { SessionStore } from './auth/session.store';

/** The branding half of `GET /v1/settings/public`. */
interface PublicBranding {
  organisation: string | null;
  branding: Partial<Record<string, string | boolean>>;
}

/**
 * Paints the application in the organisation's own colours, logo and favicon.
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
 */
@Injectable({ providedIn: 'root' })
export class BrandingService {
  private readonly api = inject(ApiClient);
  private readonly session = inject(SessionStore);

  /** The organisation's logo, or null to fall back to the default mark. */
  readonly logoUrl = signal<string | null>(null);
  readonly organisation = signal<string | null>(null);

  /** The tenant whose branding is currently applied, so it is fetched once. */
  private applied: string | null = null;

  constructor() {
    // Signing in, signing out and switching organisation all move this.
    effect(() => {
      const tenantId = this.session.session()?.tenantId ?? null;

      if (tenantId === null || tenantId === this.applied) {
        return;
      }

      this.applied = tenantId;
      this.load(tenantId);
    });
  }

  private load(tenantId: string): void {
    this.api.get<PublicBranding>(Endpoints.settings.public, { tenantId }).subscribe({
      next: (settings) => {
        this.organisation.set(settings.organisation);
        this.paint(settings.branding);
      },
      // Silently: an organisation that has set no colours looks exactly like
      // one whose settings failed to load, and the defaults are perfectly
      // usable. A toast here would fire on every page load of every tenant
      // that has never opened the branding tab.
      error: () => undefined,
    });
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
