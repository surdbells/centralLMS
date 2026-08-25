import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient, Endpoints, type UploadProgress } from '../core/api';

export type { UploadProgress };

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export type MediaType = 'VIDEO' | 'AUDIO' | 'PDF' | 'SCORM';

export interface MediaVersion {
  version_number: number;
  version_label: string;
  is_latest: boolean;
  parent_media_id: number | null;
  version_notes: string | null;
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

export interface MediaData {
  media_id: number;
  tenant_id: number;
  media_type: MediaType | '';
  title: string;
  file_name: string;
  file_path: string;
  file_url: string;
  file_size: number;
  file_size_formatted: string;
  mime_type: string;
  version: MediaVersion;
  metadata: MediaMetadata;
  uploaded_at: string;
}

export interface VersionInfo {
  media_id: number;
  title: string;
  version: { number: number; label: string; notes: string | null; is_latest: boolean };
  file: { size: number; size_formatted: string; url: string };
  uploaded_by: { user_id: number; name: string; email: string };
  uploaded_at: string;
}

export interface VersionHistory {
  root_media_id: number;
  total_versions: number;
  versions: VersionInfo[];
}

export interface UploadConfig {
  title?: string;
  description?: string;
  parent_media_id?: number;
  version_label?: string;
  version_notes?: string;
}

export interface FileValidation {
  valid: boolean;
  error?: string;
  type?: MediaType;
}

interface TypeConfig {
  readonly extensions: readonly string[];
  readonly mimes: readonly string[];
  readonly maxSize: number;
  readonly icon: string;
  readonly color: string;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * Media upload and versioning.
 *
 * Reference migration off `GlobalComponent`. Three things changed, and they
 * are the pattern for every remaining service:
 *
 *  1. **No base URL.** It named a module; `ApiRouter` decides the host, so
 *     migrating media to the new API needs no change here.
 *  2. **No credential parameters.** Every method used to take
 *     `(token, userId, tenantId)` and paste them into the payload. The auth
 *     interceptor does that now, in the form the target backend expects.
 *  3. **Unwrapped, typed results.** Callers get `MediaData`, not
 *     `{ code, status, data: { media } }` — so the envelope change at
 *     migration is invisible to them.
 */
@Injectable({ providedIn: 'root' })
export class MediaService {
  private readonly api = inject(ApiClient);

  /** Upload limits and presentation, by media type. */
  readonly allowedTypes: Readonly<Record<MediaType, TypeConfig>> = {
    VIDEO: {
      extensions: ['mp4', 'webm', 'mov', 'avi', 'mkv', 'wmv', 'flv', 'm4v'],
      mimes: ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska'],
      maxSize: 2_147_483_648, // 2 GB
      icon: 'bi-camera-video-fill',
      color: '#6366f1',
    },
    AUDIO: {
      extensions: ['mp3', 'wav', 'ogg', 'aac', 'm4a', 'flac', 'wma'],
      mimes: ['audio/mpeg', 'audio/wav', 'audio/ogg', 'audio/aac', 'audio/x-m4a'],
      maxSize: 524_288_000, // 500 MB
      icon: 'bi-music-note-beamed',
      color: '#10b981',
    },
    PDF: {
      extensions: ['pdf'],
      mimes: ['application/pdf'],
      maxSize: 104_857_600, // 100 MB
      icon: 'bi-file-earmark-pdf-fill',
      color: '#ef4444',
    },
    SCORM: {
      extensions: ['zip'],
      mimes: ['application/zip', 'application/x-zip-compressed'],
      maxSize: 1_073_741_824, // 1 GB
      icon: 'bi-box-seam-fill',
      color: '#f59e0b',
    },
  };

  /** Uploads a file, reporting progress. */
  upload(file: File, config: UploadConfig = {}): Observable<UploadProgress<{ media: MediaData }>> {
    const form = new FormData();
    form.append('file', file);

    for (const [key, value] of Object.entries(config)) {
      if (value !== undefined && value !== null && value !== '') {
        form.append(key, String(value));
      }
    }

    return this.api.upload<{ media: MediaData }>(
      Endpoints.media.module,
      Endpoints.media.upload,
      form,
    );
  }

  getVersionHistory(mediaId: number): Observable<VersionHistory> {
    return this.api.post<VersionHistory>(Endpoints.media.module, Endpoints.media.versions, {
      media_id: mediaId,
      action: 'history',
    });
  }

  getLatestVersion(mediaId: number): Observable<MediaData> {
    return this.api.post<MediaData>(Endpoints.media.module, Endpoints.media.versions, {
      media_id: mediaId,
      action: 'latest',
    });
  }

  revertToVersion(mediaId: number): Observable<MediaData> {
    return this.api.post<MediaData>(Endpoints.media.module, Endpoints.media.versions, {
      media_id: mediaId,
      action: 'revert',
    });
  }

  list(): Observable<MediaData[]> {
    return this.api.get<MediaData[]>(Endpoints.media.module, Endpoints.media.read);
  }

  delete(mediaId: number): Observable<void> {
    return this.api.post<void>(Endpoints.media.module, Endpoints.media.delete, {
      media_id: mediaId,
    });
  }

  // --- pure helpers, unchanged in behaviour ---------------------------------

  /** Checks extension and size before a file is sent anywhere. */
  validateFile(file: File): FileValidation {
    const type = this.getMediaType(file);

    if (type === null) {
      return {
        valid: false,
        error: 'File type not allowed. Supported: VIDEO, AUDIO, PDF, SCORM',
      };
    }

    const config = this.allowedTypes[type];

    if (file.size > config.maxSize) {
      return {
        valid: false,
        error: `${type} files cannot exceed ${this.formatFileSize(config.maxSize)}`,
      };
    }

    return { valid: true, type };
  }

  getMediaType(file: File): MediaType | null {
    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

    if (extension === '') {
      return null;
    }

    for (const [type, config] of Object.entries(this.allowedTypes)) {
      if (config.extensions.includes(extension)) {
        return type as MediaType;
      }
    }

    return null;
  }

  formatFileSize(bytes: number): string {
    if (!Number.isFinite(bytes) || bytes <= 0) {
      return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);

    return `${parseFloat((bytes / Math.pow(1024, exponent)).toFixed(2))} ${units[exponent]}`;
  }

  /** `MM:SS`, or `HH:MM:SS` once the duration passes an hour. */
  formatDuration(seconds: number): string {
    if (!Number.isFinite(seconds) || seconds <= 0) {
      return '00:00';
    }

    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);
    const pad = (n: number): string => n.toString().padStart(2, '0');

    return hours > 0 ? `${pad(hours)}:${pad(minutes)}:${pad(secs)}` : `${pad(minutes)}:${pad(secs)}`;
  }
}
