import { Component, Input, inject } from '@angular/core';
import { BrandingService } from '../../core/branding.service';

/**
 * The app's header — a compact branded gradient band (a sibling of the Home
 * hero): the organisation's name, a big display title, and the logo in a chip,
 * over the tenant's colour with a soft light bloom for depth. Lives inside
 * ion-content and scrolls with it, its rounded underside lifting off the
 * neutral content below.
 */
@Component({
  selector: 'app-brand-header',
  standalone: true,
  template: `
    <header class="bh">
      <div class="bh-top">
        @if (branding.organisation()) {
          <span class="bh-org">{{ branding.organisation() }}</span>
        } @else {
          <span class="bh-org">Central LMS</span>
        }
        @if (branding.logoUrl()) {
          <div class="bh-logo"><img [src]="branding.logoUrl()" [alt]="branding.organisation() || 'Logo'" /></div>
        }
      </div>
      <h1 class="bh-title">{{ title }}</h1>
      @if (subtitle) { <p class="bh-sub">{{ subtitle }}</p> }
    </header>
  `,
  styles: [`
    .bh {
      position: relative; overflow: hidden; isolation: isolate;
      padding: calc(env(safe-area-inset-top) + 18px) 20px 24px;
      color: #fff; border-radius: 0 0 26px 26px;
      background:
        radial-gradient(135% 85% at 88% -28%, color-mix(in srgb, var(--brand-primary, var(--ion-color-primary)) 40%, #ffffff) 0%, transparent 54%),
        linear-gradient(152deg, var(--brand-primary, var(--ion-color-primary)) 0%, var(--brand-primary-shade, var(--ion-color-primary-shade)) 100%);
      box-shadow: 0 16px 34px -22px color-mix(in srgb, var(--brand-primary, #2400a0) 72%, transparent);
    }
    .bh::before { content: ''; position: absolute; z-index: -1; top: -70px; right: -44px; width: 190px; height: 190px; border-radius: 50%; background: radial-gradient(circle at 50% 50%, rgba(255,255,255,.2), transparent 68%); }
    .bh-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
    .bh-org {
      font-size: .68rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase;
      color: rgba(255,255,255,.82); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 66vw;
    }
    .bh-logo {
      flex: 0 0 auto; width: 42px; height: 42px; border-radius: var(--app-r-sm); background: #fff;
      display: grid; place-items: center; padding: 5px; box-shadow: 0 10px 22px -6px rgba(0,0,0,.32);
    }
    .bh-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .bh-title { margin: 0; font-family: var(--app-font-display); font-size: 1.95rem; font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); line-height: 1.05; }
    .bh-sub { margin: 6px 0 0; color: rgba(255,255,255,.82); font-size: .9rem; line-height: 1.4; }
  `],
})
export class BrandHeaderComponent {
  @Input() title = '';
  @Input() subtitle = '';
  readonly branding = inject(BrandingService);
}
