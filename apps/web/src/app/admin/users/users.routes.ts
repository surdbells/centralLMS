// users/users.routes.ts
import { Routes } from '@angular/router';
import { permissionGuard } from './guards/role.guard';

export const usersRoutes: Routes = [
  {
    path: 'admin/users',
    children: [
      {
        path: '',
        loadComponent: () => import('./components/user-list/user-list.component').then(m => m.UserListComponent),
        canActivate: [permissionGuard], data: { permission: 'users.view' },
        title: 'User Management'
      },
      {
        path: 'create',
        loadComponent: () => import('./components/user-form/user-form.component').then(m => m.UserFormComponent),
        canActivate: [permissionGuard], data: { permission: 'users.create' },
        title: 'Create User'
      },
      {
        path: 'import',
        loadComponent: () => import('./components/user-import/user-import.component').then(m => m.UserImportComponent),
        canActivate: [permissionGuard], data: { permission: 'users.import' },
        title: 'Import Users'
      },
      {
        path: 'roles',
        loadComponent: () => import('./components/role-manager/role-manager.component').then(m => m.RoleManagerComponent),
        canActivate: [permissionGuard], data: { permission: 'roles.view' },
        title: 'Roles & Permissions'
      },
      {
        path: 'activity',
        loadComponent: () => import('./components/user-activity-log/user-activity-log.component').then(m => m.UserActivityLogComponent),
        canActivate: [permissionGuard], data: { permission: 'system.audit_log' },
        title: 'Activity Log'
      },
      {
        path: ':id/edit',
        loadComponent: () => import('./components/user-form/user-form.component').then(m => m.UserFormComponent),
        canActivate: [permissionGuard], data: { permission: 'users.edit' },
        title: 'Edit User'
      },
      {
        path: ':id',
        loadComponent: () => import('./components/user-detail/user-detail.component').then(m => m.UserDetailComponent),
        canActivate: [permissionGuard], data: { permission: 'users.view' },
        title: 'User Profile'
      }
    ]
  }
];
