import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Subject, forkJoin } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  CoursesService,
  type Course,
  type CourseInput,
  type CourseLevel,
  type CourseStatus,
} from '../../../services/courses.service';
import { LookupsService, type Lookup } from '../../../services/lookups.service';
import { UsersService, type UserOption } from '../../../services/users.service';
import { ApiError } from '../../../core/api';
import { AdmintopComponent } from '../../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';

/**
 * The details of one course. Not its content — that is the builder.
 *
 * Status is not a field on this form. Publishing is a separate call with a
 * separate permission and its own refusal (a course with no lessons cannot be
 * published), so it is a button that says what it does rather than an option
 * in a dropdown that silently fails.
 *
 * There is no certificate-template picker either. Templates are not attached
 * to courses in this system: a certificate carries the template it was issued
 * with, which is what lets a certificate from two years ago still render.
 */
@Component({
  selector: 'app-course-edit',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent, TranslatePipe],
  templateUrl: './course-edit.component.html',
})
export class CourseEditComponent implements OnInit, OnDestroy {
  private readonly coursesService = inject(CoursesService);
  private readonly lookupsService = inject(LookupsService);
  private readonly usersService = inject(UsersService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly i18n = inject(I18nService);
  private readonly destroy$ = new Subject<void>();

  courseId: string | null = null;
  isEdit = false;
  status: CourseStatus = 'draft';

  form: CourseInput = {
    title: '',
    summary: '',
    description: '',
    objectives: '',
    level: null,
    categoryId: null,
    instructorId: null,
    thumbnailUrl: '',
    passPercentage: 70,
    durationMinutes: null,
    enrollmentLimit: null,
    isMandatory: false,
    isFeatured: false,
    isOnboarding: false,
    allowSelfEnrollment: false,
    startsOn: null,
    endsOn: null,
  };

  categories: Lookup[] = [];
  instructors: UserOption[] = [];

  loading = true;
  saving = false;
  publishing = false;
  error: string | null = null;
  notice: string | null = null;
  violations: Record<string, string> = {};

  readonly levels: CourseLevel[] = ['beginner', 'intermediate', 'professional'];

  ngOnInit(): void {
    this.courseId = this.route.snapshot.paramMap.get('id');
    this.isEdit = this.courseId !== null;

    forkJoin({
      categories: this.lookupsService.active('category'),
      instructors: this.usersService.instructors(),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ categories, instructors }) => {
          this.categories = categories;
          this.instructors = instructors.items;

          if (this.courseId === null) {
            this.loading = false;

            return;
          }

          this.loadCourse(this.courseId);
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.loading = false;
        },
      });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  private loadCourse(id: string): void {
    this.coursesService
      .show(id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (course) => {
          this.apply(course);
          this.loading = false;
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.loading = false;
        },
      });
  }

  private apply(course: Course): void {
    this.status = course.status;
    this.form = {
      title: course.title,
      summary: course.summary,
      description: course.description,
      objectives: course.objectives,
      level: course.level,
      categoryId: course.categoryId,
      instructorId: course.instructorId,
      thumbnailUrl: course.thumbnailUrl,
      passPercentage: course.passPercentage,
      durationMinutes: course.durationMinutes,
      enrollmentLimit: course.enrollmentLimit,
      isMandatory: course.isMandatory,
      isFeatured: course.isFeatured,
      isOnboarding: course.isOnboarding,
      allowSelfEnrollment: course.allowSelfEnrollment,
      startsOn: course.startsOn,
      endsOn: course.endsOn,
    };
  }

  // --- saving -------------------------------------------------------------

  save(): void {
    this.saving = true;
    this.error = null;
    this.notice = null;
    this.violations = {};

    const saved = this.isEdit && this.courseId !== null
      ? this.coursesService.update(this.courseId, this.form)
      : this.coursesService.create(this.form);

    saved.pipe(takeUntil(this.destroy$)).subscribe({
      next: (course) => {
        this.saving = false;
        this.apply(course);

        if (this.isEdit) {
          this.notice = this.i18n.t('admin.course_edit.toast_saved');

          return;
        }

        // A new course goes straight to its content, because an empty course
        // cannot be published and the next thing to do is add a lesson.
        void this.router.navigate(['/courses', course.id, 'content']);
      },
      error: (error: ApiError) => {
        this.saving = false;
        this.violations = Object.fromEntries(
          Object.entries(error.violations).map(([field, messages]) => [field, messages[0]]),
        );
        this.error = Object.keys(this.violations).length === 0 ? error.message : null;
      },
    });
  }

  /**
   * Publishing is its own act.
   *
   * The server refuses a course with no lessons, and the refusal says so —
   * which is the whole reason this is a button and not a status dropdown.
   */
  setStatus(status: CourseStatus): void {
    if (this.courseId === null) {
      return;
    }

    this.publishing = true;
    this.error = null;
    this.notice = null;

    this.coursesService
      .setStatus(this.courseId, status)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (course) => {
          this.publishing = false;
          this.status = course.status;
          this.notice = status === 'published'
            ? this.i18n.t('admin.course_edit.toast_published')
            : this.i18n.t('admin.course_edit.toast_status_changed', { status: this.i18n.t('admin.courses.' + course.status) });
        },
        error: (error: ApiError) => {
          this.publishing = false;
          this.error = error.message;
        },
      });
  }

  manageContent(): void {
    if (this.courseId !== null) {
      void this.router.navigate(['/courses', this.courseId, 'content']);
    }
  }

  // --- presentation -------------------------------------------------------

  statusBadge(status: CourseStatus): string {
    return {
      draft: 'bg-secondary',
      published: 'bg-success',
      archived: 'bg-warning text-dark',
    }[status];
  }

  trackById(_index: number, item: { id: string }): string {
    return item.id;
  }
}
