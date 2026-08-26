import { Injectable, inject } from '@angular/core';
import { Observable, map, switchMap } from 'rxjs';
import { ApiClient, Endpoints, type Paged } from '../core/api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export type AssessmentStatus = 'draft' | 'published' | 'archived';
export type QuestionType =
  | 'multiple_choice'
  | 'multiple_response'
  | 'true_false'
  | 'short_answer'
  | 'essay'
  | 'matching'
  | 'ordering'
  | 'fill_blank';

export interface Assessment {
  id: string;
  title: string;
  description: string | null;
  instructions: string | null;
  kind: string;
  status: AssessmentStatus;
  statusLabel: string;
  courseId: string | null;
  moduleId: string | null;
  timeLimitMinutes: number | null;
  maxAttempts: number | null;
  passingScore: number;
  passingType: string;
  shuffleQuestions: boolean;
  shuffleOptions: boolean;
  revealAnswers: string;
  showScoreImmediately: boolean;
  showExplanations: boolean;
  /** Counted. The legacy `total_questions` disagreed with reality in 16 of 19 rows. */
  questionCount: number;
  totalPoints: number;
}

export interface AssessmentInput {
  title: string;
  description?: string | null;
  courseId?: string | null;
  moduleId?: string | null;
  kind?: string;
  timeLimitMinutes?: number | null;
  maxAttempts?: number | null;
  passingScore?: number;
  shuffleQuestions?: boolean;
  revealAnswers?: string;
}

export interface QuestionOption {
  id?: string;
  text: string;
  isCorrect: boolean;
  feedback?: string | null;
}

export interface Question {
  id: string;
  bankId: string;
  type: QuestionType;
  typeLabel: string;
  text: string;
  points: number;
  explanation: string | null;
  hint: string | null;
  isActive: boolean;
  options: Array<QuestionOption & { id: string }>;
}

export interface QuestionInput {
  type: QuestionType;
  text: string;
  points?: number;
  explanation?: string | null;
  options?: QuestionOption[];
  isActive?: boolean;
}

export interface QuestionBank {
  id: string;
  name: string;
  description: string | null;
  questionCount: number;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * Assessments, and the questions they draw on.
 *
 * **A question belongs to a bank, not to an assessment.** That is the one real
 * difference from the legacy, where a question was a row hanging off a quiz
 * and could not be reused — the same "what is the evacuation assembly point?"
 * was retyped into every fire course, and fixing the answer in one fixed it in
 * none of the others. An assessment now attaches questions from a bank.
 *
 * {@link bankForCourse} exists so a screen that edits questions in the flow of
 * building one course does not have to make the author think about banks
 * first: it finds or creates the course's own bank, by name, once.
 */
@Injectable({ providedIn: 'root' })
export class AssessmentsService {
  private readonly api = inject(ApiClient);

  // --- assessments ---------------------------------------------------------

  list(filters: { courseId?: string; moduleId?: string; perPage?: number } = {}):
  Observable<Paged<Assessment>> {
    return this.api.getPaged<Assessment>(Endpoints.assessments.list, { ...filters });
  }

  show(id: string): Observable<Assessment> {
    return this.api.get<Assessment>(Endpoints.assessments.show(id));
  }

  create(input: AssessmentInput): Observable<Assessment> {
    return this.api.post<Assessment>(Endpoints.assessments.create, input);
  }

  update(id: string, input: Partial<AssessmentInput>): Observable<Assessment> {
    return this.api.put<Assessment>(Endpoints.assessments.update(id), input);
  }

  setStatus(id: string, status: AssessmentStatus): Observable<Assessment> {
    return this.api.patch<Assessment>(Endpoints.assessments.status(id), { status });
  }

  // --- the questions on one assessment -------------------------------------

  questions(assessmentId: string): Observable<Question[]> {
    return this.api.get<Question[]>(Endpoints.assessments.questions(assessmentId));
  }

  /** Puts an existing question on an assessment. */
  attachQuestion(assessmentId: string, questionId: string, points?: number): Observable<unknown> {
    return this.api.post(Endpoints.assessments.questions(assessmentId), { questionId, points });
  }

  /** Takes it off the assessment. The question itself stays in its bank. */
  detachQuestion(assessmentId: string, questionId: string): Observable<void> {
    return this.api.delete<void>(Endpoints.assessments.detachQuestion(assessmentId, questionId));
  }

  /** One list, so a failure cannot leave two questions claiming a position. */
  reorderQuestions(assessmentId: string, questionIds: string[]): Observable<unknown> {
    return this.api.put(Endpoints.assessments.reorderQuestions(assessmentId), { questionIds });
  }

  // --- banks and questions --------------------------------------------------

  banks(): Observable<Paged<QuestionBank>> {
    return this.api.getPaged<QuestionBank>(Endpoints.banks.list, { perPage: 100 });
  }

  createQuestion(bankId: string, input: QuestionInput): Observable<Question> {
    return this.api.post<Question>(Endpoints.questions.create(bankId), input);
  }

  updateQuestion(bankId: string, id: string, input: Partial<QuestionInput>): Observable<Question> {
    return this.api.put<Question>(Endpoints.questions.update(bankId, id), input);
  }

  /**
   * The bank a course keeps its own questions in, created on first use.
   *
   * Named after the course so it is findable and obviously not a mystery: an
   * author who later opens the question bank screen sees "Fire Safety" rather
   * than a bank with a generated name they cannot account for.
   */
  bankForCourse(courseTitle: string): Observable<QuestionBank> {
    const name = `${courseTitle} questions`;

    return this.banks().pipe(
      map((page) => page.items.find((bank) => bank.name === name)),
      switchMap((existing) => existing !== undefined
        ? [existing]
        : this.api.post<QuestionBank>(Endpoints.banks.create, {
          name,
          description: `Questions written while building "${courseTitle}".`,
        })),
    );
  }
}
