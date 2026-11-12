// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 11
// Admin: Rubric Form Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { ApiError } from '../../../core/api';
import {
  AssessmentsService,
  type Rubric,
  type RubricKind,
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

interface CriterionLevel {
  level_id?: string;
  label: string;
  description: string;
  points: number;
  sort_order: number;
}

interface RubricCriterion {
  criterion_id?: string;
  title: string;
  description: string;
  max_points: number;
  weight: number;
  sort_order: number;
  levels: CriterionLevel[];
  _collapsed?: boolean;
}

interface RubricFormData {
  rubric_id?: string;
  name: string;
  description: string;
  rubric_type: string;
  status: string;
  criteria: RubricCriterion[];
}

@Component({
  selector: 'app-rubric-form',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent, SkeletonComponent],
  template: `
    <app-admintop></app-admintop>

    <main class="main">
    <div class="ui-page rubric-form-container">
      <!-- Header -->
      <header class="ui-page-header">
        <button type="button" class="ui-page-header__back" (click)="goBack()" aria-label="Back" title="Back">
          <i class="bi bi-chevron-left"></i>
        </button>
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-ui-checks-grid"></i></div>
        <div class="ui-page-header__titles">
          <h1>{{ isEditMode ? 'Edit Rubric' : 'Create Rubric' }}</h1>
          <p>{{ isEditMode ? 'Update rubric criteria and levels' : 'Define grading criteria for assessments' }}</p>
        </div>
        <div class="ui-page-header__actions">
          <button class="btn btn-outline-secondary" (click)="saveDraft()" [disabled]="saving">
            <tui-icon icon="@tui.save"></tui-icon> Save Draft
          </button>
          <button class="btn btn-primary" (click)="saveRubric()" [disabled]="saving || !isValid()">
            <span class="spinner-border spinner-border-sm me-1" *ngIf="saving"></span>
            <tui-icon icon="@tui.check" *ngIf="!saving"></tui-icon>
            {{ isEditMode ? 'Update' : 'Create' }} Rubric
          </button>
        </div>
      </header>

      <div class="form-layout" *ngIf="!loading">
        <!-- LEFT: Rubric Details -->
        <div class="form-left">
          <!-- Basic Info -->
          <div class="ui-section form-card">
            <div class="ui-section__head"><h2 class="ui-section__title">Basic Information</h2></div>
            <div class="section-body">
              <div class="mb-3">
                <label class="form-label">Rubric Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control" [(ngModel)]="form.name"
                       placeholder="e.g., Essay Writing Rubric">
              </div>
              <div class="mb-3">
                <label class="form-label">Description</label>
                <textarea class="form-control" rows="2" [(ngModel)]="form.description"
                          placeholder="Brief description of this rubric..."></textarea>
              </div>
              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label">Rubric Type <span class="text-danger">*</span></label>
                  <div class="rubric-type-selector">
                    <button *ngFor="let t of rubricTypes" class="type-option"
                            [class.selected]="form.rubric_type === t.value"
                            (click)="setRubricType(t.value)">
                      <tui-icon [icon]="t.icon"></tui-icon>
                      <span class="type-label">{{ t.label }}</span>
                      <span class="type-desc">{{ t.description }}</span>
                    </button>
                  </div>
                </div>
                <div class="col-md-6 mb-3">
                  <label class="form-label">Status</label>
                  <select class="form-select" [(ngModel)]="form.status">
                    <option value="draft">Draft</option>
                    <option value="active">Active</option>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <!-- Criteria -->
          <div class="ui-section form-card">
            <div class="ui-section__head">
              <h2 class="ui-section__title">Criteria ({{ form.criteria.length }})</h2>
              <button class="btn btn-sm btn-primary" (click)="addCriterion()">
                <tui-icon icon="@tui.plus"></tui-icon> Add Criterion
              </button>
            </div>
            <div class="section-body">
              <div class="criteria-list" *ngIf="form.criteria.length > 0">
                <div class="criterion-card" *ngFor="let c of form.criteria; let ci = index"
                     [class.collapsed]="c._collapsed">
                  <!-- Criterion Header -->
                  <div class="criterion-header" (click)="c._collapsed = !c._collapsed">
                    <div class="criterion-drag" title="Drag to reorder">
                      <tui-icon icon="@tui.grip-vertical"></tui-icon>
                    </div>
                    <span class="criterion-number">{{ ci + 1 }}</span>
                    <span class="criterion-title-display">{{ c.title || 'Untitled Criterion' }}</span>
                    <div class="criterion-badges">
                      <span class="ui-pill ui-pill--brand">{{ c.max_points }} pts</span>
                      <span class="ui-pill ui-pill--neutral" *ngIf="c.weight">{{ c.weight }}%</span>
                      <span class="ui-pill ui-pill--info">{{ c.levels.length }} levels</span>
                    </div>
                    <div class="criterion-actions" (click)="$event.stopPropagation()">
                      <button class="btn btn-sm btn-outline-secondary" (click)="moveCriterion(ci, -1)"
                              [disabled]="ci === 0" title="Move Up">
                        <tui-icon icon="@tui.arrow-up"></tui-icon>
                      </button>
                      <button class="btn btn-sm btn-outline-secondary" (click)="moveCriterion(ci, 1)"
                              [disabled]="ci === form.criteria.length - 1" title="Move Down">
                        <tui-icon icon="@tui.arrow-down"></tui-icon>
                      </button>
                      <button class="btn btn-sm btn-outline-secondary" (click)="duplicateCriterion(ci)" title="Duplicate">
                        <tui-icon icon="@tui.copy"></tui-icon>
                      </button>
                      <button class="btn btn-sm btn-outline-danger" (click)="removeCriterion(ci)" title="Remove">
                        <tui-icon icon="@tui.trash-2"></tui-icon>
                      </button>
                    </div>
                    <tui-icon [icon]="c._collapsed ? '@tui.chevron-down' : '@tui.chevron-up'" class="collapse-icon"></tui-icon>
                  </div>

                  <!-- Criterion Body -->
                  <div class="criterion-body" *ngIf="!c._collapsed">
                    <div class="row mb-3">
                      <div class="col-md-6">
                        <label class="form-label">Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control form-control-sm" [(ngModel)]="c.title"
                               placeholder="e.g., Content Quality">
                      </div>
                      <div class="col-md-3">
                        <label class="form-label">Max Points <span class="text-danger">*</span></label>
                        <input type="number" class="form-control form-control-sm" [(ngModel)]="c.max_points"
                               min="1" step="1" (input)="syncLevelPoints(c)">
                      </div>
                      <div class="col-md-3">
                        <label class="form-label">Weight (%)</label>
                        <input type="number" class="form-control form-control-sm" [(ngModel)]="c.weight"
                               min="0" max="100" step="5">
                      </div>
                    </div>
                    <div class="mb-3">
                      <label class="form-label">Description</label>
                      <textarea class="form-control form-control-sm" rows="2" [(ngModel)]="c.description"
                                placeholder="What is being evaluated..."></textarea>
                    </div>

                    <!-- Levels -->
                    <div class="levels-section">
                      <div class="levels-header">
                        <h6>Scoring Levels</h6>
                        <button class="btn btn-sm btn-primary" (click)="addLevel(c)">
                          <tui-icon icon="@tui.plus"></tui-icon> Add Level
                        </button>
                      </div>

                      <!-- Quick Level Templates -->
                      <div class="level-templates" *ngIf="c.levels.length === 0">
                        <span class="text-muted small me-2">Quick add:</span>
                        <button class="btn btn-sm btn-outline-secondary" (click)="applyLevelTemplate(c, '4-point')">
                          4-Point Scale
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" (click)="applyLevelTemplate(c, '3-point')">
                          3-Point Scale
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" (click)="applyLevelTemplate(c, 'pass-fail')">
                          Pass/Fail
                        </button>
                      </div>

                      <div class="levels-list" *ngIf="c.levels.length > 0">
                        <div class="level-item" *ngFor="let lvl of c.levels; let li = index">
                          <div class="level-row">
                            <span class="level-order">{{ li + 1 }}</span>
                            <input type="text" class="form-control form-control-sm" [(ngModel)]="lvl.label"
                                   placeholder="Level label (e.g., Excellent)">
                            <input type="number" class="form-control form-control-sm level-pts" [(ngModel)]="lvl.points"
                                   [min]="0" [max]="c.max_points" step="0.5" placeholder="Pts">
                            <button class="btn btn-sm btn-outline-danger" (click)="removeLevel(c, li)">
                              <tui-icon icon="@tui.x"></tui-icon>
                            </button>
                          </div>
                          <textarea class="form-control form-control-sm level-desc-input" rows="1"
                                    [(ngModel)]="lvl.description"
                                    placeholder="Describe what this level looks like..."></textarea>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Empty Criteria -->
              <div class="ui-state empty-criteria" *ngIf="form.criteria.length === 0">
                <span class="ui-state__icon"><i class="bi bi-layers"></i></span>
                <p>No criteria added yet</p>
                <button class="btn btn-primary btn-sm" (click)="addCriterion()">
                  <tui-icon icon="@tui.plus"></tui-icon> Add First Criterion
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- RIGHT: Summary / Preview -->
        <div class="form-right">
          <!-- Summary Card -->
          <div class="ui-section form-card summary-card">
            <div class="ui-section__head"><h2 class="ui-section__title">Summary</h2></div>
            <div class="section-body">
              <div class="summary-item">
                <span class="summary-label">Type</span>
                <span class="summary-value">{{ form.rubric_type | titlecase }}</span>
              </div>
              <div class="summary-item">
                <span class="summary-label">Criteria</span>
                <span class="summary-value">{{ form.criteria.length }}</span>
              </div>
              <div class="summary-item">
                <span class="summary-label">Total Points</span>
                <span class="summary-value fw-bold summary-accent">{{ getTotalPoints() }}</span>
              </div>
              <div class="summary-item">
                <span class="summary-label">Total Weight</span>
                <span class="summary-value" [class.text-danger]="getTotalWeight() !== 100 && getTotalWeight() > 0">
                  {{ getTotalWeight() }}%
                  <tui-icon icon="@tui.alert-circle" *ngIf="getTotalWeight() !== 100 && getTotalWeight() > 0"
                            class="text-danger ms-1"></tui-icon>
                </span>
              </div>
              <div class="summary-item">
                <span class="summary-label">Status</span>
                <span class="summary-value">
                  <span class="status-dot" [ngClass]="'dot-' + form.status"></span>
                  {{ form.status | titlecase }}
                </span>
              </div>
            </div>
          </div>

          <!-- Validation -->
          <div class="ui-section form-card validation-card" *ngIf="getValidationErrors().length > 0">
            <div class="ui-section__head"><h2 class="ui-section__title"><tui-icon icon="@tui.alert-triangle"></tui-icon> Issues</h2></div>
            <div class="section-body">
              <ul class="validation-list">
                <li *ngFor="let err of getValidationErrors()">{{ err }}</li>
              </ul>
            </div>
          </div>

          <!-- Quick Actions -->
          <div class="ui-section form-card">
            <div class="ui-section__head"><h2 class="ui-section__title">Quick Actions</h2></div>
            <div class="section-body">
              <button class="btn btn-sm btn-outline-secondary w-100 mb-2" (click)="distributeWeightsEvenly()">
                <tui-icon icon="@tui.percent"></tui-icon> Distribute Weights Evenly
              </button>
              <button class="btn btn-sm btn-outline-secondary w-100 mb-2" (click)="collapseAll()">
                <tui-icon icon="@tui.minimize-2"></tui-icon> Collapse All Criteria
              </button>
              <button class="btn btn-sm btn-outline-secondary w-100" (click)="expandAll()">
                <tui-icon icon="@tui.maximize-2"></tui-icon> Expand All Criteria
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Loading -->
      <app-skeleton *ngIf="loading" variant="form" [rows]="6"></app-skeleton>
    </div>
    </main>
  `,
  styles: [`
    /* Page chrome (header, sections, pills, states) comes from the global UI kit. */
    .ui-section__head { align-items: center; }
    .ui-section__title { display: flex; align-items: center; gap: 8px; }

    .form-layout { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 20px; align-items: start; }

    /* Rubric Type Selector */
    .rubric-type-selector { display: flex; flex-direction: column; gap: 0.5rem; }
    .type-option {
      display: flex; flex-wrap: wrap; gap: 0.375rem; align-items: center; padding: 0.625rem 0.75rem;
      border: 1px solid var(--ui-border, #e7e9e7); border-radius: var(--ui-radius-ctl, 8px); background: var(--ui-surface, #fff); cursor: pointer;
      text-align: left; transition: border-color .15s ease, background .15s ease; width: 100%;
    }
    .type-option:hover { border-color: rgba(var(--brand-primary-rgb), .45); background: var(--ui-surface-2, #f7f8f7); }
    .type-option.selected { border-color: var(--brand-primary); background: rgba(var(--brand-primary-rgb), .08); }
    .type-option.selected tui-icon { color: var(--brand-primary); }
    .type-label { font-weight: 500; font-size: 0.875rem; }
    .type-desc { width: 100%; font-size: 0.6875rem; color: var(--ui-text-muted, #6a716b); margin-left: 1.5rem; }

    /* Criteria */
    .criterion-card { border: 1px solid var(--ui-border, #e7e9e7); border-radius: var(--ui-radius-card, 12px); margin-bottom: 0.75rem; overflow: hidden; }
    .criterion-header {
      display: flex; align-items: center; gap: 0.5rem; padding: 0.625rem 0.75rem;
      background: var(--ui-surface-2, #f7f8f7); cursor: pointer; flex-wrap: wrap;
    }
    .criterion-drag { color: var(--ui-text-subtle, #8d948e); cursor: grab; }
    .criterion-number { background: rgba(var(--brand-primary-rgb), .12); color: var(--brand-primary); width: 22px; height: 22px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center; font-size: 0.6875rem; font-weight: 600; flex-shrink: 0; }
    .criterion-title-display { font-weight: 500; font-size: 0.875rem; flex: 1; min-width: 120px; }
    .criterion-badges { display: flex; gap: 0.25rem; flex-wrap: wrap; }
    .criterion-actions { display: flex; gap: 0.125rem; }
    .criterion-actions .btn { padding: 0.125rem 0.375rem; }
    .collapse-icon { color: var(--ui-text-muted, #6a716b); flex-shrink: 0; }
    .criterion-body { padding: 1rem; border-top: 1px solid var(--ui-border, #e7e9e7); }

    /* Levels */
    .levels-section { background: var(--ui-surface-2, #f7f8f7); border: 1px solid var(--ui-border, #e7e9e7); border-radius: var(--ui-radius-ctl, 8px); padding: 0.75rem; }
    .levels-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
    .levels-header h6 { margin: 0; font-size: 0.8125rem; font-weight: 600; }
    .level-templates { display: flex; align-items: center; gap: 0.375rem; flex-wrap: wrap; }
    .level-item { margin-bottom: 0.5rem; }
    .level-row { display: flex; gap: 0.375rem; align-items: center; margin-bottom: 0.25rem; }
    .level-order { width: 20px; text-align: center; font-size: 0.75rem; color: var(--ui-text-muted, #6a716b); font-weight: 600; flex-shrink: 0; }
    .level-pts { width: 80px !important; flex-shrink: 0; }
    .level-desc-input { margin-left: 20px; font-size: 0.8125rem; width: calc(100% - 20px); }

    /* Empty */
    .empty-criteria { padding: 2rem 1rem; }
    .empty-criteria p { margin: 10px 0 14px; }

    /* Summary */
    .summary-item { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0;
      border-bottom: 1px solid var(--ui-border, #e7e9e7); font-size: 0.8125rem; }
    .summary-item:first-child { padding-top: 0; }
    .summary-item:last-child { border-bottom: none; padding-bottom: 0; }
    .summary-label { color: var(--ui-text-muted, #6a716b); }
    .summary-value { font-weight: 500; display: flex; align-items: center; }
    .summary-accent { color: var(--brand-primary); }
    .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 0.375rem; }
    .dot-active { background: var(--ui-success, #2e7d4f); }
    .dot-draft { background: var(--ui-warning, #b25e09); }
    .dot-archived { background: var(--ui-neutral, #4b524c); }

    /* Validation */
    .validation-card { border-color: rgba(var(--ui-warning-rgb), .35); background: rgba(var(--ui-warning-rgb), .05); }
    .validation-card .ui-section__title { color: var(--ui-warning-text, #8f4b07); }
    .validation-list { margin: 0; padding-left: 1.25rem; }
    .validation-list li { font-size: 0.8125rem; color: var(--ui-warning-text, #8f4b07); margin-bottom: 0.25rem; }


    @media (max-width: 992px) {
      .form-layout { grid-template-columns: 1fr; }
      .form-right { order: -1; }
    }
  `]
})
export class RubricFormComponent implements OnInit, OnDestroy {
  private readonly assessments = inject(AssessmentsService);

  /** What went wrong, if anything. Rendered rather than swallowed. */
  error: string | null = null;
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private destroy$ = new Subject<void>();

  isEditMode = false;
  loading = false;
  saving = false;

  form: RubricFormData = {
    name: '',
    description: '',
    rubric_type: 'analytic',
    status: 'draft',
    criteria: []
  };

  rubricTypes = [
    { value: 'analytic', label: 'Analytic', icon: '@tui.grid', description: 'Score each criterion independently with separate levels' },
    { value: 'holistic', label: 'Holistic', icon: '@tui.circle', description: 'Single overall score based on general performance levels' },
    { value: 'checklist', label: 'Checklist', icon: '@tui.check-square', description: 'Yes/No criteria - each item is either met or not met' },
  ];

  ngOnInit(): void {
    this.route.params.pipe(takeUntil(this.destroy$)).subscribe(params => {
      const id = params['rubricId'];
      if (id && id !== 'new') {
        this.isEditMode = true;
        this.loadRubric(id);
      }
    });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // DATA
  // ==========================================

  loadRubric(rubricId: string): void {
    this.loading = true;
    this.error = null;

    this.assessments.rubric(rubricId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (rubric) => {
        this.form = toForm(rubric);
        this.loading = false;
      },
      error: (failure: ApiError) => {
        this.error = failure.message;
        this.loading = false;
      },
    });
  }

  /**
   * Saves the rubric and its criteria together.
   *
   * This has never worked. It posted to `${GlobalComponent}/user/grading` —
   * the class interpolated into a string rather than `GlobalComponent.baseURL`
   * — so every save went to `[object Object]/user/grading` and failed
   * silently, because the handler only acted on `res.code === 1`.
   *
   * Criteria go with the rubric in one request. Sending them separately
   * would let a marker open the rubric between the two and mark against
   * something half-saved.
   */
  saveRubric(): void {
    if (!this.isValid()) return;

    this.saving = true;
    this.error = null;

    const input = {
      name: this.form.name,
      description: this.form.description || null,
      kind: (this.form.rubric_type === 'holistic' ? 'holistic' : 'analytic') as RubricKind,
      isActive: this.form.status !== 'draft',
      // Position in the list is the order, so two criteria cannot claim
      // the same place.
      criteria: this.form.criteria.map((c) => ({
        title: c.title,
        description: c.description || null,
        maxPoints: c.max_points,
        levels: c.levels.map((l) => ({
          label: l.label,
          description: l.description || null,
          points: l.points,
        })),
      })),
    };

    const saved = this.form.rubric_id
      ? this.assessments.updateRubric(this.form.rubric_id, input)
      : this.assessments.createRubric(input);

    saved.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.saving = false;
        void this.router.navigate(['/admin/grading/rubrics']);
      },
      error: (failure: ApiError) => {
        this.saving = false;
        this.error = failure.message;
      },
    });
  }

  saveDraft(): void {
    this.form.status = 'draft';
    this.saveRubric();
  }

  // ==========================================
  // CRITERIA MANAGEMENT
  // ==========================================

  addCriterion(): void {
    this.form.criteria.push({
      title: '',
      description: '',
      max_points: 10,
      weight: 0,
      sort_order: this.form.criteria.length + 1,
      levels: [],
      _collapsed: false
    });
  }

  removeCriterion(index: number): void {
    this.form.criteria.splice(index, 1);
  }

  moveCriterion(index: number, direction: number): void {
    const newIndex = index + direction;
    if (newIndex < 0 || newIndex >= this.form.criteria.length) return;
    const item = this.form.criteria.splice(index, 1)[0];
    this.form.criteria.splice(newIndex, 0, item);
  }

  duplicateCriterion(index: number): void {
    const source = this.form.criteria[index];
    const clone: RubricCriterion = {
      title: source.title + ' (Copy)',
      description: source.description,
      max_points: source.max_points,
      weight: source.weight,
      sort_order: this.form.criteria.length + 1,
      levels: source.levels.map(l => ({ ...l, level_id: undefined })),
      _collapsed: false
    };
    this.form.criteria.splice(index + 1, 0, clone);
  }

  // ==========================================
  // LEVEL MANAGEMENT
  // ==========================================

  addLevel(criterion: RubricCriterion): void {
    const count = criterion.levels.length;
    criterion.levels.push({
      label: '',
      description: '',
      points: 0,
      sort_order: count + 1
    });
  }

  removeLevel(criterion: RubricCriterion, index: number): void {
    criterion.levels.splice(index, 1);
  }

  applyLevelTemplate(criterion: RubricCriterion, template: string): void {
    const max = criterion.max_points;
    switch (template) {
      case '4-point':
        criterion.levels = [
          { label: 'Excellent', description: 'Exceeds expectations', points: max, sort_order: 1 },
          { label: 'Good', description: 'Meets expectations', points: Math.round(max * 0.75), sort_order: 2 },
          { label: 'Fair', description: 'Partially meets expectations', points: Math.round(max * 0.5), sort_order: 3 },
          { label: 'Poor', description: 'Does not meet expectations', points: Math.round(max * 0.25), sort_order: 4 },
        ];
        break;
      case '3-point':
        criterion.levels = [
          { label: 'Proficient', description: 'Fully demonstrates the skill', points: max, sort_order: 1 },
          { label: 'Developing', description: 'Partially demonstrates the skill', points: Math.round(max * 0.5), sort_order: 2 },
          { label: 'Beginning', description: 'Does not yet demonstrate the skill', points: 0, sort_order: 3 },
        ];
        break;
      case 'pass-fail':
        criterion.levels = [
          { label: 'Pass', description: 'Criterion met', points: max, sort_order: 1 },
          { label: 'Fail', description: 'Criterion not met', points: 0, sort_order: 2 },
        ];
        break;
    }
  }

  syncLevelPoints(criterion: RubricCriterion): void {
    if (criterion.levels.length > 0 && criterion.levels[0].points !== criterion.max_points) {
      const ratio = criterion.max_points / (criterion.levels[0].points || 1);
      criterion.levels.forEach(l => l.points = Math.round(l.points * ratio));
    }
  }

  // ==========================================
  // TYPE
  // ==========================================

  setRubricType(type: string): void {
    this.form.rubric_type = type;
    if (type === 'checklist') {
      this.form.criteria.forEach(c => {
        if (c.levels.length === 0) {
          this.applyLevelTemplate(c, 'pass-fail');
        }
      });
    }
  }

  // ==========================================
  // HELPERS
  // ==========================================

  getTotalPoints(): number {
    return this.form.criteria.reduce((sum, c) => sum + (c.max_points || 0), 0);
  }

  getTotalWeight(): number {
    return this.form.criteria.reduce((sum, c) => sum + (c.weight || 0), 0);
  }

  distributeWeightsEvenly(): void {
    const count = this.form.criteria.length;
    if (count === 0) return;
    const weight = Math.floor(100 / count);
    this.form.criteria.forEach((c, i) => {
      c.weight = i === count - 1 ? 100 - (weight * (count - 1)) : weight;
    });
  }

  collapseAll(): void { this.form.criteria.forEach(c => c._collapsed = true); }
  expandAll(): void { this.form.criteria.forEach(c => c._collapsed = false); }

  isValid(): boolean {
    return this.getValidationErrors().length === 0;
  }

  getValidationErrors(): string[] {
    const errors: string[] = [];
    if (!this.form.name.trim()) errors.push('Rubric name is required');
    if (this.form.criteria.length === 0) errors.push('At least one criterion is required');
    this.form.criteria.forEach((c, i) => {
      if (!c.title.trim()) errors.push(`Criterion ${i + 1}: Title is required`);
      if (!c.max_points || c.max_points <= 0) errors.push(`Criterion ${i + 1}: Max points must be greater than 0`);
      if (c.levels.length === 0) errors.push(`Criterion ${i + 1}: At least one level is required`);
      c.levels.forEach((l, li) => {
        if (!l.label.trim()) errors.push(`Criterion ${i + 1}, Level ${li + 1}: Label is required`);
      });
    });
    const totalWeight = this.getTotalWeight();
    if (totalWeight > 0 && totalWeight !== 100) errors.push(`Weights must total 100% (currently ${totalWeight}%)`);
    return errors;
  }

  goBack(): void { this.router.navigate(['/admin/grading/rubrics']); }
}

// ---------------------------------------------------------------------------
// Translation
// ---------------------------------------------------------------------------

function toForm(r: Rubric): RubricFormData {
  return {
    rubric_id: r.id,
    name: r.name,
    description: r.description ?? '',
    rubric_type: r.kind,
    status: r.isActive ? 'active' : 'draft',
    criteria: r.criteria.map((c, position) => ({
      criterion_id: c.id,
      title: c.title,
      description: c.description ?? '',
      max_points: c.maxPoints,
      // Weighting is not modelled: what a criterion is worth is what its
      // bands are worth.
      weight: 1,
      sort_order: position + 1,
      levels: c.levels.map((l, band) => ({
        level_id: l.id,
        label: l.label,
        description: l.description ?? '',
        points: l.points,
        sort_order: band + 1,
      })),
    })),
  };
}
