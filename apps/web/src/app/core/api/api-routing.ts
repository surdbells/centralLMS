import { Injectable, inject, InjectionToken } from '@angular/core';
import { environment } from '../../../environments/environment';
import type { AppEnvironment } from '../../../environments/environment.model';

/**
 * Injected environment, so tests can supply their own without touching the
 * real environment files.
 */
export const APP_ENVIRONMENT = new InjectionToken<AppEnvironment>('APP_ENVIRONMENT', {
  providedIn: 'root',
  factory: () => environment,
});

/**
 * Builds absolute URLs from the paths in {@link Endpoints}.
 *
 * This was the strangler switchboard: it resolved a module to whichever of two
 * backends currently served it, so a module could migrate without touching a
 * caller. Every module has now moved, so there is one base URL and the routing
 * decision is gone — what remains is the join, which is worth keeping in one
 * place because the original `GlobalComponent` got it wrong twice, producing
 * `...comutility/shared/user` and `.../shared//mark_notifications`.
 */
@Injectable({ providedIn: 'root' })
export class ApiRouter {
  private readonly env = inject(APP_ENVIRONMENT);

  get baseUrl(): string {
    return this.env.api.baseUrl.replace(/\/+$/, '');
  }

  /**
   * Absolute URL for an API path.
   *
   * Joining is normalised, so a missing or doubled slash cannot produce a
   * broken URL.
   */
  url(path: string): string {
    const suffix = path.replace(/^\/+/, '').replace(/\/{2,}/g, '/');

    return suffix === '' ? this.baseUrl : `${this.baseUrl}/${suffix}`;
  }
}
