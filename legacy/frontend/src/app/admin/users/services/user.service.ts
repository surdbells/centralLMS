// users/services/user.service.ts
import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, map, catchError, of } from 'rxjs';
import {
  User, UserDetail, UserFormData, Role, Permission, PermissionGroup,
  UserActivity, UserStats, ImportPreview, ImportResult,
  UserFilters, BulkActionRequest, PaginatedResponse
} from '../models/user.models';
import {GlobalComponent} from '../../../global-component';

@Injectable({ providedIn: 'root' })
export class UserService {
  private apiBase = GlobalComponent.users;
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
    this.user_session = JSON.parse(atob(this.session_data!));
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
    return obs.pipe(map((res: any) => res.data ?? res));
  }

  /* ═══════ USER CRUD ═══════ */

  listUsers(filters: UserFilters = {}): Observable<PaginatedResponse<User>> {
    return this.unwrap(this.http.get(this.apiBase, { params: this.params('list', filters as any) }));
  }

  getUser(id: number): Observable<UserDetail> {
    return this.unwrap(this.http.get(this.apiBase, { params: this.params('get', { id }) }));
  }

  createUser(data: UserFormData): Observable<any> {
    return this.http.post(this.apiBase, data, { params: this.params('create') });
  }

  updateUser(id: number, data: Partial<UserFormData>): Observable<any> {
    return this.http.post(this.apiBase, { ...data, id }, { params: this.params('update') });
  }

  deleteUser(id: number): Observable<any> {
    return this.http.post(this.apiBase, { id }, { params: this.params('delete') });
  }

  /* ═══════ STATUS ═══════ */

  activateUser(id: number): Observable<any> {
    return this.http.post(this.apiBase, { id }, { params: this.params('activate') });
  }

  suspendUser(id: number): Observable<any> {
    return this.http.post(this.apiBase, { id }, { params: this.params('suspend') });
  }

  unlockUser(id: number): Observable<any> {
    return this.http.post(this.apiBase, { id }, { params: this.params('unlock') });
  }

  resetPassword(id: number): Observable<any> {
    return this.http.post(this.apiBase, { id }, { params: this.params('reset-password') });
  }

  /* ═══════ BULK ═══════ */

  bulkAction(req: BulkActionRequest): Observable<any> {
    return this.http.post(this.apiBase, req, { params: this.params('bulk-action') });
  }

  /* ═══════ IMPORT ═══════ */

  importPreview(file: File): Observable<ImportPreview> {
    const fd = new FormData();
    fd.append('file', file);
    return this.unwrap(this.http.post(this.apiBase, fd, { params: this.params('import-preview') }));
  }

  importExecute(options: Record<string, any> = {}): Observable<ImportResult> {
    return this.unwrap(this.http.post(this.apiBase, options, { params: this.params('import-execute') }));
  }

  downloadTemplate(): void {
    window.open(`${this.apiBase}?${this.params('import-template').toString()}`, '_blank');
  }

  /* ═══════ STATS & ACTIVITY ═══════ */

  getStats(): Observable<UserStats> {
    return this.unwrap(this.http.get(this.apiBase, { params: this.params('stats') }));
  }

  getActivity(filters: Record<string, any> = {}): Observable<PaginatedResponse<UserActivity>> {
    return this.unwrap(this.http.get(this.apiBase, { params: this.params('activity', filters) }));
  }

  /* ═══════ ROLES ═══════ */

  listRoles(): Observable<Role[]> {
    return this.unwrap(this.http.get(this.apiBase, { params: this.params('roles') }));
  }

  getRole(roleId: number): Observable<Role> {
    return this.unwrap(this.http.get(this.apiBase, { params: this.params('role-get', { role_id: roleId }) }));
  }

  createRole(name: string, key: string, description: string = ''): Observable<any> {
    return this.http.post(this.apiBase, { name, key, description }, { params: this.params('role-create') });
  }

  updateRole(roleId: number, name: string, description: string = ''): Observable<any> {
    return this.http.post(this.apiBase, { role_id: roleId, name, description }, { params: this.params('role-update') });
  }

  deleteRole(roleId: number): Observable<any> {
    return this.http.post(this.apiBase, { role_id: roleId }, { params: this.params('role-delete') });
  }

  /* ═══════ PERMISSIONS ═══════ */

  listPermissions(): Observable<PermissionGroup> {
    return this.unwrap(this.http.get(this.apiBase, { params: this.params('permissions') }));
  }

  setRolePermissions(roleId: number, permissionIds: number[]): Observable<any> {
    return this.http.post(this.apiBase, { role_id: roleId, permission_ids: permissionIds },
      { params: this.params('role-permissions') });
  }

  assignRole(userId: number, roleId: number): Observable<any> {
    return this.http.post(this.apiBase, { target_user_id: userId, role_id: roleId },
      { params: this.params('assign-role') });
  }

  removeRole(userId: number, roleId: number): Observable<any> {
    return this.http.post(this.apiBase, { target_user_id: userId, role_id: roleId },
      { params: this.params('remove-role') });
  }

  /* ═══════ AUTH ═══════ */

  checkPermission(perm: string): Observable<boolean> {
    return this.http.get<any>(this.apiBase, { params: this.params('check-permission', { permission: perm }) })
      .pipe(map(r => r.data?.has_permission ?? false), catchError(() => of(false)));
  }

  getMyPermissions(): Observable<{ permissions: string[]; roles: any[] }> {
    return this.unwrap(this.http.get(this.apiBase, { params: this.params('my-permissions') }));
  }

  /* ═══════ MY ACCOUNT ═══════ */

  getMyProfile(): Observable<any> {
    return this.unwrap(this.http.get(this.apiBase, { params: this.params('my-profile') }));
  }

  updateProfile(data: Record<string, any>): Observable<any> {
    return this.http.post(this.apiBase, data, { params: this.params('update-profile') });
  }

  changePassword(currentPassword: string, newPassword: string): Observable<any> {
    return this.http.post(this.apiBase, { current_password: currentPassword, new_password: newPassword },
      { params: this.params('change-password') });
  }

  uploadAvatar(file: File): Observable<any> {
    const fd = new FormData();
    fd.append('avatar', file);
    return this.http.post(this.apiBase, fd, { params: this.params('upload-avatar') });
  }

  uploadAvatarBase64(base64: string): Observable<any> {
    return this.http.post(this.apiBase, { avatar: base64 }, { params: this.params('upload-avatar') });
  }

  removeAvatar(): Observable<any> {
    return this.http.post(this.apiBase, {}, { params: this.params('remove-avatar') });
  }

  updateConsent(data: Record<string, number>): Observable<any> {
    return this.http.post(this.apiBase, data, { params: this.params('update-consent') });
  }

  getLoginHistory(limit = 20): Observable<any[]> {
    return this.unwrap(this.http.get(this.apiBase, { params: this.params('login-history', { limit }) }));
  }
}
