import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { OperatorService } from '../core/operator.service';
import type { Plan, TenantDetail, TenantStatus } from '../core/models';
import { date, daysUntil, money, ofLimit, storage } from '../core/format';
import { statusBadgeClass, statusMeaning } from '../core/status';
import { firstErrorMessage } from '../core/errors';
import { environment } from '../../environments/environment';

const STATUSES: readonly TenantStatus[] = ['active', 'trial', 'suspended', 'cancelled'];

/**
 * One organisation, in full, and the levers over it.
 *
 * Everything the operator can do to a single tenant lives here: move it
 * between states, set a bespoke allowance, and put it on (or take it off) a
 * plan. Each action calls the API and re-reads the organisation from what
 * comes back, so the screen never drifts from the record.
 */
@Component({
  selector: 'app-tenant-detail',
  standalone: true,
  imports: [FormsModule, RouterLink, DecimalPipe],
  template: `
    @if (loading()) {
      <header class="op-topbar"><h1 class="op-topbar__title">Organisation</h1></header>
      <div class="op-content"><div class="op-loading"><span class="op-spinner"></span> Loading…</div></div>
    } @else if (error()) {
      <header class="op-topbar">
        <a routerLink="/tenants" class="op-btn op-btn--ghost op-btn--sm"><i class="bi bi-chevron-left"></i> Organisations</a>
      </header>
      <div class="op-content">
        <div class="op-alert op-alert--danger"><i class="bi bi-exclamation-triangle-fill"></i>
          <span>{{ error() }}</span></div>
      </div>
    } @else if (tenant()) {
      @if (tenant(); as t) {
      <header class="op-topbar">
        <div style="display:flex;align-items:center;gap:1rem;min-width:0">
          <a routerLink="/tenants" class="op-btn op-btn--ghost op-btn--sm"><i class="bi bi-chevron-left"></i></a>
          <div style="min-width:0">
            <h1 class="op-topbar__title" style="display:flex;align-items:center;gap:0.6rem">
              {{ t.name }} <span [class]="badgeClass(t.status)">{{ t.status }}</span>
            </h1>
            <p class="op-topbar__sub"><span class="op-table__code">{{ t.code }}</span>
              @if (t.domain) { · {{ t.domain }} }</p>
          </div>
        </div>
      </header>

      <div class="op-content">
        @if (banner(); as b) {
          <div class="op-alert" [class.op-alert--ok]="b.ok" [class.op-alert--danger]="!b.ok" style="margin-bottom:1.25rem">
            <i class="bi" [class.bi-check-circle-fill]="b.ok" [class.bi-exclamation-triangle-fill]="!b.ok"></i>
            <span>{{ b.text }}</span>
          </div>
        }

        <div class="op-grid">
          <!-- Status -->
          <div class="op-card">
            <div class="op-card__head"><h2 class="op-card__title">Status</h2></div>
            <div class="op-card__body">
              <p style="margin-top:0;color:var(--op-ink-soft);font-size:0.88rem">{{ meaning(t.status) }}</p>
              <div class="op-status-grid">
                @for (s of statuses; track s) {
                  <button class="op-btn op-btn--sm" [class.op-btn--primary]="s === t.status"
                          [disabled]="savingStatus() || s === t.status" (click)="setStatus(s)">
                    {{ s }}
                  </button>
                }
              </div>
              @if (t.subscriptionExpiresAt) {
                <div class="op-field__hint" style="margin-top:0.75rem">
                  Subscription expires {{ fmtDate(t.subscriptionExpiresAt) }}
                  @if (expiryDays(t) !== null) {
                    <span [style.color]="expiryDays(t)! < 0 ? 'var(--op-danger)' : 'var(--op-ink-faint)'">
                      ({{ expiryDays(t)! < 0 ? 'expired' : expiryDays(t) + ' days left' }})
                    </span>
                  }
                </div>
              }
            </div>
          </div>

          <!-- Plan -->
          <div class="op-card">
            <div class="op-card__head"><h2 class="op-card__title">Plan</h2></div>
            <div class="op-card__body">
              @if (t.plan) {
                <div style="display:flex;align-items:baseline;justify-content:space-between;gap:0.5rem">
                  <b style="font-size:1.05rem">{{ t.plan.name }}</b>
                  @if (planPrice(t.plan.id); as p) { <span style="color:var(--op-ink-soft)">{{ p }}</span> }
                </div>
              } @else {
                <p style="margin-top:0;color:var(--op-ink-faint)">Not on a plan.</p>
              }
              <div class="op-field" style="margin-top:0.75rem;margin-bottom:0">
                <label class="op-field__label" for="planSel">Change plan</label>
                <div style="display:flex;gap:0.5rem">
                  <select id="planSel" class="op-select" [(ngModel)]="selectedPlan" [disabled]="savingPlan()">
                    <option [ngValue]="null">No plan</option>
                    @for (p of plans(); track p.id) { <option [ngValue]="p.id">{{ p.name }}</option> }
                  </select>
                  <button class="op-btn op-btn--primary" [disabled]="savingPlan() || selectedPlan === (t.plan?.id ?? null)"
                          (click)="assignPlan(t)">
                    @if (savingPlan()) { <span class="op-spinner"></span> } @else { Apply }
                  </button>
                </div>
                <div class="op-field__hint">Applying a plan copies its allowances onto this organisation.</div>
              </div>
            </div>
          </div>

          <!-- Usage & limits -->
          <div class="op-card">
            <div class="op-card__head">
              <h2 class="op-card__title">Usage &amp; limits</h2>
              @if (!editingLimits()) {
                <button class="op-btn op-btn--ghost op-btn--sm" (click)="startEditLimits(t)"><i class="bi bi-pencil"></i> Edit</button>
              }
            </div>
            <div class="op-card__body">
              @if (!editingLimits()) {
                <div class="op-meter">
                  <div class="op-meter__label"><i class="bi bi-people"></i> People</div>
                  <div class="op-meter__val">{{ peopleOf(t) }}</div>
                </div>
                <div class="op-meter">
                  <div class="op-meter__label"><i class="bi bi-hdd"></i> Storage</div>
                  <div class="op-meter__val">{{ storageUsed(t) }} <span style="color:var(--op-ink-faint)">of {{ storageLimit(t) }}</span></div>
                </div>
                <div class="op-meter">
                  <div class="op-meter__label"><i class="bi bi-journal-text"></i> Courses</div>
                  <div class="op-meter__val">{{ t.courseCount | number }}</div>
                </div>
              } @else {
                <div class="op-field">
                  <label class="op-field__label" for="uLimit">User limit</label>
                  <input id="uLimit" type="number" min="0" class="op-input" [(ngModel)]="usersLimit"
                         placeholder="Unlimited" [disabled]="savingLimits()" />
                  <div class="op-field__hint">Leave blank for unlimited.</div>
                </div>
                <div class="op-field">
                  <label class="op-field__label" for="sLimit">Storage limit (MB)</label>
                  <input id="sLimit" type="number" min="0" class="op-input" [(ngModel)]="storageLimitMb"
                         placeholder="Unlimited" [disabled]="savingLimits()" />
                  <div class="op-field__hint">Leave blank for unlimited.</div>
                </div>
                <div style="display:flex;gap:0.5rem;justify-content:flex-end">
                  <button class="op-btn op-btn--ghost" (click)="editingLimits.set(false)" [disabled]="savingLimits()">Cancel</button>
                  <button class="op-btn op-btn--primary" (click)="saveLimits(t)" [disabled]="savingLimits()">
                    @if (savingLimits()) { <span class="op-spinner"></span> Saving… } @else { Save limits }
                  </button>
                </div>
              }
            </div>
          </div>

          <!-- Details -->
          <div class="op-card">
            <div class="op-card__head"><h2 class="op-card__title">Details</h2></div>
            <div class="op-card__body">
              <dl class="op-dl">
                <dt>Contact email</dt><dd>{{ t.contactEmail || '—' }}</dd>
                <dt>Phone</dt><dd>{{ t.contactPhone || '—' }}</dd>
                <dt>Industry</dt><dd>{{ t.industry || '—' }}</dd>
                <dt>Company size</dt><dd>{{ t.companySize || '—' }}</dd>
                <dt>Address</dt><dd>{{ t.address || '—' }}</dd>
                <dt>Provisioned</dt><dd>{{ fmtDate(t.provisionedAt) }}</dd>
                <dt>Created</dt><dd>{{ fmtDate(t.createdAt) }}</dd>
              </dl>
            </div>
          </div>

          <!-- Administrator -->
          <div class="op-card">
            <div class="op-card__head"><h2 class="op-card__title">Administrator</h2></div>
            <div class="op-card__body">
              @if (t.admin) {
                <div class="op-who" style="margin-bottom:0">
                  <span class="op-who__avatar" style="background:var(--op-accent);color:#fff">{{ adminInitials(t) }}</span>
                  <div>
                    <div style="font-weight:600">{{ t.admin.name || '—' }}</div>
                    <div style="color:var(--op-ink-soft);font-size:0.85rem">{{ t.admin.email || '—' }}</div>
                  </div>
                </div>
              } @else {
                <p style="margin:0;color:var(--op-ink-faint)">No administrator on record.</p>
              }
              <a [href]="appUrl" target="_blank" rel="noopener" class="op-btn op-btn--ghost op-btn--sm" style="margin-top:1rem">
                <i class="bi bi-box-arrow-up-right"></i> Open the application
              </a>
            </div>
          </div>
        </div>
      </div>
      }
    }
  `,
  styles: [`
    .op-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.25rem; align-items: start; }
    .op-status-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem; }
    .op-status-grid .op-btn { justify-content: center; text-transform: capitalize; }
    .op-meter { display: flex; align-items: center; justify-content: space-between; padding: 0.6rem 0; border-bottom: 1px solid var(--op-border); }
    .op-meter:last-child { border-bottom: none; }
    .op-meter__label { display: flex; align-items: center; gap: 0.5rem; color: var(--op-ink-soft); font-size: 0.9rem; }
    .op-meter__label i { color: var(--op-accent); }
    .op-meter__val { font-weight: 600; }
    .op-dl { display: grid; grid-template-columns: 40% 60%; gap: 0.4rem 0; margin: 0; font-size: 0.9rem; }
    .op-dl dt { color: var(--op-ink-faint); }
    .op-dl dd { margin: 0; font-weight: 500; word-break: break-word; }
  `],
})
export class TenantDetailComponent implements OnInit {
  private readonly ops = inject(OperatorService);
  private readonly route = inject(ActivatedRoute);

  readonly statuses = STATUSES;
  readonly appUrl = environment.appUrl;

  readonly tenant = signal<TenantDetail | null>(null);
  readonly plans = signal<readonly Plan[]>([]);
  readonly loading = signal(true);
  readonly error = signal<string | null>(null);

  readonly savingStatus = signal(false);
  readonly savingPlan = signal(false);
  readonly savingLimits = signal(false);
  readonly editingLimits = signal(false);
  readonly banner = signal<{ ok: boolean; text: string } | null>(null);

  selectedPlan: string | null = null;
  usersLimit: number | null = null;
  storageLimitMb: number | null = null;

  private readonly planById = computed(() => {
    const map = new Map<string, Plan>();
    for (const p of this.plans()) {
      map.set(p.id, p);
    }

    return map;
  });

  ngOnInit(): void {
    const id = this.route.snapshot.paramMap.get('id');
    if (!id) {
      this.error.set('No organisation was named.');
      this.loading.set(false);

      return;
    }

    // The plan catalogue feeds the "Change plan" picker and the price line. If
    // it fails on its own the organisation still loads; say plan changes are
    // unavailable rather than leaving an unexplained empty dropdown (and an
    // uncaught error), and let the picker fall back to "No plan".
    this.ops.plans().subscribe({
      next: (all) => this.plans.set(all),
      error: () => this.flash(false, 'Plans could not be loaded — changing this organisation’s plan is unavailable.'),
    });
    this.load(id);
  }

  load(id: string): void {
    this.loading.set(true);
    this.error.set(null);
    this.ops.tenant(id).subscribe({
      next: (t) => this.apply(t),
      error: (failure: unknown) => {
        this.error.set(firstErrorMessage(failure, 'That organisation could not be loaded.'));
        this.loading.set(false);
      },
    });
  }

  setStatus(status: TenantStatus): void {
    const t = this.tenant();
    if (!t || this.savingStatus()) {
      return;
    }
    if ((status === 'suspended' || status === 'cancelled')
      && !confirm(`Move ${t.name} to "${status}"? Nobody there will be able to sign in.`)) {
      return;
    }

    this.savingStatus.set(true);
    this.banner.set(null);
    this.ops.setStatus(t.id, status).subscribe({
      next: (updated) => {
        this.apply(updated);
        this.savingStatus.set(false);
        this.flash(true, `Status changed to "${status}".`);
      },
      error: (failure: unknown) => {
        this.savingStatus.set(false);
        this.flash(false, firstErrorMessage(failure, 'Could not change the status.'));
      },
    });
  }

  assignPlan(current: TenantDetail): void {
    if (this.savingPlan() || this.selectedPlan === (current.plan?.id ?? null)) {
      return;
    }

    this.savingPlan.set(true);
    this.banner.set(null);
    this.ops.assignPlan(current.id, this.selectedPlan).subscribe({
      next: (updated) => {
        this.apply(updated);
        this.savingPlan.set(false);
        this.flash(true, this.selectedPlan ? 'Plan assigned; allowances updated.' : 'Plan removed.');
      },
      error: (failure: unknown) => {
        this.savingPlan.set(false);
        this.flash(false, firstErrorMessage(failure, 'Could not change the plan.'));
      },
    });
  }

  startEditLimits(t: TenantDetail): void {
    this.usersLimit = t.usersLimit;
    this.storageLimitMb = t.storageLimitMb;
    this.editingLimits.set(true);
  }

  saveLimits(current: TenantDetail): void {
    if (this.savingLimits()) {
      return;
    }

    const users = this.usersLimit === null || (this.usersLimit as unknown as string) === '' ? null : Number(this.usersLimit);
    const store = this.storageLimitMb === null || (this.storageLimitMb as unknown as string) === '' ? null : Number(this.storageLimitMb);

    this.savingLimits.set(true);
    this.banner.set(null);
    this.ops.setLimits(current.id, users, store).subscribe({
      next: (updated) => {
        this.apply(updated);
        this.savingLimits.set(false);
        this.editingLimits.set(false);
        this.flash(true, 'Limits updated.');
      },
      error: (failure: unknown) => {
        this.savingLimits.set(false);
        this.flash(false, firstErrorMessage(failure, 'Could not update the limits.'));
      },
    });
  }

  // --- view helpers ---------------------------------------------------------

  badgeClass(status: TenantStatus): string {
    return statusBadgeClass(status);
  }

  meaning(status: TenantStatus): string {
    return statusMeaning(status);
  }

  fmtDate(iso: string | null): string {
    return date(iso);
  }

  expiryDays(t: TenantDetail): number | null {
    return daysUntil(t.subscriptionExpiresAt);
  }

  peopleOf(t: TenantDetail): string {
    return ofLimit(t.userCount, t.usersLimit);
  }

  storageUsed(t: TenantDetail): string {
    return storage(t.storageMb);
  }

  storageLimit(t: TenantDetail): string {
    return storage(t.storageLimitMb);
  }

  planPrice(planId: string): string | null {
    const plan = this.planById().get(planId);
    if (!plan) {
      return null;
    }

    return plan.priceMonthly > 0 ? `${money(plan.priceMonthly, plan.currency)}/mo` : 'Free';
  }

  adminInitials(t: TenantDetail): string {
    const name = t.admin?.name?.trim();
    if (!name) {
      return '?';
    }
    const parts = name.split(/\s+/);

    return ((parts[0]?.[0] ?? '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase() || '?';
  }

  private apply(t: TenantDetail): void {
    this.tenant.set(t);
    this.selectedPlan = t.plan?.id ?? null;
    this.loading.set(false);
  }

  private flash(ok: boolean, text: string): void {
    this.banner.set({ ok, text });
  }
}
