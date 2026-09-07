import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Subject, forkJoin } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { UsersService, type UserInput } from '../../../../services/users.service';
import { RolesService } from '../../../../services/roles.service';
import { LookupsService } from '../../../../services/lookups.service';
import { ApiError } from '../../../../core/api';
import { AdmintopComponent } from '../../../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../../../translate.pipe';

/* The shapes this screen's template already reads. */

export interface Role {
  role_id: string;
  role_name: string;
  description: string | null;
}

export interface OrgUnit {
  branch_id?: string;
  branch_name?: string;
  department_id?: string;
  department_name?: string;
  team_id?: string;
  team_name?: string;
}

export interface UserFormData {
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  staffId: string;
  branch_id: string | null;
  department_id: string | null;
  team_id: string | null;
  hire_date: string;
  language: string;
  preferred_learning_mode: string;
  password: string;
}

@Component({
  selector: 'app-user-form',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent, TranslatePipe],
  template: `
    <app-admintop></app-admintop>
    <main class="main px-lg-6">
      <div class="container-fluid">
    <div class="user-form">
      <div class="d-flex align-items-center gap-3 mb-4">
        <a routerLink="/admin/users" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i></a>
        <h4 class="fw-bold mb-0"><i class="bi me-2" [class]="isEdit ? 'bi-pencil' : 'bi-person-plus'"></i>{{ isEdit ? ('admin.user_form.edit_title' | translate) : ('admin.user_form.create_title' | translate) }}</h4>
      </div>

      <div *ngIf="loading" class="text-center py-5"><div class="spinner-border text-primary"></div></div>

      <div class="alert alert-danger mt-3" *ngIf="error"><i class="bi bi-exclamation-triangle me-2"></i>{{ error }}</div>

      <form *ngIf="!loading" (ngSubmit)="onSubmit()" class="row g-4">
        <!-- Personal Info -->
        <div class="col-md-6">
          <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person me-2"></i>{{ 'admin.user_form.personal_info' | translate }}</div>
            <div class="card-body">
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.user_form.first_name' | translate }} <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-sm" [(ngModel)]="form.first_name" name="first_name" required>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.user_form.last_name' | translate }}</label>
                <input type="text" class="form-control form-control-sm" [(ngModel)]="form.last_name" name="last_name">
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.users.col_email' | translate }} <span class="text-danger">*</span></label>
                <input type="email" class="form-control form-control-sm" [(ngModel)]="form.email" name="email" required>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.user_form.phone' | translate }}</label>
                <input type="text" class="form-control form-control-sm" [(ngModel)]="form.phone" name="phone">
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.user_form.staff_id' | translate }}</label>
                <input type="text" class="form-control form-control-sm" [(ngModel)]="form.staffId" name="staffId">
              </div>
              <div class="row g-2">
                <div class="col-6 mb-3">
                  <label class="form-label small fw-semibold">{{ 'common.language' | translate }}</label>
                  <select class="form-select form-select-sm" [(ngModel)]="form.language" name="language">
                    <option value="en">{{ 'admin.user_form.lang_en' | translate }}</option><option value="fr">{{ 'admin.user_form.lang_fr' | translate }}</option><option value="ar">{{ 'admin.user_form.lang_ar' | translate }}</option>
                  </select>
                </div>
                <div class="col-6 mb-3">
                  <label class="form-label small fw-semibold">{{ 'admin.user_form.learning_mode' | translate }}</label>
                  <select class="form-select form-select-sm" [(ngModel)]="form.preferred_learning_mode" name="learning_mode">
                    <option value="video">{{ 'admin.user_form.mode_video' | translate }}</option><option value="audio">{{ 'admin.user_form.mode_audio' | translate }}</option>
                    <option value="reading">{{ 'admin.user_form.mode_reading' | translate }}</option><option value="interactive">{{ 'admin.user_form.mode_interactive' | translate }}</option>
                  </select>
                </div>
              </div>
              <div class="mb-3" *ngIf="!isEdit">
                <label class="form-label small fw-semibold">{{ 'admin.user_form.temp_password' | translate }}</label>
                <input type="text" class="form-control form-control-sm" [(ngModel)]="form.password" name="password"
                       placeholder="{{ 'admin.user_form.temp_password_placeholder' | translate }}">
              </div>
            </div>
          </div>
        </div>

        <!-- Org Info -->
        <div class="col-md-6">
          <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-building me-2"></i>{{ 'admin.user_form.organization' | translate }}</div>
            <div class="card-body">
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.enrollees.col_department' | translate }}</label>
                <select class="form-select form-select-sm" [(ngModel)]="form.department_id" name="department_id">
                  <option *ngFor="let d of departments" [ngValue]="d.department_id">{{ d.department_name }}</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.user_form.branch' | translate }}</label>
                <select class="form-select form-select-sm" [(ngModel)]="form.branch_id" name="branch_id">
                  <option *ngFor="let b of branches" [ngValue]="b.branch_id">{{ b.branch_name }}</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.user_form.team' | translate }}</label>
                <select class="form-select form-select-sm" [(ngModel)]="form.team_id" name="team_id">
                  <option *ngFor="let t of teams" [ngValue]="t.team_id">{{ t.team_name }}</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.user_form.hire_date' | translate }}</label>
                <input type="date" class="form-control form-control-sm" [(ngModel)]="form.hire_date" name="hire_date">
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.users.roles' | translate }}</label>
                <div class="border rounded p-2" style="max-height:180px; overflow-y:auto">
                  <div *ngFor="let r of roles" class="form-check">
                    <input type="checkbox" class="form-check-input" [id]="'role_'+r.role_id"
                           [checked]="selectedRoleIds.has(r.role_id)" (change)="toggleRole(r.role_id)">
                    <label class="form-check-label small" [for]="'role_'+r.role_id">{{ r.role_name }}
                      <span class="text-muted" *ngIf="r.description"> - {{ r.description }}</span>
                    </label>
                  </div>
                </div>
              </div>
              <div class="form-check mb-3">
                <input type="checkbox" class="form-check-input" id="isActive" [(ngModel)]="isActive" name="is_active">
                <label class="form-check-label small" for="isActive">{{ 'admin.user_form.active_account' | translate }}</label>
              </div>
            </div>
          </div>

          <!-- Submit -->
          <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary" [disabled]="saving || !form.first_name || !form.email">
              <span *ngIf="saving" class="spinner-border spinner-border-sm me-1"></span>
              <i *ngIf="!saving" class="bi me-1" [class]="isEdit ? 'bi-check-lg' : 'bi-plus-lg'"></i>
              {{ isEdit ? ('admin.user_form.save_changes' | translate) : ('admin.user_form.create_title' | translate) }}
            </button>
            <a routerLink="/admin/users" class="btn btn-secondary">{{ 'common.cancel' | translate }}</a>
          </div>

          <!-- Temp password result -->
          <div class="alert alert-success mt-3" *ngIf="createdPassword">
            <strong>{{ 'admin.user_form.user_created' | translate }}</strong> {{ 'admin.user_form.temp_password_result' | translate }} <code class="user-select-all">{{ createdPassword }}</code>
          </div>
        </div>
      </form>

    </div>
      </div>
    </main>
  `
})
/**
 * Creating and editing one person.
 *
 * The screen is unchanged. Two things behind it are:
 *
 *  - **No password is generated.** The old form offered "leave blank to
 *    auto-generate" and then printed what the server had made, which put a
 *    live credential on the page and into every screenshot of it. Blank now
 *    means no password: the account stays `invited` and its holder sets their
 *    own from the invitation. If one is typed, it is used.
 *  - **A role from another organisation is refused**, rather than written and
 *    later ignored.
 *
 * `preferred_learning_mode` and `staffId` are on the form and are not stored:
 * neither exists in this system. They are left in place rather than silently
 * dropped from the markup, and the component says so here rather than
 * pretending to save them.
 */
export class UserFormComponent implements OnInit, OnDestroy {
  private readonly usersService = inject(UsersService);
  private readonly rolesService = inject(RolesService);
  private readonly lookupsService = inject(LookupsService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly destroy$ = new Subject<void>();

  isEdit = false;
  userId: string | null = null;

  loading = true;
  saving = false;
  error = '';

  /**
   * Always empty.
   *
   * The template still has the box that showed a generated password; nothing
   * writes to it any more, so it never appears.
   */
  createdPassword = '';

  form: UserFormData = {
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    staffId: '',
    branch_id: null,
    department_id: null,
    team_id: null,
    hire_date: '',
    language: 'en',
    preferred_learning_mode: '',
    password: '',
  };

  isActive = true;

  roles: Role[] = [];
  branches: OrgUnit[] = [];
  departments: OrgUnit[] = [];
  teams: OrgUnit[] = [];

  selectedRoleIds = new Set<string>();

  ngOnInit(): void {
    this.userId = this.route.snapshot.paramMap.get('id');
    this.isEdit = this.userId !== null;

    forkJoin({
      roles: this.rolesService.list(),
      branches: this.lookupsService.active('branch'),
      departments: this.lookupsService.active('department'),
      teams: this.lookupsService.active('team'),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (reference) => {
          this.roles = reference.roles.map((r) => ({
            role_id: r.id,
            role_name: r.name,
            description: r.description,
          }));
          this.branches = reference.branches.map((b) => ({ branch_id: b.id, branch_name: b.name }));
          this.departments = reference.departments.map((d) => ({
            department_id: d.id,
            department_name: d.name,
          }));
          this.teams = reference.teams.map((t) => ({ team_id: t.id, team_name: t.name }));

          if (this.userId === null) {
            this.loading = false;

            return;
          }

          this.loadUser(this.userId);
        },
        error: (e: ApiError) => {
          this.error = e.message;
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
            first_name: user.firstName,
            last_name: user.lastName,
            email: user.email,
            phone: user.phone ?? '',
            staffId: '',
            branch_id: user.branchId,
            department_id: user.departmentId,
            team_id: user.teamId,
            hire_date: user.hireDate ?? '',
            language: user.locale,
            preferred_learning_mode: '',
            password: '',
          };

          this.isActive = user.status === 'active';
          this.selectedRoleIds = new Set(user.roleIds);
          this.loading = false;
        },
        error: (e: ApiError) => {
          this.error = e.message;
          this.loading = false;
        },
      });
  }

  toggleRole(roleId: string): void {
    this.selectedRoleIds.has(roleId)
      ? this.selectedRoleIds.delete(roleId)
      : this.selectedRoleIds.add(roleId);
  }

  onSubmit(): void {
    this.saving = true;
    this.error = '';

    const input: UserInput = {
      email: this.form.email.trim(),
      firstName: this.form.first_name.trim(),
      lastName: this.form.last_name.trim(),
      phone: this.form.phone || null,
      locale: this.form.language || 'en',
      branchId: this.form.branch_id,
      departmentId: this.form.department_id,
      teamId: this.form.team_id,
      hireDate: this.form.hire_date || null,
      roleIds: [...this.selectedRoleIds],
      // The tick is "can they sign in?". Unticking it suspends the account;
      // a new one with no password stays `invited` until they set one.
      status: this.isActive ? 'active' : 'suspended',
    };

    if (this.form.password.trim() !== '') {
      input.password = this.form.password;
    } else if (!this.isEdit) {
      // No password at all, rather than one this screen invented.
      delete input.password;
      input.status = 'invited';
    }

    const saved = this.isEdit && this.userId !== null
      ? this.usersService.update(this.userId, input)
      : this.usersService.create(input);

    saved.pipe(takeUntil(this.destroy$)).subscribe({
      next: (user) => {
        this.saving = false;
        void this.router.navigate(['/admin/users', user.id]);
      },
      error: (e: ApiError) => {
        this.saving = false;
        // Field by field where the server said which; the first one is the
        // one to fix.
        this.error = Object.values(e.violations).flat()[0] ?? e.message;
      },
    });
  }
}
