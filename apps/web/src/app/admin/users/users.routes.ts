import { Routes } from '@angular/router';
import { permissionGuard } from './guards/role.guard';

/**
 * The people screens.
 *
 * Permission names are the ones the API enforces — `user.view`, `user.manage`,
 * `role.manage`. The old list guessed at `users.view`, `users.create`,
 * `roles.view` and `users.import`, none of which the backend has ever defined,
 * so `checkPermission` returned false for all of them and the guard sent
 * everybody to /unauthorized. It was never noticed because the guard swallowed
 * its own failure and let the route through.
 *
 * Importing people and the audit trail are not here: neither has an API yet.
 * They are the next thing to build rather than screens that cannot work.
 */
export const usersRoutes: Routes = [
  {
    path: 'admin/users',
    children: [
      {
        path: '',
        loadComponent: () =>
          import('./components/user-list/user-list.component').then((m) => m.UserListComponent),
        canActivate: [permissionGuard],
        data: { permission: 'user.view' },
        title: 'User Management',
      },
      {
        path: 'create',
        loadComponent: () =>
          import('./components/user-form/user-form.component').then((m) => m.UserFormComponent),
        canActivate: [permissionGuard],
        data: { permission: 'user.manage' },
        title: 'Create User',
      },
      {
        path: 'roles',
        loadComponent: () =>
          import('./components/role-manager/role-manager.component').then((m) => m.RoleManagerComponent),
        canActivate: [permissionGuard],
        data: { permission: 'user.view' },
        title: 'Roles & Permissions',
      },
      {
        path: ':id/edit',
        loadComponent: () =>
          import('./components/user-form/user-form.component').then((m) => m.UserFormComponent),
        canActivate: [permissionGuard],
        data: { permission: 'user.manage' },
        title: 'Edit User',
      },
      {
        path: ':id',
        loadComponent: () =>
          import('./components/user-detail/user-detail.component').then((m) => m.UserDetailComponent),
        canActivate: [permissionGuard],
        data: { permission: 'user.view' },
        title: 'User Profile',
      },
    ],
  },
];
