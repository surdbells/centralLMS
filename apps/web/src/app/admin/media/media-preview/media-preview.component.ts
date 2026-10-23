import { Component, Inject, inject } from '@angular/core';
import { TuiDialogContext } from '@taiga-ui/core';
import { POLYMORPHEUS_CONTEXT } from '@taiga-ui/polymorpheus';
import { CommonModule } from '@angular/common';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { MediaUploadComponent } from '../upload/media-upload.component';
import { MediaService, type MediaItem, type MediaKind } from '../../../services/media.service';
import { HotToastService } from '@ngneat/hot-toast';
import { ApiError } from '../../../core/api';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';

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
  imports: [CommonModule, MediaUploadComponent, TranslatePipe],
  standalone: true,
})
export class MediaPreviewDialogComponent {
  private readonly media = inject(MediaService);
  private readonly toast = inject(HotToastService);
  private readonly i18n = inject(I18nService);

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

    // A SCORM package looks for this runtime on `window` as it loads. In the
    // preview — an administrator checking a package plays — nothing is tracked,
    // so a no-op that simply lets the content initialise is enough; the real
    // tracking runtime lives in the course player (ScormAdapterService).
    if (this.data.kind === 'scorm') {
      this.exposeNoopScormApi();
    }

    this.loadFile();
    this.loadVersions();
  }

  /** A do-nothing SCORM 1.2 / 2004 runtime, so a previewed package can start. */
  private exposeNoopScormApi(): void {
    const ok = (): string => 'true';
    const empty = (): string => '';

    const api: Record<string, (...args: string[]) => string> = {
      LMSInitialize: ok, LMSFinish: ok, LMSGetValue: empty, LMSSetValue: ok,
      LMSCommit: ok, LMSGetLastError: () => '0', LMSGetErrorString: empty, LMSGetDiagnostic: empty,
      Initialize: ok, Terminate: ok, GetValue: empty, SetValue: ok, Commit: ok,
      GetLastError: () => '0', GetErrorString: empty, GetDiagnostic: empty,
    };

    const w = window as unknown as Record<string, unknown>;
    w['API'] = api; // SCORM 1.2
    w['API_1484_11'] = api; // SCORM 2004
  }

  /**
   * Asks for a ticket, then points the player at it.
   *
   * Nothing can be shown before this answers: the browser sends no token
   * with a `src`, so an un-ticketed URL is a 401 and the preview is blank —
   * which is what every one of these dialogs used to show.
   */
  private loadFile(): void {
    // A SCORM package is played from its unpacked entry page, not streamed as
    // the zip (which just downloads). Everything else streams the file.
    const url$ = this.data.kind === 'scorm'
      ? this.media.scormLaunchUrl(this.data.id)
      : this.media.fileUrl({ id: this.data.id });

    url$.subscribe({
      next: (url) => {
        this.url = url;
        this.safeUrl = this.sanitizer.bypassSecurityTrustResourceUrl(url);
      },
      error: (error: { message?: string }) =>
        this.error_notification(error.message ?? this.i18n.t('admin.media_preview.opening')),
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
          this.i18n.t('admin.media_preview.toast_restored', { old: version.versionNumber, new: restored.versionNumber }),
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
    this.error_notification(this.i18n.t('admin.media.toast_upload_failed', { name: failure.file.name, error: failure.error }));
  }

  onAllUploadsComplete(items: MediaItem[]): void {
    this.isUploading = false;

    if (items.length > 0) {
      this.success_notification(this.i18n.t('admin.media.toast_uploaded', { count: items.length }));
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
