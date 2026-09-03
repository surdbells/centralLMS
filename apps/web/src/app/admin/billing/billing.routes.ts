import { Routes } from '@angular/router';
import { permissionGuard } from '../users/guards/role.guard';

/**
 * The organisation's billing page.
 *
 * Gated on `billing.manage` — the permission the tenant admin role carries —
 * so the same person who runs the organisation can see what it is paying for.
 */
export const billingRoutes: Routes = [
  {
    path: 'admin/billing',
    loadComponent: () => import('./billing.component').then((m) => m.BillingComponent),
    canActivate: [permissionGuard],
    data: { permission: 'billing.manage' },
    title: 'Billing & Subscription',
  },
];
