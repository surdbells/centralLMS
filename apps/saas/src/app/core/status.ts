import type { InvoiceStatus, SubscriptionStatus, TenantStatus } from './models';

/**
 * The badge class for an organisation's state.
 *
 * One mapping, so a status looks the same wherever it appears — the list, the
 * detail, the dashboard. Returns the full class string (base plus modifier) so
 * a template binds it with `[class]` and nothing static competes with it.
 */
export function statusBadgeClass(status: TenantStatus): string {
  return `op-badge op-badge--${status}`;
}

/** A short line describing what a status means, for the detail screen. */
export function statusMeaning(status: TenantStatus): string {
  switch (status) {
    case 'active':
      return 'Live and fully able to sign in and work.';
    case 'trial':
      return 'Evaluating, on a plan trial with an expiry.';
    case 'suspended':
      return 'Temporarily blocked — nobody here can sign in.';
    case 'cancelled':
      return 'Closed. Kept for the record, not in use.';
  }
}

/** The badge class for a subscription's state, reusing the shared modifiers. */
export function subscriptionBadgeClass(status: SubscriptionStatus): string {
  const modifier: Record<SubscriptionStatus, string> = {
    trialing: 'trial',
    active: 'active',
    past_due: 'suspended',
    canceled: 'cancelled',
  };

  return `op-badge op-badge--${modifier[status]}`;
}

/** The badge class for an invoice's state. */
export function invoiceBadgeClass(status: InvoiceStatus): string {
  const modifier: Record<InvoiceStatus, string> = {
    paid: 'active',
    open: 'trial',
    draft: 'muted',
    void: 'muted',
    uncollectible: 'cancelled',
  };

  return `op-badge op-badge--${modifier[status]}`;
}

/** Subscription status as a plain word for display. */
export function subscriptionLabel(status: SubscriptionStatus): string {
  return status === 'past_due' ? 'past due' : status;
}
