// reports/components/report-viewer/report-viewer.component.ts

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule, DecimalPipe, Location } from '@angular/common';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { Subject, takeUntil, debounceTime, distinctUntilChanged } from 'rxjs';
import { ReportService } from '../../services/report.service';
import { ReportResponse, ReportCatalogItem, ReportFilters } from '../../models/report.models';
import { ReportChartComponent } from '../report-chart/report-chart.component';
import { ReportTableComponent, type TableColumn } from '../report-table/report-table.component';
import { ReportFiltersComponent } from '../report-filters/report-filters.component';
import { ReportExportComponent } from '../report-export/report-export.component';
import {AdmintopComponent} from '../../../partials/top/admintop/admintop.component';
import {TranslatePipe} from '../../../translate.pipe';
import {I18nService} from '../../../i18n.service';
import { HotToastService } from '@ngneat/hot-toast';
import { ConfirmDialogService } from '../../../shared/components/confirmation-dialog/confirmation-dialog.component';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import { SelectComponent } from '../../../shared/select/select.component';

// ── Drilldown column definitions ──
// Maps column names → the status filter to send to drilldown_learner_list
const DRILLDOWN_COLUMNS: Record<string, string> = {
  // Enrollment counts
  total_enrolled:      'enrolled',
  total_enrollments:   'enrolled',
  current_enrolled:    'enrolled',
  self_enrollments:    'enrolled',
  enrollments:         'enrolled',
  unique_learners:     'enrolled',
  unique_users:        'enrolled',
  total_users:         'all',
  users_count:         'all',
  total_assigned:      'enrolled',

  // Status-specific counts
  completed:           'completed',
  completions:         'completed',
  total_completed:     'completed',
  courses_completed:   'completed',
  mandatory_completed: 'completed',
  in_progress:         'in_progress',
  total_in_progress:   'in_progress',
  not_started:         'not_started',
  total_not_started:   'not_started',

  // Compliance counts
  overdue:              'overdue',
  overdue_count:        'overdue',
  non_compliant_count:  'non_compliant',
  pending:              'not_started',

  // Assessment counts
  passed_count:        'passed',
  failed_count:        'failed',
  total_attempts:      'enrolled',

  // Activity counts
  active_learners:     'active',
  active_users:        'active',

  // Misc
  waitlist_count:       'enrolled',
  early_dropoffs:       'in_progress',
  users_with_badges:    'all',
  users_with_points:    'all',
  unique_recipients:    'completed',
};

// Which row fields map to which org filter parameters
const ORG_FILTER_MAP: { rowField: string; param: string }[] = [
  { rowField: 'department_id',   param: 'department_id' },
  { rowField: 'branch_id',      param: 'branch_id' },
  { rowField: 'team_id',        param: 'team_id' },
  { rowField: 'course_id',      param: 'course_id' },
  { rowField: 'assessment_id',  param: 'assessment_id' },
  { rowField: 'path_id',        param: 'path_id' },
  { rowField: 'manager_id',     param: 'manager_id' },
  { rowField: 'instructor_id',  param: 'instructor_id' },
];

@Component({
  selector: 'app-report-viewer',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, ReportChartComponent, ReportTableComponent, ReportFiltersComponent, ReportExportComponent, AdmintopComponent, TranslatePipe, SkeletonComponent, SelectComponent],
  providers: [DecimalPipe],
  template: `
    <app-admintop></app-admintop>
    <main class="main">
      <div class="ui-page report-viewer">
        <!-- Breadcrumb -->
        <nav class="rv-crumbs">
          <ol class="breadcrumb small mb-0">
            <li class="breadcrumb-item"><a routerLink="/admin/reports">{{ 'nav.reports' | translate }}</a></li>
            <li class="breadcrumb-item"><a routerLink="/admin/reports/catalog"
                                           [queryParams]="{category: reportItem?.category}">
              {{ formatKey(reportItem?.category || '') }}
            </a></li>
            <li class="breadcrumb-item active">{{ report?.report_name || reportItem?.name }}</li>
          </ol>
        </nav>

        <!-- Header -->
        <header class="ui-page-header">
          <button type="button" class="ui-page-header__back" (click)="goBack()" [attr.aria-label]="'common.back' | translate"><i class="bi bi-chevron-left"></i></button>
          <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-file-earmark-bar-graph"></i></div>
          <div class="ui-page-header__titles">
            <h1>{{ report?.report_name || reportItem?.name || ('common.loading' | translate) }}</h1>
            <p>{{ report?.description || reportItem?.description }}</p>
          </div>
          <div class="ui-page-header__actions">
            <button type="button" class="btn btn-outline-secondary" (click)="refreshReport()" [title]="'common.refresh' | translate" [attr.aria-label]="'common.refresh' | translate">
              <i class="bi bi-arrow-clockwise"></i>
            </button>
            <app-report-export [reportKey]="reportKey" [filters]="currentFilters" btnClass="btn-outline-secondary"
                               *ngIf="reportItem?.exportable"></app-report-export>
            <button type="button" class="btn btn-primary" (click)="saveReport()" [title]="'common.save' | translate">
              <i class="bi bi-bookmark me-1"></i>{{ 'common.save' | translate }}
            </button>
          </div>
        </header>

        <!-- ═══════ PARAMETER PICKER ═══════ -->
        <section class="ui-section" *ngIf="paramPickerType">
          <div class="row g-3 align-items-end">
            <ng-container *ngIf="paramPickerType === 'learner'">
              <div class="col-md-5">
                <label class="form-label small fw-semibold">{{ 'reports.search_learner' | translate }}</label>
                <input type="text" class="form-control" placeholder="{{ 'reports.type_name_email' | translate }}"
                       [(ngModel)]="pickerSearch" (input)="onPickerSearch()">
              </div>
              <div class="col-md-5" *ngIf="pickerOptions.length">
                <label class="form-label small fw-semibold">{{ 'reports.select_learner' | translate }}</label>
                <app-select [options]="pickerOpts" [clearable]="true"
                            placeholder="{{ 'reports.choose_learner' | translate }}" [ariaLabel]="'reports.select_learner' | translate"
                            [(ngModel)]="pickerSelectedId" (ngModelChange)="onPickerSelect()" />
              </div>
            </ng-container>
            <ng-container *ngIf="paramPickerType === 'assessment'">
              <div class="col-md-8">
                <label class="form-label small fw-semibold">{{ 'reports.select_assessment' | translate }}</label>
                <app-select [options]="pickerOpts" [clearable]="true"
                            placeholder="{{ 'reports.choose_assessment' | translate }}" [ariaLabel]="'reports.select_assessment' | translate"
                            [(ngModel)]="pickerSelectedId" (ngModelChange)="onPickerSelect()" />
              </div>
            </ng-container>
            <ng-container *ngIf="paramPickerType === 'course'">
              <div class="col-md-8">
                <label class="form-label small fw-semibold">{{ 'reports.select_course' | translate }}</label>
                <app-select [options]="pickerOpts" [clearable]="true"
                            placeholder="{{ 'reports.choose_course' | translate }}" [ariaLabel]="'reports.select_course' | translate"
                            [(ngModel)]="pickerSelectedId" (ngModelChange)="onPickerSelect()" />
              </div>
            </ng-container>
            <div class="col-md-2">
              <button type="button" class="btn btn-primary w-100" (click)="loadReport()" [disabled]="!pickerSelectedId">
                <i class="bi bi-play-fill me-1"></i>{{ 'reports.run' | translate }}
              </button>
            </div>
          </div>
        </section>

        <!-- Standard Filters -->
        <app-report-filters *ngIf="!paramPickerType" [reportItem]="reportItem" [initialFilters]="currentFilters"
                            (filtersChanged)="onFiltersChanged($event)"></app-report-filters>

        <!-- Loading -->
        <ng-container *ngIf="loading">
          <app-skeleton variant="kpis" [count]="4"></app-skeleton>
          <app-skeleton variant="table" [rows]="8" [cols]="5"></app-skeleton>
        </ng-container>

        <!-- Error -->
        <div *ngIf="error" class="ui-state">
          <span class="ui-state__icon rv-danger"><i class="bi bi-exclamation-triangle"></i></span>
          <p class="mt-3">{{ error }}</p>
          <button type="button" class="btn btn-sm btn-outline-danger" (click)="refreshReport()">{{ 'common.retry' | translate }}</button>
        </div>

        <!-- ═══════ REPORT CONTENT ═══════ -->
        <div *ngIf="report && !loading && !error">

          <!-- Summary Cards -->
          <div class="ui-kpi-grid" *ngIf="summaryCards.length">
            <div *ngFor="let card of summaryCards" class="ui-kpi" [ngClass]="'ui-kpi--' + (card.color === 'primary' ? 'brand' : card.color)">
              <span class="ui-kpi__label">{{ card.label }}</span>
              <span class="ui-kpi__value">{{ card.display }}</span>
            </div>
          </div>

          <!-- User Profile -->
          <section class="ui-section" *ngIf="report.user">
            <div class="ui-section__head">
              <div><h2 class="ui-section__title"><i class="bi bi-person-circle me-2"></i>{{ 'reports.learner_profile' | translate }}</h2></div>
            </div>
            <div class="row g-3">
              <div class="col-md-3"><span class="ui-muted small d-block">{{ 'common.name' | translate }}</span><strong>{{ report.user.full_name }}</strong></div>
              <div class="col-md-3"><span class="ui-muted small d-block">{{ 'reports.email' | translate }}</span><strong>{{ report.user.email }}</strong></div>
              <div class="col-md-3"><span class="ui-muted small d-block">{{ 'reports.department' | translate }}</span><strong>{{ report.user.department }}</strong></div>
              <div class="col-md-3"><span class="ui-muted small d-block">{{ 'reports.job_title' | translate }}</span><strong>{{ report.user.job_title }}</strong></div>
            </div>
          </section>

          <!-- Chart -->
          <app-report-chart *ngIf="chartData" [data]="chartData" [chartType]="report.chart_type || 'bar'"
                            [reportKey]="reportKey" [allowTypeChange]="true"></app-report-chart>

          <!-- Multi-section tables -->
          <ng-container *ngIf="sections.length">
            <app-report-table *ngFor="let section of sections" [data]="section.data" [title]="section.title"
                              [searchable]="true" [sortable]="true"
                              [drilldownColumns]="section.drilldownCols"
                              (cellClick)="onDrilldownClick($event, section)"></app-report-table>
          </ng-container>

          <!-- Single data table -->
          <app-report-table *ngIf="!sections.length && tableData?.length"
                            [data]="tableData" [meta]="report.meta"
                            [customColumns]="tableColumns"
                            [title]="''" [searchable]="true" [sortable]="true"
                            [drilldownColumns]="tableDrilldownCols"
                            (cellClick)="onDrilldownClick($event)"
                            (pageChange)="onPageChange($event)"></app-report-table>

          <!-- ═══════ DRILLDOWN PANEL ═══════ -->
          <section *ngIf="drilldown.visible" class="ui-section drilldown-panel" id="drilldownPanel">
            <div class="ui-section__head">
              <div>
                <h2 class="ui-section__title">
                  <i class="bi bi-funnel me-1"></i>{{ drilldown.title }}
                </h2>
                <p class="ui-section__subtitle">{{ drilldown.subtitle }}</p>
              </div>
              <div class="ui-section__actions">
                <app-report-export *ngIf="drilldown.data.length"
                                   [data]="drilldown.data" [title]="drilldown.title"
                                   btnClass="btn-outline-secondary"></app-report-export>
                <button type="button" class="btn btn-sm btn-outline-secondary" (click)="closeDrilldown()" [attr.aria-label]="'common.close' | translate">
                  <i class="bi bi-x-lg"></i>
                </button>
              </div>
            </div>
            <app-skeleton *ngIf="drilldown.loading" variant="table" [rows]="5" [cols]="4"></app-skeleton>
            <div *ngIf="drilldown.data.length">
              <app-report-table [data]="drilldown.data" [title]="''"
                                [searchable]="true" [sortable]="true" [pageSize]="10"></app-report-table>
            </div>
            <div class="ui-state" *ngIf="!drilldown.data.length && !drilldown.loading">
              <span class="ui-state__icon"><i class="bi bi-inbox"></i></span>
              <p>{{ 'reports.no_learners_found' | translate }}</p>
            </div>
          </section>

          <!-- Badges -->
          <section class="ui-section" *ngIf="report.badges?.length">
            <div class="ui-section__head">
              <div><h2 class="ui-section__title"><i class="bi bi-award me-2"></i>{{ 'reports.badges_count' | translate:{ count: report.badges.length } }}</h2></div>
            </div>
            <div class="ui-insights">
              <span *ngFor="let b of report.badges" class="ui-pill ui-pill--warning">
                <i class="bi bi-award"></i>{{ b.badge_name }}
              </span>
            </div>
          </section>

          <!-- Certificates -->
          <section class="ui-section" *ngIf="report.certificates?.length">
            <div class="ui-section__head">
              <div><h2 class="ui-section__title"><i class="bi bi-patch-check me-2"></i>{{ 'report.tab_certificates' | translate }}</h2></div>
            </div>
            <app-report-table [data]="report.certificates" [title]="''" [sortable]="true"></app-report-table>
          </section>

          <!-- Risk Breakdown -->
          <section class="ui-section" *ngIf="report.risk_breakdown">
            <div class="ui-section__head">
              <div><h2 class="ui-section__title"><i class="bi bi-shield-exclamation me-2"></i>{{ 'reports.risk_breakdown' | translate }}</h2></div>
            </div>
            <div class="ui-insights">
              <span class="ui-pill ui-pill--danger"><span class="ui-pill__dot"></span>{{ 'reports.risk_critical' | translate }} {{ report.risk_breakdown.critical }}</span>
              <span class="ui-pill ui-pill--warning"><span class="ui-pill__dot"></span>{{ 'reports.risk_high' | translate }} {{ report.risk_breakdown.high }}</span>
              <span class="ui-pill ui-pill--info"><span class="ui-pill__dot"></span>{{ 'reports.risk_medium' | translate }} {{ report.risk_breakdown.medium }}</span>
              <span class="ui-pill ui-pill--neutral"><span class="ui-pill__dot"></span>{{ 'reports.risk_low' | translate }} {{ report.risk_breakdown.low }}</span>
            </div>
          </section>

          <!-- Empty state -->
          <div *ngIf="!sections.length && !tableData?.length && !report.user && !summaryCards.length" class="ui-state">
            <span class="ui-state__icon"><i class="bi bi-inbox"></i></span>
            <p>{{ 'reports.no_data_filters' | translate }}</p>
          </div>
        </div>
      </div>
    </main>
  `,
  styles: [`
    .rv-crumbs { margin-bottom: 10px; }
    .rv-crumbs a { color: var(--ui-text-muted); text-decoration: none; }
    .rv-crumbs a:hover { color: var(--brand-primary); }
    .ui-kpi-grid { margin-bottom: 20px; }
    .drilldown-panel { border-left: 3px solid var(--brand-primary); scroll-margin-top: 80px; }
    .rv-danger { color: var(--ui-danger, #c0352b); background: rgba(var(--ui-danger-rgb), .08); border-color: rgba(var(--ui-danger-rgb), .2); }
    app-report-table { display: block; margin-bottom: 20px; }
    .ui-section app-report-table { margin-bottom: 0; }
  `]
})
export class ReportViewerComponent implements OnInit, OnDestroy {
  private readonly backLocation = inject(Location);
  reportKey = '';
  reportItem?: ReportCatalogItem;
  report: any = null;
  currentFilters: ReportFilters = {};
  summaryCards: SummaryCard[] = [];
  loading = false;
  error = '';
  private destroy$ = new Subject<void>();

  chartData: any = null;
  tableData: any[] = [];
  tableDrilldownCols: string[] = [];

  /**
   * The columns the report itself declares.
   *
   * The table guesses headers and types from the first row when nothing
   * tells it better, and its guesses came out as ENROLLEDAT, DUEDATE and
   * SECONDS with a timestamp printed raw. The report has always known
   * they are "Enrolled", "Due" and "Time spent" and what each one is;
   * nothing was passing that on.
   */
  tableColumns: TableColumn[] | undefined;
  sections: ReportSection[] = [];

  drilldown = { visible: false, loading: false, title: '', subtitle: '', data: [] as any[] };

  paramPickerType: 'learner' | 'assessment' | 'course' | null = null;
  pickerSearch = '';
  pickerOptions: any[] = [];
  pickerSelectedId: number | null = null;
  private pickerOptsCache: { src: any[]; type: string | null; list: Array<{ value: any; label: string }> } | null = null;

  /** pickerOptions as select options for the current picker type, cached per source array. */
  get pickerOpts(): Array<{ value: any; label: string }> {
    const c = this.pickerOptsCache;
    if (c && c.src === this.pickerOptions && c.type === this.paramPickerType) return c.list;
    const type = this.paramPickerType;
    const list = (this.pickerOptions ?? []).map((o: any) => {
      if (type === 'learner') {
        return { value: o.id || o.user_id, label: `${o.first_name || ''} ${o.last_name || ''} (${o.email})` };
      }
      if (type === 'assessment') return { value: o.id || o.assessment_id, label: String(o.title || o.name || '') };
      return { value: o.id || o.course_id, label: String(o.title ?? '') };
    });
    this.pickerOptsCache = { src: this.pickerOptions, type, list };
    return list;
  }
  private searchSubject = new Subject<string>();

  private individualReports = ['individual_progress_report', 'individual_assessment_report', 'learner_transcript'];
  private assessmentReports = ['question_analysis_report', 'assessment_item_analysis'];
  private courseReports = ['course_learner_roster'];

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private reportService: ReportService,
    private dp: DecimalPipe,
    private i18n: I18nService,
    private toast: HotToastService,
    private dialog: ConfirmDialogService
  ) {}

  /** Header back button: the previous page, or /admin/reports when there is no history (fresh tab / deep link). */
  goBack(): void {
    if (window.history.length > 1) {
      this.backLocation.back();
      return;
    }
    void this.router.navigate(['/admin/reports']);
  }

  ngOnInit(): void {
    this.route.params.pipe(takeUntil(this.destroy$)).subscribe(params => {
      this.reportKey = params['key'] || '';
      this.detectPickerType();
      this.loadCatalogItem();
      if (!this.paramPickerType) this.loadReport();
    });

    this.searchSubject.pipe(
      debounceTime(300), distinctUntilChanged(), takeUntil(this.destroy$)
    ).subscribe(term => {
      if (term.length >= 2) this.reportService.getLearners(term).subscribe(u => this.pickerOptions = u);
    });
  }

  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }

  private detectPickerType(): void {
    if (this.individualReports.includes(this.reportKey)) {
      this.paramPickerType = 'learner';
    } else if (this.assessmentReports.includes(this.reportKey)) {
      this.paramPickerType = 'assessment';
      this.reportService.getAssessments().subscribe(a => this.pickerOptions = a);
    } else if (this.courseReports.includes(this.reportKey)) {
      this.paramPickerType = 'course';
      this.reportService.getCourses().subscribe(c => this.pickerOptions = c);
    } else {
      this.paramPickerType = null;
    }
  }

  onPickerSearch(): void { this.searchSubject.next(this.pickerSearch); }

  onPickerSelect(): void {
    if (!this.pickerSelectedId) return;
    const map: Record<string, string> = { learner: 'learner_id', assessment: 'assessment_id', course: 'course_id' };
    this.currentFilters = { ...this.currentFilters, [map[this.paramPickerType!]]: this.pickerSelectedId };
  }

  onFiltersChanged(f: ReportFilters): void { this.currentFilters = f; this.loadReport(); }
  onPageChange(p: number): void { this.currentFilters = { ...this.currentFilters, page: p }; this.loadReport(); }
  refreshReport(): void { this.loadReport(); }

  async saveReport(): Promise<void> {
    const name = await this.dialog.prompt(this.i18n.t('reports.save_prompt'), { inputValue: this.report?.report_name || this.reportKey });
    if (!name) return;
    this.reportService.saveReport(name, this.reportKey, this.currentFilters).subscribe({
      next: () => this.toast.success(this.i18n.t('reports.save_success')),
      error: () => this.toast.error(this.i18n.t('reports.save_failed'))
    });
  }

  private loadCatalogItem(): void {
    this.reportService.getCatalog().subscribe(items => {
      this.reportItem = items.find(i => i.key === this.reportKey);
    });
  }

  loadReport(): void {
    if (!this.reportKey) return;
    if (this.paramPickerType && !this.pickerSelectedId) return;
    this.loading = true; this.error = ''; this.sections = [];
    this.chartData = null; this.tableData = []; this.tableDrilldownCols = [];
    this.tableColumns = undefined;
    this.report = null; this.closeDrilldown();

    this.reportService.runReport(this.reportKey, this.currentFilters).pipe(
      takeUntil(this.destroy$)
    ).subscribe({
      next: (res: any) => { this.report = res; this.processReport(res); this.loading = false; },
      error: (err) => { this.error = err?.error?.message || err?.message || this.i18n.t('reports.load_failed'); this.loading = false; }
    });
  }

  /* ═══════ REPORT STRUCTURE DETECTION ═══════ */

  private processReport(res: any): void {
    this.summaryCards = this.extractSummary(res.summary || res.org_summary);

    // CASE 1: Standard .data array
    if (res.data && Array.isArray(res.data) && res.data.length) {
      this.tableData = res.data;
      this.chartData = res;
      this.tableColumns = toTableColumns(res.columns);
      this.tableDrilldownCols = this.detectDrilldownCols(res.data[0]);
      return;
    }

    // CASE 2: Individual Progress (courses array)
    if (res.courses && Array.isArray(res.courses)) {
      this.tableData = res.courses;
      if (res.courses.length) this.chartData = { data: res.courses };
      return;
    }

    // CASE 3: Multi-section
    const sectionMap = [
      { key: 'by_branch', title: 'Compliance by Branch' },
      { key: 'by_department', title: 'Compliance by Department' },
      { key: 'by_team', title: 'Compliance by Team' },
      { key: 'by_course', title: 'Compliance by Course' },
      { key: 'non_compliant_learners', title: 'Non-Compliant Learners' },
      { key: 'trends', title: 'Trends' },
      { key: 'monthly_trend', title: 'Monthly Trend' },
      { key: 'by_hour', title: 'By Hour' },
      { key: 'by_day', title: 'By Day' },
      { key: 'by_activity', title: 'By Activity' },
    ];

    for (const s of sectionMap) {
      if (res[s.key] && Array.isArray(res[s.key]) && res[s.key].length) {
        this.sections.push({
          key: s.key, title: s.title, data: res[s.key],
          drilldownCols: this.detectDrilldownCols(res[s.key][0])
        });
      }
    }

    if (this.sections.length) { this.chartData = res; return; }

    // CASE 4: Find any array
    for (const [k, v] of Object.entries(res)) {
      if (Array.isArray(v) && (v as any[]).length && typeof (v as any[])[0] === 'object'
        && !['badges', 'certificates', 'applied_filters'].includes(k)) {
        this.tableData = v as any[];
        this.chartData = { data: this.tableData };
        this.tableDrilldownCols = this.detectDrilldownCols(this.tableData[0]);
        break;
      }
    }
  }

  /** Auto-detect which columns in a data sample are drillable user counts */
  private detectDrilldownCols(sample: any): string[] {
    if (!sample) return [];
    const cols: string[] = [];
    for (const key of Object.keys(sample)) {
      if (DRILLDOWN_COLUMNS[key] && typeof sample[key] === 'number') {
        cols.push(key);
      }
    }
    return cols;
  }

  /* ═══════ UNIVERSAL DRILLDOWN HANDLER ═══════ */

  onDrilldownClick(event: { row: any; column: any; value: any }, section?: ReportSection): void {
    const colKey = event.column.key;
    const row = event.row;
    const status = DRILLDOWN_COLUMNS[colKey] || 'all';

    // 1. Try client-side filter first (if non_compliant_learners exists in the report)
    if (this.report?.non_compliant_learners?.length && this.tryClientDrilldown(row, colKey, status, section)) {
      return;
    }

    // 2. Server-side drilldown via drilldown_learner_list
    const filters: Record<string, any> = {};

    // Extract org dimension from the clicked row
    for (const mapping of ORG_FILTER_MAP) {
      if (row[mapping.rowField]) {
        filters[mapping.param] = row[mapping.rowField];
      }
    }

    // Build descriptive title
    const entityName = row.department_name || row.branch_name || row.team_name
      || row.course_title || row.title || row.name || '';
    const statusLabel = this.formatKey(status);

    this.drilldown = {
      visible: true, loading: true,
      title: this.i18n.t('reports.status_learners', { status: statusLabel }) + (entityName ? ` - ${entityName}` : ''),
      subtitle: this.i18n.t('common.loading'), data: []
    };

    this.reportService.drilldown(status, filters).pipe(
      takeUntil(this.destroy$)
    ).subscribe({
      next: (res: any) => {
        const data = res.data || [];
        this.drilldown.data = data;
        this.drilldown.loading = false;
        this.drilldown.subtitle = this.i18n.t('reports.learners_found', { count: data.length });
        setTimeout(() => document.getElementById('drilldownPanel')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);
      },
      error: () => {
        this.drilldown.loading = false;
        this.drilldown.subtitle = this.i18n.t('reports.drilldown_failed');
      }
    });
  }

  /** Try client-side drilldown from non_compliant_learners in executive summary */
  private tryClientDrilldown(row: any, colKey: string, status: string, section?: ReportSection): boolean {
    const ncl: any[] = this.report.non_compliant_learners;
    if (!ncl?.length) return false;

    // Determine which field to filter on based on section
    let filterField = '';
    let matchValue = '';

    if (row.department_name) { filterField = 'department'; matchValue = row.department_name; }
    else if (row.branch_name) { filterField = 'branch'; matchValue = row.branch_name; }
    else if (row.team_name) { filterField = 'team'; matchValue = row.team_name; }
    else if (row.course_title) { filterField = 'overdue_courses'; matchValue = row.course_title; }
    else { return false; }

    let filtered: any[];
    if (filterField === 'overdue_courses') {
      // Course: match learners whose overdue_courses string contains the course title
      filtered = ncl.filter(l => l.overdue_courses?.includes(matchValue));
    } else {
      filtered = ncl.filter(l => l[filterField] === matchValue);
    }

    // Further filter by status intent
    if (colKey === 'overdue' || colKey === 'overdue_count') {
      filtered = filtered.filter(l => l.overdue_count > 0);
    }

    const statusLabel = this.formatKey(DRILLDOWN_COLUMNS[colKey] || colKey);

    this.drilldown = {
      visible: true, loading: false,
      title: this.i18n.t('reports.status_learners', { status: statusLabel }) + ` - ${matchValue}`,
      subtitle: this.i18n.t('reports.learners_found', { count: filtered.length }),
      data: filtered
    };

    setTimeout(() => document.getElementById('drilldownPanel')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);
    return true;
  }

  closeDrilldown(): void {
    this.drilldown = { visible: false, loading: false, title: '', subtitle: '', data: [] };
  }

  /* ═══════ SUMMARY CARDS ═══════ */

  private extractSummary(summary?: Record<string, any>): SummaryCard[] {
    if (!summary) return [];
    const cards: SummaryCard[] = [];
    const colorMap: Record<string, string> = {
      total: 'primary', rate: 'success', overdue: 'danger', pending: 'warning',
      active: 'info', avg: 'primary', score: 'success', compliance: 'success',
      completed: 'success', not_started: 'secondary', in_progress: 'warning',
      certificates: 'info', badges: 'warning', points: 'primary', time: 'info',
      mandatory: 'danger', non_compliant: 'danger'
    };
    for (const [key, value] of Object.entries(summary)) {
      if (value == null || typeof value === 'object' || key === 'data_source' || key === 'breakdown') continue;
      const label = this.formatKey(key);
      let color = 'primary';
      for (const [hint, c] of Object.entries(colorMap)) { if (key.toLowerCase().includes(hint)) { color = c; break; } }
      const display = typeof value === 'number'
        ? (key.includes('rate') ? this.dp.transform(value, '1.0-1')! + '%' :
          (value % 1 !== 0 ? this.dp.transform(value, '1.0-1')! : this.dp.transform(value, '1.0-0')!))
        : String(value);
      cards.push({ label, display, color });
    }
    return cards.slice(0, 10);
  }

  formatKey(k: string): string {
    return k.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }
}

interface SummaryCard { label: string; display: string; color: string; }
interface ReportSection { key: string; title: string; data: any[]; drilldownCols: string[]; }

/**
 * The report's own columns, in the shape the table draws from.
 *
 * The two vocabularies for a column's type are close but not the same:
 * the API says what a value *is* — a duration, a percentage, a status —
 * and the table says how to *draw* it. Where there is no drawing for a
 * kind, the column falls through to plain text, which is right: an
 * unrecognised type should print the value, not hide it.
 */
function toTableColumns(columns: unknown): TableColumn[] | undefined {
  if (!Array.isArray(columns) || columns.length === 0) {
    return undefined;
  }

  const drawnAs: Record<string, TableColumn['type']> = {
    number: 'number',
    percent: 'progress',
    date: 'date',
    datetime: 'date',
    status: 'badge',
    duration: 'duration',
  };

  return columns.map((column: { key: string; label: string; type?: string }) => ({
    key: column.key,
    label: column.label,
    type: drawnAs[column.type ?? ''] ?? 'text',
  }));
}
