import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { TuiIcon } from '@taiga-ui/core';
import { Subject, forkJoin, of } from 'rxjs';
import { catchError, debounceTime, distinctUntilChanged, switchMap, takeUntil } from 'rxjs/operators';
import { AdmintopComponent } from '../../../partials/top/admintop/admintop.component';
import { CoursesService, type Course } from '../../../services/courses.service';
import { UsersService, type User } from '../../../services/users.service';
import { LookupsService } from '../../../services/lookups.service';
import { EnrollmentsService } from '../../../services/enrollments.service';
import { ApiClient, ApiError } from '../../../core/api';

/* The shapes this screen's template already reads. */

export interface CourseOption {
  course_id: string;
  title: string;
  category_name: string;
  duration_minutes: number | null;
  thumbnail_url: string | null;
  current_enrollments: number;
  is_full: boolean;
}

export interface UserOption {
  user_id: string;
  first_name: string;
  last_name: string;
  name: string;
  email: string;
  /** Only on a failure row. */
  reason?: string;
}

export interface BranchOption {
  branch_id: string;
  branch_name: string;
  city: string;
  user_count: number;
}

export interface DepartmentOption {
  department_id: string;
  department_name: string;
  user_count: number;
}

export interface TeamOption {
  team_id: string;
  team_name: string;
  user_count: number;
}

export interface EnrollmentPreview {
  course: { title: string };
  summary: { will_enroll: number; already_enrolled: number; total_users: number };
  users_to_enroll: UserOption[];
  users_already_enrolled: UserOption[];
}

export interface EnrollmentResult {
  message: string;
  summary: { enrolled_count: number; skipped_count: number; failed_count: number };
  enrolled: UserOption[];
  skipped: UserOption[];
  failed: UserOption[];
}

/**
 * Putting a group of people on a course.
 *
 * **It had no header, and no course list.** The screen posted to
 * `course/enrollment` on the legacy host, so the course selector on step one
 * showed "No courses found" whatever the organisation had — and the page began
 * at its own first heading, with no navigation above it, because the top bar
 * was never in the template.
 *
 * The preview is worked out here rather than asked for. There is no
 * preview endpoint, and there does not need to be: who is already on a course
 * is a fact the enrolment list already answers, and diffing it against the
 * selection is the whole of it. Assigning is idempotent anyway, so the preview
 * is a courtesy rather than a guard.
 */
@Component({
  selector: 'app-course-enrollment',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent],
  templateUrl: './course-enrollment.component.html',
  styleUrls: ['./course-enrollment.component.scss'],
})
export class CourseEnrollmentComponent implements OnInit, OnDestroy {
  private readonly coursesService = inject(CoursesService);
  private readonly usersService = inject(UsersService);
  private readonly lookupsService = inject(LookupsService);
  private readonly enrollmentsService = inject(EnrollmentsService);
  private readonly api = inject(ApiClient);
  private readonly router = inject(Router);
  private readonly destroy$ = new Subject<void>();
  private readonly userSearch$ = new Subject<string>();

  loading = true;
  loadingUsers = false;
  loadingPreview = false;
  enrolling = false;
  error = '';

  showCourseSelector = true;
  showPreviewModal = false;
  showResultModal = false;

  courses: CourseOption[] = [];
  courseSearch = '';
  selectedCourse: CourseOption | null = null;

  users: UserOption[] = [];
  userSearch = '';
  selectedUsers: UserOption[] = [];

  /** Category names, so a course card can show one without a second call. */
  private categories: Record<string, string> = {};

  branches: BranchOption[] = [];
  departments: DepartmentOption[] = [];
  teams: TeamOption[] = [];

  selectedBranchId = '';
  selectedDepartmentId = '';
  selectedTeamId = '';

  /** The template offers `users`, `department`, `team` and `branch`. */
  enrollmentType = 'users';
  isMandatory = false;
  dueDate = '';
  notifyUsers = true;

  preview: EnrollmentPreview | null = null;
  result: EnrollmentResult | null = null;

  ngOnInit(): void {
    this.load();

    this.userSearch$
      .pipe(
        debounceTime(300),
        distinctUntilChanged(),
        switchMap(() => this.fetchUsers()),
        takeUntil(this.destroy$),
      )
      .subscribe({
        next: (page) => {
          this.users = page.items.map((u) => this.toOption(u));
          this.loadingUsers = false;
        },
        error: () => (this.loadingUsers = false),
      });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  private load(): void {
    this.loading = true;

    forkJoin({
      // Only what somebody can actually be put on. An unpublished course in
      // the picker is an enrolment that will be refused.
      courses: this.coursesService.list({ status: 'published', perPage: 200 }),
      branches: this.lookupsService.active('branch').pipe(catchError(() => of([]))),
      departments: this.lookupsService.active('department').pipe(catchError(() => of([]))),
      teams: this.lookupsService.active('team').pipe(catchError(() => of([]))),
      categories: this.lookupsService.active('category').pipe(catchError(() => of([]))),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ courses, branches, departments, teams, categories }) => {
          for (const category of categories) {
            this.categories[category.id] = category.name;
          }

          this.courses = courses.items.map((c) => ({
            course_id: c.id,
            title: c.title,
            category_name: this.categories[c.categoryId ?? ''] ?? 'Uncategorised',
            duration_minutes: c.durationMinutes,
            thumbnail_url: c.thumbnailUrl === null ? null : this.api.urlFor(c.thumbnailUrl),
            current_enrollments: 0,
            // The API refuses an enrolment past a course's limit, and says
            // so. Counting here would be one request per course to grey out
            // a card, for an answer that could be stale by the time it is
            // clicked.
            is_full: false,
          }));

          this.branches = branches.map((b) => ({
            branch_id: b.id,
            branch_name: b.name,
            city: '',
            user_count: 0,
          }));

          this.departments = departments.map((d) => ({
            department_id: d.id,
            department_name: d.name,
            user_count: 0,
          }));

          this.teams = teams.map((t) => ({
            team_id: t.id,
            team_name: t.name,
            user_count: 0,
          }));

          this.loading = false;
        },
        error: (failure: ApiError) => {
          this.error = failure.message;
          this.loading = false;
        },
      });
  }

  // --- choosing a course ---------------------------------------------------

  filterCourses(): CourseOption[] {
    const query = this.courseSearch.trim().toLowerCase();

    if (query === '') {
      return this.courses;
    }

    return this.courses.filter(
      (c) => c.title.toLowerCase().includes(query)
        || c.category_name.toLowerCase().includes(query),
    );
  }

  selectCourse(course: CourseOption): void {
    if (course.is_full) {
      return;
    }

    this.selectedCourse = course;
    this.showCourseSelector = false;
    this.loadUsers();
  }

  changeCourse(): void {
    this.showCourseSelector = true;
    this.selectedCourse = null;
    this.selectedUsers = [];
  }

  // --- choosing people -----------------------------------------------------

  onEnrollmentTypeChange(): void {
    this.selectedBranchId = '';
    this.selectedDepartmentId = '';
    this.selectedTeamId = '';
    this.selectedUsers = [];
    this.loadUsers();
  }

  onBranchChange(): void {
    this.loadUsers();
  }

  onDepartmentChange(): void {
    this.loadUsers();
  }

  onTeamChange(): void {
    this.loadUsers();
  }

  onUserSearchChange(): void {
    this.loadingUsers = true;
    this.userSearch$.next(this.userSearch);
  }

  private loadUsers(): void {
    this.loadingUsers = true;

    this.fetchUsers()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => {
          this.users = page.items.map((u) => this.toOption(u));
          this.loadingUsers = false;
        },
        error: () => (this.loadingUsers = false),
      });
  }

  private fetchUsers() {
    return this.usersService.list({
      search: this.userSearch.trim() === '' ? undefined : this.userSearch.trim(),
      // Only people who can actually be signed in and do the work. An invited
      // or suspended account on a course is an enrolment nobody will finish.
      status: 'active',
      branchId: this.selectedBranchId === '' ? undefined : this.selectedBranchId,
      departmentId: this.selectedDepartmentId === '' ? undefined : this.selectedDepartmentId,
      teamId: this.selectedTeamId === '' ? undefined : this.selectedTeamId,
      perPage: 100,
    });
  }

  private toOption(u: User): UserOption {
    return {
      user_id: u.id,
      first_name: u.firstName,
      last_name: u.lastName,
      name: u.fullName,
      email: u.email,
    };
  }

  isUserSelected(user: UserOption): boolean {
    return this.selectedUsers.some((u) => u.user_id === user.user_id);
  }

  toggleUserSelection(user: UserOption): void {
    this.selectedUsers = this.isUserSelected(user)
      ? this.selectedUsers.filter((u) => u.user_id !== user.user_id)
      : [...this.selectedUsers, user];
  }

  selectAllUsers(): void {
    const chosen = new Set(this.selectedUsers.map((u) => u.user_id));

    this.selectedUsers = [
      ...this.selectedUsers,
      ...this.users.filter((u) => !chosen.has(u.user_id)),
    ];
  }

  deselectAllUsers(): void {
    this.selectedUsers = [];
  }

  removeSelectedUser(user: UserOption): void {
    this.selectedUsers = this.selectedUsers.filter((u) => u.user_id !== user.user_id);
  }

  // --- previewing and enrolling -------------------------------------------

  canPreview(): boolean {
    return this.selectedCourse !== null && this.selectedUsers.length > 0;
  }

  getEnrollmentSummary(): string {
    const people = this.selectedUsers.length;
    const noun = people === 1 ? 'person' : 'people';

    return `${people} ${noun} on ${this.selectedCourse?.title ?? 'this course'}`;
  }

  /**
   * Who this would actually change.
   *
   * Worked out from the course's existing enrolments rather than asked of a
   * preview endpoint: who is on a course is a fact the enrolment list already
   * answers, and assigning is idempotent, so this is a courtesy rather than a
   * guard.
   */
  previewEnrollment(): void {
    const course = this.selectedCourse;

    if (course === null) {
      return;
    }

    this.loadingPreview = true;
    this.showPreviewModal = true;

    this.enrollmentsService
      .list(course.course_id, { perPage: 200 })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => {
          const already = new Set(page.items.map((e) => e.userId));

          const toEnrol = this.selectedUsers.filter((u) => !already.has(u.user_id));
          const alreadyOn = this.selectedUsers.filter((u) => already.has(u.user_id));

          this.preview = {
            course: { title: course.title },
            summary: {
              will_enroll: toEnrol.length,
              already_enrolled: alreadyOn.length,
              total_users: this.selectedUsers.length,
            },
            users_to_enroll: toEnrol,
            users_already_enrolled: alreadyOn,
          };

          this.loadingPreview = false;
        },
        error: (failure: ApiError) => {
          this.loadingPreview = false;
          this.showPreviewModal = false;
          this.error = failure.message;
        },
      });
  }

  closePreviewModal(): void {
    this.showPreviewModal = false;
  }

  confirmEnrollment(): void {
    const course = this.selectedCourse;

    if (course === null) {
      return;
    }

    this.enrolling = true;

    this.enrollmentsService
      .assign(course.course_id, {
        userIds: this.selectedUsers.map((u) => u.user_id),
        isMandatory: this.isMandatory,
        dueDate: this.dueDate === '' ? undefined : this.dueDate,
      })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (outcome) => {
          // The API answers with counts and the ids it could not place. The
          // names are here already, so the result reads as people rather than
          // as numbers.
          const missing = new Set(outcome.notFound);
          const failed = this.selectedUsers
            .filter((u) => missing.has(u.user_id))
            .map((u) => ({ ...u, reason: 'No longer in this organisation' }));

          const placed = this.selectedUsers.filter((u) => !missing.has(u.user_id));
          const enrolledCount = outcome.enrolled + outcome.reinstated;

          this.result = {
            message: outcome.summary,
            summary: {
              enrolled_count: enrolledCount,
              skipped_count: outcome.alreadyEnrolled,
              failed_count: failed.length,
            },
            enrolled: placed.slice(0, enrolledCount),
            skipped: placed.slice(enrolledCount),
            failed,
          };

          this.enrolling = false;
          this.showPreviewModal = false;
          this.showResultModal = true;
        },
        error: (failure: ApiError) => {
          this.enrolling = false;
          this.error = failure.violation('userIds') ?? failure.message;
        },
      });
  }

  closeResultModal(): void {
    this.showResultModal = false;
  }

  startNewEnrollment(): void {
    this.showResultModal = false;
    this.selectedUsers = [];
    this.preview = null;
    this.result = null;
    this.changeCourse();
  }

  goBack(): void {
    void this.router.navigate(['/administration']);
  }
}
