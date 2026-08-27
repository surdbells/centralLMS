// learning-path-enrollment/learning-path-enrollment.interfaces.ts

export interface Department {
  department_id: string;
  department_name: string;
  department_code: string;
  user_count: number;
}

export interface Team {
  team_id: string;
  team_name: string;
  team_code: string;
  user_count: number;
}

export interface Branch {
  branch_id: string;
  branch_name: string;
  branch_code: string;
  city: string;
  country: string;
  user_count: number;
}

export interface User {
  user_id: string;
  email: string;
  first_name: string;
  last_name: string;
  department_name?: string;
  team_name?: string;
  branch_name?: string;
  profile_image?: string;
  job_title?: string;
}

export interface LearningPathBasic {
  path_id: string;
  title: string;
  short_description: string;
  description?: string;
  thumbnail_url: string | null;
  skill_level: string;
  path_type: string;
  status: string;
  estimated_duration_hours: number | null;
  total_items: number;
  total_courses: number;
  total_assessments: number;
  total_enrollments: number;
  max_enrollments: number | null;
  is_mandatory: boolean;
  is_free: boolean;
  price: number;
  category_name?: string;
  current_enrollments?: number;
  is_full?: boolean;
}

export interface EnrollmentStats {
  total_enrolled: number;
  active: number;
  completed: number;
  withdrawn: number;
  suspended: number;
  expired: number;
  mandatory: number;
  avg_progress: number;
  overdue: number;
}

export interface EnrollmentPreview {
  success: boolean;
  path: { path_id: string; title: string };
  summary: {
    total_users: number;
    will_enroll: number;
    already_enrolled: number;
  };
  users_to_enroll: Array<{
    user_id: string;
    email: string;
    name: string;
    department_name?: string;
  }>;
  users_already_enrolled: Array<{
    user_id: string;
    email: string;
    name: string;
    enrollment_status?: string;
  }>;
}

export interface EnrollmentResult {
  success: boolean;
  message: string;
  summary: {
    total_users: number;
    enrolled_count: number;
    skipped_count: number;
    failed_count: number;
  };
  enrolled: Array<{
    user_id: string;
    email: string;
    first_name: string;
    last_name: string;
    enrollment_id: string;
  }>;
  skipped: Array<{
    user_id: string;
    email: string;
    name: string;
    reason: string
  }>;
  failed: Array<{
    user_id: string;
    email: string;
    name: string;
    reason: string
  }>;
  path: { path_id: string; title: string };
}

export interface EnrollmentRequest {
  path_id: string;
  enrollment_type: 'department' | 'team' | 'branch' | 'user' | 'users';
  department_id?: string;
  team_id?: string;
  branch_id?: string;
  user_id?: string;
  token?: string;
  tenant_id?: string;
  user_ids?: string[];
  is_mandatory?: boolean;
  due_date?: string;
  notify_users?: boolean;
  priority?: 'low' | 'normal' | 'high' | 'critical';
  notes?: string;
}

export interface ApiResponse<T> {
  response_code: number;
  status: 'success' | 'error';
  data: T;
  message: string;
}

export interface AuthParams {
  token: string;
  user_id: string;
  tenant_id: string;
}

export interface PaginatedResponse<T> {
  users: T[];
  pagination: {
    total: number;
    page: number;
    per_page: number;
    total_pages: number;
  };
}
