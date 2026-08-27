// learning-path-enrollment/learning-path-enrollment.service.ts

import { Injectable, inject } from '@angular/core';
import { Observable, forkJoin, of } from 'rxjs';
import { map, switchMap } from 'rxjs/operators';
import { ApiClient, Endpoints } from '../core/api';
import {
  Department,
  Team,
  Branch,
  User,
  LearningPathBasic,
  EnrollmentStats,
  EnrollmentPreview,
  EnrollmentResult,
  EnrollmentRequest,
} from '../interfaces/learning-path-enrollment.interfaces';

/**
 * Putting people onto a learning path.
 *
 * It used to call one legacy endpoint with `?action=departments|teams|
 * unenrolled-users|preview-enrollment|…` and `token`, `user_id` and `tenant_id`
 * appended to every query string — the tenant supplied by the client, which is
 * how one organisation's departments could be listed by another.
 *
 * Departments, teams and branches are lookups. They had three endpoints of
 * their own here and are one `/lookups/{kind}` call each now, the same as
 * everywhere else in the application.
 *
 * **The preview is computed here, not asked for.** It used to be a round trip
 * that returned who would be enrolled and who already was; that is a diff
 * between the path's roster and the chosen people, and both are already on the
 * client. Doing it here means the numbers move as boxes are ticked instead of
 * after a request, and there is no second implementation of "who counts as
 * already enrolled" to disagree with the one that runs on submit.
 */
@Injectable({
  providedIn: 'root',
})
export class LearningPathEnrollmentService {
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
      .getPaged<UserRow>(Endpoints.users.list, {
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
   * Everybody who is not already on this path.
   *
   * Two requests and a subtraction rather than a bespoke endpoint. The
   * alternative — a server-side "unenrolled" query — is a second definition of
   * who is enrolled, kept in step with the first by hand.
   */
  getUnenrolledUsers(filters: {
    path_id: string;
    search?: string;
    department_id?: string;
    team_id?: string;
    branch_id?: string;
    page?: number;
    per_page?: number;
  }): Observable<User[]> {
    return forkJoin({
      everybody: this.getUsers(filters),
      enrolled: this.enrolledIds(filters.path_id),
    }).pipe(map(({ everybody, enrolled }) =>
      everybody.filter((user) => !enrolled.has(String(user.user_id)))));
  }

  private enrolledIds(pathId: string): Observable<Set<string>> {
    if (pathId === '') {
      return of(new Set<string>());
    }

    return this.api
      .getPaged<Record<string, unknown>>(Endpoints.paths.enrollments(pathId), { perPage: 500 })
      .pipe(map((page) => new Set(page.items.map((row) => String(row['userId'])))));
  }

  // ==========================================
  // PATHS
  // ==========================================

  getLearningPaths(search?: string, status?: string): Observable<LearningPathBasic[]> {
    return this.api
      .getPaged<Record<string, unknown>>(Endpoints.paths.list, {
        search,
        status,
        perPage: 100,
      })
      .pipe(map((page) => page.items.map(toPathBasic)));
  }

  getLearningPathById(pathId: string): Observable<LearningPathBasic> {
    return this.api
      .get<Record<string, unknown>>(Endpoints.paths.show(pathId))
      .pipe(map(toPathBasic));
  }

  getPathStats(pathId: string): Observable<EnrollmentStats> {
    return this.api
      .getPaged<Record<string, unknown>>(Endpoints.paths.enrollments(pathId), { perPage: 500 })
      .pipe(map((page) => toStats(page.items)));
  }

  // ==========================================
  // ENROLLING
  // ==========================================

  /**
   * What enrolling would do, before it does it.
   *
   * The chosen people are diffed against the path's roster here. It was a
   * round trip; the answer is a subtraction of two things the client already
   * holds, and computing it on the server means a second answer to "who is
   * already on this" that can disagree with the one enforced on submit.
   */
  previewEnrollment(request: EnrollmentRequest): Observable<EnrollmentPreview> {
    return forkJoin({
      path: this.getLearningPathById(request.path_id),
      chosen: this.peopleFor(request),
      enrolled: this.enrolledIds(request.path_id),
    }).pipe(map(({ path, chosen, enrolled }) => {
      const already = chosen.filter((user) => enrolled.has(String(user.user_id)));
      const willEnrol = chosen.filter((user) => !enrolled.has(String(user.user_id)));

      return {
        success: true,
        path: { path_id: path.path_id, title: path.title },
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
      enrolled: this.enrolledIds(request.path_id),
    }).pipe(switchMap(({ chosen, enrolled }) => {
      const willEnrol = chosen.filter((user) => !enrolled.has(String(user.user_id)));
      const skipped = chosen.filter((user) => enrolled.has(String(user.user_id)));

      if (willEnrol.length === 0) {
        return of(toResult([], skipped, 0));
      }

      return this.api
        .post<{ enrolled: number; alreadyEnrolled?: number }>(
          Endpoints.paths.enrol(request.path_id),
          {
            userIds: willEnrol.map((user) => user.user_id),
            dueDate: request.due_date ?? null,
            isMandatory: request.is_mandatory ?? false,
          },
        )
        .pipe(map((result) => toResult(willEnrol, skipped, result.enrolled)));
    }));
  }

  /**
   * Who a request names.
   *
   * A department, a team or a branch is a filter over people, so all four
   * kinds of request come down to the same user query — which is why the
   * legacy's four separate enrolment actions were four ways of writing one.
   */
  private peopleFor(request: EnrollmentRequest): Observable<User[]> {
    const ids = request.user_ids;

    if (Array.isArray(ids) && ids.length > 0) {
      return this.getUsers({ per_page: 500 }).pipe(
        map((users) => users.filter((user) => ids.includes(String(user.user_id)))),
      );
    }

    if (request.user_id !== undefined && request.user_id !== '') {
      return this.getUsers({ per_page: 500 }).pipe(
        map((users) => users.filter((user) => String(user.user_id) === request.user_id)),
      );
    }

    return this.getUsers({
      department_id: request.department_id,
      team_id: request.team_id,
      branch_id: request.branch_id,
      per_page: 500,
    });
  }

  unenrollUsers(pathId: string, userIds: string[], _reason?: string): Observable<unknown> {
    if (userIds.length === 0) {
      return of(null);
    }

    // One request per person: withdrawal is addressed per enrolment, which is
    // also what makes it auditable — a bulk delete leaves one row saying a
    // number of people went.
    return forkJoin(userIds.map((userId) =>
      this.api.delete(Endpoints.paths.withdraw(pathId, userId))));
  }

  // ==========================================
  // NOT AVAILABLE
  // ==========================================

  /**
   * Changing a due date or a mandatory flag after the fact.
   *
   * Both are set when somebody is enrolled and there is no endpoint to change
   * them afterwards. Loud rather than silent: a due date that appears to save
   * and does not is worse than one that says it cannot.
   */
  updateDueDate(_pathId: string, _userIds: string[], _dueDate: string | null): Observable<never> {
    return notAvailable('Changing a due date after enrolment');
  }

  setMandatory(_pathId: string, _userIds: string[], _isMandatory: boolean): Observable<never> {
    return notAvailable('Changing whether a path is mandatory after enrolment');
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

interface UserRow extends Record<string, unknown> {
  id: string;
  email: string;
}

function toUser(row: UserRow): User {
  const first = String(row['firstName'] ?? '');
  const last = String(row['lastName'] ?? '');

  return {
    user_id: row.id,
    email: row.email,
    first_name: first,
    last_name: last,
    name: `${first} ${last}`.trim() || row.email,
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
  return {
    user_id: user.user_id,
    email: user.email,
    name: (user as unknown as Record<string, unknown>)['name'],
    first_name: (user as unknown as Record<string, unknown>)['first_name'],
    last_name: (user as unknown as Record<string, unknown>)['last_name'],
    department_name: (user as unknown as Record<string, unknown>)['department_name'],
  };
}

function toPathBasic(path: Record<string, unknown>): LearningPathBasic {
  return {
    path_id: String(path['id']),
    title: String(path['title'] ?? ''),
    slug: String(path['slug'] ?? ''),
    status: String(path['status'] ?? 'draft'),
    total_items: Number(path['itemCount'] ?? 0),
    total_enrollments: Number(path['enrolmentCount'] ?? 0),
  } as unknown as LearningPathBasic;
}

function toStats(rows: Array<Record<string, unknown>>): EnrollmentStats {
  const by = (status: string): number => rows.filter((r) => r['status'] === status).length;

  return {
    total_enrolled: rows.length,
    active: by('in_progress') + by('enrolled'),
    completed: by('completed'),
    withdrawn: by('withdrawn'),
    suspended: by('suspended'),
    expired: by('expired'),
    mandatory: rows.filter((r) => r['isMandatory'] === true).length,
    avg_progress: rows.length === 0
      ? 0
      : Math.round(
        rows.reduce((total, r) => total + Number(r['progressPercentage'] ?? 0), 0) / rows.length,
      ),
    overdue: rows.filter((r) => r['isOverdue'] === true).length,
  };
}

function toResult(enrolled: User[], skipped: User[], count: number): EnrollmentResult {
  return {
    success: true,
    message: count === 0
      ? 'Everybody chosen was already on this path.'
      : `${count} enrolled.`,
    summary: {
      total_users: enrolled.length + skipped.length,
      enrolled_count: count,
      skipped_count: skipped.length,
      failed_count: 0,
    },
    enrolled: enrolled.map((user) => ({
      ...named(user),
      // The API answers with a count, not a row per person, so there is no id
      // to hand back here. Empty rather than invented.
      enrollment_id: '',
    })),
    skipped: skipped.map(named),
    failed: [],
  } as unknown as EnrollmentResult;
}

/** A thing with no endpoint behind it, said out loud. */
function notAvailable(what: string): Observable<never> {
  return new Observable((subscriber) => {
    subscriber.error(new Error(`${what} is not available yet.`));
  });
}
