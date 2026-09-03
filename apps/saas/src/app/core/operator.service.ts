import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { ApiClient } from './api.client';
import type {
  Paged,
  Plan,
  PlanInput,
  PlatformMetrics,
  ProvisionRequest,
  ProvisionResult,
  TenantDetail,
  TenantRow,
  TenantStatus,
} from './models';

/** The filters the tenant list is read with. */
export interface TenantQuery {
  readonly search?: string;
  readonly status?: TenantStatus | '';
  readonly page?: number;
  readonly perPage?: number;
}

/**
 * Every operator call, in one service.
 *
 * A thin mapping onto the `/operator/*` routes — the API does the work and
 * enforces the super-admin gate; this just names each call and hands back the
 * domain shape. The plan catalogue routes answer `{plans: […]}`, unwrapped
 * here so callers get a plain array.
 */
@Injectable({ providedIn: 'root' })
export class OperatorService {
  private readonly api = inject(ApiClient);

  // --- dashboard ------------------------------------------------------------

  metrics(): Observable<PlatformMetrics> {
    return this.api.get<PlatformMetrics>('/operator/dashboard');
  }

  // --- organisations --------------------------------------------------------

  tenants(query: TenantQuery = {}): Observable<Paged<TenantRow>> {
    return this.api.getPaged<TenantRow>('/operator/tenants', {
      search: query.search,
      status: query.status,
      page: query.page ?? 1,
      perPage: query.perPage ?? 25,
    });
  }

  tenant(id: string): Observable<TenantDetail> {
    return this.api.get<TenantDetail>(`/operator/tenants/${id}`);
  }

  provision(request: ProvisionRequest): Observable<ProvisionResult> {
    return this.api.post<ProvisionResult>('/operator/tenants', request);
  }

  setStatus(id: string, status: TenantStatus): Observable<TenantDetail> {
    return this.api.put<TenantDetail>(`/operator/tenants/${id}/status`, { status });
  }

  setLimits(id: string, usersLimit: number | null, storageLimitMb: number | null): Observable<TenantDetail> {
    return this.api.put<TenantDetail>(`/operator/tenants/${id}/limits`, { usersLimit, storageLimitMb });
  }

  assignPlan(id: string, planId: string | null): Observable<TenantDetail> {
    return this.api.put<TenantDetail>(`/operator/tenants/${id}/plan`, { planId });
  }

  // --- plans ----------------------------------------------------------------

  /** The whole catalogue, retired plans included (operator view). */
  plans(): Observable<readonly Plan[]> {
    return this.api.get<{ plans: readonly Plan[] }>('/operator/plans').pipe(map((r) => r.plans));
  }

  createPlan(input: PlanInput): Observable<Plan> {
    return this.api.post<Plan>('/operator/plans', input);
  }

  updatePlan(id: string, input: PlanInput): Observable<Plan> {
    return this.api.put<Plan>(`/operator/plans/${id}`, input);
  }

  deletePlan(id: string): Observable<void> {
    return this.api.delete(`/operator/plans/${id}`);
  }
}
