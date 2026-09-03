import { Component, EventEmitter, Input, OnInit, Output, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { OperatorService } from '../core/operator.service';
import type { Plan, PlanInput } from '../core/models';
import { extractFieldErrors, firstErrorMessage } from '../core/errors';

/**
 * Creating or editing a plan.
 *
 * Prices are entered in whole currency — the operator thinks in naira, not
 * kobo — and turned into minor units on the way out, so the store never holds
 * a float. A plan's code cannot change once set, so on an edit it is shown but
 * not editable.
 */
@Component({
  selector: 'app-plan-dialog',
  standalone: true,
  imports: [FormsModule],
  template: `
    <div class="op-overlay" (click)="backdrop()">
      <div class="op-dialog" (click)="$event.stopPropagation()">
        <div class="op-dialog__head">
          <h2 class="op-dialog__title">{{ plan ? 'Edit plan' : 'New plan' }}</h2>
          <button type="button" class="op-close" (click)="close.emit()" [disabled]="busy()" aria-label="Close">&times;</button>
        </div>

        <form (ngSubmit)="submit()">
          <div class="op-dialog__body">
            @if (generalError()) {
              <div class="op-alert op-alert--danger" style="margin-bottom:1rem">
                <i class="bi bi-exclamation-triangle-fill"></i><span>{{ generalError() }}</span>
              </div>
            }

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
              <div class="op-field">
                <label class="op-field__label" for="pl-name">Name</label>
                <input id="pl-name" name="name" class="op-input" [(ngModel)]="name" [disabled]="busy()" required />
                @if (fieldError('name')) { <div class="op-field__error">{{ fieldError('name') }}</div> }
              </div>
              <div class="op-field">
                <label class="op-field__label" for="pl-code">Code</label>
                <input id="pl-code" name="code" class="op-input" [(ngModel)]="code" [disabled]="busy() || !!plan"
                       required placeholder="growth" />
                @if (plan) { <div class="op-field__hint">A plan's code is fixed.</div> }
                @if (fieldError('code')) { <div class="op-field__error">{{ fieldError('code') }}</div> }
              </div>
            </div>

            <div class="op-field">
              <label class="op-field__label" for="pl-desc">Description</label>
              <input id="pl-desc" name="description" class="op-input" [(ngModel)]="description" [disabled]="busy()"
                     placeholder="Who this plan is for." />
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem">
              <div class="op-field">
                <label class="op-field__label" for="pl-cur">Currency</label>
                <select id="pl-cur" name="currency" class="op-select" [(ngModel)]="currency" [disabled]="busy()">
                  <option value="NGN">NGN ₦</option>
                  <option value="USD">USD $</option>
                  <option value="GBP">GBP £</option>
                  <option value="EUR">EUR €</option>
                </select>
              </div>
              <div class="op-field">
                <label class="op-field__label" for="pl-pm">Price / month</label>
                <input id="pl-pm" name="priceMonthly" type="number" min="0" step="0.01" class="op-input"
                       [(ngModel)]="priceMonthly" [disabled]="busy()" />
              </div>
              <div class="op-field">
                <label class="op-field__label" for="pl-py">Price / year</label>
                <input id="pl-py" name="priceYearly" type="number" min="0" step="0.01" class="op-input"
                       [(ngModel)]="priceYearly" [disabled]="busy()" />
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem">
              <div class="op-field">
                <label class="op-field__label" for="pl-ul">User limit</label>
                <input id="pl-ul" name="usersLimit" type="number" min="0" class="op-input"
                       [(ngModel)]="usersLimit" [disabled]="busy()" placeholder="∞" />
              </div>
              <div class="op-field">
                <label class="op-field__label" for="pl-sl">Storage (MB)</label>
                <input id="pl-sl" name="storageLimitMb" type="number" min="0" class="op-input"
                       [(ngModel)]="storageLimitMb" [disabled]="busy()" placeholder="∞" />
              </div>
              <div class="op-field">
                <label class="op-field__label" for="pl-td">Trial days</label>
                <input id="pl-td" name="trialDays" type="number" min="0" class="op-input"
                       [(ngModel)]="trialDays" [disabled]="busy()" />
              </div>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem;align-items:center">
              <div class="op-field" style="margin-bottom:0">
                <label class="op-field__label" for="pl-so">Sort order</label>
                <input id="pl-so" name="sortOrder" type="number" class="op-input" [(ngModel)]="sortOrder" [disabled]="busy()" />
              </div>
              <div style="display:flex;flex-direction:column;gap:0.4rem;padding-top:0.5rem">
                <label class="op-check"><input type="checkbox" name="isActive" [(ngModel)]="isActive" [disabled]="busy()" /> Active</label>
                <label class="op-check"><input type="checkbox" name="isPublic" [(ngModel)]="isPublic" [disabled]="busy()" /> Public (shown on pricing)</label>
              </div>
            </div>
          </div>

          <div class="op-dialog__foot">
            <button type="button" class="op-btn op-btn--ghost" (click)="close.emit()" [disabled]="busy()">Cancel</button>
            <button type="submit" class="op-btn op-btn--primary" [disabled]="busy() || !name || !code">
              @if (busy()) { <span class="op-spinner"></span> Saving… }
              @else { <i class="bi bi-check-lg"></i> {{ plan ? 'Save plan' : 'Create plan' }} }
            </button>
          </div>
        </form>
      </div>
    </div>
  `,
  styles: [`
    .op-check { display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; color: var(--op-ink); cursor: pointer; }
    .op-check input { width: 1rem; height: 1rem; }
  `],
})
export class PlanDialogComponent implements OnInit {
  private readonly ops = inject(OperatorService);

  @Input() plan: Plan | null = null;
  @Output() readonly close = new EventEmitter<void>();
  @Output() readonly saved = new EventEmitter<Plan>();

  name = '';
  code = '';
  description = '';
  currency = 'NGN';
  priceMonthly = 0;
  priceYearly = 0;
  usersLimit: number | null = null;
  storageLimitMb: number | null = null;
  trialDays = 14;
  sortOrder = 0;
  isActive = true;
  isPublic = true;

  readonly busy = signal(false);
  readonly generalError = signal<string | null>(null);
  readonly errors = signal<Record<string, string>>({});

  ngOnInit(): void {
    const p = this.plan;
    if (!p) {
      return;
    }

    this.name = p.name;
    this.code = p.code;
    this.description = p.description ?? '';
    this.currency = p.currency;
    this.priceMonthly = p.priceMonthly / 100;
    this.priceYearly = p.priceYearly / 100;
    this.usersLimit = p.usersLimit;
    this.storageLimitMb = p.storageLimitMb;
    this.trialDays = p.trialDays;
    this.sortOrder = p.sortOrder;
    this.isActive = p.isActive;
    this.isPublic = p.isPublic;
  }

  fieldError(field: string): string | null {
    return this.errors()[field] ?? null;
  }

  submit(): void {
    if (this.busy() || !this.name || !this.code) {
      return;
    }

    const input: PlanInput = {
      code: this.plan ? undefined : this.code.trim().toLowerCase(),
      name: this.name.trim(),
      description: this.description.trim() || null,
      priceMonthly: toMinor(this.priceMonthly),
      priceYearly: toMinor(this.priceYearly),
      currency: this.currency,
      usersLimit: emptyToNull(this.usersLimit),
      storageLimitMb: emptyToNull(this.storageLimitMb),
      trialDays: Number(this.trialDays) || 0,
      isActive: this.isActive,
      isPublic: this.isPublic,
      sortOrder: Number(this.sortOrder) || 0,
    };

    this.busy.set(true);
    this.generalError.set(null);
    this.errors.set({});

    const call = this.plan
      ? this.ops.updatePlan(this.plan.id, input)
      : this.ops.createPlan(input);

    call.subscribe({
      next: (plan) => {
        this.busy.set(false);
        this.saved.emit(plan);
      },
      error: (failure: unknown) => {
        this.busy.set(false);
        const fields = extractFieldErrors(failure);
        if (Object.keys(fields).length > 0) {
          this.errors.set(fields);
        } else {
          this.generalError.set(firstErrorMessage(failure, 'Could not save the plan.'));
        }
      },
    });
  }

  backdrop(): void {
    if (!this.busy()) {
      this.close.emit();
    }
  }
}

/** Whole currency to minor units, guarding against float dust. */
function toMinor(major: number | null): number {
  const value = Number(major) || 0;

  return Math.round(value * 100);
}

function emptyToNull(value: number | null): number | null {
  if (value === null || (value as unknown as string) === '') {
    return null;
  }

  return Number(value);
}
