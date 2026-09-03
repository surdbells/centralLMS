import { Routes } from '@angular/router';
import { operatorGuard } from './core/operator.guard';

/**
 * Every screen the console serves.
 *
 * Sign-in is the only public one; everything else lives inside the shell and
 * behind {@link operatorGuard}. Each is lazily loaded so a visitor at the
 * sign-in screen has not yet downloaded the tenant tables.
 */
export const routes: Routes = [
  {
    path: 'login',
    loadComponent: () => import('./auth/login.component').then((m) => m.LoginComponent),
  },
  {
    path: '',
    canActivate: [operatorGuard],
    loadComponent: () => import('./shell/shell.component').then((m) => m.ShellComponent),
    children: [
      {
        path: 'dashboard',
        loadComponent: () => import('./dashboard/dashboard.component').then((m) => m.DashboardComponent),
      },
      {
        path: 'tenants',
        loadComponent: () => import('./tenants/tenants.component').then((m) => m.TenantsComponent),
      },
      {
        path: 'tenants/:id',
        loadComponent: () =>
          import('./tenants/tenant-detail.component').then((m) => m.TenantDetailComponent),
      },
      {
        path: 'plans',
        loadComponent: () => import('./plans/plans.component').then((m) => m.PlansComponent),
      },
      { path: '', redirectTo: 'dashboard', pathMatch: 'full' },
    ],
  },
  { path: '**', redirectTo: '' },
];
