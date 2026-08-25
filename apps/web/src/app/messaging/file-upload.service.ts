// shared/services/file-upload.service.ts

import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, from } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { GlobalComponent } from '../global-component';

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
  private uploadUrl = GlobalComponent.im_uploads;
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

  constructor(private http: HttpClient) {}

  /**
   * Upload single file for messaging attachments
   */
  uploadMessageAttachment(file: File): Observable<FileUploadResponse> {
    // Validate file
    const validation = this.validateFile(file);
    if (!validation.valid) {
      return from(Promise.reject({ error: validation.error }));
    }

    const formData = new FormData();
    formData.append('token', localStorage.getItem('token') || '');
    formData.append('user_id', localStorage.getItem('user_id') || '');
    formData.append('tenant_id', localStorage.getItem('tenant_id') || '');
    formData.append('action', 'upload_message_attachment');
    formData.append('file', file);
    formData.append('upload_type', 'message');

    return this.http.post<ApiResponse<FileUploadResponse>>(this.uploadUrl, formData).pipe(
      map(response => response.data),
      catchError(error => {
        console.error('Upload error:', error);
        throw error;
      })
    );
  }

  /**
   * Upload multiple files
   */
  uploadMessageAttachments(files: File[]): Observable<FileUploadResponse[]> {
    const formData = new FormData();
    formData.append('token', localStorage.getItem('token') || '');
    formData.append('user_id', localStorage.getItem('user_id') || '');
    formData.append('tenant_id', localStorage.getItem('tenant_id') || '');
    formData.append('action', 'upload_message_attachments');
    formData.append('upload_type', 'message');

    // Validate and add files
    const validFiles: File[] = [];
    for (const file of files) {
      const validation = this.validateFile(file);
      if (validation.valid) {
        validFiles.push(file);
      } else {
        console.warn(`File ${file.name} skipped: ${validation.error}`);
      }
    }

    if (validFiles.length === 0) {
      return from(Promise.reject({ error: 'No valid files to upload' }));
    }

    validFiles.forEach((file, index) => {
      formData.append(`files[${index}]`, file);
    });

    return this.http.post<ApiResponse<FileUploadResponse[]>>(this.uploadUrl, formData).pipe(
      map(response => response.data),
      catchError(error => {
        console.error('Upload error:', error);
        throw error;
      })
    );
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
