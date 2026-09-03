import { Injectable, inject } from '@angular/core';
import { Observable, of, shareReplay } from 'rxjs';
import { catchError, map, switchMap, tap } from 'rxjs/operators';
import { ApiClient } from './api.client';
import { SessionStore, type OperatorSession } from './session.store';

/** What `/auth/login` returns (the fields the console uses). */
interface LoginPayload {
  readonly accessToken: string;
  readonly refreshToken: string;
  readonly user: {
    readonly id: string;
    readonly email: string;
    readonly fullName: string;
    readonly status: string;
  };
}

/** Why a sign-in did not end at the console. */
export type SignInError = 'credentials' | 'inactive' | 'not_operator' | 'network';

/**
 * Signing the operator in, out, and keeping the token fresh.
 *
 * Being able to sign in is not the same as being allowed into the console:
 * the operator routes need a super admin, so a fresh session is proven against
 * one — `/operator/dashboard` — before it is called good. A person with an
 * ordinary account gets a clear "not authorised" here rather than a console
 * whose every call fails with 403.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly api = inject(ApiClient);
  private readonly store = inject(SessionStore);

  /** One refresh in flight at a time, however many requests hit 401 together. */
  private refreshing: Observable<boolean> | null = null;

  /**
   * Signs in and confirms the account may operate the platform.
   *
   * Resolves to `null` on success (the session is stored), or a
   * {@link SignInError} naming what went wrong.
   */
  signIn(email: string, password: string): Observable<SignInError | null> {
    return this.api.post<LoginPayload>('/auth/login', { email, password }).pipe(
      switchMap((payload) => {
        if (payload.user.status !== 'active') {
          return of<SignInError>('inactive');
        }

        const session: OperatorSession = {
          token: payload.accessToken,
          refreshToken: payload.refreshToken,
          userId: payload.user.id,
          email: payload.user.email,
          fullName: payload.user.fullName,
        };

        // Store first so the probe carries the bearer, then prove the account
        // is a platform operator. A non-operator is signed straight back out.
        this.store.set(session);

        return this.api.get<unknown>('/operator/dashboard').pipe(
          map(() => null),
          catchError((failure: unknown) => {
            this.store.clear();

            return of<SignInError>(isForbidden(failure) ? 'not_operator' : 'network');
          }),
        );
      }),
      catchError((failure: unknown) => of<SignInError>(isUnauthorized(failure) ? 'credentials' : 'network')),
    );
  }

  /** Exchanges the refresh token for a new access token, deduplicated. */
  refresh(): Observable<boolean> {
    if (this.refreshing !== null) {
      return this.refreshing;
    }

    const session = this.store.session();
    if (session?.refreshToken === undefined) {
      this.store.clear();

      return of(false);
    }

    this.refreshing = this.api
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
        tap(() => (this.refreshing = null)),
        shareReplay(1),
      );

    return this.refreshing;
  }

  signOut(): Observable<void> {
    const session = this.store.session();
    this.store.clear();

    if (session === null) {
      return of(undefined);
    }

    return this.api
      .post<unknown>('/auth/logout', { refreshToken: session.refreshToken })
      .pipe(
        map(() => undefined),
        catchError(() => of(undefined)),
      );
  }
}

function statusOf(failure: unknown): number | null {
  return failure !== null && typeof failure === 'object' && 'status' in failure
    ? Number((failure as { status: unknown }).status)
    : null;
}

function isUnauthorized(failure: unknown): boolean {
  return statusOf(failure) === 401;
}

function isForbidden(failure: unknown): boolean {
  return statusOf(failure) === 403;
}
