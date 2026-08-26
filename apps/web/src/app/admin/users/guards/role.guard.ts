import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { SessionStore } from '../../../core/auth/session.store';

/**
 * Keeps a route out of reach of somebody who cannot use it.
 *
 * Answered from the permissions the sign-in already returned, rather than by
 * asking the server on every navigation. The old guard made an HTTP request
 * per route change, and answered "no" whenever the network was slow.
 *
 * This is only about what the UI offers. The API re-checks every request and
 * is the authority; a guard that could be edited in a browser console is not.
 *
 *   { path: 'roles', canActivate: [permissionGuard], data: { permission: 'role.manage' } }
 */
export const permissionGuard: CanActivateFn = (route) => {
  const session = inject(SessionStore);
  const router = inject(Router);
  const required = route.data?.['permission'] as string | undefined;

  if (!session.isAuthenticated()) {
    return router.createUrlTree(['/login']);
  }

  if (required === undefined) {
    return true;
  }

  return session.can()(required) ? true : router.createUrlTree(['/unauthorized']);
};
