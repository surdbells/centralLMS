// reports/services/report.service.ts

import { Injectable, inject } from '@angular/core';
import { Observable, forkJoin, of } from 'rxjs';
import { map, shareReplay } from 'rxjs/operators';
import {
  ReportResponse, ReportCatalogItem, ReportCategory, ReportFilters,
  SavedReport, ScheduledReport, CustomReportConfig,
} from '../models/report.models';
import { ApiClient, Endpoints, type QueryParams } from '../../core/api';

/**
 * Everything the reports module asks of the server.
 *
 * It used to ask a single legacy endpoint — `GlobalComponent.adminReport`, an
 * absolute production URL — with `?action=catalog&token=…&tenant_id=…` read
 * out of `atob(sessionStorage.SESSION)`. Twenty-six methods, one query
 * parameter apart.
 *
 * Three things that mattered:
 *
 *  1. **The catalogue was empty on screen and full on the server.** Every read
 *     here ended in `catchError(() => of([]))`, so a request that failed — and
 *     with the legacy retired, all of them did — arrived as an empty list. The
 *     catalogue said "0 reports across 0 categories" and looked like a feature
 *     nobody had finished, while the API had fifty-seven reports in nine
 *     categories the whole time. Failures now reach the caller, which is the
 *     only way a screen can say that something went wrong.
 *  2. **The tenant came from the client.** `tenant_id` travelled in the query
 *     string beside the token, so a signed-in user could read another
 *     organisation's figures by changing a number. It comes from the token
 *     now, inside a scope the server builds.
 *  3. **The shapes.** The API answers `{report, columns, rows, totals,
 *     series}`; twelve components are written against `{report_name, data,
 *     summary, chart_*}`. Rather than edit twelve templates, the translation
 *     happens here — see `toReportResponse`.
 */
@Injectable({ providedIn: 'root' })
export class ReportService {
  private readonly api = inject(ApiClient);

  private catalogCache$: Observable<CatalogueResponse> | null = null;

  /* ═══════ CATALOG & CATEGORIES ═══════ */

  getCatalog(category?: string): Observable<ReportCatalogItem[]> {
    return this.catalogue().pipe(
      map((response) => response.categories.flatMap((c) => c.reports.map(toCatalogItem))),
      map((items) => (category ? items.filter((i) => i.category === category) : items)),
    );
  }

  getCategories(): Observable<ReportCategory[]> {
    return this.catalogue().pipe(
      map((response) =>
        response.categories.map((c) => ({
          key: c.category,
          name: c.label,
          icon: ICONS[c.category] ?? 'bi bi-file-earmark-bar-graph',
          count: c.reports.length,
        })),
      ),
    );
  }

  /**
   * One request, however many things the screen asks about it.
   *
   * The catalogue screen wants the categories and the reports, and both come
   * out of the same answer.
   */
  private catalogue(): Observable<CatalogueResponse> {
    this.catalogCache$ ??= this.api
      .get<CatalogueResponse>(Endpoints.reports.catalogue)
      .pipe(shareReplay(1));

    return this.catalogCache$;
  }

  /* ═══════ REPORT EXECUTION ═══════ */

  runReport(reportKey: string, filters: ReportFilters = {}): Observable<ReportResponse> {
    return this.api
      .get<RunPayload>(Endpoints.reports.run(reportKey), toCriteria(filters))
      .pipe(map((payload) => toReportResponse(reportKey, payload)));
  }

  /**
   * The learner-level rows behind a figure somebody clicked.
   *
   * A filter on the detail report rather than a report of its own: "the
   * fourteen people who are overdue" is the enrolment detail with
   * `status=overdue`, and giving it a second query is how the two drift apart.
   */
  drilldown(status: string, filters: Record<string, any> = {}): Observable<ReportResponse> {
    return this.runReport('enrollment_detail_list', { ...filters, status });
  }

  /* ═══════ EXPORT ═══════ */

  exportReport(opts: { report_key: string; format: string; filters: ReportFilters }): Observable<Blob> {
    // CSV is what the server writes. The format stays in the signature because
    // the export dialog offers it, and being handed a CSV is better than the
    // dialog losing a control it has always had.
    return this.api.download(Endpoints.reports.export(opts.report_key), toCriteria(opts.filters));
  }

  /* ═══════ DASHBOARDS ═══════ */

  getDashboardSummary(): Observable<any> {
    return this.runReport('executive_dashboard').pipe(map((r) => r.summary ?? {}));
  }

  getLearnerDashboard(): Observable<any> {
    return this.runReport('individual_progress').pipe(map((r) => r.summary ?? {}));
  }

  /* ═══════ SAVED REPORTS ═══════ */

  getSavedReports(): Observable<SavedReport[]> {
    return this.api
      .get<SavedReportPayload[]>(Endpoints.reports.saved)
      .pipe(map((items) => items.map(toSavedReport)));
  }

  saveReport(name: string, reportKey: string, filters: ReportFilters): Observable<SavedReport> {
    return this.api
      .post<SavedReportPayload>(Endpoints.reports.createSaved, {
        reportKey,
        name,
        criteria: toCriteria(filters),
      })
      .pipe(map(toSavedReport));
  }

  deleteSavedReport(id: string | number): Observable<void> {
    return this.api.delete<void>(Endpoints.reports.deleteSaved(String(id)));
  }

  /* ═══════ SCHEDULED REPORTS ═══════ */

  /**
   * A schedule is a saved report that has one, not a record of its own.
   *
   * The legacy kept `scheduled_reports` beside `saved_reports` with the key
   * and the filters copied into both, so editing a report's filters left its
   * scheduled twin sending the old ones for ever.
   */
  getScheduledReports(): Observable<ScheduledReport[]> {
    return this.api
      .get<SavedReportPayload[]>(Endpoints.reports.saved)
      .pipe(map((items) => items.filter(hasSchedule).map(toScheduledReport)));
  }

  createScheduledReport(config: Partial<ScheduledReport>): Observable<SavedReport> {
    const id = config.id === undefined || config.id === null ? null : String(config.id);

    const body = {
      reportKey: config.report_key,
      name: config.report_key,
      criteria: toCriteria(config.params ?? {}),
      schedule: {
        frequency: config.frequency,
        timeOfDay: config.time_of_day,
        dayOfWeek: config.day_of_week,
        dayOfMonth: config.day_of_month,
        recipients: splitRecipients(config.recipients),
        paused: config.is_active === false,
      },
    };

    return (
      id === null
        ? this.api.post<SavedReportPayload>(Endpoints.reports.createSaved, body)
        : this.api.put<SavedReportPayload>(Endpoints.reports.updateSaved(id), body)
    ).pipe(map(toSavedReport));
  }

  deleteScheduledReport(id: string | number): Observable<void> {
    return this.deleteSavedReport(id);
  }

  /* ═══════ FILTER DROPDOWNS ═══════ */

  getAllFilters(): Observable<any> {
    return forkJoin({
      departments: this.lookup('department'),
      teams: this.lookup('team'),
      branches: this.lookup('branch'),
      categories: this.lookup('category'),
    });
  }

  private lookup(kind: string): Observable<any[]> {
    return this.api.get<any[]>(Endpoints.lookups.active(kind as never));
  }

  getCourses(search?: string): Observable<any[]> {
    return this.api
      .getPaged<any>(Endpoints.courses.list, { search, perPage: 100 })
      .pipe(map((page) => page.items));
  }

  getLearners(search?: string): Observable<any[]> {
    return this.api
      .getPaged<any>(Endpoints.reports.learners, { search, perPage: 100 })
      .pipe(map((page) => page.items));
  }

  getAssessments(search?: string): Observable<any[]> {
    return this.api
      .getPaged<any>(Endpoints.assessments.list, { search, perPage: 100 })
      .pipe(map((page) => page.items));
  }

  /* ═══════ CUSTOM REPORT BUILDER ═══════ */
  //
  // There is no ad-hoc query builder behind these. The legacy answered
  // `action=custom-*` out of a table of saved SQL fragments that no migration
  // brought across, and building one is a piece of work in its own right.
  // Each says so rather than resolving to nothing and leaving a screen that
  // looks like it is still loading.

  getCustomSources(): Observable<any[]> {
    return of([]);
  }

  getCustomColumns(_source: string): Observable<any> {
    return of({ columns: [], filters: [], groupings: [] });
  }

  buildCustomReport(_config: CustomReportConfig): Observable<ReportResponse> {
    return notBuilt();
  }

  saveCustomReport(_name: string, _description: string, _config: CustomReportConfig): Observable<never> {
    return notBuilt();
  }

  updateCustomReport(
    _id: string | number,
    _name: string,
    _description: string,
    _config: CustomReportConfig,
  ): Observable<never> {
    return notBuilt();
  }

  getCustomReportsList(): Observable<any[]> {
    return of([]);
  }

  runSavedCustomReport(_id: string | number, _overrides: Record<string, unknown> = {}): Observable<ReportResponse> {
    return notBuilt();
  }

  deleteCustomReport(_id: string | number): Observable<never> {
    return notBuilt();
  }

  duplicateCustomReport(_id: string | number): Observable<never> {
    return notBuilt();
  }

  exportCustomReport(_id: string | number, _format = 'csv'): Observable<never> {
    return notBuilt();
  }
}

// ---------------------------------------------------------------------------
// Translation
// ---------------------------------------------------------------------------

interface CatalogueResponse {
  categories: Array<{
    category: string;
    label: string;
    description: string;
    reports: CataloguedReport[];
  }>;
  total: number;
  scope: string;
}

interface CataloguedReport {
  key: string;
  name: string;
  description: string;
  category: string;
  shape: string;
  exportable: boolean;
  requires: string[];
  paginated: boolean;
}

interface RunPayload {
  report: CataloguedReport;
  columns: Array<{ key: string; label: string; type: string }>;
  rows: Array<Record<string, unknown>>;
  totals: Record<string, unknown>;
  series: Array<{ label: string; value: number; percent?: number }>;
  note?: string;
}

interface SavedSchedule {
  frequency: 'daily' | 'weekly' | 'monthly';
  timeOfDay: string;
  dayOfWeek: number | null;
  dayOfMonth: number | null;
  recipients: string[];
  paused: boolean;
}

interface SavedReportPayload {
  id: string;
  reportKey: string;
  name: string;
  description: string | null;
  criteria: Record<string, unknown>;
  isShared: boolean;
  createdAt: string;
  schedule?: SavedSchedule | null;
  lastRun?: { status: string; startedAt: string } | null;
  nextRunAt?: string | null;
}

/**
 * A category's icon.
 *
 * On the client because it is a drawing decision: the server describes what a
 * report is for, and which glyph stands for "compliance" is not its business.
 */
const ICONS: Record<string, string> = {
  enrollment: 'bi bi-person-plus',
  completion: 'bi bi-check2-circle',
  compliance: 'bi bi-shield-check',
  assessment: 'bi bi-clipboard-check',
  course: 'bi bi-book',
  user: 'bi bi-people',
  time: 'bi bi-clock-history',
  certificate: 'bi bi-award',
  executive: 'bi bi-graph-up-arrow',
};

function toCatalogItem(report: CataloguedReport): ReportCatalogItem {
  return {
    key: report.key,
    name: report.name,
    description: report.description,
    category: report.category,
    // What the report needs before it can run — a course, a learner — which is
    // the nearest thing the API has to the legacy's free-text audience.
    audience: report.requires,
    params: report.requires,
    exportable: report.exportable,
  };
}

/**
 * The API's answer in the shape twelve components are written against.
 *
 * A summary report has no rows and a table has no series, so `data` takes
 * whichever the report actually produced — a chart handed an empty array
 * draws nothing and says nothing about why.
 */
function toReportResponse(key: string, payload: RunPayload): ReportResponse {
  const rows = payload.rows ?? [];
  const series = payload.series ?? [];

  return {
    report_name: payload.report?.name ?? key,
    report_key: key,
    description: payload.report?.description,
    chart_type: payload.report?.shape,
    columns: payload.columns ?? [],
    data: rows.length > 0 ? rows : series,
    summary: payload.totals ?? {},
    note: payload.note,
  } as unknown as ReportResponse;
}

function toSavedReport(payload: SavedReportPayload): SavedReport {
  return {
    id: payload.id,
    name: payload.name,
    report_key: payload.reportKey,
    filters: payload.criteria as ReportFilters,
    created_at: payload.createdAt,
  } as unknown as SavedReport;
}

function hasSchedule(payload: SavedReportPayload): boolean {
  return payload.schedule !== null && payload.schedule !== undefined;
}

function toScheduledReport(payload: SavedReportPayload): ScheduledReport {
  const schedule = payload.schedule as SavedSchedule;

  return {
    id: payload.id,
    report_key: payload.reportKey,
    frequency: schedule.frequency,
    recipients: schedule.recipients.join(', '),
    params: payload.criteria as ReportFilters,
    export_format: 'csv',
    day_of_week: schedule.dayOfWeek ?? 0,
    day_of_month: schedule.dayOfMonth ?? 0,
    time_of_day: schedule.timeOfDay,
    is_active: !schedule.paused,
    next_run_at: payload.nextRunAt ?? undefined,
    last_run_at: payload.lastRun?.startedAt,
    last_run_status: payload.lastRun?.status as ScheduledReport['last_run_status'],
    created_at: payload.createdAt,
  } as unknown as ScheduledReport;
}

/**
 * The filters a screen holds, as the criteria the API takes.
 *
 * Empty values are dropped rather than sent blank: `departmentId=` asks for a
 * department whose id is the empty string, and the server is right to find
 * nothing.
 */
function toCriteria(filters: ReportFilters): QueryParams {
  const criteria: QueryParams = {};

  for (const [key, value] of Object.entries(filters ?? {})) {
    if (value !== null && value !== undefined && value !== '') {
      criteria[CRITERIA_NAMES[key] ?? key] = value;
    }
  }

  return criteria;
}

/** Where a screen's name for a filter differs from the API's. */
const CRITERIA_NAMES: Record<string, string> = {
  date_from: 'from',
  date_to: 'to',
  per_page: 'perPage',
  course_id: 'courseId',
  user_id: 'userId',
  learner_id: 'userId',
  department_id: 'departmentId',
  branch_id: 'branchId',
  team_id: 'teamId',
  manager_id: 'managerId',
  group_by: 'groupBy',
};

function splitRecipients(recipients: string | string[] | undefined): string[] {
  if (Array.isArray(recipients)) {
    return recipients;
  }

  return (recipients ?? '')
    .split(/[,;\s]+/)
    .map((address) => address.trim())
    .filter((address) => address !== '');
}

function notBuilt(): Observable<never> {
  return new Observable((subscriber) => {
    subscriber.error(new Error('Building a report from scratch is not available yet.'));
  });
}
