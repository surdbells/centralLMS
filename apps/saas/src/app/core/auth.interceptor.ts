import type { HttpEvent, HttpHandlerFn, HttpRequest } from '@angular/common/http';
import { HttpErrorResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { Observable, throwError } from 'rxjs';
import { catchError, filter, switchMap, take } from 'rxjs/operators';
import { AuthService } from './auth.service';
import { SessionStore } from './session.store';

/**
 * Attaches the bearer token, and gets a new one when it has expired.
 *
 * An access token lasts minutes and a refresh token weeks: without this, the
 * first request after an access token expired would 401 and the console would
 * quietly stop working mid-task. On a 401 for anything but the auth routes the
 * refresh is tried once — deduplicated in {@link AuthService} so ten parallel
 * dashboard calls do not become ten refreshes — and the request retried. A
 * refresh that fails ends the session and returns to sign-in.
 */
export function authInterceptor(
  request: HttpRequest<unknown>,
  next: HttpHandlerFn,
): Observable<HttpEvent<unknown>> {
  const store = inject(SessionStore);
  const auth = inject(AuthService);
  const router = inject(Router);
  const session = store.session();

  if (session === null) {
    return next(request).pipe(
      catchError((failure: unknown) => {
        if (shouldRetry(failure, request)) {
          redirectToLogin(store, router);
        }

        return throwError(() => failure);
      }),
    );
  }

  return next(withToken(request, session.token)).pipe(
    catchError((failure: unknown) => {
      if (!shouldRetry(failure, request)) {
        return throwError(() => failure);
      }

      return auth.refresh().pipe(
        filter((done) => done !== null),
        take(1),
        switchMap((succeeded) => {
          const renewed = store.session();

          if (succeeded !== true || renewed === null) {
            redirectToLogin(store, router);

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

function redirectToLogin(store: SessionStore, router: Router): void {
  store.clear();

  const url = router.url.split('?')[0];
  if (url === '/login' || url.endsWith('/login')) {
    return;
  }

  void router.navigate(['/login'], { queryParams: { returnUrl: router.url } });
}

/**
 * Whether this failure is worth one more attempt: only a 401, and never on the
 * auth routes themselves — refreshing in response to a failed refresh is a
 * loop, and refreshing a rejected sign-in would double a wrong password.
 */
function shouldRetry(failure: unknown, request: HttpRequest<unknown>): boolean {
  if (!(failure instanceof HttpErrorResponse) || failure.status !== 401) {
    return false;
  }

  return !request.url.includes('/auth/refresh')
    && !request.url.includes('/auth/login')
    && !request.url.includes('/auth/logout');
}
