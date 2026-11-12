// components/loading-spinner/loading-spinner.component.ts
import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { SkeletonComponent, type SkeletonVariant } from '../../skeleton/skeleton.component';

/**
 * Kept under its old name so any use of <app-loading-spinner> still works,
 * but it no longer spins: it draws the app's shimmering skeleton, shaped like
 * the content on its way. Prefer <app-skeleton> directly in new code.
 *
 *   <app-loading-spinner />                              a few text lines
 *   <app-loading-spinner variant="table" [rows]="6" [cols]="5" />
 *
 * Spinners belong only inside buttons showing an action in progress.
 */
@Component({
  selector: 'app-loading-spinner',
  standalone: true,
  imports: [SkeletonComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <app-skeleton [variant]="variant" [count]="count" [rows]="rows" [cols]="cols" [height]="height"></app-skeleton>
  `,
  styles: [`:host { display: block; }`],
})
export class LoadingSpinnerComponent {
  @Input() variant: SkeletonVariant = 'lines';
  @Input() count = 4;
  @Input() rows = 3;
  @Input() cols = 5;
  @Input() height: string | null = null;
}
