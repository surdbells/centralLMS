import { decodeSessionBlob } from '../core/utils/session-blob';
// tenant-settings/tenant-settings.service.ts

import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import {GlobalComponent} from '../global-component';

// Interfaces
export interface GeneralSettings {
  company_name: string;
  company_email: string;
  company_phone: string;
  company_address: string;
  timezone: string;
  date_format: string;
  time_format: string;
  language: string;
  currency: string;
}

export interface BrandingSettings {
  logo_url: string;
  favicon_url: string;
  primary_color: string;
  secondary_color: string;
  accent_color: string;
  login_background_url: string;
  email_header_logo_url: string;
  show_powered_by: boolean;
}

export interface LearningSettings {
  default_course_duration_days: number;
  allow_self_enrollment: boolean;
  require_sequential_lessons: boolean;
  video_completion_threshold: number;
  quiz_passing_score: number;
  max_quiz_attempts: number;
  show_correct_answers: boolean;
  certificate_enabled: boolean;
  certificate_expiry_days: number;
  auto_enroll_new_users: boolean;
  mandatory_course_reminder_days: number[];
}

export interface GamificationSettings {
  enabled: boolean;
  points_per_lesson: number;
  points_per_course: number;
  points_per_quiz_correct: number;
  points_per_certificate: number;
  leaderboard_enabled: boolean;
  leaderboard_display_count: number;
  badges_enabled: boolean;
  streaks_enabled: boolean;
  streak_bonus_multiplier: number;
}

export interface NotificationSettings {
  email_notifications_enabled: boolean;
  enrollment_notification: boolean;
  course_completion_notification: boolean;
  certificate_notification: boolean;
  deadline_reminder_notification: boolean;
  quiz_result_notification: boolean;
  new_course_notification: boolean;
  weekly_progress_digest: boolean;
  manager_progress_reports: boolean;
  notification_from_name: string;
  notification_from_email: string;
}

export interface SecuritySettings {
  password_min_length: number;
  password_require_uppercase: boolean;
  password_require_lowercase: boolean;
  password_require_number: boolean;
  password_require_special: boolean;
  password_expiry_days: number;
  max_login_attempts: number;
  lockout_duration_minutes: number;
  session_timeout_minutes: number;
  force_password_change_first_login: boolean;
  two_factor_enabled: boolean;
  allowed_ip_ranges: string[];
}

export interface StorageSettings {
  max_file_size_mb: number;
  allowed_file_types: string[];
  video_max_size_mb: number;
  audio_max_size_mb: number;
  document_max_size_mb: number;
  scorm_max_size_mb: number;
}

export interface IntegrationSettings {
  sso_enabled: boolean;
  sso_provider: string;
  sso_config: Record<string, any>;
  api_access_enabled: boolean;
  webhook_url: string;
  webhook_events: string[];
}

export interface AllSettings {
  general: GeneralSettings;
  branding: BrandingSettings;
  learning: LearningSettings;
  gamification: GamificationSettings;
  notifications: NotificationSettings;
  security: SecuritySettings;
  storage: StorageSettings;
  integrations: IntegrationSettings;
}

export interface TenantInfo {
  tenant_id: number;
  tenant_name: string;
  tenant_code: string;
  domain: string;
  logo_url: string;
  is_active: boolean;
  subscription_plan: string;
  subscription_expires_at: string;
  created_at: string;
  updated_at: string;
}

export interface UsageStats {
  users: number;
  active_users: number;
  courses: number;
  published_courses: number;
  enrollments: number;
  completed_enrollments: number;
  certificates_issued: number;
  storage_used_bytes: number;
  storage_limit_bytes: number;
}

export interface SettingsHistoryItem {
  id: number;
  category: string;
  changes: Record<string, any>;
  changed_by: number;
  first_name: string;
  last_name: string;
  email: string;
  created_at: string;
}

export interface ApiResponse<T> {
  code: number;
  status: string;
  data: T;
  message: string;
}

export type SettingsCategory = 'general' | 'branding' | 'learning' | 'gamification' | 'notifications' | 'security' | 'storage' | 'integrations';

@Injectable({
  providedIn: 'root'
})
export class TenantSettingsService {
  private apiUrl = GlobalComponent.tenantSettings;
  constructor(private http: HttpClient) {}
  session_data: any = ""
  user_session = {
    id: 0,
    tenant_id: 0,
    tenant: {
      id: 0,
      name: "",
      status: "",
    },
    token: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    avatar: "",
    is_2fa: false,
    is_active: false,
    account_locked: false,
    is_admin: false,
    is_content: false,
    is_instructor: false,
    is_learner: false,
  };
  private getAuthPayload(): any {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    return {
      token: this.user_session.token,
      user_id: this.user_session.id,
      tenant_id: this.user_session.tenant_id,
    };
  }

  // ==========================================
  // GET SETTINGS
  // ==========================================

  getAllSettings(): Observable<AllSettings> {
    return this.http.post<ApiResponse<AllSettings>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-all'
    }).pipe(map(res => res.data));
  }

  getCategorySettings<T>(category: SettingsCategory): Observable<T> {
    return this.http.post<ApiResponse<T>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-category',
      category
    }).pipe(map(res => res.data));
  }

  getSetting<T>(category: SettingsCategory, key: string): Observable<T> {
    return this.http.post<ApiResponse<{ value: T }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-setting',
      category,
      key
    }).pipe(map(res => res.data.value));
  }

  // ==========================================
  // UPDATE SETTINGS
  // ==========================================

  updateCategorySettings(category: SettingsCategory, settings: Record<string, any>): Observable<any> {
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'update-category',
      category,
      settings
    }).pipe(map(res => res.data));
  }

  updateSetting(category: SettingsCategory, key: string, value: any): Observable<any> {
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'update-setting',
      category,
      key,
      value
    }).pipe(map(res => res.data));
  }

  resetCategoryToDefaults(category: SettingsCategory): Observable<any> {
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'reset-category',
      category
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // TENANT INFO
  // ==========================================

  getTenantInfo(): Observable<TenantInfo> {
    return this.http.post<ApiResponse<TenantInfo>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-tenant-info'
    }).pipe(map(res => res.data));
  }

  updateTenantInfo(data: Partial<TenantInfo>): Observable<any> {
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'update-tenant-info',
      data
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // STATISTICS
  // ==========================================

  getUsageStats(): Observable<UsageStats> {
    return this.http.post<ApiResponse<UsageStats>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-usage-stats'
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // HISTORY
  // ==========================================

  getSettingsHistory(limit: number = 50): Observable<SettingsHistoryItem[]> {
    return this.http.post<ApiResponse<SettingsHistoryItem[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-history',
      limit
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // EXPORT/IMPORT
  // ==========================================

  exportSettings(): Observable<any> {
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'export'
    }).pipe(map(res => res.data));
  }

  importSettings(settings: Record<string, any>): Observable<any> {
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'import',
      settings
    }).pipe(map(res => res.data));
  }
}
