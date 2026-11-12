import { Component, inject } from '@angular/core';
import { Location } from '@angular/common';
import { Router } from '@angular/router';
import { TopComponent } from '../../../../partials/top/top.component';
import { TranslatePipe } from '../../../../translate.pipe';

/**
 * Where `permissionGuard` sends somebody who opened a screen their role does
 * not include (a learner following an /admin link, say).
 *
 * The guard used to redirect to /unauthorized with nothing answering it, so
 * the navigation failed and the page simply did not change.
 */
@Component({
  selector: 'app-access-denied',
  standalone: true,
  imports: [TopComponent, TranslatePipe],
  template: `
    <app-top></app-top>
    <main class="main">
      <div class="ui-page">
        <header class="ui-page-header">
          <button type="button" class="ui-page-header__back" (click)="goBack()" [attr.aria-label]="'common.back' | translate">
            <i class="bi bi-chevron-left"></i>
          </button>
          <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></div>
          <div class="ui-page-header__titles">
            <h1>{{ 'access_denied.title' | translate }}</h1>
            <p>{{ 'access_denied.subtitle' | translate }}</p>
          </div>
        </header>

        <div class="ui-card">
          <div class="ui-state">
            <span class="ui-state__icon"><i class="bi bi-shield-lock"></i></span>
            <h3>{{ 'access_denied.heading' | translate }}</h3>
            <p>{{ 'access_denied.body' | translate }}</p>
            <button type="button" class="btn btn-primary" (click)="goHome()">
              <i class="bi bi-house"></i> {{ 'access_denied.go_home' | translate }}
            </button>
          </div>
        </div>
      </div>
    </main>
  `,
})
export class AccessDeniedComponent {
  private readonly router = inject(Router);
  private readonly location = inject(Location);

  /** The page they came from, or their own dashboard on a fresh tab. */
  goBack(): void {
    if (window.history.length > 1) {
      this.location.back();
      return;
    }
    this.goHome();
  }

  goHome(): void {
    void this.router.navigate(['/account']);
  }
}
