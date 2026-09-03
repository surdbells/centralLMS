import { Component, EventEmitter, OnInit, Output, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { OperatorService } from '../core/operator.service';
import type { Plan, ProvisionResult, TenantStatus } from '../core/models';
import { extractFieldErrors, firstErrorMessage } from '../core/errors';

/**
 * Standing up a new organisation.
 *
 * The same provisioning the CLI does — a tenant, its first administrator, a
 * plan and a starting state — asked for through a form. A blank password means
 * the API generates one, which is why the result carries it back exactly once
 * for the operator to hand over.
 */
@Component({
  selector: 'app-provision-dialog',
  standalone: true,
  imports: [FormsModule],
  template: `
    <div class="op-overlay" (click)="backdrop($event)">
      <div class="op-dialog" (click)="$event.stopPropagation()">
        <div class="op-dialog__head">
          <h2 class="op-dialog__title">New organisation</h2>
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
              <label class="op-field__label" for="p-name">Organisation name</label>
              <input id="p-name" name="name" class="op-input" [(ngModel)]="name" (ngModelChange)="onName()"
                     [disabled]="busy()" required placeholder="Acme Industries" />
              @if (fieldError('name')) { <div class="op-field__error">{{ fieldError('name') }}</div> }
            </div>

            <div class="op-field">
              <label class="op-field__label" for="p-code">Code</label>
              <input id="p-code" name="code" class="op-input" [(ngModel)]="code" (ngModelChange)="codeEdited = true"
                     [disabled]="busy()" required placeholder="acme" />
              <div class="op-field__hint">Lowercase letters, numbers and hyphens. Used in the organisation's address.</div>
              @if (fieldError('code')) { <div class="op-field__error">{{ fieldError('code') }}</div> }
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
              <div class="op-field">
                <label class="op-field__label" for="p-first">Admin first name</label>
                <input id="p-first" name="first" class="op-input" [(ngModel)]="adminFirstName"
                       [disabled]="busy()" placeholder="Jane" />
              </div>
              <div class="op-field">
                <label class="op-field__label" for="p-last">Admin last name</label>
                <input id="p-last" name="last" class="op-input" [(ngModel)]="adminLastName"
                       [disabled]="busy()" placeholder="Doe" />
              </div>
            </div>

            <div class="op-field">
              <label class="op-field__label" for="p-email">Admin email</label>
              <input id="p-email" name="email" type="email" class="op-input" [(ngModel)]="contactEmail"
                     [disabled]="busy()" required placeholder="jane@acme.com" />
              @if (fieldError('contactEmail')) { <div class="op-field__error">{{ fieldError('contactEmail') }}</div> }
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
              <div class="op-field">
                <label class="op-field__label" for="p-plan">Plan</label>
                <select id="p-plan" name="plan" class="op-select" [(ngModel)]="planId" [disabled]="busy()">
                  <option [ngValue]="null">No plan</option>
                  @for (plan of plans(); track plan.id) {
                    <option [ngValue]="plan.id">{{ plan.name }}</option>
                  }
                </select>
              </div>
              <div class="op-field">
                <label class="op-field__label" for="p-status">Status</label>
                <select id="p-status" name="status" class="op-select" [(ngModel)]="status" [disabled]="busy()">
                  <option value="active">Active</option>
                  <option value="trial">Trial</option>
                </select>
              </div>
            </div>

            <div class="op-field" style="margin-bottom:0">
              <label class="op-field__label" for="p-pass">Admin password <span style="font-weight:400;color:var(--op-ink-faint)">(optional)</span></label>
              <input id="p-pass" name="password" type="text" class="op-input" [(ngModel)]="password"
                     [disabled]="busy()" placeholder="Leave blank to generate one" autocomplete="off" />
              <div class="op-field__hint">Left blank, a strong password is generated and shown once.</div>
            </div>
          </div>

          <div class="op-dialog__foot">
            <button type="button" class="op-btn op-btn--ghost" (click)="close.emit()" [disabled]="busy()">Cancel</button>
            <button type="submit" class="op-btn op-btn--primary" [disabled]="busy() || !name || !code || !contactEmail">
              @if (busy()) { <span class="op-spinner"></span> Provisioning… }
              @else { <i class="bi bi-plus-lg"></i> Create organisation }
            </button>
          </div>
        </form>
      </div>
    </div>
  `,
})
export class ProvisionDialogComponent implements OnInit {
  private readonly ops = inject(OperatorService);

  @Output() readonly close = new EventEmitter<void>();
  @Output() readonly provisioned = new EventEmitter<ProvisionResult>();

  name = '';
  code = '';
  codeEdited = false;
  contactEmail = '';
  adminFirstName = '';
  adminLastName = '';
  planId: string | null = null;
  status: TenantStatus = 'active';
  password = '';

  readonly plans = signal<readonly Plan[]>([]);
  readonly busy = signal(false);
  readonly generalError = signal<string | null>(null);
  readonly errors = signal<Record<string, string>>({});

  ngOnInit(): void {
    this.ops.plans().subscribe((all) => this.plans.set(all.filter((p) => p.isActive)));
  }

  onName(): void {
    // Suggest a code from the name until the operator types their own.
    if (!this.codeEdited) {
      this.code = this.name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .slice(0, 40);
    }
  }

  fieldError(field: string): string | null {
    return this.errors()[field] ?? null;
  }

  submit(): void {
    if (this.busy() || !this.name || !this.code || !this.contactEmail) {
      return;
    }

    this.busy.set(true);
    this.generalError.set(null);
    this.errors.set({});

    this.ops
      .provision({
        name: this.name.trim(),
        code: this.code.trim().toLowerCase(),
        contactEmail: this.contactEmail.trim(),
        adminFirstName: this.adminFirstName.trim(),
        adminLastName: this.adminLastName.trim(),
        planId: this.planId,
        status: this.status,
        password: this.password.trim() || undefined,
      })
      .subscribe({
        next: (result) => {
          this.busy.set(false);
          this.provisioned.emit(result);
        },
        error: (failure: unknown) => {
          this.busy.set(false);
          const fields = extractFieldErrors(failure);
          if (Object.keys(fields).length > 0) {
            this.errors.set(fields);
          } else {
            this.generalError.set(firstErrorMessage(failure, 'Could not create the organisation.'));
          }
        },
      });
  }

  backdrop(_event: MouseEvent): void {
    if (!this.busy()) {
      this.close.emit();
    }
  }

  // markCodeEdited is bound via a keydown handler on the code input in the DOM,
  // but kept simple: any manual change stops the auto-suggestion.
}
