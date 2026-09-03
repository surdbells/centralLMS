import { Component, Input, OnInit, EventEmitter, Output, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { OperatorService } from '../core/operator.service';
import type { OnboardingCode, OnboardingCodeInput, Plan } from '../core/models';
import { extractFieldErrors, firstErrorMessage } from '../core/errors';

/**
 * Issuing or editing an onboarding code.
 *
 * A code says how many organisations it may still create (blank = unlimited),
 * an optional window it is good for, and which plans it unlocks — none ticked
 * means any plan. The use count is the sign-up's to change, so it is shown but
 * never edited here.
 */
@Component({
  selector: 'app-code-dialog',
  standalone: true,
  imports: [FormsModule],
  template: `
    <div class="op-overlay" (click)="backdrop()">
      <div class="op-dialog" (click)="$event.stopPropagation()">
        <div class="op-dialog__head">
          <h2 class="op-dialog__title">{{ existing ? 'Edit code' : 'New code' }}</h2>
          <button type="button" class="op-close" (click)="close.emit()" [disabled]="busy()" aria-label="Close">&times;</button>
        </div>

        <form (ngSubmit)="submit()">
          <div class="op-dialog__body">
            @if (generalError()) {
              <div class="op-alert op-alert--danger" style="margin-bottom:1rem">
                <i class="bi bi-exclamation-triangle-fill"></i><span>{{ generalError() }}</span>
              </div>
            }

            <div class="op-field">
              <label class="op-field__label" for="c-code">Code</label>
              <input id="c-code" name="code" class="op-input" [(ngModel)]="code" [disabled]="busy()"
                     required placeholder="WELCOME2026" style="text-transform:uppercase" />
              @if (fieldError('code')) { <div class="op-field__error">{{ fieldError('code') }}</div> }
              @if (existing) {
                <div class="op-field__hint">Used {{ existing.usedCount }} time{{ existing.usedCount === 1 ? '' : 's' }} so far.</div>
              }
            </div>

            <div class="op-field">
              <label class="op-field__label" for="c-desc">Description</label>
              <input id="c-desc" name="description" class="op-input" [(ngModel)]="description" [disabled]="busy()"
                     placeholder="What this code is for." />
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem">
              <div class="op-field">
                <label class="op-field__label" for="c-max">Max uses</label>
                <input id="c-max" name="maxUses" type="number" min="0" class="op-input"
                       [(ngModel)]="maxUses" [disabled]="busy()" placeholder="∞" />
                <div class="op-field__hint">Blank = unlimited.</div>
              </div>
              <div class="op-field">
                <label class="op-field__label" for="c-from">Valid from</label>
                <input id="c-from" name="validFrom" type="date" class="op-input" [(ngModel)]="validFrom" [disabled]="busy()" />
              </div>
              <div class="op-field">
                <label class="op-field__label" for="c-until">Valid until</label>
                <input id="c-until" name="validUntil" type="date" class="op-input" [(ngModel)]="validUntil" [disabled]="busy()" />
              </div>
            </div>

            <div class="op-field">
              <label class="op-field__label">Plans this code unlocks</label>
              <div class="op-plan-picks">
                @for (p of plans(); track p.id) {
                  @if (p.isActive || picked.has(p.id)) {
                    <label class="op-check">
                      <input type="checkbox" [checked]="picked.has(p.id)" (change)="toggle(p.id)" [disabled]="busy()" />
                      {{ p.name }}@if (!p.isActive) { <span style="color:var(--op-ink-faint)">&nbsp;(retired)</span> }
                    </label>
                  }
                }
              </div>
              <div class="op-field__hint">None ticked = every plan on offer.</div>
            </div>

            <label class="op-check" style="margin-top:0.25rem">
              <input type="checkbox" name="isActive" [(ngModel)]="isActive" [disabled]="busy()" /> Active
            </label>
          </div>

          <div class="op-dialog__foot">
            <button type="button" class="op-btn op-btn--ghost" (click)="close.emit()" [disabled]="busy()">Cancel</button>
            <button type="submit" class="op-btn op-btn--primary" [disabled]="busy() || !code">
              @if (busy()) { <span class="op-spinner"></span> Saving… }
              @else { <i class="bi bi-check-lg"></i> {{ existing ? 'Save code' : 'Create code' }} }
            </button>
          </div>
        </form>
      </div>
    </div>
  `,
  styles: [`
    .op-check { display: flex; align-items: center; gap: 0.5rem; font-size: 0.9rem; color: var(--op-ink); cursor: pointer; }
    .op-check input { width: 1rem; height: 1rem; }
    .op-plan-picks { display: flex; flex-wrap: wrap; gap: 0.75rem 1.25rem; padding: 0.6rem 0.75rem; border: 1px solid var(--op-border-strong); border-radius: var(--op-radius-sm); }
  `],
})
export class CodeDialogComponent implements OnInit {
  private readonly ops = inject(OperatorService);

  @Input() existing: OnboardingCode | null = null;
  @Output() readonly close = new EventEmitter<void>();
  @Output() readonly saved = new EventEmitter<OnboardingCode>();

  code = '';
  description = '';
  maxUses: number | null = null;
  validFrom: string | null = null;
  validUntil: string | null = null;
  isActive = true;
  readonly picked = new Set<string>();

  readonly plans = signal<readonly Plan[]>([]);
  readonly busy = signal(false);
  readonly generalError = signal<string | null>(null);
  readonly errors = signal<Record<string, string>>({});

  ngOnInit(): void {
    // All plans, not just active ones: a code may already be restricted to a
    // plan since retired, and that plan must still show so it can be un-ticked
    // (the template only offers active plans plus whatever is already picked).
    this.ops.plans().subscribe((all) => this.plans.set(all));

    const e = this.existing;
    if (!e) {
      return;
    }

    this.code = e.code;
    this.description = e.description ?? '';
    this.maxUses = e.maxUses;
    this.validFrom = e.validFrom ? e.validFrom.slice(0, 10) : null;
    this.validUntil = e.validUntil ? e.validUntil.slice(0, 10) : null;
    this.isActive = e.isActive;
    for (const id of e.allowedPlanIds ?? []) {
      this.picked.add(id);
    }
  }

  toggle(id: string): void {
    if (this.picked.has(id)) {
      this.picked.delete(id);
    } else {
      this.picked.add(id);
    }
  }

  fieldError(field: string): string | null {
    return this.errors()[field] ?? null;
  }

  submit(): void {
    if (this.busy() || !this.code) {
      return;
    }

    const input: OnboardingCodeInput = {
      code: this.code.trim(),
      description: this.description.trim() || null,
      maxUses: this.maxUses === null || (this.maxUses as unknown as string) === '' ? null : Number(this.maxUses),
      allowedPlanIds: this.picked.size === 0 ? null : [...this.picked],
      validFrom: this.validFrom || null,
      validUntil: this.validUntil || null,
      isActive: this.isActive,
    };

    this.busy.set(true);
    this.generalError.set(null);
    this.errors.set({});

    const call = this.existing
      ? this.ops.updateCode(this.existing.id, input)
      : this.ops.createCode(input);

    call.subscribe({
      next: (code) => {
        this.busy.set(false);
        this.saved.emit(code);
      },
      error: (failure: unknown) => {
        this.busy.set(false);
        const fields = extractFieldErrors(failure);
        if (Object.keys(fields).length > 0) {
          this.errors.set(fields);
        } else {
          this.generalError.set(firstErrorMessage(failure, 'Could not save the code.'));
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
