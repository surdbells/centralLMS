import type { TenantStatus } from './models';

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
