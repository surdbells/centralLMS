import { HttpErrorResponse, HttpInterceptorFn, HttpRequest } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { throwError } from 'rxjs';
import { catchError, filter, switchMap, take } from 'rxjs/operators';
import { SessionStore } from './session';
import { TokenRefresher } from './token-refresher';
import { environment } from '../../environments/environment';

/**
 * Carries the bearer token on calls to our API — and only our API, so a stray
 * request to another host can never leak it — and, when a request comes back
 * 401, gets a new access token and retries instead of dropping the session.
 *
 * An access token lasts minutes; the refresh token lasts far longer. Before
 * this, the moment the access token expired every call began 401ing and the
 * app bounced to sign-in mid-task — reloading appeared to "fix" it. Now a 401
 * triggers a single refresh (shared across every request that failed together
 * — see {@link TokenRefresher}, since refreshing rotates the token), and the
 * original request is replayed with the fresh token. Only a refresh the server
 * refuses ends the session.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const session = inject(SessionStore);
  const refresher = inject(TokenRefresher);
  const router = inject(Router);

  const isApi = req.url.startsWith(environment.apiBaseUrl);
  const token = session.token();
  const authed = token && isApi ? withToken(req, token) : req;

  return next(authed).pipe(
    catchError((err: unknown) => {
      if (!shouldRetry(err, req, isApi)) {
        return throwError(() => err);
      }
      // A 401 with no token to refresh with (e.g. a reload onto a protected
      // page after the session was cleared): straight to sign-in.
      if (!token) {
        redirectToLogin(session, router);
        return throwError(() => err);
      }

      return refresher.refresh().pipe(
        // One refresh, however many requests were in flight — wait for its real
        // answer (null means still running), then act on it once.
        filter((done) => done !== null),
        take(1),
        switchMap((succeeded) => {
          const renewed = session.session();
          if (succeeded !== true || renewed === null) {
            redirectToLogin(session, router);
            return throwError(() => err);
          }
          return next(withToken(req, renewed.token));
        }),
      );
    }),
  );
};

function withToken(req: HttpRequest<unknown>, token: string): HttpRequest<unknown> {
  return req.clone({ setHeaders: { Authorization: `Bearer ${token}` } });
}

/**
 * Whether this failure is worth a refresh + retry: a 401 on our API, but never
 * on the auth routes themselves — refreshing after a failed refresh loops, and
 * refreshing after a rejected sign-in would turn a wrong password into two
 * requests.
 */
function shouldRetry(err: unknown, req: HttpRequest<unknown>, isApi: boolean): boolean {
  if (!isApi || !(err instanceof HttpErrorResponse) || err.status !== 401) {
    return false;
  }
  return !req.url.includes('/auth/refresh')
    && !req.url.includes('/auth/login')
    && !req.url.includes('/auth/logout');
}

/** End the session locally and route to sign-in, once (never loop on /login). */
function redirectToLogin(session: SessionStore, router: Router): void {
  session.clear();
  const url = router.url.split('?')[0];
  if (url === '/login' || url.endsWith('/login')) {
    return;
  }
  void router.navigateByUrl('/login', { replaceUrl: true });
}
