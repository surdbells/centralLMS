import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient, Endpoints, type Paged } from '../core/api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export type EnrollmentStatus = 'not_started' | 'in_progress' | 'completed' | 'dropped';

/** How somebody came to be on the course. */
export type EnrollmentSource = 'self' | 'direct' | 'bulk' | 'team' | 'branch' | 'department';

export interface Enrollment {
  id: string;
  courseId: string;
  courseTitle: string | null;
  /** Joined with the title, so a course card needs no second request. */
  courseThumbnailUrl: string | null;
  courseLevel: string | null;
  courseCategory: string | null;
  userId: string;
  userName: string | null;
  userEmail: string | null;
  status: EnrollmentStatus;
  statusLabel: string;
  source: EnrollmentSource;
  sourceLabel: string;
  assignedBy: string | null;
  isMandatory: boolean;
  dueDate: string | null;
  /** Worked out from the due date and today, not stored. */
  isOverdue: boolean;
  daysRemaining: number | null;
  progressPercentage: number;
  finalScore: number | null;
  timeSpentSeconds: number;
  lastLessonId: string | null;
  startedAt: string | null;
  completedAt: string | null;
  lastActivityAt: string | null;
  enrolledAt: string | null;
}

export interface EnrollmentStats {
  total: number;
  notStarted: number;
  inProgress: number;
  completed: number;
  dropped: number;
  overdue: number;
  /** Dropped learners are left out: keeping them in makes a course look worse the more people leave it. */
  averageProgress: number;
}

export interface EnrollmentFilters {
  status?: EnrollmentStatus;
  search?: string;
  overdueOnly?: boolean;
  page?: number;
  perPage?: number;
}

/**
 * Who to put on a course.
 *
 * Exactly one target: a list of people, or one department, team or branch.
 * The legacy accepted several at once and branched internally on whichever it
 * checked first, so "which won?" depended on the order of the ifs.
 */
export type AssignmentTarget =
  | { userIds: string[] }
  | { departmentId: string }
  | { teamId: string }
  | { branchId: string };

export type Assignment = AssignmentTarget & {
  isMandatory?: boolean;
  dueDate?: string | null;
};

export interface AssignmentResult {
  courseId: string;
  source: EnrollmentSource;
  enrolled: number;
  /** Dropped learners who came back, progress intact. A different event. */
  reinstated: number;
  alreadyEnrolled: number;
  /** Ids that are not people in this organisation. Reported, not swallowed. */
  notFound: string[];
  /** The whole outcome as a sentence, written by the server. */
  summary: string;
}

export interface UnenrollResult {
  courseId: string;
  removed: number;
  /** Anybody named who was not on the course, or was already off it. */
  notEnrolled: string[];
}

/** One change to somebody's place on a course. */
export interface EnrollmentEvent {
  action: string;
  source: string | null;
  notes: string | null;
  actorName: string | null;
  at: string;
}

/** One person's place on a course, with everything that happened to it. */
export interface EnrollmentHistory {
  courseId: string;
  userId: string;
  /** Null once they are off the course — the history outlives the enrolment. */
  enrollment: Enrollment | null;
  events: EnrollmentEvent[];
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * Who is on a course, and how they are getting on.
 *
 * Taking somebody off does not delete their enrolment — it moves them to
 * `dropped`. Their progress survives, so reinstating them does not start them
 * at zero, and the record of what they completed stays auditable.
 */
@Injectable({ providedIn: 'root' })
export class EnrollmentsService {
  private readonly api = inject(ApiClient);

  list(courseId: string, filters: EnrollmentFilters = {}): Observable<Paged<Enrollment>> {
    return this.api.getPaged<Enrollment>(Endpoints.enrollments.list(courseId), { ...filters });
  }

  stats(courseId: string): Observable<EnrollmentStats> {
    return this.api.get<EnrollmentStats>(Endpoints.enrollments.stats(courseId));
  }

  /**
   * Puts people on the course.
   *
   * The result separates those newly enrolled from those already there and
   * those put back after being dropped — assigning a whole department twice
   * should not read as forty new enrolments.
   */
  assign(courseId: string, assignment: Assignment): Observable<AssignmentResult> {
    return this.api.post<AssignmentResult>(Endpoints.enrollments.assign(courseId), assignment);
  }

  /** Moves them to `dropped`. Their progress is kept. */
  unenroll(courseId: string, userIds: string[], reason?: string): Observable<UnenrollResult> {
    return this.api.deleteWith<UnenrollResult>(
      Endpoints.enrollments.unenroll(courseId),
      { userIds, reason },
    );
  }

  /**
   * Everything that has happened to one person's place on the course.
   *
   * Answered even when they are no longer on it: the enrolment comes back
   * null and the events remain, because "when was she taken off?" is exactly
   * the question somebody opens this to ask.
   */
  history(courseId: string, userId: string): Observable<EnrollmentHistory> {
    return this.api.get<EnrollmentHistory>(Endpoints.enrollments.history(courseId, userId));
  }
}
