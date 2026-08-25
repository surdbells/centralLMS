import { decodeSessionBlob } from '../core/utils/session-blob';
// assessment.service.ts
// Angular Service for Assessment API
// Multi-Tenant LMS - Proctored Assessment Module

import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import {GlobalComponent} from '../global-component';

// ==========================================
// INTERFACES
// ==========================================

export type AssessmentStatus = 'draft' | 'published' | 'closed' | 'archived';
export type ProctoringLevel = 'none' | 'basic' | 'standard' | 'advanced';
export type QuestionsPerPage = 'all' | 'one' | 'section';
export type QuestionSelection = 'manual' | 'random' | 'pool';
export type PassingType = 'percentage' | 'points';
export type ShowCorrectAnswers = 'never' | 'immediately' | 'after_deadline' | 'after_all_attempts';

export interface Assessment {
  assessment_id: number;
  tenant_id: number;
  title: string;
  description: string | null;
  instructions: string | null;
  thumbnail: string | null;

  // Linking
  course_id: number | null;
  live_training_id: number | null;
  is_standalone: boolean;
  course_title?: string | null;
  training_title?: string | null;

  // Timing
  time_limit_minutes: number | null;
  time_limit_per_question_seconds: number | null;
  has_time_limit: boolean;

  // Availability
  available_from: string | null;
  available_until: string | null;
  grace_period_minutes: number;

  // Attempts
  max_attempts: number | null;
  attempt_count: number | null;
  attempts_gap_hours: number;

  // Scoring
  passing_score: number;
  passing_type: PassingType;
  total_points: number;
  show_score_immediately: boolean;

  // Question Selection
  question_selection: QuestionSelection;
  random_question_count: number | null;
  shuffle_questions: boolean;
  shuffle_options: boolean;

  // Display
  questions_per_page: QuestionsPerPage;
  allow_backward_navigation: boolean;
  show_question_numbers: boolean;
  show_progress_bar: boolean;
  show_timer: boolean;
  allow_mark_for_review: boolean;

  // Results
  show_correct_answers: ShowCorrectAnswers;
  show_explanations: boolean;
  show_score_breakdown: boolean;

  // Certificate
  issue_certificate: boolean;
  certificate_template_id: number | null;

  // Proctoring & Grading
  proctoring_level: ProctoringLevel;
  auto_publish_results: boolean;
  require_manual_grading: boolean;

  // Status
  status: AssessmentStatus;
  published_at: string | null;

  // Metadata
  total_questions: number;
  estimated_duration_minutes: number | null;

  created_by: number | null;
  created_by_name?: string | null;
  created_at: string;
  updated_at: string | null;

  // Stats (when loaded)
  assignment_count?: number;
  learners_attempted?: number;
  learners_passed?: number;

  // Sections (when loaded with details)
  sections?: AssessmentSection[];
}

export interface AssessmentSection {
  section_id: number;
  assessment_id: number;
  tenant_id: number;
  title: string;
  description: string | null;
  instructions: string | null;
  time_limit_minutes: number | null;
  selection_type: 'all' | 'random';
  random_count: number | null;
  sort_order: number;
  question_count: number;
  total_points: number;
  created_at: string;
  updated_at: string | null;
}

export interface AssessmentQuestion {
  assessment_question_id: number;
  assessment_id: number;
  section_id: number | null;
  question_id: number;
  tenant_id: number;
  points_override: number | null;
  time_limit_override: number | null;
  required: boolean;
  sort_order: number;
  created_at: string;

  // From joined question
  question_type: string;
  question_text: string;
  difficulty: string;
  default_points: number;
  effective_points: number;
  estimated_time_seconds: number;
  question_status: string;
  section_title?: string | null;
}

export interface AssessmentAssignment {
  assignment_id: number;
  tenant_id: number;
  assessment_id: number;
  user_id: number | null;
  department_id: number | null;
  team_id: number | null;
  branch_id: number | null;
  assign_all: boolean;
  available_from_override: string | null;
  available_until_override: string | null;
  max_attempts_override: number | null;
  is_mandatory: boolean;
  due_date: string | null;
  notify_on_assign: boolean;
  reminder_days_before: number | null;
  assigned_by: number | null;
  assigned_at: string;

  // Joined data
  first_name?: string;
  last_name?: string;
  email?: string;
  department_name?: string | null;
  team_name?: string | null;
  branch_name?: string | null;
  assigned_by_name?: string | null;
}

export interface GradingScale {
  scale_id: number;
  tenant_id: number;
  name: string;
  description: string | null;
  is_default: boolean;
  grades: GradeLevel[];
  created_by: number | null;
  created_at: string;
  updated_at: string | null;
}

export interface GradeLevel {
  grade: string;
  min: number;
  max: number;
  gpa?: number;
}

export interface AssessmentStats {
  total_attempts: number;
  unique_learners: number;
  graded_attempts: number;
  passed_attempts: number;
  pass_rate: number;
  avg_score: number | null;
  min_score: number | null;
  max_score: number | null;
  avg_time_minutes: number | null;
}

export interface Pagination {
  total: number;
  page: number;
  limit: number;
  total_pages: number;
}

export interface AssessmentsResponse {
  assessments: Assessment[];
  pagination: Pagination;
  total: 0;
}

// Form interfaces
export interface AssessmentForm {
  title: string;
  description?: string;
  instructions?: string;
  thumbnail?: string;
  course_id?: number;
  live_training_id?: number;
  time_limit_minutes?: number;
  time_limit_per_question_seconds?: number;
  has_time_limit?: boolean;
  available_from?: string;
  available_until?: string;
  grace_period_minutes?: number;
  max_attempts?: number;
  attempts_gap_hours?: number;
  passing_score?: number;
  passing_type?: PassingType;
  show_score_immediately?: boolean;
  question_selection?: QuestionSelection;
  random_question_count?: number;
  shuffle_questions?: boolean;
  shuffle_options?: boolean;
  questions_per_page?: QuestionsPerPage;
  allow_backward_navigation?: boolean;
  show_question_numbers?: boolean;
  show_progress_bar?: boolean;
  show_timer?: boolean;
  allow_mark_for_review?: boolean;
  show_correct_answers?: ShowCorrectAnswers;
  show_explanations?: boolean;
  show_score_breakdown?: boolean;
  issue_certificate?: boolean;
  certificate_template_id?: number;
  proctoring_level?: ProctoringLevel;
  auto_publish_results?: boolean;
}

export interface SectionForm {
  assessment_id: number;
  title: string;
  description?: string;
  instructions?: string;
  time_limit_minutes?: number;
  selection_type?: 'all' | 'random';
  random_count?: number;
  sort_order?: number;
}

export interface AssignmentForm {
  assessment_id: number;
  user_ids?: number[];
  department_id?: number;
  team_id?: number;
  branch_id?: number;
  assign_all?: boolean;
  available_from_override?: string;
  available_until_override?: string;
  max_attempts_override?: number;
  is_mandatory?: boolean;
  due_date?: string;
  notify_on_assign?: boolean;
  reminder_days_before?: number;
}

export interface AssessmentFilters {
  status?: AssessmentStatus | 'all';
  course_id?: number;
  live_training_id?: number;
  is_standalone?: boolean;
  proctoring_level?: ProctoringLevel;
  search?: string;
  page?: number;
  limit?: number;
}

// Lookup interfaces
export interface CourseOption {
  course_id: number;
  title: string;
}

export interface TrainingOption {
  live_training_id: number;
  title: string;
}

export interface DepartmentOption {
  department_id: number;
  name: string;
  user_count: number;
}

export interface TeamOption {
  team_id: number;
  name: string;
  department_name: string | null;
  user_count: number;
}

export interface BranchOption {
  branch_id: number;
  name: string;
  user_count: number;
}

export interface UserOption {
  user_id: number;
  first_name: string;
  last_name: string;
  email: string;
  profile_image: string | null;
  full_name: string;
}

export interface ApiResponse<T> {
  response_code: number;
  status: 'success' | 'error';
  data: T;
  message: string;
}

// ==========================================
// SERVICE
// ==========================================

@Injectable({
  providedIn: 'root'
})
export class AssessmentService {
  private apiUrl = GlobalComponent.assessment;

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
  /**
   * Get auth payload for API requests
   */
  private getAuthPayload(): { token: string; user_id: number; tenant_id: number } {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    return {
      token: this.user_session.token,
      user_id: this.user_session.id,
      tenant_id: this.user_session.tenant_id,
    };
  }

  // ==========================================
  // ASSESSMENTS
  // ==========================================

  createAssessment(data: AssessmentForm): Observable<{ assessment_id: number }> {
    const payload = { ...this.getAuthPayload(), action: 'create-assessment', ...data };
    return this.http.post<ApiResponse<{ assessment_id: number }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  updateAssessment(assessment_id: number, data: Partial<AssessmentForm>): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'update-assessment', assessment_id, ...data };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  deleteAssessment(assessment_id: number): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'delete-assessment', assessment_id };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  getAssessment(assessment_id: number): Observable<Assessment> {
    const payload = { ...this.getAuthPayload(), action: 'get-assessment', assessment_id };
    return this.http.post<ApiResponse<Assessment>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  getAssessments(filters?: {
    search: string;
    limit: number;
    page: number;
    proctoring: string | null;
    type: string | null;
    status: string | null
  }): Observable<AssessmentsResponse> {
    const payload = { ...this.getAuthPayload(), action: 'get-assessments', ...filters };
    return this.http.post<ApiResponse<AssessmentsResponse>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  publishAssessment(assessment_id: number): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'publish-assessment', assessment_id };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  unpublishAssessment(assessment_id: number): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'unpublish-assessment', assessment_id };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  closeAssessment(assessment_id: number): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'close-assessment', assessment_id };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  cloneAssessment(assessment_id: number): Observable<{ assessment_id: number }> {
    const payload = { ...this.getAuthPayload(), action: 'clone-assessment', assessment_id };
    return this.http.post<ApiResponse<{ assessment_id: number }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  getAssessmentStats(assessment_id: number): Observable<AssessmentStats> {
    const payload = { ...this.getAuthPayload(), action: 'get-assessment-stats', assessment_id };
    return this.http.post<ApiResponse<AssessmentStats>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  // ==========================================
  // SECTIONS
  // ==========================================

  createSection(data: SectionForm): Observable<{ section_id: number }> {
    const payload = { ...this.getAuthPayload(), action: 'create-section', ...data };
    return this.http.post<ApiResponse<{ section_id: number }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  updateSection(section_id: number, data: Partial<SectionForm>): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'update-section', section_id, ...data };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  deleteSection(section_id: number): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'delete-section', section_id };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  getSections(assessment_id: number): Observable<AssessmentSection[]> {
    const payload = { ...this.getAuthPayload(), action: 'get-sections', assessment_id };
    return this.http.post<ApiResponse<AssessmentSection[]>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  reorderSections(assessment_id: number, section_orders: { section_id: number; sort_order: number }[]): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'reorder-sections', assessment_id, section_orders };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  // ==========================================
  // QUESTION ASSIGNMENT
  // ==========================================

  addQuestion(assessment_id: number, question_id: number, options: {
    section_id?: number;
    points_override?: number;
    time_limit_override?: number;
    required?: boolean;
    sort_order?: number;
  } = {}): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'add-question', assessment_id, question_id, ...options };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  removeQuestion(assessment_id: number, question_id: number): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'remove-question', assessment_id, question_id };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  updateQuestion(assessment_id: number, question_id: number, options: {
    section_id?: number;
    points_override?: number;
    time_limit_override?: number;
    required?: boolean;
    sort_order?: number;
  }): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'update-question', assessment_id, question_id, ...options };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  getQuestions(assessment_id: number): Observable<AssessmentQuestion[]> {
    const payload = { ...this.getAuthPayload(), action: 'get-questions', assessment_id };
    return this.http.post<ApiResponse<AssessmentQuestion[]>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  bulkAddQuestions(assessment_id: number, question_ids: number[], section_id?: number): Observable<{ added: number; skipped: number }> {
    const payload = { ...this.getAuthPayload(), action: 'bulk-add-questions', assessment_id, question_ids, section_id };
    return this.http.post<ApiResponse<{ added: number; skipped: number }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  reorderQuestions(assessment_id: number, question_orders: { question_id: number; sort_order: number }[]): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'reorder-questions', assessment_id, question_orders };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  // ==========================================
  // LEARNER ASSIGNMENT
  // ==========================================

  assignAssessment(data: AssignmentForm): Observable<{ assigned_count: number }> {
    const payload = { ...this.getAuthPayload(), action: 'assign-assessment', ...data };
    return this.http.post<ApiResponse<{ assigned_count: number }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  removeAssignment(assignment_id: number): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'remove-assignment', assignment_id };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  getAssignments(assessment_id: number): Observable<AssessmentAssignment[]> {
    const payload = { ...this.getAuthPayload(), action: 'get-assignments', assessment_id };
    return this.http.post<ApiResponse<AssessmentAssignment[]>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  checkAssignment(assessment_id: number, check_user_id?: number): Observable<{ is_assigned: boolean }> {
    const payload = { ...this.getAuthPayload(), action: 'check-assignment', assessment_id, check_user_id };
    return this.http.post<ApiResponse<{ is_assigned: boolean }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  // ==========================================
  // LOOKUPS
  // ==========================================

  getCourses(): Observable<CourseOption[]> {
    const payload = { ...this.getAuthPayload(), action: 'get-courses' };
    return this.http.post<ApiResponse<CourseOption[]>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  getTrainings(): Observable<TrainingOption[]> {
    const payload = { ...this.getAuthPayload(), action: 'get-trainings' };
    return this.http.post<ApiResponse<TrainingOption[]>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  getDepartments(): Observable<DepartmentOption[]> {
    const payload = { ...this.getAuthPayload(), action: 'get-departments' };
    return this.http.post<ApiResponse<DepartmentOption[]>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  getTeams(): Observable<TeamOption[]> {
    const payload = { ...this.getAuthPayload(), action: 'get-teams' };
    return this.http.post<ApiResponse<TeamOption[]>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  getBranches(): Observable<BranchOption[]> {
    const payload = { ...this.getAuthPayload(), action: 'get-branches' };
    return this.http.post<ApiResponse<BranchOption[]>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  getUsers(search: string = '', limit: number = 50): Observable<UserOption[]> {
    const payload = { ...this.getAuthPayload(), action: 'get-users', search, limit };
    return this.http.post<ApiResponse<UserOption[]>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  getGradingScales(): Observable<GradingScale[]> {
    const payload = { ...this.getAuthPayload(), action: 'get-grading-scales' };
    return this.http.post<ApiResponse<GradingScale[]>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  createGradingScale(data: { name: string; description?: string; grades: GradeLevel[] }): Observable<{ scale_id: number }> {
    const payload = { ...this.getAuthPayload(), action: 'create-grading-scale', ...data };
    return this.http.post<ApiResponse<{ scale_id: number }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  // ==========================================
  // UTILITY METHODS
  // ==========================================

  /**
   * Get status badge class
   */
  getStatusBadgeClass(status: AssessmentStatus): string {
    const classes: { [key: string]: string } = {
      'draft': 'bg-secondary',
      'published': 'bg-success',
      'closed': 'bg-warning',
      'archived': 'bg-dark'
    };
    return classes[status] || 'bg-secondary';
  }

  /**
   * Get proctoring level badge class
   */
  getProctoringBadgeClass(level: ProctoringLevel): string {
    const classes: { [key: string]: string } = {
      'none': 'bg-light text-dark',
      'basic': 'bg-info',
      'standard': 'bg-warning',
      'advanced': 'bg-danger'
    };
    return classes[level] || 'bg-secondary';
  }

  /**
   * Get proctoring level label
   */
  getProctoringLabel(level: ProctoringLevel): string {
    const labels: { [key: string]: string } = {
      'none': 'No Proctoring',
      'basic': 'Basic Monitoring',
      'standard': 'Standard Proctoring',
      'advanced': 'Advanced Proctoring'
    };
    return labels[level] || level;
  }

  /**
   * Format duration
   */
  formatDuration(minutes: number | null): string {
    if (!minutes) return 'No limit';
    if (minutes < 60) return `${minutes} min`;
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;
    return mins > 0 ? `${hours}h ${mins}m` : `${hours}h`;
  }

  /**
   * Get assignment type label
   */
  getAssignmentTypeLabel(assignment: AssessmentAssignment): string {
    if (assignment.assign_all) return 'All Users';
    if (assignment.department_id) return `Department: ${assignment.department_name}`;
    if (assignment.team_id) return `Team: ${assignment.team_name}`;
    if (assignment.branch_id) return `Branch: ${assignment.branch_name}`;
    if (assignment.user_id) return `User: ${assignment.first_name} ${assignment.last_name}`;
    return 'Unknown';
  }

  /**
   * Get default assessment form values
   */
  getDefaultAssessmentForm(): AssessmentForm {
    return {
      title: '',
      description: '',
      instructions: '',
      has_time_limit: true,
      time_limit_minutes: 60,
      max_attempts: 1,
      attempts_gap_hours: 0,
      passing_score: 70,
      passing_type: 'percentage',
      show_score_immediately: true,
      question_selection: 'manual',
      shuffle_questions: true,
      shuffle_options: true,
      questions_per_page: 'one',
      allow_backward_navigation: true,
      show_question_numbers: true,
      show_progress_bar: true,
      show_timer: true,
      allow_mark_for_review: true,
      show_correct_answers: 'after_all_attempts',
      show_explanations: true,
      show_score_breakdown: true,
      issue_certificate: false,
      proctoring_level: 'none',
      auto_publish_results: true
    };
  }
}
