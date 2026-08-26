import { Component, Inject, inject } from '@angular/core';
import { TuiDialogContext } from '@taiga-ui/core';
import { POLYMORPHEUS_CONTEXT } from '@taiga-ui/polymorpheus';
import { CommonModule } from '@angular/common';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { ScormAPI } from '../../../scorm-api';
import { MediaUploadComponent } from '../upload/media-upload.component';
import { MediaService, type MediaItem, type MediaKind } from '../../../services/media.service';
import { HotToastService } from '@ngneat/hot-toast';

export interface MediaPreviewData {
  kind: MediaKind;
  title: string;
  url: string;
  id: string;
}

/**
 * Plays or shows one file, and takes a replacement for it.
 *
 * The session blob is gone: this read `atob(sessionStorage.SESSION)` purely to
 * hand `token`, `id` and `tenant_id` down to the upload component as inputs,
 * which no longer wants them — the auth interceptor attaches the token.
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
  public safeUrl!: SafeResourceUrl;

  uploadedFiles: MediaItem[] = [];
  isUploading = false;

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

    this.safeUrl = this.sanitizer.bypassSecurityTrustResourceUrl(this.data.url);
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

  fileUrl(item: MediaItem): string {
    return this.media.fileUrl(item);
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
