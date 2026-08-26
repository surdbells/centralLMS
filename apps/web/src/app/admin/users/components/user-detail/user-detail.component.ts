import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { Subject, forkJoin } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { UsersService, type User, type UserStatus } from '../../../../services/users.service';
import { RolesService, type Role } from '../../../../services/roles.service';
import { type LearnerSkills } from '../../../../services/skills.service';
import { ApiError } from '../../../../core/api';
import { AdmintopComponent } from '../../../../partials/top/admintop/admintop.component';

type Tab = 'profile' | 'roles' | 'learning';

interface Field {
  label: string;
  value: string;
}

/**
 * One person: who they are, what they may do, and what they have learnt.
 *
 * Roles are edited on the form, not here. This screen used to assign and
 * remove them one at a time through `assign-role` / `remove-role` while the
 * form wrote the whole set, and the two disagreed about whether somebody could
 * be left with none — so the answer depended on which screen you happened to
 * use. There is one way now, and this links to it.
 *
 * The effective permissions are the union of the person's roles' permissions,
 * worked out from the role list this page already has. It was a field on a
 * `user-get` payload that recomputed the same union server-side.
 */
@Component({
  selector: 'app-user-detail',
  standalone: true,
  imports: [CommonModule, RouterLink, AdmintopComponent],
  templateUrl: './user-detail.component.html',
  styles: [`
    .avatar-lg { width:56px; height:56px; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:700; font-size:24px; flex-shrink:0; }
    .nav-link { cursor:pointer; }
  `],
})
export class UserDetailComponent implements OnInit, OnDestroy {
  private readonly usersService = inject(UsersService);
  private readonly rolesService = inject(RolesService);
  private readonly route = inject(ActivatedRoute);
  private readonly destroy$ = new Subject<void>();

  user: User | null = null;
  roles: Role[] = [];
  skills: LearnerSkills | null = null;

  loading = true;
  working = false;
  error: string | null = null;

  activeTab: Tab = 'profile';

  readonly tabs: Array<{ key: Tab; label: string; icon: string }> = [
    { key: 'profile', label: 'Profile', icon: 'bi-person' },
    { key: 'roles', label: 'Roles & Permissions', icon: 'bi-shield-lock' },
    { key: 'learning', label: 'Learning', icon: 'bi-mortarboard' },
  ];

  personalFields: Field[] = [];
  orgFields: Field[] = [];
  accountFields: Field[] = [];

  ngOnInit(): void {
    const id = this.route.snapshot.paramMap.get('id');

    if (id === null) {
      this.error = 'No user was named in the address.';
      this.loading = false;

      return;
    }

    this.load(id);
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  private load(id: string): void {
    this.loading = true;

    // `withActivity` costs a join, so it is asked for here and nowhere else.
    forkJoin({
      user: this.usersService.show(id),
      roles: this.rolesService.list(),
      skills: this.usersService.skills(id),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ user, roles, skills }) => {
          this.user = user;
          this.roles = roles;
          this.skills = skills;
          this.buildFields(user);
          this.loading = false;
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.loading = false;
        },
      });
  }

  private buildFields(user: User): void {
    this.personalFields = [
      { label: 'Full Name', value: user.fullName },
      { label: 'Email', value: user.email },
      { label: 'Phone', value: user.phone ?? '' },
      { label: 'Language', value: user.locale },
    ];

    this.orgFields = [
      { label: 'Branch', value: user.placement?.branchName ?? '' },
      { label: 'Department', value: user.placement?.departmentName ?? '' },
      { label: 'Team', value: user.placement?.teamName ?? '' },
      { label: 'Level', value: user.placement?.levelName ?? '' },
      { label: 'Job Title', value: user.placement?.jobTitleName ?? '' },
      { label: 'Manager', value: user.placement?.managerName ?? '' },
      { label: 'Hire Date', value: user.hireDate ?? '' },
    ];

    this.accountFields = [
      { label: 'Status', value: user.status },
      { label: 'Two-factor', value: user.twoFactor ? 'On' : 'Off' },
      { label: 'Last Signed In', value: user.lastLoginAt ?? 'Never' },
      { label: 'Sign-ins', value: String(user.loginCount) },
      { label: 'Created', value: user.createdAt ?? '' },
    ];
  }

  // --- what they may do ---------------------------------------------------

  get assignedRoles(): Role[] {
    const ids = new Set(this.user?.roleIds ?? []);

    return this.roles.filter((role) => ids.has(role.id));
  }

  /**
   * The union of what their roles allow, sorted so a person can find one.
   */
  get effectivePermissions(): string[] {
    const permissions = new Set<string>();

    for (const role of this.assignedRoles) {
      for (const permission of role.permissions) {
        permissions.add(permission);
      }
    }

    return [...permissions].sort();
  }

  // --- acting -------------------------------------------------------------

  toggleStatus(): void {
    const user = this.user;

    if (user === null) {
      return;
    }

    const next: UserStatus = user.status === 'active' ? 'suspended' : 'active';

    if (next === 'suspended' && !confirm(`Suspend ${user.fullName}?`)) {
      return;
    }

    this.working = true;
    this.error = null;

    this.usersService
      .setStatus(user.id, next)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (updated) => {
          this.working = false;
          this.user = updated;
          this.buildFields(updated);
        },
        error: (error: ApiError) => {
          this.working = false;
          this.error = error.message;
        },
      });
  }

  // --- presentation -------------------------------------------------------

  get statusClass(): string {
    const classes: Record<UserStatus, string> = {
      active: 'bg-success',
      invited: 'bg-info',
      suspended: 'bg-warning text-dark',
      locked: 'bg-danger',
    };

    return this.user === null ? 'bg-secondary' : classes[this.user.status] ?? 'bg-secondary';
  }

  avatarColour(name: string): string {
    const colours = ['#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f'];

    let hash = 0;

    for (let i = 0; i < name.length; i++) {
      hash = name.charCodeAt(i) + ((hash << 5) - hash);
    }

    return colours[Math.abs(hash) % colours.length];
  }

  /** Seconds, as something a person reads. */
  hours(seconds: number): string {
    if (seconds < 60) {
      return `${seconds}s`;
    }

    const minutes = Math.round(seconds / 60);

    return minutes < 60 ? `${minutes} min` : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
  }

  trackById(_index: number, item: { id: string }): string {
    return item.id;
  }
}
