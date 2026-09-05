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
  /** The viewer's standing, sent only when they fall off the end of the board. */
  viewer: { rank: number; points: number } | null;
}

export interface Achievements {
  points: number;
  rank: Rank | null;
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
  email?: string;
  jobTitle?: string | null;
  department?: string | null;
  avatarUrl?: string | null;
  [key: string]: unknown;
}
