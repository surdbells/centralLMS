import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Subject, forkJoin, of } from 'rxjs';
import { catchError, debounceTime, distinctUntilChanged, takeUntil } from 'rxjs/operators';
import {
  CoursesService,
  type Course,
  type CourseFilters,
  type CourseLevel,
  type CourseStatistics,
  type CourseStatus,
} from '../../services/courses.service';
import { LookupsService, type Lookup } from '../../services/lookups.service';
import { UsersService, type UserOption } from '../../services/users.service';
import { ApiError } from '../../core/api';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';

/** What a confirmation dialog is about to do. */
type Pending =
  | { kind: 'publish' | 'unpublish' | 'archive' | 'delete' | 'duplicate'; course: Course }
  | null;

/**
 * The course catalogue, for the people who build it.
 *
 * The status buttons now say what the server will actually do. "Delete" is
 * refused while anybody is enrolled and the refusal names archiving instead —
 * the legacy deleted the row and left every enrolment, attempt and certificate
 * pointing at nothing.
 *
 * There is no certificate-template picker. Templates are not attached to
 * courses in this system; a certificate carries the template it was issued
 * with, which is what makes an old certificate still render years later.
 */
@Component({
  selector: 'app-courses',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent],
  templateUrl: './courses.component.html',
  styleUrls: ['./courses.component.css'],
})
export class CourseComponent implements OnInit, OnDestroy {
  private readonly coursesService = inject(CoursesService);
  private readonly lookupsService = inject(LookupsService);
  private readonly usersService = inject(UsersService);
  private readonly router = inject(Router);

  private readonly destroy$ = new Subject<void>();
  private readonly search$ = new Subject<string>();

  courses: Course[] = [];
  statistics: CourseStatistics | null = null;
  categories: Lookup[] = [];
  instructors: UserOption[] = [];

  filters: CourseFilters = { page: 1, perPage: 12 };
  meta = { total: 0, page: 1, perPage: 12, totalPages: 0 };

  loading = true;
  working = false;
  error: string | null = null;
  notice: string | null = null;

  viewMode: 'grid' | 'list' = 'grid';
  searchQuery = '';

  pending: Pending = null;

  readonly levels: CourseLevel[] = ['beginner', 'intermediate', 'professional'];

  ngOnInit(): void {
    this.search$
      .pipe(debounceTime(400), distinctUntilChanged(), takeUntil(this.destroy$))
      .subscribe((term) => {
        this.filters.search = term === '' ? undefined : term;
        this.filters.page = 1;
        this.load();
      });

    // The pickers are reference data and the caller's own people, not fields
    // scraped off whatever the course list happened to return.
    forkJoin({
      categories: this.lookupsService.active('category'),
      instructors: this.usersService.instructors(),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ categories, instructors }) => {
          this.categories = categories;
          this.instructors = instructors.items;
        },
        error: () => undefined,
      });

    this.load();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // --- loading ------------------------------------------------------------

  load(): void {
    this.loading = true;

    forkJoin({
      page: this.coursesService.list(this.filters),
      statistics: this.coursesService.statistics().pipe(catchError(() => of(null))),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ page, statistics }) => {
          this.courses = page.items;
          this.meta = page.meta;
          this.statistics = statistics;
          this.loading = false;
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.loading = false;
        },
      });
  }

  // --- narrowing ----------------------------------------------------------

  onSearchChange(): void {
    this.search$.next(this.searchQuery.trim());
  }

  clearSearch(): void {
    this.searchQuery = '';
    this.search$.next('');
  }

  filterByStatus(status: CourseStatus | ''): void {
    this.filters.status = status === '' || this.filters.status === status ? undefined : status;
    this.filters.page = 1;
    this.load();
  }

  onFilterChange(): void {
    this.filters.page = 1;
    this.load();
  }

  clearFilters(): void {
    this.searchQuery = '';
    this.filters = { page: 1, perPage: this.filters.perPage };
    this.load();
  }

  get hasActiveFilters(): boolean {
    return this.filters.status !== undefined
      || this.filters.categoryId !== undefined
      || this.filters.instructorId !== undefined
      || this.filters.level !== undefined
      || (this.filters.search ?? '') !== '';
  }

  goPage(page: number): void {
    this.filters.page = page;
    this.load();
  }

  // --- acting on one course ------------------------------------------------

  ask(kind: Exclude<Pending, null>['kind'], course: Course): void {
    this.pending = { kind, course };
  }

  cancel(): void {
    this.pending = null;
  }

  confirm(): void {
    const pending = this.pending;

    if (pending === null) {
      return;
    }

    const { kind, course } = pending;

    this.working = true;
    this.error = null;

    const done = (message: string) => {
      this.working = false;
      this.pending = null;
      this.notice = message;
      this.load();
    };

    const failed = (error: ApiError) => {
      this.working = false;
      this.pending = null;
      // The server's own words. "Nineteen people are enrolled on this — archive
      // it instead" is worth reading; "Failed to delete course" is not.
      this.error = error.message;
    };

    switch (kind) {
      case 'publish':
        this.coursesService.setStatus(course.id, 'published').pipe(takeUntil(this.destroy$))
          .subscribe({ next: () => done(`"${course.title}" is now in the catalogue.`), error: failed });
        break;

      case 'unpublish':
        this.coursesService.setStatus(course.id, 'draft').pipe(takeUntil(this.destroy$))
          .subscribe({ next: () => done(`"${course.title}" is back to a draft.`), error: failed });
        break;

      case 'archive':
        this.coursesService.setStatus(course.id, 'archived').pipe(takeUntil(this.destroy$))
          .subscribe({
            next: () => done(`"${course.title}" is archived. Anyone already on it can still finish.`),
            error: failed,
          });
        break;

      case 'delete':
        this.coursesService.delete(course.id).pipe(takeUntil(this.destroy$))
          .subscribe({ next: () => done(`"${course.title}" was deleted.`), error: failed });
        break;

      case 'duplicate':
        this.coursesService.duplicate(course.id).pipe(takeUntil(this.destroy$))
          .subscribe({
            next: (copy) => done(
              `"${copy.title}" was created as a draft, with ${copy.copiedModules} `
              + `module(s) and ${copy.copiedLessons} lesson(s).`,
            ),
            error: failed,
          });
        break;
    }
  }

  /** What the confirmation dialog says, per action. */
  get pendingPrompt(): { title: string; body: string; confirm: string; danger: boolean } | null {
    const pending = this.pending;

    if (pending === null) {
      return null;
    }

    const name = pending.course.title;

    switch (pending.kind) {
      case 'publish':
        return {
          title: 'Publish this course?',
          body: `"${name}" goes into the catalogue and learners can enrol. A course with no lessons `
            + 'cannot be published — there would be nothing to complete.',
          confirm: 'Publish',
          danger: false,
        };
      case 'unpublish':
        return {
          title: 'Take this course off the catalogue?',
          body: `"${name}" becomes a draft. Nobody new can enrol; the people already on it keep their `
            + 'progress.',
          confirm: 'Unpublish',
          danger: false,
        };
      case 'archive':
        return {
          title: 'Archive this course?',
          body: `"${name}" leaves the catalogue for good. Everyone already on it can still finish, and `
            + 'their certificates stay valid.',
          confirm: 'Archive',
          danger: false,
        };
      case 'delete':
        return {
          title: 'Delete this course?',
          body: `"${name}" is removed. This is refused if anybody is enrolled or while it is published `
            + '— archive it instead.',
          confirm: 'Delete',
          danger: true,
        };
      case 'duplicate':
        return {
          title: 'Duplicate this course?',
          body: `A draft copy of "${name}" is made, with its modules and lessons. Enrolments, ratings `
            + 'and the skills it teaches do not come across.',
          confirm: 'Duplicate',
          danger: false,
        };
    }
  }

  // --- going elsewhere -----------------------------------------------------

  createCourse(): void {
    void this.router.navigate(['/courses/create']);
  }

  editCourse(course: Course): void {
    void this.router.navigate(['/courses', course.id, 'edit']);
  }

  manageContent(course: Course): void {
    void this.router.navigate(['/courses', course.id, 'content']);
  }

  viewEnrollees(course: Course): void {
    void this.router.navigate(['/courses', course.id, 'enrollees']);
  }

  viewInsights(course: Course): void {
    void this.router.navigate(['/courses', course.id, 'insights']);
  }

  // --- presentation --------------------------------------------------------

  categoryName(id: string | null): string {
    return id === null ? '-' : this.categories.find((c) => c.id === id)?.name ?? '-';
  }

  instructorName(id: string | null): string {
    return id === null ? 'Unassigned' : this.instructors.find((i) => i.id === id)?.fullName ?? 'Unassigned';
  }

  statusClass(status: CourseStatus): string {
    return { draft: 'status-draft', published: 'status-published', archived: 'status-archived' }[status];
  }

  statusIcon(status: CourseStatus): string {
    return {
      draft: 'bi bi-file-earmark',
      published: 'bi bi-check-circle-fill',
      archived: 'bi bi-archive-fill',
    }[status];
  }

  statusLabel(status: CourseStatus): string {
    return { draft: 'Draft', published: 'Published', archived: 'Archived' }[status];
  }

  duration(minutes: number | null): string {
    if (minutes === null || minutes === 0) {
      return '-';
    }

    return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
  }

  formatDate(date: string | null): string {
    return date === null
      ? '-'
      : new Date(date).toLocaleDateString('en-GB', { month: 'short', day: 'numeric', year: 'numeric' });
  }

  trackById(_index: number, item: { id: string }): string {
    return item.id;
  }
}
