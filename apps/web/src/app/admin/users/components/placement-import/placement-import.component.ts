import { Component, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  UsersService,
  type PlacementCommitResult,
  type PlacementPreview,
  type PlacementPreviewRow,
} from '../../../../services/users.service';
import { ApiError } from '../../../../core/api';
import { AdmintopComponent } from '../../../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../../../translate.pipe';
import { I18nService } from '../../../../i18n.service';
import { SelectComponent } from '../../../../shared/select/select.component';

type Step = 'upload' | 'review' | 'done';
type RowFilter = 'all' | 'changes' | 'unmatched' | 'error';

/**
 * Staff placement from a workbook: staff ID, department, job title, function
 * and the grade bands (job group, salary group, job category).
 *
 * Upload → Review → Done. The review is a dry run: every row's "old → new" is
 * shown, plus the departments, job titles, profile fields and dropdown options
 * that the commit would create. Commit re-sends the same file; the server
 * recomputes everything. People are matched by email and never created; blank
 * cells change nothing.
 */
@Component({
  selector: 'app-placement-import',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent, TranslatePipe, SelectComponent],
  templateUrl: './placement-import.component.html',
  styleUrls: ['./placement-import.component.scss'],
})
export class PlacementImportComponent implements OnDestroy {
  private readonly users = inject(UsersService);
  private readonly i18n = inject(I18nService);
  private readonly destroy$ = new Subject<void>();

  step: Step = 'upload';
  file: File | null = null;
  sheet: string | null = null;
  sheetOptions: { value: string; label: string }[] = [];
  dragging = false;
  working = false;
  error: string | null = null;

  preview: PlacementPreview | null = null;
  result: PlacementCommitResult | null = null;

  filter: RowFilter = 'all';
  copied = false;

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // --- upload ----------------------------------------------------------------

  onDragOver(event: DragEvent): void {
    event.preventDefault();
    this.dragging = true;
  }

  onDragLeave(): void {
    this.dragging = false;
  }

  onDrop(event: DragEvent): void {
    event.preventDefault();
    this.dragging = false;
    this.accept(event.dataTransfer?.files?.[0]);
  }

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    this.accept(input.files?.[0]);
    input.value = '';
  }

  private accept(file: File | undefined): void {
    if (!file || this.working) {
      return;
    }
    this.file = file;
    this.sheet = null;
    this.sheetOptions = [];
    this.read();
  }

  /** A different placement sheet of the same workbook. */
  onSheet(sheet: string | null): void {
    if (!sheet || sheet === this.sheet) {
      return;
    }
    this.sheet = sheet;
    this.read();
  }

  private read(): void {
    const file = this.file;
    if (!file) {
      return;
    }
    this.working = true;
    this.error = null;
    this.users.previewPlacement(file, this.sheet).pipe(takeUntil(this.destroy$)).subscribe({
      next: (preview) => {
        this.working = false;
        this.preview = preview;
        this.sheet = preview.sheet ?? this.sheet;
        const sheets = (preview.sheets ?? []).filter((s) => s.kind === 'placement');
        this.sheetOptions = sheets.length > 1
          ? sheets.map((s) => ({ value: s.name, label: `${s.name} (${s.rows})` }))
          : [];
        this.filter = this.summary.update > 0 ? 'changes' : 'all';
        this.step = 'review';
      },
      error: (e: unknown) => {
        this.working = false;
        this.error = this.errorText(e);
      },
    });
  }

  // --- review ----------------------------------------------------------------

  get rows(): PlacementPreviewRow[] {
    return Array.isArray(this.preview?.rows) ? this.preview!.rows : [];
  }

  get summary(): PlacementPreview['summary'] {
    return this.preview?.summary ?? { update: 0, unchanged: 0, unmatched: 0, error: 0, warnings: 0 };
  }

  get visibleRows(): PlacementPreviewRow[] {
    switch (this.filter) {
      case 'changes': return this.rows.filter((r) => r.action === 'update');
      case 'unmatched': return this.rows.filter((r) => r.action === 'unmatched');
      case 'error': return this.rows.filter((r) => r.action === 'error');
      default: return this.rows;
    }
  }

  get newDepartments(): string[] {
    return this.preview?.lookupsToCreate?.department ?? [];
  }

  get newJobTitles(): string[] {
    return this.preview?.lookupsToCreate?.job_title ?? [];
  }

  get newFields(): PlacementPreview['customFieldsToCreate'] {
    return this.preview?.customFieldsToCreate ?? [];
  }

  get newOptions(): { key: string; values: string[] }[] {
    const o = this.preview?.optionsToAdd ?? {};
    return Object.entries(o)
      .filter(([, v]) => Array.isArray(v) && v.length > 0)
      .map(([key, values]) => ({ key, values: values as string[] }));
  }

  get willCreateAnything(): boolean {
    return this.newDepartments.length + this.newJobTitles.length + this.newFields.length + this.newOptions.length > 0;
  }

  /** Label for a custom-field key, preferring the translated placement label. */
  fieldLabel(key: string): string {
    const map: Record<string, string> = {
      job_group: 'people.placement.field_jobGroup',
      salary_group: 'people.placement.field_salaryGroup',
      job_category: 'people.placement.field_jobCategory',
      division: 'people.placement.field_division',
    };
    return map[key] ? this.i18n.t(map[key]) : key;
  }

  changeLabel(field: string, fallback: string): string {
    const key = 'people.placement.field_' + field;
    const t = this.i18n.t(key);
    return t && t !== key ? t : fallback;
  }

  actionPill(action: PlacementPreviewRow['action']): string {
    switch (action) {
      case 'update': return 'ui-pill--info';
      case 'unchanged': return 'ui-pill--neutral';
      case 'unmatched': return 'ui-pill--warning';
      default: return 'ui-pill--danger';
    }
  }

  actionLabel(action: PlacementPreviewRow['action']): string {
    switch (action) {
      case 'update': return 'people.placement.to_update';
      case 'unchanged': return 'people.placement.unchanged';
      case 'unmatched': return 'people.placement.not_found';
      default: return 'people.placement.errors';
    }
  }

  copyUnmatched(): void {
    const text = this.rows.filter((r) => r.action === 'unmatched').map((r) => r.email).join('\n');
    try {
      void navigator.clipboard.writeText(text).then(() => {
        this.copied = true;
        setTimeout(() => (this.copied = false), 2000);
      }, () => undefined);
    } catch {
      // No clipboard access; nothing else to do.
    }
  }

  // --- commit ----------------------------------------------------------------

  apply(): void {
    const file = this.file;
    if (!file || this.working || this.summary.update === 0) {
      return;
    }
    this.working = true;
    this.error = null;
    this.users.commitPlacement(file, this.sheet).pipe(takeUntil(this.destroy$)).subscribe({
      next: (result) => {
        this.working = false;
        this.result = result;
        this.step = 'done';
      },
      error: (e: unknown) => {
        this.working = false;
        this.error = this.errorText(e);
      },
    });
  }

  get failedRows(): PlacementCommitResult['rows'] {
    return this.result?.rows.filter((r) => r.outcome === 'error') ?? [];
  }

  get createdLookups(): number {
    const l = this.result?.lookupsCreated;
    return l ? (l.department ?? 0) + (l.job_title ?? 0) : 0;
  }

  restart(): void {
    this.step = 'upload';
    this.file = null;
    this.sheet = null;
    this.sheetOptions = [];
    this.preview = null;
    this.result = null;
    this.error = null;
  }

  private errorText(e: unknown): string {
    if (e instanceof ApiError) {
      return e.violation('file') ?? e.violation('sheet') ?? e.message ?? this.i18n.t('plan.import.failed');
    }
    return this.i18n.t('plan.import.failed');
  }
}
