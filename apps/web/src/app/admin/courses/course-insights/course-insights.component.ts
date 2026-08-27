import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { POLYMORPHEUS_CONTEXT } from '@taiga-ui/polymorpheus';
import { AdmintopComponent } from '../../../partials/top/admintop/admintop.component';
import type { TuiDialogContext } from '@taiga-ui/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { TuiIcon } from '@taiga-ui/core';
import { Subject, forkJoin, of } from 'rxjs';
import { catchError, takeUntil } from 'rxjs/operators';
import { CoursesService } from '../../../services/courses.service';
import { EnrollmentsService } from '../../../services/enrollments.service';
import { ReportsService, type ReportResult, type ReportRow } from '../../../services/reports.service';
import { AssessmentsService } from '../../../services/assessments.service';
import { ApiError } from '../../../core/api';


/*
 * The rows this screen renders.
 *
 * Typed rather than left as bare report rows, so the template keeps reading
 * `module.completion_rate` and a report that stops producing a column is a
 * compile error here instead of a blank cell nobody notices.
 */

/** One lesson inside a module, as the accordion lists them. */
interface ModuleLesson {
  title: string;
  type: string;
  duration_minutes: number | null;
  /**
   * Per-lesson completion is not reported by this system.
   *
   * `module_performance` answers per module; a per-lesson figure would need
   * `lesson_progress` aggregated, which no report does yet. Zero here draws an
   * empty bar rather than a wrong one.
   */
  completion_rate: number;
  average_time_spent: number;
}

interface ModuleRow {
  module_id: string;
  title: string;
  order_index: number;
  /** The lessons themselves, from the outline. */
  lessons: ModuleLesson[];
  total_lessons: number;
  completion_rate: number;
  average_score: number;
  average_time_spent: number;
  drop_off_rate: number;
}

interface LearnerRow {
  full_name: string;
  email: string;
  department: string;
  avatar_url: string | null;
  rank: number;
  progress: number;
  average_score: number;
  time_spent: number;
  days_inactive: number;
  risk_level: string;
}

interface CompletionRow {
  full_name: string;
  completed_at: string | null;
  final_score: number | null;
  time_to_complete: number | null;
  certificate_id: string | null;
}

interface QuestionRow {
  question_text: string;
  correct_rate: number;
  average_time: number;
  difficulty: string;
  most_selected_wrong: string;
}

interface AssessmentRow {
  title: string;
  type: string;
  total_questions: number;
  total_attempts: number;
  average_score: number;
  pass_rate: number;
  average_time: number;
}

/* The shape this screen's template already reads. */

interface CourseBasicInfo {
  title: string;
  category: string;
  difficulty_level: string;
  estimated_duration: number;
  total_modules: number;
  total_lessons: number;
}

interface CourseOverview {
  total_enrolled: number;
  active_learners: number;
  completed_count: number;
  in_progress_count: number;
  average_score: number;
  average_time_spent: number;
  course_rating: number;
  total_reviews: number;
  total_certificates_issued: number;
}

/** One department's completion, as the table renders it. */
interface DepartmentRow {
  department_name: string;
  enrolled: number;
  completed: number;
  completion_rate: number;
}

/** Where enrolments came from. */
interface SourceRow {
  source: string;
  count: number;
  percentage: number;
}

interface EnrollmentAnalytics {
  growth_rate: number;
  by_department: DepartmentRow[];
  by_source: SourceRow[];
}

interface CompletionAnalytics {
  completion_rate: number;
  completion_funnel: {
    enrolled: number;
    started: number;
    halfway: number;
    almost_done: number;
    completed: number;
  };
}

/**
 * Sessions, peak hours, content interactions.
 *
 * Null in this system, and the panel that shows it is guarded. The legacy read
 * `learning_sessions`, which held three rows across the whole platform;
 * nothing replaced it. Zeros here would read as "nobody engaged" rather than
 * "this was never measured".
 */
interface EngagementAnalytics {
  engagement_score: number;
  total_sessions: number;
  average_session_duration: number;
  average_sessions_per_learner: number;
  content_interactions: Array<{ type: string; count: number; unique_users: number }>;
  peak_hours: Array<{ hour: string; sessions: number; percentage: number }>;
  peak_days: Array<{ day: string; sessions: number; percentage: number }>;
}

interface AssessmentAnalytics {
  total_assessments: number;
  total_attempts: number;
  average_score: number;
  highest_score: number;
  lowest_score: number;
  pass_rate: number;
  score_distribution: Array<{ range: string; count: number; percentage: number }>;
  question_analytics: QuestionRow[];
  assessments: AssessmentRow[];
}

interface LearnerAnalytics {
  top_performers: LearnerRow[];
  at_risk_learners: LearnerRow[];
  recent_completions: CompletionRow[];
  learner_segments: Array<{ segment: string; count: number; percentage: number; average_score: number }>;
}

/** One point on the completion trend. */
interface TrendPoint {
  label: string;
  value: number;
  enrollments: number;
  completions: number;
}

interface CourseInsightsData {
  course: CourseBasicInfo;
  overview: CourseOverview;
  enrollment: EnrollmentAnalytics;
  completion: CompletionAnalytics;
  engagement: EngagementAnalytics | null;
  assessments: AssessmentAnalytics;
  modules: ModuleRow[];
  learners: LearnerAnalytics;
  trends: { data_points: TrendPoint[] };
}

/**
 * How one course is going.
 *
 * The screen is as it was. What fills it is not: there is no course-analytics
 * endpoint and there should not be. The legacy grew one, then grew a second in
 * `class/reporting/` that answered the same questions differently, and nobody
 * could say which was right. Every figure here is either the course's own
 * enrolment stats or a report from the reporting module narrowed to this
 * course — one aggregator, one answer.
 *
 * A report this caller may not run leaves its panel empty rather than showing
 * zeroes: "no struggling learners" and "you may not see who is struggling" are
 * very different, and the second is not the first.
 */
@Component({
  selector: 'app-course-insights',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent],
  templateUrl: './course-insights.component.html',
  styleUrls: ['./course-insights.component.css'],
})
export class CourseInsightsComponent implements OnInit, OnDestroy {
  private readonly coursesService = inject(CoursesService);
  private readonly enrollmentsService = inject(EnrollmentsService);
  private readonly reportsService = inject(ReportsService);
  private readonly assessmentsService = inject(AssessmentsService);
  private readonly route = inject(ActivatedRoute);
  /**
   * Set when this is opened as a dialog rather than reached by its route.
   *
   * `optional` because the route is still there — a link to
   * `/courses/:id/insights` has to keep working — and outside a dialog
   * there is no context to inject.
   */
  private readonly context = inject<TuiDialogContext<void, { courseId: string }> | null>(
    POLYMORPHEUS_CONTEXT,
    { optional: true },
  );
  private readonly destroy$ = new Subject<void>();

  courseId = '';
  courseTitle = '';

  isLoading = true;
  error: string | null = null;
  insights: CourseInsightsData | null = null;

  activeTab: 'overview' | 'learners' | 'content' | 'assessments' | 'leaderboard' = 'overview';

  filter = { date_range: 'last_30_days', learner_status: 'all' };

  /** True when this is in a dialog, which is where the close button lives. */
  get isDialog(): boolean {
    return this.context !== null;
  }

  close(): void {
    this.context?.$implicit.complete();
  }

  ngOnInit(): void {
    this.courseId = this.context?.data.courseId
      ?? this.route.snapshot.paramMap.get('id')
      ?? '';

    if (this.courseId === '') {
      this.error = 'No course was named in the address.';
      this.isLoading = false;

      return;
    }

    this.loadInsights();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  setActiveTab(tab: CourseInsightsComponent['activeTab']): void {
    this.activeTab = tab;
  }

  refreshData(): void {
    this.loadInsights();
  }

  private loadInsights(): void {
    this.isLoading = true;
    this.error = null;

    const criteria = { courseId: this.courseId, from: this.rangeStart() };

    // Every report may fail on its own — one the caller cannot run must not
    // blank the whole screen.
    forkJoin({
      course: this.coursesService.show(this.courseId),
      outline: this.coursesService.outline(this.courseId).pipe(catchError(() => of(null))),
      stats: this.enrollmentsService.stats(this.courseId).pipe(catchError(() => of(null))),
      modules: this.reportsService.run('module_performance', criteria).pipe(catchError(() => of(null))),
      scores: this.reportsService.run('score_distribution', criteria).pipe(catchError(() => of(null))),
      assessments: this.reportsService.run('assessment_results_summary', criteria)
        .pipe(catchError(() => of(null))),
      // Question analysis is per assessment, not per course, and refuses
      // without one. Asked for separately once there is an assessment to ask
      // about, rather than fired on every load to be refused.
      assessmentList: this.assessmentsService
        .list({ courseId: this.courseId, perPage: 1 })
        .pipe(catchError(() => of(null))),
      top: this.reportsService.run('top_performers', criteria).pipe(catchError(() => of(null))),
      struggling: this.reportsService.run('struggling_learners', criteria).pipe(catchError(() => of(null))),
      bySource: this.reportsService.run('enrollment_by_source', criteria).pipe(catchError(() => of(null))),
      byDepartment: this.reportsService.run('completion_by_department', criteria)
        .pipe(catchError(() => of(null))),
      trend: this.reportsService.run('completion_trend', criteria).pipe(catchError(() => of(null))),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (loaded) => {
          const stats = loaded.stats;
          const live = stats === null ? 0 : stats.total - stats.dropped;
          const lessons = loaded.outline?.modules.reduce((n, m) => n + m.lessons.length, 0) ?? 0;

          this.courseTitle = loaded.course.title;

          this.insights = {
            course: {
              title: loaded.course.title,
              category: '',
              difficulty_level: loaded.course.level ?? '-',
              estimated_duration: loaded.course.durationMinutes ?? 0,
              total_modules: loaded.outline?.modules.length ?? 0,
              total_lessons: lessons,
            },
            overview: {
              total_enrolled: stats?.total ?? 0,
              // Anybody who has opened it and not finished. "Active" as a
              // separate idea needs session data this system does not keep.
              active_learners: stats?.inProgress ?? 0,
              completed_count: stats?.completed ?? 0,
              in_progress_count: stats?.inProgress ?? 0,
              average_score: this.total(loaded.assessments, 'averageScore'),
              average_time_spent: 0,
              course_rating: loaded.course.rating.average ?? 0,
              total_reviews: loaded.course.rating.count,
              total_certificates_issued: stats?.completed ?? 0,
            },
            enrollment: {
              growth_rate: 0,
              by_department: (loaded.byDepartment?.rows ?? []).map((row) => ({
                department_name: this.text(row, 'department', 'department_name'),
                enrolled: this.number(row, 'enrolled', 'total'),
                completed: this.number(row, 'completed'),
                completion_rate: this.number(row, 'completionRate', 'completion_rate'),
              })),
              by_source: this.share(loaded.bySource).map((p) => ({
                source: p.label,
                count: p.count,
                percentage: p.percentage,
              })),
            },
            completion: {
              // Measured against the people still on it. Counting those taken
              // off makes a course look worse the more of them there are.
              completion_rate: live === 0 ? 0 : Math.round(((stats?.completed ?? 0) / live) * 100),
              completion_funnel: {
                enrolled: stats?.total ?? 0,
                started: (stats?.inProgress ?? 0) + (stats?.completed ?? 0),
                halfway: 0,
                almost_done: 0,
                completed: stats?.completed ?? 0,
              },
            },
            // Deliberately absent — see EngagementAnalytics.
            engagement: null,
            assessments: {
              total_assessments: loaded.assessments?.rows.length ?? 0,
              total_attempts: this.total(loaded.assessments, 'attempts'),
              average_score: this.total(loaded.assessments, 'averageScore'),
              highest_score: this.total(loaded.assessments, 'highestScore'),
              lowest_score: this.total(loaded.assessments, 'lowestScore'),
              pass_rate: this.total(loaded.assessments, 'passRate'),
              score_distribution: this.share(loaded.scores).map((p) => ({
                range: p.label,
                count: p.count,
                percentage: p.percentage,
              })),
              question_analytics: [],
              assessments: this.asAssessmentRows(loaded.assessments),
            },
            modules: this.asModuleRows(loaded.modules, loaded.outline?.modules ?? []),
            trends: {
              data_points: (loaded.trend?.series ?? []).map((point) => ({
                label: point.label,
                value: point.value,
                // The trend reports completions; enrolments over time is its
                // own report, and drawing one series as the other would be a
                // second bar that means the first.
                enrollments: 0,
                completions: point.value,
              })),
            },
            learners: {
              top_performers: this.asLearnerRows(loaded.top),
              at_risk_learners: this.asLearnerRows(loaded.struggling),
              recent_completions: [],
              // Derived from the enrolment stats rather than a segmentation
              // endpoint: these four are what a status can be.
              learner_segments: stats === null ? [] : this.segments(stats),
            },
          };

          this.isLoading = false;

          // Now that an assessment is known, the per-question breakdown can
          // be asked for.
          const first = loaded.assessmentList?.items[0];

          if (first !== undefined) {
            this.loadQuestionAnalysis(first.id);
          }
        },
        error: (e: ApiError) => {
          this.error = e.message;
          this.isLoading = false;
        },
      });
  }

  private loadQuestionAnalysis(assessmentId: string): void {
    this.reportsService
      .run('question_analysis', { assessmentId, from: this.rangeStart() })
      .pipe(takeUntil(this.destroy$), catchError(() => of(null)))
      .subscribe((report) => {
        if (this.insights !== null) {
          this.insights.assessments.question_analytics = this.asQuestionRows(report);
        }
      });
  }

  /** The window the filter names, as a date the API accepts. */
  private rangeStart(): string | undefined {
    const days = ({
      last_7_days: 7,
      last_30_days: 30,
      last_90_days: 90,
      last_year: 365,
    } as Record<string, number>)[this.filter.date_range];

    if (days === undefined) {
      return undefined;
    }

    const start = new Date();
    start.setDate(start.getDate() - days);

    return start.toISOString().slice(0, 10);
  }

  onFilterChange(): void {
    this.loadInsights();
  }

  // --- exporting -----------------------------------------------------------

  /**
   * The reporting module writes the file.
   *
   * Building a CSV in the browser from what happens to be on screen produces
   * a different answer from the report it claims to be — this asks the one
   * thing that aggregates, with the same filters.
   */
  exportToCSV(): void {
    window.open(
      this.reportsService.exportUrl('enrollment_detail_list', {
        courseId: this.courseId,
        from: this.rangeStart(),
      }),
      '_blank',
    );
  }

  exportToExcel(): void {
    // The API writes CSV, which Excel opens. A second format that had to be
    // kept in step with the first is not worth the drift.
    this.exportToCSV();
  }

  exportToPDF(): void {
    this.exportToCSV();
  }

  /**
   * Nudging somebody who is behind.
   *
   * Not wired: sending is the notifications module's job and needs
   * `notification.send`. The button says so rather than appearing to work.
   */
  sendReminder(_learner: LearnerRow): void {
    this.error = 'Reminders are sent from the notifications screen, which needs permission to send.';
  }

  // --- presentation --------------------------------------------------------

  formatNumber(value: number | null | undefined): string {
    return value === null || value === undefined ? '0' : value.toLocaleString('en-GB');
  }

  formatPercentage(value: number | null | undefined): string {
    return value === null || value === undefined ? '0%' : `${Math.round(value)}%`;
  }

  formatDuration(minutes: number | null | undefined): string {
    if (minutes === null || minutes === undefined || minutes === 0) {
      return '-';
    }

    return minutes < 60 ? `${Math.round(minutes)} min` : `${Math.floor(minutes / 60)}h ${Math.round(minutes % 60)}m`;
  }

  formatRelativeTime(value: string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
      return '-';
    }

    const days = Math.floor((Date.now() - new Date(value).getTime()) / 86_400_000);

    if (days <= 0) {
      return 'Today';
    }

    if (days === 1) {
      return 'Yesterday';
    }

    return days < 30 ? `${days} days ago` : `${Math.floor(days / 30)} months ago`;
  }

  formatInteractionType(type: string): string {
    return type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }

  getInitials(name: string | null | undefined): string {
    if (name === null || name === undefined || name === '') {
      return '?';
    }

    return name.split(' ').filter(Boolean).slice(0, 2).map((part) => part[0].toUpperCase()).join('');
  }

  /** Reads one column out of a report row, whatever it is called. */
  cell(row: ReportRow, ...keys: string[]): string {
    for (const key of keys) {
      const value = row[key];

      if (value !== undefined && value !== null && value !== '') {
        return String(value);
      }
    }

    return '-';
  }

  // --- reading reports ------------------------------------------------------



  /** `Math` is reached for directly in the template. */
  protected readonly Math = Math;

  /** One step of the funnel, against the number who enrolled. */
  getFunnelPercentage(value: number, total: number): number {
    return total === 0 ? 0 : Math.round((value / total) * 100);
  }

  /** The two numbers an SVG progress ring needs. */
  getProgressDashArray(radius = 45): number {
    return 2 * Math.PI * radius;
  }

  getProgressDashOffset(percentage: number, radius = 45): number {
    const circumference = this.getProgressDashArray(radius);

    return circumference - (Math.min(100, Math.max(0, percentage)) / 100) * circumference;
  }

  getLessonIcon(type: string | null | undefined): string {
    return ({
      video: '@tui.video',
      audio: '@tui.music',
      pdf: '@tui.file-text',
      scorm: '@tui.package',
      text: '@tui.file-text',
      html: '@tui.code',
      quiz: '@tui.circle-help',
    } as Record<string, string>)[type ?? ''] ?? '@tui.file';
  }

  getInteractionTuiIcon(type: string): string {
    return ({
      view: '@tui.eye',
      download: '@tui.download',
      note: '@tui.pencil',
      bookmark: '@tui.bookmark',
    } as Record<string, string>)[type] ?? '@tui.activity';
  }

  // --- report rows into the shapes the template renders --------------------

  /**
   * A report answers in whatever its columns are called. These read the
   * columns each report actually produces, and fall back rather than throwing
   * — a report that grows a column should not blank a screen.
   */
  private asModuleRows(
    report: ReportResult | null,
    outline: Array<{ id: string; title: string; lessons: Array<{ title: string; contentType: string; durationMinutes: number | null }> }> = [],
  ): ModuleRow[] {
    const lessonsOf = (id: string): ModuleLesson[] =>
      (outline.find((m) => m.id === id)?.lessons ?? []).map((l) => ({
        title: l.title,
        type: l.contentType,
        duration_minutes: l.durationMinutes,
        completion_rate: 0,
        average_time_spent: 0,
      }));

    return (report?.rows ?? []).map((row, index) => ({
      module_id: this.text(row, 'moduleId', 'module_id'),
      title: this.text(row, 'module', 'title', 'moduleTitle'),
      order_index: index + 1,
      lessons: lessonsOf(this.text(row, 'moduleId', 'module_id')),
      total_lessons: this.number(row, 'lessons', 'lessonCount'),
      completion_rate: this.number(row, 'completionRate', 'completion_rate'),
      average_score: this.number(row, 'averageScore', 'average_score'),
      average_time_spent: this.number(row, 'averageTime', 'average_time_spent'),
      drop_off_rate: this.number(row, 'dropOffRate', 'drop_off_rate'),
    }));
  }

  private asLearnerRows(report: ReportResult | null): LearnerRow[] {
    return (report?.rows ?? []).map((row, index) => ({
      full_name: this.text(row, 'learner', 'fullName', 'name'),
      email: this.text(row, 'email'),
      department: this.text(row, 'department'),
      avatar_url: null,
      rank: index + 1,
      progress: this.number(row, 'progress', 'progressPercentage'),
      average_score: this.number(row, 'score', 'averageScore', 'finalScore'),
      time_spent: this.number(row, 'timeSpent', 'time_spent'),
      days_inactive: this.number(row, 'daysInactive', 'days_inactive'),
      risk_level: this.text(row, 'risk', 'riskLevel') || 'unknown',
    }));
  }

  private asQuestionRows(report: ReportResult | null): QuestionRow[] {
    return (report?.rows ?? []).map((row) => ({
      question_text: this.text(row, 'question', 'questionText'),
      correct_rate: this.number(row, 'correctRate', 'correct_rate'),
      average_time: this.number(row, 'averageTime', 'average_time'),
      difficulty: this.text(row, 'difficulty'),
      most_selected_wrong: this.text(row, 'commonWrongAnswer', 'most_selected_wrong'),
    }));
  }

  private asAssessmentRows(report: ReportResult | null): AssessmentRow[] {
    return (report?.rows ?? []).map((row) => ({
      title: this.text(row, 'assessment', 'title'),
      type: this.text(row, 'kind', 'type'),
      total_questions: this.number(row, 'questions', 'total_questions'),
      total_attempts: this.number(row, 'attempts', 'total_attempts'),
      average_score: this.number(row, 'averageScore', 'average_score'),
      pass_rate: this.number(row, 'passRate', 'pass_rate'),
      average_time: this.number(row, 'averageTime', 'average_time'),
    }));
  }

  private text(row: ReportRow, ...keys: string[]): string {
    for (const key of keys) {
      const value = row[key];

      if (value !== undefined && value !== null && value !== '') {
        return String(value);
      }
    }

    return '';
  }

  private number(row: ReportRow, ...keys: string[]): number {
    for (const key of keys) {
      const value = row[key];

      if (typeof value === 'number') {
        return value;
      }

      if (typeof value === 'string' && value !== '' && !Number.isNaN(Number(value))) {
        return Number(value);
      }
    }

    return 0;
  }

  /**
   * A report's series, with each point's share of the whole.
   *
   * The percentage is worked out here rather than asked of the server: it is
   * arithmetic on numbers already in hand, and a second source for it is a
   * second thing to disagree.
   */
  private share(report: ReportResult | null): Array<{ label: string; count: number; percentage: number }> {
    const series = report?.series ?? [];
    const total = series.reduce((sum, point) => sum + point.value, 0);

    return series.map((point) => ({
      label: point.label,
      count: point.value,
      percentage: total === 0 ? 0 : Math.round((point.value / total) * 100),
    }));
  }

  /**
   * @param stats the course's enrolment stats
   */
  private segments(
    stats: { total: number; notStarted: number; inProgress: number; completed: number; dropped: number },
  ): Array<{ segment: string; count: number; percentage: number; average_score: number }> {
    const share = (n: number): number => (stats.total === 0 ? 0 : Math.round((n / stats.total) * 100));

    return [
      { segment: 'Not started', count: stats.notStarted, percentage: share(stats.notStarted), average_score: 0 },
      { segment: 'In progress', count: stats.inProgress, percentage: share(stats.inProgress), average_score: 0 },
      { segment: 'Completed', count: stats.completed, percentage: share(stats.completed), average_score: 0 },
      { segment: 'Removed', count: stats.dropped, percentage: share(stats.dropped), average_score: 0 },
    ];
  }

  private total(report: ReportResult | null, key: string): number {
    const value = report?.totals?.[key];

    return typeof value === 'number' ? Math.round(value * 10) / 10 : 0;
  }
}
