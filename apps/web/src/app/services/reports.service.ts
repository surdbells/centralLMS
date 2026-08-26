import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export type ReportShape = 'summary' | 'table' | 'series';
export type ReportValueType = 'text' | 'number' | 'percent' | 'date' | 'duration' | 'boolean';

export interface ReportColumn {
  key: string;
  label: string;
  type: ReportValueType;
}

export interface ReportDefinition {
  key: string;
  category: string;
  name: string;
  description: string;
  shape: ReportShape;
  exportable: boolean;
  /** Whether rows name individual people, which decides who may run it. */
  namesPeople: boolean;
}

export type ReportRow = Record<string, string | number | boolean | null>;

export interface ReportResult {
  report: ReportDefinition;
  columns: ReportColumn[];
  rows: ReportRow[];
  /** Always an object, so `totals.completed` can be read without checking. */
  totals: Record<string, number>;
  series: Array<{ label: string; value: number }>;
  /** Set when the report has something to say about its own answer. */
  note?: string;
}

/**
 * How a report is narrowed.
 *
 * The tenant is never here: it comes from the token. The legacy's reporting
 * endpoints took `tenant_id` from the query string.
 */
export interface ReportCriteria {
  from?: string;
  to?: string;
  courseId?: string;
  departmentId?: string;
  teamId?: string;
  branchId?: string;
  learnerId?: string;
  assessmentId?: string;
  page?: number;
  perPage?: number;
  sortBy?: string;
  sortDirection?: 'asc' | 'desc';
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * The reporting engine.
 *
 * One place that aggregates. Screens that want to know how something is going
 * ask a report rather than growing their own analytics endpoint — which is how
 * the legacy ended up with `class/reports/` and a stale twin in
 * `class/reporting/` that answered the same questions differently.
 */
@Injectable({ providedIn: 'root' })
export class ReportsService {
  private readonly api = inject(ApiClient);

  /** Every report this caller may run. */
  catalogue(): Observable<ReportDefinition[]> {
    return this.api.get<ReportDefinition[]>(Endpoints.reports.catalogue);
  }

  /** What one report needs and what it returns, without running it. */
  describe(key: string): Observable<ReportDefinition & { criteria: string[] }> {
    return this.api.get<ReportDefinition & { criteria: string[] }>(Endpoints.reports.describe(key));
  }

  run(key: string, criteria: ReportCriteria = {}): Observable<ReportResult> {
    return this.api.get<ReportResult>(Endpoints.reports.run(key), { ...criteria });
  }

  /** The URL a browser fetches the CSV from, with its filters attached. */
  exportUrl(key: string, criteria: ReportCriteria = {}): string {
    const query = Object.entries(criteria)
      .filter(([, value]) => value !== undefined && value !== null && value !== '')
      .map(([key_, value]) => `${encodeURIComponent(key_)}=${encodeURIComponent(String(value))}`)
      .join('&');

    const path = Endpoints.reports.export(key);

    return this.api.urlFor(query === '' ? path : `${path}?${query}`);
  }
}
