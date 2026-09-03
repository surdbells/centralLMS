/**
 * The shapes the operator API speaks, one file so a screen and the service
 * cannot disagree about them.
 *
 * These mirror what the API writes — camelCase, money in minor units, a null
 * limit meaning unlimited — and nothing here is invented client-side.
 */

/** The four states an organisation moves between. */
export type TenantStatus = 'trial' | 'active' | 'suspended' | 'cancelled';

/** A plan as it appears on a tenant — just enough to name it. */
export interface PlanRef {
  readonly id: string;
  readonly code: string;
  readonly name: string;
}

/** One organisation in the operator's list. */
export interface TenantRow {
  readonly id: string;
  readonly name: string;
  readonly code: string;
  readonly domain: string | null;
  readonly status: TenantStatus;
  readonly contactEmail: string | null;
  readonly usersLimit: number | null;
  readonly storageLimitMb: number | null;
  readonly userCount: number;
  readonly plan: PlanRef | null;
  readonly subscriptionExpiresAt: string | null;
  readonly createdAt: string | null;
}

/** The administrator an organisation was provisioned with. */
export interface TenantAdmin {
  readonly id: string;
  readonly name: string | null;
  readonly email: string | null;
}

/** One organisation in full, as the detail screen reads it. */
export interface TenantDetail extends TenantRow {
  readonly contactPhone: string | null;
  readonly industry: string | null;
  readonly companySize: string | null;
  readonly logoUrl: string | null;
  readonly address: string | null;
  readonly provisionedAt: string | null;
  readonly courseCount: number;
  readonly storageMb: number;
  readonly admin: TenantAdmin | null;
}

/** The platform-wide figures behind the dashboard. */
export interface PlatformMetrics {
  readonly tenants: {
    readonly total: number;
    readonly active: number;
    readonly trial: number;
    readonly suspended: number;
    readonly cancelled: number;
  };
  readonly users: number;
  readonly storageMb: number;
  /** Monthly recurring revenue, in the plan currency's minor units. */
  readonly mrr: number;
  readonly byPlan: ReadonlyArray<{ readonly plan: string; readonly tenants: number }>;
}

/** A plan in the catalogue. Prices in minor units; a null limit is unlimited. */
export interface Plan {
  readonly id: string;
  readonly code: string;
  readonly name: string;
  readonly description: string | null;
  readonly priceMonthly: number;
  readonly priceYearly: number;
  readonly currency: string;
  readonly usersLimit: number | null;
  readonly storageLimitMb: number | null;
  readonly features: Record<string, unknown>;
  readonly trialDays: number;
  readonly isActive: boolean;
  readonly isPublic: boolean;
  readonly sortOrder: number;
  readonly createdAt: string | null;
}

/** What a paged collection carries alongside its items. */
export interface PageMeta {
  readonly total: number;
  readonly page: number;
  readonly perPage: number;
  readonly totalPages: number;
}

export interface Paged<T> {
  readonly items: readonly T[];
  readonly meta: PageMeta;
}

/** What the operator sends to create an organisation. */
export interface ProvisionRequest {
  readonly name: string;
  readonly code: string;
  readonly contactEmail: string;
  readonly adminFirstName: string;
  readonly adminLastName: string;
  readonly password?: string;
  readonly planId?: string | null;
  readonly status?: TenantStatus;
}

/** What provisioning returns — the organisation, and a password shown once. */
export interface ProvisionResult {
  readonly tenant: TenantDetail;
  /** Present only when the API generated it; never stored, shown once. */
  readonly generatedPassword: string | null;
}

/** An onboarding code that gates public self-service sign-up. */
export interface OnboardingCode {
  readonly id: string;
  readonly code: string;
  readonly description: string | null;
  /** null = unlimited uses. */
  readonly maxUses: number | null;
  readonly usedCount: number;
  /** null when unlimited, else uses left. */
  readonly remainingUses: number | null;
  /** null = every plan; otherwise the plan ids this code unlocks. */
  readonly allowedPlanIds: readonly string[] | null;
  readonly validFrom: string | null;
  readonly validUntil: string | null;
  readonly isActive: boolean;
  readonly createdAt: string | null;
}

/** The fields an onboarding code is created or edited with. */
export interface OnboardingCodeInput {
  readonly code: string;
  readonly description?: string | null;
  readonly maxUses: number | null;
  readonly allowedPlanIds: readonly string[] | null;
  readonly validFrom: string | null;
  readonly validUntil: string | null;
  readonly isActive: boolean;
}

/** The fields a plan is created or edited with. */
export interface PlanInput {
  readonly code?: string;
  readonly name: string;
  readonly description?: string | null;
  readonly priceMonthly: number;
  readonly priceYearly: number;
  readonly currency: string;
  readonly usersLimit: number | null;
  readonly storageLimitMb: number | null;
  readonly trialDays: number;
  readonly isActive: boolean;
  readonly isPublic: boolean;
  readonly sortOrder: number;
}
