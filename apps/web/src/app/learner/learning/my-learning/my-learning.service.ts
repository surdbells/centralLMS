// my-learning/my-learning.service.ts

import { Injectable, inject } from '@angular/core';
import { Observable, forkJoin, of } from 'rxjs';
import { map } from 'rxjs/operators';
import { ApiClient, Endpoints } from '../../../core/api';
import { DashboardsService } from '../../../services/dashboards.service';
import {
  EnrollmentsService,
  type Enrollment as ApiEnrollment,
  type EnrollmentStatus,
} from '../../../services/enrollments.service';
import { LookupsService } from '../../../services/lookups.service';

// Interfaces
export interface LearningStats {
  total_enrolled: number;
  in_progress: number;
  completed: number;
  not_started: number;
  overdue: number;
  certificates_earned: number;
  total_points: number;
  current_streak: number;
  total_learning_time_minutes: number;
}

export interface EnrolledCourse {
  enrollment_id: string;
  course_id: string;
  status: 'not_started' | 'in_progress' | 'completed';
  progress_percentage: number;
  enrolled_at: string;
  started_at: string | null;
  completed_at: string | null;
  due_date: string | null;
  is_mandatory: boolean;
  last_accessed_at: string | null;
  title: string;
  description: string;
  thumbnail_url: string | null;
  duration_minutes: number;
  difficulty_level: string;
  category_name: string | null;
  total_lessons: number;
  completed_lessons: number;
  is_overdue: boolean;
  days_until_due: number | null;
}

export interface InProgressCourse {
  enrollment_id: string;
  course_id: string;
  progress_percentage: number;
  due_date: string | null;
  is_mandatory: boolean;
  last_accessed_at: string | null;
  title: string;
  thumbnail_url: string | null;
  duration_minutes: number;
  next_lesson_id: string | null;
  next_lesson_title: string | null;
  is_overdue: boolean;
}

export interface CompletedCourse {
  enrollment_id: string;
  course_id: string;
  completed_at: string;
  title: string;
  thumbnail_url: string | null;
  duration_minutes: number;
  category_name: string | null;
  certificate_id: string | null;
  has_certificate: boolean;
}

export interface Lesson {
  lesson_id: string;
  title: string;
  lesson_type: string;
  duration_minutes: number;
  sort_order: number;
  is_completed: boolean;
  progress_percentage: number;
  last_position: number | null;
  completed_at: string | null;
}

export interface CourseModule {
  module_id: string | null;
  title: string;
  description: string | null;
  sort_order: number;
  lessons: Lesson[];
}

export interface Certificate {
  certificate_id: string;
  certificate_number: string;
  issued_at: string;
  expires_at: string | null;
  certificate_url: string | null;
}

export interface CourseDetails {
  enrollment_id: string;
  course_id: string;
  status: 'not_started' | 'in_progress' | 'completed';
  progress_percentage: number;
  enrolled_at: string;
  started_at: string | null;
  completed_at: string | null;
  due_date: string | null;
  is_mandatory: boolean;
  last_accessed_at: string | null;
  title: string;
  description: string;
  thumbnail_url: string | null;
  duration_minutes: number;
  difficulty_level: string;
  objectives: string | null;
  category_name: string | null;
  is_overdue: boolean;
  modules: CourseModule[];
  certificate?: Certificate;
}

export interface AvailableCourse {
  course_id: string;
  title: string;
  description: string;
  thumbnail_url: string | null;
  duration_minutes: number;
  level: string;
  category_name: string | null;
  enrollment_count: number;
}

export interface Category {
  category_id: string;
  category_name: string;
  category_code: string;
}

export interface NextLesson {
  lesson_id: string;
  title: string;
  lesson_type: string;
  duration_minutes: number;
  progress_percentage: number;
  last_position: number | null;
}

export interface ApiResponse<T> {
  code: number;
  status: string;
  data: T;
  message: string;
}

/**
 * My Learning, over the new API.
 *
 * It used to post to one legacy endpoint with
 * `?action=stats|enrolled-courses|self-enroll|…` and `token`, `user_id` and
 * `tenant_id` read out of `atob(sessionStorage.SESSION)` — the tenant supplied
 * by the client, as everywhere else in the legacy.
 *
 * The screen above is untouched. The shapes it binds to are kept exactly,
 * snake_case and all, and filled from the endpoints that answer the same
 * questions: the learner's dashboard for the figures, `/me/enrollments` for
 * what somebody is on, and the catalogue for what they could join.
 *
 * Two of its ten calls turn out to have had nothing behind them.
 * `startCourse` and `updateLastAccessed` both existed to stamp a row when a
 * learner opened something; the player records that with the first piece of
 * progress it sends, inside the same transaction, so a separate "I have
 * started" call can only disagree with it.
 */
@Injectable({
  providedIn: 'root',
})
export class MyLearningService {
  private readonly api = inject(ApiClient);
  private readonly dashboards = inject(DashboardsService);
  private readonly enrollments = inject(EnrollmentsService);
  private readonly lookups = inject(LookupsService);

  // ==========================================
  // FIGURES
  // ==========================================

  getStats(): Observable<LearningStats> {
    return this.dashboards.forMe().pipe(map((mine) => ({
      total_enrolled: mine.overview.enrolled,
      in_progress: mine.overview.inProgress,
      completed: mine.overview.completed,
      not_started: mine.overview.notStarted,
      overdue: mine.overview.overdue,
      certificates_earned: mine.overview.certificates,
      total_points: mine.overview.points,
      current_streak: mine.streak.current,
      total_learning_time_minutes: Math.round(mine.overview.secondsSpent / 60),
    })));
  }

  // ==========================================
  // WHAT SOMEBODY IS ON
  // ==========================================

  getEnrolledCourses(status?: string, search?: string): Observable<EnrolledCourse[]> {
    return this.enrollments
      .mine({
        status: status === '' || status === 'all' ? undefined : (status as EnrollmentStatus),
        search: search === '' ? undefined : search,
      })
      .pipe(map((rows) => rows.map(toEnrolled)));
  }

  getInProgressCourses(limit = 5): Observable<InProgressCourse[]> {
    return this.enrollments
      .mine({ status: 'in_progress' })
      .pipe(map((rows) => rows.slice(0, limit).map(toInProgress)));
  }

  getCompletedCourses(limit = 10): Observable<CompletedCourse[]> {
    return this.enrollments
      .mine({ status: 'completed' })
      .pipe(map((rows) => rows.slice(0, limit).map(toCompleted)));
  }

  /**
   * One course, with its modules and lessons and how far this person has got.
   *
   * The outline and the progress are separate requests because they are
   * separate things — one describes the course, the other describes one
   * person on it — and they are merged here, the same way the player does it.
   */
  getCourseDetails(courseId: string): Observable<CourseDetails> {
    return forkJoin({
      outline: this.api.get<OutlinePayload>(Endpoints.courses.outline(courseId)),
      mine: this.api.get<MyProgressPayload>(Endpoints.me.course(courseId)),
    }).pipe(map(({ outline, mine }) => toCourseDetails(outline, mine)));
  }

  getNextLesson(courseId: string): Observable<NextLesson | null> {
    return this.getCourseDetails(courseId).pipe(map((course) => {
      for (const module of course.modules) {
        for (const lesson of module.lessons) {
          if (!lesson.is_completed) {
            return {
              lesson_id: lesson.lesson_id,
              title: lesson.title,
              lesson_type: lesson.lesson_type,
              duration_minutes: lesson.duration_minutes,
              progress_percentage: lesson.progress_percentage,
              last_position: lesson.last_position,
            };
          }
        }
      }

      return null;
    }));
  }

  // ==========================================
  // JOINING SOMETHING
  // ==========================================

  getAvailableCourses(search?: string, categoryId?: string): Observable<AvailableCourse[]> {
    return this.api
      .getPaged<Record<string, unknown>>(Endpoints.courses.catalogue, {
        search: search === '' ? undefined : search,
        categoryId,
        perPage: 100,
      })
      .pipe(map((page) => page.items.map(toAvailable)));
  }

  selfEnroll(courseId: string): Observable<{ success: boolean; message: string }> {
    // The screen reads `success` and `message` off the answer. The API
    // says no by failing, not by returning `success: false`, so a reply
    // that arrives at all is a yes.
    return this.api
      .post(Endpoints.courses.selfEnroll(courseId), {})
      .pipe(map(() => ({ success: true, message: 'You are enrolled.' })));
  }

  // ==========================================
  // NOTHING BEHIND THEM
  // ==========================================

  /**
   * Opening a course no longer needs announcing.
   *
   * The player records the first piece of progress in the same transaction
   * that moves the enrolment to "in progress", so a separate call saying "I
   * have started" can only ever disagree with it. Kept because the screen
   * calls it, and it resolves rather than posting to a retired host.
   */
  startCourse(_courseId: string): Observable<{ success: boolean; message: string }> {
    return of({ success: true, message: '' });
  }

  /** Likewise: last activity is stamped by the progress that caused it. */
  updateLastAccessed(_courseId: string): Observable<{ success: boolean; message: string }> {
    return of({ success: true, message: '' });
  }

  // ==========================================
  // CATEGORIES
  // ==========================================

  getCategories(): Observable<Category[]> {
    return this.lookups.active('category').pipe(map((rows) => rows.map((row) => ({
      category_id: row.id,
      category_name: row.name,
      category_code: row.code ?? '',
    }))));
  }
}

// ---------------------------------------------------------------------------
// Translation
// ---------------------------------------------------------------------------

interface OutlinePayload {
  course: Record<string, any>;
  modules: Array<Record<string, any>>;
}

interface MyProgressPayload {
  enrollment: Record<string, any>;
  lessons: Record<string, Record<string, any>>;
}

function toEnrolled(e: ApiEnrollment): EnrolledCourse {
  return {
    enrollment_id: e.id,
    course_id: e.courseId,
    status: e.status as EnrolledCourse['status'],
    progress_percentage: e.progressPercentage,
    enrolled_at: e.enrolledAt ?? '',
    started_at: e.startedAt,
    completed_at: e.completedAt,
    due_date: e.dueDate,
    is_mandatory: e.isMandatory,
    last_accessed_at: e.lastActivityAt,
    title: e.courseTitle ?? '',
    description: '',
    thumbnail_url: e.courseThumbnailUrl,
    duration_minutes: 0,
    difficulty_level: e.courseLevel ?? '',
    category_name: e.courseCategory,
    // The lesson counts belong to the outline, which a list of enrolments does
    // not carry — and fetching an outline per row to fill them in would be one
    // request per course on a page. The percentage is what this screen draws.
    total_lessons: 0,
    completed_lessons: 0,
    is_overdue: e.isOverdue,
    days_until_due: e.daysRemaining,
  };
}

function toInProgress(e: ApiEnrollment): InProgressCourse {
  return {
    enrollment_id: e.id,
    course_id: e.courseId,
    progress_percentage: e.progressPercentage,
    due_date: e.dueDate,
    is_mandatory: e.isMandatory,
    last_accessed_at: e.lastActivityAt,
    title: e.courseTitle ?? '',
    thumbnail_url: e.courseThumbnailUrl,
    duration_minutes: 0,
    next_lesson_id: e.lastLessonId,
    next_lesson_title: null,
    is_overdue: e.isOverdue,
  };
}

function toCompleted(e: ApiEnrollment): CompletedCourse {
  return {
    enrollment_id: e.id,
    course_id: e.courseId,
    completed_at: e.completedAt ?? '',
    title: e.courseTitle ?? '',
    thumbnail_url: e.courseThumbnailUrl,
    duration_minutes: 0,
    category_name: e.courseCategory,
    // Whether a certificate exists is the certificates endpoint's answer, not
    // something to infer from having finished — a course need not issue one.
    certificate_id: null,
    has_certificate: false,
  };
}

function toCourseDetails(outline: OutlinePayload, mine: MyProgressPayload): CourseDetails {
  const course = outline.course ?? {};
  const enrollment = mine.enrollment ?? {};
  // Keyed by lesson id, which is the point of it — see MyCourseProgressAction.
  const progress = new Map<string, Record<string, any>>(Object.entries(mine.lessons ?? {}));

  return {
    enrollment_id: String(enrollment['id'] ?? ''),
    course_id: String(course['id'] ?? ''),
    status: (enrollment['status'] ?? 'not_started') as CourseDetails['status'],
    progress_percentage: Number(enrollment['progressPercentage'] ?? 0),
    enrolled_at: String(enrollment['enrolledAt'] ?? ''),
    started_at: enrollment['startedAt'] ?? null,
    completed_at: enrollment['completedAt'] ?? null,
    due_date: enrollment['dueDate'] ?? null,
    is_mandatory: Boolean(enrollment['isMandatory']),
    last_accessed_at: enrollment['lastActivityAt'] ?? null,
    title: String(course['title'] ?? ''),
    description: String(course['description'] ?? course['summary'] ?? ''),
    thumbnail_url: course['thumbnailUrl'] ?? null,
    duration_minutes: Number(course['durationMinutes'] ?? 0),
    difficulty_level: String(course['level'] ?? ''),
    objectives: course['objectives'] ?? null,
    category_name: course['categoryName'] ?? null,
    is_overdue: Boolean(enrollment['isOverdue']),
    modules: (outline.modules ?? []).map((m) => ({
      module_id: String(m['id']),
      title: String(m['title'] ?? ''),
      description: m['description'] ?? null,
      sort_order: Number(m['sortOrder'] ?? 0),
      lessons: ((m['lessons'] ?? []) as Array<Record<string, any>>).map((l) => {
        const p = progress.get(String(l['id']));

        return {
          lesson_id: String(l['id']),
          title: String(l['title'] ?? ''),
          lesson_type: String(l['contentType'] ?? 'text'),
          duration_minutes: Number(l['durationMinutes'] ?? 0),
          sort_order: Number(l['sortOrder'] ?? 0),
          is_completed: p?.['status'] === 'completed',
          progress_percentage: Number(p?.['progressPercentage'] ?? 0),
          last_position: p?.['lastPositionSeconds'] ?? null,
          completed_at: p?.['completedAt'] ?? null,
        };
      }),
    })),
  };
}

function toAvailable(course: Record<string, any>): AvailableCourse {
  return {
    course_id: String(course['id']),
    title: String(course['title'] ?? ''),
    description: String(course['summary'] ?? course['description'] ?? ''),
    thumbnail_url: course['thumbnailUrl'] ?? null,
    duration_minutes: Number(course['durationMinutes'] ?? 0),
    level: String(course['level'] ?? ''),
    category_name: course['categoryName'] ?? null,
    enrollment_count: Number(course['enrollmentCount'] ?? 0),
  };
}
