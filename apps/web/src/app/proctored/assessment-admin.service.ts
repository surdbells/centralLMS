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
  trainingId: string | null;
  welcomeMessage: string | null;
  completionMessage: string | null;
  negativeMarking: boolean;
  negativeMarkingPercentage: number;
  partialCredit: boolean;
  questionsPerPage: number;
  showQuestionNumbers: boolean;
  showProgressBar: boolean;
  gradingScaleId: string | null;
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

/** One question as it sits on an assessment (from the show response). */
export interface AttachedQuestion {
  id: string;
  text: string;
  type: string;
  typeLabel?: string;
  points: number;
  pointsOverride: number | null;
  pointsInThisAssessment: number;
  sortOrder: number;
  isRequired: boolean;
  sectionId: string | null;
  /** Present because the show payload embeds the review shape of each question. */
  difficulty?: string | null;
  mediaUrl?: string | null;
}

/** The assessment with its attached questions, for the editor. */
export interface AssessmentDetail extends Assessment {
  questions: AttachedQuestion[];
}

export interface AssessmentSection {
  id: string;
  assessmentId: string;
  title: string;
  description: string | null;
  instructions: string | null;
  timeLimitMinutes: number | null;
  selectionType: string;
  randomCount: number | null;
  sortOrder: number;
  questionCount: number;
  totalPoints: number;
}

/** A question in a bank, for the picker. */
export interface BankQuestion {
  id: string;
  bankId: string;
  type: string;
  typeLabel: string;
  text: string;
  points: number;
  difficulty: string | null;
}

export interface QuestionBank {
  id: string;
  name: string;
  questionCount: number;
}

export type AssignmentType = 'user' | 'department' | 'team' | 'branch' | 'all';

export interface AssessmentAssignment {
  id: string;
  assessmentId: string;
  assignmentType: AssignmentType;
  targetId: string | null;
  targetName: string | null;
  dueDate: string | null;
  createdAt: string | null;
}

export interface AssignmentTargets {
  users: Array<{ id: string; name: string }>;
  departments: Array<{ id: string; name: string }>;
  teams: Array<{ id: string; name: string }>;
  branches: Array<{ id: string; name: string }>;
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
  trainingId?: string | null;
  welcomeMessage?: string | null;
  completionMessage?: string | null;
  negativeMarking?: boolean;
  negativeMarkingPercentage?: number;
  partialCredit?: boolean;
  questionsPerPage?: number;
  showQuestionNumbers?: boolean;
  showProgressBar?: boolean;
  gradingScaleId?: string | null;
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

  /** The assessment with its attached questions, for the editor. */
  detail(id: string): Observable<AssessmentDetail> {
    return this.api.get<AssessmentDetail>(Endpoints.assessments.show(id));
  }

  // --- sections -----------------------------------------------------------

  sections(id: string): Observable<AssessmentSection[]> {
    return this.api.get<AssessmentSection[]>(Endpoints.assessments.sections(id));
  }

  createSection(id: string, input: Partial<AssessmentSection>): Observable<AssessmentSection> {
    return this.api.post<AssessmentSection>(Endpoints.assessments.createSection(id), input);
  }

  updateSection(id: string, sectionId: string, input: Partial<AssessmentSection>): Observable<AssessmentSection> {
    return this.api.put<AssessmentSection>(Endpoints.assessments.updateSection(id, sectionId), input);
  }

  deleteSection(id: string, sectionId: string): Observable<void> {
    return this.api.delete<void>(Endpoints.assessments.deleteSection(id, sectionId));
  }

  reorderSections(id: string, orderedIds: string[]): Observable<unknown> {
    return this.api.put<unknown>(Endpoints.assessments.reorderSections(id), { order: orderedIds });
  }

  // --- questions ----------------------------------------------------------

  attachQuestion(
    id: string,
    questionId: string,
    pointsOverride?: number | null,
    sectionId?: string | null,
  ): Observable<AttachedQuestion[]> {
    return this.api.post<AttachedQuestion[]>(Endpoints.assessments.questions(id), {
      questionId,
      pointsOverride,
      sectionId,
    });
  }

  detachQuestion(id: string, questionId: string): Observable<AttachedQuestion[]> {
    return this.api.delete<AttachedQuestion[]>(Endpoints.assessments.detachQuestion(id, questionId));
  }

  setQuestionPoints(id: string, questionId: string, pointsOverride: number | null): Observable<AttachedQuestion[]> {
    return this.api.put<AttachedQuestion[]>(Endpoints.assessments.setQuestionPoints(id, questionId), {
      pointsOverride,
    });
  }

  setQuestionSection(id: string, questionId: string, sectionId: string | null): Observable<unknown> {
    return this.api.put<unknown>(Endpoints.assessments.setQuestionSection(id, questionId), { sectionId });
  }

  reorderQuestions(id: string, orderedQuestionIds: string[]): Observable<unknown> {
    return this.api.put<unknown>(Endpoints.assessments.reorderQuestions(id), { order: orderedQuestionIds });
  }

  // --- the question picker ------------------------------------------------

  banks(): Observable<Paged<QuestionBank>> {
    return this.api.getPaged<QuestionBank>(Endpoints.banks.list, { perPage: 200 });
  }

  bankQuestions(bankId: string, filters: { search?: string; type?: string } = {}): Observable<Paged<BankQuestion>> {
    return this.api.getPaged<BankQuestion>(Endpoints.questions.list(bankId), { perPage: 200, ...filters });
  }

  // --- assignments --------------------------------------------------------

  assignments(id: string): Observable<AssessmentAssignment[]> {
    return this.api.get<AssessmentAssignment[]>(Endpoints.assessments.assignments(id));
  }

  createAssignment(
    id: string,
    assignmentType: AssignmentType,
    targetId: string | null,
    dueDate: string | null,
  ): Observable<AssessmentAssignment> {
    return this.api.post<AssessmentAssignment>(Endpoints.assessments.assignments(id), {
      assignmentType,
      targetId,
      dueDate,
    });
  }

  deleteAssignment(id: string, assignmentId: string): Observable<void> {
    return this.api.delete<void>(Endpoints.assessments.deleteAssignment(id, assignmentId));
  }

  assignmentTargets(): Observable<AssignmentTargets> {
    return this.api.get<AssignmentTargets>(Endpoints.assessments.assignmentTargets);
  }
}
