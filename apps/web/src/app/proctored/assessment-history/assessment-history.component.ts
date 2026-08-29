// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 14
// Learner: Assessment History Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { AssessmentsService, type AttemptResult } from '../../services/assessments.service';
import {TopComponent} from '../../partials/top/top.component';

// ==========================================
// INTERFACES
// ==========================================

interface ApiResponse<T> {
  code: number;
  status: string;
  data: T;
  message: string;
}

interface AttemptHistoryItem {
  attempt_id: string;
  assessment_id: string;
  assessment_title: string;
  assessment_type: string;
  category_name: string;
  attempt_number: number;
  max_attempts: number;
  status: string;
  started_at: string;
  completed_at: string | null;
  duration_seconds: number;
  total_score: number;
  max_score: number;
  percentage: number;
  passed: boolean;
  grade: string;
  grading_status: string;
  has_certificate: boolean;
  certificate_id: string | null;
  proctored: boolean;
  proctoring_level: string;
  violation_count: number;
}

interface HistoryStats {
  total_attempts: number;
  total_passed: number;
  total_failed: number;
  total_pending: number;
  total_in_progress: number;
  average_score: number;
  best_score: number;
  total_time_seconds: number;
  certificates_earned: number;
  pass_rate: number;
}

interface PaginationInfo {
  current_page: number;
  total_pages: number;
  total_records: number;
  per_page: number;
}

@Component({
  selector: 'app-assessment-history',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, TopComponent],
  template: `
    <app-top></app-top>
    <main class="main">
      <div class="container-fluid py-4">
        <!-- ==========================================
             PAGE HEADER
             ========================================== -->
        <div class="page-header mb-4">
          <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
              <h5 class="mb-1">
                <tui-icon icon="@tui.clock" class="me-2"></tui-icon> Assessment History
              </h5>
              <p class="text-muted mb-0" *ngIf="stats">
                {{ stats.total_attempts }} total attempts · {{ stats.pass_rate | number:'1.0-0' }}% pass rate
              </p>
            </div>
            <button class="btn btn-sm btn-secondary" (click)="exportHistory()">
              <tui-icon icon="@tui.download" class="me-1"></tui-icon> Export
            </button>
          </div>
        </div>

        <!-- ==========================================
             STATS CARDS
             ========================================== -->
        <div class="row g-3 mb-4" *ngIf="stats && !loading">
          <div class="col-6 col-md-2">
            <div class="stat-card">
              <div class="stat-value text-primary">{{ stats.total_attempts }}</div>
              <div class="stat-label">Total Attempts</div>
            </div>
          </div>
          <div class="col-6 col-md-2">
            <div class="stat-card">
              <div class="stat-value text-success">{{ stats.total_passed }}</div>
              <div class="stat-label">Passed</div>
            </div>
          </div>
          <div class="col-6 col-md-2">
            <div class="stat-card">
              <div class="stat-value text-danger">{{ stats.total_failed }}</div>
              <div class="stat-label">Failed</div>
            </div>
          </div>
          <div class="col-6 col-md-2">
            <div class="stat-card">
              <div class="stat-value text-info">{{ stats.average_score | number:'1.0-0' }}%</div>
              <div class="stat-label">Avg Score</div>
            </div>
          </div>
          <div class="col-6 col-md-2">
            <div class="stat-card">
              <div class="stat-value text-warning">{{ stats.best_score | number:'1.0-0' }}%</div>
              <div class="stat-label">Best Score</div>
            </div>
          </div>
          <div class="col-6 col-md-2">
            <div class="stat-card">
              <div class="stat-value">{{ stats.certificates_earned }}</div>
              <div class="stat-label">Certificates</div>
            </div>
          </div>
        </div>

        <!-- ==========================================
             FILTERS
             ========================================== -->
        <div class="filter-bar mb-3">
          <div class="row g-2 align-items-end">
            <div class="col-md-3">
              <div class="input-group input-group-sm">
                <span class="input-group-text"><tui-icon icon="@tui.search"></tui-icon></span>
                <input type="text" class="form-control" placeholder="Search assessments..."
                       [(ngModel)]="filters.search" (ngModelChange)="onFilterChange()">
              </div>
            </div>
            <div class="col-md-2">
              <select class="form-select form-select-sm" [(ngModel)]="filters.type" (ngModelChange)="onFilterChange()">
                <option value="">All Types</option>
                <option value="exam">Exam</option>
                <option value="quiz">Quiz</option>
                <option value="practice">Practice</option>
                <option value="survey">Survey</option>
                <option value="assignment">Assignment</option>
              </select>
            </div>
            <div class="col-md-2">
              <select class="form-select form-select-sm" [(ngModel)]="filters.status" (ngModelChange)="onFilterChange()">
                <option value="">All Status</option>
                <option value="completed">Completed</option>
                <option value="in_progress">In Progress</option>
                <option value="submitted">Submitted</option>
                <option value="abandoned">Abandoned</option>
              </select>
            </div>
            <div class="col-md-2">
              <select class="form-select form-select-sm" [(ngModel)]="filters.result" (ngModelChange)="onFilterChange()">
                <option value="">All Results</option>
                <option value="passed">Passed</option>
                <option value="failed">Failed</option>
                <option value="pending">Pending Grading</option>
              </select>
            </div>
            <div class="col-md-2">
              <select class="form-select form-select-sm" [(ngModel)]="filters.sort" (ngModelChange)="onFilterChange()">
                <option value="newest">Newest First</option>
                <option value="oldest">Oldest First</option>
                <option value="score_high">Highest Score</option>
                <option value="score_low">Lowest Score</option>
                <option value="title_az">Title A-Z</option>
              </select>
            </div>
            <div class="col-md-1">
              <button class="btn btn-sm btn-secondary w-100" (click)="clearFilters()" title="Clear filters">
                <tui-icon icon="@tui.x"></tui-icon>
              </button>
            </div>
          </div>
        </div>

        <!-- ==========================================
             LOADING
             ========================================== -->
        <div class="text-center py-5" *ngIf="loading">
          <div class="spinner-border text-primary"></div>
          <p class="mt-2 text-muted">Loading history...</p>
        </div>

        <!-- ==========================================
             HISTORY TABLE
             ========================================== -->
        <div class="card" *ngIf="!loading">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th style="width: 30%">Assessment</th>
                  <th class="text-center">Attempt</th>
                  <th class="text-center">Score</th>
                  <th class="text-center">Result</th>
                  <th class="text-center">Grade</th>
                  <th>Date</th>
                  <th class="text-center">Duration</th>
                  <th class="text-center">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr *ngFor="let item of attempts; trackBy: trackByAttempt" class="history-row"
                    [class.in-progress]="item.status === 'in_progress'">
                  <td>
                    <div class="assessment-info">
                      <div class="d-flex align-items-center gap-2">
                        <span class="badge badge-sm" [ngClass]="getTypeBadgeClass(item.assessment_type)">
                          {{ item.assessment_type }}
                        </span>
                        <tui-icon *ngIf="item.proctored" icon="@tui.eye" class="text-info"
                                  title="Proctored Assessment"></tui-icon>
                      </div>
                      <h6 class="assessment-title mb-0 mt-1">{{ item.assessment_title }}</h6>
                      <small class="text-muted" *ngIf="item.category_name">{{ item.category_name }}</small>
                    </div>
                  </td>
                  <td class="text-center">
                    <span class="attempt-badge">
                      {{ item.attempt_number }}
                      <small *ngIf="item.max_attempts > 0">/{{ item.max_attempts }}</small>
                    </span>
                  </td>
                  <td class="text-center">
                    <div *ngIf="item.status === 'completed' || item.status === 'submitted'">
                      <span class="score-value" [class.text-success]="item.percentage >= 70"
                            [class.text-warning]="item.percentage >= 50 && item.percentage < 70"
                            [class.text-danger]="item.percentage < 50">
                        {{ item.percentage | number:'1.0-0' }}%
                      </span>
                      <div class="score-detail">
                        {{ item.total_score | number:'1.0-1' }}/{{ item.max_score | number:'1.0-1' }}
                      </div>
                    </div>
                    <span *ngIf="item.status === 'in_progress'" class="text-muted">-</span>
                    <span *ngIf="item.status === 'abandoned'" class="text-muted">N/A</span>
                  </td>
                  <td class="text-center">
                    <span class="badge" [ngClass]="getResultBadgeClass(item)">
                      {{ getResultLabel(item) }}
                    </span>
                    <div *ngIf="item.violation_count > 0" class="mt-1">
                      <small class="text-danger">
                        <tui-icon icon="@tui.alert-triangle" class="me-1"></tui-icon>
                        {{ item.violation_count }} violation(s)
                      </small>
                    </div>
                  </td>
                  <td class="text-center">
                    <span *ngIf="item.grade && item.status === 'completed'" class="grade-badge">{{ item.grade }}</span>
                    <span *ngIf="item.status !== 'completed'" class="text-muted">-</span>
                  </td>
                  <td>
                    <div class="date-info">
                      <span class="date-primary">{{ formatDate(item.started_at) }}</span>
                      <small class="text-muted d-block" *ngIf="item.completed_at">
                        Completed: {{ formatDate(item.completed_at) }}
                      </small>
                    </div>
                  </td>
                  <td class="text-center">
                    <span *ngIf="item.duration_seconds > 0">{{ formatDuration(item.duration_seconds) }}</span>
                    <span *ngIf="!item.duration_seconds" class="text-muted">-</span>
                  </td>
                  <td class="text-center">
                    <div class="d-flex justify-content-center gap-1">
                      <button class="btn btn-sm btn-primary" (click)="viewResults(item)"
                              *ngIf="item.status === 'completed' || item.status === 'submitted'"
                              title="View Results">
                        <tui-icon icon="@tui.eye"></tui-icon>
                      </button>
                      <button class="btn btn-sm btn-outline-warning" (click)="resumeAttempt(item)"
                              *ngIf="item.status === 'in_progress'" title="Resume">
                        <tui-icon icon="@tui.play"></tui-icon>
                      </button>
                      <button class="btn btn-sm btn-success" (click)="viewCertificate(item)"
                              *ngIf="item.has_certificate" title="View Certificate">
                        <tui-icon icon="@tui.award"></tui-icon>
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>

            <!-- No Results -->
            <div class="empty-state" *ngIf="attempts.length === 0">
              <tui-icon icon="@tui.clock" class="empty-icon"></tui-icon>
              <h6>No Assessment History</h6>
              <p *ngIf="hasActiveFilters()">No results match your filters. Try adjusting them.</p>
              <p *ngIf="!hasActiveFilters()">You haven't taken any assessments yet.</p>
              <button class="btn btn-primary btn-sm" *ngIf="!hasActiveFilters()" (click)="goToAssessments()">
                <tui-icon icon="@tui.file-text" class="me-1"></tui-icon> Browse Assessments
              </button>
              <button class="btn btn-secondary btn-sm" *ngIf="hasActiveFilters()" (click)="clearFilters()">
                <tui-icon icon="@tui.x" class="me-1"></tui-icon> Clear Filters
              </button>
            </div>
          </div>
        </div>

        <!-- ==========================================
             PAGINATION
             ========================================== -->
        <div class="pagination-bar mt-3" *ngIf="pagination && pagination.total_pages > 1">
          <div class="d-flex justify-content-between align-items-center">
            <small class="text-muted">
              Showing {{ getShowingStart() }}-{{ getShowingEnd() }} of {{ pagination.total_records }}
            </small>
            <nav>
              <ul class="pagination pagination-sm mb-0">
                <li class="page-item" [class.disabled]="pagination.current_page <= 1">
                  <a class="page-link" href="javascript:void(0)" (click)="goToPage(pagination.current_page - 1)">
                    <tui-icon icon="@tui.chevron-left"></tui-icon>
                  </a>
                </li>
                <li class="page-item" *ngFor="let page of getPageNumbers()"
                    [class.active]="page === pagination.current_page"
                    [class.disabled]="page === -1">
                  <a class="page-link" href="javascript:void(0)" (click)="page !== -1 && goToPage(page)">
                    {{ page === -1 ? '...' : page }}
                  </a>
                </li>
                <li class="page-item" [class.disabled]="pagination.current_page >= pagination.total_pages">
                  <a class="page-link" href="javascript:void(0)" (click)="goToPage(pagination.current_page + 1)">
                    <tui-icon icon="@tui.chevron-right"></tui-icon>
                  </a>
                </li>
              </ul>
            </nav>
            <select class="form-select form-select-sm" style="width: 90px;"
                    [(ngModel)]="filters.perPage" (ngModelChange)="onFilterChange()">
              <option [value]="10">10</option>
              <option [value]="25">25</option>
              <option [value]="50">50</option>
            </select>
          </div>
        </div>
      </div>
    </main>
  `,
  styles: [`
    :host { display: block; }

    .stat-card {
      background: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem;
      padding: 1rem; text-align: center;
      .stat-value { font-size: 1.25rem; font-weight: 700; }
      .stat-label { font-size: 0.75rem; color: #6c757d; margin-top: 0.25rem; }
    }

    .filter-bar { background: #f8f9fa; border-radius: 0.5rem; padding: 0.75rem; }

    .history-row {
      &.in-progress { background: #fffef5; }
      &:hover { background: #f8f9fa; }
    }

    .assessment-info {
      .assessment-title { font-size: 0.9rem; font-weight: 600; color: #212529; }
    }

    .badge-sm { font-size: 0.65rem; padding: 0.15rem 0.4rem; text-transform: uppercase; }

    .attempt-badge {
      display: inline-block; background: #e9ecef; padding: 0.2rem 0.5rem;
      border-radius: 0.25rem; font-weight: 600; font-size: 0.85rem;
    }

    .score-value { font-size: 1rem; font-weight: 700; display: block; }
    .score-detail { font-size: 0.75rem; color: #6c757d; }

    .grade-badge {
      display: inline-block; background: #c9a227; color: #fff;
      width: 32px; height: 32px; line-height: 32px; border-radius: 50%;
      text-align: center; font-weight: 700; font-size: 0.8rem;
    }

    .date-info { .date-primary { font-size: 0.85rem; color: #212529; } }

    .empty-state {
      text-align: center; padding: 3rem 1rem; color: #6c757d;
      .empty-icon { font-size: 3rem; margin-bottom: 1rem; opacity: 0.3; }
    }

    .pagination-bar { padding: 0.5rem 0; }

    @media (max-width: 768px) {
      .stat-card { padding: 0.75rem; .stat-value { font-size: 1rem; } }
      .filter-bar .row > div { margin-bottom: 0.25rem; }
    }
  `]
})
export class AssessmentHistoryComponent implements OnInit, OnDestroy {
  private assessments = inject(AssessmentsService);
  private router = inject(Router);
  private destroy$ = new Subject<void>();

  // State
  loading = true;
  attempts: AttemptHistoryItem[] = [];
  /** The full history, filtered/paginated into `attempts` on the client. */
  private allAttempts: AttemptHistoryItem[] = [];
  stats: HistoryStats | null = null;
  pagination: PaginationInfo | null = null;

  // Filters
  filters = {
    search: '',
    type: '',
    status: '',
    result: '',
    sort: 'newest',
    perPage: 10,
    page: 1
  };

  private filterTimer: any = null;

  ngOnInit(): void {
    this.loadHistory();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
    if (this.filterTimer) clearTimeout(this.filterTimer);
  }

  // ==========================================
  // DATA LOADING
  // ==========================================

  loadHistory(): void {
    this.loading = true;
    // The new API answers with the caller's attempts; filtering, sorting,
    // paging and the summary are computed here rather than by the server.
    this.assessments.myAttempts(200).pipe(takeUntil(this.destroy$)).subscribe({
      next: (rows) => {
        this.allAttempts = rows.map(r => this.toHistoryItem(r));
        this.stats = this.computeStats(this.allAttempts);
        this.applyView();
        this.loading = false;
      },
      error: () => { this.loading = false; },
    });
  }

  private toHistoryItem(r: AttemptResult): AttemptHistoryItem {
    return {
      attempt_id: r.id,
      assessment_id: r.assessmentId,
      assessment_title: r.assessmentTitle ?? 'Assessment',
      assessment_type: String((r as Record<string, unknown>)['assessmentKind'] ?? 'quiz'),
      category_name: '',
      attempt_number: Number((r as Record<string, unknown>)['attemptNumber'] ?? 1),
      max_attempts: 0,
      status: r.status,
      started_at: r.startedAt ?? '',
      completed_at: r.submittedAt,
      duration_seconds: Number((r as Record<string, unknown>)['timeSpentSeconds'] ?? 0),
      total_score: r.rawScore ?? 0,
      max_score: r.maxScore ?? 0,
      percentage: r.percentage ?? 0,
      passed: r.passed === true,
      grade: '',
      grading_status: r.status === 'graded' ? 'graded' : (r.status === 'submitted' ? 'pending' : r.status),
      has_certificate: false,
      certificate_id: null,
      proctored: false,
      proctoring_level: 'none',
      violation_count: 0,
    };
  }

  private computeStats(items: AttemptHistoryItem[]): HistoryStats {
    const done = items.filter(i => i.status === 'graded' || i.status === 'submitted');
    const passed = items.filter(i => i.passed).length;
    const scores = done.map(i => i.percentage);
    return {
      total_attempts: items.length,
      total_passed: passed,
      total_failed: done.length - passed,
      total_pending: items.filter(i => i.grading_status === 'pending').length,
      total_in_progress: items.filter(i => i.status === 'in_progress').length,
      average_score: scores.length ? Math.round(scores.reduce((a, b) => a + b, 0) / scores.length) : 0,
      best_score: scores.length ? Math.max(...scores) : 0,
      total_time_seconds: items.reduce((a, i) => a + i.duration_seconds, 0),
      certificates_earned: 0,
      pass_rate: done.length ? Math.round((passed / done.length) * 100) : 0,
    };
  }

  /** Applies the current filters, sort and page to the loaded attempts. */
  private applyView(): void {
    const f = this.filters;
    let rows = [...this.allAttempts];

    if (f.search) {
      const q = f.search.toLowerCase();
      rows = rows.filter(r => r.assessment_title.toLowerCase().includes(q));
    }
    if (f.type) rows = rows.filter(r => r.assessment_type === f.type);
    if (f.status) rows = rows.filter(r => r.status === f.status);
    if (f.result) rows = rows.filter(r => (f.result === 'passed' ? r.passed : !r.passed));

    rows.sort((a, b) => {
      switch (f.sort) {
        case 'oldest': return a.started_at.localeCompare(b.started_at);
        case 'highest': return b.percentage - a.percentage;
        case 'lowest': return a.percentage - b.percentage;
        default: return b.started_at.localeCompare(a.started_at);
      }
    });

    const total = rows.length;
    const totalPages = Math.max(1, Math.ceil(total / f.perPage));
    const page = Math.min(f.page, totalPages);
    this.pagination = { current_page: page, total_pages: totalPages, total_records: total, per_page: f.perPage };
    this.attempts = rows.slice((page - 1) * f.perPage, page * f.perPage);
  }

  // ==========================================
  // FILTERS
  // ==========================================

  onFilterChange(): void {
    if (this.filterTimer) clearTimeout(this.filterTimer);
    this.filterTimer = setTimeout(() => {
      this.filters.page = 1;
      this.applyView();
    }, 300);
  }

  clearFilters(): void {
    this.filters = { search: '', type: '', status: '', result: '', sort: 'newest', perPage: 10, page: 1 };
    this.applyView();
  }

  hasActiveFilters(): boolean {
    return !!(this.filters.search || this.filters.type || this.filters.status || this.filters.result);
  }

  // ==========================================
  // NAVIGATION
  // ==========================================

  viewResults(item: AttemptHistoryItem): void {
    this.router.navigate(['/learner/assessments', item.assessment_id, 'results', item.attempt_id]);
  }

  resumeAttempt(item: AttemptHistoryItem): void {
    // Back to the lobby, which resumes the in-progress attempt.
    this.router.navigate(['/learner/assessments', item.assessment_id, 'lobby']);
  }

  viewCertificate(item: AttemptHistoryItem): void {
    if (item.certificate_id) {
      this.router.navigate(['/learner/assessments/certificates', item.certificate_id]);
    }
  }

  goToAssessments(): void {
    this.router.navigate(['/learner/assessments']);
  }

  // ==========================================
  // PAGINATION
  // ==========================================

  goToPage(page: number): void {
    if (!this.pagination) return;
    if (page < 1 || page > this.pagination.total_pages) return;
    this.filters.page = page;
    this.applyView();
  }

  getShowingStart(): number {
    if (!this.pagination) return 0;
    return (this.pagination.current_page - 1) * this.pagination.per_page + 1;
  }

  getShowingEnd(): number {
    if (!this.pagination) return 0;
    return Math.min(this.pagination.current_page * this.pagination.per_page, this.pagination.total_records);
  }

  getPageNumbers(): number[] {
    if (!this.pagination) return [];
    const total = this.pagination.total_pages;
    const current = this.pagination.current_page;
    const pages: number[] = [];

    if (total <= 7) {
      for (let i = 1; i <= total; i++) pages.push(i);
    } else {
      pages.push(1);
      if (current > 3) pages.push(-1);
      for (let i = Math.max(2, current - 1); i <= Math.min(total - 1, current + 1); i++) {
        pages.push(i);
      }
      if (current < total - 2) pages.push(-1);
      pages.push(total);
    }
    return pages;
  }

  // ==========================================
  // EXPORT
  // ==========================================

  exportHistory(): void {
    // Built on the client from the loaded history, so no export endpoint is
    // needed and the current filters are respected.
    const rows = this.allAttempts;
    const header = ['Assessment', 'Attempt', 'Status', 'Score', 'Max', 'Percentage', 'Passed', 'Started', 'Completed'];
    const escape = (v: unknown) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const lines = [header.map(escape).join(',')];
    for (const r of rows) {
      lines.push([
        r.assessment_title, r.attempt_number, r.status, r.total_score, r.max_score,
        r.percentage, r.passed ? 'Yes' : 'No', r.started_at, r.completed_at ?? '',
      ].map(escape).join(','));
    }
    const blob = new Blob([lines.join('\n')], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `assessment-history-${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  }

  // ==========================================
  // FORMATTING HELPERS
  // ==========================================

  formatDate(date: string): string {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
      year: 'numeric', month: 'short', day: 'numeric',
      hour: '2-digit', minute: '2-digit'
    });
  }

  formatDuration(seconds: number): string {
    if (!seconds || seconds <= 0) return '0s';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    if (h > 0) return `${h}h ${m}m`;
    if (m > 0) return `${m}m ${s}s`;
    return `${s}s`;
  }

  getTypeBadgeClass(type: string): string {
    const map: { [key: string]: string } = {
      'exam': 'bg-danger', 'quiz': 'bg-primary', 'practice': 'bg-info',
      'survey': 'bg-secondary', 'assignment': 'bg-warning text-dark'
    };
    return map[type] || 'bg-secondary';
  }

  getResultBadgeClass(item: AttemptHistoryItem): string {
    if (item.status === 'in_progress') return 'bg-warning text-dark';
    if (item.status === 'abandoned') return 'bg-secondary';
    if (item.grading_status === 'pending') return 'bg-info';
    return item.passed ? 'bg-success' : 'bg-danger';
  }

  getResultLabel(item: AttemptHistoryItem): string {
    if (item.status === 'in_progress') return 'In Progress';
    if (item.status === 'abandoned') return 'Abandoned';
    if (item.grading_status === 'pending') return 'Pending';
    return item.passed ? 'Passed' : 'Failed';
  }

  trackByAttempt(index: number, item: AttemptHistoryItem): string {
    return item.attempt_id;
  }
}
