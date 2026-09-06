import { Injectable, inject } from '@angular/core';
import { Observable, map, of, tap } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { ApiClient, ApiError } from './api';
import { SessionStore } from './session';
import { LoginPayload } from './models';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly api = inject(ApiClient);
  private readonly store = inject(SessionStore);

  /**
   * Signs in and stores the session. On a device there is no subdomain, so when
   * the learner has named their institution the tenant rides along in the body
   * — the same context the web app takes from the host — letting the same email
   * hold accounts in more than one organisation.
   */
  login(email: string, password: string, tenantId?: string | null): Observable<void> {
    const body = tenantId ? { email, password, tenantId } : { email, password };
    return this.api.post<LoginPayload>('/auth/login', body).pipe(
      tap((p) => {
        if (p.user.status && p.user.status !== 'active') {
          throw new ApiError('account_inactive', 'This account is not active.');
        }
        this.store.set({
          token: p.accessToken,
          refreshToken: p.refreshToken,
          userId: p.user.id,
          tenantId: p.user.tenantId,
          email: p.user.email,
          name: p.user.fullName,
        });
      }),
      map(() => undefined),
    );
  }

  /**
   * Exchanges the (rotating, single-use) refresh token for a fresh access token
   * — the thing that keeps a session alive past the access token's few minutes.
   * Called by the interceptor via {@link TokenRefresher} when a request 401s.
   *
   * Resolves to `true` when renewed, `false` when the session is over (no
   * refresh token, or the server declined it); on `false` the local session is
   * cleared so the interceptor can send the user to sign in instead of retrying
   * an unrenewable token forever.
   */
  refresh(): Observable<boolean> {
    const session = this.store.session();
    if (!session?.refreshToken) {
      this.store.clear();
      return of(false);
    }

    return this.api
      .post<{ accessToken: string; refreshToken?: string }>('/auth/refresh', {
        refreshToken: session.refreshToken,
      })
      .pipe(
        tap(({ accessToken, refreshToken }) => this.store.updateToken(accessToken, refreshToken)),
        map(() => true),
        catchError(() => {
          this.store.clear();
          return of(false);
        }),
      );
  }

  /**
   * Sign out. The best-effort server call is dispatched first — synchronously,
   * so the interceptor still attaches the token that identifies the session to
   * revoke — and then the local session is cleared immediately, not in the
   * request's callback. Clearing synchronously means the very next navigation
   * sees the signed-out state (otherwise guestGuard, still seeing a session,
   * bounces the user back to Home). The refresh token rides along so the server
   * can revoke that too, not just the access token.
   */
  logout(): void {
    const refreshToken = this.store.session()?.refreshToken;
    this.api.post('/auth/logout', { refreshToken }).subscribe({ next: () => {}, error: () => {} });
    this.store.clear();
  }
}
