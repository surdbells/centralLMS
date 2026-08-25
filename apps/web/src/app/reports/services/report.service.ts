import { decodeSessionBlob } from '../../core/utils/session-blob';
// reports/services/report.service.ts

import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable, of, throwError } from 'rxjs';
import { map, catchError, shareReplay } from 'rxjs/operators';
import {
  ReportResponse, ReportCatalogItem, ReportCategory, ReportFilters,
  SavedReport, ScheduledReport, CustomReportConfig, ExportOptions
} from '../models/report.models';
import { GlobalComponent } from '../../global-component';

@Injectable({ providedIn: 'root' })
export class ReportService {
  private apiBase = GlobalComponent.adminReport;
  private catalogCache$: Observable<ReportCatalogItem[]> | null = null;
  private categoriesCache$: Observable<ReportCategory[]> | null = null;
  private allFiltersCache$: Observable<any> | null = null;

  session_data: any = '';
  current_role: any = '';
  user_session = {
    id: 0, tenant_id: 0, token: '',
    tenant: { id: 0, name: '', status: '' },
    first_name: '', last_name: '', email: '', phone: '', avatar: '',
    is_2fa: false, is_active: false, account_locked: false,
    is_admin: false, is_content: false, is_instructor: false, is_learner: false,
  };

  constructor(private http: HttpClient) {}

  /* ═══════ AUTH ═══════ */

  private getAuthParams(): HttpParams {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = decodeSessionBlob(this.session_data!) ?? this.user_session;
    this.current_role = sessionStorage.getItem('IS_CURRENT');
    return new HttpParams()
      .set('token', this.user_session.token)
      .set('user_id', this.user_session.id)
      .set('role', this.current_role)
      .set('tenant_id', this.user_session.tenant_id);
  }

  private buildParams(action: string, extra: Record<string, any> = {}): HttpParams {
    let params = this.getAuthParams().set('action', action);
    Object.entries(extra).forEach(([key, value]) => {
      if (value !== null && value !== undefined && value !== '') {
        params = params.set(key, String(value));
      }
    });
    return params;
  }

  private buildPostBody(action: string, extra: Record<string, any> = {}): any {
    this.session_data = sessionStorage.getItem('SESSION');
    this.current_role = sessionStorage.getItem('IS_CURRENT');
    this.user_session = decodeSessionBlob(this.session_data!) ?? this.user_session;
    return {
      action,
      token: this.user_session.token,
      user_id: this.user_session.id,
      tenant_id: this.user_session.tenant_id,
      role: this.current_role,
      ...extra
    };
  }

  /* ═══════ CATALOG & CATEGORIES ═══════ */

  getCatalog(category?: string): Observable<ReportCatalogItem[]> {
    if (!this.catalogCache$) {
      this.catalogCache$ = this.http.get<any>(this.apiBase, { params: this.buildParams('catalog') }).pipe(
        map(res => (res.data || []).map((r: any) => ({ ...r, key: r.report_key || r.key }))),
        shareReplay(1),
        catchError(() => of([]))
      );
    }
    return this.catalogCache$.pipe(
      map(items => category ? items.filter((i: ReportCatalogItem) => i.category === category) : items)
    );
  }

  getCategories(): Observable<ReportCategory[]> {
    if (!this.categoriesCache$) {
      this.categoriesCache$ = this.http.get<any>(this.apiBase, { params: this.buildParams('categories') }).pipe(
        map(res => res.data || []),
        shareReplay(1),
        catchError(() => of([]))
      );
    }
    return this.categoriesCache$;
  }

  /* ═══════ REPORT EXECUTION ═══════ */
  // API returns: { data: { success, report_key, report_name, result: { report_name, data, meta, summary } } }
  // We unwrap to: { report_name, data, meta, summary, report_key }

  runReport(reportKey: string, filters: ReportFilters = {}): Observable<ReportResponse> {
    const params = this.buildParams('run', { report: reportKey, ...filters });
    return this.http.get<any>(this.apiBase, { params }).pipe(
      map(res => {
        const wrapper = res.data || res;
        // If wrapped in { result: { data, meta, summary } }, unwrap it
        if (wrapper.result) {
          return {
            report_name: wrapper.result.report_name || wrapper.report_name,
            report_key: wrapper.report_key || reportKey,
            data: wrapper.result.data || [],
            meta: wrapper.result.meta,
            summary: wrapper.result.summary,
            chart_type: wrapper.result.chart_type,
            chart_config: wrapper.result.chart_config,
            ...wrapper.result
          };
        }
        console.log(wrapper);
        return wrapper;
      }),
      catchError(err => throwError(() => err))
    );
  }

  /* ═══════ EXPORT ═══════ */

  exportReport(opts: { report_key: string; format: string; filters: ReportFilters }): Observable<Blob> {
    const params = this.buildParams('export', {
      report: opts.report_key,
      format: opts.format,
      ...opts.filters
    });
    return this.http.get(this.apiBase, { params, responseType: 'blob' });
  }

  /* ═══════ DASHBOARDS ═══════ */

  getDashboardSummary(): Observable<any> {
    return this.http.get<any>(this.apiBase, { params: this.buildParams('dashboard') }).pipe(
      map(res => res.data || {}),
      catchError(() => of({}))
    );
  }

  getLearnerDashboard(): Observable<any> {
    return this.http.get<any>(this.apiBase, { params: this.buildParams('my-dashboard') }).pipe(
      map(res => res.data || {}),
      catchError(() => of({}))
    );
  }

  /* ═══════ SAVED REPORTS ═══════ */

  getSavedReports(): Observable<SavedReport[]> {
    return this.http.get<any>(this.apiBase, { params: this.buildParams('saved') }).pipe(
      map(res => res.data || []),
      catchError(() => of([]))
    );
  }

  saveReport(name: string, reportKey: string, filters: ReportFilters): Observable<any> {
    return this.http.post(this.apiBase, this.buildPostBody('save', { name, report_key: reportKey, filters }));
  }

  deleteSavedReport(id: number): Observable<any> {
    return this.http.post(this.apiBase, this.buildPostBody('delete', { id }));
  }

  /* ═══════ SCHEDULED REPORTS ═══════ */

  getScheduledReports(): Observable<ScheduledReport[]> {
    return this.http.get<any>(this.apiBase, { params: this.buildParams('scheduled-reports') }).pipe(
      map(res => res.data || []),
      catchError(() => of([]))
    );
  }

  createScheduledReport(config: Partial<ScheduledReport>): Observable<any> {
    return this.http.post(this.apiBase, this.buildPostBody('schedule-report', config));
  }

  deleteScheduledReport(id: number): Observable<any> {
    return this.http.post(this.apiBase, this.buildPostBody('delete-schedule', { id }));
  }

  /* ═══════ CUSTOM REPORT BUILDER ═══════ */

  getCustomSources(): Observable<any[]> {
    return this.http.get<any>(this.apiBase, { params: this.buildParams('custom-sources') }).pipe(
      map(res => res.sources || []), catchError(() => of([]))
    );
  }

  /** Returns { columns, filters, groupings } for a data source */
  getCustomColumns(source: string): Observable<any> {
    return this.http.get<any>(this.apiBase, { params: this.buildParams('custom-columns', { source }) }).pipe(
      map(res => res.data || res), catchError(() => of({ columns: [], filters: [], groupings: [] }))
    );
  }

  /** Build and run an ad-hoc custom report (POST: action=custom-build) */
  buildCustomReport(config: CustomReportConfig): Observable<ReportResponse> {
    return this.http.post<any>(this.apiBase, this.buildPostBody('custom-build', config)).pipe(
      map(res => res.data || res),
      catchError(err => throwError(() => err))
    );
  }

  /** Save a custom report config (POST: action=custom-save) */
  saveCustomReport(name: string, description: string, config: CustomReportConfig): Observable<any> {
    return this.http.post(this.apiBase, this.buildPostBody('custom-save', { name, description, config }));
  }

  /** Update an existing saved custom report (POST: action=custom-update) */
  updateCustomReport(reportId: number, name: string, description: string, config: CustomReportConfig): Observable<any> {
    return this.http.post(this.apiBase, this.buildPostBody('custom-update', { report_id: reportId, name, description, config }));
  }

  /** List all saved custom reports (GET: action=custom-list) */
  getCustomReportsList(): Observable<any[]> {
    return this.http.get<any>(this.apiBase, { params: this.buildParams('custom-list') }).pipe(
      map(res => res.reports || res.data || []), catchError(() => of([]))
    );
  }

  /** Run a saved custom report by ID (GET: action=custom-run&id=X) with optional overrides */
  runSavedCustomReport(id: number, overrides: Record<string, any> = {}): Observable<ReportResponse> {
    return this.http.get<any>(this.apiBase, { params: this.buildParams('custom-run', { id, ...overrides }) }).pipe(
      map(res => res.data || res),
      catchError(err => throwError(() => err))
    );
  }

  /** Delete a saved custom report (POST: action=custom-delete) */
  deleteCustomReport(id: number): Observable<any> {
    return this.http.post(this.apiBase, this.buildPostBody('custom-delete', { report_id: id }));
  }

  /** Duplicate a saved custom report (GET: action=custom-duplicate&id=X) */
  duplicateCustomReport(id: number): Observable<any> {
    return this.http.get<any>(this.apiBase, { params: this.buildParams('custom-duplicate', { id }) }).pipe(
      map(res => res.data || res), catchError(err => throwError(() => err))
    );
  }

  /** Export a saved custom report (GET: action=custom-export&id=X&format=csv|xlsx|pdf) */
  exportCustomReport(id: number, format: string = 'csv'): Observable<Blob> {
    return this.http.get(this.apiBase, {
      params: this.buildParams('custom-export', { id, format }),
      responseType: 'blob'
    });
  }

  /* ═══════ FILTER DROPDOWNS ═══════ */

  getAllFilters(): Observable<any> {
    if (!this.allFiltersCache$) {
      this.allFiltersCache$ = this.http.get<any>(this.apiBase, { params: this.buildParams('all-filters') }).pipe(
        shareReplay(1),
        catchError(() => of({ departments: [], teams: [], branches: [], categories: [], states: [], managers: [] }))
      );
    }
    return this.allFiltersCache$;
  }

  getCourses(search?: string): Observable<any[]> {
    return this.http.get<any>(this.apiBase, { params: this.buildParams('courses-list', search ? { search } : {}) }).pipe(
      map(res => res.data || []), catchError(() => of([]))
    );
  }

  getLearners(search?: string): Observable<any[]> {
    return this.http.get<any>(this.apiBase, { params: this.buildParams('learners-list', search ? { search } : {}) }).pipe(
      map(res => res.data || []), catchError(() => of([]))
    );
  }

  getAssessments(search?: string): Observable<any[]> {
    return this.http.get<any>(this.apiBase, { params: this.buildParams('assessments-list', search ? { search } : {}) }).pipe(
      map(res => res.data || []), catchError(() => of([]))
    );
  }

  /** Universal drilldown: fetches learner-level rows by status + org filters */
  drilldown(status: string, filters: Record<string, any> = {}): Observable<any> {
    const params = this.buildParams('run', { report: 'drilldown_learner_list', status, ...filters });
    return this.http.get<any>(this.apiBase, { params }).pipe(
      map(res => {
        const wrapper = res.data || res;
        if (wrapper.result) return { ...wrapper.result, report_key: 'drilldown_learner_list' };
        return wrapper;
      }),
      catchError(err => throwError(() => err))
    );
  }
}
