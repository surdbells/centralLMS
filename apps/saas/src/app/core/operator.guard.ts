import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { SessionStore } from './session.store';

/**
 * Keeps a signed-out visitor off the console.
 *
 * Answered from the stored session, so a reload onto a console URL without one
 * lands on sign-in rather than a shell whose every call fails. Whether the
 * session belongs to an actual super admin is the API's to decide, and it does
 * on every request; this only decides what the browser may route to.
 */
export const operatorGuard: CanActivateFn = (_route, state) => {
  const session = inject(SessionStore);
  const router = inject(Router);

  if (session.isAuthenticated()) {
    return true;
  }

  return router.createUrlTree(['/login'], { queryParams: { returnUrl: state.url } });
};
