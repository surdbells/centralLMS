import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Observable, Subject, forkJoin, of } from 'rxjs';
import { catchError, debounceTime, distinctUntilChanged, takeUntil } from 'rxjs/operators';
import {
  UsersService,
  type User,
  type UserFilters,
  type UserStatistics,
  type UserStatus,
} from '../../../../services/users.service';
import { RolesService, type Role } from '../../../../services/roles.service';
import { LookupsService, type Lookup } from '../../../../services/lookups.service';
import { ApiError } from '../../../../core/api';
import { AdmintopComponent } from '../../../../partials/top/admintop/admintop.component';

interface StatCard {
  label: string;
  value: number;
  icon: string;
  colour: string;
  status: UserStatus | '';
}

/**
 * The people in an organisation.
 *
 * The old screen sorted by asking the server for `sort_by`/`sort_dir`, which
 * it ignored; the column headers did nothing. They are gone rather than left
 * looking clickable.
 *
 * Bulk actions are the per-person calls, run together. There is no bulk
 * endpoint, and inventing one that loops server-side would only move the
 * partial-failure problem out of sight — if three of five suspensions fail,
 * this says which.
 */
@Component({
  selector: 'app-user-list',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent],
  templateUrl: './user-list.component.html',
  styles: [`
    .avatar-circle { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:600; font-size:14px; flex-shrink:0; }
    .cursor-pointer { cursor:pointer; }
  `],
})
export class UserListComponent implements OnInit, OnDestroy {
  private readonly usersService = inject(UsersService);
  private readonly rolesService = inject(RolesService);
  private readonly lookupsService = inject(LookupsService);

  private readonly destroy$ = new Subject<void>();
  private readonly search$ = new Subject<string>();

  users: User[] = [];
  statistics: UserStatistics | null = null;
  roles: Role[] = [];
  departments: Lookup[] = [];

  loading = false;
  working = false;
  error: string | null = null;
  notice: string | null = null;

  filters: UserFilters = { page: 1, perPage: 25, withPlacement: true };
  meta = { total: 0, page: 1, perPage: 25, totalPages: 0 };
  selectedIds: string[] = [];

  /** The person whose password is being set, if any. */
  passwordFor: User | null = null;
  newPassword = '';

  statCards: StatCard[] = [];

  ngOnInit(): void {
    this.search$
      .pipe(debounceTime(400), distinctUntilChanged(), takeUntil(this.destroy$))
      .subscribe(() => {
        this.filters.page = 1;
        this.loadUsers();
      });

    this.loadUsers();
    this.loadStatistics();

    this.rolesService
      .list()
      .pipe(takeUntil(this.destroy$))
      .subscribe({ next: (roles) => (this.roles = roles), error: () => undefined });

    // From the reference data, not from a statistics payload that happened to
    // group by department — the old screen took its department filter from
    // `stats.by_department`, so a department with nobody in it was missing.
    this.lookupsService
      .active('department')
      .pipe(takeUntil(this.destroy$))
      .subscribe({ next: (departments) => (this.departments = departments), error: () => undefined });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // --- loading ------------------------------------------------------------

  loadUsers(): void {
    this.loading = true;

    this.usersService
      .list(this.filters)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => {
          this.users = page.items;
          this.meta = page.meta;
          this.loading = false;
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.loading = false;
        },
      });
  }

  loadStatistics(): void {
    this.usersService
      .statistics()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (statistics) => {
          this.statistics = statistics;
          this.statCards = [
            { label: 'Total', value: statistics.total, icon: 'bi-people-fill', colour: 'text-primary', status: '' },
            { label: 'Active', value: statistics.active, icon: 'bi-check-circle-fill', colour: 'text-success', status: 'active' },
            { label: 'Invited', value: statistics.invited, icon: 'bi-envelope-fill', colour: 'text-info', status: 'invited' },
            { label: 'Suspended', value: statistics.suspended, icon: 'bi-pause-circle-fill', colour: 'text-warning', status: 'suspended' },
            { label: 'Locked', value: statistics.locked, icon: 'bi-lock-fill', colour: 'text-danger', status: 'locked' },
            { label: 'Active 30d', value: statistics.activeLast30, icon: 'bi-activity', colour: 'text-info', status: '' },
          ];
        },
        error: (error: ApiError) => (this.error = error.message),
      });
  }

  // --- narrowing ----------------------------------------------------------

  onSearchChange(term: string): void {
    this.search$.next(term);
  }

  filterByStatus(status: UserStatus | ''): void {
    this.filters.status = this.filters.status === status || status === '' ? undefined : status;
    this.filters.page = 1;
    this.loadUsers();
  }

  onFilterChange(): void {
    this.filters.page = 1;
    this.loadUsers();
  }

  resetFilters(): void {
    this.filters = { page: 1, perPage: 25, withPlacement: true };
    this.loadUsers();
  }

  goPage(page: number): void {
    this.filters.page = page;
    this.loadUsers();
  }

  // --- selection ----------------------------------------------------------

  get allSelected(): boolean {
    return this.users.length > 0 && this.selectedIds.length === this.users.length;
  }

  toggleSelectAll(event: Event): void {
    this.selectedIds = (event.target as HTMLInputElement).checked ? this.users.map((u) => u.id) : [];
  }

  toggleSelect(id: string): void {
    this.selectedIds = this.selectedIds.includes(id)
      ? this.selectedIds.filter((i) => i !== id)
      : [...this.selectedIds, id];
  }

  isSelected(id: string): boolean {
    return this.selectedIds.includes(id);
  }

  // --- acting on one person ------------------------------------------------

  setStatus(user: User, status: UserStatus): void {
    if (status === 'suspended' && !confirm(`Suspend ${user.fullName}?`)) {
      return;
    }

    this.run(this.usersService.setStatus(user.id, status), `${user.fullName} is now ${status}.`);
  }

  deleteUser(user: User): void {
    if (!confirm(`Delete ${user.fullName}? Their certificates and enrolments are kept.`)) {
      return;
    }

    this.run(this.usersService.delete(user.id), `${user.fullName} was removed.`);
  }

  openPasswordModal(user: User): void {
    this.passwordFor = user;
    this.newPassword = '';
  }

  /**
   * Sets a password an administrator has chosen.
   *
   * The legacy generated one server-side and displayed it. That put a live
   * credential into a page, a screenshot and very often a chat message —
   * whoever asks for the reset now types one and tells the person themselves.
   */
  savePassword(): void {
    const user = this.passwordFor;

    if (user === null || this.newPassword === '') {
      return;
    }

    this.working = true;
    this.error = null;

    this.usersService
      .setPassword(user.id, this.newPassword)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.working = false;
          this.passwordFor = null;
          this.newPassword = '';
          this.notice = `Password set for ${user.fullName}.`;
        },
        error: (error: ApiError) => {
          this.working = false;
          this.error = error.violation('password') ?? error.message;
        },
      });
  }

  // --- acting on several ---------------------------------------------------

  /**
   * Runs the same change over every selected person, together, and reports
   * how many of them actually took.
   */
  bulkStatus(status: UserStatus): void {
    if (status === 'suspended' && !confirm(`Suspend ${this.selectedIds.length} people?`)) {
      return;
    }

    this.bulk(this.selectedIds.map((id) => this.usersService.setStatus(id, status)), 'updated');
  }

  bulkDelete(): void {
    if (!confirm(`Delete ${this.selectedIds.length} people?`)) {
      return;
    }

    this.bulk(this.selectedIds.map((id) => this.usersService.delete(id)), 'removed');
  }

  /**
   * Replaces each selected person's roles with the one chosen.
   *
   * Wholesale, like the user form: the legacy's `assign_role` added a role and
   * left whatever else was there, so a bulk "make these people learners" left
   * the admins among them still admins.
   */
  bulkAssignRole(role: Role): void {
    if (!confirm(`Make ${this.selectedIds.length} people "${role.name}"? Their other roles are replaced.`)) {
      return;
    }

    this.bulk(
      this.selectedIds.map((id) => this.usersService.update(id, { roleIds: [role.id] })),
      'updated',
    );
  }

  private bulk(calls: Array<Observable<unknown>>, verb: string): void {
    // Each failure is caught into the result array rather than collapsing the
    // whole forkJoin, so one refusal does not hide four successes.
    const requests = calls.map((call) => call.pipe(catchError((error: ApiError) => of(error))));

    this.working = true;
    this.error = null;

    forkJoin(requests)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (results) => {
          const failed = results.filter((r) => r instanceof ApiError) as ApiError[];

          this.working = false;
          this.selectedIds = [];
          this.loadUsers();
          this.loadStatistics();

          if (failed.length === 0) {
            this.notice = `${results.length} ${results.length === 1 ? 'person' : 'people'} ${verb}.`;

            return;
          }

          // Named, not rounded off to "Bulk action failed": some of it worked.
          this.error = `${results.length - failed.length} ${verb}, ${failed.length} refused — ${failed[0].message}`;
        },
        error: (error: ApiError) => {
          this.working = false;
          this.error = error.message;
        },
      });
  }

  private run(call: Observable<unknown>, message: string): void {
    this.working = true;
    this.error = null;

    call.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.working = false;
        this.notice = message;
        this.loadUsers();
        this.loadStatistics();
      },
      error: (error: ApiError) => {
        this.working = false;
        this.error = error.message;
      },
    });
  }

  // --- presentation --------------------------------------------------------

  roleNames(user: User): string[] {
    return user.roleIds
      .map((id) => this.roles.find((r) => r.id === id)?.name)
      .filter((name): name is string => name !== undefined);
  }

  avatarColour(name: string): string {
    const colours = [
      '#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f',
      '#edc948', '#b07aa1', '#ff9da7', '#9c755f', '#bab0ac',
    ];

    let hash = 0;

    for (let i = 0; i < name.length; i++) {
      hash = name.charCodeAt(i) + ((hash << 5) - hash);
    }

    return colours[Math.abs(hash) % colours.length];
  }

  statusBadgeClass(status: UserStatus): string {
    const classes: Record<UserStatus, string> = {
      active: 'bg-success',
      invited: 'bg-info',
      suspended: 'bg-warning text-dark',
      locked: 'bg-danger',
    };

    return classes[status] ?? 'bg-secondary';
  }

  trackById(_index: number, item: { id: string }): string {
    return item.id;
  }
}
