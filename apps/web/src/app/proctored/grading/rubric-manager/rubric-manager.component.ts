// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 11
// Admin: Rubric Manager Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import { SelectComponent } from '../../../shared/select/select.component';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '../../../translate.pipe';
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
  imports: [CommonModule, FormsModule, RouterLink, TuiIcon, AdmintopComponent, SkeletonComponent, TranslatePipe, SelectComponent],
  template: `
    <app-admintop></app-admintop>

    <main class="main">
    <div class="ui-page rubric-manager-container">
      <!-- Header -->
      <header class="ui-page-header">
        <a routerLink="/admin/grading" class="ui-page-header__back" [attr.aria-label]="'common.back' | translate"><i class="bi bi-chevron-left"></i></a>
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-list-check"></i></div>
        <div class="ui-page-header__titles">
          <h1>Rubric Manager</h1>
          <p>Create and manage grading rubrics</p>
        </div>
        <div class="ui-page-header__actions">
          <button class="btn btn-primary" (click)="createRubric()">
            <tui-icon icon="@tui.plus"></tui-icon> New Rubric
          </button>
        </div>
      </header>

      <!-- Stats -->
      <div class="ui-kpi-grid ui-kpi-grid--4 stats-row">
        <div class="ui-kpi ui-kpi--brand">
          <span class="ui-kpi__label"><i class="bi bi-list-check"></i> Total Rubrics</span>
          <span class="ui-kpi__value">{{ rubrics.length }}</span>
        </div>
        <div class="ui-kpi ui-kpi--info">
          <span class="ui-kpi__label"><i class="bi bi-grid-3x3"></i> Analytic</span>
          <span class="ui-kpi__value">{{ getCountByType('analytic') }}</span>
        </div>
        <div class="ui-kpi">
          <span class="ui-kpi__label"><i class="bi bi-circle"></i> Holistic</span>
          <span class="ui-kpi__value">{{ getCountByType('holistic') }}</span>
        </div>
        <div class="ui-kpi ui-kpi--success">
          <span class="ui-kpi__label"><i class="bi bi-check2-square"></i> Checklist</span>
          <span class="ui-kpi__value">{{ getCountByType('checklist') }}</span>
        </div>
      </div>

      <section class="ui-section">
        <div class="ui-section__head">
          <div>
            <h2 class="ui-section__title">Rubrics</h2>
            <p class="ui-section__subtitle">{{ filteredRubrics.length }} of {{ rubrics.length }}</p>
          </div>
        </div>

        <!-- Filters -->
        <div class="ui-toolbar filters-bar">
          <div class="ui-search search-box">
            <i class="bi bi-search"></i>
            <input type="search" placeholder="Search rubrics..."
                   [(ngModel)]="searchTerm" (ngModelChange)="onSearch()">
          </div>
          <div class="ui-filters">
            <app-select style="width:150px" [options]="typeOpts" placeholder="All Types" [(ngModel)]="filterType" (selectionChange)="applyFilters()" ariaLabel="Type" />
            <app-select style="width:150px" [options]="statusOpts" placeholder="All Status" [(ngModel)]="filterStatus" (selectionChange)="applyFilters()" ariaLabel="Status" />
            <app-select style="width:160px" [options]="sortOpts" [(ngModel)]="sortBy" (selectionChange)="applyFilters()" ariaLabel="Sort" />
          </div>
        </div>

        <!-- Rubric Grid -->
        <div class="ui-grid rubric-grid" *ngIf="!loading && filteredRubrics.length > 0">
          <article class="ui-card rubric-card" *ngFor="let r of filteredRubrics">
            <div class="rubric-card-header">
              <span class="ui-pill rubric-type-badge" [ngClass]="'type-' + r.rubric_type">
                <tui-icon [icon]="getRubricTypeIcon(r.rubric_type)"></tui-icon>
                {{ r.rubric_type | titlecase }}
              </span>
              <span class="ui-pill rubric-status" [ngClass]="'status-' + r.status"><span class="ui-pill__dot"></span>{{ r.status }}</span>
            </div>

            <div class="ui-card__body rubric-card-body" (click)="previewRubric(r)">
              <h3 class="ui-card__title rubric-name">{{ r.name }}</h3>
              <p class="rubric-desc" *ngIf="r.description">{{ truncate(r.description, 100) }}</p>
              <div class="ui-card__meta rubric-meta">
                <span><tui-icon icon="@tui.layers"></tui-icon> {{ r.criteria_count }} criteria</span>
                <span><tui-icon icon="@tui.star"></tui-icon> {{ r.total_points }} pts</span>
                <span><tui-icon icon="@tui.activity"></tui-icon> Used {{ r.usage_count }}x</span>
              </div>
            </div>

            <div class="ui-card__footer rubric-card-footer">
              <span class="rubric-author">{{ r.created_by_name }}</span>
              <div class="rubric-actions">
                <button class="btn btn-sm btn-primary" (click)="editRubric(r)" title="Edit">
                  <tui-icon icon="@tui.edit"></tui-icon>
                </button>
                <button class="btn btn-sm btn-outline-secondary" (click)="cloneRubric(r)" title="Clone">
                  <tui-icon icon="@tui.copy"></tui-icon>
                </button>
                <button class="btn btn-sm btn-outline-secondary" (click)="previewRubric(r)" title="Preview">
                  <tui-icon icon="@tui.eye"></tui-icon>
                </button>
                <button class="btn btn-sm btn-outline-danger" (click)="confirmDelete(r)" title="Delete"
                        [disabled]="r.usage_count > 0">
                  <tui-icon icon="@tui.trash-2"></tui-icon>
                </button>
              </div>
            </div>
          </article>
        </div>

        <!-- Empty State -->
        <div class="ui-state empty-state" *ngIf="!loading && filteredRubrics.length === 0">
          <span class="ui-state__icon"><i class="bi bi-list-check"></i></span>
          <h3>{{ hasActiveFilters() ? 'No Matching Rubrics' : 'No Rubrics Yet' }}</h3>
          <p>{{ hasActiveFilters() ? 'Try adjusting your filters' : 'Create your first rubric to get started' }}</p>
          <button class="btn btn-primary" *ngIf="!hasActiveFilters()" (click)="createRubric()">
            <tui-icon icon="@tui.plus"></tui-icon> Create Rubric
          </button>
        </div>

        <!-- Loading -->
        <app-skeleton *ngIf="loading" variant="cards" [count]="6"></app-skeleton>
      </section>

      <!-- ==========================================
           PREVIEW MODAL
           ========================================== -->
      <div class="modal-overlay" *ngIf="showPreviewModal && previewData" (click)="showPreviewModal = false">
        <div class="modal-dialog modal-lg" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h5 class="modal-title">
              <tui-icon icon="@tui.eye"></tui-icon> {{ previewData.name }}
              <span class="ui-pill ms-2" [ngClass]="'type-' + previewData.rubric_type">{{ previewData.rubric_type }}</span>
            </h5>
            <button class="btn-close" (click)="showPreviewModal = false"></button>
          </div>
          <div class="modal-body">
            <p *ngIf="previewData.description" class="ui-muted mb-3">{{ previewData.description }}</p>

            <!-- Analytic Rubric Table -->
            <div class="ui-table-wrap rubric-preview-table" *ngIf="previewData.rubric_type === 'analytic' && previewData.criteria?.length">
              <table class="table table-bordered table-sm">
                <thead>
                  <tr>
                    <th class="criteria-col">Criteria</th>
                    <th *ngFor="let lvl of getUniqueLevels()" class="level-col text-center">
                      {{ lvl.label }}<br><small class="ui-muted">{{ lvl.points }} pts</small>
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr *ngFor="let c of previewData.criteria">
                    <td>
                      <strong>{{ c.title }}</strong>
                      <div class="small ui-muted" *ngIf="c.description">{{ c.description }}</div>
                      <div class="small accent-text">{{ c.max_points }} pts ({{ c.weight }}%)</div>
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
                  <span class="accent-text fw-bold">{{ c.max_points }} pts</span>
                </div>
                <p class="ui-muted small mb-2" *ngIf="c.description">{{ c.description }}</p>
                <div class="preview-levels">
                  <div class="preview-level" *ngFor="let lvl of c.levels">
                    <span class="level-badge">{{ lvl.label }} ({{ lvl.points }}pts)</span>
                    <span class="ui-muted small ms-2" *ngIf="lvl.description">{{ lvl.description }}</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="rubric-preview-total">
              <strong>Total Points:</strong>
              <span class="fw-bold accent-text ms-1">{{ previewData.total_points }}</span>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline-secondary" (click)="showPreviewModal = false">Close</button>
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
            <p class="ui-muted small">This action cannot be undone.</p>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline-secondary" (click)="showDeleteModal = false">Cancel</button>
            <button class="btn btn-danger" (click)="executeDelete()">
              <tui-icon icon="@tui.trash-2"></tui-icon> Delete
            </button>
          </div>
        </div>
      </div>
    </div>
    </main>
  `,
  styles: [`
    /* Page chrome (header, KPIs, section, toolbar, cards, pills) comes from the global UI kit. */
    .ui-kpi-grid { margin-bottom: 20px; }
    .ui-toolbar { margin-bottom: 16px; }
    .ui-search { flex: 1 1 260px; }
    .accent-text { color: var(--brand-primary); }

    .rubric-grid { grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); }
    .rubric-card { display: flex; flex-direction: column; }
    .rubric-card-header { display: flex; justify-content: space-between; align-items: center; gap: .5rem; padding: 12px 16px 0; }
    .rubric-type-badge { text-transform: capitalize; }
    .type-analytic { background: rgba(var(--brand-primary-rgb), .1); border-color: rgba(var(--brand-primary-rgb), .28); color: var(--brand-primary); }
    .type-holistic { background: rgba(var(--ui-info-rgb), .1); border-color: rgba(var(--ui-info-rgb), .28); color: var(--ui-info-text); }
    .type-checklist { background: rgba(var(--ui-success-rgb), .1); border-color: rgba(var(--ui-success-rgb), .28); color: var(--ui-success-text); }
    .rubric-status { text-transform: capitalize; }
    .status-active { background: rgba(var(--ui-success-rgb), .1); border-color: rgba(var(--ui-success-rgb), .28); color: var(--ui-success-text); }
    .status-draft { background: rgba(var(--ui-warning-rgb), .1); border-color: rgba(var(--ui-warning-rgb), .28); color: var(--ui-warning-text); }
    .status-archived { background: rgba(var(--ui-neutral-rgb), .1); border-color: rgba(var(--ui-neutral-rgb), .28); color: var(--ui-neutral-text); }
    .rubric-card-body { flex: 1; cursor: pointer; }
    .rubric-name { margin: 0; }
    .rubric-desc { font-size: 0.8125rem; color: var(--ui-text-muted, #6a716b); margin: 0; }
    .rubric-meta { display: flex; gap: 1rem; flex-wrap: wrap; font-size: 0.75rem; }
    .rubric-meta span { display: flex; align-items: center; gap: 0.25rem; }
    .rubric-card-footer { justify-content: space-between; }
    .rubric-author { font-size: 0.75rem; color: var(--ui-text-muted, #6a716b); }
    .rubric-actions { display: flex; gap: 0.25rem; }


    /* Modals */
    .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1050;
      display: flex; align-items: center; justify-content: center; }
    .modal-dialog { background: var(--ui-surface, #fff); border-radius: var(--ui-radius-card, 12px); width: 95%; max-height: 90vh; display: flex; flex-direction: column; }
    .modal-dialog.modal-lg { max-width: 900px; }
    .modal-dialog.modal-sm { max-width: 400px; }
    .modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--ui-border, #e7e9e7); display: flex; justify-content: space-between; align-items: center; }
    .modal-title { font-size: 1.0625rem; font-weight: 650; display: flex; align-items: center; gap: 0.5rem; margin: 0; }
    .modal-body { padding: 1.25rem; overflow-y: auto; flex: 1; }
    .modal-footer { padding: 0.75rem 1.25rem; border-top: 1px solid var(--ui-border, #e7e9e7); display: flex; justify-content: flex-end; gap: 0.5rem; }

    /* Preview */
    .rubric-preview-table { overflow-x: auto; }
    .rubric-preview-table .table { margin: 0; }
    .criteria-col { min-width: 200px; }
    .level-col { min-width: 120px; }
    .preview-criterion { padding: 0.75rem; border: 1px solid var(--ui-border, #e7e9e7); border-radius: var(--ui-radius-ctl, 8px); margin-bottom: 0.75rem; }
    .preview-levels { display: flex; flex-direction: column; gap: 0.375rem; }
    .preview-level { display: flex; align-items: center; padding: 0.25rem 0; }
    .level-badge { background: rgba(var(--brand-primary-rgb), .08); color: var(--brand-primary); padding: 0.125rem 0.5rem; border-radius: 999px; font-size: 0.75rem; font-weight: 500; white-space: nowrap; }
    .rubric-preview-total { background: var(--ui-surface-2, #f7f8f7); border: 1px solid var(--ui-border, #e7e9e7); padding: 0.75rem; border-radius: var(--ui-radius-ctl, 8px); margin-top: 1rem; text-align: right; }
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
  readonly typeOpts = [
    { label: 'All Types', value: '' }, { label: 'Analytic', value: 'analytic' },
    { label: 'Holistic', value: 'holistic' }, { label: 'Checklist', value: 'checklist' },
  ];
  readonly statusOpts = [
    { label: 'All Status', value: '' }, { label: 'Active', value: 'active' },
    { label: 'Draft', value: 'draft' }, { label: 'Archived', value: 'archived' },
  ];
  readonly sortOpts = [
    { label: 'Newest First', value: 'newest' }, { label: 'Oldest First', value: 'oldest' },
    { label: 'Name A-Z', value: 'name' }, { label: 'Most Used', value: 'most_used' },
  ];

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
    this.router.navigate(['/admin/grading/rubrics', 'create']);
  }

  editRubric(r: RubricListItem): void {
    this.router.navigate(['/admin/grading/rubrics', r.rubric_id, 'edit']);
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
