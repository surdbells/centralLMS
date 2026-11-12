import { Injectable, inject, signal } from '@angular/core';
import { Observable, catchError, map, of, shareReplay, tap } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';

// ---------------------------------------------------------------------------
// Types — mirror what /billing returns. Money is in minor units (kobo).
// ---------------------------------------------------------------------------

export type SubscriptionStatus = 'trialing' | 'active' | 'past_due' | 'canceled';
export type BillingCycle = 'monthly' | 'yearly';
export type InvoiceStatus = 'draft' | 'open' | 'paid' | 'void' | 'uncollectible';

export interface Subscription {
  id: string;
  planId: string | null;
  planName: string | null;
  planFeatures: Record<string, unknown>;
  billingCycle: BillingCycle;
  status: SubscriptionStatus;
  amount: number;
  currency: string;
  currentPeriodStart: string | null;
  currentPeriodEnd: string | null;
  trialEndsAt: string | null;
  cancelAtPeriodEnd: boolean;
  canceledAt: string | null;
  startedAt: string | null;
}

export interface Invoice {
  id: string;
  number: string;
  status: InvoiceStatus;
  description: string | null;
  amountDue: number;
  amountPaid: number;
  currency: string;
  periodStart: string | null;
  periodEnd: string | null;
  issuedAt: string | null;
  dueAt: string | null;
  paidAt: string | null;
}

export interface Billing {
  subscription: Subscription | null;
  invoices: Invoice[];
}

/** Where to send the payer to settle an invoice. */
export interface PaymentStart {
  reference: string;
  authorizationUrl: string | null;
}

/** The outcome of confirming a payment on return from the gateway. */
export interface PaymentOutcome {
  paid: boolean;
  status: string;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * The organisation's own billing.
 *
 * Read-only: what plan it is on, where the period runs to, and its invoices.
 * Changing the plan or paying an invoice is the operator's to do until the
 * payment gateway lands, so there is nothing to write here yet.
 */
@Injectable({ providedIn: 'root' })
export class BillingService {
  private readonly api = inject(ApiClient);

  /**
   * Whether this deployment bills its organisations at all — `billingEnabled`
   * from `GET /settings/public`. False on a dedicated single-tenant install,
   * where the Billing page and its nav item are hidden. Starts false so
   * nothing billing-shaped flashes up before the answer arrives.
   */
  readonly enabled = signal(false);

  /** The settled answer, once the API has given one. */
  private known: boolean | null = null;
  private pending$: Observable<boolean> | null = null;

  /**
   * Resolves (once, then from memory) whether billing exists on this
   * deployment. A failed lookup answers false for now but is not remembered,
   * so the next caller asks again rather than hiding billing for the session.
   */
  availability(): Observable<boolean> {
    if (this.known !== null) {
      return of(this.known);
    }

    if (this.pending$ === null) {
      this.pending$ = this.api.get<{ billingEnabled?: boolean }>(Endpoints.settings.public).pipe(
        map((settings) => settings.billingEnabled === true),
        tap((enabled) => {
          this.known = enabled;
          this.enabled.set(enabled);
        }),
        catchError(() => {
          this.pending$ = null;
          return of(false);
        }),
        shareReplay(1),
      );
    }

    return this.pending$;
  }

  show(): Observable<Billing> {
    return this.api.get<Billing>(Endpoints.billing.show);
  }

  /** Begins paying an invoice; the caller sends the payer to the returned URL. */
  pay(invoiceId: string): Observable<PaymentStart> {
    return this.api.post<PaymentStart>(Endpoints.billing.pay(invoiceId), {});
  }

  /** Confirms a payment once the payer is back from the gateway. */
  confirm(reference: string): Observable<PaymentOutcome> {
    return this.api.post<PaymentOutcome>(Endpoints.billing.confirmPayment, { reference });
  }
}
