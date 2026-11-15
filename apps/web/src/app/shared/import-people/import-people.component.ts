import { Component, EventEmitter, Input, OnDestroy, OnInit, Output, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  UsersService,
  type ImportPersonCannotCreate,
  type ImportPersonToCreate,
} from '../../services/users.service';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { SelectComponent } from '../select/select.component';

/**
 * "Create people who aren't on the platform yet" — the options block shared by
 * the staff-placement and learning-plan importers.
 *
 * Owns the role list: it loads `GET /users/roles` once and, when no role is
 * chosen yet, picks the learner role (the built-in `learner` slug) and emits it,
 * so the host's `roleId` starts at the same default the API would use.
 */
@Component({
  selector: 'app-import-people-options',
  standalone: true,
  imports: [CommonModule, FormsModule, TranslatePipe, SelectComponent],
  template: `
    <div class="ipo" [class.is-on]="createMissing">
      <label class="ipo__opt">
        <input type="checkbox" class="form-check-input mt-0" [disabled]="disabled"
               [ngModel]="createMissing" (ngModelChange)="createMissingChange.emit($event)">
        <span class="ipo__text">
          <strong>{{ 'people.create.option' | translate }}</strong>
          <small class="ui-muted">{{ 'people.create.option_hint' | translate }}</small>
        </span>
        <span class="ui-pill ui-pill--brand ipo__count" *ngIf="createMissing && createCount > 0">
          <span class="ui-pill__dot"></span>{{ 'people.create.will_create' | translate:{ count: createCount } }}
        </span>
      </label>

      <div class="ipo__more" *ngIf="createMissing">
        <div class="ipo__role">
          <label class="ipo__label" for="ipo-role">{{ 'people.create.role' | translate }}</label>
          <app-select size="sm" inputId="ipo-role" [options]="roleOptions" bindLabel="label" bindValue="value"
                      [clearable]="false" [loading]="loadingRoles" [disabled]="disabled"
                      [placeholder]="'people.create.role_default' | translate"
                      [ngModel]="roleId" (ngModelChange)="roleIdChange.emit($event)" />
        </div>

        <label class="ipo__opt">
          <input type="checkbox" class="form-check-input mt-0" [disabled]="disabled"
                 [ngModel]="sendInvites" (ngModelChange)="sendInvitesChange.emit($event)">
          <span class="ipo__text">
            <strong>{{ 'people.create.send_invites' | translate }}</strong>
            <small class="ui-muted">{{ 'people.create.send_invites_hint' | translate }}</small>
          </span>
        </label>
        <div class="alert alert-warning ipo__warn" *ngIf="sendInvites && createCount > 0">
          <i class="bi bi-envelope-exclamation me-2"></i>{{ 'people.create.invites_warning' | translate:{ count: createCount } }}
        </div>
      </div>
    </div>
  `,
  styles: [`
    .ipo { display: flex; flex-direction: column; gap: 10px; padding: 12px 14px; border: 1px solid var(--ui-border);
      border-radius: var(--ui-radius-ctl); background: var(--ui-surface-2); }
    .ipo.is-on { border-color: rgba(var(--brand-primary-rgb), .35); background: rgba(var(--brand-primary-rgb), .04); }
    .ipo__opt { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; margin: 0; flex-wrap: wrap;
      input { width: 18px; height: 18px; margin-top: 2px; flex: 0 0 auto; } }
    .ipo__text { display: flex; flex-direction: column; gap: 1px; flex: 1 1 240px; min-width: 0;
      strong { font-size: .875rem; font-weight: 600; } small { font-size: .75rem; } }
    .ipo__count { align-self: center; }
    .ipo__more { display: flex; flex-direction: column; gap: 10px; padding: 10px 0 0 28px; border-top: 1px solid var(--ui-border); }
    .ipo__role { display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
      app-select { flex: 0 1 280px; min-width: 200px; } }
    .ipo__label { font-size: .75rem; font-weight: 600; color: var(--ui-text-muted); margin: 0; }
    .ipo__warn { display: flex; align-items: flex-start; margin: 0; padding: 8px 12px; font-size: .8125rem; }
    @media (max-width: 520px) { .ipo__more { padding-left: 0; } }
  `],
})
export class ImportPeopleOptionsComponent implements OnInit, OnDestroy {
  private readonly users = inject(UsersService);
  private readonly destroy$ = new Subject<void>();

  /** How many people the commit would create (distinct emails). */
  @Input() createCount = 0;
  @Input() disabled = false;

  @Input() createMissing = true;
  @Output() createMissingChange = new EventEmitter<boolean>();
  @Input() sendInvites = false;
  @Output() sendInvitesChange = new EventEmitter<boolean>();
  @Input() roleId: string | null = null;
  @Output() roleIdChange = new EventEmitter<string | null>();

  roleOptions: { value: string; label: string }[] = [];
  loadingRoles = false;

  ngOnInit(): void {
    this.loadingRoles = true;
    this.users.roles().pipe(takeUntil(this.destroy$)).subscribe({
      next: (roles) => {
        this.loadingRoles = false;
        const list = Array.isArray(roles) ? roles : [];
        this.roleOptions = list.map((r) => ({ value: r.id, label: r.name }));
        if (!this.roleId) {
          const learner = list.find((r) => r.slug === 'learner');
          if (learner) {
            this.roleIdChange.emit(learner.id);
          }
        }
      },
      // No role list: leave it to the server's default (the learner role).
      error: () => (this.loadingRoles = false),
    });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }
}

/**
 * The two people tables of an import preview: who would be created (name as it
 * will be saved, email, job title, department) and who cannot be (with why).
 */
@Component({
  selector: 'app-import-people-table',
  standalone: true,
  imports: [CommonModule, TranslatePipe],
  template: `
    <div class="table-responsive ipt">
      <table class="table table-sm align-middle mb-0" *ngIf="mode === 'create'">
        <thead><tr>
          <th>{{ 'plan.import.col_row' | translate }}</th>
          <th>{{ 'people.create.col_saved_name' | translate }}</th>
          <th>{{ 'plan.import.col_email' | translate }}</th>
          <th>{{ 'people.create.col_job_title' | translate }}</th>
          <th>{{ 'people.create.col_department' | translate }}</th>
        </tr></thead>
        <tbody>
          <tr *ngFor="let p of toCreate">
            <td class="ui-num">{{ p.row }}</td>
            <td>
              <strong>{{ fullName(p) }}</strong>
              <span class="ui-pill ui-pill--warning ms-1" *ngIf="p.previouslyDeleted" [title]="'people.create.previously_deleted_hint' | translate">
                {{ 'people.create.previously_deleted' | translate }}
              </span>
            </td>
            <td class="ui-muted">{{ p.email }}</td>
            <td>{{ p.jobTitle || '—' }}</td>
            <td>{{ p.department || '—' }}</td>
          </tr>
          <tr *ngIf="toCreate.length === 0"><td colspan="5" class="ui-muted text-center">{{ 'plan.import.no_rows' | translate }}</td></tr>
        </tbody>
      </table>

      <table class="table table-sm align-middle mb-0" *ngIf="mode === 'cannot'">
        <thead><tr>
          <th>{{ 'plan.import.col_row' | translate }}</th>
          <th>{{ 'plan.import.col_name' | translate }}</th>
          <th>{{ 'plan.import.col_email' | translate }}</th>
          <th>{{ 'plan.import.col_reason' | translate }}</th>
        </tr></thead>
        <tbody>
          <tr *ngFor="let p of cannotCreate">
            <td class="ui-num">{{ p.row }}</td>
            <td>{{ p.name || '—' }}</td>
            <td class="ui-muted">{{ p.email || '—' }}</td>
            <td class="text-danger" [title]="p.message || ''">{{ reasonLabel(p) }}</td>
          </tr>
          <tr *ngIf="cannotCreate.length === 0"><td colspan="4" class="ui-muted text-center">{{ 'plan.import.no_rows' | translate }}</td></tr>
        </tbody>
      </table>
    </div>
  `,
  styles: [`
    .ipt { margin-top: 10px; max-height: 340px; overflow-y: auto; border: 1px solid var(--ui-border);
      border-radius: var(--ui-radius-ctl);
      thead th { position: sticky; top: 0; z-index: 1; background: var(--ui-surface-2); font-size: .75rem;
        font-weight: 600; color: var(--ui-text-muted); white-space: nowrap; }
      td { font-size: .8125rem; } strong { font-weight: 600; } }
  `],
})
export class ImportPeopleTableComponent {
  private readonly i18n = inject(I18nService);

  @Input() mode: 'create' | 'cannot' = 'create';
  @Input() toCreate: ImportPersonToCreate[] = [];
  @Input() cannotCreate: ImportPersonCannotCreate[] = [];

  fullName(p: ImportPersonToCreate): string {
    return `${p.firstName ?? ''} ${p.lastName ?? ''}`.trim() || '—';
  }

  /**
   * A known reason code reads as a translated sentence; `invalid` (and any
   * code not known here) shows the server's own message.
   */
  reasonLabel(p: ImportPersonCannotCreate): string {
    const key = 'people.create.reason_' + p.reason;
    const t = p.reason === 'invalid' ? key : this.i18n.t(key);
    return t && t !== key ? t : (p.message || p.reason);
  }
}
