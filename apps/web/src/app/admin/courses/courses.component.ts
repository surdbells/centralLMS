import { Component, OnDestroy, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { TuiIcon, TuiLoader } from '@taiga-ui/core';
import { Subject, forkJoin, of } from 'rxjs';
import { catchError, debounceTime, distinctUntilChanged, takeUntil } from 'rxjs/operators';
import {
  CoursesService,
  type Course as ApiCourse,
  type CourseFilters,
  type CourseStatistics,
  type CourseStatus,
} from '../../services/courses.service';
import { LookupsService, type Lookup } from '../../services/lookups.service';
import { ApiError } from '../../core/api';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { CourseCardComponent } from '../../course-card.component';
import { CourseMiniCardComponent } from '../../course-mini-card.component';

/**
 * A course, in the shape this screen's template reads.
 *
 * Kept as it was. The API answers in camelCase and the template speaks
 * snake_case; translating here is a smaller change than rewriting a screen
 * that already works, and it keeps the two independent — the next field the
 * API renames is a line in {@link CourseComponent.adapt}, not a hunt through
 * seven hundred lines of markup.
 */
export interface Course {
  course_id: string;
  title: string;
  description: string;
  summary: string;
  category_name: string;
  status: CourseStatus;
  level: string | null;
  duration_minutes: number | null;
  is_featured: boolean;
  is_active: boolean;
  start_date: string | null;
  end_date: string | null;
}

export interface Category {
  category_id: string;
  category_name: string;
}

/** What a duplicate would bring across, shown before it runs. */
interface PreviewData {
  will_copy: { modules: number; lessons: number; assessments: number; questions: number };
}

/**
 * Course management.
 *
 * The screen is unchanged; what sits behind it is not. `CrudService` posted a
 * token, a user id and a tenant id in the body of every request and read a
 * `response_code` out of the reply. This uses {@link CoursesService}, and the
 * statistics are counted by the server rather than read from columns that
 * never decremented.
 *
 * The methods the template calls keep their names — `list_course`,
 * `edit_course`, `executeDelete` — because the template is the thing that was
 * working.
 */
@Component({
  selector: 'app-courses',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    TuiIcon,
    TuiLoader,
    AdmintopComponent,
    CourseCardComponent,
    CourseMiniCardComponent,
  ],
  templateUrl: './courses.component.html',
  styleUrls: ['./courses.component.css'],
})
export class CourseComponent implements OnInit, OnDestroy {
  private readonly coursesService = inject(CoursesService);
  private readonly lookupsService = inject(LookupsService);
  private readonly router = inject(Router);

  private readonly destroy$ = new Subject<void>();
  private readonly search$ = new Subject<string>();

  course: Course[] = [];
  category: Category[] = [];

  /** Counted by the server on every read. */
  statistics = { total: 0, draft: 0, published: 0, archived: 0 };

  ui_controls = { is_loading: false };
  protected readonly open = signal(false);

  viewMode: 'grid' | 'list' = 'grid';
  searchQuery = '';

  /** The filters, under the name the template already uses. */
  initial: { status: CourseStatus | ''; category_id: string | null; page: number; per_page: number } = {
    status: '',
    category_id: null,
    page: 1,
    per_page: 12,
  };

  meta = { total: 0, page: 1, per_page: 12, total_pages: 0 };

  isLoading = false;
  previewData: PreviewData | null = null;

  showDeleteModal = false;
  courseToDelete: Course | null = null;

  showPublishModal = false;
  courseToPublish: Course | null = null;

  showUnPublishModal = false;
  courseToUnPublish: Course | null = null;

  showArchiveModal = false;
  courseToArchive: Course | null = null;

  /** The duplicate dialog. `new_title` is advisory: the server picks a free one. */
  /**
   * The duplicate dialog.
   *
   * The tick-boxes are kept because the dialog has them, and they are honest
   * about what the server actually does: modules and lessons always come
   * across, assessments and their questions never do — an assessment belongs
   * to a question bank now, and copying one would mean inventing a second
   * bank nobody asked for.
   */
  duplicate = {
    course_id: '',
    course_title: '',
    new_title: '',
    copy_modules: true,
    copy_lessons: true,
    copy_assessments: false,
    copy_questions: false,
    // Always true, and not settable: a copy that arrived published would put
    // an unreviewed course in the catalogue.
    set_as_draft: true,
  };

  insights = { courseId: '', courseTitle: '' };

  /**
   * The last failure, in the server's own words.
   *
   * Deleting a course somebody is enrolled on comes back with the count and
   * the suggestion to archive it. A toast saying "Failed to delete course"
   * threw that away.
   */
  error: string | null = null;
  notice: string | null = null;

  ngOnInit(): void {
    this.search$
      .pipe(debounceTime(400), distinctUntilChanged(), takeUntil(this.destroy$))
      .subscribe(() => this.list_course(1));

    this.list_category();
    this.list_course();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // --- loading ------------------------------------------------------------

  list_course(page: number = 1, per_page: number = this.initial.per_page): void {
    this.initial.page = page;
    this.initial.per_page = per_page;
    this.ui_controls.is_loading = true;

    const filters: CourseFilters = {
      page,
      perPage: per_page,
      search: this.searchQuery.trim() || undefined,
      status: this.initial.status || undefined,
      categoryId: this.initial.category_id ?? undefined,
    };

    forkJoin({
      page: this.coursesService.list(filters),
      // Counted server-side. The legacy returned them inside the list payload,
      // computed from the page it happened to be returning.
      statistics: this.coursesService.statistics().pipe(catchError(() => of(null))),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ page: result, statistics }) => {
          this.course = result.items.map((c) => this.adapt(c));
          this.meta = {
            total: result.meta.total,
            page: result.meta.page,
            per_page: result.meta.perPage,
            total_pages: result.meta.totalPages,
          };

          if (statistics !== null) {
            this.applyStatistics(statistics);
          }

          this.ui_controls.is_loading = false;
        },
        error: (e: ApiError) => {
          this.error = e.message;
          this.ui_controls.is_loading = false;
        },
      });
  }

  list_category(): void {
    this.lookupsService
      .active('category')
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (categories) => (this.category = categories.map((c) => this.adaptCategory(c))),
        error: () => undefined,
      });
  }

  private applyStatistics(statistics: CourseStatistics): void {
    this.statistics = {
      total: statistics.total,
      draft: statistics.draft,
      published: statistics.published,
      archived: statistics.archived,
    };
  }

  // --- filtering ----------------------------------------------------------

  filterByStatus(status: CourseStatus | ''): void {
    this.initial.status = status;
    this.list_course(1);
  }

  onSearchChange(): void {
    this.search$.next(this.searchQuery);
  }

  clearSearch(): void {
    this.searchQuery = '';
    this.list_course(1);
  }

  clearFilters(): void {
    this.searchQuery = '';
    this.initial.status = '';
    this.initial.category_id = null;
    this.list_course(1);
  }

  hasActiveFilters(): boolean {
    return this.searchQuery !== '' || this.initial.status !== '' || this.initial.category_id !== null;
  }

  // --- publishing ---------------------------------------------------------

  confirmPublish(c: Course): void {
    this.courseToPublish = c;
    this.showPublishModal = true;
  }

  cancelPublish(): void {
    this.showPublishModal = false;
    this.courseToPublish = null;
  }

  executePublish(): void {
    const course = this.courseToPublish;

    if (course === null) {
      return;
    }

    this.setStatus(course, 'published', () => {
      this.cancelPublish();
      this.notice = `"${course.title}" is in the catalogue.`;
    });
  }

  confirmUnPublish(c: Course): void {
    this.courseToUnPublish = c;
    this.showUnPublishModal = true;
  }

  cancelUnPublish(): void {
    this.showUnPublishModal = false;
    this.courseToUnPublish = null;
  }

  executeUnPublish(): void {
    const course = this.courseToUnPublish;

    if (course === null) {
      return;
    }

    this.setStatus(course, 'draft', () => {
      this.cancelUnPublish();
      this.notice = `"${course.title}" is back to a draft.`;
    });
  }

  confirmArchive(c: Course): void {
    this.courseToArchive = c;
    this.showArchiveModal = true;
  }

  cancelArchive(): void {
    this.showArchiveModal = false;
    this.courseToArchive = null;
  }

  executeArchive(): void {
    const course = this.courseToArchive;

    if (course === null) {
      return;
    }

    this.setStatus(course, 'archived', () => {
      this.cancelArchive();
      this.notice = `"${course.title}" is archived. Anyone on it can still finish.`;
    });
  }

  private setStatus(course: Course, status: CourseStatus, done: () => void): void {
    this.ui_controls.is_loading = true;
    this.error = null;

    this.coursesService
      .setStatus(course.course_id, status)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.ui_controls.is_loading = false;
          done();
          this.list_course(this.initial.page);
        },
        error: (e: ApiError) => {
          this.ui_controls.is_loading = false;
          // "This course has no lessons yet, so there would be nothing to
          // complete" — worth reading, and previously replaced by a toast.
          this.error = e.message;
          this.cancelPublish();
          this.cancelUnPublish();
          this.cancelArchive();
        },
      });
  }

  // --- deleting -----------------------------------------------------------

  confirmDelete(c: Course): void {
    this.courseToDelete = c;
    this.showDeleteModal = true;
  }

  cancelDelete(): void {
    this.showDeleteModal = false;
    this.courseToDelete = null;
  }

  executeDelete(): void {
    const course = this.courseToDelete;

    if (course === null) {
      return;
    }

    this.ui_controls.is_loading = true;
    this.error = null;

    this.coursesService
      .delete(course.course_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.ui_controls.is_loading = false;
          this.cancelDelete();
          this.notice = `"${course.title}" was deleted.`;
          this.list_course(this.initial.page);
        },
        error: (e: ApiError) => {
          this.ui_controls.is_loading = false;
          this.cancelDelete();
          this.error = e.message;
        },
      });
  }

  // --- duplicating --------------------------------------------------------

  duplicateCourse(c: Course): void {
    this.duplicate = {
      ...this.duplicate,
      course_id: c.course_id,
      course_title: c.title,
      new_title: `${c.title} (copy)`,
    };
    this.previewData = null;
    this.onPreview();
  }

  /**
   * What a duplicate would bring across.
   *
   * Read from the course's own outline rather than from a preview endpoint —
   * the copy is exactly its modules and lessons, so the outline is the answer.
   * Assessments and their questions are deliberately not copied, and the
   * dialog shows zero rather than hiding the rows.
   */
  onPreview(): void {
    this.isLoading = true;

    this.coursesService
      .outline(this.duplicate.course_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (outline) => {
          this.previewData = {
            will_copy: {
              modules: outline.modules.length,
              lessons: outline.modules.reduce((n, m) => n + m.lessons.length, 0),
              assessments: 0,
              questions: 0,
            },
          };
          this.isLoading = false;
        },
        error: () => {
          this.previewData = null;
          this.isLoading = false;
        },
      });
  }

  onDuplicate(): void {
    this.ui_controls.is_loading = true;
    this.error = null;

    this.coursesService
      .duplicate(this.duplicate.course_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (copy) => {
          this.ui_controls.is_loading = false;
          this.previewData = null;
          this.notice = `"${copy.title}" was created as a draft, with `
            + `${copy.copiedModules} module(s) and ${copy.copiedLessons} lesson(s).`;
          this.list_course(1);
        },
        error: (e: ApiError) => {
          this.ui_controls.is_loading = false;
          this.error = e.message;
        },
      });
  }

  // --- going elsewhere -----------------------------------------------------

  confirmCreate(): void {
    void this.router.navigate(['/courses/create']);
  }

  edit_course(courseId: string, _title: string): void {
    void this.router.navigate(['/courses', courseId, 'edit']);
  }

  manage_course(id: string, _title: string): void {
    // Addressed by the course it edits. It was `/manageCourse?id=`, on a
    // screen that is entirely about one course.
    void this.router.navigate(['/courses', id, 'content']);
  }

  showCourseInsights(courseId: string, _courseTitle: string): void {
    void this.router.navigate(['/courses', courseId, 'insights']);
  }

  courseEnrollees(c: Course): void {
    void this.router.navigate(['/courses', c.course_id, 'enrollees']);
  }

  goBack(): void {
    void this.router.navigate(['/account']);
  }

  // --- presentation --------------------------------------------------------

  getStatusClass(status: string): string {
    return ({
      draft: 'status-draft',
      published: 'status-published',
      archived: 'status-archived',
    } as Record<string, string>)[status] ?? 'status-draft';
  }

  getStatusIcon(status: string): string {
    return ({
      draft: 'bi bi-file-earmark',
      published: 'bi bi-check-circle-fill',
      archived: 'bi bi-archive-fill',
    } as Record<string, string>)[status] ?? 'bi bi-file-earmark';
  }

  getStatusLabel(status: string): string {
    return ({
      draft: 'Draft',
      published: 'Published',
      archived: 'Archived',
    } as Record<string, string>)[status] ?? status;
  }

  formatDate(dateString: string | null): string {
    return dateString === null || dateString === ''
      ? ''
      : new Date(dateString).toLocaleDateString('en-GB', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
      });
  }

  paginate(): void {
    this.list_course(1, this.initial.per_page);
  }

  // --- translation ---------------------------------------------------------

  private adapt(course: ApiCourse): Course {
    return {
      course_id: course.id,
      title: course.title,
      description: course.description ?? course.summary ?? '',
      summary: course.summary ?? '',
      category_name: this.category.find((c) => c.category_id === course.categoryId)?.category_name ?? '',
      status: course.status,
      level: course.level,
      duration_minutes: course.durationMinutes,
      is_featured: course.isFeatured,
      // A course is "active" to this screen when learners can reach it.
      is_active: course.status === 'published',
      start_date: course.startsOn,
      end_date: course.endsOn,
    };
  }

  private adaptCategory(lookup: Lookup): Category {
    return { category_id: lookup.id, category_name: lookup.name };
  }
}
