import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { HotToastService } from '@ngneat/hot-toast';
import {
  CustomFieldsService,
  type CustomFieldDefinition,
  type CustomFieldInput,
  type CustomFieldType,
} from '../../services/custom-fields.service';
import { ApiError } from '../../core/api';
import { SessionStore } from '../../core/auth/session.store';
import { I18nService } from '../../i18n.service';
import { ConfirmDialogService } from '../../shared/components/confirmation-dialog/confirmation-dialog.component';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../translate.pipe';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';

/** The draft a tenant's custom field is edited as. */
interface FieldDraft {
  label: string;
  type: CustomFieldType;
  options: string[];
  required: boolean;
  isActive: boolean;
  sortOrder: number;
}

@Component({
  selector: 'app-custom-fields',
  standalone: true,
  imports: [CommonModule, FormsModule, AdmintopComponent, TranslatePipe, SkeletonComponent],
  template: `
    <app-admintop></app-admintop>
    <main class="main">
        <div class="ui-page custom-fields">
          <header class="ui-page-header">
            <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-sliders"></i></div>
            <div class="ui-page-header__titles">
              <h1>{{ 'admin.custom_fields.title' | translate }}</h1>
              <p>{{ 'admin.custom_fields.intro' | translate }}</p>
            </div>
            <div class="ui-page-header__actions" *ngIf="canManage && !editing && !loading">
              <button type="button" class="btn btn-sm btn-primary" (click)="startCreate()">
                <i class="bi bi-plus-lg me-1"></i>{{ 'admin.custom_fields.add' | translate }}
              </button>
            </div>
          </header>

          <div *ngIf="loading" class="row g-4">
            <div class="col-lg-7">
              <section class="ui-section mb-0"><app-skeleton variant="list" [rows]="5"></app-skeleton></section>
            </div>
          </div>

          <div class="alert alert-danger" *ngIf="error"><i class="bi bi-exclamation-triangle me-2"></i>{{ error }}</div>

          <div class="row g-4" *ngIf="!loading">
            <!-- Existing fields -->
            <div class="col-lg-7">
              <section class="ui-section ui-section--flush mb-0">
                <div class="ui-section__head">
                  <h2 class="ui-section__title"><i class="bi bi-card-list me-2"></i>{{ 'admin.custom_fields.defined' | translate }}</h2>
                  <span class="ui-pill ui-pill--neutral">{{ fields.length }}</span>
                </div>
                <div *ngIf="fields.length === 0" class="p-3">
                  <div class="ui-state">
                    <span class="ui-state__icon"><i class="bi bi-sliders"></i></span>
                    <p>{{ 'admin.custom_fields.none' | translate }}</p>
                    <button *ngIf="canManage && !editing" type="button" class="btn btn-sm btn-primary" (click)="startCreate()">
                      <i class="bi bi-plus-lg me-1"></i>{{ 'admin.custom_fields.add' | translate }}
                    </button>
                  </div>
                </div>
                <div class="field-list" *ngIf="fields.length">
                  <div *ngFor="let f of fields" class="field-item" [class.opacity-50]="!f.isActive">
                    <div class="flex-grow-1 min-w-0">
                      <div class="field-item__name">
                        {{ f.label }}
                        <span class="ui-pill ui-pill--neutral ms-1 text-lowercase">{{ f.type }}</span>
                        <span *ngIf="f.required" class="ui-pill ui-pill--warning ms-1">{{ 'admin.custom_fields.required' | translate }}</span>
                        <span *ngIf="!f.isActive" class="ui-pill ms-1">{{ 'admin.custom_fields.inactive' | translate }}</span>
                      </div>
                      <small class="ui-muted font-monospace">{{ f.key }}</small>
                      <small class="ui-muted d-block" *ngIf="f.type === 'dropdown' && f.options?.length">
                        {{ f.options?.join(' · ') }}
                      </small>
                    </div>
                    <div class="d-flex gap-1" *ngIf="canManage">
                      <button type="button" class="btn btn-sm btn-outline-secondary" (click)="startEdit(f)" [title]="'common.edit' | translate">
                        <i class="bi bi-pencil"></i>
                      </button>
                      <button type="button" class="btn btn-sm btn-outline-danger" (click)="remove(f)" [title]="'common.remove' | translate">
                        <i class="bi bi-trash3"></i>
                      </button>
                    </div>
                  </div>
                </div>
              </section>
            </div>

            <!-- Create / edit form -->
            <div class="col-lg-5" *ngIf="canManage && editing">
              <section class="ui-section mb-0">
                <div class="ui-section__head">
                  <h2 class="ui-section__title">
                    <i class="bi me-2" [ngClass]="editingId ? 'bi-pencil' : 'bi-plus-circle'"></i>
                    {{ (editingId ? 'admin.custom_fields.edit' : 'admin.custom_fields.add') | translate }}
                  </h2>
                </div>
                  <div class="mb-3">
                    <label class="form-label small fw-semibold">{{ 'admin.custom_fields.label' | translate }} <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" [(ngModel)]="draft.label" name="label"
                           placeholder="{{ 'admin.custom_fields.label_placeholder' | translate }}">
                  </div>
                  <div class="mb-3">
                    <label class="form-label small fw-semibold">{{ 'admin.custom_fields.type' | translate }}</label>
                    <select class="form-select" [(ngModel)]="draft.type" name="type" [disabled]="editingId !== null">
                      <option value="text">{{ 'admin.custom_fields.type_text' | translate }}</option>
                      <option value="number">{{ 'admin.custom_fields.type_number' | translate }}</option>
                      <option value="date">{{ 'admin.custom_fields.type_date' | translate }}</option>
                      <option value="dropdown">{{ 'admin.custom_fields.type_dropdown' | translate }}</option>
                      <option value="boolean">{{ 'admin.custom_fields.type_boolean' | translate }}</option>
                    </select>
                    <small class="ui-muted" *ngIf="editingId !== null">{{ 'admin.custom_fields.type_locked' | translate }}</small>
                  </div>

                  <!-- Dropdown options -->
                  <div class="mb-3" *ngIf="draft.type === 'dropdown'">
                    <label class="form-label small fw-semibold">{{ 'admin.custom_fields.options' | translate }}</label>
                    <div class="d-flex gap-2 mb-2" *ngFor="let opt of draft.options; let i = index; trackBy: trackByIndex">
                      <input type="text" class="form-control" [(ngModel)]="draft.options[i]" [name]="'opt' + i">
                      <button type="button" class="btn btn-sm btn-outline-danger" (click)="removeOption(i)"><i class="bi bi-x"></i></button>
                    </div>
                    <button type="button" class="btn btn-sm btn-outline-secondary" (click)="addOption()">
                      <i class="bi bi-plus-lg me-1"></i>{{ 'admin.custom_fields.add_option' | translate }}
                    </button>
                  </div>

                  <div class="form-check mb-2">
                    <input type="checkbox" class="form-check-input" id="cfRequired" [(ngModel)]="draft.required" name="required">
                    <label class="form-check-label small" for="cfRequired">{{ 'admin.custom_fields.required_label' | translate }}</label>
                  </div>
                  <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="cfActive" [(ngModel)]="draft.isActive" name="isActive">
                    <label class="form-check-label small" for="cfActive">{{ 'admin.custom_fields.active_label' | translate }}</label>
                  </div>

                  <div class="d-flex gap-2">
                    <button type="button" class="btn btn-primary btn-sm" (click)="save()" [disabled]="saving || !draft.label.trim()">
                      <span *ngIf="saving" class="spinner-border spinner-border-sm me-1"></span>
                      {{ 'common.save' | translate }}
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" (click)="cancel()">{{ 'common.cancel' | translate }}</button>
                  </div>
                  <div class="alert alert-danger py-1 small mt-2 mb-0" *ngIf="formError">{{ formError }}</div>
              </section>
            </div>
          </div>
        </div>
    </main>
  `,
  styles: [`
    .min-w-0 { min-width: 0; }
    .ui-section--flush > .ui-section__head { align-items: center; padding-bottom: 14px; margin-bottom: 0;
      border-bottom: 1px solid var(--ui-border); }
    .field-item { display: flex; align-items: center; gap: 12px; padding: 12px 22px; border-bottom: 1px solid var(--ui-border); }
    .field-item:last-child { border-bottom: 0; }
    .field-item__name { font-weight: 600; font-size: .875rem; color: var(--ui-text); margin-bottom: 2px; }
    @media (max-width: 575.98px) { .field-item { padding: 12px 16px; } }
  `],
})
/**
 * Where an administrator defines the profile fields this organisation keeps
 * beyond the fixed set.
 *
 * The key is derived from the label on creation and then fixed — the type is
 * locked once a field exists too, because changing either would strand the
 * values already stored under it.
 */
export class CustomFieldsComponent implements OnInit, OnDestroy {
  private readonly service = inject(CustomFieldsService);
  private readonly session = inject(SessionStore);
  private readonly i18n = inject(I18nService);
  private readonly toast = inject(HotToastService);
  private readonly dialog = inject(ConfirmDialogService);
  private readonly destroy$ = new Subject<void>();

  fields: CustomFieldDefinition[] = [];
  loading = true;
  error = '';

  canManage = false;

  editing = false;
  editingId: string | null = null;
  draft: FieldDraft = this.emptyDraft();
  saving = false;
  formError = '';

  ngOnInit(): void {
    this.canManage = this.session.can()('user.manage');
    this.load();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  private load(): void {
    this.loading = true;
    this.service
      .list()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (fields) => {
          this.fields = fields;
          this.loading = false;
        },
        error: (e: ApiError) => {
          this.error = e.message;
          this.loading = false;
        },
      });
  }

  startCreate(): void {
    this.editingId = null;
    this.draft = this.emptyDraft();
    this.formError = '';
    this.editing = true;
  }

  startEdit(field: CustomFieldDefinition): void {
    this.editingId = field.id;
    this.draft = {
      label: field.label,
      type: field.type,
      options: [...(field.options ?? [])],
      required: field.required,
      isActive: field.isActive,
      sortOrder: field.sortOrder,
    };
    this.formError = '';
    this.editing = true;
  }

  cancel(): void {
    this.editing = false;
    this.editingId = null;
    this.formError = '';
  }

  /** Track option rows by index so editing one does not rebuild its input (and drop focus). */
  trackByIndex(index: number): number {
    return index;
  }

  addOption(): void {
    this.draft.options.push('');
  }

  removeOption(index: number): void {
    this.draft.options.splice(index, 1);
  }

  save(): void {
    const input: CustomFieldInput = {
      label: this.draft.label.trim(),
      type: this.draft.type,
      required: this.draft.required,
      isActive: this.draft.isActive,
      sortOrder: this.draft.sortOrder,
    };

    if (this.draft.type === 'dropdown') {
      input.options = this.draft.options.map((o) => o.trim()).filter((o) => o !== '');
    }

    this.saving = true;
    this.formError = '';

    const request = this.editingId === null
      ? this.service.create(input)
      : this.service.update(this.editingId, input);

    request.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.saving = false;
        this.editing = false;
        this.editingId = null;
        this.toast.success(this.i18n.t('admin.custom_fields.saved'));
        this.load();
      },
      error: (e: ApiError) => {
        this.saving = false;
        // Field by field where the server said which; the first is the one to fix.
        this.formError = Object.values(e.violations).flat()[0] ?? e.message;
      },
    });
  }

  async remove(field: CustomFieldDefinition): Promise<void> {
    const confirmed = await this.dialog.confirm(
      this.i18n.t('admin.custom_fields.confirm_delete', { label: field.label }),
      { type: 'danger' },
    );

    if (!confirmed) {
      return;
    }

    this.service
      .delete(field.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.toast.success(this.i18n.t('admin.custom_fields.deleted'));
          this.load();
        },
        error: (e: ApiError) => this.toast.error(e.message),
      });
  }

  private emptyDraft(): FieldDraft {
    return { label: '', type: 'text', options: [], required: false, isActive: true, sortOrder: 0 };
  }
}
