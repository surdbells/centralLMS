import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { SessionStore } from './session';

/** Keep a signed-out visitor on the sign-in screen. */
export const authGuard: CanActivateFn = () => {
  const session = inject(SessionStore);
  const router = inject(Router);
  return session.isAuthenticated() ? true : router.createUrlTree(['/login']);
};

/** Keep an already-signed-in visitor out of the sign-in screen. */
export const guestGuard: CanActivateFn = () => {
  const session = inject(SessionStore);
  const router = inject(Router);
  return session.isAuthenticated() ? router.createUrlTree(['/tabs/home']) : true;
};
