// users/models/user.models.ts
export interface User {
  id: number; first_name: string; last_name: string | null; full_name: string;
  email: string; phone: string | null; staffId: string | null;
  department: number | null; department_name: string | null;
  team: number | null; team_name: string | null;
  branch: number | null; branch_name: string | null;
  job_title: number | null; manager_id: number | null; manager_name: string | null;
  avatar: string | null; is_active: number; account_locked: number; suspended: string | null;
  status: 'active' | 'inactive' | 'suspended' | 'locked';
  role_keys: string; role_names: string; role_list: string[];
  last_login_at: string | null; login_count: number | null;
  created: string; hire_date: string | null;
  language: string | null; preferred_learning_mode: string | null;
}

export interface UserDetail extends User {
  roles: UserRole[]; permissions: string[];
  probation_end_date: string | null; privacy_consent: number; created_by: number | null;
}

export interface UserRole {
  role_id: number; role_name: string; role_key: string;
  description: string | null; is_system: number;
  assigned_at: string; assigned_by: number | null;
}

export interface UserFormData {
  first_name: string; last_name?: string; email: string; phone?: string; staffId?: string;
  department_id?: number | null; team_id?: number | null; branch_id?: number | null;
  job_title?: number | null; manager_id?: number | null; hire_date?: string;
  language?: string; preferred_learning_mode?: string; is_active?: number;
  role_ids?: number[]; password?: string;
}

export interface Role {
  role_id: number; role_name: string; role_key: string; description: string | null;
  is_system: number; is_active: number; user_count: number;
  permissions?: Permission[]; created_at: string; updated_at: string;
}

export interface Permission {
  permission_id: number; permission_key: string; module: string; description: string | null;
}
export type PermissionGroup = Record<string, Permission[]>;

export interface UserActivity {
  log_id: number; user_id: number; user_name: string; user_email: string;
  action: string; details: Record<string, any>; ip_address: string | null;
  performed_by: number | null; performed_by_name: string | null; created_at: string;
}

export interface UserStats {
  total_users: number; active: number; inactive: number; suspended: number; locked: number;
  active_30d: number; never_logged_in: number;
  by_role: { role_key: string; role_name: string; count: number }[];
  by_department: { department_name: string; count: number }[];
}

export interface ImportPreview {
  success: boolean; error?: string; headers: string[];
  mapping: Record<string, string>; preview: ImportRow[];
  stats: ImportStats; total_rows: number;
}
export interface ImportedUserData {
  first_name?: string;
  last_name?: string;
  email?: string;
  department?: string;
  role?: string;
}
export type ImportStatus = 'valid' | 'warning' | 'error';

export interface ImportRow {
  row_number: number;
  status: ImportStatus;
  data: ImportedUserData;
  errors: string[];
  warnings: string[];
}
export interface ImportStats { total: number; valid: number; warnings: number; errors: number; duplicates: number; }
export interface ImportResult { created: number; updated: number; skipped: number; failed: number; errors: string[]; }

export interface UserFilters {
  search?: string; department_id?: number; branch_id?: number; team_id?: number;
  manager_id?: number; status?: string; role_key?: string; is_active?: number;
  page?: number; per_page?: number; sort_by?: string; sort_dir?: 'asc' | 'desc';
}

export interface BulkActionRequest { bulk_action: string; user_ids: number[]; extra?: Record<string, any>; }
export interface PaginatedResponse<T> { data: T[]; meta: { total: number; page: number; per_page: number; total_pages: number; }; }
