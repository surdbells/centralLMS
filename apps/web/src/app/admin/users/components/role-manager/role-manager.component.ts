import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Subject, forkJoin } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  RolesService,
  type PermissionGroup,
  type Role,
} from '../../../../services/roles.service';
import { ApiError } from '../../../../core/api';
import { AdmintopComponent } from '../../../../partials/top/admintop/admintop.component';

/**
 * Roles, and what each of them may do.
 *
 * Three differences from the screen this replaces, all of them about the same
 * thing — the editor and the code now agree:
 *
 *  - The permission list comes from the server, which derives it from the one
 *    enum authorisation actually checks. The legacy read a `permissions` table
 *    of 61 rows that nothing enforced, so a box could be ticked and mean
 *    nothing at all.
 *  - Saving a role saves its permissions. They were two requests, and the
 *    second could fail on its own and leave a role that promised nothing.
 *  - A built-in cannot be edited or deleted, and the server says so rather
 *    than the button merely being hidden.
 */
@Component({
  selector: 'app-role-manager',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent],
  templateUrl: './role-manager.component.html',
  styles: [`
    .cursor-pointer { cursor: pointer; }
    .modal.fade { display: block; opacity: 1; }
  `],
})
export class RoleManagerComponent implements OnInit, OnDestroy {
  private readonly rolesService = inject(RolesService);
  private readonly destroy$ = new Subject<void>();

  roles: Role[] = [];
  selectedRole: Role | null = null;

  groups: PermissionGroup[] = [];
  /** Permission values, not ids: the value is what a check names. */
  selected = new Set<string>();
  expanded = new Set<string>();

  loading = true;
  saving = false;
  /** Set when the permissions on screen differ from the ones last saved. */
  dirty = false;
  error: string | null = null;
  notice: string | null = null;

  showModal = false;
  editing = false;
  form = { name: '', description: '' };
  modalError: string | null = null;

  ngOnInit(): void {
    this.load();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  private load(): void {
    this.loading = true;

    forkJoin({ roles: this.rolesService.list(), groups: this.rolesService.permissions() })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ roles, groups }) => {
          this.roles = roles;
          this.groups = groups;
          this.loading = false;

          // Keep the selection pointed at the same role across a reload.
          const keep = this.selectedRole;
          this.selectedRole = keep === null ? null : roles.find((r) => r.id === keep.id) ?? null;

          if (this.selectedRole !== null) {
            this.showPermissionsOf(this.selectedRole);
          }
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.loading = false;
        },
      });
  }

  // --- choosing a role ----------------------------------------------------

  selectRole(role: Role): void {
    this.selectedRole = role;
    this.notice = null;
    this.showPermissionsOf(role);
  }

  /**
   * The list already carries each role's permissions, so opening one costs no
   * request. The legacy fetched the role again to get them.
   */
  private showPermissionsOf(role: Role): void {
    this.selected = new Set(role.permissions);
    this.dirty = false;
    this.expanded = new Set(
      this.groups
        .filter((group) => group.permissions.some((p) => this.selected.has(p.value)))
        .map((group) => group.module),
    );
  }

  // --- ticking boxes ------------------------------------------------------

  toggleModule(module: string): void {
    this.expanded.has(module) ? this.expanded.delete(module) : this.expanded.add(module);
  }

  togglePermission(value: string): void {
    this.selected.has(value) ? this.selected.delete(value) : this.selected.add(value);
    this.dirty = true;
  }

  selectAllInModule(group: PermissionGroup, event: Event): void {
    event.stopPropagation();

    const all = group.permissions.every((p) => this.selected.has(p.value));

    for (const permission of group.permissions) {
      all ? this.selected.delete(permission.value) : this.selected.add(permission.value);
    }

    this.expanded.add(group.module);
    this.dirty = true;
  }

  selectedCount(group: PermissionGroup): number {
    return group.permissions.filter((p) => this.selected.has(p.value)).length;
  }

  savePermissions(): void {
    const role = this.selectedRole;

    if (role === null || !role.editable) {
      return;
    }

    this.saving = true;
    this.error = null;

    this.rolesService
      .update(role.id, { permissions: [...this.selected] })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (saved) => {
          this.saving = false;
          this.dirty = false;
          this.notice = `Saved. Anyone holding "${saved.name}" sees the change on their next request.`;
          this.replace(saved);
        },
        error: (error: ApiError) => {
          this.saving = false;
          this.error = error.message;
        },
      });
  }

  // --- creating and editing -----------------------------------------------

  openCreateModal(): void {
    this.editing = false;
    this.form = { name: '', description: '' };
    this.modalError = null;
    this.showModal = true;
  }

  openEditModal(): void {
    const role = this.selectedRole;

    if (role === null) {
      return;
    }

    this.editing = true;
    this.form = { name: role.name, description: role.description ?? '' };
    this.modalError = null;
    this.showModal = true;
  }

  closeModal(): void {
    this.showModal = false;
    this.modalError = null;
  }

  saveRole(): void {
    const name = this.form.name.trim();

    if (name === '') {
      return;
    }

    this.saving = true;
    this.modalError = null;

    // A new role is created with whatever is ticked, in one request. The
    // legacy created it empty and then set permissions separately.
    const saved = this.editing && this.selectedRole !== null
      ? this.rolesService.update(this.selectedRole.id, { name, description: this.form.description })
      : this.rolesService.create({ name, description: this.form.description, permissions: [...this.selected] });

    saved.pipe(takeUntil(this.destroy$)).subscribe({
      next: (role) => {
        this.saving = false;
        this.showModal = false;
        this.selectedRole = role;
        this.replace(role);
        this.showPermissionsOf(role);
      },
      error: (error: ApiError) => {
        this.saving = false;
        // The slug is derived from the name, so a slug complaint is about the
        // only field on this form.
        this.modalError = error.violation('slug') ?? error.violation('name') ?? error.message;
      },
    });
  }

  deleteRole(): void {
    const role = this.selectedRole;

    if (role === null || !confirm(`Delete the role "${role.name}"?`)) {
      return;
    }

    this.error = null;

    this.rolesService
      .delete(role.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.selectedRole = null;
          this.load();
        },
        // Refused while somebody still holds it, and the message says how
        // many. Shown rather than replaced with "Failed to delete role".
        error: (error: ApiError) => (this.error = error.message),
      });
  }

  private replace(role: Role): void {
    const at = this.roles.findIndex((r) => r.id === role.id);

    this.roles = at === -1 ? [...this.roles, role] : this.roles.map((r) => (r.id === role.id ? role : r));
  }

  trackById(_index: number, item: { id: string }): string {
    return item.id;
  }

  trackByModule(_index: number, group: PermissionGroup): string {
    return group.module;
  }
}
