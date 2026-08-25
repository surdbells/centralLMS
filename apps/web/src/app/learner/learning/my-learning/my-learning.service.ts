import { decodeSessionBlob } from '../../../core/utils/session-blob';
// my-learning/my-learning.service.ts

import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import {GlobalComponent} from '../../../global-component';

// Interfaces
export interface LearningStats {
  total_enrolled: number;
  in_progress: number;
  completed: number;
  not_started: number;
  overdue: number;
  certificates_earned: number;
  total_points: number;
  current_streak: number;
  total_learning_time_minutes: number;
}

export interface EnrolledCourse {
  enrollment_id: number;
  course_id: number;
  status: 'not_started' | 'in_progress' | 'completed';
  progress_percentage: number;
  enrolled_at: string;
  started_at: string | null;
  completed_at: string | null;
  due_date: string | null;
  is_mandatory: boolean;
  last_accessed_at: string | null;
  title: string;
  description: string;
  thumbnail_url: string | null;
  duration_minutes: number;
  difficulty_level: string;
  category_name: string | null;
  total_lessons: number;
  completed_lessons: number;
  is_overdue: boolean;
  days_until_due: number | null;
}

export interface InProgressCourse {
  enrollment_id: number;
  course_id: number;
  progress_percentage: number;
  due_date: string | null;
  is_mandatory: boolean;
  last_accessed_at: string | null;
  title: string;
  thumbnail_url: string | null;
  duration_minutes: number;
  next_lesson_id: number | null;
  next_lesson_title: string | null;
  is_overdue: boolean;
}

export interface CompletedCourse {
  enrollment_id: number;
  course_id: number;
  completed_at: string;
  title: string;
  thumbnail_url: string | null;
  duration_minutes: number;
  category_name: string | null;
  certificate_id: number | null;
  has_certificate: boolean;
}

export interface Lesson {
  lesson_id: number;
  title: string;
  lesson_type: string;
  duration_minutes: number;
  sort_order: number;
  is_completed: boolean;
  progress_percentage: number;
  last_position: number | null;
  completed_at: string | null;
}

export interface CourseModule {
  module_id: number | null;
  title: string;
  description: string | null;
  sort_order: number;
  lessons: Lesson[];
}

export interface Certificate {
  certificate_id: number;
  certificate_number: string;
  issued_at: string;
  expires_at: string | null;
  certificate_url: string | null;
}

export interface CourseDetails {
  enrollment_id: number;
  course_id: number;
  status: 'not_started' | 'in_progress' | 'completed';
  progress_percentage: number;
  enrolled_at: string;
  started_at: string | null;
  completed_at: string | null;
  due_date: string | null;
  is_mandatory: boolean;
  last_accessed_at: string | null;
  title: string;
  description: string;
  thumbnail_url: string | null;
  duration_minutes: number;
  difficulty_level: string;
  objectives: string | null;
  category_name: string | null;
  is_overdue: boolean;
  modules: CourseModule[];
  certificate?: Certificate;
}

export interface AvailableCourse {
  course_id: number;
  title: string;
  description: string;
  thumbnail_url: string | null;
  duration_minutes: number;
  level: string;
  category_name: string | null;
  enrollment_count: number;
}

export interface Category {
  category_id: number;
  category_name: string;
  category_code: string;
}

export interface NextLesson {
  lesson_id: number;
  title: string;
  lesson_type: string;
  duration_minutes: number;
  progress_percentage: number;
  last_position: number | null;
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
export class MyLearningService {
  private apiUrl = GlobalComponent.myLearning;
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
  // DASHBOARD & STATS
  // ==========================================

  getStats(): Observable<LearningStats> {
    return this.http.post<ApiResponse<LearningStats>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'stats'
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // ENROLLED COURSES
  // ==========================================

  getEnrolledCourses(status?: string, search?: string): Observable<EnrolledCourse[]> {
    return this.http.post<ApiResponse<EnrolledCourse[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'enrolled',
      status,
      search
    }).pipe(map(res => res.data));
  }

  getInProgressCourses(limit: number = 5): Observable<InProgressCourse[]> {
    return this.http.post<ApiResponse<InProgressCourse[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'in-progress',
      limit
    }).pipe(map(res => res.data));
  }

  getCompletedCourses(limit: number = 10): Observable<CompletedCourse[]> {
    return this.http.post<ApiResponse<CompletedCourse[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'completed',
      limit
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // COURSE DETAILS
  // ==========================================

  getCourseDetails(courseId: number): Observable<CourseDetails> {
    return this.http.post<ApiResponse<CourseDetails>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'course-details',
      course_id: courseId
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // LEARNING ACTIONS
  // ==========================================

  startCourse(courseId: number): Observable<any> {
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'start-course',
      course_id: courseId
    }).pipe(map(res => res.data));
  }

  getNextLesson(courseId: number): Observable<NextLesson | null> {
    return this.http.post<ApiResponse<NextLesson | null>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'next-lesson',
      course_id: courseId
    }).pipe(map(res => res.data));
  }

  updateLastAccessed(courseId: number): Observable<any> {
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'update-accessed',
      course_id: courseId
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // AVAILABLE / SELF-ENROLLMENT
  // ==========================================

  getAvailableCourses(search?: string, categoryId?: number): Observable<AvailableCourse[]> {
    return this.http.post<ApiResponse<AvailableCourse[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'available-courses',
      search,
      category_id: categoryId
    }).pipe(map(res => res.data));
  }

  selfEnroll(courseId: number): Observable<any> {
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'self-enroll',
      course_id: courseId
    }).pipe(map(res => res.data));
  }

  // ==========================================
  // CATEGORIES
  // ==========================================

  getCategories(): Observable<Category[]> {
    return this.http.post<ApiResponse<Category[]>>(this.apiUrl, {
      ...this.getAuthPayload(),
      action: 'categories'
    }).pipe(map(res => res.data));
  }
}
