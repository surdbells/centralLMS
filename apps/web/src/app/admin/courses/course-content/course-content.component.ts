import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { CdkDragDrop, DragDropModule, moveItemInArray } from '@angular/cdk/drag-drop';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  CoursesService,
  type CourseModule,
  type CourseOutline,
  type Lesson,
  type LessonContentType,
} from '../../../services/courses.service';
import { MediaService, type MediaItem } from '../../../services/media.service';
import { ApiError } from '../../../core/api';
import { AdmintopComponent } from '../../../partials/top/admintop/admintop.component';

type OutlineModule = CourseModule & { lessons: Lesson[] };

interface ContentTypeOption {
  value: LessonContentType;
  label: string;
  icon: string;
  /** Which kind of media file this lesson type plays, if any. */
  media?: MediaItem['kind'];
}

/**
 * The structure of one course: its modules, and the lessons inside them.
 *
 * **Assessments are not edited here.** They have their own screens, their own
 * permission and their own question bank; a second editor for them inside this
 * one is how the two ended up disagreeing about what a passing score was. The
 * course's assessments are listed with a link out to the real editor.
 *
 * The whole outline arrives in one request. The screen this replaces fetched
 * the course, then its modules, then the lessons of each module — sixteen
 * requests for a course with fifteen modules, every time anything changed.
 */
@Component({
  selector: 'app-course-content',
  standalone: true,
  imports: [CommonModule, FormsModule, DragDropModule, RouterLink, AdmintopComponent],
  templateUrl: './course-content.component.html',
  styleUrls: ['./course-content.component.scss'],
})
export class CourseContentComponent implements OnInit, OnDestroy {
  private readonly coursesService = inject(CoursesService);
  private readonly mediaService = inject(MediaService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly destroy$ = new Subject<void>();

  courseId = '';
  outline: CourseOutline | null = null;

  media: MediaItem[] = [];
  mediaSearch = '';

  loading = true;
  saving = false;
  error: string | null = null;
  notice: string | null = null;

  expanded = new Set<string>();

  // Module editing
  showModuleModal = false;
  moduleForm: { id: string | null; title: string; description: string } = {
    id: null, title: '', description: '',
  };

  // Lesson editing
  showLessonModal = false;
  lessonModuleId: string | null = null;
  lessonForm: {
    id: string | null;
    title: string;
    description: string;
    contentType: LessonContentType;
    contentUrl: string | null;
    contentText: string | null;
    durationMinutes: number | null;
    isMandatory: boolean;
    isPreview: boolean;
  } = this.emptyLesson();

  violations: Record<string, string> = {};

  // Deleting
  pendingDelete: { kind: 'module' | 'lesson'; id: string; moduleId?: string; name: string } | null = null;

  readonly contentTypes: ContentTypeOption[] = [
    { value: 'video', label: 'Video', icon: 'bi-camera-video', media: 'video' },
    { value: 'audio', label: 'Audio', icon: 'bi-music-note-beamed', media: 'audio' },
    { value: 'pdf', label: 'PDF', icon: 'bi-file-earmark-pdf', media: 'pdf' },
    { value: 'scorm', label: 'SCORM package', icon: 'bi-box-seam', media: 'scorm' },
    { value: 'text', label: 'Text', icon: 'bi-file-text' },
    { value: 'html', label: 'Rich content', icon: 'bi-code-slash' },
  ];

  ngOnInit(): void {
    this.courseId = this.route.snapshot.paramMap.get('id') ?? '';

    if (this.courseId === '') {
      this.error = 'No course was named in the address.';
      this.loading = false;

      return;
    }

    this.load();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  load(): void {
    this.loading = true;

    this.coursesService
      .outline(this.courseId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (outline) => {
          this.outline = outline;
          this.loading = false;

          // Everything open on a first visit, so an empty course is visibly
          // empty rather than looking like a list that has not loaded.
          if (this.expanded.size === 0) {
            this.expanded = new Set(outline.modules.map((m) => m.id));
          }
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.loading = false;
        },
      });
  }

  get modules(): OutlineModule[] {
    return this.outline?.modules ?? [];
  }

  get lessonCount(): number {
    return this.modules.reduce((total, m) => total + m.lessons.length, 0);
  }

  // --- modules ------------------------------------------------------------

  toggle(module: OutlineModule): void {
    this.expanded.has(module.id) ? this.expanded.delete(module.id) : this.expanded.add(module.id);
  }

  expandAll(): void {
    this.expanded = new Set(this.modules.map((m) => m.id));
  }

  collapseAll(): void {
    this.expanded = new Set();
  }

  openModuleModal(module?: OutlineModule): void {
    this.violations = {};
    this.moduleForm = module === undefined
      ? { id: null, title: '', description: '' }
      : { id: module.id, title: module.title, description: module.description ?? '' };
    this.showModuleModal = true;
  }

  saveModule(): void {
    const title = this.moduleForm.title.trim();

    if (title === '') {
      return;
    }

    this.saving = true;
    this.violations = {};

    const input = { title, description: this.moduleForm.description || null };
    const saved = this.moduleForm.id === null
      ? this.coursesService.createModule(this.courseId, input)
      : this.coursesService.updateModule(this.courseId, this.moduleForm.id, input);

    saved.pipe(takeUntil(this.destroy$)).subscribe({
      next: (module) => {
        this.saving = false;
        this.showModuleModal = false;
        this.expanded.add(module.id);
        this.load();
      },
      error: (error: ApiError) => this.failed(error),
    });
  }

  setModuleActive(module: OutlineModule, isActive: boolean): void {
    this.run(
      this.coursesService.setModuleStatus(this.courseId, module.id, isActive),
      isActive ? `"${module.title}" is back on.` : `"${module.title}" is hidden from learners.`,
    );
  }

  /**
   * The new order is sent as one list, not as a position per row.
   *
   * Dragging one module used to mean an update per module it moved past; a
   * failure halfway through left two modules claiming the same position.
   */
  onModuleDrop(event: CdkDragDrop<OutlineModule[]>): void {
    if (event.previousIndex === event.currentIndex || this.outline === null) {
      return;
    }

    moveItemInArray(this.outline.modules, event.previousIndex, event.currentIndex);

    this.run(
      this.coursesService.reorderModules(this.courseId, this.modules.map((m) => m.id)),
      'Order saved.',
      // The screen already shows the new order; reloading would make it jump.
      false,
    );
  }

  // --- lessons ------------------------------------------------------------

  openLessonModal(moduleId: string, lesson?: Lesson): void {
    this.violations = {};
    this.lessonModuleId = moduleId;
    this.lessonForm = lesson === undefined
      ? this.emptyLesson()
      : {
        id: lesson.id,
        title: lesson.title,
        description: lesson.description ?? '',
        contentType: lesson.contentType,
        contentUrl: lesson.contentUrl,
        contentText: lesson.contentText,
        durationMinutes: lesson.durationMinutes,
        isMandatory: lesson.isMandatory,
        isPreview: lesson.isPreview,
      };

    this.showLessonModal = true;
    this.loadMediaFor(this.lessonForm.contentType);
  }

  onContentTypeChange(): void {
    // A video's URL is not a PDF's. Keeping the old one would leave a lesson
    // pointing at a file its player cannot open.
    this.lessonForm.contentUrl = null;
    this.loadMediaFor(this.lessonForm.contentType);
  }

  private loadMediaFor(contentType: LessonContentType): void {
    const kind = this.contentTypes.find((t) => t.value === contentType)?.media;

    if (kind === undefined) {
      this.media = [];

      return;
    }

    this.mediaService
      .list({ kind, perPage: 100 })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => (this.media = page.items),
        error: () => (this.media = []),
      });
  }

  get filteredMedia(): MediaItem[] {
    const term = this.mediaSearch.trim().toLowerCase();

    return term === ''
      ? this.media
      : this.media.filter((m) => m.title.toLowerCase().includes(term));
  }

  chooseMedia(item: MediaItem): void {
    this.lessonForm.contentUrl = this.mediaService.fileUrl(item);

    // The library knows how long the file runs; asking somebody to type it
    // again is how a course's advertised duration drifts from its content.
    if (this.lessonForm.durationMinutes === null && item.durationSeconds !== null) {
      this.lessonForm.durationMinutes = Math.max(1, Math.round(item.durationSeconds / 60));
    }
  }

  get needsMedia(): boolean {
    return this.contentTypes.find((t) => t.value === this.lessonForm.contentType)?.media !== undefined;
  }

  saveLesson(): void {
    const moduleId = this.lessonModuleId;
    const title = this.lessonForm.title.trim();

    if (moduleId === null || title === '') {
      return;
    }

    this.saving = true;
    this.violations = {};

    const input: Partial<Lesson> = {
      title,
      description: this.lessonForm.description || null,
      contentType: this.lessonForm.contentType,
      contentUrl: this.lessonForm.contentUrl,
      contentText: this.lessonForm.contentText,
      durationMinutes: this.lessonForm.durationMinutes,
      isMandatory: this.lessonForm.isMandatory,
      isPreview: this.lessonForm.isPreview,
    };

    const saved = this.lessonForm.id === null
      ? this.coursesService.createLesson(this.courseId, moduleId, input)
      : this.coursesService.updateLesson(this.courseId, moduleId, this.lessonForm.id, input);

    saved.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.saving = false;
        this.showLessonModal = false;
        this.load();
      },
      error: (error: ApiError) => this.failed(error),
    });
  }

  setLessonActive(module: OutlineModule, lesson: Lesson, isActive: boolean): void {
    this.run(
      this.coursesService.setLessonStatus(this.courseId, module.id, lesson.id, isActive),
      isActive ? `"${lesson.title}" is back on.` : `"${lesson.title}" is hidden from learners.`,
    );
  }

  onLessonDrop(module: OutlineModule, event: CdkDragDrop<Lesson[]>): void {
    if (event.previousIndex === event.currentIndex) {
      return;
    }

    moveItemInArray(module.lessons, event.previousIndex, event.currentIndex);

    this.run(
      this.coursesService.reorderLessons(this.courseId, module.id, module.lessons.map((l) => l.id)),
      'Order saved.',
      false,
    );
  }

  // --- deleting -----------------------------------------------------------

  askDelete(kind: 'module' | 'lesson', id: string, name: string, moduleId?: string): void {
    this.pendingDelete = { kind, id, name, moduleId };
  }

  confirmDelete(): void {
    const pending = this.pendingDelete;

    if (pending === null) {
      return;
    }

    const call = pending.kind === 'module'
      ? this.coursesService.deleteModule(this.courseId, pending.id)
      : this.coursesService.deleteLesson(this.courseId, pending.moduleId ?? '', pending.id);

    this.saving = true;

    call.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.saving = false;
        this.pendingDelete = null;
        this.notice = `"${pending.name}" was removed.`;
        this.load();
      },
      error: (error: ApiError) => {
        this.saving = false;
        this.pendingDelete = null;
        // A lesson somebody has already completed cannot be removed, and the
        // server says why rather than this guessing.
        this.error = error.message;
      },
    });
  }

  // --- helpers ------------------------------------------------------------

  private run(call: ReturnType<CoursesService['deleteModule']> | unknown, message: string, reload = true): void {
    this.error = null;

    (call as ReturnType<CoursesService['deleteModule']>)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.notice = message;

          if (reload) {
            this.load();
          }
        },
        error: (error: ApiError) => {
          this.error = error.message;
          // The screen showed the change optimistically; putting the server's
          // version back is the only honest thing to do once it refused.
          this.load();
        },
      });
  }

  private failed(error: ApiError): void {
    this.saving = false;
    this.violations = Object.fromEntries(
      Object.entries(error.violations).map(([field, messages]) => [field, messages[0]]),
    );

    if (Object.keys(this.violations).length === 0) {
      this.error = error.message;
    }
  }

  private emptyLesson(): CourseContentComponent['lessonForm'] {
    return {
      id: null,
      title: '',
      description: '',
      contentType: 'video',
      contentUrl: null,
      contentText: null,
      durationMinutes: null,
      isMandatory: true,
      isPreview: false,
    };
  }

  contentIcon(type: LessonContentType): string {
    return this.contentTypes.find((t) => t.value === type)?.icon ?? 'bi-file-earmark';
  }

  contentLabel(type: LessonContentType): string {
    return this.contentTypes.find((t) => t.value === type)?.label ?? type;
  }

  duration(minutes: number | null): string {
    if (minutes === null || minutes === 0) {
      return '';
    }

    return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
  }

  backToCourse(): void {
    void this.router.navigate(['/courses', this.courseId, 'edit']);
  }

  trackById(_index: number, item: { id: string }): string {
    return item.id;
  }
}
