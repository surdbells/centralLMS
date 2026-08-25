import { Injectable } from '@angular/core';
import { HttpClient, HttpEvent, HttpEventType, HttpHeaders, HttpRequest } from '@angular/common/http';
import { Observable, Subject, BehaviorSubject } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

// Interfaces
export interface MediaUploadResponse {
  code: number;
  status: string;
  data: {
    media: MediaData;
  };
  message: string;
}

export interface MediaData {
  media_id: number;
  tenant_id: number;
  media_type: 'VIDEO' | 'AUDIO' | 'PDF' | 'SCORM' | '';
  title: string;
  file_name: string;
  file_path: string;
  file_url: string;
  file_size: number;
  file_size_formatted: string;
  mime_type: string;
  version: {
    version_number: number;
    version_label: string;
    is_latest: boolean;
    parent_media_id: number | null;
    version_notes: string | null;
  };
  metadata: MediaMetadata;
  uploaded_at: string;
}

export interface MediaMetadata {
  original_name: string;
  file_size: number;
  mime_type: string;
  extension: string;
  duration?: number;
  duration_formatted?: string;
  width?: number;
  height?: number;
  resolution?: string;
  page_count?: number;
  scorm_version?: string;
  launch_url?: string;
}

export interface VersionHistoryResponse {
  code: number;
  status: string;
  data: {
    root_media_id: number;
    total_versions: number;
    versions: VersionInfo[];
  };
  message: string;
}

export interface VersionInfo {
  media_id: number;
  title: string;
  version: {
    number: number;
    label: string;
    notes: string | null;
    is_latest: boolean;
  };
  file: {
    size: number;
    size_formatted: string;
    url: string;
  };
  uploaded_by: {
    user_id: number;
    name: string;
    email: string;
  };
  uploaded_at: string;
}

export interface UploadProgress {
  file: File;
  progress: number;
  status: 'pending' | 'uploading' | 'success' | 'error';
  response?: MediaData;
  error?: string;
}

export interface UploadConfig {
  title?: string;
  description?: string;
  parent_media_id?: number;
  version_label?: string;
  version_notes?: string;
}

@Injectable({
  providedIn: 'root'
})
export class MediaService {
  private baseUrl = 'https://api.centrallms.com';

  // File type configurations
  readonly allowedTypes = {
    VIDEO: {
      extensions: ['mp4', 'webm', 'mov', 'avi', 'mkv', 'wmv', 'flv', 'm4v'],
      mimes: ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'],
      maxSize: 2147483648, // 2GB
      icon: 'bi-camera-video-fill',
      color: '#6366f1'
    },
    AUDIO: {
      extensions: ['mp3', 'wav', 'ogg', 'aac', 'm4a', 'flac', 'wma'],
      mimes: ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/aac', 'audio/x-m4a'],
      maxSize: 524288000, // 500MB
      icon: 'bi-music-note-beamed',
      color: '#10b981'
    },
    PDF: {
      extensions: ['pdf'],
      mimes: ['application/pdf'],
      maxSize: 104857600, // 100MB
      icon: 'bi-file-earmark-pdf-fill',
      color: '#ef4444'
    },
    SCORM: {
      extensions: ['zip'],
      mimes: ['application/zip', 'application/x-zip-compressed'],
      maxSize: 1073741824, // 1GB
      icon: 'bi-box-seam-fill',
      color: '#f59e0b'
    }
  };

  constructor(private http: HttpClient) {}

  /**
   * Upload media file with progress tracking
   */
  uploadMedia(
    file: File,
    token: string,
    userId: number,
    tenantId: number,
    config: UploadConfig = {}
  ): Observable<HttpEvent<MediaUploadResponse>> {
    const formData = new FormData();

    // Required fields
    formData.append('file', file);
    formData.append('token', token);
    formData.append('user_id', userId.toString());
    formData.append('tenant_id', tenantId.toString());

    // Optional fields
    if (config.title) formData.append('title', config.title);
    if (config.description) formData.append('description', config.description);
    if (config.parent_media_id) formData.append('parent_media_id', config.parent_media_id.toString());
    if (config.version_label) formData.append('version_label', config.version_label);
    if (config.version_notes) formData.append('version_notes', config.version_notes);

    const req = new HttpRequest('POST', `${this.baseUrl}/user/media/upload`, formData, {
      reportProgress: true
    });

    return this.http.request<MediaUploadResponse>(req);
  }

  /**
   * Get version history for a media file
   */
  getVersionHistory(
    mediaId: number,
    token: string,
    userId: number,
    tenantId: number
  ): Observable<VersionHistoryResponse> {
    return this.http.post<VersionHistoryResponse>(`${this.baseUrl}/user/media/versions`, {
      token,
      user_id: userId,
      tenant_id: tenantId,
      media_id: mediaId,
      action: 'history'
    });
  }

  /**
   * Get latest version of a media file
   */
  getLatestVersion(
    mediaId: number,
    token: string,
    userId: number,
    tenantId: number
  ): Observable<any> {
    return this.http.post(`${this.baseUrl}/user/media/versions`, {
      token,
      user_id: userId,
      tenant_id: tenantId,
      media_id: mediaId,
      action: 'latest'
    });
  }

  /**
   * Revert to a specific version
   */
  revertToVersion(
    mediaId: number,
    token: string,
    userId: number,
    tenantId: number
  ): Observable<any> {
    return this.http.post(`${this.baseUrl}/user/media/versions`, {
      token,
      user_id: userId,
      tenant_id: tenantId,
      media_id: mediaId,
      action: 'revert'
    });
  }

  /**
   * Validate file before upload
   */
  validateFile(file: File): { valid: boolean; error?: string; type?: string } {
    const extension = file.name.split('.').pop()?.toLowerCase() || '';

    // Find matching type
    for (const [type, config] of Object.entries(this.allowedTypes)) {
      if (config.extensions.includes(extension)) {
        // Check file size
        if (file.size > config.maxSize) {
          return {
            valid: false,
            error: `${type} files cannot exceed ${this.formatFileSize(config.maxSize)}`
          };
        }
        return { valid: true, type };
      }
    }

    return {
      valid: false,
      error: 'File type not allowed. Supported: VIDEO, AUDIO, PDF, SCORM'
    };
  }

  /**
   * Get media type from file
   */
  getMediaType(file: File): string | null {
    const extension = file.name.split('.').pop()?.toLowerCase() || '';

    for (const [type, config] of Object.entries(this.allowedTypes)) {
      if (config.extensions.includes(extension)) {
        return type;
      }
    }
    return null;
  }

  /**
   * Format file size to human readable
   */
  formatFileSize(bytes: number): string {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  }

  /**
   * Format duration to MM:SS or HH:MM:SS
   */
  formatDuration(seconds: number): string {
    if (!seconds || seconds <= 0) return '00:00';

    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);

    if (hours > 0) {
      return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }
    return `${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
  }
}
