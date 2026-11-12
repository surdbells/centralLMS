import { Component, ElementRef, inject, input, output, signal, viewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { HotToastService } from '@ngneat/hot-toast';
import { ApiError } from '../../../core/api';
import { LibraryService, type LibraryCategory } from '../../library.service';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';
import { ConfirmDialogService } from '../../../shared/components/confirmation-dialog/confirmation-dialog.component';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';

/**
 * The library's categories, managed from a side panel on the manage page:
 * add, rename inline, reorder and delete. Deleting one leaves its files
 * uncategorised rather than removing them.
 */
@Component({
  selector: 'app-library-categories',
  standalone: true,
  imports: [FormsModule, TranslatePipe, SkeletonComponent],
  templateUrl: './library-categories.component.html',
  styleUrl: './library-categories.component.scss',
})
export class LibraryCategoriesComponent {
  private readonly library = inject(LibraryService);
  private readonly toast = inject(HotToastService);
  private readonly i18n = inject(I18nService);
  private readonly dialog = inject(ConfirmDialogService);

  readonly categories = input<LibraryCategory[]>([]);
  readonly uncategorisedCount = input(0);
  readonly loading = input(false);
  readonly error = input(false);

  /** Something changed — the parent reloads categories (and items). */
  readonly changed = output<void>();
  readonly closed = output<void>();
  readonly retry = output<void>();

  private readonly addInput = viewChild<ElementRef<HTMLInputElement>>('addInput');

  newName = '';
  readonly adding = signal(false);
  readonly addError = signal<string | null>(null);

  readonly editingId = signal<string | null>(null);
  editName = '';
  readonly renameError = signal<string | null>(null);
  readonly busyId = signal<string | null>(null);

  add(): void {
    const name = this.newName.trim();
    if (!name || this.adding()) {
      this.addError.set(name ? null : this.i18n.t('library.category_name_required'));
      return;
    }
    this.addError.set(null);
    this.adding.set(true);
    const nextOrder = this.categories().reduce((max, c) => Math.max(max, c.sortOrder), 0) + 10;
    this.library.createCategory({ name, sortOrder: nextOrder }).subscribe({
      next: () => {
        this.adding.set(false);
        this.newName = '';
        this.toast.success(this.i18n.t('library.category_added'));
        this.changed.emit();
        setTimeout(() => this.addInput()?.nativeElement.focus());
      },
      error: (err: unknown) => {
        this.adding.set(false);
        this.addError.set(this.message(err));
      },
    });
  }

  startRename(category: LibraryCategory): void {
    this.editingId.set(category.id);
    this.editName = category.name;
    this.renameError.set(null);
    setTimeout(() => (document.getElementById('lib-cat-rename-' + category.id) as HTMLInputElement | null)?.select());
  }

  cancelRename(): void {
    this.editingId.set(null);
    this.renameError.set(null);
  }

  saveRename(category: LibraryCategory): void {
    const name = this.editName.trim();
    if (!name) {
      this.renameError.set(this.i18n.t('library.category_name_required'));
      return;
    }
    if (name === category.name) {
      this.cancelRename();
      return;
    }
    this.busyId.set(category.id);
    this.library.updateCategory(category.id, { name }).subscribe({
      next: () => {
        this.busyId.set(null);
        this.editingId.set(null);
        this.toast.success(this.i18n.t('library.category_renamed'));
        this.changed.emit();
      },
      error: (err: unknown) => {
        this.busyId.set(null);
        this.renameError.set(this.message(err));
      },
    });
  }

  /** Swaps a category with its neighbour; the order is rewritten in steps of 10. */
  move(index: number, delta: -1 | 1): void {
    const list = [...this.categories()];
    const target = index + delta;
    if (target < 0 || target >= list.length || this.busyId()) {
      return;
    }
    [list[index], list[target]] = [list[target], list[index]];
    const changes = list
      .map((c, i) => ({ c, order: (i + 1) * 10 }))
      .filter(({ c, order }) => c.sortOrder !== order);
    if (!changes.length) {
      return;
    }
    this.busyId.set(list[target].id);
    let pending = changes.length;
    let failed = false;
    for (const { c, order } of changes) {
      this.library.updateCategory(c.id, { sortOrder: order }).subscribe({
        next: () => undefined,
        error: () => {
          failed = true;
          if (--pending === 0) this.afterReorder(failed);
        },
        complete: () => {
          if (--pending === 0) this.afterReorder(failed);
        },
      });
    }
  }

  private afterReorder(failed: boolean): void {
    this.busyId.set(null);
    if (failed) {
      this.toast.error(this.i18n.t('library.category_reorder_failed'));
    }
    this.changed.emit();
  }

  async remove(category: LibraryCategory): Promise<void> {
    const message = category.itemCount > 0
      ? this.i18n.t('library.category_delete_confirm_items', { name: category.name, count: category.itemCount })
      : this.i18n.t('library.category_delete_confirm', { name: category.name });
    if (!(await this.dialog.danger(message, { title: this.i18n.t('library.category_delete_title') }))) {
      return;
    }
    this.busyId.set(category.id);
    this.library.deleteCategory(category.id).subscribe({
      next: () => {
        this.busyId.set(null);
        this.toast.success(this.i18n.t('library.category_deleted'));
        this.changed.emit();
      },
      error: (err: unknown) => {
        this.busyId.set(null);
        this.toast.error(this.message(err));
      },
    });
  }

  private message(err: unknown): string {
    if (err instanceof ApiError) {
      if (err.isConflict) {
        return this.i18n.t('library.category_duplicate');
      }
      const raw = err.details['violations'] as Record<string, unknown> | undefined;
      const name = raw?.['name'];
      if (Array.isArray(name) && name.length) return String(name[0]);
      if (typeof name === 'string' && name) return name;
    }
    return this.i18n.t('library.category_save_failed');
  }
}
