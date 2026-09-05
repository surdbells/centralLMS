import { Component, ChangeDetectionStrategy, input, computed } from '@angular/core';

/**
 * A slim progress bar replacing ion-progress-bar. The track is the theme's
 * neutral rail and the fill is the tenant brand, so it recolours per org and
 * reads correctly on both Studio and Bold.
 *
 *   <app-progress [value]="0.4"></app-progress>   <!-- 0..1 -->
 */
@Component({
  selector: 'app-progress',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="track"
      role="progressbar"
      aria-valuemin="0"
      aria-valuemax="100"
      [attr.aria-valuenow]="pct()">
      <span class="fill" [style.width.%]="pct()"></span>
    </div>
  `,
  styles: [`
    :host { display: block; }
    .track {
      height: 6px; border-radius: 999px; overflow: hidden;
      background: var(--app-track);
    }
    .fill {
      display: block; height: 100%; border-radius: 999px;
      background: var(--app-accent-fill);
      transition: width 0.4s cubic-bezier(0.2, 0.7, 0.2, 1);
    }
    @media (prefers-reduced-motion: reduce) { .fill { transition: none; } }
  `],
})
export class ProgressComponent {
  readonly value = input(0);
  readonly pct = computed(() => Math.max(0, Math.min(100, Math.round(this.value() * 100))));
}
