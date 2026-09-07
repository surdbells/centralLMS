import { Component, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  UsersService,
  type ExistingPolicy,
  type ImportPreview,
  type ImportResult,
  type ImportRow,
} from '../../../../services/users.service';
import { ApiError } from '../../../../core/api';
import { AdmintopComponent } from '../../../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../../../translate.pipe';

type Step = 'choose' | 'check' | 'done';

/**
 * Adding a lot of people at once.
 *
 * Three steps, and the middle one is the point: the file is read, and what
 * would happen to every row is shown before anything is written.
 *
 * Nothing is held on the server between the check and the import — this screen
 * sends back the rows it was shown. The version this replaces relied on the
 * upload sitting in the PHP session, so two administrators importing at the
 * same time overwrote each other and a preview left open over lunch imported
 * nobody.
 */
@Component({
  selector: 'app-user-import',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent, TranslatePipe],
  templateUrl: './user-import.component.html',
  styles: [`
    .drop-zone { border: 2px dashed var(--bs-border-color); border-radius: .5rem; cursor: pointer; }
    .drop-zone.dragging { border-color: var(--bs-primary); background: var(--bs-primary-bg-subtle); }
    .mono { font-family: var(--bs-font-monospace); font-size: .75rem; }
  `],
})
export class UserImportComponent implements OnDestroy {
  private readonly usersService = inject(UsersService);
  private readonly router = inject(Router);
  private readonly destroy$ = new Subject<void>();

  step: Step = 'choose';

  file: File | null = null;
  dragging = false;

  preview: ImportPreview | null = null;
  result: ImportResult | null = null;

  onExisting: ExistingPolicy = 'skip';
  /** Rows with a problem are hidden by default once there are many of them. */
  showOnly: 'all' | 'error' | 'warning' = 'all';

  working = false;
  error: string | null = null;

  /** The columns a file may have, shown before anybody uploads one. */
  readonly columns = [
    { name: 'email', required: true, note: 'admin.user_import.note_email' },
    { name: 'firstName', required: true, note: 'admin.user_import.note_firstname' },
    { name: 'lastName', required: true, note: 'admin.user_import.note_lastname' },
    { name: 'phone', required: false, note: '' },
    { name: 'branch', required: false, note: 'admin.user_import.note_branch' },
    { name: 'department', required: false, note: 'admin.user_import.note_matched_by_name' },
    { name: 'team', required: false, note: 'admin.user_import.note_matched_by_name' },
    { name: 'level', required: false, note: 'admin.user_import.note_matched_by_name' },
    { name: 'jobTitle', required: false, note: 'admin.user_import.note_jobtitle' },
    { name: 'manager', required: false, note: 'admin.user_import.note_manager' },
    { name: 'hireDate', required: false, note: 'admin.user_import.note_hiredate' },
    { name: 'roles', required: false, note: 'admin.user_import.note_roles' },
  ];

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // --- choosing a file ----------------------------------------------------

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
    this.accept((event.target as HTMLInputElement).files?.[0]);
  }

  private accept(file: File | undefined): void {
    if (file === undefined) {
      return;
    }

    this.file = file;
    this.error = null;
    this.check();
  }

  // --- checking it --------------------------------------------------------

  check(): void {
    const file = this.file;

    if (file === null) {
      return;
    }

    this.working = true;
    this.error = null;

    this.usersService
      .importPreview(file)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (preview) => {
          this.working = false;
          this.preview = preview;
          this.step = 'check';
        },
        error: (error: ApiError) => {
          this.working = false;
          // A file with no address column comes back naming that, rather than
          // as "import failed".
          this.error = error.violation('file') ?? error.message;
        },
      });
  }

  // --- importing ----------------------------------------------------------

  /**
   * Only the rows that would work are sent.
   *
   * The server refuses the rest anyway; sending them would produce a result
   * screen mostly full of failures somebody had already been shown.
   */
  get importable(): ImportRow[] {
    return this.preview?.rows.filter((row) => row.action !== 'error') ?? [];
  }

  get visibleRows(): ImportRow[] {
    const rows = this.preview?.rows ?? [];

    return this.showOnly === 'error'
      ? rows.filter((r) => r.action === 'error')
      : this.showOnly === 'warning'
        ? rows.filter((r) => r.warnings.length > 0)
        : rows;
  }

  runImport(): void {
    const rows = this.importable;

    if (rows.length === 0) {
      return;
    }

    this.working = true;
    this.error = null;

    this.usersService
      .import(rows.map((row) => row.values), this.onExisting)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.working = false;
          this.result = result;
          this.step = 'done';
        },
        error: (error: ApiError) => {
          this.working = false;
          this.error = error.message;
        },
      });
  }

  startOver(): void {
    this.step = 'choose';
    this.file = null;
    this.preview = null;
    this.result = null;
    this.error = null;
    this.showOnly = 'all';
  }

  done(): void {
    void this.router.navigate(['/admin/users']);
  }

  // --- presentation -------------------------------------------------------

  firstViolation(row: ImportRow): string {
    return Object.values(row.violations)[0] ?? '';
  }

  actionClass(action: ImportRow['action']): string {
    return action === 'error'
      ? 'bg-danger-subtle text-danger'
      : action === 'existing'
        ? 'bg-info-subtle text-info'
        : 'bg-success-subtle text-success';
  }

  actionLabel(row: ImportRow): string {
    if (row.action === 'error') {
      return 'admin.user_import.cannot_import';
    }

    return row.action === 'existing'
      ? (this.onExisting === 'update' ? 'admin.user_import.will_update' : 'admin.user_import.will_skip')
      : 'admin.user_import.will_create';
  }

  outcomeClass(outcome: string): string {
    const classes: Record<string, string> = {
      created: 'bg-success',
      updated: 'bg-primary',
      skipped: 'bg-secondary',
      error: 'bg-danger',
    };

    return classes[outcome] ?? 'bg-secondary';
  }

  trackByLine(_index: number, row: { line: number }): number {
    return row.line;
  }
}
