// course-player/services/course-player.service.ts

import { Injectable, inject } from '@angular/core';
import { BehaviorSubject, Observable, Subject, Subscription, forkJoin, interval, of } from 'rxjs';
import { map, switchMap, takeUntil, tap } from 'rxjs/operators';
import { ApiClient, Endpoints } from '../../core/api';
import { MediaService } from '../../services/media.service';

// ---------------------------------------------------------------------------
// What the player works with
// ---------------------------------------------------------------------------
//
// Ids are strings. They used to be numbers, and the player took its course id
// with `+params['courseId']` — which every caller in the application now feeds
// a UUID, so it received NaN and asked the server for course "NaN". The player
// has been unreachable from the dashboard, from My Learning, from a report and
// from a notification, all four of which link to it.

export interface CoursePlayerData {
  course: Course;
  enrollment: Enrollment;
  modules: Module[];
  assessments: Assessment[];
  bookmarks: Bookmark[];
  user_rating: UserRating | null;
  stats: CourseStats;
}

export interface Course {
  course_id: string;
  title: string;
  description: string;
  thumbnail_url: string;
  duration_minutes: number;
  difficulty_level: string;
  pass_mark: number;
  certificate_template_id: string | null;
  is_mandatory: boolean;
  category_name: string;
  instructor_name: string;
}

export interface Enrollment {
  enrollment_id: string;
  status: 'not_started' | 'in_progress' | 'completed';
  progress_percentage: number;
  enrolled_at: string;
  started_at: string;
  completed_at: string;
  due_date: string;
  time_spent_minutes: number;
  last_accessed_at: string;
  last_lesson_id: string | null;
  final_score: number;
  attempts: number;
}

export interface Module {
  module_id: string;
  title: string;
  description: string;
  sort_order: number;
  duration_minutes: number;
  lessons: Lesson[];
  assessments: Assessment[];
  completed_lessons: number;
  total_lessons: number;
}

export interface Lesson {
  lesson_id: string;
  module_id: string;
  title: string;
  description: string;
  content_type: 'video' | 'audio' | 'pdf' | 'scorm' | 'html' | 'quiz' | 'text';
  content_url: string;
  content_text: string;
  duration_minutes: number;
  sort_order: number;
  is_mandatory: boolean;
  pass_mark: number;
  status: 'not_started' | 'in_progress' | 'completed';
  progress: number;
  completed_at: string;
  time_spent_seconds: number;
  score: number;
  last_position: number;
  attempts: number;
}

export interface Assessment {
  assessment_id: string;
  module_id: string | null;
  title: string;
  description: string;
  assessment_type: 'quiz' | 'exam' | 'assignment';
  time_limit_minutes: number;
  pass_percentage: number;
  max_attempts: number;
  shuffle_questions: boolean;
  show_correct_answers: boolean;
  sequence_order: number;
  total_questions: number;
  attempts_used: number;
  best_score: number | null;
  has_passed: boolean;
}

export interface Bookmark {
  bookmark_id: string;
  lesson_id: string;
  position_seconds: number;
  note: string;
  created_at: string;
}

export interface UserRating {
  rating: number;
  review: string;
}

export interface CourseStats {
  total_lessons: number;
  completed_lessons: number;
  completion_percentage: number;
}

export interface Note {
  note_id: string;
  lesson_id: string;
  title: string;
  content: string;
  /** Where in the lesson it was taken. The template's name for it. */
  video_timestamp: number;
  is_pinned: boolean;
  created_at: string;
  updated_at: string;
}

export interface QuizData {
  assessment: {
    assessment_id: string;
    title: string;
    description: string;
    assessment_type: 'quiz' | 'exam' | 'assignment';
    time_limit_minutes: number;
    pass_percentage: number;
    max_attempts: number;
    shuffle_questions: boolean;
    show_correct_answers: boolean;
    total_questions: number;
  };
  questions: QuizQuestion[];
  attempts_used: number;
  best_score: number;
  can_attempt: boolean;
}

export interface QuizQuestion {
  question_id: string;
  question_text: string;
  question_type: 'single_choice' | 'multiple_choice' | 'true_false' | 'short_answer' | 'essay' | 'fill_blank';
  points: number;
  sequence_order: number;
  options: QuizQuestionOption[];
}

export interface QuizQuestionOption {
  option_id: string;
  option_text: string;
  sequence_order: number;
}

export interface QuizAttemptStart {
  attempt_id: string;
  started_at: string;
  time_limit_minutes: number;
  questions: QuizQuestion[];
}

export interface QuestionResult {
  question_id: string;
  user_answer: unknown;
  correct_answer: unknown;
  is_correct: boolean;
  points: number;
  explanation?: string;
}

export interface QuizResult {
  attempt_id: string;
  score: number;
  max_score: number;
  percentage: number;
  passed: boolean;
  passing_score: number;
  attempts_used: number;
  max_attempts: number;
  points_earned: number;
  /**
   * Whether the answer key may be shown.
   *
   * The assessment decides — `revealAnswers` — and it can depend on whether
   * the deadline has passed or the attempts are used up. The client asks; it
   * does not work it out, which is how an answer key leaks.
   */
  show_correct_answers: boolean;
  results: QuestionResult[];
}
export interface ProgressUpdate {
  overall_progress: number;
  lessons_completed: number;
  total_lessons: number;
  points_earned?: number;
  badges_earned?: unknown[];
}

export interface ResumePosition {
  lesson_id: string;
  lesson_title: string;
  position: number;
  progress: number;
}

/**
 * Everything the course player asks of the server.
 *
 * It used to ask one legacy endpoint, `GlobalComponent.coursePlayer`, with
 * `?action=load|track|complete-lesson|…` and `token`, `user_id` and
 * `tenant_id` read out of `atob(sessionStorage.SESSION)` and appended to every
 * query string and body. Sixteen actions, one parameter apart, with the tenant
 * supplied by the client.
 *
 * Four things worth naming:
 *
 *  1. **The player was unreachable.** It read its course id with
 *     `+params['courseId']`, and every link into it — the learner dashboard,
 *     My Learning, a report row, a notification — now passes a UUID. `+uuid`
 *     is NaN. Ids are strings here, throughout.
 *  2. **A course was one request; it is now two, in parallel.** The outline
 *     (`/courses/{id}/outline`) says what the course contains; the enrolment
 *     (`/me/enrollments/{id}`) says how far this person has got. Merging them
 *     on the client is what lets the same outline serve an author previewing
 *     and a learner working, without a second server-side assembly of the
 *     same thing that drifts from the first.
 *  3. **Sessions are gone, and nothing is lost.** `start-session`/`end-session`
 *     existed to hold a row that time was accumulated against. Time is now an
 *     increment sent with progress, which is one fewer thing to leak when a
 *     browser is closed mid-lesson — the legacy's open sessions were never
 *     closed by anything.
 *  4. **Bookmarks, ratings and SCORM runtime data have no server.** No table
 *     for any of them survived the migration, and the endpoints they called
 *     are gone. Rather than fail silently — which is what a request to a
 *     retired host does — each says so. The SCORM *outcome* (status, score)
 *     is real and is recorded as lesson progress, which is the part that
 *     decides whether somebody has completed the course.
 */
@Injectable({
  providedIn: 'root',
})
export class CoursePlayerService {
  private readonly api = inject(ApiClient);
  private readonly media = inject(MediaService);

  // State
  private courseDataSubject = new BehaviorSubject<CoursePlayerData | null>(null);
  private currentLessonSubject = new BehaviorSubject<Lesson | null>(null);
  private loadingSubject = new BehaviorSubject<boolean>(false);

  // Tracking
  private trackingInterval: Subscription | null = null;
  private lessonStartTime: Date | null = null;
  private currentVideoPosition = 0;

  /** Seconds already reported for this lesson, so time is sent once. */
  private reportedSeconds = 0;

  private destroy$ = new Subject<void>();

  // Observables
  courseData$ = this.courseDataSubject.asObservable();
  currentLesson$ = this.currentLessonSubject.asObservable();
  loading$ = this.loadingSubject.asObservable();

  // ==========================================
  // COURSE LOADING
  // ==========================================

  loadCourse(courseId: string): Observable<CoursePlayerData> {
    this.loadingSubject.next(true);

    return forkJoin({
      outline: this.api.get<OutlinePayload>(Endpoints.courses.outline(courseId)),
      mine: this.api.get<MyProgressPayload>(Endpoints.me.course(courseId)),
      assessments: this.api
        .getPaged<AssessmentPayload>(Endpoints.assessments.list, { courseId, perPage: 100 })
        .pipe(map((page) => page.items)),
    }).pipe(
      map(({ outline, mine, assessments }) => assemble(outline, mine, assessments)),
      tap((data) => {
        this.courseDataSubject.next(data);
        this.loadingSubject.next(false);
      }),
    );
  }

  // ==========================================
  // SESSION MANAGEMENT
  // ==========================================

  /**
   * Starts counting time against the lesson somebody is on.
   *
   * There is no session row on the server any more, so this takes no ids and
   * asks for nothing. The signature is kept because the component calls it on
   * open and the heartbeat it starts is the real work.
   */
  startSession(): Observable<void> {
    this.startTrackingHeartbeat();

    return of(undefined);
  }

  /** Sends whatever time has accrued, and stops counting. */
  endSession(): Observable<void> {
    this.stopTrackingHeartbeat();

    const pending = this.elapsedSeconds();

    if (pending <= 0) {
      return of(undefined);
    }

    return this.sendHeartbeat().pipe(map(() => undefined));
  }

  // ==========================================
  // PROGRESS TRACKING
  // ==========================================

  private startTrackingHeartbeat(): void {
    this.stopTrackingHeartbeat();

    this.trackingInterval = interval(30000)
      .pipe(takeUntil(this.destroy$))
      .subscribe(() => this.sendHeartbeat().subscribe({ error: () => undefined }));
  }

  private stopTrackingHeartbeat(): void {
    if (this.trackingInterval) {
      this.trackingInterval.unsubscribe();
      this.trackingInterval = null;
    }
  }

  /** Seconds on this lesson that have not been reported yet. */
  private elapsedSeconds(): number {
    if (this.lessonStartTime === null) {
      return 0;
    }

    const total = Math.floor((Date.now() - this.lessonStartTime.getTime()) / 1000);

    return Math.max(0, total - this.reportedSeconds);
  }

  private sendHeartbeat(): Observable<ProgressUpdate | null> {
    const courseData = this.courseDataSubject.getValue();
    const currentLesson = this.currentLessonSubject.getValue();

    if (courseData === null || currentLesson === null) {
      return of(null);
    }

    const seconds = this.elapsedSeconds();

    if (seconds <= 0) {
      return of(null);
    }

    this.reportedSeconds += seconds;

    return this.trackProgress(
      courseData.course.course_id,
      currentLesson.lesson_id,
      currentLesson.progress,
      seconds,
      this.currentVideoPosition,
    );
  }

  /**
   * Records where somebody has got to.
   *
   * `secondsSpent` is an increment rather than a total — the server adds it —
   * so two tabs open on the same lesson do not each overwrite the other's
   * total with their own.
   */
  trackProgress(
    courseId: string,
    lessonId: string,
    progress: number,
    secondsSpent: number,
    positionSeconds: number,
    score?: number,
  ): Observable<ProgressUpdate> {
    return this.api
      .put<ProgressPayload>(Endpoints.me.recordProgress(courseId, lessonId), {
        progressPercentage: progress,
        secondsSpent,
        positionSeconds,
        score,
      })
      .pipe(
        map(toProgressUpdate),
        tap((update) => this.applyProgress(update)),
      );
  }

  updateVideoPosition(position: number): void {
    this.currentVideoPosition = position;
  }

  // ==========================================
  // LESSON NAVIGATION
  // ==========================================

  selectLesson(lesson: Lesson): void {
    // Whatever time is owed for the lesson being left.
    this.sendHeartbeat().subscribe({ error: () => undefined });

    this.lessonStartTime = new Date();
    this.reportedSeconds = 0;
    this.currentVideoPosition = lesson.last_position || 0;

    this.currentLessonSubject.next(lesson);
  }

  completeLesson(
    courseId: string,
    lessonId: string,
    timeSpent: number,
    score?: number,
  ): Observable<ProgressUpdate> {
    return this.api
      .put<ProgressPayload>(Endpoints.me.recordProgress(courseId, lessonId), {
        completed: true,
        secondsSpent: timeSpent,
        score,
      })
      .pipe(
        map(toProgressUpdate),
        tap((update) => {
          const courseData = this.courseDataSubject.getValue();

          if (courseData) {
            courseData.modules.forEach((module) => {
              const lesson = module.lessons.find((l) => l.lesson_id === lessonId);

              if (lesson && lesson.status !== 'completed') {
                lesson.status = 'completed';
                lesson.progress = 100;
                module.completed_lessons++;
              }
            });
          }

          this.applyProgress(update);

          const currentLesson = this.currentLessonSubject.getValue();

          if (currentLesson && currentLesson.lesson_id === lessonId) {
            currentLesson.status = 'completed';
            currentLesson.progress = 100;
            this.currentLessonSubject.next({ ...currentLesson });
          }
        }),
      );
  }

  private applyProgress(update: ProgressUpdate): void {
    const courseData = this.courseDataSubject.getValue();

    if (courseData === null) {
      return;
    }

    // Counted from the outline rather than taken from the response: the
    // server returns the enrolment's percentage, and how many lessons that
    // is depends on the outline, which is here.
    const completed = courseData.modules.reduce(
      (total, module) => total + module.lessons.filter((l) => l.status === 'completed').length,
      0,
    );

    update.lessons_completed = completed;
    update.total_lessons = courseData.stats.total_lessons;

    courseData.enrollment.progress_percentage = update.overall_progress;
    courseData.stats.completed_lessons = completed;
    courseData.stats.completion_percentage = update.overall_progress;
    this.courseDataSubject.next({ ...courseData });
  }

  getNextLesson(): Lesson | null {
    const courseData = this.courseDataSubject.getValue();
    const currentLesson = this.currentLessonSubject.getValue();

    if (!courseData || !currentLesson) return null;

    let foundCurrent = false;

    for (const module of courseData.modules) {
      for (const lesson of module.lessons) {
        if (foundCurrent) return lesson;

        if (lesson.lesson_id === currentLesson.lesson_id) {
          foundCurrent = true;
        }
      }
    }

    return null;
  }

  getPreviousLesson(): Lesson | null {
    const courseData = this.courseDataSubject.getValue();
    const currentLesson = this.currentLessonSubject.getValue();

    if (!courseData || !currentLesson) return null;

    let previousLesson: Lesson | null = null;

    for (const module of courseData.modules) {
      for (const lesson of module.lessons) {
        if (lesson.lesson_id === currentLesson.lesson_id) {
          return previousLesson;
        }

        previousLesson = lesson;
      }
    }

    return null;
  }

  /**
   * Finishing the course.
   *
   * The server decides this: the enrolment is marked complete when the last
   * mandatory lesson is, inside the same transaction that records it. There is
   * nothing left for the client to declare, so this reloads rather than
   * asserts — the old endpoint let a client mark a course complete by asking.
   */
  completeCourse(courseId: string): Observable<CoursePlayerData> {
    return this.loadCourse(courseId);
  }

  // ==========================================
  // NOTES
  // ==========================================

  saveNote(
    lessonId: string,
    content: string,
    positionSeconds?: number,
    title?: string,
  ): Observable<string> {
    return this.api
      .post<{ id: string }>(Endpoints.notes.create, {
        lessonId,
        title: title ?? '',
        content,
        positionSeconds: positionSeconds ?? null,
      })
      .pipe(map((note) => note.id));
  }

  getNotes(lessonId?: string): Observable<Note[]> {
    return this.api
      .getPaged<NotePayload>(Endpoints.notes.list, { lessonId, perPage: 100 })
      .pipe(map((page) => page.items.map(toNote)));
  }

  // ==========================================
  // QUIZ
  // ==========================================

  /**
   * The paper, and where this person stands on it.
   *
   * One request rather than two: starting an attempt returns the questions,
   * and asking for a paper the learner is not going to sit is how the legacy
   * ended up with abandoned attempt rows.
   */
  loadQuiz(assessmentId: string): Observable<QuizData> {
    return this.api
      .get<AssessmentPayload>(Endpoints.assessments.show(assessmentId))
      .pipe(map((assessment) => ({
        assessment: toQuizAssessment(assessment),
        questions: [],
        attempts_used: assessment.attemptsUsed ?? 0,
        best_score: assessment.bestScore ?? 0,
        can_attempt:
          assessment.maxAttempts === null
          || (assessment.attemptsUsed ?? 0) < assessment.maxAttempts,
      })));
  }

  startQuizAttempt(assessmentId: string): Observable<QuizAttemptStart> {
    return this.api
      .post<AttemptPaper>(Endpoints.attempts.start(assessmentId), {})
      .pipe(map(toAttemptStart));
  }

  /**
   * Answers first, then submit.
   *
   * The API takes one answer at a time, deliberately: an attempt interrupted
   * halfway keeps what was already put down, which a single all-or-nothing
   * submission cannot do.
   */
  submitQuiz(
    attemptId: string,
    answers: Array<{ question_id: string; answer: unknown }>,
  ): Observable<QuizResult> {
    const saved = answers.length === 0
      ? of([])
      : forkJoin(answers.map((a) =>
        this.api.put(Endpoints.attempts.answer(attemptId, a.question_id), { answer: a.answer })));

    return saved.pipe(
      switchMap(() => this.api.post<AttemptResult>(Endpoints.attempts.submit(attemptId), {})),
      map(toQuizResult),
    );
  }

  // ==========================================
  // RESUME
  // ==========================================

  getResumePosition(): Observable<ResumePosition | null> {
    const courseData = this.courseDataSubject.getValue();

    if (courseData === null) {
      return of(null);
    }

    const lastId = courseData.enrollment.last_lesson_id;

    if (lastId === null) {
      return of(null);
    }

    // Already on the client: the enrolment names the last lesson touched and
    // the outline holds its position. Asking the server for something both
    // halves of the answer are already here for is a round trip for nothing.
    for (const module of courseData.modules) {
      for (const lesson of module.lessons) {
        if (lesson.lesson_id === lastId) {
          return of({
            lesson_id: lesson.lesson_id,
            lesson_title: lesson.title,
            position: lesson.last_position,
            progress: lesson.progress,
          });
        }
      }
    }

    return of(null);
  }

  // ==========================================
  // SCORM
  // ==========================================

  /**
   * The part of a SCORM package's runtime that outlives the tab.
   *
   * Only the outcome. The full CMI model — every `cmi.interactions.n.id`, every
   * suspend blob — had a legacy endpoint and no table on this side, and
   * inventing one to hold data nothing reads would be storage for its own
   * sake. What decides whether somebody has completed a course is the lesson
   * status and the score, and those are lesson progress like any other.
   */
  saveScormData(
    courseId: string,
    lessonId: string,
    scormData: Record<string, unknown>,
  ): Observable<void> {
    const score = Number(
      scormData['cmi.score.raw'] ?? scormData['cmi.core.score.raw'] ?? NaN,
    );

    const location = Number(
      scormData['cmi.location'] ?? scormData['cmi.core.lesson_location'] ?? 0,
    );

    return this.trackProgress(
      courseId,
      lessonId,
      progressFromScorm(scormData),
      0,
      Number.isFinite(location) ? location : 0,
      Number.isFinite(score) ? score : undefined,
    ).pipe(map(() => undefined));
  }

  completeScormLesson(
    courseId: string,
    lessonId: string,
    status: string,
    score?: number,
  ): Observable<void> {
    const passed = ['completed', 'passed'].includes(status.toLowerCase());

    if (!passed) {
      // "incomplete", "browsed", "failed" — real outcomes, and none of them
      // finish the lesson. Recording them as completion is how a SCORM course
      // reports everybody as trained.
      return this.trackProgress(courseId, lessonId, 50, 0, 0, score).pipe(map(() => undefined));
    }

    return this.completeLesson(courseId, lessonId, 0, score).pipe(map(() => undefined));
  }

  // ==========================================
  // NOT AVAILABLE
  // ==========================================
  //
  // Bookmarks, ratings and per-interaction telemetry each had a legacy action
  // and no table that survived the migration. They fail loudly rather than
  // resolving to nothing: a bookmark that silently does not save is worse than
  // one that says it cannot.

  saveBookmark(): Observable<never> {
    return notAvailable('Bookmarks');
  }

  rateCourse(): Observable<never> {
    return notAvailable('Course ratings');
  }

  logInteraction(): Observable<void> {
    // The exception: this was fire-and-forget telemetry nobody read, called
    // from a dozen places, and failing it loudly would put an error on screen
    // every time somebody pressed pause.
    return of(undefined);
  }

  trackContentInteraction(): Observable<void> {
    return of(undefined);
  }

  // ==========================================
  // CLEANUP
  // ==========================================

  cleanup(): void {
    this.destroy$.next();
    this.stopTrackingHeartbeat();
    this.courseDataSubject.next(null);
    this.currentLessonSubject.next(null);
    this.lessonStartTime = null;
    this.reportedSeconds = 0;
  }

  // ==========================================
  // HELPERS
  // ==========================================

  /** A URL a player can use for a lesson whose content is a library file. */
  resolveContent(reference: string | null): Observable<string | null> {
    return this.media.resolve(reference);
  }

  formatDuration(seconds: number): string {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);

    if (hours > 0) {
      return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }

    return `${minutes}:${secs.toString().padStart(2, '0')}`;
  }
}

// ---------------------------------------------------------------------------
// Translation
// ---------------------------------------------------------------------------

interface OutlinePayload {
  course: Record<string, any>;
  modules: Array<Record<string, any>>;
  totals: { modules: number; lessons: number; durationMinutes: number };
}

interface MyProgressPayload {
  enrollment: Record<string, any>;
  /** Keyed by lesson id, so the player can merge without searching. */
  lessons: Record<string, Record<string, any>>;
  completedLessons: number;
}

interface AssessmentPayload extends Record<string, any> {
  id: string;
  title: string;
  maxAttempts: number | null;
  attemptsUsed?: number;
  bestScore?: number;
}

interface NotePayload {
  id: string;
  lessonId: string | null;
  title: string;
  content: string;
  positionSeconds: number | null;
  isPinned: boolean;
  createdAt: string;
  updatedAt: string;
}

interface ProgressPayload extends Record<string, any> {
  lesson?: Record<string, any>;
  enrollment?: Record<string, any>;
}

interface AttemptPaper {
  attempt: Record<string, any>;
  sections: Array<Record<string, any>>;
  questions: Array<Record<string, any>>;
}

interface AttemptResult extends Record<string, any> {
  score?: number;
  passed?: boolean;
}

/**
 * The outline and the learner's progress, merged.
 *
 * The two halves are kept apart on the server on purpose — one describes the
 * course, the other describes one person on it — and joining them is the
 * player's job because the player is the only thing that needs both.
 */
function assemble(
  outline: OutlinePayload,
  mine: MyProgressPayload,
  assessments: AssessmentPayload[],
): CoursePlayerData {
  // Keyed by lesson id, deliberately — the endpoint says so in as many
  // words — so this reads it as a map rather than iterating it as a list,
  // which walks nothing and silently loses every lesson's progress.
  const progressByLesson = new Map<string, Record<string, any>>(
    Object.entries(mine.lessons ?? {}),
  );

  const modules: Module[] = (outline.modules ?? []).map((m) => {
    const lessons = (m['lessons'] ?? []).map((l: Record<string, any>) =>
      toLesson(l, progressByLesson.get(String(l['id']))));

    return {
      module_id: String(m['id']),
      title: String(m['title'] ?? ''),
      description: String(m['description'] ?? ''),
      sort_order: Number(m['sortOrder'] ?? 0),
      duration_minutes: lessons.reduce((total: number, l: Lesson) => total + l.duration_minutes, 0),
      lessons,
      assessments: assessments
        .filter((a) => a['moduleId'] === m['id'])
        .map(toAssessment),
      completed_lessons: lessons.filter((l: Lesson) => l.status === 'completed').length,
      total_lessons: lessons.length,
    };
  });

  const totalLessons = modules.reduce((total, m) => total + m.total_lessons, 0);
  const completed = modules.reduce((total, m) => total + m.completed_lessons, 0);

  return {
    course: toCourse(outline.course, outline.totals),
    enrollment: toEnrollment(mine.enrollment),
    modules,
    assessments: assessments.map(toAssessment),
    // No table survived the migration for either. Empty rather than absent, so
    // the template's `*ngIf="bookmarks.length"` reads false instead of failing.
    bookmarks: [],
    user_rating: null,
    stats: {
      total_lessons: totalLessons,
      completed_lessons: completed,
      completion_percentage: Number(mine.enrollment?.['progressPercentage'] ?? 0),
    },
  };
}

function toCourse(course: Record<string, any>, totals: OutlinePayload['totals']): Course {
  return {
    course_id: String(course['id']),
    title: String(course['title'] ?? ''),
    description: String(course['description'] ?? course['summary'] ?? ''),
    thumbnail_url: String(course['thumbnailUrl'] ?? ''),
    duration_minutes: Number(course['durationMinutes'] ?? totals?.durationMinutes ?? 0),
    difficulty_level: String(course['level'] ?? ''),
    pass_mark: Number(course['passPercentage'] ?? 0),
    certificate_template_id: course['certificateTemplateId'] ?? null,
    is_mandatory: Boolean(course['isMandatory']),
    category_name: String(course['categoryName'] ?? ''),
    instructor_name: String(course['instructorName'] ?? ''),
  };
}

function toEnrollment(enrollment: Record<string, any>): Enrollment {
  return {
    enrollment_id: String(enrollment?.['id'] ?? ''),
    status: (enrollment?.['status'] ?? 'not_started') as Enrollment['status'],
    progress_percentage: Number(enrollment?.['progressPercentage'] ?? 0),
    enrolled_at: String(enrollment?.['enrolledAt'] ?? ''),
    started_at: String(enrollment?.['startedAt'] ?? ''),
    completed_at: String(enrollment?.['completedAt'] ?? ''),
    due_date: String(enrollment?.['dueDate'] ?? ''),
    time_spent_minutes: Math.round(Number(enrollment?.['timeSpentSeconds'] ?? 0) / 60),
    last_accessed_at: String(enrollment?.['lastActivityAt'] ?? ''),
    last_lesson_id: enrollment?.['lastLessonId'] ?? null,
    final_score: Number(enrollment?.['finalScore'] ?? 0),
    attempts: Number(enrollment?.['attempts'] ?? 0),
  };
}

function toLesson(lesson: Record<string, any>, progress?: Record<string, any>): Lesson {
  return {
    lesson_id: String(lesson['id']),
    module_id: String(lesson['moduleId']),
    title: String(lesson['title'] ?? ''),
    description: String(lesson['description'] ?? ''),
    content_type: (lesson['contentType'] ?? 'text') as Lesson['content_type'],
    content_url: String(lesson['contentUrl'] ?? ''),
    content_text: String(lesson['contentText'] ?? ''),
    duration_minutes: Number(lesson['durationMinutes'] ?? 0),
    sort_order: Number(lesson['sortOrder'] ?? 0),
    is_mandatory: Boolean(lesson['isMandatory']),
    pass_mark: Number(lesson['passMark'] ?? 0),
    status: (progress?.['status'] ?? 'not_started') as Lesson['status'],
    progress: Number(progress?.['progressPercentage'] ?? 0),
    completed_at: String(progress?.['completedAt'] ?? ''),
    time_spent_seconds: Number(progress?.['timeSpentSeconds'] ?? 0),
    score: Number(progress?.['score'] ?? 0),
    last_position: Number(progress?.['lastPositionSeconds'] ?? 0),
    attempts: Number(progress?.['attempts'] ?? 0),
  };
}

function toAssessment(assessment: AssessmentPayload): Assessment {
  return {
    assessment_id: String(assessment['id']),
    module_id: assessment['moduleId'] ?? null,
    title: String(assessment['title'] ?? ''),
    description: String(assessment['description'] ?? ''),
    assessment_type: (assessment['kind'] ?? 'quiz') as Assessment['assessment_type'],
    time_limit_minutes: Number(assessment['timeLimitMinutes'] ?? 0),
    pass_percentage: Number(assessment['passingScore'] ?? 0),
    max_attempts: Number(assessment['maxAttempts'] ?? 0),
    shuffle_questions: Boolean(assessment['shuffleQuestions']),
    show_correct_answers: assessment['revealAnswers'] !== 'never',
    sequence_order: Number(assessment['sortOrder'] ?? 0),
    total_questions: Number(assessment['questionCount'] ?? 0),
    attempts_used: Number(assessment['attemptsUsed'] ?? 0),
    best_score: assessment['bestScore'] ?? null,
    has_passed: Boolean(assessment['hasPassed']),
  };
}

function toQuizAssessment(assessment: AssessmentPayload): QuizData['assessment'] {
  const mapped = toAssessment(assessment);

  return {
    assessment_id: mapped.assessment_id,
    title: mapped.title,
    description: mapped.description,
    assessment_type: mapped.assessment_type,
    time_limit_minutes: mapped.time_limit_minutes,
    pass_percentage: mapped.pass_percentage,
    max_attempts: mapped.max_attempts,
    shuffle_questions: mapped.shuffle_questions,
    show_correct_answers: mapped.show_correct_answers,
    total_questions: mapped.total_questions,
  };
}

function toAttemptStart(paper: AttemptPaper): QuizAttemptStart {
  return {
    attempt_id: String(paper.attempt?.['id'] ?? ''),
    started_at: String(paper.attempt?.['startedAt'] ?? ''),
    time_limit_minutes: Number(paper.attempt?.['timeLimitMinutes'] ?? 0),
    questions: (paper.questions ?? []).map(toQuizQuestion),
  };
}

function toQuizQuestion(question: Record<string, any>): QuizQuestion {
  return {
    question_id: String(question['id']),
    question_text: String(question['prompt'] ?? question['text'] ?? ''),
    question_type: (question['kind'] ?? 'single_choice') as QuizQuestion['question_type'],
    points: Number(question['pointsPossible'] ?? question['points'] ?? 1),
    sequence_order: Number(question['sortOrder'] ?? 0),
    options: (question['options'] ?? []).map((option: Record<string, any>, index: number) => ({
      option_id: String(option['id']),
      option_text: String(option['text'] ?? option['label'] ?? ''),
      sequence_order: Number(option['sortOrder'] ?? index),
    })),
  };
}

/**
 * The submitted attempt, in the shape the quiz screen draws.
 *
 * `percentage`, `passed` and the two scores come back null when the
 * assessment is set to withhold the mark — deliberately null rather than
 * absent, so a client can tell "not shown to you" from "not marked yet". Zero
 * is the honest reading for a screen that has nothing to display.
 */
function toQuizResult(result: AttemptResult): QuizResult {
  const max = Number(result['maxScore'] ?? 0);
  const used = Number(result['attemptNumber'] ?? 0);
  const remaining = result['attemptsRemaining'];

  return {
    attempt_id: String(result['id'] ?? ''),
    score: Number(result['rawScore'] ?? 0),
    max_score: max,
    percentage: Number(result['percentage'] ?? 0),
    passed: result['passed'] === true,
    passing_score: Number(result['passingScore'] ?? 0),
    attempts_used: used,
    // Zero is this screen's word for "as many as you like", which is what a
    // null limit means.
    max_attempts: remaining === null || remaining === undefined ? 0 : used + Number(remaining),
    points_earned: Number(result['pointsEarned'] ?? 0),
    show_correct_answers: result['answersRevealed'] === true,
    results: ((result['answers'] ?? []) as Array<Record<string, any>>).map((answer) => ({
      question_id: String(answer['questionId'] ?? ''),
      user_answer: answer['answer'],
      correct_answer: answer['correctAnswer'],
      is_correct: answer['isCorrect'] === true,
      points: Number(answer['pointsAwarded'] ?? 0),
      explanation: answer['explanation'] ?? undefined,
    })),
  };
}
function toNote(note: NotePayload): Note {
  return {
    note_id: note.id,
    lesson_id: String(note.lessonId ?? ''),
    title: note.title ?? '',
    content: note.content,
    video_timestamp: note.positionSeconds ?? 0,
    is_pinned: Boolean(note.isPinned),
    created_at: note.createdAt,
    updated_at: note.updatedAt ?? note.createdAt,
  };
}

/**
 * What changed, from the enrolment the server sent back.
 *
 * It returns the whole enrolment on every call precisely so the header can
 * move without a second request. The lesson counts are not in it — they are
 * the client's own outline, counted — so they are filled in by the caller
 * from what it already holds.
 */
function toProgressUpdate(payload: ProgressPayload): ProgressUpdate {
  const enrollment = payload['enrollment'] ?? payload;

  return {
    overall_progress: Number(enrollment?.['progressPercentage'] ?? 0),
    lessons_completed: 0,
    total_lessons: 0,
  };
}

/**
 * How far through a SCORM lesson its runtime says somebody is.
 *
 * SCORM has no percentage, only a status, so this is the nearest honest
 * reading of one: started but unfinished counts as halfway, which is what the
 * course percentage needs in order to move at all.
 */
function progressFromScorm(data: Record<string, unknown>): number {
  const status = String(
    data['cmi.completion_status'] ?? data['cmi.core.lesson_status'] ?? '',
  ).toLowerCase();

  if (['completed', 'passed'].includes(status)) {
    return 100;
  }

  return status === 'not attempted' || status === '' ? 0 : 50;
}

function notAvailable(what: string): Observable<never> {
  return new Observable((subscriber) => {
    subscriber.error(new Error(`${what} are not available yet.`));
  });
}
