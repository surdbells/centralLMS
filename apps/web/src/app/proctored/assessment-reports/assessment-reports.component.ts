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

    <div class="reports-container">
      <div class="reports-header">
        <h1>Assessment Reports</h1>
        <div class="header-actions">
          <button class="btn btn-outline" (click)="exportReport('csv')"><tui-icon icon="@tui.download" style="font-size:14px"></tui-icon> CSV</button>
          <button class="btn btn-outline" (click)="exportReport('pdf')"><tui-icon icon="@tui.file" style="font-size:14px"></tui-icon> PDF</button>
        </div>
      </div>

      <div class="alert alert-info d-flex align-items-center gap-2" *ngIf="notice">
        <tui-icon icon="@tui.info"></tui-icon>
        <span>{{ notice }}</span>
      </div>

      <!-- Filters -->
      <div class="filter-row">
        <select class="fc" [(ngModel)]="filterAssessment" (change)="onFilterChange()">
          <option value="">All Assessments</option>
          <option *ngFor="let a of assessments" [value]="a.id">{{ a.title }}</option>
        </select>
        <select class="fc" [(ngModel)]="filterCategory" (change)="onFilterChange()">
          <option value="">All Categories</option>
          <option *ngFor="let c of categories" [value]="c.id">{{ c.name }}</option>
        </select>
        <select class="fc" [(ngModel)]="filterStatus" (change)="onFilterChange()">
          <option value="">All Statuses</option><option value="passed">Passed</option>
          <option value="failed">Failed</option><option value="in_progress">In Progress</option>
        </select>
        <select class="fc" [(ngModel)]="dateRange" (change)="onFilterChange()">
          <option value="7d">Last 7 Days</option><option value="30d">Last 30 Days</option>
          <option value="90d">Last 90 Days</option><option value="all">All Time</option>
        </select>
      </div>

      <!-- Report Tabs -->
      <div class="tab-bar">
        <button class="tab" [class.active]="activeTab==='learners'" (click)="activeTab='learners';loadReport()">
          <tui-icon icon="@tui.users" style="font-size:16px"></tui-icon> Learner Performance
        </button>
        <button class="tab" [class.active]="activeTab==='questions'" (click)="activeTab='questions';loadQuestionAnalytics()">
          <tui-icon icon="@tui.help-circle" style="font-size:16px"></tui-icon> Question Analytics
        </button>
      </div>

      <!-- Learner Performance Table -->
      <div class="report-section" *ngIf="activeTab==='learners'">
        <div class="summary-row" *ngIf="reportData.length">
          <div class="sum-card"><span class="sum-val">{{ reportData.length }}</span><span class="sum-lbl">Learners</span></div>
          <div class="sum-card"><span class="sum-val">{{ avgBestScore | number:'1.0-0' }}%</span><span class="sum-lbl">Avg Best Score</span></div>
          <div class="sum-card"><span class="sum-val">{{ passCount }}</span><span class="sum-lbl">Passed</span></div>
          <div class="sum-card"><span class="sum-val">{{ failCount }}</span><span class="sum-lbl">Failed</span></div>
        </div>

        <div class="table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th (click)="sortBy('learner_name')">Learner <tui-icon icon="@tui.chevrons-up-down" style="font-size:12px"></tui-icon></th>
                <th>Email</th>
                <th>Department</th>
                <th (click)="sortBy('attempts')">Attempts</th>
                <th (click)="sortBy('best_score')">Best Score</th>
                <th (click)="sortBy('avg_score')">Avg Score</th>
                <th>Avg Time</th>
                <th>Status</th>
                <th (click)="sortBy('last_attempt_date')">Last Attempt</th>
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
                <td><span class="status-badge" [attr.data-status]="r.status">{{ r.status }}</span></td>
                <td>{{ formatDate(r.last_attempt_date) }}</td>
                <td><tui-icon *ngIf="r.certificate_issued" icon="@tui.award" style="font-size:16px;color:#f59e0b"></tui-icon></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="pagination" *ngIf="totalPages > 1">
          <button class="pg-btn" (click)="goToPage(page-1)" [disabled]="page===1"><tui-icon icon="@tui.chevron-left" style="font-size:14px"></tui-icon></button>
          <span class="pg-info">{{ page }} / {{ totalPages }}</span>
          <button class="pg-btn" (click)="goToPage(page+1)" [disabled]="page===totalPages"><tui-icon icon="@tui.chevron-right" style="font-size:14px"></tui-icon></button>
        </div>
      </div>

      <!-- Question Analytics -->
      <div class="report-section" *ngIf="activeTab==='questions'">
        <div class="qa-select" *ngIf="!filterAssessment">
          <tui-icon icon="@tui.info" style="font-size:20px;color:#6366f1"></tui-icon>
          <p>Select a specific assessment above to view question-level analytics.</p>
        </div>
        <div class="table-wrap" *ngIf="filterAssessment && questionData.length">
          <table class="data-table">
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
                <td><span class="type-mini">{{ q.question_type }}</span></td>
                <td>
                  <div class="mini-bar"><div class="mini-fill" [style.width.%]="q.correct_rate"
                       [class.high]="q.correct_rate>=70" [class.mid]="q.correct_rate>=40 && q.correct_rate<70"
                       [class.low]="q.correct_rate<40"></div></div>
                  <span class="mini-val">{{ q.correct_rate | number:'1.0-0' }}%</span>
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
      </div>

      <div class="loading" *ngIf="loading"><div class="spinner"></div></div>
    </div>
  `,
  styles: [`
    :host { display: block; }
    .reports-container { max-width: 1200px; margin: 0 auto; padding: 24px; font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
    .reports-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
    .reports-header h1 { font-size: 24px; font-weight: 800; color: #0f172a; margin: 0; }
    .header-actions { display: flex; gap: 8px; }
    .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 500; border: none; cursor: pointer; }
    .btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; } .btn-outline:hover { background: #f9fafb; }
    .filter-row { display: flex; gap: 10px; margin-bottom: 16px; flex-wrap: wrap; }
    .fc { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 13px; background: #fff; }
    .tab-bar { display: flex; gap: 4px; margin-bottom: 20px; border-bottom: 2px solid #e2e8f0; padding-bottom: 0; }
    .tab { padding: 10px 18px; border: none; background: none; cursor: pointer; font-size: 14px; font-weight: 500; color: #64748b; display: flex; align-items: center; gap: 6px; border-bottom: 2px solid transparent; margin-bottom: -2px; }
    .tab:hover { color: #334155; } .tab.active { color: #6366f1; border-bottom-color: #6366f1; }

    .summary-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; }
    .sum-card { padding: 14px; background: #f8fafc; border-radius: 10px; text-align: center; }
    .sum-val { display: block; font-size: 22px; font-weight: 700; color: #0f172a; }
    .sum-lbl { font-size: 12px; color: #64748b; }

    .table-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
    .data-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .data-table th { padding: 10px 14px; text-align: left; font-weight: 600; color: #64748b; background: #f8fafc; border-bottom: 2px solid #e2e8f0; cursor: pointer; white-space: nowrap; }
    .data-table td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; color: #334155; }
    .data-table tr:hover td { background: #fafaff; }
    .learner-name { font-weight: 600; color: #0f172a; }
    .email { color: #64748b; font-size: 12px; }
    .score { font-weight: 700; } .score.pass { color: #22c55e; } .score.fail { color: #ef4444; }
    .status-badge { padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: capitalize; }
    .status-badge[data-status="passed"] { background: #f0fdf4; color: #15803d; }
    .status-badge[data-status="failed"] { background: #fef2f2; color: #dc2626; }
    .status-badge[data-status="in_progress"] { background: #fffbeb; color: #92400e; }
    .type-mini { font-size: 11px; color: #6366f1; background: #eef2ff; padding: 2px 6px; border-radius: 3px; }
    .q-text { max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .mini-bar { display: inline-block; width: 60px; height: 6px; background: #e2e8f0; border-radius: 3px; vertical-align: middle; margin-right: 6px; overflow: hidden; }
    .mini-fill { height: 100%; border-radius: 3px; }
    .mini-fill.high { background: #22c55e; } .mini-fill.mid { background: #f59e0b; } .mini-fill.low { background: #ef4444; }
    .mini-val { font-size: 12px; }
    .di { font-weight: 600; } .di.easy { color: #22c55e; } .di.medium { color: #f59e0b; } .di.hard { color: #ef4444; }
    .disc { font-weight: 600; color: #64748b; } .disc.good { color: #22c55e; }

    .qa-select { display: flex; align-items: center; gap: 12px; padding: 24px; background: #f8fafc; border-radius: 10px; }
    .qa-select p { margin: 0; color: #64748b; font-size: 14px; }

    .pagination { display: flex; align-items: center; justify-content: center; gap: 12px; margin-top: 16px; }
    .pg-btn { padding: 6px; border: 1px solid #e2e8f0; border-radius: 6px; background: #fff; cursor: pointer; }
    .pg-btn:disabled { opacity: 0.4; cursor: not-allowed; }
    .pg-info { font-size: 13px; color: #64748b; }

    .loading { display: flex; justify-content: center; padding: 40px; }
    .spinner { width: 32px; height: 32px; border: 3px solid #e2e8f0; border-top-color: #6366f1; border-radius: 50%; animation: spin 0.8s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }

    @media (max-width: 768px) { .summary-row { grid-template-columns: repeat(2, 1fr); } }
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
