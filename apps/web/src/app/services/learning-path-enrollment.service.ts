import { decodeSessionBlob } from '../core/utils/session-blob';
// learning-path-enrollment/learning-path-enrollment.service.ts

import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import {
  Department,
  Team,
  Branch,
  User,
  LearningPathBasic,
  EnrollmentStats,
  EnrollmentPreview,
  EnrollmentResult,
  EnrollmentRequest,
  ApiResponse,
  AuthParams,
  PaginatedResponse
} from '../interfaces/learning-path-enrollment.interfaces';
import {GlobalComponent} from '../global-component';

@Injectable({
  providedIn: 'root'
})
export class LearningPathEnrollmentService {
  private apiUrl = GlobalComponent.learningPathEnrollment;

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

  getLearningPaths(search?: string, status?: string): Observable<LearningPathBasic[]> {
    let params = new HttpParams().set('action', 'paths');
    if (search) params = params.set('search', search);
    if (status) params = params.set('status', status);

    const authParams = this.getAuthParams();
    for (const [key, value] of Object.entries(authParams)) {
      params = params.set(key, value.toString());
    }

    return this.http.get<ApiResponse<LearningPathBasic[]>>(this.apiUrl, { params })
      .pipe(map(res => res.data || []));
  }

  getLearningPathById(pathId: number): Observable<LearningPathBasic> {
    let params = new HttpParams()
      .set('action', 'path')
      .set('path_id', pathId.toString());

    const authParams = this.getAuthParams();
    for (const [key, value] of Object.entries(authParams)) {
      params = params.set(key, value.toString());
    }

    return this.http.get<ApiResponse<LearningPathBasic>>(this.apiUrl, { params })
      .pipe(map(res => res.data));
  }

  getUnenrolledUsers(filters: {
    path_id: number;
    search?: string;
    department_id?: number;
    team_id?: number;
    branch_id?: number;
    page?: number;
    per_page?: number;
  }): Observable<User[]> {
    let params = new HttpParams()
      .set('action', 'unenrolled-users')
      .set('path_id', filters.path_id.toString());

    if (filters.search) params = params.set('search', filters.search);
    if (filters.department_id) params = params.set('department_id', filters.department_id.toString());
    if (filters.team_id) params = params.set('team_id', filters.team_id.toString());
    if (filters.branch_id) params = params.set('branch_id', filters.branch_id.toString());
    if (filters.page) params = params.set('page', filters.page.toString());
    if (filters.per_page) params = params.set('per_page', filters.per_page.toString());

    const authParams = this.getAuthParams();
    for (const [key, value] of Object.entries(authParams)) {
      params = params.set(key, value.toString());
    }

    return this.http.get<ApiResponse<User[]>>(this.apiUrl, { params })
      .pipe(map(res => res.data));
  }

  // ==========================================
  // ENROLLMENT STATS
  // ==========================================

  getPathStats(pathId: number): Observable<EnrollmentStats> {
    let params = new HttpParams()
      .set('action', 'path-stats')
      .set('path_id', pathId.toString());

    const authParams = this.getAuthParams();
    for (const [key, value] of Object.entries(authParams)) {
      params = params.set(key, value.toString());
    }

    return this.http.get<ApiResponse<EnrollmentStats>>(this.apiUrl, { params })
      .pipe(map(res => res.data));
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

  unenrollUsers(pathId: number, userIds: number[], reason?: string): Observable<any> {
    const authParams = this.getAuthParams();
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      action: 'unenroll',
      path_id: pathId,
      user_ids: userIds,
      reason: reason || 'Admin removed',
      ...authParams
    }).pipe(map(res => res.data));
  }

  updateDueDate(pathId: number, userIds: number[], dueDate: string | null): Observable<any> {
    const authParams = this.getAuthParams();
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      action: 'update-deadline',
      path_id: pathId,
      user_ids: userIds,
      due_date: dueDate,
      ...authParams
    }).pipe(map(res => res.data));
  }

  setMandatory(pathId: number, userIds: number[], isMandatory: boolean): Observable<any> {
    const authParams = this.getAuthParams();
    return this.http.post<ApiResponse<any>>(this.apiUrl, {
      action: 'set-mandatory',
      path_id: pathId,
      user_ids: userIds,
      is_mandatory: isMandatory,
      ...authParams
    }).pipe(map(res => res.data));
  }
}
