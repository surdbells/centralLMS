import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Observable, Subject, forkJoin, of } from 'rxjs';
import { catchError, debounceTime, distinctUntilChanged, takeUntil } from 'rxjs/operators';
import {
  UsersService,
  type User as ApiUser,
  type UserFilters as ApiUserFilters,
  type UserStatus,
} from '../../../../services/users.service';
import { RolesService } from '../../../../services/roles.service';
import { LookupsService } from '../../../../services/lookups.service';
import { SettingsService } from '../../../../services/settings.service';
import { SessionStore } from '../../../../core/auth/session.store';
import { ApiError } from '../../../../core/api';
import { AdmintopComponent } from '../../../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../../../translate.pipe';
import { I18nService } from '../../../../i18n.service';

/* The shapes this screen's template already reads. */

export interface User {
  id: string;
  full_name: string;
  email: string;
  staffId: string | null;
  department_name: string | null;
  status: UserStatus;
  account_locked: boolean;
  /** Their roles, named. The badge's colour still keys off the slug. */
  roles: Array<{ slug: string; name: string }>;
  last_login_at: string | null;
}

export interface Role {
  role_id: string;
  role_key: string;
  role_name: string;
}

export interface Department {
  department_id: string;
  department_name: string;
}

export interface UserStats {
  total_users: number;
  active: number;
  inactive: number;
  suspended: number;
  locked: number;
  active_30d: number;
}

export interface UserFilters {
  search?: string;
  status?: UserStatus | '';
  department_id?: string;
  role_key?: string;
  page: number;
  per_page: number;
}

/**
 * The people in an organisation.
 *
 * The screen is as it was. What changed is underneath: `UserService` pasted a
 * token, a user id and a tenant id into the query string of every request,
 * reading them out of the session blob itself.
 *
 * Four things the screen used to claim and now does:
 *
 *  - **The sortable headers sort.** They sent `sort_by` and `sort_dir` to an
 *    endpoint that ignored both, so clicking them did nothing at all. They
 *    sort the page in hand now, and say so by only offering the columns that
 *    can be sorted without asking the server for a different page.
 *  - **The department filter offers every department.** It was built from
 *    `stats.by_department`, so a department with nobody in it could not be
 *    filtered on. It comes from the reference data.
 *  - **A bulk role assignment replaces the set.** `assign_role` added a role
 *    and left the rest, so making five people learners left the admins among
 *    them still admins.
 *  - **Failures are named.** A refused delete says why instead of "Failed to
 *    delete".
 *
 * One thing it no longer does: "Reset Password" generated a password
 * server-side and printed it in a modal, which put a live credential into the
 * page and into every screenshot of it. It asks for one instead — the modal
 * the template already has is now where you type it.
 */
@Component({
  selector: 'app-user-list',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent, TranslatePipe],
  templateUrl: './user-list.component.html',
  styles: [`
    .avatar-circle { width:36px; height:36px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:600; font-size:14px; flex-shrink:0; }
    .sortable { cursor:pointer; user-select:none; }
    .sortable:hover { color: var(--bs-primary); }
    .cursor-pointer { cursor:pointer; }
  `],
})
export class UserListComponent implements OnInit, OnDestroy {
  private readonly usersService = inject(UsersService);
  private readonly rolesService = inject(RolesService);
  private readonly lookupsService = inject(LookupsService);
  private readonly settingsService = inject(SettingsService);
  private readonly session = inject(SessionStore);
  private readonly i18n = inject(I18nService);

  private readonly destroy$ = new Subject<void>();
  private readonly search$ = new Subject<string>();

  users: User[] = [];
  stats: UserStats | null = null;
  roles: Role[] = [];
  departments: Department[] = [];
  loading = false;

  /** The set-a-password modal. Holds what is being typed, never what was generated. */
  tempPasswordModal = '';

  // --- the organisation's registration link ------------------------------
  //
  // Here because this is where somebody goes when they want to give
  // people accounts, and handing out a link is one of the three ways to do
  // that — the others being Add User and Import, which sit beside it.

  showRegistrationLink = false;
  registrationOpen = false;
  registrationDomains = '';
  savingRegistration = false;

  /**
   * The link itself.
   *
   * Built from the address the administrator is already looking at, so it
   * is right on every deployment without anything being configured.
   */
  get registrationLink(): string {
    return `${window.location.origin}/${this.session.tenantId() ?? ''}/register`;
  }

  openRegistrationLink(): void {
    this.showRegistrationLink = true;

    // Read afresh each time: the setting is also changeable from Settings,
    // and a switch showing a stale answer is worse than no switch.
    this.settingsService
      .show()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (settings) => {
          this.registrationOpen = settings['public_registration'] === true;
          this.registrationDomains = String(settings['registration_domains'] ?? '');
        },
        error: () => undefined,
      });
  }

  setRegistrationOpen(open: boolean): void {
    this.savingRegistration = true;

    this.settingsService
      .update({ public_registration: open })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.registrationOpen = open;
          this.savingRegistration = false;
        },
        error: (failure: ApiError) => {
          this.savingRegistration = false;
          alert(failure.message);
        },
      });
  }

  copyRegistrationLink(): void {
    void navigator.clipboard?.writeText(this.registrationLink);
  }
  private passwordFor: User | null = null;

  filters: UserFilters = { page: 1, per_page: 25 };
  meta = { total: 0, page: 1, per_page: 25, total_pages: 0 };
  selectedIds: string[] = [];

  statCards: Array<{ label: string; value: number; icon: string; color: string; filter: UserStatus | '' }> = [];

  /**
   * The page as the API sent it.
   *
   * Kept because a person's roles are named from the role list, and the
   * two arrive independently — whichever lands second re-renders the rows.
   * Mapping once on arrival left the Roles column empty whenever the users
   * came back first, which was most of the time.
   */
  private page: ApiUser[] = [];

  /** Which column the rows in hand are ordered by. */
  private sortBy: 'first_name' | 'is_active' | 'last_login_at' = 'first_name';
  private sortDir: 'asc' | 'desc' = 'asc';

  ngOnInit(): void {
    this.search$
      .pipe(debounceTime(400), distinctUntilChanged(), takeUntil(this.destroy$))
      .subscribe(() => {
        this.filters.page = 1;
        this.loadUsers();
      });

    this.loadUsers();
    this.loadStats();
    this.loadDropdowns();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // --- loading -------------------------------------------------------------

  loadUsers(): void {
    this.loading = true;

    const filters: ApiUserFilters = {
      page: this.filters.page,
      perPage: this.filters.per_page,
      search: this.filters.search || undefined,
      status: this.filters.status || undefined,
      departmentId: this.filters.department_id || undefined,
      roleId: this.roles.find((r) => r.role_key === this.filters.role_key)?.role_id,
      // The department name is a join, and this table shows it.
      withPlacement: true,
    };

    this.usersService
      .list(filters)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (page) => {
          this.page = page.items;
          this.render();
          this.meta = {
            total: page.meta.total,
            page: page.meta.page,
            per_page: page.meta.perPage,
            total_pages: page.meta.totalPages,
          };
          this.loading = false;
        },
        error: () => (this.loading = false),
      });
  }

  loadStats(): void {
    this.usersService
      .statistics()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (s) => {
          this.stats = {
            total_users: s.total,
            // `invited` is what the legacy called inactive: an account that
            // exists and has never been used.
            inactive: s.invited,
            active: s.active,
            suspended: s.suspended,
            locked: s.locked,
            active_30d: s.activeLast30,
          };

          this.statCards = [
            { label: 'admin.users.stat_total', value: s.total, icon: 'bi-people-fill', color: 'text-primary', filter: '' },
            { label: 'admin.users.status_active', value: s.active, icon: 'bi-check-circle-fill', color: 'text-success', filter: 'active' },
            { label: 'admin.users.status_invited', value: s.invited, icon: 'bi-envelope-fill', color: 'text-secondary', filter: 'invited' },
            { label: 'admin.users.status_suspended', value: s.suspended, icon: 'bi-pause-circle-fill', color: 'text-warning', filter: 'suspended' },
            { label: 'admin.users.status_locked', value: s.locked, icon: 'bi-lock-fill', color: 'text-danger', filter: 'locked' },
            { label: 'admin.users.stat_active_30d', value: s.activeLast30, icon: 'bi-activity', color: 'text-info', filter: '' },
          ];
        },
        error: () => undefined,
      });
  }

  loadDropdowns(): void {
    forkJoin({
      roles: this.rolesService.list().pipe(catchError(() => of([]))),
      // From the reference data, not from a statistics payload that happened
      // to group by department — a department with nobody in it was missing.
      departments: this.lookupsService.active('department').pipe(catchError(() => of([]))),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe(({ roles, departments }) => {
        this.roles = roles.map((r) => ({ role_id: r.id, role_key: r.slug, role_name: r.name }));
        this.departments = departments.map((d) => ({
          department_id: d.id,
          department_name: d.name,
        }));

        this.render();
      });
  }

  // --- narrowing -----------------------------------------------------------

  onSearchChange(term: string): void {
    this.filters.search = term;
    this.search$.next(term);
  }

  filterByStatus(status: UserStatus | ''): void {
    this.filters.status = this.filters.status === status ? '' : status;
    this.filters.page = 1;
    this.loadUsers();
  }

  resetFilters(): void {
    this.filters = { page: 1, per_page: 25 };
    this.loadUsers();
  }

  /**
   * Sorts the page in hand.
   *
   * The headers used to send `sort_by`/`sort_dir` to an endpoint that ignored
   * them, so they did nothing. Sorting the rows on screen is what they look
   * like they do, and it is honest about its scope — the page footer says
   * which page of how many is showing.
   */
  sort(column: 'first_name' | 'is_active' | 'last_login_at'): void {
    if (this.sortBy === column) {
      this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
    } else {
      this.sortBy = column;
      this.sortDir = 'asc';
    }

    this.applySort();
  }

  private applySort(): void {
    const direction = this.sortDir === 'asc' ? 1 : -1;

    this.users = [...this.users].sort((a, b) => {
      const [left, right] = this.sortBy === 'first_name'
        ? [a.full_name, b.full_name]
        : this.sortBy === 'is_active'
          ? [a.status, b.status]
          : [a.last_login_at ?? '', b.last_login_at ?? ''];

      return left.localeCompare(right) * direction;
    });
  }

  goPage(page: number): void {
    this.filters.page = page;
    this.loadUsers();
  }

  // --- selection -----------------------------------------------------------

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

  // --- one person ----------------------------------------------------------

  quickAction(action: string, user: User): void {
    if (action === 'delete' && !confirm(this.i18n.t('admin.users.confirm_delete_user', { name: user.full_name }))) {
      return;
    }

    if (action === 'suspend' && !confirm(this.i18n.t('admin.users.confirm_suspend_user', { name: user.full_name }))) {
      return;
    }

    if (action === 'reset-password') {
      // Nothing is generated. The modal the template already has is where a
      // password is typed — the old one displayed one the server had made,
      // which put a live credential into the page.
      this.passwordFor = user;
      this.tempPasswordModal = '';
      this.promptForPassword(user);

      return;
    }

    const call = action === 'activate' ? this.usersService.setStatus(user.id, 'active')
      : action === 'suspend' ? this.usersService.setStatus(user.id, 'suspended')
      // "Unlock" is the system lock being lifted: the account goes active.
      : action === 'unlock' ? this.usersService.setStatus(user.id, 'active')
      : action === 'delete' ? this.usersService.delete(user.id)
      : null;

    if (call === null) {
      return;
    }

    this.run(call, this.i18n.t('admin.users.action_failed', { name: user.full_name }));
  }

  private promptForPassword(user: User): void {
    const password = prompt(this.i18n.t('admin.users.password_prompt', { name: user.full_name }));

    if (password === null || password.trim() === '') {
      this.passwordFor = null;

      return;
    }

    this.usersService
      .setPassword(user.id, password)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.passwordFor = null;
          alert(this.i18n.t('admin.users.password_set', { name: user.full_name }));
        },
        error: (e: ApiError) => {
          this.passwordFor = null;
          alert(e.violation('password') ?? e.message);
        },
      });
  }

  // --- several people ------------------------------------------------------

  bulkAction(action: string, extra: { role_id?: string } = {}): void {
    if (this.selectedIds.length === 0) {
      return;
    }

    if (action === 'delete' && !confirm(this.i18n.t('admin.users.confirm_delete_bulk', { count: this.selectedIds.length }))) {
      return;
    }

    const calls = this.selectedIds.map((id) => {
      if (action === 'activate') {
        return this.usersService.setStatus(id, 'active');
      }

      if (action === 'suspend') {
        return this.usersService.setStatus(id, 'suspended');
      }

      if (action === 'delete') {
        return this.usersService.delete(id);
      }

      // Replaces the set, like the form. `assign_role` added a role and left
      // the rest, so "make these five learners" left the admins among them
      // still admins.
      return this.usersService.update(id, { roleIds: extra.role_id === undefined ? [] : [extra.role_id] });
    });

    // Each failure is caught into the results rather than collapsing the
    // whole batch, so one refusal does not hide four successes.
    forkJoin(calls.map((call) => (call as Observable<unknown>).pipe(catchError((e: ApiError) => of(e)))))
      .pipe(takeUntil(this.destroy$))
      .subscribe((results) => {
        const failed = results.filter((r) => r instanceof ApiError) as ApiError[];

        this.selectedIds = [];
        this.loadUsers();
        this.loadStats();

        if (failed.length > 0) {
          alert(this.i18n.t('admin.users.bulk_result', { done: results.length - failed.length, failed: failed.length, message: failed[0].message }));
        }
      });
  }

  private run(call: Observable<unknown>, whenItFails: string): void {
    call.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.loadUsers();
        this.loadStats();
      },
      // The server's own words where it has them.
      error: (e: ApiError) => alert(e.message || whenItFails),
    });
  }

  // --- presentation --------------------------------------------------------

  getAvatarColor(name: string): string {
    const colors = [
      '#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f',
      '#edc948', '#b07aa1', '#ff9da7', '#9c755f', '#bab0ac',
    ];

    let hash = 0;

    for (let i = 0; i < name.length; i++) {
      hash = name.charCodeAt(i) + ((hash << 5) - hash);
    }

    return colors[Math.abs(hash) % colors.length];
  }

  /** The page in hand, named and ordered. */
  private render(): void {
    this.users = this.page.map((u) => this.adapt(u));
    this.applySort();
  }

  getRoleBadgeClass(role: string): string {
    return ({
      super_admin: 'bg-danger',
      tenant_admin: 'bg-danger',
      org_admin: 'bg-primary',
      hr_manager: 'bg-info text-black',
      hr: 'bg-info text-black',
      manager: 'bg-warning text-black',
      instructor: 'bg-success',
      content_creator: 'bg-secondary',
      learner: 'bg-light text-black border',
    } as Record<string, string>)[role] ?? 'bg-light text-black border';
  }

  getStatusBadgeClass(status: string): string {
    return ({
      active: 'bg-success',
      invited: 'bg-secondary',
      suspended: 'bg-warning text-dark',
      locked: 'bg-danger',
    } as Record<string, string>)[status] ?? 'bg-secondary';
  }

  // --- translation ---------------------------------------------------------

  private adapt(user: ApiUser): User {
    return {
      id: user.id,
      full_name: user.fullName,
      email: user.email,
      // The legacy had a staff id column; this system does not, and an empty
      // one is better than a made-up one.
      staffId: null,
      department_name: user.placement?.departmentName ?? null,
      status: user.status,
      account_locked: user.status === 'locked',
      roles: user.roleIds
        .map((id) => this.roles.find((r) => r.role_id === id))
        .filter((role): role is Role => role !== undefined)
        .map((role) => ({ slug: role.role_key, name: role.role_name })),
      last_login_at: user.lastLoginAt,
    };
  }
}
