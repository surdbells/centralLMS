// components/progress-bar/progress-bar.component.ts
import { Component, Input, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { TranslatePipe } from '../../../translate.pipe';

@Component({
  selector: 'app-progress-bar',
  standalone: true,
  imports: [CommonModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="progress-wrapper">
      <div class="progress-header" *ngIf="showLabel || showPercentage">
        <span class="progress-label" *ngIf="showLabel">{{ label }}</span>
        <span class="progress-percentage" *ngIf="showPercentage">{{ displayPercentage }}%</span>
      </div>
      
      <div class="progress" [style.height.px]="height">
        <div 
          class="progress-bar" 
          [class.progress-bar-striped]="striped"
          [class.progress-bar-animated]="animated && value < 100"
          [ngClass]="colorClass"
          [style.width.%]="displayPercentage"
          role="progressbar"
          [attr.aria-valuenow]="value"
          [attr.aria-valuemin]="0"
          [attr.aria-valuemax]="max">
          <span *ngIf="showValueInBar && height >= 16">{{ displayPercentage }}%</span>
        </div>
      </div>
      
      <div class="progress-footer" *ngIf="showSteps">
        <span class="step-text">{{ 'shared.progress.steps' | translate:{ current: current, total: total, label: stepLabel || ('shared.progress.completed' | translate) } }}</span>
      </div>
    </div>
  `,
  styles: [`
    .progress-wrapper { width: 100%; }
    .progress-header {
      display: flex;
      justify-content: space-between;
      margin-bottom: 0.25rem;
      font-size: 0.875rem;
    }
    .progress-label { color: #495057; font-weight: 500; }
    .progress-percentage { color: #6c757d; }
    .progress {
      background-color: #e9ecef;
      border-radius: 0.375rem;
      overflow: hidden;
    }
    .progress-bar {
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.75rem;
      font-weight: 600;
      color: #fff;
      transition: width 0.3s ease;
    }
    .progress-footer {
      margin-top: 0.25rem;
      font-size: 0.75rem;
      color: #6c757d;
      text-align: center;
    }
    .bg-success-gradient {
      background: linear-gradient(90deg, #28a745, #20c997);
    }
    .bg-warning-gradient {
      background: linear-gradient(90deg, #ffc107, #fd7e14);
    }
    .bg-danger-gradient {
      background: linear-gradient(90deg, #dc3545, #e83e8c);
    }
  `]
})
export class ProgressBarComponent {
  @Input() value: number = 0;
  @Input() max: number = 100;
  @Input() current: number = 0;
  @Input() total: number = 0;
  @Input() label: string = '';
  @Input() stepLabel: string = '';
  @Input() height: number = 20;
  @Input() color: 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'auto' = 'primary';
  @Input() striped: boolean = false;
  @Input() animated: boolean = false;
  @Input() showLabel: boolean = true;
  @Input() showPercentage: boolean = true;
  @Input() showValueInBar: boolean = false;
  @Input() showSteps: boolean = false;
  @Input() gradient: boolean = false;

  get displayPercentage(): number {
    if (this.total > 0) {
      return Math.round((this.current / this.total) * 100);
    }
    return Math.min(100, Math.max(0, Math.round((this.value / this.max) * 100)));
  }

  get colorClass(): string {
    if (this.color === 'auto') {
      const pct = this.displayPercentage;
      if (pct >= 80) return this.gradient ? 'bg-success-gradient' : 'bg-success';
      if (pct >= 50) return this.gradient ? 'bg-warning-gradient' : 'bg-warning';
      return this.gradient ? 'bg-danger-gradient' : 'bg-danger';
    }
    return this.gradient ? `bg-${this.color}-gradient` : `bg-${this.color}`;
  }
}
