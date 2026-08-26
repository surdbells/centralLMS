import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Subject, forkJoin } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  UsersService,
  type UserInput,
  type UserStatus,
} from '../../../../services/users.service';
import { RolesService, type Role } from '../../../../services/roles.service';
import { LookupsService, type Lookup } from '../../../../services/lookups.service';
import { ApiError } from '../../../../core/api';
import { AdmintopComponent } from '../../../../partials/top/admintop/admintop.component';

/**
 * Creating and editing one person.
 *
 * The account is created with a password whoever is filling this in has
 * chosen, or with none at all — in which case the person is `invited` and sets
 * their own. The legacy generated a temporary password and printed it on the
 * page, so a live credential ended up in screenshots and chat messages.
 *
 * `status` is a field here rather than an "Active account" tick, because there
 * are four of them and a checkbox cannot say which of `invited`, `suspended`
 * or `locked` an unticked box means.
 */
@Component({
  selector: 'app-user-form',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent],
  templateUrl: './user-form.component.html',
})
export class UserFormComponent implements OnInit, OnDestroy {
  private readonly usersService = inject(UsersService);
  private readonly rolesService = inject(RolesService);
  private readonly lookupsService = inject(LookupsService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly destroy$ = new Subject<void>();

  userId: string | null = null;
  isEdit = false;

  form: UserInput = {
    email: '',
    firstName: '',
    lastName: '',
    password: '',
    status: 'invited',
    phone: '',
    locale: 'en',
    branchId: null,
    departmentId: null,
    teamId: null,
    levelId: null,
    jobTitleId: null,
    managerId: null,
    hireDate: null,
    roleIds: [],
  };

  roles: Role[] = [];
  branches: Lookup[] = [];
  departments: Lookup[] = [];
  teams: Lookup[] = [];
  levels: Lookup[] = [];
  jobTitles: Lookup[] = [];

  selectedRoleIds = new Set<string>();

  loading = true;
  saving = false;
  error: string | null = null;
  /** Field-by-field complaints from the server, bound beside each input. */
  violations: Record<string, string> = {};

  readonly statuses: Array<{ value: UserStatus; label: string; hint: string }> = [
    { value: 'invited', label: 'Invited', hint: 'Cannot sign in until they set a password.' },
    { value: 'active', label: 'Active', hint: 'Can sign in.' },
    { value: 'suspended', label: 'Suspended', hint: 'Switched off by an administrator.' },
    { value: 'locked', label: 'Locked', hint: 'Locked by the system after failed sign-ins.' },
  ];

  ngOnInit(): void {
    this.userId = this.route.snapshot.paramMap.get('id');
    this.isEdit = this.userId !== null;

    forkJoin({
      roles: this.rolesService.list(),
      branches: this.lookupsService.active('branch'),
      departments: this.lookupsService.active('department'),
      teams: this.lookupsService.active('team'),
      levels: this.lookupsService.active('level'),
      jobTitles: this.lookupsService.active('job_title'),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (reference) => {
          this.roles = reference.roles;
          this.branches = reference.branches;
          this.departments = reference.departments;
          this.teams = reference.teams;
          this.levels = reference.levels;
          this.jobTitles = reference.jobTitles;

          if (this.userId === null) {
            this.loading = false;

            return;
          }

          this.loadUser(this.userId);
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.loading = false;
        },
      });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  private loadUser(id: string): void {
    this.usersService
      .show(id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (user) => {
          this.form = {
            email: user.email,
            firstName: user.firstName,
            lastName: user.lastName,
            status: user.status,
            phone: user.phone,
            locale: user.locale,
            branchId: user.branchId,
            departmentId: user.departmentId,
            teamId: user.teamId,
            levelId: user.levelId,
            jobTitleId: user.jobTitleId,
            managerId: user.managerId,
            hireDate: user.hireDate,
            roleIds: user.roleIds,
          };
          this.selectedRoleIds = new Set(user.roleIds);
          this.loading = false;
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.loading = false;
        },
      });
  }

  // --- roles --------------------------------------------------------------

  toggleRole(id: string): void {
    this.selectedRoleIds.has(id) ? this.selectedRoleIds.delete(id) : this.selectedRoleIds.add(id);
  }

  // --- saving -------------------------------------------------------------

  onSubmit(): void {
    this.saving = true;
    this.error = null;
    this.violations = {};

    const input: UserInput = { ...this.form, roleIds: [...this.selectedRoleIds] };

    // An empty password on a new account is not a blank password: it is no
    // password, and the person sets their own from the invitation.
    if (input.password === '') {
      delete input.password;
    }

    const saved = this.isEdit && this.userId !== null
      ? this.usersService.update(this.userId, input)
      : this.usersService.create(input);

    saved.pipe(takeUntil(this.destroy$)).subscribe({
      next: (user) => {
        this.saving = false;
        void this.router.navigate(['/admin/users', user.id]);
      },
      error: (error: ApiError) => {
        this.saving = false;
        this.violations = Object.fromEntries(
          Object.entries(error.violations).map(([field, messages]) => [field, messages[0]]),
        );
        // Only shown when the failure is not about a particular field, so a
        // form does not carry the same complaint twice.
        this.error = Object.keys(this.violations).length === 0 ? error.message : null;
      },
    });
  }

  trackById(_index: number, item: { id: string }): string {
    return item.id;
  }
}
