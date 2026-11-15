import { Component, EventEmitter, OnDestroy, Output, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { ApiError } from '../../../core/api';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';
import {
  LearningPlansService,
  PLAN_CATEGORY_ORDER,
  type ImportCommitResult,
  type ImportItemCounts,
  type ImportPreview,
  type ImportSheetPreview,
  type PlanCategory,
} from '../../../services/learning-plans.service';

type Step = 'upload' | 'review' | 'done';
type Panel = 'matched' | 'unmatched' | 'skipped' | 'dates' | 'warnings';

/**
 * Importing per-person learning plans from an Excel workbook.
 *
 * Upload → Review → Done. The review is a dry run on the server: nothing is
 * written until "Import", and the commit re-sends the same file (the server
 * keeps nothing between the two calls). One plan per sheet, matched by
 * year + title; people are matched by email and never created.
 */
@Component({
  selector: 'app-plan-import',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, TranslatePipe],
  templateUrl: './plan-import.component.html',
  styleUrls: ['./plan-import.component.scss'],
})
export class PlanImportComponent implements OnDestroy {
  private readonly svc = inject(LearningPlansService);
  private readonly i18n = inject(I18nService);
  private readonly destroy$ = new Subject<void>();

  /** The wizard was dismissed. */
  @Output() closed = new EventEmitter<void>();
  /** Something was written; the host should reload its plan list. */
  @Output() imported = new EventEmitter<ImportCommitResult>();
  /** "Open plan" on the result list. */
  @Output() openPlan = new EventEmitter<string>();

  readonly categoryOrder = PLAN_CATEGORY_ORDER;

  step: Step = 'upload';
  file: File | null = null;
  dragging = false;
  working = false;
  error: string | null = null;

  preview: ImportPreview | null = null;
  result: ImportCommitResult | null = null;

  /** Sheets ticked for import, by sheet name. */
  selected = new Set<string>();
  publish = false;
  removeMissing = false;

  /** Which collapsible table is open on each sheet card. */
  open: Record<string, Panel | null> = {};
  /** Per-sheet search over the people tables. */
  search: Record<string, string> = {};
  copied: string | null = null;

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
    this.read();
  }

  private read(): void {
    const file = this.file;
    if (!file) {
      return;
    }
    this.working = true;
    this.error = null;
    this.svc.previewImport(file).pipe(takeUntil(this.destroy$)).subscribe({
      next: (preview) => {
        this.working = false;
        this.preview = preview;
        this.selected = new Set(preview.sheets.filter((s) => s.kind === 'plan' && s.importable).map((s) => s.sheet));
        this.open = {};
        this.search = {};
        this.publish = false;
        this.removeMissing = false;
        this.step = 'review';
      },
      error: (e: unknown) => {
        this.working = false;
        this.error = this.errorText(e);
      },
    });
  }

  // --- review ----------------------------------------------------------------

  get planSheets(): ImportSheetPreview[] {
    return this.preview?.sheets.filter((s) => s.kind === 'plan') ?? [];
  }

  get otherSheets(): ImportSheetPreview[] {
    return this.preview?.sheets.filter((s) => s.kind !== 'plan') ?? [];
  }

  isSelected(sheet: ImportSheetPreview): boolean {
    return this.selected.has(sheet.sheet);
  }

  toggleSheet(sheet: ImportSheetPreview, on: boolean): void {
    const next = new Set(this.selected);
    if (on) {
      next.add(sheet.sheet);
    } else {
      next.delete(sheet.sheet);
    }
    this.selected = next;
  }

  /** Stale items across the ticked sheets — what "remove missing" would delete. */
  get staleSelected(): number {
    return this.planSheets.filter((s) => this.selected.has(s.sheet)).reduce((n, s) => n + (s.items?.stale ?? 0), 0);
  }

  toggle(sheet: ImportSheetPreview, panel: Panel): void {
    this.open = { ...this.open, [sheet.sheet]: this.open[sheet.sheet] === panel ? null : panel };
  }

  isOpen(sheet: ImportSheetPreview, panel: Panel): boolean {
    return this.open[sheet.sheet] === panel;
  }

  private term(sheet: ImportSheetPreview): string {
    return (this.search[sheet.sheet] ?? '').trim().toLowerCase();
  }

  filteredMatched(sheet: ImportSheetPreview): ImportSheetPreview['matched'] {
    const t = this.term(sheet);
    return t === '' ? sheet.matched : sheet.matched.filter((m) => this.hit(t, m.name, m.email));
  }

  filteredUnmatched(sheet: ImportSheetPreview): ImportSheetPreview['unmatched'] {
    const t = this.term(sheet);
    return t === '' ? sheet.unmatched : sheet.unmatched.filter((m) => this.hit(t, m.name, m.email));
  }

  private hit(term: string, ...values: (string | null | undefined)[]): boolean {
    return values.some((v) => (v ?? '').toLowerCase().includes(term));
  }

  /** Categories present on a sheet, in display order, with their counts. */
  categoriesOf(sheet: ImportSheetPreview): { key: PlanCategory; counts: ImportItemCounts; total: number }[] {
    const by = sheet.items?.byCategory ?? {};
    return this.categoryOrder
      .filter((c) => by[c] !== undefined)
      .map((c) => {
        const counts = by[c] as ImportItemCounts;
        return { key: c, counts, total: counts.create + counts.update + counts.unchanged };
      });
  }

  itemTotal(counts: ImportItemCounts): number {
    return counts.create + counts.update + counts.unchanged;
  }

  sheetReason(sheet: ImportSheetPreview): string {
    switch (sheet.kind) {
      case 'empty': return 'plan.import.sheet_empty';
      case 'placement': return 'plan.import.sheet_placement';
      default: return 'plan.import.sheet_not_plan';
    }
  }

  copyEmails(sheet: ImportSheetPreview): void {
    const text = sheet.unmatched.map((u) => u.email).join('\n');
    const done = (): void => {
      this.copied = sheet.sheet;
      setTimeout(() => { if (this.copied === sheet.sheet) { this.copied = null; } }, 2000);
    };
    try {
      void navigator.clipboard.writeText(text).then(done, () => undefined);
    } catch {
      // No clipboard access (insecure context); nothing else to do.
    }
  }

  // --- commit ----------------------------------------------------------------

  run(): void {
    const file = this.file;
    const sheets = this.planSheets.filter((s) => this.selected.has(s.sheet)).map((s) => s.sheet);
    if (!file || sheets.length === 0 || this.working) {
      if (sheets.length === 0) {
        this.error = this.i18n.t('plan.import.none_selected');
      }
      return;
    }
    this.working = true;
    this.error = null;
    this.svc.commitImport(file, sheets, this.publish, this.removeMissing && this.staleSelected > 0)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.working = false;
          this.result = result;
          this.step = 'done';
          this.imported.emit(result);
        },
        error: (e: unknown) => {
          this.working = false;
          this.error = this.errorText(e);
        },
      });
  }

  get writtenSheets(): ImportCommitResult['sheets'] {
    return this.result?.sheets.filter((s) => s.written !== undefined) ?? [];
  }

  // --- navigation --------------------------------------------------------------

  restart(): void {
    this.step = 'upload';
    this.file = null;
    this.preview = null;
    this.result = null;
    this.error = null;
    this.selected = new Set();
  }

  close(): void {
    this.closed.emit();
  }

  goToPlan(planId: string | null | undefined): void {
    if (planId) {
      this.openPlan.emit(planId);
    }
  }

  private errorText(e: unknown): string {
    if (e instanceof ApiError) {
      return e.violation('file') ?? e.violation('sheets') ?? e.message ?? this.i18n.t('plan.import.failed');
    }
    return this.i18n.t('plan.import.failed');
  }
}
