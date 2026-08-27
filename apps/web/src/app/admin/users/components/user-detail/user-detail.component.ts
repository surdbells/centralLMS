import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Subject, forkJoin } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { UsersService, type User as ApiUser } from '../../../../services/users.service';
import { RolesService, type Role as ApiRole } from '../../../../services/roles.service';
import { AuditService } from '../../../../services/audit.service';
import { ApiError } from '../../../../core/api';
import { AdmintopComponent } from '../../../../partials/top/admintop/admintop.component';

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

const LANGUAGES: Record<string, string> = { en: 'English', fr: 'French', ar: 'Arabic' };

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
  imports: [CommonModule, RouterLink, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>
    <main class="main px-lg-6">
      <div class="container-fluid">
    <div *ngIf="loading" class="text-center py-5"><div class="spinner-border text-primary"></div></div>
    <div *ngIf="user && !loading">
      <!-- Header -->
      <div class="d-flex justify-content-between align-items-start mb-4">
        <div class="d-flex gap-3 align-items-center">
          <a routerLink="/admin/users" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i></a>
          <div class="avatar-lg" [style.background-color]="getColor(user.full_name)">{{ user.full_name.charAt(0) }}</div>
          <div>
            <h4 class="fw-bold mb-0">{{ user.full_name }}</h4>
            <p class="text-muted mb-0">{{ user.email }}
              <span class="badge ms-2" [class]="statusClass">{{ user.status | titlecase }}</span>
            </p>
            <div class="mt-1">
              <span *ngFor="let r of user.roles" class="badge bg-primary-subtle text-primary me-1">{{ r.role_name }}</span>
            </div>
          </div>
        </div>
        <div class="d-flex gap-2">
          <a [routerLink]="['/admin/users', user.id, 'edit']" class="btn btn-sm btn-secondary text-white"><i class="bi bi-pencil me-1"></i>Edit</a>
          <button class="btn btn-sm" [class]="user.status === 'active' ? 'btn-secondary text-white' : 'btn-success'"
                  (click)="toggleStatus()">
            <i class="bi" [class]="user.status === 'active' ? 'bi-pause-circle' : 'bi-check-circle'"></i>
            {{ user.status === 'active' ? 'Suspend' : 'Activate' }}
          </button>
        </div>
      </div>

      <!-- Tabs -->
      <ul class="nav nav-tabs mb-3">
        <li class="nav-item" *ngFor="let tab of tabs">
          <a class="nav-link" [class.active]="activeTab === tab.key" role="button" (click)="onTabChange(tab.key)">
            <i class="bi me-1" [class]="tab.icon"></i>{{ tab.label }}
          </a>
        </li>
      </ul>

      <!-- Profile Tab -->
      <div *ngIf="activeTab === 'profile'" class="row g-4">
        <div class="col-md-6">
          <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person me-2"></i>Personal Information</div>
            <div class="card-body">
              <div class="row mb-2" *ngFor="let f of personalFields">
                <div class="col-5 text-muted small">{{ f.label }}</div>
                <div class="col-7 small fw-medium">{{ f.value || '-' }}</div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-building me-2"></i>Organization</div>
            <div class="card-body">
              <div class="row mb-2" *ngFor="let f of orgFields">
                <div class="col-5 text-muted small">{{ f.label }}</div>
                <div class="col-7 small fw-medium">{{ f.value || '-' }}</div>
              </div>
            </div>
          </div>
          <div class="card border-0 shadow-sm mt-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clock me-2"></i>Account</div>
            <div class="card-body">
              <div class="row mb-2" *ngFor="let f of accountFields">
                <div class="col-5 text-muted small">{{ f.label }}</div>
                <div class="col-7 small fw-medium">{{ f.value || '-' }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Roles Tab -->
      <div *ngIf="activeTab === 'roles'">
        <div class="row g-4">
          <div class="col-md-6">
            <div class="card border-0 shadow-sm">
              <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-shield me-2"></i>Assigned Roles</span>
                <div class="dropdown" *ngIf="availableRoles.length">
                  <button class="btn btn-sm btn-primary dropdown-toggle" data-bs-toggle="dropdown">Assign Role</button>
                  <ul class="dropdown-menu">
                    <li *ngFor="let r of availableRoles"><a class="dropdown-item" (click)="assignRole(r.role_id)">{{ r.role_name }}</a></li>
                  </ul>
                </div>
              </div>
              <ul class="list-group list-group-flush">
                <li *ngFor="let r of user.roles" class="list-group-item d-flex justify-content-between align-items-center">
                  <div>
                    <span class="fw-semibold">{{ r.role_name }}</span>
                    <span class="badge bg-light text-muted ms-2" *ngIf="r.is_system">System</span>
                    <br><small class="text-muted">{{ r.description }}</small>
                  </div>
                  <button class="btn btn-sm btn-outline-danger" (click)="removeRole(r.role_id)" title="Remove"><i class="bi bi-x"></i></button>
                </li>
                <li *ngIf="!user.roles.length" class="list-group-item text-muted text-center py-3">No roles assigned</li>
              </ul>
            </div>
          </div>
          <div class="col-md-6">
            <div class="card border-0 shadow-sm">
              <div class="card-header bg-white fw-semibold"><i class="bi bi-key me-2"></i>Effective Permissions ({{ user.permissions.length }})</div>
              <div class="card-body" style="max-height:400px; overflow-y:auto">
                <div *ngFor="let p of user.permissions" class="d-flex align-items-center gap-2 mb-1">
                  <i class="bi bi-check-circle-fill text-success small"></i>
                  <small>{{ p }}</small>
                </div>
                <p *ngIf="!user.permissions.length" class="text-muted text-center mb-0">No permissions</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Activity Tab -->
      <div *ngIf="activeTab === 'activity'">
        <div *ngIf="loadingActivity" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></div>
        <div class="card border-0 shadow-sm" *ngIf="!loadingActivity">
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead class="table-light">
                <tr><th>Time</th><th>Action</th><th>Details</th><th>By</th><th>IP</th></tr>
              </thead>
              <tbody>
                <tr *ngFor="let a of activities">
                  <td><small>{{ a.created_at | date:'short' }}</small></td>
                  <td><span class="badge" [class]="getActionBadge(a.action)">{{ a.action | titlecase }}</span></td>
                  <td><small class="text-muted">{{ formatDetails(a.details) }}</small></td>
                  <td><small>{{ a.performed_by_name || 'System' }}</small></td>
                  <td><small class="text-muted">{{ a.ip_address || '-' }}</small></td>
                </tr>
                <tr *ngIf="!activities.length"><td colspan="5" class="text-center text-muted py-3">No activity recorded</td></tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
      </div>
    </main>
  `,
  styles: [`
    .avatar-lg { width:56px; height:56px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:24px; flex-shrink:0; }
    .nav-link { cursor:pointer; }
  `]
})
export class UserDetailComponent implements OnInit, OnDestroy {
  private readonly usersService = inject(UsersService);
  private readonly rolesService = inject(RolesService);
  private readonly auditService = inject(AuditService);
  private readonly route = inject(ActivatedRoute);
  private readonly destroy$ = new Subject<void>();

  user: UserDetail | null = null;
  loading = true;
  activeTab = 'profile';

  tabs = [
    { key: 'profile', label: 'Profile', icon: 'bi-person' },
    { key: 'roles', label: 'Roles & Permissions', icon: 'bi-shield-lock' },
    { key: 'activity', label: 'Activity', icon: 'bi-clock-history' },
  ];

  allRoles: Role[] = [];
  activities: UserActivity[] = [];
  loadingActivity = false;

  personalFields: Array<{ label: string; value: string }> = [];
  orgFields: Array<{ label: string; value: string }> = [];
  accountFields: Array<{ label: string; value: string }> = [];

  /** The full role records, so permissions can be worked out without a fetch. */
  private roleIndex = new Map<string, ApiRole>();
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
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ user, roles }) => {
          for (const role of roles) {
            this.roleIndex.set(role.id, role);
          }

          this.allRoles = roles.map((r) => ({ role_id: r.id, role_name: r.name }));
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

  private buildFields(u: ApiUser): void {
    this.personalFields = [
      { label: 'Full Name', value: u.fullName },
      { label: 'Email', value: u.email },
      { label: 'Phone', value: u.phone ?? '' },
      { label: 'Language', value: LANGUAGES[u.locale] ?? u.locale },
    ];

    this.orgFields = [
      { label: 'Branch', value: u.placement?.branchName ?? '' },
      { label: 'Department', value: u.placement?.departmentName ?? '' },
      { label: 'Team', value: u.placement?.teamName ?? '' },
      { label: 'Job Title', value: u.placement?.jobTitleName ?? '' },
      { label: 'Manager', value: u.placement?.managerName ?? '' },
      { label: 'Hire Date', value: onDay(u.hireDate) },
    ];

    this.accountFields = [
      { label: 'Status', value: u.status.charAt(0).toUpperCase() + u.status.slice(1) },
      { label: 'Two-factor', value: u.twoFactor ? 'On' : 'Off' },
      { label: 'Last Login', value: u.lastLoginAt === null ? 'Never' : at(u.lastLoginAt) },
      { label: 'Login Count', value: String(u.loginCount) },
      { label: 'Created', value: onDay(u.createdAt) },
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

  removeRole(roleId: string): void {
    const user = this.user;

    if (user === null || !confirm('Remove this role?')) {
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
        error: (e: ApiError) => alert(e.violation('roleIds') ?? e.message),
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
        error: (e: ApiError) => alert(e.message),
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
