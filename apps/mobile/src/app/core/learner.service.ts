import { Injectable, inject } from '@angular/core';
import { Observable, forkJoin, map, of } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { ApiClient } from './api';
import {
  CatalogueCourse,
  Certificate,
  CourseDetails,
  Enrollment,
  EnrollmentStatus,
  LeaderboardResult,
  LearnerDashboard,
  Profile,
} from './models';

interface OutlinePayload {
  course: Record<string, any>;
  modules: Array<Record<string, any>>;
}
interface MyProgressPayload {
  enrollment: Record<string, any>;
  lessons: Record<string, Record<string, any>>;
}

/** Everything the learner screens read, over the same endpoints as the web app. */
@Injectable({ providedIn: 'root' })
export class LearnerService {
  private readonly api = inject(ApiClient);

  /** The one rich call: stats, in-progress courses, certificates, deadlines. */
  dashboard(): Observable<LearnerDashboard> {
    return this.api.get<LearnerDashboard>('/dashboards/me');
  }

  myEnrollments(status?: EnrollmentStatus, search?: string): Observable<Enrollment[]> {
    return this.api
      .getPaged<Enrollment>('/me/enrollments', { status, search, perPage: 100 })
      .pipe(map((page) => page.items));
  }

  catalogue(search?: string): Observable<CatalogueCourse[]> {
    return this.api
      .getPaged<CatalogueCourse>('/courses/catalogue', { search, perPage: 100 })
      .pipe(map((page) => page.items));
  }

  /** Outline plus this person's progress, merged — progress is absent if not enrolled. */
  courseDetails(courseId: string): Observable<CourseDetails> {
    return forkJoin({
      outline: this.api.get<OutlinePayload>(`/courses/${courseId}/outline`),
      mine: this.api
        .get<MyProgressPayload>(`/me/enrollments/${courseId}`)
        .pipe(catchError(() => of(null))),
    }).pipe(map(({ outline, mine }) => toCourseDetails(courseId, outline, mine)));
  }

  selfEnroll(courseId: string): Observable<void> {
    return this.api.post(`/courses/${courseId}/enroll`, {}).pipe(map(() => undefined));
  }

  /** Marks one lesson complete — the same PUT the web player uses to finish one. */
  markLessonComplete(courseId: string, lessonId: string): Observable<void> {
    return this.api
      .put(`/me/enrollments/${courseId}/lessons/${lessonId}/progress`, {
        completed: true,
        secondsSpent: 0,
      })
      .pipe(map(() => undefined));
  }

  profile(): Observable<Profile> {
    return this.api.get<Profile>('/me/profile');
  }

  /** The standings for a period ('all_time' | 'monthly' | 'weekly' | 'daily'). */
  leaderboard(period: string): Observable<LeaderboardResult> {
    return this.api.get<LeaderboardResult>('/leaderboard', { period });
  }

  /** This learner's certificates, in full. */
  certificates(): Observable<Certificate[]> {
    return this.api
      .getPaged<Certificate>('/me/certificates', { perPage: 100 })
      .pipe(map((page) => page.items));
  }
}

function toCourseDetails(
  courseId: string,
  outline: OutlinePayload,
  mine: MyProgressPayload | null,
): CourseDetails {
  const course = outline.course ?? {};
  const enrollment = mine?.enrollment ?? null;
  const progress = new Map(Object.entries(mine?.lessons ?? {}));

  return {
    courseId: String(course['id'] ?? courseId),
    enrollmentId: String(enrollment?.['id'] ?? ''),
    status: (enrollment?.['status'] ?? 'none') as EnrollmentStatus | 'none',
    isEnrolled: enrollment !== null,
    progressPercentage: Number(enrollment?.['progressPercentage'] ?? 0),
    title: String(course['title'] ?? ''),
    description: String(course['description'] ?? course['summary'] ?? ''),
    thumbnailUrl: course['thumbnailUrl'] ?? null,
    durationMinutes: Number(course['durationMinutes'] ?? 0),
    level: String(course['level'] ?? ''),
    objectives: course['objectives'] ?? null,
    categoryName: course['categoryName'] ?? null,
    modules: (outline.modules ?? []).map((m) => ({
      moduleId: String(m['id'] ?? ''),
      title: String(m['title'] ?? ''),
      description: m['description'] ?? null,
      sortOrder: Number(m['sortOrder'] ?? 0),
      lessons: ((m['lessons'] ?? []) as Array<Record<string, any>>).map((l) => {
        const p = progress.get(String(l['id']));
        return {
          lessonId: String(l['id'] ?? ''),
          title: String(l['title'] ?? ''),
          lessonType: String(l['contentType'] ?? 'text'),
          durationMinutes: Number(l['durationMinutes'] ?? 0),
          sortOrder: Number(l['sortOrder'] ?? 0),
          isCompleted: p?.['status'] === 'completed',
          progressPercentage: Number(p?.['progressPercentage'] ?? 0),
        };
      }),
    })),
  };
}
