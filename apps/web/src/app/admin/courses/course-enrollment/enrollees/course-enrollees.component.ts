import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { TuiIcon } from '@taiga-ui/core';
import { ActivatedRoute } from '@angular/router';
import { Subject, forkJoin, of } from 'rxjs';
import { catchError, debounceTime, distinctUntilChanged, takeUntil } from 'rxjs/operators';
import {
  EnrollmentsService,
  type Enrollment,
  type EnrollmentStatus,
} from '../../../../services/enrollments.service';
import { CoursesService, type Course as ApiCourse } from '../../../../services/courses.service';
import { UsersService } from '../../../../services/users.service';
import { ApiError } from '../../../../core/api';

/** A course, in the shape this screen's template already reads. */
export interface Course {
  course_id: string;
  title: string;
  category_name: string;
  thumbnail_url: string | null;
  current_enrollments: number;
}

/** One person on the course, in the template's shape. */
export interface Enrollee {
  user_id: string;
  first_name: string;
  last_name: string;
  email: string;
  department_name: string;
  status: EnrollmentStatus;
  progress_percentage: number;
  is_mandatory: boolean;
  due_date: string | null;
  enrolled_at: string | null;
}

export interface UnenrolledUser {
  user_id: string;
  first_name: string;
  last_name: string;
  email: string;
}

/**
 * Who is on a course.
 *
 * The screen is as it was. What changed is underneath: `CourseEnrollmentService`
 * pasted a token and a tenant id into every request body and read a
 * `response_code`; this goes through {@link EnrollmentsService}.
 *
 * Three things the screen now tells the truth about:
 *
 *  - "Remove from course" never deleted anything, then or now — it moves
 *    somebody to `dropped` and their progress survives. The confirmation says
 *    so, so reinstating them is not a surprise.
 *  - The average progress leaves out people who were taken off. Counting them
 *    made a course look worse the more of them there were.
 *  - A refusal is shown in the server's own words instead of a generic
 *    "Failed to update enrollment".
 */
@Component({
  selector: 'app-course-enrollees',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon],
  templateUrl: './course-enrollees.component.html',
  styleUrls: ['./course-enrollees.component.scss'],
})
export class CourseEnrolleesComponent implements OnInit, OnDestroy {
  private readonly enrollmentsService = inject(EnrollmentsService);
  private readonly coursesService = inject(CoursesService);
  private readonly usersService = inject(UsersService);
  private readonly route = inject(ActivatedRoute);

  private readonly destroy$ = new Subject<void>();
  private readonly userSearch$ = new Subject<string>();

  /** `Math` is reached for directly in the template's pagination line. */
  protected readonly Math = Math;

  courses: Course[] = [];
  selectedCourse: Course | null = null;
  showCourseSelector = true;
  courseSearch = '';

  enrollees: Enrollee[] = [];
  selectedEnrollees: string[] = [];
  selectAll = false;

  stats: {
    total_enrolled: number;
    not_started: number;
    in_progress: number;
    completed: number;
    mandatory: number;
    avg_progress: number;
  } | null = null;

  statusFilter: EnrollmentStatus | '' = '';

  loading = true;
  loadingEnrollees = false;
  loadingUnenrolled = false;
  processing = false;

  successMessage = '';
  errorMessage = '';

  // Paging, under the names the template uses.
  currentPage = 1;
  pageSize = 25;
  totalEnrollees = 0;
  totalPages = 0;

  // Adding people
  showAddUsersModal = false;
  unenrolledUsers: UnenrolledUser[] = [];
  selectedUsersToAdd: UnenrolledUser[] = [];
  userSearch = '';

  // Due dates
  showDueDateModal = false;
  newDueDate = '';

  // Confirmations
  showConfirmModal = false;
  confirmAction: 'unenroll' | 'mandatory' | 'optional' | '' = '';

  ngOnInit(): void {
    this.userSearch$
      .pipe(debounceTime(300), distinctUntilChanged(), takeUntil(this.destroy$))
      .subscribe((term) => this.loadUnenrolled(term));

    const courseId = this.route.snapshot.paramMap.get('id');

    this.coursesService
      .list({ perPage: 100 })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => {
          this.courses = page.items.map((c) => this.adaptCourse(c));
          this.loading = false;

          // Addressed by the course when there is one in the URL; the picker
          // is for the case where there is not.
          const named = courseId === null
            ? undefined
            : this.courses.find((c) => c.course_id === courseId);

          if (named !== undefined) {
            this.selectCourse(named);
          }
        },
        error: (e: ApiError) => {
          this.errorMessage = e.message;
          this.loading = false;
        },
      });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // --- choosing a course ---------------------------------------------------

  filterCourses(): Course[] {
    const term = this.courseSearch.trim().toLowerCase();

    return term === '' ? this.courses : this.courses.filter((c) => c.title.toLowerCase().includes(term));
  }

  selectCourse(course: Course): void {
    this.selectedCourse = course;
    this.showCourseSelector = false;
    this.currentPage = 1;
    this.selectedEnrollees = [];
    this.loadEnrollees();
  }

  changeCourse(): void {
    this.showCourseSelector = true;
    this.selectedCourse = null;
    this.enrollees = [];
    this.stats = null;
  }

  // --- loading -------------------------------------------------------------

  loadEnrollees(): void {
    const course = this.selectedCourse;

    if (course === null) {
      return;
    }

    this.loadingEnrollees = true;

    forkJoin({
      page: this.enrollmentsService.list(course.course_id, {
        page: this.currentPage,
        perPage: this.pageSize,
        status: this.statusFilter || undefined,
      }),
      stats: this.enrollmentsService.stats(course.course_id).pipe(catchError(() => of(null))),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ page, stats }) => {
          this.enrollees = page.items.map((e) => this.adaptEnrollee(e));
          this.totalEnrollees = page.meta.total;
          this.totalPages = page.meta.totalPages;

          if (stats !== null) {
            this.stats = {
              total_enrolled: stats.total,
              not_started: stats.notStarted,
              in_progress: stats.inProgress,
              completed: stats.completed,
              // Counted from the rows on screen; the enrolment stats do not
              // split by mandatory, and a made-up number is worse than none.
              mandatory: this.enrollees.filter((e) => e.is_mandatory).length,
              avg_progress: stats.averageProgress,
            };
          }

          this.loadingEnrollees = false;
        },
        error: (e: ApiError) => {
          this.errorMessage = e.message;
          this.loadingEnrollees = false;
        },
      });
  }

  goToPage(page: number): void {
    if (page < 1 || page > this.totalPages) {
      return;
    }

    this.currentPage = page;
    this.loadEnrollees();
  }

  get pageNumbers(): number[] {
    return Array.from({ length: this.totalPages }, (_, i) => i + 1);
  }

  // --- selection -----------------------------------------------------------

  isSelected(enrollee: Enrollee): boolean {
    return this.selectedEnrollees.includes(enrollee.user_id);
  }

  toggleEnrolleeSelection(enrollee: Enrollee): void {
    this.selectedEnrollees = this.isSelected(enrollee)
      ? this.selectedEnrollees.filter((id) => id !== enrollee.user_id)
      : [...this.selectedEnrollees, enrollee.user_id];
  }

  /** Every row, or none. The header checkbox binds to `selectAll`. */
  toggleSelectAll(): void {
    this.selectedEnrollees = this.selectAll ? this.enrollees.map((e) => e.user_id) : [];
  }

  isUserToAddSelected(user: UnenrolledUser): boolean {
    return this.selectedUsersToAdd.some((u) => u.user_id === user.user_id);
  }

  /**
   * Whether a due date has passed.
   *
   * Worked out here from the date, the same way the server does — nothing is
   * stored, so a row cannot be stale about it.
   */
  isOverdue(dueDate: string | null): boolean {
    return dueDate !== null && dueDate !== ''
      && new Date(dueDate) < new Date(new Date().toDateString());
  }

  onStatusFilterChange(): void {
    this.currentPage = 1;
    this.loadEnrollees();
  }

  get selectedCount(): number {
    return this.selectedEnrollees.length;
  }

  // --- adding people -------------------------------------------------------

  openAddUsersModal(): void {
    this.showAddUsersModal = true;
    this.selectedUsersToAdd = [];
    this.userSearch = '';
    this.loadUnenrolled();
  }

  closeAddUsersModal(): void {
    this.showAddUsersModal = false;
  }

  private loadUnenrolled(search?: string): void {
    this.loadingUnenrolled = true;

    this.usersService
      .options(search)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => {
          const already = new Set(this.enrollees.map((e) => e.user_id));

          // The people already on the course are filtered out here rather
          // than by a second endpoint that would have to agree with this one.
          this.unenrolledUsers = page.items
            .filter((u) => !already.has(u.id))
            .map((u) => ({
              user_id: u.id,
              first_name: u.firstName,
              last_name: u.lastName,
              email: u.email,
            }));

          this.loadingUnenrolled = false;
        },
        error: () => {
          this.unenrolledUsers = [];
          this.loadingUnenrolled = false;
        },
      });
  }

  onUserSearchChange(): void {
    this.userSearch$.next(this.userSearch.trim());
  }

  toggleUserToAdd(user: UnenrolledUser): void {
    this.selectedUsersToAdd = this.selectedUsersToAdd.some((u) => u.user_id === user.user_id)
      ? this.selectedUsersToAdd.filter((u) => u.user_id !== user.user_id)
      : [...this.selectedUsersToAdd, user];
  }

  removeUserToAdd(user: UnenrolledUser): void {
    this.selectedUsersToAdd = this.selectedUsersToAdd.filter((u) => u.user_id !== user.user_id);
  }

  confirmAddUsers(): void {
    const course = this.selectedCourse;

    if (course === null || this.selectedUsersToAdd.length === 0) {
      return;
    }

    this.processing = true;
    this.errorMessage = '';

    this.enrollmentsService
      .assign(course.course_id, { userIds: this.selectedUsersToAdd.map((u) => u.user_id) })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.processing = false;
          this.closeAddUsersModal();
          // The server's sentence: it separates newly enrolled from already
          // there from reinstated, which one number cannot.
          this.successMessage = result.summary;
          this.loadEnrollees();
        },
        error: (e: ApiError) => {
          this.processing = false;
          this.errorMessage = e.message;
        },
      });
  }

  // --- due dates -----------------------------------------------------------

  openDueDateModal(): void {
    this.showDueDateModal = true;
    this.newDueDate = '';
  }

  closeDueDateModal(): void {
    this.showDueDateModal = false;
  }

  /**
   * Sets a due date on the people already selected.
   *
   * Re-assigning is how a due date changes: assignment is idempotent, so
   * naming somebody already on the course updates their terms rather than
   * enrolling them twice.
   */
  confirmDueDateUpdate(): void {
    const course = this.selectedCourse;

    if (course === null || this.selectedEnrollees.length === 0) {
      return;
    }

    this.processing = true;
    this.errorMessage = '';

    this.enrollmentsService
      .assign(course.course_id, {
        userIds: this.selectedEnrollees,
        dueDate: this.newDueDate === '' ? null : this.newDueDate,
      })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.processing = false;
          this.closeDueDateModal();
          this.successMessage = `Due date set for ${this.selectedEnrollees.length} learner(s).`;
          this.selectedEnrollees = [];
          this.loadEnrollees();
        },
        error: (e: ApiError) => {
          this.processing = false;
          this.errorMessage = e.violation('dueDate') ?? e.message;
        },
      });
  }

  // --- confirmations -------------------------------------------------------

  initiateUnenroll(): void {
    this.confirmAction = 'unenroll';
    this.showConfirmModal = true;
  }

  initiateMandatory(): void {
    this.confirmAction = 'mandatory';
    this.showConfirmModal = true;
  }

  initiateOptional(): void {
    this.confirmAction = 'optional';
    this.showConfirmModal = true;
  }

  closeConfirmModal(): void {
    this.showConfirmModal = false;
    this.confirmAction = '';
  }

  confirmUnenroll(): void {
    const course = this.selectedCourse;

    if (course === null || this.selectedEnrollees.length === 0) {
      return;
    }

    this.processing = true;
    this.errorMessage = '';

    this.enrollmentsService
      .unenroll(course.course_id, this.selectedEnrollees)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.processing = false;
          this.closeConfirmModal();
          // Never a delete. Their progress is still there if they come back.
          this.successMessage = `${result.removed} taken off the course. Their progress is kept.`;
          this.selectedEnrollees = [];
          this.loadEnrollees();
        },
        error: (e: ApiError) => {
          this.processing = false;
          this.closeConfirmModal();
          this.errorMessage = e.message;
        },
      });
  }

  confirmMandatoryChange(): void {
    const course = this.selectedCourse;

    if (course === null || this.selectedEnrollees.length === 0) {
      return;
    }

    const isMandatory = this.confirmAction === 'mandatory';

    this.processing = true;
    this.errorMessage = '';

    this.enrollmentsService
      .assign(course.course_id, { userIds: this.selectedEnrollees, isMandatory })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.processing = false;
          this.closeConfirmModal();
          this.successMessage = isMandatory
            ? `${this.selectedEnrollees.length} enrolment(s) are now mandatory.`
            : `${this.selectedEnrollees.length} enrolment(s) are now optional.`;
          this.selectedEnrollees = [];
          this.loadEnrollees();
        },
        error: (e: ApiError) => {
          this.processing = false;
          this.closeConfirmModal();
          this.errorMessage = e.message;
        },
      });
  }

  // --- presentation --------------------------------------------------------

  getStatusLabel(status: EnrollmentStatus): string {
    return ({
      not_started: 'Not Started',
      in_progress: 'In Progress',
      completed: 'Completed',
      dropped: 'Removed',
    } as Record<string, string>)[status] ?? status;
  }

  getStatusBadgeClass(status: EnrollmentStatus): string {
    return ({
      not_started: 'bg-secondary',
      in_progress: 'bg-info text-dark',
      completed: 'bg-success',
      dropped: 'bg-warning text-dark',
    } as Record<string, string>)[status] ?? 'bg-secondary';
  }

  formatProgress(value: number | null): string {
    return value === null ? '0%' : `${Math.round(value)}%`;
  }

  formatDate(date: string | null): string {
    return date === null || date === ''
      ? '-'
      : new Date(date).toLocaleDateString('en-GB', { month: 'short', day: 'numeric', year: 'numeric' });
  }

  // --- translation ---------------------------------------------------------

  private adaptCourse(course: ApiCourse): Course {
    return {
      course_id: course.id,
      title: course.title,
      category_name: '',
      thumbnail_url: course.thumbnailUrl,
      current_enrollments: 0,
    };
  }

  private adaptEnrollee(enrollment: Enrollment): Enrollee {
    const [first = '', ...rest] = (enrollment.userName ?? enrollment.userEmail ?? '').split(' ');

    return {
      user_id: enrollment.userId,
      first_name: first,
      last_name: rest.join(' '),
      email: enrollment.userEmail ?? '',
      department_name: '',
      status: enrollment.status,
      progress_percentage: enrollment.progressPercentage,
      is_mandatory: enrollment.isMandatory,
      due_date: enrollment.dueDate,
      enrolled_at: enrollment.enrolledAt,
    };
  }
}
