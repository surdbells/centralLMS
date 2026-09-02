import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { SessionStore } from './session.store';

/**
 * Keeps a signed-out visitor off an authenticated route.
 *
 * Answered from the stored session, so navigating (or reloading) onto a
 * protected URL without a session lands on sign-in rather than rendering a
 * shell whose every call 401s. `permissionGuard` already implies this, so
 * routes that use it do not also need this one.
 *
 * The API re-checks every request and is the authority; this only decides what
 * the browser is allowed to route to.
 */
export const authGuard: CanActivateFn = (_route, state) => {
  const session = inject(SessionStore);
  const router = inject(Router);

  if (session.isAuthenticated()) {
    return true;
  }

  return router.createUrlTree(['/login'], { queryParams: { returnUrl: state.url } });
};
