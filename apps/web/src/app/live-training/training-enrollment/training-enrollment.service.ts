// training-enrollment/training-enrollment.service.ts

import { Injectable, inject } from '@angular/core';
import { Observable, forkJoin, of } from 'rxjs';
import { map, switchMap } from 'rxjs/operators';
import { ApiClient, Endpoints } from '../../core/api';

// Interfaces
export interface Department {
  department_id: number;
  department_name: string;
  department_code: string;
  user_count: number;
}

export interface Team {
  team_id: number;
  team_name: string;
  team_code: string;
  user_count: number;
}

export interface Branch {
  branch_id: number;
  branch_name: string;
  branch_code: string;
  city: string;
  country: string;
  user_count: number;
}

export interface User {
  /** Kept because the picker's template binds to it. */
  id: string;
  user_id: string;
  email: string;
  name: string;
  first_name: string;
  last_name: string;
  department_id?: string | null;
  department_name?: string | null;
  team_id?: string | null;
  team_name?: string | null;
  branch_id?: string | null;
  branch_name?: string | null;
  is_active?: boolean;
}

export interface Training {
  training_id: string;
  title: string;
  description: string;
  short_description: string;
  thumbnail_url: string;
  training_type: string;
  delivery_method: string;
  max_capacity: number | null;
  status: string;
  session_count: number;
  enrollment_count: number;
  category_name: string;
  is_full: boolean;
}

export interface EnrollmentStats {
  total_enrolled: number;
  active: number;
  completed: number;
  cancelled: number;
  waitlisted: number;
  avg_attendance: number;
}

export interface Enrollee {
  enrollment_id: string;
  user_id: string;
  status: string;
  sessions_total: number;
  sessions_attended: number;
  attendance_percentage: number;
  enrolled_at: string;
  completion_date: string | null;
  certificate_issued: boolean;
  email: string;
  first_name: string;
  last_name: string;
  department_name: string | null;
  team_name: string | null;
  branch_name: string | null;
}

export interface EnrollmentPreview {
  success: boolean;
  training: { training_id: string; title: string };
  summary: {
    total_users: number;
    will_enroll: number;
    already_enrolled: number;
    will_waitlist: number;
  };
  users_to_enroll: Array<{ user_id: string; email: string; name: string }>;
  users_already_enrolled: Array<{ user_id: string; email: string; name: string }>;
  users_to_waitlist: Array<{ user_id: string; email: string; name: string }>;
  capacity_info?: {
    max_capacity: number;
    current_enrollments: number;
    available_slots: number;
  };
}

export interface EnrollmentResult {
  success: boolean;
  message: string;
  summary: {
    total_users: number;
    enrolled_count: number;
    waitlisted_count: number;
    skipped_count: number;
    failed_count: number;
  };
  enrolled: Array<{
    user_id: string;
    email: string;
    first_name: string;
    last_name: string;
    enrollment_id: string;
  }>;
  waitlisted: Array<{
    user_id: string;
    email: string;
    first_name: string;
    last_name: string;
    waitlist_position: number;
  }>;
  skipped: Array<{ user_id: string; email: string; name: string; reason: string }>;
  failed: Array<{ user_id: string; email: string; name: string; reason: string }>;
  training: { training_id: string; title: string };
}

export interface EnrollmentRequest {
  training_id: string;
  enrollment_type: 'department' | 'team' | 'branch' | 'user' | 'users';
  department_id?: number;
  team_id?: number;
  branch_id?: number;
  user_id?: string;
  token?: string;
  tenant_id?: string;
  user_ids?: string[];
  is_mandatory?: boolean;
  notify_users?: boolean;
}

interface ApiResponse<T> {
  response_code: number;
  status: 'success' | 'error';
  data: T;
  message: string;
}

export interface AuthParams {
  token: string;
  user_id: string;
  tenant_id: string;
}

/**
 * Putting people onto an instructor-led programme.
 *
 * The same shape as the learning-path enrolment service, against trainings
 * instead of paths, and for the same reasons: the lookups are lookups, the
 * roster is the roster, and the preview is a subtraction rather than a round
 * trip.
 *
 * It called one legacy endpoint with `?action=departments|users|
 * unenrolled-users|preview-enrollment|…` and `token`, `user_id` and
 * `tenant_id` on every request — the tenant named by the client.
 */
@Injectable({
  providedIn: 'root',
})
export class TrainingEnrollmentService {
  private readonly api = inject(ApiClient);

  // ==========================================
  // LOOKUPS
  // ==========================================

  getDepartments(): Observable<Department[]> {
    return this.api
      .get<LookupRow[]>(Endpoints.lookups.active('department'))
      .pipe(map((rows) => rows.map((row) => ({
        department_id: row.id,
        department_name: row.name,
        department_code: row.code ?? '',
      } as unknown as Department))));
  }

  getTeams(): Observable<Team[]> {
    return this.api
      .get<LookupRow[]>(Endpoints.lookups.active('team'))
      .pipe(map((rows) => rows.map((row) => ({
        team_id: row.id,
        team_name: row.name,
        team_code: row.code ?? '',
      } as unknown as Team))));
  }

  getBranches(): Observable<Branch[]> {
    return this.api
      .get<LookupRow[]>(Endpoints.lookups.active('branch'))
      .pipe(map((rows) => rows.map((row) => ({
        branch_id: row.id,
        branch_name: row.name,
        branch_code: row.code ?? '',
      } as unknown as Branch))));
  }

  // ==========================================
  // PEOPLE
  // ==========================================

  getUsers(filters?: {
    search?: string;
    department_id?: string;
    team_id?: string;
    branch_id?: string;
    page?: number;
    per_page?: number;
  }): Observable<User[]> {
    return this.api
      .getPaged<Record<string, any>>(Endpoints.users.list, {
        search: filters?.search,
        departmentId: filters?.department_id,
        teamId: filters?.team_id,
        branchId: filters?.branch_id,
        page: filters?.page,
        perPage: filters?.per_page ?? 100,
      })
      .pipe(map((page) => page.items.map(toUser)));
  }

  /**
   * Everybody not already on this programme.
   *
   * Two requests and a subtraction. A server-side "unenrolled" query would be
   * a second definition of who is enrolled, kept in step with the first by
   * hand.
   */
  getUnenrolledUsers(trainingId: string, search?: string): Observable<User[]> {
    return forkJoin({
      everybody: this.getUsers({ search, per_page: 500 }),
      enrolled: this.enrolledIds(trainingId),
    }).pipe(map(({ everybody, enrolled }) =>
      everybody.filter((user) => !enrolled.has(String(user.user_id)))));
  }

  private enrolledIds(trainingId: string): Observable<Set<string>> {
    if (trainingId === '') {
      return of(new Set<string>());
    }

    return this.api
      .getPaged<Record<string, unknown>>(Endpoints.trainings.roster(trainingId), { perPage: 500 })
      .pipe(map((page) => new Set(page.items.map((row) => String(row['userId'])))));
  }

  // ==========================================
  // PROGRAMMES
  // ==========================================

  getTrainings(search?: string, status?: string): Observable<Training[]> {
    return this.api
      .getPaged<Record<string, any>>(Endpoints.trainings.list, {
        search,
        status,
        perPage: 100,
      })
      .pipe(map((page) => page.items.map(toTrainingBasic)));
  }

  getTrainingStats(trainingId: string): Observable<EnrollmentStats> {
    return this.api
      .getPaged<Record<string, any>>(Endpoints.trainings.roster(trainingId), { perPage: 500 })
      .pipe(map((page) => toStats(page.items)));
  }

  getTrainingEnrollees(
    trainingId: string,
    filters?: { search?: string; status?: string; page?: number; per_page?: number },
  ): Observable<Enrollee[]> {
    return this.api
      .getPaged<Record<string, any>>(Endpoints.trainings.roster(trainingId), {
        search: filters?.search,
        status: filters?.status,
        page: filters?.page,
        perPage: filters?.per_page ?? 100,
      })
      .pipe(map((page) => page.items.map(toEnrollee)));
  }

  // ==========================================
  // ENROLLING
  // ==========================================

  previewEnrollment(request: EnrollmentRequest): Observable<EnrollmentPreview> {
    return forkJoin({
      chosen: this.peopleFor(request),
      enrolled: this.enrolledIds(request.training_id),
    }).pipe(map(({ chosen, enrolled }) => {
      const already = chosen.filter((user) => enrolled.has(String(user.user_id)));
      const willEnrol = chosen.filter((user) => !enrolled.has(String(user.user_id)));

      return {
        success: true,
        summary: {
          total_users: chosen.length,
          will_enroll: willEnrol.length,
          already_enrolled: already.length,
        },
        users_to_enroll: willEnrol.map(named),
        users_already_enrolled: already.map(named),
      } as unknown as EnrollmentPreview;
    }));
  }

  enrollUsers(request: EnrollmentRequest): Observable<EnrollmentResult> {
    return forkJoin({
      chosen: this.peopleFor(request),
      enrolled: this.enrolledIds(request.training_id),
    }).pipe(switchMap(({ chosen, enrolled }) => {
      const willEnrol = chosen.filter((user) => !enrolled.has(String(user.user_id)));
      const skipped = chosen.filter((user) => enrolled.has(String(user.user_id)));

      if (willEnrol.length === 0) {
        return of(toResult([], skipped, 0, 0));
      }

      return this.api
        .post<{ enrolled: number; waitlisted?: number }>(
          Endpoints.trainings.assign(request.training_id),
          { userIds: willEnrol.map((user) => user.user_id) },
        )
        .pipe(map((result) =>
          toResult(willEnrol, skipped, result.enrolled, result.waitlisted ?? 0)));
    }));
  }

  /**
   * Who a request names.
   *
   * A department, a team or a branch is a filter over people, so all four
   * kinds of request come down to one user query — which is why the legacy's
   * four separate enrolment actions were four ways of writing one.
   */
  private peopleFor(request: EnrollmentRequest): Observable<User[]> {
    const r = request as unknown as Record<string, any>;
    const ids = r['user_ids'] as string[] | undefined;

    if (Array.isArray(ids) && ids.length > 0) {
      return this.getUsers({ per_page: 500 }).pipe(
        map((users) => users.filter((user) => ids.includes(String(user.user_id)))),
      );
    }

    return this.getUsers({
      department_id: r['department_id'],
      team_id: r['team_id'],
      branch_id: r['branch_id'],
      per_page: 500,
    });
  }

  unenrollUsers(trainingId: string, userIds: string[], _reason?: string): Observable<unknown> {
    if (userIds.length === 0) {
      return of(null);
    }

    // The people to remove go in the body: forty ids do not belong in a query
    // string, and the API takes them all at once.
    return this.api.deleteWith(Endpoints.trainings.withdraw(trainingId), { userIds });
  }

  cancelEnrollment(
    enrollmentId: string,
    reason?: string,
    trainingId = '',
  ): Observable<unknown> {
    return this.unenrollUsers(trainingId, [enrollmentId], reason);
  }
}

// ---------------------------------------------------------------------------
// Translation
// ---------------------------------------------------------------------------

interface LookupRow {
  id: string;
  name: string;
  code?: string | null;
}

function toUser(row: Record<string, any>): User {
  const first = String(row['firstName'] ?? '');
  const last = String(row['lastName'] ?? '');

  return {
    id: String(row['id']),
    user_id: String(row['id']),
    email: String(row['email'] ?? ''),
    first_name: first,
    last_name: last,
    name: `${first} ${last}`.trim() || String(row['email'] ?? ''),
    department_id: row['departmentId'] ?? null,
    department_name: row['departmentName'] ?? null,
    team_id: row['teamId'] ?? null,
    team_name: row['teamName'] ?? null,
    branch_id: row['branchId'] ?? null,
    branch_name: row['branchName'] ?? null,
    is_active: row['status'] === 'active',
  } as unknown as User;
}

function named(user: User): Record<string, unknown> {
  const u = user as unknown as Record<string, unknown>;

  return {
    user_id: u['user_id'],
    email: u['email'],
    name: u['name'],
    first_name: u['first_name'],
    last_name: u['last_name'],
    department_name: u['department_name'],
  };
}

function toTrainingBasic(t: Record<string, any>): Training {
  return {
    training_id: String(t['id']),
    title: String(t['title'] ?? ''),
    training_type: String(t['delivery'] ?? 'physical'),
    status: String(t['status'] ?? 'draft'),
    session_count: Number(t['sessionCount'] ?? 0),
    enrollment_count: Number(t['enrolledCount'] ?? 0),
    max_capacity: t['maxCapacity'] ?? null,
  } as unknown as Training;
}

function toEnrollee(row: Record<string, any>): Enrollee {
  return {
    enrollment_id: String(row['id'] ?? ''),
    user_id: String(row['userId'] ?? ''),
    name: row['userName'] ?? '',
    email: row['userEmail'] ?? '',
    status: row['status'] ?? 'enrolled',
    enrollment_type: row['source'] ?? 'self',
    enrolled_at: row['enrolledAt'] ?? null,
    attendance_percentage: Number(row['attendancePercentage'] ?? 0),
    sessions_attended: Number(row['sessionsAttended'] ?? 0),
    waitlisted: row['waitlisted'] === true,
  } as unknown as Enrollee;
}

function toStats(rows: Array<Record<string, any>>): EnrollmentStats {
  const by = (status: string): number => rows.filter((r) => r['status'] === status).length;

  return {
    total_enrolled: rows.length,
    active: by('enrolled') + by('in_progress'),
    completed: by('completed'),
    cancelled: by('cancelled'),
    waitlisted: rows.filter((r) => r['waitlisted'] === true).length,
    avg_attendance: rows.length === 0
      ? 0
      : Math.round(
        rows.reduce((total, r) => total + Number(r['attendancePercentage'] ?? 0), 0) / rows.length,
      ),
  } as unknown as EnrollmentStats;
}

function toResult(
  enrolled: User[],
  skipped: User[],
  count: number,
  waitlisted: number,
): EnrollmentResult {
  return {
    success: true,
    message: count === 0
      ? 'Everybody chosen was already on this programme.'
      : `${count} enrolled.`,
    summary: {
      total_users: enrolled.length + skipped.length,
      enrolled_count: count,
      waitlisted_count: waitlisted,
      skipped_count: skipped.length,
      failed_count: 0,
    },
    enrolled: enrolled.map((user) => ({
      ...named(user),
      // The API answers with a count rather than a row per person, so there
      // is no id to hand back. Empty rather than invented.
      enrollment_id: '',
    })),
    skipped: skipped.map(named),
    failed: [],
  } as unknown as EnrollmentResult;
}
