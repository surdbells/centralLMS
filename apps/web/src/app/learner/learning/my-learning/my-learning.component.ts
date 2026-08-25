// my-learning/my-learning.component.ts

import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject } from 'rxjs';
import { takeUntil, debounceTime, distinctUntilChanged } from 'rxjs/operators';
import {
  MyLearningService,
  LearningStats,
  EnrolledCourse,
  InProgressCourse,
  CompletedCourse,
  AvailableCourse,
  CourseDetails,
  Category
} from './my-learning.service';

type ViewMode = 'dashboard' | 'all-courses' | 'browse' | 'course-detail';
type StatusFilter = 'all' | 'in_progress' | 'not_started' | 'completed';

@Component({
  selector: 'app-my-learning',
  standalone: true,
  imports: [CommonModule, FormsModule],
  templateUrl: './my-learning.component.html',
  styleUrls: ['./my-learning.component.scss']
})
export class MyLearningComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();
  private searchSubject$ = new Subject<string>();

  // View state
  viewMode: ViewMode = 'dashboard';
  selectedCourseId: number | null = null;

  // Data
  stats: LearningStats | null = null;
  inProgressCourses: InProgressCourse[] = [];
  completedCourses: CompletedCourse[] = [];
  enrolledCourses: EnrolledCourse[] = [];
  availableCourses: AvailableCourse[] = [];
  categories: Category[] = [];
  courseDetails: CourseDetails | null = null;

  // Filters
  statusFilter: StatusFilter = 'all';
  searchQuery = '';
  selectedCategoryId: number | null = null;

  // UI State
  loading = false;
  loadingCourses = false;
  loadingDetails = false;
  processing = false;

  // Messages
  successMessage = '';
  errorMessage = '';

  // Expanded modules for course detail view
  expandedModules: Set<number | null> = new Set();

  constructor(
    private learningService: MyLearningService,
    private router: Router
  ) {}

  ngOnInit(): void {
    this.loadDashboard();
    this.setupSearch();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // INITIALIZATION
  // ==========================================

  private setupSearch(): void {
    this.searchSubject$.pipe(
      debounceTime(300),
      distinctUntilChanged(),
      takeUntil(this.destroy$)
    ).subscribe(search => {
      if (this.viewMode === 'all-courses') {
        this.loadEnrolledCourses();
      } else if (this.viewMode === 'browse') {
        this.loadAvailableCourses();
      }
    });
  }

  // ==========================================
  // DASHBOARD
  // ==========================================

  loadDashboard(): void {
    this.viewMode = 'dashboard';
    this.loading = true;

    // Load stats
    this.learningService.getStats()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (stats) => {
          this.stats = stats;
          this.loading = false;
        },
        error: () => this.loading = false
      });

    // Load in-progress courses
    this.learningService.getInProgressCourses(4)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (courses) => this.inProgressCourses = courses
      });

    // Load recently completed
    this.learningService.getCompletedCourses(4)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (courses) => this.completedCourses = courses
      });
  }

  // ==========================================
  // ALL COURSES VIEW
  // ==========================================

  showAllCourses(): void {
    this.viewMode = 'all-courses';
    this.statusFilter = 'all';
    this.searchQuery = '';
    this.loadEnrolledCourses();
  }

  loadEnrolledCourses(): void {
    this.loadingCourses = true;
    const status = this.statusFilter === 'all' ? undefined : this.statusFilter;
    const search = this.searchQuery.trim() || undefined;

    this.learningService.getEnrolledCourses(status, search)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (courses) => {
          this.enrolledCourses = courses;
          this.loadingCourses = false;
        },
        error: () => this.loadingCourses = false
      });
  }

  onStatusFilterChange(): void {
    this.loadEnrolledCourses();
  }

  onSearchChange(): void {
    this.searchSubject$.next(this.searchQuery);
  }

  // ==========================================
  // BROWSE COURSES VIEW
  // ==========================================

  showBrowseCourses(): void {
    this.viewMode = 'browse';
    this.searchQuery = '';
    this.selectedCategoryId = null;
    this.loadAvailableCourses();
    this.loadCategories();
  }

  loadAvailableCourses(): void {
    this.loadingCourses = true;
    const search = this.searchQuery.trim() || undefined;

    this.learningService.getAvailableCourses(search, this.selectedCategoryId || undefined)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (courses) => {
          this.availableCourses = courses;
          this.loadingCourses = false;
        },
        error: () => this.loadingCourses = false
      });
  }

  loadCategories(): void {
    this.learningService.getCategories()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (categories) => this.categories = categories
      });
  }

  onCategoryChange(): void {
    this.loadAvailableCourses();
  }

  selfEnroll(course: AvailableCourse): void {
    this.processing = true;

    this.learningService.selfEnroll(course.course_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          if (result.success) {
            this.showSuccess(`Enrolled in "${course.title}"`);
            this.loadAvailableCourses();
            // Update stats
            this.learningService.getStats()
              .pipe(takeUntil(this.destroy$))
              .subscribe(stats => this.stats = stats);
          } else {
            this.showError(result.message);
          }
          this.processing = false;
        },
        error: (err) => {
          this.showError(err.error?.message || 'Failed to enroll');
          this.processing = false;
        }
      });
  }

  // ==========================================
  // COURSE DETAIL VIEW
  // ==========================================

  viewCourseDetails(courseId: number): void {
    this.viewMode = 'course-detail';
    this.selectedCourseId = courseId;
    this.loadingDetails = true;
    this.expandedModules.clear();

    this.learningService.getCourseDetails(courseId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (details) => {
          this.courseDetails = details;
          // Expand first module by default
          if (details.modules.length > 0) {
            this.expandedModules.add(details.modules[0].module_id);
          }
          this.loadingDetails = false;
        },
        error: (err) => {
          this.showError(err.error?.message || 'Failed to load course details');
          this.loadingDetails = false;
        }
      });

    // Update last accessed
    this.learningService.updateLastAccessed(courseId)
      .pipe(takeUntil(this.destroy$))
      .subscribe();
  }

  toggleModule(moduleId: number | null): void {
    if (this.expandedModules.has(moduleId)) {
      this.expandedModules.delete(moduleId);
    } else {
      this.expandedModules.add(moduleId);
    }
  }

  isModuleExpanded(moduleId: number | null): boolean {
    return this.expandedModules.has(moduleId);
  }

  getModuleProgress(module: any): number {
    if (!module.lessons || module.lessons.length === 0) return 0;
    const completed = module.lessons.filter((l: any) => l.is_completed).length;
    return Math.round((completed / module.lessons.length) * 100);
  }

  // ==========================================
  // LEARNING ACTIONS
  // ==========================================

  startCourse(course: EnrolledCourse | InProgressCourse | CourseDetails): void {
    this.processing = true;

    this.learningService.startCourse(course.course_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          if (result.success) {
            this.showSuccess('Course started!');
            this.playCourse(course.course_id);
          } else {
            this.showError(result.message);
          }
          this.processing = false;
        },
        error: (err) => {
          this.showError(err.error?.message || 'Failed to start course');
          this.processing = false;
        }
      });
  }

  continueCourse(course: InProgressCourse | EnrolledCourse | CourseDetails): void {
    this.playCourse(course.course_id);
  }

  playCourse(courseId: number): void {
    this.router.navigate(['/player', courseId]).then(r => console.log(r));
  }

  goToLesson(courseId: number, lessonId: number): void {
    // Navigate to course player - it handles lesson navigation internally
    this.playCourse(courseId);
  }

  viewCertificate(certificateId: number): void {
    // Navigate to certificate view
    console.log(`View certificate: ${certificateId}`);
    // this.router.navigate(['/certificates', certificateId]);
  }

  // ==========================================
  // NAVIGATION
  // ==========================================

  goBack(): void {
    if (this.viewMode === 'course-detail') {
      this.viewMode = 'all-courses';
      this.courseDetails = null;
      this.selectedCourseId = null;
      this.loadEnrolledCourses();
    } else {
      this.loadDashboard();
    }
  }

  // ==========================================
  // HELPERS
  // ==========================================

  formatDuration(minutes: number): string {
    if (!minutes) return '-';
    if (minutes < 60) return `${minutes}m`;
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;
    return mins > 0 ? `${hours}h ${mins}m` : `${hours}h`;
  }

  formatLearningTime(minutes: number): string {
    if (!minutes) return '0h';
    const hours = Math.floor(minutes / 60);
    if (hours < 1) return `${minutes}m`;
    return `${hours}h`;
  }

  formatDate(dateString: string | null): string {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString();
  }

  formatRelativeDate(dateString: string | null): string {
    if (!dateString) return '-';
    const date = new Date(dateString);
    const now = new Date();
    const diffDays = Math.floor((now.getTime() - date.getTime()) / (1000 * 60 * 60 * 24));
    
    if (diffDays === 0) return 'Today';
    if (diffDays === 1) return 'Yesterday';
    if (diffDays < 7) return `${diffDays} days ago`;
    return date.toLocaleDateString();
  }

  getDueDateClass(course: EnrolledCourse | InProgressCourse): string {
    if (!course.due_date) return '';
    if (course.is_overdue) return 'overdue';
    if ('days_until_due' in course && course.days_until_due !== null && course.days_until_due <= 3) {
      return 'due-soon';
    }
    return '';
  }

  getDueDateText(course: EnrolledCourse): string {
    if (!course.due_date) return '';
    if (course.is_overdue) return 'Overdue';
    if (course.days_until_due === 0) return 'Due today';
    if (course.days_until_due === 1) return 'Due tomorrow';
    if (course.days_until_due !== null && course.days_until_due <= 7) {
      return `Due in ${course.days_until_due} days`;
    }
    return `Due ${this.formatDate(course.due_date)}`;
  }

  getStatusLabel(status: string): string {
    const labels: Record<string, string> = {
      'not_started': 'Not Started',
      'in_progress': 'In Progress',
      'completed': 'Completed'
    };
    return labels[status] || status;
  }

  getStatusClass(status: string): string {
    const classes: Record<string, string> = {
      'not_started': 'status-not-started',
      'in_progress': 'status-in-progress',
      'completed': 'status-completed'
    };
    return classes[status] || '';
  }

  getDifficultyLabel(level: string): string {
    const labels: Record<string, string> = {
      'beginner': 'Beginner',
      'intermediate': 'Intermediate',
      'advanced': 'Advanced'
    };
    return labels[level] || level;
  }

  getLessonTypeIcon(type: string): string {
    const icons: Record<string, string> = {
      'video': 'bi-play-circle',
      'audio': 'bi-music-note',
      'document': 'bi-file-text',
      'pdf': 'bi-file-pdf',
      'quiz': 'bi-question-circle',
      'assignment': 'bi-pencil-square',
      'scorm': 'bi-box',
      'interactive': 'bi-hand-index'
    };
    return icons[type] || 'bi-file-earmark';
  }

  // ==========================================
  // MESSAGES
  // ==========================================

  private showSuccess(message: string): void {
    this.successMessage = message;
    this.errorMessage = '';
    setTimeout(() => this.successMessage = '', 4000);
  }

  private showError(message: string): void {
    this.errorMessage = message;
    this.successMessage = '';
  }
}
