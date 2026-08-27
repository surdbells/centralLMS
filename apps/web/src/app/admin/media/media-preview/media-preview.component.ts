import { Component, Inject, inject } from '@angular/core';
import { TuiDialogContext } from '@taiga-ui/core';
import { POLYMORPHEUS_CONTEXT } from '@taiga-ui/polymorpheus';
import { CommonModule } from '@angular/common';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { ScormAPI } from '../../../scorm-api';
import { MediaUploadComponent } from '../upload/media-upload.component';
import { MediaService, type MediaItem, type MediaKind } from '../../../services/media.service';
import { HotToastService } from '@ngneat/hot-toast';
import { ApiError } from '../../../core/api';

export interface MediaPreviewData {
  kind: MediaKind;
  title: string;
  id: string;
}

/**
 * Plays or shows one file, its history, and takes a replacement for it.
 *
 * The session blob is gone: this read `atob(sessionStorage.SESSION)` purely to
 * hand `token`, `id` and `tenant_id` down to the upload component as inputs,
 * which no longer wants them — the auth interceptor attaches the token.
 *
 * **The history was two rows of invented HTML** — v5.0 and v4.9, dated March
 * and February, credited to two named colleagues, with a Restore button
 * wired to nothing. It looked exactly like a working feature, which is worse
 * than an empty table: the whole point of keeping versions is being able to
 * answer "what did this say in March", and the screen answered confidently
 * with fiction. The columns are unchanged; what fills them is now the file's
 * actual chain, and Restore restores.
 */
@Component({
  selector: 'app-media-preview-component',
  templateUrl: './media-preview.component.html',
  styleUrls: ['./media-preview.component.css'],
  imports: [CommonModule, MediaUploadComponent],
  standalone: true,
})
export class MediaPreviewDialogComponent {
  private readonly media = inject(MediaService);
  private readonly toast = inject(HotToastService);

  public data!: MediaPreviewData;

  /**
   * The ticketed URL of the file, once the API has minted one.
   *
   * Null until then, so the preview shows nothing rather than an element
   * pointed at a URL that would come back 401.
   */
  public safeUrl: SafeResourceUrl | null = null;
  public url: string | null = null;

  uploadedFiles: MediaItem[] = [];
  isUploading = false;

  /** Every version of this file, newest first. */
  versions: MediaItem[] = [];
  loadingVersions = false;
  restoring: string | null = null;

  constructor(
    @Inject(POLYMORPHEUS_CONTEXT)
    private readonly context: TuiDialogContext<void, MediaPreviewData>,
    private readonly sanitizer: DomSanitizer,
  ) {
    this.data = this.context.data;

    // Both SCORM dialects, because a package may target either.
    const api = new ScormAPI();
    (window as unknown as Record<string, unknown>)['API'] = api; // SCORM 1.2
    (window as unknown as Record<string, unknown>)['API_1484_11'] = api; // SCORM 2004

    this.loadFile();
    this.loadVersions();
  }

  /**
   * Asks for a ticket, then points the player at it.
   *
   * Nothing can be shown before this answers: the browser sends no token
   * with a `src`, so an un-ticketed URL is a 401 and the preview is blank —
   * which is what every one of these dialogs used to show.
   */
  private loadFile(): void {
    this.media.fileUrl({ id: this.data.id }).subscribe({
      next: (url) => {
        this.url = url;
        this.safeUrl = this.sanitizer.bypassSecurityTrustResourceUrl(url);
      },
      error: (error: ApiError) => this.error_notification(error.message),
    });
  }

  // --- history ------------------------------------------------------------

  /**
   * Asked of whichever version is open; the API answers for the whole chain.
   */
  loadVersions(): void {
    this.loadingVersions = true;

    this.media.versions(this.data.id).subscribe({
      next: (versions) => {
        this.versions = versions;
        this.loadingVersions = false;
      },
      error: (error: ApiError) => {
        // Not silently: an empty history and a history that failed to load
        // look identical, and only one of them means the file has one version.
        this.loadingVersions = false;
        this.error_notification(error.message);
      },
    });
  }

  restore(version: MediaItem): void {
    this.restoring = version.id;

    this.media.restore(version.id).subscribe({
      next: (restored) => {
        this.restoring = null;
        this.success_notification(
          `v${version.versionNumber} is current again, as v${restored.versionNumber}.`,
        );
        this.loadVersions();
      },
      error: (error: ApiError) => {
        this.restoring = null;
        this.error_notification(error.message);
      },
    });
  }

  /** Opens one version in a new tab. Each needs its own ticket. */
  openVersion(version: MediaItem): void {
    this.media.openInNewTab(version).subscribe({
      error: (error: ApiError) => this.error_notification(error.message),
    });
  }

  iconFor(kind: MediaKind): string {
    return this.media.iconFor(kind);
  }

  labelFor(kind: MediaKind): string {
    return this.media.labelFor(kind);
  }

  formatFileSize(bytes: number): string {
    return this.media.formatFileSize(bytes);
  }

  openFile(item: MediaItem): void {
    this.media.openInNewTab(item).subscribe({
      error: (error: ApiError) => this.error_notification(error.message),
    });
  }

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
      // The upload was a new version of this file, so the history it was
      // added to is the one on the next tab.
      this.loadVersions();
    }
  }

  removeUploadedFile(index: number): void {
    this.uploadedFiles.splice(index, 1);
  }

  clearUploadedFiles(): void {
    this.uploadedFiles = [];
  }

  error_notification(message: string): void {
    this.toast.error(message);
  }

  success_notification(message: string): void {
    this.toast.success(message);
  }
}
