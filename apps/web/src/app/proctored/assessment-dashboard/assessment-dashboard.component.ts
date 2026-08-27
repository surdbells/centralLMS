import { decodeSessionBlob } from '../../core/utils/session-blob';
// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 15
// Admin: Assessment Dashboard Component
// Multi-Tenant LMS | Angular Standalone
// Analytics overview: stats, charts, recent activity
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { GlobalComponent } from '../../global-component';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';

interface ApiResponse<T> { code: number; status: string; data: T; message: string; }

interface DashboardStats {
  total_assessments: number; published: number; draft: number;
  total_attempts: number; attempts_this_month: number; attempts_change_percent: number;
  avg_score: number; overall_pass_rate: number;
  total_learners: number; active_learners: number;
  pending_grading: number; flagged_sessions: number;
  total_questions: number; total_certificates: number;
}

interface TypeDistribution { type: string; count: number; percentage: number; }
interface MonthlyTrend { month: string; attempts: number; pass_rate: number; avg_score: number; }
interface TopAssessment { assessment_id: number; title: string; type: string; attempts: number; avg_score: number; pass_rate: number; }
interface RecentActivity { id: number; type: string; message: string; timestamp: string; user_name: string; assessment_title: string; }
interface DifficultyBreakdown { difficulty: string; count: number; avg_score: number; pass_rate: number; }

@Component({
  selector: 'app-assessment-dashboard',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>

    <div class="admin-dash">
      <div class="dash-header">
        <div><h1>Assessment Dashboard</h1><p>Overview of assessment performance and activity</p></div>
        <div class="header-actions">
          <select class="fc" [(ngModel)]="dateRange" (change)="loadDashboard()">
            <option value="7d">Last 7 Days</option><option value="30d">Last 30 Days</option>
            <option value="90d">Last 90 Days</option><option value="1y">Last Year</option>
          </select>
          <button class="btn btn-primary" (click)="router.navigate(['/admin/assessments/create'])">
            <tui-icon icon="@tui.plus" style="font-size:16px"></tui-icon> New Assessment
          </button>
        </div>
      </div>

      <!-- Stat Cards -->
      <div class="stat-grid" *ngIf="stats">
        <div class="scard" (click)="router.navigate(['/admin/assessments'])">
          <div class="sc-top"><div class="sc-icon blue"><tui-icon icon="@tui.file-text" style="font-size:20px"></tui-icon></div>
            <span class="sc-change" *ngIf="stats.attempts_change_percent" [class.up]="stats.attempts_change_percent>0" [class.down]="stats.attempts_change_percent<0">
              <tui-icon [icon]="stats.attempts_change_percent>0?'@tui.trending-up':'@tui.trending-down'" style="font-size:14px"></tui-icon>
              {{ stats.attempts_change_percent>0?'+':'' }}{{ stats.attempts_change_percent }}%
            </span>
          </div>
          <span class="sc-val">{{ stats.total_assessments }}</span><span class="sc-lbl">Assessments</span>
          <span class="sc-sub">{{ stats.published }} published · {{ stats.draft }} drafts</span>
        </div>
        <div class="scard">
          <div class="sc-top"><div class="sc-icon green"><tui-icon icon="@tui.users" style="font-size:20px"></tui-icon></div></div>
          <span class="sc-val">{{ stats.total_attempts | number }}</span><span class="sc-lbl">Total Attempts</span>
          <span class="sc-sub">{{ stats.attempts_this_month }} this month</span>
        </div>
        <div class="scard">
          <div class="sc-top"><div class="sc-icon purple"><tui-icon icon="@tui.percent" style="font-size:20px"></tui-icon></div></div>
          <span class="sc-val">{{ stats.overall_pass_rate | number:'1.0-0' }}%</span><span class="sc-lbl">Pass Rate</span>
          <span class="sc-sub">Avg score: {{ stats.avg_score | number:'1.0-0' }}%</span>
        </div>
        <div class="scard" (click)="router.navigate(['/admin/grading'])">
          <div class="sc-top"><div class="sc-icon amber"><tui-icon icon="@tui.edit-3" style="font-size:20px"></tui-icon></div></div>
          <span class="sc-val">{{ stats.pending_grading }}</span><span class="sc-lbl">Pending Grading</span>
          <span class="sc-sub act" *ngIf="stats.pending_grading>0">Review now →</span>
        </div>
        <div class="scard" (click)="router.navigate(['/admin/proctoring/sessions'])">
          <div class="sc-top"><div class="sc-icon red"><tui-icon icon="@tui.shield" style="font-size:20px"></tui-icon></div></div>
          <span class="sc-val">{{ stats.flagged_sessions }}</span><span class="sc-lbl">Flagged Sessions</span>
          <span class="sc-sub act" *ngIf="stats.flagged_sessions>0">Review now →</span>
        </div>
        <div class="scard">
          <div class="sc-top"><div class="sc-icon teal"><tui-icon icon="@tui.award" style="font-size:20px"></tui-icon></div></div>
          <span class="sc-val">{{ stats.total_certificates }}</span><span class="sc-lbl">Certificates Issued</span>
          <span class="sc-sub">{{ stats.active_learners }} active learners</span>
        </div>
      </div>

      <div class="two-col">
        <!-- Monthly Trend Chart -->
        <div class="chart-card">
          <h3>Monthly Trends</h3>
          <div class="bar-chart" *ngIf="monthlyTrends.length">
            <div *ngFor="let m of monthlyTrends" class="bar-group">
              <div class="bar-wrapper">
                <div class="bar attempts" [style.height.%]="(m.attempts / maxAttempts) * 100" [title]="m.attempts + ' attempts'"></div>
              </div>
              <div class="bar-meta">
                <span class="bar-label">{{ m.month }}</span>
                <span class="bar-val">{{ m.pass_rate | number:'1.0-0' }}%</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Type Distribution -->
        <div class="chart-card">
          <h3>By Type</h3>
          <div class="donut-chart" *ngIf="typeDistribution.length">
            <div class="donut-visual">
              <svg viewBox="0 0 120 120" class="donut-svg">
                <ng-container *ngFor="let seg of donutSegments; let i=index">
                  <circle cx="60" cy="60" r="46" fill="none" [attr.stroke]="donutColors[i]" stroke-width="18"
                          [attr.stroke-dasharray]="seg.dash" [attr.stroke-dashoffset]="seg.offset"
                          transform="rotate(-90 60 60)"/>
                </ng-container>
              </svg>
              <div class="donut-center">
                <span class="dc-val">{{ stats?.total_assessments }}</span><span class="dc-lbl">Total</span>
              </div>
            </div>
            <div class="donut-legend">
              <div *ngFor="let t of typeDistribution; let i=index" class="dl-item">
                <span class="dl-dot" [style.background]="donutColors[i]"></span>
                <span class="dl-name">{{ t.type | titlecase }}</span>
                <span class="dl-val">{{ t.count }} ({{ t.percentage | number:'1.0-0' }}%)</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="two-col">
        <!-- Top Assessments -->
        <div class="chart-card">
          <h3>Top Assessments</h3>
          <div class="top-table">
            <div *ngFor="let a of topAssessments; let i=index" class="tt-row" (click)="router.navigate(['/admin/assessments', a.assessment_id])">
              <span class="tt-rank">{{ i+1 }}</span>
              <div class="tt-info">
                <span class="tt-title">{{ a.title }}</span>
                <span class="tt-type">{{ a.type }}</span>
              </div>
              <div class="tt-stats">
                <span>{{ a.attempts }} attempts</span>
                <span class="tt-pass">{{ a.pass_rate | number:'1.0-0' }}% pass</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Recent Activity -->
        <div class="chart-card">
          <h3>Recent Activity</h3>
          <div class="activity-feed">
            <div *ngFor="let a of recentActivity" class="af-item">
              <div class="af-icon" [attr.data-type]="a.type">
                <tui-icon [icon]="getActivityIcon(a.type)" style="font-size:14px"></tui-icon>
              </div>
              <div class="af-content">
                <span class="af-msg">{{ a.message }}</span>
                <span class="af-time">{{ timeAgo(a.timestamp) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Difficulty Breakdown -->
      <div class="chart-card full-width" *ngIf="diffBreakdown.length">
        <h3>Performance by Difficulty</h3>
        <div class="diff-grid">
          <div *ngFor="let d of diffBreakdown" class="diff-card" [attr.data-diff]="d.difficulty">
            <h4>{{ d.difficulty | titlecase }}</h4>
            <div class="diff-stats">
              <div class="ds"><span class="ds-val">{{ d.count }}</span><span class="ds-lbl">Questions</span></div>
              <div class="ds"><span class="ds-val">{{ d.avg_score | number:'1.0-0' }}%</span><span class="ds-lbl">Avg Score</span></div>
              <div class="ds"><span class="ds-val">{{ d.pass_rate | number:'1.0-0' }}%</span><span class="ds-lbl">Pass Rate</span></div>
            </div>
            <div class="diff-bar"><div class="diff-fill" [style.width.%]="d.pass_rate"
                 [style.background]="d.difficulty==='easy'?'#22c55e':d.difficulty==='medium'?'#f59e0b':'#ef4444'"></div></div>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    :host { display: block; }
    .admin-dash { max-width: 1200px; margin: 0 auto; padding: 24px; font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
    .dash-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
    .dash-header h1 { font-size: 24px; font-weight: 800; color: #0f172a; margin: 0; }
    .dash-header p { color: #64748b; margin: 4px 0 0; font-size: 14px; }
    .header-actions { display: flex; gap: 10px; }
    .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 500; border: none; cursor: pointer; transition: all 0.15s; }
    .btn-primary { background: #6366f1; color: #fff; } .btn-primary:hover { background: #4f46e5; }
    .fc { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; background: #fff; }

    .stat-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 14px; margin-bottom: 24px; }
    .scard { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; cursor: pointer; transition: all 0.15s; }
    .scard:hover { border-color: #a5b4fc; box-shadow: 0 2px 8px rgba(99,102,241,0.08); }
    .sc-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .sc-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
    .sc-icon.blue { background: #eef2ff; color: #6366f1; }
    .sc-icon.green { background: #f0fdf4; color: #22c55e; }
    .sc-icon.purple { background: #f5f3ff; color: #8b5cf6; }
    .sc-icon.amber { background: #fffbeb; color: #f59e0b; }
    .sc-icon.red { background: #fef2f2; color: #ef4444; }
    .sc-icon.teal { background: #f0fdfa; color: #14b8a6; }
    .sc-change { font-size: 12px; font-weight: 600; display: flex; align-items: center; gap: 2px; }
    .sc-change.up { color: #22c55e; } .sc-change.down { color: #ef4444; }
    .sc-val { display: block; font-size: 24px; font-weight: 700; color: #0f172a; }
    .sc-lbl { display: block; font-size: 12px; color: #64748b; margin-bottom: 2px; }
    .sc-sub { font-size: 11px; color: #94a3b8; }
    .sc-sub.act { color: #6366f1; cursor: pointer; }

    .two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
    .chart-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 20px; }
    .chart-card.full-width { margin-bottom: 20px; }
    .chart-card h3 { font-size: 16px; font-weight: 600; color: #0f172a; margin: 0 0 16px; }

    .bar-chart { display: flex; gap: 8px; align-items: flex-end; height: 200px; }
    .bar-group { flex: 1; display: flex; flex-direction: column; align-items: center; height: 100%; }
    .bar-wrapper { flex: 1; width: 100%; display: flex; align-items: flex-end; justify-content: center; }
    .bar.attempts { width: 32px; background: #6366f1; border-radius: 6px 6px 0 0; min-height: 4px; transition: height 0.5s; cursor: pointer; }
    .bar.attempts:hover { background: #4f46e5; }
    .bar-meta { text-align: center; padding-top: 6px; }
    .bar-label { display: block; font-size: 11px; color: #64748b; }
    .bar-val { font-size: 11px; color: #22c55e; font-weight: 600; }

    .donut-chart { display: flex; align-items: center; gap: 24px; }
    .donut-visual { position: relative; width: 140px; height: 140px; flex-shrink: 0; }
    .donut-svg { width: 100%; height: 100%; }
    .donut-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .dc-val { font-size: 24px; font-weight: 800; color: #0f172a; }
    .dc-lbl { font-size: 11px; color: #64748b; }
    .donut-legend { display: flex; flex-direction: column; gap: 8px; flex: 1; }
    .dl-item { display: flex; align-items: center; gap: 8px; font-size: 13px; }
    .dl-dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
    .dl-name { flex: 1; color: #334155; }
    .dl-val { color: #64748b; font-size: 12px; }

    .top-table { display: flex; flex-direction: column; gap: 6px; }
    .tt-row { display: flex; align-items: center; gap: 12px; padding: 10px; border-radius: 8px; cursor: pointer; transition: all 0.15s; }
    .tt-row:hover { background: #f8fafc; }
    .tt-rank { width: 24px; height: 24px; border-radius: 50%; background: #f1f5f9; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: #64748b; flex-shrink: 0; }
    .tt-info { flex: 1; min-width: 0; }
    .tt-title { display: block; font-size: 14px; font-weight: 500; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tt-type { font-size: 11px; color: #94a3b8; text-transform: uppercase; }
    .tt-stats { text-align: right; font-size: 12px; color: #64748b; }
    .tt-pass { display: block; color: #22c55e; font-weight: 600; }

    .activity-feed { display: flex; flex-direction: column; gap: 10px; max-height: 300px; overflow-y: auto; }
    .af-item { display: flex; align-items: flex-start; gap: 10px; }
    .af-icon { width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .af-icon[data-type="attempt"] { background: #eef2ff; color: #6366f1; }
    .af-icon[data-type="grading"] { background: #f0fdf4; color: #22c55e; }
    .af-icon[data-type="violation"] { background: #fef2f2; color: #ef4444; }
    .af-icon[data-type="certificate"] { background: #fffbeb; color: #f59e0b; }
    .af-icon[data-type="publish"] { background: #f5f3ff; color: #8b5cf6; }
    .af-content { flex: 1; }
    .af-msg { font-size: 13px; color: #334155; display: block; }
    .af-time { font-size: 11px; color: #94a3b8; }

    .diff-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
    .diff-card { padding: 18px; border-radius: 10px; border: 1px solid #e2e8f0; }
    .diff-card[data-diff="easy"] { border-left: 4px solid #22c55e; }
    .diff-card[data-diff="medium"] { border-left: 4px solid #f59e0b; }
    .diff-card[data-diff="hard"] { border-left: 4px solid #ef4444; }
    .diff-card h4 { margin: 0 0 12px; font-size: 15px; color: #0f172a; }
    .diff-stats { display: flex; gap: 16px; margin-bottom: 12px; }
    .ds { text-align: center; }
    .ds-val { display: block; font-size: 18px; font-weight: 700; color: #0f172a; }
    .ds-lbl { font-size: 11px; color: #64748b; }
    .diff-bar { height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; }
    .diff-fill { height: 100%; border-radius: 3px; transition: width 0.5s; }

    @media (max-width: 1024px) { .stat-grid { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 768px) { .stat-grid { grid-template-columns: repeat(2, 1fr); } .two-col, .diff-grid { grid-template-columns: 1fr; } }
  `]
})
export class AssessmentDashboardComponent implements OnInit, OnDestroy {
  private http = inject(HttpClient);
  router = inject(Router);
  private destroy$ = new Subject<void>();
  private apiUrl = GlobalComponent.baseURL + '/user/assessment.php';

  session_data: any = ""
  user_session = {
    id: 0,
    tenant_id: 0,
    tenant: {
      id: 0,
      name: "",
      status: "",
    },
    token: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    avatar: "",
    is_2fa: false,
    is_active: false,
    account_locked: false,
    is_admin: false,
    is_content: false,
    is_instructor: false,
    is_learner: false,
  };

  stats: DashboardStats|null = null;
  monthlyTrends: MonthlyTrend[] = [];
  typeDistribution: TypeDistribution[] = [];
  topAssessments: TopAssessment[] = [];
  recentActivity: RecentActivity[] = [];
  diffBreakdown: DifficultyBreakdown[] = [];
  dateRange = '30d';

  donutColors = ['#6366f1', '#22c55e', '#f59e0b', '#ef4444', '#14b8a6'];
  donutSegments: {dash: string; offset: string}[] = [];

  get maxAttempts(): number { return Math.max(...this.monthlyTrends.map(m => m.attempts), 1); }

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    this.loadDashboard();
  }
  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }

  loadDashboard(): void {
    const body = { action: 'get-admin-dashboard', range: this.dateRange, token: this.user_session.token, user_id: this.user_session.id, tenant_id: this.user_session.tenant_id };
    this.http.post<ApiResponse<any>>(this.apiUrl, body).pipe(takeUntil(this.destroy$)).subscribe({
      next: r => {
        if (r.status === 'success') {
          this.stats = r.data.stats;
          this.monthlyTrends = r.data.monthly_trends || [];
          this.typeDistribution = r.data.type_distribution || [];
          this.topAssessments = r.data.top_assessments || [];
          this.recentActivity = r.data.recent_activity || [];
          this.diffBreakdown = r.data.difficulty_breakdown || [];
          this.buildDonut();
        }
      }
    });
  }

  buildDonut(): void {
    const C = 2 * Math.PI * 46;
    let cumulative = 0;
    this.donutSegments = this.typeDistribution.map(t => {
      const seg = { dash: `${(t.percentage / 100) * C} ${C}`, offset: `${-cumulative * C / 100}` };
      cumulative += t.percentage;
      return seg;
    });
  }

  getActivityIcon(type: string): string {
    const m: Record<string, string> = {
      attempt: '@tui.play', grading: '@tui.check-square', violation: '@tui.alert-triangle',
      certificate: '@tui.award', publish: '@tui.globe'
    };
    return m[type] || '@tui.activity';
  }

  timeAgo(timestamp: string): string {
    const diff = Date.now() - new Date(timestamp).getTime();
    const m = Math.floor(diff / 60000);
    if (m < 1) return 'Just now';
    if (m < 60) return m + 'm ago';
    const h = Math.floor(m / 60);
    if (h < 24) return h + 'h ago';
    const d = Math.floor(h / 24);
    return d + 'd ago';
  }
}
