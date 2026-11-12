import { Component, ElementRef, HostListener, OnDestroy, OnInit, computed, inject, signal, viewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { HotToastService } from '@ngneat/hot-toast';
import { Subscription } from 'rxjs';
import { AdmintopComponent } from '../../../partials/top/admintop/admintop.component';
import { ApiClient, ApiError, Endpoints } from '../../../core/api';
import { LibraryViewerComponent } from '../library-viewer/library-viewer.component';
import {
  LibraryAudiencePickerComponent,
  type AudienceOption,
  type AudienceOptions,
} from '../library-audience-picker/library-audience-picker.component';
import { LibraryCategoriesComponent } from '../library-categories/library-categories.component';
import {
  LibraryService,
  type LibraryCategory,
  type LibraryItem,
  type LibraryKind,
  type AudienceType,
} from '../../library.service';
import {
  KIND_CLASSES,
  KIND_ICONS,
  KIND_LABEL_KEYS,
  LIBRARY_ACCEPT,
  LibraryUploadQueue,
  humanSize,
  uploadErrorMessage,
  type BatchSettings,
  type QueueEntry,
  type QueueStatus,
  type QueueSummary,
  type RejectedFile,
} from '../../library-upload';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';
import { ConfirmDialogService } from '../../../shared/components/confirmation-dialog/confirmation-dialog.component';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';

/**
 * Managing the digital library: what is shared, and sharing more.
 *
 * An administrator drops (or browses for) one or many files, gives the batch
 * a category and an audience — everyone, or a department, branch, team or
 * role — and they upload a few at a time with per-file progress. Office
 * documents are converted for viewing in the background, so a freshly
 * uploaded one shows as "preparing" until its PDF is ready.
 */
@Component({
  selector: 'app-library-manage',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    RouterLink,
    AdmintopComponent,
    LibraryViewerComponent,
    LibraryAudiencePickerComponent,
    LibraryCategoriesComponent,
    TranslatePipe,
    SkeletonComponent,
  ],
  templateUrl: './library-manage.component.html',
  styleUrl: './library-manage.component.scss',
})
export class LibraryManageComponent implements OnInit, OnDestroy {
  private readonly library = inject(LibraryService);
  private readonly api = inject(ApiClient);
  private readonly toast = inject(HotToastService);
  private readonly i18n = inject(I18nService);
  private readonly dialog = inject(ConfirmDialogService);

  readonly accept = LIBRARY_ACCEPT;
  private readonly fileInput = viewChild<ElementRef<HTMLInputElement>>('fileInput');

  readonly loading = signal(true);
  readonly loadError = signal(false);
  readonly items = signal<LibraryItem[]>([]);
  search = '';
  private listSub?: Subscription;

  // --- categories ---
  readonly categories = signal<LibraryCategory[]>([]);
  readonly uncategorisedCount = signal(0);
  readonly categoriesLoading = signal(true);
  readonly categoriesError = signal(false);
  /** null = everything, 'none' = uncategorised, otherwise a category id. */
  readonly categoryFilter = signal<string | null>(null);
  readonly showCategories = signal(false);
  readonly totalCount = computed(
    () => this.categories().reduce((sum, c) => sum + c.itemCount, 0) + this.uncategorisedCount(),
  );

  // --- the edit dialog ---
  readonly showForm = signal(false);
  readonly saving = signal(false);
  editing: LibraryItem | null = null;
  form = { title: '', description: '', categoryId: '', targetType: 'all' as AudienceType, targetIds: [] as string[] };

  // --- the batch upload ---
  readonly batchOpen = signal(false);
  readonly rejected = signal<RejectedFile[]>([]);
  /** Bumped whenever the queue changes, so computed views refresh. */
  readonly queueTick = signal(0);
  batch = { categoryId: '', targetType: 'all' as AudienceType, targetIds: [] as string[] };
  readonly queue = new LibraryUploadQueue(
    this.library,
    () => this.i18n.t('library.toast_upload_failed'),
    () => this.queueTick.update((n) => n + 1),
    (summary) => this.onBatchFinished(summary),
  );

  // --- drag and drop ---
  /** dragenter/dragleave fire for every child; a counter tells real leaves apart. */
  private dragDepth = 0;
  readonly pageDragging = signal(false);
  readonly zoneDragging = signal(false);

  // --- audience options, loaded once ---
  readonly audienceOptions = signal<AudienceOptions>({ role: [], department: [], branch: [], team: [] });

  readonly preview = signal<LibraryItem | null>(null);

  ngOnInit(): void {
    this.load();
    this.loadCategories();
    this.loadOptions();
  }

  ngOnDestroy(): void {
    this.destroyed = true;
    this.listSub?.unsubscribe();
    this.queue.cancelRemaining();
  }

  load(): void {
    this.loading.set(true);
    this.loadError.set(false);
    this.listSub?.unsubscribe();
    this.listSub = this.library.listManage(this.search.trim() || undefined, this.categoryFilter()).subscribe({
      next: (paged) => {
        this.items.set(paged.items);
        this.loading.set(false);
      },
      error: () => {
        this.toast.error(this.i18n.t('library.load_error'));
        this.loadError.set(true);
        this.loading.set(false);
      },
    });
  }

  loadCategories(): void {
    this.categoriesLoading.set(true);
    this.categoriesError.set(false);
    this.library.categories('all').subscribe({
      next: (list) => {
        this.categories.set(list.categories);
        this.uncategorisedCount.set(list.uncategorisedCount);
        this.categoriesLoading.set(false);
        // A filter on a category that no longer exists falls back to everything.
        const ids = new Set(list.categories.map((c) => c.id));
        const filter = this.categoryFilter();
        if (filter && filter !== 'none' && !ids.has(filter)) {
          this.setCategoryFilter(null);
        }
        // Nor may the batch or the edit form keep pointing at a deleted one.
        if (this.batch.categoryId && !ids.has(this.batch.categoryId)) {
          this.batch = { ...this.batch, categoryId: '' };
        }
        if (this.form.categoryId && !ids.has(this.form.categoryId)) {
          this.form = { ...this.form, categoryId: '' };
        }
      },
      error: () => {
        this.categoriesError.set(true);
        this.categoriesLoading.set(false);
      },
    });
  }

  /** Categories changed in the manager: counts, names and the list all refresh. */
  onCategoriesChanged(): void {
    this.loadCategories();
    this.load();
  }

  setCategoryFilter(filter: string | null): void {
    if (this.categoryFilter() === filter) {
      return;
    }
    this.categoryFilter.set(filter);
    this.load();
  }

  onSearchChange(): void {
    if (this.search === '') {
      this.load();
    }
  }

  clearFilters(): void {
    this.search = '';
    this.categoryFilter.set(null);
    this.load();
  }

  get filtered(): boolean {
    return this.search.trim() !== '' || this.categoryFilter() !== null;
  }

  private loadOptions(): void {
    this.api.get<AudienceOption[]>(Endpoints.roles.list).subscribe({
      next: (rows) => this.audienceOptions.update((o) => ({ ...o, role: rows ?? [] })),
      error: () => undefined,
    });

    for (const kind of ['department', 'branch', 'team'] as const) {
      this.api.get<AudienceOption[]>(Endpoints.lookups.active(kind)).subscribe({
        next: (rows) => this.audienceOptions.update((o) => ({ ...o, [kind]: rows ?? [] })),
        error: () => undefined,
      });
    }
  }

  // --- edit ---------------------------------------------------------------

  openEdit(item: LibraryItem): void {
    this.editing = item;
    this.form = {
      title: item.title,
      description: item.description ?? '',
      categoryId: item.categoryId ?? '',
      targetType: item.audience.type,
      targetIds: this.criteriaIds(item),
    };
    this.showForm.set(true);
  }

  closeForm(): void {
    if (this.saving()) {
      return;
    }
    this.showForm.set(false);
  }

  canSave(): boolean {
    if (!this.form.title.trim()) {
      return false;
    }
    return this.form.targetType === 'all' || this.form.targetIds.length > 0;
  }

  save(): void {
    if (!this.editing || !this.canSave()) {
      return;
    }
    this.saving.set(true);
    this.library
      .update(this.editing.id, {
        title: this.form.title.trim(),
        description: this.form.description.trim() || undefined,
        categoryId: this.form.categoryId || null,
        targetType: this.form.targetType,
        targetIds: this.form.targetIds,
      })
      .subscribe({
        next: () => {
          this.toast.success(this.i18n.t('library.toast_updated'));
          this.saving.set(false);
          this.showForm.set(false);
          this.load();
          this.loadCategories();
        },
        error: (err: unknown) => {
          this.toast.error(uploadErrorMessage(err, this.i18n.t('library.toast_save_failed')));
          this.saving.set(false);
          // The chosen category may have been deleted elsewhere.
          if (err instanceof ApiError && err.status === 422) {
            this.loadCategories();
          }
        },
      });
  }

  // --- choosing files -----------------------------------------------------

  /** Opens the system file picker. */
  browse(): void {
    const input = this.fileInput()?.nativeElement;
    if (input) {
      input.value = '';
      input.click();
    }
  }

  onFilesChosen(event: Event): void {
    const input = event.target as HTMLInputElement;
    const files = input.files ? Array.from(input.files) : [];
    input.value = '';
    this.addFiles(files);
  }

  /** Adds files to the batch and opens the batch panel. */
  addFiles(files: File[]): void {
    if (!files.length) {
      return;
    }
    if (this.queue.busy) {
      this.toast.info(this.i18n.t('library.wait_for_uploads'));
      return;
    }
    // A fresh batch after a finished one starts clean.
    if (!this.batchOpen()) {
      this.queue.reset();
      this.rejected.set([]);
      this.batch = { categoryId: this.defaultBatchCategory(), targetType: 'all', targetIds: [] };
    } else if (this.queue.started) {
      this.queue.clearFinished();
    }
    const refused = this.queue.add(files);
    this.rejected.update((r) => [...r, ...refused]);
    this.batchOpen.set(true);
  }

  /** New uploads go to the category being viewed, when it is a real one. */
  private defaultBatchCategory(): string {
    const filter = this.categoryFilter();
    return filter && filter !== 'none' ? filter : '';
  }

  // --- drag and drop ------------------------------------------------------

  private static hasFiles(event: DragEvent): boolean {
    return !!event.dataTransfer && Array.from(event.dataTransfer.types ?? []).includes('Files');
  }

  @HostListener('document:dragenter', ['$event'])
  onDocDragEnter(event: DragEvent): void {
    if (!LibraryManageComponent.hasFiles(event) || this.showForm()) {
      return;
    }
    event.preventDefault();
    this.dragDepth++;
    this.pageDragging.set(true);
  }

  @HostListener('document:dragover', ['$event'])
  onDocDragOver(event: DragEvent): void {
    if (!LibraryManageComponent.hasFiles(event)) {
      return;
    }
    // Without this the browser opens the file instead of letting it drop.
    event.preventDefault();
    if (event.dataTransfer) {
      event.dataTransfer.dropEffect = this.showForm() || this.queue.busy ? 'none' : 'copy';
    }
  }

  @HostListener('document:dragleave', ['$event'])
  onDocDragLeave(event: DragEvent): void {
    if (!LibraryManageComponent.hasFiles(event)) {
      return;
    }
    this.dragDepth = Math.max(0, this.dragDepth - 1);
    if (this.dragDepth === 0) {
      this.pageDragging.set(false);
      this.zoneDragging.set(false);
    }
  }

  @HostListener('document:drop', ['$event'])
  onDocDrop(event: DragEvent): void {
    if (!LibraryManageComponent.hasFiles(event)) {
      return;
    }
    event.preventDefault();
    this.dragDepth = 0;
    this.pageDragging.set(false);
    this.zoneDragging.set(false);
    if (this.showForm()) {
      return;
    }
    this.addFiles(Array.from(event.dataTransfer?.files ?? []));
  }

  // --- the batch ----------------------------------------------------------

  readonly batchView = computed(() => {
    this.queueTick();
    const q = this.queue;
    return {
      entries: [...q.entries],
      total: q.entries.length,
      queued: q.count('queued'),
      uploading: q.count('uploading'),
      done: q.count('done'),
      failed: q.count('failed'),
      cancelled: q.count('cancelled'),
      retryable: q.retryable.length,
      busy: q.busy,
      started: q.started,
      overall: q.overall,
    };
  });

  canStartBatch(): boolean {
    if (this.queue.busy || !this.queue.ready) {
      return false;
    }
    return this.batch.targetType === 'all' || this.batch.targetIds.length > 0;
  }

  startBatch(): void {
    if (!this.canStartBatch()) {
      return;
    }
    this.queue.start(this.batchSettings());
  }

  /** What the batch's pickers say right now. */
  private batchSettings(): BatchSettings {
    return {
      categoryId: this.batch.categoryId || null,
      targetType: this.batch.targetType,
      targetIds: this.batch.targetType === 'all' ? [] : this.batch.targetIds,
    };
  }

  /** Retries with the pickers' current values, so a fixed category or audience applies. */
  canRetry(): boolean {
    return !this.queue.busy && (this.batch.targetType === 'all' || this.batch.targetIds.length > 0);
  }

  retryFailed(): void {
    if (!this.canRetry()) {
      return;
    }
    this.queue.retryFailed(this.batchSettings());
  }

  cancelRemaining(): void {
    this.queue.cancelRemaining();
  }

  removeEntry(entry: QueueEntry): void {
    this.queue.remove(entry);
  }

  dismissRejected(index?: number): void {
    this.rejected.update((r) => (index === undefined ? [] : r.filter((_, i) => i !== index)));
  }

  async closeBatch(): Promise<void> {
    if (this.queue.busy) {
      return;
    }
    const pending = this.queue.count('queued');
    if (pending > 0 && !this.queue.started) {
      const ok = await this.dialog.warning(this.i18n.t('library.batch_discard_confirm', { count: pending }));
      if (!ok) {
        return;
      }
    }
    this.batchOpen.set(false);
    this.queue.reset();
    this.rejected.set([]);
  }

  private destroyed = false;

  private onBatchFinished(summary: QueueSummary): void {
    if (this.destroyed || summary.done + summary.failed + summary.cancelled === 0) {
      return;
    }
    const { done, failed, cancelled } = summary;
    const message = cancelled > 0
      ? this.i18n.t('library.batch_summary_cancelled', { done, failed, cancelled })
      : this.i18n.t('library.batch_summary', { done, failed });
    if (failed === 0 && cancelled === 0 && done > 0) {
      this.toast.success(message);
    } else if (done === 0) {
      this.toast.error(message);
    } else {
      this.toast.warning(message);
    }
    // A cancelled upload may still have been stored server-side, so refresh then too.
    if (done > 0 || cancelled > 0) {
      this.load();
      this.loadCategories();
    }
    // Everything went up: the panel has nothing left to say.
    if (failed === 0 && summary.cancelled === 0 && this.rejected().length === 0) {
      this.batchOpen.set(false);
      this.queue.reset();
    }
  }

  statusLabel(status: QueueStatus): string {
    return `library.upload_status_${status}`;
  }

  // --- leaving --------------------------------------------------------------

  /** Asked by the route's canDeactivate guard. */
  async canLeave(): Promise<boolean> {
    if (!this.queue.busy) {
      return true;
    }
    const ok = await this.dialog.warning(this.i18n.t('library.leave_while_uploading'), {
      confirmText: this.i18n.t('library.leave_anyway'),
    });
    if (ok) {
      this.destroyed = true;
      this.queue.cancelRemaining();
    }
    return ok;
  }

  @HostListener('window:beforeunload', ['$event'])
  onBeforeUnload(event: BeforeUnloadEvent): void {
    if (this.queue.busy) {
      event.preventDefault();
      event.returnValue = '';
    }
  }

  async remove(item: LibraryItem): Promise<void> {
    if (!(await this.dialog.confirm(this.i18n.t('library.remove_confirm', { title: item.title }), { type: 'danger' }))) {
      return;
    }
    this.library.delete(item.id).subscribe({
      next: () => {
        this.toast.success(this.i18n.t('library.toast_removed'));
        this.items.set(this.items().filter((x) => x.id !== item.id));
        this.loadCategories();
      },
      error: () => this.toast.error(this.i18n.t('library.toast_remove_failed')),
    });
  }

  private criteriaIds(item: LibraryItem): string[] {
    const values = Object.values(item.audience.criteria ?? {});
    return values.length ? (values[0] as string[]) : [];
  }

  icon(kind: LibraryKind): string {
    return KIND_ICONS[kind];
  }

  kindClass(kind: LibraryKind): string {
    return KIND_CLASSES[kind];
  }

  kindLabel(kind: LibraryKind): string {
    return KIND_LABEL_KEYS[kind];
  }

  conversionLabel(item: LibraryItem): string | null {
    switch (item.conversionStatus) {
      case 'pending':
      case 'processing': return this.i18n.t('library.status_preparing');
      case 'failed': return this.i18n.t('library.status_conversion_failed');
      default: return null;
    }
  }

  size(bytes: number): string {
    return humanSize(bytes);
  }
}
