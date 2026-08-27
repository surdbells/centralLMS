// shared/services/file-upload.service.ts

import { Injectable, inject } from '@angular/core';
import { Observable, forkJoin, throwError } from 'rxjs';
import { filter, map, switchMap, take } from 'rxjs/operators';
import { MediaService } from '../services/media.service';

export interface FileUploadResponse {
  success: boolean;
  file_path: string;
  file_name: string;
  file_size: number;
  file_type: string;
  thumbnail_path?: string;
  error?: string;
}

export interface UploadProgress {
  loaded: number;
  total: number;
  percentage: number;
}

interface ApiResponse<T> {
  code: number;
  status: string;
  data: T;
  message: string;
}

@Injectable({
  providedIn: 'root'
})
export class FileUploadService {
  /**
   * Attachments go into the media library.
   *
   * There is no attachment table on a message — the schema is
   * conversations, participants and messages — so a file sent in a
   * conversation is stored the way every other file is, and the message
   * carries its link. That is a real place for it to live rather than an
   * upload endpoint writing into nothing.
   *
   * It also stops the credentials being posted in the form body: this used
   * to append `token`, `user_id` and `tenant_id` from `localStorage` to
   * every upload.
   */
  private readonly media = inject(MediaService);
  private maxFileSize = 10485760; // 10MB
  private allowedTypes = {
    images: ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'],
    documents: ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
    archives: ['application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed'],
    videos: ['video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo'],
    audio: ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/mp4']
  };

  /**
   * Upload single file for messaging attachments
   */
  uploadMessageAttachment(file: File): Observable<FileUploadResponse> {
    const validation = this.validateFile(file);

    if (!validation.valid) {
      return throwError(() => new Error(validation.error ?? 'That file cannot be sent.'));
    }

    return this.media.upload(file, { title: file.name }).pipe(
      // The upload reports progress as it goes; only the finished item is
      // an answer to "where did the file go".
      filter((progress) => progress.result !== undefined),
      take(1),
      // A link to a library file is a short-lived ticket rather than a
      // permanent path, so that a URL pasted into a conversation does not
      // become a way to read the file without being signed in.
      switchMap((progress) => this.media.fileUrl(progress.result!).pipe(
        map((url) => ({
          success: true,
          file_path: url,
          file_name: progress.result!.title,
          file_size: progress.result!.fileSize,
          file_type: file.type,
        })),
      )),
    );
  }

  /**
   * Upload multiple files
   */
  uploadMessageAttachments(files: File[]): Observable<FileUploadResponse[]> {
    const valid = files.filter((file) => this.validateFile(file).valid);

    if (valid.length === 0) {
      return throwError(() => new Error('None of those files can be sent.'));
    }

    // One upload each. The legacy posted them as `files[0]`, `files[1]`
    // in a single request, which meant one oversized file failed all of
    // them.
    return forkJoin(valid.map((file) => this.uploadMessageAttachment(file)));
  }

  /**
   * Validate file before upload
   */
  validateFile(file: File): { valid: boolean; error?: string } {
    // Check file size
    if (file.size > this.maxFileSize) {
      return {
        valid: false,
        error: `File ${file.name} exceeds maximum size of ${this.formatFileSize(this.maxFileSize)}`
      };
    }

    // Check file type
    const allAllowedTypes = [
      ...this.allowedTypes.images,
      ...this.allowedTypes.documents,
      ...this.allowedTypes.archives,
      ...this.allowedTypes.videos,
      ...this.allowedTypes.audio
    ];

    if (!allAllowedTypes.includes(file.type)) {
      return {
        valid: false,
        error: `File type ${file.type} is not allowed`
      };
    }

    return { valid: true };
  }

  /**
   * Get file type category
   */
  getFileTypeCategory(mimeType: string): 'image' | 'document' | 'archive' | 'video' | 'audio' | 'other' {
    if (this.allowedTypes.images.includes(mimeType)) return 'image';
    if (this.allowedTypes.documents.includes(mimeType)) return 'document';
    if (this.allowedTypes.archives.includes(mimeType)) return 'archive';
    if (this.allowedTypes.videos.includes(mimeType)) return 'video';
    if (this.allowedTypes.audio.includes(mimeType)) return 'audio';
    return 'other';
  }

  /**
   * Create file preview URL
   */
  createPreviewUrl(file: File): Promise<string> {
    return new Promise((resolve, reject) => {
      if (!file.type.startsWith('image/')) {
        resolve('');
        return;
      }

      const reader = new FileReader();
      reader.onload = (e: any) => resolve(e.target.result);
      reader.onerror = reject;
      reader.readAsDataURL(file);
    });
  }

  /**
   * Format file size for display
   */
  formatFileSize(bytes: number): string {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  }

  /**
   * Get file icon class based on type
   */
  getFileIcon(mimeType: string): string {
    const category = this.getFileTypeCategory(mimeType);
    const icons: { [key: string]: string } = {
      'image': 'bi-file-image',
      'document': 'bi-file-earmark-text',
      'archive': 'bi-file-zip',
      'video': 'bi-file-play',
      'audio': 'bi-file-music',
      'other': 'bi-file-earmark'
    };
    return icons[category] || 'bi-file-earmark';
  }

  /**
   * Check if file is an image
   */
  isImage(file: File): boolean {
    return this.allowedTypes.images.includes(file.type);
  }

  /**
   * Get allowed extensions as string
   */
  getAllowedExtensions(): string {
    return '.jpg,.jpeg,.png,.gif,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip,.mp4,.mp3,.wav';
  }

  /**
   * Get max file size
   */
  getMaxFileSize(): number {
    return this.maxFileSize;
  }
}
