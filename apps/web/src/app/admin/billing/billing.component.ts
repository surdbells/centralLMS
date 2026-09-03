import { Component, DestroyRef, OnInit, computed, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, Router } from '@angular/router';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { BillingService, type Billing, type Invoice } from '../../services/billing.service';
import { TenantService, type Tenant } from '../../services/tenant.service';

/**
 * The organisation's billing, for its administrator.
 *
 * Read-only: the plan it is on, where the current period runs to, how much of
 * its allowance it is using, and its invoices. Changing a plan or settling an
 * invoice is the operator's until the payment gateway lands — so this shows,
 * it does not act.
 */
@Component({
  selector: 'app-billing',
  standalone: true,
  imports: [CommonModule, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>
    <main class="main px-lg-6">
      <div class="container-fluid py-4">
        <div class="row align-items-center mb-4">
          <div (click)="goBack()" role="button" class="col-auto">
            <div class="avatar text-center avatar-md rounded text-primary-emphasis">
              <i class="bi bi-chevron-left fs-5"></i>
            </div>
          </div>
          <div class="col">
            <h1 class="fs-4 mb-0">Billing &amp; Subscription</h1>
            <small class="text-muted">Your plan, your invoices, and what your organisation is using.</small>
          </div>
        </div>

        @if (payBanner(); as pb) {
          <div class="alert d-flex align-items-center gap-2" [class.alert-success]="pb.ok" [class.alert-warning]="!pb.ok">
            <i class="bi" [class.bi-check-circle-fill]="pb.ok" [class.bi-exclamation-circle-fill]="!pb.ok"></i>
            {{ pb.text }}
            <button class="btn-close ms-auto" (click)="payBanner.set(null)"></button>
          </div>
        }

        @if (loading()) {
          <div class="text-center py-5"><div class="spinner-border text-primary"></div></div>
        } @else if (error()) {
          <div class="alert alert-danger d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill"></i> Could not load your billing.
            <button class="btn btn-sm btn-outline-danger ms-auto" (click)="load()">Retry</button>
          </div>
        } @else {
          <div class="row g-4">
            <!-- Subscription -->
            <div class="col-lg-7">
              <div class="card h-100">
                <div class="card-header bg-transparent fw-semibold">Your plan</div>
                <div class="card-body">
                  @if (sub(); as s) {
                    <div class="d-flex justify-content-between align-items-start mb-3">
                      <div>
                        <div class="fs-4 fw-bold">{{ s.planName || 'Custom plan' }}</div>
                        <span class="badge {{ subBadge(s.status) }}">{{ label(s.status) }}</span>
                        @if (s.cancelAtPeriodEnd) { <span class="badge bg-warning text-dark ms-1">Ends at period</span> }
                      </div>
                      <div class="text-end">
                        <div class="fs-4 fw-bold">{{ s.amount > 0 ? money(s.amount, s.currency) : 'Free' }}</div>
                        <div class="text-muted small">per {{ s.billingCycle === 'yearly' ? 'year' : 'month' }}</div>
                      </div>
                    </div>
                    <dl class="row mb-0 small">
                      @if (s.status === 'trialing' && s.trialEndsAt) {
                        <dt class="col-5 text-muted fw-normal">Trial ends</dt>
                        <dd class="col-7 fw-semibold">{{ s.trialEndsAt | date:'d MMM y' }} ({{ daysLeft(s.trialEndsAt) }})</dd>
                      }
                      <dt class="col-5 text-muted fw-normal">Current period</dt>
                      <dd class="col-7">{{ s.currentPeriodStart | date:'d MMM y' }} – {{ s.currentPeriodEnd | date:'d MMM y' }}</dd>
                      @if (s.status === 'active' && !s.cancelAtPeriodEnd && s.currentPeriodEnd) {
                        <dt class="col-5 text-muted fw-normal">Renews</dt>
                        <dd class="col-7">{{ s.currentPeriodEnd | date:'d MMM y' }}</dd>
                      }
                      <dt class="col-5 text-muted fw-normal">Since</dt>
                      <dd class="col-7">{{ s.startedAt | date:'d MMM y' }}</dd>
                    </dl>
                    <div class="alert alert-light border mt-3 mb-0 small d-flex gap-2">
                      <i class="bi bi-info-circle"></i>
                      <span>To change your plan, contact your account manager. Outstanding invoices can be paid below.</span>
                    </div>
                  } @else {
                    <div class="text-center text-muted py-4">
                      <i class="bi bi-credit-card fs-2 d-block mb-2 opacity-50"></i>
                      You’re not on a plan yet. Contact us to get started.
                    </div>
                  }
                </div>
              </div>
            </div>

            <!-- Usage -->
            <div class="col-lg-5">
              <div class="card h-100">
                <div class="card-header bg-transparent fw-semibold">Usage</div>
                <div class="card-body">
                  @if (tenant(); as t) {
                    <div class="mb-3">
                      <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted">People</span>
                        <span class="fw-semibold">{{ t.usage.users | number }} / {{ t.limits.users === null ? '∞' : (t.limits.users | number) }}</span>
                      </div>
                      <div class="progress" style="height:6px"><div class="progress-bar" [style.width.%]="pct(t.usage.users, t.limits.users)"></div></div>
                    </div>
                    <div class="mb-3">
                      <div class="d-flex justify-content-between small mb-1">
                        <span class="text-muted">Storage</span>
                        <span class="fw-semibold">{{ storage(t.usage.storageMb) }} / {{ t.limits.storage === null ? '∞' : storage(t.limits.storage) }}</span>
                      </div>
                      <div class="progress" style="height:6px"><div class="progress-bar bg-info" [style.width.%]="pct(t.usage.storageMb, t.limits.storage)"></div></div>
                    </div>
                    <div class="d-flex justify-content-between small">
                      <span class="text-muted">Courses</span>
                      <span class="fw-semibold">{{ t.usage.courses | number }}</span>
                    </div>
                  } @else {
                    <div class="text-muted small">Usage is unavailable.</div>
                  }
                </div>
              </div>
            </div>

            <!-- Invoices -->
            <div class="col-12">
              <div class="card">
                <div class="card-header bg-transparent fw-semibold">Invoices</div>
                <div class="card-body p-0">
                  @if (invoices().length === 0) {
                    <div class="text-center text-muted py-4"><i class="bi bi-receipt fs-4 d-block mb-2 opacity-50"></i> No invoices yet.</div>
                  } @else {
                    <div class="table-responsive">
                      <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                          <tr><th>Invoice</th><th>Description</th><th class="text-end">Amount</th><th>Status</th><th>Issued</th><th>Due</th><th></th></tr>
                        </thead>
                        <tbody>
                          @for (inv of invoices(); track inv.id) {
                            <tr>
                              <td class="font-monospace small">{{ inv.number }}</td>
                              <td class="small text-muted">{{ inv.description || '—' }}</td>
                              <td class="text-end fw-semibold">{{ money(inv.amountDue, inv.currency) }}</td>
                              <td><span class="badge {{ invBadge(inv.status) }}">{{ inv.status }}</span></td>
                              <td class="small">{{ inv.issuedAt | date:'d MMM y' }}</td>
                              <td class="small">{{ inv.status === 'paid' ? (inv.paidAt | date:'d MMM y') : (inv.dueAt | date:'d MMM y') }}</td>
                              <td class="text-end">
                                @if (inv.status === 'open' || inv.status === 'draft') {
                                  <button class="btn btn-sm btn-success" [disabled]="paying() !== null" (click)="payInvoice(inv)">
                                    @if (paying() === inv.id) { <span class="spinner-border spinner-border-sm"></span> }
                                    @else { <i class="bi bi-credit-card me-1"></i>Pay now }
                                  </button>
                                }
                              </td>
                            </tr>
                          }
                        </tbody>
                      </table>
                    </div>
                  }
                </div>
              </div>
            </div>
          </div>
        }
      </div>
    </main>
  `,
})
export class BillingComponent implements OnInit {
  private readonly billing = inject(BillingService);
  private readonly tenants = inject(TenantService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly destroyRef = inject(DestroyRef);

  private readonly data = signal<Billing | null>(null);
  readonly tenant = signal<Tenant | null>(null);
  readonly loading = signal(true);
  readonly error = signal(false);
  /** The invoice a payment is being started for, so its button spins. */
  readonly paying = signal<string | null>(null);
  readonly payBanner = signal<{ ok: boolean; text: string } | null>(null);

  readonly sub = computed(() => this.data()?.subscription ?? null);
  readonly invoices = computed(() => this.data()?.invoices ?? []);

  ngOnInit(): void {
    this.load();
    this.confirmReturnFromGateway();
  }

  /** Sends the payer to the gateway to settle an outstanding invoice. */
  payInvoice(inv: Invoice): void {
    if (this.paying()) {
      return;
    }
    this.paying.set(inv.id);
    this.payBanner.set(null);

    // Tear down on navigate-away: without this a slow response could redirect
    // the whole browser to the gateway from a page the user already left.
    this.billing.pay(inv.id).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (start) => {
        if (start.authorizationUrl) {
          // Off to the gateway's hosted checkout; it returns to this page.
          window.location.href = start.authorizationUrl;
        } else {
          this.paying.set(null);
          this.payBanner.set({ ok: false, text: 'Could not start the payment. Please try again.' });
        }
      },
      error: () => {
        this.paying.set(null);
        this.payBanner.set({ ok: false, text: 'Payments are unavailable right now. Please try again shortly.' });
      },
    });
  }

  /**
   * When the gateway returns the payer here it appends the reference. Confirm
   * it, then strip it from the URL so a refresh does not re-confirm.
   */
  private confirmReturnFromGateway(): void {
    const params = this.route.snapshot.queryParamMap;
    const reference = params.get('reference') ?? params.get('trxref');
    if (!reference) {
      return;
    }

    void this.router.navigate([], { relativeTo: this.route, queryParams: {}, replaceUrl: true });

    this.billing.confirm(reference).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (outcome) => {
        this.payBanner.set(outcome.paid
          ? { ok: true, text: 'Payment received — thank you. Your invoice is now settled.' }
          : { ok: false, text: 'That payment did not complete. You can try paying again.' });
        this.load();
      },
      // A charge may still land by webhook, so this is reassuring, not alarming.
      error: () => this.payBanner.set({
        ok: false,
        text: 'We could not confirm the payment just now. If you were charged, it will update here shortly.',
      }),
    });
  }

  load(): void {
    this.loading.set(true);
    this.error.set(false);

    this.billing.show().pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (b) => {
        this.data.set(b);
        this.loading.set(false);
      },
      error: () => {
        this.error.set(true);
        this.loading.set(false);
      },
    });

    // Usage is a bonus; a failure here should not blank the page.
    this.tenants.show().pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({ next: (t) => this.tenant.set(t), error: () => this.tenant.set(null) });
  }

  goBack(): void {
    void this.router.navigate(['/administration']);
  }

  money(minor: number, currency: string): string {
    const symbol = currency === 'NGN' ? '₦' : currency === 'USD' ? '$' : currency + ' ';
    const major = minor / 100;
    return symbol + (Number.isInteger(major) ? major.toLocaleString('en-US') : major.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
  }

  storage(mb: number): string {
    if (mb < 1024) {
      return `${mb} MB`;
    }
    // One decimal, no trailing .0 — so "1.5 GB" reads honestly against the
    // progress bar rather than rounding 1536 MB up to a full "2 GB".
    const gb = mb / 1024;
    return `${Number.isInteger(gb) ? gb : gb.toFixed(1)} GB`;
  }

  pct(used: number, limit: number | null): number {
    if (limit === null || limit <= 0) {
      return 0;
    }
    return Math.min(100, Math.round((used / limit) * 100));
  }

  daysLeft(iso: string): string {
    const diff = new Date(iso).getTime() - Date.now();
    // Guard the sign before rounding: Math.ceil of a small negative is -0,
    // which is not < 0, so a just-lapsed trial would read "0 days left".
    if (diff <= 0) {
      return 'expired';
    }
    const days = Math.ceil(diff / 86_400_000);
    return `${days} day${days === 1 ? '' : 's'} left`;
  }

  label(status: string): string {
    return status === 'past_due' ? 'past due' : status;
  }

  subBadge(status: string): string {
    switch (status) {
      case 'active': return 'bg-success';
      case 'trialing': return 'bg-info';
      case 'past_due': return 'bg-warning text-dark';
      default: return 'bg-secondary';
    }
  }

  invBadge(status: string): string {
    switch (status) {
      case 'paid': return 'bg-success';
      case 'open': return 'bg-warning text-dark';
      case 'uncollectible': return 'bg-danger';
      default: return 'bg-secondary';
    }
  }
}
