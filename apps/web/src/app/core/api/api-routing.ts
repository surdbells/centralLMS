import { Injectable, inject, InjectionToken } from '@angular/core';
import { environment } from '../../../environments/environment';
import type { AppEnvironment } from '../../../environments/environment.model';
import { ApiBackend } from './api-backend';
import { ApiModule } from './api-module';
import { resolvePath, type EndpointPath } from './endpoint-path';

/**
 * Injected environment, so tests can supply their own without touching the
 * real environment files.
 */
export const APP_ENVIRONMENT = new InjectionToken<AppEnvironment>('APP_ENVIRONMENT', {
  providedIn: 'root',
  factory: () => environment,
});

/**
 * Resolves a module to the backend serving it, and builds absolute URLs.
 *
 * This is the strangler switchboard. Callers never see a base URL: they ask
 * for `/user/courses/list` in the `Courses` module and get whichever backend
 * currently owns that module.
 *
 * The default is deliberately {@link ApiBackend.Legacy} — a module that nobody
 * has migrated keeps working, and a newly added one cannot accidentally point
 * at an endpoint the new API has not built yet.
 */
@Injectable({ providedIn: 'root' })
export class ApiRouter {
  private readonly env = inject(APP_ENVIRONMENT);

  backendFor(module: ApiModule): ApiBackend {
    return this.env.moduleBackends[module] ?? ApiBackend.Legacy;
  }

  baseUrlFor(module: ApiModule): string {
    return this.backendFor(module) === ApiBackend.V1
      ? this.env.api.v1BaseUrl
      : this.env.api.legacyBaseUrl;
  }

  /**
   * Absolute URL for a module-relative path.
   *
   * Joining is normalised so a missing or doubled slash cannot produce a
   * broken URL — the old `GlobalComponent` had both bugs
   * (`...comutility/shared/user`, `.../shared//mark_notifications`).
   */
  url(module: ApiModule, path: EndpointPath): string {
    const base = this.baseUrlFor(module).replace(/\/+$/, '');
    // A handful of endpoints were renamed by the new API and declare both
    // spellings. Which one applies is a property of the module's backend, so
    // it is settled here rather than at every call site.
    const resolved = resolvePath(path, this.backendFor(module));
    const suffix = resolved.replace(/^\/+/, '').replace(/\/{2,}/g, '/');

    return suffix === '' ? base : `${base}/${suffix}`;
  }

  /** True when this module has been migrated to the new API. */
  isMigrated(module: ApiModule): boolean {
    return this.backendFor(module) === ApiBackend.V1;
  }

  /**
   * Every module and where it currently points. Useful in a diagnostics screen
   * and when reasoning about a partially-migrated deployment.
   */
  snapshot(): ReadonlyArray<{ module: ApiModule; backend: ApiBackend; baseUrl: string }> {
    return Object.values(ApiModule).map((module) => ({
      module,
      backend: this.backendFor(module),
      baseUrl: this.baseUrlFor(module),
    }));
  }
}
