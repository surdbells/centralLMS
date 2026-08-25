import { decodeSessionBlob } from '../../../../core/utils/session-blob';
// users/components/user-form/user-form.component.ts
import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { UserService } from '../../services/user.service';
import { UserFormData, Role } from '../../models/user.models';
import {AdmintopComponent} from '../../../../partials/top/admintop/admintop.component';
import {Observable, of} from 'rxjs';
import {catchError, shareReplay} from 'rxjs/operators';
import {GlobalComponent} from '../../../../global-component';

@Component({
  selector: 'app-user-form',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>
    <main class="main px-lg-6">
      <div class="container-fluid">
    <div class="user-form">
      <div class="d-flex align-items-center gap-3 mb-4">
        <a routerLink="/admin/users" class="btn btn-sm btn-secondary"><i class="bi bi-arrow-left"></i></a>
        <h4 class="fw-bold mb-0"><i class="bi me-2" [class]="isEdit ? 'bi-pencil' : 'bi-person-plus'"></i>{{ isEdit ? 'Edit User' : 'Create User' }}</h4>
      </div>

      <div *ngIf="loading" class="text-center py-5"><div class="spinner-border text-primary"></div></div>

      <div class="alert alert-danger mt-3" *ngIf="error"><i class="bi bi-exclamation-triangle me-2"></i>{{ error }}</div>

      <form *ngIf="!loading" (ngSubmit)="onSubmit()" class="row g-4">
        <!-- Personal Info -->
        <div class="col-md-6">
          <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person me-2"></i>Personal Information</div>
            <div class="card-body">
              <div class="mb-3">
                <label class="form-label small fw-semibold">First Name <span class="text-danger">*</span></label>
                <input type="text" class="form-control form-control-sm" [(ngModel)]="form.first_name" name="first_name" required>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">Last Name</label>
                <input type="text" class="form-control form-control-sm" [(ngModel)]="form.last_name" name="last_name">
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">Email <span class="text-danger">*</span></label>
                <input type="email" class="form-control form-control-sm" [(ngModel)]="form.email" name="email" required>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">Phone</label>
                <input type="text" class="form-control form-control-sm" [(ngModel)]="form.phone" name="phone">
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">Staff ID</label>
                <input type="text" class="form-control form-control-sm" [(ngModel)]="form.staffId" name="staffId">
              </div>
              <div class="row g-2">
                <div class="col-6 mb-3">
                  <label class="form-label small fw-semibold">Language</label>
                  <select class="form-select form-select-sm" [(ngModel)]="form.language" name="language">
                    <option value="en">English</option><option value="fr">French</option><option value="ar">Arabic</option>
                  </select>
                </div>
                <div class="col-6 mb-3">
                  <label class="form-label small fw-semibold">Learning Mode</label>
                  <select class="form-select form-select-sm" [(ngModel)]="form.preferred_learning_mode" name="learning_mode">
                    <option value="video">Video</option><option value="audio">Audio</option>
                    <option value="reading">Reading</option><option value="interactive">Interactive</option>
                  </select>
                </div>
              </div>
              <div class="mb-3" *ngIf="!isEdit">
                <label class="form-label small fw-semibold">Temporary Password</label>
                <input type="text" class="form-control form-control-sm" [(ngModel)]="form.password" name="password"
                       placeholder="Leave blank to auto-generate">
              </div>
            </div>
          </div>
        </div>

        <!-- Org Info -->
        <div class="col-md-6">
          <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-building me-2"></i>Organization</div>
            <div class="card-body">
              <div class="mb-3">
                <label class="form-label small fw-semibold">Department</label>
                <select class="form-select form-select-sm" [(ngModel)]="form.department_id" name="department_id">
                  <option *ngFor="let d of departments" [ngValue]="d.department_id">{{ d.department_name }}</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">Branch</label>
                <select class="form-select form-select-sm" [(ngModel)]="form.branch_id" name="branch_id">
                  <option *ngFor="let b of branches" [ngValue]="b.branch_id">{{ b.branch_name }}</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">Team</label>
                <select class="form-select form-select-sm" [(ngModel)]="form.team_id" name="team_id">
                  <option *ngFor="let t of teams" [ngValue]="t.team_id">{{ t.team_name }}</option>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">Hire Date</label>
                <input type="date" class="form-control form-control-sm" [(ngModel)]="form.hire_date" name="hire_date">
              </div>
              <div class="mb-3">
                <label class="form-label small fw-semibold">Roles</label>
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
                <label class="form-check-label small" for="isActive">Active account</label>
              </div>
            </div>
          </div>

          <!-- Submit -->
          <div class="d-flex gap-2 mt-3">
            <button type="submit" class="btn btn-primary" [disabled]="saving || !form.first_name || !form.email">
              <span *ngIf="saving" class="spinner-border spinner-border-sm me-1"></span>
              <i *ngIf="!saving" class="bi me-1" [class]="isEdit ? 'bi-check-lg' : 'bi-plus-lg'"></i>
              {{ isEdit ? 'Save Changes' : 'Create User' }}
            </button>
            <a routerLink="/admin/users" class="btn btn-secondary">Cancel</a>
          </div>

          <!-- Temp password result -->
          <div class="alert alert-success mt-3" *ngIf="createdPassword">
            <strong>User created!</strong> Temporary password: <code class="user-select-all">{{ createdPassword }}</code>
          </div>
        </div>
      </form>

    </div>
      </div>
    </main>
  `
})
export class UserFormComponent implements OnInit {
  isEdit = false;
  loading = false;
  saving = false;
  error = '';
  createdPassword = '';
  session_data: any = '';
  user_session = {
    id: 0, tenant_id: 0, token: '',
    tenant: { id: 0, name: '', status: '' },
    first_name: '', last_name: '', email: '', phone: '', avatar: '',
    is_2fa: false, is_active: false, account_locked: false,
    is_admin: false, is_content: false, is_instructor: false, is_learner: false,
  };
  form: UserFormData = { first_name: '', email: '', language: 'en' };
  selectedRoleIds = new Set<number>();
  isActive = true;

  roles: Role[] = [];
  departments: any[] = [];
  branches: any[] = [];
  teams: any[] = [];

  private userId = 0;

  constructor(private svc: UserService, private route: ActivatedRoute, private router: Router) {}

  ngOnInit(): void {
    this.userId = +(this.route.snapshot.paramMap.get('id') || 0);
    this.isEdit = !!this.userId;

    this.svc.listRoles().subscribe(r => this.roles = r);
    this.loadOrgUnits();

    if (this.isEdit) {
      this.loading = true;
      this.svc.getUser(this.userId).subscribe({
        next: u => {
          this.form = {
            first_name: u.first_name, last_name: u.last_name || '', email: u.email,
            phone: u.phone || '', staffId: u.staffId || '',
            department_id: u.department, team_id: u.team, branch_id: u.branch,
            job_title: u.job_title, manager_id: u.manager_id,
            hire_date: u.hire_date || '', language: u.language || 'en',
            preferred_learning_mode: u.preferred_learning_mode || '',
          };
          this.isActive = u.is_active === 1;
          u.roles.forEach(r => this.selectedRoleIds.add(r.role_id));
          this.loading = false;
        },
        error: () => { this.error = 'Failed to load user'; this.loading = false; }
      });
    }
  }

  loadOrgUnits(): void {
    // Reuse reports API org-units endpoint or a dedicated one
    // For now, load via reports endpoint pattern
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = decodeSessionBlob(this.session_data!) ?? this.user_session;
    const token = this.user_session.token;
    const uid = this.user_session.id;
    const tid = this.user_session.tenant_id;
    const base = GlobalComponent.adminReport + `?action=org-units&token=${token}&user_id=${uid}&tenant_id=${tid}`;
    fetch(base).then(r => r.json()).then(res => {
      const d = res.data || res;
      this.departments = d.departments || [];
      this.branches = d.branches || [];
      this.teams = d.teams || [];
    }).catch(() => {});
  }



  toggleRole(roleId: number): void {
    if (this.selectedRoleIds.has(roleId)) this.selectedRoleIds.delete(roleId);
    else this.selectedRoleIds.add(roleId);
  }

  onSubmit(): void {
    this.saving = true;
    this.error = '';
    this.createdPassword = '';

    const data: any = { ...this.form, is_active: this.isActive ? 1 : 0, role_ids: [...this.selectedRoleIds] };

    const obs = this.isEdit ? this.svc.updateUser(this.userId, data) : this.svc.createUser(data);

    obs.subscribe({
      next: (res: any) => {
        this.saving = false;
        const r = res.data || res;
        if (r.success === false) { this.error = r.error || 'Failed'; return; }

        if (!this.isEdit && r.temp_password) {
          this.createdPassword = r.temp_password;
        } else {
          this.router.navigate(['/admin/users']);
        }
      },
      error: (e: any) => {
        this.saving = false;
        this.error = e?.error?.message || e?.error?.data?.error || 'Save failed';
      }
    });
  }
}
