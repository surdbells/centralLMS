// my-learning/my-learning.component.ts

import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, ParamMap, Router, RouterLink } from '@angular/router';
import { Subject } from 'rxjs';
import { takeUntil, debounceTime, distinctUntilChanged } from 'rxjs/operators';
import {
  MyLearningService,
  LearningStats,
  EnrolledCourse,
  InProgressCourse,
  AvailableCourse,
  CourseDetails,
  Category
} from './my-learning.service';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';
import { MyCompetencyComponent } from '../../my-competency/my-competency.component';
import { MyLearningPlanComponent } from '../../my-learning-plan/my-learning-plan.component';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';

/**
 * The full list of enrolments used to live in a separate 'all-courses' view
 * that a learner only reached through "View all" — the landing view showed at
 * most four in-progress and four completed courses and never a not-started
 * one. The list is the landing view now, so that mode is gone.
 */
type ViewMode = 'dashboard' | 'browse' | 'course-detail';
type StatusFilter = 'all' | 'in_progress' | 'not_started' | 'completed';
type LearningTab = 'learning' | 'plan' | 'competency';

/** In progress first, then the ones still to start, then the finished ones. */
const STATUS_RANK: Record<EnrolledCourse['status'], number> = {
  in_progress: 0,
  not_started: 1,
  completed: 2,
};

@Component({
  selector: 'app-my-learning',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, TranslatePipe, MyCompetencyComponent, MyLearningPlanComponent, SkeletonComponent],
  templateUrl: './my-learning.component.html',
  styleUrls: ['./my-learning.component.scss']
})
export class MyLearningComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();
  private searchSubject$ = new Subject<string>();

  // View state
  viewMode: ViewMode = 'dashboard';
  selectedCourseId: string | null = null;

  /**
   * Which tab is showing, and which have been opened at least once.
   *
   * The tabs used to be Bootstrap's data-attribute tabs, which rely on the
   * theme bundle's JS having bound itself to the DOM. They are plain state now,
   * and a tab's component is only created the first time it is opened — so
   * landing on My Learning no longer fetches the plan and competency data too —
   * then kept (hidden) so switching back does not refetch it.
   */
  activeTab: LearningTab = 'learning';
  visitedTabs = new Set<LearningTab>(['learning']);

  // Data
  stats: LearningStats | null = null;
  /** Every enrolment the learner has, in display order. Nothing is capped. */
  allCourses: EnrolledCourse[] = [];
  /** `allCourses` narrowed by the status chips and the search box. */
  filteredCourses: EnrolledCourse[] = [];
  /** A short "pick up where you left off" strip: the most recently active in-progress courses. */
  continueCourses: EnrolledCourse[] = [];
  counts: Record<StatusFilter, number> = { all: 0, in_progress: 0, not_started: 0, completed: 0 };
  /** Average progress across every enrolment, completed ones counting as 100. */
  overallProgress = 0;

  availableCourses: AvailableCourse[] = [];
  categories: Category[] = [];
  courseDetails: CourseDetails | null = null;

  // Filters
  statusFilter: StatusFilter = 'all';
  /** The browse view's search, which goes to the server. */
  searchQuery = '';
  /**
   * The My courses search. Kept apart from `searchQuery` and applied in the
   * browser: the whole list is already here, and /me/enrollments ignores
   * `search` anyway, so a round trip per keystroke would change nothing.
   */
  courseSearch = '';
  selectedCategoryId: string | null = null;

  readonly statusChips: StatusFilter[] = ['all', 'in_progress', 'not_started', 'completed'];

  // UI State
  loadingCourses = false;
  /** False until the first list arrives, so the KPIs show a skeleton rather than a row of zeros. */
  coursesLoaded = false;
  coursesError = false;
  loadingDetails = false;
  processing = false;

  /** Covers that failed to load; those cards fall back to the lettered tile. */
  brokenCovers = new Set<string>();

  // Messages
  successMessage = '';
  errorMessage = '';

  // Expanded modules for course detail view
  expandedModules: Set<string | null> = new Set();

  /**
   * Where "back" leaves the course detail for: Browse when the detail was
   * opened from there (or linked as `?view=browse&course=…`), else My courses.
   */
  private detailReturnTo: 'dashboard' | 'browse' = 'dashboard';
  /** The last `?view=` / `?course=` pair acted on, so a repeat emission is ignored. */
  private appliedQuery = '';

  constructor(
    private learningService: MyLearningService,
    private router: Router,
    private route: ActivatedRoute,
    private i18n: I18nService
  ) {}

  ngOnInit(): void {
    this.loadDashboard();
    this.setupSearch();
    this.followQueryParams();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // INITIALIZATION
  // ==========================================

  private setupSearch(): void {
    // Only the browse view searches on the server; My courses filters locally.
    this.searchSubject$.pipe(
      debounceTime(300),
      distinctUntilChanged(),
      takeUntil(this.destroy$)
    ).subscribe(() => {
      if (this.viewMode === 'browse') {
        this.loadAvailableCourses();
      }
    });
  }

  /**
   * Deep links from the learner home: `?course=ID` opens that course's detail
   * (enrolled or catalogue), `?view=browse` opens Browse. Followed rather than
   * read once, because a link to /learning from /learning reuses this
   * component. With neither, the default My courses view stays as it is.
   */
  private followQueryParams(): void {
    this.route.queryParamMap
      .pipe(takeUntil(this.destroy$))
      .subscribe((params) => this.applyQueryParams(params));
  }

  private applyQueryParams(params: ParamMap): void {
    const courseId = (params.get('course') ?? '').trim();
    const browse = params.get('view') === 'browse';
    const key = `${browse ? 'browse' : ''}|${courseId}`;
    if (key === this.appliedQuery) {
      return;
    }
    this.appliedQuery = key;

    this.selectTab('learning');
    if (courseId) {
      // Browse's lists are loaded too, so "back" has something to show.
      if (browse && this.viewMode !== 'browse') {
        this.showBrowseCourses();
      }
      this.detailReturnTo = browse ? 'browse' : 'dashboard';
      this.viewCourseDetails(courseId, this.detailReturnTo);
    } else if (browse) {
      this.showBrowseCourses();
    } else if (this.viewMode !== 'dashboard') {
      this.resetToDashboard();
    }
  }

  // ==========================================
  // TABS
  // ==========================================

  selectTab(tab: LearningTab): void {
    this.activeTab = tab;
    this.visitedTabs.add(tab);
  }

  // ==========================================
  // DASHBOARD
  // ==========================================

  loadDashboard(): void {
    this.viewMode = 'dashboard';

    // The secondary figures (time, streak, certificates…). The headline counts
    // come from the list itself so they always agree with the cards below.
    this.learningService.getStats()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (stats) => this.stats = stats,
        error: () => this.stats = null
      });

    this.loadEnrolledCourses();
  }

  /**
   * Every enrolment, whatever its state.
   *
   * No status goes to the server: the chips filter what has already arrived,
   * so switching between them is instant and the counts on them stay right.
   */
  loadEnrolledCourses(): void {
    this.loadingCourses = true;
    this.coursesError = false;

    this.learningService.getEnrolledCourses()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (courses) => {
          this.allCourses = [...courses].sort(compareCourses);
          this.summarise();
          this.applyFilters();
          this.loadingCourses = false;
          this.coursesLoaded = true;
        },
        error: () => {
          this.loadingCourses = false;
          this.coursesError = true;
        }
      });
  }

  private summarise(): void {
    const counts: Record<StatusFilter, number> = { all: 0, in_progress: 0, not_started: 0, completed: 0 };
    let progressTotal = 0;

    for (const course of this.allCourses) {
      counts.all++;
      if (course.status in counts) {
        counts[course.status]++;
      }
      progressTotal += course.status === 'completed' ? 100 : clampPercent(course.progress_percentage);
    }

    this.counts = counts;
    this.overallProgress = counts.all > 0 ? Math.round(progressTotal / counts.all) : 0;

    // Only worth a strip when it says something the grid's first row does not.
    // `allCourses` already leads with in-progress courses, so with only a
    // handful the strip would just repeat them.
    this.continueCourses = this.allCourses
      .filter((c) => c.status === 'in_progress' && !!c.last_accessed_at)
      .sort((a, b) => timeOf(b.last_accessed_at) - timeOf(a.last_accessed_at))
      .slice(0, 3);
    if (this.allCourses.length <= 3) {
      this.continueCourses = [];
    }
  }

  applyFilters(): void {
    const needle = this.courseSearch.trim().toLocaleLowerCase();

    this.filteredCourses = this.allCourses.filter((course) => {
      if (this.statusFilter !== 'all' && course.status !== this.statusFilter) {
        return false;
      }
      if (!needle) {
        return true;
      }

      return course.title.toLocaleLowerCase().includes(needle)
        || (course.category_name ?? '').toLocaleLowerCase().includes(needle);
    });
  }

  setStatusFilter(filter: StatusFilter): void {
    this.statusFilter = filter;
    this.applyFilters();
  }

  clearCourseFilters(): void {
    this.statusFilter = 'all';
    this.courseSearch = '';
    this.applyFilters();
  }

  /** Kept for anything that still asks for "all my courses": it is the dashboard now. */
  showAllCourses(): void {
    this.clearCourseFilters();
    if (this.viewMode !== 'dashboard') {
      this.loadDashboard();
    }
  }

  onStatusFilterChange(): void {
    this.applyFilters();
  }

  onSearchChange(): void {
    this.searchSubject$.next(this.searchQuery);
  }

  onCoverError(enrollmentId: string): void {
    this.brokenCovers.add(enrollmentId);
  }

  hasCover(course: { enrollment_id: string; thumbnail_url: string | null }): boolean {
    return !!course.thumbnail_url && !this.brokenCovers.has(course.enrollment_id);
  }

  /** Up to two letters for a course without a usable cover. */
  initials(title: string): string {
    const words = (title || '').trim().split(/\s+/).filter(Boolean);
    if (words.length === 0) return '?';

    return words.slice(0, 2).map((w) => w.charAt(0)).join('').toLocaleUpperCase();
  }

  trackByEnrollment(_index: number, course: EnrolledCourse): string {
    return course.enrollment_id;
  }

  getStatusFilterLabel(filter: StatusFilter): string {
    return filter === 'all' ? this.i18n.t('common.all') : this.getStatusLabel(filter);
  }

  getCtaLabel(course: EnrolledCourse): string {
    if (course.status === 'completed') return this.i18n.t('learning.review');
    if (course.status === 'in_progress') return this.i18n.t('common.continue');

    return this.i18n.t('learning.start_course');
  }

  getCtaIcon(course: EnrolledCourse): string {
    if (course.status === 'completed') return 'bi-arrow-counterclockwise';
    if (course.status === 'in_progress') return 'bi-arrow-right';

    return 'bi-play-fill';
  }

  progressOf(course: EnrolledCourse): number {
    return course.status === 'completed' ? 100 : clampPercent(course.progress_percentage);
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
            this.showSuccess(this.i18n.t('learning.enrolled_in', { title: course.title }));
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
          this.showError(err.error?.message || this.i18n.t('learning.enroll_failed'));
          this.processing = false;
        }
      });
  }

  // ==========================================
  // COURSE DETAIL VIEW
  // ==========================================

  viewCourseDetails(courseId: string, returnTo: 'dashboard' | 'browse' = 'dashboard'): void {
    this.detailReturnTo = returnTo;
    this.viewMode = 'course-detail';
    this.selectedCourseId = courseId;
    this.courseDetails = null;
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
          this.showError(err?.error?.message || err?.message || this.i18n.t('learning.load_details_failed'));
          this.loadingDetails = false;
        }
      });

    // Update last accessed
    this.learningService.updateLastAccessed(courseId)
      .pipe(takeUntil(this.destroy$))
      .subscribe();
  }

  /** Enrols from the detail of a catalogue course, then shows it as enrolled. */
  enrollFromDetails(details: CourseDetails): void {
    this.processing = true;

    this.learningService.selfEnroll(details.course_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.processing = false;
          if (!result.success) {
            this.showError(result.message);
            return;
          }
          this.showSuccess(this.i18n.t('learning.enrolled_in', { title: details.title }));
          this.viewCourseDetails(details.course_id, this.detailReturnTo);
          // The list behind "back" and the figures now include it.
          this.loadEnrolledCourses();
          this.learningService.getStats()
            .pipe(takeUntil(this.destroy$))
            .subscribe({ next: (stats) => this.stats = stats, error: () => undefined });
        },
        error: (err) => {
          this.showError(err?.error?.message || err?.message || this.i18n.t('learning.enroll_failed'));
          this.processing = false;
        }
      });
  }

  toggleModule(moduleId: string | null): void {
    if (this.expandedModules.has(moduleId)) {
      this.expandedModules.delete(moduleId);
    } else {
      this.expandedModules.add(moduleId);
    }
  }

  isModuleExpanded(moduleId: string | null): boolean {
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
            this.showSuccess(this.i18n.t('learning.course_started'));
            this.playCourse(course.course_id);
          } else {
            this.showError(result.message);
          }
          this.processing = false;
        },
        error: (err) => {
          this.showError(err.error?.message || this.i18n.t('learning.start_failed'));
          this.processing = false;
        }
      });
  }

  continueCourse(course: InProgressCourse | EnrolledCourse | CourseDetails): void {
    this.playCourse(course.course_id);
  }

  playCourse(courseId: string): void {
    void this.router.navigate(['/player', courseId]);
  }

  goToLesson(courseId: string, lessonId: string): void {
    // Navigate to course player - it handles lesson navigation internally
    this.playCourse(courseId);
  }

  viewCertificate(certificateId: string): void {
    // The certificate viewer renders any issued certificate (course, path or
    // assessment) from the certificates API; fall back to the list without an id.
    if (!certificateId) {
      void this.router.navigate(['/certificates']);
      return;
    }
    void this.router.navigate(['/learner/assessments/certificates', certificateId]);
  }

  // ==========================================
  // NAVIGATION
  // ==========================================

  goBack(): void {
    const linked = this.route.snapshot.queryParamMap;
    const wasLinked = linked.has('course') || linked.has('view');

    // A detail opened from Browse goes back to Browse.
    if (this.viewMode === 'course-detail' && this.detailReturnTo === 'browse') {
      this.courseDetails = null;
      this.selectedCourseId = null;
      this.detailReturnTo = 'dashboard';
      if (wasLinked) {
        // Drop `course` so a refresh does not reopen the detail; the
        // parameter handler then sees plain `?view=browse`, already shown.
        this.appliedQuery = 'browse|';
        void this.router.navigate([], { relativeTo: this.route, queryParams: { view: 'browse' }, replaceUrl: true });
      }
      this.viewMode = 'browse';
      return;
    }

    if (wasLinked) {
      // The parameter handler sees no parameters and resets to the dashboard.
      void this.router.navigate([], { relativeTo: this.route, queryParams: {}, replaceUrl: true });
      return;
    }
    this.resetToDashboard();
  }

  /**
   * Back to My courses, which is where the full list lives now. It reloads,
   * so a course self-enrolled from Browse appears.
   */
  private resetToDashboard(): void {
    this.courseDetails = null;
    this.selectedCourseId = null;
    this.detailReturnTo = 'dashboard';
    this.searchQuery = '';
    this.loadDashboard();
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

    if (diffDays === 0) return this.i18n.t('learning.today');
    if (diffDays === 1) return this.i18n.t('learning.yesterday');
    if (diffDays < 7) return this.i18n.t('learning.days_ago', { count: diffDays });
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
    if (course.is_overdue) return this.i18n.t('learning.overdue');
    if (course.days_until_due === 0) return this.i18n.t('learning.due_today');
    if (course.days_until_due === 1) return this.i18n.t('learning.due_tomorrow');
    if (course.days_until_due !== null && course.days_until_due <= 7) {
      return this.i18n.t('learning.due_in_days', { days: course.days_until_due });
    }
    return this.i18n.t('learning.due_on', { date: this.formatDate(course.due_date) });
  }

  getStatusLabel(status: string): string {
    const labels: Record<string, string> = {
      'not_started': this.i18n.t('learning.not_started'),
      'in_progress': this.i18n.t('learning.in_progress'),
      'completed': this.i18n.t('learning.completed')
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
      'beginner': this.i18n.t('learning.beginner'),
      'intermediate': this.i18n.t('learning.intermediate'),
      'advanced': this.i18n.t('learning.advanced')
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

// ---------------------------------------------------------------------------
// Ordering
// ---------------------------------------------------------------------------

function timeOf(value: string | null): number {
  const t = value ? Date.parse(value) : NaN;

  return Number.isNaN(t) ? 0 : t;
}

function clampPercent(value: number): number {
  const n = Number(value) || 0;

  return Math.max(0, Math.min(100, n));
}

/**
 * In progress, then not started, then completed. Within the first two, what
 * is overdue or due soonest leads — an undated course waits behind a dated
 * one, as it would on any to-do list — then the most recently touched, then
 * the newest enrolment; completed courses are newest-finished first.
 */
function compareCourses(a: EnrolledCourse, b: EnrolledCourse): number {
  const byStatus = (STATUS_RANK[a.status] ?? 3) - (STATUS_RANK[b.status] ?? 3);
  if (byStatus !== 0) return byStatus;

  if (a.status === 'completed') {
    return timeOf(b.completed_at) - timeOf(a.completed_at);
  }

  if (a.is_overdue !== b.is_overdue) return a.is_overdue ? -1 : 1;

  const dueA = a.due_date ? timeOf(a.due_date) : Number.POSITIVE_INFINITY;
  const dueB = b.due_date ? timeOf(b.due_date) : Number.POSITIVE_INFINITY;
  if (dueA !== dueB) return dueA < dueB ? -1 : 1;

  const active = timeOf(b.last_accessed_at) - timeOf(a.last_accessed_at);
  if (active !== 0) return active;

  return timeOf(b.enrolled_at) - timeOf(a.enrolled_at);
}
