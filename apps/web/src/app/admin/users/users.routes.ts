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
        path: 'import',
        loadComponent: () =>
          import('./components/user-import/user-import.component').then((m) => m.UserImportComponent),
        canActivate: [permissionGuard],
        data: { permission: 'user.manage' },
        title: 'Import People',
      },
      {
        path: 'activity',
        loadComponent: () =>
          import('./components/user-activity-log/user-activity-log.component')
            .then((m) => m.UserActivityLogComponent),
        canActivate: [permissionGuard],
        data: { permission: 'audit.view' },
        title: 'Audit Trail',
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
