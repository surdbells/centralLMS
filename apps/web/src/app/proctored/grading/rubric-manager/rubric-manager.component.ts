// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 11
// Admin: Rubric Manager Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, takeUntil, debounceTime } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { ApiError } from '../../../core/api';
import {
  AssessmentsService,
  type Rubric,
} from '../../../services/assessments.service';
import { AdmintopComponent } from '../../../partials/top/admintop/admintop.component';

// ==========================================
// INTERFACES
// ==========================================

interface ApiResponse<T> {
  code: number;
  status: string;
  data: T;
  message: string;
}

interface RubricListItem {
  rubric_id: string;
  name: string;
  description: string;
  rubric_type: string;
  total_points: number;
  criteria_count: number;
  status: string;
  usage_count: number;
  created_by_name: string;
  created_at: string;
  updated_at: string;
}

interface RubricPreview extends RubricListItem {
  criteria: {
    criterion_id: string;
    title: string;
    description: string;
    max_points: number;
    weight: number;
    levels: { level_id: string; label: string; description: string; points: number; }[];
  }[];
}

@Component({
  selector: 'app-rubric-manager',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>

    <div class="rubric-manager-container">
      <!-- Header -->
      <div class="page-header">
        <div class="header-left">
          <h1 class="page-title">
            <tui-icon icon="@tui.list"></tui-icon>
            Rubric Manager
          </h1>
          <p class="page-subtitle">Create and manage grading rubrics</p>
        </div>
        <div class="header-actions">
          <button class="btn btn-primary" (click)="createRubric()">
            <tui-icon icon="@tui.plus"></tui-icon> New Rubric
          </button>
        </div>
      </div>

      <!-- Stats -->
      <div class="stats-row">
        <div class="stat-card">
          <span class="stat-value">{{ rubrics.length }}</span>
          <span class="stat-label">Total Rubrics</span>
        </div>
        <div class="stat-card">
          <span class="stat-value">{{ getCountByType('analytic') }}</span>
          <span class="stat-label">Analytic</span>
        </div>
        <div class="stat-card">
          <span class="stat-value">{{ getCountByType('holistic') }}</span>
          <span class="stat-label">Holistic</span>
        </div>
        <div class="stat-card">
          <span class="stat-value">{{ getCountByType('checklist') }}</span>
          <span class="stat-label">Checklist</span>
        </div>
      </div>

      <!-- Filters -->
      <div class="filters-bar">
        <div class="search-box">
          <tui-icon icon="@tui.search"></tui-icon>
          <input type="text" class="form-control" placeholder="Search rubrics..."
                 [(ngModel)]="searchTerm" (ngModelChange)="onSearch()">
        </div>
        <select class="form-select form-select-sm" [(ngModel)]="filterType" (change)="applyFilters()">
          <option value="">All Types</option>
          <option value="analytic">Analytic</option>
          <option value="holistic">Holistic</option>
          <option value="checklist">Checklist</option>
        </select>
        <select class="form-select form-select-sm" [(ngModel)]="filterStatus" (change)="applyFilters()">
          <option value="">All Status</option>
          <option value="active">Active</option>
          <option value="draft">Draft</option>
          <option value="archived">Archived</option>
        </select>
        <select class="form-select form-select-sm" [(ngModel)]="sortBy" (change)="applyFilters()">
          <option value="newest">Newest First</option>
          <option value="oldest">Oldest First</option>
          <option value="name">Name A-Z</option>
          <option value="most_used">Most Used</option>
        </select>
      </div>

      <!-- Rubric Grid -->
      <div class="rubric-grid" *ngIf="!loading && filteredRubrics.length > 0">
        <div class="rubric-card" *ngFor="let r of filteredRubrics">
          <div class="rubric-card-header">
            <span class="rubric-type-badge" [ngClass]="'type-' + r.rubric_type">
              <tui-icon [icon]="getRubricTypeIcon(r.rubric_type)"></tui-icon>
              {{ r.rubric_type | titlecase }}
            </span>
            <span class="rubric-status" [ngClass]="'status-' + r.status">{{ r.status }}</span>
          </div>

          <div class="rubric-card-body" (click)="previewRubric(r)">
            <h5 class="rubric-name">{{ r.name }}</h5>
            <p class="rubric-desc" *ngIf="r.description">{{ truncate(r.description, 100) }}</p>
            <div class="rubric-meta">
              <span><tui-icon icon="@tui.layers"></tui-icon> {{ r.criteria_count }} criteria</span>
              <span><tui-icon icon="@tui.star"></tui-icon> {{ r.total_points }} pts</span>
              <span><tui-icon icon="@tui.activity"></tui-icon> Used {{ r.usage_count }}x</span>
            </div>
          </div>

          <div class="rubric-card-footer">
            <span class="rubric-author">{{ r.created_by_name }}</span>
            <div class="rubric-actions">
              <button class="btn btn-sm btn-primary" (click)="editRubric(r)" title="Edit">
                <tui-icon icon="@tui.edit"></tui-icon>
              </button>
              <button class="btn btn-sm btn-secondary" (click)="cloneRubric(r)" title="Clone">
                <tui-icon icon="@tui.copy"></tui-icon>
              </button>
              <button class="btn btn-sm btn-secondary" (click)="previewRubric(r)" title="Preview">
                <tui-icon icon="@tui.eye"></tui-icon>
              </button>
              <button class="btn btn-sm btn-outline-danger" (click)="confirmDelete(r)" title="Delete"
                      [disabled]="r.usage_count > 0">
                <tui-icon icon="@tui.trash-2"></tui-icon>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty State -->
      <div class="empty-state" *ngIf="!loading && filteredRubrics.length === 0">
        <tui-icon icon="@tui.list"></tui-icon>
        <h4>{{ hasActiveFilters() ? 'No Matching Rubrics' : 'No Rubrics Yet' }}</h4>
        <p class="text-muted">{{ hasActiveFilters() ? 'Try adjusting your filters' : 'Create your first rubric to get started' }}</p>
        <button class="btn btn-primary" *ngIf="!hasActiveFilters()" (click)="createRubric()">
          <tui-icon icon="@tui.plus"></tui-icon> Create Rubric
        </button>
      </div>

      <!-- Loading -->
      <div class="loading-state" *ngIf="loading">
        <div class="spinner-border text-primary"></div>
        <p>Loading rubrics...</p>
      </div>

      <!-- ==========================================
           PREVIEW MODAL
           ========================================== -->
      <div class="modal-overlay" *ngIf="showPreviewModal && previewData" (click)="showPreviewModal = false">
        <div class="modal-dialog modal-lg" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h5 class="modal-title">
              <tui-icon icon="@tui.eye"></tui-icon> {{ previewData.name }}
              <span class="badge ms-2" [ngClass]="'type-' + previewData.rubric_type">{{ previewData.rubric_type }}</span>
            </h5>
            <button class="btn-close" (click)="showPreviewModal = false"></button>
          </div>
          <div class="modal-body">
            <p *ngIf="previewData.description" class="text-muted mb-3">{{ previewData.description }}</p>

            <!-- Analytic Rubric Table -->
            <div class="rubric-preview-table" *ngIf="previewData.rubric_type === 'analytic' && previewData.criteria?.length">
              <table class="table table-bordered table-sm">
                <thead>
                  <tr>
                    <th class="criteria-col">Criteria</th>
                    <th *ngFor="let lvl of getUniqueLevels()" class="level-col text-center">
                      {{ lvl.label }}<br><small class="text-muted">{{ lvl.points }} pts</small>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr *ngFor="let c of previewData.criteria">
                    <td>
                      <strong>{{ c.title }}</strong>
                      <div class="small text-muted" *ngIf="c.description">{{ c.description }}</div>
                      <div class="small text-primary">{{ c.max_points }} pts ({{ c.weight }}%)</div>
                    </td>
                    <td *ngFor="let lvl of c.levels" class="small">
                      {{ lvl.description || '-' }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- Holistic / Checklist -->
            <div *ngIf="previewData.rubric_type !== 'analytic' && previewData.criteria?.length">
              <div class="preview-criterion" *ngFor="let c of previewData.criteria">
                <div class="d-flex justify-content-between mb-1">
                  <strong>{{ c.title }}</strong>
                  <span class="text-primary fw-bold">{{ c.max_points }} pts</span>
                </div>
                <p class="text-muted small mb-2" *ngIf="c.description">{{ c.description }}</p>
                <div class="preview-levels">
                  <div class="preview-level" *ngFor="let lvl of c.levels">
                    <span class="level-badge">{{ lvl.label }} ({{ lvl.points }}pts)</span>
                    <span class="text-muted small ms-2" *ngIf="lvl.description">{{ lvl.description }}</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="rubric-preview-total">
              <strong>Total Points:</strong>
              <span class="fw-bold text-primary ms-1">{{ previewData.total_points }}</span>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" (click)="showPreviewModal = false">Close</button>
            <button class="btn btn-primary" (click)="editRubric(previewData); showPreviewModal = false">
              <tui-icon icon="@tui.edit"></tui-icon> Edit
            </button>
          </div>
        </div>
      </div>

      <!-- Delete Confirm -->
      <div class="modal-overlay" *ngIf="showDeleteModal" (click)="showDeleteModal = false">
        <div class="modal-dialog modal-sm" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h5 class="modal-title"><tui-icon icon="@tui.alert-triangle"></tui-icon> Delete Rubric</h5>
            <button class="btn-close" (click)="showDeleteModal = false"></button>
          </div>
          <div class="modal-body">
            <p>Are you sure you want to delete <strong>{{ deleteTarget?.name }}</strong>?</p>
            <p class="text-muted small">This action cannot be undone.</p>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" (click)="showDeleteModal = false">Cancel</button>
            <button class="btn btn-danger" (click)="executeDelete()">
              <tui-icon icon="@tui.trash-2"></tui-icon> Delete
            </button>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .rubric-manager-container { padding: 1.5rem; }
    .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; }
    .page-title { font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; margin: 0; }
    .page-subtitle { color: #6c757d; font-size: 0.875rem; margin: 0.25rem 0 0; }

    .stats-row { display: flex; gap: 1rem; margin-bottom: 1.25rem; }
    .stat-card { background: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 0.75rem 1.25rem; text-align: center; flex: 1; }
    .stat-value { display: block; font-size: 1.5rem; font-weight: 700; color: #212529; }
    .stat-label { font-size: 0.75rem; color: #6c757d; }

    .filters-bar { display: flex; gap: 0.75rem; margin-bottom: 1.25rem; flex-wrap: wrap; align-items: center; }
    .search-box { position: relative; flex: 1; min-width: 200px; }
    .search-box tui-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #adb5bd; font-size: 0.875rem; }
    .search-box input { padding-left: 2rem; font-size: 0.875rem; }
    .form-select { width: auto; min-width: 130px; }

    .rubric-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 1rem; }
    .rubric-card { background: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem; display: flex; flex-direction: column; transition: all 0.2s; }
    .rubric-card:hover { border-color: #adb5bd; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .rubric-card-header { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; border-bottom: 1px solid #e9ecef; }
    .rubric-type-badge { display: flex; align-items: center; gap: 0.375rem; font-size: 0.8125rem; font-weight: 500; }
    .type-analytic { color: #0d6efd; }
    .type-holistic { color: #6f42c1; }
    .type-checklist { color: #198754; }
    .rubric-status { font-size: 0.6875rem; padding: 0.125rem 0.5rem; border-radius: 1rem; text-transform: capitalize; }
    .status-active { background: #d4edda; color: #155724; }
    .status-draft { background: #fff3cd; color: #856404; }
    .status-archived { background: #e2e3e5; color: #6c757d; }
    .rubric-card-body { padding: 1rem; flex: 1; cursor: pointer; }
    .rubric-name { font-size: 1rem; font-weight: 600; margin: 0 0 0.375rem; }
    .rubric-desc { font-size: 0.8125rem; color: #6c757d; margin-bottom: 0.5rem; }
    .rubric-meta { display: flex; gap: 1rem; font-size: 0.75rem; color: #6c757d; }
    .rubric-meta span { display: flex; align-items: center; gap: 0.25rem; }
    .rubric-card-footer { display: flex; justify-content: space-between; align-items: center; padding: 0.625rem 1rem;
      border-top: 1px solid #e9ecef; background: #f8f9fa; border-radius: 0 0 0.5rem 0.5rem; }
    .rubric-author { font-size: 0.75rem; color: #6c757d; }
    .rubric-actions { display: flex; gap: 0.25rem; }

    .empty-state { text-align: center; padding: 3rem; }
    .empty-state tui-icon { font-size: 2.5rem; color: #adb5bd; margin-bottom: 1rem; }
    .empty-state h4 { font-weight: 600; margin-bottom: 0.5rem; }
    .loading-state { text-align: center; padding: 3rem; }

    /* Modals */
    .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1050;
      display: flex; align-items: center; justify-content: center; }
    .modal-dialog { background: #fff; border-radius: 0.5rem; width: 95%; max-height: 90vh; display: flex; flex-direction: column; }
    .modal-dialog.modal-lg { max-width: 900px; }
    .modal-dialog.modal-sm { max-width: 400px; }
    .modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid #dee2e6; display: flex; justify-content: space-between; align-items: center; }
    .modal-title { font-size: 1.125rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; margin: 0; }
    .modal-body { padding: 1.25rem; overflow-y: auto; flex: 1; }
    .modal-footer { padding: 0.75rem 1.25rem; border-top: 1px solid #dee2e6; display: flex; justify-content: flex-end; gap: 0.5rem; }

    /* Preview */
    .rubric-preview-table { overflow-x: auto; }
    .criteria-col { min-width: 200px; }
    .level-col { min-width: 120px; }
    .preview-criterion { padding: 0.75rem; border: 1px solid #e9ecef; border-radius: 0.375rem; margin-bottom: 0.75rem; }
    .preview-levels { display: flex; flex-direction: column; gap: 0.375rem; }
    .preview-level { display: flex; align-items: center; padding: 0.25rem 0; }
    .level-badge { background: #e9ecef; padding: 0.125rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; font-weight: 500; white-space: nowrap; }
    .rubric-preview-total { background: #f8f9fa; padding: 0.75rem; border-radius: 0.375rem; margin-top: 1rem; text-align: right; }
  `]
})
export class RubricManagerComponent implements OnInit, OnDestroy {
  private readonly assessments = inject(AssessmentsService);

  /** What went wrong, if anything. Rendered rather than swallowed. */
  error: string | null = null;
  private router = inject(Router);
  private destroy$ = new Subject<void>();
  private searchSubject = new Subject<string>();

  // Data
  rubrics: RubricListItem[] = [];
  filteredRubrics: RubricListItem[] = [];

  // State
  loading = false;
  searchTerm = '';
  filterType = '';
  filterStatus = '';
  sortBy = 'newest';

  // Modals
  showPreviewModal = false;
  showDeleteModal = false;
  previewData: RubricPreview | null = null;
  deleteTarget: RubricListItem | null = null;

  ngOnInit(): void {
    this.searchSubject.pipe(debounceTime(300), takeUntil(this.destroy$)).subscribe(() => this.applyFilters());
    this.loadRubrics();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // DATA LOADING
  // ==========================================

  loadRubrics(): void {
    this.loading = true;
    this.error = null;

    this.assessments.rubrics().pipe(takeUntil(this.destroy$)).subscribe({
      next: (rubrics) => {
        this.rubrics = rubrics.map(toListItem);
        this.applyFilters();
        this.loading = false;
      },
      error: (failure: ApiError) => {
        // Said out loud. The legacy checked `res.code === 1` and did
        // nothing otherwise, so a failure left an empty list that looked
        // exactly like having no rubrics.
        this.error = failure.message;
        this.loading = false;
      },
    });
  }

  // ==========================================
  // FILTERS
  // ==========================================

  onSearch(): void {
    this.searchSubject.next(this.searchTerm);
  }

  applyFilters(): void {
    let result = [...this.rubrics];

    if (this.searchTerm) {
      const term = this.searchTerm.toLowerCase();
      result = result.filter(r =>
        r.name.toLowerCase().includes(term) ||
        r.description?.toLowerCase().includes(term) ||
        r.created_by_name?.toLowerCase().includes(term)
      );
    }

    if (this.filterType) {
      result = result.filter(r => r.rubric_type === this.filterType);
    }

    if (this.filterStatus) {
      result = result.filter(r => r.status === this.filterStatus);
    }

    switch (this.sortBy) {
      case 'newest': result.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime()); break;
      case 'oldest': result.sort((a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime()); break;
      case 'name': result.sort((a, b) => a.name.localeCompare(b.name)); break;
      case 'most_used': result.sort((a, b) => b.usage_count - a.usage_count); break;
    }

    this.filteredRubrics = result;
  }

  hasActiveFilters(): boolean {
    return !!(this.searchTerm || this.filterType || this.filterStatus);
  }

  getCountByType(type: string): number {
    return this.rubrics.filter(r => r.rubric_type === type).length;
  }

  // ==========================================
  // ACTIONS
  // ==========================================

  createRubric(): void {
    this.router.navigate(['/admin/rubrics', 'new']);
  }

  editRubric(r: RubricListItem): void {
    this.router.navigate(['/admin/rubrics', r.rubric_id, 'edit']);
  }

  cloneRubric(r: RubricListItem): void {
    this.assessments
      .duplicateRubric(r.rubric_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.loadRubrics(),
        error: (failure: ApiError) => (this.error = failure.message),
      });
  }

  previewRubric(r: RubricListItem): void {
    this.assessments
      .rubric(r.rubric_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (rubric) => {
          this.previewData = toPreview(rubric);
          this.showPreviewModal = true;
        },
        error: (failure: ApiError) => (this.error = failure.message),
      });
  }

  confirmDelete(r: RubricListItem): void {
    this.deleteTarget = r;
    this.showDeleteModal = true;
  }

  executeDelete(): void {
    if (!this.deleteTarget) return;

    this.assessments
      .deleteRubric(this.deleteTarget.rubric_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.showDeleteModal = false;
          this.deleteTarget = null;
          this.loadRubrics();
        },
        // The API refuses while questions still point at it, and says how
        // many — the marks already given name its criteria.
        error: (failure: ApiError) => {
          this.showDeleteModal = false;
          this.error = failure.message;
        },
      });
  }

  // ==========================================
  // HELPERS
  // ==========================================

  getRubricTypeIcon(type: string): string {
    const icons: Record<string, string> = {
      analytic: '@tui.grid', holistic: '@tui.circle', checklist: '@tui.check-square'
    };
    return icons[type] || '@tui.list';
  }

  getUniqueLevels(): { label: string; points: number }[] {
    if (!this.previewData?.criteria?.length) return [];
    return this.previewData.criteria[0].levels.map(l => ({ label: l.label, points: l.points }));
  }

  truncate(text: string, len: number): string {
    return text && text.length > len ? text.substring(0, len) + '...' : text || '';
  }
}

// ---------------------------------------------------------------------------
// Translation
// ---------------------------------------------------------------------------
//
// The screen's own vocabulary, kept exactly. `total_points` and
// `criteria_count` are counted by the API from the bands rather than stored,
// so what arrives here is already right.

function toListItem(r: Rubric): RubricListItem {
  return {
    rubric_id: r.id,
    name: r.name,
    description: r.description ?? '',
    rubric_type: r.kind,
    total_points: r.totalPoints,
    criteria_count: r.criterionCount,
    status: r.isActive ? 'active' : 'inactive',
    usage_count: r.questionCount,
    created_by_name: '',
    created_at: r.createdAt ?? '',
    updated_at: r.updatedAt ?? '',
  };
}

function toPreview(r: Rubric): RubricPreview {
  return {
    ...toListItem(r),
    criteria: r.criteria.map((c) => ({
      criterion_id: c.id,
      title: c.title,
      description: c.description ?? '',
      max_points: c.maxPoints,
      // Weighting is not modelled: a criterion's weight is what its bands
      // are worth, which is the same thing said once instead of twice.
      weight: 1,
      levels: c.levels.map((l) => ({
        level_id: l.id,
        label: l.label,
        description: l.description ?? '',
        points: l.points,
      })),
    })),
  };
}
