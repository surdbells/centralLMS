// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 15
// Admin: Assessment Reports Component
// Multi-Tenant LMS | Angular Standalone
// Detailed analytics, exportable reports, learner performance
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { AssessmentAdminService } from '../assessment-admin.service';

interface Assessment { id: string; title: string; }
interface Category { id: string; name: string; }

interface ReportRow {
  learner_id: string; learner_name: string; email: string; department: string | null;
  attempts: number; best_score: number; avg_score: number;
  last_attempt_date: string | null; status: string; time_spent_avg: number;
  passed: boolean; certificate_issued: boolean;
}

interface QuestionAnalytics {
  question_id: string; question_text: string; question_type: string;
  correct_rate: number; avg_time_seconds: number;
  discrimination_index: number; difficulty_index: number;
  total_attempts: number; skipped_count: number;
}

@Component({
  selector: 'app-assessment-reports',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>

    <main class="main">
    <div class="ui-page reports-container">
      <header class="ui-page-header">
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-bar-chart-line"></i></div>
        <div class="ui-page-header__titles">
          <h1>Assessment Reports</h1>
          <p>Learner performance and question-level analytics</p>
        </div>
        <div class="ui-page-header__actions">
          <button type="button" class="btn btn-outline-secondary" (click)="exportReport('csv')"><i class="bi bi-download"></i> CSV</button>
          <button type="button" class="btn btn-outline-secondary" (click)="exportReport('pdf')"><i class="bi bi-file-earmark"></i> PDF</button>
        </div>
      </header>

      <div class="alert alert-info d-flex align-items-center gap-2" *ngIf="notice">
        <i class="bi bi-info-circle"></i>
        <span>{{ notice }}</span>
      </div>

      <!-- Report Tabs -->
      <nav class="ui-tabs" role="tablist">
        <button type="button" role="tab" class="ui-tab" [class.is-active]="activeTab==='learners'" [attr.aria-selected]="activeTab==='learners'" (click)="activeTab='learners';loadReport()">
          <i class="bi bi-people"></i> Learner Performance
        </button>
        <button type="button" role="tab" class="ui-tab" [class.is-active]="activeTab==='questions'" [attr.aria-selected]="activeTab==='questions'" (click)="activeTab='questions';loadQuestionAnalytics()">
          <i class="bi bi-question-circle"></i> Question Analytics
        </button>
      </nav>

      <!-- Filters -->
      <div class="ui-toolbar">
        <div class="ui-filters">
          <select class="form-select" [(ngModel)]="filterAssessment" (change)="onFilterChange()" aria-label="Assessment">
            <option value="">All Assessments</option>
            <option *ngFor="let a of assessments" [value]="a.id">{{ a.title }}</option>
          </select>
          <select class="form-select" [(ngModel)]="filterCategory" (change)="onFilterChange()" aria-label="Category">
            <option value="">All Categories</option>
            <option *ngFor="let c of categories" [value]="c.id">{{ c.name }}</option>
          </select>
          <select class="form-select" [(ngModel)]="filterStatus" (change)="onFilterChange()" aria-label="Status">
            <option value="">All Statuses</option><option value="passed">Passed</option>
            <option value="failed">Failed</option><option value="in_progress">In Progress</option>
          </select>
          <select class="form-select" [(ngModel)]="dateRange" (change)="onFilterChange()" aria-label="Date range">
            <option value="7d">Last 7 Days</option><option value="30d">Last 30 Days</option>
            <option value="90d">Last 90 Days</option><option value="all">All Time</option>
          </select>
        </div>
      </div>

      <!-- Learner Performance Table -->
      <div class="report-section" *ngIf="activeTab==='learners'">
        <div class="ui-kpi-grid ui-kpi-grid--4 summary-row" *ngIf="reportData.length">
          <div class="ui-kpi ui-kpi--brand"><span class="ui-kpi__label"><i class="bi bi-people"></i> Learners</span><span class="ui-kpi__value">{{ reportData.length }}</span></div>
          <div class="ui-kpi ui-kpi--info"><span class="ui-kpi__label"><i class="bi bi-graph-up"></i> Avg Best Score</span><span class="ui-kpi__value">{{ avgBestScore | number:'1.0-0' }}<small>%</small></span></div>
          <div class="ui-kpi ui-kpi--success"><span class="ui-kpi__label"><i class="bi bi-check-circle"></i> Passed</span><span class="ui-kpi__value">{{ passCount }}</span></div>
          <div class="ui-kpi ui-kpi--danger"><span class="ui-kpi__label"><i class="bi bi-x-circle"></i> Failed</span><span class="ui-kpi__value">{{ failCount }}</span></div>
        </div>

        <section class="ui-section ui-section--flush">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title">Learner Performance</h2><p class="ui-section__subtitle">Click a column header to sort</p></div>
          </div>
          <div class="table-responsive">
            <table class="table data-table align-middle">
              <thead>
                <tr>
                  <th class="sortable" (click)="sortBy('learner_name')">Learner <i class="bi bi-chevron-expand"></i></th>
                  <th>Email</th>
                  <th>Department</th>
                  <th class="sortable" (click)="sortBy('attempts')">Attempts</th>
                  <th class="sortable" (click)="sortBy('best_score')">Best Score</th>
                  <th class="sortable" (click)="sortBy('avg_score')">Avg Score</th>
                  <th>Avg Time</th>
                  <th>Status</th>
                  <th class="sortable" (click)="sortBy('last_attempt_date')">Last Attempt</th>
                  <th>Cert</th>
                </tr>
              </thead>
              <tbody>
                <tr *ngFor="let r of paginatedData">
                  <td class="learner-name">{{ r.learner_name }}</td>
                  <td class="email">{{ r.email }}</td>
                  <td>{{ r.department || '-' }}</td>
                  <td>{{ r.attempts }}</td>
                  <td><span class="score" [class.pass]="r.passed" [class.fail]="!r.passed">{{ r.best_score }}%</span></td>
                  <td>{{ r.avg_score | number:'1.0-0' }}%</td>
                  <td>{{ formatDuration(r.time_spent_avg) }}</td>
                  <td><span class="ui-pill status-badge" [attr.data-status]="r.status"><span class="ui-pill__dot"></span>{{ r.status }}</span></td>
                  <td>{{ formatDate(r.last_attempt_date) }}</td>
                  <td><i *ngIf="r.certificate_issued" class="bi bi-award cert-icon" title="Certificate issued"></i></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="ui-state table-empty" *ngIf="!loading && !reportData.length">
            <span class="ui-state__icon"><i class="bi bi-table"></i></span>
            <h3>No results</h3><p>No learner attempts match these filters.</p>
          </div>
          <div class="pager" *ngIf="totalPages > 1">
            <button type="button" class="btn btn-sm btn-outline-secondary" (click)="goToPage(page-1)" [disabled]="page===1" aria-label="Previous page"><i class="bi bi-chevron-left"></i></button>
            <span class="pg-info">{{ page }} / {{ totalPages }}</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" (click)="goToPage(page+1)" [disabled]="page===totalPages" aria-label="Next page"><i class="bi bi-chevron-right"></i></button>
          </div>
        </section>
      </div>

      <!-- Question Analytics -->
      <div class="report-section" *ngIf="activeTab==='questions'">
        <div class="ui-state" *ngIf="!filterAssessment">
          <span class="ui-state__icon"><i class="bi bi-info-circle"></i></span>
          <p>Select a specific assessment above to view question-level analytics.</p>
        </div>
        <section class="ui-section ui-section--flush" *ngIf="filterAssessment && questionData.length">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title">Question Analytics</h2><p class="ui-section__subtitle">Correct rate, timing, difficulty and discrimination per question</p></div>
          </div>
          <div class="table-responsive">
            <table class="table data-table align-middle">
              <thead>
                <tr>
                  <th>#</th><th>Question</th><th>Type</th>
                  <th>Correct %</th><th>Avg Time</th><th>Difficulty Index</th>
                  <th>Discrimination</th><th>Skip Rate</th>
                </tr>
              </thead>
              <tbody>
                <tr *ngFor="let q of questionData; let i=index">
                  <td>{{ i+1 }}</td>
                  <td class="q-text">{{ truncate(q.question_text, 60) }}</td>
                  <td><span class="ui-pill ui-pill--brand type-mini">{{ q.question_type }}</span></td>
                  <td>
                    <div class="ui-progress correct-cell">
                      <div class="ui-bar"><span class="mini-fill" [style.width.%]="q.correct_rate"
                           [class.high]="q.correct_rate>=70" [class.mid]="q.correct_rate>=40 && q.correct_rate<70"
                           [class.low]="q.correct_rate<40"></span></div>
                      <span class="ui-pct">{{ q.correct_rate | number:'1.0-0' }}%</span>
                    </div>
                  </td>
                  <td>{{ q.avg_time_seconds | number:'1.0-0' }}s</td>
                  <td><span class="di" [class.easy]="q.difficulty_index>=0.7" [class.medium]="q.difficulty_index>=0.3 && q.difficulty_index<0.7"
                       [class.hard]="q.difficulty_index<0.3">{{ q.difficulty_index | number:'1.2-2' }}</span></td>
                  <td><span class="disc" [class.good]="q.discrimination_index>=0.3">{{ q.discrimination_index | number:'1.2-2' }}</span></td>
                  <td>{{ ((q.skipped_count / q.total_attempts) * 100) | number:'1.0-0' }}%</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <div class="loading" *ngIf="loading"><div class="spinner-border text-secondary" role="status"></div></div>
    </div>
    </main>
  `,
  styles: [`
    :host { display: block; }

    .summary-row { margin-bottom: 20px; }

    .data-table { font-size: .8125rem; }
    .data-table th.sortable { cursor: pointer; white-space: nowrap; user-select: none; }
    .data-table th.sortable:hover { color: var(--brand-primary); }
    .data-table th { white-space: nowrap; }
    .data-table th i { font-size: .7rem; }
    .learner-name { font-weight: 600; color: var(--ui-text); }
    .email { color: var(--ui-text-muted); font-size: .75rem; }
    .score { font-weight: 700; font-variant-numeric: tabular-nums; } .score.pass { color: var(--ui-success-text); } .score.fail { color: var(--ui-danger-text); }
    .status-badge { text-transform: capitalize; }
    .status-badge[data-status="passed"] { background: rgba(var(--ui-success-rgb), .1); border-color: rgba(var(--ui-success-rgb), .28); color: var(--ui-success-text); }
    .status-badge[data-status="failed"] { background: rgba(var(--ui-danger-rgb), .1); border-color: rgba(var(--ui-danger-rgb), .28); color: var(--ui-danger-text); }
    .status-badge[data-status="in_progress"] { background: rgba(var(--ui-warning-rgb), .1); border-color: rgba(var(--ui-warning-rgb), .28); color: var(--ui-warning-text); }
    .cert-icon { font-size: 1rem; color: var(--brand-primary); }
    .type-mini { height: 22px; font-size: .6875rem; }
    .q-text { max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .correct-cell { min-width: 120px; }
    .ui-bar > .mini-fill.high { background: var(--ui-success); } .ui-bar > .mini-fill.mid { background: var(--ui-warning); } .ui-bar > .mini-fill.low { background: var(--ui-danger); }
    .di { font-weight: 600; font-variant-numeric: tabular-nums; } .di.easy { color: var(--ui-success-text); } .di.medium { color: var(--ui-warning-text); } .di.hard { color: var(--ui-danger-text); }
    .disc { font-weight: 600; color: var(--ui-text-muted); font-variant-numeric: tabular-nums; } .disc.good { color: var(--ui-success-text); }

    .table-empty { margin: 0 22px 22px; }
    .pager { display: flex; align-items: center; justify-content: center; gap: 12px; padding: 14px 22px; border-top: 1px solid var(--ui-border); }
    .pg-info { font-size: .8125rem; color: var(--ui-text-muted); font-variant-numeric: tabular-nums; }

    .loading { display: flex; justify-content: center; padding: 40px; }
  `]
})
export class AssessmentReportsComponent implements OnInit, OnDestroy {
  private admin = inject(AssessmentAdminService);
  private router = inject(Router);
  private destroy$ = new Subject<void>();

  assessments: Assessment[] = [];
  categories: Category[] = [];
  reportData: ReportRow[] = [];
  questionData: QuestionAnalytics[] = [];
  loading = false;
  notice: string | null = null;
  activeTab = 'learners';
  filterAssessment = '';
  filterCategory = '';
  filterStatus = '';
  dateRange = '30d';
  sortField = 'learner_name';
  sortDir: 'asc'|'desc' = 'asc';
  page = 1;
  pageSize = 25;

  get totalPages(): number { return Math.max(1, Math.ceil(this.reportData.length / this.pageSize)); }
  get paginatedData(): ReportRow[] {
    const sorted = [...this.reportData].sort((a, b) => {
      let va = (a as any)[this.sortField]; let vb = (b as any)[this.sortField];
      if (typeof va === 'string') { va = va.toLowerCase(); vb = (vb || '').toLowerCase(); }
      return this.sortDir === 'asc' ? (va > vb ? 1 : -1) : (va < vb ? 1 : -1);
    });
    return sorted.slice((this.page - 1) * this.pageSize, this.page * this.pageSize);
  }
  get avgBestScore(): number {
    if (!this.reportData.length) return 0;
    return this.reportData.reduce((s, r) => s + r.best_score, 0) / this.reportData.length;
  }
  get passCount(): number { return this.reportData.filter(r => r.passed).length; }
  get failCount(): number { return this.reportData.filter(r => !r.passed && r.status === 'failed').length; }

  ngOnInit(): void {
    this.loadAssessments();
    // The Learner Performance tab is the default view; load it up front rather
    // than waiting for the first filter change.
    this.loadReport();
  }
  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }

  /** A filter changed — reload whichever tab is showing. */
  onFilterChange(): void {
    if (this.activeTab === 'questions') {
      this.loadQuestionAnalytics();
    } else {
      this.loadReport();
    }
  }

  loadAssessments(): void {
    // The assessment dropdown feeds off the authoring list; category options
    // have no listing endpoint yet, so that filter stays empty.
    this.admin.list({ perPage: 200 }).pipe(takeUntil(this.destroy$)).subscribe({
      next: (page) => { this.assessments = (page.items || []).map((a) => ({ id: a.id, title: a.title })); },
    });
  }

  loadCategories(): void { /* no categories listing endpoint on the new API */ }

  loadReport(): void {
    this.loading = true;
    this.notice = null;
    this.page = 1;
    this.admin.learnerReport({
      assessmentId: this.filterAssessment,
      categoryId: this.filterCategory,
      status: this.filterStatus,
      range: this.dateRange,
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: (rows) => { this.reportData = rows; this.loading = false; },
      error: () => { this.loading = false; this.reportData = []; this.notice = 'Could not load the report.'; },
    });
  }

  loadQuestionAnalytics(): void {
    if (!this.filterAssessment) { this.questionData = []; return; }
    this.loading = true;
    this.notice = null;
    this.admin.itemAnalysis(this.filterAssessment).pipe(takeUntil(this.destroy$)).subscribe({
      next: (rows) => { this.questionData = rows; this.loading = false; },
      error: () => { this.loading = false; this.questionData = []; this.notice = 'Could not load question analytics.'; },
    });
  }

  sortBy(field: string): void {
    if (this.sortField === field) this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
    else { this.sortField = field; this.sortDir = 'asc'; }
  }

  goToPage(p: number): void { if (p >= 1 && p <= this.totalPages) this.page = p; }

  exportReport(_format: string): void {
    if (!this.reportData.length) { this.notice = 'There is nothing to export yet.'; return; }
    const esc = (v: unknown) => `"${String(v ?? '').replace(/"/g, '""')}"`;
    const header = ['Learner', 'Email', 'Department', 'Attempts', 'Best %', 'Avg %', 'Status', 'Certificate'];
    const rows = this.reportData.map(r => [
      esc(r.learner_name), esc(r.email), esc(r.department ?? ''), r.attempts,
      r.best_score, r.avg_score, esc(r.status), r.certificate_issued ? 'Yes' : 'No',
    ].join(','));
    const blob = new Blob([[header.join(','), ...rows].join('\n')], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'learner-report.csv'; a.click();
    URL.revokeObjectURL(url);
  }

  formatDate(d: string | null): string { if (!d) return '-'; return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }); }
  formatDuration(sec: number): string { const m = Math.floor(sec / 60); return m >= 60 ? Math.floor(m/60) + 'h ' + (m%60) + 'm' : m + 'm'; }
  truncate(t: string, max: number): string { const s = (t||'').replace(/<[^>]*>/g, ''); return s.length > max ? s.substring(0, max) + '...' : s; }
}
