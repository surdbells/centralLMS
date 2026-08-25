import { decodeSessionBlob } from '../../core/utils/session-blob';
// course-player/services/course-player.service.ts

import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { BehaviorSubject, Observable, Subject, interval, Subscription } from 'rxjs';
import { map, tap, takeUntil } from 'rxjs/operators';
import {GlobalComponent} from '../../global-component';
// Interfaces
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
  course_id: number;
  title: string;
  description: string;
  thumbnail_url: string;
  duration_minutes: number;
  difficulty_level: string;
  pass_mark: number;
  certificate_template_id: number;
  is_mandatory: boolean;
  category_name: string;
  instructor_name: string;
}

export interface Enrollment {
  enrollment_id: number;
  status: 'not_started' | 'in_progress' | 'completed';
  progress_percentage: number;
  enrolled_at: string;
  started_at: string;
  completed_at: string;
  due_date: string;
  time_spent_minutes: number;
  last_accessed_at: string;
  last_lesson_id: number;
  final_score: number;
  attempts: number;
}

export interface Module {
  module_id: number;
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
  lesson_id: number;
  module_id: number;
  title: string;
  description: string;
  content_type: 'video' | 'audio' | 'pdf' | 'scorm' | 'html' | 'quiz';
  content_url: string;
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
  assessment_id: number;
  module_id: number | null;
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
  bookmark_id: number;
  lesson_id: number;
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
  note_id: number;
  lesson_id: number;
  content: string;
  video_timestamp: number;
  is_pinned: boolean;
  created_at: string;
  updated_at: string;
}

export interface QuizResult {
  attempt_id: number;
  score: number;
  max_score: number;
  percentage: number;
  passed: boolean;
  passing_score: number;
  attempts_used: number;
  max_attempts: number;
  points_earned: number;
  show_correct_answers: boolean;
  results: QuestionResult[];
}

export interface QuestionResult {
  question_id: number;
  user_answer: any;
  correct_answer: any;
  is_correct: boolean;
  points: number;
  explanation?: string;
}

export interface QuizData {
  assessment: {
    assessment_id: number;
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
  question_id: number;
  question_text: string;
  question_type: 'single_choice' | 'multiple_choice' | 'true_false' | 'short_answer' | 'essay' | 'fill_blank';
  points: number;
  sequence_order: number;
  options: QuizQuestionOption[];
}

export interface QuizQuestionOption {
  option_id: number;
  option_text: string;
  sequence_order: number;
}

export interface QuizAttemptStart {
  attempt_id: number;
  started_at: string;
  time_limit_minutes: number;
  questions: QuizQuestion[];
}

export interface ProgressUpdate {
  overall_progress: number;
  lessons_completed: number;
  total_lessons: number;
  points_earned?: number;
  badges_earned?: any[];
}

export interface ResumePosition {
  lesson_id: number;
  lesson_title: string;
  position: number;
  progress: number;
}

// API Response interface
interface ApiResponse<T = any> {
  response_code: number;
  status: 'success' | 'error';
  message: string;
  data: T;
}

@Injectable({
  providedIn: 'root'
})
export class CoursePlayerService {
  private apiUrl = GlobalComponent.coursePlayer;

  // State
  private courseDataSubject = new BehaviorSubject<CoursePlayerData | null>(null);
  private currentLessonSubject = new BehaviorSubject<Lesson | null>(null);
  private loadingSubject = new BehaviorSubject<boolean>(false);
  private sessionIdSubject = new BehaviorSubject<number | null>(null);

  // Tracking
  private trackingInterval: Subscription | null = null;
  private sessionStartTime: Date | null = null;
  private lessonStartTime: Date | null = null;
  private currentVideoPosition = 0;
  private destroy$ = new Subject<void>();

  // Observables
  courseData$ = this.courseDataSubject.asObservable();
  currentLesson$ = this.currentLessonSubject.asObservable();
  loading$ = this.loadingSubject.asObservable();

  constructor(private http: HttpClient) {}

  // ==========================================
  // AUTH HELPERS
  // ==========================================

  private getAuthParams(): { token: string; user_id: number; tenant_id: number } {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    return {
      token: this.user_session.token,
      user_id: this.user_session.id,
      tenant_id: this.user_session.tenant_id,
    };
  }
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
    role: "superadmin",
    is_2fa: false,
    is_active: false,
    account_locked: false,
    is_admin: false,
    is_content: false,
    is_instructor: false,
    is_learner: false,
  };
  private buildParams(action: string, additionalParams?: Record<string, any>): HttpParams {
    const auth = this.getAuthParams();

    let params = new HttpParams()
      .set('action', action)
      .set('token', auth.token)
      .set('user_id', auth.user_id)
      .set('tenant_id', auth.tenant_id);

    if (additionalParams) {
      Object.entries(additionalParams).forEach(([key, value]) => {
        if (value !== undefined && value !== null) {
          params = params.set(key, String(value));
        }
      });
    }

    return params;
  }

  private buildBody(data: Record<string, any>): Record<string, any> {
    const auth = this.getAuthParams();
    return { ...auth, ...data };
  }

  // ==========================================
  // COURSE LOADING
  // ==========================================

  loadCourse(courseId: number): Observable<CoursePlayerData> {
    this.loadingSubject.next(true);

    const params = this.buildParams('load', { course_id: courseId });

    return this.http.get<ApiResponse<CoursePlayerData>>(this.apiUrl, { params }).pipe(
      map(response => response.data),
      tap(data => {
        this.courseDataSubject.next(data);
        this.loadingSubject.next(false);
      })
    );
  }

  // ==========================================
  // SESSION MANAGEMENT
  // ==========================================

  startSession(enrollmentId: number, courseId: number): Observable<number> {
    const body = this.buildBody({
      enrollment_id: enrollmentId,
      course_id: courseId
    });

    return this.http.post<ApiResponse<{ session_id: number }>>(
      `${this.apiUrl}?action=start-session`,
      body
    ).pipe(
      map(response => response.data.session_id),
      tap(sessionId => {
        this.sessionIdSubject.next(sessionId);
        this.sessionStartTime = new Date();
        this.startTrackingHeartbeat();
      })
    );
  }

  endSession(): Observable<void> {
    const sessionId = this.sessionIdSubject.getValue();
    if (!sessionId) {
      return new Observable(observer => observer.complete());
    }

    this.stopTrackingHeartbeat();

    const body = this.buildBody({ session_id: sessionId });

    return this.http.post<ApiResponse>(
      `${this.apiUrl}?action=end-session`,
      body
    ).pipe(
      map(() => {
        this.sessionIdSubject.next(null);
        this.sessionStartTime = null;
      })
    );
  }

  // ==========================================
  // PROGRESS TRACKING
  // ==========================================

  private startTrackingHeartbeat(): void {
    this.stopTrackingHeartbeat();

    // Send heartbeat every 30 seconds
    this.trackingInterval = interval(30000)
      .pipe(takeUntil(this.destroy$))
      .subscribe(() => this.sendHeartbeat());
  }

  private stopTrackingHeartbeat(): void {
    if (this.trackingInterval) {
      this.trackingInterval.unsubscribe();
      this.trackingInterval = null;
    }
  }

  private sendHeartbeat(): void {
    const courseData = this.courseDataSubject.getValue();
    const currentLesson = this.currentLessonSubject.getValue();
    const sessionId = this.sessionIdSubject.getValue();

    if (!courseData || !currentLesson) return;

    const timeSpent = this.lessonStartTime
      ? Math.floor((new Date().getTime() - this.lessonStartTime.getTime()) / 1000)
      : 0;

    this.trackProgress(
      courseData.enrollment.enrollment_id,
      currentLesson.lesson_id,
      sessionId || 0,
      currentLesson.progress,
      timeSpent,
      this.currentVideoPosition
    ).subscribe();
  }

  trackProgress(
    enrollmentId: number,
    lessonId: number,
    sessionId: number,
    progress: number,
    timeSpent: number,
    videoPosition: number
  ): Observable<ProgressUpdate> {
    const body = this.buildBody({
      enrollment_id: enrollmentId,
      lesson_id: lessonId,
      session_id: sessionId,
      progress,
      time_spent: timeSpent,
      video_position: videoPosition
    });

    return this.http.post<ApiResponse<ProgressUpdate>>(
      `${this.apiUrl}?action=track`,
      body
    ).pipe(
      map(response => response.data),
      tap(update => {
        // Update local state
        const courseData = this.courseDataSubject.getValue();
        if (courseData) {
          courseData.enrollment.progress_percentage = update.overall_progress;
          courseData.stats.completed_lessons = update.lessons_completed;
          this.courseDataSubject.next({ ...courseData });
        }
      })
    );
  }

  updateVideoPosition(position: number): void {
    this.currentVideoPosition = position;
  }

  // ==========================================
  // LESSON NAVIGATION
  // ==========================================

  selectLesson(lesson: Lesson): void {
    // Send heartbeat for previous lesson
    this.sendHeartbeat();

    // Reset lesson tracking
    this.lessonStartTime = new Date();
    this.currentVideoPosition = lesson.last_position || 0;

    this.currentLessonSubject.next(lesson);
  }

  completeLesson(
    enrollmentId: number,
    lessonId: number,
    timeSpent: number,
    score?: number
  ): Observable<ProgressUpdate> {
    const body = this.buildBody({
      enrollment_id: enrollmentId,
      lesson_id: lessonId,
      time_spent: timeSpent,
      score
    });

    return this.http.post<ApiResponse<ProgressUpdate>>(
      `${this.apiUrl}?action=complete-lesson`,
      body
    ).pipe(
      map(response => response.data),
      tap(update => {
        // Update lesson status in local state
        const courseData = this.courseDataSubject.getValue();
        if (courseData) {
          courseData.modules.forEach(module => {
            const lesson = module.lessons.find(l => l.lesson_id === lessonId);
            if (lesson) {
              lesson.status = 'completed';
              lesson.progress = 100;
              module.completed_lessons++;
            }
          });
          courseData.enrollment.progress_percentage = update.overall_progress;
          courseData.stats.completed_lessons = update.lessons_completed;
          this.courseDataSubject.next({ ...courseData });
        }

        // Update current lesson
        const currentLesson = this.currentLessonSubject.getValue();
        if (currentLesson && currentLesson.lesson_id === lessonId) {
          currentLesson.status = 'completed';
          currentLesson.progress = 100;
          this.currentLessonSubject.next({ ...currentLesson });
        }
      })
    );
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

  // ==========================================
  // COURSE COMPLETION
  // ==========================================

  completeCourse(enrollmentId: number, finalScore?: number): Observable<any> {
    const body = this.buildBody({
      enrollment_id: enrollmentId,
      final_score: finalScore
    });

    return this.http.post<ApiResponse>(
      `${this.apiUrl}?action=complete-course`,
      body
    ).pipe(map(response => response.data));
  }

  // ==========================================
  // BOOKMARKS
  // ==========================================

  saveBookmark(
    enrollmentId: number,
    lessonId: number,
    position: number,
    note?: string
  ): Observable<number> {
    const body = this.buildBody({
      enrollment_id: enrollmentId,
      lesson_id: lessonId,
      position,
      note
    });

    return this.http.post<ApiResponse<{ bookmark_id: number }>>(
      `${this.apiUrl}?action=bookmark`,
      body
    ).pipe(
      map(response => response.data.bookmark_id),
      tap(bookmarkId => {
        // Add to local state
        const courseData = this.courseDataSubject.getValue();
        if (courseData) {
          courseData.bookmarks.unshift({
            bookmark_id: bookmarkId,
            lesson_id: lessonId,
            position_seconds: position,
            note: note || '',
            created_at: new Date().toISOString()
          });
          this.courseDataSubject.next({ ...courseData });
        }
      })
    );
  }

  // ==========================================
  // NOTES
  // ==========================================

  saveNote(
    enrollmentId: number,
    lessonId: number,
    content: string,
    timestamp: number = 0,
    isPinned: boolean = false
  ): Observable<number> {
    const body = this.buildBody({
      enrollment_id: enrollmentId,
      lesson_id: lessonId,
      content,
      timestamp,
      is_pinned: isPinned
    });

    return this.http.post<ApiResponse<{ note_id: number }>>(
      `${this.apiUrl}?action=save-note`,
      body
    ).pipe(map(response => response.data.note_id));
  }

  getNotes(enrollmentId: number, lessonId?: number): Observable<Note[]> {
    const additionalParams: Record<string, any> = { enrollment_id: enrollmentId };
    if (lessonId) {
      additionalParams['lesson_id'] = lessonId;
    }

    const params = this.buildParams('get-notes', additionalParams);

    return this.http.get<ApiResponse<Note[]>>(this.apiUrl, { params }).pipe(
      map(response => response.data)
    );
  }

  // ==========================================
  // QUIZ
  // ==========================================

  /**
   * Load quiz/assessments question-banks
   */
  loadQuiz(assessmentId: number): Observable<QuizData> {
    const params = this.buildParams('load-quiz', { assessment_id: assessmentId });

    return this.http.get<ApiResponse<QuizData>>(this.apiUrl, { params }).pipe(
      map(response => response.data)
    );
  }

  /**
   * Start quiz attempt
   */
  startQuizAttempt(enrollmentId: number, assessmentId: number): Observable<QuizAttemptStart> {
    const body = this.buildBody({
      enrollment_id: enrollmentId,
      assessment_id: assessmentId
    });

    return this.http.post<ApiResponse<QuizAttemptStart>>(
      `${this.apiUrl}?action=start-quiz`,
      body
    ).pipe(map(response => response.data));
  }

  submitQuiz(
    enrollmentId: number,
    assessmentId: number,
    attemptId: number,
    answers: Record<number, any>,
    timeTaken: number
  ): Observable<QuizResult> {
    const body = this.buildBody({
      enrollment_id: enrollmentId,
      assessment_id: assessmentId,
      attempt_id: attemptId,
      answers,
      time_taken: timeTaken
    });

    return this.http.post<ApiResponse<QuizResult>>(
      `${this.apiUrl}?action=submit-quiz`,
      body
    ).pipe(map(response => response.data));
  }

  // ==========================================
  // INTERACTIONS
  // ==========================================

  logInteraction(
    enrollmentId: number,
    lessonId: number,
    type: string,
    data: any = {}
  ): Observable<void> {
    const body = this.buildBody({
      enrollment_id: enrollmentId,
      lesson_id: lessonId,
      interaction_type: type,
      interaction_data: data
    });

    return this.http.post<ApiResponse>(
      `${this.apiUrl}?action=log-interaction`,
      body
    ).pipe(map(() => {}));
  }

  // ==========================================
  // RATING
  // ==========================================

  rateCourse(courseId: number, rating: number, review?: string): Observable<any> {
    const body = this.buildBody({
      course_id: courseId,
      rating,
      review
    });

    return this.http.post<ApiResponse>(
      `${this.apiUrl}?action=rate`,
      body
    ).pipe(
      map(response => response.data),
      tap(() => {
        const courseData = this.courseDataSubject.getValue();
        if (courseData) {
          courseData.user_rating = { rating, review: review || '' };
          this.courseDataSubject.next({ ...courseData });
        }
      })
    );
  }

  // ==========================================
  // RESUME
  // ==========================================

  getResumePosition(enrollmentId: number): Observable<ResumePosition> {
    const params = this.buildParams('resume', { enrollment_id: enrollmentId });

    return this.http.get<ApiResponse<ResumePosition>>(this.apiUrl, { params }).pipe(
      map(response => response.data)
    );
  }

  // ==========================================
  // SCORM
  // ==========================================

  saveScormData(
    enrollmentId: number,
    lessonId: number,
    scormData: Record<string, any>
  ): Observable<void> {
    const body = this.buildBody({
      enrollment_id: enrollmentId,
      lesson_id: lessonId,
      scorm_data: scormData
    });

    return this.http.post<ApiResponse>(
      `${this.apiUrl}?action=scorm-commit`,
      body
    ).pipe(map(() => {}));
  }

  /**
   * Complete SCORM lesson with status and score
   */
  completeScormLesson(
    enrollmentId: number,
    lessonId: number,
    status: string,
    score?: number
  ): Observable<void> {
    const body = this.buildBody({
      enrollment_id: enrollmentId,
      lesson_id: lessonId,
      scorm_status: status,
      scorm_score: score
    });

    return this.http.post<ApiResponse>(
      `${this.apiUrl}?action=complete-scorm-lesson`,
      body
    ).pipe(map(() => {}));
  }

  // ==========================================
  // CONTENT INTERACTION TRACKING
  // ==========================================

  /**
   * Track content interaction (video play/pause, document view, etc.)
   */
  trackContentInteraction(
    courseId: number,
    lessonId: number,
    interactionType: 'video_play' | 'video_pause' | 'video_complete' | 'document_view' | 'document_download' | 'scorm_launch',
    positionSeconds?: number,
    durationSeconds?: number
  ): Observable<void> {
    const body = this.buildBody({
      course_id: courseId,
      lesson_id: lessonId,
      interaction_type: interactionType,
      position_seconds: positionSeconds,
      duration_seconds: durationSeconds
    });

    return this.http.post<ApiResponse>(
      `${this.apiUrl}?action=track-content-interaction`,
      body
    ).pipe(map(() => {}));
  }

  // ==========================================
  // CLEANUP
  // ==========================================

  cleanup(): void {
    this.destroy$.next();
    this.stopTrackingHeartbeat();
    this.courseDataSubject.next(null);
    this.currentLessonSubject.next(null);
    this.sessionIdSubject.next(null);
  }

  // ==========================================
  // HELPERS
  // ==========================================

  formatDuration(seconds: number): string {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = seconds % 60;

    if (hours > 0) {
      return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }
    return `${minutes}:${secs.toString().padStart(2, '0')}`;
  }
}
