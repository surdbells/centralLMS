import { Component, ElementRef, OnDestroy, OnInit, ViewChild, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { TuiIcon } from '@taiga-ui/core';
import { Subject, forkJoin } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { MyProfileService, type Consents } from '../services/my-profile.service';
import { UsersService } from '../services/users.service';
import { RolesService } from '../services/roles.service';
import { SessionStore } from '../core/auth/session.store';
import { ApiError, AuthImgDirective } from '../core/api';
import { TopComponent } from '../partials/top/top.component';
import { HotToastService } from '@ngneat/hot-toast';
import { ConfirmDialogService } from '../shared/components/confirmation-dialog/confirmation-dialog.component';

/* The shapes this screen's template already reads. */

export interface ProfileRole {
  role_name: string;
}

export interface Profile {
  full_name: string;
  email: string;
  /** The API's URL, made absolute. Not the base64 the legacy inlined. */
  avatar_url: string | null;
  roles: ProfileRole[];
  permissions: string[];
}

/**
 * One sign-in, in the shape the template's list already reads.
 *
 * The "login history" is now the account's own sessions — every refresh token
 * records when it was issued, from what browser and from what address. The
 * legacy read `user_activity_log` for this, which was the one thing that table
 * did have rows for, and it could not tell you a session was still live or let
 * you end it.
 */
/** The editable half of the profile, in the names the form already binds. */
export interface ProfileForm {
  first_name: string;
  last_name: string;
  phone: string;
  /** '' | 'male' | 'female' | 'other' | 'undisclosed'. */
  gender: string;
  language: string;
  /**
   * On the form and not stored: this system has no such field. Left in the
   * markup rather than quietly deleted, and saving simply does not send it.
   */
  preferred_learning_mode: string;
}

export interface LoginRecord {
  action: string;
  created_at: string;
  ip_address: string | null;
  user_agent: string | null;
  /** Whether this session can still be used. */
  active: boolean;
  id: string;
}

@Component({
  selector: 'app-my-account',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, TopComponent, TuiIcon, AuthImgDirective],
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
                  <img *ngIf="profile.avatar_url" [appAuthImg]="profile.avatar_url" class="w-100 h-100 object-fit-cover" alt="Avatar">
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
               (click)="onTabChange(tab.key)">
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
                      <label class="form-label small fw-semibold">Gender</label>
                      <select class="form-select form-select-sm" [(ngModel)]="profileForm.gender">
                        <option value="">Prefer not to say</option>
                        <option value="male">Male</option>
                        <option value="female">Female</option>
                        <option value="other">Other</option>
                        <option value="undisclosed">Undisclosed</option>
                      </select>
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
                        <div class="d-flex align-items-center gap-2">
                          <small class="text-muted font-monospace">{{ h.ip_address || '-' }}</small>
                          <button *ngIf="h.active" class="btn btn-sm btn-link text-danger p-0" style="font-size:.75rem"
                                  (click)="endSession(h)">End</button>
                        </div>
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
/**
 * Somebody's own account.
 *
 * The screen is unchanged. Every request behind it now acts on whoever holds
 * the token: there is no id in any of them. The version this replaces sent
 * `user_id` in the body of each call, because the endpoint took one and
 * checked nothing about whose it was — so any signed-in account could rewrite
 * anybody's name and phone number, in any organisation.
 *
 * The security tab's list is the account's own sessions rather than a log of
 * sign-ins. Those are the rows the system actually has, they say whether a
 * session is still live, and each one can be ended.
 */
export class MyAccountComponent implements OnInit, OnDestroy {
  private readonly profileService = inject(MyProfileService);
  private readonly usersService = inject(UsersService);
  private readonly rolesService = inject(RolesService);
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);
  private readonly toast = inject(HotToastService);
  private readonly dialog = inject(ConfirmDialogService);
  private readonly destroy$ = new Subject<void>();

  @ViewChild('avatarInput') avatarInputRef?: ElementRef<HTMLInputElement>;

  profile: Profile | null = null;
  loading = true;

  activeTab = 'profile';

  tabs = [
    { key: 'profile', label: 'Profile', icon: 'bi-person' },
    { key: 'security', label: 'Security', icon: 'bi-shield-lock' },
    { key: 'preferences', label: 'Preferences', icon: 'bi-sliders' },
  ];

  personalInfo: Array<{ label: string; value: string }> = [];
  orgInfo: Array<{ label: string; value: string }> = [];
  accountInfo: Array<{ label: string; value: string }> = [];

  // Profile
  editingProfile = false;
  profileForm: ProfileForm = {
    first_name: '',
    last_name: '',
    phone: '',
    gender: '',
    language: 'en',
    preferred_learning_mode: '',
  };
  savingProfile = false;
  profileSuccess = '';
  profileError = '';

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

  // Sessions, under the name the template's list uses
  loginHistory: LoginRecord[] = [];
  loadingHistory = false;
  private historyLoaded = false;

  ngOnInit(): void {
    this.loadProfile();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadProfile(): void {
    this.loading = true;

    forkJoin({
      me: this.profileService.show(),
      consents: this.profileService.consents(),
      roles: this.rolesService.list(),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ me, consents, roles }) => {
          const mine = roles.filter((role) => me.roleIds.includes(role.id));

          this.profile = {
            full_name: me.fullName,
            email: me.email,
            // The base-relative path; AuthImgDirective fetches it with the token.
            avatar_url: me.avatarUrl,
            roles: mine.map((role) => ({ role_name: role.name })),
            // The union of what their roles allow, worked out here from the
            // role list rather than fetched as a field that recomputed it.
            permissions: [...new Set(mine.flatMap((role) => role.permissions))].sort(),
          };

          this.buildInfoCards(me);

          this.profileForm = {
            first_name: me.firstName,
            last_name: me.lastName,
            phone: me.phone ?? '',
            gender: me.gender ?? '',
            language: me.locale,
            preferred_learning_mode: '',
          };

          this.consentForm = {
            privacy_consent: consents.privacy ? 1 : 0,
            marketing_consent: consents.marketing ? 1 : 0,
            data_processing_consent: consents.dataProcessing ? 1 : 0,
          };

          this.loading = false;
        },
        error: (e: ApiError) => {
          this.profileError = e.message;
          this.loading = false;
        },
      });
  }

  private buildInfoCards(me: {
    firstName: string; lastName: string; email: string; phone: string | null; locale: string;
    gender: string | null; employeeId: string | null;
    hireDate: string | null; lastLoginAt: string | null; loginCount: number; createdAt: string | null;
    placement?: { branchName: string | null; departmentName: string | null; teamName: string | null;
      levelName: string | null; jobTitleName: string | null; managerName: string | null;
      lineExecutiveName: string | null } | null;
  }): void {
    this.personalInfo = [
      { label: 'First Name', value: me.firstName },
      { label: 'Last Name', value: me.lastName },
      { label: 'Email', value: me.email },
      { label: 'Phone', value: me.phone ?? '' },
      { label: 'Gender', value: this.genderLabel(me.gender) },
      {
        label: 'Language',
        value: ({ en: 'English', fr: 'French', ar: 'Arabic' } as Record<string, string>)[me.locale]
          ?? me.locale,
      },
    ];

    // Everything an administrator sets: the whole org side of the profile,
    // including the employee number and both reporting lines.
    this.orgInfo = [
      { label: 'Employee ID', value: me.employeeId ?? '' },
      { label: 'Job Title', value: me.placement?.jobTitleName ?? '' },
      { label: 'Department', value: me.placement?.departmentName ?? '' },
      { label: 'Job Grade', value: me.placement?.levelName ?? '' },
      { label: 'Team', value: me.placement?.teamName ?? '' },
      { label: 'Work Location', value: me.placement?.branchName ?? '' },
      { label: 'Line Manager', value: me.placement?.managerName ?? '' },
      { label: 'Line Executive', value: me.placement?.lineExecutiveName ?? '' },
      { label: 'Hire Date', value: this.onDay(me.hireDate) },
    ];

    this.accountInfo = [
      { label: 'Last Login', value: me.lastLoginAt === null ? 'Never' : this.at(me.lastLoginAt) },
      { label: 'Login Count', value: String(me.loginCount) },
      { label: 'Joined', value: this.onDay(me.createdAt) },
    ];
  }

  /**
   * A timestamp as a person reads one.
   *
   * The API sends every instant as ISO 8601 in UTC, which is right for a
   * wire format and wrong to put on a card. These two are the only place
   * that changes, in the reader's own locale and zone.
   */
  private at(value: string | null): string {
    if (value === null || value === '') {
      return '';
    }

    return new Date(value).toLocaleString();
  }

  private onDay(value: string | null): string {
    if (value === null || value === '') {
      return '';
    }

    return new Date(value).toLocaleDateString(undefined, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
    });
  }

  /* ═══════ PROFILE EDIT ═══════ */

  saveProfile(): void {
    this.savingProfile = true;
    this.profileSuccess = '';
    this.profileError = '';

    this.profileService
      .update({
        firstName: this.profileForm.first_name,
        lastName: this.profileForm.last_name,
        phone: this.profileForm.phone || null,
        gender: this.profileForm.gender || null,
        locale: this.profileForm.language,
      })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.savingProfile = false;
          this.profileSuccess = 'Profile updated successfully';
          this.editingProfile = false;
          this.loadProfile();
          setTimeout(() => (this.profileSuccess = ''), 3000);
        },
        error: (e: ApiError) => {
          this.savingProfile = false;
          this.profileError = Object.values(e.violations).flat()[0] ?? e.message;
        },
      });
  }

  /* ═══════ AVATAR ═══════ */

  onAvatarSelected(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (file === undefined) {
      return;
    }

    // Checked here as a courtesy; the server checks the bytes it actually
    // received, which is the number that counts.
    if (file.size > 2 * 1024 * 1024) {
      this.toast.error('Image must be under 2MB');

      return;
    }

    this.avatarFile = file;

    const reader = new FileReader();
    reader.onload = () => (this.avatarPreview = reader.result as string);
    reader.readAsDataURL(file);
  }

  uploadAvatar(): void {
    const file = this.avatarFile;

    if (file === null) {
      return;
    }

    this.uploadingAvatar = true;

    this.profileService
      .uploadAvatar(file)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (me) => {
          this.uploadingAvatar = false;

          if (this.profile !== null) {
            // The URL carries a checksum of the bytes, so the browser stops
            // showing the previous picture.
            this.profile.avatar_url = me.avatarUrl;
          }

          this.cancelAvatarUpload();
        },
        error: (e: ApiError) => {
          this.uploadingAvatar = false;
          this.toast.error(e.violation('file') ?? e.message);
        },
      });
  }

  async removeAvatar(): Promise<void> {
    if (!(await this.dialog.confirm('Remove your profile picture?', { type: 'danger' }))) {
      return;
    }

    this.profileService
      .removeAvatar()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          if (this.profile !== null) {
            this.profile.avatar_url = null;
          }

          this.cancelAvatarUpload();
        },
        error: (e: ApiError) => this.toast.error(e.message),
      });
  }

  cancelAvatarUpload(): void {
    this.avatarPreview = null;
    this.avatarFile = null;

    if (this.avatarInputRef !== undefined) {
      this.avatarInputRef.nativeElement.value = '';
    }
  }

  /* ═══════ PASSWORD ═══════ */

  get canChangePassword(): boolean {
    return this.pwForm.current_password !== ''
      && this.pwForm.new_password.length >= 8
      && this.pwForm.new_password === this.pwForm.confirm_password;
  }

  validatePassword(): void {
    const pw = this.pwForm.new_password;
    let score = 0;

    // Twelve, not eight: that is the organisation's own minimum, and a meter
    // that turns green at eight teaches the wrong thing.
    if (pw.length >= 12) {
      score++;
    }

    if (/[A-Z]/.test(pw) && /[a-z]/.test(pw)) {
      score++;
    }

    if (/\d/.test(pw)) {
      score++;
    }

    if (/[^A-Za-z0-9]/.test(pw)) {
      score++;
    }

    const levels: Array<[string, string]> = [
      ['Weak', '#dc3545'],
      ['Fair', '#fd7e14'],
      ['Good', '#ffc107'],
      ['Strong', '#198754'],
    ];

    const [label, color] = levels[Math.max(0, score - 1)] ?? levels[0];
    this.pwStrength = { score, label, color };
  }

  /**
   * Changes the password, through the same route an administrator resetting
   * one uses. The server tells the two apart from who is asking, and asks for
   * the current password when it is somebody changing their own.
   */
  changePassword(): void {
    const userId = this.session.userId();

    if (userId === null) {
      return;
    }

    this.changingPw = true;
    this.pwSuccess = '';
    this.pwError = '';

    this.usersService
      .setPassword(userId, this.pwForm.new_password, this.pwForm.current_password)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.changingPw = false;
          this.pwSuccess = 'Password changed successfully';
          this.pwForm = { current_password: '', new_password: '', confirm_password: '' };
          this.pwStrength = { score: 0, label: '', color: '#e9ecef' };
          setTimeout(() => (this.pwSuccess = ''), 5000);
        },
        error: (e: ApiError) => {
          this.changingPw = false;
          this.pwError = e.violation('password')
            ?? e.violation('currentPassword')
            ?? e.message;
        },
      });
  }

  /* ═══════ CONSENT ═══════ */

  saveConsent(): void {
    this.savingConsent = true;
    this.consentSuccess = '';

    const consents: Consents = {
      privacy: this.consentForm.privacy_consent === 1,
      marketing: this.consentForm.marketing_consent === 1,
      dataProcessing: this.consentForm.data_processing_consent === 1,
    };

    // The whole set every time: an unticked box is a consent withdrawn, not
    // one merely left out.
    this.profileService
      .saveConsents(consents)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.savingConsent = false;
          this.consentSuccess = 'Preferences saved';
          setTimeout(() => (this.consentSuccess = ''), 3000);
        },
        error: (e: ApiError) => {
          this.savingConsent = false;
          this.toast.error(e.message);
        },
      });
  }

  /* ═══════ SESSIONS ═══════ */

  /**
   * Loaded when the tab is opened.
   *
   * `ngDoCheck` used to fire this — on every change detection pass, guarded
   * only by a flag, which is a request waiting for the guard to be wrong.
   */
  onTabChange(key: string): void {
    this.activeTab = key;

    if (key === 'security' && !this.historyLoaded) {
      this.historyLoaded = true;
      this.loadHistory();
    }
  }

  loadHistory(): void {
    this.loadingHistory = true;

    this.profileService
      .sessions()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (sessions) => {
          this.loginHistory = sessions.map((s) => ({
            id: s.id,
            action: s.active ? 'login' : 'session_ended',
            created_at: s.issuedAt,
            ip_address: s.ipAddress,
            user_agent: s.userAgent,
            active: s.active,
          }));
          this.loadingHistory = false;
        },
        error: () => {
          this.loginHistory = [];
          this.loadingHistory = false;
        },
      });
  }

  /** Ends one session. Not something the old screen could do. */
  async endSession(record: LoginRecord): Promise<void> {
    if (!record.active) {
      return;
    }

    if (!(await this.dialog.confirm('Sign out of this session?', { type: 'warning' }))) {
      return;
    }

    this.profileService
      .revokeSession(record.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.loadHistory(),
        error: (e: ApiError) => this.toast.error(e.message),
      });
  }

  /* ═══════ HELPERS ═══════ */

  /** A stored gender token as a label; empty stays empty, so the card shows '-'. */
  genderLabel(value: string | null): string {
    return ({
      male: 'Male',
      female: 'Female',
      other: 'Other',
      undisclosed: 'Undisclosed',
    } as Record<string, string>)[value ?? ''] ?? '';
  }

  getColor(name: string): string {
    const colors = ['#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f', '#edc948', '#b07aa1'];

    let hash = 0;

    for (let i = 0; i < name.length; i++) {
      hash = name.charCodeAt(i) + ((hash << 5) - hash);
    }

    return colors[Math.abs(hash) % colors.length];
  }

  formatAction(a: string): string {
    return a.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
  }

  /** A user-agent string, as the device it describes. */
  truncateUA(ua: string | null): string {
    if (ua === null || ua === '') {
      return '-';
    }

    const browser = /Edg\//.test(ua) ? 'Edge'
      : /Chrome\//.test(ua) ? 'Chrome'
      : /Safari\//.test(ua) ? 'Safari'
      : /Firefox\//.test(ua) ? 'Firefox'
      : 'Browser';

    const platform = /Android/.test(ua) ? 'Android'
      : /iPhone|iPad/.test(ua) ? 'iOS'
      : /Windows/.test(ua) ? 'Windows'
      : /Mac OS/.test(ua) ? 'macOS'
      : /Linux/.test(ua) ? 'Linux'
      : '';

    return platform === '' ? browser : `${browser} on ${platform}`;
  }

  goBack(): void {
    void this.router.navigate(['/account']);
  }
}
