import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReportService } from '../../services/report.service';
import { ReportChartComponent } from '../report-chart/report-chart.component';
import { TopComponent } from '../../../partials/top/top.component';
import { TuiIcon } from '@taiga-ui/core';
import { Router } from '@angular/router';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';

@Component({
  selector: 'app-learner-dashboard',
  standalone: true,
  imports: [CommonModule, ReportChartComponent, TopComponent, TuiIcon, TranslatePipe],
  template: `
    <app-top></app-top>
    <main class="main">
      <div class="container-fluid py-4">
        <!-- Back -->
        <div class="row align-items-center mb-3">
          <div (click)="goBack()" role="button" class="col-auto">
            <div class="avatar text-center avatar-md rounded text-primary-emphasis">
              <i class="fs-5"><tui-icon icon="@tui.chevron-left"></tui-icon></i>
            </div>
          </div>
          <div class="col">
            <h4 class="fw-bold mb-0">{{ 'reports.my_learning_dashboard' | translate }}</h4>
          </div>
        </div>

        <!-- Loading -->
        <div *ngIf="loading" class="text-center py-5">
          <div class="spinner-border text-primary"></div>
          <p class="text-muted mt-2 small">{{ 'reports.loading_dashboard' | translate }}</p>
        </div>

        <div *ngIf="!loading" class="learner-dashboard">
          <!-- KPI Row 1: Core stats -->
          <div class="row g-3 mb-3">
            <div *ngFor="let kpi of kpisRow1" class="col-6 col-md-3">
              <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3 text-center">
                  <i [class]="kpi.icon + ' fs-4 d-block mb-1 text-' + kpi.color"></i>
                  <h4 class="fw-bold mb-0">{{ kpi.value }}</h4>
                  <p class="text-muted small mb-0">{{ kpi.label }}</p>
                </div>
              </div>
            </div>
          </div>

          <!-- KPI Row 2: Performance stats -->
          <div class="row g-3 mb-4">
            <div *ngFor="let kpi of kpisRow2" class="col-6 col-md">
              <div class="card border-0 shadow-sm h-100">
                <div class="card-body py-3 text-center">
                  <i [class]="kpi.icon + ' fs-5 d-block mb-1 text-' + kpi.color"></i>
                  <h5 class="fw-bold mb-0">{{ kpi.value }}</h5>
                  <p class="text-muted small mb-0">{{ kpi.label }}</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Charts Row -->
          <div class="row g-4 mb-4">
            <div class="col-md-8">
              <div *ngIf="progressChartData">
                <app-report-chart [data]="progressChartData" chartType="line" [title]="'reports.my_performance_trend' | translate"
                                  [allowTypeChange]="true" height="300px"></app-report-chart>
              </div>
              <div *ngIf="!progressChartData" class="card border-0 shadow-sm">
                <div class="card-body text-center py-5 text-muted">
                  <i class="bi bi-graph-up fs-1 d-block mb-2"></i>
                  <p>{{ 'reports.complete_courses_trend' | translate }}</p>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div *ngIf="statusChartData">
                <app-report-chart [data]="statusChartData" chartType="doughnut" [title]="'reports.course_status' | translate"
                                  [allowTypeChange]="false" height="300px"></app-report-chart>
              </div>
            </div>
          </div>

          <!-- Tabs -->
          <ul class="nav nav-tabs mb-3">
            <li class="nav-item" *ngFor="let tab of tabs">
              <a class="nav-link" [class.active]="activeTab === tab.key" role="button"
                 (click)="switchTab(tab.key)">
                <i class="bi me-1" [class]="tab.icon"></i>{{ tab.label }}
                <span class="badge rounded-pill ms-1" [class]="tab.badge" *ngIf="tab.count !== null">{{ tab.count }}</span>
              </a>
            </li>
          </ul>

          <!-- Enrolled Courses Tab -->
          <div *ngIf="activeTab === 'courses'">
            <div *ngIf="loadingCourses" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></div>
            <div class="card border-0 shadow-sm" *ngIf="!loadingCourses">
              <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                  <thead class="table-light">
                  <tr>
                    <th class="px-3">{{ 'reports.course' | translate }}</th>
                    <th>{{ 'reports.category' | translate }}</th>
                    <th>{{ 'common.status' | translate }}</th>
                    <th>{{ 'reports.progress' | translate }}</th>
                    <th>{{ 'reports.score' | translate }}</th>
                    <th>{{ 'report.time_spent' | translate }}</th>
                    <th>{{ 'report.due_date' | translate }}</th>
                  </tr>
                  </thead>
                  <tbody>
                  <tr *ngFor="let c of courses" [class.table-danger-subtle]="c.is_overdue">
                    <td class="px-3">
                      <div class="fw-medium">{{ c.title }}</div>
                      <small class="text-muted" *ngIf="c.is_mandatory"><i class="bi bi-asterisk text-danger me-1"></i>{{ 'report.mandatory' | translate }}</small>
                    </td>
                    <td><small class="text-muted">{{ c.category || '-' }}</small></td>
                    <td><span class="badge" [class]="getStatusBadge(c.status)">{{ formatStatus(c.status) }}</span></td>
                    <td>
                      <div class="d-flex align-items-center gap-2">
                        <div class="progress" style="height:6px; width:70px">
                          <div class="progress-bar" [class]="getProgressBarClass(c.progress_percentage)"
                               [style.width.%]="c.progress_percentage || 0"></div>
                        </div>
                        <small class="text-muted">{{ c.progress_percentage || 0 }}%</small>
                      </div>
                    </td>
                    <td>
                      <span *ngIf="c.final_score !== null && c.status === 'completed'">{{ c.final_score }}%</span>
                      <span *ngIf="c.final_score === null || c.status !== 'completed'" class="text-muted">-</span>
                    </td>
                    <td><small class="text-muted">{{ c.time_formatted || '-' }}</small></td>
                    <td>
                      <small [class]="c.is_overdue ? 'text-danger fw-semibold' : 'text-muted'">
                        {{ c.due_date || '-' }}
                        <i class="bi bi-exclamation-triangle-fill ms-1" *ngIf="c.is_overdue"></i>
                      </small>
                    </td>
                  </tr>
                  <tr *ngIf="!courses.length">
                    <td colspan="7" class="text-center py-4 text-muted">{{ 'report.no_courses_enrolled' | translate }}</td>
                  </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Assessments Tab -->
          <div *ngIf="activeTab === 'assessments'">
            <div *ngIf="loadingAssessments" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></div>
            <div class="card border-0 shadow-sm" *ngIf="!loadingAssessments">
              <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                  <thead class="table-light">
                  <tr><th class="px-3">{{ 'reports.assessment' | translate }}</th><th>{{ 'reports.course' | translate }}</th><th>{{ 'reports.score' | translate }}</th><th>{{ 'reports.result' | translate }}</th><th>{{ 'reports.attempts' | translate }}</th><th>{{ 'reports.date' | translate }}</th></tr>
                  </thead>
                  <tbody>
                  <tr *ngFor="let a of assessments">
                    <td class="px-3 fw-medium">{{ a.title || a.assessment_title }}</td>
                    <td><small class="text-muted">{{ a.course_title || '-' }}</small></td>
                    <td>
                        <span class="fw-semibold" [class]="a.score >= (a.pass_mark || 70) ? 'text-success' : 'text-danger'">
                          {{ a.score !== null ? a.score + '%' : '-' }}
                        </span>
                    </td>
                    <td>
                        <span class="badge" [class]="a.passed ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger'">
                          {{ (a.passed ? 'report.passed' : a.score !== null ? 'report.failed' : 'common.pending') | translate }}
                        </span>
                    </td>
                    <td><small class="text-muted">{{ a.attempt_number || a.attempts || '-' }}</small></td>
                    <td><small class="text-muted">{{ a.completed_at || a.submitted_at || '-' }}</small></td>
                  </tr>
                  <tr *ngIf="!assessments.length">
                    <td colspan="6" class="text-center py-4 text-muted">{{ 'reports.no_assessment_results' | translate }}</td>
                  </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <!-- Achievements Tab -->
          <div *ngIf="activeTab === 'achievements'">
            <div *ngIf="loadingAchievements" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></div>
            <div *ngIf="!loadingAchievements">
              <div class="row g-4">
                <!-- Certificates -->
                <div class="col-12">
                  <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-award me-2 text-warning"></i>{{ 'reports.certificates_count' | translate:{ count: certificates.length } }}</div>
                    <div class="list-group list-group-flush">
                      <div *ngFor="let cert of certificates" class="list-group-item d-flex justify-content-between align-items-center py-2">
                        <div>
                          <div class="small fw-medium">{{ cert.title || cert.course_title }}</div>
                          <small class="text-muted">{{ cert.issue_date || cert.completed_at }}</small>
                        </div>
                        <span class="badge bg-warning-subtle text-warning" *ngIf="cert.certificate_number">{{ cert.certificate_number }}</span>
                      </div>
                      <div *ngIf="!certificates.length" class="list-group-item text-center text-muted py-3 small">{{ 'report.no_certificates' | translate }}</div>
                    </div>
                  </div>
                </div>

                <!-- Badges -->
                <div class="col-12">
                  <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-semibold"><i class="bi bi-star me-2 text-info"></i>{{ 'reports.badges_count' | translate:{ count: badges.length } }}</div>
                    <div class="list-group list-group-flush">
                      <div *ngFor="let b of badges" class="list-group-item d-flex justify-content-between align-items-center py-2">
                        <div>
                          <div class="small fw-medium">{{ b.name || b.badge_name }}</div>
                          <small class="text-muted">{{ b.description || '' }}</small>
                        </div>
                        <small class="text-muted">{{ b.earned_at || b.achieved_at }}</small>
                      </div>
                      <div *ngIf="!badges.length" class="list-group-item text-center text-muted py-3 small">{{ 'reports.no_badges' | translate }}</div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Timeline -->
              <div class="card border-0 shadow-sm mt-4" *ngIf="timeline.length">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history me-2"></i>{{ 'reports.achievements_timeline' | translate }}</div>
                <div class="card-body p-0">
                  <div class="list-group list-group-flush" style="max-height:300px; overflow-y:auto">
                    <div *ngFor="let t of timeline" class="list-group-item py-2 d-flex align-items-center gap-3">
                      <i class="bi fs-5" [class]="getTimelineIcon(t.type)"></i>
                      <div class="flex-grow-1">
                        <div class="small fw-medium">{{ t.name }}</div>
                        <small class="text-muted">{{ formatTimelineType(t.type) }}</small>
                      </div>
                      <small class="text-muted">{{ t.achieved_at }}</small>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Compliance Tab -->
          <div *ngIf="activeTab === 'compliance'">
            <div *ngIf="loadingCompliance" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></div>
            <div class="card border-0 shadow-sm" *ngIf="!loadingCompliance">
              <div class="table-responsive">
                <table class="table table-hover table-sm mb-0">
                  <thead class="table-light">
                  <tr><th class="px-3">{{ 'reports.mandatory_course' | translate }}</th><th>{{ 'common.status' | translate }}</th><th>{{ 'reports.progress' | translate }}</th><th>{{ 'report.due_date' | translate }}</th><th>{{ 'report.completed' | translate }}</th></tr>
                  </thead>
                  <tbody>
                  <tr *ngFor="let c of complianceCourses" [class.table-danger-subtle]="c.is_overdue">
                    <td class="px-3 fw-medium">{{ c.title }}</td>
                    <td><span class="badge" [class]="getStatusBadge(c.status)">{{ formatStatus(c.status) }}</span></td>
                    <td>
                      <div class="progress" style="height:6px; width:70px">
                        <div class="progress-bar" [class]="getProgressBarClass(c.progress_percentage)"
                             [style.width.%]="c.progress_percentage || 0"></div>
                      </div>
                    </td>
                    <td>
                      <small [class]="c.is_overdue ? 'text-danger fw-semibold' : 'text-muted'">
                        {{ c.due_date || '-' }}
                      </small>
                    </td>
                    <td><small class="text-muted">{{ c.completed_at || '-' }}</small></td>
                  </tr>
                  <tr *ngIf="!complianceCourses.length">
                    <td colspan="5" class="text-center py-4 text-muted">{{ 'reports.no_mandatory' | translate }}</td>
                  </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>
  `,
  styles: [`
    .nav-link { cursor: pointer; }
    .table-danger-subtle { background-color: rgba(220, 53, 69, 0.05); }
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

  // Chart data
  progressChartData: any = null;
  statusChartData: any = null;

  constructor(private svc: ReportService, private router: Router, private i18n: I18nService) {}

  /** Extract actual data from report response.
   *  runReport() does: { data: wrapper.result.data || [], ...wrapper.result }
   *  - If PHP returned an object (summary): fields spread at top level → res.total_enrolled etc.
   *  - If PHP returned an array (course list): ...spread gives { 0:{...}, 1:{...}, data:[] }
   *    We detect this by checking for numeric keys.
   */
  private extract(res: any): any {
    if (!res) return null;
    if (Array.isArray(res)) return res;

    // Check if runReport spread an array → numeric keys present (0, 1, 2, ...)
    const keys = Object.keys(res);
    const hasNumericKeys = keys.some(k => /^\d+$/.test(k));
    if (hasNumericKeys) {
      // Reconstruct the array from numeric keys
      return keys
        .filter(k => /^\d+$/.test(k))
        .sort((a, b) => +a - +b)
        .map(k => res[k]);
    }

    // If data is a non-empty array, use it
    if (Array.isArray(res.data) && res.data.length > 0) return res.data;

    // For summary reports: useful fields are at top level (total_enrolled, completed, etc.)
    return res;
  }

  ngOnInit(): void {
    this.loading = true;

    // 1. Load summary (flat KPI object)
    // After runReport unwraps: { report_name, data:[], total_enrolled:9, completed:0, ... }
    // The summary fields are spread at the top level via ...wrapper.result
    this.svc.runReport('my_learning_summary', {}).subscribe({
      next: res => {
        // res has total_enrolled, completed, etc. at top level (spread from result)
        const d: any = res;
        this.summary = d;
        this.buildKPIs(d);
        this.buildStatusChart(d);
        this.buildTabs(d);
        this.loading = false;

        // Auto-load courses tab
        this.loadTabData('courses');
      },
      error: () => {
        this.loading = false;
        this.buildKPIs({});
        this.buildTabs({});
      }
    });

    // 2. Load performance trend chart
    this.svc.runReport('my_performance_trend', {}).subscribe({
      next: (res: any) => {
        const d = this.extract(res);
        this.buildProgressChart(Array.isArray(d) ? d : []);
      },
      error: () => {} // Not critical - chart just won't show
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

  private buildStatusChart(d: any): void {
    const completed = d.completed ?? 0;
    const inProgress = d.in_progress ?? 0;
    const notStarted = d.not_started ?? 0;
    const overdue = d.overdue ?? 0;

    if (completed + inProgress + notStarted + overdue === 0) return;

    // report-chart extractChartData() checks for d.labels + d.datasets first (extractGeneric),
    // but actually it goes through extractChartData → d.data (array) → extractGeneric.
    // Safest: provide a data array with 'label' (matches labelCandidates) and 'count' (matches valueHints)
    this.statusChartData = {
      data: [
        { label: this.i18n.t('report.completed'), count: completed },
        { label: this.i18n.t('report.in_progress'), count: inProgress },
        { label: this.i18n.t('report.not_started'), count: notStarted },
        { label: this.i18n.t('reports.overdue'), count: overdue },
      ].filter(item => item.count > 0)
    };
  }

  private buildProgressChart(d: any): void {
    // d is array of {month_key, label, avg_score, completions} from myPerformanceTrend
    if (!d || !Array.isArray(d) || d.length === 0) return;

    // Ensure numeric values (MySQL may return strings) and use field names
    // that match report-chart's extractGeneric: 'label' (labelCandidates) + 'score'/'completions' (valueHints)
    this.progressChartData = {
      data: d.map((r: any) => ({
        label: r.label || r.month || r.period || '',
        score: Number(r.avg_score ?? r.score ?? 0),
        completions: Number(r.completions ?? r.courses_completed ?? r.completed ?? 0),
      }))
    };
  }

  // ═══════════════════════════════════════════
  //  TABS
  // ═══════════════════════════════════════════

  private buildTabs(d: any): void {
    this.tabs = [
      { key: 'courses', label: this.i18n.t('reports.tab_my_courses'), icon: 'bi-book', badge: 'bg-primary', count: d.total_enrolled ?? null },
      { key: 'assessments', label: this.i18n.t('reports.tab_assessments'), icon: 'bi-pencil-square', badge: 'bg-info', count: null },
      { key: 'achievements', label: this.i18n.t('reports.tab_achievements'), icon: 'bi-trophy', badge: 'bg-warning', count: (d.certificates ?? 0) + (d.badges ?? 0) || null },
      { key: 'compliance', label: this.i18n.t('reports.tab_compliance'), icon: 'bi-shield-check', badge: 'bg-danger', count: d.overdue ?? null },
    ];
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
        this.svc.runReport('my_enrolled_courses', {}).subscribe({
          next: (res: any) => { this.courses = this.extract(res) ?? []; this.loadingCourses = false; },
          error: () => { this.loadingCourses = false; }
        });
        break;

      case 'assessments':
        this.loadingAssessments = true;
        this.svc.runReport('my_assessment_results', {}).subscribe({
          next: (res: any) => { this.assessments = this.extract(res) ?? []; this.loadingAssessments = false; },
          error: () => { this.loadingAssessments = false; }
        });
        break;

      case 'achievements':
        this.loadingAchievements = true;
        let loaded = 0;
        const checkDone = () => { loaded++; if (loaded >= 3) this.loadingAchievements = false; };

        this.svc.runReport('my_completed_courses', {}).subscribe({
          next: (res: any) => { this.certificates = (this.extract(res) ?? []).filter((c: any) => c.certificate_number); checkDone(); },
          error: checkDone
        });
        this.svc.runReport('my_badges', {}).subscribe({
          next: (res: any) => { this.badges = this.extract(res) ?? []; checkDone(); },
          error: checkDone
        });
        this.svc.runReport('my_achievements_timeline', {}).subscribe({
          next: (res: any) => { this.timeline = this.extract(res) ?? []; checkDone(); },
          error: checkDone
        });
        break;

      case 'compliance':
        this.loadingCompliance = true;
        this.svc.runReport('my_mandatory_training', {}).subscribe({
          next: (res: any) => { this.complianceCourses = this.extract(res) ?? []; this.loadingCompliance = false; },
          error: () => { this.loadingCompliance = false; }
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
