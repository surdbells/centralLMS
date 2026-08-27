import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/** What an organisation is entitled to. Read-only: it is what it pays for. */
export interface TenantLimits {
  users: number | null;
  storage: number | null;
  expires: string | null;
}

/** How much of that it is using. Counted at read time, never stored. */
export interface TenantUsage {
  users: number;
  storageMb: number;
  courses: number;
}

export interface Tenant {
  id: string;
  name: string;
  /** The tail of every registration and sign-in link. Not editable. */
  code: string;
  status: string;
  contactEmail: string | null;
  contactPhone: string | null;
  address: string | null;
  domain: string | null;
  logoUrl: string | null;
  industry: string | null;
  companySize: string | null;
  limits: TenantLimits;
  usage: TenantUsage;
  createdAt: string | null;
}

/** Absent means "leave it alone"; empty means "clear it". */
export interface TenantProfileInput {
  name?: string;
  contactEmail?: string;
  contactPhone?: string;
  address?: string;
  domain?: string;
  logoUrl?: string;
  industry?: string;
  companySize?: string;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * The organisation's own record.
 *
 * Distinct from its settings, which are preferences with defaults. This is the
 * organisation itself — what it is called, how to reach it, what its mark looks
 * like. The legacy settings screen offered `company_name` as a key/value
 * setting *alongside* the real column, so there were two answers to "what is
 * this company called" and nothing kept them in step.
 */
@Injectable({ providedIn: 'root' })
export class TenantService {
  private readonly api = inject(ApiClient);

  show(): Observable<Tenant> {
    return this.api.get<Tenant>(Endpoints.tenant.show);
  }

  update(input: TenantProfileInput): Observable<Tenant> {
    return this.api.put<Tenant>(Endpoints.tenant.update, input);
  }
}
