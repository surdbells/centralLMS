// users/components/my-account/my-account.component.ts
import { Component, OnInit, ViewChild, ElementRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {Router, RouterLink} from '@angular/router';
import { UserService } from '../admin/users/services/user.service';
import {TopComponent} from '../partials/top/top.component';
import {TuiIcon} from '@taiga-ui/core';

@Component({
  selector: 'app-my-account',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, TopComponent, TuiIcon],
  template: `
    <app-top></app-top>
    <main class="main">
      <div class="container-fluid py-4">
        <div class="row align-items-center mb-4">
          <div (click)="goBack()" role="button" class="col-auto">
            <div class="avatar text-center avatar-md rounded text-primary-emphasis">
              <i class="fs-5"><tui-icon icon="@tui.chevron-left"></tui-icon></i>
            </div>
          </div>
          <div class="col">
            <h1 class="fs-4 mb-0">My Account</h1>
            <small class="text-muted">Track your progress and continue learning</small>
          </div>
        </div>
        <div class="my-account">
      <!-- Header -->

      <div *ngIf="loading" class="text-center py-5"><div class="spinner-border text-primary"></div></div>

      <div *ngIf="profile && !loading">
        <!-- Profile Banner -->
        <div class="card border-0 shadow-sm mb-4 overflow-hidden">
          <div class="profile-banner"></div>
          <div class="card-body position-relative pt-0">
            <div class="d-flex align-items-end gap-3" style="margin-top:-40px">
              <!-- Avatar -->
              <div class="position-relative">
                <div class="avatar-xl rounded-circle border border-4 border-white shadow-sm overflow-hidden"
                     [style.background-color]="!profile.avatar_url ? getColor(profile.full_name) : 'transparent'">
                  <img *ngIf="profile.avatar_url" [src]="profile.avatar_url" class="w-100 h-100 object-fit-cover" alt="Avatar">
                  <span *ngIf="!profile.avatar_url" class="avatar-letter">{{ profile.full_name.charAt(0).toUpperCase() }}</span>
                </div>
                <button class="btn btn-sm btn-primary rounded-circle position-absolute bottom-0 end-0 shadow"
                        style="width:32px; height:32px; padding:0" (click)="avatarInput.click()" title="Change photo">
                  <i class="bi bi-camera-fill" style="font-size:14px"></i>
                </button>
                <input #avatarInput type="file" accept="image/jpeg,image/png,image/gif,image/webp" class="d-none"
                       (change)="onAvatarSelected($event)">
              </div>
              <div class="pb-1">
                <h5 class="fw-bold mb-0">{{ profile.full_name }}</h5>
                <p class="text-muted mb-0 small">{{ profile.email }}</p>
                <div class="mt-1">
                  <span *ngFor="let r of profile.roles" class="badge bg-primary-subtle text-primary me-1 small">{{ r.role_name }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Avatar crop/preview modal -->
        <div class="modal fade show d-block" *ngIf="avatarPreview" style="background:rgba(0,0,0,.6)">
          <div class="modal-dialog modal-sm modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header py-2">
                <h6 class="modal-title">Update Profile Picture</h6>
                <button class="btn-close" (click)="cancelAvatarUpload()"></button>
              </div>
              <div class="modal-body text-center">
                <img [src]="avatarPreview" class="rounded-circle mb-3" style="width:200px; height:200px; object-fit:cover" alt="Preview">
                <p class="small text-muted mb-0">{{ avatarFile?.name }}</p>
              </div>
              <div class="modal-footer py-2">
                <button class="btn btn-sm btn-secondary" (click)="cancelAvatarUpload()">Cancel</button>
                <button class="btn btn-sm btn-danger" *ngIf="profile.avatar_url" (click)="removeAvatar()">
                  <i class="bi bi-trash me-1"></i>Remove
                </button>
                <button class="btn btn-sm btn-primary" (click)="uploadAvatar()" [disabled]="uploadingAvatar">
                  <span *ngIf="uploadingAvatar" class="spinner-border spinner-border-sm me-1"></span>
                  Upload
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4">
          <li class="nav-item" *ngFor="let tab of tabs">
            <a class="nav-link" [class.active]="activeTab === tab.key" role="button"
               (click)="activeTab = tab.key">
              <i class="bi me-1" [class]="tab.icon"></i>{{ tab.label }}
            </a>
          </li>
        </ul>

        <!-- ═══════ PROFILE TAB ═══════ -->
        <div *ngIf="activeTab === 'profile'">
          <div class="row g-4">
            <div class="col-md-6">
              <div class="card border-0 shadow-sm">
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                  <span class="fw-semibold"><i class="bi bi-person me-2"></i>Personal Information</span>
                  <button class="btn btn-sm" [class]="editingProfile ? 'btn-secondary' : 'btn-primary'"
                          (click)="editingProfile = !editingProfile">
                    <i class="bi" [class]="editingProfile ? 'bi-x' : 'bi-pencil'"></i>
                    {{ editingProfile ? 'Cancel' : 'Edit' }}
                  </button>
                </div>
                <div class="card-body">
                  <!-- View mode -->
                  <div *ngIf="!editingProfile">
                    <div class="row mb-3" *ngFor="let f of personalInfo">
                      <div class="col-5 text-muted small">{{ f.label }}</div>
                      <div class="col-7 small fw-medium">{{ f.value || '-' }}</div>
                    </div>
                  </div>
                  <!-- Edit mode -->
                  <div *ngIf="editingProfile">
                    <div class="mb-3">
                      <label class="form-label small fw-semibold">First Name</label>
                      <input type="text" class="form-control form-control-sm" [(ngModel)]="profileForm.first_name">
                    </div>
                    <div class="mb-3">
                      <label class="form-label small fw-semibold">Last Name</label>
                      <input type="text" class="form-control form-control-sm" [(ngModel)]="profileForm.last_name">
                    </div>
                    <div class="mb-3">
                      <label class="form-label small fw-semibold">Phone</label>
                      <input type="text" class="form-control form-control-sm" [(ngModel)]="profileForm.phone">
                    </div>
                    <div class="mb-3">
                      <label class="form-label small fw-semibold">Language</label>
                      <select class="form-select form-select-sm" [(ngModel)]="profileForm.language">
                        <option value="en">English</option>
                        <option value="fr">French</option>
                        <option value="ar">Arabic</option>
                      </select>
                    </div>
                    <div class="mb-3">
                      <label class="form-label small fw-semibold">Preferred Learning Mode</label>
                      <select class="form-select form-select-sm" [(ngModel)]="profileForm.preferred_learning_mode">
                        <option value="">No preference</option>
                        <option value="video">Video</option>
                        <option value="audio">Audio</option>
                        <option value="reading">Reading</option>
                        <option value="interactive">Interactive</option>
                      </select>
                    </div>
                    <div class="d-flex gap-2">
                      <button class="btn btn-primary btn-sm" (click)="saveProfile()" [disabled]="savingProfile">
                        <span *ngIf="savingProfile" class="spinner-border spinner-border-sm me-1"></span>
                        Save Changes
                      </button>
                      <button class="btn btn-secondary btn-sm" (click)="editingProfile = false">Cancel</button>
                    </div>
                    <div class="alert alert-success py-1 small mt-2" *ngIf="profileSuccess">{{ profileSuccess }}</div>
                    <div class="alert alert-danger py-1 small mt-2" *ngIf="profileError">{{ profileError }}</div>
                  </div>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <!-- Organization (read-only) -->
              <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-building me-2"></i>Organization</div>
                <div class="card-body">
                  <div class="row mb-3" *ngFor="let f of orgInfo">
                    <div class="col-5 text-muted small">{{ f.label }}</div>
                    <div class="col-7 small fw-medium">{{ f.value || '-' }}</div>
                  </div>
                  <small class="text-muted fst-italic">Organization info can only be changed by your administrator.</small>
                </div>
              </div>

              <!-- Account info -->
              <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle me-2"></i>Account</div>
                <div class="card-body">
                  <div class="row mb-3" *ngFor="let f of accountInfo">
                    <div class="col-5 text-muted small">{{ f.label }}</div>
                    <div class="col-7 small fw-medium">{{ f.value || '-' }}</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ═══════ SECURITY TAB ═══════ -->
        <div *ngIf="activeTab === 'security'">
          <div class="row g-4">
            <div class="col-md-6">
              <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-key me-2"></i>Change Password</div>
                <div class="card-body">
                  <div class="mb-3">
                    <label class="form-label small fw-semibold">Current Password</label>
                    <div class="input-group input-group-sm">
                      <input [type]="showCurrentPw ? 'text' : 'password'" class="form-control" [(ngModel)]="pwForm.current_password">
                      <button class="btn btn-secondary" type="button" (click)="showCurrentPw = !showCurrentPw">
                        <i class="bi" [class]="showCurrentPw ? 'bi-eye-slash' : 'bi-eye'"></i>
                      </button>
                    </div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label small fw-semibold">New Password</label>
                    <div class="input-group input-group-sm">
                      <input [type]="showNewPw ? 'text' : 'password'" class="form-control" [(ngModel)]="pwForm.new_password"
                             (ngModelChange)="validatePassword()">
                      <button class="btn btn-secondary" type="button" (click)="showNewPw = !showNewPw">
                        <i class="bi" [class]="showNewPw ? 'bi-eye-slash' : 'bi-eye'"></i>
                      </button>
                    </div>
                    <!-- Strength indicator -->
                    <div class="mt-2" *ngIf="pwForm.new_password">
                      <div class="d-flex gap-1 mb-1">
                        <div *ngFor="let s of [1,2,3,4]" class="flex-grow-1" style="height:4px; border-radius:2px"
                             [style.background-color]="s <= pwStrength.score ? pwStrength.color : '#e9ecef'"></div>
                      </div>
                      <small [style.color]="pwStrength.color">{{ pwStrength.label }}</small>
                    </div>
                  </div>
                  <div class="mb-3">
                    <label class="form-label small fw-semibold">Confirm New Password</label>
                    <input type="password" class="form-control form-control-sm" [(ngModel)]="pwForm.confirm_password">
                    <small class="text-danger" *ngIf="pwForm.confirm_password && pwForm.confirm_password !== pwForm.new_password">
                      Passwords do not match
                    </small>
                  </div>
                  <button class="btn btn-primary btn-sm" (click)="changePassword()"
                          [disabled]="changingPw || !canChangePassword">
                    <span *ngIf="changingPw" class="spinner-border spinner-border-sm me-1"></span>
                    <i *ngIf="!changingPw" class="bi bi-shield-check me-1"></i>Change Password
                  </button>
                  <div class="alert alert-success py-1 small mt-2" *ngIf="pwSuccess">{{ pwSuccess }}</div>
                  <div class="alert alert-danger py-1 small mt-2" *ngIf="pwError">{{ pwError }}</div>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <!-- Login History -->
              <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history me-2"></i>Recent Login Activity</div>
                <div class="card-body p-0">
                  <div *ngIf="loadingHistory" class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div></div>
                  <div class="list-group list-group-flush" *ngIf="!loadingHistory">
                    <div *ngFor="let h of loginHistory" class="list-group-item py-2">
                      <div class="d-flex justify-content-between align-items-start">
                        <div>
                          <span class="badge me-2" [class]="h.action === 'login' ? 'bg-success' : h.action === 'password_changed' ? 'bg-warning text-dark' : 'bg-secondary'">
                            {{ formatAction(h.action) }}
                          </span>
                          <small class="text-muted">{{ h.created_at | date:'medium' }}</small>
                        </div>
                        <small class="text-muted font-monospace">{{ h.ip_address || '-' }}</small>
                      </div>
                      <small class="text-muted d-block mt-1" style="font-size:.7rem" *ngIf="h.user_agent">
                        {{ truncateUA(h.user_agent) }}
                      </small>
                    </div>
                    <div *ngIf="!loginHistory.length" class="list-group-item text-center text-muted py-3">No login activity</div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ═══════ PREFERENCES TAB ═══════ -->
        <div *ngIf="activeTab === 'preferences'">
          <div class="row g-4">
            <div class="col-md-6">
              <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-bell me-2"></i>Data & Privacy</div>
                <div class="card-body">
                  <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="privacyConsent"
                           [checked]="consentForm.privacy_consent" (change)="consentForm.privacy_consent = $any($event.target).checked ? 1 : 0">
                    <label class="form-check-label small" for="privacyConsent">
                      <strong>Privacy Consent</strong><br>
                      <span class="text-muted">I agree to the platform's privacy policy and data handling practices.</span>
                    </label>
                  </div>
                  <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="marketingConsent"
                           [checked]="consentForm.marketing_consent" (change)="consentForm.marketing_consent = $any($event.target).checked ? 1 : 0">
                    <label class="form-check-label small" for="marketingConsent">
                      <strong>Marketing Communications</strong><br>
                      <span class="text-muted">Receive notifications about new courses, features, and updates.</span>
                    </label>
                  </div>
                  <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="dataConsent"
                           [checked]="consentForm.data_processing_consent" (change)="consentForm.data_processing_consent = $any($event.target).checked ? 1 : 0">
                    <label class="form-check-label small" for="dataConsent">
                      <strong>Data Processing</strong><br>
                      <span class="text-muted">Allow processing of my learning data for personalized recommendations.</span>
                    </label>
                  </div>
                  <button class="btn btn-primary btn-sm" (click)="saveConsent()" [disabled]="savingConsent">
                    <span *ngIf="savingConsent" class="spinner-border spinner-border-sm me-1"></span>
                    Save Preferences
                  </button>
                  <div class="alert alert-success py-1 small mt-2" *ngIf="consentSuccess">{{ consentSuccess }}</div>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <!-- Roles & Permissions (read-only) -->
              <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-shield me-2"></i>My Roles & Permissions</div>
                <div class="card-body">
                  <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Assigned Roles</label>
                    <div>
                      <span *ngFor="let r of profile.roles" class="badge bg-primary me-1 mb-1">{{ r.role_name }}</span>
                      <span *ngIf="!profile.roles?.length" class="text-muted small">No roles assigned</span>
                    </div>
                  </div>
                  <div>
                    <label class="form-label small fw-semibold text-muted">Effective Permissions ({{ profile.permissions?.length || 0 }})</label>
                    <div style="max-height:200px; overflow-y:auto">
                      <div *ngFor="let p of profile.permissions" class="d-flex align-items-center gap-2 mb-1">
                        <i class="bi bi-check-circle-fill text-success" style="font-size:.7rem"></i>
                        <small class="font-monospace text-muted">{{ p }}</small>
                      </div>
                    </div>
                    <small class="text-muted fst-italic mt-2 d-block">Roles and permissions are managed by your administrator.</small>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
      </div>
    </main>
  `,
  styles: [`
    .profile-banner { height: 120px; background: linear-gradient(135deg, #4e79a7 0%, #59a14f 100%); }
    .avatar-xl { width: 90px; height: 90px; display: flex; align-items: center; justify-content: center; background-size: cover; }
    .avatar-letter { color: #fff; font-size: 36px; font-weight: 700; }
    .object-fit-cover { object-fit: cover; }
    .nav-link { cursor: pointer; }
    .modal.fade { display: block; opacity: 1; }
  `]
})
export class MyAccountComponent implements OnInit {
  @ViewChild('avatarInput') avatarInputRef!: ElementRef<HTMLInputElement>;

  profile: any = null;
  loading = true;
  activeTab = 'profile';

  tabs = [
    { key: 'profile', label: 'Profile', icon: 'bi-person' },
    { key: 'security', label: 'Security', icon: 'bi-shield-lock' },
    { key: 'preferences', label: 'Preferences', icon: 'bi-gear' }
  ];

  // Profile edit
  editingProfile = false;
  profileForm: any = {};
  savingProfile = false;
  profileSuccess = '';
  profileError = '';

  personalInfo: { label: string; value: string }[] = [];
  orgInfo: { label: string; value: string }[] = [];
  accountInfo: { label: string; value: string }[] = [];

  // Avatar
  avatarPreview: string | null = null;
  avatarFile: File | null = null;
  uploadingAvatar = false;

  // Password
  pwForm = { current_password: '', new_password: '', confirm_password: '' };
  showCurrentPw = false;
  showNewPw = false;
  changingPw = false;
  pwSuccess = '';
  pwError = '';
  pwStrength = { score: 0, label: '', color: '#e9ecef' };

  // Consent
  consentForm = { privacy_consent: 0, marketing_consent: 0, data_processing_consent: 0 };
  savingConsent = false;
  consentSuccess = '';

  // Login history
  loginHistory: any[] = [];
  loadingHistory = false;
  private historyLoaded = false;

  constructor(private svc: UserService, private router: Router,) {}

  ngOnInit(): void { this.loadProfile(); }

  loadProfile(): void {
    this.loading = true;
    this.svc.getMyProfile().subscribe({
      next: p => {
        this.profile = p;
        this.buildInfoCards(p);
        this.profileForm = {
          first_name: p.first_name, last_name: p.last_name || '',
          phone: p.phone || '', language: p.language || 'en',
          preferred_learning_mode: p.preferred_learning_mode || ''
        };
        this.consentForm = {
          privacy_consent: p.privacy_consent || 0,
          marketing_consent: p.marketing_consent || 0,
          data_processing_consent: p.data_processing_consent || 0
        };
        this.loading = false;
      },
      error: () => { this.loading = false; }
    });
  }

  private buildInfoCards(p: any): void {
    this.personalInfo = [
      { label: 'First Name', value: p.first_name },
      { label: 'Last Name', value: p.last_name || '' },
      { label: 'Email', value: p.email },
      { label: 'Phone', value: p.phone || '' },
      { label: 'Staff ID', value: p.staffId || '' },
      { label: 'Language', value: { en: 'English', fr: 'French', ar: 'Arabic' }[p.language as string] || p.language || '' },
      { label: 'Learning Mode', value: p.preferred_learning_mode || '' },
    ];
    this.orgInfo = [
      { label: 'Department', value: p.department_name || '' },
      { label: 'Team', value: p.team_name || '' },
      { label: 'Branch', value: p.branch_name || '' },
      { label: 'Manager', value: p.manager_name || '' },
      { label: 'Hire Date', value: p.hire_date || '' },
    ];
    this.accountInfo = [
      { label: 'Last Login', value: p.last_login_at || 'Never' },
      { label: 'Login Count', value: String(p.login_count || 0) },
      { label: 'Joined', value: p.created || '' },
    ];
  }

  /* ═══════ PROFILE EDIT ═══════ */

  saveProfile(): void {
    this.savingProfile = true;
    this.profileSuccess = '';
    this.profileError = '';
    this.svc.updateProfile(this.profileForm).subscribe({
      next: (res: any) => {
        this.savingProfile = false;
        const r = res.data || res;
        if (r.success === false) { this.profileError = r.error || 'Failed'; return; }
        this.profileSuccess = 'Profile updated successfully';
        this.editingProfile = false;
        this.loadProfile();
        setTimeout(() => this.profileSuccess = '', 3000);
      },
      error: () => { this.savingProfile = false; this.profileError = 'Failed to update profile'; }
    });
  }

  /* ═══════ AVATAR ═══════ */

  onAvatarSelected(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) { alert('Image must be under 2MB'); return; }
    this.avatarFile = file;
    const reader = new FileReader();
    reader.onload = () => this.avatarPreview = reader.result as string;
    reader.readAsDataURL(file);
  }

  uploadAvatar(): void {
    if (!this.avatarFile) return;
    this.uploadingAvatar = true;
    this.svc.uploadAvatar(this.avatarFile).subscribe({
      next: (res: any) => {
        this.uploadingAvatar = false;
        const r = res.data || res;
        if (r.success === false) { alert(r.error || 'Upload failed'); return; }
        this.profile.avatar_url = r.avatar_url;
        this.cancelAvatarUpload();
      },
      error: () => { this.uploadingAvatar = false; alert('Upload failed'); }
    });
  }

  removeAvatar(): void {
    if (!confirm('Remove your profile picture?')) return;
    this.svc.removeAvatar().subscribe({
      next: () => { this.profile.avatar_url = null; this.cancelAvatarUpload(); },
      error: () => alert('Failed to remove avatar')
    });
  }

  cancelAvatarUpload(): void {
    this.avatarPreview = null;
    this.avatarFile = null;
    if (this.avatarInputRef) this.avatarInputRef.nativeElement.value = '';
  }

  /* ═══════ PASSWORD ═══════ */

  get canChangePassword(): boolean {
    return !!this.pwForm.current_password && !!this.pwForm.new_password
      && this.pwForm.new_password.length >= 8
      && this.pwForm.new_password === this.pwForm.confirm_password;
  }

  validatePassword(): void {
    const pw = this.pwForm.new_password;
    let score = 0;
    if (pw.length >= 8) score++;
    if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) score++;
    if (/\d/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;

    const levels: [string, string][] = [['Weak', '#dc3545'], ['Fair', '#fd7e14'], ['Good', '#ffc107'], ['Strong', '#198754']];
    const [label, color] = levels[Math.max(0, score - 1)] || levels[0];
    this.pwStrength = { score, label, color };
  }

  changePassword(): void {
    this.changingPw = true;
    this.pwSuccess = '';
    this.pwError = '';
    this.svc.changePassword(this.pwForm.current_password, this.pwForm.new_password).subscribe({
      next: (res: any) => {
        this.changingPw = false;
        const r = res.data || res;
        if (r.success === false) { this.pwError = r.error || 'Failed'; return; }
        this.pwSuccess = 'Password changed successfully';
        this.pwForm = { current_password: '', new_password: '', confirm_password: '' };
        this.pwStrength = { score: 0, label: '', color: '#e9ecef' };
        setTimeout(() => this.pwSuccess = '', 5000);
      },
      error: (e: any) => { this.changingPw = false; this.pwError = e?.error?.data?.error || 'Failed to change password'; }
    });
  }

  /* ═══════ CONSENT ═══════ */

  saveConsent(): void {
    this.savingConsent = true;
    this.consentSuccess = '';
    this.svc.updateConsent(this.consentForm).subscribe({
      next: () => {
        this.savingConsent = false;
        this.consentSuccess = 'Preferences saved';
        setTimeout(() => this.consentSuccess = '', 3000);
      },
      error: () => { this.savingConsent = false; }
    });
  }

  /* ═══════ LOGIN HISTORY ═══════ */

  ngDoCheck(): void {
    if (this.activeTab === 'security' && !this.historyLoaded) {
      this.historyLoaded = true;
      this.loadingHistory = true;
      this.svc.getLoginHistory(20).subscribe({
        next: h => { this.loginHistory = h; this.loadingHistory = false; },
        error: () => { this.loadingHistory = false; }
      });
    }
  }

  /* ═══════ HELPERS ═══════ */

  getColor(name: string): string {
    const colors = ['#4e79a7','#f28e2b','#e15759','#76b7b2','#59a14f','#edc948','#b07aa1'];
    let hash = 0;
    for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return colors[Math.abs(hash) % colors.length];
  }

  formatAction(a: string): string { return a.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()); }

  truncateUA(ua: string): string { return ua.length > 80 ? ua.substring(0, 80) + '...' : ua; }

  goBack() {
    this.router.navigate(['/account']).then(r => console.log(r));
  }
}
