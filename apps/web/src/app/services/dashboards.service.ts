import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';
import type { Enrollment } from './enrollments.service';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface LearnerOverview {
  enrolled: number;
  completed: number;
  inProgress: number;
  notStarted: number;
  overdue: number;
  secondsSpent: number;
  averageScore: number | null;
  points: number;
  certificates: number;
  badges: number;
  /** Courses finished out of courses held, not an average of progress bars. */
  overallProgress: number;
}

export interface StreakDay {
  date: string;
  day: string;
  active: boolean;
}

/**
 * How many days running somebody has done something.
 *
 * Worked out from the days they were active, not read from `user_streaks` —
 * that was a stored counter kept up to date by whichever code path remembered
 * to, and it had drifted.
 */
export interface Streak {
  current: number;
  longest: number;
  isActive: boolean;
  lastActiveOn: string | null;
  week: StreakDay[];
}

export interface DayOfActivity {
  date: string;
  seconds: number;
  lessons: number;
}

/** The stretch of days the chart is drawn over. */
export interface ActivityWindow {
  days: number;
  from: string;
  until: string;
}

export interface Level {
  id: string;
  number: number;
  name: string;
  requiredPoints: number;
  iconUrl: string | null;
  rewards: string | null;
}

export interface EarnedBadge {
  id: string;
  name: string;
  description: string | null;
  icon: string | null;
  imageUrl: string | null;
  pointsValue: number;
  earnedAt: string | null;
}

export interface Rank {
  rank: number;
  userId: string;
  name: string | null;
  points: number;
  avatarUrl: string | null;
  department: string | null;
  level: string | null;
  isViewer: boolean;
}

/**
 * Points, and where they place somebody on the tenant's ladder.
 *
 * The ladder is configured per organisation. The screen used to carry its own
 * eight rungs in a `const LEVELS` — so a tenant that had set its own saw one
 * set of names on the dashboard and another everywhere else.
 */
export interface Achievements {
  points: number;
  level: Level | null;
  next: Level | null;
  percentToNext: number;
  pointsToNext: number | null;
  badges: EarnedBadge[];
  rank: Rank | null;
}

export interface LearnerCertificate {
  id: string;
  number: string;
  title: string;
  status: string;
  statusLabel: string;
  isValid: boolean;
  issuedAt: string | null;
  validUntil: string | null;
  daysRemaining: number | null;
}

export interface Deadline {
  enrollmentId: string;
  courseId: string;
  courseTitle: string;
  thumbnailUrl: string | null;
  dueDate: string;
  daysRemaining: number;
  progress: number;
  urgency: 'normal' | 'warning' | 'critical';
}

/** Published, not already taken, most enrolled first. */
export interface RecommendedCourse {
  courseId: string;
  title: string;
  category: string;
  level: string | null;
  durationMinutes: number | null;
  thumbnailUrl: string | null;
  enrolments: number;
  rating: number | null;
  ratingCount: number;
}

export interface ActivityEntry {
  kind: string;
  at: string | null;
  subject: string | null;
  detail: string | null;
}

export interface LearnerDashboard {
  overview: LearnerOverview;
  streak: Streak;
  courses: {
    inProgress: Enrollment[];
    notStarted: Enrollment[];
    completed: Enrollment[];
  };
  activityWindow: ActivityWindow;
  activity: DayOfActivity[];
  achievements: Achievements;
  certificates: LearnerCertificate[];
  deadlines: Deadline[];
  recommended: RecommendedCourse[];
  recentActivity: ActivityEntry[];
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * The two dashboards.
 *
 * The learner one takes no id. `LearnerDashboard.php` took `user_id` and
 * `tenant_id` from the request body and its `verify_user()` returned 100
 * unconditionally, so any signed-in account could ask for anybody's dashboard
 * — their courses, their scores, their certificates — in any organisation.
 */
@Injectable({ providedIn: 'root' })
export class DashboardsService {
  private readonly api = inject(ApiClient);

  /**
   * The caller's own.
   *
   * `window` is what the activity chart's Week/Month toggle and its arrows
   * ask for. Left out, it is the fortnight ending today.
   */
  forMe(window?: { days?: number; until?: string }): Observable<LearnerDashboard> {
    return this.api.get<LearnerDashboard>(Endpoints.dashboards.me, {
      activityDays: window?.days,
      activityUntil: window?.until,
    });
  }
}
