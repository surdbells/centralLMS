import { ChangeDetectionStrategy, Component, Input, booleanAttribute, inject } from '@angular/core';
import { Location } from '@angular/common';
import { Router } from '@angular/router';
import { IonIcon } from '@ionic/angular/standalone';

/**
 * The app's screen header — a clean title bar that sits inside ion-content and
 * scrolls with it: an optional back arrow, a big display title with a muted
 * subtitle, and a right-hand slot for a filter or action (project content with
 * the `actions` attribute). No gradient band — the page reads as one calm
 * surface, the way the reference designs do.
 */
@Component({
  selector: 'app-brand-header',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [IonIcon],
  template: `
    <header class="ah" [class.hasback]="back">
      <div class="ah-top">
        @if (back) {
          <button type="button" class="ah-back" (click)="goBack()" aria-label="Go back">
            <ion-icon name="arrow-back" aria-hidden="true"></ion-icon>
          </button>
        }
        <h1 class="ah-title">{{ title }}</h1>
        <div class="ah-actions"><ng-content select="[actions]"></ng-content></div>
      </div>
      @if (subtitle) { <p class="ah-sub">{{ subtitle }}</p> }
    </header>
  `,
  styles: [`
    .ah { padding: calc(env(safe-area-inset-top) + 12px) 16px 10px; background: transparent; }
    .ah-top { display: flex; align-items: center; gap: 12px; }
    .ah-back {
      flex: none; width: 40px; height: 40px; margin-left: -8px; border: 0; background: transparent;
      display: grid; place-items: center; cursor: pointer; color: var(--app-ink); border-radius: 50%;
      transition: background .12s ease;
    }
    .ah-back:active { background: var(--app-field); }
    .ah-back ion-icon { font-size: 24px; }
    .ah-title { flex: 1; min-width: 0; margin: 0; font-family: var(--app-font-display); font-size: 1.5rem; font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); line-height: 1.15; color: var(--app-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ah-sub { margin: 4px 0 0; color: var(--app-muted); font-size: .88rem; line-height: 1.35; }
    .ah.hasback .ah-sub { padding-left: 44px; }
    .ah-actions { flex: none; display: flex; align-items: center; gap: 8px; }
    .ah-actions:empty { display: none; }
  `],
})
export class BrandHeaderComponent {
  @Input() title = '';
  @Input() subtitle = '';
  @Input({ transform: booleanAttribute }) back = false;
  /** Where the back arrow lands when there's no history to pop (deep link / reload). */
  @Input() defaultHref = '/tabs/home';

  private readonly location = inject(Location);
  private readonly router = inject(Router);

  goBack(): void {
    // Real history gives the native reverse-slide; on a first-entry page
    // (a reload or deep link straight onto it) there's nothing to pop, so the
    // arrow would be dead — fall back to a sensible home like ion-back-button.
    if (window.history.length > 1) {
      this.location.back();
    } else {
      void this.router.navigateByUrl(this.defaultHref);
    }
  }
}
