// courses/models/course.models.ts
export interface Course {
  course_id: number; title: string; course_type: string; description: string | null;
  objectives: string | null; summary: string | null; thumbnail_url: string | null;
  category_id: number | null; category_name: string | null;
  pass_mark: number; instructor_id: number; instructor_name: string | null;
  certificate_template_id: number | null; certificate_template_name: string | null;
  is_mandatory: number; status: string; level: string | null;
  pass_percentage: number; duration_minutes: number | null;
  is_featured: number; is_active: number; is_onboarding: number;
  enrollment_limit: number; allow_self_enrollment: number;
  average_rating: number; rating_count: number;
  start_date: string | null; end_date: string | null;
  version: string | null; created_by_name: string | null;
  enrollment_count: number; created_at: string; updated_at: string;
}

export interface CourseForm {
  title: string; course_type: string; description?: string; objectives?: string; summary?: string;
  thumbnail_url?: string; category_id?: number | null; pass_mark?: number; instructor_id: number;
  certificate_template_id?: number | null; is_mandatory?: number; status?: string; level?: string;
  pass_percentage?: number; duration_minutes?: number | null; is_featured?: number; is_active?: number;
  is_onboarding?: number; enrollment_limit?: number; allow_self_enrollment?: number;
  start_date?: string; end_date?: string; version?: string;
}

export interface CourseDropdowns {
  categories: { category_id: number; name: string }[];
  instructors: { id: number; name: string; email: string }[];
  certificate_templates: { template_id: number; template_name: string }[];
}

export interface CourseStats {
  total: number; published: number; draft: number; archived: number;
  mandatory: number; featured: number; onboarding: number; avg_rating: number;
}
