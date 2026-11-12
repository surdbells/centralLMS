import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { map } from 'rxjs';
import { BillingService } from './billing.service';

/**
 * Lets a route through only on a deployment that bills its organisations
 * (`billingEnabled` from `GET /settings/public`).
 *
 * Used on `/home`, the SaaS marketing and pricing page: a dedicated
 * single-tenant install has no plans to sell and no sign-up to offer, so a
 * visitor there is sent to sign-in, exactly where the app root sends them.
 */
export const billingEnabledGuard: CanActivateFn = () => {
  const billing = inject(BillingService);
  const router = inject(Router);

  return billing
    .availability()
    .pipe(map((enabled) => (enabled ? true : router.createUrlTree(['/login']))));
};
