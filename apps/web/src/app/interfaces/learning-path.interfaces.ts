/**
 * Learning Path Module - TypeScript Interfaces
 * Multi-Tenant LMS
 */

// ============================================
// API RESPONSE WRAPPER
// ============================================

export interface ApiResponse<T> {
  code: number;
  status: string;
  data: T;
  message: string;
}

export interface Pagination {
  total: number;
  page: number;
  per_page: number;
  total_pages: number;
}

export interface PaginatedResponse<T> {
  data: T[];
  pagination: Pagination;
}

// ============================================
// LEARNING PATH
// ============================================

export interface LearningPath {
  path_id: string;
  tenant_id: string;
  title: string;
  slug: string;
  short_description: string;
  description: string;
  thumbnail_url: string | null;
  banner_url: string | null;
  category_id: string | null;
  category_name?: string;
  skill_level: SkillLevel;
  path_type: PathType;
  is_mandatory: boolean;
  enforce_prerequisites: boolean;
  allow_skip: boolean;
  estimated_duration_hours: number | null;
  estimated_weeks: number | null;
  weekly_commitment_hours: number | null;
  completion_type: CompletionType;
  completion_threshold: number;
  passing_score: number | null;
  certificate_enabled: boolean;
  certificate_template_id: string | null;
  certificate_validity_months: number | null;
  total_points: number;
  badge_id: string | null;
  status: PathStatus;
  visibility: Visibility;
  publish_date: string | null;
  unpublish_date: string | null;
  enrollment_type: EnrollmentType;
  max_enrollments: number | null;
  enrollment_start_date: string | null;
  enrollment_end_date: string | null;
  is_free: boolean;
  price: number;
  currency: string;
  tags: string[];
  target_audience: TargetAudience | null;
  learning_objectives: string[];
  prerequisites_description: string;
  total_items: number;
  total_courses: number;
  total_assessments: number;
  total_enrollments: number;
  total_completions: number;
  avg_completion_days: number | null;
  avg_rating: number | null;
  total_ratings: number;
  created_by: string | null;
  creator_first_name?: string;
  creator_last_name?: string;
  updated_by: string | null;
  created_at: string;
  updated_at: string | null;
  published_at: string | null;
  is_active: boolean;

  // Computed/joined fields
  items_count?: number;
  sections_count?: number;
}

export type SkillLevel = 'beginner' | 'intermediate' | 'advanced' | 'expert' | 'all_levels';
export type PathType = 'sequential' | 'flexible' | 'milestone';
export type PathStatus = 'draft' | 'published' | 'archived' | 'scheduled';
export type Visibility = 'public' | 'private' | 'restricted' | 'enrolled_only';
export type EnrollmentType = 'open' | 'approval_required' | 'invitation_only' | 'auto_enroll';
export type CompletionType = 'all_items' | 'percentage' | 'points' | 'assessment';

export interface TargetAudience {
  roles?: string[];
  departments?: string[];
  teams?: string[];
  levels?: string[];
}

export interface LearningPathCreateRequest {
  title: string;
  short_description: string;
  description?: string;
  thumbnail_url?: string;
  banner_url?: string;
  category_id?: string;
  skill_level?: SkillLevel;
  path_type?: PathType;
  is_mandatory?: boolean;
  enforce_prerequisites?: boolean;
  allow_skip?: boolean;
  estimated_duration_hours?: number;
  estimated_weeks?: number;
  weekly_commitment_hours?: number;
  completion_type?: CompletionType;
  completion_threshold?: number;
  passing_score?: number;
  certificate_enabled?: boolean;
  certificate_template_id?: string;
  certificate_validity_months?: number;
  total_points?: number;
  badge_id?: string;
  visibility?: Visibility;
  enrollment_type?: EnrollmentType;
  max_enrollments?: number;
  enrollment_start_date?: string;
  enrollment_end_date?: string;
  is_free?: boolean;
  price?: number;
  currency?: string;
  tags?: string[];
  target_audience?: TargetAudience;
  learning_objectives?: string[];
  prerequisites_description?: string;
}

// UPDATE REQUEST - Same as Create but all fields optional
export interface LearningPathUpdateRequest extends Partial<LearningPathCreateRequest> {}

export interface LearningPathListParams {
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
  search?: string;
  status?: PathStatus;
  skill_level?: SkillLevel;
  category_id?: string;
  visibility?: Visibility;
  is_mandatory?: boolean;
}

export interface LearningPathListResponse {
  data: LearningPath[];
  pagination: Pagination;
}

// ============================================
// LEARNING PATH SECTION
// ============================================

export interface LearningPathSection {
  section_id: string;
  path_id: string;
  tenant_id: string;
  title: string;
  description: string;
  sort_order: number;
  is_milestone: boolean;
  milestone_title?: string;
  unlock_type: SectionUnlockType;
  unlock_after_section_id?: string | null;
  unlock_date: string | null;
  completion_required: boolean;
  min_items_to_complete: number | null;
  total_items: number;
  estimated_duration_hours: number | null;
  created_at: string;
  updated_at: string | null;
  is_active: boolean;

  // Nested items
  items?: LearningPathItem[];
}

export type SectionUnlockType = 'immediate' | 'previous_section' | 'sequential' | 'date' | 'manual';

export interface SectionCreateRequest {
  path_id: string;
  title: string;
  description?: string;
  sort_order?: number;
  is_milestone?: boolean;
  milestone_title?: string;
  unlock_type?: SectionUnlockType;
  unlock_after_section_id?: string;
  unlock_date?: string;
  completion_required?: boolean;
  min_items_to_complete?: number;
}

// UPDATE REQUEST - Same as Create but all fields optional except identifiers
export interface SectionUpdateRequest extends Partial<Omit<SectionCreateRequest, 'path_id'>> {}

// ============================================
// LEARNING PATH ITEM
// ============================================

export interface LearningPathItem {
  item_id: string;
  path_id: string;
  section_id: string | null;
  tenant_id: string;
  item_type: ItemType;
  course_id: string | null;
  assessment_id: string | null;
  live_session_id: string | null;
  media_id: string | null;
  external_url: string;
  external_title: string;
  external_description: string;
  sort_order: number;
  is_required: boolean;
  is_locked: boolean;
  unlock_type: ItemUnlockType;
  unlock_after_item_ids: string[] | null;
  unlock_after_item_id?: string | null;
  unlock_date: string | null;
  unlock_min_score: number | null;
  unlock_score?: number | null;
  completion_type: ItemCompletionType;
  min_time_spent_minutes: number | null;
  passing_score: number | null;
  max_attempts: number | null;
  points_value: number;
  bonus_points: number;
  bonus_deadline: string | null;
  due_date: string | null;
  due_days_from_enrollment: number | null;
  estimated_duration_minutes: number | null;
  instructor_notes: string;
  learner_notes: string;
  created_at: string;
  updated_at: string | null;
  is_active: boolean;

  // Joined fields
  display_title?: string;
  display_thumbnail?: string;
  course_title?: string;
  course_thumbnail?: string;
  assessment_title?: string;
  session_title?: string;
  media_name?: string;
  media_url?: string;
  section_title?: string;
}

export type ItemType = 'course' | 'assessment' | 'live_session' | 'external_link' | 'document' | 'video' | 'milestone' | 'checkpoint';
export type ItemUnlockType = 'immediate' | 'previous_item' | 'sequential' | 'specific_items' | 'date' | 'score' | 'manual';
export type ItemCompletionType = 'view' | 'complete' | 'pass' | 'time_spent' | 'manual';

export interface ItemCreateRequest {
  path_id: string;
  section_id?: string;
  item_type: ItemType;
  course_id?: string;
  assessment_id?: string;
  live_session_id?: string;
  media_id?: string;
  external_url?: string;
  external_title?: string;
  external_description?: string;
  sort_order?: number;
  is_required?: boolean;
  unlock_type?: ItemUnlockType;
  unlock_after_item_ids?: number[];
  unlock_after_item_id?: string;
  unlock_date?: string;
  unlock_min_score?: number;
  unlock_score?: number;
  completion_type?: ItemCompletionType;
  min_time_spent_minutes?: number;
  passing_score?: number;
  max_attempts?: number;
  points_value?: number;
  bonus_points?: number;
  bonus_deadline?: string;
  due_date?: string;
  due_days_from_enrollment?: number;
  estimated_duration_minutes?: number;
  instructor_notes?: string;
  learner_notes?: string;
}

// UPDATE REQUEST - Same as Create but all fields optional except identifiers
export interface ItemUpdateRequest extends Partial<Omit<ItemCreateRequest, 'path_id' | 'item_type'>> {}

export interface ItemReorderRequest {
  item_id: string;
  sort_order: number;
  section_id?: string | null;
}

// ============================================
// LEARNING PATH ENROLLMENT
// ============================================

export interface LearningPathEnrollment {
  enrollment_id: string;
  path_id: string;
  user_id: string;
  tenant_id: string;
  status: EnrollmentStatus;
  enrollment_type: UserEnrollmentType;
  enrolled_by: string | null;
  enrolled_at: string;
  started_at: string | null;
  completed_at: string | null;
  due_date: string | null;
  expiry_date: string | null;
  withdrawn_at: string | null;
  progress_percentage: number;
  items_completed: number;
  items_total: number;
  current_item_id: string | null;
  current_section_id: string | null;
  total_time_spent_minutes: number;
  last_accessed_at: string | null;
  total_points_earned: number;
  avg_assessment_score: number | null;
  completion_type: string | null;
  completed_by: string | null;
  certificate_id: string | null;
  certificate_issued_at: string | null;
  certificate_expiry_date: string | null;
  admin_notes: string;
  learner_notes: string;
  created_at: string;
  updated_at: string | null;
  is_active: boolean;

  // Joined fields
  path_title?: string;
  path_thumbnail?: string;
  skill_level?: SkillLevel;
  path_type?: PathType;
  first_name?: string;
  last_name?: string;
  email?: string;
  profile_image_url?: string;
  enrolled_by_first_name?: string;
  enrolled_by_last_name?: string;
  is_overdue?: boolean;
  days_until_due?: number;
}

export type EnrollmentStatus = 'pending' | 'active' | 'completed' | 'expired' | 'withdrawn' | 'suspended';
export type UserEnrollmentType = 'self' | 'admin' | 'manager' | 'auto' | 'bulk';

export interface EnrollRequest {
  path_id: string;
  user_id?: string;
  user_ids?: number[];
  enrollment_type?: UserEnrollmentType;
  due_date?: string;
  expiry_date?: string;
  admin_notes?: string;
}

export interface EnrollmentListParams {
  page?: number;
  per_page?: number;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
  search?: string;
  status?: EnrollmentStatus;
  path_id?: string;
  user_id?: string;
}

export interface EnrollmentListResponse {
  data: LearningPathEnrollment[];
  pagination: Pagination;
}

export interface BulkEnrollResult {
  success: number[];
  failed: { user_id: string; error: string }[];
  already_enrolled: number[];
}

// ============================================
// LEARNING PATH PROGRESS
// ============================================

export interface LearningPathItemProgress {
  progress_id: string;
  enrollment_id: string;
  item_id: string;
  user_id: string;
  tenant_id: string;
  status: ItemProgressStatus;
  progress_percentage: number;
  unlocked_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  due_date: string | null;
  time_spent_minutes: number;
  last_accessed_at: string | null;
  attempts: number;
  best_score: number | null;
  last_score: number | null;
  passed: boolean | null;
  points_earned: number;
  bonus_earned: number;
  course_enrollment_id: string | null;
  assessment_attempt_id: string | null;
  notes: string;
  feedback: string;
  created_at: string;
  updated_at: string | null;

  // Joined fields from item
  item_type?: ItemType;
  completion_type?: ItemCompletionType;
  passing_score?: number | null;
  max_attempts?: number | null;
  points_value?: number;
  display_title?: string;
  display_thumbnail?: string;
  course_id?: string;
  assessment_id?: string;
  external_url?: string;
  is_required?: boolean;
  section_id?: string;
  section_title?: string;
  estimated_duration_minutes?: number | null;
}

export type ItemProgressStatus = 'locked' | 'available' | 'in_progress' | 'completed' | 'failed' | 'skipped';

export interface SectionProgress {
  progress_id: string;
  enrollment_id: string;
  section_id: string;
  user_id: string;
  tenant_id: string;
  status: SectionProgressStatus;
  progress_percentage: number;
  items_completed: number;
  items_total: number;
  unlocked_at: string | null;
  started_at: string | null;
  completed_at: string | null;
  time_spent_minutes: number;
  created_at: string;
  updated_at: string | null;

  // Joined fields
  section_title?: string;
  sort_order?: number;
}

export type SectionProgressStatus = 'locked' | 'available' | 'in_progress' | 'completed';

export interface ProgressBySection {
  sections: {
    section: SectionProgress;
    items: LearningPathItemProgress[];
  }[];
  unsectioned_items: LearningPathItemProgress[];
}

export interface CompleteItemRequest {
  score?: number;
  time_spent?: number;
}

export interface CompleteItemResponse {
  success: boolean;
  error?: string;
  score?: number;
  passed?: boolean;
  points_earned?: number;
  bonus_earned?: number;
  path_completed?: boolean;
  attempts_remaining?: number;
}

// ============================================
// LEARNING PATH REVIEW
// ============================================

export interface LearningPathReview {
  review_id: string;
  path_id: string;
  user_id: string;
  enrollment_id: string;
  tenant_id: string;
  rating: number;
  title: string;
  review_text: string;
  content_rating: number | null;
  difficulty_rating: number | null;
  practical_value_rating: number | null;
  status: ReviewStatus;
  moderation_status: ModerationStatus;  // Added
  moderated_by: string | null;
  moderated_at: string | null;
  rejection_reason: string;
  helpful_count: number;
  reported_count: number;
  instructor_response: string | null;  // Added
  response_text: string;
  response_by: string | null;
  response_at: string | null;
  created_at: string;
  updated_at: string | null;
  is_active: boolean;

  // Joined fields
  first_name?: string;
  last_name?: string;
  profile_image_url?: string;
  path_title?: string;
  response_by_first_name?: string;
  response_by_last_name?: string;
}

export type ReviewStatus = 'pending' | 'approved' | 'rejected' | 'flagged';
export type ModerationStatus = 'pending' | 'approved' | 'rejected' | 'flagged';

export interface ReviewCreateRequest {
  path_id: string;
  rating: number;
  title?: string;
  review_text?: string;
  content_rating?: number;
  difficulty_rating?: number;
  practical_value_rating?: number;
}

export interface RatingSummary {
  total_reviews: number;
  avg_rating: number | null;
  avg_content_rating: number | null;
  avg_difficulty_rating: number | null;
  avg_practical_value_rating: number | null;
  five_star: number;
  four_star: number;
  three_star: number;
  two_star: number;
  one_star: number;
  five_star_pct?: number;
  four_star_pct?: number;
  three_star_pct?: number;
  two_star_pct?: number;
  one_star_pct?: number;
  // Distribution by star rating (1-5)
  distribution: { [key: number]: number };
}

export interface ReviewListResponse {
  data: LearningPathReview[];
  pagination: Pagination;
}

// ============================================
// LEARNING PATH ASSIGNMENT
// ============================================

export interface LearningPathAssignment {
  assignment_id: string;
  path_id: string;
  tenant_id: string;
  assignment_type: AssignmentType;
  user_id: string | null;
  // Ids are UUIDs, and were typed as numbers here from the days when they
  // were not. A rule targeting department 0 is what that produced.
  role_id: string | null;
  department_id: string | null;
  team_id: string | null;
  branch_id?: string | null;
  assigned_by: string | null;
  assigned_at: string;
  due_date: string | null;
  due_days_from_assignment: number | null;
  priority: AssignmentPriority;
  is_mandatory: boolean;
  send_notification: boolean;
  send_reminder: boolean;
  reminder_days_before: number;
  assignment_notes: string;
  status: AssignmentStatus;
  created_at: string;
  updated_at: string | null;
  is_active: boolean;

  // Joined fields
  path_title?: string;
  path_thumbnail?: string;
  assigned_by_first_name?: string;
  assigned_by_last_name?: string;
  users_enrolled?: number;
  target_description?: string;  // Added - describes the assignment target (e.g., "Engineering Department", "All Users")

  // Target name fields for display
  user_name?: string;
  role_name?: string;
  department_name?: string;
  team_name?: string;
  branch_name?: string;
}

export type AssignmentType = 'user' | 'role' | 'department' | 'team' | 'branch' | 'all_users';
export type AssignmentPriority = 'low' | 'normal' | 'high' | 'critical';
export type AssignmentStatus = 'active' | 'paused' | 'completed' | 'cancelled';

export interface AssignmentCreateRequest {
  path_id: string;
  assignment_type: AssignmentType;
  user_id?: string;
  /** More than one, when people were picked by hand. */
  user_ids?: string[];
  role_id?: string;
  department_id?: string;
  team_id?: string;
  branch_id?: string;
  due_date?: string;
  due_days_from_assignment?: number;
  priority?: AssignmentPriority;
  is_mandatory?: boolean;
  send_notification?: boolean;
  send_reminder?: boolean;
  reminder_days_before?: number;
  assignment_notes?: string;
}

export interface AssignmentCreateResponse {
  success: boolean;
  assignment_id?: string;
  enrollments_created?: number;
  users_enrolled?: number[];
  users_skipped?: { user_id: string; reason: string }[];
  error?: string;
}

export interface AssignmentListResponse {
  data: LearningPathAssignment[];
  pagination: Pagination;
}

// ============================================
// LEARNING PATH STATISTICS
// ============================================

export interface LearningPathStatistics {
  total_enrollments: number;  // Added
  active_enrollments: number;
  completed_enrollments: number;
  withdrawn_enrollments: number;
  avg_progress: number;
  avg_completion_days: number | null;
  avg_time_spent: number;
  total_points_awarded: number;
  avg_rating: number | null;
  total_reviews: number;
  overdue_count: number;
}

export interface EnrollmentStatistics {
  total_enrollments: number;
  active_enrollments: number;
  completed_enrollments: number;
  withdrawn_enrollments: number;
  avg_progress: number;
  avg_time_spent: number;
  avg_completion_days: number | null;
  overdue_count: number;
}

// ============================================
// UI HELPERS
// ============================================

export interface PathCardData {
  path_id: string;
  title: string;
  slug: string;
  short_description: string;
  thumbnail_url: string | null;
  skill_level: SkillLevel;
  estimated_duration_hours: number | null;
  total_items: number;
  total_enrollments: number;
  avg_rating: number | null;
  total_ratings: number;
  is_free: boolean;
  price: number;
  currency: string;
  category_name?: string;
  enrollment_status?: EnrollmentStatus;
  progress_percentage?: number;
}

export interface PathDetailData extends LearningPath {
  sections: LearningPathSection[];
  items: LearningPathItem[];
  prerequisites: LearningPathPrerequisite[];
  user_enrollment?: LearningPathEnrollment;
  user_progress?: ProgressBySection;
  rating_summary?: RatingSummary;
}

export interface LearningPathPrerequisite {
  prereq_id: number;
  path_id: string;
  tenant_id: string;
  prereq_type: PrerequisiteType;
  required_path_id: number | null;
  required_course_id: number | null;
  required_assessment_id: number | null;
  required_badge_id: number | null;
  required_level: number | null;
  custom_requirement_description: string | null;
  is_mandatory: boolean;
  min_score: number | null;
  is_active: boolean;

  // Joined fields
  required_path_title?: string;
  required_course_title?: string;
}

export type PrerequisiteType = 'path' | 'course' | 'assessment' | 'badge' | 'level' | 'custom';

// ============================================
// SKILL LEVEL CONFIG
// ============================================

export const SKILL_LEVELS: { value: SkillLevel; label: string; color: string }[] = [
  { value: 'beginner', label: 'Beginner', color: 'success' },
  { value: 'intermediate', label: 'Intermediate', color: 'info' },
  { value: 'advanced', label: 'Advanced', color: 'warning' },
  { value: 'expert', label: 'Expert', color: 'danger' },
  { value: 'all_levels', label: 'All Levels', color: 'secondary' }
];

export const PATH_STATUSES: { value: PathStatus; label: string; color: string }[] = [
  { value: 'draft', label: 'Draft', color: 'secondary' },
  { value: 'published', label: 'Published', color: 'success' },
  { value: 'archived', label: 'Archived', color: 'warning' },
  { value: 'scheduled', label: 'Scheduled', color: 'info' }
];

export const ITEM_TYPES: { value: ItemType; label: string; icon: string }[] = [
  { value: 'course', label: 'Course', icon: 'bi-collection-play' },
  { value: 'assessment', label: 'Assessment', icon: 'bi-clipboard-check' },
  { value: 'live_session', label: 'Live Session', icon: 'bi-camera-video' },
  { value: 'external_link', label: 'External Link', icon: 'bi-link-45deg' },
  { value: 'document', label: 'Document', icon: 'bi-file-earmark-text' },
  { value: 'video', label: 'Video', icon: 'bi-play-circle' },
  { value: 'milestone', label: 'Milestone', icon: 'bi-flag' },
  { value: 'checkpoint', label: 'Checkpoint', icon: 'bi-check2-circle' }
];

export const PROGRESS_STATUSES: { value: ItemProgressStatus; label: string; color: string; icon: string }[] = [
  { value: 'locked', label: 'Locked', color: 'secondary', icon: 'bi-lock' },
  { value: 'available', label: 'Available', color: 'primary', icon: 'bi-unlock' },
  { value: 'in_progress', label: 'In Progress', color: 'info', icon: 'bi-hourglass-split' },
  { value: 'completed', label: 'Completed', color: 'success', icon: 'bi-check-circle-fill' },
  { value: 'failed', label: 'Failed', color: 'danger', icon: 'bi-x-circle' },
  { value: 'skipped', label: 'Skipped', color: 'warning', icon: 'bi-skip-forward' }
];

export const ASSIGNMENT_TYPES: { value: AssignmentType; label: string; icon: string }[] = [
  { value: 'user', label: 'Individual User', icon: 'bi-person' },
  { value: 'role', label: 'By Role', icon: 'bi-person-badge' },
  { value: 'department', label: 'By Department', icon: 'bi-building' },
  { value: 'team', label: 'By Team', icon: 'bi-people' },
  { value: 'branch', label: 'By Branch', icon: 'bi-geo-alt' },
  { value: 'all_users', label: 'All Users', icon: 'bi-people-fill' }
];

export const ASSIGNMENT_PRIORITIES: { value: AssignmentPriority; label: string; color: string }[] = [
  { value: 'low', label: 'Low', color: 'secondary' },
  { value: 'normal', label: 'Normal', color: 'primary' },
  { value: 'high', label: 'High', color: 'warning' },
  { value: 'critical', label: 'Critical', color: 'danger' }
];

// ============================================
// ASSIGNMENT STATISTICS
// ============================================

export interface AssignmentStatistics {
  total_enrollments: number;
  completed_enrollments: number;
  in_progress_enrollments: number;
  not_started_enrollments: number;
  overdue_enrollments: number;
  avg_progress: number;
  avg_completion_days: number;
  on_track_count: number;
  at_risk_count: number;
}
