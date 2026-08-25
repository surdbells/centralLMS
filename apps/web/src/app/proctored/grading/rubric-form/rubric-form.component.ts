// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 11
// Admin: Rubric Form Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { ActivatedRoute, Router } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { GlobalComponent } from '../../../global-component';

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
  level_id?: number;
  label: string;
  description: string;
  points: number;
  sort_order: number;
}

interface RubricCriterion {
  criterion_id?: number;
  title: string;
  description: string;
  max_points: number;
  weight: number;
  sort_order: number;
  levels: CriterionLevel[];
  _collapsed?: boolean;
}

interface RubricFormData {
  rubric_id?: number;
  name: string;
  description: string;
  rubric_type: string;
  status: string;
  criteria: RubricCriterion[];
}

@Component({
  selector: 'app-rubric-form',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon],
  template: `
    <div class="rubric-form-container">
      <!-- Header -->
      <div class="form-header">
        <div class="header-left">
          <button class="btn btn-sm btn-secondary me-2" (click)="goBack()">
            <tui-icon icon="@tui.arrow-left"></tui-icon>
          </button>
          <div>
            <h1 class="page-title">{{ isEditMode ? 'Edit Rubric' : 'Create Rubric' }}</h1>
            <p class="page-subtitle">{{ isEditMode ? 'Update rubric criteria and levels' : 'Define grading criteria for assessments' }}</p>
          </div>
        </div>
        <div class="header-actions">
          <button class="btn btn-secondary" (click)="saveDraft()" [disabled]="saving">
            <tui-icon icon="@tui.save"></tui-icon> Save Draft
          </button>
          <button class="btn btn-primary" (click)="saveRubric()" [disabled]="saving || !isValid()">
            <span class="spinner-border spinner-border-sm me-1" *ngIf="saving"></span>
            <tui-icon icon="@tui.check" *ngIf="!saving"></tui-icon>
            {{ isEditMode ? 'Update' : 'Create' }} Rubric
          </button>
        </div>
      </div>

      <div class="form-layout" *ngIf="!loading">
        <!-- LEFT: Rubric Details -->
        <div class="form-left">
          <!-- Basic Info -->
          <div class="form-card">
            <div class="card-header"><h5>Basic Information</h5></div>
            <div class="card-body">
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
          <div class="form-card">
            <div class="card-header">
              <h5>Criteria ({{ form.criteria.length }})</h5>
              <button class="btn btn-sm btn-primary" (click)="addCriterion()">
                <tui-icon icon="@tui.plus"></tui-icon> Add Criterion
              </button>
            </div>
            <div class="card-body">
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
                      <span class="badge bg-primary-subtle text-primary">{{ c.max_points }} pts</span>
                      <span class="badge bg-secondary-subtle text-secondary" *ngIf="c.weight">{{ c.weight }}%</span>
                      <span class="badge bg-info-subtle text-info">{{ c.levels.length }} levels</span>
                    </div>
                    <div class="criterion-actions" (click)="$event.stopPropagation()">
                      <button class="btn btn-sm btn-secondary" (click)="moveCriterion(ci, -1)"
                              [disabled]="ci === 0" title="Move Up">
                        <tui-icon icon="@tui.arrow-up"></tui-icon>
                      </button>
                      <button class="btn btn-sm btn-secondary" (click)="moveCriterion(ci, 1)"
                              [disabled]="ci === form.criteria.length - 1" title="Move Down">
                        <tui-icon icon="@tui.arrow-down"></tui-icon>
                      </button>
                      <button class="btn btn-sm btn-secondary" (click)="duplicateCriterion(ci)" title="Duplicate">
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
                        <button class="btn btn-sm btn-secondary" (click)="applyLevelTemplate(c, '4-point')">
                          4-Point Scale
                        </button>
                        <button class="btn btn-sm btn-secondary" (click)="applyLevelTemplate(c, '3-point')">
                          3-Point Scale
                        </button>
                        <button class="btn btn-sm btn-secondary" (click)="applyLevelTemplate(c, 'pass-fail')">
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
              <div class="empty-criteria" *ngIf="form.criteria.length === 0">
                <tui-icon icon="@tui.layers"></tui-icon>
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
          <div class="form-card summary-card">
            <div class="card-header"><h5>Summary</h5></div>
            <div class="card-body">
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
                <span class="summary-value fw-bold text-primary">{{ getTotalPoints() }}</span>
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
          <div class="form-card validation-card" *ngIf="getValidationErrors().length > 0">
            <div class="card-header"><h5><tui-icon icon="@tui.alert-triangle"></tui-icon> Issues</h5></div>
            <div class="card-body">
              <ul class="validation-list">
                <li *ngFor="let err of getValidationErrors()">{{ err }}</li>
              </ul>
            </div>
          </div>

          <!-- Quick Actions -->
          <div class="form-card">
            <div class="card-header"><h5>Quick Actions</h5></div>
            <div class="card-body">
              <button class="btn btn-sm btn-secondary w-100 mb-2" (click)="distributeWeightsEvenly()">
                <tui-icon icon="@tui.percent"></tui-icon> Distribute Weights Evenly
              </button>
              <button class="btn btn-sm btn-secondary w-100 mb-2" (click)="collapseAll()">
                <tui-icon icon="@tui.minimize-2"></tui-icon> Collapse All Criteria
              </button>
              <button class="btn btn-sm btn-secondary w-100" (click)="expandAll()">
                <tui-icon icon="@tui.maximize-2"></tui-icon> Expand All Criteria
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Loading -->
      <div class="loading-state" *ngIf="loading">
        <div class="spinner-border text-primary"></div>
        <p>Loading rubric...</p>
      </div>
    </div>
  `,
  styles: [`
    .rubric-form-container { padding: 1.5rem; }
    .form-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; }
    .header-left { display: flex; align-items: center; gap: 0; }
    .page-title { font-size: 1.25rem; font-weight: 700; margin: 0; }
    .page-subtitle { font-size: 0.8125rem; color: #6c757d; margin: 0; }
    .header-actions { display: flex; gap: 0.5rem; }

    .form-layout { display: grid; grid-template-columns: 1fr 300px; gap: 1.25rem; }
    .form-card { background: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem; margin-bottom: 1rem; }
    .card-header { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem;
      border-bottom: 1px solid #e9ecef; background: #f8f9fa; border-radius: 0.5rem 0.5rem 0 0; }
    .card-header h5 { margin: 0; font-size: 0.9375rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; }
    .card-body { padding: 1rem; }

    /* Rubric Type Selector */
    .rubric-type-selector { display: flex; flex-direction: column; gap: 0.5rem; }
    .type-option {
      display: flex; flex-wrap: wrap; gap: 0.375rem; align-items: center; padding: 0.625rem 0.75rem;
      border: 1px solid #dee2e6; border-radius: 0.375rem; background: #fff; cursor: pointer;
      text-align: left; transition: all 0.2s; width: 100%;
    }
    .type-option:hover { border-color: #0d6efd; background: #f8f9fa; }
    .type-option.selected { border-color: #0d6efd; background: #e8f0fe; }
    .type-label { font-weight: 500; font-size: 0.875rem; }
    .type-desc { width: 100%; font-size: 0.6875rem; color: #6c757d; margin-left: 1.5rem; }

    /* Criteria */
    .criterion-card { border: 1px solid #dee2e6; border-radius: 0.5rem; margin-bottom: 0.75rem; }
    .criterion-header {
      display: flex; align-items: center; gap: 0.5rem; padding: 0.625rem 0.75rem;
      background: #f8f9fa; border-radius: 0.5rem 0.5rem 0 0; cursor: pointer;
    }
    .criterion-card.collapsed .criterion-header { border-radius: 0.5rem; }
    .criterion-drag { color: #adb5bd; cursor: grab; }
    .criterion-number { background: #6c757d; color: #fff; width: 22px; height: 22px; border-radius: 50%;
      display: flex; align-items: center; justify-content: center; font-size: 0.6875rem; font-weight: 600; flex-shrink: 0; }
    .criterion-title-display { font-weight: 500; font-size: 0.875rem; flex: 1; }
    .criterion-badges { display: flex; gap: 0.25rem; }
    .criterion-badges .badge { font-size: 0.6875rem; }
    .criterion-actions { display: flex; gap: 0.125rem; }
    .criterion-actions .btn { padding: 0.125rem 0.375rem; }
    .collapse-icon { color: #6c757d; flex-shrink: 0; }
    .criterion-body { padding: 1rem; border-top: 1px solid #e9ecef; }

    /* Levels */
    .levels-section { background: #f8f9fa; border-radius: 0.375rem; padding: 0.75rem; }
    .levels-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
    .levels-header h6 { margin: 0; font-size: 0.8125rem; font-weight: 600; }
    .level-templates { display: flex; align-items: center; gap: 0.375rem; flex-wrap: wrap; }
    .level-item { margin-bottom: 0.5rem; }
    .level-row { display: flex; gap: 0.375rem; align-items: center; margin-bottom: 0.25rem; }
    .level-order { width: 20px; text-align: center; font-size: 0.75rem; color: #6c757d; font-weight: 600; flex-shrink: 0; }
    .level-pts { width: 80px !important; flex-shrink: 0; }
    .level-desc-input { margin-left: 20px; font-size: 0.8125rem; }

    /* Empty */
    .empty-criteria { text-align: center; padding: 2rem; }
    .empty-criteria tui-icon { font-size: 2rem; color: #adb5bd; margin-bottom: 0.5rem; }

    /* Summary */
    .summary-item { display: flex; justify-content: space-between; align-items: center; padding: 0.5rem 0;
      border-bottom: 1px solid #e9ecef; font-size: 0.8125rem; }
    .summary-item:last-child { border-bottom: none; }
    .summary-label { color: #6c757d; }
    .summary-value { font-weight: 500; display: flex; align-items: center; }
    .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; margin-right: 0.375rem; }
    .dot-active { background: #28a745; }
    .dot-draft { background: #ffc107; }
    .dot-archived { background: #6c757d; }

    /* Validation */
    .validation-card .card-header { background: #fff3cd; }
    .validation-list { margin: 0; padding-left: 1.25rem; }
    .validation-list li { font-size: 0.8125rem; color: #856404; margin-bottom: 0.25rem; }

    .loading-state { text-align: center; padding: 3rem; }

    @media (max-width: 992px) {
      .form-layout { grid-template-columns: 1fr; }
      .form-right { order: -1; }
    }
  `]
})
export class RubricFormComponent implements OnInit, OnDestroy {
  private http = inject(HttpClient);
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
        this.loadRubric(+id);
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

  loadRubric(rubricId: number): void {
    this.loading = true;
    const fd = new FormData();
    fd.append('action', 'get_rubric');
    fd.append('rubric_id', rubricId.toString());

    this.http.post<ApiResponse<RubricFormData>>(
      `${GlobalComponent.baseURL}/user/grading`, fd
    ).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        if (res.code === 1) this.form = res.data;
        this.loading = false;
      },
      error: () => { this.loading = false; }
    });
  }

  saveRubric(): void {
    if (!this.isValid()) return;
    this.saving = true;
    const fd = new FormData();
    fd.append('action', this.isEditMode ? 'update_rubric' : 'create_rubric');
    if (this.form.rubric_id) fd.append('rubric_id', this.form.rubric_id.toString());
    fd.append('name', this.form.name);
    fd.append('description', this.form.description);
    fd.append('rubric_type', this.form.rubric_type);
    fd.append('status', this.form.status);
    fd.append('criteria', JSON.stringify(this.form.criteria.map((c, i) => ({
      ...c, sort_order: i + 1,
      levels: c.levels.map((l, li) => ({ ...l, sort_order: li + 1 }))
    }))));

    this.http.post<ApiResponse<{ rubric_id: number }>>(
      `${GlobalComponent}/user/grading`, fd
    ).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        this.saving = false;
        if (res.code === 1) this.router.navigate(['/admin/rubrics']);
      },
      error: () => { this.saving = false; }
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

  goBack(): void { this.router.navigate(['/admin/rubrics']); }
}
