/** The API shapes this app reads — a camelCase subset of what @central/web uses. */

export interface LoginPayload {
  accessToken: string;
  refreshToken: string;
  user: {
    id: string;
    tenantId: string;
    email: string;
    firstName: string;
    lastName: string;
    fullName: string;
    status: string;
  };
  permissions: string[];
}

export type EnrollmentStatus = 'not_started' | 'in_progress' | 'completed' | 'dropped';

export interface Enrollment {
  id: string;
  courseId: string;
  courseTitle: string | null;
  courseThumbnailUrl: string | null;
  courseLevel: string | null;
  courseCategory: string | null;
  status: EnrollmentStatus;
  statusLabel: string;
  isMandatory: boolean;
  dueDate: string | null;
  isOverdue: boolean;
  daysRemaining: number | null;
  progressPercentage: number;
  lastLessonId: string | null;
  startedAt: string | null;
  completedAt: string | null;
  lastActivityAt: string | null;
  enrolledAt: string | null;
}

export interface LearnerOverview {
  enrolled: number;
  completed: number;
  inProgress: number;
  notStarted: number;
  overdue: number;
  secondsSpent: number;
  points: number;
  certificates: number;
  badges: number;
  overallProgress: number;
}

export interface StreakDay {
  date: string;
  day: string;
  active: boolean;
}

export interface Streak {
  current: number;
  longest: number;
  isActive: boolean;
  week: StreakDay[];
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

/** A learner's certificate, in full — from GET /me/certificates. */
export interface Certificate {
  id: string;
  number: string;
  verificationCode: string;
  source: string;
  sourceLabel: string;
  title: string;
  score: number | null;
  percentage: number | null;
  grade: string | null;
  status: string;
  statusLabel: string;
  isValid: boolean;
  pdfUrl: string | null;
  issuedAt: string | null;
  validFrom: string | null;
  validUntil: string | null;
  daysRemaining: number | null;
  learnerName: string | null;
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

export interface RecommendedCourse {
  courseId: string;
  title: string;
  category: string;
  level: string | null;
  durationMinutes: number | null;
  thumbnailUrl: string | null;
  enrolments: number;
}

export interface ActivityEntry {
  kind: string;
  at: string | null;
  subject: string | null;
  detail: string | null;
}

export interface Rank {
  rank: number;
  points: number;
}

export interface LeaderboardEntry {
  rank: number;
  userId: string;
  name: string;
  points: number;
  avatarUrl: string | null;
  department: string | null;
  level: { name?: string } | null;
  isViewer: boolean;
}

export interface LeaderboardResult {
  period: string;
  periodLabel: string;
  entries: LeaderboardEntry[];
  /**
   * The viewer's own standing, sent as a full entry (name, avatar, level and
   * all) only when they fall off the end of the board — so it can be pinned
   * to the bottom with their real identity, not a placeholder.
   */
  viewer: LeaderboardEntry | null;
}

export interface Achievements {
  points: number;
  rank: Rank | null;
}

export interface LevelProgress {
  points: number;
  current: string | null;
  next: string | null;
  pointsToNext: number | null;
  percentToNext: number;
  atTop: boolean;
}

export interface PointBreakdown {
  reason: string;
  label: string;
  points: number;
}

export interface GamificationBadge {
  id: string;
  name: string;
  icon?: string | null;
  description?: string | null;
}

/** GET /me/gamification — the learner's points, level, badges and breakdown. */
export interface Gamification {
  points: number;
  level: LevelProgress;
  breakdown: PointBreakdown[];
  badges: GamificationBadge[];
  badgeCount: number;
  rank: { rank: number; points: number; name?: string } | null;
}

/** One entry in the points ledger, from GET /me/gamification/history. */
export interface PointEvent {
  id: string;
  points: number;
  reason: string;
  reasonLabel: string;
  description: string | null;
  subjectType: string | null;
  subjectId: string | null;
  subjectTitle: string | null;
  earnedAt: string | null;
}

export interface LearnerDashboard {
  overview: LearnerOverview;
  streak: Streak;
  courses: {
    inProgress: Enrollment[];
    notStarted: Enrollment[];
    completed: Enrollment[];
  };
  certificates: LearnerCertificate[];
  deadlines: Deadline[];
  recommended: RecommendedCourse[];
  recentActivity: ActivityEntry[];
  achievements?: Achievements;
}

/** A course in the catalogue (camelCase from `/courses/catalogue`). */
export interface CatalogueCourse {
  id: string;
  title: string;
  summary?: string;
  description?: string;
  thumbnailUrl: string | null;
  durationMinutes: number;
  level: string;
  categoryName: string | null;
  enrollmentCount?: number;
}

export interface Lesson {
  lessonId: string;
  title: string;
  lessonType: string;
  durationMinutes: number;
  sortOrder: number;
  isCompleted: boolean;
  progressPercentage: number;
  /** A media reference (media:{id}, a /media/{id}/file URL, or an external link). */
  contentUrl: string | null;
  /** Rich HTML body, for text/html lessons. */
  contentText: string | null;
}

export interface CourseModule {
  moduleId: string;
  title: string;
  description: string | null;
  sortOrder: number;
  lessons: Lesson[];
}

export interface CourseDetails {
  courseId: string;
  enrollmentId: string;
  status: EnrollmentStatus | 'none';
  isEnrolled: boolean;
  progressPercentage: number;
  title: string;
  description: string;
  thumbnailUrl: string | null;
  durationMinutes: number;
  level: string;
  objectives: string | null;
  categoryName: string | null;
  modules: CourseModule[];
}

/** `/me/profile` — read defensively; fields the app doesn't need are ignored. */
export interface Profile {
  id?: string;
  fullName?: string;
  firstName?: string;
  lastName?: string;
  email?: string;
  phone?: string | null;
  locale?: string | null;
  jobTitle?: string | null;
  department?: string | null;
  avatarUrl?: string | null;
  [key: string]: unknown;
}

/** The tenant's rules for a valid password, from `/settings/public`. */
export interface PasswordPolicy {
  minimumLength: number;
  requiresUppercase: boolean;
  requiresLowercase: boolean;
  requiresNumber: boolean;
  requiresSymbol: boolean;
  description?: string;
}
