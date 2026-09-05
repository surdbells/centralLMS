import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { SessionStore } from './session';

/**
 * Carries the bearer token on every API call, and treats a 401 as "the session
 * is gone" — clear it and return to sign-in. A token refresh flow can slot in
 * here later; for now an expired token means logging back in.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const session = inject(SessionStore);
  const router = inject(Router);
  const token = session.token();

  const authed = token
    ? req.clone({ setHeaders: { Authorization: `Bearer ${token}` } })
    : req;

  return next(authed).pipe(
    catchError((err: unknown) => {
      const status = (err as { status?: number })?.status;
      if (status === 401 && token) {
        session.clear();
        void router.navigateByUrl('/login', { replaceUrl: true });
      }
      return throwError(() => err);
    }),
  );
};
