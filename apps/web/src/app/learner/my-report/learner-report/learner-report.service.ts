// learner-report/learner-report.service.ts

import { Injectable, inject } from '@angular/core';
import { Observable, forkJoin, of } from 'rxjs';
import { map } from 'rxjs/operators';
import { ApiClient, Endpoints } from '../../../core/api';
import {
  DashboardsService,
  type ActivityEntry,
  type DayOfActivity,
  type LearnerCertificate,
  type LearnerDashboard,
} from '../../../services/dashboards.service';
import {
  EnrollmentsService,
  type Enrollment as ApiEnrollment,
} from '../../../services/enrollments.service';

// Interfaces
export interface EnrollmentStats {
  total_enrolled: number;
  completed: number;
  in_progress: number;
  not_started: number;
  overdue: number;
  mandatory_total: number;
  mandatory_completed: number;
  completion_rate: number;
  mandatory_compliance_rate: number;
  avg_completion_rate: number;
}

export interface LearningTimeStats {
  total_minutes: number;
  total_hours: number;
  this_week_minutes: number;
  this_month_minutes: number;
  avg_daily_minutes: number;
  active_days_last_30: number;
}

export interface PerformanceStats {
  quizzes_taken: number;
  quizzes_passed: number;
  average_score: number;
  highest_score: number;
  pass_rate: number;
}

export interface AchievementStats {
  certificates_earned: number;
  badges_earned: number;
  total_points: number;
}

export interface LearningOverview {
  enrollment_stats: EnrollmentStats;
  learning_time: LearningTimeStats;
  performance: PerformanceStats;
  achievements: AchievementStats;
  current_streak: number;
  rank: number | null;
}

export interface CourseProgress {
  enrollment_id: string;
  course_id: string;
  status: 'not_started' | 'in_progress' | 'completed';
  progress_percentage: number;
  enrolled_at: string;
  started_at: string | null;
  completed_at: string | null;
  due_date: string | null;
  is_mandatory: boolean;
  title: string;
  duration_minutes: number;
  category_name: string | null;
  total_lessons: number;
  completed_lessons: number;
  time_spent_minutes: number;
  days_to_complete: number | null;
  is_overdue: boolean;
}

export interface DailyActivity {
  date: string;
  lessons_accessed: number;
  lessons_completed: number;
  minutes_spent: number;
}

export interface WeeklyActivity {
  week_start: string;
  lessons_accessed: number;
  lessons_completed: number;
  minutes_spent: number;
  active_days: number;
}

export interface MonthlyActivity {
  month: string;
  month_label: string;
  lessons_accessed: number;
  lessons_completed: number;
  minutes_spent: number;
  hours_spent: number;
  active_days: number;
}

export interface CategoryBreakdown {
  category_name: string;
  total_courses: number;
  completed_courses: number;
  in_progress_courses: number;
  avg_progress: number;
  completion_rate: number;
}

export interface QuizAttempt {
  attempt_id: string;
  quiz_id: string;
  score: number;
  passed: boolean;
  time_taken_seconds: number;
  time_taken_minutes: number;
  attempt_number: number;
  attempted_at: string;
  quiz_title: string;
  passing_score: number;
  course_title: string;
}

export interface Certificate {
  certificate_id: string;
  certificate_number: string;
  issue_date: string;
  expiry_date: string | null;
  pdf_url: string | null;
  course_title: string;
  course_id: string;
  category_name: string | null;
  status: 'valid' | 'expired' | 'expiring_soon';
}

export interface RecentActivity {
  /** The learner feed's kinds. `points` is one; it was not listed. */
  activity_type: 'lesson' | 'course_completed' | 'certificate' | 'quiz' | 'points';
  item_id: string;
  item_title: string;
  course_title: string;
  status: string;
  progress_percentage: number;
  activity_at: string;
}

export interface Skill {
  skill_id: string;
  skill_name: string;
  courses_count: number;
  last_acquired: string;
}

export interface FullReport {
  generated_at: string;
  overview: LearningOverview;
  course_progress: CourseProgress[];
  category_breakdown: CategoryBreakdown[];
  monthly_activity: MonthlyActivity[];
  certificates: Certificate[];
  quiz_performance: QuizAttempt[];
  skills_acquired: Skill[];
}

export interface ApiResponse<T> {
  code: number;
  status: string;
  data: T;
  message: string;
}

/**
 * The learner's own report, over the new API.
 *
 * It posted to one legacy endpoint with `?action=overview|course-progress|
 * quiz-performance|…` and the credentials decoded from
 * `sessionStorage.SESSION`, tenant included.
 *
 * Almost all of it is one request now. The learner dashboard endpoint already
 * assembles the overview, the streak, the activity window, the achievements,
 * the certificates and the recent activity — the same figures this screen was
 * asking for one action at a time, and computed once on the server rather than
 * nine times from nine different angles.
 *
 * The one thing it could not answer was quiz performance: there was no way for
 * a learner to read their own attempts. `/assessments/{id}/attempts` is a
 * marker's queue and needs `assessment.grade`, and a learner looking at their
 * record has neither that permission nor the list of assessments they have sat
 * to ask about one at a time. `/me/attempts` is new and fills that in.
 *
 * Weekly activity is derived from the daily figures rather than fetched. It is
 * the same numbers grouped differently, and asking a server to group them a
 * second way is how two endpoints end up disagreeing about the same week.
 */
@Injectable({
  providedIn: 'root',
})
export class LearnerReportService {
  private readonly api = inject(ApiClient);
  private readonly dashboards = inject(DashboardsService);
  private readonly enrollments = inject(EnrollmentsService);

  // ==========================================
  // OVERVIEW
  // ==========================================

  getOverview(): Observable<LearningOverview> {
    return this.dashboards.forMe().pipe(map(toOverview));
  }

  // ==========================================
  // COURSES
  // ==========================================

  getCourseProgress(): Observable<CourseProgress[]> {
    return this.enrollments.mine().pipe(map((rows) => rows.map(toCourseProgress)));
  }

  /**
   * How somebody is doing per category.
   *
   * Counted from their own enrolments rather than asked for. Every enrolment
   * carries the course's category, so the grouping is already here — and a
   * second server-side count of the same rows is a second answer that can
   * disagree with the list beside it.
   */
  getCategoryBreakdown(): Observable<CategoryBreakdown[]> {
    return this.enrollments.mine().pipe(map(toCategoryBreakdown));
  }

  // ==========================================
  // ACTIVITY
  // ==========================================

  getDailyActivity(days = 30): Observable<DailyActivity[]> {
    return this.dashboards
      .forMe({ days })
      .pipe(map((mine) => mine.activity.map(toDailyActivity)));
  }

  getWeeklyActivity(weeks = 12): Observable<WeeklyActivity[]> {
    return this.dashboards
      .forMe({ days: weeks * 7 })
      .pipe(map((mine) => groupIntoWeeks(mine.activity)));
  }

  getMonthlyActivity(months = 12): Observable<MonthlyActivity[]> {
    // The window is clamped server-side to 92 days, which is the honest limit
    // of what the activity series holds — a year of months cannot be drawn
    // from it, and pretending otherwise fills a chart with zeroes.
    return this.dashboards
      .forMe({ days: Math.min(92, months * 31) })
      .pipe(map((mine) => groupIntoMonths(mine.activity)));
  }

  getRecentActivities(limit = 20): Observable<RecentActivity[]> {
    return this.dashboards
      .forMe()
      .pipe(map((mine) => mine.recentActivity.slice(0, limit).map(toRecentActivity)));
  }

  // ==========================================
  // ASSESSMENTS
  // ==========================================

  getQuizPerformance(limit = 20): Observable<QuizAttempt[]> {
    return this.api
      .get<AttemptPayload[]>(Endpoints.me.attempts, { limit })
      .pipe(map((rows) => rows.map(toQuizAttempt)));
  }

  // ==========================================
  // CERTIFICATES
  // ==========================================

  getCertificates(): Observable<Certificate[]> {
    return this.dashboards
      .forMe()
      .pipe(map((mine) => mine.certificates.map(toCertificate)));
  }

  getSkills(): Observable<Skill[]> {
    // Skills come from competencies, which this screen has no section for.
    // Empty rather than a request that would only be discarded.
    return of([]);
  }

  // ==========================================
  // EVERYTHING AT ONCE
  // ==========================================

  /**
   * The whole report in one go.
   *
   * Three requests rather than nine: the dashboard carries most of it, and
   * the enrolments and the attempts are the two things it does not.
   */
  getFullReport(): Observable<FullReport> {
    return forkJoin({
      mine: this.dashboards.forMe(),
      enrolments: this.enrollments.mine(),
      attempts: this.api.get<AttemptPayload[]>(Endpoints.me.attempts, { limit: 50 }),
    }).pipe(map(({ mine, enrolments, attempts }) => ({
      generated_at: new Date().toISOString(),
      overview: toOverview(mine),
      course_progress: enrolments.map(toCourseProgress),
      category_breakdown: toCategoryBreakdown(enrolments),
      monthly_activity: groupIntoMonths(mine.activity),
      certificates: mine.certificates.map(toCertificate),
      quiz_performance: attempts.map(toQuizAttempt),
      recent_activities: mine.recentActivity.map(toRecentActivity),
      skills_acquired: [],
    })));
  }
}

// ---------------------------------------------------------------------------
// Translation
// ---------------------------------------------------------------------------

interface AttemptPayload extends Record<string, any> {
  id: string;
  assessmentId: string;
  assessmentTitle: string | null;
  percentage: number | null;
  passed: boolean | null;
  timeSpentSeconds: number;
  attemptNumber: number;
  submittedAt: string | null;
}

function toOverview(mine: LearnerDashboard): LearningOverview {
  const o = mine.overview;
  const mandatory = mine.deadlines.length;

  return {
    enrollment_stats: {
      total_enrolled: o.enrolled,
      completed: o.completed,
      in_progress: o.inProgress,
      not_started: o.notStarted,
      overdue: o.overdue,
      mandatory_total: mandatory,
      mandatory_completed: 0,
      completion_rate: o.overallProgress,
      mandatory_compliance_rate: o.overallProgress,
      avg_completion_rate: o.overallProgress,
    },
    learning_time: {
      total_minutes: Math.round(o.secondsSpent / 60),
      total_hours: Math.round(o.secondsSpent / 3600),
      this_week_minutes: minutesOverLast(mine.activity, 7),
      this_month_minutes: minutesOverLast(mine.activity, 30),
      avg_daily_minutes: mine.activity.length === 0
        ? 0
        : Math.round(minutesOverLast(mine.activity, mine.activity.length) / mine.activity.length),
      active_days_last_30: mine.activity.filter((day) => day.seconds > 0).length,
    },
    performance: {
      // The dashboard carries the average; how many were taken and how many
      // passed belong to the attempts, which the caller fetches separately
      // when it wants them.
      quizzes_taken: 0,
      quizzes_passed: 0,
      average_score: o.averageScore ?? 0,
      highest_score: 0,
      pass_rate: 0,
    },
    achievements: {
      certificates_earned: o.certificates,
      badges_earned: o.badges,
      total_points: o.points,
    },
    current_streak: mine.streak.current,
    rank: mine.achievements.rank?.rank ?? null,
  };
}

function toCourseProgress(e: ApiEnrollment): CourseProgress {
  return {
    enrollment_id: e.id,
    course_id: e.courseId,
    status: e.status as CourseProgress['status'],
    progress_percentage: e.progressPercentage,
    enrolled_at: e.enrolledAt ?? '',
    started_at: e.startedAt,
    completed_at: e.completedAt,
    due_date: e.dueDate,
    is_mandatory: e.isMandatory,
    title: e.courseTitle ?? '',
    duration_minutes: 0,
    category_name: e.courseCategory,
    // Lesson counts belong to a course's outline, which a list of enrolments
    // does not carry — and one outline request per row is one request per
    // course on the page.
    total_lessons: 0,
    completed_lessons: 0,
    time_spent_minutes: Math.round(e.timeSpentSeconds / 60),
    days_to_complete: daysBetween(e.startedAt, e.completedAt),
    is_overdue: e.isOverdue,
  };
}

function toCategoryBreakdown(rows: ApiEnrollment[]): CategoryBreakdown[] {
  const byCategory = new Map<string, ApiEnrollment[]>();

  for (const row of rows) {
    const name = row.courseCategory ?? 'Uncategorised';
    byCategory.set(name, [...(byCategory.get(name) ?? []), row]);
  }

  return [...byCategory.entries()].map(([category_name, held]) => {
    const completed = held.filter((e) => e.status === 'completed').length;

    return {
      category_name,
      total_courses: held.length,
      completed_courses: completed,
      in_progress_courses: held.filter((e) => e.status === 'in_progress').length,
      avg_progress: Math.round(
        held.reduce((total, e) => total + e.progressPercentage, 0) / held.length,
      ),
      completion_rate: Math.round((completed / held.length) * 100),
    };
  });
}

function toDailyActivity(day: DayOfActivity): DailyActivity {
  return {
    date: day.date,
    lessons_accessed: day.lessons,
    lessons_completed: day.lessons,
    minutes_spent: Math.round(day.seconds / 60),
  };
}

/**
 * The same days, grouped by the week they fall in.
 *
 * Grouped here rather than fetched: it is the same series the daily chart
 * draws, and a second endpoint counting the same seconds a second way is how
 * two charts on one screen come to disagree about a week.
 */
function groupIntoWeeks(days: DayOfActivity[]): WeeklyActivity[] {
  const weeks = new Map<string, DayOfActivity[]>();

  for (const day of days) {
    const date = new Date(day.date);
    // Monday, so a week reads the way a working week does.
    const offset = (date.getUTCDay() + 6) % 7;
    date.setUTCDate(date.getUTCDate() - offset);
    const key = date.toISOString().slice(0, 10);
    weeks.set(key, [...(weeks.get(key) ?? []), day]);
  }

  return [...weeks.entries()].map(([week_start, inWeek]) => ({
    week_start,
    lessons_accessed: sum(inWeek, (d) => d.lessons),
    lessons_completed: sum(inWeek, (d) => d.lessons),
    minutes_spent: Math.round(sum(inWeek, (d) => d.seconds) / 60),
    active_days: inWeek.filter((d) => d.seconds > 0).length,
  }));
}

function groupIntoMonths(days: DayOfActivity[]): MonthlyActivity[] {
  const months = new Map<string, DayOfActivity[]>();

  for (const day of days) {
    const key = day.date.slice(0, 7);
    months.set(key, [...(months.get(key) ?? []), day]);
  }

  return [...months.entries()].map(([month, inMonth]) => {
    const seconds = sum(inMonth, (d) => d.seconds);

    return {
      month,
      month_label: new Date(`${month}-01T00:00:00Z`).toLocaleDateString(undefined, {
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
      }),
      lessons_accessed: sum(inMonth, (d) => d.lessons),
      lessons_completed: sum(inMonth, (d) => d.lessons),
      minutes_spent: Math.round(seconds / 60),
      hours_spent: Math.round(seconds / 3600),
      active_days: inMonth.filter((d) => d.seconds > 0).length,
    };
  });
}

function toQuizAttempt(attempt: AttemptPayload): QuizAttempt {
  const seconds = Number(attempt.timeSpentSeconds ?? 0);

  return {
    attempt_id: attempt.id,
    quiz_id: attempt.assessmentId,
    score: Number(attempt.percentage ?? 0),
    passed: attempt.passed === true,
    time_taken_seconds: seconds,
    time_taken_minutes: Math.round(seconds / 60),
    attempt_number: Number(attempt.attemptNumber ?? 1),
    attempted_at: attempt.submittedAt ?? '',
    quiz_title: attempt.assessmentTitle ?? '',
    passing_score: Number(attempt['passingScore'] ?? 0),
    course_title: String(attempt['courseTitle'] ?? ''),
  };
}

function toCertificate(certificate: LearnerCertificate): Certificate {
  return {
    certificate_id: certificate.id,
    certificate_number: certificate.number,
    issue_date: certificate.issuedAt ?? '',
    expiry_date: certificate.validUntil,
    pdf_url: null,
    course_title: certificate.title,
    course_id: '',
    category_name: null,
    status: statusOf(certificate),
  };
}

/**
 * The three words this screen has for a certificate's state.
 *
 * "Expiring soon" is a month, which is roughly the notice somebody needs to
 * book and sit a refresher.
 */
function statusOf(certificate: LearnerCertificate): Certificate['status'] {
  if (!certificate.isValid) {
    return 'expired';
  }

  const days = certificate.daysRemaining;

  return days !== null && days <= 30 ? 'expiring_soon' : 'valid';
}

/**
 * One line of the learner's own feed.
 *
 * The four kinds are the learner feed's — `lesson`, `course`,
 * `certificate`, `points`. They are not the administrator dashboard's
 * (`enrolled`, `completed`, `certified`), which is a different query over
 * a whole tenant; using those here makes every line fall through to a
 * default and read as something it is not.
 */
function toRecentActivity(entry: ActivityEntry): RecentActivity {
  const kinds: Record<string, RecentActivity['activity_type']> = {
    lesson: 'lesson',
    course: 'course_completed',
    certificate: 'certificate',
    points: 'points',
  };

  return {
    activity_type: kinds[entry.kind] ?? 'lesson',
    item_id: '',
    // A points event's subject is its reason code — `certificate_earned`,
    // `assessment_passed` — which is a database value, not a sentence.
    item_title: entry.kind === 'points' ? readable(entry.subject) : (entry.subject ?? ''),
    course_title: entry.subject ?? '',
    // Lessons in this feed are the completed ones — the query says so.
    status: entry.kind === 'lesson' ? 'completed' : (entry.detail ?? ''),
    progress_percentage: 0,
    activity_at: entry.at ?? '',
  };
}

/** `certificate_earned` as "Certificate earned". */
function readable(reason: string | null): string {
  if (reason === null || reason === '') {
    return 'Points earned';
  }

  const words = reason.replace(/_/g, ' ');

  return words.charAt(0).toUpperCase() + words.slice(1);
}

// --- small helpers ----------------------------------------------------------

function sum<T>(rows: T[], of_: (row: T) => number): number {
  return rows.reduce((total, row) => total + of_(row), 0);
}

function minutesOverLast(days: DayOfActivity[], count: number): number {
  return Math.round(sum(days.slice(-count), (day) => day.seconds) / 60);
}

function daysBetween(from: string | null, to: string | null): number | null {
  if (from === null || to === null) {
    return null;
  }

  const ms = new Date(to).getTime() - new Date(from).getTime();

  return ms < 0 ? null : Math.round(ms / 86400000);
}
