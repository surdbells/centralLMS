import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import {Router, RouterLink} from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { GlobalComponent } from '../../global-component';

// ====================================
// INTERFACES
// ====================================
interface UserProfile {
  user_id: number;
  full_name: string;
  first_name: string;
  email: string;
  avatar_url: string | null;
  job_title: string;
  department: string;
  joined_date: string;
}

interface Overview {
  courses_enrolled: number;
  courses_completed: number;
  courses_in_progress: number;
  courses_not_started: number;
  total_learning_hours: number;
  hours_this_week: number;
  average_score: number;
  total_certificates: number;
  total_badges: number;
  total_points: number;
  overall_progress: number;
}

interface WeekActivity {
  date: string;
  day: string;
  active: boolean;
}

interface LearningStreak {
  current_streak: number;
  longest_streak: number;
  is_active: boolean;
  last_activity: string;
  week_activity: WeekActivity[];
}

interface CourseInProgress {
  course_id: number;
  title: string;
  thumbnail: string | null;
  level: string;
  category: string;
  duration_minutes: number;
  progress: number;
  enrolled_at: string;
  last_activity: string;
  due_date: string | null;
  days_until_due: number | null;
}

interface CourseCompleted {
  course_id: number;
  title: string;
  thumbnail: string | null;
  level: string;
  category: string;
  completed_at: string;
  final_score: number;
  has_certificate: boolean;
}

interface MyCourses {
  in_progress: CourseInProgress[];
  not_started: CourseInProgress[];
  completed: CourseCompleted[];
}

interface ProgressBreakdown {
  status: string;
  count: number;
  avg_progress: number;
}

interface MonthlyCompletion {
  month: string;
  year: number;
  completions: number;
}

interface ProgressStats {
  breakdown: ProgressBreakdown[];
  monthly_completions: MonthlyCompletion[];
}

interface Badge {
  badge_id: number;
  name: string;
  description: string;
  icon: string;
  color: string;
  earned_at: string;
}

interface PointsBreakdown {
  activity: string;
  points: number;
}

interface NextBadge {
  badge_id: number;
  name: string;
  description: string;
  icon: string;
  progress: number;
}

interface Achievements {
  badges: Badge[];
  points_breakdown: PointsBreakdown[];
  points_this_month: number;
  next_badge: NextBadge | null;
}

interface Certificate {
  certificate_id: number;
  certificate_number: string;
  course_title: string;
  issued_at: string;
  expiry_date: string | null;
  days_until_expiry: number | null;
  status: 'valid' | 'expiring_soon' | 'expired';
}

interface Certificates {
  recent: Certificate[];
  expiring_soon: number;
}

interface AssessmentAttempt {
  assessment_id: number;
  title: string;
  type: string;
  course_title: string;
  score: number;
  passed: boolean;
  time_taken: number;
  completed_at: string;
  attempt_number: number;
}

interface Assessments {
  recent_attempts: AssessmentAttempt[];
  total_attempts: number;
  average_score: number;
  pass_rate: number;
}

interface MandatoryCourse {
  course_id: number;
  title: string;
  status: string;
  progress: number;
  due_date: string | null;
  days_remaining: number | null;
}

interface Compliance {
  compliance_rate: number;
  completed: number;
  pending: number;
  overdue: number;
  mandatory_courses: MandatoryCourse[];
}

interface LeaderboardLearner {
  user_id: number;
  full_name: string;
  avatar_url: string | null;
  points: number;
  rank: number;
  is_me: boolean;
}

interface Leaderboard {
  my_rank: number | null;
  my_points: number;
  total_learners: number;
  department_rank: number | null;
  top_learners: LeaderboardLearner[];
}

interface Activity {
  type: string;
  icon: string;
  message: string;
  date: string;
  color: string;
}

interface Deadline {
  course_id: number;
  title: string;
  due_date: string;
  days_remaining: number;
  progress: number;
  urgency: 'normal' | 'warning' | 'critical';
}

interface RecommendedCourse {
  course_id: number;
  title: string;
  thumbnail: string | null;
  level: string;
  category: string;
  duration_minutes: number;
  avg_rating: number;
  enrollment_count: number;
}

interface WeeklyProgress {
  date: string;
  day: string;
  minutes: number;
  hours: number;
}

interface SkillProgress {
  skill: string;
  completed: number;
  total: number;
  progress: number;
}

interface DashboardData {
  user: UserProfile;
  overview: Overview;
  learning_streak: LearningStreak;
  courses: MyCourses;
  progress: ProgressStats;
  achievements: Achievements;
  certificates: Certificates;
  assessments: Assessments;
  compliance: Compliance;
  leaderboard: Leaderboard;
  recent_activity: Activity[];
  upcoming_deadlines: Deadline[];
  recommended_courses: RecommendedCourse[];
  weekly_progress: WeeklyProgress[];
  skill_progress: SkillProgress[];
}

interface DashboardResponse {
  code: number;
  status: string;
  data: DashboardData;
  message: string;
}

// Level thresholds for gamification
interface LevelThreshold {
  name: string;
  minPoints: number;
  maxPoints: number;
}

const LEVELS: LevelThreshold[] = [
  { name: 'Beginner', minPoints: 0, maxPoints: 500 },
  { name: 'Learner', minPoints: 500, maxPoints: 1000 },
  { name: 'Progressive', minPoints: 1000, maxPoints: 2000 },
  { name: 'Advanced', minPoints: 2000, maxPoints: 4000 },
  { name: 'Expert', minPoints: 4000, maxPoints: 8000 },
  { name: 'Master', minPoints: 8000, maxPoints: 15000 },
  { name: 'Champion', minPoints: 15000, maxPoints: 30000 },
  { name: 'Legend', minPoints: 30000, maxPoints: Infinity }
];

// ====================================
// COMPONENT
// ====================================
@Component({
  selector: 'app-learner-dashboard',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, RouterLink],
  templateUrl: './learner-dashboard.component.html',
  styleUrls: ['./learner-dashboard.component.css']
})
export class LearnerDashboardComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();
  private router = inject(Router);

  // Auth data
  token: string = '';
  userId: number = 0;
  tenantId: number = 0;
  session_data: any = '';
  user_session = {
    id: 0,
    tenant_id: 0,
    tenant: {
      id: 0,
      name: '',
      status: '',
    },
    token: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    avatar: '',
    is_2fa: false,
    is_active: false,
    account_locked: false,
    is_admin: false,
    is_content: false,
    is_instructor: false,
    is_learner: false,
  };

  // State
  isLoading: boolean = true;
  error: string | null = null;
  dashboard: DashboardData | null = null;

  // Activity view toggle
  activityView: 'week' | 'month' = 'week';

  // Math helper for template
  Math = Math;

  constructor(private http: HttpClient) {}

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    if (this.session_data) {
      this.user_session = JSON.parse(atob(this.session_data));
      this.token = this.user_session.token;
      this.userId = this.user_session.id;
      this.tenantId = this.user_session.tenant_id;
    }
    this.loadDashboard();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ====================================
  // DATA LOADING
  // ====================================
  loadDashboard(): void {
    this.isLoading = true;
    this.error = null;

    const request = {
      token: this.token,
      user_id: this.userId,
      tenant_id: this.tenantId
    };

    this.http.post<DashboardResponse>(GlobalComponent.learnerDashboard, request)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          if (response.status === 'success') {
            this.dashboard = response.data;
          } else {
            this.error = response.message || 'Failed to load dashboard';
          }
          this.isLoading = false;
        },
        error: (err) => {
          this.error = 'Failed to load dashboard. Please try again.';
          this.isLoading = false;
          console.error('Dashboard error:', err);
        }
      });
  }

  // ====================================
  // NAVIGATION
  // ====================================
  navigateTo(route: string): void {
    this.router.navigate(['/' + route]);
  }

  continueCourse(courseId: number): void {
    this.router.navigate(['/course', courseId, 'learn']);
  }

  viewCourse(courseId: number): void {
    this.router.navigate(['/course', courseId]);
  }

  play_course(courseId: number): void {
    this.router.navigate(['/player', courseId]);
  }

  downloadCertificate(certificateId: number): void {
    // Implement certificate download
    // window.open(`${GlobalComponent.downloadCertificate}?id=${certificateId}`, '_blank');
  }

  // ====================================
  // LEVEL SYSTEM
  // ====================================
  getLevelName(points: number): string {
    const level = LEVELS.find(l => points >= l.minPoints && points < l.maxPoints);
    return level?.name || 'Legend';
  }

  getNextLevelTarget(points: number): number {
    const level = LEVELS.find(l => points >= l.minPoints && points < l.maxPoints);
    return level?.maxPoints === Infinity ? points : (level?.maxPoints || 500);
  }

  getLevelProgress(points: number): number {
    const level = LEVELS.find(l => points >= l.minPoints && points < l.maxPoints);
    if (!level || level.maxPoints === Infinity) return 100;
    const range = level.maxPoints - level.minPoints;
    const progress = points - level.minPoints;
    return Math.round((progress / range) * 100);
  }

  getPointsToNextLevel(points: number): number {
    const level = LEVELS.find(l => points >= l.minPoints && points < l.maxPoints);
    if (!level || level.maxPoints === Infinity) return 0;
    return level.maxPoints - points;
  }

  // ====================================
  // DAYS CALCULATION
  // ====================================
  getTotalDaysLearning(): number {
    if (!this.dashboard?.user?.joined_date) return 0;
    const joinDate = new Date(this.dashboard.user.joined_date);
    const today = new Date();
    const diffTime = Math.abs(today.getTime() - joinDate.getTime());
    const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
    return diffDays;
  }

  // ====================================
  // ACTIVITY CHART
  // ====================================
  setActivityView(view: 'week' | 'month'): void {
    this.activityView = view;
  }

  navigateActivity(direction: number): void {
    // Implement week/month navigation
    console.log('Navigate activity:', direction);
  }

  getActivityDateRange(): string {
    if (!this.dashboard?.weekly_progress?.length) return '';
    const dates = this.dashboard.weekly_progress.map(d => new Date(d.date));
    const start = dates[0];
    const end = dates[dates.length - 1];
    const options: Intl.DateTimeFormatOptions = { month: 'short', day: 'numeric' };
    return `${start.toLocaleDateString('en-US', options)} - ${end.toLocaleDateString('en-US', options)}`;
  }

  getActivityHeight(minutes: number): number {
    if (!this.dashboard?.weekly_progress) return 0;
    const maxMinutes = Math.max(...this.dashboard.weekly_progress.map(d => d.minutes));
    if (maxMinutes === 0) return 0;
    return Math.round((minutes / maxMinutes) * 100);
  }

  formatActivityTime(minutes: number): string {
    const hours = Math.floor(minutes / 60);
    const mins = minutes % 60;
    if (hours === 0) return `${mins}m`;
    return `${hours}h ${mins}m`;
  }

  formatActivityDate(dateString: string): string {
    const date = new Date(dateString);
    return date.toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: '2-digit' });
  }

  // ====================================
  // FORMATTERS
  // ====================================
  formatNumber(num: number): string {
    if (num === null || num === undefined) return '0';
    if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
    if (num >= 1000) return (num / 1000).toFixed(1) + 'K';
    return num.toString();
  }

  formatRelativeTime(dateString: string): string {
    if (!dateString) return 'N/A';
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / (1000 * 60));
    const diffHours = Math.floor(diffMs / (1000 * 60 * 60));
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays === 1) return 'Yesterday';
    if (diffDays < 7) return `${diffDays}d ago`;
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }

  formatDate(dateString: string): string {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric'
    });
  }

  // ====================================
  // HELPER METHODS
  // ====================================
  getInitials(name: string): string {
    if (!name) return '?';
    const parts = name.trim().split(' ');
    if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  }

  getLevelClass(level: string): string {
    switch (level?.toLowerCase()) {
      case 'beginner': return 'bg-success';
      case 'intermediate': return 'bg-warning';
      case 'advanced': return 'bg-danger';
      default: return 'bg-secondary';
    }
  }

  getSkillColor(progress: number): string {
    if (progress >= 75) return 'bg-success';
    if (progress >= 50) return 'bg-info';
    if (progress >= 25) return 'bg-warning';
    return 'bg-danger';
  }

  isToday(dateString: string): boolean {
    if (!dateString) return false;
    const today = new Date().toISOString().split('T')[0];
    return dateString === today;
  }

  // ====================================
  // PROGRESS RING CALCULATIONS
  // ====================================
  getProgressCircle(progress: number): string {
    const circumference = 2 * Math.PI * 34;
    return `${circumference} ${circumference}`;
  }

  getProgressOffset(progress: number): string {
    const circumference = 2 * Math.PI * 34;
    const offset = circumference - (progress / 100) * circumference;
    return `${offset}`;
  }

  getComplianceCircle(progress: number): string {
    const circumference = 2 * Math.PI * 42;
    return `${circumference} ${circumference}`;
  }

  getComplianceOffset(progress: number): string {
    const circumference = 2 * Math.PI * 42;
    const offset = circumference - (progress / 100) * circumference;
    return `${offset}`;
  }

  // ====================================
  // WEEKLY CHART
  // ====================================
  getWeekBarHeight(minutes: number): number {
    if (!this.dashboard?.weekly_progress) return 0;
    const maxMinutes = Math.max(...this.dashboard.weekly_progress.map(d => d.minutes));
    if (maxMinutes === 0) return minutes > 0 ? 20 : 4;
    return Math.max((minutes / maxMinutes) * 100, minutes > 0 ? 20 : 4);
  }
}
