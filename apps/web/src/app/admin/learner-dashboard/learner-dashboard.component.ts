import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { Subject, forkJoin, of } from 'rxjs';
import { catchError, takeUntil } from 'rxjs/operators';
import { TuiIcon } from '@taiga-ui/core';
import {
  DashboardsService,
  type ActivityEntry,
  type LearnerDashboard,
  type Rank,
} from '../../services/dashboards.service';
import { MyProfileService } from '../../services/my-profile.service';
import { SkillsService } from '../../services/skills.service';
import { GamificationService } from '../../services/gamification.service';
import { CertificatesService } from '../../services/certificates.service';
import { ApiClient, ApiError, AuthImgDirective } from '../../core/api';
import { TranslatePipe } from '../../translate.pipe';

/* The shapes this screen's template already reads. Each is filled in one
 * place — `adapt()` below — so the markup keeps the names it was written
 * against and nothing else has to know the API speaks differently. */

export interface UserProfile {
  full_name: string;
  /** The API's URL, made absolute. Not the base64 the legacy inlined. */
  avatar_url: string | null;
  job_title: string;
  department: string;
  joined_date: string | null;
}

export interface Overview {
  courses_enrolled: number;
  courses_completed: number;
  courses_in_progress: number;
  total_learning_hours: number;
  hours_this_week: number;
  total_certificates: number;
  total_badges: number;
  total_points: number;
  overall_progress: number;
}

export interface WeekActivity {
  date: string;
  day: string;
  active: boolean;
}

export interface LearningStreak {
  current_streak: number;
  longest_streak: number;
  is_active: boolean;
  week_activity: WeekActivity[];
}

export interface CourseInProgress {
  course_id: string;
  title: string;
  thumbnail: string | null;
  level: string;
  category: string;
  progress: number;
  days_until_due: number | null;
}

export interface Badge {
  name: string;
  description: string;
  icon: string;
  color: string;
}

export interface NextBadge {
  name: string;
}

export interface Achievements {
  badges: Badge[];
  /** The next rung of the tenant's ladder, not a badge. See {@link levelName}. */
  next_badge: NextBadge | null;
}

export interface Certificate {
  certificate_id: string;
  certificate_number: string;
  course_title: string;
  issued_at: string | null;
  expiry_date: string | null;
  days_until_expiry: number | null;
  status: 'valid' | 'expiring_soon' | 'expired';
}

export interface Certificates {
  recent: Certificate[];
  expiring_soon: number;
}

/** Mandatory courses, and how somebody stands against them. */
export interface Compliance {
  compliance_rate: number;
  completed: number;
  pending: number;
  overdue: number;
}

export interface LeaderboardLearner {
  full_name: string;
  avatar_url: string | null;
  points: number;
  rank: number;
  is_me: boolean;
}

export interface Leaderboard {
  my_rank: number | null;
  total_learners: number;
  top_learners: LeaderboardLearner[];
}

export interface Activity {
  icon: string;
  message: string;
  date: string;
  color: string;
}

export interface Deadline {
  course_id: string;
  title: string;
  days_remaining: number;
  progress: number;
  urgency: 'normal' | 'warning' | 'critical';
}

export interface RecommendedCourse {
  course_id: string;
  title: string;
  thumbnail: string | null;
  level: string;
  category: string;
  duration_minutes: number | null;
  avg_rating: number | null;
}

export interface WeeklyProgress {
  date: string;
  day: string;
  minutes: number;
  hours: number;
}

export interface SkillProgress {
  skill: string;
  completed: number;
  total: number;
  progress: number;
}

export interface DashboardData {
  user: UserProfile;
  overview: Overview;
  learning_streak: LearningStreak;
  courses: { in_progress: CourseInProgress[] };
  achievements: Achievements;
  certificates: Certificates;
  compliance: Compliance | null;
  leaderboard: Leaderboard;
  recent_activity: Activity[];
  upcoming_deadlines: Deadline[];
  recommended_courses: RecommendedCourse[];
  weekly_progress: WeeklyProgress[];
  skill_progress: SkillProgress[];
}

/**
 * A learner's own dashboard.
 *
 * The screen is unchanged. What it asks for is not: one POST to
 * `user/learner/dashboard` carrying `token`, `user_id` and `tenant_id` out of
 * the session blob in `sessionStorage`. That endpoint's `verify_user()`
 * returned 100 unconditionally, so any signed-in account could pass somebody
 * else's id and read their courses, scores, certificates and rank — in any
 * organisation. Nothing here sends an id at all; the server works out who is
 * asking from the token.
 *
 * Three controls above the activity chart now do something. The Week/Month
 * toggle drew the same fourteen days either way, and the arrows either side of
 * it called `console.log`. The window is the caller's to choose now — see
 * `ActivityWindow` on the API.
 *
 * The level ladder is the organisation's. This file carried its own `LEVELS`
 * table — eight rungs from Beginner to Legend, hardcoded — while the API reads
 * a ladder each tenant configures, so an organisation that had set its own saw
 * one set of names here and another everywhere else.
 */
@Component({
  selector: 'app-learner-dashboard',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, RouterLink, AuthImgDirective, TranslatePipe],
  templateUrl: './learner-dashboard.component.html',
  styleUrls: ['./learner-dashboard.component.css'],
})
export class LearnerDashboardComponent implements OnInit, OnDestroy {
  private readonly dashboards = inject(DashboardsService);
  private readonly profiles = inject(MyProfileService);
  private readonly skills = inject(SkillsService);
  private readonly gamification = inject(GamificationService);
  private readonly certificates = inject(CertificatesService);
  private readonly api = inject(ApiClient);
  private readonly router = inject(Router);
  private readonly destroy$ = new Subject<void>();

  isLoading = true;
  error: string | null = null;
  dashboard: DashboardData | null = null;

  activityView: 'week' | 'month' = 'week';

  /** Used by the template's inline arithmetic. */
  Math = Math;

  /**
   * How far back the chart is scrolled, in windows.
   *
   * Zero is the window ending today. The arrows move it, and the API refuses
   * to draw days that have not happened, so forward stops at today.
   */
  private windowsBack = 0;

  /**
   * Where the points place somebody, as the API worked it out.
   *
   * The rungs are the organisation's, read from its own ladder. This file
   * used to carry eight of its own in a `const LEVELS`.
   */
  private level: {
    name: string;
    target: number | null;
    percentToNext: number;
    pointsToNext: number | null;
  } | null = null;

  ngOnInit(): void {
    this.load();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // -------------------------------------------------------------------------
  // Loading
  // -------------------------------------------------------------------------

  loadDashboard(): void {
    this.load();
  }

  private load(): void {
    this.isLoading = true;
    this.error = null;

    forkJoin({
      dashboard: this.dashboards.forMe(this.window()),
      profile: this.profiles.show(),
      // Both are their own screens elsewhere. A panel that cannot be filled is
      // hidden rather than zero-filled, so a refusal here is not an error.
      skills: this.skills.getMySkills().pipe(catchError(() => of(null))),
      leaderboard: this.gamification.leaderboard().pipe(catchError(() => of(null))),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ dashboard, profile, skills, leaderboard }) => {
          this.dashboard = this.adapt(dashboard, profile, skills, leaderboard?.entries ?? []);
          this.isLoading = false;
        },
        error: (failure: ApiError) => {
          this.error = failure.message;
          this.isLoading = false;
        },
      });
  }

  /** Just the chart, when only the window moved. */
  private reloadChart(): void {
    this.dashboards
      .forMe(this.window())
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (fresh) => {
          if (this.dashboard !== null) {
            this.dashboard.weekly_progress = this.chart(fresh);
          }
        },
        error: () => undefined,
      });
  }

  private window(): { days: number; until: string } {
    const days = this.activityView === 'week' ? 7 : 30;
    const until = new Date();
    until.setDate(until.getDate() - this.windowsBack * days);

    return { days, until: this.isoDay(until) };
  }

  // -------------------------------------------------------------------------
  // Translating between the API and what this template reads
  // -------------------------------------------------------------------------

  private adapt(
    d: LearnerDashboard,
    profile: {
      fullName: string; avatarUrl: string | null; createdAt: string | null;
      placement?: { jobTitleName: string | null; departmentName: string | null } | null;
    },
    skills: { held: Array<{ name: string; coursesCompleted: number; coursesAvailable: number;
      percentComplete: number }>; inProgress: Array<{ name: string; coursesCompleted: number;
      coursesAvailable: number; percentComplete: number }> } | null,
    ranked: Rank[],
  ): DashboardData {
    this.level = {
      name: d.achievements.level?.name ?? 'Unranked',
      target: d.achievements.next?.requiredPoints ?? null,
      percentToNext: d.achievements.percentToNext,
      pointsToNext: d.achievements.pointsToNext,
    };

    const mandatory = [
      ...d.courses.inProgress,
      ...d.courses.notStarted,
      ...d.courses.completed,
    ].filter((e) => e.isMandatory);

    return {
      user: {
        full_name: profile.fullName,
        // Base-relative path; AuthImgDirective fetches it with the token.
        avatar_url: profile.avatarUrl,
        job_title: profile.placement?.jobTitleName ?? '',
        department: profile.placement?.departmentName ?? '',
        joined_date: profile.createdAt,
      },

      overview: {
        courses_enrolled: d.overview.enrolled,
        courses_completed: d.overview.completed,
        courses_in_progress: d.overview.inProgress,
        total_learning_hours: Math.round(d.overview.secondsSpent / 360) / 10,
        hours_this_week: this.hoursIn(d, 7),
        total_certificates: d.overview.certificates,
        total_badges: d.overview.badges,
        total_points: d.overview.points,
        overall_progress: d.overview.overallProgress,
      },

      learning_streak: {
        current_streak: d.streak.current,
        longest_streak: d.streak.longest,
        is_active: d.streak.isActive,
        week_activity: d.streak.week,
      },

      courses: {
        in_progress: d.courses.inProgress.map((e) => ({
          course_id: e.courseId,
          title: e.courseTitle ?? '',
          thumbnail: e.courseThumbnailUrl === null ? null : this.api.urlFor(e.courseThumbnailUrl),
          level: e.courseLevel ?? '',
          category: e.courseCategory ?? '',
          progress: Math.round(e.progressPercentage),
          days_until_due: e.daysRemaining,
        })),
      },

      achievements: {
        badges: d.achievements.badges.map((b) => ({
          name: b.name,
          description: b.description ?? '',
          icon: b.icon ?? 'award',
          color: 'primary',
        })),
        // The next rung of the ladder. The panel is headed "Next to unlock",
        // which is what this is; the legacy filled it from a `next_badge` the
        // server never sent, so it was always absent.
        next_badge: d.achievements.next === null ? null : { name: d.achievements.next.name },
      },

      certificates: {
        recent: d.certificates.slice(0, 5).map((c) => ({
          certificate_id: c.id,
          certificate_number: c.number,
          course_title: c.title,
          issued_at: c.issuedAt,
          expiry_date: c.validUntil,
          days_until_expiry: c.daysRemaining,
          status: this.certificateStatus(c.status, c.daysRemaining),
        })),
        expiring_soon: d.certificates.filter(
          (c) => c.daysRemaining !== null && c.daysRemaining >= 0 && c.daysRemaining <= 30,
        ).length,
      },

      // Worked out from the enrolments already in hand, which carry both
      // `isMandatory` and `isOverdue`. The legacy had a `compliance` block
      // assembled by a separate query that counted "pending" as everything
      // not completed, overdue included, so the three figures overlapped.
      compliance: mandatory.length === 0 ? null : {
        compliance_rate: Math.round(
          (100 * mandatory.filter((e) => e.status === 'completed').length) / mandatory.length,
        ),
        completed: mandatory.filter((e) => e.status === 'completed').length,
        pending: mandatory.filter((e) => e.status !== 'completed' && !e.isOverdue).length,
        overdue: mandatory.filter((e) => e.status !== 'completed' && e.isOverdue).length,
      },

      leaderboard: {
        my_rank: d.achievements.rank?.rank ?? null,
        total_learners: ranked.length,
        top_learners: ranked.slice(0, 10).map((r) => ({
          full_name: r.name ?? '',
          avatar_url: r.avatarUrl,
          points: r.points,
          rank: r.rank,
          is_me: r.isViewer,
        })),
      },

      recent_activity: d.recentActivity.map((a) => this.describe(a)),

      upcoming_deadlines: d.deadlines.map((dl) => ({
        course_id: dl.courseId,
        title: dl.courseTitle,
        days_remaining: dl.daysRemaining,
        progress: Math.round(dl.progress),
        urgency: dl.urgency,
      })),

      recommended_courses: d.recommended.map((c) => ({
        course_id: c.courseId,
        title: c.title,
        thumbnail: c.thumbnailUrl === null ? null : this.api.urlFor(c.thumbnailUrl),
        level: c.level ?? '',
        category: c.category,
        duration_minutes: c.durationMinutes,
        avg_rating: c.rating,
      })),

      weekly_progress: this.chart(d),

      // Held and part-held together, so the panel shows what somebody is
      // working towards and not only what they already have.
      skill_progress: skills === null ? [] : [...skills.held, ...skills.inProgress].map((s) => ({
        skill: s.name,
        completed: s.coursesCompleted,
        total: s.coursesAvailable,
        progress: Math.round(s.percentComplete),
      })),
    };
  }

  /** The activity chart, in the shape the lollipops and bars both read. */
  private chart(d: LearnerDashboard): WeeklyProgress[] {
    return d.activity.map((day) => ({
      date: day.date,
      day: new Date(day.date).toLocaleDateString(undefined, { weekday: 'short' }),
      minutes: Math.round(day.seconds / 60),
      hours: Math.round(day.seconds / 360) / 10,
    }));
  }

  /** Hours spent over the last `days` of the window in hand. */
  private hoursIn(d: LearnerDashboard, days: number): number {
    const seconds = d.activity.slice(-days).reduce((total, day) => total + day.seconds, 0);

    return Math.round(seconds / 360) / 10;
  }

  /**
   * A certificate's standing, as the table's three colours.
   *
   * The API says active or expired; "expiring soon" is a reading of the days
   * left, and thirty is the number the register elsewhere uses.
   */
  private certificateStatus(
    status: string,
    daysRemaining: number | null,
  ): 'valid' | 'expiring_soon' | 'expired' {
    if (status === 'expired' || status === 'revoked') {
      return 'expired';
    }

    if (daysRemaining !== null && daysRemaining >= 0 && daysRemaining <= 30) {
      return 'expiring_soon';
    }

    return 'valid';
  }

  /** One line of the feed, as something a person would say. */
  private describe(entry: ActivityEntry): Activity {
    const subject = entry.subject ?? '';

    const said: Record<string, { message: string; icon: string; color: string }> = {
      lesson: { message: `Finished the lesson "${subject}"`, icon: 'check', color: 'success' },
      course: { message: `Completed "${subject}"`, icon: 'graduation-cap', color: 'primary' },
      certificate: { message: `Earned a certificate for "${subject}"`, icon: 'award', color: 'warning' },
      points: {
        message: `Earned ${entry.detail ?? '0'} points for ${subject.replace(/_/g, ' ')}`,
        icon: 'star',
        color: 'info',
      },
    };

    const line = said[entry.kind] ?? { message: subject, icon: 'activity', color: 'secondary' };

    return { ...line, date: entry.at ?? '' };
  }

  // -------------------------------------------------------------------------
  // Navigation
  // -------------------------------------------------------------------------

  navigateTo(route: string): void {
    void this.router.navigate(['/' + route]);
  }

  continueCourse(courseId: string): void {
    void this.router.navigate(['/course', courseId, 'learn']);
  }

  viewCourse(courseId: string): void {
    void this.router.navigate(['/course', courseId]);
  }

  play_course(courseId: string): void {
    void this.router.navigate(['/player', courseId]);
  }

  /**
   * Opens the certificate, laid out and ready to print.
   *
   * It used to be an empty method with the real thing commented out beneath
   * it, so the button did nothing at all. The API returns the certificate as
   * HTML rather than a PDF — building one costs seconds of CPU and belongs
   * behind a queue — and the browser's own print dialogue is where "Save as
   * PDF" lives anyway.
   */
  downloadCertificate(certificateId: string): void {
    this.certificates
      .openForPrinting(certificateId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        error: (failure: ApiError) => alert(failure.message),
      });
  }

  // -------------------------------------------------------------------------
  // The level panel
  // -------------------------------------------------------------------------

  /**
   * These four take the points the template hands them and ignore the number,
   * because the answer is the organisation's ladder and the API has already
   * worked it out. The signatures stay as the markup calls them.
   */
  /**
   * Whether there is a rung above.
   *
   * False both at the top of the ladder and in an organisation that has
   * configured none. In either case the bar has nothing to fill towards,
   * and the panel says the points rather than "110 / 110, 0 to go".
   */
  get hasNextLevel(): boolean {
    return this.level?.target !== null && this.level?.target !== undefined;
  }

  getLevelName(_points: number): string {
    return this.level?.name ?? 'Unranked';
  }

  getNextLevelTarget(points: number): number {
    return this.level?.target ?? points;
  }

  getLevelProgress(_points: number): number {
    return Math.round(this.level?.percentToNext ?? 0);
  }

  getPointsToNextLevel(_points: number): number {
    return this.level?.pointsToNext ?? 0;
  }

  // -------------------------------------------------------------------------
  // The activity chart
  // -------------------------------------------------------------------------

  setActivityView(view: 'week' | 'month'): void {
    if (view === this.activityView) {
      return;
    }

    this.activityView = view;
    this.windowsBack = 0;
    this.reloadChart();
  }

  /**
   * Steps the chart back or forward one window.
   *
   * This logged to the console. The window is a parameter of the request now,
   * so the arrows fetch the days either side of what is drawn.
   */
  navigateActivity(direction: number): void {
    const moved = this.windowsBack - direction;

    // Forward stops at the present. The API clamps too, but stopping here
    // saves a request that would return the same days.
    if (moved < 0) {
      return;
    }

    this.windowsBack = moved;
    this.reloadChart();
  }

  getActivityDateRange(): string {
    const days = this.dashboard?.weekly_progress ?? [];

    if (days.length === 0) {
      return '';
    }

    const shown: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric' };
    const first = new Date(days[0].date).toLocaleDateString(undefined, shown);
    const last = new Date(days[days.length - 1].date).toLocaleDateString(undefined, shown);

    return `${first} - ${last}`;
  }

  getActivityHeight(minutes: number): number {
    const busiest = this.busiestDay();

    return busiest === 0 ? 0 : Math.round((minutes / busiest) * 100);
  }

  getWeekBarHeight(minutes: number): number {
    const busiest = this.busiestDay();

    if (busiest === 0) {
      return minutes > 0 ? 20 : 4;
    }

    return Math.max((minutes / busiest) * 100, minutes > 0 ? 20 : 4);
  }

  private busiestDay(): number {
    const days = this.dashboard?.weekly_progress ?? [];

    return days.length === 0 ? 0 : Math.max(...days.map((d) => d.minutes));
  }

  // -------------------------------------------------------------------------
  // Display
  // -------------------------------------------------------------------------

  getTotalDaysLearning(): number {
    const joined = this.dashboard?.user.joined_date;

    if (joined === null || joined === undefined || joined === '') {
      return 0;
    }

    const since = new Date(joined).getTime();

    return Math.max(0, Math.ceil((Date.now() - since) / 86_400_000));
  }

  formatActivityTime(minutes: number): string {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return hours === 0 ? `${rest}m` : `${hours}h ${rest}m`;
  }

  formatActivityDate(value: string): string {
    return new Date(value).toLocaleDateString(undefined, {
      day: 'numeric',
      month: 'short',
      year: '2-digit',
    });
  }

  formatNumber(value: number | null): string {
    if (value === null) {
      return '0';
    }

    if (value >= 1_000_000) {
      return `${(value / 1_000_000).toFixed(1)}M`;
    }

    return value >= 1000 ? `${(value / 1000).toFixed(1)}K` : String(value);
  }

  formatRelativeTime(value: string): string {
    if (value === '') {
      return 'N/A';
    }

    const then = new Date(value);
    const minutes = Math.floor((Date.now() - then.getTime()) / 60_000);
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);

    if (minutes < 1) {
      return 'Just now';
    }

    if (minutes < 60) {
      return `${minutes}m ago`;
    }

    if (hours < 24) {
      return `${hours}h ago`;
    }

    if (days === 1) {
      return 'Yesterday';
    }

    if (days < 7) {
      return `${days}d ago`;
    }

    return then.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  }

  formatDate(value: string | null): string {
    if (value === null || value === '') {
      return '-';
    }

    return new Date(value).toLocaleDateString(undefined, {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });
  }

  getInitials(name: string): string {
    const parts = name.trim().split(/\s+/).filter((p) => p !== '');

    if (parts.length === 0) {
      return '?';
    }

    if (parts.length === 1) {
      return parts[0].charAt(0).toUpperCase();
    }

    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  }

  /** The three a course can be. The legacy's fourth, `advanced`, is not one. */
  getLevelClass(level: string): string {
    return ({
      beginner: 'bg-success',
      intermediate: 'bg-warning',
      professional: 'bg-danger',
    } as Record<string, string>)[level.toLowerCase()] ?? 'bg-secondary';
  }

  getSkillColor(progress: number): string {
    if (progress >= 75) {
      return 'bg-success';
    }

    if (progress >= 50) {
      return 'bg-info';
    }

    return progress >= 25 ? 'bg-warning' : 'bg-danger';
  }

  isToday(date: string): boolean {
    return date === this.isoDay(new Date());
  }

  /**
   * A date as `Y-m-d` in the reader's own zone.
   *
   * Not `toISOString()`: that converts to UTC first, so anybody east of
   * Greenwich has "today" marked on the wrong square of the streak all
   * evening, and anybody west has it wrong all morning.
   */
  private isoDay(date: Date): string {
    const pad = (n: number): string => String(n).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  }

  // --- the progress rings ---------------------------------------------------

  getProgressCircle(_progress: number): string {
    return this.circle(34);
  }

  getProgressOffset(progress: number): string {
    return this.offset(34, progress);
  }

  getComplianceCircle(_progress: number): string {
    return this.circle(42);
  }

  getComplianceOffset(progress: number): string {
    return this.offset(42, progress);
  }

  private circle(radius: number): string {
    const circumference = 2 * Math.PI * radius;

    return `${circumference} ${circumference}`;
  }

  private offset(radius: number, progress: number): string {
    const circumference = 2 * Math.PI * radius;

    return String(circumference - (progress / 100) * circumference);
  }
}
