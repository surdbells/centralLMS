// users/components/user-list/user-list.component.ts
import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Subject, debounceTime, takeUntil } from 'rxjs';
import { UserService } from '../../services/user.service';
import { User, UserStats, UserFilters, Role } from '../../models/user.models';
import {AdmintopComponent} from '../../../../partials/top/admintop/admintop.component';

@Component({
  selector: 'app-user-list',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent],
  templateUrl: `user-list.component.html`,
  styles: [`
    .avatar-circle { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:600; font-size:14px; flex-shrink:0; }
    .sortable { cursor:pointer; user-select:none; }
    .sortable:hover { color: var(--bs-primary); }
    .cursor-pointer { cursor:pointer; }
  `]
})
export class UserListComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();
  private search$ = new Subject<string>();

  users: User[] = [];
  stats: UserStats | null = null;
  roles: Role[] = [];
  departments: any[] = [];
  loading = false;
  tempPasswordModal = '';

  filters: UserFilters = { page: 1, per_page: 25, sort_by: 'first_name', sort_dir: 'asc' };
  meta = { total: 0, page: 1, per_page: 25, total_pages: 0 };
  selectedIds: number[] = [];

  statCards: { label: string; value: number; icon: string; color: string; filter: string }[] = [];

  constructor(private svc: UserService) {}

  ngOnInit(): void {
    this.search$.pipe(debounceTime(400), takeUntil(this.destroy$)).subscribe(() => this.loadUsers());
    this.loadUsers();
    this.loadStats();
    this.loadDropdowns();
  }

  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }

  loadUsers(): void {
    this.loading = true;
    this.svc.listUsers(this.filters).pipe(takeUntil(this.destroy$)).subscribe({
      next: res => { this.users = res.data; this.meta = res.meta; this.loading = false; },
      error: () => { this.loading = false; }
    });
  }

  loadStats(): void {
    this.svc.getStats().subscribe(s => {
      this.stats = s;
      this.statCards = [
        { label: 'Total', value: s.total_users, icon: 'bi-people-fill', color: 'text-primary', filter: '' },
        { label: 'Active', value: s.active, icon: 'bi-check-circle-fill', color: 'text-success', filter: 'active' },
        { label: 'Inactive', value: s.inactive, icon: 'bi-dash-circle-fill', color: 'text-secondary', filter: 'inactive' },
        { label: 'Suspended', value: s.suspended, icon: 'bi-pause-circle-fill', color: 'text-warning', filter: 'suspended' },
        { label: 'Locked', value: s.locked, icon: 'bi-lock-fill', color: 'text-danger', filter: 'locked' },
        { label: 'Active 30d', value: s.active_30d, icon: 'bi-activity', color: 'text-info', filter: '' },
      ];
    });
  }

  loadDropdowns(): void {
    this.svc.listRoles().subscribe(r => this.roles = r);
    // Departments loaded from reports API org-units (or dedicated endpoint)
    // For now, extract from stats
    this.svc.getStats().subscribe(s => {
      this.departments = (s.by_department || []).map((d: any) => ({ department_id: d.department_id, department_name: d.department_name }));
    });
  }

  onSearchChange(term: string): void { this.search$.next(term); }
  filterByStatus(status: string): void { this.filters.status = this.filters.status === status ? '' : status; this.filters.page = 1; this.loadUsers(); }
  resetFilters(): void { this.filters = { page: 1, per_page: 25, sort_by: 'first_name', sort_dir: 'asc' }; this.loadUsers(); }
  sort(col: string): void { if (this.filters.sort_by === col) this.filters.sort_dir = this.filters.sort_dir === 'asc' ? 'desc' : 'asc'; else { this.filters.sort_by = col; this.filters.sort_dir = 'asc'; } this.loadUsers(); }
  goPage(p: number): void { this.filters.page = p; this.loadUsers(); }

  /* Selection */
  get allSelected(): boolean { return this.users.length > 0 && this.selectedIds.length === this.users.length; }
  toggleSelectAll(e: Event): void { this.selectedIds = (e.target as HTMLInputElement).checked ? this.users.map(u => u.id) : []; }
  toggleSelect(id: number): void { const i = this.selectedIds.indexOf(id); i >= 0 ? this.selectedIds.splice(i, 1) : this.selectedIds.push(id); }

  /* Quick actions */
  quickAction(action: string, user: User): void {
    if (action === 'delete' && !confirm(`Delete "${user.full_name}"?`)) return;
    if (action === 'suspend' && !confirm(`Suspend "${user.full_name}"?`)) return;

    const obs = action === 'activate' ? this.svc.activateUser(user.id) :
                action === 'suspend' ? this.svc.suspendUser(user.id) :
                action === 'unlock' ? this.svc.unlockUser(user.id) :
                action === 'delete' ? this.svc.deleteUser(user.id) :
                action === 'reset-password' ? this.svc.resetPassword(user.id) : null;
    if (!obs) return;

    obs.subscribe({
      next: (res: any) => {
        if (action === 'reset-password' && res?.data?.temp_password) {
          this.tempPasswordModal = res.data.temp_password;
        }
        this.loadUsers(); this.loadStats();
      },
      error: () => alert(`Failed to ${action}`)
    });
  }

  /* Bulk */
  bulkAction(action: string, extra: any = {}): void {
    if (action === 'delete' && !confirm(`Delete ${this.selectedIds.length} users?`)) return;
    this.svc.bulkAction({ bulk_action: action, user_ids: this.selectedIds, extra }).subscribe({
      next: () => { this.selectedIds = []; this.loadUsers(); this.loadStats(); },
      error: () => alert('Bulk action failed')
    });
  }

  /* Helpers */
  getAvatarColor(name: string): string {
    const colors = ['#4e79a7','#f28e2b','#e15759','#76b7b2','#59a14f','#edc948','#b07aa1','#ff9da7','#9c755f','#bab0ac'];
    let hash = 0; for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return colors[Math.abs(hash) % colors.length];
  }

  getRoleBadgeClass(role: string): string {
    const map: Record<string, string> = {
      superadmin: 'bg-danger', admin: 'bg-primary', hr_manager: 'bg-info text-black',
      hr: 'bg-info text-black', manager: 'bg-warning text-black', instructor: 'bg-success',
      content_creator: 'bg-secondary', learner: 'bg-light text-black border'
    };
    return map[role] || 'bg-light text-black border';
  }

  getStatusBadgeClass(status: string): string {
    return { active: 'bg-success', inactive: 'bg-secondary', suspended: 'bg-warning text-dark', locked: 'bg-danger' }[status] || 'bg-secondary';
  }
}
