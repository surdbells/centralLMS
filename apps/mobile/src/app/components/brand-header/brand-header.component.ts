import { Component, Input, inject } from '@angular/core';
import { BrandingService } from '../../core/branding.service';

/**
 * The app's own header — a big left-aligned title with the organisation's logo
 * in a chip on the right, sitting on the page rather than in a stock toolbar.
 * Lives inside ion-content and scrolls with it.
 */
@Component({
  selector: 'app-brand-header',
  standalone: true,
  template: `
    <header class="bh">
      <div class="bh-text">
        @if (branding.organisation()) {
          <p class="bh-org">{{ branding.organisation() }}</p>
        }
        <h1 class="bh-title">{{ title }}</h1>
        @if (subtitle) { <p class="bh-sub">{{ subtitle }}</p> }
      </div>
      @if (branding.logoUrl()) {
        <div class="bh-logo"><img [src]="branding.logoUrl()" [alt]="branding.organisation() || 'Logo'" /></div>
      }
    </header>
  `,
  styles: [`
    .bh {
      display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
      padding: calc(env(safe-area-inset-top) + 14px) 20px 6px;
    }
    .bh-text { min-width: 0; }
    .bh-org {
      margin: 0 0 2px; font-size: .68rem; font-weight: 700; letter-spacing: .06em;
      text-transform: uppercase; color: var(--ion-color-primary);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 62vw;
    }
    .bh-title { margin: 0; font-size: 1.85rem; font-weight: 800; letter-spacing: -.01em; line-height: 1.1; }
    .bh-sub { margin: 4px 0 0; color: var(--ion-color-medium); font-size: .9rem; }
    .bh-logo {
      flex: 0 0 auto; width: 46px; height: 46px; border-radius: 14px; background: #fff;
      display: grid; place-items: center; padding: 6px; box-shadow: 0 6px 16px rgba(0,0,0,.1);
    }
    .bh-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
  `],
})
export class BrandHeaderComponent {
  @Input() title = '';
  @Input() subtitle = '';
  readonly branding = inject(BrandingService);
}
