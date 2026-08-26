import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { TuiIcon } from '@taiga-ui/core';
import { Subject, forkJoin } from 'rxjs';
import { debounceTime, distinctUntilChanged, takeUntil } from 'rxjs/operators';
import {
  EnrollmentsService,
  type Assignment,
} from '../../../services/enrollments.service';
import { CoursesService, type Course as ApiCourse } from '../../../services/courses.service';
import { UsersService, type User as ApiUser } from '../../../services/users.service';
import { LookupsService, type Lookup } from '../../../services/lookups.service';
import { ApiError } from '../../../core/api';

type EnrollmentType = 'department' | 'team' | 'branch' | 'users';

/* The shapes this screen's template already reads. */

export interface Course {
  course_id: string;
  title: string;
  category_name: string;
  thumbnail_url: string | null;
  duration_minutes: number | null;
  current_enrollments: number;
  is_full: boolean;
}

export interface User {
  user_id: string;
  first_name: string;
  last_name: string;
  email: string;
}

export interface Department { department_id: string; department_name: string; user_count: number | null }
export interface Team { team_id: string; team_name: string; user_count: number | null }
export interface Branch { branch_id: string; branch_name: string; city: string; user_count: number | null }

interface EnrollmentPreview {
  course: { title: string };
  summary: { total_users: number; will_enroll: number; already_enrolled: number };
  users_to_enroll: Array<{ user_id: string; name: string; email: string }>;
  users_already_enrolled: Array<{ user_id: string; name: string; email: string }>;
}

/** A person named in the result lists, in the template's shape. */
interface ResultUser {
  name: string;
  first_name: string;
  last_name: string;
  email: string;
  reason?: string;
}

interface EnrollmentResult {
  message: string;
  summary: { enrolled_count: number; skipped_count: number; failed_count: number };
  enrolled: ResultUser[];
  skipped: ResultUser[];
  failed: ResultUser[];
}

/**
 * Enrolling people on a course, by department, team, branch or name.
 *
 * The screen is as it was, including its preview step. What sits behind it is
 * not: `CourseEnrollmentService` pasted a token and a tenant id into every
 * request body, and the wrapper page at `/enrollment` carried two hundred
 * lines of its own enrolment logic that pushed rows into a local array,
 * alerted "Invitations sent for 12 address(es)" and called nothing at all.
 * None of it was even rendered — the template only showed this component — so
 * it was dead code that told a convincing lie if it ever ran. It is gone.
 *
 * The preview is worked out here from the course's current roster rather than
 * by a second endpoint. Two endpoints answering "who would this enrol?" is how
 * a preview comes to disagree with what the button then does; the assignment
 * itself is the authority, and it reports the same three numbers back.
 */
@Component({
  selector: 'app-course-enrollment',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon],
  templateUrl: './course-enrollment.component.html',
  styleUrls: ['./course-enrollment.component.scss'],
})
export class CourseEnrollmentComponent implements OnInit, OnDestroy {
  private readonly enrollmentsService = inject(EnrollmentsService);
  private readonly coursesService = inject(CoursesService);
  private readonly usersService = inject(UsersService);
  private readonly lookupsService = inject(LookupsService);

  private readonly destroy$ = new Subject<void>();
  private readonly userSearch$ = new Subject<string>();

  courses: Course[] = [];
  selectedCourse: Course | null = null;
  showCourseSelector = true;
  courseSearch = '';

  enrollmentType: EnrollmentType = 'users';

  departments: Department[] = [];
  teams: Team[] = [];
  branches: Branch[] = [];

  selectedDepartmentId: string | null = null;
  selectedTeamId: string | null = null;
  selectedBranchId: string | null = null;

  users: User[] = [];
  selectedUsers: User[] = [];
  userSearch = '';

  isMandatory = true;
  dueDate = '';
  notifyUsers = true;

  loading = true;
  loadingUsers = false;
  loadingPreview = false;
  enrolling = false;
  error = '';

  showPreviewModal = false;
  preview: EnrollmentPreview | null = null;

  showResultModal = false;
  result: EnrollmentResult | null = null;

  /** Who is already on the chosen course, so the preview can be honest. */
  private enrolledIds = new Set<string>();

  ngOnInit(): void {
    this.userSearch$
      .pipe(debounceTime(300), distinctUntilChanged(), takeUntil(this.destroy$))
      .subscribe((term) => this.loadUsers(term));

    forkJoin({
      // Published only: enrolling somebody on a draft puts them on something
      // they cannot open.
      courses: this.coursesService.list({ status: 'published', perPage: 100 }),
      departments: this.lookupsService.active('department'),
      teams: this.lookupsService.active('team'),
      branches: this.lookupsService.active('branch'),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ courses, departments, teams, branches }) => {
          this.courses = courses.items.map((c) => this.adaptCourse(c));
          this.departments = departments.map((d) => ({
            department_id: d.id,
            department_name: d.name,
            user_count: null,
          }));
          this.teams = teams.map((t) => ({ team_id: t.id, team_name: t.name, user_count: null }));
          this.branches = branches.map((b) => this.adaptBranch(b));
          this.loading = false;
        },
        error: (e: ApiError) => {
          this.error = e.message;
          this.loading = false;
        },
      });

    this.loadUsers();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // --- the course ----------------------------------------------------------

  filterCourses(): Course[] {
    const term = this.courseSearch.trim().toLowerCase();

    return term === '' ? this.courses : this.courses.filter((c) => c.title.toLowerCase().includes(term));
  }

  selectCourse(course: Course): void {
    this.selectedCourse = course;
    this.showCourseSelector = false;
    this.error = '';
    this.loadRoster(course.course_id);
  }

  changeCourse(): void {
    this.showCourseSelector = true;
    this.selectedCourse = null;
    this.enrolledIds = new Set();
  }

  /**
   * Who is already on it.
   *
   * Read once when the course is chosen so the preview can say "eight of
   * these forty are already here" without a second endpoint that would have
   * to agree with the assignment.
   */
  private loadRoster(courseId: string): void {
    this.enrollmentsService
      .list(courseId, { perPage: 100 })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => (this.enrolledIds = new Set(page.items.map((e) => e.userId))),
        error: () => (this.enrolledIds = new Set()),
      });
  }

  // --- who ------------------------------------------------------------------

  onEnrollmentTypeChange(): void {
    this.selectedDepartmentId = null;
    this.selectedTeamId = null;
    this.selectedBranchId = null;
    this.selectedUsers = [];
  }

  onUserSearchChange(): void {
    this.userSearch$.next(this.userSearch.trim());
  }

  private loadUsers(search?: string): void {
    this.loadingUsers = true;

    this.usersService
      .options(search)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => {
          this.users = page.items.map((u) => this.adaptUser(u));
          this.loadingUsers = false;
        },
        error: () => {
          this.users = [];
          this.loadingUsers = false;
        },
      });
  }

  toggleUserSelection(user: User): void {
    this.selectedUsers = this.selectedUsers.some((u) => u.user_id === user.user_id)
      ? this.selectedUsers.filter((u) => u.user_id !== user.user_id)
      : [...this.selectedUsers, user];
  }

  removeSelectedUser(user: User): void {
    this.selectedUsers = this.selectedUsers.filter((u) => u.user_id !== user.user_id);
  }

  selectAllUsers(): void {
    this.selectedUsers = [...this.users];
  }

  deselectAllUsers(): void {
    this.selectedUsers = [];
  }

  isUserSelected(user: User): boolean {
    return this.selectedUsers.some((u) => u.user_id === user.user_id);
  }

  // The three group pickers clear the preview, so it cannot describe a
  // department that is no longer the one selected.
  onDepartmentChange(): void {
    this.preview = null;
  }

  onTeamChange(): void {
    this.preview = null;
  }

  onBranchChange(): void {
    this.preview = null;
  }

  canPreview(): boolean {
    if (this.selectedCourse === null) {
      return false;
    }

    switch (this.enrollmentType) {
      case 'users': return this.selectedUsers.length > 0;
      case 'department': return this.selectedDepartmentId !== null;
      case 'team': return this.selectedTeamId !== null;
      case 'branch': return this.selectedBranchId !== null;
    }
  }

  getEnrollmentSummary(): string {
    switch (this.enrollmentType) {
      case 'users':
        return `${this.selectedUsers.length} selected learner(s)`;
      case 'department':
        return this.departments.find((d) => d.department_id === this.selectedDepartmentId)?.department_name
          ?? 'a department';
      case 'team':
        return this.teams.find((t) => t.team_id === this.selectedTeamId)?.team_name ?? 'a team';
      case 'branch':
        return this.branches.find((b) => b.branch_id === this.selectedBranchId)?.branch_name ?? 'a branch';
    }
  }

  // --- previewing -----------------------------------------------------------

  /**
   * What enrolling would do.
   *
   * For a named list this is exact, because the roster is known. For a whole
   * department it says how many people are in it rather than pretending to
   * know which of them are already enrolled — the assignment reports that
   * precisely, and a preview that guessed would be the number people
   * remembered.
   */
  previewEnrollment(): void {
    const course = this.selectedCourse;

    if (course === null || !this.canPreview()) {
      return;
    }

    this.loadingPreview = true;
    this.showPreviewModal = true;
    this.error = '';

    if (this.enrollmentType === 'users') {
      const already = this.selectedUsers.filter((u) => this.enrolledIds.has(u.user_id));
      const fresh = this.selectedUsers.filter((u) => !this.enrolledIds.has(u.user_id));

      this.preview = {
        course: { title: course.title },
        summary: {
          total_users: this.selectedUsers.length,
          will_enroll: fresh.length,
          already_enrolled: already.length,
        },
        users_to_enroll: fresh.map((u) => this.asPreviewUser(u)),
        users_already_enrolled: already.map((u) => this.asPreviewUser(u)),
      };

      this.loadingPreview = false;

      return;
    }

    // A group. Count it, and say plainly that the split is settled on save.
    const filters = this.enrollmentType === 'department' ? { departmentId: this.selectedDepartmentId ?? '' }
      : this.enrollmentType === 'team' ? { teamId: this.selectedTeamId ?? '' }
      : { branchId: this.selectedBranchId ?? '' };

    this.usersService
      .list({ ...filters, perPage: 100 })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => {
          const already = page.items.filter((u) => this.enrolledIds.has(u.id));
          const fresh = page.items.filter((u) => !this.enrolledIds.has(u.id));

          this.preview = {
            course: { title: course.title },
            summary: {
              total_users: page.meta.total,
              will_enroll: fresh.length,
              already_enrolled: already.length,
            },
            users_to_enroll: fresh.map((u) => ({
              user_id: u.id, name: u.fullName, email: u.email,
            })),
            users_already_enrolled: already.map((u) => ({
              user_id: u.id, name: u.fullName, email: u.email,
            })),
          };

          this.loadingPreview = false;
        },
        error: (e: ApiError) => {
          this.error = e.message;
          this.showPreviewModal = false;
          this.loadingPreview = false;
        },
      });
  }

  closePreviewModal(): void {
    this.showPreviewModal = false;
    this.preview = null;
  }

  // --- enrolling ------------------------------------------------------------

  confirmEnrollment(): void {
    const course = this.selectedCourse;

    if (course === null || !this.canPreview()) {
      return;
    }

    // Exactly one target, decided here. The legacy request carried a user
    // list *and* a department, and the server branched on whichever it
    // checked first — so which won depended on the order of the ifs.
    const target = this.enrollmentType === 'users' ? { userIds: this.selectedUsers.map((u) => u.user_id) }
      : this.enrollmentType === 'department' ? { departmentId: this.selectedDepartmentId as string }
      : this.enrollmentType === 'team' ? { teamId: this.selectedTeamId as string }
      : { branchId: this.selectedBranchId as string };

    const assignment: Assignment = {
      ...target,
      isMandatory: this.isMandatory,
      dueDate: this.dueDate === '' ? null : this.dueDate,
    };

    this.enrolling = true;
    this.error = '';

    this.enrollmentsService
      .assign(course.course_id, assignment)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (outcome) => {
          this.enrolling = false;
          this.closePreviewModal();

          // The server's own counts, which distinguish newly enrolled from
          // already there from put back after being dropped.
          this.result = {
            message: outcome.summary,
            summary: {
              enrolled_count: outcome.enrolled + outcome.reinstated,
              skipped_count: outcome.alreadyEnrolled,
              failed_count: outcome.notFound.length,
            },
            // The server answers in counts rather than names — it may have
            // enrolled a whole department — so the lists are filled only for
            // the case where this screen knows who it named.
            enrolled: this.enrollmentType === 'users'
              ? this.selectedUsers
                .filter((u) => !this.enrolledIds.has(u.user_id))
                .map((u) => this.asResultUser(u))
              : [],
            skipped: this.enrollmentType === 'users'
              ? this.selectedUsers
                .filter((u) => this.enrolledIds.has(u.user_id))
                .map((u) => this.asResultUser(u, 'Already on the course.'))
              : [],
            failed: outcome.notFound.map((id) => ({
              name: id,
              first_name: id,
              last_name: '',
              email: '',
              reason: 'Not a person in this organisation.',
            })),
          };

          this.showResultModal = true;
          this.loadRoster(course.course_id);
        },
        error: (e: ApiError) => {
          this.enrolling = false;
          this.closePreviewModal();
          this.error = e.violation('userIds') ?? e.violation('dueDate') ?? e.message;
        },
      });
  }

  closeResultModal(): void {
    this.showResultModal = false;
    this.result = null;
  }

  startNewEnrollment(): void {
    this.closeResultModal();
    this.selectedUsers = [];
    this.selectedDepartmentId = null;
    this.selectedTeamId = null;
    this.selectedBranchId = null;
    this.dueDate = '';
  }

  // --- translation ----------------------------------------------------------

  private adaptCourse(course: ApiCourse): Course {
    return {
      course_id: course.id,
      title: course.title,
      category_name: '',
      thumbnail_url: course.thumbnailUrl,
      duration_minutes: course.durationMinutes,
      current_enrollments: 0,
      // The server enforces the limit and refuses with a message; this is only
      // the card's own hint, and it never guesses when there is no limit.
      is_full: false,
    };
  }

  private adaptUser(user: ApiUser): User {
    return {
      user_id: user.id,
      first_name: user.firstName,
      last_name: user.lastName,
      email: user.email,
    };
  }

  private adaptBranch(lookup: Lookup): Branch {
    return {
      branch_id: lookup.id,
      branch_name: lookup.name,
      city: lookup.description ?? '',
      user_count: null,
    };
  }

  private asResultUser(user: User, reason?: string): ResultUser {
    return {
      name: `${user.first_name} ${user.last_name}`.trim(),
      first_name: user.first_name,
      last_name: user.last_name,
      email: user.email,
      reason,
    };
  }

  private asPreviewUser(user: User): { user_id: string; name: string; email: string } {
    return {
      user_id: user.user_id,
      name: `${user.first_name} ${user.last_name}`.trim(),
      email: user.email,
    };
  }
}
