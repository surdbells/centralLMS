// components/file-uploader/file-uploader.component.ts
import { Component, Input, Output, EventEmitter, ViewChild, ElementRef, ChangeDetectionStrategy, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';

export interface UploadedFile {
  file: File;
  name: string;
  size: number;
  type: string;
  preview?: string;
  progress: number;
  status: 'pending' | 'uploading' | 'success' | 'error';
  error?: string;
}

@Component({
  selector: 'app-file-uploader',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="file-uploader" [class.disabled]="disabled">
      <!-- Drop Zone -->
      <div
        class="drop-zone"
        [class.drag-over]="isDragOver"
        [class.has-files]="files.length > 0"
        (dragover)="onDragOver($event)"
        (dragleave)="onDragLeave($event)"
        (drop)="onDrop($event)"
        (click)="openFilePicker()">

        <input
          #fileInput
          type="file"
          [accept]="acceptTypes"
          [multiple]="multiple"
          (change)="onFileSelected($event)"
          hidden>

        <div class="drop-zone-content" *ngIf="files.length === 0">
          <i class="bi bi-cloud-arrow-up"></i>
          <p class="drop-text">{{ dropText }}</p>
          <p class="drop-hint">{{ dropHint }}</p>
          <button type="button" class="btn btn-primary btn-sm">
            {{ buttonText }}
          </button>
        </div>
      </div>

      <!-- File List -->
      <div class="file-list" *ngIf="files.length > 0">
        <div class="file-item" *ngFor="let file of files; let i = index">
          <div class="file-preview" *ngIf="showPreview && file.preview">
            <img [src]="file.preview" [alt]="file.name">
          </div>
          <div class="file-icon" *ngIf="!showPreview || !file.preview">
            <i class="bi" [ngClass]="getFileIcon(file.type)"></i>
          </div>

          <div class="file-info">
            <span class="file-name">{{ file.name }}</span>
            <span class="file-size">{{ formatSize(file.size) }}</span>
            <div class="file-progress" *ngIf="file.status === 'uploading'">
              <div class="progress" style="height: 4px;">
                <div class="progress-bar" [style.width.%]="file.progress"></div>
              </div>
            </div>
            <span class="file-error text-danger" *ngIf="file.error">{{ file.error }}</span>
          </div>

          <div class="file-status">
            <i class="bi bi-check-circle-fill text-success" *ngIf="file.status === 'success'"></i>
            <i class="bi bi-x-circle-fill text-danger" *ngIf="file.status === 'error'"></i>
            <div class="spinner-border spinner-border-sm" *ngIf="file.status === 'uploading'"></div>
          </div>

          <button
            type="button"
            class="btn-remove"
            (click)="removeFile(i)"
            *ngIf="file.status !== 'uploading'">
            <i class="bi bi-x-lg"></i>
          </button>
        </div>

        <button
          type="button"
          class="btn btn-primary btn-sm mt-2"
          (click)="openFilePicker()"
          *ngIf="multiple && files.length < maxFiles">
          <i class="bi bi-plus-lg me-1"></i> Add More
        </button>
      </div>

      <!-- Validation Messages -->
      <div class="validation-info mt-2" *ngIf="showValidation">
        <small class="text-muted">
          <span *ngIf="acceptTypes">Allowed: {{ acceptTypes }}</span>
          <span *ngIf="maxSizeMB"> | Max size: {{ maxSizeMB }}MB</span>
          <span *ngIf="multiple && maxFiles"> | Max files: {{ maxFiles }}</span>
        </small>
      </div>
    </div>
  `,
  styles: [`
    .file-uploader.disabled { opacity: 0.6; pointer-events: none; }
    .drop-zone {
      border: 2px dashed #dee2e6;
      border-radius: 0.5rem;
      padding: 2rem;
      text-align: center;
      cursor: pointer;
      transition: all 0.2s;
      background: #f8f9fa;
    }
    .drop-zone:hover, .drop-zone.drag-over {
      border-color: #0d6efd;
      background: #e7f1ff;
    }
    .drop-zone.has-files { padding: 1rem; }
    .drop-zone-content i { font-size: 3rem; color: #6c757d; }
    .drop-text { font-size: 1.1rem; margin: 0.5rem 0 0.25rem; color: #495057; }
    .drop-hint { font-size: 0.875rem; color: #6c757d; margin-bottom: 1rem; }

    .file-list { margin-top: 1rem; }
    .file-item {
      display: flex;
      align-items: center;
      padding: 0.75rem;
      background: #f8f9fa;
      border-radius: 0.375rem;
      margin-bottom: 0.5rem;
      gap: 0.75rem;
    }
    .file-preview {
      width: 48px;
      height: 48px;
      border-radius: 0.25rem;
      overflow: hidden;
    }
    .file-preview img { width: 100%; height: 100%; object-fit: cover; }
    .file-icon {
      width: 48px;
      height: 48px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #e9ecef;
      border-radius: 0.25rem;
      font-size: 1.5rem;
      color: #6c757d;
    }
    .file-info { flex: 1; min-width: 0; }
    .file-name {
      display: block;
      font-weight: 500;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .file-size { font-size: 0.75rem; color: #6c757d; }
    .file-progress { margin-top: 0.25rem; }
    .file-error { font-size: 0.75rem; display: block; }
    .btn-remove {
      background: none;
      border: none;
      color: #6c757d;
      cursor: pointer;
      padding: 0.25rem;
    }
    .btn-remove:hover { color: #dc3545; }
  `]
})
export class FileUploaderComponent {
  @ViewChild('fileInput') fileInput!: ElementRef<HTMLInputElement>;

  @Input() multiple: boolean = false;
  @Input() maxFiles: number = 10;
  @Input() maxSizeMB: number = 10;
  @Input() acceptTypes: string = '';
  @Input() showPreview: boolean = true;
  @Input() showValidation: boolean = true;
  @Input() disabled: boolean = false;
  @Input() dropText: string = 'Drag and drop files here';
  @Input() dropHint: string = 'or click to browse';
  @Input() buttonText: string = 'Browse Files';

  @Output() filesSelected = new EventEmitter<UploadedFile[]>();
  @Output() fileRemoved = new EventEmitter<{ file: UploadedFile; index: number }>();
  @Output() validationError = new EventEmitter<string>();

  files: UploadedFile[] = [];
  isDragOver: boolean = false;

  constructor(private cdr: ChangeDetectorRef) {}

  openFilePicker(): void {
    if (!this.disabled) {
      this.fileInput.nativeElement.click();
    }
  }

  onDragOver(event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();
    this.isDragOver = true;
  }

  onDragLeave(event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();
    this.isDragOver = false;
  }

  onDrop(event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();
    this.isDragOver = false;

    if (this.disabled) return;

    const files = event.dataTransfer?.files;
    if (files) {
      this.processFiles(Array.from(files));
    }
  }

  onFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    if (input.files) {
      this.processFiles(Array.from(input.files));
    }
    input.value = '';
  }

  private processFiles(fileList: File[]): void {
    for (const file of fileList) {
      if (!this.multiple && this.files.length > 0) {
        this.files = [];
      }

      if (this.files.length >= this.maxFiles) {
        this.validationError.emit(`Maximum ${this.maxFiles} files allowed`);
        break;
      }

      const validation = this.validateFile(file);
      if (validation) {
        this.validationError.emit(validation);
        continue;
      }

      const uploadedFile: UploadedFile = {
        file,
        name: file.name,
        size: file.size,
        type: file.type,
        progress: 0,
        status: 'pending'
      };

      if (this.showPreview && file.type.startsWith('image/')) {
        this.createPreview(uploadedFile);
      }

      this.files.push(uploadedFile);
    }

    this.filesSelected.emit([...this.files]);
    this.cdr.markForCheck();
  }

  private validateFile(file: File): string | null {
    if (this.maxSizeMB && file.size > this.maxSizeMB * 1024 * 1024) {
      return `File "${file.name}" exceeds ${this.maxSizeMB}MB limit`;
    }

    if (this.acceptTypes) {
      const acceptedTypes = this.acceptTypes.split(',').map(t => t.trim().toLowerCase());
      const fileExt = '.' + file.name.split('.').pop()?.toLowerCase();
      const mimeType = file.type.toLowerCase();

      const isAccepted = acceptedTypes.some(type => {
        if (type.startsWith('.')) return fileExt === type;
        if (type.endsWith('/*')) return mimeType.startsWith(type.replace('/*', '/'));
        return mimeType === type;
      });

      if (!isAccepted) {
        return `File type "${fileExt}" is not allowed`;
      }
    }

    return null;
  }

  private createPreview(uploadedFile: UploadedFile): void {
    const reader = new FileReader();
    reader.onload = (e) => {
      uploadedFile.preview = e.target?.result as string;
      this.cdr.markForCheck();
    };
    reader.readAsDataURL(uploadedFile.file);
  }

  removeFile(index: number): void {
    const removed = this.files.splice(index, 1)[0];
    this.fileRemoved.emit({ file: removed, index });
    this.filesSelected.emit([...this.files]);
    this.cdr.markForCheck();
  }

  updateFileProgress(index: number, progress: number): void {
    if (this.files[index]) {
      this.files[index].progress = progress;
      this.files[index].status = progress < 100 ? 'uploading' : 'success';
      this.cdr.markForCheck();
    }
  }

  setFileStatus(index: number, status: UploadedFile['status'], error?: string): void {
    if (this.files[index]) {
      this.files[index].status = status;
      this.files[index].error = error;
      this.cdr.markForCheck();
    }
  }

  clearAll(): void {
    this.files = [];
    this.cdr.markForCheck();
  }

  getFileIcon(mimeType: string): string {
    if (mimeType.startsWith('image/')) return 'bi-file-image';
    if (mimeType.startsWith('video/')) return 'bi-file-play';
    if (mimeType.startsWith('audio/')) return 'bi-file-music';
    if (mimeType.includes('pdf')) return 'bi-file-pdf';
    if (mimeType.includes('word') || mimeType.includes('document')) return 'bi-file-word';
    if (mimeType.includes('excel') || mimeType.includes('spreadsheet')) return 'bi-file-excel';
    if (mimeType.includes('powerpoint') || mimeType.includes('presentation')) return 'bi-file-ppt';
    if (mimeType.includes('zip') || mimeType.includes('compressed')) return 'bi-file-zip';
    return 'bi-file-earmark';
  }

  formatSize(bytes: number): string {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  }
}
