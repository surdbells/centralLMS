import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
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
