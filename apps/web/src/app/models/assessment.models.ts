// models/assessment.models.ts
// Shared TypeScript Models for Proctored Assessment Module

// ==========================================
// ENUMS
// ==========================================

export enum QuestionType {
  MULTIPLE_CHOICE = 'multiple_choice',
  MULTIPLE_RESPONSE = 'multiple_response',
  TRUE_FALSE = 'true_false',
  SHORT_ANSWER = 'short_answer',
  LONG_ANSWER = 'long_answer',
  FILL_BLANK = 'fill_in_blank',
  MATCHING = 'matching',
  ORDERING = 'ordering',
  HOTSPOT = 'hotspot',
  FILE_UPLOAD = 'file_upload',
  NUMERIC = 'numeric'
}

export enum AssessmentStatus {
  DRAFT = 'draft',
  PUBLISHED = 'published',
  ARCHIVED = 'archived',
  CLOSED = 'closed'
}

export enum AttemptStatus {
  NOT_STARTED = 'not_started',
  IN_PROGRESS = 'in_progress',
  PAUSED = 'paused',
  SUBMITTED = 'submitted',
  AUTO_SUBMITTED = 'auto_submitted',
  GRADING = 'grading',
  GRADED = 'graded',
  ABANDONED = 'abandoned'
}

export enum ProctoringLevel {
  NONE = 'none',
  BASIC = 'basic',
  STANDARD = 'standard',
  ADVANCED = 'advanced'
}

export enum ViolationType {
  TAB_SWITCH = 'tab_switch',
  WINDOW_BLUR = 'window_blur',
  FULLSCREEN_EXIT = 'fullscreen_exit',
  COPY_ATTEMPT = 'copy_attempt',
  PASTE_ATTEMPT = 'paste_attempt',
  RIGHT_CLICK = 'right_click',
  PRINT_SCREEN = 'print_screen',
  DEV_TOOLS_OPEN = 'dev_tools_open',
  NO_FACE_DETECTED = 'no_face_detected',
  MULTIPLE_FACES = 'multiple_faces',
  LOOKING_AWAY = 'looking_away'
}

export enum CertificateStatus {
  ACTIVE = 'active',
  REVOKED = 'revoked',
  EXPIRED = 'expired',
  SUPERSEDED = 'superseded'
}

// ==========================================
// QUESTION INTERFACES
// ==========================================

export interface Question {
  question_id: number;
  bank_id: number;
  question_type: QuestionType;
  question_text: string;
  question_html: string;
  default_points: number;
  difficulty: 'easy' | 'medium' | 'hard';
  explanation?: string;
  tags?: string[];
  options?: QuestionOption[];
  correct_answer?: any;
  settings?: QuestionSettings;
}

export interface QuestionOption {
  option_id: number;
  option_text: string;
  option_html?: string;
  is_correct: boolean;
  feedback?: string;
  sort_order: number;
}

export interface QuestionSettings {
  shuffle_options?: boolean;
  case_sensitive?: boolean;
  partial_credit?: boolean;
  min_words?: number;
  max_words?: number;
  allowed_file_types?: string[];
  max_file_size_mb?: number;
  tolerance?: number;
  tolerance_type?: 'absolute' | 'percentage';
}

// ==========================================
// ASSESSMENT INTERFACES
// ==========================================

export interface Assessment {
  assessment_id: number;
  title: string;
  description?: string;
  instructions?: string;
  status: AssessmentStatus;
  time_limit_minutes?: number;
  passing_score: number;
  max_attempts: number;
  shuffle_questions: boolean;
  shuffle_options: boolean;
  show_results: boolean;
  show_answers: boolean;
  proctoring_level: ProctoringLevel;
  total_questions: number;
  total_points: number;
  sections?: AssessmentSection[];
}

export interface AssessmentSection {
  section_id: number;
  title: string;
  description?: string;
  questions: AssessmentQuestion[];
  time_limit_minutes?: number;
  sort_order: number;
}

export interface AssessmentQuestion {
  aq_id: number;
  question_id: number;
  question: Question;
  points: number;
  required: boolean;
  sort_order: number;
}

// ==========================================
// ATTEMPT INTERFACES
// ==========================================

export interface Attempt {
  attempt_id: number;
  assessment_id: number;
  user_id: number;
  attempt_number: number;
  status: AttemptStatus;
  started_at: string;
  submitted_at?: string;
  time_spent_seconds: number;
  time_remaining_seconds?: number;
  raw_score: number;
  max_score: number;
  percentage_score: number;
  passed: boolean;
  letter_grade?: string;
  answers: AttemptAnswer[];
  current_question_index: number;
}

export interface AttemptAnswer {
  answer_id: number;
  question_id: number;
  question_type: QuestionType;
  answer_data: any;
  is_answered: boolean;
  is_correct?: boolean;
  is_partial?: boolean;
  points_earned: number;
  max_points: number;
  time_spent_seconds: number;
  flagged: boolean;
  feedback?: string;
}

// ==========================================
// PROCTORING INTERFACES
// ==========================================

export interface ProctoringSession {
  session_id: number;
  attempt_id: number;
  status: 'pending_check' | 'system_check' | 'active' | 'paused' | 'completed' | 'terminated';
  trust_score: number;
  total_violations: number;
  system_check_passed: boolean;
  started_at?: string;
  ended_at?: string;
}

export interface ProctoringViolation {
  violation_id: number;
  violation_type: ViolationType;
  severity: 'low' | 'medium' | 'high' | 'critical';
  description: string;
  timestamp: string;
  acknowledged: boolean;
}

export interface ProctoringSettings {
  proctoring_level: ProctoringLevel;
  prevent_tab_switch: boolean;
  prevent_copy_paste: boolean;
  require_fullscreen: boolean;
  require_webcam: boolean;
  face_detection_enabled: boolean;
  max_tab_switches: number;
  violation_threshold: number;
  auto_submit_on_violations: boolean;
}

// ==========================================
// GRADING INTERFACES
// ==========================================

export interface Rubric {
  rubric_id: number;
  title: string;
  rubric_type: 'analytic' | 'holistic' | 'checklist';
  max_points: number;
  criteria: RubricCriterion[];
}

export interface RubricCriterion {
  criterion_id: number;
  title: string;
  description?: string;
  weight: number;
  max_points: number;
  levels: RubricLevel[];
}

export interface RubricLevel {
  level_id: number;
  title: string;
  description?: string;
  points: number;
  color?: string;
}

export interface GradingQueueItem {
  queue_id: number;
  answer_id: number;
  attempt_id: number;
  question_type: QuestionType;
  question_text: string;
  max_points: number;
  status: 'pending' | 'in_progress' | 'graded' | 'skipped';
  priority: 'low' | 'normal' | 'high' | 'urgent';
  learner_name: string;
  assessment_title: string;
  answer_data: any;
}

// ==========================================
// CERTIFICATE INTERFACES
// ==========================================

export interface Certificate {
  certificate_id: number;
  certificate_number: string;
  verification_code: string;
  learner_name: string;
  assessment_title: string;
  percentage: number;
  grade?: string;
  issued_at: string;
  valid_until?: string;
  status: CertificateStatus;
  verification_url: string;
}

// ==========================================
// REPORT INTERFACES
// ==========================================

export interface DashboardStats {
  total_assessments: number;
  active_learners: number;
  overall_pass_rate: number;
  total_certificates: number;
  pending_grading: number;
  attempts_today: number;
}

export interface QuestionStatistics {
  question_id: number;
  difficulty_index: number;
  difficulty_label: 'very_easy' | 'easy' | 'moderate' | 'hard' | 'very_hard';
  discrimination_index: number;
  discrimination_label: 'poor' | 'fair' | 'good' | 'excellent';
  correct_rate: number;
  avg_time_seconds: number;
  needs_review: boolean;
  review_reason?: string;
}

// ==========================================
// UI HELPER INTERFACES
// ==========================================

export interface SelectOption {
  value: any;
  label: string;
  disabled?: boolean;
  icon?: string;
}

export interface TableColumn {
  key: string;
  label: string;
  sortable?: boolean;
  type?: 'text' | 'number' | 'date' | 'badge' | 'actions';
  width?: string;
  align?: 'left' | 'center' | 'right';
}

export interface PaginationConfig {
  currentPage: number;
  pageSize: number;
  totalItems: number;
  pageSizeOptions: number[];
}

export interface ConfirmDialogData {
  title: string;
  message: string;
  confirmText?: string;
  cancelText?: string;
  type?: 'info' | 'warning' | 'danger';
}

export interface ToastMessage {
  type: 'success' | 'error' | 'warning' | 'info';
  title?: string;
  message: string;
  duration?: number;
}

// ==========================================
// API RESPONSE INTERFACE
// ==========================================

export interface ApiResponse<T> {
  response_code: number;
  status: 'success' | 'error';
  data: T;
  message: string;
}
