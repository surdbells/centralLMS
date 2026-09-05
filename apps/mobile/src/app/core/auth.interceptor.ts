import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { SessionStore } from './session';
import { environment } from '../../environments/environment';

/**
 * Carries the bearer token on calls to our API — and only our API, so a stray
 * request to another host can never leak it — and treats a 401 as "the session
 * is gone": clear it and return to sign-in. A token refresh flow can slot in
 * here later; for now an expired token means logging back in.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const session = inject(SessionStore);
  const router = inject(Router);
  const token = session.token();
  const isApi = req.url.startsWith(environment.apiBaseUrl);

  const authed = token && isApi
    ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } })
    : req;

  return next(authed).pipe(
    catchError((err: unknown) => {
      const status = (err as { status?: number })?.status;
      if (status === 401 && token && isApi) {
        session.clear();
        void router.navigateByUrl('/login', { replaceUrl: true });
      }
      return throwError(() => err);
    }),
  );
};
