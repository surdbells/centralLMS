// course-content/course-content.component.ts

import { Component, OnInit, OnDestroy, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  CdkDragDrop,
  moveItemInArray,
  transferArrayItem,
  DragDropModule
} from '@angular/cdk/drag-drop';
import { CourseContentService, MediaFile } from './course-content.service';
import {
  Course,
  Module,
  Lesson,
  Assessment,
  Question,
  QuestionOption,
  ModuleForm,
  LessonForm,
  AssessmentForm,
  QuestionForm,
  QuestionOptionForm,
  LessonContentType,
  AssessmentType,
  QuestionType
} from './course-content.interfaces';
import {TuiDialog, TuiIcon} from '@taiga-ui/core';
import {HotToastService} from '@ngneat/hot-toast';
import {ActivatedRoute, Router} from '@angular/router';
import {CrudService} from '../../../services/crud.service';

@Component({
  selector: 'app-course-content',
  standalone: true,
  imports: [CommonModule, FormsModule, DragDropModule, TuiDialog, TuiIcon],
  templateUrl: './course-content.component.html',
  styleUrls: ['./course-content.component.scss']
})
export class CourseContentComponent implements OnInit, OnDestroy {
  @Input() courseId!: number;

  private destroy$ = new Subject<void>();

  // Math for template usage
  Math = Math;

  // Data
  course: Course | null = null;
  modules: Module[] = [];
  mediaFiles: MediaFile[] = [];
  filteredMediaFiles: MediaFile[] = [];

  // UI State
  loading = false;
  saving = false;
  error: string | null = null;
  successMessage: string | null = null;

  // Modal states
  showModuleModal = false;
  showLessonModal = false;
  showAssessmentModal = false;
  showQuestionModal = false;
  showDeleteConfirm = false;

  // Edit mode
  editMode: 'create' | 'edit' = 'create';

  // Current editing items
  currentModule: ModuleForm = this.getEmptyModuleForm();
  currentLesson: LessonForm = this.getEmptyLessonForm();
  currentAssessment: AssessmentForm = this.getEmptyAssessmentForm();
  currentQuestion: QuestionForm = this.getEmptyQuestionForm();

  // Parent context for modals
  selectedModuleId: number | null = null;
  selectedAssessmentId: number | null = null;

  // Delete confirmation
  deleteType: 'module' | 'lesson' | 'assessment' | 'question' = 'module';
  deleteId: number = 0;
  deleteName: string = '';

  // Content type options with media type mapping
  contentTypes: { value: LessonContentType; label: string; icon: string; mediaType?: string }[] = [
    { value: 'video', label: 'Video', icon: 'bi-play-circle', mediaType: 'VIDEO' },
    { value: 'audio', label: 'Audio', icon: 'bi-music-note-beamed', mediaType: 'AUDIO' },
    { value: 'pdf', label: 'PDF Document', icon: 'bi-file-pdf', mediaType: 'PDF' },
    { value: 'html', label: 'HTML/Web Content', icon: 'bi-code-slash' },
    { value: 'scorm', label: 'SCORM Package', icon: 'bi-box', mediaType: 'SCORM' },
    { value: 'text', label: 'Text Content', icon: 'bi-file-text' }
  ];

  // Selected media for lesson
  selectedMedia: MediaFile | null = null;
  loadingMedia = false;

  // Question management
  showQuestionManagerModal = false;
  currentAssessmentForQuestions: Assessment | null = null;
  questions: Question[] = [];
  loadingQuestions = false;
  showQuestionFormModal = false;
  questionEditMode: 'create' | 'edit' = 'create';

  assessmentTypes: { value: AssessmentType; label: string; icon: string }[] = [
    { value: 'quiz', label: 'Quiz', icon: 'bi-question-circle' },
    { value: 'exam', label: 'Exam', icon: 'bi-journal-check' },
    { value: 'survey', label: 'Survey', icon: 'bi-clipboard-data' },
    { value: 'assignment', label: 'Assignment', icon: 'bi-file-earmark-text' }
  ];

  questionTypes: { value: QuestionType; label: string }[] = [
    { value: 'single_choice', label: 'Single Choice' },
    { value: 'multiple_choice', label: 'Multiple Choice' },
    { value: 'true_false', label: 'True/False' },
    { value: 'short_answer', label: 'Short Answer' },
    { value: 'essay', label: 'Essay' },
    { value: 'fill_blank', label: 'Fill in the Blank' }
  ];

  // Stats
  stats = {
    total_modules: 0,
    total_lessons: 0,
    total_assessments: 0,
    total_questions: 0,
    total_duration_minutes: 0
  };

  constructor(
    private contentService: CourseContentService,
    private router: Router,
    private route: ActivatedRoute,
    private crudService: CrudService,
    private toast: HotToastService
    ) {}

  ngOnInit(): void {
    if (this.courseId) {
      this.loadContent();
    }
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // DATA LOADING
  // ==========================================

  loadContent(): void {
    this.loading = true;
    this.error = null;

    this.contentService.loadCourseContent(this.courseId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (data) => {
          this.course = data.course;
          this.modules = data.modules.map(m => ({ ...m, expanded: true }));
          this.stats = data.stats;
          this.loading = false;
        },
        error: (err) => {
          this.error = 'Failed to load course content';
          this.loading = false;
          console.error(err);
        }
      });
  }

  refreshModules(): void {
    this.contentService.refreshModules(this.courseId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (modules) => {
          this.modules = modules.map(m => ({ ...m, expanded: true }));
          this.updateStats();
        },
        error: (err) => console.error(err)
      });
  }

  // ==========================================
  // MODULE OPERATIONS
  // ==========================================

  openModuleModal(module?: Module): void {
    this.editMode = module ? 'edit' : 'create';
    this.currentModule = module ? {
      module_id: module.module_id,
      title: module.title,
      description: module.description || '',
      is_active: module.is_active
    } : this.getEmptyModuleForm();
    this.showModuleModal = true;
  }

  saveModule(): void {
    if (!this.currentModule.title.trim()) {
      this.error_notification('Module title is required');
      return;
    }

    this.saving = true;

    const operation = this.editMode === 'create'
      ? this.contentService.createModule(this.courseId, this.currentModule)
      : this.contentService.updateModule(this.currentModule.module_id!, this.currentModule);

    operation.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.success_notification(`Module ${this.editMode === 'create' ? 'created' : 'updated'} successfully`);
        this.showModuleModal = false;
        this.refreshModules();
        this.saving = false;
      },
      error: (err) => {
        this.error_notification(`Failed to ${this.editMode} module`);
        this.saving = false;
      }
    });
  }

  toggleModuleExpand(module: Module): void {
    module.expanded = !module.expanded;
  }

  expandAllModules(): void {
    this.modules.forEach(m => m.expanded = true);
  }

  collapseAllModules(): void {
    this.modules.forEach(m => m.expanded = false);
  }

  duplicateModule(module: Module, event: Event): void {
    event.stopPropagation();
    this.saving = true;

    this.contentService.duplicateModule(module.module_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.success_notification('Module duplicated successfully');
          this.refreshModules();
          this.saving = false;
        },
        error: (err) => {
          this.error_notification('Failed to duplicate module');
          this.saving = false;
        }
      });
  }

  // ==========================================
  // LESSON OPERATIONS
  // ==========================================

  openLessonModal(moduleId: number, lesson?: Lesson): void {
    this.selectedModuleId = moduleId;
    this.editMode = lesson ? 'edit' : 'create';
    this.selectedMedia = null;
    this.filteredMediaFiles = [];

    this.currentLesson = lesson ? {
      lesson_id: lesson.lesson_id,
      module_id: lesson.module_id,
      title: lesson.title,
      description: lesson.description || '',
      content_type: lesson.content_type,
      content_url: lesson.content_url || '',
      content_text: lesson.content_text || '',
      duration_minutes: lesson.duration_minutes || 0,
      is_mandatory: lesson.is_mandatory,
      is_active: lesson.is_active,
      pass_percentage: lesson.pass_percentage || 70
    } : this.getEmptyLessonForm(moduleId);

    // Load media for the current content type
    this.onContentTypeChange(this.currentLesson.content_type);

    this.showLessonModal = true;
  }

  /**
   * Handle content type change - load appropriate media files
   */
  onContentTypeChange(contentType: LessonContentType): void {
    this.currentLesson.content_type = contentType;
    this.selectedMedia = null;
    this.filteredMediaFiles = [];

    // Find the media type for this content type
    const typeConfig = this.contentTypes.find(t => t.value === contentType);
    if (typeConfig?.mediaType) {
      this.loadMediaForType(typeConfig.mediaType);
    }
  }

  /**
   * Load media files for a specific type
   */
  private loadMediaForType(mediaType: string): void {
    this.loadingMedia = true;

    this.contentService.getMediaByType(mediaType)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (files) => {
          this.filteredMediaFiles = files;
          this.loadingMedia = false;

          // If editing and there's a content_url, try to find matching media
          if (this.currentLesson.content_url) {
            this.selectedMedia = files.find(f => {
              const builtUrl = this.contentService.buildContentUrl(f);
              return builtUrl === this.currentLesson.content_url || f.file_url === this.currentLesson.content_url;
            }) || null;
          }
        },
        error: (err) => {
          console.error('Failed to load media:', err);
          this.loadingMedia = false;
        }
      });
  }

  /**
   * Handle media selection from dropdown
   */
  onMediaSelect(mediaId: number | string): void {
    if (!mediaId || mediaId === '') {
      this.selectedMedia = null;
      this.currentLesson.content_url = '';
      return;
    }

    const media = this.filteredMediaFiles.find(f => f.media_id === +mediaId);
    if (media) {
      this.selectedMedia = media;
      // Build the content URL based on media type
      this.currentLesson.content_url = this.contentService.buildContentUrl(media);

      // Auto-fill title if empty
      if (!this.currentLesson.title.trim()) {
        this.currentLesson.title = media.title;
      }

      // Auto-fill duration for video/audio
      if (media.duration_seconds && (media.media_type === 'VIDEO' || media.media_type === 'AUDIO')) {
        this.currentLesson.duration_minutes = Math.ceil(media.duration_seconds / 60);
      }
    }
  }

  /**
   * Get display name for media in dropdown
   */
  getMediaDisplayName(media: MediaFile): string {
    let name = media.title;
    if (media.duration_seconds) {
      const mins = Math.floor(media.duration_seconds / 60);
      const secs = media.duration_seconds % 60;
      name += ` (${mins}:${secs.toString().padStart(2, '0')})`;
    }
    return name;
  }

  saveLesson(): void {
    if (!this.currentLesson.title.trim()) {
      this.error_notification('Lesson title is required');
      return;
    }

    this.saving = true;

    const operation = this.editMode === 'create'
      ? this.contentService.createLesson(this.courseId,this.currentLesson)
      : this.contentService.updateLesson(this.currentLesson.lesson_id!, this.currentLesson);

    operation.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.success_notification(`Lesson ${this.editMode === 'create' ? 'created' : 'updated'} successfully`);
        this.showLessonModal = false;
        this.refreshModules();
        this.saving = false;
      },
      error: (err) => {
        this.error_notification(`Failed to ${this.editMode} lesson`);
        this.saving = false;
      }
    });
  }

  duplicateLesson(lesson: Lesson, event: Event): void {
    event.stopPropagation();
    this.saving = true;

    this.contentService.duplicateLesson(lesson.lesson_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.success_notification('Lesson duplicated successfully');
          this.refreshModules();
          this.saving = false;
        },
        error: (err) => {
          this.error_notification('Failed to duplicate lesson');
          this.saving = false;
        }
      });
  }

  // ==========================================
  // ASSESSMENT OPERATIONS
  // ==========================================

  openAssessmentModal(moduleId?: number, assessment?: Assessment): void {
    this.selectedModuleId = moduleId || null;
    this.editMode = assessment ? 'edit' : 'create';
    this.currentAssessment = assessment ? {
      assessment_id: assessment.assessment_id,
      module_id: assessment.module_id,
      title: assessment.title,
      description: assessment.description || '',
      assessment_type: assessment.assessment_type,
      time_limit_minutes: assessment.time_limit_minutes || 0,
      pass_percentage: assessment.pass_percentage,
      max_attempts: assessment.max_attempts || 3,
      shuffle_questions: assessment.shuffle_questions,
      show_correct_answers: assessment.show_correct_answers,
      is_active: assessment.is_active
    } : this.getEmptyAssessmentForm(moduleId);
    this.showAssessmentModal = true;
  }

  saveAssessment(): void {
    if (!this.currentAssessment.title.trim()) {
      this.error_notification('Assessment title is required');
      return;
    }

    this.saving = true;

    const operation = this.editMode === 'create'
      ? this.contentService.createAssessment(this.courseId, this.currentAssessment)
      : this.contentService.updateAssessment(this.currentAssessment.assessment_id!, this.currentAssessment);

    operation.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.success_notification(`Assessment ${this.editMode === 'create' ? 'created' : 'updated'} successfully`);
        this.showAssessmentModal = false;
        this.refreshModules();
        this.saving = false;
      },
      error: (err) => {
        this.error_notification(`Failed to ${this.editMode} assessment`);
        this.saving = false;
      }
    });
  }

  manageQuestions(assessment: Assessment): void {
    this.currentAssessmentForQuestions = assessment;
    this.selectedAssessmentId = assessment.assessment_id;
    this.questions = [];
    this.showQuestionManagerModal = true;
    this.loadQuestions(assessment.assessment_id);
  }

  private loadQuestions(assessmentId: number): void {
    this.loadingQuestions = true;

    this.contentService.getQuestions(assessmentId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (questions) => {
          this.questions = questions;
          this.loadingQuestions = false;
        },
        error: (err) => {
          this.error_notification('Failed to load question-banks');
          this.loadingQuestions = false;
        }
      });
  }

  openQuestionForm(question?: Question): void {
    this.questionEditMode = question ? 'edit' : 'create';

    if (question) {
      this.currentQuestion = {
        question_id: question.question_id,
        question_text: question.question_text,
        question_type: question.question_type,
        points: question.points,
        explanation: question.explanation || '',
        is_active: question.is_active,
        options: question.options?.map(o => ({
          option_id: o.option_id,
          option_text: o.option_text,
          is_correct: o.is_correct,
          feedback: o.feedback || ''
        })) || this.getDefaultOptions()
      };
    } else {
      this.currentQuestion = this.getEmptyQuestionForm();
    }

    this.showQuestionFormModal = true;
  }

  saveQuestion(): void {
    if (!this.currentQuestion.question_text.trim()) {
      this.error_notification('Question text is required');
      return;
    }

    // Validate at least one correct answer for choice question-banks
    if (['single_choice', 'multiple_choice', 'true_false'].includes(this.currentQuestion.question_type)) {
      const hasCorrect = this.currentQuestion.options.some(o => o.is_correct);
      if (!hasCorrect) {
        this.error_notification('Please mark at least one correct answer');
        return;
      }
    }

    this.saving = true;

    const operation = this.questionEditMode === 'create'
      ? this.contentService.createQuestion(this.selectedAssessmentId!, this.currentQuestion)
      : this.contentService.updateQuestion(this.currentQuestion.question_id!, this.currentQuestion);

    operation.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.success_notification(`Question ${this.questionEditMode === 'create' ? 'created' : 'updated'} successfully`);
        this.showQuestionFormModal = false;
        this.loadQuestions(this.selectedAssessmentId!);
        this.saving = false;
      },
      error: (err) => {
        this.error_notification(`Failed to ${this.questionEditMode} question`);
        this.saving = false;
      }
    });
  }

  deleteQuestion(question: Question): void {
    this.deleteType = 'question';
    this.deleteId = question.question_id;
    this.deleteName = question.question_text.substring(0, 50) + (question.question_text.length > 50 ? '...' : '');
    this.showDeleteConfirm = true;
  }

  duplicateQuestion(question: Question): void {
    const newQuestion: QuestionForm = {
      question_text: question.question_text + ' (Copy)',
      question_type: question.question_type,
      points: question.points,
      explanation: question.explanation || '',
      is_active: question.is_active,
      options: question.options?.map(o => ({
        option_text: o.option_text,
        is_correct: o.is_correct,
        feedback: o.feedback || ''
      })) || []
    };

    this.saving = true;
    this.contentService.createQuestion(this.selectedAssessmentId!, newQuestion)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.success_notification('Question duplicated');
          this.loadQuestions(this.selectedAssessmentId!);
          this.saving = false;
        },
        error: () => {
          this.error_notification('Failed to duplicate question');
          this.saving = false;
        }
      });
  }

  onQuestionDrop(event: CdkDragDrop<Question[]>): void {
    if (event.previousIndex === event.currentIndex) return;

    moveItemInArray(this.questions, event.previousIndex, event.currentIndex);

    const questionIds = this.questions.map(q => q.question_id);
    this.contentService.reorderQuestions(this.selectedAssessmentId!, questionIds)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        error: () => {
          this.error_notification('Failed to reorder question-banks');
          this.loadQuestions(this.selectedAssessmentId!);
        }
      });
  }

  private getDefaultOptions(): QuestionOptionForm[] {
    return [
      { option_text: '', is_correct: false, feedback: '' },
      { option_text: '', is_correct: false, feedback: '' }
    ];
  }

  getQuestionTypeLabel(type: QuestionType): string {
    const found = this.questionTypes.find(t => t.value === type);
    return found ? found.label : type;
  }

  getQuestionTypeIcon(type: QuestionType): string {
    switch (type) {
      case 'single_choice': return 'bi-circle';
      case 'multiple_choice': return 'bi-check2-square';
      case 'true_false': return 'bi-toggle-on';
      case 'short_answer': return 'bi-input-cursor-text';
      case 'essay': return 'bi-text-paragraph';
      case 'fill_blank': return 'bi-input-cursor';
      default: return 'bi-question';
    }
  }

  getTotalPoints(): number {
    return this.questions.reduce((sum, q) => sum + q.points, 0);
  }

  closeQuestionManager(): void {
    this.showQuestionManagerModal = false;
    this.currentAssessmentForQuestions = null;
    this.questions = [];
    // Refresh modules to update question counts
    this.refreshModules();
  }

  duplicateAssessment(assessment: Assessment, event: Event): void {
    event.stopPropagation();
    this.saving = true;

    this.contentService.duplicateAssessment(assessment.assessment_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.success_notification('Assessment duplicated successfully');
          this.refreshModules();
          this.saving = false;
        },
        error: (err) => {
          this.error_notification('Failed to duplicate assessments');
          this.saving = false;
        }
      });
  }

  // ==========================================
  // DELETE OPERATIONS
  // ==========================================

  confirmDelete(type: 'module' | 'lesson' | 'assessment' | 'question', id: number, name: string): void {
    this.deleteType = type;
    this.deleteId = id;
    this.deleteName = name;
    this.showDeleteConfirm = true;
  }

  executeDelete(): void {
    this.saving = true;

    let operation;
    switch (this.deleteType) {
      case 'module':
        operation = this.contentService.deleteModule(this.deleteId);
        break;
      case 'lesson':
        operation = this.contentService.deleteLesson(this.deleteId);
        break;
      case 'assessment':
        operation = this.contentService.deleteAssessment(this.deleteId);
        break;
      case 'question':
        operation = this.contentService.deleteQuestion(this.deleteId);
        break;
    }

    operation.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.success_notification(`${this.deleteType.charAt(0).toUpperCase() + this.deleteType.slice(1)} deleted successfully`);
        this.showDeleteConfirm = false;

        // If deleting a question, refresh question-banks list
        if (this.deleteType === 'question' && this.selectedAssessmentId) {
          this.loadQuestions(this.selectedAssessmentId);
        } else {
          this.refreshModules();
        }
        this.saving = false;
      },
      error: (err) => {
        this.error_notification(`Failed to delete ${this.deleteType}`);
        this.saving = false;
      }
    });
  }

  // ==========================================
  // DRAG & DROP
  // ==========================================

  onModuleDrop(event: CdkDragDrop<Module[]>): void {
    if (event.previousIndex === event.currentIndex) return;

    moveItemInArray(this.modules, event.previousIndex, event.currentIndex);

    const moduleIds = this.modules.map(m => m.module_id);
    this.contentService.reorderModules(this.courseId, moduleIds)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        error: (err) => {
          this.error_notification('Failed to reorder modules');
          this.refreshModules(); // Revert on error
        }
      });
  }

  onLessonDrop(event: CdkDragDrop<Lesson[]>, module: Module): void {
    if (event.previousContainer === event.container) {
      // Same container - reorder
      if (event.previousIndex === event.currentIndex) return;

      moveItemInArray(module.lessons!, event.previousIndex, event.currentIndex);

      const lessonIds = module.lessons!.map(l => l.lesson_id);
      this.contentService.reorderLessons(module.module_id, lessonIds)
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          error: (err) => {
            this.error_notification('Failed to reorder lessons');
            this.refreshModules();
          }
        });
    } else {
      // Different container - move between modules
      transferArrayItem(
        event.previousContainer.data,
        event.container.data,
        event.previousIndex,
        event.currentIndex
      );

      const lesson = module.lessons![event.currentIndex];
      this.contentService.moveLesson(lesson.lesson_id, module.module_id)
        .pipe(takeUntil(this.destroy$))
        .subscribe({
          next: () => {
            // Update lesson order in new module
            const lessonIds = module.lessons!.map(l => l.lesson_id);
            this.contentService.reorderLessons(module.module_id, lessonIds).subscribe();
          },
          error: (err) => {
            this.error_notification('Failed to move lesson');
            this.refreshModules();
          }
        });
    }
  }

  getConnectedLists(): string[] {
    return this.modules.map(m => `lesson-list-${m.module_id}`);
  }

  // ==========================================
  // FORM HELPERS
  // ==========================================

  private getEmptyModuleForm(): ModuleForm {
    return {
      title: '',
      description: '',
      is_active: true
    };
  }

  private getEmptyLessonForm(moduleId?: number): LessonForm {
    return {
      module_id: moduleId || 0,
      title: '',
      description: '',
      content_type: 'video',
      content_url: '',
      content_text: '',
      duration_minutes: 0,
      is_mandatory: true,
      is_active: true,
      pass_percentage: 70
    };
  }

  private getEmptyAssessmentForm(moduleId?: number): AssessmentForm {
    return {
      module_id: moduleId,
      title: '',
      description: '',
      assessment_type: 'quiz',
      time_limit_minutes: 30,
      pass_percentage: 70,
      max_attempts: 3,
      shuffle_questions: true,
      show_correct_answers: true,
      is_active: true
    };
  }

  private getEmptyQuestionForm(): QuestionForm {
    return {
      question_text: '',
      question_type: 'single_choice',
      points: 1,
      explanation: '',
      is_active: true,
      options: [
        { option_text: '', is_correct: false, feedback: '' },
        { option_text: '', is_correct: false, feedback: '' }
      ]
    };
  }

  onQuestionTypeChange(type: QuestionType): void {
    this.currentQuestion.question_type = type;

    // Set default options based on type
    if (type === 'true_false') {
      this.currentQuestion.options = [
        { option_text: 'True', is_correct: true, feedback: '' },
        { option_text: 'False', is_correct: false, feedback: '' }
      ];
    } else if (['single_choice', 'multiple_choice'].includes(type)) {
      // Keep existing options or create defaults
      if (!this.currentQuestion.options || this.currentQuestion.options.length < 2) {
        this.currentQuestion.options = this.getDefaultOptions();
      }
    } else {
      // For essay, short_answer, fill_blank - no options needed
      this.currentQuestion.options = [];
    }
  }

  // ==========================================
  // QUESTION OPTIONS HELPERS
  // ==========================================

  addOption(): void {
    this.currentQuestion.options.push({
      option_text: '',
      is_correct: false,
      feedback: ''
    });
  }

  removeOption(index: number): void {
    if (this.currentQuestion.options.length > 2) {
      this.currentQuestion.options.splice(index, 1);
    }
  }

  onCorrectOptionChange(index: number): void {
    // For single choice, uncheck other options
    if (this.currentQuestion.question_type === 'single_choice' ||
      this.currentQuestion.question_type === 'true_false') {
      this.currentQuestion.options.forEach((opt, i) => {
        opt.is_correct = i === index;
      });
    }
  }

  // ==========================================
  // UI HELPERS
  // ==========================================

  getContentTypeIcon(type: LessonContentType): string {
    const found = this.contentTypes.find(t => t.value === type);
    return found ? found.icon : 'bi-file';
  }

  getContentTypeLabel(type: LessonContentType): string {
    const found = this.contentTypes.find(t => t.value === type);
    return found ? found.label : type;
  }

  getAssessmentTypeIcon(type: AssessmentType): string {
    const found = this.assessmentTypes.find(t => t.value === type);
    return found ? found.icon : 'bi-question-circle';
  }

  formatDuration(minutes: number): string {
    if (minutes < 60) return `${minutes}m`;
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;
    return mins > 0 ? `${hours}h ${mins}m` : `${hours}h`;
  }

  private updateStats(): void {
    this.stats = {
      total_modules: this.modules.length,
      total_lessons: this.modules.reduce((sum, m) => sum + (m.lessons?.length || 0), 0),
      total_assessments: this.modules.reduce((sum, m) => sum + (m.assessments?.length || 0), 0),
      total_questions: 0, // Would need to sum from all assessments
      total_duration_minutes: this.modules.reduce((sum, m) =>
        sum + (m.lessons?.reduce((ls, l) => ls + (l.duration_minutes || 0), 0) || 0), 0)
    };
  }

  // ==========================================
  // NOTIFICATIONS
  // ==========================================

  private showSuccess(message: string): void {
    this.successMessage = message;
    setTimeout(() => this.successMessage = null, 3000);
  }

  private showError(message: string): void {
    this.error = message;
    setTimeout(() => this.error = null, 5000);
  }

  closeAllModals(): void {
    this.showModuleModal = false;
    this.showLessonModal = false;
    this.showAssessmentModal = false;
    this.showQuestionModal = false;
    this.showDeleteConfirm = false;
  }

  // Track by functions
  trackByModuleId(index: number, module: Module): number {
    return module.module_id;
  }

  trackByLessonId(index: number, lesson: Lesson): number {
    return lesson.lesson_id;
  }

  trackByAssessmentId(index: number, assessment: Assessment): number {
    return assessment.assessment_id;
  }

  trackByQuestionId(index: number, question: Question): number {
    return question.question_id;
  }

  trackByOptionIndex(index: number): number {
    return index;
  }

  success_notification(message: string) {
    this.toast.success(message);
  }
  error_notification(message: string) {
    this.toast.error(message);
  }

  goBack() {
    this.router.navigate(['/courses']).then(r => console.log(r));
  }
}
