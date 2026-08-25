import { decodeSessionBlob } from '../../core/utils/session-blob';
// courses/services/course.service.ts
import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import { Course, CourseForm, CourseDropdowns, CourseStats } from './course.models';
import {GlobalComponent} from '../../global-component';

@Injectable({ providedIn: 'root' })
export class CourseService {
  private apiBase = GlobalComponent.baseURL + '/user/courses.php';

  constructor(private http: HttpClient) {}

  session_data: any = '';
  user_session = {
    id: 0, tenant_id: 0, token: '',
    tenant: { id: 0, name: '', status: '' },
    first_name: '', last_name: '', email: '', phone: '', avatar: '',
    is_2fa: false, is_active: false, account_locked: false,
    is_admin: false, is_content: false, is_instructor: false, is_learner: false,
  };

  private params(action: string, extra: Record<string, any> = {}): HttpParams {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = decodeSessionBlob(this.session_data!) ?? this.user_session;
    let p = new HttpParams().set('action', action);
    // Auth params injected by interceptor or from storage
    const token = this.user_session.token || '';
    const userId = this.user_session.id || '';
    const tenantId = this.user_session.tenant_id || '';
    p = p.set('token', token).set('user_id', userId).set('tenant_id', tenantId);
    for (const [k, v] of Object.entries(extra)) {
      if (v !== null && v !== undefined && v !== '') p = p.set(k, String(v));
    }
    return p;
  }

  private unwrap<T>(obs: Observable<any>): Observable<T> {
    return obs.pipe(map((r: any) => r.data ?? r));
  }

  listCourses(filters: Record<string, any> = {}): Observable<{ data: Course[]; meta: any }> {
    return this.unwrap(this.http.get(this.apiBase, { params: this.params('list', filters) }));
  }

  getCourse(id: number): Observable<Course> {
    return this.unwrap(this.http.get(this.apiBase, { params: this.params('get', { course_id: id }) }));
  }

  createCourse(data: CourseForm): Observable<any> {
    return this.http.post(this.apiBase, data, { params: this.params('create') });
  }

  updateCourse(id: number, data: Partial<CourseForm>): Observable<any> {
    return this.http.post(this.apiBase, { ...data, course_id: id }, { params: this.params('update') });
  }

  deleteCourse(id: number): Observable<any> {
    return this.http.post(this.apiBase, { course_id: id }, { params: this.params('delete') });
  }

  duplicateCourse(id: number): Observable<any> {
    return this.http.post(this.apiBase, { course_id: id }, { params: this.params('duplicate') });
  }

  getDropdowns(): Observable<CourseDropdowns> {
    return this.unwrap(this.http.get(this.apiBase, { params: this.params('dropdowns') }));
  }

  getStats(): Observable<CourseStats> {
    return this.unwrap(this.http.get(this.apiBase, { params: this.params('stats') }));
  }
}
