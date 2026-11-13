import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Subject, forkJoin } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { UsersService, type UserInput } from '../../../../services/users.service';
import { RolesService } from '../../../../services/roles.service';
import { LookupsService } from '../../../../services/lookups.service';
import { CustomFieldsService, type CustomFieldDefinition } from '../../../../services/custom-fields.service';
import { ApiError } from '../../../../core/api';
import { SessionStore } from '../../../../core/auth/session.store';
import { CompetencyService, type JobCompetencyProfile } from '../../../../competency/competency.service';
import { I18nService } from '../../../../i18n.service';
import { HotToastService } from '@ngneat/hot-toast';
import { AdmintopComponent } from '../../../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../../../translate.pipe';
import { SkeletonComponent } from '../../../../shared/skeleton/skeleton.component';
import { SelectComponent } from '../../../../shared/select/select.component';

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
  gender: string;
  /** The organisation's own staff number — stored as `employeeId`. */
  staffId: string;
  branch_id: string | null;
  department_id: string | null;
  team_id: string | null;
  job_title_id: string | null;
  level_id: string | null;
  manager_id: string | null;
  line_executive_id: string | null;
  hire_date: string;
  language: string;
  preferred_learning_mode: string;
  password: string;
}

@Component({
  selector: 'app-user-form',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent, TranslatePipe, SkeletonComponent, SelectComponent],
  template: `
    <app-admintop></app-admintop>
    <main class="main">
      <div class="ui-page user-form">
      <header class="ui-page-header">
        <a routerLink="/admin/users" class="ui-page-header__back" [attr.aria-label]="'common.back' | translate"><i class="bi bi-chevron-left"></i></a>
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi" [ngClass]="isEdit ? 'bi-pencil' : 'bi-person-plus'"></i></div>
        <div class="ui-page-header__titles">
          <h1>{{ isEdit ? ('admin.user_form.edit_title' | translate) : ('admin.user_form.create_title' | translate) }}</h1>
        </div>
      </header>

      <section *ngIf="loading" class="ui-section"><app-skeleton variant="form" [rows]="6"></app-skeleton></section>

      <div class="alert alert-danger" *ngIf="error"><i class="bi bi-exclamation-triangle me-2"></i>{{ error }}</div>

      <form *ngIf="!loading" (ngSubmit)="onSubmit()" class="ui-row">
        <!-- Personal Info -->
        <div class="section-stack">
          <section class="ui-section">
            <div class="ui-section__head">
              <h2 class="ui-section__title"><i class="bi bi-person me-2"></i>{{ 'admin.user_form.personal_info' | translate }}</h2>
            </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.user_form.first_name' | translate }} <span class="text-danger">*</span></label>
                <input type="text" class="form-control" [(ngModel)]="form.first_name" name="first_name" required>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.user_form.last_name' | translate }}</label>
                <input type="text" class="form-control" [(ngModel)]="form.last_name" name="last_name">
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.users.col_email' | translate }} <span class="text-danger">*</span></label>
                <input type="email" class="form-control" [(ngModel)]="form.email" name="email" required>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.user_form.phone' | translate }}</label>
                <input type="text" class="form-control" [(ngModel)]="form.phone" name="phone">
              </div>
              <div class="row g-2">
                <div class="col-6 mb-3">
                  <label class="form-label small fw-semibold">{{ 'admin.user_form.gender' | translate }}</label>
                  <app-select [(ngModel)]="form.gender" name="gender" [options]="genderOptions"
                              [translateLabels]="true" [searchable]="false"
                              placeholder="{{ 'admin.user_form.gender_unspecified' | translate }}" />
                </div>
                <div class="col-6 mb-3">
                  <label class="form-label small fw-semibold">{{ 'admin.user_form.employee_id' | translate }}</label>
                  <input type="text" class="form-control" [(ngModel)]="form.staffId" name="staffId">
                </div>
              </div>
              <div class="row g-2">
                <div class="col-6 mb-3">
                  <label class="form-label small fw-semibold">{{ 'common.language' | translate }}</label>
                  <app-select [(ngModel)]="form.language" name="language" [options]="languageOptions"
                              [translateLabels]="true" [searchable]="false" />
                </div>
                <div class="col-6 mb-3">
                  <label class="form-label small fw-semibold">{{ 'admin.user_form.learning_mode' | translate }}</label>
                  <app-select [(ngModel)]="form.preferred_learning_mode" name="learning_mode" [options]="learningModeOptions"
                              [translateLabels]="true" [searchable]="false" />
                </div>
              </div>
              <div class="mb-0" *ngIf="!isEdit">
                <label class="form-label small fw-semibold">{{ 'admin.user_form.temp_password' | translate }}</label>
                <input type="text" class="form-control" [(ngModel)]="form.password" name="password"
                       placeholder="{{ 'admin.user_form.temp_password_placeholder' | translate }}">
              </div>
          </section>
        </div>

        <!-- Org Info -->
        <div class="section-stack">
          <section class="ui-section">
            <div class="ui-section__head">
              <h2 class="ui-section__title"><i class="bi bi-building me-2"></i>{{ 'admin.user_form.organization' | translate }}</h2>
            </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.enrollees.col_department' | translate }}</label>
                <app-select [(ngModel)]="form.department_id" name="department_id" [options]="departments"
                            bindLabel="department_name" bindValue="department_id" />
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.user_form.branch' | translate }}</label>
                <app-select [(ngModel)]="form.branch_id" name="branch_id" [options]="branches"
                            bindLabel="branch_name" bindValue="branch_id" />
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.user_form.team' | translate }}</label>
                <app-select [(ngModel)]="form.team_id" name="team_id" [options]="teams"
                            bindLabel="team_name" bindValue="team_id" />
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.user_detail.job_title' | translate }}</label>
                <app-select [(ngModel)]="form.job_title_id" name="job_title_id" [options]="jobTitles"
                            bindLabel="name" bindValue="id" [clearable]="true" placeholder="—" />
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.user_form.job_grade' | translate }}</label>
                <app-select [(ngModel)]="form.level_id" name="level_id" [options]="levels"
                            bindLabel="name" bindValue="id" [clearable]="true" placeholder="—" />
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.user_detail.manager' | translate }}</label>
                <app-select [(ngModel)]="form.manager_id" name="manager_id" [options]="people"
                            bindLabel="fullName" bindValue="id" [clearable]="true" placeholder="—" />
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.user_form.line_executive' | translate }}</label>
                <app-select [(ngModel)]="form.line_executive_id" name="line_executive_id" [options]="people"
                            bindLabel="fullName" bindValue="id" [clearable]="true" placeholder="—" />
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.user_form.hire_date' | translate }}</label>
                <input type="date" class="form-control" [(ngModel)]="form.hire_date" name="hire_date">
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.users.roles' | translate }}</label>
                <div class="role-box">
                  <div *ngFor="let r of roles" class="form-check">
                    <input type="checkbox" class="form-check-input" [id]="'role_'+r.role_id"
                           [checked]="selectedRoleIds.has(r.role_id)" (change)="toggleRole(r.role_id)">
                    <label class="form-check-label small" [for]="'role_'+r.role_id">{{ r.role_name }}
                      <span class="ui-muted" *ngIf="r.description"> - {{ r.description }}</span>
                    </label>
                  </div>
                </div>
              </div>
              <div class="form-check mb-0">
                <input type="checkbox" class="form-check-input" id="isActive" [(ngModel)]="isActive" name="is_active">
                <label class="form-check-label small" for="isActive">{{ 'admin.user_form.active_account' | translate }}</label>
              </div>
          </section>

          <!-- Custom fields — whatever this tenant records beyond the fixed profile -->
          <section class="ui-section" *ngIf="customFields.length">
            <div class="ui-section__head">
              <h2 class="ui-section__title"><i class="bi bi-sliders me-2"></i>{{ 'admin.user_form.additional_info' | translate }}</h2>
            </div>
              <div class="mb-3 cf-field" *ngFor="let f of customFields">
                <label class="form-label small fw-semibold">{{ f.label }}<span class="text-danger" *ngIf="f.required"> *</span></label>
                <ng-container [ngSwitch]="f.type">
                  <app-select *ngSwitchCase="'dropdown'" [options]="f.options" [clearable]="true" placeholder="—"
                              [(ngModel)]="customFieldValues[f.key]" [name]="'cf_' + f.key" />
                  <input *ngSwitchCase="'number'" type="number" class="form-control"
                         [(ngModel)]="customFieldValues[f.key]" [name]="'cf_' + f.key">
                  <input *ngSwitchCase="'date'" type="date" class="form-control"
                         [(ngModel)]="customFieldValues[f.key]" [name]="'cf_' + f.key">
                  <div *ngSwitchCase="'boolean'" class="form-check">
                    <input type="checkbox" class="form-check-input" [id]="'cf_' + f.key"
                           [(ngModel)]="customFieldValues[f.key]" [name]="'cf_' + f.key">
                    <label class="form-check-label small" [for]="'cf_' + f.key">{{ 'common.yes' | translate }}</label>
                  </div>
                  <input *ngSwitchDefault type="text" class="form-control"
                         [(ngModel)]="customFieldValues[f.key]" [name]="'cf_' + f.key">
                </ng-container>
              </div>
          </section>

          <!-- Submit -->
          <div class="form-actions">
            <button type="submit" class="btn btn-primary" [disabled]="saving || !form.first_name || !form.email">
              <span *ngIf="saving" class="spinner-border spinner-border-sm me-1"></span>
              <i *ngIf="!saving" class="bi me-1" [ngClass]="isEdit ? 'bi-check-lg' : 'bi-plus-lg'"></i>
              {{ isEdit ? ('admin.user_form.save_changes' | translate) : ('admin.user_form.create_title' | translate) }}
            </button>
            <a routerLink="/admin/users" class="btn btn-outline-secondary">{{ 'common.cancel' | translate }}</a>
          </div>

          <!-- Temp password result -->
          <div class="alert alert-success mt-3 mb-0" *ngIf="createdPassword">
            <strong>{{ 'admin.user_form.user_created' | translate }}</strong> {{ 'admin.user_form.temp_password_result' | translate }} <code class="user-select-all">{{ createdPassword }}</code>
          </div>
        </div>
      </form>

      <!-- Competency job profiles — the assignment that lets a new cycle
           auto-start this person's assessment. Assessors only, edit mode. -->
      <section class="ui-section" *ngIf="isEdit && canAssignProfiles && !loading">
        <div class="ui-section__head">
          <div>
            <h2 class="ui-section__title"><i class="bi bi-bullseye me-2"></i>{{ 'admin.user_form.job_profiles' | translate }}</h2>
            <p class="ui-section__subtitle">{{ 'admin.user_form.job_profiles_hint' | translate }}</p>
          </div>
          <div class="ui-section__actions">
            <button type="button" class="btn btn-primary btn-sm" [disabled]="savingProfiles" (click)="saveProfiles()">
              <span *ngIf="savingProfiles" class="spinner-border spinner-border-sm me-1"></span>
              {{ 'admin.user_form.save_profiles' | translate }}
            </button>
          </div>
        </div>
        <div *ngIf="allProfiles.length === 0" class="ui-state">
          <span class="ui-state__icon"><i class="bi bi-bullseye"></i></span>
          <p>{{ 'admin.user_form.no_profiles' | translate }}</p>
        </div>
        <div class="row g-2">
          <div class="col-md-6" *ngFor="let p of allProfiles">
            <label class="profile-option" [class.is-checked]="selectedProfiles.has(p.id)">
              <input type="checkbox" class="form-check-input mt-1" [checked]="selectedProfiles.has(p.id)" (change)="toggleProfile(p.id)">
              <span>
                <span class="fw-medium d-block">{{ p.jobTitle }}</span>
                <small class="ui-muted">{{ p.functionName }} · {{ p.competencyCount }} {{ 'admin.user_form.competencies' | translate }}</small>
              </span>
            </label>
          </div>
        </div>
      </section>

      </div>
    </main>
  `,
  styles: [`
    .section-stack { min-width: 0; }
    .section-stack > .ui-section { margin-bottom: 20px; }
    .role-box { max-height: 180px; overflow-y: auto; padding: 8px 12px; border: 1px solid var(--ui-border);
      border-radius: var(--ui-radius-ctl); background: var(--ui-surface); }
    .cf-field:last-child { margin-bottom: 0 !important; }
    .form-actions { display: flex; gap: 8px; }
    .profile-option { display: flex; align-items: flex-start; gap: 8px; height: 100%; padding: 10px 12px; cursor: pointer;
      border: 1px solid var(--ui-border); border-radius: var(--ui-radius-ctl); background: var(--ui-surface);
      transition: border-color .15s ease, background .15s ease; }
    .profile-option:hover { border-color: var(--ui-border-strong); }
    .profile-option.is-checked { border-color: rgba(var(--brand-primary-rgb), .45); background: rgba(var(--brand-primary-rgb), .05); }
  `]
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
 * `preferred_learning_mode` is on the form and is not stored: this system has
 * no such field. It is left in place rather than silently dropped from the
 * markup. `staffId`, once the same kind of orphan, is now the stored
 * `employeeId`; gender, job title, job grade, line manager and line executive
 * are all persisted too.
 */
export class UserFormComponent implements OnInit, OnDestroy {
  private readonly usersService = inject(UsersService);
  private readonly rolesService = inject(RolesService);
  private readonly lookupsService = inject(LookupsService);
  private readonly customFieldsService = inject(CustomFieldsService);
  private readonly competency = inject(CompetencyService);
  private readonly session = inject(SessionStore);
  private readonly i18n = inject(I18nService);
  private readonly toast = inject(HotToastService);
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
    gender: '',
    staffId: '',
    branch_id: null,
    department_id: null,
    team_id: null,
    job_title_id: null,
    level_id: null,
    manager_id: null,
    line_executive_id: null,
    hire_date: '',
    language: 'en',
    preferred_learning_mode: '',
    password: '',
  };

  isActive = true;

  // Competency job profiles the person is assigned to (edit mode, for
  // assessors) — the assignment that lets a new cycle auto-start their
  // assessment.
  canAssignProfiles = false;
  allProfiles: JobCompetencyProfile[] = [];
  selectedProfiles = new Set<string>();
  savingProfiles = false;

  readonly genderOptions = [
    { value: '', label: 'admin.user_form.gender_unspecified' },
    { value: 'male', label: 'admin.user_form.gender_male' },
    { value: 'female', label: 'admin.user_form.gender_female' },
    { value: 'other', label: 'admin.user_form.gender_other' },
    { value: 'undisclosed', label: 'admin.user_form.gender_undisclosed' },
  ];
  readonly languageOptions = [
    { value: 'en', label: 'admin.user_form.lang_en' },
    { value: 'fr', label: 'admin.user_form.lang_fr' },
    { value: 'ar', label: 'admin.user_form.lang_ar' },
  ];
  readonly learningModeOptions = [
    { value: 'video', label: 'admin.user_form.mode_video' },
    { value: 'audio', label: 'admin.user_form.mode_audio' },
    { value: 'reading', label: 'admin.user_form.mode_reading' },
    { value: 'interactive', label: 'admin.user_form.mode_interactive' },
  ];

  roles: Role[] = [];
  branches: OrgUnit[] = [];
  departments: OrgUnit[] = [];
  teams: OrgUnit[] = [];
  jobTitles: Array<{ id: string; name: string }> = [];
  levels: Array<{ id: string; name: string }> = [];
  /** People the manager / line-executive pickers choose from. */
  people: Array<{ id: string; fullName: string }> = [];

  /** The tenant's active custom fields, and this person's answers to them. */
  customFields: CustomFieldDefinition[] = [];
  customFieldValues: Record<string, string | number | boolean> = {};

  selectedRoleIds = new Set<string>();

  ngOnInit(): void {
    this.userId = this.route.snapshot.paramMap.get('id');
    this.isEdit = this.userId !== null;
    this.canAssignProfiles = this.session.can()('competency.assess');

    if (this.isEdit && this.canAssignProfiles && this.userId !== null) {
      this.loadProfiles(this.userId);
    }

    forkJoin({
      roles: this.rolesService.list(),
      branches: this.lookupsService.active('branch'),
      departments: this.lookupsService.active('department'),
      teams: this.lookupsService.active('team'),
      jobTitles: this.lookupsService.active('job_title'),
      levels: this.lookupsService.active('level'),
      // The manager / line-executive pickers. A plain list, capped — the same
      // shape every other picker on this form uses.
      people: this.usersService.options(undefined, 500),
      customFields: this.customFieldsService.list(),
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
          this.jobTitles = reference.jobTitles.map((j) => ({ id: j.id, name: j.name }));
          this.levels = reference.levels.map((l) => ({ id: l.id, name: l.name }));
          // A person cannot be their own manager or line executive; leaving
          // themselves out of the picker is clearer than a server refusal.
          this.people = reference.people.items
            .filter((p) => p.id !== this.userId)
            .map((p) => ({ id: p.id, fullName: p.fullName }));
          // Only active fields are offered on the form; an inactive definition
          // stops being asked for without losing the values already stored.
          this.customFields = reference.customFields.filter((f) => f.isActive);

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

  // --- competency job profiles --------------------------------------------

  private loadProfiles(userId: string): void {
    this.competency.getProfiles({ perPage: 200 }).pipe(takeUntil(this.destroy$)).subscribe({
      next: (page) => (this.allProfiles = page.items),
      error: () => undefined,
    });
    this.competency.getUserProfiles(userId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => (this.selectedProfiles = new Set(res.profiles.map((p) => p.id))),
      error: () => undefined,
    });
  }

  toggleProfile(id: string): void {
    if (this.selectedProfiles.has(id)) {
      this.selectedProfiles.delete(id);
    } else {
      this.selectedProfiles.add(id);
    }
  }

  saveProfiles(): void {
    if (this.userId === null) {
      return;
    }
    this.savingProfiles = true;
    this.competency
      .setUserProfiles(this.userId, [...this.selectedProfiles])
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (res) => {
          this.selectedProfiles = new Set(res.profiles.map((p) => p.id));
          this.savingProfiles = false;
          this.toast.success(this.i18n.t('admin.user_form.profiles_saved'));
        },
        error: () => {
          this.savingProfiles = false;
          this.toast.error(this.i18n.t('admin.user_form.profiles_save_error'));
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
            gender: user.gender ?? '',
            staffId: user.employeeId ?? '',
            branch_id: user.branchId,
            department_id: user.departmentId,
            team_id: user.teamId,
            job_title_id: user.jobTitleId,
            level_id: user.levelId,
            manager_id: user.managerId,
            line_executive_id: user.lineExecutiveId,
            hire_date: user.hireDate ?? '',
            language: user.locale,
            preferred_learning_mode: '',
            password: '',
          };

          this.isActive = user.status === 'active';
          this.selectedRoleIds = new Set(user.roleIds);
          this.customFieldValues = { ...(user.customFields ?? {}) };
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
      gender: this.form.gender || null,
      employeeId: this.form.staffId.trim() || null,
      locale: this.form.language || 'en',
      branchId: this.form.branch_id,
      departmentId: this.form.department_id,
      teamId: this.form.team_id,
      jobTitleId: this.form.job_title_id,
      levelId: this.form.level_id,
      managerId: this.form.manager_id,
      lineExecutiveId: this.form.line_executive_id,
      hireDate: this.form.hire_date || null,
      customFields: this.customFieldValues,
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
