import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { OperatorService } from '../core/operator.service';
import type { OnboardingCode, Plan } from '../core/models';
import { date } from '../core/format';
import { firstErrorMessage } from '../core/errors';
import { CodeDialogComponent } from './code-dialog.component';

/**
 * The onboarding codes that gate public self-service sign-up.
 *
 * Each code lets a customer create an organisation from the marketing site.
 * Issue one, set how many organisations it may create and which plans it
 * unlocks, and retire it when its run is over. Retiring is soft — it stops
 * validating, it does not touch the organisations it already made.
 */
@Component({
  selector: 'app-codes',
  standalone: true,
  imports: [CodeDialogComponent],
  template: `
    <header class="op-topbar">
      <div>
        <h1 class="op-topbar__title">Onboarding codes</h1>
        <p class="op-topbar__sub">What a customer needs to create an organisation themselves.</p>
      </div>
      <button class="op-btn op-btn--primary" (click)="create()"><i class="bi bi-plus-lg"></i> New code</button>
    </header>

    <div class="op-content">
      @if (banner(); as b) {
        <div class="op-alert" [class.op-alert--ok]="b.ok" [class.op-alert--danger]="!b.ok" style="margin-bottom:1.25rem">
          <i class="bi" [class.bi-check-circle-fill]="b.ok" [class.bi-exclamation-triangle-fill]="!b.ok"></i>
          <span>{{ b.text }}</span>
        </div>
      }

      <div class="op-card">
        @if (loading()) {
          <div class="op-loading"><span class="op-spinner"></span> Loading codes…</div>
        } @else if (error()) {
          <div class="op-empty">
            <i class="bi bi-exclamation-triangle" style="color:var(--op-danger)"></i>
            Could not load the codes.
            <div style="margin-top:0.75rem"><button class="op-btn op-btn--sm" (click)="load()">Retry</button></div>
          </div>
        } @else if (codes().length === 0) {
          <div class="op-empty"><i class="bi bi-key"></i> No codes yet. Issue the first one to open self-service sign-up.</div>
        } @else {
          <div style="overflow-x:auto">
            <table class="op-table">
              <thead>
                <tr>
                  <th>Code</th>
                  <th>Uses</th>
                  <th>Plans</th>
                  <th>Valid</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                @for (c of codes(); track c.id) {
                  <tr>
                    <td>
                      <div class="op-table__name" style="font-family:ui-monospace,Menlo,monospace">{{ c.code }}</div>
                      @if (c.description) { <div class="op-table__code" style="font-family:inherit">{{ c.description }}</div> }
                    </td>
                    <td>{{ c.usedCount }} / {{ c.maxUses === null ? '∞' : c.maxUses }}</td>
                    <td>{{ planLabel(c) }}</td>
                    <td style="color:var(--op-ink-soft)">{{ validity(c) }}</td>
                    <td>
                      @if (c.isActive) { <span class="op-badge op-badge--active">Active</span> }
                      @else { <span class="op-badge op-badge--muted">Inactive</span> }
                    </td>
                    <td style="text-align:right;white-space:nowrap">
                      <button class="op-btn op-btn--ghost op-btn--sm" (click)="edit(c)"><i class="bi bi-pencil"></i></button>
                      <button class="op-btn op-btn--danger op-btn--sm" style="margin-left:0.35rem"
                              [disabled]="retiring() !== null" (click)="retire(c)">
                        @if (retiring() === c.id) { <span class="op-spinner"></span> } @else { <i class="bi bi-archive"></i> }
                      </button>
                    </td>
                  </tr>
                }
              </tbody>
            </table>
          </div>
        }
      </div>
    </div>

    @if (dialog()) {
      <app-code-dialog [existing]="editing()" (close)="dialog.set(false)" (saved)="onSaved($event)" />
    }
  `,
})
export class CodesComponent implements OnInit {
  private readonly ops = inject(OperatorService);

  readonly codes = signal<readonly OnboardingCode[]>([]);
  readonly plans = signal<readonly Plan[]>([]);
  readonly loading = signal(true);
  readonly error = signal(false);
  readonly dialog = signal(false);
  readonly editing = signal<OnboardingCode | null>(null);
  readonly retiring = signal<string | null>(null);
  readonly banner = signal<{ ok: boolean; text: string } | null>(null);

  private readonly planName = computed(() => {
    const map = new Map<string, string>();
    for (const p of this.plans()) {
      map.set(p.id, p.name);
    }

    return map;
  });

  ngOnInit(): void {
    this.ops.plans().subscribe({ next: (all) => this.plans.set(all), error: () => {} });
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(false);
    this.ops.codes().subscribe({
      next: (all) => {
        this.codes.set(all);
        this.loading.set(false);
      },
      error: () => {
        this.codes.set([]);
        this.error.set(true);
        this.loading.set(false);
      },
    });
  }

  create(): void {
    this.editing.set(null);
    this.dialog.set(true);
  }

  edit(c: OnboardingCode): void {
    this.editing.set(c);
    this.dialog.set(true);
  }

  onSaved(code: OnboardingCode): void {
    this.dialog.set(false);
    this.flash(true, `Code “${code.code}” saved.`);
    this.load();
  }

  retire(c: OnboardingCode): void {
    if (this.retiring() || !confirm(`Retire code “${c.code}”? It stops working for new sign-ups.`)) {
      return;
    }

    this.retiring.set(c.id);
    this.banner.set(null);
    this.ops.deleteCode(c.id).subscribe({
      next: () => {
        this.retiring.set(null);
        this.flash(true, `Code “${c.code}” retired.`);
        this.load();
      },
      error: (failure: unknown) => {
        this.retiring.set(null);
        this.flash(false, firstErrorMessage(failure, 'Could not retire the code.'));
      },
    });
  }

  planLabel(c: OnboardingCode): string {
    if (c.allowedPlanIds === null || c.allowedPlanIds.length === 0) {
      return 'All plans';
    }

    const names = c.allowedPlanIds.map((id) => this.planName().get(id)).filter((n): n is string => !!n);

    return names.length > 0 ? names.join(', ') : `${c.allowedPlanIds.length} plan(s)`;
  }

  validity(c: OnboardingCode): string {
    if (!c.validFrom && !c.validUntil) {
      return 'Always';
    }
    if (c.validFrom && c.validUntil) {
      return `${date(c.validFrom)} – ${date(c.validUntil)}`;
    }

    return c.validUntil ? `Until ${date(c.validUntil)}` : `From ${date(c.validFrom)}`;
  }

  private flash(ok: boolean, text: string): void {
    this.banner.set({ ok, text });
  }
}
