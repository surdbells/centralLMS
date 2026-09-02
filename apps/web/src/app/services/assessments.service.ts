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
  /** Matching questions: the right-hand side this option pairs with. */
  matchText?: string | null;
  /** Ordering questions: this item's correct position. */
  sortOrder?: number;
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
  // The API validates against the canonical set (multiple_choice_single, ...)
  // and folds the older names in, so a string is accepted here.
  type: QuestionType | string;
  text: string;
  points?: number;
  explanation?: string | null;
  options?: QuestionOption[];
  isActive?: boolean;
  difficulty?: string | null;
  hint?: string | null;
  modelAnswer?: string | null;
  numericAnswer?: number | null;
  numericTolerance?: number | null;
  mediaUrl?: string | null;
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

  createBank(input: { name: string; description?: string | null }): Observable<QuestionBank> {
    return this.api.post<QuestionBank>(Endpoints.banks.create, input);
  }

  updateBank(id: string, input: { name?: string; description?: string | null }): Observable<QuestionBank> {
    return this.api.put<QuestionBank>(Endpoints.banks.update(id), input);
  }

  deleteBank(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.banks.delete(id));
  }

  /** The questions in one bank, in their review shape (with the answer key). */
  bankQuestions(bankId: string): Observable<Paged<Record<string, unknown>>> {
    return this.api.getPaged<Record<string, unknown>>(Endpoints.questions.list(bankId), { perPage: 200 });
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

  // --- sections -------------------------------------------------------------

  sections(assessmentId: string): Observable<AssessmentSection[]> {
    return this.api.get<AssessmentSection[]>(Endpoints.assessments.sections(assessmentId));
  }

  createSection(assessmentId: string, input: SectionInput): Observable<AssessmentSection> {
    return this.api.post<AssessmentSection>(
      Endpoints.assessments.createSection(assessmentId),
      input,
    );
  }

  updateSection(
    assessmentId: string,
    sectionId: string,
    input: Partial<SectionInput>,
  ): Observable<AssessmentSection> {
    return this.api.put<AssessmentSection>(
      Endpoints.assessments.updateSection(assessmentId, sectionId),
      input,
    );
  }

  deleteSection(assessmentId: string, sectionId: string): Observable<void> {
    return this.api.delete<void>(Endpoints.assessments.deleteSection(assessmentId, sectionId));
  }

  /** One list, so a failure cannot leave two sections claiming a position. */
  reorderSections(assessmentId: string, sectionIds: string[]): Observable<unknown> {
    return this.api.put(Endpoints.assessments.reorderSections(assessmentId), { sectionIds });
  }

  moveQuestionToSection(
    assessmentId: string,
    questionId: string,
    sectionId: string | null,
  ): Observable<unknown> {
    return this.api.put(
      Endpoints.assessments.setQuestionSection(assessmentId, questionId),
      { sectionId },
    );
  }

  // --- how a paper is drawn -------------------------------------------------

  /**
   * The rules that pick questions at random.
   *
   * An assessment either lists its questions or describes them — "six from
   * this bank, difficulty medium" — and the rules are that description. They
   * are read and written whole because a half-applied set of rules produces a
   * paper nobody intended.
   */
  rules(assessmentId: string): Observable<RandomRule[]> {
    return this.api.get<RandomRule[]>(Endpoints.assessments.rules(assessmentId));
  }

  createRule(assessmentId: string, input: RandomRuleInput): Observable<RandomRule> {
    return this.api.post<RandomRule>(Endpoints.assessments.createRule(assessmentId), input);
  }

  deleteRule(assessmentId: string, ruleId: string): Observable<void> {
    return this.api.delete<void>(Endpoints.assessments.deleteRule(assessmentId, ruleId));
  }

  // --- sitting one ----------------------------------------------------------

  /**
   * Opens an attempt, or returns the one already open.
   *
   * The server decides which: a learner who closed the tab mid-paper gets
   * their attempt back with the answers they had already given, rather than a
   * second attempt against their limit.
   */
  startAttempt(assessmentId: string): Observable<AttemptPaper> {
    return this.api.post<AttemptPaper>(Endpoints.attempts.start(assessmentId), {});
  }

  attempt(attemptId: string): Observable<AttemptPaper> {
    return this.api.get<AttemptPaper>(Endpoints.attempts.show(attemptId));
  }

  /**
   * Records one answer.
   *
   * One at a time, deliberately: an attempt interrupted halfway keeps what was
   * already put down, which a single all-or-nothing submission cannot do.
   */
  saveAnswer(attemptId: string, questionId: string, answer: unknown): Observable<unknown> {
    return this.api.put(Endpoints.attempts.answer(attemptId, questionId), { answer });
  }

  submitAttempt(attemptId: string): Observable<AttemptResult> {
    return this.api.post<AttemptResult>(Endpoints.attempts.submit(attemptId), {});
  }

  /** Every attempt this person has sat, newest first. */
  myAttempts(limit = 50): Observable<AttemptResult[]> {
    return this.api.get<AttemptResult[]>(Endpoints.me.attempts, { limit });
  }

  // --- marking --------------------------------------------------------------

  /**
   * What is waiting to be marked, longest-waiting first.
   *
   * There is no queue table: the queue is the attempts with an ungraded
   * written answer, which is a question the attempts themselves can answer.
   * That is why there is nothing here to assign, prioritise or claim — a
   * second table tracking who is marking what would need keeping in step with
   * the answers, and would be wrong whenever it was not.
   */
  awaitingMarking(): Observable<Paged<AttemptResult>> {
    return this.api.getPaged<AttemptResult>(Endpoints.attempts.awaiting, { perPage: 100 });
  }

  queueFor(assessmentId: string): Observable<Paged<AttemptResult>> {
    return this.api.getPaged<AttemptResult>(Endpoints.attempts.queue(assessmentId), {
      perPage: 100,
    });
  }

  grade(
    attemptId: string,
    questionId: string,
    marks: { pointsEarned: number; feedback?: string },
  ): Observable<unknown> {
    return this.api.put(Endpoints.attempts.grade(attemptId, questionId), marks);
  }

  // --- proctoring -----------------------------------------------------------

  proctoringSettings(assessmentId: string): Observable<ProctoringSettings> {
    return this.api.get<ProctoringSettings>(Endpoints.proctoring.settings(assessmentId));
  }

  saveProctoringSettings(
    assessmentId: string,
    settings: Partial<ProctoringSettings>,
  ): Observable<ProctoringSettings> {
    return this.api.put<ProctoringSettings>(
      Endpoints.proctoring.settings(assessmentId),
      settings,
    );
  }

  /** Every flagged sitting; `pending` for the ones not yet signed off. */
  flaggedSittings(pending = false): Observable<Paged<Record<string, unknown>>> {
    return this.api.getPaged<Record<string, unknown>>(Endpoints.proctoring.allQueues, {
      pending: pending ? 1 : undefined,
      perPage: 100,
    });
  }

  flaggedFor(assessmentId: string): Observable<Paged<Record<string, unknown>>> {
    return this.api.getPaged<Record<string, unknown>>(
      Endpoints.proctoring.reviewQueue(assessmentId),
      { perPage: 100 },
    );
  }

  sitting(attemptId: string): Observable<Record<string, unknown>> {
    return this.api.get<Record<string, unknown>>(Endpoints.proctoring.review(attemptId));
  }

  reviewSitting(
    attemptId: string,
    verdict: { outcome: string; notes?: string },
  ): Observable<unknown> {
    return this.api.put(Endpoints.proctoring.review(attemptId), verdict);
  }

  reviewViolation(
    violationId: string,
    verdict: { status: 'valid' | 'false_positive' | 'inconclusive'; notes?: string },
  ): Observable<unknown> {
    return this.api.put(Endpoints.proctoring.reviewViolation(violationId), verdict);
  }

  openProctoringSession(attemptId: string, environment: Record<string, unknown>):
  Observable<Record<string, unknown>> {
    return this.api.post<Record<string, unknown>>(
      Endpoints.proctoring.openSession(attemptId),
      environment,
    );
  }

  recordViolation(attemptId: string, violation: Record<string, unknown>): Observable<unknown> {
    return this.api.post(Endpoints.proctoring.recordViolation(attemptId), violation);
  }

  /**
   * A webcam still.
   *
   * Sent as it is taken rather than collected and uploaded at the end: a tab
   * closed mid-paper otherwise takes the evidence with it, which is the one
   * moment it matters.
   */
  recordSnapshot(attemptId: string, snapshot: Record<string, unknown>): Observable<unknown> {
    return this.api.post(Endpoints.proctoring.recordSnapshot(attemptId), snapshot);
  }

  /**
   * The stills taken during one sitting.
   *
   * Off the sitting rather than an endpoint of their own: a review shows
   * the violations and the stills together, and two requests for one
   * screen is two chances for them to disagree about what happened.
   */
  snapshots(attemptId: string): Observable<Array<Record<string, unknown>>> {
    return this.sitting(attemptId).pipe(
      map((review) => (review['snapshots'] ?? []) as Array<Record<string, unknown>>),
    );
  }

  violations(attemptId: string): Observable<Array<Record<string, unknown>>> {
    return this.sitting(attemptId).pipe(
      map((review) => (review['violations'] ?? []) as Array<Record<string, unknown>>),
    );
  }

  // --- rubrics ---------------------------------------------------------

  rubrics(activeOnly = false): Observable<Rubric[]> {
    return this.api.get<Rubric[]>(Endpoints.rubrics.list, {
      activeOnly: activeOnly ? 1 : undefined,
    });
  }

  rubric(id: string): Observable<Rubric> {
    return this.api.get<Rubric>(Endpoints.rubrics.show(id));
  }

  createRubric(input: RubricInput): Observable<Rubric> {
    return this.api.post<Rubric>(Endpoints.rubrics.create, input);
  }

  /**
   * Saves a rubric.
   *
   * Sending `criteria` replaces them all. Leaving the key out leaves them
   * alone — the API distinguishes the two, so a form that edits only the
   * name cannot empty the rubric by omission.
   */
  updateRubric(id: string, input: Partial<RubricInput>): Observable<Rubric> {
    return this.api.put<Rubric>(Endpoints.rubrics.update(id), input);
  }

  deleteRubric(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.rubrics.delete(id));
  }

  duplicateRubric(id: string, name?: string): Observable<Rubric> {
    return this.api.post<Rubric>(Endpoints.rubrics.duplicate(id), { name });
  }

  // --- who is marking what ---------------------------------------------

  /**
   * The marking queue.
   *
   * `mine` is what a marker wants and a coordinator does not;
   * `unassigned` is the other half of the same question.
   */
  gradingQueue(filters: { mine?: boolean; unassigned?: boolean } = {}):
  Observable<{ items: QueuedAttempt[]; total: number }> {
    return this.api.get<{ items: QueuedAttempt[]; total: number }>(Endpoints.grading.queue, {
      mine: filters.mine === true ? 1 : undefined,
      unassigned: filters.unassigned === true ? 1 : undefined,
    });
  }

  assignForMarking(
    attemptId: string,
    assignedTo: string,
    options: { priority?: number; notes?: string } = {},
  ): Observable<GradingAssignment> {
    return this.api.put<GradingAssignment>(Endpoints.grading.assign(attemptId), {
      assignedTo,
      priority: options.priority ?? 0,
      notes: options.notes ?? null,
    });
  }

  /** Puts a paper back in the general queue. */
  releaseForMarking(attemptId: string): Observable<void> {
    return this.api.delete<void>(Endpoints.grading.release(attemptId));
  }

  skipForMarking(attemptId: string, reason?: string): Observable<GradingAssignment> {
    return this.api.post<GradingAssignment>(Endpoints.grading.skip(attemptId), { reason });
  }

  prioritiseForMarking(attemptId: string, priority: number): Observable<GradingAssignment> {
    return this.api.patch<GradingAssignment>(
      Endpoints.grading.prioritise(attemptId),
      { priority },
    );
  }

  /** Shares a batch out between markers, round-robin. */
  assignManyForMarking(attemptIds: string[], markerIds: string[]):
  Observable<{ assigned: number; markers: number }> {
    return this.api.post<{ assigned: number; markers: number }>(
      Endpoints.grading.assignMany,
      { attemptIds, markerIds },
    );
  }
}

// ---------------------------------------------------------------------------
// The shapes the extended surface works with
// ---------------------------------------------------------------------------

export interface AssessmentSection {
  id: string;
  assessmentId: string;
  title: string;
  instructions: string | null;
  sortOrder: number;
  timeLimitMinutes: number | null;
  questionCount: number;
}

export interface SectionInput {
  title: string;
  instructions?: string | null;
  sortOrder?: number;
  timeLimitMinutes?: number | null;
}

/**
 * A rule that picks questions rather than naming them.
 *
 * "Six from this bank, difficulty medium" — so two people sitting the same
 * assessment get different papers of equal weight.
 */
export interface RandomRule {
  id: string;
  bankId: string;
  bankName: string | null;
  difficulty: string | null;
  questionCount: number;
  pointsEach: number | null;
}

export interface RandomRuleInput {
  bankId: string;
  questionCount: number;
  difficulty?: string | null;
  pointsEach?: number | null;
}

/** An attempt with its paper, as the player receives it. */
export interface AttemptPaper {
  attempt: Record<string, unknown>;
  sections: Array<Record<string, unknown>>;
  questions: Array<Record<string, unknown>>;
}

export interface AttemptResult extends Record<string, unknown> {
  id: string;
  assessmentId: string;
  assessmentTitle: string | null;
  userId: string;
  userName: string | null;
  status: string;
  rawScore: number | null;
  maxScore: number | null;
  percentage: number | null;
  passed: boolean | null;
  startedAt: string | null;
  submittedAt: string | null;
}

export interface ProctoringSettings extends Record<string, unknown> {
  assessmentId: string;
  isEnabled: boolean;
  requireWebcam: boolean;
  requireFullscreen: boolean;
  blockCopyPaste: boolean;
  snapshotIntervalSeconds: number | null;
  maxViolations: number | null;
}

// ---------------------------------------------------------------------------
// Rubrics and the marking queue
// ---------------------------------------------------------------------------

export type RubricKind = 'analytic' | 'holistic';

export interface RubricLevel {
  id: string;
  label: string;
  description: string | null;
  points: number;
  sortOrder: number;
}

export interface RubricCriterion {
  id: string;
  title: string;
  description: string | null;
  /** Taken from the bands, not a stored number — see the API's Rubric. */
  maxPoints: number;
  sortOrder: number;
  levels: RubricLevel[];
}

export interface Rubric {
  id: string;
  name: string;
  description: string | null;
  kind: RubricKind;
  kindLabel: string;
  isActive: boolean;
  /** Whether it has criteria, and every criterion has bands to choose between. */
  isUsable: boolean;
  totalPoints: number;
  criterionCount: number;
  /** How many questions are marked against it. Blocks deletion while > 0. */
  questionCount: number;
  criteria: RubricCriterion[];
  createdAt: string | null;
  updatedAt: string | null;
}

export interface RubricInput {
  name: string;
  description?: string | null;
  kind?: RubricKind;
  isActive?: boolean;
  criteria?: Array<{
    title: string;
    description?: string | null;
    maxPoints?: number;
    levels?: Array<{ label: string; description?: string | null; points: number }>;
  }>;
}

export interface GradingAssignment {
  id: string;
  attemptId: string;
  assignedTo: string | null;
  assigneeName: string | null;
  assignedBy: string | null;
  priority: number;
  /** False once a marker has set it aside; it stays in the queue regardless. */
  isOpen: boolean;
  skippedAt: string | null;
  skipReason: string | null;
  notes: string | null;
}

/** One paper in the queue: the attempt, what is left to mark, and who has it. */
export interface QueuedAttempt extends Record<string, unknown> {
  id: string;
  assessmentId: string;
  assessmentTitle: string | null;
  userName: string | null;
  submittedAt: string | null;
  awaitingMarking: number;
  assignment: GradingAssignment | null;
}
