// ============================================
// COURSE INSIGHTS INTERFACES
// Comprehensive analytics for LMS courses
// ============================================

/**
 * Main Course Insights Response
 */
export interface CourseInsightsResponse {
  code: number;
  status: 'success' | 'failed';
  data: CourseInsightsData;
  message: string;
}

/**
 * Complete Course Insights Data
 */
export interface CourseInsightsData {
  course: CourseBasicInfo;
  overview: CourseOverview;
  enrollment: EnrollmentAnalytics;
  completion: CompletionAnalytics;
  engagement: EngagementAnalytics;
  assessments: AssessmentAnalytics;
  modules: ModuleAnalytics[];
  learners: LearnerAnalytics;
  trends: TrendData;
}

/**
 * Basic Course Information
 */
export interface CourseBasicInfo {
  course_id: number;
  tenant_id: number;
  title: string;
  description: string;
  thumbnail_url: string | null;
  category: string;
  difficulty_level: 'Beginner' | 'Intermediate' | 'Advanced';
  estimated_duration: number; // minutes
  total_modules: number;
  total_lessons: number;
  is_published: boolean;
  created_at: string;
  updated_at: string;
}

/**
 * Course Overview Statistics
 */
export interface CourseOverview {
  total_enrolled: number;
  active_learners: number;
  completed_count: number;
  in_progress_count: number;
  not_started_count: number;
  dropped_count: number;
  average_progress: number;
  average_score: number;
  average_time_spent: number; // minutes
  total_certificates_issued: number;
  course_rating: number;
  total_reviews: number;
}

/**
 * Enrollment Analytics
 */
export interface EnrollmentAnalytics {
  total: number;
  this_week: number;
  this_month: number;
  growth_rate: number; // percentage
  by_source: EnrollmentSource[];
  by_department: DepartmentEnrollment[];
  daily_trend: DailyTrend[];
  monthly_trend: MonthlyTrend[];
}

export interface EnrollmentSource {
  source: string;
  count: number;
  percentage: number;
}

export interface DepartmentEnrollment {
  department_id: number;
  department_name: string;
  enrolled: number;
  completed: number;
  completion_rate: number;
}

export interface DailyTrend {
  date: string;
  enrollments: number;
  completions: number;
}

export interface MonthlyTrend {
  month: string;
  year: number;
  enrollments: number;
  completions: number;
  average_score: number;
}

/**
 * Completion Analytics
 */
export interface CompletionAnalytics {
  total_completed: number;
  completion_rate: number;
  average_completion_time: number; // days
  fastest_completion: number; // days
  slowest_completion: number; // days
  by_time_range: CompletionTimeRange[];
  completion_funnel: CompletionFunnel;
}

export interface CompletionTimeRange {
  range: string; // e.g., "1-7 days", "8-14 days"
  count: number;
  percentage: number;
}

export interface CompletionFunnel {
  enrolled: number;
  started: number;
  halfway: number;
  almost_done: number;
  completed: number;
}

/**
 * Engagement Analytics
 */
export interface EngagementAnalytics {
  total_time_spent: number; // minutes
  average_session_duration: number; // minutes
  total_sessions: number;
  average_sessions_per_learner: number;
  peak_hours: PeakHour[];
  peak_days: PeakDay[];
  content_interactions: ContentInteraction[];
  engagement_score: number; // 0-100
}

export interface PeakHour {
  hour: number;
  sessions: number;
  percentage: number;
}

export interface PeakDay {
  day: string;
  sessions: number;
  percentage: number;
}

export interface ContentInteraction {
  type: 'video_plays' | 'document_views' | 'quiz_attempts' | 'notes_created' | 'discussions';
  count: number;
  unique_users: number;
}

/**
 * Assessment Analytics
 */
export interface AssessmentAnalytics {
  total_assessments: number;
  total_attempts: number;
  average_score: number;
  pass_rate: number;
  highest_score: number;
  lowest_score: number;
  score_distribution: ScoreDistribution[];
  assessments: AssessmentDetail[];
  question_analytics: QuestionAnalytic[];
}

export interface ScoreDistribution {
  range: string; // e.g., "0-20", "21-40"
  count: number;
  percentage: number;
}

export interface AssessmentDetail {
  assessment_id: number;
  title: string;
  type: 'quiz' | 'exam' | 'assignment';
  total_questions: number;
  total_attempts: number;
  average_score: number;
  pass_rate: number;
  average_time: number; // minutes
}

export interface QuestionAnalytic {
  question_id: number;
  question_text: string;
  correct_rate: number;
  average_time: number; // seconds
  difficulty: 'easy' | 'medium' | 'hard';
  most_selected_wrong: string | null;
}

/**
 * Module Analytics
 */
export interface ModuleAnalytics {
  module_id: number;
  title: string;
  order_index: number;
  total_lessons: number;
  completion_rate: number;
  average_time_spent: number;
  average_score: number | null;
  drop_off_rate: number;
  engagement_score: number;
  lessons: LessonAnalytic[];
}

export interface LessonAnalytic {
  lesson_id: number;
  title: string;
  type: 'VIDEO' | 'AUDIO' | 'PDF' | 'SCORM' | 'TEXT' | 'QUIZ';
  completion_rate: number;
  average_time_spent: number;
  replay_rate: number; // for video/audio
  drop_off_point: number | null; // percentage where users stop
}

/**
 * Learner Analytics
 */
export interface LearnerAnalytics {
  top_performers: LearnerPerformance[];
  at_risk_learners: AtRiskLearner[];
  recent_completions: RecentCompletion[];
  learner_segments: LearnerSegment[];
}

export interface LearnerPerformance {
  user_id: number;
  full_name: string;
  email: string;
  avatar_url: string | null;
  department: string;
  progress: number;
  average_score: number;
  time_spent: number;
  completed_at: string | null;
  rank: number;
}

export interface AtRiskLearner {
  user_id: number;
  full_name: string;
  email: string;
  avatar_url: string | null;
  department: string;
  progress: number;
  last_activity: string;
  days_inactive: number;
  risk_level: 'low' | 'medium' | 'high';
  risk_reason: string;
}

export interface RecentCompletion {
  user_id: number;
  full_name: string;
  avatar_url: string | null;
  completed_at: string;
  final_score: number;
  time_to_complete: number; // days
  certificate_id: number | null;
}

export interface LearnerSegment {
  segment: 'completed' | 'in_progress' | 'not_started' | 'dropped';
  count: number;
  percentage: number;
  average_score: number | null;
}

/**
 * Trend Data for Charts
 */
export interface TrendData {
  period: 'daily' | 'weekly' | 'monthly';
  data_points: TrendDataPoint[];
}

export interface TrendDataPoint {
  label: string;
  date: string;
  enrollments: number;
  completions: number;
  active_users: number;
  average_score: number;
  time_spent: number;
}

/**
 * Request Parameters
 */
export interface CourseInsightsRequest {
  token: string;
  user_id: number;
  tenant_id: number;
  course_id: number;
  date_from?: string;
  date_to?: string;
  period?: 'daily' | 'weekly' | 'monthly';
}

/**
 * Filter Options
 */
export interface InsightsFilter {
  date_range: 'last_7_days' | 'last_30_days' | 'last_90_days' | 'this_year' | 'all_time' | 'custom';
  department_id?: number;
  learner_status?: 'all' | 'completed' | 'in_progress' | 'not_started' | 'dropped';
}
