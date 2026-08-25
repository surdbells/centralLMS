// reports/models/report.models.ts
// Shared interfaces for entire Reports module

export interface ReportMeta {
  total: number;
  page: number;
  per_page: number;
  last_page: number;
}

export interface ReportResponse {
  report_name: string;
  description?: string;
  chart_type?: string;
  chart_config?: ChartConfig;
  summary?: Record<string, any>;
  data: any[];
  meta?: ReportMeta;
  error?: string;
}

export interface ChartConfig {
  x_axis?: string;
  y_axis?: string;
  y_axes?: string[];
  color_by?: string;
}

export interface ReportCatalogItem {
  key: string;
  name: string;
  description: string;
  category: string;
  audience: string[];
  params?: string[];
  exportable: boolean;
}

export interface ReportCategory {
  key: string;
  name: string;
  icon: string;
  count?: number;
}

export interface ReportFilters {
  department_id?: number;
  branch_id?: number;
  team_id?: number;
  course_id?: number;
  user_id?: number;
  manager_id?: number;
  learner_id?: number;
  date_from?: string;
  date_to?: string;
  status?: string;
  period?: string;
  group_by?: string;
  breakdown?: string;
  page?: number;
  per_page?: number;
  export_format?: string;
  [key: string]: any;
}

export interface SavedReport {
  id: number;
  name: string;
  report_key: string;
  report_name?: string;
  filters: ReportFilters;
  created_at: string;
  updated_at?: string;
  is_default?: boolean;
}

export interface ScheduledReport {
  id: number;
  user_id: number;
  report_key: string;
  frequency: 'daily' | 'weekly' | 'monthly';
  recipients: string;
  params: ReportFilters;
  export_format: 'csv' | 'xlsx' | 'pdf';
  day_of_week: number;
  day_of_month: number;
  time_of_day: string;
  is_active: boolean;
  last_run_at?: string;
  next_run_at?: string;
  last_run_status?: 'success' | 'error' | 'pending';
  created_at: string;
}

export interface CustomReportConfig {
  source: string;
  columns: string[];
  filters: Record<string, any>;
  group_by?: string;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
  limit?: number;
}

export interface ExportOptions {
  format: 'csv' | 'xlsx' | 'pdf';
  report_key: string;
  filters: ReportFilters;
}

export interface OrgUnit {
  id: number;
  name: string;
  type: 'department' | 'branch' | 'team';
}

export interface ChartDataset {
  label: string;
  data: number[];
}

export interface ChartData {
  labels: string[];
  datasets: ChartDataset[];
}

export interface PieSlice {
  path: string;
  label: string;
  value: number;
  percent: number;
  color: string;
}

export type ChartType = 'bar' | 'line' | 'area' | 'pie' | 'doughnut' | 'horizontal_bar' | 'stacked_bar' | 'grouped_bar' | 'radar' | 'scatter';

export interface DashboardKPI {
  label: string;
  value: number | string;
  change?: number;
  icon: string;
  color: string;
  trend?: 'up' | 'down' | 'flat';
}
