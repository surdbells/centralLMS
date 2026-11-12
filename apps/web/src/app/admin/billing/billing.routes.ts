import { inject } from '@angular/core';
import { CanActivateFn, Router, Routes } from '@angular/router';
import { map } from 'rxjs';
import { BillingService } from '../../services/billing.service';
import { permissionGuard } from '../users/guards/role.guard';

/**
 * Sends an administrator back to the admin dashboard on a deployment that does
 * not bill its organisations (a dedicated single-tenant install), where the
 * API's billing endpoints do not exist either.
 */
export const billingEnabledGuard: CanActivateFn = () => {
  const router = inject(Router);

  return inject(BillingService)
    .availability()
    .pipe(map((enabled) => (enabled ? true : router.createUrlTree(['/administration']))));
};

/**
 * The organisation's billing page.
 *
 * Gated on `billing.manage` — the permission the tenant admin role carries —
 * so the same person who runs the organisation can see what it is paying for;
 * and only on a deployment that bills at all (`billingEnabled`).
 */
export const billingRoutes: Routes = [
  {
    path: 'admin/billing',
    loadComponent: () => import('./billing.component').then((m) => m.BillingComponent),
    canActivate: [permissionGuard, billingEnabledGuard],
    data: { permission: 'billing.manage' },
    title: 'Billing & Subscription',
  },
];
