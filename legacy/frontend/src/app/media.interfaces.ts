// ============================================
// MEDIA INTERFACES
// For: POST /user/media/read
// ============================================

/**
 * API Response wrapper
 */
export interface ApiResponse<T> {
  code: number;
  status: 'success' | 'failed';
  data: T;
  message: string;
}

/**
 * Media Read Response
 */
export interface MediaReadResponse {
  media: MediaItem[];
  pagination: Pagination;
}

/**
 * Pagination metadata
 */
export interface Pagination {
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
}

/**
 * Media Item
 */
export interface MediaItem {
  media_id: number;
  tenant_id: number;
  uploaded_by: number;
  media_type: MediaType;
  title: string;
  description: string | null;

  // File Information
  original_filename: string;
  stored_filename: string;
  file_path: string;
  file_url: string;
  file_size: number;
  file_size_formatted: string;
  mime_type: string;
  extension: string;

  // Video/Audio Specific
  duration: number | null;
  width: number | null;
  height: number | null;
  bitrate: number | null;

  // PDF Specific
  page_count: number | null;

  // SCORM Specific
  scorm_version: string | null;
  launch_url: string | null;
  extracted_path: string | null;

  // Metadata JSON (parsed)
  metadata_json: string | null;
  metadata: MediaMetadata | null;

  // Security Hashes
  md5_hash: string | null;
  sha256_hash: string | null;

  // Version Control
  root_parent_id: number | null;
  version_number: number;
  version_label: string | null;
  version_notes: string | null;
  is_latest_version: boolean | number;

  // Status
  is_processed: boolean | number;
  is_active: boolean | number;

  // Timestamps
  created_at: string;
  updated_at: string;
  deleted_at: string | null;
}

/**
 * Media Type Enum
 */
export type MediaType = 'VIDEO' | 'AUDIO' | 'PDF' | 'SCORM' | '';

/**
 * Media Metadata (from metadata_json)
 */
export interface MediaMetadata {
  original_name: string;
  file_size: number;
  mime_type: string;
  extension: string;
  md5_hash: string;
  sha256_hash: string;

  // Video specific
  duration?: number;
  duration_formatted?: string;
  width?: number;
  height?: number;
  resolution?: string;
  bitrate?: number;
  codec?: string;
  framerate?: number;

  // Audio specific
  sample_rate?: number;
  channels?: number;

  // PDF specific
  page_count?: number;
  pdf_version?: string;
  author?: string;
  title?: string;
  creator?: string;

  // SCORM specific
  scorm_version?: string;
  manifest_identifier?: string;
  organization_title?: string;
  launch_url?: string;
  total_items?: number;
  extracted_path?: string;
  extracted_files?: number;
  extracted_size?: number;
  extracted_size_formatted?: string;
}

/**
 * Media Read Request Parameters
 */
export interface MediaReadRequest {
  token: string;
  user_id: number;
  tenant_id: number;
  page?: number;
  per_page?: number;
  media_type?: MediaType;
  latest_only?: boolean;
  search?: string;
  sort_by?: 'created_at' | 'title' | 'file_size' | 'updated_at' | 'media_type';
  sort_order?: 'ASC' | 'DESC';
}

/**
 * Type alias for full API response
 */
export type MediaListResponse = ApiResponse<MediaReadResponse>;
