// onboarding/services/onboarding.service.ts

import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';

export interface Plan {
  plan_id: number; name: string; users_limit: number;
  storage_limit_mb: number; price: number; billing_cycle: string;
}

export interface ContentPackage {
  package_id: number; slug: string; name: string; description: string;
  icon: string; color: string; courses_count: number;
  assessments_count: number; certificates_count: number;
  price: number; tags: string[]; is_popular: boolean;
}

export interface ProvisionRequest {
  onboarding_code: string;
  company_name: string;
  admin_email: string;
  admin_first_name: string;
  admin_last_name: string;
  password: string;
  company_size: string;
  industry: string;
  phone?: string;
  plan_id: number;
  content_packages: string[]; // slugs
}

export interface ProvisionResponse {
  tenant_id: number;
  subdomain: string;
  domain: string;
  admin_email: string;
  tenant_code: string;
}

@Injectable({ providedIn: 'root' })
export class OnboardingService {
  /**
   * Still the legacy endpoint.
   *
   * Onboarding is choosing a plan and asking for an organisation to be
   * created, and the new API has nothing for that yet — provisioning is
   * `bin/console tenant:provision`, run by hand. Named here rather than
   * reached through a shared constant so it is obvious this one page has
   * not moved with the others.
   */
  private apiBase = 'https://api.centrallms.com/api/onboarding.php';

  constructor(private http: HttpClient) {}

  /** Validate an onboarding code */
  validateCode(code: string): Observable<{ valid: boolean; error?: string; allowed_plan_ids?: number[] | null }> {
    return this.http.post<any>(this.apiBase, { action: 'validate-code', code }).pipe(
      map(res => res.data || res),
      catchError(() => of({ valid: false, error: 'Network error. Please try again.' }))
    );
  }

  /** Get available plans */
  getPlans(): Observable<Plan[]> {
    return this.http.post<any>(this.apiBase, { action: 'plans' }).pipe(
      map(res => res.data || []),
      catchError(() => of([]))
    );
  }

  /** Get available content packages */
  getContentPackages(): Observable<ContentPackage[]> {
    return this.http.post<any>(this.apiBase, { action: 'content-packages' }).pipe(
      map(res => res.data || []),
      catchError(() => of([]))
    );
  }

  /** Check subdomain availability */
  checkSubdomain(subdomain: string): Observable<{ available: boolean; subdomain: string; error?: string }> {
    return this.http.post<any>(this.apiBase, { action: 'check-subdomain', subdomain }).pipe(
      map(res => res.data || res),
      catchError(() => of({ available: false, subdomain, error: 'Check failed' }))
    );
  }

  /** Check email availability */
  checkEmail(email: string): Observable<{ available: boolean }> {
    return this.http.post<any>(this.apiBase, { action: 'check-email', email }).pipe(
      map(res => res.data || res),
      catchError(() => of({ available: true })) // Fail open - real check happens on provision
    );
  }

  /** Full tenant provisioning */
  provision(data: ProvisionRequest): Observable<{ success: boolean; data?: ProvisionResponse; error?: string }> {
    return this.http.post<any>(this.apiBase, { action: 'provision', ...data }).pipe(
      map(res => ({
        success: res.status === 'success',
        data: res.data || null,
        error: res.status !== 'success' ? (res.message || 'Provisioning failed') : undefined
      })),
      catchError(err => of({
        success: false,
        error: err?.error?.message || 'Network error. Please try again.'
      }))
    );
  }
}
