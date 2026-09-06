import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { SessionStore } from './session';
import { BrandingService } from './branding.service';

/** Keep a signed-out visitor out of the app — the sign-in flow takes it from there. */
export const authGuard: CanActivateFn = () => {
  const session = inject(SessionStore);
  const router = inject(Router);
  return session.isAuthenticated() ? true : router.createUrlTree(['/login']);
};

/**
 * The sign-in screen. A signed-in visitor is sent home; a signed-out one may
 * only sign in once an organisation is in hand — on a multi-tenant cloud with
 * no default, that means naming an institution first.
 */
export const guestGuard: CanActivateFn = async () => {
  const session = inject(SessionStore);
  const branding = inject(BrandingService);
  const router = inject(Router);
  if (session.isAuthenticated()) {
    return router.createUrlTree(['/tabs/home']);
  }
  await branding.whenReady();
  return branding.hasTenant() ? true : router.createUrlTree(['/institution']);
};

/**
 * The institution-code screen. Only reachable signed out with no organisation
 * yet — a signed-in visitor goes home, and one that already has an institution
 * (a default deployment, or a code entered earlier) skips straight to sign-in.
 */
export const institutionGuard: CanActivateFn = async () => {
  const session = inject(SessionStore);
  const branding = inject(BrandingService);
  const router = inject(Router);
  if (session.isAuthenticated()) {
    return router.createUrlTree(['/tabs/home']);
  }
  await branding.whenReady();
  return branding.hasTenant() ? router.createUrlTree(['/login']) : true;
};
