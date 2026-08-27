import { Component, inject, OnInit, TemplateRef, ViewChild } from '@angular/core';
import { SideComponent } from '../../partials/side/side.component';
import { TopComponent } from '../../partials/top/top.component';
import { TuiButton, TuiDialogService, TuiIcon, TuiLoader } from '@taiga-ui/core';
import { Router } from '@angular/router';
import { HotToastService } from '@ngneat/hot-toast';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { MediaUploadComponent } from './upload/media-upload.component';
import {
  MediaService,
  type MediaItem,
  type MediaKind,
  type MediaStatistics,
} from '../../services/media.service';
import { MediaPreviewDialogComponent } from './media-preview/media-preview.component';
import { PolymorpheusComponent } from '@taiga-ui/polymorpheus';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { ApiError, type PageMeta } from '../../core/api';

/**
 * The media library.
 *
 * First screen off `GlobalComponent` and `CrudService`, and the pattern for
 * the rest. Four things went:
 *
 *  1. **The session blob.** It read `atob(sessionStorage.SESSION)` and copied
 *     `id`, `tenant_id` and `token` into three separate request objects. The
 *     token is attached by the auth interceptor and the tenant comes from it,
 *     so none of that is the component's business.
 *  2. **The URL constants.** `GlobalComponent.MediaList` and friends were
 *     absolute production URLs pasted into a service that took `any`.
 *  3. **The envelope checks.** Every call tested
 *     `response.response_code === 200 && response.status === 'success'` and
 *     silently did nothing otherwise — so a failure left the spinner running
 *     for ever. Failures are now an `ApiError` with a message worth showing.
 *  4. **The mirrored storage figures.** `tenant_storage_response` declared a
 *     twenty-line nested literal with the shape of the legacy response,
 *     defaulting `total_files` to 5.
 */
@Component({
  selector: 'app-media',
  standalone: true,
  imports: [
    SideComponent,
    TopComponent,
    TuiIcon,
    CommonModule,
    FormsModule,
    MediaUploadComponent,
    TuiLoader,
    AdmintopComponent,
    TuiButton,
  ],
  templateUrl: './media.component.html',
  styleUrl: './media.component.css',
})
export class MediaComponent implements OnInit {
  private readonly dialogs = inject(TuiDialogService);
  private readonly media = inject(MediaService);
  private readonly router = inject(Router);
  private readonly toast = inject(HotToastService);

  @ViewChild('deleteDialog') deleteDialog!: TemplateRef<unknown>;

  mediaList: MediaItem[] = [];
  meta: PageMeta = { total: 0, page: 1, perPage: 8, totalPages: 0 };
  statistics: MediaStatistics | null = null;

  /** Set to upload a replacement rather than a new file. */
  replacesMediaId: string | null = null;
  uploadedFiles: MediaItem[] = [];
  isUploading = false;

  ui_controls = {
    is_loading: false,
    no_recent: false,
    viewing_dashboard: true,
    viewing_recent_orders: false,
    viewing_upload: false,
  };

  /** Whether a delete takes the whole version chain or just this one. */
  deleteAllVersions = false;

  /** The kind filter, empty for everything. */
  kind: MediaKind | '' = '';

  ngOnInit(): void {
    this.storageStatistics();
    this.list_media();
  }

  // --- reading ------------------------------------------------------------

  storageStatistics(): void {
    this.media.statistics().subscribe({
      next: (statistics) => (this.statistics = statistics),
      // Worth saying rather than leaving the header blank: the figure is the
      // storage somebody is paying for.
      error: (error: ApiError) => this.error_notification(error.message),
    });
  }

  list_media(page = 1, perPage = this.meta.perPage): void {
    this.ui_controls.is_loading = true;

    this.media.list({ page, perPage, kind: this.kind === '' ? undefined : this.kind }).subscribe({
      next: ({ items, meta }) => {
        this.mediaList = items;
        this.meta = meta;
        this.ui_controls.is_loading = false;
      },
      error: (error: ApiError) => {
        // The spinner stops either way. It used to run for ever on a failure.
        this.ui_controls.is_loading = false;
        this.error_notification(error.message);
      },
    });
  }

  paginate(): void {
    this.list_media(1, this.meta.perPage);
  }

  goToPage(page: number): void {
    this.list_media(page, this.meta.perPage);
  }

  // --- writing ------------------------------------------------------------

  delete_media(id: string): void {
    this.media.delete(id, this.deleteAllVersions).subscribe({
      next: () => {
        this.success_notification('The file was deleted.');
        this.list_media(this.meta.page);
        this.storageStatistics();
      },
      error: (error: ApiError) => this.error_notification(error.message),
    });
  }

  confirmDelete(id: string, title: string): void {
    this.dialogs
      .open<boolean>(this.deleteDialog, { label: `Are you sure you want to delete: ${title}` })
      .subscribe((confirmed: boolean) => {
        if (confirmed) {
          this.delete_media(id);
        }
      });
  }

  // --- uploads ------------------------------------------------------------

  onFileUploaded(item: MediaItem): void {
    this.uploadedFiles.push(item);
  }

  onUploadError(failure: { file: File; error: string }): void {
    this.error_notification(`Failed to upload ${failure.file.name}: ${failure.error}`);
  }

  onAllUploadsComplete(items: MediaItem[]): void {
    this.isUploading = false;

    if (items.length > 0) {
      this.success_notification(`${items.length} file(s) uploaded.`);
      // The library and the storage figure both moved.
      this.list_media(this.meta.page);
      this.storageStatistics();
    }
  }

  removeUploadedFile(index: number): void {
    this.uploadedFiles.splice(index, 1);
  }

  clearUploadedFiles(): void {
    this.uploadedFiles = [];
  }

  // --- presentation -------------------------------------------------------

  openPreview(item: MediaItem): void {
    this.dialogs
      .open(new PolymorpheusComponent(MediaPreviewDialogComponent), {
        // No URL: the dialog asks for its own ticket. Minting one here
        // would start the clock while somebody is still deciding whether
        // to open the file.
        data: {
          kind: item.kind,
          title: item.title,
          id: item.id,
        },
        size: 'page',
        closeable: true,
      })
      .subscribe();
  }

  iconFor(kind: MediaKind): string {
    return this.media.iconFor(kind);
  }

  /** Opens the file in a new tab, with a ticket that lets it be read. */
  openFile(item: MediaItem): void {
    this.media.openInNewTab(item).subscribe({
      error: (error: ApiError) => this.error_notification(error.message),
    });
  }

  labelFor(kind: MediaKind): string {
    return this.media.labelFor(kind);
  }

  formatFileSize(bytes: number): string {
    return this.media.formatFileSize(bytes);
  }

  /** How much of the allowance is gone, or null when there is no limit. */
  get usagePercentage(): number | null {
    const statistics = this.statistics;

    if (statistics === null || statistics.limitBytes === null || statistics.limitBytes === 0) {
      return null;
    }

    return Math.min(100, Math.round((statistics.bytesUsed / statistics.limitBytes) * 100));
  }

  countOf(kind: MediaKind): number {
    return this.statistics?.byKind[kind] ?? 0;
  }

  error_notification(message: string): void {
    this.toast.error(message);
  }

  success_notification(message: string): void {
    this.toast.success(message);
  }

  goBack(): void {
    void this.router.navigate(['/administration']);
  }
}
