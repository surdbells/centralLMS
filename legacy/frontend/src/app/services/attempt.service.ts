// attempt.service.ts
// Angular Service for Learner Assessment Attempts
// Multi-Tenant LMS - Proctored Assessment Module

import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map, interval, Subject, takeUntil } from 'rxjs';
import {GlobalComponent} from '../global-component';

// ==========================================
// INTERFACES
// ==========================================

export type AttemptStatus = 'not_started' | 'in_progress' | 'submitted' | 'auto_submitted' | 'grading' | 'graded' | 'abandoned';
export type LearnerAssessmentStatus = 'assigned' | 'available' | 'in_progress' | 'completed' | 'passed' | 'failed' | 'expired';

export interface LearnerAssessment {
  assessment_id: number;
  title: string;
  description: string | null;
  instructions: string | null;
  thumbnail: string | null;
  total_questions: number;
  total_points: number;
  time_limit_minutes: number | null;
  passing_score: number;
  max_attempts: number | null;
  proctoring_level: string;
  course_title: string | null;
  training_title: string | null;

  // Learner-specific
  learner_status: LearnerAssessmentStatus | null;
  total_attempts: number;
  remaining_attempts: number | null;
  best_score: number | null;
  last_score: number | null;
  learner_due_date: string | null;
  is_mandatory: boolean;
  first_attempted_at: string | null;
  last_attempted_at: string | null;
  completed_at: string | null;
  can_attempt: boolean;

  // In-progress attempt
  in_progress_attempt?: InProgressAttempt | null;
}

export interface InProgressAttempt {
  attempt_id: number;
  attempt_number: number;
  started_at: string;
  time_remaining_seconds: number | null;
  current_question_index: number;
}

export interface AttemptData {
  attempt_id: number;
  assessment_id: number;
  assessment_title: string;
  instructions: string | null;
  status: AttemptStatus;
  attempt_number: number;

  // Timing
  started_at: string;
  time_limit_seconds: number | null;
  time_remaining_seconds: number | null;

  // Progress
  total_questions: number;
  answered_questions: number;
  current_question_index: number;
  questions_order: number[];
  marked_for_review: number[];

  // Settings
  questions_per_page: string;
  allow_backward_navigation: boolean;
  show_question_numbers: boolean;
  show_progress_bar: boolean;
  show_timer: boolean;
  allow_mark_for_review: boolean;
  shuffle_options: boolean;

  // Questions with answers
  questions: { [key: number]: QuestionWithAnswer };
}

export interface QuestionWithAnswer {
  question_id: number;
  question_type: string;
  question_text: string;
  question_html: string | null;
  hint: string | null;
  featured_image: string | null;
  audio_url: string | null;
  video_url: string | null;
  points: number;
  options?: QuestionOption[];
  answer_data: any | null;
  is_answered: boolean;
  marked_for_review: boolean;
  time_spent_seconds: number;
}

export interface QuestionOption {
  option_id: number;
  option_text: string;
  option_html: string | null;
  option_image: string | null;
  match_text?: string | null;
  match_image?: string | null;
  sort_order: number;
}

export interface AnswerData {
  // MCQ Single
  selected_option_id?: number;
  // MCQ Multiple
  selected_option_ids?: number[];
  // True/False
  selected?: boolean;
  // Fill in blanks
  blanks?: string[];
  // Short/Long Answer
  text?: string;
  word_count?: number;
  // Matching
  matches?: { [key: string]: string };
  // Ordering
  order?: number[];
  // Hotspot
  clicked_coords?: { x: number; y: number };
  // Numeric
  value?: number;
  // File Upload
  file_path?: string;
  file_name?: string;
  file_size?: number;
}

export interface AttemptResult {
  attempt_id: number;
  assessment_title: string;
  status: AttemptStatus;
  started_at: string;
  submitted_at: string;
  time_spent_seconds: number;
  total_questions: number;
  answered_questions: number;
  correct_answers: number;
  wrong_answers: number;
  partial_answers: number;
  skipped_questions: number;
  raw_score: number;
  max_score: number;
  percentage_score: number;
  letter_grade: string;
  passed: boolean;
  passing_score: number;
  show_answers: boolean;
  show_explanations: boolean;
  show_score_breakdown: boolean;
  requires_grading: boolean;
  pending_grading: number;
  answers?: AnswerReview[];
}

export interface AnswerReview {
  question_id: number;
  question_type: string;
  question_text: string;
  answer_data: any | null;
  is_correct: boolean | null;
  is_partial: boolean;
  points_earned: number;
  max_points: number;
  grader_feedback: string | null;
  explanation?: string;
  correct_answer?: CorrectAnswer[];
}

export interface CorrectAnswer {
  option_id: number;
  option_text: string;
  match_text?: string;
  correct_position?: number;
}

export interface AttemptHistoryItem {
  attempt_id: number;
  attempt_number: number;
  status: AttemptStatus;
  started_at: string;
  submitted_at: string | null;
  time_spent_seconds: number;
  percentage_score: number | null;
  letter_grade: string | null;
  passed: boolean | null;
  correct_answers: number;
  wrong_answers: number;
  skipped_questions: number;
}

export interface SubmitResult {
  score: number;
  max_score: number;
  percentage: number;
  passed: boolean;
  letter_grade: string;
  correct: number;
  wrong: number;
  partial: number;
  skipped: number;
  requires_grading: boolean;
}

export interface DraftData {
  current_index: number;
  time_remaining: number;
  answers: { [key: number]: any };
  marked_for_review: number[];
}

export interface TimelineEvent {
  timeline_id: number;
  attempt_id: number;
  event_type: string;
  question_id: number | null;
  question_index: number | null;
  event_data: any | null;
  timestamp: string;
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
export class AttemptService {
  private apiUrl = GlobalComponent.attempt;

  // Auto-save
  private autoSaveInterval$ = new Subject<void>();
  private autoSaveActive = false;

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
    this.user_session = JSON.parse(atob(this.session_data));
    return {
      token: this.user_session.token,
      user_id: this.user_session.id,
      tenant_id: this.user_session.tenant_id,
    };
  }
  // ==========================================
  // LEARNER ASSESSMENTS
  // ==========================================

  /**
   * Get assessments available for the current learner
   */
  getMyAssessments(filters: {
    status?: string;
    search?: string;
    page?: number;
    limit?: number;
  } = {}): Observable<LearnerAssessment[]> {
    const payload = { ...this.getAuthPayload(), action: 'get-my-assessments', ...filters };
    return this.http.post<ApiResponse<LearnerAssessment[]>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  /**
   * Get single assessment details for learner
   */
  getAssessment(assessment_id: number): Observable<LearnerAssessment> {
    const payload = { ...this.getAuthPayload(), action: 'get-assessment', assessment_id };
    return this.http.post<ApiResponse<LearnerAssessment>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  // ==========================================
  // ATTEMPT MANAGEMENT
  // ==========================================

  /**
   * Start or resume an attempt
   */
  startAttempt(assessment_id: number, deviceInfo?: {
    browser_name?: string;
    browser_version?: string;
    os_name?: string;
    device_type?: string;
    screen_width?: number;
    screen_height?: number;
  }): Observable<{ attempt_id: number; is_resume: boolean }> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'start-attempt',
      assessment_id,
      ...deviceInfo
    };
    return this.http.post<ApiResponse<{ attempt_id: number; is_resume: boolean }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  /**
   * Get attempt data for player
   */
  getAttempt(attempt_id: number): Observable<AttemptData> {
    const payload = { ...this.getAuthPayload(), action: 'get-attempt', attempt_id };
    return this.http.post<ApiResponse<AttemptData>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  /**
   * Submit attempt for grading
   */
  submitAttempt(attempt_id: number): Observable<SubmitResult> {
    const payload = { ...this.getAuthPayload(), action: 'submit-attempt', attempt_id };
    return this.http.post<ApiResponse<SubmitResult>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  // ==========================================
  // ANSWERS
  // ==========================================

  /**
   * Save answer for a question
   */
  saveAnswer(attempt_id: number, question_id: number, answer_data: AnswerData): Observable<void> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'save-answer',
      attempt_id,
      question_id,
      answer_data
    };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  /**
   * Mark/unmark question for review
   */
  markForReview(attempt_id: number, question_id: number, marked: boolean = true): Observable<void> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'mark-for-review',
      attempt_id,
      question_id,
      marked
    };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  /**
   * Update navigation position
   */
  updateNavigation(attempt_id: number, question_index: number): Observable<void> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'update-navigation',
      attempt_id,
      question_index
    };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  /**
   * Update time remaining
   */
  updateTime(attempt_id: number, time_remaining: number): Observable<void> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'update-time',
      attempt_id,
      time_remaining
    };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  // ==========================================
  // AUTO-SAVE
  // ==========================================

  /**
   * Auto-save attempt state
   */
  autoSave(attempt_id: number, draft_data: DraftData): Observable<{ saved_at: string }> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'auto-save',
      attempt_id,
      draft_data
    };
    return this.http.post<ApiResponse<{ saved_at: string }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  /**
   * Recover draft data
   */
  recoverDraft(attempt_id: number): Observable<{ data: DraftData; saved_at: string } | null> {
    const payload = { ...this.getAuthPayload(), action: 'recover-draft', attempt_id };
    return this.http.post<ApiResponse<{ data: DraftData; saved_at: string }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  /**
   * Start auto-save interval (call in component)
   */
  startAutoSave(attempt_id: number, getDraftData: () => DraftData, intervalMs: number = 30000): void {
    this.stopAutoSave();
    this.autoSaveActive = true;

    interval(intervalMs)
      .pipe(takeUntil(this.autoSaveInterval$))
      .subscribe(() => {
        if (this.autoSaveActive) {
          const draftData = getDraftData();
          this.autoSave(attempt_id, draftData).subscribe({
            next: (result) => console.log('Auto-saved at:', result.saved_at),
            error: (err) => console.error('Auto-save failed:', err)
          });
        }
      });
  }

  /**
   * Stop auto-save interval
   */
  stopAutoSave(): void {
    this.autoSaveActive = false;
    this.autoSaveInterval$.next();
  }

  // ==========================================
  // RESULTS
  // ==========================================

  /**
   * Get attempt results
   */
  getResults(attempt_id: number): Observable<AttemptResult> {
    const payload = { ...this.getAuthPayload(), action: 'get-results', attempt_id };
    return this.http.post<ApiResponse<AttemptResult>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  /**
   * Get attempt history for an assessment
   */
  getAttemptHistory(assessment_id: number): Observable<AttemptHistoryItem[]> {
    const payload = { ...this.getAuthPayload(), action: 'get-attempt-history', assessment_id };
    return this.http.post<ApiResponse<AttemptHistoryItem[]>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  // ==========================================
  // TIMELINE
  // ==========================================

  /**
   * Log timeline event
   */
  logEvent(attempt_id: number, event_type: string, question_id?: number, event_data?: any): Observable<void> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'log-event',
      attempt_id,
      event_type,
      question_id,
      event_data
    };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  /**
   * Get attempt timeline
   */
  getTimeline(attempt_id: number): Observable<TimelineEvent[]> {
    const payload = { ...this.getAuthPayload(), action: 'get-timeline', attempt_id };
    return this.http.post<ApiResponse<TimelineEvent[]>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  // ==========================================
  // UTILITY METHODS
  // ==========================================

  /**
   * Get device info for attempt start
   */
  getDeviceInfo(): {
    browser_name: string;
    browser_version: string;
    os_name: string;
    device_type: 'desktop' | 'tablet' | 'mobile';
    screen_width: number;
    screen_height: number;
  } {
    const ua = navigator.userAgent;
    let browserName = 'Unknown';
    let browserVersion = '';
    let osName = 'Unknown';
    let deviceType: 'desktop' | 'tablet' | 'mobile' = 'desktop';

    // Detect browser
    if (ua.includes('Firefox')) {
      browserName = 'Firefox';
      browserVersion = ua.match(/Firefox\/(\d+)/)?.[1] || '';
    } else if (ua.includes('Chrome')) {
      browserName = 'Chrome';
      browserVersion = ua.match(/Chrome\/(\d+)/)?.[1] || '';
    } else if (ua.includes('Safari')) {
      browserName = 'Safari';
      browserVersion = ua.match(/Version\/(\d+)/)?.[1] || '';
    } else if (ua.includes('Edge')) {
      browserName = 'Edge';
      browserVersion = ua.match(/Edge\/(\d+)/)?.[1] || '';
    }

    // Detect OS
    if (ua.includes('Windows')) osName = 'Windows';
    else if (ua.includes('Mac')) osName = 'macOS';
    else if (ua.includes('Linux')) osName = 'Linux';
    else if (ua.includes('Android')) osName = 'Android';
    else if (ua.includes('iOS')) osName = 'iOS';

    // Detect device type
    if (/Mobi|Android/i.test(ua)) {
      deviceType = 'mobile';
    } else if (/Tablet|iPad/i.test(ua)) {
      deviceType = 'tablet';
    }

    return {
      browser_name: browserName,
      browser_version: browserVersion,
      os_name: osName,
      device_type: deviceType,
      screen_width: window.screen.width,
      screen_height: window.screen.height
    };
  }

  /**
   * Format time (seconds to MM:SS or HH:MM:SS)
   */
  formatTime(seconds: number): string {
    if (seconds < 0) seconds = 0;

    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;

    if (hours > 0) {
      return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }
    return `${minutes}:${secs.toString().padStart(2, '0')}`;
  }

  /**
   * Format time spent in human readable format
   */
  formatTimeSpent(seconds: number): string {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;

    const parts: string[] = [];
    if (hours > 0) parts.push(`${hours}h`);
    if (minutes > 0) parts.push(`${minutes}m`);
    if (secs > 0 || parts.length === 0) parts.push(`${secs}s`);

    return parts.join(' ');
  }

  /**
   * Get status badge class
   */
  getStatusBadgeClass(status: AttemptStatus | LearnerAssessmentStatus): string {
    const classes: { [key: string]: string } = {
      'not_started': 'bg-secondary',
      'assigned': 'bg-secondary',
      'available': 'bg-info',
      'in_progress': 'bg-primary',
      'submitted': 'bg-warning',
      'auto_submitted': 'bg-warning',
      'grading': 'bg-info',
      'graded': 'bg-success',
      'completed': 'bg-success',
      'passed': 'bg-success',
      'failed': 'bg-danger',
      'expired': 'bg-dark',
      'abandoned': 'bg-dark'
    };
    return classes[status] || 'bg-secondary';
  }

  /**
   * Get status label
   */
  getStatusLabel(status: AttemptStatus | LearnerAssessmentStatus): string {
    const labels: { [key: string]: string } = {
      'not_started': 'Not Started',
      'assigned': 'Assigned',
      'available': 'Available',
      'in_progress': 'In Progress',
      'submitted': 'Submitted',
      'auto_submitted': 'Auto-Submitted',
      'grading': 'Being Graded',
      'graded': 'Graded',
      'completed': 'Completed',
      'passed': 'Passed',
      'failed': 'Failed',
      'expired': 'Expired',
      'abandoned': 'Abandoned'
    };
    return labels[status] || status;
  }

  /**
   * Get score color class based on percentage
   */
  getScoreColorClass(percentage: number, passingScore: number = 70): string {
    if (percentage >= passingScore) return 'text-success';
    if (percentage >= passingScore - 10) return 'text-warning';
    return 'text-danger';
  }

  /**
   * Calculate progress percentage
   */
  calculateProgress(answered: number, total: number): number {
    if (total === 0) return 0;
    return Math.round((answered / total) * 100);
  }

  /**
   * Check if answer is empty
   */
  isAnswerEmpty(answer: AnswerData | null): boolean {
    if (!answer) return true;

    if (answer.selected_option_id !== undefined) return !answer.selected_option_id;
    if (answer.selected_option_ids !== undefined) return answer.selected_option_ids.length === 0;
    if (answer.selected !== undefined) return answer.selected === null;
    if (answer.text !== undefined) return !answer.text?.trim();
    if (answer.blanks !== undefined) return answer.blanks.every(b => !b?.trim());
    if (answer.matches !== undefined) return Object.keys(answer.matches).length === 0;
    if (answer.order !== undefined) return answer.order.length === 0;
    if (answer.clicked_coords !== undefined) return !answer.clicked_coords;
    if (answer.value !== undefined) return answer.value === null || answer.value === undefined;
    if (answer.file_path !== undefined) return !answer.file_path;

    return true;
  }
}
