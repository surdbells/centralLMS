import { decodeSessionBlob } from '../../core/utils/session-blob';
// training-enrollment/training-enrollment.service.ts

import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { GlobalComponent } from '../../global-component';

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

export interface Training {
  training_id: number;
  title: string;
  description: string;
  short_description: string;
  thumbnail_url: string;
  training_type: string;
  delivery_method: string;
  max_capacity: number | null;
  status: string;
  session_count: number;
  enrollment_count: number;
  category_name: string;
  is_full: boolean;
}

export interface EnrollmentStats {
  total_enrolled: number;
  active: number;
  completed: number;
  cancelled: number;
  waitlisted: number;
  avg_attendance: number;
}

export interface Enrollee {
  enrollment_id: number;
  user_id: number;
  status: string;
  sessions_total: number;
  sessions_attended: number;
  attendance_percentage: number;
  enrolled_at: string;
  completion_date: string | null;
  certificate_issued: boolean;
  email: string;
  first_name: string;
  last_name: string;
  department_name: string | null;
  team_name: string | null;
  branch_name: string | null;
}

export interface EnrollmentPreview {
  success: boolean;
  training: { training_id: number; title: string };
  summary: {
    total_users: number;
    will_enroll: number;
    already_enrolled: number;
    will_waitlist: number;
  };
  users_to_enroll: Array<{ user_id: number; email: string; name: string }>;
  users_already_enrolled: Array<{ user_id: number; email: string; name: string }>;
  users_to_waitlist: Array<{ user_id: number; email: string; name: string }>;
  capacity_info?: {
    max_capacity: number;
    current_enrollments: number;
    available_slots: number;
  };
}

export interface EnrollmentResult {
  success: boolean;
  message: string;
  summary: {
    total_users: number;
    enrolled_count: number;
    waitlisted_count: number;
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
  waitlisted: Array<{
    user_id: number;
    email: string;
    first_name: string;
    last_name: string;
    waitlist_position: number;
  }>;
  skipped: Array<{ user_id: number; email: string; name: string; reason: string }>;
  failed: Array<{ user_id: number; email: string; name: string; reason: string }>;
  training: { training_id: number; title: string };
}

export interface EnrollmentRequest {
  training_id: number;
  enrollment_type: 'department' | 'team' | 'branch' | 'user' | 'users';
  department_id?: number;
  team_id?: number;
  branch_id?: number;
  user_id?: number;
  token?: string;
  tenant_id?: number;
  user_ids?: number[];
  is_mandatory?: boolean;
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
export class TrainingEnrollmentService {
  private apiUrl = GlobalComponent.training_enrollment;

  session_data: any = "";
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
  constructor(private http: HttpClient) {}

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
        user_id: this.user_session.id.toString(),
        token: this.user_session.token,
        tenant_id: this.user_session.tenant_id.toString()
      }
    }).pipe(map(res => res.data || []));
  }

  getTeams(): Observable<Team[]> {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    return this.http.get<ApiResponse<Team[]>>(this.apiUrl, {
      params: {
        action: 'teams',
        user_id: this.user_session.id.toString(),
        token: this.user_session.token,
        tenant_id: this.user_session.tenant_id.toString()
      }
    }).pipe(map(res => res.data || []));
  }

  getBranches(): Observable<Branch[]> {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    return this.http.get<ApiResponse<Branch[]>>(this.apiUrl, {
      params: {
        action: 'branches',
        user_id: this.user_session.id.toString(),
        token: this.user_session.token,
        tenant_id: this.user_session.tenant_id.toString()
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

  getTrainings(search?: string, status?: string): Observable<Training[]> {
    let params = new HttpParams().set('action', 'trainings');
    if (search) params = params.set('search', search);
    if (status) params = params.set('status', status);

    const authParams = this.getAuthParams();
    for (const [key, value] of Object.entries(authParams)) {
      params = params.set(key, value.toString());
    }

    return this.http.get<ApiResponse<Training[]>>(this.apiUrl, { params })
      .pipe(map(res => res.data || []));
  }

  getUnenrolledUsers(trainingId: number, search?: string): Observable<User[]> {
    let params = new HttpParams()
      .set('action', 'unenrolled-users')
      .set('training_id', trainingId.toString());

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

  getTrainingStats(trainingId: number): Observable<EnrollmentStats> {
    let params = new HttpParams()
      .set('action', 'training-stats')
      .set('training_id', trainingId.toString());

    const authParams = this.getAuthParams();
    for (const [key, value] of Object.entries(authParams)) {
      params = params.set(key, value.toString());
    }

    return this.http.get<ApiResponse<EnrollmentStats>>(this.apiUrl, { params })
      .pipe(map(res => res.data));
  }

  getTrainingEnrollees(
    trainingId: number,
    status?: string,
    page: number = 1,
    limit: number = 20
  ): Observable<{ enrollees: Enrollee[]; pagination: any }> {
    let params = new HttpParams()
      .set('action', 'training-enrollees')
      .set('training_id', trainingId.toString())
      .set('page', page.toString())
      .set('limit', limit.toString());

    if (status) params = params.set('status', status);

    const authParams = this.getAuthParams();
    for (const [key, value] of Object.entries(authParams)) {
      params = params.set(key, value.toString());
    }

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

  unenrollUsers(trainingId: number, userIds: number[], reason?: string): Observable<any> {
    const authParams = this.getAuthParams();
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      action: 'unenroll',
      training_id: trainingId,
      user_ids: userIds,
      reason: reason || 'Admin removed',
      ...authParams
    }).pipe(map(res => res.data));
  }

  cancelEnrollment(enrollmentId: number, reason?: string): Observable<any> {
    const authParams = this.getAuthParams();
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      action: 'cancel-enrollment',
      enrollment_id: enrollmentId,
      reason: reason,
      ...authParams
    }).pipe(map(res => res.data));
  }
}
