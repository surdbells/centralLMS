import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { CdkDragDrop, DragDropModule, moveItemInArray } from '@angular/cdk/drag-drop';
import { TuiIcon } from '@taiga-ui/core';
import { Subject, forkJoin, of } from 'rxjs';
import { catchError, takeUntil } from 'rxjs/operators';
import {
  CoursesService,
  type Course as ApiCourse,
  type LessonContentType,
} from '../../../services/courses.service';
import {
  AssessmentsService,
  type Assessment as ApiAssessment,
  type Question as ApiQuestion,
  type QuestionType,
} from '../../../services/assessments.service';
import { MediaService, type MediaItem } from '../../../services/media.service';
import { ApiError } from '../../../core/api';

/* The shapes this screen's template already reads. */

export interface Course {
  course_id: string;
  title: string;
}

export interface Lesson {
  lesson_id: string;
  module_id: string;
  title: string;
  description: string;
  content_type: LessonContentType;
  content_url: string | null;
  content_text: string | null;
  duration_minutes: number | null;
  is_mandatory: boolean;
  is_active: boolean;
}

export interface Assessment {
  assessment_id: string;
  module_id: string | null;
  title: string;
  assessment_type: string;
  time_limit_minutes: number | null;
  pass_percentage: number;
  question_count: number;
  is_active: boolean;
}

export interface Module {
  module_id: string;
  title: string;
  description: string;
  is_active: boolean;
  expanded: boolean;
  lessons: Lesson[];
  assessments: Assessment[];
}

export interface QuestionOptionForm {
  option_text: string;
  is_correct: boolean;
}

/**
 * What this screen calls a question type.
 *
 * `single_choice` is the screen's own word for a question with one right
 * answer; the API calls it `multiple_choice` and distinguishes it from
 * `multiple_response`. Translated on the way in and out rather than renamed
 * in the markup, because the markup is the part that was working.
 */
export type ScreenQuestionType = QuestionType | 'single_choice';

export interface Question {
  question_id: string;
  question_text: string;
  question_type: ScreenQuestionType;
  points: number;
  explanation: string;
  is_active: boolean;
  options: QuestionOptionForm[];
}

export interface MediaFile {
  media_id: string;
  title: string;
  duration_seconds: number | null;
}

/**
 * The curriculum: modules, the lessons in them, and the quizzes attached.
 *
 * The screen is as it was. Three things behind it are not:
 *
 *  - **The whole outline arrives in one request.** It used to fetch the
 *    course, then its modules, then the lessons of each module — sixteen
 *    requests for a fifteen-module course, every time anything changed.
 *  - **Reordering sends the new order as one list.** Dragging a module meant
 *    an update per module it moved past, and a failure halfway through left
 *    two of them claiming the same position.
 *  - **A question belongs to a bank.** That is the one real difference from
 *    the legacy, where a question hung off a quiz and could not be reused —
 *    the same "where is the assembly point?" was retyped into every fire
 *    course, and correcting one corrected none of the others. Questions
 *    written here go into a bank named after the course, so they can be, and
 *    the author does not have to think about banks to write one.
 */
@Component({
  selector: 'app-course-content',
  standalone: true,
  imports: [CommonModule, FormsModule, DragDropModule, TuiIcon],
  templateUrl: './course-content.component.html',
  styleUrls: ['./course-content.component.scss'],
})
export class CourseContentComponent implements OnInit, OnDestroy {
  private readonly coursesService = inject(CoursesService);
  private readonly assessmentsService = inject(AssessmentsService);
  private readonly mediaService = inject(MediaService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly destroy$ = new Subject<void>();

  courseId = '';
  course: Course | null = null;
  modules: Module[] = [];

  mediaFiles: MediaFile[] = [];
  filteredMediaFiles: MediaFile[] = [];
  selectedMedia: MediaFile | null = null;

  loading = true;
  saving = false;
  error: string | null = null;
  successMessage: string | null = null;

  /** Counted from what is on screen, so it cannot drift from it. */
  stats = { total_modules: 0, total_lessons: 0, total_assessments: 0, total_duration_minutes: 0 };

  showModuleModal = false;
  showLessonModal = false;
  showAssessmentModal = false;
  showQuestionManager = false;
  showQuestionFormModal = false;
  showDeleteConfirm = false;

  editMode: 'create' | 'edit' = 'create';
  questionEditMode: 'create' | 'edit' = 'create';

  currentModule = this.emptyModule();
  currentLesson = this.emptyLesson();
  currentAssessment = this.emptyAssessment();
  currentQuestion = this.emptyQuestion();

  selectedModuleId: string | null = null;
  selectedAssessmentId: string | null = null;
  currentAssessmentForQuestions: Assessment | null = null;
  questions: Question[] = [];

  deleteType: 'module' | 'lesson' | 'assessment' | 'question' = 'module';
  deleteId = '';
  deleteName = '';

  readonly contentTypes: Array<{ value: LessonContentType; label: string; icon: string; mediaType?: MediaItem['kind'] }> = [
    { value: 'video', label: 'Video', icon: 'bi-camera-video', mediaType: 'video' },
    { value: 'audio', label: 'Audio', icon: 'bi-music-note-beamed', mediaType: 'audio' },
    { value: 'pdf', label: 'PDF', icon: 'bi-file-earmark-pdf', mediaType: 'pdf' },
    { value: 'scorm', label: 'SCORM', icon: 'bi-box-seam', mediaType: 'scorm' },
    { value: 'text', label: 'Text', icon: 'bi-file-text' },
    { value: 'html', label: 'Rich Content', icon: 'bi-code-slash' },
  ];

  readonly assessmentTypes = [
    { value: 'quiz', label: 'Quiz', icon: 'bi-question-circle' },
    { value: 'exam', label: 'Exam', icon: 'bi-mortarboard' },
    { value: 'survey', label: 'Survey', icon: 'bi-clipboard-check' },
  ];

  readonly questionTypes: Array<{ value: ScreenQuestionType; label: string }> = [
    { value: 'single_choice', label: 'Single Choice' },
    { value: 'multiple_choice', label: 'Multiple Choice' },
    { value: 'multiple_response', label: 'Multiple Response' },
    { value: 'true_false', label: 'True / False' },
    { value: 'short_answer', label: 'Short Answer' },
    { value: 'essay', label: 'Essay' },
  ];

  loadingMedia = false;
  loadingQuestions = false;

  /** `Math` is reached for directly in the template. */
  protected readonly Math = Math;

  /** The bank this course's questions go into, found or made on first use. */
  private bankId: string | null = null;

  ngOnInit(): void {
    this.courseId = this.route.snapshot.paramMap.get('id')
      ?? this.route.snapshot.queryParamMap.get('id')
      ?? '';

    if (this.courseId === '') {
      this.error = 'No course was named in the address.';
      this.loading = false;

      return;
    }

    this.loadContent();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // --- loading -------------------------------------------------------------

  loadContent(): void {
    this.loading = true;

    forkJoin({
      // One request for the whole structure.
      outline: this.coursesService.outline(this.courseId),
      assessments: this.assessmentsService
        .list({ courseId: this.courseId, perPage: 100 })
        .pipe(catchError(() => of(null))),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ outline, assessments }) => {
          this.course = { course_id: outline.course.id, title: outline.course.title };

          const open = new Set(this.modules.filter((m) => m.expanded).map((m) => m.module_id));
          const byModule = new Map<string, Assessment[]>();

          for (const assessment of assessments?.items ?? []) {
            const key = assessment.moduleId ?? '';
            byModule.set(key, [...(byModule.get(key) ?? []), this.adaptAssessment(assessment)]);
          }

          this.modules = outline.modules.map((module) => ({
            module_id: module.id,
            title: module.title,
            description: module.description ?? '',
            is_active: module.isActive,
            // Everything open on a first visit, so an empty course looks empty
            // rather than looking like a list that has not loaded.
            expanded: open.size === 0 ? true : open.has(module.id),
            lessons: module.lessons.map((lesson) => this.adaptLesson(lesson, module.id)),
            assessments: byModule.get(module.id) ?? [],
          }));

          this.recount(outline.course);
          this.loading = false;
        },
        error: (e: ApiError) => {
          this.error = e.message;
          this.loading = false;
        },
      });
  }

  refreshModules(): void {
    this.loadContent();
  }

  private recount(course?: ApiCourse): void {
    const lessons = this.modules.flatMap((m) => m.lessons);

    this.stats = {
      total_modules: this.modules.length,
      total_lessons: lessons.length,
      total_assessments: this.modules.reduce((n, m) => n + m.assessments.length, 0),
      // The lessons' own minutes, not the course's advertised duration —
      // the two drift, and this panel is about what is actually in the course.
      total_duration_minutes: lessons.reduce((n, l) => n + (l.duration_minutes ?? 0), 0)
        || (course?.durationMinutes ?? 0),
    };
  }

  // --- modules --------------------------------------------------------------

  toggleModuleExpand(module: Module): void {
    module.expanded = !module.expanded;
  }

  expandAllModules(): void {
    this.modules.forEach((m) => (m.expanded = true));
  }

  collapseAllModules(): void {
    this.modules.forEach((m) => (m.expanded = false));
  }

  openModuleModal(module?: Module): void {
    this.editMode = module === undefined ? 'create' : 'edit';
    this.currentModule = module === undefined
      ? this.emptyModule()
      : {
        module_id: module.module_id,
        title: module.title,
        description: module.description,
        is_active: module.is_active,
      };
    this.showModuleModal = true;
  }

  saveModule(): void {
    const title = this.currentModule.title.trim();

    if (title === '') {
      return;
    }

    const input = { title, description: this.currentModule.description || null };
    const saved = this.currentModule.module_id === ''
      ? this.coursesService.createModule(this.courseId, input)
      : this.coursesService.updateModule(this.courseId, this.currentModule.module_id, input);

    this.run(saved, 'Module saved.', () => (this.showModuleModal = false));
  }

  duplicateModule(module: Module, event: Event): void {
    event.stopPropagation();

    // A copy of the module with its lessons, made through the same endpoints
    // the builder uses. There is no server-side module duplicate, and adding
    // one would be a second way to do what these two calls already do.
    this.saving = true;

    this.coursesService
      .createModule(this.courseId, { title: `${module.title} (copy)`, description: module.description })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (copy) => {
          const lessons = module.lessons.map((lesson) => this.coursesService.createLesson(
            this.courseId,
            copy.id,
            {
              title: lesson.title,
              description: lesson.description,
              contentType: lesson.content_type,
              contentUrl: lesson.content_url,
              contentText: lesson.content_text,
              durationMinutes: lesson.duration_minutes,
              isMandatory: lesson.is_mandatory,
            },
          ));

          if (lessons.length === 0) {
            this.saving = false;
            this.successMessage = 'Module duplicated.';
            this.loadContent();

            return;
          }

          forkJoin(lessons).pipe(takeUntil(this.destroy$)).subscribe({
            next: () => {
              this.saving = false;
              this.successMessage = `"${module.title}" was duplicated with ${lessons.length} lesson(s).`;
              this.loadContent();
            },
            error: (e: ApiError) => this.failed(e),
          });
        },
        error: (e: ApiError) => this.failed(e),
      });
  }

  onModuleDrop(event: CdkDragDrop<Module[]>): void {
    if (event.previousIndex === event.currentIndex) {
      return;
    }

    moveItemInArray(this.modules, event.previousIndex, event.currentIndex);

    // The whole order in one statement.
    this.coursesService
      .reorderModules(this.courseId, this.modules.map((m) => m.module_id))
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => (this.successMessage = 'Order saved.'),
        error: (e: ApiError) => {
          this.error = e.message;
          this.loadContent();
        },
      });
  }

  // --- lessons --------------------------------------------------------------

  openLessonModal(moduleId: string, lesson?: Lesson): void {
    this.selectedModuleId = moduleId;
    this.editMode = lesson === undefined ? 'create' : 'edit';
    this.currentLesson = lesson === undefined
      ? this.emptyLesson()
      : {
        lesson_id: lesson.lesson_id,
        title: lesson.title,
        description: lesson.description,
        content_type: lesson.content_type,
        content_url: lesson.content_url,
        content_text: lesson.content_text,
        duration_minutes: lesson.duration_minutes,
        is_mandatory: lesson.is_mandatory,
        is_active: lesson.is_active,
        pass_percentage: 0,
      };

    this.selectedMedia = null;
    this.showLessonModal = true;
    this.loadMedia(this.currentLesson.content_type);
  }

  onContentTypeChange(contentType: LessonContentType): void {
    this.currentLesson.content_type = contentType;
    // A video's URL is not a PDF's; keeping the old one leaves a lesson
    // pointing at a file its player cannot open.
    this.currentLesson.content_url = null;
    this.selectedMedia = null;
    this.loadMedia(contentType);
  }

  private loadMedia(contentType: LessonContentType): void {
    const kind = this.contentTypes.find((t) => t.value === contentType)?.mediaType;

    if (kind === undefined) {
      this.mediaFiles = [];
      this.filteredMediaFiles = [];

      return;
    }

    this.loadingMedia = true;

    this.mediaService
      .list({ kind, perPage: 100 })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => {
          this.mediaFiles = page.items.map((item) => ({
            media_id: item.id,
            title: item.title,
            duration_seconds: item.durationSeconds,
          }));
          this.filteredMediaFiles = this.mediaFiles;
          this.loadingMedia = false;
        },
        error: () => {
          this.mediaFiles = [];
          this.filteredMediaFiles = [];
          this.loadingMedia = false;
        },
      });
  }

  onMediaSelect(mediaId: string): void {
    const item = this.mediaFiles.find((m) => m.media_id === mediaId) ?? null;

    this.selectedMedia = item;

    if (item === null) {
      return;
    }

    this.currentLesson.content_url = this.mediaService.fileUrl({ id: item.media_id } as MediaItem);

    // The library knows how long the file runs; asking somebody to retype it
    // is how a course's advertised duration drifts from its content.
    if (this.currentLesson.duration_minutes === null && item.duration_seconds !== null) {
      this.currentLesson.duration_minutes = Math.max(1, Math.round(item.duration_seconds / 60));
    }
  }

  getMediaDisplayName(media: MediaFile): string {
    return media.duration_seconds === null
      ? media.title
      : `${media.title} (${Math.round(media.duration_seconds / 60)} min)`;
  }

  saveLesson(): void {
    const moduleId = this.selectedModuleId;
    const title = this.currentLesson.title.trim();

    if (moduleId === null || title === '') {
      return;
    }

    const input = {
      title,
      description: this.currentLesson.description || null,
      contentType: this.currentLesson.content_type,
      contentUrl: this.currentLesson.content_url,
      contentText: this.currentLesson.content_text,
      durationMinutes: this.currentLesson.duration_minutes,
      isMandatory: this.currentLesson.is_mandatory,
    };

    const saved = this.currentLesson.lesson_id === ''
      ? this.coursesService.createLesson(this.courseId, moduleId, input)
      : this.coursesService.updateLesson(this.courseId, moduleId, this.currentLesson.lesson_id, input);

    this.run(saved, 'Lesson saved.', () => (this.showLessonModal = false));
  }

  duplicateLesson(lesson: Lesson, event: Event): void {
    event.stopPropagation();

    this.run(
      this.coursesService.createLesson(this.courseId, lesson.module_id, {
        title: `${lesson.title} (copy)`,
        description: lesson.description,
        contentType: lesson.content_type,
        contentUrl: lesson.content_url,
        contentText: lesson.content_text,
        durationMinutes: lesson.duration_minutes,
        isMandatory: lesson.is_mandatory,
      }),
      'Lesson duplicated.',
    );
  }

  onLessonDrop(event: CdkDragDrop<Lesson[]>, module: Module): void {
    if (event.previousIndex === event.currentIndex) {
      return;
    }

    moveItemInArray(module.lessons, event.previousIndex, event.currentIndex);

    this.coursesService
      .reorderLessons(this.courseId, module.module_id, module.lessons.map((l) => l.lesson_id))
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => (this.successMessage = 'Order saved.'),
        error: (e: ApiError) => {
          this.error = e.message;
          this.loadContent();
        },
      });
  }

  /** The drop lists a lesson may be dragged between. */
  getConnectedLists(): string[] {
    return this.modules.map((m) => `lesson-list-${m.module_id}`);
  }

  // --- assessments ----------------------------------------------------------

  openAssessmentModal(moduleId?: string, assessment?: Assessment): void {
    this.selectedModuleId = moduleId ?? null;
    this.editMode = assessment === undefined ? 'create' : 'edit';
    this.currentAssessment = assessment === undefined
      ? this.emptyAssessment()
      : {
        assessment_id: assessment.assessment_id,
        title: assessment.title,
        description: '',
        assessment_type: assessment.assessment_type,
        time_limit_minutes: assessment.time_limit_minutes,
        pass_percentage: assessment.pass_percentage,
        max_attempts: null,
        shuffle_questions: false,
        show_correct_answers: true,
        is_active: assessment.is_active,
      };
    this.showAssessmentModal = true;
  }

  saveAssessment(): void {
    const title = this.currentAssessment.title.trim();

    if (title === '') {
      return;
    }

    const input = {
      title,
      description: this.currentAssessment.description || null,
      courseId: this.courseId,
      moduleId: this.selectedModuleId,
      kind: this.currentAssessment.assessment_type,
      timeLimitMinutes: this.currentAssessment.time_limit_minutes,
      maxAttempts: this.currentAssessment.max_attempts,
      passingScore: this.currentAssessment.pass_percentage,
      shuffleQuestions: this.currentAssessment.shuffle_questions,
      revealAnswers: this.currentAssessment.show_correct_answers ? 'after_attempt' : 'never',
    };

    const saved = this.currentAssessment.assessment_id === ''
      ? this.assessmentsService.create(input)
      : this.assessmentsService.update(this.currentAssessment.assessment_id, input);

    this.run(saved, 'Assessment saved.', () => (this.showAssessmentModal = false));
  }

  duplicateAssessment(assessment: Assessment, event: Event): void {
    event.stopPropagation();

    this.run(
      this.assessmentsService.create({
        title: `${assessment.title} (copy)`,
        courseId: this.courseId,
        moduleId: assessment.module_id,
        kind: assessment.assessment_type,
        timeLimitMinutes: assessment.time_limit_minutes,
        passingScore: assessment.pass_percentage,
      }),
      // The questions are not copied: they live in a bank, and both
      // assessments can draw on the same ones rather than owning duplicates.
      'Assessment duplicated. Its questions are shared, not copied — attach the ones you want.',
    );
  }

  // --- questions ------------------------------------------------------------

  manageQuestions(assessment: Assessment): void {
    this.currentAssessmentForQuestions = assessment;
    this.selectedAssessmentId = assessment.assessment_id;
    this.showQuestionManager = true;
    this.loadQuestions();
  }

  private loadQuestions(): void {
    const id = this.selectedAssessmentId;

    if (id === null) {
      return;
    }

    this.loadingQuestions = true;

    this.assessmentsService
      .questions(id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (questions) => {
          this.questions = questions.map((q) => this.adaptQuestion(q));
          this.loadingQuestions = false;
        },
        error: (e: ApiError) => {
          this.error = e.message;
          this.loadingQuestions = false;
        },
      });
  }

  closeQuestionManager(): void {
    this.showQuestionManager = false;
    this.currentAssessmentForQuestions = null;
    this.selectedAssessmentId = null;
    this.questions = [];
  }

  openQuestionForm(question?: Question): void {
    this.questionEditMode = question === undefined ? 'create' : 'edit';
    this.currentQuestion = question === undefined
      ? this.emptyQuestion()
      : { ...question, options: question.options.map((o) => ({ ...o })) };
    this.showQuestionFormModal = true;
  }

  onQuestionTypeChange(type: ScreenQuestionType): void {
    this.currentQuestion.question_type = type;

    if (type === 'true_false') {
      this.currentQuestion.options = [
        { option_text: 'True', is_correct: true },
        { option_text: 'False', is_correct: false },
      ];

      return;
    }

    if (type === 'short_answer' || type === 'essay') {
      this.currentQuestion.options = [];

      return;
    }

    if (this.currentQuestion.options.length === 0) {
      this.currentQuestion.options = [
        { option_text: '', is_correct: true },
        { option_text: '', is_correct: false },
      ];
    }
  }

  addOption(): void {
    this.currentQuestion.options.push({ option_text: '', is_correct: false });
  }

  removeOption(index: number): void {
    this.currentQuestion.options.splice(index, 1);
  }

  /** One right answer, unless the type allows several. */
  onCorrectOptionChange(index: number): void {
    if (this.currentQuestion.question_type === 'multiple_response') {
      return;
    }

    this.currentQuestion.options.forEach((option, i) => (option.is_correct = i === index));
  }

  saveQuestion(): void {
    const assessmentId = this.selectedAssessmentId;
    const text = this.currentQuestion.question_text.trim();

    if (assessmentId === null || text === '') {
      return;
    }

    const input = {
      // The screen's `single_choice` is the API's `multiple_choice`.
      type: (this.currentQuestion.question_type === 'single_choice'
        ? 'multiple_choice'
        : this.currentQuestion.question_type) as QuestionType,
      text,
      points: this.currentQuestion.points,
      explanation: this.currentQuestion.explanation || null,
      options: this.currentQuestion.options.map((o) => ({
        text: o.option_text,
        isCorrect: o.is_correct,
      })),
    };

    this.saving = true;
    this.error = null;

    // Questions live in a bank. The course's own bank is found or made once,
    // so writing a question here does not require thinking about banks — and
    // the question is reusable afterwards, which the legacy's were not.
    this.assessmentsService
      .bankForCourse(this.course?.title ?? 'Course')
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (bank) => {
          this.bankId = bank.id;

          if (this.currentQuestion.question_id !== '') {
            this.assessmentsService
              .updateQuestion(bank.id, this.currentQuestion.question_id, input)
              .pipe(takeUntil(this.destroy$))
              .subscribe({
                next: () => this.questionSaved(),
                error: (e: ApiError) => this.failed(e),
              });

            return;
          }

          this.assessmentsService
            .createQuestion(bank.id, input)
            .pipe(takeUntil(this.destroy$))
            .subscribe({
              next: (created) => {
                // Written into the bank, then put on this assessment. Two
                // steps because they are two facts.
                this.assessmentsService
                  .attachQuestion(assessmentId, created.id, input.points)
                  .pipe(takeUntil(this.destroy$))
                  .subscribe({
                    next: () => this.questionSaved(),
                    error: (e: ApiError) => this.failed(e),
                  });
              },
              error: (e: ApiError) => this.failed(e),
            });
        },
        error: (e: ApiError) => this.failed(e),
      });
  }

  private questionSaved(): void {
    this.saving = false;
    this.showQuestionFormModal = false;
    this.successMessage = 'Question saved.';
    this.loadQuestions();
  }

  deleteQuestion(question: Question): void {
    const assessmentId = this.selectedAssessmentId;

    if (assessmentId === null) {
      return;
    }

    this.run(
      this.assessmentsService.detachQuestion(assessmentId, question.question_id),
      // Taken off this assessment, not destroyed: another one may use it.
      'Question removed from this assessment. It stays in the bank.',
      () => this.loadQuestions(),
    );
  }

  duplicateQuestion(question: Question): void {
    const bankId = this.bankId;

    if (bankId === null) {
      this.openQuestionForm({ ...question, question_id: '', question_text: `${question.question_text} (copy)` });

      return;
    }

    this.run(
      this.assessmentsService.createQuestion(bankId, {
        type: (question.question_type === 'single_choice'
          ? 'multiple_choice'
          : question.question_type) as QuestionType,
        text: `${question.question_text} (copy)`,
        points: question.points,
        explanation: question.explanation || null,
        options: question.options.map((o) => ({ text: o.option_text, isCorrect: o.is_correct })),
      }),
      'Question duplicated into the bank.',
      () => this.loadQuestions(),
    );
  }

  onQuestionDrop(event: CdkDragDrop<Question[]>): void {
    const assessmentId = this.selectedAssessmentId;

    if (assessmentId === null || event.previousIndex === event.currentIndex) {
      return;
    }

    moveItemInArray(this.questions, event.previousIndex, event.currentIndex);

    this.assessmentsService
      .reorderQuestions(assessmentId, this.questions.map((q) => q.question_id))
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => (this.successMessage = 'Order saved.'),
        error: (e: ApiError) => {
          this.error = e.message;
          this.loadQuestions();
        },
      });
  }

  getTotalPoints(): number {
    return this.questions.reduce((n, q) => n + q.points, 0);
  }

  // --- deleting -------------------------------------------------------------

  confirmDelete(type: 'module' | 'lesson' | 'assessment' | 'question', id: string, name: string): void {
    this.deleteType = type;
    this.deleteId = id;
    this.deleteName = name;
    this.showDeleteConfirm = true;
  }

  executeDelete(): void {
    const moduleOf = (lessonId: string): string =>
      this.modules.find((m) => m.lessons.some((l) => l.lesson_id === lessonId))?.module_id ?? '';

    const call = this.deleteType === 'module'
      ? this.coursesService.deleteModule(this.courseId, this.deleteId)
      : this.deleteType === 'lesson'
        ? this.coursesService.deleteLesson(this.courseId, moduleOf(this.deleteId), this.deleteId)
        : this.deleteType === 'assessment'
          ? this.assessmentsService.setStatus(this.deleteId, 'archived')
          : this.assessmentsService.detachQuestion(this.selectedAssessmentId ?? '', this.deleteId);

    this.run(call, `"${this.deleteName}" was removed.`, () => {
      this.showDeleteConfirm = false;

      if (this.deleteType === 'question') {
        this.loadQuestions();
      }
    });
  }

  // --- shared ---------------------------------------------------------------

  private run(call: unknown, message: string, then?: () => void): void {
    this.saving = true;
    this.error = null;

    (call as ReturnType<CoursesService['deleteModule']>)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.saving = false;
          this.successMessage = message;
          then?.();
          this.loadContent();
        },
        error: (e: ApiError) => this.failed(e),
      });
  }

  private failed(error: ApiError): void {
    this.saving = false;
    // The server's own words. A lesson somebody has completed cannot be
    // removed, and it says why.
    this.error = error.message;
  }

  // --- presentation ---------------------------------------------------------

  getContentTypeIcon(type: LessonContentType): string {
    return this.contentTypes.find((t) => t.value === type)?.icon ?? 'bi-file-earmark';
  }

  getContentTypeLabel(type: LessonContentType): string {
    return this.contentTypes.find((t) => t.value === type)?.label ?? type;
  }

  getAssessmentTypeIcon(type: string): string {
    return this.assessmentTypes.find((t) => t.value === type)?.icon ?? 'bi-question-circle';
  }

  getQuestionTypeLabel(type: ScreenQuestionType): string {
    return this.questionTypes.find((t) => t.value === type)?.label ?? type;
  }

  getQuestionTypeIcon(type: ScreenQuestionType): string {
    return ({
      single_choice: 'bi-ui-radios',
      multiple_choice: 'bi-ui-radios',
      multiple_response: 'bi-ui-checks',
      true_false: 'bi-toggle-on',
      short_answer: 'bi-input-cursor-text',
      essay: 'bi-file-text',
    } as Record<string, string>)[type] ?? 'bi-question-circle';
  }

  formatDuration(minutes: number | null): string {
    if (minutes === null || minutes === 0) {
      return '0m';
    }

    return minutes < 60 ? `${minutes}m` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
  }

  trackByModuleId(_index: number, module: Module): string {
    return module.module_id;
  }

  trackByLessonId(_index: number, lesson: Lesson): string {
    return lesson.lesson_id;
  }

  trackByAssessmentId(_index: number, assessment: Assessment): string {
    return assessment.assessment_id;
  }

  trackByQuestionId(_index: number, question: Question): string {
    return question.question_id;
  }

  trackByOptionIndex(index: number): number {
    return index;
  }

  goBack(): void {
    void this.router.navigate(['/courses']);
  }

  // --- empty forms ----------------------------------------------------------

  private emptyModule(): { module_id: string; title: string; description: string; is_active: boolean } {
    return { module_id: '', title: '', description: '', is_active: true };
  }

  private emptyLesson(): {
    lesson_id: string;
    title: string;
    description: string;
    content_type: LessonContentType;
    content_url: string | null;
    content_text: string | null;
    duration_minutes: number | null;
    is_mandatory: boolean;
    is_active: boolean;
    pass_percentage: number;
  } {
    return {
      lesson_id: '',
      title: '',
      description: '',
      content_type: 'video',
      content_url: null,
      content_text: null,
      duration_minutes: null,
      is_mandatory: true,
      is_active: true,
      pass_percentage: 0,
    };
  }

  private emptyAssessment(): {
    assessment_id: string;
    title: string;
    description: string;
    assessment_type: string;
    time_limit_minutes: number | null;
    pass_percentage: number;
    max_attempts: number | null;
    shuffle_questions: boolean;
    show_correct_answers: boolean;
    is_active: boolean;
  } {
    return {
      assessment_id: '',
      title: '',
      description: '',
      assessment_type: 'quiz',
      time_limit_minutes: null,
      pass_percentage: 70,
      max_attempts: null,
      shuffle_questions: false,
      show_correct_answers: true,
      is_active: true,
    };
  }

  private emptyQuestion(): Question {
    return {
      question_id: '',
      question_text: '',
      question_type: 'multiple_choice',
      points: 1,
      explanation: '',
      is_active: true,
      options: [
        { option_text: '', is_correct: true },
        { option_text: '', is_correct: false },
      ],
    };
  }

  // --- translation ----------------------------------------------------------

  private adaptLesson(
    lesson: { id: string; title: string; description: string | null; contentType: LessonContentType;
      contentUrl: string | null; contentText: string | null; durationMinutes: number | null;
      isMandatory: boolean; isActive: boolean },
    moduleId: string,
  ): Lesson {
    return {
      lesson_id: lesson.id,
      module_id: moduleId,
      title: lesson.title,
      description: lesson.description ?? '',
      content_type: lesson.contentType,
      content_url: lesson.contentUrl,
      content_text: lesson.contentText,
      duration_minutes: lesson.durationMinutes,
      is_mandatory: lesson.isMandatory,
      is_active: lesson.isActive,
    };
  }

  private adaptAssessment(assessment: ApiAssessment): Assessment {
    return {
      assessment_id: assessment.id,
      module_id: assessment.moduleId,
      title: assessment.title,
      assessment_type: assessment.kind,
      time_limit_minutes: assessment.timeLimitMinutes,
      pass_percentage: assessment.passingScore,
      // Counted by the server, never stored.
      question_count: assessment.questionCount,
      is_active: assessment.status === 'published',
    };
  }

  private adaptQuestion(question: ApiQuestion): Question {
    return {
      question_id: question.id,
      question_text: question.text,
      question_type: question.type,
      points: question.points,
      explanation: question.explanation ?? '',
      is_active: question.isActive,
      options: question.options.map((o) => ({ option_text: o.text, is_correct: o.isCorrect })),
    };
  }
}
