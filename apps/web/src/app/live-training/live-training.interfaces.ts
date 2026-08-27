// live-training.interfaces.ts
// TypeScript interfaces for Live Training enhancement

/**
 * Enrollment Rule Interface
 */
export interface EnrollmentRule {
  rule_id?: number;
  training_id: number;
  tenant_id?: number;
  enrollment_type: 'direct' | 'department' | 'branch' | 'team' | 'automatic';
  target_criteria: {
    department_ids?: number[];
    branch_ids?: number[];
    team_ids?: number[];
    user_ids?: number[];
  };
  auto_enroll_new_users?: boolean;
  auto_enroll_on_hire?: boolean;
  is_active?: boolean;
  created_by?: number;
  created_by_first_name?: string;
  created_by_last_name?: string;
  created_at?: string;
  updated_by?: number;
  updated_at?: string;
}

/**
 * Bulk Enrollment Result Interface
 */
export interface BulkEnrollmentResult {
  total: number;
  enrolled: number;
  already_enrolled: number;
  waitlisted: number;
  failed: number;
  errors: Array<{
    user_id: number;
    name: string;
    email: string;
    error: string;
  }>;
  message: string;
}

/**
 * Training Skill Interface
 */
export interface TrainingSkill {
  training_skill_id?: number;
  training_id: number;
  skill_id: number;
  tenant_id?: number;
  skill_name?: string;
  category_id?: number;
  skill_category?: string;
  skill_description?: string;
  proficiency_level: 'beginner' | 'intermediate' | 'advanced' | 'expert';
  is_required: boolean;
  created_by?: number;
  created_at?: string;
  updated_at?: string;
}

/**
 * Skill Assignment Result Interface
 */
export interface SkillAssignmentResult {
  added: number;
  updated: number;
  skipped: number;
  errors: Array<{
    skill_id: number | string;
    error: string;
  }>;
}

/**
 * User Skill Interface
 */
export interface UserSkill {
  user_skill_id: number;
  user_id: number;
  skill_id: number;
  tenant_id: number;
  skill_name: string;
  category_id: number;
  skill_category: string;
  skill_description: string;
  source_type: 'course' | 'live_training' | 'assessment' | 'certification' | 'manual' | 'external';
  source_id: number | null;
  source_title?: string;
  proficiency_level: 'beginner' | 'intermediate' | 'advanced' | 'expert';
  verified_by: number | null;
  verified_at: string | null;
  expires_at: string | null;
  is_active: boolean;
  acquired_at: string;
  updated_at: string;
  notes: string | null;
}

/**
 * User Skills Summary Interface
 */
export interface UserSkillsSummary {
  total_skills: number;
  beginner_count: number;
  intermediate_count: number;
  advanced_count: number;
  expert_count: number;
  from_trainings: number;
  from_courses: number;
  verified_count: number;
}

/**
 * Skill Interface (for selection)
 */
export interface Skill {
  skill_id: number;
  skill_name: string;
  category_id: number;
  skill_category: string;
  skill_description: string;
  is_active: boolean;
}

/**
 * Skill Category Interface
 */
export interface SkillCategory {
  category_id: number;
  category_name: string;
  description?: string;
}

/**
 * User for Enrollment Interface
 */
export interface UserForEnrollment {
  user_id: number;
  first_name: string;
  last_name: string;
  email: string;
  department_id: number | null;
  department_name: string | null;
  branch_id: number | null;
  branch_name: string | null;
}

/**
 * Department Interface
 */
export interface Department {
  department_id: number;
  name: string;
  description?: string;
}

/**
 * Branch Interface
 */
export interface Branch {
  branch_id: number;
  name: string;
  location?: string;
}

/**
 * Team Interface
 */
export interface Team {
  team_id: number;
  name: string;
  description?: string;
}

/**
 * Enrollment Filter Interface
 */
export interface EnrollmentFilter {
  search?: string;
  department_id?: number;
  branch_id?: number;
  team_id?: number;
  training_id?: number;
  limit?: number;
}

/**
 * Skill Search Filter Interface
 */
export interface SkillSearchFilter {
  search?: string;
  category_id?: number;
  training_id?: number;
}

/**
 * API Response Interface (generic)
 */
export interface ApiResponse<T> {
  code: number;
  status: 'success' | 'error';
  data: T;
  message: string;
}


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

/** What `POST /me/trainings/check-in` answers with. */
export interface CheckInResult {
  sessionId: string;
  title: string;
  /** `present` or `late` — arriving late is still attendance. */
  status: string;
  message: string;
  summary: Record<string, unknown>;
}
