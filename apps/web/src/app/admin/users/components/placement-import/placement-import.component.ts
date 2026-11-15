import { Component, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  UsersService,
  failedInviteEmails,
  type PlacementCommitResult,
  type PlacementPreview,
  type PlacementPreviewRow,
  type ImportPersonToCreate,
  type ImportPersonCannotCreate,
} from '../../../../services/users.service';
import { ApiError } from '../../../../core/api';
import { AdmintopComponent } from '../../../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../../../translate.pipe';
import { I18nService } from '../../../../i18n.service';
import { SelectComponent } from '../../../../shared/select/select.component';
import { ImportPeopleOptionsComponent, ImportPeopleTableComponent } from '../../../../shared/import-people/import-people.component';

type Step = 'upload' | 'review' | 'done';
type RowFilter = 'all' | 'changes' | 'unmatched' | 'error';
type PeoplePanel = 'create' | 'cannot' | null;

/**
 * Staff placement from a workbook: staff ID, department, job title, function
 * and the grade bands (job group, salary group, job category).
 *
 * Upload → Review → Done. The review is a dry run: every row's "old → new" is
 * shown, plus the departments, job titles, profile fields and dropdown options
 * that the commit would create. Commit re-sends the same file; the server
 * recomputes everything. People are matched by email; blank cells change
 * nothing. With "create people who aren't on the platform yet" (on by
 * default) unmatched rows with an email and a name become invited accounts —
 * no password — placed from the same row.
 */
@Component({
  selector: 'app-placement-import',
  standalone: true,
  imports: [
    CommonModule, FormsModule, RouterLink, AdmintopComponent, TranslatePipe, SelectComponent,
    ImportPeopleOptionsComponent, ImportPeopleTableComponent,
  ],
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

  /** Create invited accounts for unmatched rows (default on here; the API defaults off). */
  createMissing = true;
  /** Email the invitation to each person created now (default off). */
  sendInvites = false;
  /** Role for new people; the options block defaults it to the learner role. */
  roleId: string | null = null;
  peoplePanel: PeoplePanel = null;

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
    this.users.previewPlacement(file, this.sheet, this.createMissing).pipe(takeUntil(this.destroy$)).subscribe({
      next: (preview) => {
        this.working = false;
        this.preview = preview;
        this.sheet = preview.sheet ?? this.sheet;
        const sheets = (preview.sheets ?? []).filter((s) => s.kind === 'placement');
        this.sheetOptions = sheets.length > 1
          ? sheets.map((s) => ({ value: s.name, label: `${s.name} (${s.rows})` }))
          : [];
        if (this.step !== 'review' || this.filter === 'changes' || this.filter === 'all') {
          this.filter = this.changesCount > 0 ? 'changes' : 'all';
        }
        if (!this.createMissing) {
          this.peoplePanel = null;
        }
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
    return this.preview?.summary ?? { update: 0, unchanged: 0, unmatched: 0, error: 0, warnings: 0, create: 0 };
  }

  get visibleRows(): PlacementPreviewRow[] {
    switch (this.filter) {
      case 'changes': return this.rows.filter((r) => r.action === 'update' || this.willCreate(r));
      case 'unmatched': return this.rows.filter((r) => this.isUnmatched(r) && !this.willCreate(r));
      case 'error': return this.rows.filter((r) => r.action === 'error');
      default: return this.rows;
    }
  }

  // --- people who are not on the platform yet --------------------------------

  get toCreate(): ImportPersonToCreate[] {
    return Array.isArray(this.preview?.toCreate) ? this.preview!.toCreate! : [];
  }

  get cannotCreate(): ImportPersonCannotCreate[] {
    return Array.isArray(this.preview?.cannotCreate) ? this.preview!.cannotCreate! : [];
  }

  /** How many accounts the commit would create (distinct emails). */
  get createCount(): number {
    return this.preview?.totals?.toCreate ?? this.toCreate.length;
  }

  /**
   * The checkbox changed. The server plans new people differently (their rows
   * become "create" with the placement they will get, and their departments
   * and job titles count as new), so the preview is recomputed.
   */
  onCreateMissing(on: boolean): void {
    this.createMissing = on;
    if (this.step === 'review' && this.file && (this.preview?.createMissing ?? null) !== on) {
      this.read();
    }
  }

  private isUnmatched(r: PlacementPreviewRow): boolean {
    return r.action === 'unmatched' || r.action === 'create';
  }

  /** This row becomes a new account on commit. */
  willCreate(r: PlacementPreviewRow): boolean {
    return this.createMissing && this.isUnmatched(r)
      && (r.action === 'create' || this.toCreate.some((p) => p.row === r.row));
  }

  /** "Not found" means: not found and not going to be created. */
  get unmatchedCount(): number {
    return this.createMissing
      ? this.rows.filter((r) => this.isUnmatched(r) && !this.willCreate(r)).length
      : this.summary.unmatched;
  }

  togglePeople(panel: 'create' | 'cannot'): void {
    this.peoplePanel = this.peoplePanel === panel ? null : panel;
  }

  /** Rows the commit writes: updates plus (when creating) new people. */
  get changesCount(): number {
    return this.summary.update + this.rows.filter((r) => this.willCreate(r)).length;
  }

  /** People updated plus, when creating, people created. */
  get applyCount(): number {
    return this.summary.update + (this.createMissing ? this.createCount : 0);
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

  actionPill(r: PlacementPreviewRow): string {
    if (this.willCreate(r)) {
      return 'ui-pill--brand';
    }
    switch (r.action) {
      case 'update': return 'ui-pill--info';
      case 'unchanged': return 'ui-pill--neutral';
      case 'unmatched':
      case 'create': return 'ui-pill--warning';
      default: return 'ui-pill--danger';
    }
  }

  actionLabel(r: PlacementPreviewRow): string {
    if (this.willCreate(r)) {
      return 'people.create.will_be_created';
    }
    switch (r.action) {
      case 'update': return 'people.placement.to_update';
      case 'unchanged': return 'people.placement.unchanged';
      case 'unmatched':
      case 'create': return 'people.placement.not_found';
      default: return 'people.placement.errors';
    }
  }

  copyUnmatched(): void {
    const text = this.visibleRows.filter((r) => this.isUnmatched(r)).map((r) => r.email).join('\n');
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
    if (!file || this.working || this.applyCount === 0) {
      return;
    }
    this.working = true;
    this.error = null;
    const options = { createMissing: this.createMissing, sendInvites: this.sendInvites, roleId: this.roleId };
    this.users.commitPlacement(file, this.sheet, options).pipe(takeUntil(this.destroy$)).subscribe({
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

  /** Tooltip for the failed-invitations pill: the addresses, not user ids. */
  failedInvites(r: PlacementCommitResult): string {
    return failedInviteEmails(r);
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
    this.peoplePanel = null;
  }

  private errorText(e: unknown): string {
    if (e instanceof ApiError) {
      return e.violation('file') ?? e.violation('sheet') ?? e.violation('roleId') ?? e.message ?? this.i18n.t('plan.import.failed');
    }
    return this.i18n.t('plan.import.failed');
  }
}
