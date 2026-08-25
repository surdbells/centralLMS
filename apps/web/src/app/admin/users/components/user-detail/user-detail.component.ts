// users/components/user-detail/user-detail.component.ts
import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { UserService } from '../../services/user.service';
import { UserDetail, Role, UserActivity } from '../../models/user.models';
import {AdmintopComponent} from '../../../../partials/top/admintop/admintop.component';
type UserStatus = 'active' | 'inactive' | 'suspended' | 'locked';
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
          <a class="nav-link" [class.active]="activeTab === tab.key" role="button" (click)="activeTab = tab.key">
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
export class UserDetailComponent implements OnInit {
  user: UserDetail | null = null;
  loading = true;
  activeTab = 'profile';
  tabs = [
    { key: 'profile', label: 'Profile', icon: 'bi-person' },
    { key: 'roles', label: 'Roles & Permissions', icon: 'bi-shield-lock' },
    { key: 'activity', label: 'Activity', icon: 'bi-clock-history' }
  ];

  allRoles: Role[] = [];
  activities: UserActivity[] = [];
  loadingActivity = false;

  personalFields: { label: string; value: string }[] = [];
  orgFields: { label: string; value: string }[] = [];
  accountFields: { label: string; value: string }[] = [];

  constructor(private svc: UserService, private route: ActivatedRoute) {}

  ngOnInit(): void {
    const id = +this.route.snapshot.paramMap.get('id')!;
    this.loadUser(id);
    this.svc.listRoles().subscribe(r => this.allRoles = r);
  }

  loadUser(id: number): void {
    this.loading = true;
    this.svc.getUser(id).subscribe({
      next: u => {
        this.user = u;
        this.buildFields(u);
        this.loading = false;
      },
      error: () => { this.loading = false; }
    });
  }

  private buildFields(u: UserDetail): void {
    this.personalFields = [
      { label: 'Full Name', value: u.full_name },
      { label: 'Email', value: u.email },
      { label: 'Phone', value: u.phone || '' },
      { label: 'Staff ID', value: u.staffId || '' },
      { label: 'Language', value: u.language || 'en' },
      { label: 'Learning Mode', value: u.preferred_learning_mode || '' },
    ];
    this.orgFields = [
      { label: 'Department', value: u.department_name || '' },
      { label: 'Team', value: u.team_name || '' },
      { label: 'Branch', value: u.branch_name || '' },
      { label: 'Manager', value: u.manager_name || '' },
      { label: 'Hire Date', value: u.hire_date || '' },
    ];
    this.accountFields = [
      { label: 'Status', value: u.status },
      { label: 'Last Login', value: u.last_login_at || 'Never' },
      { label: 'Login Count', value: String(u.login_count || 0) },
      { label: 'Created', value: u.created },
      { label: 'Account Locked', value: u.account_locked ? 'Yes' : 'No' },
    ];
  }

  get availableRoles(): Role[] {
    if (!this.user) return [];
    const assigned = new Set(this.user.roles.map(r => r.role_id));
    return this.allRoles.filter(r => !assigned.has(r.role_id));
  }

  get statusClass(): string {
    const map: Record<UserStatus, string> = {
      active: 'bg-success',
      inactive: 'bg-secondary',
      suspended: 'bg-warning text-dark',
      locked: 'bg-danger'
    };
    const status = this.user?.status as UserStatus | undefined;
    return status ? map[status] : 'bg-secondary';
  }

  assignRole(roleId: number): void {
    if (!this.user) return;
    this.svc.assignRole(this.user.id, roleId).subscribe(() => this.loadUser(this.user!.id));
  }

  removeRole(roleId: number): void {
    if (!this.user || !confirm('Remove this role?')) return;
    this.svc.removeRole(this.user.id, roleId).subscribe(() => this.loadUser(this.user!.id));
  }

  toggleStatus(): void {
    if (!this.user) return;
    const obs = this.user.status === 'active' ? this.svc.suspendUser(this.user.id) : this.svc.activateUser(this.user.id);
    obs.subscribe(() => this.loadUser(this.user!.id));
  }

  // Lazy load activity on tab click
  ngDoCheck(): void {
    if (this.activeTab === 'activity' && !this.activities.length && !this.loadingActivity && this.user) {
      this.loadingActivity = true;
      this.svc.getActivity({ target_user_id: this.user.id, per_page: 50 }).subscribe({
        next: res => { this.activities = res.data; this.loadingActivity = false; },
        error: () => { this.loadingActivity = false; }
      });
    }
  }

  getColor(name: string): string {
    const c = ['#4e79a7','#f28e2b','#e15759','#76b7b2','#59a14f'];
    let h = 0; for (let i = 0; i < name.length; i++) h = name.charCodeAt(i) + ((h << 5) - h);
    return c[Math.abs(h) % c.length];
  }

  getActionBadge(action: string): string {
    if (action.includes('login')) return 'bg-info text-dark';
    if (action.includes('created') || action.includes('activated')) return 'bg-success';
    if (action.includes('suspended') || action.includes('deleted')) return 'bg-danger';
    return 'bg-secondary';
  }

  formatDetails(d: Record<string, any>): string {
    if (!d || !Object.keys(d).length) return '';
    return Object.entries(d).map(([k, v]) => `${k}: ${v}`).join(', ');
  }
}
