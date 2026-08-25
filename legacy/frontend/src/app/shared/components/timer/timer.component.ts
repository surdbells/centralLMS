// components/timer/timer.component.ts
import { Component, Input, Output, EventEmitter, OnInit, OnDestroy, ChangeDetectionStrategy, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { interval, Subscription, Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';

@Component({
  selector: 'app-timer',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="timer-container" [ngClass]="timerClass">
      <div class="timer-icon" *ngIf="showIcon">
        <i class="bi" [ngClass]="isPaused ? 'bi-pause-circle' : 'bi-clock'"></i>
      </div>
      <div class="timer-display">
        <span class="timer-value" [class.warning]="isWarning" [class.critical]="isCritical">
          {{ displayTime }}
        </span>
        <span class="timer-label" *ngIf="label">{{ label }}</span>
      </div>
      <div class="timer-controls" *ngIf="showControls">
        <button class="btn btn-sm btn-secondary" (click)="togglePause()" [disabled]="!isRunning">
          <i class="bi" [ngClass]="isPaused ? 'bi-play-fill' : 'bi-pause-fill'"></i>
        </button>
      </div>
    </div>
  `,
  styles: [`
    .timer-container {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 1rem;
      border-radius: 0.5rem;
      background: #f8f9fa;
      font-family: 'Courier New', monospace;
    }
    .timer-container.compact { padding: 0.25rem 0.5rem; }
    .timer-container.large { padding: 0.75rem 1.5rem; font-size: 1.5rem; }
    .timer-icon { font-size: 1.25em; color: #6c757d; }
    .timer-display { display: flex; flex-direction: column; align-items: center; }
    .timer-value { font-weight: bold; font-size: 1.25em; }
    .timer-value.warning { color: #ffc107; }
    .timer-value.critical { color: #dc3545; animation: pulse 1s infinite; }
    .timer-label { font-size: 0.75em; color: #6c757d; text-transform: uppercase; }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
  `]
})
export class TimerComponent implements OnInit, OnDestroy {
  @Input() initialSeconds: number = 0;
  @Input() countDown: boolean = true;
  @Input() autoStart: boolean = true;
  @Input() warningThreshold: number = 300; // 5 minutes
  @Input() criticalThreshold: number = 60; // 1 minute
  @Input() showIcon: boolean = true;
  @Input() showControls: boolean = false;
  @Input() label: string = '';
  @Input() size: 'compact' | 'normal' | 'large' = 'normal';

  @Output() tick = new EventEmitter<number>();
  @Output() complete = new EventEmitter<void>();
  @Output() warning = new EventEmitter<number>();
  @Output() paused = new EventEmitter<boolean>();

  currentSeconds: number = 0;
  isRunning: boolean = false;
  isPaused: boolean = false;
  isWarning: boolean = false;
  isCritical: boolean = false;

  private destroy$ = new Subject<void>();
  private timerSubscription?: Subscription;

  constructor(private cdr: ChangeDetectorRef) {}

  ngOnInit(): void {
    this.currentSeconds = this.initialSeconds;
    if (this.autoStart) {
      this.start();
    }
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
    this.stop();
  }

  get displayTime(): string {
    const totalSeconds = Math.max(0, Math.floor(this.currentSeconds));
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    if (hours > 0) {
      return `${this.pad(hours)}:${this.pad(minutes)}:${this.pad(seconds)}`;
    }
    return `${this.pad(minutes)}:${this.pad(seconds)}`;
  }

  get timerClass(): string {
    return this.size === 'normal' ? '' : this.size;
  }

  start(): void {
    if (this.isRunning && !this.isPaused) return;

    this.isRunning = true;
    this.isPaused = false;

    this.timerSubscription = interval(1000)
      .pipe(takeUntil(this.destroy$))
      .subscribe(() => {
        if (!this.isPaused) {
          if (this.countDown) {
            this.currentSeconds--;
            if (this.currentSeconds <= 0) {
              this.currentSeconds = 0;
              this.complete.emit();
              this.stop();
            }
          } else {
            this.currentSeconds++;
          }

          this.checkThresholds();
          this.tick.emit(this.currentSeconds);
          this.cdr.markForCheck();
        }
      });
  }

  stop(): void {
    this.isRunning = false;
    this.isPaused = false;
    this.timerSubscription?.unsubscribe();
  }

  togglePause(): void {
    this.isPaused = !this.isPaused;
    this.paused.emit(this.isPaused);
  }

  reset(seconds?: number): void {
    this.stop();
    this.currentSeconds = seconds ?? this.initialSeconds;
    this.isWarning = false;
    this.isCritical = false;
    this.cdr.markForCheck();
  }

  addTime(seconds: number): void {
    this.currentSeconds += seconds;
    this.checkThresholds();
    this.cdr.markForCheck();
  }

  setTime(seconds: number): void {
    this.currentSeconds = seconds;
    this.checkThresholds();
    this.cdr.markForCheck();
  }

  private checkThresholds(): void {
    if (this.countDown) {
      const wasWarning = this.isWarning;
      this.isWarning = this.currentSeconds <= this.warningThreshold && this.currentSeconds > this.criticalThreshold;
      this.isCritical = this.currentSeconds <= this.criticalThreshold;

      if (this.isWarning && !wasWarning) {
        this.warning.emit(this.currentSeconds);
      }
    }
  }

  private pad(num: number): string {
    return num.toString().padStart(2, '0');
  }
}
