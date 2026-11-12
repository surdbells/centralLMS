import { Component, DestroyRef, OnInit, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { CommonModule } from '@angular/common';
import { ReportService } from '../../services/report.service';
import { ReportChartComponent } from '../report-chart/report-chart.component';
import { TopComponent } from '../../../partials/top/top.component';
import { TuiIcon } from '@taiga-ui/core';
import { ActivatedRoute, Router } from '@angular/router';
import { Observable, forkJoin, of } from 'rxjs';
import { catchError, map } from 'rxjs/operators';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';

@Component({
  selector: 'app-learner-dashboard',
  standalone: true,
  imports: [CommonModule, ReportChartComponent, TopComponent, TuiIcon, TranslatePipe, SkeletonComponent],
  template: `
    <app-top></app-top>
    <main class="main">
      <div class="ui-page">
        <header class="ui-page-header">
          <button type="button" class="ui-page-header__back" (click)="goBack()" [attr.aria-label]="'common.back' | translate">
            <i class="bi bi-chevron-left"></i>
          </button>
          <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-graph-up-arrow"></i></div>
          <div class="ui-page-header__titles">
            <h1>{{ 'reports.my_learning_dashboard' | translate }}</h1>
            <p>{{ 'report.subtitle' | translate }}</p>
          </div>
        </header>

        <!-- Loading -->
        <ng-container *ngIf="loading">
          <app-skeleton variant="kpis" [count]="4"></app-skeleton>
          <app-skeleton variant="block" height="300px"></app-skeleton>
        </ng-container>

        <div *ngIf="!loading" class="learner-dashboard">
          <!-- KPI Row 1: Core stats -->
          <div class="ui-kpi-grid ui-kpi-grid--4">
            <div *ngFor="let kpi of kpisRow1" class="ui-kpi" [ngClass]="'ui-kpi--' + (kpi.color === 'primary' ? 'brand' : kpi.color === 'secondary' ? 'neutral' : kpi.color)">
              <span class="ui-kpi__label"><i [class]="kpi.icon"></i> {{ kpi.label }}</span>
              <span class="ui-kpi__value">{{ kpi.value }}</span>
            </div>
          </div>

          <!-- KPI Row 2: Performance stats -->
          <div class="ld-insights">
            <div class="ui-kpi-grid">
              <div *ngFor="let kpi of kpisRow2" class="ui-kpi" [ngClass]="'ui-kpi--' + (kpi.color === 'primary' ? 'brand' : kpi.color === 'secondary' ? 'neutral' : kpi.color)">
                <span class="ui-kpi__label"><i [class]="kpi.icon"></i> {{ kpi.label }}</span>
                <span class="ui-kpi__value">{{ kpi.value }}</span>
              </div>
            </div>
          </div>

          <!-- Charts Row -->
          <div class="ld-charts">
            <div class="ld-min">
              <app-report-chart *ngIf="progressChartData" [data]="progressChartData" chartType="line" [title]="'reports.my_performance_trend' | translate"
                                [allowTypeChange]="true" height="300px"></app-report-chart>
              <section *ngIf="!progressChartData" class="ui-section">
                <div class="ui-section__head">
                  <div><h2 class="ui-section__title">{{ 'reports.my_performance_trend' | translate }}</h2></div>
                </div>
                <div class="ui-state">
                  <span class="ui-state__icon"><i class="bi bi-graph-up"></i></span>
                  <p>{{ 'reports.complete_courses_trend' | translate }}</p>
                </div>
              </section>
            </div>
            <div class="ld-min" *ngIf="statusChartData">
              <app-report-chart [data]="statusChartData" chartType="doughnut" [title]="'reports.course_status' | translate"
                                [allowTypeChange]="false" height="300px"></app-report-chart>
            </div>
          </div>

          <!-- Tabs -->
          <nav class="ui-tabs" role="tablist">
            <button type="button" role="tab" class="ui-tab" *ngFor="let tab of tabs"
                    [class.is-active]="activeTab === tab.key" [attr.aria-selected]="activeTab === tab.key"
                    (click)="switchTab(tab.key)">
              <i class="bi" [ngClass]="tab.icon"></i> {{ tab.label }}
              <span class="ui-tab__count" *ngIf="tab.count !== null">{{ tab.count }}</span>
            </button>
          </nav>

          <!-- Enrolled Courses Tab -->
          <div *ngIf="activeTab === 'courses'">
            <app-skeleton *ngIf="loadingCourses" variant="table" [rows]="6" [cols]="7"></app-skeleton>
            <section class="ui-section ui-section--flush" *ngIf="!loadingCourses">
              <div class="table-responsive">
                <table class="table table-hover mb-0">
                  <thead>
                  <tr>
                    <th class="ps-4">{{ 'reports.course' | translate }}</th>
                    <th>{{ 'reports.category' | translate }}</th>
                    <th>{{ 'common.status' | translate }}</th>
                    <th>{{ 'reports.progress' | translate }}</th>
                    <th>{{ 'reports.score' | translate }}</th>
                    <th>{{ 'report.time_spent' | translate }}</th>
                    <th class="pe-4">{{ 'report.due_date' | translate }}</th>
                  </tr>
                  </thead>
                  <tbody>
                  <tr *ngFor="let c of courses" [class.table-danger-subtle]="c.is_overdue">
                    <td class="ps-4">
                      <div class="fw-medium">{{ c.title }}</div>
                      <small class="ui-muted" *ngIf="c.is_mandatory"><i class="bi bi-asterisk text-danger me-1"></i>{{ 'report.mandatory' | translate }}</small>
                    </td>
                    <td><small class="ui-muted">{{ c.category || '-' }}</small></td>
                    <td><span class="badge" [class]="getStatusBadge(c.status)">{{ formatStatus(c.status) }}</span></td>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <div class="progress" style="height:6px; width:70px">
                          <div class="progress-bar" [class]="getProgressBarClass(c.progress_percentage)"
                               [style.width.%]="c.progress_percentage || 0"></div>
                        </div>
                        <small class="ui-muted">{{ c.progress_percentage || 0 }}%</small>
                      </div>
                    </td>
                    <td>
                      <span *ngIf="c.final_score !== null && c.status === 'completed'">{{ c.final_score }}%</span>
                      <span *ngIf="c.final_score === null || c.status !== 'completed'" class="ui-muted">-</span>
                    </td>
                    <td><small class="ui-muted">{{ c.time_formatted || '-' }}</small></td>
                    <td class="pe-4">
                      <small [class]="c.is_overdue ? 'text-danger fw-semibold' : 'ui-muted'">
                        {{ c.due_date || '-' }}
                        <i class="bi bi-exclamation-triangle-fill ms-1" *ngIf="c.is_overdue"></i>
                      </small>
                    </td>
                  </tr>
                  <tr *ngIf="!courses.length">
                    <td colspan="7" class="ld-empty">{{ 'report.no_courses_enrolled' | translate }}</td>
                  </tr>
                  </tbody>
                </table>
              </div>
            </section>
          </div>

          <!-- Assessments Tab -->
          <div *ngIf="activeTab === 'assessments'">
            <app-skeleton *ngIf="loadingAssessments" variant="table" [rows]="6" [cols]="6"></app-skeleton>
            <section class="ui-section ui-section--flush" *ngIf="!loadingAssessments">
              <div class="table-responsive">
                <table class="table table-hover mb-0">
                  <thead>
                  <tr><th class="ps-4">{{ 'reports.assessment' | translate }}</th><th>{{ 'reports.course' | translate }}</th><th>{{ 'reports.score' | translate }}</th><th>{{ 'reports.result' | translate }}</th><th>{{ 'reports.attempts' | translate }}</th><th class="pe-4">{{ 'reports.date' | translate }}</th></tr>
                  </thead>
                  <tbody>
                  <tr *ngFor="let a of assessments">
                    <td class="ps-4 fw-medium">{{ a.title || a.assessment_title }}</td>
                    <td><small class="ui-muted">{{ a.course_title || '-' }}</small></td>
                    <td>
                        <span class="fw-semibold" [class]="a.passed ? 'text-success' : a.score !== null ? 'text-danger' : 'ui-muted'">
                          {{ a.score !== null ? a.score + '%' : '-' }}
                        </span>
                    </td>
                    <td>
                        <span class="ui-pill" [ngClass]="a.passed ? 'ui-pill--success' : a.score !== null ? 'ui-pill--danger' : 'ui-pill--warning'">
                          <span class="ui-pill__dot"></span>{{ (a.passed ? 'report.passed' : a.score !== null ? 'report.failed' : 'common.pending') | translate }}
                        </span>
                    </td>
                    <td><small class="ui-muted">{{ a.attempt_number || a.attempts || '-' }}</small></td>
                    <td class="pe-4"><small class="ui-muted">{{ a.completed_at || a.submitted_at || '-' }}</small></td>
                  </tr>
                  <tr *ngIf="!assessments.length">
                    <td colspan="6" class="ld-empty">{{ 'reports.no_assessment_results' | translate }}</td>
                  </tr>
                  </tbody>
                </table>
              </div>
            </section>
          </div>

          <!-- Achievements Tab -->
          <div *ngIf="activeTab === 'achievements'">
            <app-skeleton *ngIf="loadingAchievements" variant="list" [rows]="5"></app-skeleton>
            <div *ngIf="!loadingAchievements">
              <div class="ui-row">
                <!-- Certificates -->
                <section class="ui-section">
                  <div class="ui-section__head">
                    <div><h2 class="ui-section__title"><i class="bi bi-award me-2 text-warning"></i>{{ 'reports.certificates_count' | translate:{ count: certificates.length } }}</h2></div>
                  </div>
                  <ul class="ui-list">
                    <li *ngFor="let cert of certificates" class="justify-content-between">
                      <div class="ld-min">
                        <div class="small fw-medium">{{ cert.title || cert.course_title }}</div>
                        <small class="ui-muted">{{ cert.issue_date || cert.completed_at }}</small>
                      </div>
                      <span class="ui-pill ui-pill--warning" *ngIf="cert.certificate_number">{{ cert.certificate_number }}</span>
                    </li>
                  </ul>
                  <p *ngIf="!certificates.length" class="ui-muted small text-center mb-0 py-2">{{ 'report.no_certificates' | translate }}</p>
                </section>

                <!-- Badges -->
                <section class="ui-section">
                  <div class="ui-section__head">
                    <div><h2 class="ui-section__title"><i class="bi bi-star me-2 text-info"></i>{{ 'reports.badges_count' | translate:{ count: badges.length } }}</h2></div>
                  </div>
                  <ul class="ui-list">
                    <li *ngFor="let b of badges" class="justify-content-between">
                      <div class="ld-min">
                        <div class="small fw-medium">{{ b.name || b.badge_name }}</div>
                        <small class="ui-muted">{{ b.description || '' }}</small>
                      </div>
                      <small class="ui-muted">{{ b.earned_at || b.achieved_at }}</small>
                    </li>
                  </ul>
                  <p *ngIf="!badges.length" class="ui-muted small text-center mb-0 py-2">{{ 'reports.no_badges' | translate }}</p>
                </section>
              </div>

              <!-- Timeline -->
              <section class="ui-section" *ngIf="timeline.length">
                <div class="ui-section__head">
                  <div><h2 class="ui-section__title"><i class="bi bi-clock-history me-2"></i>{{ 'reports.achievements_timeline' | translate }}</h2></div>
                </div>
                <ul class="ui-list" style="max-height:300px; overflow-y:auto">
                  <li *ngFor="let t of timeline">
                    <i class="bi fs-5" [class]="getTimelineIcon(t.type)"></i>
                    <div class="flex-grow-1 ld-min">
                      <div class="small fw-medium">{{ t.name }}</div>
                      <small class="ui-muted">{{ formatTimelineType(t.type) }}</small>
                    </div>
                    <small class="ui-muted">{{ t.achieved_at }}</small>
                  </li>
                </ul>
              </section>
            </div>
          </div>

          <!-- Compliance Tab -->
          <div *ngIf="activeTab === 'compliance'">
            <app-skeleton *ngIf="loadingCompliance" variant="table" [rows]="6" [cols]="5"></app-skeleton>
            <section class="ui-section ui-section--flush" *ngIf="!loadingCompliance">
              <div class="table-responsive">
                <table class="table table-hover mb-0">
                  <thead>
                  <tr><th class="ps-4">{{ 'reports.mandatory_course' | translate }}</th><th>{{ 'common.status' | translate }}</th><th>{{ 'reports.progress' | translate }}</th><th>{{ 'report.due_date' | translate }}</th><th class="pe-4">{{ 'report.completed' | translate }}</th></tr>
                  </thead>
                  <tbody>
                  <tr *ngFor="let c of complianceCourses" [class.table-danger-subtle]="c.is_overdue">
                    <td class="ps-4 fw-medium">{{ c.title }}</td>
                    <td><span class="badge" [class]="getStatusBadge(c.status)">{{ formatStatus(c.status) }}</span></td>
                    <td>
                      <div class="progress" style="height:6px; width:70px">
                        <div class="progress-bar" [class]="getProgressBarClass(c.progress_percentage)"
                             [style.width.%]="c.progress_percentage || 0"></div>
                      </div>
                    </td>
                    <td>
                      <small [class]="c.is_overdue ? 'text-danger fw-semibold' : 'ui-muted'">
                        {{ c.due_date || '-' }}
                      </small>
                    </td>
                    <td class="pe-4"><small class="ui-muted">{{ c.completed_at || '-' }}</small></td>
                  </tr>
                  <tr *ngIf="!complianceCourses.length">
                    <td colspan="5" class="ld-empty">{{ 'reports.no_mandatory' | translate }}</td>
                  </tr>
                  </tbody>
                </table>
              </div>
            </section>
          </div>
        </div>
      </div>
    </main>
  `,
  styles: [`
    .ld-insights { margin-bottom: 8px; }
    .ld-insights .ui-kpi__value { font-size: 1.25rem; }
    .ld-charts { display: grid; grid-template-columns: minmax(0, 2fr) minmax(0, 1fr); gap: 20px; margin-bottom: 4px; }
    .ld-charts:has(> .ld-min:only-child) { grid-template-columns: minmax(0, 1fr); }
    @media (max-width: 991.98px) { .ld-charts { grid-template-columns: minmax(0, 1fr); } }
    .ld-min { min-width: 0; }
    .ld-empty { text-align: center; padding: 32px 16px !important; color: var(--ui-text-muted); }
    .table-danger-subtle { background-color: rgba(var(--ui-danger-rgb, 220, 53, 69), 0.05); }
    .ui-tabs { margin-bottom: 16px; }
  `]
})
export class LearnerDashboardComponent implements OnInit {
  loading = true;
  activeTab = 'courses';

  // KPI data
  summary: any = {};
  kpisRow1: any[] = [];
  kpisRow2: any[] = [];

  // Tabs
  tabs: { key: string; label: string; icon: string; badge: string; count: number | null }[] = [];

  // Tab data
  courses: any[] = [];
  assessments: any[] = [];
  certificates: any[] = [];
  badges: any[] = [];
  timeline: any[] = [];
  complianceCourses: any[] = [];

  // Loading states per tab
  loadingCourses = false;
  loadingAssessments = false;
  loadingAchievements = false;
  loadingCompliance = false;

  // Loaded flags to prevent re-fetching
  private loadedTabs = new Set<string>();

  /**
   * The tab asked for by `?tab=` (the learner home links to
   * `?tab=achievements`). Held until the tabs exist, then applied once.
   */
  private requestedTab: string | null = null;
  private readonly route = inject(ActivatedRoute);
  private readonly destroyRef = inject(DestroyRef);

  // Chart data
  progressChartData: any = null;
  statusChartData: any = null;

  constructor(private svc: ReportService, private router: Router, private i18n: I18nService) {}

  /**
   * Rows of a report, or [] when it failed.
   *
   * The "My learning" reports answer in camelCase (`course`, `progress`,
   * `dueDate`, `submittedAt` …); every loader below maps them to the names
   * this template reads, in one place.
   */
  private rows(key: string, filters: Record<string, any> = { per_page: 500 }): Observable<any[]> {
    return this.svc.runReport(key, filters).pipe(
      map(res => (Array.isArray(res?.data) ? res.data : [])),
      catchError(() => of([])),
    );
  }

  ngOnInit(): void {
    this.loading = true;

    // Same-route navigation (a new ?tab= on this page) reuses the component,
    // so the parameter is followed rather than read once.
    this.route.queryParamMap
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(params => {
        this.requestedTab = params.get('tab');
        this.applyRequestedTab();
      });

    forkJoin({
      summary: this.svc.runReport('my_learning_summary', {}).pipe(
        map(res => res?.summary ?? {}),
        catchError(() => of({} as Record<string, any>)),
      ),
      time: this.svc.runReport('my_learning_time', {}).pipe(
        map(res => res?.summary ?? {}),
        catchError(() => of({} as Record<string, any>)),
      ),
      courses: this.rows('my_enrolled_courses'),
      assessments: this.rows('my_assessment_results'),
    }).subscribe(({ summary, time, courses, assessments }) => {
      this.courses = courses.map(r => toCourse(r));
      this.assessments = assessments.map(r => toAssessment(r));
      this.loadedTabs.add('courses');
      this.loadedTabs.add('assessments');

      const scored = this.assessments.filter(a => a.score !== null);
      const d = {
        total_enrolled: num(summary['enrolled'] ?? this.courses.length),
        completed: num(summary['completed']),
        in_progress: num(summary['inProgress']),
        overdue: this.courses.filter(c => c.is_overdue).length,
        avg_progress: this.courses.length
          ? Math.round(this.courses.reduce((s, c) => s + c.progress_percentage, 0) / this.courses.length)
          : 0,
        avg_score: scored.length ? Math.round(scored.reduce((s, a) => s + a.score, 0) / scored.length) : 0,
        total_learning_hours: num(time['totalHours']),
        certificates: num(summary['certificates']),
        badges: num(summary['badges']),
        points: num(summary['points']),
      };

      this.summary = d;
      this.buildKPIs(d);
      this.buildStatusChart();
      this.buildProgressChart();
      this.buildTabs(d);
      this.loading = false;
      this.applyRequestedTab();
    });
  }

  // ═══════════════════════════════════════════
  //  BUILD KPIs FROM SUMMARY
  // ═══════════════════════════════════════════

  private buildKPIs(d: any): void {
    this.kpisRow1 = [
      { label: this.i18n.t('report.enrolled'), value: d.total_enrolled ?? 0, icon: 'bi bi-book-fill', color: 'primary' },
      { label: this.i18n.t('report.completed'), value: d.completed ?? 0, icon: 'bi bi-check-circle-fill', color: 'success' },
      { label: this.i18n.t('report.in_progress'), value: d.in_progress ?? 0, icon: 'bi bi-hourglass-split', color: 'warning' },
      { label: this.i18n.t('reports.overdue'), value: d.overdue ?? 0, icon: 'bi bi-exclamation-triangle-fill', color: 'danger' },
    ];
    this.kpisRow2 = [
      { label: this.i18n.t('reports.avg_progress'), value: (d.avg_progress ?? 0) + '%', icon: 'bi bi-speedometer2', color: 'info' },
      { label: this.i18n.t('reports.avg_score'), value: (d.avg_score ?? 0) + '%', icon: 'bi bi-bullseye', color: 'primary' },
      { label: this.i18n.t('reports.learning_hours'), value: d.total_learning_hours ?? 0, icon: 'bi bi-clock', color: 'secondary' },
      { label: this.i18n.t('report.tab_certificates'), value: d.certificates ?? 0, icon: 'bi bi-award-fill', color: 'warning' },
      { label: this.i18n.t('reports.badges'), value: d.badges ?? 0, icon: 'bi bi-star-fill', color: 'info' },
      { label: this.i18n.t('reports.points'), value: d.points ?? 0, icon: 'bi bi-gem', color: 'success' },
    ];
  }

  // ═══════════════════════════════════════════
  //  BUILD CHARTS
  // ═══════════════════════════════════════════

  /**
   * Parts of a whole, from the course rows: an overdue course is counted as
   * overdue rather than also as in progress, so the slices add up.
   */
  private buildStatusChart(): void {
    let completed = 0, inProgress = 0, notStarted = 0, overdue = 0;
    for (const c of this.courses) {
      if (c.status === 'completed') completed++;
      else if (c.is_overdue) overdue++;
      else if (c.status === 'in_progress') inProgress++;
      else if (c.status !== 'dropped') notStarted++;
    }

    this.statusChartData = completed + inProgress + notStarted + overdue === 0 ? null : {
      data: [
        { label: this.i18n.t('report.completed'), value: completed },
        { label: this.i18n.t('report.in_progress'), value: inProgress },
        { label: this.i18n.t('report.not_started'), value: notStarted },
        { label: this.i18n.t('reports.overdue'), value: overdue },
      ].filter(item => item.value > 0),
    };
  }

  /**
   * Month by month: the average assessment score when there are scores to
   * average, otherwise the courses completed. One unit per chart — a
   * percentage and a count on one axis flatten one of them.
   */
  private buildProgressChart(): void {
    const scored = this.assessments.filter(a => a.score !== null && a.submitted_at);
    const months = new Map<string, number[]>();

    if (scored.length) {
      for (const a of scored) bucket(months, a.submitted_at).push(a.score);
    } else {
      for (const c of this.courses) if (c.completed_at) bucket(months, c.completed_at).push(1);
    }
    if (!months.size) { this.progressChartData = null; return; }

    const keys = [...months.keys()].sort().slice(-12);
    const values = keys.map(k => {
      const v = months.get(k)!;
      return scored.length ? Math.round(v.reduce((s, x) => s + x, 0) / v.length) : v.length;
    });

    this.progressChartData = {
      labels: keys.map(k => monthLabel(k)),
      datasets: [{
        label: this.i18n.t(scored.length ? 'reports.avg_score' : 'reports.kpi_completions'),
        data: values,
      }],
    };
  }

  // ═══════════════════════════════════════════
  //  TABS
  // ═══════════════════════════════════════════

  private buildTabs(d: any): void {
    this.tabs = [
      { key: 'courses', label: this.i18n.t('reports.tab_my_courses'), icon: 'bi-book', badge: 'bg-primary', count: d.total_enrolled ?? null },
      { key: 'assessments', label: this.i18n.t('reports.tab_assessments'), icon: 'bi-pencil-square', badge: 'bg-info', count: this.assessments.length || null },
      { key: 'achievements', label: this.i18n.t('reports.tab_achievements'), icon: 'bi-trophy', badge: 'bg-warning', count: (d.certificates ?? 0) + (d.badges ?? 0) || null },
      { key: 'compliance', label: this.i18n.t('reports.tab_compliance'), icon: 'bi-shield-check', badge: 'bg-danger', count: d.overdue || null },
    ];
  }

  /** Switches to the `?tab=` tab once the tabs are built, if it names one. */
  private applyRequestedTab(): void {
    const wanted = this.requestedTab;
    if (!wanted || this.tabs.length === 0) return;
    this.requestedTab = null;
    if (wanted !== this.activeTab && this.tabs.some(t => t.key === wanted)) {
      this.switchTab(wanted);
    }
  }

  switchTab(tab: string): void {
    this.activeTab = tab;
    this.loadTabData(tab);
  }

  private loadTabData(tab: string): void {
    if (this.loadedTabs.has(tab)) return;
    this.loadedTabs.add(tab);

    switch (tab) {
      case 'courses':
        this.loadingCourses = true;
        this.rows('my_enrolled_courses').subscribe(rows => {
          this.courses = rows.map(r => toCourse(r));
          this.loadingCourses = false;
        });
        break;

      case 'assessments':
        this.loadingAssessments = true;
        this.rows('my_assessment_results').subscribe(rows => {
          this.assessments = rows.map(r => toAssessment(r));
          this.loadingAssessments = false;
        });
        break;

      case 'achievements':
        this.loadingAchievements = true;
        forkJoin({
          certificates: this.rows('my_certificates'),
          badges: this.rows('my_badges'),
        }).subscribe(({ certificates, badges }) => {
          this.certificates = certificates.map(c => ({
            title: c.title ?? '',
            certificate_number: c.certificate ?? '',
            issue_date: day(c.issuedAt),
            issued_at: c.issuedAt ?? null,
          }));
          this.badges = badges.map(b => ({
            name: b.badge ?? '',
            description: humanise(b.type),
            earned_at: day(b.earnedAt),
            earned_raw: b.earnedAt ?? null,
          }));
          // The timeline is the three kinds of achievement, newest first.
          this.timeline = [
            ...this.courses.filter(c => c.completed_at).map(c => ({ type: 'course_completed', name: c.title, at: c.completed_raw })),
            ...this.certificates.filter(c => c.issued_at).map(c => ({ type: 'certificate_earned', name: c.title, at: c.issued_at })),
            ...this.badges.filter(b => b.earned_raw).map(b => ({ type: 'badge_earned', name: b.name, at: b.earned_raw })),
          ]
            .sort((a, b) => String(b.at).localeCompare(String(a.at)))
            .map(t => ({ type: t.type, name: t.name, achieved_at: day(t.at) }));
          this.loadingAchievements = false;
        });
        break;

      case 'compliance':
        this.loadingCompliance = true;
        this.rows('my_mandatory_training').subscribe(rows => {
          this.complianceCourses = rows.map(r => toCourse(r));
          this.loadingCompliance = false;
        });
        break;
    }
  }

  // ═══════════════════════════════════════════
  //  HELPERS
  // ═══════════════════════════════════════════

  getStatusBadge(status: string): string {
    const m: Record<string, string> = {
      completed: 'bg-success-subtle text-success',
      in_progress: 'bg-warning-subtle text-warning',
      overdue: 'bg-danger-subtle text-danger',
      not_started: 'bg-secondary-subtle text-secondary',
      expired: 'bg-danger-subtle text-danger'
    };
    return m[status] || 'bg-secondary-subtle text-secondary';
  }

  formatStatus(status: string): string {
    return (status || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  getProgressBarClass(pct: number): string {
    if (pct >= 80) return 'bg-success';
    if (pct >= 50) return 'bg-warning';
    return 'bg-danger';
  }

  getTimelineIcon(type: string): string {
    const m: Record<string, string> = {
      course_completed: 'bi-check-circle-fill text-success',
      certificate_earned: 'bi-award-fill text-warning',
      badge_earned: 'bi-star-fill text-info',
    };
    return m[type] || 'bi-circle-fill text-secondary';
  }

  formatTimelineType(type: string): string {
    const m: Record<string, string> = {
      course_completed: this.i18n.t('reports.timeline_course_completed'),
      certificate_earned: this.i18n.t('reports.timeline_certificate_earned'),
      badge_earned: this.i18n.t('reports.timeline_badge_earned'),
    };
    return m[type] || type;
  }

  goBack(): void {
    this.router.navigate(['/account']);
  }
}

// ---------------------------------------------------------------------------
// API rows → the names the template reads
// ---------------------------------------------------------------------------

function num(v: unknown): number {
  const n = Number(v);
  return Number.isFinite(n) ? n : 0;
}

/** An ISO timestamp as its date, or null. */
function day(v: unknown): string | null {
  if (v === null || v === undefined || v === '') return null;
  return String(v).slice(0, 10);
}

function humanise(v: unknown): string {
  return String(v ?? '').replace(/_/g, ' ').replace(/^\w/, c => c.toUpperCase());
}

/** A course row from my_enrolled_courses / my_mandatory_training. */
function toCourse(r: any): any {
  const status = String(r?.status ?? '');
  const due = day(r?.dueDate);
  const today = new Date().toISOString().slice(0, 10);
  return {
    title: r?.course ?? r?.title ?? '',
    category: r?.category ?? null,
    status,
    progress_percentage: Math.round(num(r?.progress)),
    final_score: r?.score ?? null,
    time_formatted: null,
    due_date: due,
    completed_at: day(r?.completedAt),
    completed_raw: r?.completedAt ?? null,
    is_overdue: status !== 'completed' && status !== 'dropped' && !!due && due < today,
    is_mandatory: !!r?.mandatory,
  };
}

/** An attempt row from my_assessment_results. */
function toAssessment(r: any): any {
  const raw = r?.score;
  const score = raw === null || raw === undefined || raw === '' || !Number.isFinite(Number(raw)) ? null : Math.round(Number(raw) * 10) / 10;
  return {
    title: r?.assessment ?? r?.title ?? '',
    course_title: r?.course ?? null,
    score,
    passed: String(r?.verdict ?? '').toLowerCase() === 'passed',
    attempts: r?.attempt ?? null,
    submitted_at: day(r?.submittedAt),
  };
}

/** The month bucket ('YYYY-MM') a date falls in. */
function bucket(months: Map<string, number[]>, date: string): number[] {
  const key = String(date).slice(0, 7);
  if (!months.has(key)) months.set(key, []);
  return months.get(key)!;
}

function monthLabel(key: string): string {
  const [y, m] = key.split('-').map(Number);
  if (!y || !m) return key;
  return new Date(y, m - 1, 1).toLocaleDateString(undefined, { month: 'short', year: '2-digit' });
}
