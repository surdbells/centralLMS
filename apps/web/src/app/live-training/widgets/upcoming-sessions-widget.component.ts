// live-training/widgets/upcoming-sessions-widget.component.ts

import { Component, OnInit, OnDestroy, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterModule } from '@angular/router';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { LiveTrainingService, TrainingSession } from '../live-training.service';

@Component({
  selector: 'app-upcoming-sessions-widget',
  standalone: true,
  imports: [CommonModule, RouterModule],
  template: `
    <div class="upcoming-sessions-widget">
      <div class="widget-header">
        <h6 class="widget-title mb-0">
          <i class="bi bi-calendar-event me-2"></i>
          Upcoming Sessions
        </h6>
        <a routerLink="/live-training" class="btn btn-sm btn-link text-decoration-none">
          View All <i class="bi bi-arrow-right"></i>
        </a>
      </div>

      <div class="widget-body">
        <!-- Loading -->
        <div *ngIf="loading" class="text-center py-4">
          <div class="spinner-border spinner-border-sm text-primary" role="status"></div>
        </div>

        <!-- Sessions List -->
        <div *ngIf="!loading && sessions.length > 0" class="sessions-list">
          <div *ngFor="let session of sessions" class="session-item" [class.today]="isToday(session.session_date)">
            <div class="session-date">
              <div class="date-day">{{ getDay(session.session_date) }}</div>
              <div class="date-month">{{ getMonth(session.session_date) }}</div>
            </div>
            <div class="session-info">
              <div class="session-title">{{ session.title }}</div>
              <div class="session-meta">
                <span class="text-muted">
                  <i class="bi bi-clock me-1"></i>
                  {{ formatTime(session.start_time) }}
                </span>
                <span class="badge" [ngClass]="getTypeBadgeClass(session.session_type)">
                  <i class="bi me-1" [ngClass]="getTypeIcon(session.session_type)"></i>
                  {{ session.session_type | titlecase }}
                </span>
              </div>
              <div class="session-training text-muted small">
                {{ session.training_title }}
              </div>
            </div>
            <div class="session-action">
              <button *ngIf="canJoin(session)"
                      class="btn btn-sm btn-primary"
                      (click)="joinSession(session)">
                <i class="bi bi-box-arrow-up-right me-1"></i>Join
              </button>
              <span *ngIf="!canJoin(session)" class="time-until text-muted small">
                {{ getTimeUntil(session) }}
              </span>
            </div>
          </div>
        </div>

        <!-- Empty State -->
        <div *ngIf="!loading && sessions.length === 0" class="empty-state text-center py-4">
          <i class="bi bi-calendar-x display-6 text-muted mb-2"></i>
          <p class="text-muted mb-0 small">No upcoming sessions</p>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .upcoming-sessions-widget {
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
      padding: 0;
    }

    .sessions-list {
      max-height: 350px;
      overflow-y: auto;
    }

    .session-item {
      display: flex;
      align-items: center;
      gap: 1rem;
      padding: 1rem 1.25rem;
      border-bottom: 1px solid #f0f0f0;
      transition: background 0.15s ease;

      &:last-child {
        border-bottom: none;
      }

      &:hover {
        background: #f8f9fa;
      }

      &.today {
        background: rgba(201, 162, 39, 0.05);
        border-left: 3px solid #303234;
      }
    }

    .session-date {
      text-align: center;
      min-width: 45px;

      .date-day {
        font-size: 1.25rem;
        font-weight: 700;
        color: #1a1a2e;
        line-height: 1;
      }

      .date-month {
        font-size: 0.6875rem;
        text-transform: uppercase;
        color: #6c757d;
        letter-spacing: 0.5px;
      }
    }

    .session-info {
      flex: 1;
      min-width: 0;

      .session-title {
        font-weight: 600;
        color: #1a1a2e;
        margin-bottom: 0.25rem;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }

      .session-meta {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        margin-bottom: 0.25rem;

        .badge {
          font-size: 0.6875rem;
          padding: 0.25em 0.5em;
        }
      }
    }

    .session-action {
      flex-shrink: 0;

      .time-until {
        display: block;
        text-align: right;
        white-space: nowrap;
      }
    }

    .empty-state i {
      display: block;
    }
  `]
})
export class UpcomingSessionsWidgetComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();

  @Input() limit = 5;

  sessions: TrainingSession[] = [];
  loading = true;

  constructor(private liveTrainingService: LiveTrainingService) {}

  ngOnInit(): void {
    this.loadSessions();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadSessions(): void {
    this.liveTrainingService.getUpcomingSessions(this.limit)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: data => {
          this.sessions = data;
          this.loading = false;
        },
        error: () => {
          this.loading = false;
        }
      });
  }

  isToday(date: string): boolean {
    const today = new Date();
    const sessionDate = new Date(date);
    return today.toDateString() === sessionDate.toDateString();
  }

  getDay(date: string): string {
    return new Date(date).getDate().toString();
  }

  getMonth(date: string): string {
    return new Date(date).toLocaleString('en', { month: 'short' });
  }

  formatTime(time: string): string {
    const [hours, minutes] = time.split(':');
    const h = parseInt(hours);
    const ampm = h >= 12 ? 'PM' : 'AM';
    const h12 = h % 12 || 12;
    return `${h12}:${minutes} ${ampm}`;
  }

  getTypeIcon(type: string): string {
    switch (type) {
      case 'physical': return 'bi-building';
      case 'virtual': return 'bi-camera-video';
      case 'hybrid': return 'bi-diagram-3';
      default: return 'bi-calendar';
    }
  }

  getTypeBadgeClass(type: string): string {
    switch (type) {
      case 'physical': return 'bg-primary-subtle text-primary';
      case 'virtual': return 'bg-success-subtle text-success';
      case 'hybrid': return 'bg-purple-subtle text-purple';
      default: return 'bg-secondary';
    }
  }

  canJoin(session: TrainingSession): boolean {
    const now = new Date();
    const start = new Date(`${session.session_date}T${session.start_time}`);
    const end = new Date(`${session.session_date}T${session.end_time}`);
    const joinStart = new Date(start.getTime() - 15 * 60 * 1000);

    return now >= joinStart && now <= end && session.session_type !== 'physical';
  }

  getTimeUntil(session: TrainingSession): string {
    const now = new Date();
    const start = new Date(`${session.session_date}T${session.start_time}`);
    const diff = start.getTime() - now.getTime();

    if (diff < 0) return 'Started';

    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
    const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));

    if (days > 0) return `in ${days}d`;
    if (hours > 0) return `in ${hours}h`;
    return `in ${minutes}m`;
  }

  joinSession(session: TrainingSession): void {
    if (session.join_url) {
      window.open(session.join_url, '_blank');
    }
  }
}
