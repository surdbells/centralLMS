import { Component, ChangeDetectionStrategy, input, model } from '@angular/core';

export interface SegmentOption {
  value: string;
  label: string;
}

/**
 * A segmented control replacing ion-segment. An inset track with a raised pill
 * that slides under the active segment — crisp in Studio, floating on the dark
 * field in Bold. Two-way [(value)].
 *
 *   <app-segment [(value)]="tab" [segments]="[{value:'a',label:'A'},…]" />
 */
@Component({
  selector: 'app-segment',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="track" role="group" [attr.aria-label]="ariaLabel() || null">
      <div
        class="thumb"
        [style.width]="thumbWidth()"
        [style.transform]="'translateX(' + activeIndex() * 100 + '%)'"></div>
      @for (s of segments(); track s.value) {
        <button
          type="button"
          class="seg"
          [attr.aria-pressed]="s.value === value()"
          [class.on]="s.value === value()"
          (click)="value.set(s.value)">
          {{ s.label }}
        </button>
      }
    </div>
  `,
  styles: [`
    :host { display: block; }
    .track {
      position: relative; display: flex; padding: 3px;
      background: var(--app-field); border: var(--app-card-border);
      border-radius: var(--app-r-sm);
    }
    /* The thumb sits inside the 3px padding; its width is one segment and it
       slides by whole segment-widths (translateX in % of its own width). */
    .thumb {
      position: absolute; top: 3px; bottom: 3px; left: 3px;
      border-radius: calc(var(--app-r-sm) - 3px);
      background: var(--app-surface); box-shadow: var(--app-shadow);
      transition: transform 0.26s cubic-bezier(0.3, 0.8, 0.3, 1);
    }

    .seg {
      position: relative; z-index: 1; flex: 1; border: 0; background: transparent;
      font: inherit; font-weight: 600; font-size: 0.92rem; color: var(--app-muted);
      padding: 9px 6px; cursor: pointer; border-radius: calc(var(--app-r-sm) - 3px);
      -webkit-tap-highlight-color: transparent;
      transition: color 0.2s ease;
    }
    .seg.on { color: var(--app-ink); font-weight: 700; }

    @media (prefers-reduced-motion: reduce) { .thumb { transition: none; } }
  `],
})
export class SegmentComponent {
  readonly value = model('');
  readonly segments = input<SegmentOption[]>([]);
  readonly ariaLabel = input('');

  activeIndex(): number {
    const i = this.segments().findIndex((s) => s.value === this.value());
    return i < 0 ? 0 : i;
  }

  thumbWidth(): string {
    return `calc((100% - 6px) / ${Math.max(1, this.segments().length)})`;
  }
}
