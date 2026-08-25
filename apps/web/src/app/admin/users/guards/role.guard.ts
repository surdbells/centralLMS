// users/guards/role.guard.ts
import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { UserService } from '../services/user.service';
import { map, catchError, of } from 'rxjs';

/**
 * Route guard that checks if the current user has the required permission.
 * Usage in routes: { path: 'users', canActivate: [permissionGuard], data: { permission: 'users.view' } }
 */
export const permissionGuard: CanActivateFn = (route, state) => {
  const userService = inject(UserService);
  const router = inject(Router);
  const requiredPerm = route.data?.['permission'] as string;

  if (!requiredPerm) return true;

  return userService.checkPermission(requiredPerm).pipe(
    map(has => {
      if (!has) { router.navigate(['/unauthorized']); return false; }
      return true;
    }),
    catchError(() => { router.navigate(['/login']); return of(false); })
  );
};
