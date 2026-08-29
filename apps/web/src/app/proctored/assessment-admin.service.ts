import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient, Endpoints, type Paged } from '../core/api';

// ---------------------------------------------------------------------------
// Types — the new REST shapes, camelCase and UUID-keyed.
// ---------------------------------------------------------------------------

export type AssessmentKind = 'quiz' | 'exam' | 'practice' | 'survey';
export type AssessmentStatus = 'draft' | 'published' | 'closed' | 'archived';
export type ProctoringLevel = 'none' | 'basic' | 'standard' | 'advanced';
export type PassingType = 'percentage' | 'points';

/** One assessment as the authoring API returns it. */
export interface Assessment {
  id: string;
  title: string;
  description: string | null;
  instructions: string | null;
  kind: AssessmentKind;
  status: AssessmentStatus;
  statusLabel: string;
  difficulty: string | null;
  courseId: string | null;
  moduleId: string | null;
  categoryId: string | null;
  timeLimitMinutes: number | null;
  availableFrom: string | null;
  availableUntil: string | null;
  gracePeriodMinutes: number;
  maxAttempts: number | null;
  attemptsGapHours: number;
  passingScore: number;
  passingType: PassingType;
  questionSelection: string;
  randomQuestionCount: number | null;
  shuffleQuestions: boolean;
  shuffleOptions: boolean;
  revealAnswers: string;
  showScoreImmediately: boolean;
  showExplanations: boolean;
  allowBackwardNavigation: boolean;
  requiresManualGrading: boolean;
  proctoringLevel: ProctoringLevel;
  questionCount: number;
  totalPoints: number;
  /** Attempt statistics — present on the listing, zero on the single view. */
  totalAttempts: number;
  totalCompletions: number;
  passRate: number;
  avgScore: number;
  publishedAt: string | null;
  createdAt: string | null;
  updatedAt: string | null;
}

export interface AssessmentStats {
  total: number;
  published: number;
  draft: number;
  closed: number;
  totalAttempts: number;
  totalCompletions: number;
  avgPassRate: number;
}

export interface AssessmentFilters {
  search?: string;
  status?: string;
  kind?: string;
  proctoringLevel?: string;
  categoryId?: string;
  sort?: string;
  order?: string;
  page?: number;
  perPage?: number;
}

/** The fields the create/edit form sends. */
export interface AssessmentInput {
  title: string;
  kind: AssessmentKind;
  description?: string | null;
  instructions?: string | null;
  difficulty?: string | null;
  courseId?: string | null;
  moduleId?: string | null;
  categoryId?: string | null;
  timeLimitMinutes?: number | null;
  availableFrom?: string | null;
  availableUntil?: string | null;
  gracePeriodMinutes?: number;
  maxAttempts?: number | null;
  attemptsGapHours?: number;
  passingScore?: number;
  passingType?: PassingType;
  questionSelection?: string;
  randomQuestionCount?: number | null;
  shuffleQuestions?: boolean;
  shuffleOptions?: boolean;
  revealAnswers?: string;
  showScoreImmediately?: boolean;
  showExplanations?: boolean;
  allowBackwardNavigation?: boolean;
  proctoringLevel?: ProctoringLevel;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * The authoring side of assessments, on the REST API.
 *
 * Replaces the legacy `POST /user/assessment` with an `action` field — a
 * single endpoint that switched on a string — with the typed routes under
 * `/assessments`. The gap the old screens filled with their own arithmetic
 * (attempt counts, pass rates) is computed by the API now.
 */
@Injectable({ providedIn: 'root' })
export class AssessmentAdminService {
  private readonly api = inject(ApiClient);

  list(filters: AssessmentFilters = {}): Observable<Paged<Assessment>> {
    return this.api.getPaged<Assessment>(Endpoints.assessments.list, { ...filters });
  }

  stats(): Observable<AssessmentStats> {
    return this.api.get<AssessmentStats>(Endpoints.assessments.stats);
  }

  get(id: string): Observable<Assessment> {
    return this.api.get<Assessment>(Endpoints.assessments.show(id));
  }

  create(input: AssessmentInput): Observable<Assessment> {
    return this.api.post<Assessment>(Endpoints.assessments.create, input);
  }

  update(id: string, input: AssessmentInput): Observable<Assessment> {
    return this.api.put<Assessment>(Endpoints.assessments.update(id), input);
  }

  setStatus(id: string, status: AssessmentStatus): Observable<Assessment> {
    return this.api.patch<Assessment>(Endpoints.assessments.status(id), { status });
  }

  delete(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.assessments.delete(id));
  }

  duplicate(id: string): Observable<Assessment> {
    return this.api.post<Assessment>(Endpoints.assessments.duplicate(id), {});
  }
}
