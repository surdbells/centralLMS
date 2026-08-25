// sessions-list/sessions-list.component.ts
import { Component, OnInit, OnDestroy, ChangeDetectionStrategy, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, debounceTime, takeUntil } from 'rxjs';

// Shared Components
import { StatusBadgeComponent } from '../../shared/components/status-badge/status-badge.component';
import { PaginationComponent } from '../../shared/components/pagination/pagination.component';
import { LoadingSpinnerComponent } from '../../shared/components/loading-spinner/loading-spinner.component';

// Services
import { ProctoringService, ProctoringSession } from '../../services/proctoring.service';

@Component({
  selector: 'app-sessions-list',
  standalone: true,
  imports: [CommonModule, FormsModule, StatusBadgeComponent, PaginationComponent, LoadingSpinnerComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="sessions-list-container">
      <!-- Header -->
      <div class="page-header">
        <div class="header-left">
          <h1 class="page-title">Proctoring Sessions</h1>
          <p class="page-subtitle">Monitor and review assessment sessions</p>
        </div>
        <div class="header-actions">
          <button class="btn btn-secondary" (click)="exportSessions()">
            <i class="bi bi-download me-1"></i> Export
          </button>
          <button class="btn btn-primary" (click)="viewLiveMonitor()" *ngIf="stats.active > 0">
            <i class="bi bi-broadcast me-1"></i> Live Monitor ({{ stats.active }})
          </button>
        </div>
      </div>

      <!-- Stats Cards -->
      <div class="stats-row mb-4">
        <div class="stat-card" [class.active]="filters.status === null" (click)="filterByStatus(null)">
          <div class="stat-icon"><i class="bi bi-collection text-primary"></i></div>
          <div class="stat-content">
            <span class="stat-value">{{ stats.total }}</span>
            <span class="stat-label">Total Sessions</span>
          </div>
        </div>
        <div class="stat-card live" [class.active]="filters.status === 'active'" (click)="filterByStatus('active')">
          <div class="stat-icon"><i class="bi bi-broadcast text-success"></i></div>
          <div class="stat-content">
            <span class="stat-value">{{ stats.active }}</span>
            <span class="stat-label">Live Now</span>
          </div>
          <span class="live-indicator" *ngIf="stats.active > 0"></span>
        </div>
        <div class="stat-card" [class.active]="filters.flagged === true" (click)="toggleFlagged()">
          <div class="stat-icon"><i class="bi bi-flag text-danger"></i></div>
          <div class="stat-content">
            <span class="stat-value">{{ stats.flagged }}</span>
            <span class="stat-label">Flagged</span>
          </div>
        </div>
        <div class="stat-card" [class.active]="filters.status === 'needs_review'" (click)="filterByStatus('needs_review')">
          <div class="stat-icon"><i class="bi bi-eye text-warning"></i></div>
          <div class="stat-content">
            <span class="stat-value">{{ stats.needs_review }}</span>
            <span class="stat-label">Needs Review</span>
          </div>
        </div>
        <div class="stat-card" [class.active]="filters.status === 'reviewed'" (click)="filterByStatus('reviewed')">
          <div class="stat-icon"><i class="bi bi-check-circle text-info"></i></div>
          <div class="stat-content">
            <span class="stat-value">{{ stats.reviewed }}</span>
            <span class="stat-label">Reviewed</span>
          </div>
        </div>
      </div>

      <!-- Filters -->
      <div class="filters-bar card mb-3">
        <div class="card-body py-2">
          <div class="row g-2 align-items-center">
            <div class="col-md-3">
              <div class="input-group input-group-sm">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" class="form-control" placeholder="Search learner..."
                       [(ngModel)]="filters.search" (ngModelChange)="onSearchChange()">
              </div>
            </div>
            <div class="col-md-2">
              <select class="form-select form-select-sm" [(ngModel)]="filters.assessment_id" (ngModelChange)="loadSessions()">
                <option [ngValue]="null">All Assessments</option>
                <option *ngFor="let a of assessments" [ngValue]="a.assessment_id">{{ a.title }}</option>
              </select>
            </div>
            <div class="col-md-2">
              <select class="form-select form-select-sm" [(ngModel)]="filters.proctoring_level" (ngModelChange)="loadSessions()">
                <option [ngValue]="null">All Levels</option>
                <option value="basic">Basic</option>
                <option value="standard">Standard</option>
                <option value="advanced">Advanced</option>
              </select>
            </div>
            <div class="col-md-2">
              <select class="form-select form-select-sm" [(ngModel)]="filters.trust_score" (ngModelChange)="loadSessions()">
                <option [ngValue]="null">All Trust Scores</option>
                <option value="high">High (80-100)</option>
                <option value="medium">Medium (50-79)</option>
                <option value="low">Low (0-49)</option>
              </select>
            </div>
            <div class="col-md-2">
              <select class="form-select form-select-sm" [(ngModel)]="filters.sort" (ngModelChange)="loadSessions()">
                <option value="started_at_desc">Newest First</option>
                <option value="started_at_asc">Oldest First</option>
                <option value="trust_score_asc">Trust Score ↑</option>
                <option value="violations_desc">Most Violations</option>
              </select>
            </div>
            <div class="col-md-1 text-end">
              <button class="btn btn-sm btn-secondary" (click)="resetFilters()">
                <i class="bi bi-arrow-counterclockwise"></i>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Loading -->
      <app-loading-spinner *ngIf="isLoading" type="block" text="Loading sessions..."></app-loading-spinner>

      <!-- Sessions Table -->
      <div class="sessions-table card" *ngIf="!isLoading">
        <div class="table-responsive">
          <table class="table table-hover mb-0">
            <thead>
              <tr>
                <th>Learner</th>
                <th>Assessment</th>
                <th>Level</th>
                <th>Started</th>
                <th>Duration</th>
                <th>Trust Score</th>
                <th>Violations</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr *ngFor="let session of sessions"
                  [class.flagged]="session.is_flagged"
                  [class.live]="session.status === 'active'">
                <td>
                  <div class="learner-cell">
                    <div class="learner-avatar" *ngIf="session.photo_url">
                      <img [src]="session.photo_url" alt="Photo">
                    </div>
                    <div class="learner-info">
                      <span class="learner-name">{{ session.learner_name }}</span>
                      <small class="text-muted d-block">{{ session.learner_email }}</small>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="assessment-name">{{ session.assessment_title }}</span>
                </td>
                <td>
                  <app-status-badge [value]="session.proctoring_level" type="proctoring" size="sm"></app-status-badge>
                </td>
                <td>
                  <span>{{ session.started_at | date:'short' }}</span>
                </td>
                <td>
                  <span>{{ formatDuration(session.duration_seconds) }}</span>
                  <span class="live-badge" *ngIf="session.status === 'active'">LIVE</span>
                </td>
                <td>
                  <div class="trust-score-cell">
                    <div class="trust-score-bar">
                      <div class="trust-score-fill" [style.width.%]="session.trust_score"
                           [class.high]="session.trust_score >= 80"
                           [class.medium]="session.trust_score >= 50 && session.trust_score < 80"
                           [class.low]="session.trust_score < 50"></div>
                    </div>
                    <span class="trust-score-value" [class.low]="session.trust_score < 50">
                      {{ session.trust_score }}%
                    </span>
                  </div>
                </td>
                <td>
                  <div class="violations-cell">
                    <span class="violation-count" [class.has-violations]="session.violation_count > 0">
                      {{ session.violation_count }}
                    </span>
                    <div class="violation-breakdown" *ngIf="session.violation_count > 0">
                      <span *ngIf="session.critical_violations" class="critical" title="Critical">
                        <i class="bi bi-exclamation-octagon-fill"></i>{{ session.critical_violations }}
                      </span>
                      <span *ngIf="session.high_violations" class="high" title="High">
                        <i class="bi bi-exclamation-triangle-fill"></i>{{ session.high_violations }}
                      </span>
                    </div>
                  </div>
                </td>
                <td>
                  <span class="status-badge" [ngClass]="getStatusClass(session)">
                    <i class="bi" [ngClass]="getStatusIcon(session)"></i>
                    {{ getStatusLabel(session) }}
                  </span>
                </td>
                <td>
                  <div class="action-buttons">
                    <button class="btn btn-sm btn-primary" (click)="reviewSession(session)" title="Review">
                      <i class="bi bi-eye"></i>
                    </button>
                    <button class="btn btn-sm btn-secondary" (click)="toggleFlag(session)"
                            [title]="session.is_flagged ? 'Unflag' : 'Flag'">
                      <i class="bi" [ngClass]="session.is_flagged ? 'bi-flag-fill text-danger' : 'bi-flag'"></i>
                    </button>
                    <button class="btn btn-sm btn-secondary dropdown-toggle" data-bs-toggle="dropdown">
                      <i class="bi bi-three-dots"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                      <li><a class="dropdown-item" (click)="viewEvidence(session)"><i class="bi bi-camera-video me-2"></i>View Evidence</a></li>
                      <li><a class="dropdown-item" (click)="viewTimeline(session)"><i class="bi bi-clock-history me-2"></i>Event Timeline</a></li>
                      <li><a class="dropdown-item" (click)="downloadReport(session)"><i class="bi bi-file-pdf me-2"></i>Download Report</a></li>
                      <li><hr class="dropdown-divider"></li>
                      <li><a class="dropdown-item" (click)="markReviewed(session)" *ngIf="session.review_status !== 'reviewed'">
                        <i class="bi bi-check-circle me-2"></i>Mark Reviewed</a></li>
                      <li><a class="dropdown-item text-danger" (click)="invalidateSession(session)">
                        <i class="bi bi-x-octagon me-2"></i>Invalidate Session</a></li>
                    </ul>
                  </div>
                </td>
              </tr>
              <tr *ngIf="sessions.length === 0">
                <td colspan="9" class="text-center py-5">
                  <div class="empty-state">
                    <i class="bi bi-shield-check"></i>
                    <h5>No sessions found</h5>
                    <p class="text-muted">No proctoring sessions match your filters</p>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="card-footer" *ngIf="totalItems > 0">
          <app-pagination
            [currentPage]="currentPage"
            [pageSize]="pageSize"
            [totalItems]="totalItems"
            [pageSizeOptions]="[20, 50, 100]"
            (pageChange)="onPageChange($event)"
            (pageSizeChange)="onPageSizeChange($event)">
          </app-pagination>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .sessions-list-container { padding: 1.5rem; }
    .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; }
    .page-title { margin: 0; font-size: 1.75rem; font-weight: 600; }
    .page-subtitle { margin: 0.25rem 0 0; color: #6c757d; }

    .stats-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 1rem; }
    .stat-card { display: flex; align-items: center; gap: 0.75rem; padding: 1rem; background: #fff; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.1); cursor: pointer; border: 2px solid transparent; transition: all 0.15s; position: relative; }
    .stat-card:hover { border-color: #dee2e6; }
    .stat-card.active { border-color: #0d6efd; background: #e7f1ff; }
    .stat-card.live.active { border-color: #198754; background: #d1e7dd; }
    .stat-icon { font-size: 1.5rem; }
    .stat-value { display: block; font-size: 1.5rem; font-weight: 600; }
    .stat-label { font-size: 0.75rem; color: #6c757d; }
    .live-indicator { position: absolute; top: 8px; right: 8px; width: 10px; height: 10px; background: #198754; border-radius: 50%; animation: pulse 2s infinite; }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }

    tr.flagged { background: #fff5f5; border-left: 3px solid #dc3545; }
    tr.live { background: #f0fff4; }

    .learner-cell { display: flex; align-items: center; gap: 0.75rem; }
    .learner-avatar { width: 36px; height: 36px; border-radius: 50%; overflow: hidden; }
    .learner-avatar img { width: 100%; height: 100%; object-fit: cover; }
    .learner-name { font-weight: 500; }

    .live-badge { display: inline-block; padding: 0.125rem 0.375rem; background: #198754; color: #fff; font-size: 0.625rem; font-weight: 700; border-radius: 0.25rem; margin-left: 0.5rem; animation: pulse 2s infinite; }

    .trust-score-cell { display: flex; align-items: center; gap: 0.5rem; }
    .trust-score-bar { width: 60px; height: 6px; background: #e9ecef; border-radius: 3px; overflow: hidden; }
    .trust-score-fill { height: 100%; transition: width 0.3s; }
    .trust-score-fill.high { background: #198754; }
    .trust-score-fill.medium { background: #ffc107; }
    .trust-score-fill.low { background: #dc3545; }
    .trust-score-value { font-weight: 600; font-size: 0.875rem; }
    .trust-score-value.low { color: #dc3545; }

    .violations-cell { display: flex; align-items: center; gap: 0.5rem; }
    .violation-count { font-weight: 600; }
    .violation-count.has-violations { color: #dc3545; }
    .violation-breakdown { display: flex; gap: 0.25rem; }
    .violation-breakdown span { font-size: 0.75rem; display: flex; align-items: center; gap: 2px; }
    .violation-breakdown .critical { color: #dc3545; }
    .violation-breakdown .high { color: #fd7e14; }

    .status-badge { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 500; }
    .status-badge.active { background: #d1e7dd; color: #0f5132; }
    .status-badge.completed { background: #e2e3e5; color: #41464b; }
    .status-badge.needs-review { background: #fff3cd; color: #664d03; }
    .status-badge.reviewed { background: #cfe2ff; color: #084298; }
    .status-badge.flagged { background: #f8d7da; color: #842029; }

    .action-buttons { display: flex; gap: 0.25rem; }
    .empty-state i { font-size: 3rem; color: #dee2e6; }

    @media (max-width: 1200px) { .stats-row { grid-template-columns: repeat(3, 1fr); } }
  `]
})
export class SessionsListComponent implements OnInit, OnDestroy {
  sessions: ProctoringSession[] = [];
  assessments: any[] = [];
  isLoading = false;
  currentPage = 1;
  pageSize = 20;
  totalItems = 0;

  filters = {
    search: '',
    status: null as string | null,
    assessment_id: null as number | null,
    proctoring_level: null as string | null,
    trust_score: null as string | null,
    flagged: null as boolean | null,
    sort: 'started_at_desc'
  };

  stats = { total: 0, active: 0, flagged: 0, needs_review: 0, reviewed: 0 };

  private destroy$ = new Subject<void>();
  private searchSubject = new Subject<void>();

  constructor(private proctoringService: ProctoringService, private router: Router, private cdr: ChangeDetectorRef) {}

  ngOnInit(): void {
    this.loadAssessments();
    this.loadSessions();
    this.loadStats();
    this.searchSubject.pipe(debounceTime(300), takeUntil(this.destroy$)).subscribe(() => this.loadSessions());
  }

  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }

  loadAssessments(): void {
    this.proctoringService.getProctoredAssessments().subscribe(a => { this.assessments = a; this.cdr.markForCheck(); });
  }

  loadSessions(): void {
    this.isLoading = true;
    this.cdr.markForCheck();
    this.proctoringService.getSessions({ page: this.currentPage, limit: this.pageSize, ...this.filters }).subscribe({
      next: (r) => { this.sessions = r.sessions || []; this.totalItems = r.total || 0; this.isLoading = false; this.cdr.markForCheck(); },
      error: () => { this.isLoading = false; this.cdr.markForCheck(); }
    });
  }

  loadStats(): void {
    this.proctoringService.getSessionStats().subscribe(s => { this.stats = s; this.cdr.markForCheck(); });
  }

  onSearchChange(): void { this.searchSubject.next(); }
  resetFilters(): void { this.filters = { search: '', status: null, assessment_id: null, proctoring_level: null, trust_score: null, flagged: null, sort: 'started_at_desc' }; this.loadSessions(); }
  filterByStatus(status: string | null): void { this.filters.status = status; this.filters.flagged = null; this.loadSessions(); }
  toggleFlagged(): void { this.filters.flagged = this.filters.flagged ? null : true; this.filters.status = null; this.loadSessions(); }
  onPageChange(p: number): void { this.currentPage = p; this.loadSessions(); }
  onPageSizeChange(s: number): void { this.pageSize = s; this.currentPage = 1; this.loadSessions(); }

  reviewSession(session: ProctoringSession): void { this.router.navigate(['/admin/proctoring/sessions', session.session_id]); }
  viewEvidence(session: ProctoringSession): void { this.router.navigate(['/admin/proctoring/sessions', session.session_id, 'evidence']); }
  viewTimeline(session: ProctoringSession): void { this.router.navigate(['/admin/proctoring/sessions', session.session_id, 'timeline']); }
  viewLiveMonitor(): void { this.router.navigate(['/admin/proctoring/live']); }

  toggleFlag(session: ProctoringSession): void {
    this.proctoringService.toggleSessionFlag(session.session_id).subscribe(() => { this.loadSessions(); this.loadStats(); });
  }

  markReviewed(session: ProctoringSession): void {
    this.proctoringService.updateSessionReviewStatus(session.session_id, 'reviewed').subscribe(() => { this.loadSessions(); this.loadStats(); });
  }

  invalidateSession(session: ProctoringSession): void {
    if (confirm(`Invalidate session for ${session.learner_name}? This will void their attempt.`)) {
      this.proctoringService.invalidateSession(session.session_id).subscribe(() => { this.loadSessions(); this.loadStats(); });
    }
  }

  downloadReport(session: ProctoringSession): void { this.proctoringService.downloadSessionReport(session.session_id).subscribe(); }
  exportSessions(): void { this.proctoringService.exportSessions(this.filters).subscribe(); }

  formatDuration(seconds: number): string {
    if (!seconds) return '-';
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return `${mins}:${secs.toString().padStart(2, '0')}`;
  }

  getStatusClass(session: ProctoringSession): string {
    if (session.is_flagged) return 'flagged';
    if (session.status === 'active') return 'active';
    if (session.review_status === 'reviewed') return 'reviewed';
    if (session.review_status === 'needs_review') return 'needs-review';
    return 'completed';
  }

  getStatusIcon(session: ProctoringSession): string {
    if (session.is_flagged) return 'bi-flag-fill';
    if (session.status === 'active') return 'bi-broadcast';
    if (session.review_status === 'reviewed') return 'bi-check-circle';
    if (session.review_status === 'needs_review') return 'bi-eye';
    return 'bi-circle';
  }

  getStatusLabel(session: ProctoringSession): string {
    if (session.is_flagged) return 'Flagged';
    if (session.status === 'active') return 'Live';
    if (session.review_status === 'reviewed') return 'Reviewed';
    if (session.review_status === 'needs_review') return 'Needs Review';
    return 'Completed';
  }
}
