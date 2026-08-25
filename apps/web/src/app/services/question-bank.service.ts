import { decodeSessionBlob } from '../core/utils/session-blob';
// question-bank.service.ts
// Angular Service for Question Bank API
// Multi-Tenant LMS - Proctored Assessment Module

import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import {GlobalComponent} from '../global-component';


// ==========================================
// INTERFACES
// ==========================================

export interface QuestionBank {
  bank_id: number;
  tenant_id: number;
  name: string;
  description: string | null;
  color: string;
  icon: string;
  total_questions: number;
  is_shared: boolean;
  status: 'active' | 'archived';
  created_by: number | null;
  created_by_name: string | null;
  category_count: number;
  created_at: string;
  updated_at: string | null;
}

export interface QuestionCategory {
  category_id: number;
  tenant_id: number;
  bank_id: number;
  parent_id: number | null;
  name: string;
  description: string | null;
  sort_order: number;
  question_count: number;
  children?: QuestionCategory[];
  created_at: string;
  updated_at: string | null;
}

export interface QuestionTag {
  tag_id: number;
  tenant_id: number;
  name: string;
  color: string;
  usage_count: number;
  created_at: string;
}

export type QuestionType =
  | 'multiple_choice_single'
  | 'multiple_choice_multiple'
  | 'true_false'
  | 'fill_in_blanks'
  | 'short_answer'
  | 'long_answer'
  | 'matching'
  | 'ordering'
  | 'hotspot'
  | 'numeric'
  | 'file_upload';

export type Difficulty = 'easy' | 'medium' | 'hard';

export interface QuestionOption {
  option_id?: number;
  question_id?: number;
  option_text: string;
  option_html?: string | null;
  option_image?: string | null;
  is_correct: boolean;
  points_if_selected: number;
  match_text?: string | null;
  match_image?: string | null;
  correct_position?: number | null;
  hotspot_coords?: HotspotCoords | null;
  acceptable_answers?: string[] | null;
  sort_order: number;
  feedback?: string | null;
}

export interface HotspotCoords {
  x: number;
  y: number;
  width: number;
  height: number;
  shape: 'rect' | 'circle' | 'polygon';
  points?: { x: number; y: number }[];
}

export interface QuestionMedia {
  media_id: number;
  tenant_id: number;
  question_id: number;
  media_type: 'image' | 'audio' | 'video' | 'document';
  file_name: string;
  file_path: string;
  file_size: number;
  mime_type: string;
  sort_order: number;
  created_at: string;
}

export interface Question {
  question_id: number;
  tenant_id: number;
  bank_id: number;
  category_id: number | null;
  question_type: QuestionType;
  question_text: string;
  question_html: string | null;
  explanation: string | null;
  hint: string | null;
  featured_image: string | null;
  audio_url: string | null;
  video_url: string | null;
  points: number;
  negative_points: number;
  partial_credit: boolean;
  difficulty: Difficulty;
  estimated_time_seconds: number;
  case_sensitive: boolean;
  allow_regex: boolean;
  numeric_tolerance: number;
  max_file_size_mb: number;
  allowed_file_types: string;
  max_words: number | null;
  min_words: number | null;
  version: number;
  status: 'draft' | 'active' | 'archived';
  usage_count: number;
  avg_score: number | null;
  times_answered: number;
  times_correct: number;
  created_by: number | null;
  created_by_name: string | null;
  bank_name: string | null;
  category_name: string | null;
  options: QuestionOption[];
  tags: QuestionTag[];
  media: QuestionMedia[];
  created_at: string;
  updated_at: string | null;
}

export interface QuestionListItem {
  question_id: number;
  bank_id: number;
  category_id: number | null;
  question_type: QuestionType;
  question_text: string;
  points: number;
  difficulty: Difficulty;
  status: 'draft' | 'active' | 'archived';
  usage_count: number;
  bank_name: string | null;
  category_name: string | null;
  created_by_name: string | null;
  tags: QuestionTag[];
  created_at: string;
}

export interface Pagination {
  total: number;
  page: number;
  limit: number;
  total_pages: number;
}

export interface QuestionsResponse {
  questions: QuestionListItem[];
  pagination: Pagination;
}

export interface BankStats {
  total_questions: number;
  active_questions: number;
  draft_questions: number;
  by_difficulty: {
    easy: number;
    medium: number;
    hard: number;
  };
  by_type: { [key: string]: number };
}

// Form interfaces for creating/updating
export interface QuestionBankForm {
  name: string;
  description?: string;
  color?: string;
  icon?: string;
  is_shared?: boolean;
}

export interface QuestionCategoryForm {
  bank_id: number;
  name: string;
  description?: string;
  parent_id?: number | null;
  sort_order?: number;
}

export interface QuestionTagForm {
  name: string;
  color?: string;
}

export interface QuestionForm {
  bank_id: number;
  category_id?: number | null;
  question_type: QuestionType;
  question_text: string;
  question_html?: string;
  explanation?: string;
  hint?: string;
  featured_image?: string;
  audio_url?: string;
  video_url?: string;
  points?: number;
  negative_points?: number;
  partial_credit?: boolean;
  difficulty?: Difficulty;
  estimated_time_seconds?: number;
  case_sensitive?: boolean;
  allow_regex?: boolean;
  numeric_tolerance?: number;
  max_file_size_mb?: number;
  allowed_file_types?: string;
  max_words?: number;
  min_words?: number;
  options?: QuestionOption[];
  tag_ids?: number[];
}

export interface QuestionFilters {
  bank_id?: number;
  category_id?: number | null;
  question_type?: QuestionType;
  difficulty?: Difficulty;
  status?: 'active' | 'draft' | 'archived' | 'all';
  search?: string;
  tag_ids?: number[];
  page?: number;
  limit?: number;
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
export class QuestionBankService {
  private apiUrl = GlobalComponent.question_bank;
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
  // QUESTION BANKS
  // ==========================================

  /**
   * Create a new question bank
   */
  createBank(data: QuestionBankForm): Observable<{ bank_id: number }> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'create-bank',
      ...data
    };
    return this.http.post<ApiResponse<{ bank_id: number }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  /**
   * Update an existing question bank
   */
  updateBank(bank_id: number, data: QuestionBankForm): Observable<void> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'update-bank',
      bank_id,
      ...data
    };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  /**
   * Delete (archive) a question bank
   */
  deleteBank(bank_id: number): Observable<void> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'delete-bank',
      bank_id
    };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  /**
   * Get a single question bank with stats
   */
  getBank(bank_id: number): Observable<QuestionBank> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'get-bank',
      bank_id
    };
    return this.http.post<ApiResponse<QuestionBank>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  /**
   * Get all question banks
   */
  getBanks(status: string = 'active', search: string = ''): Observable<QuestionBank[]> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'get-banks',
      status,
      search
    };
    return this.http.post<ApiResponse<QuestionBank[]>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  /**
   * Get question bank statistics
   */
  getBankStats(bank_id: number): Observable<BankStats> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'get-bank-stats',
      bank_id
    };
    return this.http.post<ApiResponse<BankStats>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  // ==========================================
  // CATEGORIES
  // ==========================================

  /**
   * Create a category within a bank
   */
  createCategory(data: QuestionCategoryForm): Observable<{ category_id: number }> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'create-category',
      ...data
    };
    return this.http.post<ApiResponse<{ category_id: number }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  /**
   * Update a category
   */
  updateCategory(category_id: number, data: Partial<QuestionCategoryForm>): Observable<void> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'update-category',
      category_id,
      ...data
    };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  /**
   * Delete a category
   */
  deleteCategory(category_id: number): Observable<void> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'delete-category',
      category_id
    };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  /**
   * Get categories for a bank (hierarchical)
   */
  getCategories(bank_id: number): Observable<QuestionCategory[]> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'get-categories',
      bank_id
    };
    return this.http.post<ApiResponse<QuestionCategory[]>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  // ==========================================
  // TAGS
  // ==========================================

  /**
   * Create a new tag
   */
  createTag(data: QuestionTagForm): Observable<{ tag_id: number }> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'create-tag',
      ...data
    };
    return this.http.post<ApiResponse<{ tag_id: number }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  /**
   * Delete a tag
   */
  deleteTag(tag_id: number): Observable<void> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'delete-tag',
      tag_id
    };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  /**
   * Get all tags
   */
  getTags(): Observable<QuestionTag[]> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'get-tags'
    };
    return this.http.post<ApiResponse<QuestionTag[]>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  // ==========================================
  // QUESTIONS
  // ==========================================

  /**
   * Create a new question
   */
  createQuestion(data: QuestionForm): Observable<{ question_id: number }> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'create-question',
      ...data
    };
    return this.http.post<ApiResponse<{ question_id: number }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  /**
   * Update an existing question
   */
  updateQuestion(question_id: number, data: Partial<QuestionForm>): Observable<void> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'update-question',
      question_id,
      ...data
    };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  /**
   * Delete (archive) a question
   */
  deleteQuestion(question_id: number): Observable<void> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'delete-question',
      question_id
    };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  /**
   * Permanently delete a question
   */
  permanentDeleteQuestion(question_id: number): Observable<void> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'permanent-delete',
      question_id
    };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  /**
   * Get a single question with options, tags, and media
   */
  getQuestion(question_id: number): Observable<Question> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'get-question',
      question_id
    };
    return this.http.post<ApiResponse<Question>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  /**
   * Get questions with filters and pagination
   */
  getQuestions(filters: QuestionFilters = {}): Observable<QuestionsResponse> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'get-questions',
      ...filters
    };
    return this.http.post<ApiResponse<QuestionsResponse>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  /**
   * Clone a question
   */
  cloneQuestion(question_id: number, target_bank_id?: number): Observable<{ question_id: number }> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'clone-question',
      question_id,
      target_bank_id
    };
    return this.http.post<ApiResponse<{ question_id: number }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  /**
   * Bulk delete (archive) questions
   */
  bulkDeleteQuestions(question_ids: number[]): Observable<{ affected_count: number }> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'bulk-delete',
      question_ids
    };
    return this.http.post<ApiResponse<{ affected_count: number }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  /**
   * Bulk move questions to a category
   */
  bulkMoveQuestions(question_ids: number[], category_id: number | null): Observable<{ affected_count: number }> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'bulk-move',
      question_ids,
      category_id
    };
    return this.http.post<ApiResponse<{ affected_count: number }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  // ==========================================
  // MEDIA
  // ==========================================

  /**
   * Add media to a question
   */
  addQuestionMedia(question_id: number, data: {
    media_type: 'image' | 'audio' | 'video' | 'document';
    file_name: string;
    file_path: string;
    file_size?: number;
    mime_type?: string;
    sort_order?: number;
  }): Observable<{ media_id: number }> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'add-media',
      question_id,
      ...data
    };
    return this.http.post<ApiResponse<{ media_id: number }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  /**
   * Delete question media
   */
  deleteQuestionMedia(media_id: number): Observable<void> {
    const payload = {
      ...this.getAuthPayload(),
      action: 'delete-media',
      media_id
    };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload)
      .pipe(map(() => void 0));
  }

  // ==========================================
  // UTILITY METHODS
  // ==========================================

  /**
   * Get question type display name
   */
  getQuestionTypeLabel(type: QuestionType): string {
    const labels: { [key in QuestionType]: string } = {
      'multiple_choice_single': 'Multiple Choice (Single)',
      'multiple_choice_multiple': 'Multiple Choice (Multiple)',
      'true_false': 'True/False',
      'fill_in_blanks': 'Fill in the Blanks',
      'short_answer': 'Short Answer',
      'long_answer': 'Long Answer/Essay',
      'matching': 'Matching',
      'ordering': 'Ordering/Sequence',
      'hotspot': 'Hotspot',
      'numeric': 'Numeric',
      'file_upload': 'File Upload'
    };
    return labels[type] || type;
  }

  /**
   * Get question type icon (Bootstrap Icons)
   */
  getQuestionTypeIcon(type: QuestionType): string {
    const icons: { [key in QuestionType]: string } = {
      'multiple_choice_single': 'bi-ui-radios',
      'multiple_choice_multiple': 'bi-ui-checks',
      'true_false': 'bi-toggle-on',
      'fill_in_blanks': 'bi-input-cursor-text',
      'short_answer': 'bi-text-paragraph',
      'long_answer': 'bi-file-earmark-text',
      'matching': 'bi-arrows-expand',
      'ordering': 'bi-list-ol',
      'hotspot': 'bi-bullseye',
      'numeric': 'bi-123',
      'file_upload': 'bi-upload'
    };
    return icons[type] || 'bi-question-circle';
  }

  /**
   * Get difficulty badge class
   */
  getDifficultyBadgeClass(difficulty: Difficulty): string {
    const classes: { [key in Difficulty]: string } = {
      'easy': 'bg-success',
      'medium': 'bg-warning',
      'hard': 'bg-danger'
    };
    return classes[difficulty] || 'bg-secondary';
  }

  /**
   * Get all question types
   */
  getQuestionTypes(): { value: QuestionType; label: string; icon: string }[] {
    const types: QuestionType[] = [
      'multiple_choice_single',
      'multiple_choice_multiple',
      'true_false',
      'fill_in_blanks',
      'short_answer',
      'long_answer',
      'matching',
      'ordering',
      'hotspot',
      'numeric',
      'file_upload'
    ];
    return types.map(type => ({
      value: type,
      label: this.getQuestionTypeLabel(type),
      icon: this.getQuestionTypeIcon(type)
    }));
  }

  /**
   * Check if question type supports auto-grading
   */
  isAutoGradable(type: QuestionType): boolean {
    const autoGradable: QuestionType[] = [
      'multiple_choice_single',
      'multiple_choice_multiple',
      'true_false',
      'fill_in_blanks',
      'matching',
      'ordering',
      'hotspot',
      'numeric'
    ];
    return autoGradable.includes(type);
  }

  /**
   * Check if question type requires options
   */
  requiresOptions(type: QuestionType): boolean {
    const requiresOptions: QuestionType[] = [
      'multiple_choice_single',
      'multiple_choice_multiple',
      'true_false',
      'matching',
      'ordering'
    ];
    return requiresOptions.includes(type);
  }

  /**
   * Get default options for a question type
   */
  getDefaultOptions(type: QuestionType): QuestionOption[] {
    switch (type) {
      case 'true_false':
        return [
          { option_text: 'True', is_correct: true, points_if_selected: 1, sort_order: 0 },
          { option_text: 'False', is_correct: false, points_if_selected: 0, sort_order: 1 }
        ];
      case 'multiple_choice_single':
      case 'multiple_choice_multiple':
        return [
          { option_text: 'Option A', is_correct: false, points_if_selected: 0, sort_order: 0 },
          { option_text: 'Option B', is_correct: false, points_if_selected: 0, sort_order: 1 },
          { option_text: 'Option C', is_correct: false, points_if_selected: 0, sort_order: 2 },
          { option_text: 'Option D', is_correct: false, points_if_selected: 0, sort_order: 3 }
        ];
      case 'matching':
        return [
          { option_text: 'Item 1', match_text: 'Match 1', is_correct: true, points_if_selected: 1, sort_order: 0 },
          { option_text: 'Item 2', match_text: 'Match 2', is_correct: true, points_if_selected: 1, sort_order: 1 },
          { option_text: 'Item 3', match_text: 'Match 3', is_correct: true, points_if_selected: 1, sort_order: 2 }
        ];
      case 'ordering':
        return [
          { option_text: 'Step 1', correct_position: 1, is_correct: true, points_if_selected: 1, sort_order: 0 },
          { option_text: 'Step 2', correct_position: 2, is_correct: true, points_if_selected: 1, sort_order: 1 },
          { option_text: 'Step 3', correct_position: 3, is_correct: true, points_if_selected: 1, sort_order: 2 }
        ];
      default:
        return [];
    }
  }
}
