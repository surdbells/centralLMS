import { Component, EventEmitter, Input, OnInit, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ReportFilters, ReportCatalogItem } from '../../models/report.models';
import { ReportService } from '../../services/report.service';

@Component({
  selector: 'app-report-filters',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="report-filters card border-0 shadow-sm mb-4">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-3">
          <h6 class="mb-0 fw-semibold"><i class="bi bi-funnel me-2"></i>Filters</h6>
          <div class="d-flex gap-2">
            <button class="btn btn-sm btn-secondary" (click)="toggleExpanded()" *ngIf="!alwaysExpanded">
              <i class="bi" [class.bi-chevron-down]="!expanded" [class.bi-chevron-up]="expanded"></i>
            </button>
            <button class="btn btn-sm btn-outline-danger" (click)="reset()" *ngIf="hasActiveFilters">
              <i class="bi bi-x-circle me-1"></i>Clear
            </button>
          </div>
        </div>

        <div [class.d-none]="!expanded && !alwaysExpanded">
          <div class="row g-3">
            <!-- Date Range -->
            <div class="col-md-3" *ngIf="showFilter('date')">
              <label class="form-label small text-muted">Date From</label>
              <input type="date" class="form-control form-control-sm" [(ngModel)]="filters.date_from" (change)="emitFilters()">
            </div>
            <div class="col-md-3" *ngIf="showFilter('date')">
              <label class="form-label small text-muted">Date To</label>
              <input type="date" class="form-control form-control-sm" [(ngModel)]="filters.date_to" (change)="emitFilters()">
            </div>

            <!-- Department -->
            <div class="col-md-3" *ngIf="showFilter('department')">
              <label class="form-label small text-muted">Department</label>
              <select class="form-select form-select-sm" [(ngModel)]="filters.department_id" (change)="emitFilters()">
                <option [ngValue]="undefined">All Departments</option>
                <option *ngFor="let d of departments" [ngValue]="d.id">{{ d.department_name }}</option>
              </select>
            </div>

            <!-- Branch -->
            <div class="col-md-3" *ngIf="showFilter('branch')">
              <label class="form-label small text-muted">Branch</label>
              <select class="form-select form-select-sm" [(ngModel)]="filters.branch_id" (change)="emitFilters()">
                <option [ngValue]="undefined">All Branches</option>
                <option *ngFor="let b of branches" [ngValue]="b.id">{{ b.branch_name }}</option>
              </select>
            </div>

            <!-- Team -->
            <div class="col-md-3" *ngIf="showFilter('team')">
              <label class="form-label small text-muted">Team</label>
              <select class="form-select form-select-sm" [(ngModel)]="filters.team_id" (change)="emitFilters()">
                <option [ngValue]="undefined">All Teams</option>
                <option *ngFor="let t of teams" [ngValue]="t.id">{{ t.team_name }}</option>
              </select>
            </div>

            <!-- Course -->
            <div class="col-md-3" *ngIf="showFilter('course')">
              <label class="form-label small text-muted">Course</label>
              <select class="form-select form-select-sm" [(ngModel)]="filters.course_id" (change)="emitFilters()">
                <option [ngValue]="undefined">All Courses</option>
                <option *ngFor="let c of courses" [ngValue]="c.id || c.course_id">{{ c.title }}</option>
              </select>
            </div>

            <!-- Status -->
            <div class="col-md-3" *ngIf="showFilter('status')">
              <label class="form-label small text-muted">Status</label>
              <select class="form-select form-select-sm" [(ngModel)]="filters.status" (change)="emitFilters()">
                <option value="">All Statuses</option>
                <option value="completed">Completed</option>
                <option value="in_progress">In Progress</option>
                <option value="not_started">Not Started</option>
                <option value="overdue">Overdue</option>
              </select>
            </div>

            <!-- Period -->
            <div class="col-md-3" *ngIf="showFilter('period')">
              <label class="form-label small text-muted">Period</label>
              <select class="form-select form-select-sm" [(ngModel)]="filters.period" (change)="emitFilters()">
                <option value="month">Monthly</option>
                <option value="quarter">Quarterly</option>
                <option value="week">Weekly</option>
              </select>
            </div>

            <!-- Group By / Breakdown -->
            <div class="col-md-3" *ngIf="showFilter('breakdown')">
              <label class="form-label small text-muted">Group By</label>
              <select class="form-select form-select-sm" [(ngModel)]="filters.breakdown" (change)="emitFilters()">
                <option value="department">Department</option>
                <option value="branch">Branch</option>
                <option value="team">Team</option>
              </select>
            </div>

            <!-- Per Page -->
            <div class="col-md-2" *ngIf="showFilter('per_page')">
              <label class="form-label small text-muted">Per Page</label>
              <select class="form-select form-select-sm" [(ngModel)]="filters.per_page" (change)="emitFilters()">
                <option [ngValue]="25">25</option>
                <option [ngValue]="50">50</option>
                <option [ngValue]="100">100</option>
              </select>
            </div>
          </div>

          <!-- Quick date presets -->
          <div class="mt-3 d-flex gap-2 flex-wrap" *ngIf="showFilter('date')">
            <span class="small text-muted me-1 mt-1">Quick:</span>
            <button *ngFor="let p of datePresets" class="btn btn-sm btn-primary"
                    [class.active]="activePreset === p.key" (click)="applyPreset(p)">
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

  constructor(private reportService: ReportService) {}

  ngOnInit(): void {
    this.filters = { per_page: 25, ...this.initialFilters };
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
    this.reportService.getCourses().subscribe(c => this.courses = c);
  }
}
