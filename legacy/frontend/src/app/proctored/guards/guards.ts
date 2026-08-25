// guards/guards.ts
import { Injectable, inject } from '@angular/core';
import { CanActivate, CanDeactivate, Router, ActivatedRouteSnapshot, RouterStateSnapshot, UrlTree } from '@angular/router';
import { Observable } from 'rxjs';

// ==========================================
// ASSESSMENT ACCESS GUARD
// ==========================================
@Injectable({ providedIn: 'root' })
export class AssessmentAccessGuard implements CanActivate {
  private router = inject(Router);

  canActivate(
    route: ActivatedRouteSnapshot,
    state: RouterStateSnapshot
  ): boolean | UrlTree {
    const assessmentId = route.params['assessmentId'];
    const isAuthenticated = !!localStorage.getItem('token');
    
    if (!isAuthenticated) {
      return this.router.createUrlTree(['/login'], { 
        queryParams: { returnUrl: state.url } 
      });
    }

    // Additional checks can be added here:
    // - Check if user is enrolled
    // - Check if assessment is available
    // - Check if attempts remaining

    return true;
  }
}


// ==========================================
// PROCTORING CHECK GUARD
// ==========================================
@Injectable({ providedIn: 'root' })
export class ProctoringCheckGuard implements CanActivate {
  private router = inject(Router);

  canActivate(
    route: ActivatedRouteSnapshot,
    state: RouterStateSnapshot
  ): Observable<boolean | UrlTree> | Promise<boolean | UrlTree> | boolean | UrlTree {
    const proctoringLevel = route.data['proctoringLevel'] || 'none';
    
    if (proctoringLevel === 'none') {
      return true;
    }

    // Check if system requirements are met
    const systemCheckPassed = sessionStorage.getItem('systemCheckPassed') === 'true';
    
    if (!systemCheckPassed) {
      const assessmentId = route.params['assessmentId'];
      return this.router.createUrlTree(['/assessment', assessmentId, 'system-check']);
    }

    return true;
  }
}


// ==========================================
// UNSAVED CHANGES GUARD
// ==========================================
export interface HasUnsavedChanges {
  hasUnsavedChanges(): boolean;
  saveChanges?(): Observable<boolean> | Promise<boolean> | boolean;
}

@Injectable({ providedIn: 'root' })
export class UnsavedChangesGuard implements CanDeactivate<HasUnsavedChanges> {
  canDeactivate(
    component: HasUnsavedChanges,
    currentRoute: ActivatedRouteSnapshot,
    currentState: RouterStateSnapshot,
    nextState?: RouterStateSnapshot
  ): Observable<boolean | UrlTree> | Promise<boolean | UrlTree> | boolean | UrlTree {
    
    if (component.hasUnsavedChanges && component.hasUnsavedChanges()) {
      return confirm('You have unsaved changes. Are you sure you want to leave this page?');
    }
    
    return true;
  }
}


// ==========================================
// ATTEMPT IN PROGRESS GUARD
// ==========================================
@Injectable({ providedIn: 'root' })
export class AttemptInProgressGuard implements CanDeactivate<any> {
  canDeactivate(
    component: any,
    currentRoute: ActivatedRouteSnapshot,
    currentState: RouterStateSnapshot,
    nextState?: RouterStateSnapshot
  ): boolean {
    
    const attemptInProgress = sessionStorage.getItem('attemptInProgress') === 'true';
    
    if (attemptInProgress) {
      const confirmLeave = confirm(
        'You have an assessment in progress. Leaving this page may result in your attempt being auto-submitted. Are you sure you want to leave?'
      );
      
      if (!confirmLeave) {
        return false;
      }
      
      // Optionally trigger auto-submit here
      sessionStorage.removeItem('attemptInProgress');
    }
    
    return true;
  }
}


// ==========================================
// ADMIN GUARD
// ==========================================
@Injectable({ providedIn: 'root' })
export class AdminGuard implements CanActivate {
  private router = inject(Router);

  canActivate(
    route: ActivatedRouteSnapshot,
    state: RouterStateSnapshot
  ): boolean | UrlTree {
    const userRole = localStorage.getItem('user_role');
    const allowedRoles = route.data['roles'] as string[] || ['admin'];
    
    if (!userRole || !allowedRoles.includes(userRole)) {
      return this.router.createUrlTree(['/unauthorized']);
    }
    
    return true;
  }
}


// ==========================================
// TIME WINDOW GUARD
// ==========================================
@Injectable({ providedIn: 'root' })
export class TimeWindowGuard implements CanActivate {
  private router = inject(Router);

  canActivate(
    route: ActivatedRouteSnapshot,
    state: RouterStateSnapshot
  ): boolean | UrlTree {
    const startDate = route.data['startDate'] as Date;
    const endDate = route.data['endDate'] as Date;
    const now = new Date();

    if (startDate && now < startDate) {
      // Assessment not yet available
      return this.router.createUrlTree(['/assessment-not-available'], {
        queryParams: { reason: 'not_started', startsAt: startDate.toISOString() }
      });
    }

    if (endDate && now > endDate) {
      // Assessment window has passed
      return this.router.createUrlTree(['/assessment-not-available'], {
        queryParams: { reason: 'ended', endedAt: endDate.toISOString() }
      });
    }

    return true;
  }
}


// ==========================================
// FULLSCREEN GUARD (for proctored assessments)
// ==========================================
@Injectable({ providedIn: 'root' })
export class FullscreenGuard implements CanActivate {
  canActivate(
    route: ActivatedRouteSnapshot,
    state: RouterStateSnapshot
  ): Promise<boolean> {
    const requireFullscreen = route.data['requireFullscreen'] === true;
    
    if (!requireFullscreen) {
      return Promise.resolve(true);
    }

    // Check if already in fullscreen
    if (document.fullscreenElement) {
      return Promise.resolve(true);
    }

    // Request fullscreen
    return document.documentElement.requestFullscreen()
      .then(() => true)
      .catch(() => {
        alert('This assessment requires fullscreen mode. Please allow fullscreen access.');
        return false;
      });
  }
}
