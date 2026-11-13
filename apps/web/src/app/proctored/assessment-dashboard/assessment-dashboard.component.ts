// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 15
// Admin: Assessment Dashboard Component
// Multi-Tenant LMS | Angular Standalone
// Analytics overview: stats, charts, recent activity
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Subject, forkJoin, of, takeUntil } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { TuiIcon } from '@taiga-ui/core';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../translate.pipe';
import { SelectComponent } from '../../shared/select/select.component';
import { AssessmentAdminService } from '../assessment-admin.service';
import { AssessmentsService } from '../../services/assessments.service';
import { CertificatesService } from '../../services/certificates.service';

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
interface TopAssessment { assessment_id: string; title: string; type: string; attempts: number; avg_score: number; pass_rate: number; }
interface RecentActivity { id: string; type: string; message: string; timestamp: string; user_name: string; assessment_title: string; }
interface DifficultyBreakdown { difficulty: string; count: number; avg_score: number; pass_rate: number; }

@Component({
  selector: 'app-assessment-dashboard',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, TuiIcon, AdmintopComponent, TranslatePipe, SelectComponent],
  template: `
    <app-admintop></app-admintop>

    <main class="main">
    <div class="ui-page admin-dash">
      <header class="ui-page-header">
        <a routerLink="/administration" class="ui-page-header__back" [attr.aria-label]="'common.back' | translate"><i class="bi bi-chevron-left"></i></a>
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-speedometer2"></i></div>
        <div class="ui-page-header__titles">
          <h1>Assessment Dashboard</h1>
          <p>Overview of assessment performance and activity</p>
        </div>
        <div class="ui-page-header__actions">
          <app-select style="width:170px" [options]="dateRangeOpts" [searchable]="false" [(ngModel)]="dateRange" (selectionChange)="loadDashboard()" ariaLabel="Date range" />
          <button type="button" class="btn btn-primary" (click)="router.navigate(['/admin/assessments/create'])">
            <i class="bi bi-plus-lg"></i> New Assessment
          </button>
        </div>
      </header>

      <!-- Stat Cards -->
      <div class="ui-kpi-grid dash-kpis" *ngIf="stats">
        <button type="button" class="ui-kpi ui-kpi--brand" (click)="router.navigate(['/admin/assessments'])">
          <span class="ui-kpi__label"><i class="bi bi-file-earmark-text"></i> Assessments</span>
          <span class="ui-kpi__value">{{ stats.total_assessments }}</span>
          <span class="ui-kpi__sub">{{ stats.published }} published · {{ stats.draft }} drafts</span>
          <span class="ui-kpi__sub" *ngIf="stats.attempts_change_percent"
                [class.ui-kpi__sub--up]="stats.attempts_change_percent>0" [class.ui-kpi__sub--down]="stats.attempts_change_percent<0">
            <i class="bi" [class.bi-graph-up-arrow]="stats.attempts_change_percent>0" [class.bi-graph-down-arrow]="stats.attempts_change_percent<0"></i>
            {{ stats.attempts_change_percent>0?'+':'' }}{{ stats.attempts_change_percent }}%
          </span>
        </button>
        <div class="ui-kpi ui-kpi--info">
          <span class="ui-kpi__label"><i class="bi bi-people"></i> Total Attempts</span>
          <span class="ui-kpi__value">{{ stats.total_attempts | number }}</span>
          <span class="ui-kpi__sub">{{ stats.attempts_this_month }} this month</span>
        </div>
        <div class="ui-kpi ui-kpi--success">
          <span class="ui-kpi__label"><i class="bi bi-percent"></i> Pass Rate</span>
          <span class="ui-kpi__value">{{ stats.overall_pass_rate | number:'1.0-0' }}<small>%</small></span>
          <span class="ui-kpi__sub">Avg score: {{ stats.avg_score | number:'1.0-0' }}%</span>
        </div>
        <button type="button" class="ui-kpi ui-kpi--warning" (click)="router.navigate(['/admin/grading'])">
          <span class="ui-kpi__label"><i class="bi bi-pencil-square"></i> Pending Grading</span>
          <span class="ui-kpi__value">{{ stats.pending_grading }}</span>
          <span class="ui-kpi__sub kpi-act" *ngIf="stats.pending_grading>0">Review now →</span>
        </button>
        <button type="button" class="ui-kpi ui-kpi--danger" (click)="router.navigate(['/admin/proctoring/sessions'])">
          <span class="ui-kpi__label"><i class="bi bi-shield-exclamation"></i> Flagged Sessions</span>
          <span class="ui-kpi__value">{{ stats.flagged_sessions }}</span>
          <span class="ui-kpi__sub kpi-act" *ngIf="stats.flagged_sessions>0">Review now →</span>
        </button>
        <div class="ui-kpi ui-kpi--brand">
          <span class="ui-kpi__label"><i class="bi bi-award"></i> Certificates Issued</span>
          <span class="ui-kpi__value">{{ stats.total_certificates }}</span>
          <span class="ui-kpi__sub">{{ stats.active_learners }} active learners</span>
        </div>
      </div>

      <div class="ui-row">
        <!-- Monthly Trend Chart -->
        <section class="ui-section">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title">Monthly Trends</h2><p class="ui-section__subtitle">Attempts per month, with the pass rate</p></div>
          </div>
          <div class="bar-chart" *ngIf="monthlyTrends.length; else noTrends">
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
          <ng-template #noTrends>
            <div class="ui-state ui-state--compact"><span class="ui-state__icon"><i class="bi bi-bar-chart"></i></span><p>No attempts in this period yet.</p></div>
          </ng-template>
        </section>

        <!-- Type Distribution -->
        <section class="ui-section">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title">By Type</h2><p class="ui-section__subtitle">How your assessments split by kind</p></div>
          </div>
          <div class="donut-chart" *ngIf="typeDistribution.length; else noTypes">
            <div class="donut-visual">
              <svg viewBox="0 0 120 120" class="donut-svg">
                <circle cx="60" cy="60" r="46" fill="none" class="donut-track" stroke-width="18"/>
                <ng-container *ngFor="let seg of donutSegments; let i=index">
                  <circle cx="60" cy="60" r="46" fill="none" [style.stroke]="donutColors[i]" stroke-width="18"
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
          <ng-template #noTypes>
            <div class="ui-state ui-state--compact"><span class="ui-state__icon"><i class="bi bi-pie-chart"></i></span><p>No assessments yet.</p></div>
          </ng-template>
        </section>
      </div>

      <div class="ui-row">
        <!-- Top Assessments -->
        <section class="ui-section">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title">Top Assessments</h2><p class="ui-section__subtitle">Most attempted</p></div>
          </div>
          <ul class="ui-list top-list">
            <li *ngFor="let a of topAssessments; let i=index" class="tt-row" (click)="router.navigate(['/admin/assessments', a.assessment_id])">
              <span class="ui-avatar tt-rank">{{ i+1 }}</span>
              <div class="tt-info">
                <span class="tt-title">{{ a.title }}</span>
                <span class="tt-type">{{ a.type }}</span>
              </div>
              <div class="tt-stats">
                <span>{{ a.attempts }} attempts</span>
                <span class="ui-pill ui-pill--success"><span class="ui-pill__dot"></span>{{ a.pass_rate | number:'1.0-0' }}% pass</span>
              </div>
            </li>
          </ul>
          <div class="ui-state ui-state--compact" *ngIf="!topAssessments.length">
            <span class="ui-state__icon"><i class="bi bi-trophy"></i></span><p>No assessments yet.</p>
          </div>
        </section>

        <!-- Recent Activity -->
        <section class="ui-section">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title">Recent Activity</h2><p class="ui-section__subtitle">Latest attempts, grading and publishing</p></div>
          </div>
          <ul class="ui-list activity-feed">
            <li *ngFor="let a of recentActivity" class="af-item">
              <div class="af-icon" [attr.data-type]="a.type">
                <tui-icon [icon]="getActivityIcon(a.type)" style="font-size:14px"></tui-icon>
              </div>
              <div class="af-content">
                <span class="af-msg">{{ a.message }}</span>
                <span class="af-time">{{ timeAgo(a.timestamp) }}</span>
              </div>
            </li>
          </ul>
          <div class="ui-state ui-state--compact" *ngIf="!recentActivity.length">
            <span class="ui-state__icon"><i class="bi bi-activity"></i></span><p>No recent activity.</p>
          </div>
        </section>
      </div>

      <!-- Difficulty Breakdown -->
      <section class="ui-section" *ngIf="diffBreakdown.length">
        <div class="ui-section__head">
          <div><h2 class="ui-section__title">Performance by Difficulty</h2><p class="ui-section__subtitle">Question count, average score and pass rate per level</p></div>
        </div>
        <div class="diff-grid">
          <div *ngFor="let d of diffBreakdown" class="diff-card" [attr.data-diff]="d.difficulty">
            <h4>{{ d.difficulty | titlecase }}</h4>
            <div class="diff-stats">
              <div class="ds"><span class="ds-val">{{ d.count }}</span><span class="ds-lbl">Questions</span></div>
              <div class="ds"><span class="ds-val">{{ d.avg_score | number:'1.0-0' }}%</span><span class="ds-lbl">Avg Score</span></div>
              <div class="ds"><span class="ds-val">{{ d.pass_rate | number:'1.0-0' }}%</span><span class="ds-lbl">Pass Rate</span></div>
            </div>
            <div class="ui-bar diff-bar"><span [style.width.%]="d.pass_rate"
                 [style.background]="d.difficulty==='beginner'?'var(--ui-success)':d.difficulty==='intermediate'?'var(--ui-warning)':'var(--ui-danger)'"></span></div>
          </div>
        </div>
      </section>
    </div>
    </main>
  `,
  styles: [`
    :host { display: block; }

    .range-select { width: auto; min-width: 150px; }
    .dash-kpis { grid-template-columns: repeat(6, minmax(0, 1fr)); margin-bottom: 20px; }
    .kpi-act { color: var(--brand-primary); font-weight: 600; }

    .ui-state--compact { padding: 28px 16px; }
    .ui-state--compact p { margin: 10px 0 0; }

    /* Monthly trend bars */
    .bar-chart { display: flex; gap: 8px; align-items: flex-end; height: 200px; }
    .bar-group { flex: 1; display: flex; flex-direction: column; align-items: center; height: 100%; min-width: 0; }
    .bar-wrapper { flex: 1; width: 100%; display: flex; align-items: flex-end; justify-content: center; }
    .bar.attempts { width: 100%; max-width: 32px; background: var(--brand-primary); border-radius: 6px 6px 0 0; min-height: 4px; transition: height 0.5s; cursor: pointer; opacity: .9; }
    .bar.attempts:hover { opacity: 1; }
    .bar-meta { text-align: center; padding-top: 6px; }
    .bar-label { display: block; font-size: .6875rem; color: var(--ui-text-muted); }
    .bar-val { font-size: .6875rem; color: var(--ui-success-text); font-weight: 600; font-variant-numeric: tabular-nums; }

    /* Type donut */
    .donut-chart { display: flex; align-items: center; gap: 24px; }
    .donut-visual { position: relative; width: 140px; height: 140px; flex-shrink: 0; }
    .donut-svg { width: 100%; height: 100%; }
    .donut-track { stroke: var(--ui-surface-2); }
    .donut-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .dc-val { font-size: 1.5rem; font-weight: 700; color: var(--ui-text); font-variant-numeric: tabular-nums; }
    .dc-lbl { font-size: .6875rem; color: var(--ui-text-muted); }
    .donut-legend { display: flex; flex-direction: column; gap: 8px; flex: 1; min-width: 0; }
    .dl-item { display: flex; align-items: center; gap: 8px; font-size: .8125rem; }
    .dl-dot { width: 10px; height: 10px; border-radius: 3px; flex-shrink: 0; }
    .dl-name { flex: 1; color: var(--ui-text); }
    .dl-val { color: var(--ui-text-muted); font-size: .75rem; font-variant-numeric: tabular-nums; }

    /* Top assessments */
    .tt-row { cursor: pointer; }
    .tt-row:hover .tt-title { color: var(--brand-primary); }
    .tt-rank { width: 28px; height: 28px; font-size: .75rem; }
    .tt-info { flex: 1; min-width: 0; }
    .tt-title { display: block; font-size: .875rem; font-weight: 550; color: var(--ui-text); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; transition: color .15s ease; }
    .tt-type { font-size: .6875rem; color: var(--ui-text-subtle); text-transform: uppercase; letter-spacing: .03em; }
    .tt-stats { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; font-size: .75rem; color: var(--ui-text-muted); }

    /* Activity feed */
    .activity-feed { max-height: 300px; overflow-y: auto; }
    .activity-feed .af-item { align-items: flex-start; }
    .af-icon { width: 30px; height: 30px; border-radius: var(--ui-radius-ctl); display: flex; align-items: center; justify-content: center; flex-shrink: 0; background: rgba(var(--brand-primary-rgb), .08); color: var(--brand-primary); }
    .af-icon[data-type="grading"] { background: rgba(var(--ui-success-rgb), .1); color: var(--ui-success); }
    .af-icon[data-type="violation"] { background: rgba(var(--ui-danger-rgb), .1); color: var(--ui-danger); }
    .af-icon[data-type="certificate"] { background: rgba(var(--ui-warning-rgb), .1); color: var(--ui-warning); }
    .af-icon[data-type="publish"] { background: rgba(var(--ui-info-rgb), .1); color: var(--ui-info); }
    .af-content { flex: 1; min-width: 0; }
    .af-msg { font-size: .8125rem; color: var(--ui-text); display: block; }
    .af-time { font-size: .6875rem; color: var(--ui-text-subtle); }

    /* Difficulty */
    .diff-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
    .diff-card { padding: 16px 18px; border-radius: var(--ui-radius-card); border: 1px solid var(--ui-border); background: var(--ui-surface); }
    .diff-card[data-diff="beginner"] { border-left: 3px solid var(--ui-success); }
    .diff-card[data-diff="intermediate"] { border-left: 3px solid var(--ui-warning); }
    .diff-card[data-diff="professional"] { border-left: 3px solid var(--ui-danger); }
    .diff-card h4 { margin: 0 0 12px; font-size: .9375rem; font-weight: 600; color: var(--ui-text); }
    .diff-stats { display: flex; gap: 16px; margin-bottom: 12px; }
    .ds { text-align: left; }
    .ds-val { display: block; font-size: 1.125rem; font-weight: 700; color: var(--ui-text); font-variant-numeric: tabular-nums; }
    .ds-lbl { font-size: .6875rem; color: var(--ui-text-muted); }

    @media (max-width: 1199.98px) { .dash-kpis { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 767.98px) { .dash-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } .diff-grid { grid-template-columns: 1fr; } .donut-chart { flex-direction: column; align-items: flex-start; } }
  `]
})
export class AssessmentDashboardComponent implements OnInit, OnDestroy {
  private admin = inject(AssessmentAdminService);
  private assessments = inject(AssessmentsService);
  private certificates = inject(CertificatesService);
  router = inject(Router);
  private destroy$ = new Subject<void>();

  stats: DashboardStats|null = null;
  monthlyTrends: MonthlyTrend[] = [];
  typeDistribution: TypeDistribution[] = [];
  topAssessments: TopAssessment[] = [];
  recentActivity: RecentActivity[] = [];
  diffBreakdown: DifficultyBreakdown[] = [];
  dateRange = '30d';
  readonly dateRangeOpts = [
    { label: 'Last 7 Days', value: '7d' }, { label: 'Last 30 Days', value: '30d' },
    { label: 'Last 90 Days', value: '90d' }, { label: 'Last Year', value: '1y' },
  ];

  donutColors = ['var(--brand-primary)', 'var(--ui-success)', 'var(--ui-warning)', 'var(--ui-danger)', 'var(--ui-info)'];
  donutSegments: {dash: string; offset: string}[] = [];

  get maxAttempts(): number { return Math.max(...this.monthlyTrends.map(m => m.attempts), 1); }

  ngOnInit(): void {
    this.loadDashboard();
  }
  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }

  /**
   * The headline scalars, trend, activity feed and difficulty breakdown come
   * from the dedicated dashboard endpoint; the type distribution and top table
   * are still assembled from the authoring listing, and the pending-grading and
   * certificate counts from their own sources.
   */
  loadDashboard(): void {
    forkJoin({
      dash: this.admin.dashboard(this.dateRange).pipe(catchError(() => of(null))),
      stats: this.admin.stats().pipe(catchError(() => of(null))),
      list: this.admin.list({ perPage: 200 }).pipe(catchError(() => of(null))),
      awaiting: this.assessments.awaitingMarking().pipe(catchError(() => of(null))),
      certs: this.certificates.mine().pipe(catchError(() => of([]))),
    }).pipe(takeUntil(this.destroy$)).subscribe(({ dash, stats, list, awaiting, certs }) => {
      const items = list?.items ?? [];
      this.stats = {
        total_assessments: stats?.total ?? items.length,
        published: stats?.published ?? 0,
        draft: stats?.draft ?? 0,
        total_attempts: stats?.totalAttempts ?? 0,
        attempts_this_month: dash?.stats.attemptsThisMonth ?? 0,
        attempts_change_percent: dash?.stats.attemptsChangePercent ?? 0,
        avg_score: Math.round(dash?.stats.avgScore ?? 0),
        overall_pass_rate: Math.round(stats?.avgPassRate ?? 0),
        total_learners: dash?.stats.totalLearners ?? 0,
        active_learners: dash?.stats.activeLearners ?? 0,
        pending_grading: awaiting?.meta.total ?? 0,
        flagged_sessions: 0,
        total_questions: dash?.stats.totalQuestions ?? 0,
        total_certificates: certs.length,
      };

      // Type distribution, from the listing grouped by kind.
      const byKind = new Map<string, number>();
      for (const a of items) byKind.set(a.kind, (byKind.get(a.kind) ?? 0) + 1);
      const totalKinds = items.length || 1;
      this.typeDistribution = Array.from(byKind, ([type, count]) => ({
        type, count, percentage: Math.round((count / totalKinds) * 100),
      }));

      // Top assessments, by attempts, from the embedded per-assessment stats.
      this.topAssessments = [...items]
        .sort((a, b) => b.totalAttempts - a.totalAttempts)
        .slice(0, 5)
        .map(a => ({
          assessment_id: a.id, title: a.title, type: a.kind,
          attempts: a.totalAttempts, avg_score: Math.round(a.avgScore ?? 0), pass_rate: Math.round(a.passRate ?? 0),
        }));

      this.monthlyTrends = (dash?.monthlyTrends ?? []).map(m => ({
        month: m.month, attempts: m.attempts, pass_rate: m.passRate, avg_score: m.avgScore,
      }));
      this.recentActivity = (dash?.recentActivity ?? []).map(a => ({
        id: a.id, type: a.type, message: a.message, timestamp: a.timestamp,
        user_name: a.userName ?? '', assessment_title: a.assessmentTitle ?? '',
      }));
      this.diffBreakdown = (dash?.difficultyBreakdown ?? []).map(d => ({
        difficulty: d.difficulty, count: d.count, avg_score: d.avgScore, pass_rate: d.passRate,
      }));
      this.buildDonut();
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
