/**
 * Turning the API's raw units into something a person reads.
 *
 * Money arrives in minor units (kobo for NGN) so it is never a float; storage
 * arrives in whole megabytes; dates arrive as ISO strings. Each of these is a
 * pure function so a template can call it without a pipe.
 */

const CURRENCY_SYMBOL: Record<string, string> = {
  NGN: '₦',
  USD: '$',
  GBP: '£',
  EUR: '€',
};

/** Minor units to a currency string — `2500000, 'NGN'` → `₦25,000`. */
export function money(minor: number, currency = 'NGN'): string {
  const symbol = CURRENCY_SYMBOL[currency] ?? (currency + ' ');
  const major = minor / 100;
  const whole = Number.isInteger(major)
    ? major.toLocaleString('en-US')
    : major.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  return symbol + whole;
}

/** Whole megabytes to a human size — `51200` → `50 GB`, `5120` → `5 GB`. */
export function storage(mb: number | null): string {
  if (mb === null) {
    return 'Unlimited';
  }
  if (mb < 1024) {
    return `${mb.toLocaleString('en-US')} MB`;
  }

  const gb = mb / 1024;
  if (gb < 1024) {
    return `${trim(gb)} GB`;
  }

  return `${trim(gb / 1024)} TB`;
}

/** A count against its ceiling — `12, 25` → `12 / 25`, unlimited when null. */
export function ofLimit(count: number, limit: number | null): string {
  return limit === null ? `${count.toLocaleString('en-US')} / ∞` : `${count} / ${limit}`;
}

/** An ISO timestamp as a plain date — `2026-09-03T…` → `3 Sep 2026`. */
export function date(iso: string | null): string {
  if (iso === null || iso === '') {
    return '—';
  }

  const parsed = new Date(iso);
  if (Number.isNaN(parsed.getTime())) {
    return '—';
  }

  return parsed.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

/**
 * Whole days from now until a date — positive in the future, negative once
 * past — or null when there is no date.
 *
 * The sign is what the caller branches on ("expired" vs "N days left"), so it
 * must be reliable right up to the boundary. A future part-day rounds up (six
 * hours from now reads as "1 day left"); a past part-day rounds down to −1, not
 * `Math.ceil`'s −0 — which is not `< 0` and would leave a lapsed subscription
 * reading "0 days left" for its whole first day past expiry.
 */
export function daysUntil(iso: string | null): number | null {
  if (iso === null || iso === '') {
    return null;
  }

  const target = new Date(iso).getTime();
  if (Number.isNaN(target)) {
    return null;
  }

  const diff = target - Date.now();
  if (diff === 0) {
    return 0;
  }

  return diff > 0 ? Math.ceil(diff / 86_400_000) : Math.floor(diff / 86_400_000);
}

function trim(value: number): string {
  // One decimal, but no trailing `.0` — `5 GB`, not `5.0 GB`.
  return Number.isInteger(value) ? String(value) : value.toFixed(1);
}
