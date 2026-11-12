import { Component, EventEmitter, Input, Output } from '@angular/core';
import { CommonModule } from '@angular/common';

/**
 * A 5-star rating control — an input when editable, a read-out otherwise.
 *
 * Used both to leave a course evaluation and to display an aggregate average
 * (set `[readonly]` and pass a fractional `value`; whole stars fill up to it).
 */
@Component({
  selector: 'app-star-rating',
  standalone: true,
  imports: [CommonModule],
  template: `
    <span class="stars" [class.readonly]="readonly" [attr.role]="readonly ? 'img' : 'group'"
          [attr.aria-label]="label + ': ' + (value || 0) + ' / 5'">
      <button type="button" class="star" *ngFor="let n of slots"
              [class.filled]="n <= (hover || value)"
              [disabled]="readonly"
              [attr.aria-label]="n + ' / 5'"
              [attr.aria-pressed]="readonly ? null : n === value"
              [attr.aria-hidden]="readonly ? true : null"
              (mouseenter)="onHover(n)" (mouseleave)="onHover(0)" (click)="pick(n)">
        <i class="bi" [ngClass]="n <= (hover || value) ? 'bi-star-fill' : 'bi-star'"></i>
      </button>
      <span class="count" *ngIf="count !== null">({{ count }})</span>
    </span>
  `,
  styles: [`
    .stars { display:inline-flex; align-items:center; gap:1px; }
    .star { background:none; border:0; padding:0 1px; line-height:1; color:#f0a500; cursor:pointer; font-size:1.05rem; }
    .stars.readonly .star, .star:disabled { cursor:default; }
    .star .bi-star { color:#c9ccd1; }
    .count { margin-left:6px; color:#6c757d; font-size:.82rem; }
  `],
})
export class StarRatingComponent {
  @Input() value = 0;
  @Input() readonly = false;
  @Input() label = 'Rating';
  /** An optional review count shown beside the stars (e.g. for an average). */
  @Input() count: number | null = null;

  @Output() rate = new EventEmitter<number>();

  readonly slots = [1, 2, 3, 4, 5];
  hover = 0;

  onHover(n: number): void {
    if (!this.readonly) {
      this.hover = n;
    }
  }

  pick(n: number): void {
    if (!this.readonly) {
      this.value = n;
      this.rate.emit(n);
    }
  }
}
