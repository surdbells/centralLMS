import { decodeSessionBlob } from '../core/utils/session-blob';
// grading.service.ts - Angular Service for Review & Grading
// Multi-Tenant LMS - Proctored Assessment Module

import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import {GlobalComponent} from '../global-component';

// INTERFACES
export type RubricType = 'analytic' | 'holistic' | 'checklist';
export type RubricStatus = 'draft' | 'active' | 'archived';
export type QueueStatus = 'pending' | 'in_progress' | 'graded' | 'skipped';
export type QueuePriority = 'low' | 'normal' | 'high' | 'urgent';
export type CommentType = 'general' | 'inline' | 'highlight' | 'correction' | 'praise' | 'suggestion';
export type FeedbackType = 'positive' | 'constructive' | 'correction' | 'neutral';
export type AdjustmentType = 'bonus' | 'penalty' | 'override' | 'curve';

export interface Rubric {
  rubric_id: number; title: string; description: string | null; rubric_type: RubricType;
  max_points: number; allow_partial_points: boolean; is_template: boolean; usage_count: number;
  status: RubricStatus; created_by_name?: string; created_at: string; criteria?: RubricCriterion[];
}

export interface RubricCriterion {
  criterion_id: number; rubric_id: number; title: string; description: string | null;
  weight: number; max_points: number; sort_order: number; levels: RubricLevel[];
}

export interface RubricLevel {
  level_id: number; criterion_id: number; title: string; description: string | null;
  points: number; percentage: number | null; sort_order: number; color: string | null;
}

export interface GradingQueueItem {
  queue_id: number; answer_id: number; attempt_id: number; assessment_id: number;
  question_id: number; user_id: number; question_type: string; question_text: string;
  max_points: number; status: QueueStatus; priority: QueuePriority; flagged: boolean;
  created_at: string; learner_name: string; assessment_title: string; answer_data: any;
}

export interface GradingQueueResponse { items: GradingQueueItem[]; counts: QueueCounts; }
export interface QueueCounts { pending: number; in_progress: number; graded: number; skipped: number; total: number; }

export interface AnswerForGrading extends GradingQueueItem {
  points_earned: number; grader_feedback: string | null; question_html: string;
  explanation: string | null; email: string; rubric: Rubric | null; comments: FeedbackComment[];
}

export interface RubricScore { points_awarded: number; max_points: number; level_id: number | null; feedback: string | null; }

export interface FeedbackComment {
  comment_id: number; answer_id: number; comment_type: CommentType; start_position: number | null;
  end_position: number | null; selected_text: string | null; comment_text: string;
  visible_to_learner: boolean; created_by_name: string; created_at: string;
}

export interface QuickFeedback {
  feedback_id: number; title: string; content: string; category: string;
  feedback_type: FeedbackType; usage_count: number; is_global: boolean;
}

export interface GradeAdjustment {
  adjustment_id: number; attempt_id: number; scope: 'attempt' | 'question'; answer_id: number | null;
  adjustment_type: AdjustmentType; adjustment_value: number; adjustment_unit: 'points' | 'percentage';
  original_score: number; adjusted_score: number; reason: string; created_by_name: string; created_at: string;
}

export interface GradingHistory { history_id: number; answer_id: number; action: string; previous_score: number | null; new_score: number | null; performed_by_name: string; performed_at: string; }
export interface GradingStats { total_items: number; pending: number; graded: number; avg_grading_time_seconds: number | null; completion_rate: number; }
export interface ApiResponse<T> { response_code: number; status: 'success' | 'error'; data: T; message: string; }

@Injectable({ providedIn: 'root' })
export class GradingService {
  private apiUrl = GlobalComponent.grading;

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

  // RUBRICS
  createRubric(data: Partial<Rubric>): Observable<{ rubric_id: number }> {
    return this.http.post<ApiResponse<{ rubric_id: number }>>(this.apiUrl, { ...this.getAuthPayload(), action: 'create-rubric', ...data }).pipe(map(res => res.data));
  }

  updateRubric(rubric_id: number, data: Partial<Rubric>): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, { ...this.getAuthPayload(), action: 'update-rubric', rubric_id, ...data }).pipe(map(() => void 0));
  }

  deleteRubric(rubric_id: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, { ...this.getAuthPayload(), action: 'delete-rubric', rubric_id }).pipe(map(() => void 0));
  }

  getRubric(rubric_id: number): Observable<Rubric> {
    return this.http.post<ApiResponse<Rubric>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-rubric', rubric_id }).pipe(map(res => res.data));
  }

  getRubrics(filters: { status?: RubricStatus; template_only?: boolean } = {}): Observable<Rubric[]> {
    return this.http.post<ApiResponse<Rubric[]>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-rubrics', ...filters }).pipe(map(res => res.data));
  }

  cloneRubric(rubric_id: number): Observable<{ rubric_id: number }> {
    return this.http.post<ApiResponse<{ rubric_id: number }>>(this.apiUrl, { ...this.getAuthPayload(), action: 'clone-rubric', rubric_id }).pipe(map(res => res.data));
  }

  // CRITERIA & LEVELS
  createCriterion(rubric_id: number, data: Partial<RubricCriterion>): Observable<{ criterion_id: number }> {
    return this.http.post<ApiResponse<{ criterion_id: number }>>(this.apiUrl, { ...this.getAuthPayload(), action: 'create-criterion', rubric_id, ...data }).pipe(map(res => res.data));
  }

  deleteCriterion(criterion_id: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, { ...this.getAuthPayload(), action: 'delete-criterion', criterion_id }).pipe(map(() => void 0));
  }

  createLevel(criterion_id: number, rubric_id: number, data: Partial<RubricLevel>): Observable<{ level_id: number }> {
    return this.http.post<ApiResponse<{ level_id: number }>>(this.apiUrl, { ...this.getAuthPayload(), action: 'create-level', criterion_id, rubric_id, ...data }).pipe(map(res => res.data));
  }

  // GRADING QUEUE
  getGradingQueue(filters: { status?: QueueStatus | 'all'; assessment_id?: number; page?: number; limit?: number } = {}): Observable<GradingQueueResponse> {
    return this.http.post<ApiResponse<GradingQueueResponse>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-grading-queue', ...filters }).pipe(map(res => res.data));
  }

  assignQueueItem(queue_id: number, grader_id?: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, { ...this.getAuthPayload(), action: 'assign-queue-item', queue_id, grader_id }).pipe(map(() => void 0));
  }

  getNextQueueItem(assessment_id?: number): Observable<AnswerForGrading | null> {
    return this.http.post<ApiResponse<AnswerForGrading>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-next-item', assessment_id }).pipe(map(res => res.data));
  }

  getAnswerForGrading(queue_id: number): Observable<AnswerForGrading> {
    return this.http.post<ApiResponse<AnswerForGrading>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-answer-for-grading', queue_id }).pipe(map(res => res.data));
  }

  // MANUAL GRADING
  gradeAnswer(answer_id: number, data: { points: number; feedback?: string; is_correct?: boolean; is_partial?: boolean }): Observable<{ points: number }> {
    return this.http.post<ApiResponse<{ points: number }>>(this.apiUrl, { ...this.getAuthPayload(), action: 'grade-answer', answer_id, ...data }).pipe(map(res => res.data));
  }

  gradeWithRubric(answer_id: number, rubric_id: number, scores: { [criterion_id: number]: { points: number; level_id?: number; feedback?: string } }): Observable<{ points: number }> {
    return this.http.post<ApiResponse<{ points: number }>>(this.apiUrl, { ...this.getAuthPayload(), action: 'grade-with-rubric', answer_id, rubric_id, scores }).pipe(map(res => res.data));
  }

  batchGrade(answer_ids: number[], points: number, feedback?: string): Observable<{ graded: number; failed: number }> {
    return this.http.post<ApiResponse<{ graded: number; failed: number }>>(this.apiUrl, { ...this.getAuthPayload(), action: 'batch-grade', answer_ids, points, feedback }).pipe(map(res => res.data));
  }

  releaseGrades(assessment_id: number): Observable<{ released: number }> {
    return this.http.post<ApiResponse<{ released: number }>>(this.apiUrl, { ...this.getAuthPayload(), action: 'release-grades', assessment_id }).pipe(map(res => res.data));
  }

  // FEEDBACK COMMENTS
  addComment(answer_id: number, data: { comment_type?: CommentType; comment_text: string; start_position?: number; end_position?: number; selected_text?: string; visible_to_learner?: boolean }): Observable<{ comment_id: number }> {
    return this.http.post<ApiResponse<{ comment_id: number }>>(this.apiUrl, { ...this.getAuthPayload(), action: 'add-comment', answer_id, ...data }).pipe(map(res => res.data));
  }

  getComments(answer_id: number): Observable<FeedbackComment[]> {
    return this.http.post<ApiResponse<FeedbackComment[]>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-comments', answer_id }).pipe(map(res => res.data));
  }

  deleteComment(comment_id: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, { ...this.getAuthPayload(), action: 'delete-comment', comment_id }).pipe(map(() => void 0));
  }

  // QUICK FEEDBACK
  getQuickFeedback(category?: string): Observable<QuickFeedback[]> {
    return this.http.post<ApiResponse<QuickFeedback[]>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-quick-feedback', category }).pipe(map(res => res.data));
  }

  createQuickFeedback(data: { title: string; content: string; category?: string; feedback_type?: FeedbackType; is_global?: boolean }): Observable<{ feedback_id: number }> {
    return this.http.post<ApiResponse<{ feedback_id: number }>>(this.apiUrl, { ...this.getAuthPayload(), action: 'create-quick-feedback', ...data }).pipe(map(res => res.data));
  }

  // GRADE ADJUSTMENTS
  createAdjustment(attempt_id: number, data: { scope?: 'attempt' | 'question'; answer_id?: number; adjustment_type: AdjustmentType; adjustment_value: number; adjustment_unit?: 'points' | 'percentage'; reason: string }): Observable<{ adjustment_id: number; adjusted_score: number }> {
    return this.http.post<ApiResponse<{ adjustment_id: number; adjusted_score: number }>>(this.apiUrl, { ...this.getAuthPayload(), action: 'create-adjustment', attempt_id, ...data }).pipe(map(res => res.data));
  }

  getAdjustments(attempt_id: number): Observable<GradeAdjustment[]> {
    return this.http.post<ApiResponse<GradeAdjustment[]>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-adjustments', attempt_id }).pipe(map(res => res.data));
  }

  // QUESTION RUBRICS
  assignRubricToQuestion(question_id: number, rubric_id: number, assessment_id?: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, { ...this.getAuthPayload(), action: 'assign-rubric-to-question', question_id, rubric_id, assessment_id }).pipe(map(() => void 0));
  }

  getQuestionRubric(question_id: number, assessment_id?: number): Observable<Rubric | null> {
    return this.http.post<ApiResponse<Rubric | null>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-question-rubric', question_id, assessment_id }).pipe(map(res => res.data));
  }

  // HISTORY & STATS
  getGradingHistory(answer_id: number): Observable<GradingHistory[]> {
    return this.http.post<ApiResponse<GradingHistory[]>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-grading-history', answer_id }).pipe(map(res => res.data));
  }

  getGradingStats(assessment_id?: number): Observable<GradingStats> {
    return this.http.post<ApiResponse<GradingStats>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-grading-stats', assessment_id }).pipe(map(res => res.data));
  }

  // UTILITIES
  getQueueStatusBadgeClass(status: QueueStatus): string {
    return { 'pending': 'bg-warning', 'in_progress': 'bg-info', 'graded': 'bg-success', 'skipped': 'bg-secondary' }[status] || 'bg-secondary';
  }

  getQueueStatusLabel(status: QueueStatus): string {
    return { 'pending': 'Pending', 'in_progress': 'In Progress', 'graded': 'Graded', 'skipped': 'Skipped' }[status] || status;
  }

  getPriorityBadgeClass(priority: QueuePriority): string {
    return { 'low': 'bg-secondary', 'normal': 'bg-primary', 'high': 'bg-warning', 'urgent': 'bg-danger' }[priority] || 'bg-secondary';
  }

  getCommentTypeBadgeClass(type: CommentType): string {
    return { 'general': 'bg-secondary', 'inline': 'bg-info', 'highlight': 'bg-warning', 'correction': 'bg-danger', 'praise': 'bg-success', 'suggestion': 'bg-primary' }[type] || 'bg-secondary';
  }

  calculateRubricTotal(scores: { [criterion_id: number]: RubricScore }): { awarded: number; max: number; percentage: number } {
    let awarded = 0, max = 0;
    for (const key of Object.keys(scores)) { awarded += scores[parseInt(key)].points_awarded || 0; max += scores[parseInt(key)].max_points || 0; }
    return { awarded, max, percentage: max > 0 ? Math.round((awarded / max) * 100) : 0 };
  }

  formatGradingTime(seconds: number): string {
    if (seconds < 60) return `${seconds}s`;
    const mins = Math.floor(seconds / 60);
    if (mins < 60) return `${mins}m ${seconds % 60}s`;
    return `${Math.floor(mins / 60)}h ${mins % 60}m`;
  }
}
