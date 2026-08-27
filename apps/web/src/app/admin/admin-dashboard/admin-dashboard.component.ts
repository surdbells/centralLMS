import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { TuiIcon } from '@taiga-ui/core';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { DashboardsService, type AdminDashboard } from '../../services/dashboards.service';
import { ApiClient, ApiError } from '../../core/api';

/* The shapes this screen's template already reads. Each is filled in one
 * place — `adapt()` below — so the markup keeps the names it was written
 * against and nothing else has to know the API speaks differently. */

export interface Overview {
  total_users: number;
  active_learners: number;
  total_courses: number;
  published_courses: number;
  total_enrollments: number;
  total_completions: number;
  in_progress_count: number;
  completion_rate: number;
  total_certificates: number;
  total_learning_hours: number;
  /** Null when the previous window was empty — see `growth` below. */
  enrollment_growth: number | null;
  completion_growth: number | null;
}

export interface TrendPoint {
  month: string;
  value: number;
  enrollments: number;
  completions: number;
}

export interface StatusCount {
  status: string;
  count: number;
}

export interface TopCourse {
  title: string;
  enrollments: number;
  completion_rate: number;
}

export interface DepartmentStat {
  department: string;
  total_users: number;
  total_enrollments: number;
  completions: number;
  completion_rate: number;
}

export interface AtRiskLearner {
  full_name: string;
  department: string;
  avg_progress: number;
  days_inactive: number;
  risk_level: string;
}

export interface UpcomingDeadline {
  full_name: string;
  course_title: string;
  days_remaining: number;
  progress: number;
  urgency: string;
}

export interface RecentCompletion {
  full_name: string;
  course_title: string;
  completed_at: string | null;
  final_score: number | null;
  has_certificate: boolean;
}

export interface ActivityEntry {
  icon: string;
  message: string;
  date: string;
  color: string;
}

export interface Compliance {
  compliance_rate: number;
  compliant: number;
  overdue_count: number;
  expiring_certificates: number;
}

export interface DashboardData {
  overview: Overview;
  trends: { enrollment_trend: TrendPoint[]; daily_trend: TrendPoint[] };
  learners: { by_status: StatusCount[] };
  top_courses: TopCourse[];
  department_stats: DepartmentStat[];
  at_risk_learners: AtRiskLearner[];
  upcoming_deadlines: UpcomingDeadline[];
  recent_completions: RecentCompletion[];
  recent_activity: ActivityEntry[];
  compliance: Compliance;
}

/**
 * What an administrator sees first.
 *
 * **It was blank.** The screen posted to `user/admin/dashboard` on the legacy
 * host with `token`, `user_id` and `tenant_id` out of the session blob, and
 * nothing answers there — so `dashboard` stayed null and the whole page
 * rendered nothing, with no error to say why.
 *
 * The figures are the same ones the reporting layer produces, which is the
 * point: `AdminDashboard.php` was 842 lines that re-queried almost everything
 * `class/reports/` already answered, and the two disagreed in places.
 *
 * The period control works. It sent `period` to an endpoint that ignored it.
 */
@Component({
  selector: 'app-admin-dashboard',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, RouterLink],
  templateUrl: './admin-dashboard.component.html',
  styleUrls: ['./admin-dashboard.component.css'],
})
export class AdminDashboardComponent implements OnInit, OnDestroy {
  private readonly dashboards = inject(DashboardsService);
  private readonly api = inject(ApiClient);
  private readonly router = inject(Router);
  private readonly destroy$ = new Subject<void>();

  isLoading = true;
  error: string | null = null;
  dashboard: DashboardData | null = null;

  /** One of the four the endpoint accepts. Anything else it rounds to 30. */
  selectedPeriod = '30';

  /**
   * Which series the trend chart draws.
   *
   * Both come from the same window, so the toggle changes what is plotted
   * rather than what was asked for.
   */
  chartView: 'monthly' | 'daily' = 'monthly';

  Math = Math;

  ngOnInit(): void {
    this.load();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  private load(): void {
    this.isLoading = true;
    this.error = null;

    this.dashboards
      .forAdmin(Number(this.selectedPeriod))
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (data) => {
          this.dashboard = this.adapt(data);
          this.isLoading = false;
        },
        error: (failure: ApiError) => {
          this.error = failure.message;
          this.isLoading = false;
        },
      });
  }

  onPeriodChange(): void {
    this.load();
  }

  refreshDashboard(): void {
    this.load();
  }

  // -------------------------------------------------------------------------
  // Translating between the API and what this template reads
  // -------------------------------------------------------------------------

  private adapt(d: AdminDashboard): DashboardData {
    return {
      overview: {
        total_users: d.overview.people,
        active_learners: d.overview.activeLast30,
        total_courses: d.overview.courses,
        published_courses: d.overview.publishedCourses,
        total_enrollments: d.overview.enrolments,
        total_completions: d.overview.completed,
        in_progress_count: d.overview.inProgress,
        completion_rate: d.overview.completionRate,
        total_certificates: d.overview.certificates,
        total_learning_hours: Math.round(d.overview.learningSeconds / 360) / 10,
        enrollment_growth: d.overview.enrolmentGrowth,
        completion_growth: d.overview.completionGrowth,
      },

      trends: {
        // Both charts read the same series. The legacy sent two, computed
        // separately, and they disagreed about what a month contained.
        enrollment_trend: d.trends.enrolments.map((point, i) => ({
          month: point.label,
          value: point.count,
          enrollments: point.count,
          completions: d.trends.completions[i]?.count ?? 0,
        })),
        daily_trend: d.trends.completions.map((point) => ({
          month: point.label,
          value: point.count,
          enrollments: 0,
          completions: point.count,
        })),
      },

      learners: {
        by_status: d.learners.byStatus.map((s) => ({ status: s.status, count: s.people })),
      },

      top_courses: d.topCourses.map((c) => ({
        title: c.course,
        enrollments: c.enrolled,
        completion_rate: Math.round(c.completionRate),
      })),

      department_stats: d.departments.map((unit) => ({
        department: unit.unit,
        total_users: unit.people,
        total_enrollments: unit.enrolled,
        completions: unit.completed,
        completion_rate: Math.round(unit.completionRate),
      })),

      at_risk_learners: d.atRiskLearners.map((l) => ({
        full_name: l.learner ?? '',
        department: l.unit ?? '',
        avg_progress: Math.round(l.progress),
        days_inactive: l.daysInactive,
        risk_level: l.risk,
      })),

      upcoming_deadlines: d.upcomingDeadlines.map((dl) => ({
        full_name: dl.learner ?? '',
        course_title: dl.course,
        days_remaining: dl.daysRemaining,
        progress: Math.round(dl.progress),
        urgency: dl.urgency,
      })),

      recent_completions: d.recentCompletions.map((c) => ({
        full_name: c.learnerName ?? '',
        course_title: c.courseTitle,
        completed_at: c.completedAt,
        final_score: c.finalScore,
        has_certificate: c.hasCertificate,
      })),

      recent_activity: d.recentActivity.map((a) => this.describe(a)),

      compliance: {
        compliance_rate: Math.round(d.compliance.complianceRate),
        compliant: d.compliance.completed,
        overdue_count: d.compliance.overdue,
        // The mandatory work falling due in the next month. The legacy panel
        // called this "expiring certificates" and counted something else.
        expiring_certificates: d.compliance.dueWithin30Days,
      },
    };
  }

  /** One line of the feed, as something a person would say. */
  private describe(entry: {
    kind: string; at: string | null; person: string | null; subject: string | null;
  }): ActivityEntry {
    const person = entry.person ?? 'Someone';
    const subject = entry.subject ?? '';

    // The API's own words for what happened. Mine were guesses, so every
    // line fell through to the default and read "Somebody — Some Course".
    const said: Record<string, { message: string; icon: string; color: string }> = {
      enrolled: { message: `${person} was assigned "${subject}"`, icon: 'user-plus', color: 'primary' },
      completed: { message: `${person} completed "${subject}"`, icon: 'check', color: 'success' },
      certified: { message: `${person} earned a certificate for "${subject}"`, icon: 'award', color: 'warning' },
    };

    const line = said[entry.kind] ?? { message: `${person} — ${subject}`, icon: 'activity', color: 'secondary' };

    return { ...line, date: entry.at ?? '' };
  }

  // -------------------------------------------------------------------------
  // Display
  // -------------------------------------------------------------------------

  navigateTo(route: string): void {
    void this.router.navigate(['/' + route]);
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

  /** A rate. No sign: a completion rate of 100% has not gone up by 100%. */
  formatPercentage(value: number | null): string {
    return value === null ? '—' : `${Math.round(value)}%`;
  }

  /**
   * Growth against the window before this one, which does carry a sign.
   *
   * Null means that earlier window was empty, and the API says so rather
   * than returning 100 — going from no enrolments to three is not growth of
   * one hundred per cent, and drawing it as such is how a dashboard reports
   * a triumph on the day an organisation is created.
   */
  formatGrowth(value: number | null): string {
    return value === null ? '—' : `${value > 0 ? '+' : ''}${value}%`;
  }

  formatStatus(status: string): string {
    return status.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }

  formatRelativeTime(value: string): string {
    if (value === '') {
      return '';
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

  getRiskColor(level: string): string {
    return ({
      high: 'danger',
      medium: 'warning',
      low: 'info',
    } as Record<string, string>)[level.toLowerCase()] ?? 'secondary';
  }

  // --- the charts -----------------------------------------------------------

  getMaxEnrollment(): number {
    return this.peak(this.dashboard?.trends.enrollment_trend ?? []);
  }

  getMaxDaily(): number {
    return this.peak(this.dashboard?.trends.daily_trend ?? []);
  }

  private peak(points: TrendPoint[]): number {
    return points.length === 0 ? 0 : Math.max(...points.map((p) => p.value));
  }

  getBarHeight(value: number, max: number): number {
    if (max === 0) {
      return value > 0 ? 20 : 4;
    }

    return Math.max((value / max) * 100, value > 0 ? 20 : 4);
  }

  /**
   * The donut's arc for one status, as a stroke-dasharray.
   *
   * Keyed by the status rather than by position, because the template
   * draws four fixed slices in a fixed order and the API only returns the
   * statuses that have anybody in them.
   */
  getDonutSegment(status: string): string {
    const circumference = 2 * Math.PI * 40;
    const total = this.statusTotal();
    const share = total === 0 ? 0 : this.countOf(status) / total;

    return `${circumference * share} ${circumference}`;
  }

  /** Where that slice starts, so the next one begins where this one ends. */
  getDonutOffset(status: string): string {
    const circumference = 2 * Math.PI * 40;
    const total = this.statusTotal();

    if (total === 0) {
      return '0';
    }

    // The slices the template draws before this one, in its own order.
    const order = ['completed', 'in_progress', 'not_started', 'dropped'];
    const before = order
      .slice(0, Math.max(0, order.indexOf(status)))
      .reduce((sum, s) => sum + this.countOf(s), 0);

    return String(-circumference * (before / total));
  }

  private countOf(status: string): number {
    return (this.dashboard?.learners.by_status ?? [])
      .find((s) => s.status === status)?.count ?? 0;
  }

  private statusTotal(): number {
    return (this.dashboard?.learners.by_status ?? []).reduce((sum, s) => sum + s.count, 0);
  }
}
