import type { HttpEvent, HttpHandlerFn, HttpRequest } from '@angular/common/http';
import { HttpErrorResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { Observable, throwError } from 'rxjs';
import { catchError, filter, switchMap, take } from 'rxjs/operators';
import { SessionStore } from '../../auth/session.store';
import { TokenRefresher } from '../../auth/token-refresher';
import { Endpoints } from '../api-endpoints';

/**
 * Attaches the bearer token, and gets a new one when it has expired.
 *
 * The attaching is one line, because there is one way to authenticate. It used
 * to also merge `token`, `id` and `tenant_id` into the request *body* for the
 * legacy backend, whose 248 endpoints read credentials from `$_REQUEST` —
 * including the tenant, which is why one signed-in user could read another
 * organisation's data by changing a number in a JSON payload.
 *
 * **The refresh is new, and it matters.** An access token lasts minutes; a
 * refresh token lasts weeks. `AuthService.refresh()` has always existed and
 * nothing ever called it, so the moment an access token expired every request
 * began failing with 401 — mid-form, mid-upload, mid-anything — and each
 * screen rendered that as its own kind of emptiness. Somebody working for an
 * hour would find the application had quietly stopped working with no sign of
 * why, and reloading appeared to fix it.
 */
export function authInterceptor(
  request: HttpRequest<unknown>,
  next: HttpHandlerFn,
): Observable<HttpEvent<unknown>> {
  const store = inject(SessionStore);
  const refresher = inject(TokenRefresher);
  const session = store.session();

  if (session === null) {
    return next(request);
  }

  return next(withToken(request, session.token)).pipe(
    catchError((failure: unknown) => {
      if (!shouldRetry(failure, request)) {
        return throwError(() => failure);
      }

      return refresher.refresh().pipe(
        // One refresh, however many requests were in flight when the token
        // expired — see TokenRefresher. Without that, ten parallel calls on a
        // dashboard become ten refreshes, nine of which use a token the first
        // has already rotated away.
        filter((done) => done !== null),
        take(1),
        switchMap((succeeded) => {
          const renewed = store.session();

          if (succeeded !== true || renewed === null) {
            return throwError(() => failure);
          }

          return next(withToken(request, renewed.token));
        }),
      );
    }),
  );
}

function withToken(request: HttpRequest<unknown>, token: string): HttpRequest<unknown> {
  return request.clone({ setHeaders: { Authorization: `Bearer ${token}` } });
}

/**
 * Whether this failure is worth one more attempt.
 *
 * Only a 401, and never on the auth routes themselves: refreshing in response
 * to a failed refresh is a loop, and refreshing in response to a rejected
 * sign-in would turn a wrong password into two requests.
 */
function shouldRetry(failure: unknown, request: HttpRequest<unknown>): boolean {
  if (!(failure instanceof HttpErrorResponse) || failure.status !== 401) {
    return false;
  }

  return !request.url.includes(Endpoints.auth.refresh)
    && !request.url.includes(Endpoints.auth.login)
    && !request.url.includes(Endpoints.auth.logout);
}
