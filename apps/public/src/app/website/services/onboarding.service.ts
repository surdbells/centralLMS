// website/services/onboarding.service.ts

import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, of } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { environment } from '../../../environments/environment';

/** A plan as the new API's public catalogue returns it. Money in minor units. */
export interface Plan {
  id: string;
  code: string;
  name: string;
  description: string | null;
  priceMonthly: number;
  priceYearly: number;
  currency: string;
  usersLimit: number | null;
  storageLimitMb: number | null;
  features: Record<string, unknown>;
  trialDays: number;
  isActive: boolean;
  isPublic: boolean;
  sortOrder: number;
}

export interface CodeCheck {
  valid: boolean;
  error?: string;
  description?: string | null;
  /** null = every plan; otherwise the plan ids this code unlocks. */
  allowedPlanIds?: string[] | null;
}

export interface ProvisionRequest {
  onboardingCode: string;
  companyName: string;
  adminEmail: string;
  adminFirstName: string;
  adminLastName: string;
  password: string;
  planId: string;
  industry?: string;
  companySize?: string;
  phone?: string;
}

export interface ProvisionedTenant {
  id: string;
  name: string;
  code: string;
  status: string;
  adminEmail: string;
  plan: { id: string; name: string };
}

/** Field-level messages the API returns on a 422, keyed by field. */
export type FieldErrors = Record<string, string>;

@Injectable({ providedIn: 'root' })
export class OnboardingService {
  private readonly http = inject(HttpClient);

  /**
   * The new API — self-service now runs against it, not the legacy
   * `onboarding.php`. Sign-up is gated by an operator-issued code, so there is
   * no content-package or subdomain-availability call any more; the workspace
   * code is derived and made unique server-side.
   */
  private readonly api = environment.apiBaseUrl;

  /** The plans on offer, from the public catalogue. */
  getPlans(): Observable<Plan[]> {
    return this.http.get<{ data: { plans: Plan[] } }>(`${this.api}/plans`).pipe(
      map((res) => res.data?.plans ?? []),
      catchError(() => of([])),
    );
  }

  /** Whether an onboarding code may be used, and which plans it unlocks. */
  validateCode(code: string): Observable<CodeCheck> {
    return this.http.post<{ data: CodeCheck }>(`${this.api}/onboarding/validate-code`, { code }).pipe(
      map((res) => res.data),
      catchError(() => of({ valid: false, error: 'Could not reach the server. Please try again.' })),
    );
  }

  /**
   * Creates the organisation. Resolves to the new tenant, or the field errors /
   * a message explaining why not — the wizard shows those against the step.
   */
  provision(
    data: ProvisionRequest,
  ): Observable<{ success: boolean; tenant?: ProvisionedTenant; error?: string; fields?: FieldErrors }> {
    return this.http.post<{ data: { tenant: ProvisionedTenant } }>(`${this.api}/onboarding/register`, data).pipe(
      map((res) => ({ success: true, tenant: res.data.tenant })),
      catchError((failure: unknown) => {
        const fields = fieldErrors(failure);

        return of({
          success: false,
          fields: Object.keys(fields).length ? fields : undefined,
          error: message(failure, 'Something went wrong creating your workspace. Please try again.'),
        });
      }),
    );
  }
}

// --- error-envelope helpers --------------------------------------------------

interface ApiFailure {
  error?: { error?: { message?: string; details?: { violations?: Record<string, unknown> } } };
}

function violations(failure: unknown): Record<string, unknown> {
  const body = (failure as ApiFailure | null)?.error?.error;

  return body?.details?.violations && typeof body.details.violations === 'object'
    ? body.details.violations
    : {};
}

function fieldErrors(failure: unknown): FieldErrors {
  const out: FieldErrors = {};
  for (const [field, msg] of Object.entries(violations(failure))) {
    if (typeof msg === 'string') {
      out[field] = msg;
    } else if (Array.isArray(msg) && typeof msg[0] === 'string') {
      out[field] = msg[0];
    }
  }

  return out;
}

function message(failure: unknown, fallback: string): string {
  const body = (failure as ApiFailure | null)?.error?.error;
  if (typeof body?.message === 'string' && body.message.trim() !== '') {
    return body.message;
  }
  const first = Object.values(fieldErrors(failure))[0];

  return first ?? fallback;
}
