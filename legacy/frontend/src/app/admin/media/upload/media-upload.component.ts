import { Component, OnInit, OnDestroy, Input, Output, EventEmitter, ViewChild, ElementRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpEventType } from '@angular/common/http';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {MediaService, MediaData, UploadConfig, UploadProgress} from '../../../services/media.service';

interface QueuedFile {
  id: string;
  file: File;
  mediaType: string;
  preview?: string;
  progress: number;
  status: 'pending' | 'uploading' | 'success' | 'error';
  response?: MediaData;
  error?: string;
  config: UploadConfig;
}

@Component({
  selector: 'app-media-upload',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './media-upload.component.html',
  styleUrl: './media-upload.component.scss'
})
export class MediaUploadComponent implements OnInit, OnDestroy {
  @ViewChild('fileInput') fileInput!: ElementRef<HTMLInputElement>;

  // Inputs from parent
  @Input() token: string = '';
  @Input() userId: number = 0;
  @Input() tenantId: number = 0;
  @Input() parentMediaId: number | null = null; // For uploading new versions
  @Input() multiple: boolean = true;
  @Input() acceptedTypes: string[] = ['VIDEO', 'AUDIO', 'PDF', 'SCORM'];
  @Input() maxFiles: number = 10;

  // Outputs
  @Output() uploadComplete = new EventEmitter<MediaData>();
  @Output() uploadError = new EventEmitter<{ file: File; error: string }>();
  @Output() allUploadsComplete = new EventEmitter<MediaData[]>();

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

  ngOnInit(): void {}

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
      alert(`Maximum ${this.maxFiles} files allowed`);
      return;
    }

    for (const file of files) {
      // Validate file
      const validation = this.mediaService.validateFile(file);

      if (!validation.valid) {
        this.uploadError.emit({ file, error: validation.error! });
        continue;
      }

      // Check if type is accepted
      if (!this.acceptedTypes.includes(validation.type!)) {
        this.uploadError.emit({ file, error: `${validation.type} files are not accepted here` });
        continue;
      }

      // Add to queue
      const queuedFile: QueuedFile = {
        id: this.generateId(),
        file,
        mediaType: validation.type!,
        progress: 0,
        status: 'pending',
        config: {
          title: file.name.replace(/\.[^/.]+$/, ''), // Remove extension
          parent_media_id: this.parentMediaId || undefined
        }
      };

      // Generate preview for images/videos if needed
      if (validation.type === 'VIDEO') {
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

  /**
   * Open config modal for a file
   */
  openConfig(queuedFile: QueuedFile): void {
    this.selectedFile = queuedFile;
    this.fileTitle = queuedFile.config.title || '';
    this.fileDescription = queuedFile.config.description || '';
    this.versionLabel = queuedFile.config.version_label || '';
    this.versionNotes = queuedFile.config.version_notes || '';
    this.showConfigModal = true;
  }

  /**
   * Save config and close modal
   */
  saveConfig(): void {
    if (this.selectedFile) {
      this.selectedFile.config = {
        ...this.selectedFile.config,
        title: this.fileTitle,
        description: this.fileDescription,
        version_label: this.versionLabel,
        version_notes: this.versionNotes
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

    this.mediaService.uploadMedia(
      nextFile.file,
      this.token,
      this.userId,
      this.tenantId,
      nextFile.config
    ).pipe(
      takeUntil(this.destroy$)
    ).subscribe({
      next: (event) => {
        if (event.type === HttpEventType.UploadProgress && event.total) {
          nextFile.progress = Math.round((event.loaded / event.total) * 100);
        } else if (event.type === HttpEventType.Response) {
          const response = event.body;
          if (response?.status === 'success') {
            nextFile.status = 'success';
            nextFile.response = response.data.media;
            nextFile.progress = 100;
            this.uploadComplete.emit(response.data.media);
          } else {
            nextFile.status = 'error';
            nextFile.error = response?.message || 'Upload failed';
            this.uploadError.emit({ file: nextFile.file, error: nextFile.error || 'Upload failed' });
          }
          this.uploadNextFile();
        }
      },
      error: (error) => {
        nextFile.status = 'error';
        nextFile.error = error.error?.message || error.message || 'Upload failed';
        this.uploadError.emit({ file: nextFile.file, error: nextFile.error || 'Upload failed' });
        this.uploadNextFile();
      }
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

  /**
   * Get icon for media type
   */
  getMediaTypeIcon(type: string): string {
    return this.mediaService.allowedTypes[type as keyof typeof this.mediaService.allowedTypes]?.icon || 'bi-file-earmark';
  }

  /**
   * Get color for media type
   */
  getMediaTypeColor(type: string): string {
    return this.mediaService.allowedTypes[type as keyof typeof this.mediaService.allowedTypes]?.color || '#6b7280';
  }

  /**
   * Get accepted file extensions string
   */
  getAcceptedExtensions(): string {
    const extensions: string[] = [];
    for (const type of this.acceptedTypes) {
      const config = this.mediaService.allowedTypes[type as keyof typeof this.mediaService.allowedTypes];
      if (config) {
        extensions.push(...config.extensions.map(e => `.${e}`));
      }
    }
    return extensions.join(',');
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
