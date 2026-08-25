// users/components/user-activity-log/user-activity-log.component.ts
import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { UserService } from '../../services/user.service';
import { UserActivity } from '../../models/user.models';
import {AdmintopComponent} from '../../../../partials/top/admintop/admintop.component';
interface ActivityFilters {
  action: string;
  date_from: string;
  date_to: string;
  page: number;
  per_page: number;
}

@Component({
  selector: 'app-user-activity-log',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent],
  template: `
    <<app-admintop></app-admintop>
    <main class="main px-lg-6">
      <div class="container-fluid">
    <div class="activity-log">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
          <a routerLink="/admin/users" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i></a>
          <h4 class="fw-bold mb-0"><i class="bi bi-clock-history me-2"></i>Activity Log</h4>
        </div>
      </div>

      <!-- Filters -->
      <div class="card border-0 shadow-sm mb-3">
        <div class="card-body py-2">
          <div class="row g-2 align-items-center">
            <div class="col-md-3">
              <select class="form-select form-select-sm" [(ngModel)]="filters.action" (change)="loadActivity()">
                <option value="">All Actions</option>
                <option *ngFor="let a of actionTypes" [value]="a">{{ formatAction(a) }}</option>
              </select>
            </div>
            <div class="col-md-2">
              <input type="date" class="form-control form-control-sm" [(ngModel)]="filters.date_from" (change)="loadActivity()" placeholder="From">
            </div>
            <div class="col-md-2">
              <input type="date" class="form-control form-control-sm" [(ngModel)]="filters.date_to" (change)="loadActivity()" placeholder="To">
            </div>
            <div class="col-md-2">
              <select class="form-select form-select-sm" [(ngModel)]="filters.per_page" (change)="loadActivity()">
                <option [ngValue]="25">25 per page</option>
                <option [ngValue]="50">50 per page</option>
                <option [ngValue]="100">100 per page</option>
              </select>
            </div>
            <div class="col-md-3 text-end">
              <span class="text-muted small" *ngIf="meta.total">{{ meta.total }} records</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Table -->
      <div class="card border-0 shadow-sm">
        <div class="table-responsive">
          <table class="table table-sm table-hover mb-0">
            <thead class="table-light">
              <tr>
                <th>Time</th><th>User</th><th>Action</th><th>Details</th><th>Performed By</th><th>IP Address</th>
              </tr>
            </thead>
            <tbody>
              <tr *ngIf="loading"><td colspan="6" class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div></td></tr>
              <tr *ngIf="!loading && !activities.length"><td colspan="6" class="text-center py-4 text-muted">No activity found</td></tr>
              <tr *ngFor="let a of activities">
                <td><small>{{ a.created_at | date:'medium' }}</small></td>
                <td>
                  <a [routerLink]="['/admin/users', a.user_id]" class="text-decoration-none">
                    <small class="fw-semibold">{{ a.user_name }}</small>
                  </a>
                  <br><small class="text-muted">{{ a.user_email }}</small>
                </td>
                <td>
                  <span class="badge" [class]="getActionBadge(a.action)">
                    <i class="bi me-1" [class]="getActionIcon(a.action)"></i>{{ formatAction(a.action) }}
                  </span>
                </td>
                <td><small class="text-muted" style="max-width:250px; display:block; overflow:hidden; text-overflow:ellipsis">{{ formatDetails(a.details) }}</small></td>
                <td><small>{{ a.performed_by_name || 'System' }}</small></td>
                <td><small class="text-muted font-monospace">{{ a.ip_address || '-' }}</small></td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="card-footer bg-white d-flex justify-content-between align-items-center py-2" *ngIf="meta.total_pages > 1">
          <small class="text-muted">Page {{ meta.page }} of {{ meta.total_pages }}</small>
          <div class="d-flex gap-1">
            <button class="btn btn-sm btn-secondary" [disabled]="meta.page <= 1" (click)="goPage(meta.page - 1)">‹</button>
            <button class="btn btn-sm btn-secondary" [disabled]="meta.page >= meta.total_pages" (click)="goPage(meta.page + 1)">›</button>
          </div>
        </div>
      </div>
    </div>
      </div>
    </main>
  `
})
export class UserActivityLogComponent implements OnInit {
  activities: UserActivity[] = [];
  loading = false;
  filters: ActivityFilters = {
    action: '',
    date_from: '',
    date_to: '',
    page: 1,
    per_page: 25
  };
  meta = { total: 0, page: 1, per_page: 25, total_pages: 0 };

  actionTypes = [
    'login', 'logout', 'user_created', 'user_updated', 'user_deleted',
    'user_activated', 'user_suspended', 'account_unlocked', 'password_reset',
    'role_assigned', 'role_removed', 'role_created', 'role_updated', 'role_deleted',
    'role_permissions_updated'
  ];

  constructor(private svc: UserService) {}

  ngOnInit(): void { this.loadActivity(); }

  loadActivity(): void {
    this.loading = true;
    const params: Record<string, any> = {};
    for (const [k, v] of Object.entries(this.filters)) { if (v !== '' && v !== null) params[k] = v; }

    this.svc.getActivity(params).subscribe({
      next: res => { this.activities = res.data; this.meta = res.meta; this.loading = false; },
      error: () => { this.loading = false; }
    });
  }

  goPage(p: number): void { this.filters['page'] = p; this.loadActivity(); }

  formatAction(action: string): string {
    return action.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  formatDetails(d: Record<string, any>): string {
    if (!d || !Object.keys(d).length) return '';
    return Object.entries(d).map(([k, v]) => `${k}: ${typeof v === 'object' ? JSON.stringify(v) : v}`).join(', ');
  }

  getActionBadge(action: string): string {
    if (action.includes('login') || action.includes('logout')) return 'bg-info text-dark';
    if (action.includes('created') || action.includes('activated') || action.includes('unlocked')) return 'bg-success';
    if (action.includes('deleted') || action.includes('suspended')) return 'bg-danger';
    if (action.includes('updated') || action.includes('assigned') || action.includes('removed')) return 'bg-warning text-dark';
    return 'bg-secondary';
  }

  getActionIcon(action: string): string {
    if (action.includes('login')) return 'bi-box-arrow-in-right';
    if (action.includes('created')) return 'bi-plus-circle';
    if (action.includes('deleted')) return 'bi-trash';
    if (action.includes('suspended')) return 'bi-pause-circle';
    if (action.includes('activated')) return 'bi-check-circle';
    if (action.includes('role')) return 'bi-shield';
    if (action.includes('password')) return 'bi-key';
    return 'bi-activity';
  }
}
