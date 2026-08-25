import { decodeSessionBlob } from '../../../core/utils/session-blob';
// learner-report/learner-report.service.ts

import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import {GlobalComponent} from '../../../global-component';

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
  enrollment_id: number;
  course_id: number;
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
  attempt_id: number;
  quiz_id: number;
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
  certificate_id: number;
  certificate_number: string;
  issue_date: string;
  expiry_date: string | null;
  pdf_url: string | null;
  course_title: string;
  course_id: number;
  category_name: string | null;
  status: 'valid' | 'expired' | 'expiring_soon';
}

export interface RecentActivity {
  activity_type: 'lesson' | 'course_completed' | 'certificate' | 'quiz';
  item_id: number;
  item_title: string;
  course_title: string;
  status: string;
  progress_percentage: number;
  activity_at: string;
}

export interface Skill {
  skill_id: number;
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

@Injectable({
  providedIn: 'root'
})
export class LearnerReportService {
  private apiUrl = GlobalComponent.myReport;
  constructor(private http: HttpClient) {}
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
  private getAuthPayload(): any {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    return {
      token: this.user_session.token,
      user_id: this.user_session.id,
      tenant_id: this.user_session.tenant_id,
    };
  }
  // ==========================================
  // OVERVIEW
  // ==========================================

  getOverview(): Observable<LearningOverview> {
    return this.http.post<ApiResponse<LearningOverview>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'overview'
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // COURSE PROGRESS
  // ==========================================

  getCourseProgress(): Observable<CourseProgress[]> {
    return this.http.post<ApiResponse<CourseProgress[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'course-progress'
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // ACTIVITY CHARTS
  // ==========================================

  getDailyActivity(days: number = 30): Observable<DailyActivity[]> {
    return this.http.post<ApiResponse<DailyActivity[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'daily-activity',
      days
    }).pipe(map(res => res.data));
  }

  getWeeklyActivity(weeks: number = 12): Observable<WeeklyActivity[]> {
    return this.http.post<ApiResponse<WeeklyActivity[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'weekly-activity',
      weeks
    }).pipe(map(res => res.data));
  }

  getMonthlyActivity(months: number = 12): Observable<MonthlyActivity[]> {
    return this.http.post<ApiResponse<MonthlyActivity[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'monthly-activity',
      months
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // CATEGORY BREAKDOWN
  // ==========================================

  getCategoryBreakdown(): Observable<CategoryBreakdown[]> {
    return this.http.post<ApiResponse<CategoryBreakdown[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'category-breakdown'
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // QUIZ PERFORMANCE
  // ==========================================

  getQuizPerformance(limit: number = 20): Observable<QuizAttempt[]> {
    return this.http.post<ApiResponse<QuizAttempt[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'quiz-performance',
      limit
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // CERTIFICATES
  // ==========================================

  getCertificates(): Observable<Certificate[]> {
    return this.http.post<ApiResponse<Certificate[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'certificates'
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // RECENT ACTIVITIES
  // ==========================================

  getRecentActivities(limit: number = 20): Observable<RecentActivity[]> {
    return this.http.post<ApiResponse<RecentActivity[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'recent-activities',
      limit
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // SKILLS
  // ==========================================

  getSkills(): Observable<Skill[]> {
    return this.http.post<ApiResponse<Skill[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'skills'
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // FULL REPORT
  // ==========================================

  getFullReport(): Observable<FullReport> {
    return this.http.post<ApiResponse<FullReport>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'full-report'
    }).pipe(map(res => res.data));
  }
}
