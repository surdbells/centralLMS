import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';
import type { SignedIn } from '../core/auth/auth.service';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export type SsoProviderId = 'google' | 'microsoft';

/** Enough to draw a button, and nothing else. */
export interface SsoProviderOption {
  provider: SsoProviderId;
  label: string;
}

/**
 * One organisation's arrangement with one directory, as an administrator
 * sees it.
 *
 * There is no `clientSecret`. No endpoint returns one — an administrator who
 * has lost theirs gets a new one from the provider, which is what the
 * provider's own console tells them to do. `hasSecret` says whether one is
 * stored, which is all a form needs to know.
 */
export interface SsoConnection {
  id: string | null;
  provider: SsoProviderId;
  providerLabel: string;
  enabled: boolean;
  clientId: string;
  hasSecret: boolean;
  /** Entra only: which directory to authenticate against. */
  directory: string | null;
  domains: string[];
  autoProvision: boolean;
}

export interface SsoConnectionInput {
  clientId: string;
  /** Left out to keep the secret already stored. */
  clientSecret?: string;
  enabled: boolean;
  directory?: string | null;
  domains?: string;
  autoProvision?: boolean;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * Signing in from Google or Microsoft Entra — which is what "Active
 * Directory" means once it is reached over the internet.
 *
 * Both are OpenID Connect, so there is one of everything with the provider as
 * a parameter rather than two implementations.
 */
@Injectable({ providedIn: 'root' })
export class SsoService {
  private readonly api = inject(ApiClient);

  /** Which directories an organisation accepts. Public. */
  providers(tenantId: string): Observable<SsoProviderOption[]> {
    return this.api.get<SsoProviderOption[]>(Endpoints.sso.providers(tenantId));
  }

  /**
   * Where to send somebody to be identified.
   *
   * The state that ties the round trip together is written down server-side
   * before this returns, so nothing here has to be trusted to keep it.
   */
  start(
    tenantId: string,
    provider: SsoProviderId,
    redirectTo?: string,
  ): Observable<string> {
    return this.api
      .post<{ url: string }>(Endpoints.sso.start, { tenantId, provider, redirectTo })
      .pipe(map((result) => result.url));
  }

  /**
   * Hands back what the provider returned, and gets a session for it.
   *
   * The same session a password sign-in produces — same tokens, same refresh
   * record. How somebody proved who they are is not a reason to have a second
   * kind of session.
   */
  complete(code: string, state: string): Observable<SignedIn> {
    return this.api.post<SignedIn>(Endpoints.sso.callback, { code, state });
  }

  // --- administering it -------------------------------------------------

  connections(): Observable<SsoConnection[]> {
    return this.api.get<SsoConnection[]>(Endpoints.sso.list);
  }

  save(provider: SsoProviderId, input: SsoConnectionInput): Observable<SsoConnection> {
    return this.api.put<SsoConnection>(Endpoints.sso.save(provider), input);
  }

  remove(provider: SsoProviderId): Observable<void> {
    return this.api.delete<void>(Endpoints.sso.delete(provider)).pipe(map(() => undefined));
  }
}
