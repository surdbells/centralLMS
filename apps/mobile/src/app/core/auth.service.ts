import { Injectable, inject } from '@angular/core';
import { Observable, map, tap } from 'rxjs';
import { ApiClient, ApiError } from './api';
import { SessionStore } from './session';
import { LoginPayload } from './models';

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly api = inject(ApiClient);
  private readonly store = inject(SessionStore);

  /** Signs in and stores the session; the tenant comes from the account. */
  login(email: string, password: string): Observable<void> {
    return this.api.post<LoginPayload>('/auth/login', { email, password }).pipe(
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
   * Sign out. The best-effort server call is dispatched first — synchronously,
   * so the interceptor still attaches the token that identifies the session to
   * revoke — and then the local session is cleared immediately, not in the
   * request's callback. Clearing synchronously means the very next navigation
   * sees the signed-out state (otherwise guestGuard, still seeing a session,
   * bounces the user back to Home).
   */
  logout(): void {
    this.api.post('/auth/logout', {}).subscribe({ next: () => {}, error: () => {} });
    this.store.clear();
  }
}
