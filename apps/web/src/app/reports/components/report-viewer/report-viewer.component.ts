// reports/components/report-viewer/report-viewer.component.ts

import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
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
  imports: [CommonModule, FormsModule, RouterLink, ReportChartComponent, ReportTableComponent, ReportFiltersComponent, ReportExportComponent, AdmintopComponent],
  providers: [DecimalPipe],
  template: `
    <app-admintop></app-admintop>
    <main class="main px-lg-6">
      <div class="container-fluid">
    <div class="report-viewer">
      <!-- Breadcrumb -->
      <nav class="mb-3">
        <ol class="breadcrumb small mb-0">
          <li class="breadcrumb-item"><a routerLink="/admin/reports">Reports</a></li>
          <li class="breadcrumb-item"><a routerLink="/admin/reports/catalog"
                                         [queryParams]="{category: reportItem?.category}">
            {{ formatKey(reportItem?.category || '') }}
          </a></li>
          <li class="breadcrumb-item active">{{ report?.report_name || reportItem?.name }}</li>
        </ol>
      </nav>

      <!-- Header -->
      <div class="d-flex justify-content-between align-items-start mb-4">
        <div>
          <h4 class="fw-bold mb-1">{{ report?.report_name || reportItem?.name || 'Loading...' }}</h4>
          <p class="text-muted mb-0 small">{{ report?.description || reportItem?.description }}</p>
        </div>
        <div class="d-flex gap-2">
          <app-report-export [reportKey]="reportKey" [filters]="currentFilters"
                             *ngIf="reportItem?.exportable"></app-report-export>
          <button class="btn btn-sm btn-primary" (click)="saveReport()" title="Save">
            <i class="bi bi-bookmark me-1"></i>Save
          </button>
          <button class="btn btn-sm btn-secondary" (click)="refreshReport()" title="Refresh">
            <i class="bi bi-arrow-clockwise"></i>
          </button>
        </div>
      </div>

      <!-- ═══════ PARAMETER PICKER ═══════ -->
      <div class="card border-0 shadow-sm mb-4" *ngIf="paramPickerType">
        <div class="card-body">
          <div class="row g-3 align-items-end">
            <ng-container *ngIf="paramPickerType === 'learner'">
              <div class="col-md-5">
                <label class="form-label small fw-semibold">Search Learner</label>
                <input type="text" class="form-control form-control-sm" placeholder="Type name or email..."
                       [(ngModel)]="pickerSearch" (input)="onPickerSearch()">
              </div>
              <div class="col-md-5" *ngIf="pickerOptions.length">
                <label class="form-label small fw-semibold">Select Learner</label>
                <select class="form-select form-select-sm" [(ngModel)]="pickerSelectedId" (change)="onPickerSelect()">
                  <option [ngValue]="null">Choose learner...</option>
                  <option *ngFor="let u of pickerOptions" [ngValue]="u.id || u.user_id">
                    {{ u.first_name || '' }} {{ u.last_name || '' }} ({{ u.email }})
                  </option>
                </select>
              </div>
            </ng-container>
            <ng-container *ngIf="paramPickerType === 'assessment'">
              <div class="col-md-8">
                <label class="form-label small fw-semibold">Select Assessment</label>
                <select class="form-select form-select-sm" [(ngModel)]="pickerSelectedId" (change)="onPickerSelect()">
                  <option [ngValue]="null">Choose assessment...</option>
                  <option *ngFor="let a of pickerOptions" [ngValue]="a.id || a.assessment_id">{{ a.title || a.name }}</option>
                </select>
              </div>
            </ng-container>
            <ng-container *ngIf="paramPickerType === 'course'">
              <div class="col-md-8">
                <label class="form-label small fw-semibold">Select Course</label>
                <select class="form-select form-select-sm" [(ngModel)]="pickerSelectedId" (change)="onPickerSelect()">
                  <option [ngValue]="null">Choose course...</option>
                  <option *ngFor="let c of pickerOptions" [ngValue]="c.id || c.course_id">{{ c.title }}</option>
                </select>
              </div>
            </ng-container>
            <div class="col-md-2">
              <button class="btn btn-sm btn-primary w-100" (click)="loadReport()" [disabled]="!pickerSelectedId">
                <i class="bi bi-play-fill me-1"></i>Run
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Standard Filters -->
      <app-report-filters *ngIf="!paramPickerType" [reportItem]="reportItem" [initialFilters]="currentFilters"
                          (filtersChanged)="onFiltersChanged($event)"></app-report-filters>

      <!-- Loading -->
      <div *ngIf="loading" class="text-center py-5">
        <div class="spinner-border text-primary" role="status"></div>
        <p class="mt-2 text-muted">Generating report...</p>
      </div>

      <!-- Error -->
      <div *ngIf="error" class="alert alert-danger">
        <i class="bi bi-exclamation-triangle me-2"></i>{{ error }}
        <button class="btn btn-sm btn-outline-danger ms-2" (click)="refreshReport()">Retry</button>
      </div>

      <!-- ═══════ REPORT CONTENT ═══════ -->
      <div *ngIf="report && !loading && !error">

        <!-- Summary Cards -->
        <div class="row g-3 mb-4" *ngIf="summaryCards.length">
          <div *ngFor="let card of summaryCards" class="col-sm-6 col-md-4 col-lg-3">
            <div class="card border-0 shadow-sm h-100">
              <div class="card-body text-center py-3">
                <p class="text-muted small mb-1">{{ card.label }}</p>
                <h4 class="fw-bold mb-0" [class]="'text-' + card.color">{{ card.display }}</h4>
              </div>
            </div>
          </div>
        </div>

        <!-- User Profile -->
        <div class="card border-0 shadow-sm mb-4" *ngIf="report.user">
          <div class="card-body">
            <h6 class="fw-semibold mb-3"><i class="bi bi-person-circle me-2"></i>Learner Profile</h6>
            <div class="row g-3">
              <div class="col-md-3"><span class="text-muted small d-block">Name</span><strong>{{ report.user.full_name }}</strong></div>
              <div class="col-md-3"><span class="text-muted small d-block">Email</span><strong>{{ report.user.email }}</strong></div>
              <div class="col-md-3"><span class="text-muted small d-block">Department</span><strong>{{ report.user.department }}</strong></div>
              <div class="col-md-3"><span class="text-muted small d-block">Job Title</span><strong>{{ report.user.job_title }}</strong></div>
            </div>
          </div>
        </div>

        <!-- Chart -->
        <div class="mb-4" *ngIf="chartData">
          <app-report-chart [data]="chartData" [chartType]="report.chart_type || 'bar'"
                            [reportKey]="reportKey" [allowTypeChange]="true"></app-report-chart>
        </div>

        <!-- Multi-section tables -->
        <ng-container *ngIf="sections.length">
          <div *ngFor="let section of sections" class="mb-4">
            <app-report-table [data]="section.data" [title]="section.title"
                              [searchable]="true" [sortable]="true"
                              [drilldownColumns]="section.drilldownCols"
                              (cellClick)="onDrilldownClick($event, section)"></app-report-table>
          </div>
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
        <div *ngIf="drilldown.visible" class="card my-5 border-0 shadow mb-4 drilldown-panel" id="drilldownPanel">
          <div class="card-header bg-primary bg-opacity-10 border-0 d-flex justify-content-between align-items-center py-2">
            <div>
              <h6 class="mb-0 fw-semibold text-primary">
                <i class="bi bi-funnel me-1"></i>{{ drilldown.title }}
              </h6>
              <small class="text-muted">{{ drilldown.subtitle }}</small>
            </div>
            <div class="d-flex gap-2 align-items-center">
              <div class="spinner-border spinner-border-sm text-primary" *ngIf="drilldown.loading"></div>
              <app-report-export *ngIf="drilldown.data.length"
                                 [data]="drilldown.data" [title]="drilldown.title"
                                 label="Export" btnClass="btn-primary"></app-report-export>
              <button class="btn btn-sm btn-secondary" (click)="closeDrilldown()">
                <i class="bi bi-x-lg"></i>
              </button>
            </div>
          </div>
          <div class="card-body p-0" *ngIf="drilldown.data.length">
            <app-report-table [data]="drilldown.data" [title]="''"
                              [searchable]="true" [sortable]="true" [pageSize]="10"></app-report-table>
          </div>
          <div class="card-body text-center text-muted py-4" *ngIf="!drilldown.data.length && !drilldown.loading">
            <i class="bi bi-inbox d-block fs-3 mb-2"></i>No learners found for this filter.
          </div>
        </div>

        <!-- Badges -->
        <div class="card border-0 shadow-sm mb-4" *ngIf="report.badges?.length">
          <div class="card-body">
            <h6 class="fw-semibold mb-3"><i class="bi bi-award me-2"></i>Badges ({{ report.badges.length }})</h6>
            <div class="d-flex flex-wrap gap-2">
              <span *ngFor="let b of report.badges" class="badge bg-warning-subtle text-warning px-3 py-2" style="font-size:.8rem">
                <i class="bi bi-award me-1"></i>{{ b.badge_name }}
              </span>
            </div>
          </div>
        </div>

        <!-- Certificates -->
        <div class="card border-0 shadow-sm mb-4" *ngIf="report.certificates?.length">
          <div class="card-body">
            <h6 class="fw-semibold mb-3"><i class="bi bi-patch-check me-2"></i>Certificates</h6>
            <app-report-table [data]="report.certificates" [title]="''" [sortable]="true"></app-report-table>
          </div>
        </div>

        <!-- Risk Breakdown -->
        <div class="card border-0 shadow-sm mb-4" *ngIf="report.risk_breakdown">
          <div class="card-body">
            <h6 class="fw-semibold mb-3"><i class="bi bi-shield-exclamation me-2"></i>Risk Breakdown</h6>
            <div class="d-flex gap-3 flex-wrap">
              <span class="badge bg-danger px-3 py-2">Critical: {{ report.risk_breakdown.critical }}</span>
              <span class="badge bg-warning px-3 py-2">High: {{ report.risk_breakdown.high }}</span>
              <span class="badge bg-info px-3 py-2">Medium: {{ report.risk_breakdown.medium }}</span>
              <span class="badge bg-secondary px-3 py-2">Low: {{ report.risk_breakdown.low }}</span>
            </div>
          </div>
        </div>

        <!-- Empty state -->
        <div *ngIf="!sections.length && !tableData?.length && !report.user && !summaryCards.length"
             class="text-center py-5 text-muted">
          <i class="bi bi-inbox fs-1 d-block mb-2"></i>
          <p>No data available for the selected filters.</p>
        </div>
      </div>
    </div>
      </div>
    </main>
  `,
  styles: [`
    .drilldown-panel { border-left: 3px solid var(--bs-primary) !important; }
  `]
})
export class ReportViewerComponent implements OnInit, OnDestroy {
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
  private searchSubject = new Subject<string>();

  private individualReports = ['individual_progress_report', 'individual_assessment_report', 'learner_transcript'];
  private assessmentReports = ['question_analysis_report', 'assessment_item_analysis'];
  private courseReports = ['course_learner_roster'];

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private reportService: ReportService,
    private dp: DecimalPipe
  ) {}

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

  saveReport(): void {
    const name = prompt('Save report as:', this.report?.report_name || this.reportKey);
    if (!name) return;
    this.reportService.saveReport(name, this.reportKey, this.currentFilters).subscribe({
      next: () => alert('Report saved successfully!'),
      error: () => alert('Failed to save report.')
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
      error: (err) => { this.error = err?.error?.message || err?.message || 'Failed to load report.'; this.loading = false; }
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
      title: `${statusLabel} Learners` + (entityName ? ` - ${entityName}` : ''),
      subtitle: 'Loading...', data: []
    };

    this.reportService.drilldown(status, filters).pipe(
      takeUntil(this.destroy$)
    ).subscribe({
      next: (res: any) => {
        const data = res.data || [];
        this.drilldown.data = data;
        this.drilldown.loading = false;
        this.drilldown.subtitle = `${data.length} learner${data.length !== 1 ? 's' : ''} found`;
        setTimeout(() => document.getElementById('drilldownPanel')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 100);
      },
      error: () => {
        this.drilldown.loading = false;
        this.drilldown.subtitle = 'Failed to load drilldown data. The drilldown endpoint may not be configured.';
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
      title: `${statusLabel} Learners - ${matchValue}`,
      subtitle: `${filtered.length} learner${filtered.length !== 1 ? 's' : ''} found`,
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
