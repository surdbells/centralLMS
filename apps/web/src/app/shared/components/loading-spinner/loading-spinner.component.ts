// components/loading-spinner/loading-spinner.component.ts
import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { TranslatePipe } from '../../../translate.pipe';

@Component({
  selector: 'app-loading-spinner',
  standalone: true,
  imports: [CommonModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <!-- Inline Spinner -->
    <span *ngIf="type === 'inline'" class="spinner-inline">
      <span class="spinner-border" [ngClass]="sizeClass" role="status">
        <span class="visually-hidden">{{ 'common.loading' | translate }}</span>
      </span>
      <span *ngIf="text" class="spinner-text">{{ text }}</span>
    </span>

    <!-- Block Spinner -->
    <div *ngIf="type === 'block'" class="spinner-block" [style.minHeight.px]="minHeight">
      <div class="spinner-content">
        <div class="spinner-border" [ngClass]="[sizeClass, colorClass]" role="status">
          <span class="visually-hidden">{{ 'common.loading' | translate }}</span>
        </div>
        <div *ngIf="text" class="spinner-text mt-2">{{ text }}</div>
      </div>
    </div>

    <!-- Overlay Spinner -->
    <div *ngIf="type === 'overlay'" class="spinner-overlay" [class.opaque]="opaque">
      <div class="spinner-content">
        <div class="spinner-border" [ngClass]="[sizeClass, colorClass]" role="status">
          <span class="visually-hidden">{{ 'common.loading' | translate }}</span>
        </div>
        <div *ngIf="text" class="spinner-text mt-3">{{ text }}</div>
      </div>
    </div>

    <!-- Dots Spinner -->
    <div *ngIf="type === 'dots'" class="spinner-dots">
      <div class="dot" [ngClass]="colorClass"></div>
      <div class="dot" [ngClass]="colorClass"></div>
      <div class="dot" [ngClass]="colorClass"></div>
      <span *ngIf="text" class="spinner-text ms-2">{{ text }}</span>
    </div>

    <!-- Progress Spinner -->
    <div *ngIf="type === 'progress'" class="spinner-progress">
      <div class="progress-ring" [ngClass]="sizeClass">
        <svg viewBox="0 0 100 100">
          <circle class="bg" cx="50" cy="50" r="45"></circle>
          <circle class="fg" [ngClass]="colorClass" cx="50" cy="50" r="45"
                  [style.strokeDashoffset]="dashOffset"></circle>
        </svg>
        <span class="progress-value" *ngIf="showPercent">{{ progress }}%</span>
      </div>
      <div *ngIf="text" class="spinner-text mt-2">{{ text }}</div>
    </div>

    <!-- Skeleton -->
    <div *ngIf="type === 'skeleton'" class="skeleton-loader">
      <div *ngFor="let line of skeletonLines" class="skeleton-line"
           [style.width.%]="line" [style.height.px]="skeletonHeight"></div>
    </div>
  `,
  styles: [`
    .spinner-inline {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }
    .spinner-text {
      color: #6c757d;
      font-size: 0.875rem;
    }

    .spinner-block {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 100%;
    }
    .spinner-content {
      text-align: center;
    }

    .spinner-overlay {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      display: flex;
      align-items: center;
      justify-content: center;
      background: rgba(255, 255, 255, 0.8);
      z-index: 1000;
      border-radius: inherit;
    }
    .spinner-overlay.opaque {
      background: rgba(255, 255, 255, 0.95);
    }

    .spinner-sm { width: 1rem; height: 1rem; }
    .spinner-md { width: 2rem; height: 2rem; }
    .spinner-lg { width: 3rem; height: 3rem; }
    .spinner-xl { width: 4rem; height: 4rem; }

    .text-primary { color: #0d6efd !important; }
    .text-secondary { color: #6c757d !important; }
    .text-success { color: #198754 !important; }
    .text-danger { color: #dc3545 !important; }
    .text-warning { color: #ffc107 !important; }
    .text-info { color: #0dcaf0 !important; }

    .spinner-dots {
      display: inline-flex;
      align-items: center;
      gap: 0.25rem;
    }
    .dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: currentColor;
      animation: dotPulse 1.4s infinite ease-in-out both;
    }
    .dot:nth-child(1) { animation-delay: -0.32s; }
    .dot:nth-child(2) { animation-delay: -0.16s; }
    @keyframes dotPulse {
      0%, 80%, 100% { transform: scale(0); opacity: 0.5; }
      40% { transform: scale(1); opacity: 1; }
    }

    .spinner-progress {
      display: flex;
      flex-direction: column;
      align-items: center;
    }
    .progress-ring {
      position: relative;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .progress-ring.spinner-sm { width: 40px; height: 40px; }
    .progress-ring.spinner-md { width: 60px; height: 60px; }
    .progress-ring.spinner-lg { width: 80px; height: 80px; }
    .progress-ring svg {
      width: 100%;
      height: 100%;
      transform: rotate(-90deg);
    }
    .progress-ring circle {
      fill: none;
      stroke-width: 8;
      stroke-linecap: round;
    }
    .progress-ring .bg { stroke: #e9ecef; }
    .progress-ring .fg {
      stroke: currentColor;
      stroke-dasharray: 283;
      transition: stroke-dashoffset 0.3s;
    }
    .progress-value {
      position: absolute;
      font-size: 0.75rem;
      font-weight: 600;
    }

    .skeleton-loader {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }
    .skeleton-line {
      background: linear-gradient(90deg, #f0f0f0 25%, #e0e0e0 50%, #f0f0f0 75%);
      background-size: 200% 100%;
      animation: shimmer 1.5s infinite;
      border-radius: 0.25rem;
    }
    @keyframes shimmer {
      0% { background-position: 200% 0; }
      100% { background-position: -200% 0; }
    }
  `]
})
export class LoadingSpinnerComponent {
  @Input() type: 'inline' | 'block' | 'fullscreen' | 'overlay' | 'dots' | 'progress' | 'skeleton' = 'inline';
  @Input() size: 'sm' | 'md' | 'lg' | 'xl' = 'md';
  @Input() color: 'primary' | 'secondary' | 'success' | 'danger' | 'warning' | 'info' = 'primary';
  @Input() text: string = '';
  @Input() minHeight: number = 200;
  @Input() opaque: boolean = false;
  @Input() progress: number = 0;
  @Input() showPercent: boolean = true;
  @Input() skeletonLines: number[] = [100, 80, 90, 60];
  @Input() skeletonHeight: number = 16;

  get sizeClass(): string {
    return `spinner-${this.size}`;
  }

  get colorClass(): string {
    return `text-${this.color}`;
  }

  get dashOffset(): number {
    const circumference = 283;
    return circumference - (this.progress / 100) * circumference;
  }
}
