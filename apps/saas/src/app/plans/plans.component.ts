import { Component, OnInit, inject, signal } from '@angular/core';
import { DecimalPipe } from '@angular/common';
import { OperatorService } from '../core/operator.service';
import type { Plan } from '../core/models';
import { money, storage } from '../core/format';
import { firstErrorMessage } from '../core/errors';
import { PlanDialogComponent } from './plan-dialog.component';

/**
 * The plan catalogue.
 *
 * The whole shelf, retired plans included — the operator's view, not the
 * public one — with each plan's price, allowances and whether it is on offer.
 * A plan is created, edited or retired here; retiring is soft, so an
 * organisation already on it is undisturbed.
 */
@Component({
  selector: 'app-plans',
  standalone: true,
  imports: [DecimalPipe, PlanDialogComponent],
  template: `
    <header class="op-topbar">
      <div>
        <h1 class="op-topbar__title">Plans</h1>
        <p class="op-topbar__sub">The catalogue organisations subscribe to.</p>
      </div>
      <button class="op-btn op-btn--primary" (click)="create()"><i class="bi bi-plus-lg"></i> New plan</button>
    </header>

    <div class="op-content">
      @if (banner(); as b) {
        <div class="op-alert" [class.op-alert--ok]="b.ok" [class.op-alert--danger]="!b.ok" style="margin-bottom:1.25rem">
          <i class="bi" [class.bi-check-circle-fill]="b.ok" [class.bi-exclamation-triangle-fill]="!b.ok"></i>
          <span>{{ b.text }}</span>
        </div>
      }

      @if (loading()) {
        <div class="op-loading"><span class="op-spinner"></span> Loading plans…</div>
      } @else if (error()) {
        <div class="op-card"><div class="op-empty">
          <i class="bi bi-exclamation-triangle" style="color:var(--op-danger)"></i>
          Could not load the plans.
          <div style="margin-top:0.75rem"><button class="op-btn op-btn--sm" (click)="load()">Retry</button></div>
        </div></div>
      } @else if (plans().length === 0) {
        <div class="op-card"><div class="op-empty"><i class="bi bi-tags"></i> No plans yet. Create the first one.</div></div>
      } @else {
        <div class="op-plan-grid">
          @for (p of plans(); track p.id) {
            <div class="op-card op-plan" [class.is-retired]="!p.isActive">
              <div class="op-card__body">
                <div class="op-plan__top">
                  <div>
                    <div class="op-plan__name">{{ p.name }}</div>
                    <div class="op-table__code">{{ p.code }}</div>
                  </div>
                  <div style="display:flex;gap:0.35rem;flex-wrap:wrap;justify-content:flex-end">
                    @if (!p.isActive) { <span class="op-badge op-badge--muted">Retired</span> }
                    @else if (p.isPublic) { <span class="op-badge op-badge--active">Public</span> }
                    @else { <span class="op-badge op-badge--trial">Private</span> }
                  </div>
                </div>

                <div class="op-plan__price">
                  {{ p.priceMonthly > 0 ? money(p.priceMonthly, p.currency) : 'Free' }}
                  @if (p.priceMonthly > 0) { <span>/mo</span> }
                </div>
                @if (p.description) { <p class="op-plan__desc">{{ p.description }}</p> }

                <ul class="op-plan__feats">
                  <li><i class="bi bi-people"></i> {{ p.usersLimit === null ? 'Unlimited users' : (p.usersLimit | number) + ' users' }}</li>
                  <li><i class="bi bi-hdd"></i> {{ storage(p.storageLimitMb) }} storage</li>
                  <li><i class="bi bi-calendar-check"></i> {{ p.trialDays }}-day trial</li>
                  @if (p.priceYearly > 0) { <li><i class="bi bi-cash"></i> {{ money(p.priceYearly, p.currency) }}/yr</li> }
                </ul>
              </div>
              <div class="op-plan__foot">
                <button class="op-btn op-btn--ghost op-btn--sm" (click)="edit(p)"><i class="bi bi-pencil"></i> Edit</button>
                @if (p.isActive) {
                  <button class="op-btn op-btn--danger op-btn--sm" [disabled]="retiring() !== null" (click)="retire(p)">
                    @if (retiring() === p.id) { <span class="op-spinner"></span> } @else { <i class="bi bi-archive"></i> Retire }
                  </button>
                }
              </div>
            </div>
          }
        </div>
      }
    </div>

    @if (dialog()) {
      <app-plan-dialog [plan]="editing()" (close)="dialog.set(false)" (saved)="onSaved($event)" />
    }
  `,
  styles: [`
    .op-plan-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1.25rem; }
    .op-plan { display: flex; flex-direction: column; }
    .op-plan.is-retired { opacity: 0.72; }
    .op-plan .op-card__body { flex: 1; }
    .op-plan__top { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; }
    .op-plan__name { font-size: 1.15rem; font-weight: 700; }
    .op-plan__price { font-size: 1.6rem; font-weight: 700; margin: 0.9rem 0 0.2rem; letter-spacing: -0.02em; }
    .op-plan__price span { font-size: 0.9rem; font-weight: 500; color: var(--op-ink-faint); }
    .op-plan__desc { color: var(--op-ink-soft); font-size: 0.88rem; margin: 0.4rem 0 0.9rem; }
    .op-plan__feats { list-style: none; padding: 0; margin: 0.9rem 0 0; display: flex; flex-direction: column; gap: 0.5rem; }
    .op-plan__feats li { display: flex; align-items: center; gap: 0.6rem; font-size: 0.88rem; color: var(--op-ink); }
    .op-plan__feats i { color: var(--op-accent); width: 1.1rem; }
    .op-plan__foot { padding: 0.85rem 1.25rem; border-top: 1px solid var(--op-border); display: flex; gap: 0.5rem; justify-content: flex-end; }
  `],
})
export class PlansComponent implements OnInit {
  private readonly ops = inject(OperatorService);

  readonly plans = signal<readonly Plan[]>([]);
  readonly loading = signal(true);
  readonly error = signal(false);
  readonly dialog = signal(false);
  readonly editing = signal<Plan | null>(null);
  readonly retiring = signal<string | null>(null);
  readonly banner = signal<{ ok: boolean; text: string } | null>(null);

  readonly money = money;
  readonly storage = storage;

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.error.set(false);
    this.ops.plans().subscribe({
      next: (all) => {
        this.plans.set([...all].sort((a, b) => a.sortOrder - b.sortOrder));
        this.loading.set(false);
      },
      error: () => {
        // Don't render a failure as "no plans yet" — that invites recreating a
        // plan that already exists, which then fails on its unique code.
        this.plans.set([]);
        this.error.set(true);
        this.loading.set(false);
      },
    });
  }

  create(): void {
    this.editing.set(null);
    this.dialog.set(true);
  }

  edit(p: Plan): void {
    this.editing.set(p);
    this.dialog.set(true);
  }

  onSaved(plan: Plan): void {
    this.dialog.set(false);
    this.flash(true, `Plan "${plan.name}" saved.`);
    this.load();
  }

  retire(p: Plan): void {
    if (this.retiring() || !confirm(`Retire "${p.name}"? It stops being offered; organisations already on it keep it.`)) {
      return;
    }

    this.retiring.set(p.id);
    this.banner.set(null);
    this.ops.deletePlan(p.id).subscribe({
      next: () => {
        this.retiring.set(null);
        this.flash(true, `Plan "${p.name}" retired.`);
        this.load();
      },
      error: (failure: unknown) => {
        this.retiring.set(null);
        this.flash(false, firstErrorMessage(failure, 'Could not retire the plan.'));
      },
    });
  }

  private flash(ok: boolean, text: string): void {
    this.banner.set({ ok, text });
  }
}
