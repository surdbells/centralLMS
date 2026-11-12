import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Subject, forkJoin } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { RolesService, type Role as ApiRole } from '../../../../services/roles.service';
import { ApiError } from '../../../../core/api';
import { AdmintopComponent } from '../../../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../../../translate.pipe';
import { I18nService } from '../../../../i18n.service';
import { ConfirmDialogService } from '../../../../shared/components/confirmation-dialog/confirmation-dialog.component';
import { HotToastService } from '@ngneat/hot-toast';

/*
 * The shapes this screen's template already reads.
 *
 * The screen is unchanged; what fills it is not. `UserService` served a
 * `permissions` table of 61 rows that the authorisation code did not read, so
 * a box could be ticked and mean nothing. The list here is derived from the
 * one enum the API actually checks.
 */

export interface Role {
  role_id: string;
  role_key: string;
  role_name: string;
  description: string | null;
  is_system: boolean;
  /** How many people hold it. Counted, never stored. */
  user_count: number;
}

export interface Permission {
  /** The permission's own value — `course.manage`. It is the identifier. */
  permission_id: string;
  permission_key: string;
  module: string;
  description: string;
}

export type PermissionGroup = Record<string, Permission[]>;

@Component({
  selector: 'app-role-manager',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent, TranslatePipe],
  template: `
    <app-admintop></app-admintop>
    <main class="main">
    <div class="ui-page role-manager">
      <header class="ui-page-header">
        <a routerLink="/admin/users" class="ui-page-header__back" [attr.aria-label]="'common.back' | translate"><i class="bi bi-chevron-left"></i></a>
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></div>
        <div class="ui-page-header__titles">
          <h1>{{ 'admin.roles.title' | translate }}</h1>
          <p>{{ 'admin.roles.subtitle' | translate }}</p>
        </div>
        <div class="ui-page-header__actions">
          <button type="button" class="btn btn-primary btn-sm" (click)="openCreateModal()"><i class="bi bi-plus-lg me-1"></i>{{ 'admin.roles.new_role' | translate }}</button>
        </div>
      </header>

      <div class="row g-4">
        <!-- Roles List -->
        <div class="col-md-4">
          <section class="ui-section ui-section--flush">
            <div class="ui-section__head">
              <h2 class="ui-section__title">{{ 'admin.users.roles' | translate }}</h2>
              <span class="ui-pill ui-pill--neutral">{{ roles.length }}</span>
            </div>
            <div class="role-list">
              <a *ngFor="let r of roles" class="role-item"
                 [class.is-active]="selectedRole?.role_id === r.role_id" role="button" (click)="selectRole(r)">
                <div class="min-w-0">
                  <div class="role-item__name">{{ r.role_name }}
                    <span class="ui-pill ui-pill--neutral ms-1 role-item__sys" *ngIf="r.is_system">{{ 'admin.roles.system_badge' | translate }}</span>
                  </div>
                  <small class="ui-muted" *ngIf="r.description">{{ r.description }}</small>
                </div>
                <span class="ui-pill ui-pill--brand"><i class="bi bi-people"></i>{{ r.user_count }}</span>
              </a>
              <div *ngIf="!roles.length" class="p-3">
                <div class="ui-state">
                  <span class="ui-state__icon"><i class="bi bi-shield"></i></span>
                  <h3>{{ 'admin.roles.no_roles' | translate }}</h3>
                </div>
              </div>
            </div>
          </section>
        </div>

        <!-- Permissions Panel -->
        <div class="col-md-8">
          <div *ngIf="!selectedRole" class="ui-state">
            <span class="ui-state__icon"><i class="bi bi-shield"></i></span>
            <p>{{ 'admin.roles.select_role_hint' | translate }}</p>
          </div>

          <div *ngIf="selectedRole">
            <!-- Role header -->
            <section class="ui-section">
              <div class="ui-section__head mb-0">
                <div class="min-w-0">
                  <h2 class="ui-section__title">{{ selectedRole.role_name }}</h2>
                  <p class="ui-section__subtitle">{{ selectedRole.description || ('admin.roles.no_description' | translate) }}</p>
                </div>
                <div class="ui-section__actions">
                  <button type="button" class="btn btn-sm btn-outline-secondary" (click)="openEditModal()" *ngIf="!selectedRole.is_system">
                    <i class="bi bi-pencil me-1"></i>{{ 'common.edit' | translate }}
                  </button>
                  <button type="button" class="btn btn-sm btn-outline-danger" (click)="deleteRole()" *ngIf="!selectedRole.is_system">
                    <i class="bi bi-trash me-1"></i>{{ 'common.delete' | translate }}
                  </button>
                  <button type="button" class="btn btn-sm btn-primary" (click)="savePermissions()" [disabled]="savingPerms">
                    <span *ngIf="savingPerms" class="spinner-border spinner-border-sm me-1"></span>
                    <i *ngIf="!savingPerms" class="bi bi-check-lg me-1"></i>{{ 'admin.roles.save_permissions' | translate }}
                  </button>
                </div>
              </div>
            </section>

            <!-- Permission modules -->
            <section *ngFor="let module of permissionModules" class="ui-section ui-section--flush perm-module">
              <div class="perm-module__head cursor-pointer" (click)="toggleModule(module)">
                <span class="perm-module__title text-capitalize"><i class="bi bi-folder me-2"></i>{{ module }}
                  <span class="ui-pill ui-pill--brand ms-2">{{ getModuleSelectedCount(module) }}/{{ permissionGroups[module].length }}</span>
                </span>
                <div class="d-flex gap-2 align-items-center">
                  <button type="button" class="btn btn-sm btn-link p-0 small" (click)="selectAllModule(module, $event)">{{ 'admin.enroll.select_all' | translate }}</button>
                  <i class="bi" [ngClass]="expandedModules.has(module) ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                </div>
              </div>
              <div class="perm-module__body" *ngIf="expandedModules.has(module)">
                <div class="row g-0">
                  <div *ngFor="let p of permissionGroups[module]" class="col-md-6">
                    <div class="form-check mb-1">
                      <input type="checkbox" class="form-check-input" [id]="'perm_'+p.permission_id"
                             [checked]="selectedPermIds.has(p.permission_id)" (change)="togglePermission(p.permission_id)">
                      <label class="form-check-label small" [for]="'perm_'+p.permission_id">
                        <code class="perm-key">{{ p.permission_key }}</code>
                        <span class="d-block ui-muted perm-desc">{{ p.description }}</span>
                      </label>
                    </div>
                  </div>
                </div>
              </div>
            </section>
          </div>
        </div>
      </div>

      <!-- Create/Edit Role Modal -->
      <div class="modal fade show d-block" *ngIf="showModal" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">{{ editingRole ? ('admin.roles.edit_role' | translate) : ('admin.roles.create_role' | translate) }}</h5>
              <button type="button" class="btn-close" (click)="showModal=false"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.roles.role_name' | translate }}</label>
                <input type="text" class="form-control" [(ngModel)]="modalData.name" placeholder="{{ 'admin.roles.role_name_placeholder' | translate }}">
              </div>
              <div class="mb-3" *ngIf="!editingRole">
                <label class="form-label small fw-semibold">{{ 'admin.roles.role_key' | translate }}</label>
                <input type="text" class="form-control" [(ngModel)]="modalData.key"
                       placeholder="{{ 'admin.roles.role_key_placeholder' | translate }}" pattern="[a-z0-9_]+">
                <small class="text-muted">{{ 'admin.roles.role_key_hint' | translate }}</small>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">{{ 'admin.course_content.description' | translate }}</label>
                <textarea class="form-control" rows="2" [(ngModel)]="modalData.description"></textarea>
              </div>
              <div class="alert alert-danger py-1 small" *ngIf="modalError">{{ modalError }}</div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-sm btn-outline-secondary" (click)="showModal=false">{{ 'common.cancel' | translate }}</button>
              <button type="button" class="btn btn-sm btn-primary" (click)="saveRole()" [disabled]="savingRole || !modalData.name">
                <span *ngIf="savingRole" class="spinner-border spinner-border-sm me-1"></span>
                {{ editingRole ? ('admin.roles.update' | translate) : ('admin.roles.create' | translate) }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
    </main>
  `,
  styles: [`
    .cursor-pointer { cursor:pointer; }
    .modal.fade { display:block; opacity:1; }
    .min-w-0 { min-width: 0; }
    .ui-section--flush > .ui-section__head { align-items: center; padding-bottom: 14px; margin-bottom: 0; border-bottom: 1px solid var(--ui-border); }
    .role-list { max-height: 70vh; overflow-y: auto; }
    .role-item { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 12px 22px;
      border-bottom: 1px solid var(--ui-border); border-left: 3px solid transparent; color: var(--ui-text);
      text-decoration: none; cursor: pointer; transition: background .15s ease; }
    .role-item:last-child { border-bottom: 0; }
    .role-item:hover { background: var(--ui-surface-2); color: var(--ui-text); }
    .role-item.is-active { background: rgba(var(--brand-primary-rgb), .07); border-left-color: var(--brand-primary); }
    .role-item.is-active .role-item__name { color: var(--brand-primary); }
    .role-item__name { font-weight: 600; font-size: .875rem; }
    .role-item__sys { height: 18px; font-size: .625rem; padding: 0 6px; }
    .perm-module { margin-bottom: 10px; }
    .perm-module__head { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 18px; }
    .perm-module__head:hover { background: var(--ui-surface-2); }
    .perm-module__title { font-weight: 600; font-size: .875rem; color: var(--ui-text); }
    .perm-module__body { padding: 12px 18px; border-top: 1px solid var(--ui-border); }
    .perm-key { color: var(--ui-text-muted); }
    .perm-desc { font-size: .7rem; }
  `]
})
export class RoleManagerComponent implements OnInit, OnDestroy {
  private readonly rolesService = inject(RolesService);
  private readonly i18n = inject(I18nService);
  private readonly dialog = inject(ConfirmDialogService);
  private readonly toast = inject(HotToastService);
  private readonly destroy$ = new Subject<void>();

  roles: Role[] = [];
  selectedRole: Role | null = null;
  permissionGroups: PermissionGroup = {};
  permissionModules: string[] = [];
  /** Permission *values*, which is what a check names. */
  selectedPermIds = new Set<string>();
  expandedModules = new Set<string>();
  savingPerms = false;

  // Modal
  showModal = false;
  editingRole = false;
  modalData = { name: '', key: '', description: '' };
  modalError = '';
  savingRole = false;

  ngOnInit(): void {
    // The list carries each role's permissions, so opening one costs no
    // request — the old screen fetched the role again to get them.
    forkJoin({ roles: this.rolesService.list(), groups: this.rolesService.permissions() })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ roles, groups }) => {
          this.roles = roles.map((r) => this.adapt(r));
          this.permissionGroups = {};

          for (const group of groups) {
            this.permissionGroups[group.label] = group.permissions.map((p) => ({
              permission_id: p.value,
              permission_key: p.value,
              module: group.label,
              description: p.description,
            }));
          }

          this.permissionModules = Object.keys(this.permissionGroups).sort();
        },
        error: (e: ApiError) => (this.modalError = e.message),
      });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadRoles(): void {
    this.rolesService
      .list()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (roles) => {
          this.roles = roles.map((r) => this.adapt(r));

          // Keep the selection pointed at the same role across a reload.
          const keep = this.selectedRole;
          this.selectedRole = keep === null
            ? null
            : this.roles.find((r) => r.role_id === keep.role_id) ?? null;
        },
        error: () => undefined,
      });
  }

  selectRole(role: Role): void {
    this.selectedRole = role;
    this.selectedPermIds = new Set(this.permissionsOf(role.role_id));
    this.expandedModules = new Set(
      this.permissionModules.filter((module) => this.getModuleSelectedCount(module) > 0),
    );
  }

  toggleModule(mod: string): void {
    this.expandedModules.has(mod) ? this.expandedModules.delete(mod) : this.expandedModules.add(mod);
  }

  togglePermission(pid: string): void {
    this.selectedPermIds.has(pid) ? this.selectedPermIds.delete(pid) : this.selectedPermIds.add(pid);
  }

  selectAllModule(mod: string, e: Event): void {
    e.stopPropagation();

    const perms = this.permissionGroups[mod] ?? [];
    const allSelected = perms.every((p) => this.selectedPermIds.has(p.permission_id));

    perms.forEach((p) => (allSelected
      ? this.selectedPermIds.delete(p.permission_id)
      : this.selectedPermIds.add(p.permission_id)));

    this.expandedModules.add(mod);
  }

  getModuleSelectedCount(mod: string): number {
    return (this.permissionGroups[mod] ?? []).filter((p) => this.selectedPermIds.has(p.permission_id)).length;
  }

  savePermissions(): void {
    const role = this.selectedRole;

    if (role === null) {
      return;
    }

    this.savingPerms = true;

    this.rolesService
      .update(role.role_id, { permissions: [...this.selectedPermIds] })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (saved) => {
          this.savingPerms = false;
          this.replace(saved);
        },
        error: (e: ApiError) => {
          this.savingPerms = false;
          // The refusals are worth reading: a built-in cannot be edited, and
          // nobody may grant a permission they do not hold themselves.
          this.toast.error(e.violation('permissions') ?? e.message);
        },
      });
  }

  /* Modal */
  openCreateModal(): void {
    this.editingRole = false;
    this.modalData = { name: '', key: '', description: '' };
    this.modalError = '';
    this.showModal = true;
  }

  openEditModal(): void {
    const role = this.selectedRole;

    if (role === null) {
      return;
    }

    this.editingRole = true;
    this.modalData = { name: role.role_name, key: role.role_key, description: role.description ?? '' };
    this.modalError = '';
    this.showModal = true;
  }

  saveRole(): void {
    this.savingRole = true;
    this.modalError = '';

    // A new role is created with whatever is ticked, in one request. The old
    // screen created it empty and set the permissions separately, so the
    // second call could fail and leave a role that promised nothing.
    const saved = this.editingRole && this.selectedRole !== null
      ? this.rolesService.update(this.selectedRole.role_id, {
        name: this.modalData.name,
        description: this.modalData.description,
      })
      : this.rolesService.create({
        name: this.modalData.name,
        description: this.modalData.description,
        permissions: [...this.selectedPermIds],
      });

    saved.pipe(takeUntil(this.destroy$)).subscribe({
      next: (role) => {
        this.savingRole = false;
        this.showModal = false;
        this.replace(role);
        this.selectRole(this.adapt(role));
      },
      error: (e: ApiError) => {
        this.savingRole = false;
        // The key is derived from the name, so a complaint about it is about
        // the only field on this form.
        this.modalError = e.violation('slug') ?? e.violation('name') ?? e.message;
      },
    });
  }

  async deleteRole(): Promise<void> {
    const role = this.selectedRole;

    if (role === null || !(await this.dialog.confirm(this.i18n.t('admin.roles.confirm_delete', { name: role.role_name }), { type: 'danger' }))) {
      return;
    }

    this.rolesService
      .delete(role.role_id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.selectedRole = null;
          this.loadRoles();
        },
        // Refused while somebody still holds it, with the count. The old
        // screen warned "Users will lose this role" and then let it happen.
        error: (e: ApiError) => this.toast.error(e.message),
      });
  }

  // --- keeping the two lists in step ---------------------------------------

  private permissionsOf(roleId: string): string[] {
    return this.rolePermissions.get(roleId) ?? [];
  }

  /** Each role's permissions, from the list that already carried them. */
  private rolePermissions = new Map<string, string[]>();

  private replace(role: ApiRole): void {
    const adapted = this.adapt(role);
    const at = this.roles.findIndex((r) => r.role_id === adapted.role_id);

    this.roles = at === -1
      ? [...this.roles, adapted]
      : this.roles.map((r) => (r.role_id === adapted.role_id ? adapted : r));
  }

  private adapt(role: ApiRole): Role {
    this.rolePermissions.set(role.id, role.permissions);

    return {
      role_id: role.id,
      role_key: role.slug,
      role_name: role.name,
      description: role.description,
      is_system: role.isSystem,
      // Counted through `users`, never stored. The legacy kept
      // `roles.user_count` and never decremented it.
      user_count: role.holders,
    };
  }
}
