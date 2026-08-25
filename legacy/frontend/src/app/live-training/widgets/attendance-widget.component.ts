// live-training/widgets/attendance-widget.component.ts

import { Component, OnInit, OnDestroy, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { LiveTrainingService, TrainingSession } from '../live-training.service';

interface AttendanceStats {
  total_sessions: number;
  completed_sessions: number;
  total_expected: number;
  total_present: number;
  total_late: number;
  total_absent: number;
  attendance_rate: number;
}

@Component({
  selector: 'app-attendance-widget',
  standalone: true,
  imports: [CommonModule, RouterModule],
  template: `
    <div class="attendance-widget">
      <div class="widget-header">
        <h6 class="widget-title mb-0">
          <i class="bi bi-clipboard-check me-2"></i>
          Live Training Attendance
        </h6>
        <a routerLink="/live-training/admin" class="btn btn-sm btn-link text-decoration-none">
          View Details <i class="bi bi-arrow-right"></i>
        </a>
      </div>

      <div class="widget-body">
        <!-- Loading -->
        <div *ngIf="loading" class="text-center py-4">
          <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
        </div>

        <!-- Stats -->
        <div *ngIf="!loading && stats" class="attendance-stats">
          <!-- Attendance Rate Circle -->
          <div class="rate-circle-container">
            <div class="rate-circle"
                 [ngStyle]="{'background': 'conic-gradient(#198754 0% ' + stats.attendance_rate + '%, #e9ecef ' + stats.attendance_rate + '% 100%)'}">
              <div class="rate-value">
                <span class="rate-number">{{ stats.attendance_rate }}</span>
                <span class="rate-percent">%</span>
              </div>
              <div class="rate-label">Attendance Rate</div>
            </div>
          </div>

          <!-- Breakdown -->
          <div class="breakdown-grid">
            <div class="breakdown-item">
              <div class="breakdown-value text-success">{{ stats.total_present }}</div>
              <div class="breakdown-label">Present</div>
            </div>
            <div class="breakdown-item">
              <div class="breakdown-value text-warning">{{ stats.total_late }}</div>
              <div class="breakdown-label">Late</div>
            </div>
            <div class="breakdown-item">
              <div class="breakdown-value text-danger">{{ stats.total_absent }}</div>
              <div class="breakdown-label">Absent</div>
            </div>
            <div class="breakdown-item">
              <div class="breakdown-value text-info">{{ stats.completed_sessions }}</div>
              <div class="breakdown-label">Sessions</div>
            </div>
          </div>

          <!-- Progress Bar -->
          <div class="attendance-bar mt-4">
            <div class="bar-segments">
              <div class="segment present"
                   [style.width.%]="getPercentage(stats.total_present)"
                   [title]="'Present: ' + stats.total_present"></div>
              <div class="segment late"
                   [style.width.%]="getPercentage(stats.total_late)"
                   [title]="'Late: ' + stats.total_late"></div>
              <div class="segment absent"
                   [style.width.%]="getPercentage(stats.total_absent)"
                   [title]="'Absent: ' + stats.total_absent"></div>
            </div>
            <div class="bar-legend">
              <span class="legend-item"><span class="dot present"></span> Present</span>
              <span class="legend-item"><span class="dot late"></span> Late</span>
              <span class="legend-item"><span class="dot absent"></span> Absent</span>
            </div>
          </div>
        </div>

        <!-- Today's Sessions -->
        <div *ngIf="!loading && todaySessions.length > 0" class="today-sessions mt-4 pt-4 border-top">
          <h6 class="small text-muted mb-3">Today's Sessions</h6>
          <div *ngFor="let session of todaySessions" class="today-session-item">
            <div class="session-info">
              <span class="session-title">{{ session.title }}</span>
              <span class="session-time text-muted">{{ formatTime(session.start_time) }}</span>
            </div>
            <span class="badge" [ngClass]="getStatusBadgeClass(session.status)">
              {{ session.status | titlecase }}
            </span>
          </div>
        </div>

        <!-- Empty State -->
        <div *ngIf="!loading && !stats" class="empty-state text-center py-4">
          <i class="bi bi-clipboard-x display-6 text-muted mb-2"></i>
          <p class="text-muted mb-0 small">No attendance data yet</p>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .attendance-widget {
      background: #fff;
      border-radius: 12px;
      box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
      overflow: hidden;
    }

    .widget-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 1rem 1.25rem;
      border-bottom: 1px solid #f0f0f0;
      background: #f8f9fa;
    }

    .widget-title {
      font-weight: 600;
      color: #1a1a2e;
      font-size: 0.9375rem;

      i {
        color: #303234;
      }
    }

    .widget-body {
      padding: 1.25rem;
    }

    .rate-circle-container {
      display: flex;
      justify-content: center;
      margin-bottom: 1.5rem;
    }

    .rate-circle {
      width: 120px;
      height: 120px;
      border-radius: 50%;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      position: relative;

      &::before {
        content: '';
        position: absolute;
        width: 90px;
        height: 90px;
        background: #fff;
        border-radius: 50%;
      }

      .rate-value {
        position: relative;
        z-index: 1;
      }

      .rate-number {
        font-size: 1.75rem;
        font-weight: 700;
        color: #1a1a2e;
      }

      .rate-percent {
        font-size: 0.875rem;
        color: #6c757d;
      }

      .rate-label {
        position: relative;
        z-index: 1;
        font-size: 0.6875rem;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }
    }

    .breakdown-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 0.5rem;
      text-align: center;
    }

    .breakdown-item {
      padding: 0.75rem 0.5rem;
      background: #f8f9fa;
      border-radius: 8px;

      .breakdown-value {
        font-size: 1.25rem;
        font-weight: 700;
        line-height: 1;
        margin-bottom: 0.25rem;
      }

      .breakdown-label {
        font-size: 0.6875rem;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }
    }

    .attendance-bar {
      .bar-segments {
        display: flex;
        height: 8px;
        border-radius: 4px;
        overflow: hidden;
        background: #e9ecef;
      }

      .segment {
        transition: width 0.3s ease;

        &.present { background: #198754; }
        &.late { background: #ffc107; }
        &.absent { background: #dc3545; }
      }

      .bar-legend {
        display: flex;
        justify-content: center;
        gap: 1rem;
        margin-top: 0.5rem;

        .legend-item {
          display: flex;
          align-items: center;
          gap: 0.25rem;
          font-size: 0.75rem;
          color: #6c757d;
        }

        .dot {
          width: 8px;
          height: 8px;
          border-radius: 50%;

          &.present { background: #198754; }
          &.late { background: #ffc107; }
          &.absent { background: #dc3545; }
        }
      }
    }

    .today-sessions {
      .today-session-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 0;
        border-bottom: 1px dashed #e9ecef;

        &:last-child {
          border-bottom: none;
        }

        .session-info {
          display: flex;
          flex-direction: column;
        }

        .session-title {
          font-weight: 500;
          font-size: 0.875rem;
          color: #1a1a2e;
        }

        .session-time {
          font-size: 0.75rem;
        }

        .badge {
          font-size: 0.6875rem;
        }
      }
    }

    .empty-state i {
      display: block;
    }
  `]
})
export class AttendanceWidgetComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  @Input() trainingId?: number;

  stats: AttendanceStats | null = null;
  todaySessions: TrainingSession[] = [];
  loading = true;

  constructor(private liveTrainingService: LiveTrainingService) {}

  ngOnInit(): void {
    this.loadStats();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadStats(): void {
    // If specific training, load its analytics
    if (this.trainingId) {
      this.liveTrainingService.getTrainingAnalytics(this.trainingId)
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          next: data => {
            this.stats = {
              total_sessions: data.sessions.total,
              completed_sessions: data.sessions.completed,
              total_expected: data.enrollments.active * data.sessions.completed,
              total_present: data.attendance.present,
              total_late: data.attendance.late,
              total_absent: data.attendance.absent,
              attendance_rate: data.attendance.average_percentage
            };
            this.loading = false;
          },
          error: () => {
            this.loading = false;
          }
        });
    } else {
      // Load overall stats from multiple trainings
      this.liveTrainingService.getTrainings({ status: 'ongoing' })
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          next: trainings => {
            // Aggregate stats from all trainings
            // This is simplified - in real app you'd have a dedicated endpoint
            this.stats = {
              total_sessions: trainings.reduce((sum, t) => sum + (t.session_count || 0), 0),
              completed_sessions: 0,
              total_expected: 0,
              total_present: 0,
              total_late: 0,
              total_absent: 0,
              attendance_rate: 85 // Placeholder
            };
            this.loading = false;
          },
          error: () => {
            this.loading = false;
          }
        });
    }

    // Load today's sessions
    this.loadTodaySessions();
  }

  loadTodaySessions(): void {
    this.liveTrainingService.getUpcomingSessions(5)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: sessions => {
          const today = new Date().toDateString();
          this.todaySessions = sessions.filter(s =>
            new Date(s.session_date).toDateString() === today
          );
        }
      });
  }

  getPercentage(count: number): number {
    if (!this.stats) return 0;
    const total = this.stats.total_present + this.stats.total_late + this.stats.total_absent;
    return total > 0 ? Math.round((count / total) * 100) : 0;
  }

  formatTime(time: string): string {
    const [hours, minutes] = time.split(':');
    const h = parseInt(hours);
    const ampm = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 || 12;
    return `${h12}:${minutes} ${ampm}`;
  }

  getStatusBadgeClass(status: string): string {
    switch (status) {
      case 'scheduled': return 'bg-info';
      case 'ongoing': return 'bg-warning text-dark';
      case 'completed': return 'bg-success';
      case 'cancelled': return 'bg-danger';
      default: return 'bg-secondary';
    }
  }
}
