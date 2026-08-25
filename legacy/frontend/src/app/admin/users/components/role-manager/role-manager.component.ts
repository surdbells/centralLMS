// users/components/role-manager/role-manager.component.ts
import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { UserService } from '../../services/user.service';
import { Role, Permission, PermissionGroup } from '../../models/user.models';
import {AdmintopComponent} from '../../../../partials/top/admintop/admintop.component';

@Component({
  selector: 'app-role-manager',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>
    <main class="main px-lg-6">
      <div class="container-fluid">
    <div class="role-manager">
      <div class="d-flex justify-content-between align-items-center mb-4">
        <div class="d-flex align-items-center gap-3">
          <a routerLink="/admin/users" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i></a>
          <h4 class="fw-bold mb-0"><i class="bi bi-shield-lock me-2"></i>Roles & Permissions</h4>
        </div>
        <button class="btn btn-primary btn-sm" (click)="openCreateModal()"><i class="bi bi-plus-lg me-1"></i>New Role</button>
      </div>

      <div class="row g-4">
        <!-- Roles List -->
        <div class="col-md-4">
          <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Roles</div>
            <div class="list-group list-group-flush">
              <a *ngFor="let r of roles" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center"
                 [class.active]="selectedRole?.role_id === r.role_id" role="button" (click)="selectRole(r)">
                <div>
                  <div class="fw-semibold">{{ r.role_name }}
                    <span class="badge bg-light text-muted ms-1" style="font-size:.6rem" *ngIf="r.is_system">SYSTEM</span>
                  </div>
                  <small class="text-muted" *ngIf="r.description">{{ r.description }}</small>
                </div>
                <span class="badge bg-primary rounded-pill">{{ r.user_count }}</span>
              </a>
              <div *ngIf="!roles.length" class="list-group-item text-center text-muted py-3">No roles found</div>
            </div>
          </div>
        </div>

        <!-- Permissions Panel -->
        <div class="col-md-8">
          <div *ngIf="!selectedRole" class="text-center py-5 text-muted">
            <i class="bi bi-shield fs-1 d-block mb-2"></i><p>Select a role to manage permissions</p>
          </div>

          <div *ngIf="selectedRole">
            <!-- Role header -->
            <div class="card border-0 shadow-sm mb-3">
              <div class="card-body d-flex justify-content-between align-items-center py-2">
                <div>
                  <h5 class="fw-bold mb-0">{{ selectedRole.role_name }}</h5>
                  <small class="text-muted">{{ selectedRole.description || 'No description' }}</small>
                </div>
                <div class="d-flex gap-2">
                  <button class="btn btn-sm btn-primary" (click)="openEditModal()" *ngIf="!selectedRole.is_system">
                    <i class="bi bi-pencil me-1"></i>Edit
                  </button>
                  <button class="btn btn-sm btn-outline-danger" (click)="deleteRole()" *ngIf="!selectedRole.is_system">
                    <i class="bi bi-trash me-1"></i>Delete
                  </button>
                  <button class="btn btn-sm btn-success" (click)="savePermissions()" [disabled]="savingPerms">
                    <span *ngIf="savingPerms" class="spinner-border spinner-border-sm me-1"></span>
                    <i *ngIf="!savingPerms" class="bi bi-check-lg me-1"></i>Save Permissions
                  </button>
                </div>
              </div>
            </div>

            <!-- Permission modules -->
            <div *ngFor="let module of permissionModules" class="card border-0 shadow-sm mb-2">
              <div class="card-header bg-white d-flex justify-content-between align-items-center py-2 cursor-pointer"
                   (click)="toggleModule(module)">
                <span class="fw-semibold text-capitalize"><i class="bi bi-folder me-2"></i>{{ module }}
                  <span class="badge bg-primary-subtle text-primary ms-2">{{ getModuleSelectedCount(module) }}/{{ permissionGroups[module].length }}</span>
                </span>
                <div class="d-flex gap-2 align-items-center">
                  <button class="btn btn-sm btn-link p-0 small" (click)="selectAllModule(module, $event)">Select All</button>
                  <i class="bi" [class]="expandedModules.has(module) ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                </div>
              </div>
              <div class="card-body py-2" *ngIf="expandedModules.has(module)">
                <div class="row g-0">
                  <div *ngFor="let p of permissionGroups[module]" class="col-md-6">
                    <div class="form-check mb-1">
                      <input type="checkbox" class="form-check-input" [id]="'perm_'+p.permission_id"
                             [checked]="selectedPermIds.has(p.permission_id)" (change)="togglePermission(p.permission_id)">
                      <label class="form-check-label small" [for]="'perm_'+p.permission_id">
                        <code class="text-muted">{{ p.permission_key }}</code>
                        <span class="d-block text-muted" style="font-size:.7rem">{{ p.description }}</span>
                      </label>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Create/Edit Role Modal -->
      <div class="modal fade show d-block" *ngIf="showModal" style="background:rgba(0,0,0,.5)">
        <div class="modal-dialog modal-dialog-centered">
          <div class="modal-content">
            <div class="modal-header">
              <h5 class="modal-title">{{ editingRole ? 'Edit Role' : 'Create Role' }}</h5>
              <button type="button" class="btn-close" (click)="showModal=false"></button>
            </div>
            <div class="modal-body">
              <div class="mb-3">
                <label class="form-label small fw-semibold">Role Name</label>
                <input type="text" class="form-control form-control-sm" [(ngModel)]="modalData.name" placeholder="e.g. Department Head">
              </div>
              <div class="mb-3" *ngIf="!editingRole">
                <label class="form-label small fw-semibold">Role Key</label>
                <input type="text" class="form-control form-control-sm" [(ngModel)]="modalData.key"
                       placeholder="e.g. department_head" pattern="[a-z0-9_]+">
                <small class="text-muted">Lowercase, underscores only. Cannot be changed later.</small>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">Description</label>
                <textarea class="form-control form-control-sm" rows="2" [(ngModel)]="modalData.description"></textarea>
              </div>
              <div class="alert alert-danger py-1 small" *ngIf="modalError">{{ modalError }}</div>
            </div>
            <div class="modal-footer">
              <button class="btn btn-sm btn-secondary" (click)="showModal=false">Cancel</button>
              <button class="btn btn-sm btn-primary" (click)="saveRole()" [disabled]="savingRole || !modalData.name">
                <span *ngIf="savingRole" class="spinner-border spinner-border-sm me-1"></span>
                {{ editingRole ? 'Update' : 'Create' }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
      </div>
    </main>
  `,
  styles: [`.cursor-pointer { cursor:pointer; } .modal.fade { display:block; opacity:1; }`]
})
export class RoleManagerComponent implements OnInit {
  roles: Role[] = [];
  selectedRole: Role | null = null;
  permissionGroups: PermissionGroup = {};
  permissionModules: string[] = [];
  selectedPermIds = new Set<number>();
  expandedModules = new Set<string>();
  savingPerms = false;

  // Modal
  showModal = false;
  editingRole = false;
  modalData = { name: '', key: '', description: '' };
  modalError = '';
  savingRole = false;

  constructor(private svc: UserService) {}

  ngOnInit(): void {
    this.loadRoles();
    this.svc.listPermissions().subscribe(g => {
      this.permissionGroups = g;
      this.permissionModules = Object.keys(g).sort();
    });
  }

  loadRoles(): void {
    this.svc.listRoles().subscribe(r => this.roles = r);
  }

  selectRole(role: Role): void {
    this.selectedRole = role;
    this.selectedPermIds.clear();
    this.expandedModules.clear();
    // Load role permissions
    this.svc.getRole(role.role_id).subscribe(full => {
      (full.permissions || []).forEach((p: Permission) => this.selectedPermIds.add(p.permission_id));
      // Expand modules that have selections
      for (const mod of this.permissionModules) {
        if (this.getModuleSelectedCount(mod) > 0) this.expandedModules.add(mod);
      }
    });
  }

  toggleModule(mod: string): void {
    if (this.expandedModules.has(mod)) this.expandedModules.delete(mod);
    else this.expandedModules.add(mod);
  }

  togglePermission(pid: number): void {
    if (this.selectedPermIds.has(pid)) this.selectedPermIds.delete(pid);
    else this.selectedPermIds.add(pid);
  }

  selectAllModule(mod: string, e: Event): void {
    e.stopPropagation();
    const perms = this.permissionGroups[mod] || [];
    const allSelected = perms.every(p => this.selectedPermIds.has(p.permission_id));
    perms.forEach(p => allSelected ? this.selectedPermIds.delete(p.permission_id) : this.selectedPermIds.add(p.permission_id));
    this.expandedModules.add(mod);
  }

  getModuleSelectedCount(mod: string): number {
    return (this.permissionGroups[mod] || []).filter(p => this.selectedPermIds.has(p.permission_id)).length;
  }

  savePermissions(): void {
    if (!this.selectedRole) return;
    this.savingPerms = true;
    this.svc.setRolePermissions(this.selectedRole.role_id, [...this.selectedPermIds]).subscribe({
      next: () => { this.savingPerms = false; },
      error: () => { this.savingPerms = false; alert('Failed to save permissions'); }
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
    if (!this.selectedRole) return;
    this.editingRole = true;
    this.modalData = { name: this.selectedRole.role_name, key: this.selectedRole.role_key, description: this.selectedRole.description || '' };
    this.modalError = '';
    this.showModal = true;
  }

  saveRole(): void {
    this.savingRole = true;
    this.modalError = '';

    const obs = this.editingRole
      ? this.svc.updateRole(this.selectedRole!.role_id, this.modalData.name, this.modalData.description)
      : this.svc.createRole(this.modalData.name, this.modalData.key, this.modalData.description);

    obs.subscribe({
      next: (res: any) => {
        this.savingRole = false;
        const r = res.data || res;
        if (r.success === false) { this.modalError = r.error || 'Failed'; return; }
        this.showModal = false;
        this.loadRoles();
      },
      error: (e: any) => { this.savingRole = false; this.modalError = e?.error?.message || 'Failed'; }
    });
  }

  deleteRole(): void {
    if (!this.selectedRole || !confirm(`Delete role "${this.selectedRole.role_name}"? Users will lose this role.`)) return;
    this.svc.deleteRole(this.selectedRole.role_id).subscribe({
      next: () => { this.selectedRole = null; this.loadRoles(); },
      error: () => alert('Failed to delete role')
    });
  }
}
