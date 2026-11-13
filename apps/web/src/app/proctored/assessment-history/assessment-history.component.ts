// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 14
// Learner: Assessment History Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { SelectComponent } from '../../shared/select/select.component';
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
  imports: [CommonModule, FormsModule, TuiIcon, TopComponent, SkeletonComponent, SelectComponent],
  template: `
    <app-top></app-top>
    <main class="main">
      <div class="ui-page">
        <!-- ==========================================
             PAGE HEADER
             ========================================== -->
        <header class="ui-page-header">
          <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-clock-history"></i></div>
          <div class="ui-page-header__titles">
            <h1>Assessment History</h1>
            <p *ngIf="stats">
              {{ stats.total_attempts }} total attempts · {{ stats.pass_rate | number:'1.0-0' }}% pass rate
            </p>
            <p *ngIf="!stats">Every assessment attempt you have made</p>
          </div>
          <div class="ui-page-header__actions">
            <button type="button" class="btn btn-outline-secondary" (click)="exportHistory()">
              <i class="bi bi-download"></i> Export
            </button>
          </div>
        </header>

        <!-- ==========================================
             STATS CARDS
             ========================================== -->
        <div class="ui-kpi-grid history-kpis" *ngIf="stats && !loading">
          <div class="ui-kpi ui-kpi--brand">
            <span class="ui-kpi__label"><i class="bi bi-list-check"></i> Total Attempts</span>
            <span class="ui-kpi__value">{{ stats.total_attempts }}</span>
          </div>
          <div class="ui-kpi ui-kpi--success">
            <span class="ui-kpi__label"><i class="bi bi-check-circle"></i> Passed</span>
            <span class="ui-kpi__value">{{ stats.total_passed }}</span>
          </div>
          <div class="ui-kpi ui-kpi--danger">
            <span class="ui-kpi__label"><i class="bi bi-x-circle"></i> Failed</span>
            <span class="ui-kpi__value">{{ stats.total_failed }}</span>
          </div>
          <div class="ui-kpi ui-kpi--info">
            <span class="ui-kpi__label"><i class="bi bi-graph-up"></i> Avg Score</span>
            <span class="ui-kpi__value">{{ stats.average_score | number:'1.0-0' }}<small>%</small></span>
          </div>
          <div class="ui-kpi ui-kpi--warning">
            <span class="ui-kpi__label"><i class="bi bi-trophy"></i> Best Score</span>
            <span class="ui-kpi__value">{{ stats.best_score | number:'1.0-0' }}<small>%</small></span>
          </div>
          <div class="ui-kpi ui-kpi--brand">
            <span class="ui-kpi__label"><i class="bi bi-award"></i> Certificates</span>
            <span class="ui-kpi__value">{{ stats.certificates_earned }}</span>
          </div>
        </div>

        <section class="ui-section ui-section--flush">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title">Attempts</h2><p class="ui-section__subtitle">Search, filter and sort your attempts</p></div>
          </div>
          <!-- ==========================================
               FILTERS
               ========================================== -->
          <div class="ui-toolbar">
            <label class="ui-search">
              <i class="bi bi-search"></i>
              <input type="search" placeholder="Search assessments..." aria-label="Search assessments"
                     [(ngModel)]="filters.search" (ngModelChange)="onFilterChange()">
            </label>
            <div class="ui-filters">
              <app-select style="width:150px" [options]="typeOpts" placeholder="All Types" [(ngModel)]="filters.type" (ngModelChange)="onFilterChange()" ariaLabel="Type" />
              <app-select style="width:150px" [options]="statusOpts" placeholder="All Status" [(ngModel)]="filters.status" (ngModelChange)="onFilterChange()" ariaLabel="Status" />
              <app-select style="width:160px" [options]="resultOpts" placeholder="All Results" [searchable]="false" [(ngModel)]="filters.result" (ngModelChange)="onFilterChange()" ariaLabel="Result" />
              <app-select style="width:160px" [options]="sortOpts" [(ngModel)]="filters.sort" (ngModelChange)="onFilterChange()" ariaLabel="Sort" />
              <button type="button" class="btn btn-outline-secondary clear-btn" (click)="clearFilters()" title="Clear filters" aria-label="Clear filters">
                <i class="bi bi-x-lg"></i>
              </button>
            </div>
          </div>

          <!-- ==========================================
               LOADING
               ========================================== -->
          <app-skeleton *ngIf="loading" variant="table" [rows]="6" [cols]="8"></app-skeleton>

          <!-- ==========================================
               HISTORY TABLE
               ========================================== -->
          <div class="table-responsive" *ngIf="!loading">
            <table class="table align-middle mb-0">
              <thead>
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
                        <i *ngIf="item.proctored" class="bi bi-eye proctor-icon" title="Proctored Assessment"></i>
                      </div>
                      <h6 class="assessment-title mb-0 mt-1">{{ item.assessment_title }}</h6>
                      <small class="ui-muted" *ngIf="item.category_name">{{ item.category_name }}</small>
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
                    <span *ngIf="item.status === 'in_progress'" class="ui-muted">-</span>
                    <span *ngIf="item.status === 'abandoned'" class="ui-muted">N/A</span>
                  </td>
                  <td class="text-center">
                    <span class="badge" [ngClass]="getResultBadgeClass(item)">
                      {{ getResultLabel(item) }}
                    </span>
                    <div *ngIf="item.violation_count > 0" class="mt-1">
                      <small class="text-danger">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        {{ item.violation_count }} violation(s)
                      </small>
                    </div>
                  </td>
                  <td class="text-center">
                    <span *ngIf="item.grade && item.status === 'completed'" class="grade-badge">{{ item.grade }}</span>
                    <span *ngIf="item.status !== 'completed'" class="ui-muted">-</span>
                  </td>
                  <td>
                    <div class="date-info">
                      <span class="date-primary">{{ formatDate(item.started_at) }}</span>
                      <small class="ui-muted d-block" *ngIf="item.completed_at">
                        Completed: {{ formatDate(item.completed_at) }}
                      </small>
                    </div>
                  </td>
                  <td class="text-center">
                    <span *ngIf="item.duration_seconds > 0">{{ formatDuration(item.duration_seconds) }}</span>
                    <span *ngIf="!item.duration_seconds" class="ui-muted">-</span>
                  </td>
                  <td class="text-center">
                    <div class="d-flex justify-content-center gap-1">
                      <button type="button" class="btn btn-sm btn-outline-secondary" (click)="viewResults(item)"
                              *ngIf="item.status === 'completed' || item.status === 'submitted'"
                              title="View Results" aria-label="View Results">
                        <i class="bi bi-eye"></i>
                      </button>
                      <button type="button" class="btn btn-sm btn-primary" (click)="resumeAttempt(item)"
                              *ngIf="item.status === 'in_progress'" title="Resume" aria-label="Resume">
                        <i class="bi bi-play-fill"></i>
                      </button>
                      <button type="button" class="btn btn-sm btn-outline-secondary" (click)="viewCertificate(item)"
                              *ngIf="item.has_certificate" title="View Certificate" aria-label="View Certificate">
                        <i class="bi bi-award"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- No Results -->
          <div class="ui-state history-empty" *ngIf="!loading && attempts.length === 0">
            <span class="ui-state__icon"><i class="bi bi-clock-history"></i></span>
            <h3>No Assessment History</h3>
            <p *ngIf="hasActiveFilters()">No results match your filters. Try adjusting them.</p>
            <p *ngIf="!hasActiveFilters()">You haven't taken any assessments yet.</p>
            <button type="button" class="btn btn-primary btn-sm" *ngIf="!hasActiveFilters()" (click)="goToAssessments()">
              <i class="bi bi-file-earmark-text me-1"></i> Browse Assessments
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" *ngIf="hasActiveFilters()" (click)="clearFilters()">
              <i class="bi bi-x-lg me-1"></i> Clear Filters
            </button>
          </div>

          <!-- ==========================================
               PAGINATION
               ========================================== -->
          <div class="pagination-bar" *ngIf="pagination && pagination.total_pages > 1">
            <p class="ui-result-count mb-0">
              Showing {{ getShowingStart() }}-{{ getShowingEnd() }} of {{ pagination.total_records }}
            </p>
            <nav>
              <ul class="pagination pagination-sm mb-0">
                <li class="page-item" [class.disabled]="pagination.current_page <= 1">
                  <a class="page-link" href="javascript:void(0)" (click)="goToPage(pagination.current_page - 1)" aria-label="Previous page">
                    <i class="bi bi-chevron-left"></i>
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
                  <a class="page-link" href="javascript:void(0)" (click)="goToPage(pagination.current_page + 1)" aria-label="Next page">
                    <i class="bi bi-chevron-right"></i>
                  </a>
                </li>
              </ul>
            </nav>
            <app-select class="per-page" style="width:90px" [options]="perPageOpts" [searchable]="false" ariaLabel="Rows per page"
                        [(ngModel)]="filters.perPage" (ngModelChange)="onFilterChange()" />
          </div>
        </section>
      </div>
    </main>
  `,
  styles: [`
    :host { display: block; }

    .history-kpis { grid-template-columns: repeat(6, minmax(0, 1fr)); margin-bottom: 20px; }
    @media (max-width: 1199.98px) { .history-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 767.98px) { .history-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

    .ui-section--flush > .ui-toolbar { padding-bottom: 4px; }
    .clear-btn { width: var(--ui-ctl-h); padding: 0; }

    .history-row.in-progress > td { background: rgba(var(--ui-warning-rgb), .04); }

    .assessment-title { font-size: .875rem; font-weight: 600; color: var(--ui-text); }
    .proctor-icon { color: var(--brand-primary); }

    .badge-sm { font-size: .65rem; text-transform: uppercase; }

    .attempt-badge {
      display: inline-block; background: var(--ui-surface-2); border: 1px solid var(--ui-border);
      padding: .15rem .5rem; border-radius: var(--ui-radius-ctl); font-weight: 600; font-size: .8125rem;
      font-variant-numeric: tabular-nums;
    }

    .score-value { font-size: .9375rem; font-weight: 700; display: block; font-variant-numeric: tabular-nums; }
    .score-detail { font-size: .75rem; color: var(--ui-text-muted); font-variant-numeric: tabular-nums; }

    .grade-badge {
      display: inline-block; background: rgba(var(--brand-primary-rgb), .1); color: var(--brand-primary);
      width: 32px; height: 32px; line-height: 32px; border-radius: 50%;
      text-align: center; font-weight: 700; font-size: .8rem;
    }

    .date-primary { font-size: .8125rem; color: var(--ui-text); }

    .history-empty { margin: 0 22px 22px; }

    .pagination-bar {
      display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap;
      padding: 14px 22px; border-top: 1px solid var(--ui-border);
    }
    .per-page { width: 90px; }
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
  readonly typeOpts = [
    { label: 'All Types', value: '' }, { label: 'Exam', value: 'exam' }, { label: 'Quiz', value: 'quiz' },
    { label: 'Practice', value: 'practice' }, { label: 'Survey', value: 'survey' }, { label: 'Assignment', value: 'assignment' },
  ];
  readonly statusOpts = [
    { label: 'All Status', value: '' }, { label: 'Completed', value: 'completed' }, { label: 'In Progress', value: 'in_progress' },
    { label: 'Submitted', value: 'submitted' }, { label: 'Abandoned', value: 'abandoned' },
  ];
  readonly resultOpts = [
    { label: 'All Results', value: '' }, { label: 'Passed', value: 'passed' },
    { label: 'Failed', value: 'failed' }, { label: 'Pending Grading', value: 'pending' },
  ];
  readonly sortOpts = [
    { label: 'Newest First', value: 'newest' }, { label: 'Oldest First', value: 'oldest' },
    { label: 'Highest Score', value: 'score_high' }, { label: 'Lowest Score', value: 'score_low' },
    { label: 'Title A-Z', value: 'title_az' },
  ];
  readonly perPageOpts = [10, 25, 50];

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
