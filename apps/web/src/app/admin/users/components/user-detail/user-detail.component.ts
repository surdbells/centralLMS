import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Subject, forkJoin } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { UsersService, type User as ApiUser } from '../../../../services/users.service';
import { RolesService, type Role as ApiRole } from '../../../../services/roles.service';
import { CustomFieldsService, type CustomFieldDefinition } from '../../../../services/custom-fields.service';
import { AuditService } from '../../../../services/audit.service';
import { ApiError } from '../../../../core/api';
import { AdmintopComponent } from '../../../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../../../translate.pipe';
import { I18nService } from '../../../../i18n.service';
import { ConfirmDialogService } from '../../../../shared/components/confirmation-dialog/confirmation-dialog.component';
import { HotToastService } from '@ngneat/hot-toast';

type UserStatus = 'active' | 'invited' | 'suspended' | 'locked';

/* The shapes this screen's template already reads. */

export interface UserRole {
  role_id: string;
  role_name: string;
  role_key: string;
  description: string | null;
  is_system: boolean;
}

export interface UserDetail {
  id: string;
  full_name: string;
  email: string;
  status: UserStatus;
  roles: UserRole[];
  /** The union of what their roles allow, worked out from the role list. */
  permissions: string[];
}

export interface Role {
  role_id: string;
  role_name: string;
}

/**
 * One entry in the Activity tab.
 *
 * The tab used to read `user_activity_log`, which only the login handler ever
 * wrote to — so it was a list of sign-ins presented as a full history, and
 * every column but the timestamp was empty for anything else. It reads the
 * audit trail now, filtered to things done *to* this person.
 */
export interface UserActivity {
  action: string;
  details: Record<string, unknown>;
  ip_address: string | null;
  performed_by_name: string | null;
  created_at: string;
}

const LANGUAGES: Record<string, string> = {
  en: 'admin.user_form.lang_en',
  fr: 'admin.user_form.lang_fr',
  ar: 'admin.user_form.lang_ar',
};

/**
 * A timestamp as a person reads one.
 *
 * The API sends every instant as ISO 8601 in UTC, which is right for a wire
 * format and wrong to put on a card. These two are the only place that
 * changes, in the reader's own locale and zone.
 */
function at(value: string | null): string {
  return value === null || value === '' ? '' : new Date(value).toLocaleString();
}

function onDay(value: string | null): string {
  if (value === null || value === '') {
    return '';
  }

  return new Date(value).toLocaleDateString(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  });
}

@Component({
  selector: 'app-user-detail',
  standalone: true,
  imports: [CommonModule, RouterLink, AdmintopComponent, TranslatePipe],
  template: `
    <app-admintop></app-admintop>
    <main class="main">
      <div class="ui-page">
    <div *ngIf="loading" class="text-center py-5"><div class="spinner-border text-primary"></div></div>
    <div *ngIf="user && !loading">
      <!-- Header -->
      <header class="ui-page-header">
        <a routerLink="/admin/users" class="ui-page-header__back" [attr.aria-label]="'common.back' | translate"><i class="bi bi-chevron-left"></i></a>
        <div class="ui-page-header__icon user-initial" aria-hidden="true">{{ user.full_name.charAt(0) }}</div>
        <div class="ui-page-header__titles">
          <h1>{{ user.full_name }}</h1>
          <p>{{ user.email }}</p>
          <div class="header-pills">
            <span class="ui-pill" [ngClass]="{
                    'ui-pill--success': user.status === 'active',
                    'ui-pill--neutral': user.status === 'invited',
                    'ui-pill--warning': user.status === 'suspended',
                    'ui-pill--danger': user.status === 'locked'
                  }"><span class="ui-pill__dot"></span>{{ ('admin.users.status_' + user.status) | translate }}</span>
            <span *ngFor="let r of user.roles" class="ui-pill ui-pill--brand">{{ r.role_name }}</span>
          </div>
        </div>
        <div class="ui-page-header__actions">
          <a [routerLink]="['/admin/users', user.id, 'edit']" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>{{ 'common.edit' | translate }}</a>
          <button type="button" class="btn btn-sm" [ngClass]="user.status === 'active' ? 'btn-outline-secondary' : 'btn-primary'"
                  (click)="toggleStatus()">
            <i class="bi me-1" [ngClass]="user.status === 'active' ? 'bi-pause-circle' : 'bi-check-circle'"></i>
            {{ user.status === 'active' ? ('admin.users.suspend' | translate) : ('admin.users.activate' | translate) }}
          </button>
        </div>
      </header>

      <!-- Tabs -->
      <nav class="ui-tabs" role="tablist">
        <button type="button" role="tab" class="ui-tab" *ngFor="let tab of tabs"
                [class.is-active]="activeTab === tab.key" [attr.aria-selected]="activeTab === tab.key"
                (click)="onTabChange(tab.key)">
          <i class="bi" [ngClass]="tab.icon"></i>{{ tab.label | translate }}
        </button>
      </nav>

      <!-- Profile Tab -->
      <div *ngIf="activeTab === 'profile'" class="ui-row">
        <div class="section-stack">
          <section class="ui-section">
            <div class="ui-section__head">
              <h2 class="ui-section__title"><i class="bi bi-person me-2"></i>{{ 'admin.user_form.personal_info' | translate }}</h2>
            </div>
            <dl class="field-list">
              <div class="field-row" *ngFor="let f of personalFields">
                <dt>{{ f.label }}</dt><dd>{{ f.value || '-' }}</dd>
              </div>
            </dl>
          </section>
        </div>
        <div class="section-stack">
          <section class="ui-section">
            <div class="ui-section__head">
              <h2 class="ui-section__title"><i class="bi bi-building me-2"></i>{{ 'admin.user_form.organization' | translate }}</h2>
            </div>
            <dl class="field-list">
              <div class="field-row" *ngFor="let f of orgFields">
                <dt>{{ f.label }}</dt><dd>{{ f.value || '-' }}</dd>
              </div>
            </dl>
          </section>
          <section class="ui-section" *ngIf="customFields.length">
            <div class="ui-section__head">
              <h2 class="ui-section__title"><i class="bi bi-sliders me-2"></i>{{ 'admin.user_form.additional_info' | translate }}</h2>
            </div>
            <dl class="field-list">
              <div class="field-row" *ngFor="let f of customFields">
                <dt>{{ f.label }}</dt><dd>{{ f.value || '-' }}</dd>
              </div>
            </dl>
          </section>
          <section class="ui-section">
            <div class="ui-section__head">
              <h2 class="ui-section__title"><i class="bi bi-clock me-2"></i>{{ 'admin.user_detail.account' | translate }}</h2>
            </div>
            <dl class="field-list">
              <div class="field-row" *ngFor="let f of accountFields">
                <dt>{{ f.label }}</dt><dd>{{ f.value || '-' }}</dd>
              </div>
            </dl>
          </section>
        </div>
      </div>

      <!-- Roles Tab -->
      <div *ngIf="activeTab === 'roles'">
        <div class="ui-row">
          <section class="ui-section">
            <div class="ui-section__head">
              <h2 class="ui-section__title"><i class="bi bi-shield me-2"></i>{{ 'admin.user_detail.assigned_roles' | translate }}</h2>
              <div class="ui-section__actions">
                <div class="dropdown" *ngIf="availableRoles.length">
                  <button type="button" class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown">{{ 'admin.users.assign_role' | translate }}</button>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <li *ngFor="let r of availableRoles"><a class="dropdown-item" (click)="assignRole(r.role_id)">{{ r.role_name }}</a></li>
                  </ul>
                </div>
              </div>
            </div>
            <ul class="ui-list" *ngIf="user.roles.length">
              <li *ngFor="let r of user.roles" class="justify-content-between">
                <div class="min-w-0">
                  <span class="fw-semibold">{{ r.role_name }}</span>
                  <span class="ui-pill ui-pill--neutral ms-2" *ngIf="r.is_system">{{ 'admin.user_detail.system' | translate }}</span>
                  <br><small class="ui-muted">{{ r.description }}</small>
                </div>
                <button type="button" class="btn btn-sm btn-outline-danger" (click)="removeRole(r.role_id)" title="{{ 'common.remove' | translate }}"><i class="bi bi-x"></i></button>
              </li>
            </ul>
            <div *ngIf="!user.roles.length" class="ui-state">
              <span class="ui-state__icon"><i class="bi bi-shield"></i></span>
              <h3>{{ 'admin.user_detail.no_roles' | translate }}</h3>
            </div>
          </section>
          <section class="ui-section">
            <div class="ui-section__head">
              <h2 class="ui-section__title"><i class="bi bi-key me-2"></i>{{ 'admin.user_detail.effective_permissions' | translate:{ count: user.permissions.length } }}</h2>
            </div>
            <div class="perm-scroll">
              <div *ngFor="let p of user.permissions" class="d-flex align-items-center gap-2 mb-1">
                <i class="bi bi-check-circle-fill text-success small"></i>
                <small>{{ p }}</small>
              </div>
              <p *ngIf="!user.permissions.length" class="ui-muted text-center mb-0">{{ 'admin.user_detail.no_permissions' | translate }}</p>
            </div>
          </section>
        </div>
      </div>

      <!-- Activity Tab -->
      <div *ngIf="activeTab === 'activity'">
        <div *ngIf="loadingActivity" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></div>
        <section class="ui-section ui-section--flush" *ngIf="!loadingActivity">
          <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
              <thead>
                <tr><th>{{ 'admin.user_detail.col_time' | translate }}</th><th>{{ 'admin.audit.col_action' | translate }}</th><th>{{ 'admin.user_detail.col_details' | translate }}</th><th>{{ 'admin.user_detail.col_by' | translate }}</th><th>{{ 'admin.user_detail.col_ip' | translate }}</th></tr>
              </thead>
              <tbody>
                <tr *ngFor="let a of activities">
                  <td><small>{{ a.created_at | date:'short' }}</small></td>
                  <td><span class="badge" [class]="getActionBadge(a.action)">{{ a.action | titlecase }}</span></td>
                  <td><small class="ui-muted">{{ formatDetails(a.details) }}</small></td>
                  <td><small>{{ a.performed_by_name || ('admin.user_detail.system_actor' | translate) }}</small></td>
                  <td><small class="ui-muted">{{ a.ip_address || '-' }}</small></td>
                </tr>
                <tr *ngIf="!activities.length">
                  <td colspan="5" class="p-3">
                    <div class="ui-state">
                      <span class="ui-state__icon"><i class="bi bi-clock-history"></i></span>
                      <h3>{{ 'admin.user_detail.no_activity' | translate }}</h3>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </div>
      </div>
    </main>
  `,
  styles: [`
    .user-initial { font-weight: 700; font-size: 1.15rem; text-transform: uppercase; }
    .header-pills { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
    .section-stack { min-width: 0; }
    .section-stack > .ui-section:last-child { margin-bottom: 0; }
    .field-list { margin: 0; }
    .field-row { display: grid; grid-template-columns: minmax(0, 5fr) minmax(0, 7fr); gap: 12px; padding: 8px 0;
      border-bottom: 1px solid var(--ui-border); font-size: .8125rem; }
    .field-row:first-child { padding-top: 0; }
    .field-row:last-child { border-bottom: 0; padding-bottom: 0; }
    .field-row dt { font-weight: 500; color: var(--ui-text-muted); }
    .field-row dd { margin: 0; font-weight: 550; color: var(--ui-text); overflow-wrap: anywhere; }
    .perm-scroll { max-height: 400px; overflow-y: auto; }
    .min-w-0 { min-width: 0; }
  `]
})
export class UserDetailComponent implements OnInit, OnDestroy {
  private readonly usersService = inject(UsersService);
  private readonly rolesService = inject(RolesService);
  private readonly customFieldsService = inject(CustomFieldsService);
  private readonly auditService = inject(AuditService);
  private readonly route = inject(ActivatedRoute);
  private readonly i18n = inject(I18nService);
  private readonly dialog = inject(ConfirmDialogService);
  private readonly toast = inject(HotToastService);
  private readonly destroy$ = new Subject<void>();

  user: UserDetail | null = null;
  loading = true;
  activeTab = 'profile';

  tabs = [
    { key: 'profile', label: 'admin.user_detail.tab_profile', icon: 'bi-person' },
    { key: 'roles', label: 'admin.user_detail.tab_roles', icon: 'bi-shield-lock' },
    { key: 'activity', label: 'admin.user_detail.tab_activity', icon: 'bi-clock-history' },
  ];

  allRoles: Role[] = [];
  activities: UserActivity[] = [];
  loadingActivity = false;

  personalFields: Array<{ label: string; value: string }> = [];
  orgFields: Array<{ label: string; value: string }> = [];
  accountFields: Array<{ label: string; value: string }> = [];
  customFields: Array<{ label: string; value: string }> = [];

  /** The full role records, so permissions can be worked out without a fetch. */
  private roleIndex = new Map<string, ApiRole>();
  /** The tenant's active custom field definitions, for labels and ordering. */
  private customDefs: CustomFieldDefinition[] = [];
  private userId = '';

  ngOnInit(): void {
    this.userId = this.route.snapshot.paramMap.get('id') ?? '';

    if (this.userId === '') {
      this.loading = false;

      return;
    }

    forkJoin({
      user: this.usersService.show(this.userId),
      roles: this.rolesService.list(),
      customFields: this.customFieldsService.list(),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ user, roles, customFields }) => {
          for (const role of roles) {
            this.roleIndex.set(role.id, role);
          }

          this.allRoles = roles.map((r) => ({ role_id: r.id, role_name: r.name }));
          this.customDefs = customFields.filter((f) => f.isActive);
          this.apply(user);
          this.loading = false;
        },
        error: () => (this.loading = false),
      });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadUser(): void {
    this.usersService
      .show(this.userId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (user) => this.apply(user),
        error: () => undefined,
      });
  }

  private apply(user: ApiUser): void {
    const roles = user.roleIds
      .map((id) => this.roleIndex.get(id))
      .filter((role): role is ApiRole => role !== undefined);

    // The union of what their roles allow, from the list already in hand.
    // The legacy sent this as a field on the user payload, recomputing the
    // same union server-side on every read.
    const permissions = [...new Set(roles.flatMap((role) => role.permissions))].sort();

    this.user = {
      id: user.id,
      full_name: user.fullName,
      email: user.email,
      status: user.status,
      roles: roles.map((role) => ({
        role_id: role.id,
        role_name: role.name,
        role_key: role.slug,
        description: role.description,
        is_system: role.isSystem,
      })),
      permissions,
    };

    this.buildFields(user);
  }

  private formatCustom(
    value: string | number | boolean | undefined,
    field: CustomFieldDefinition,
  ): string {
    if (value === undefined || value === null || value === '') {
      return '';
    }

    if (field.type === 'boolean') {
      return value ? this.i18n.t('common.yes') : this.i18n.t('common.no');
    }

    return String(value);
  }

  private buildFields(u: ApiUser): void {
    this.personalFields = [
      { label: this.i18n.t('admin.user_detail.full_name'), value: u.fullName },
      { label: this.i18n.t('admin.users.col_email'), value: u.email },
      { label: this.i18n.t('admin.user_form.phone'), value: u.phone ?? '' },
      { label: this.i18n.t('admin.user_form.gender'), value: u.gender === null ? '' : this.i18n.t('admin.user_form.gender_' + u.gender) },
      { label: this.i18n.t('common.language'), value: this.i18n.t(LANGUAGES[u.locale] ?? '') || u.locale },
    ];

    this.orgFields = [
      { label: this.i18n.t('admin.user_form.employee_id'), value: u.employeeId ?? '' },
      { label: this.i18n.t('admin.user_detail.job_title'), value: u.placement?.jobTitleName ?? '' },
      { label: this.i18n.t('admin.enrollees.col_department'), value: u.placement?.departmentName ?? '' },
      { label: this.i18n.t('admin.user_form.job_grade'), value: u.placement?.levelName ?? '' },
      { label: this.i18n.t('admin.user_form.team'), value: u.placement?.teamName ?? '' },
      { label: this.i18n.t('admin.user_form.branch'), value: u.placement?.branchName ?? '' },
      { label: this.i18n.t('admin.user_detail.manager'), value: u.placement?.managerName ?? '' },
      { label: this.i18n.t('admin.user_form.line_executive'), value: u.placement?.lineExecutiveName ?? '' },
      { label: this.i18n.t('admin.user_form.hire_date'), value: onDay(u.hireDate) },
    ];

    this.customFields = this.customDefs.map((f) => ({
      label: f.label,
      value: this.formatCustom(u.customFields?.[f.key], f),
    }));

    this.accountFields = [
      { label: this.i18n.t('admin.enrollees.col_status'), value: this.i18n.t('admin.users.status_' + u.status) },
      { label: this.i18n.t('admin.user_detail.two_factor'), value: u.twoFactor ? this.i18n.t('admin.user_detail.on') : this.i18n.t('admin.user_detail.off') },
      { label: this.i18n.t('admin.user_detail.last_login'), value: u.lastLoginAt === null ? this.i18n.t('admin.users.never') : at(u.lastLoginAt) },
      { label: this.i18n.t('admin.user_detail.login_count'), value: String(u.loginCount) },
      { label: this.i18n.t('admin.user_detail.created'), value: onDay(u.createdAt) },
    ];
  }

  get availableRoles(): Role[] {
    const assigned = new Set(this.user?.roles.map((r) => r.role_id) ?? []);

    return this.allRoles.filter((r) => !assigned.has(r.role_id));
  }

  get statusClass(): string {
    return ({
      active: 'bg-success',
      invited: 'bg-secondary',
      suspended: 'bg-warning text-dark',
      locked: 'bg-danger',
    } as Record<string, string>)[this.user?.status ?? ''] ?? 'bg-secondary';
  }

  /**
   * Adding a role.
   *
   * Written as the whole set. `assign-role` was a second way to write
   * `user_roles` and disagreed with the user form about whether somebody
   * could be left with none — so the answer depended on which screen you used.
   */
  assignRole(roleId: string): void {
    const user = this.user;

    if (user === null) {
      return;
    }

    this.save([...user.roles.map((r) => r.role_id), roleId]);
  }

  async removeRole(roleId: string): Promise<void> {
    const user = this.user;

    if (user === null || !(await this.dialog.confirm(this.i18n.t('admin.user_detail.confirm_remove_role'), { type: 'danger' }))) {
      return;
    }

    this.save(user.roles.filter((r) => r.role_id !== roleId).map((r) => r.role_id));
  }

  private save(roleIds: string[]): void {
    this.usersService
      .update(this.userId, { roleIds })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (user) => this.apply(user),
        error: (e: ApiError) => this.toast.error(e.violation('roleIds') ?? e.message),
      });
  }

  toggleStatus(): void {
    const user = this.user;

    if (user === null) {
      return;
    }

    this.usersService
      .setStatus(this.userId, user.status === 'active' ? 'suspended' : 'active')
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (updated) => this.apply(updated),
        error: (e: ApiError) => this.toast.error(e.message),
      });
  }

  /**
   * The Activity tab, loaded when it is opened.
   *
   * `ngDoCheck` used to fire this — on every change detection pass, guarded
   * only by a flag, which is a request waiting for the guard to be wrong.
   */
  onTabChange(key: string): void {
    this.activeTab = key;

    if (key === 'activity' && this.activities.length === 0 && !this.loadingActivity) {
      this.loadActivity();
    }
  }

  loadActivity(): void {
    this.loadingActivity = true;

    // Things done *to* this person. The audit trail records the subject of
    // every write, so "who suspended this account?" has an answer.
    this.auditService
      .list({ subjectId: this.userId, perPage: 50 })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => {
          this.activities = page.items.map((event) => ({
            action: event.description,
            details: { path: event.path, result: event.status },
            ip_address: event.ipAddress,
            performed_by_name: event.actorName,
            created_at: event.createdAt,
          }));
          this.loadingActivity = false;
        },
        // Reading the trail needs `audit.view`, which not every administrator
        // holds. An empty tab is the honest answer to being refused.
        error: () => {
          this.activities = [];
          this.loadingActivity = false;
        },
      });
  }

  getColor(name: string): string {
    const c = ['#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f'];

    let h = 0;

    for (let i = 0; i < name.length; i++) {
      h = name.charCodeAt(i) + ((h << 5) - h);
    }

    return c[Math.abs(h) % c.length];
  }

  getActionBadge(action: string): string {
    const said = action.toLowerCase();

    if (said.includes('sign') || said.includes('login')) {
      return 'bg-info text-dark';
    }

    if (said.includes('created') || said.includes('activated')) {
      return 'bg-success';
    }

    if (said.includes('deleted') || said.includes('suspended')) {
      return 'bg-danger';
    }

    return 'bg-secondary';
  }

  formatDetails(d: Record<string, unknown>): string {
    return Object.entries(d)
      .filter(([, v]) => v !== null && v !== undefined && v !== '')
      .map(([k, v]) => `${k}: ${String(v)}`)
      .join(', ');
  }
}
