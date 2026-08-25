import { decodeSessionBlob } from '../core/utils/session-blob';
// live-training/live-training.service.ts

import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import {GlobalComponent} from '../global-component';
import {
  EnrollmentRule,
  BulkEnrollmentResult,
  TrainingSkill,
  SkillAssignmentResult,
  UserSkill,
  UserSkillsSummary,
  Skill,
  UserForEnrollment,
  EnrollmentFilter,
  SkillSearchFilter,
  ApiResponse, SkillCategory
} from './live-training.interfaces';

// ==========================================
// INTERFACES
// ==========================================

export interface LiveTraining {
  training_id: number;
  tenant_id: number;
  title: string;
  description: string | null;
  short_description: string | null;
  thumbnail_url: string | null;
  category_id: number | null;
  tags: string[];
  training_type: 'physical' | 'virtual' | 'hybrid';
  max_capacity: number | null;
  allow_waitlist: boolean;
  certification_enabled: boolean;
  certificate_template_id: number | null;
  min_attendance_percentage: number;
  mandatory_sessions_only: boolean;
  auto_issue_certificate: boolean;
  reminder_24h: boolean;
  reminder_1h: boolean;
  reminder_15m: boolean;
  reminder_channels: string[];
  qr_grace_period_minutes: number;
  qr_valid_before_start_minutes: number;
  min_virtual_duration_minutes: number;
  status: 'draft' | 'published' | 'ongoing' | 'completed' | 'cancelled';
  is_active: boolean;
  created_by: number;
  created_at: string;
  updated_at: string | null;
  published_at: string | null;
  // Computed fields
  session_count?: number;
  enrollment_count?: number;
  first_session_date?: string | null;
  last_session_date?: string | null;
  next_session_date?: string | null;
  creator_first_name?: string;
  creator_last_name?: string;
}

export interface TrainingSession {
  session_id: number;
  training_id: number;
  tenant_id: number;
  title: string;
  description: string | null;
  session_number: number;
  session_type: 'physical' | 'virtual' | 'hybrid';
  session_date: string;
  start_time: string;
  end_time: string;
  timezone: string;
  // Physical
  venue_name: string | null;
  venue_address: string | null;
  venue_room: string | null;
  venue_capacity: number | null;
  venue_map_url: string | null;
  // Virtual
  video_platform: 'zoom' | 'google_meet' | 'microsoft_teams' | null;
  meeting_id: string | null;
  meeting_password: string | null;
  meeting_url: string | null;
  join_url: string | null;
  host_url: string | null;
  integration_id: number | null;
  external_meeting_id: string | null;
  // Settings
  is_mandatory: boolean;
  allow_late_join: boolean;
  late_threshold_minutes: number;
  hybrid_physical_allowed: boolean;
  hybrid_virtual_allowed: boolean;
  // QR
  qr_code_token: string | null;
  qr_code_generated_at: string | null;
  qr_code_expires_at: string | null;
  // Status
  status: 'scheduled' | 'ongoing' | 'completed' | 'cancelled';
  attendance_locked: boolean;
  attendance_locked_at: string | null;
  actual_start_time: string | null;
  actual_end_time: string | null;
  created_at: string;
  // Computed
  instructors?: SessionInstructor[];
  present_count?: number;
  late_count?: number;
  absent_count?: number;
  expected_attendees?: number;
  attended_count?: number;
  training_title?: string;
  thumbnail_url?: string;
  attendance_status?: string;
}

export interface SessionInstructor {
  id: number;
  instructor_id?: number; // Alias for id
  session_id: number;
  user_id: number;
  role: 'primary' | 'co_instructor' | 'assistant';
  is_host: boolean;
  first_name: string;
  last_name: string;
  email: string;
  profile_image: string | null;
}

export interface TrainingEnrollment {
  enrollment_id: number;
  training_id: number;
  user_id: number;
  tenant_id: number;
  status: 'enrolled' | 'waitlisted' | 'cancelled' | 'completed';
  waitlist_position: number | null;
  sessions_attended: number;
  sessions_total: number;
  attendance_percentage: number;
  certification_status: 'pending' | 'eligible' | 'not_eligible' | 'issued' | 'revoked';
  certification_reason: string | null;
  certificate_id: number | null;
  certificate_issued_at: string | null;
  enrolled_at: string;
  enrolled_by: number | null;
  completed_at: string | null;
  cancelled_at: string | null;
  // User info
  first_name?: string;
  last_name?: string;
  email?: string;
  profile_image?: string | null;
  training_title?: string;
}

export interface SessionAttendance {
  attendance_id: number;
  session_id: number;
  user_id: number;
  enrollment_id: number;
  tenant_id: number;
  status: 'present' | 'late' | 'absent';
  attendance_mode: 'physical' | 'virtual' | null;
  qr_scanned_at: string | null;
  qr_scan_location: string | null;
  virtual_join_time: string | null;
  virtual_leave_time: string | null;
  virtual_duration_minutes: number | null;
  virtual_attendance_data: any;
  is_manual_override: boolean;
  override_by: number | null;
  override_at: string | null;
  override_reason: string | null;
  marked_at: string | null;
  created_at: string;
  // User info
  first_name?: string;
  last_name?: string;
  email?: string;
  profile_image?: string | null;
}

export interface VideoIntegration {
  integration_id: number;
  tenant_id: number;
  platform: 'zoom' | 'google_meet' | 'microsoft_teams';
  is_enabled: boolean;
  is_default: boolean;
  is_active: boolean;
  client_id: string | null;
  client_secret?: string | null;
  account_email?: string | null;
  token_expires_at: string | null;
  settings: Record<string, any>;
  connected_by: number | null;
  connected_at: string | null;
  last_refreshed_at: string | null;
}

export interface TrainingAnalytics {
  training: LiveTraining;
  sessions: {
    total: number;
    completed: number;
    remaining: number;
  };
  enrollments: {
    total: number;
    active: number;
    completed: number;
  };
  attendance: {
    present: number;
    late: number;
    absent: number;
    physical: number;
    virtual: number;
    average_percentage: number;
  };
  certification: {
    eligible: number;
    issued: number;
    not_eligible: number;
  };
}

export interface LearnerProgress {
  enrollment: TrainingEnrollment;
  sessions: Array<{
    session_id: number;
    title: string;
    session_date: string;
    start_time: string;
    end_time: string;
    session_type: string;
    session_status: string;
    is_mandatory: boolean;
    attendance_status: string | null;
    attendance_mode: string | null;
    marked_at: string | null;
  }>;
}

export interface TrainingFilters {
  status?: string;
  is_active?: number;
  training_type?: string;
  category_id?: number;
  search?: string;
  limit?: number;
  offset?: number;
}

export interface BulkEnrollResult {
  success: number[];
  failed: Array<{ user_id: number; error: string }>;
}


// ==========================================
// SERVICE
// ==========================================

@Injectable({
  providedIn: 'root'
})
export class LiveTrainingService {
  private apiUrl = GlobalComponent.liveTraining;
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
  // TRAINING CRUD
  // ==========================================

  // ==========================================
  // TRAINING CRUD
  // ==========================================

  createTraining(data: Partial<LiveTraining>): Observable<LiveTraining> {
    return this.http.post<ApiResponse<LiveTraining>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'create-training',
      ...data
    }).pipe(map(res => res.data));
  }

  updateTraining(training_id: number, data: Partial<LiveTraining>): Observable<LiveTraining> {
    return this.http.post<ApiResponse<LiveTraining>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'update-training',
      training_id,
      ...data
    }).pipe(map(res => res.data));
  }

  getTraining(training_id: number): Observable<LiveTraining> {
    return this.http.post<ApiResponse<LiveTraining>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-training',
      training_id
    }).pipe(map(res => res.data));
  }

  getTrainings(filters?: TrainingFilters): Observable<LiveTraining[]> {
    return this.http.post<ApiResponse<LiveTraining[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-trainings',
      ...filters
    }).pipe(map(res => res.data));
  }

  deleteTraining(training_id: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'delete-training',
      training_id
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // SESSION MANAGEMENT
  // ==========================================

  createSession(training_id: number, data: Partial<TrainingSession>): Observable<TrainingSession> {
    return this.http.post<ApiResponse<TrainingSession>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'create-session',
      training_id,
      ...data
    }).pipe(map(res => res.data));
  }

  updateSession(session_id: number, data: Partial<TrainingSession>): Observable<TrainingSession> {
    return this.http.post<ApiResponse<TrainingSession>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'update-session',
      session_id,
      ...data
    }).pipe(map(res => res.data));
  }

  getSession(session_id: number): Observable<TrainingSession> {
    return this.http.post<ApiResponse<TrainingSession>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-session',
      session_id
    }).pipe(map(res => res.data));
  }

  getTrainingSessions(training_id: number): Observable<TrainingSession[]> {
    return this.http.post<ApiResponse<TrainingSession[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-training-sessions',
      training_id
    }).pipe(map(res => res.data));
  }

  deleteSession(session_id: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'delete-session',
      session_id
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // INSTRUCTOR MANAGEMENT
  // ==========================================

  assignInstructor(session_id: number, instructor_id: number, role: string = 'primary', is_host: boolean = false): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'assign-instructor',
      session_id,
      instructor_id,
      role,
      is_host
    }).pipe(map(res => res.data));
  }

  removeInstructor(session_id: number, instructor_id: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'remove-instructor',
      session_id,
      instructor_id
    }).pipe(map(res => res.data));
  }

  getSessionInstructors(session_id: number): Observable<SessionInstructor[]> {
    return this.http.post<ApiResponse<SessionInstructor[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-session-instructors',
      session_id
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // ENROLLMENT MANAGEMENT
  // ==========================================

  enrollLearner(training_id: number, learner_id?: number): Observable<TrainingEnrollment> {
    return this.http.post<ApiResponse<TrainingEnrollment>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'enroll-learner',
      training_id,
      learner_id
    }).pipe(map(res => res.data));
  }

  bulkEnroll(training_id: number, learner_ids: number[]): Observable<BulkEnrollResult> {
    return this.http.post<ApiResponse<BulkEnrollResult>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'bulk-enroll',
      training_id,
      learner_ids
    }).pipe(map(res => res.data));
  }

  cancelEnrollment(enrollment_id: number, reason?: string): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'cancel-enrollment',
      enrollment_id,
      reason
    }).pipe(map(res => res.data));
  }

  getEnrollment(enrollment_id: number): Observable<TrainingEnrollment> {
    return this.http.post<ApiResponse<TrainingEnrollment>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-enrollment',
      enrollment_id
    }).pipe(map(res => res.data));
  }

  getMyEnrollments(): Observable<TrainingEnrollment[]> {
    return this.http.post<ApiResponse<TrainingEnrollment[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-my-enrollments'
    }).pipe(map(res => res.data));
  }

  getTrainingEnrollments(training_id: number, status?: string): Observable<TrainingEnrollment[]> {
    return this.http.post<ApiResponse<TrainingEnrollment[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-training-enrollments',
      training_id,
      status
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // ATTENDANCE TRACKING
  // ==========================================

  markAttendanceQR(qr_token: string, learner_id?: number): Observable<SessionAttendance> {
    return this.http.post<ApiResponse<SessionAttendance>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'mark-attendance-qr',
      qr_token,
      learner_id
    }).pipe(map(res => res.data));
  }

  markAttendanceVirtual(session_id: number, learner_id: number, virtual_data: {
    join_time?: string;
    leave_time?: string;
    duration_minutes: number;
  }): Observable<SessionAttendance> {
    return this.http.post<ApiResponse<SessionAttendance>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'mark-attendance-virtual',
      session_id,
      learner_id,
      ...virtual_data
    }).pipe(map(res => res.data));
  }

  overrideAttendance(session_id: number, learner_id: number, status: string, reason: string): Observable<SessionAttendance> {
    return this.http.post<ApiResponse<SessionAttendance>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'override-attendance',
      session_id,
      learner_id,
      status,
      reason
    }).pipe(map(res => res.data));
  }

  getSessionAttendance(session_id: number): Observable<SessionAttendance[]> {
    return this.http.post<ApiResponse<SessionAttendance[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-session-attendance',
      session_id
    }).pipe(map(res => res.data));
  }

  lockAttendance(session_id: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'lock-attendance',
      session_id
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // QR CODE
  // ==========================================

  generateQRCode(session_id: number): Observable<{ qr_token: string }> {
    return this.http.post<ApiResponse<{ qr_token: string }>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'generate-qr',
      session_id
    }).pipe(map(res => res.data));
  }

  getQRData(session_id: number): Observable<{
    qr_code_token: string;
    qr_code_generated_at: string;
    qr_code_expires_at: string;
  }> {
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-qr-data',
      session_id
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // CERTIFICATION
  // ==========================================

  checkEligibility(enrollment_id: number): Observable<TrainingEnrollment> {
    return this.http.post<ApiResponse<TrainingEnrollment>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'check-eligibility',
      enrollment_id
    }).pipe(map(res => res.data));
  }

  issueCertificate(enrollment_id: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'issue-certificate',
      enrollment_id
    }).pipe(map(res => res.data));
  }

  revokeCertificate(enrollment_id: number, reason: string): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'revoke-certificate',
      enrollment_id,
      reason
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // VIDEO INTEGRATIONS
  // ==========================================

  getIntegrations(): Observable<VideoIntegration[]> {
    return this.http.post<ApiResponse<VideoIntegration[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-integrations'
    }).pipe(map(res => res.data));
  }

  saveIntegration(data: Partial<VideoIntegration>): Observable<VideoIntegration> {
    return this.http.post<ApiResponse<VideoIntegration>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'save-integration',
      ...data
    }).pipe(map(res => res.data));
  }

  disconnectIntegration(platform: string): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'disconnect-integration',
      platform
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // REPORTING & ANALYTICS
  // ==========================================

  getTrainingAnalytics(training_id: number): Observable<TrainingAnalytics> {
    return this.http.post<ApiResponse<TrainingAnalytics>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-training-analytics',
      training_id
    }).pipe(map(res => res.data));
  }

  getLearnerProgress(training_id: number, learner_id?: number): Observable<LearnerProgress> {
    return this.http.post<ApiResponse<LearnerProgress>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-learner-progress',
      training_id,
      learner_id
    }).pipe(map(res => res.data));
  }

  getUpcomingSessions(limit: number = 10, learner_id?: number): Observable<TrainingSession[]> {
    return this.http.post<ApiResponse<TrainingSession[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-upcoming-sessions',
      limit,
      learner_id
    }).pipe(map(res => res.data));
  }

  exportAttendance(training_id: number): Observable<any[]> {
    return this.http.post<ApiResponse<any[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'export-attendance',
      training_id
    }).pipe(map(res => res.data));
  }

  // ==========================================
// ENHANCED ENROLLMENT METHODS
// ==========================================

  /**
   * Bulk enroll users by rule
   */
  bulkEnrollByRule(trainingId: number, rule: EnrollmentRule): Observable<BulkEnrollmentResult> {
    return this.http.post<ApiResponse<BulkEnrollmentResult>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'bulk-enroll-by-rule',
      training_id: trainingId,
      rule: rule
    }).pipe(map(res => res.data));
  }

  /**
   * Get enrollment rules for a training
   */
  getEnrollmentRules(trainingId: number): Observable<EnrollmentRule[]> {
    return this.http.post<ApiResponse<EnrollmentRule[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-enrollment-rules',
      training_id: trainingId
    }).pipe(map(res => res.data));
  }

  /**
   * Delete enrollment rule
   */
  deleteEnrollmentRule(ruleId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'delete-enrollment-rule',
      rule_id: ruleId
    }).pipe(map(res => res.data));
  }

  /**
   * Search users for enrollment with filters
   */
  searchUsersForEnrollment(filters: EnrollmentFilter): Observable<UserForEnrollment[]> {
    return this.http.post<ApiResponse<UserForEnrollment[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'search-users-for-enrollment',
      ...filters
    }).pipe(map(res => res.data));
  }

// ==========================================
// SKILLS MANAGEMENT METHODS
// ==========================================

  /**
   * Assign skills to training
   */
  assignSkills(trainingId: number, skills: TrainingSkill[]): Observable<SkillAssignmentResult> {
    return this.http.post<ApiResponse<SkillAssignmentResult>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'assign-skills',
      training_id: trainingId,
      skills: skills
    }).pipe(map(res => res.data));
  }

  /**
   * Remove skill from training
   */
  removeSkill(trainingId: number, skillId: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'remove-skill',
      training_id: trainingId,
      skill_id: skillId
    }).pipe(map(res => res.data));
  }

  /**
   * Get training skills
   */
  getTrainingSkills(trainingId: number): Observable<TrainingSkill[]> {
    return this.http.post<ApiResponse<TrainingSkill[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-training-skills',
      training_id: trainingId
    }).pipe(map(res => res.data));
  }

  /**
   * Get user acquired skills
   */
  getUserAcquiredSkills(userId?: number, sourceType?: string): Observable<UserSkill[]> {
    return this.http.post<ApiResponse<UserSkill[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-user-acquired-skills',
      user_id: userId,
      source_type: sourceType
    }).pipe(map(res => res.data));
  }

  /**
   * Get user skills summary
   */
  getUserSkillsSummary(userId?: number): Observable<UserSkillsSummary> {
    return this.http.post<ApiResponse<UserSkillsSummary>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-user-skills-summary',
      user_id: userId
    }).pipe(map(res => res.data));
  }

  /**
   * Search skills with filters
   */
  searchSkills(filters: SkillSearchFilter): Observable<Skill[]> {
    return this.http.post<ApiResponse<Skill[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'search-skills',
      ...filters
    }).pipe(map(res => res.data));
  }

  /**
   * Get skill categories
   */
  getSkillsCategories(): Observable<SkillCategory[]> {
    return this.http.post<ApiResponse<SkillCategory[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'get-skills-categories'
    }).pipe(map(res => res.data));
  }

  /**
   * Manually award skills (for admins)
   */
  awardSkillsManual(enrollmentId: number): Observable<SkillAssignmentResult> {
    return this.http.post<ApiResponse<SkillAssignmentResult>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'award-skills-manual',
      enrollment_id: enrollmentId
    }).pipe(map(res => res.data));
  }

// ==========================================
// HELPER METHODS
// ==========================================

  /**
   * Get proficiency level badge class
   */
  getProficiencyBadgeClass(level: string): string {
    const classes: { [key: string]: string } = {
      'beginner': 'badge-secondary',
      'intermediate': 'badge-info',
      'advanced': 'badge-primary',
      'expert': 'badge-success'
    };
    return classes[level] || 'badge-secondary';
  }

  /**
   * Get proficiency level display name
   */
  getProficiencyDisplayName(level: string): string {
    const names: { [key: string]: string } = {
      'beginner': 'Beginner',
      'intermediate': 'Intermediate',
      'advanced': 'Advanced',
      'expert': 'Expert'
    };
    return names[level] || level;
  }

  /**
   * Get enrollment type display name
   */
  getEnrollmentTypeDisplayName(type: string): string {
    const names: { [key: string]: string } = {
      'direct': 'Direct Selection',
      'department': 'Department-based',
      'branch': 'Branch-based',
      'team': 'Team-based',
      'automatic': 'Automatic'
    };
    return names[type] || type;
  }

  /**
   * Format skill source display
   */
  formatSkillSource(sourceType: string, sourceTitle?: string): string {
    const types: { [key: string]: string } = {
      'live_training': 'Live Training',
      'course': 'Course',
      'assessment': 'Assessment',
      'certification': 'Certification',
      'manual': 'Manual',
      'external': 'External'
    };

    const typeName = types[sourceType] || sourceType;
    return sourceTitle ? `${typeName}: ${sourceTitle}` : typeName;
  }

}
