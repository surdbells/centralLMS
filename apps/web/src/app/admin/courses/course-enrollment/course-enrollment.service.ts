import { decodeSessionBlob } from '../../../core/utils/session-blob';
// course-enrollment/course-enrollment.service.ts

import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import {GlobalComponent} from '../../../global-component';

// Interfaces
export interface Department {
  department_id: number;
  department_name: string;
  department_code: string;
  user_count: number;
}

export interface Team {
  team_id: number;
  team_name: string;
  team_code: string;
  user_count: number;
}

export interface Branch {
  branch_id: number;
  branch_name: string;
  branch_code: string;
  city: string;
  country: string;
  user_count: number;
}

export interface User {
  id: number;
  email: string;
  first_name: string;
  last_name: string;
  department_name?: string;
  team_name?: string;
  branch_name?: string;
}

export interface Course {
  course_id: number;
  title: string;
  description: string;
  thumbnail_url: string;
  duration_minutes: number;
  difficulty_level: string;
  status: string;
  start_date: string | null;
  end_date: string | null;
  max_enrollments: number;
  category_name: string;
  current_enrollments: number;
  is_full: boolean;
}

export interface EnrollmentStats {
  total_enrolled: number;
  active: number;
  in_progress: number;
  not_started: number;
  completed: number;
  cancelled: number;
  mandatory: number;
  avg_progress: number;
}

export interface Enrollee {
  enrollment_id: number;
  user_id: number;
  status: string;
  progress_percentage: number;
  enrolled_at: string;
  completed_at: string | null;
  is_mandatory: boolean;
  due_date: string | null;
  email: string;
  first_name: string;
  last_name: string;
  department_name: string | null;
  team_name: string | null;
  branch_name: string | null;
}

export interface EnrollmentPreview {
  success: boolean;
  course: { course_id: number; title: string };
  summary: {
    total_users: number;
    will_enroll: number;
    already_enrolled: number;
  };
  users_to_enroll: Array<{ user_id: number; email: string; name: string }>;
  users_already_enrolled: Array<{ user_id: number; email: string; name: string }>;
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
    user_id: number;
    email: string;
    first_name: string;
    last_name: string;
    enrollment_id: number;
  }>;
  skipped: Array<{ user_id: number; email: string; name: string; reason: string }>;
  failed: Array<{ user_id: number; email: string; name: string; reason: string }>;
  course: { course_id: number; title: string };
}

export interface EnrollmentRequest {
  course_id: number;
  enrollment_type: 'department' | 'team' | 'branch' | 'user' | 'users';
  department_id?: number;
  team_id?: number;
  branch_id?: number;
  user_id?: number;
  token?: string;
  tenant_id?: number;
  user_ids?: number[];
  is_mandatory?: boolean;
  due_date?: string;
  notify_users?: boolean;
}

interface ApiResponse<T> {
  response_code: number;
  status: 'success' | 'error';
  data: T;
  message: string;
}
export interface AuthParams {
  token: string;
  user_id: number;
  tenant_id: number;
}

@Injectable({
  providedIn: 'root'
})
export class CourseEnrollmentService {
  private apiUrl = GlobalComponent.courseEnrollment;
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
  // ==========================================
  // AUTH HELPERS
  // ==========================================

  private getAuthParams(): AuthParams {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    return {
      token: this.user_session.token,
      user_id: this.user_session.id,
      tenant_id: this.user_session.tenant_id
    };
  }
  // ==========================================
  // LOOKUP METHODS
  // ==========================================

  getDepartments(): Observable<Department[]> {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    return this.http.get<ApiResponse<Department[]>>(this.apiUrl, {
      params: {
        action: 'departments',
        user_id: this.user_session.id,
        token: this.user_session.token,
        tenant_id: this.user_session.tenant_id
      }

    }).pipe(map(res => res.data || []));
  }

  getTeams(): Observable<Team[]> {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    return this.http.get<ApiResponse<Team[]>>(this.apiUrl, {
      params: {
        action: 'teams',
        user_id: this.user_session.id,
        token: this.user_session.token,
        tenant_id: this.user_session.tenant_id
      }
    }).pipe(map(res => res.data || []));
  }

  getBranches(): Observable<Branch[]> {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    return this.http.get<ApiResponse<Branch[]>>(this.apiUrl, {
      params: {
        action: 'branches',
        user_id: this.user_session.id,
        token: this.user_session.token,
        tenant_id: this.user_session.tenant_id
      }
    }).pipe(map(res => res.data || []));
  }

  getUsers(filters?: {
    search?: string;
    department_id?: number;
    team_id?: number;
    branch_id?: number;
  }): Observable<User[]> {
    let params = new HttpParams().set('action', 'users');

    if (filters?.search) params = params.set('search', filters.search);
    if (filters?.department_id) params = params.set('department_id', filters.department_id.toString());
    if (filters?.team_id) params = params.set('team_id', filters.team_id.toString());
    if (filters?.branch_id) params = params.set('branch_id', filters.branch_id.toString());
    const authParams = this.getAuthParams();
    for (const [key, value] of Object.entries(authParams)) {
      params = params.set(key, value.toString());
    }

    return this.http.get<ApiResponse<User[]>>(this.apiUrl, { params })
      .pipe(map(res => res.data || []));
  }
  getCourses(search?: string, status?: string): Observable<Course[]> {
    let params = new HttpParams().set('action', 'courses');
    if (search) params = params.set('search', search);
    if (status) params = params.set('status', status);
    const authParams = this.getAuthParams();
    for (const [key, value] of Object.entries(authParams)) {
      params = params.set(key, value.toString());
    }
    return this.http.get<ApiResponse<Course[]>>(this.apiUrl, { params })
      .pipe(map(res => res.data || []));
  }


  getUnenrolledUsers(courseId: number, search?: string): Observable<User[]> {
    let params = new HttpParams()
      .set('action', 'unenrolled-users')
      .set('course_id', courseId.toString());

    if (search) params = params.set('search', search);
    const authParams = this.getAuthParams();
    for (const [key, value] of Object.entries(authParams)) {
      params = params.set(key, value.toString());
    }
    return this.http.get<ApiResponse<User[]>>(this.apiUrl, { params })
      .pipe(map(res => res.data || []));
  }

  // ==========================================
  // ENROLLMENT STATS
  // ==========================================

  getCourseStats(courseId: number): Observable<EnrollmentStats> {
    return this.http.get<ApiResponse<EnrollmentStats>>(this.apiUrl, {
      params: { action: 'course-stats', course_id: courseId.toString() }
    }).pipe(map(res => res.data));
  }

  getCourseEnrollees(
    courseId: number,
    status?: string,
    page: number = 1,
    limit: number = 20
  ): Observable<{ enrollees: Enrollee[]; pagination: any }> {
    let params = new HttpParams()
      .set('action', 'course-enrollees')
      .set('course_id', courseId.toString())
      .set('page', page.toString())
      .set('limit', limit.toString());

    if (status) params = params.set('status', status);

    return this.http.get<ApiResponse<{ enrollees: Enrollee[]; pagination: any }>>(
      this.apiUrl, { params }
    ).pipe(map(res => res.data));
  }

  // ==========================================
  // ENROLLMENT ACTIONS
  // ==========================================

  previewEnrollment(request: EnrollmentRequest): Observable<EnrollmentPreview> {
    return this.http.post<ApiResponse<EnrollmentPreview>>(
      this.apiUrl,
      { ...request, action: 'preview' }
    ).pipe(map(res => res.data));
  }

  enrollUsers(request: EnrollmentRequest): Observable<EnrollmentResult> {
    return this.http.post<ApiResponse<EnrollmentResult>>(
      this.apiUrl,
      { ...request, action: 'enroll' }
    ).pipe(map(res => res.data));
  }

  unenrollUsers(courseId: number, userIds: number[], reason?: string): Observable<any> {
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      action: 'unenroll',
      course_id: courseId,
      user_ids: userIds,
      reason: reason || 'Admin removed'
    }).pipe(map(res => res.data));
  }

  updateDueDate(courseId: number, userIds: number[], dueDate: string | null): Observable<any> {
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      action: 'update-deadline',
      course_id: courseId,
      user_ids: userIds,
      due_date: dueDate
    }).pipe(map(res => res.data));
  }

  setMandatory(courseId: number, userIds: number[], isMandatory: boolean): Observable<any> {
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      action: 'set-mandatory',
      course_id: courseId,
      user_ids: userIds,
      is_mandatory: isMandatory
    }).pipe(map(res => res.data));
  }
}
