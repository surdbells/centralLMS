import { Component, OnInit, OnDestroy, Input, Output, EventEmitter, ViewChild, ElementRef, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  MediaService,
  type MediaItem,
  type MediaKind,
  type MediaStatistics,
  type UploadDetails,
  type UploadProgress,
} from '../../../services/media.service';
import { ApiError } from '../../../core/api';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';
import { HotToastService } from '@ngneat/hot-toast';

interface QueuedFile {
  id: string;
  file: File;
  kind: MediaKind;
  preview?: string;
  progress: number;
  status: 'pending' | 'uploading' | 'extracting' | 'success' | 'error';
  response?: MediaItem;
  error?: string;
  details: UploadDetails;
}

@Component({
  selector: 'app-media-upload',
  standalone: true,
  imports: [CommonModule, FormsModule, TranslatePipe],
  templateUrl: './media-upload.component.html',
  styleUrl: './media-upload.component.scss'
})
export class MediaUploadComponent implements OnInit, OnDestroy {
  @ViewChild('fileInput') fileInput!: ElementRef<HTMLInputElement>;

  private readonly i18n = inject(I18nService);
  private readonly toast = inject(HotToastService);

  // No credentials here any more: the auth interceptor attaches the bearer
  // token. This component used to be handed `token`, `userId` and `tenantId`
  // by its parent and paste them into the FormData.

  /** Set to replace an existing file rather than add a new one. */
  @Input() replacesMediaId: string | null = null;
  @Input() multiple: boolean = true;
  /** Narrow what this particular picker accepts. Empty means anything the API takes. */
  @Input() acceptedTypes: MediaKind[] = [];
  @Input() maxFiles: number = 10;

  // Outputs
  @Output() uploadComplete = new EventEmitter<MediaItem>();
  @Output() uploadError = new EventEmitter<{ file: File; error: string }>();
  @Output() allUploadsComplete = new EventEmitter<MediaItem[]>();

  // Component state
  isDragOver = false;
  fileQueue: QueuedFile[] = [];
  isUploading = false;
  showConfigModal = false;
  selectedFile: QueuedFile | null = null;

  // Version upload form
  versionLabel: string = '';
  versionNotes: string = '';
  fileTitle: string = '';
  fileDescription: string = '';

  private destroy$ = new Subject<void>();

  constructor(public mediaService: MediaService) {}

  /** What the API will accept, fetched from the code that enforces it. */
  accepts: MediaStatistics['accepts'] = {} as MediaStatistics['accepts'];

  ngOnInit(): void {
    this.mediaService
      .acceptedTypes()
      .pipe(takeUntil(this.destroy$))
      .subscribe((accepts) => (this.accepts = accepts));
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  /**
   * Handle drag over event
   */
  onDragOver(event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();
    this.isDragOver = true;
  }

  /**
   * Handle drag leave event
   */
  onDragLeave(event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();
    this.isDragOver = false;
  }

  /**
   * Handle file drop
   */
  onDrop(event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();
    this.isDragOver = false;

    const files = event.dataTransfer?.files;
    if (files) {
      this.handleFiles(Array.from(files));
    }
  }

  /**
   * Handle file input change
   */
  onFileSelect(event: Event): void {
    const input = event.target as HTMLInputElement;
    if (input.files) {
      this.handleFiles(Array.from(input.files));
      input.value = ''; // Reset input
    }
  }

  /**
   * Open file browser
   */
  openFileBrowser(): void {
    this.fileInput.nativeElement.click();
  }

  /**
   * Process selected files
   */
  handleFiles(files: File[]): void {
    // Check max files limit
    if (this.fileQueue.length + files.length > this.maxFiles) {
      this.toast.error(this.i18n.t('admin.media_upload.max_files', { max: this.maxFiles }));
      return;
    }

    for (const file of files) {
      // Checked against the rules the API just gave us. It checks again, from
      // the extension rather than the mime type the browser reports.
      const validation = this.mediaService.validate(file, this.accepts);

      if (!validation.valid || validation.kind === undefined) {
        this.uploadError.emit({ file, error: validation.error ?? this.i18n.t('admin.media_upload.cannot_upload') });
        continue;
      }

      const kind = validation.kind;

      // A picker may be narrowed further than the API allows — a course
      // thumbnail wants an image, not a SCORM package.
      if (this.acceptedTypes.length > 0 && !this.acceptedTypes.includes(kind)) {
        this.uploadError.emit({
          file,
          error: this.i18n.t('admin.media_upload.not_accepted', { kind: this.mediaService.labelFor(kind) }),
        });
        continue;
      }

      const queuedFile: QueuedFile = {
        id: this.generateId(),
        file,
        kind,
        progress: 0,
        status: 'pending',
        details: {
          // The filename without its extension, which is what somebody
          // uploading twenty files at once would have typed anyway.
          title: file.name.replace(/\.[^/.]+$/, ''),
        },
      };

      if (kind === 'video') {
        this.generateVideoThumbnail(file).then(preview => {
          queuedFile.preview = preview;
        });
      }

      this.fileQueue.push(queuedFile);
    }
  }

  /**
   * Generate video thumbnail
   */
  private generateVideoThumbnail(file: File): Promise<string> {
    return new Promise((resolve) => {
      const video = document.createElement('video');
      video.preload = 'metadata';
      video.src = URL.createObjectURL(file);
      video.muted = true;

      video.onloadeddata = () => {
        video.currentTime = 1; // Seek to 1 second
      };

      video.onseeked = () => {
        const canvas = document.createElement('canvas');
        canvas.width = 160;
        canvas.height = 90;
        const ctx = canvas.getContext('2d');
        ctx?.drawImage(video, 0, 0, canvas.width, canvas.height);
        resolve(canvas.toDataURL());
        URL.revokeObjectURL(video.src);
      };

      video.onerror = () => {
        resolve('');
      };
    });
  }

  /** Opens an uploaded file in a new tab, with a ticket that lets it be read. */
  openUploaded(item: MediaItem): void {
    this.mediaService.openInNewTab(item).subscribe({
      error: () => this.uploadError.emit({
        file: new File([], item.originalFilename),
        error: this.i18n.t('admin.media_upload.could_not_open'),
      }),
    });
  }

  /**
   * Open config modal for a file
   */
  openConfig(queuedFile: QueuedFile): void {
    this.selectedFile = queuedFile;
    this.fileTitle = queuedFile.details.title || '';
    this.fileDescription = queuedFile.details.description || '';
    this.versionLabel = queuedFile.details.versionLabel || '';
    this.versionNotes = queuedFile.details.versionNotes || '';
    this.showConfigModal = true;
  }

  /**
   * Save config and close modal
   */
  saveConfig(): void {
    if (this.selectedFile) {
      this.selectedFile.details = {
        ...this.selectedFile.details,
        title: this.fileTitle,
        description: this.fileDescription,
        versionLabel: this.versionLabel,
        versionNotes: this.versionNotes,
      };
    }
    this.closeConfigModal();
  }

  /**
   * Close config modal
   */
  closeConfigModal(): void {
    this.showConfigModal = false;
    this.selectedFile = null;
    this.fileTitle = '';
    this.fileDescription = '';
    this.versionLabel = '';
    this.versionNotes = '';
  }

  /**
   * Remove file from queue
   */
  removeFile(queuedFile: QueuedFile): void {
    const index = this.fileQueue.findIndex(f => f.id === queuedFile.id);
    if (index > -1) {
      this.fileQueue.splice(index, 1);
    }
  }

  /**
   * Clear all files from queue
   */
  clearQueue(): void {
    this.fileQueue = this.fileQueue.filter(f => f.status === 'uploading');
  }

  /**
   * Start uploading all pending files
   */
  startUpload(): void {
    const pendingFiles = this.fileQueue.filter(f => f.status === 'pending');
    if (pendingFiles.length === 0) return;

    this.isUploading = true;
    this.uploadNextFile();
  }

  /**
   * Upload next pending file
   */
  private uploadNextFile(): void {
    const nextFile = this.fileQueue.find(f => f.status === 'pending');

    if (!nextFile) {
      this.isUploading = false;
      const successfulUploads = this.fileQueue
        .filter(f => f.status === 'success' && f.response)
        .map(f => f.response!);

      if (successfulUploads.length > 0) {
        this.allUploadsComplete.emit(successfulUploads);
      }
      return;
    }

    nextFile.status = 'uploading';

    // Credentials are not passed in: the auth interceptor attaches the token.
    const upload = this.replacesMediaId === null
      ? this.mediaService.upload(nextFile.file, nextFile.details)
      : this.mediaService.uploadVersion(this.replacesMediaId, nextFile.file, nextFile.details);

    upload
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (event: UploadProgress<MediaItem>) => {
          if (event.state === 'uploading') {
            nextFile.progress = event.progress;
            return;
          }

          if (event.state === 'done' && event.result) {
            nextFile.response = event.result;
            nextFile.progress = 100;
            this.uploadComplete.emit(event.result);

            if (nextFile.kind === 'scorm') {
              // The zip is stored, but a SCORM package is only playable once the
              // worker has unpacked it — wait on screen and show the result.
              this.awaitScormExtraction(nextFile);
            } else {
              nextFile.status = 'success';
            }

            this.uploadNextFile();
          }
        },
        // A refusal is already an ApiError, with the reason the API gave.
        error: (error: ApiError) => {
          nextFile.status = 'error';
          nextFile.error = error.message || this.i18n.t('admin.media_upload.upload_failed');
          this.uploadError.emit({ file: nextFile.file, error: nextFile.error });
          this.uploadNextFile();
        },
      });
  }

  /**
   * Waits on screen for a SCORM package to finish unpacking.
   *
   * The upload is already done; this polls the worker's progress and settles
   * the queued file on the parsed result (entry file + version) or the failure.
   * The rest of the queue keeps uploading meanwhile.
   */
  private awaitScormExtraction(queuedFile: QueuedFile): void {
    queuedFile.status = 'extracting';

    const id = queuedFile.response?.id;

    if (id === undefined) {
      queuedFile.status = 'success';

      return;
    }

    this.mediaService
      .pollScormExtraction(id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (ready) => {
          queuedFile.response = ready;
          queuedFile.status = 'success';
        },
        error: (error: { message?: string }) => {
          queuedFile.status = 'error';
          queuedFile.error = error.message ?? this.i18n.t('admin.media_upload.upload_failed');
          this.uploadError.emit({ file: queuedFile.file, error: queuedFile.error });
        },
      });
  }

  /**
   * Retry failed upload
   */
  retryUpload(queuedFile: QueuedFile): void {
    queuedFile.status = 'pending';
    queuedFile.progress = 0;
    queuedFile.error = undefined;

    if (!this.isUploading) {
      this.startUpload();
    }
  }

  iconFor(kind: MediaKind): string {
    return this.mediaService.iconFor(kind);
  }

  colourFor(kind: MediaKind): string {
    return this.mediaService.colourFor(kind);
  }

  /** The kinds this picker offers, which is what the API accepts unless narrowed. */
  get offeredTypes(): MediaKind[] {
    const available = Object.keys(this.accepts) as MediaKind[];

    return this.acceptedTypes.length === 0
      ? available
      : available.filter((kind) => this.acceptedTypes.includes(kind));
  }

  /** The `accept` attribute for the file input, from the same rules. */
  getAcceptedExtensions(): string {
    return this.offeredTypes
      .flatMap((kind) => this.accepts[kind]?.extensions ?? [])
      .map((extension) => `.${extension}`)
      .join(',');
  }

  /**
   * Generate unique ID
   */
  private generateId(): string {
    return Math.random().toString(36).substring(2) + Date.now().toString(36);
  }

  /**
   * Get pending files count
   */
  get pendingCount(): number {
    return this.fileQueue.filter(f => f.status === 'pending').length;
  }

  /**
   * Get uploading files count
   */
  get uploadingCount(): number {
    return this.fileQueue.filter(f => f.status === 'uploading').length;
  }

  /**
   * Get completed files count
   */
  get completedCount(): number {
    return this.fileQueue.filter(f => f.status === 'success').length;
  }

  /**
   * Get failed files count
   */
  get failedCount(): number {
    return this.fileQueue.filter(f => f.status === 'error').length;
  }

  /**
   * Get overall progress
   */
  get overallProgress(): number {
    if (this.fileQueue.length === 0) return 0;
    const total = this.fileQueue.reduce((sum, f) => sum + f.progress, 0);
    return Math.round(total / this.fileQueue.length);
  }
}
