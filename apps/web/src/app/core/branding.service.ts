import { Injectable, effect, inject } from '@angular/core';
import { ApiClient, Endpoints } from './api';
import { SessionStore } from './auth/session.store';

/** The branding half of `GET /v1/settings/public`. */
interface PublicBranding {
  organisation: string | null;
  branding: Partial<Record<string, string | boolean>>;
}

/**
 * Paints the application in the organisation's own colours.
 *
 * Every one of these settings has been in the catalogue since it was written,
 * `primary_color` says in as many words that it is honoured by "the web app
 * theme", and the web app honoured none of them: each stylesheet declared its
 * own `$primary` — a different one per screen — so an organisation could
 * choose a colour, save it, see it saved, and watch nothing change anywhere.
 *
 * The colours become custom properties on the root element, which is what
 * makes them usable from a stylesheet compiled long before anybody signed in.
 * Screens that want the organisation's colour say
 * `var(--brand-primary, #303234)`; the fallback keeps them looking right
 * before the settings arrive and in a test with no server.
 */
@Injectable({ providedIn: 'root' })
export class BrandingService {
  private readonly api = inject(ApiClient);
  private readonly session = inject(SessionStore);

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
      next: (settings) => this.paint(settings.branding),
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
        root.style.setProperty(property, value.trim());
      }
    }

    const favicon = branding['favicon_url'];

    if (typeof favicon === 'string' && favicon.trim() !== '') {
      this.setFavicon(favicon.trim());
    }
  }

  private setFavicon(href: string): void {
    const link = document.querySelector<HTMLLinkElement>('link[rel~="icon"]')
      ?? document.head.appendChild(Object.assign(document.createElement('link'), { rel: 'icon' }));

    link.href = href;
  }
}
