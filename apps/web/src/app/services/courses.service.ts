import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient, Endpoints, type Paged } from '../core/api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export type CourseStatus = 'draft' | 'published' | 'archived';
export type CourseLevel = 'beginner' | 'intermediate' | 'professional';
export type LessonContentType = 'video' | 'text' | 'audio' | 'pdf' | 'scorm' | 'html' | 'quiz';

export interface Course {
  id: string;
  title: string;
  summary: string | null;
  description: string | null;
  /** Free text, one per line. It was never a list on the server. */
  objectives: string | null;
  kind: string;
  status: CourseStatus;
  level: CourseLevel | null;
  categoryId: string | null;
  instructorId: string | null;
  thumbnailUrl: string | null;
  passPercentage: number;
  durationMinutes: number | null;
  enrollmentLimit: number | null;
  isMandatory: boolean;
  isFeatured: boolean;
  isOnboarding: boolean;
  allowSelfEnrollment: boolean;
  startsOn: string | null;
  endsOn: string | null;
  /** Averaged from the ratings, never stored on the course. */
  rating: { average: number | null; count: number };
  publishedAt: string | null;
  createdAt: string | null;
  updatedAt: string | null;
}

/** A duplicate, with what came across it. */
export interface DuplicatedCourse extends Course {
  copiedModules: number;
  copiedLessons: number;
}

export interface CourseModule {
  id: string;
  courseId: string;
  title: string;
  description: string | null;
  sortOrder: number;
  isActive: boolean;
  lessonCount?: number;
}

export interface Lesson {
  id: string;
  courseId: string;
  moduleId: string;
  title: string;
  description: string | null;
  contentType: LessonContentType;
  contentUrl: string | null;
  contentText: string | null;
  durationMinutes: number | null;
  sortOrder: number;
  isMandatory: boolean;
  isPreview: boolean;
  isActive: boolean;
}

/** A course with its modules and lessons, for the player and the builder. */
export interface CourseOutline {
  course: Course;
  modules: Array<CourseModule & { lessons: Lesson[] }>;
}

export interface CourseStatistics {
  total: number;
  draft: number;
  published: number;
  archived: number;
  mandatory: number;
  featured: number;
  /** Across every course. Counted, never stored. */
  enrollments: number;
}

export interface CourseFilters {
  search?: string;
  status?: CourseStatus;
  categoryId?: string;
  instructorId?: string;
  level?: CourseLevel;
  page?: number;
  perPage?: number;
}

export interface CourseInput {
  title: string;
  summary?: string | null;
  description?: string | null;
  objectives?: string | null;
  level?: CourseLevel | null;
  categoryId?: string | null;
  instructorId?: string | null;
  thumbnailUrl?: string | null;
  passPercentage?: number;
  durationMinutes?: number | null;
  enrollmentLimit?: number | null;
  isMandatory?: boolean;
  isFeatured?: boolean;
  isOnboarding?: boolean;
  allowSelfEnrollment?: boolean;
  startsOn?: string | null;
  endsOn?: string | null;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * The course catalogue and its structure.
 *
 * `status` is not settable through {@link create} or {@link update}, and the
 * server ignores it if sent. Publishing is its own call because it is a
 * different act with a different permission — an instructor may write a course
 * and not release it — and its own refusal: a course with no lessons cannot be
 * published, because a learner would enrol and find nothing to do.
 */
@Injectable({ providedIn: 'root' })
export class CoursesService {
  private readonly api = inject(ApiClient);

  // --- the catalogue ------------------------------------------------------

  /** Everything, drafts included. Needs `course.manage`. */
  list(filters: CourseFilters = {}): Observable<Paged<Course>> {
    return this.api.getPaged<Course>(Endpoints.courses.list, { ...filters });
  }

  /** What a learner may browse: published, and visible to them. */
  catalogue(filters: CourseFilters = {}): Observable<Paged<Course>> {
    return this.api.getPaged<Course>(Endpoints.courses.catalogue, { ...filters });
  }

  statistics(): Observable<CourseStatistics> {
    return this.api.get<CourseStatistics>(Endpoints.courses.statistics);
  }

  show(id: string): Observable<Course> {
    return this.api.get<Course>(Endpoints.courses.show(id));
  }

  /** Modules and lessons in one call, so a builder does not fetch N+1 times. */
  outline(id: string): Observable<CourseOutline> {
    return this.api.get<CourseOutline>(Endpoints.courses.outline(id));
  }

  create(input: CourseInput): Observable<Course> {
    return this.api.post<Course>(Endpoints.courses.create, input);
  }

  update(id: string, input: Partial<CourseInput>): Observable<Course> {
    return this.api.put<Course>(Endpoints.courses.update(id), input);
  }

  setStatus(id: string, status: CourseStatus): Observable<Course> {
    return this.api.patch<Course>(Endpoints.courses.status(id), { status });
  }

  /**
   * A copy, as a draft.
   *
   * Its modules and lessons come across; its enrolments, rating and the skills
   * it teaches do not — see the API's DuplicateCourseAction for why each.
   */
  duplicate(id: string): Observable<DuplicatedCourse> {
    return this.api.post<DuplicatedCourse>(Endpoints.courses.duplicate(id), {});
  }

  /**
   * Refused while anybody is enrolled, and while it is published.
   *
   * The error says how many people are on it and names archiving as the thing
   * that was probably meant.
   */
  delete(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.courses.delete(id));
  }

  /** A learner putting themselves on an open course. */
  selfEnroll(id: string): Observable<unknown> {
    return this.api.post(Endpoints.courses.selfEnroll(id), {});
  }

  // --- structure ----------------------------------------------------------

  modules(courseId: string): Observable<CourseModule[]> {
    return this.api.get<CourseModule[]>(Endpoints.modules.list(courseId));
  }

  createModule(courseId: string, input: Partial<CourseModule>): Observable<CourseModule> {
    return this.api.post<CourseModule>(Endpoints.modules.create(courseId), input);
  }

  updateModule(courseId: string, id: string, input: Partial<CourseModule>): Observable<CourseModule> {
    return this.api.put<CourseModule>(Endpoints.modules.update(courseId, id), input);
  }

  setModuleStatus(courseId: string, id: string, isActive: boolean): Observable<CourseModule> {
    return this.api.patch<CourseModule>(Endpoints.modules.status(courseId, id), { isActive });
  }

  deleteModule(courseId: string, id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.modules.delete(courseId, id));
  }

  /** One statement, so a failure cannot leave the list half-ordered. */
  reorderModules(courseId: string, moduleIds: string[]): Observable<CourseModule[]> {
    return this.api.put<CourseModule[]>(Endpoints.modules.reorder(courseId), { moduleIds });
  }

  lessons(courseId: string, moduleId: string): Observable<Lesson[]> {
    return this.api.get<Lesson[]>(Endpoints.lessons.list(courseId, moduleId));
  }

  createLesson(courseId: string, moduleId: string, input: Partial<Lesson>): Observable<Lesson> {
    return this.api.post<Lesson>(Endpoints.lessons.create(courseId, moduleId), input);
  }

  updateLesson(
    courseId: string,
    moduleId: string,
    id: string,
    input: Partial<Lesson>,
  ): Observable<Lesson> {
    return this.api.put<Lesson>(Endpoints.lessons.update(courseId, moduleId, id), input);
  }

  setLessonStatus(
    courseId: string,
    moduleId: string,
    id: string,
    isActive: boolean,
  ): Observable<Lesson> {
    return this.api.patch<Lesson>(Endpoints.lessons.status(courseId, moduleId, id), { isActive });
  }

  deleteLesson(courseId: string, moduleId: string, id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.lessons.delete(courseId, moduleId, id));
  }

  reorderLessons(courseId: string, moduleId: string, lessonIds: string[]): Observable<Lesson[]> {
    return this.api.put<Lesson[]>(Endpoints.lessons.reorder(courseId, moduleId), { lessonIds });
  }
}
