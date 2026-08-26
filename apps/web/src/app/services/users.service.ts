import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient, Endpoints, type Paged } from '../core/api';
import type { LearnerSkills } from './skills.service';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/** `locked` is the system's doing, after repeated failed sign-ins; `suspended` is an administrator's. */
export type UserStatus = 'invited' | 'active' | 'suspended' | 'locked';

/** Where somebody sits in the organisation, with the names resolved. */
export interface Placement {
  branchName: string | null;
  departmentName: string | null;
  teamName: string | null;
  levelName: string | null;
  jobTitleName: string | null;
  managerName: string | null;
}

/** What somebody has been doing. Derived from their enrolments, never stored. */
export interface UserActivity {
  enrolments: number;
  inProgress: number;
  completed: number;
  overdue: number;
  secondsSpent: number;
  lastActivityAt: string | null;
}

export interface User {
  id: string;
  email: string;
  firstName: string;
  lastName: string;
  fullName: string;
  status: UserStatus;
  phone: string | null;
  avatarUrl: string | null;
  locale: string;
  managerId: string | null;
  branchId: string | null;
  departmentId: string | null;
  teamId: string | null;
  levelId: string | null;
  jobTitleId: string | null;
  hireDate: string | null;
  probationEndDate: string | null;
  roleIds: string[];
  twoFactor: boolean;
  lastLoginAt: string | null;
  loginCount: number;
  createdAt: string | null;
  /**
   * Present only when the list was asked to resolve them.
   *
   * Absent is not the same as empty: a list that did not join the lookups
   * should not claim everybody is unassigned.
   */
  placement?: Placement | null;
  activity?: UserActivity | null;
}

/** Just enough to fill a picker. */
export interface UserOption {
  id: string;
  fullName: string;
  email: string;
}

export interface UserFilters {
  search?: string;
  status?: UserStatus;
  branchId?: string;
  departmentId?: string;
  teamId?: string;
  levelId?: string;
  jobTitleId?: string;
  managerId?: string;
  roleId?: string;
  /** Both cost a join, so they are off unless a table needs them. */
  withPlacement?: boolean;
  withActivity?: boolean;
  page?: number;
  perPage?: number;
}

export interface UserInput {
  email: string;
  firstName: string;
  lastName: string;
  password?: string;
  status?: UserStatus;
  phone?: string | null;
  locale?: string;
  managerId?: string | null;
  branchId?: string | null;
  departmentId?: string | null;
  teamId?: string | null;
  levelId?: string | null;
  jobTitleId?: string | null;
  hireDate?: string | null;
  probationEndDate?: string | null;
  roleIds?: string[];
}

export interface UserStatistics {
  total: number;
  active: number;
  invited: number;
  suspended: number;
  locked: number;
  neverSignedIn: number;
  activeLast30: number;
  joinedLast30: number;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * The people in an organisation.
 *
 * Every call is scoped to the caller's own tenant by the token.
 * `User::getById(int $id)` ran `SELECT * FROM users WHERE id = ?` with no
 * tenant clause and `single.php` handed it whatever the request body said, so
 * any id returned any organisation's record.
 */
@Injectable({ providedIn: 'root' })
export class UsersService {
  private readonly api = inject(ApiClient);

  list(filters: UserFilters = {}): Observable<Paged<User>> {
    return this.api.getPaged<User>(Endpoints.users.list, { ...filters });
  }

  /** A picker's worth: id, name, email. */
  options(search?: string, perPage = 100): Observable<Paged<User>> {
    return this.api.getPaged<User>(Endpoints.users.list, { search, perPage });
  }

  /** For a course's instructor picker; needs only `course.manage`. */
  instructors(search?: string): Observable<Paged<UserOption>> {
    return this.api.getPaged<UserOption>(Endpoints.users.instructors, { search });
  }

  show(id: string): Observable<User> {
    return this.api.get<User>(Endpoints.users.show(id));
  }

  create(input: UserInput): Observable<User> {
    return this.api.post<User>(Endpoints.users.create, input);
  }

  update(id: string, input: Partial<UserInput>): Observable<User> {
    return this.api.put<User>(Endpoints.users.update(id), input);
  }

  setStatus(id: string, status: UserStatus): Observable<User> {
    return this.api.patch<User>(Endpoints.users.status(id), { status });
  }

  /** Soft delete: certificates and enrolments point at this person. */
  delete(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.users.delete(id));
  }

  /**
   * Sets a password.
   *
   * Somebody changing their own must send `currentPassword`; an administrator
   * resetting another person's must not. The API tells the two apart from who
   * is asking.
   */
  setPassword(id: string, password: string, currentPassword?: string): Observable<unknown> {
    return this.api.post(Endpoints.users.password(id), { password, currentPassword });
  }

  statistics(): Observable<UserStatistics> {
    return this.api.get<UserStatistics>(Endpoints.users.statistics);
  }

  /** What this person can do, derived from the courses they have finished. */
  skills(id: string): Observable<LearnerSkills> {
    return this.api.get<LearnerSkills>(Endpoints.users.skills(id));
  }
}
