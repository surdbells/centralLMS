import { decodeSessionBlob } from '../../core/utils/session-blob';
import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import {GlobalComponent} from '../../global-component';

// Interfaces
interface DashboardOverview {
  total_users: number;
  active_learners: number;
  total_courses: number;
  published_courses: number;
  total_enrollments: number;
  enrollments_this_month: number;
  enrollment_growth: number;
  total_completions: number;
  completions_this_month: number;
  completion_growth: number;
  completion_rate: number;
  total_certificates: number;
  average_score: number;
  total_learning_hours: number;
  in_progress_count: number;
  not_started_count: number;
}

interface TrendItem {
  month: string;
  year: string;
  label: string;
  value: number;
}

interface DailyTrendItem {
  date: string;
  label: string;
  enrollments: number;
  completions: number;
}

interface DashboardTrends {
  enrollment_trend: TrendItem[];
  completion_trend: TrendItem[];
  daily_trend: DailyTrendItem[];
}

interface CourseStats {
  by_category: { category: string; course_count: number; enrollments: number }[];
  by_level: { level: string; count: number }[];
}

interface LearnerStats {
  by_status: { status: string; count: number }[];
  new_users_this_month: number;
}

interface ComplianceStats {
  total_assigned: number;
  compliant: number;
  non_compliant: number;
  compliance_rate: number;
  overdue_count: number;
  expiring_certificates: number;
}

interface AssessmentStats {
  total_attempts: number;
  average_score: number;
  passed: number;
  failed: number;
  pass_rate: number;
}

interface Activity {
  type: string;
  icon: string;
  message: string;
  date: string;
  color: string;
}

interface TopCourse {
  course_id: number;
  title: string;
  level: string;
  enrollments: number;
  completions: number;
  completion_rate: number;
  avg_score: number;
  avg_rating: number;
}

interface AtRiskLearner {
  user_id: number;
  full_name: string;
  email: string;
  avatar_url: string | null;
  department: string;
  courses_assigned: number;
  courses_completed: number;
  avg_progress: number;
  last_activity: string;
  days_inactive: number;
  risk_level: 'low' | 'medium' | 'high';
}

interface RecentCompletion {
  user_id: number;
  full_name: string;
  avatar_url: string | null;
  course_title: string;
  completed_at: string;
  final_score: number;
  has_certificate: boolean;
}

interface UpcomingDeadline {
  user_id: number;
  full_name: string;
  course_title: string;
  due_date: string;
  days_remaining: number;
  progress: number;
  urgency: 'normal' | 'warning' | 'critical';
}

interface DepartmentStat {
  department: string;
  total_users: number;
  total_enrollments: number;
  completions: number;
  completion_rate: number;
  avg_progress: number;
}

interface EngagementStats {
  peak_hours: { hour: number; sessions: number }[];
  peak_days: { day: string; sessions: number }[];
}

interface DashboardData {
  overview: DashboardOverview;
  trends: DashboardTrends;
  courses: CourseStats;
  learners: LearnerStats;
  compliance: ComplianceStats;
  assessments: AssessmentStats;
  recent_activity: Activity[];
  top_courses: TopCourse[];
  at_risk_learners: AtRiskLearner[];
  recent_completions: RecentCompletion[];
  upcoming_deadlines: UpcomingDeadline[];
  department_stats: DepartmentStat[];
  engagement: EngagementStats;
}

interface DashboardResponse {
  code: number;
  status: string;
  data: DashboardData;
  message: string;
}

@Component({
  selector: 'app-admin-dashboard',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon],
  templateUrl: './admin-dashboard.component.html',
  styleUrls: ['./admin-dashboard.component.css']
})
export class AdminDashboardComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();
  private router = inject(Router);

  // Auth data - should come from your auth service
  token: string = '';
  userId: number = 0;
  tenantId: number = 0;
  session_data: any = ""
  user_session = {
    id: 0,
    tenant_id: 0,
    tenant: {
      id: 0,
      name: "",
      status: "",
    },
    token: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    avatar: "",
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

  // Filters
  selectedPeriod: string = '30';
  chartView: 'monthly' | 'daily' = 'monthly';

  // Math helper for template
  Math = Math;

  constructor(private http: HttpClient) {}

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    this.token = this.user_session.token;
    this.userId = this.user_session.id;
    this.tenantId = this.user_session.tenant_id;
    this.loadAuthData();
    this.loadDashboard();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  private loadAuthData(): void {
    // Load from your auth service or localStorage
    // this.token = ...
    // this.userId = ...
    // this.tenantId = ...
  }

  loadDashboard(): void {
    this.isLoading = true;
    this.error = null;

    const request = {
      token: this.token,
      user_id: this.userId,
      tenant_id: this.tenantId,
      period: this.selectedPeriod
    };

    this.http.post<DashboardResponse>(GlobalComponent.adminDashboard, request)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (response) => {
          if (response.status === 'success') {
            this.dashboard = response.data;
          } else {
            this.error = response.message;
          }
          this.isLoading = false;
        },
        error: (err) => {
          this.error = 'Failed to load dashboard';
          this.isLoading = false;
          console.error('Dashboard error:', err);
        }
      });
  }

  refreshDashboard(): void {
    this.loadDashboard();
  }

  onPeriodChange(): void {
    this.loadDashboard();
  }

  // Navigation
  navigateTo(route: string): void {
    this.router.navigate(['/', route]);
  }

  // Formatters
  formatNumber(num: number): string {
    if (num >= 1000000) {
      return (num / 1000000).toFixed(1) + 'M';
    }
    if (num >= 1000) {
      return (num / 1000).toFixed(1) + 'K';
    }
    return num?.toString() || '0';
  }

  formatPercentage(value: number): string {
    return (value?.toFixed(1) || '0') + '%';
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
    if (diffDays < 30) return `${Math.floor(diffDays / 7)}w ago`;
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }

  formatStatus(status: string): string {
    return status?.replace(/_/g, ' ') || status;
  }

  // Chart helpers
  getMaxEnrollment(): number {
    if (!this.dashboard?.trends.enrollment_trend) return 1;
    const max = Math.max(...this.dashboard.trends.enrollment_trend.map(t => t.value));
    return max || 1;
  }

  getMaxDaily(): number {
    if (!this.dashboard?.trends.daily_trend) return 1;
    const maxE = Math.max(...this.dashboard.trends.daily_trend.map(t => t.enrollments));
    const maxC = Math.max(...this.dashboard.trends.daily_trend.map(t => t.completions));
    return Math.max(maxE, maxC) || 1;
  }

  getBarHeight(value: number, max: number): number {
    const maxHeight = 200;
    return max > 0 ? (value / max) * maxHeight : 0;
  }

  // Donut chart helpers
  getDonutSegment(status: string): string {
    if (!this.dashboard?.learners.by_status) return '0 314.16';
    const total = this.dashboard.overview.total_enrollments;
    const statusData = this.dashboard.learners.by_status.find(s => s.status === status);
    const value = statusData?.count || 0;
    const percentage = total > 0 ? (value / total) : 0;
    const circumference = 2 * Math.PI * 50;
    return `${percentage * circumference} ${circumference}`;
  }

  getDonutOffset(status: string): string {
    if (!this.dashboard?.learners.by_status) return '0';
    const total = this.dashboard.overview.total_enrollments;
    const circumference = 2 * Math.PI * 50;
    const statusOrder = ['completed', 'in_progress', 'not_started', 'dropped'];
    const currentIndex = statusOrder.indexOf(status);

    let offset = circumference * 0.25; // Start from top
    for (let i = 0; i < currentIndex; i++) {
      const prevStatus = this.dashboard.learners.by_status.find(s => s.status === statusOrder[i]);
      const prevValue = prevStatus?.count || 0;
      const prevPercentage = total > 0 ? (prevValue / total) : 0;
      offset -= prevPercentage * circumference;
    }
    return offset.toString();
  }

  // Utility helpers
  getInitials(name: string): string {
    if (!name) return '?';
    const parts = name.trim().split(' ');
    if (parts.length === 1) return parts[0].charAt(0).toUpperCase();
    return (parts[0].charAt(0) + parts[parts.length - 1].charAt(0)).toUpperCase();
  }

  getRiskColor(level: string): string {
    switch (level) {
      case 'high': return 'danger';
      case 'medium': return 'warning';
      case 'low': return 'info';
      default: return 'secondary';
    }
  }
}
