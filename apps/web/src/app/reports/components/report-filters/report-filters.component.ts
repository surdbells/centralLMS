import { Component, EventEmitter, Input, OnInit, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ReportFilters, ReportCatalogItem } from '../../models/report.models';
import { ReportService } from '../../services/report.service';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';
import { SelectComponent } from '../../../shared/select/select.component';

@Component({
  selector: 'app-report-filters',
  standalone: true,
  imports: [CommonModule, FormsModule, TranslatePipe, SelectComponent],
  template: `
    <div class="report-filters ui-section">
      <div>
        <div class="ui-section__head rf-head">
          <div><h2 class="ui-section__title"><i class="bi bi-funnel me-2"></i>{{ 'reports.filters' | translate }}</h2></div>
          <div class="ui-section__actions">
            <button type="button" class="btn btn-sm btn-outline-secondary" (click)="toggleExpanded()" *ngIf="!alwaysExpanded">
              <i class="bi" [class.bi-chevron-down]="!expanded" [class.bi-chevron-up]="expanded"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-danger" (click)="reset()" *ngIf="hasActiveFilters">
              <i class="bi bi-x-circle me-1"></i>{{ 'common.clear' | translate }}
            </button>
          </div>
        </div>

        <div [class.d-none]="!expanded && !alwaysExpanded">
          <div class="row g-3">
            <!-- Date Range -->
            <div class="col-md-3" *ngIf="showFilter('date')">
              <label class="form-label small ui-muted">{{ 'reports.date_from' | translate }}</label>
              <input type="date" class="form-control" [(ngModel)]="filters.date_from" (change)="emitFilters()">
            </div>
            <div class="col-md-3" *ngIf="showFilter('date')">
              <label class="form-label small ui-muted">{{ 'reports.date_to' | translate }}</label>
              <input type="date" class="form-control" [(ngModel)]="filters.date_to" (change)="emitFilters()">
            </div>

            <!-- Department -->
            <div class="col-md-3" *ngIf="showFilter('department')">
              <label class="form-label small ui-muted">{{ 'reports.department' | translate }}</label>
              <app-select [options]="departments" bindLabel="department_name" bindValue="id" [clearable]="true"
                          placeholder="{{ 'reports.all_departments' | translate }}" [ariaLabel]="'reports.department' | translate"
                          [ngModel]="filters.department_id" (ngModelChange)="filters.department_id = $event ?? undefined; emitFilters()" />
            </div>

            <!-- Branch -->
            <div class="col-md-3" *ngIf="showFilter('branch')">
              <label class="form-label small ui-muted">{{ 'reports.branch' | translate }}</label>
              <app-select [options]="branches" bindLabel="branch_name" bindValue="id" [clearable]="true"
                          placeholder="{{ 'reports.all_branches' | translate }}" [ariaLabel]="'reports.branch' | translate"
                          [ngModel]="filters.branch_id" (ngModelChange)="filters.branch_id = $event ?? undefined; emitFilters()" />
            </div>

            <!-- Team -->
            <div class="col-md-3" *ngIf="showFilter('team')">
              <label class="form-label small ui-muted">{{ 'reports.team' | translate }}</label>
              <app-select [options]="teams" bindLabel="team_name" bindValue="id" [clearable]="true"
                          placeholder="{{ 'reports.all_teams' | translate }}" [ariaLabel]="'reports.team' | translate"
                          [ngModel]="filters.team_id" (ngModelChange)="filters.team_id = $event ?? undefined; emitFilters()" />
            </div>

            <!-- Course -->
            <div class="col-md-3" *ngIf="showFilter('course')">
              <label class="form-label small ui-muted">{{ 'reports.course' | translate }}</label>
              <app-select [options]="courseOpts" [clearable]="true"
                          placeholder="{{ 'reports.all_courses' | translate }}" [ariaLabel]="'reports.course' | translate"
                          [ngModel]="filters.course_id" (ngModelChange)="filters.course_id = $event ?? undefined; emitFilters()" />
            </div>

            <!-- Status -->
            <div class="col-md-3" *ngIf="showFilter('status')">
              <label class="form-label small ui-muted">{{ 'common.status' | translate }}</label>
              <app-select [options]="statusOpts" [translateLabels]="true" [clearable]="true"
                          placeholder="{{ 'reports.all_statuses' | translate }}" [ariaLabel]="'common.status' | translate"
                          [ngModel]="filters.status" (ngModelChange)="filters.status = $event ?? ''; emitFilters()" />
            </div>

            <!-- Period -->
            <div class="col-md-3" *ngIf="showFilter('period')">
              <label class="form-label small ui-muted">{{ 'reports.period' | translate }}</label>
              <app-select [options]="periodOpts" [translateLabels]="true" [searchable]="false" [ariaLabel]="'reports.period' | translate"
                          [(ngModel)]="filters.period" (ngModelChange)="emitFilters()" />
            </div>

            <!-- Group By / Breakdown -->
            <div class="col-md-3" *ngIf="showFilter('breakdown')">
              <label class="form-label small ui-muted">{{ 'reports.group_by' | translate }}</label>
              <app-select [options]="breakdownOpts" [translateLabels]="true" [searchable]="false" [ariaLabel]="'reports.group_by' | translate"
                          [(ngModel)]="filters.breakdown" (ngModelChange)="emitFilters()" />
            </div>

            <!-- Per Page -->
            <div class="col-md-2" *ngIf="showFilter('per_page')">
              <label class="form-label small ui-muted">{{ 'reports.per_page' | translate }}</label>
              <app-select [options]="perPageOpts" [searchable]="false" [ariaLabel]="'reports.per_page' | translate"
                          [(ngModel)]="filters.per_page" (ngModelChange)="emitFilters()" />
            </div>
          </div>

          <!-- Quick date presets -->
          <div class="mt-3 ui-filters align-items-center" *ngIf="showFilter('date')">
            <span class="small ui-muted me-1">{{ 'reports.quick_label' | translate }}</span>
            <button type="button" *ngFor="let p of datePresets" class="ui-filter"
                    [class.is-active]="activePreset === p.key" (click)="applyPreset(p)">
              {{ p.label }}
            </button>
          </div>
        </div>
      </div>
    </div>
  `
})
export class ReportFiltersComponent implements OnInit {
  @Input() reportItem?: ReportCatalogItem;
  @Input() initialFilters: ReportFilters = {};
  @Input() visibleFilters: string[] = ['date', 'department', 'branch', 'team', 'course', 'status', 'per_page'];
  @Input() alwaysExpanded = false;
  @Output() filtersChanged = new EventEmitter<ReportFilters>();

  filters: ReportFilters = {};
  expanded = true;
  departments: any[] = [];
  branches: any[] = [];
  teams: any[] = [];
  courses: any[] = [];
  /** Courses as select options; a course's id may come as `id` or `course_id`. */
  courseOpts: Array<{ value: any; label: string }> = [];

  readonly statusOpts = [
    { value: 'completed', label: 'report.completed' },
    { value: 'in_progress', label: 'report.in_progress' },
    { value: 'not_started', label: 'report.not_started' },
    { value: 'overdue', label: 'reports.overdue' },
  ] as const;
  readonly periodOpts = [
    { value: 'month', label: 'reports.period_monthly' },
    { value: 'quarter', label: 'reports.period_quarterly' },
    { value: 'week', label: 'reports.period_weekly' },
  ] as const;
  readonly breakdownOpts = [
    { value: 'department', label: 'reports.department' },
    { value: 'branch', label: 'reports.branch' },
    { value: 'team', label: 'reports.team' },
  ] as const;
  readonly perPageOpts = [25, 50, 100] as const;
  activePreset = '';

  datePresets = [
    { key: '7d', label: 'Last 7 days', days: 7 },
    { key: '30d', label: 'Last 30 days', days: 30 },
    { key: '90d', label: 'Last 90 days', days: 90 },
    { key: 'ytd', label: 'Year to date', days: 0 },
    { key: 'all', label: 'All time', days: -1 }
  ];

  get hasActiveFilters(): boolean {
    return Object.entries(this.filters).some(([k, v]) =>
      v !== undefined && v !== null && v !== '' && k !== 'page' && k !== 'per_page'
    );
  }

  constructor(private reportService: ReportService, private i18n: I18nService) {}

  ngOnInit(): void {
    this.filters = { per_page: 25, ...this.initialFilters };
    this.datePresets = [
      { key: '7d', label: this.i18n.t('reports.preset_7d'), days: 7 },
      { key: '30d', label: this.i18n.t('reports.preset_30d'), days: 30 },
      { key: '90d', label: this.i18n.t('reports.preset_90d'), days: 90 },
      { key: 'ytd', label: this.i18n.t('reports.preset_ytd'), days: 0 },
      { key: 'all', label: this.i18n.t('reports.preset_all'), days: -1 }
    ];
    this.loadLookups();
  }

  showFilter(key: string): boolean {
    if (this.visibleFilters.includes(key)) return true;
    if (this.reportItem?.params?.includes(key)) return true;
    if (key === 'period' && this.reportItem?.params?.includes('period')) return true;
    if (key === 'breakdown' && this.reportItem?.params?.includes('breakdown')) return true;
    return false;
  }

  toggleExpanded(): void { this.expanded = !this.expanded; }

  emitFilters(): void {
    const clean: ReportFilters = {};
    Object.entries(this.filters).forEach(([k, v]) => {
      if (v !== undefined && v !== null && v !== '') clean[k] = v;
    });
    this.filtersChanged.emit(clean);
  }

  reset(): void {
    this.filters = { per_page: 25 };
    this.activePreset = '';
    this.emitFilters();
  }

  applyPreset(preset: { key: string; label: string; days: number }): void {
    this.activePreset = preset.key;
    if (preset.days < 0) {
      this.filters.date_from = '';
      this.filters.date_to = '';
    } else if (preset.key === 'ytd') {
      this.filters.date_from = new Date(new Date().getFullYear(), 0, 1).toISOString().split('T')[0];
      this.filters.date_to = new Date().toISOString().split('T')[0];
    } else {
      const from = new Date();
      from.setDate(from.getDate() - preset.days);
      this.filters.date_from = from.toISOString().split('T')[0];
      this.filters.date_to = new Date().toISOString().split('T')[0];
    }
    this.emitFilters();
  }

  /** Uses action=all-filters for org units + action=courses-list for courses */
  private loadLookups(): void {
    this.reportService.getAllFilters().subscribe(data => {
      this.departments = data.departments || [];
      this.branches = data.branches || [];
      this.teams = data.teams || [];
    });
    this.reportService.getCourses().subscribe(c => {
      this.courses = c;
      this.courseOpts = (c ?? []).map((x: any) => ({ value: x.id || x.course_id, label: x.title }));
    });
  }
}
