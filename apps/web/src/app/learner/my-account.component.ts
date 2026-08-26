import { Component, ElementRef, OnDestroy, OnInit, ViewChild, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, forkJoin } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { TuiIcon } from '@taiga-ui/core';
import {
  MyProfileService,
  type Consents,
  type ProfileInput,
  type SignInSession,
} from '../services/my-profile.service';
import { UsersService, type User } from '../services/users.service';
import { SessionStore } from '../core/auth/session.store';
import { ApiClient, ApiError, Endpoints } from '../core/api';
import { TopComponent } from '../partials/top/top.component';

type Tab = 'profile' | 'security' | 'preferences';

interface Field {
  label: string;
  value: string;
}

/**
 * Somebody's own account.
 *
 * Every request this screen makes is about the person holding the token —
 * there is no id anywhere in it. The screen it replaces sent `user_id` in the
 * body of each call, because the endpoint behind it took one and checked
 * nothing about whose it was.
 *
 * The security tab lists sign-ins rather than an activity log. Those are the
 * rows the system actually has — every refresh token records when, from what
 * browser and from what address — and they answer the question somebody opens
 * this tab to ask: am I signed in anywhere I did not expect? Each one can be
 * ended from here.
 */
@Component({
  selector: 'app-my-account',
  standalone: true,
  imports: [CommonModule, FormsModule, TopComponent, TuiIcon],
  templateUrl: './my-account.component.html',
  styles: [`
    .profile-banner { height: 120px; background: linear-gradient(135deg, #303234, #5a5d61); }
    .avatar-xl { width: 96px; height: 96px; display: flex; align-items: center; justify-content: center; }
    .avatar-letter { font-size: 40px; font-weight: 700; color: #fff; }
    .nav-link { cursor: pointer; }
  `],
})
export class MyAccountComponent implements OnInit, OnDestroy {
  private readonly profileService = inject(MyProfileService);
  private readonly usersService = inject(UsersService);
  private readonly session = inject(SessionStore);
  private readonly api = inject(ApiClient);
  private readonly router = inject(Router);
  private readonly destroy$ = new Subject<void>();

  @ViewChild('avatarInput') avatarInputRef?: ElementRef<HTMLInputElement>;

  profile: User | null = null;
  consents: Consents = { privacy: false, marketing: false, dataProcessing: false };
  sessions: SignInSession[] = [];

  loading = true;
  error: string | null = null;

  activeTab: Tab = 'profile';

  readonly tabs: Array<{ key: Tab; label: string; icon: string }> = [
    { key: 'profile', label: 'Profile', icon: 'bi-person' },
    { key: 'security', label: 'Security', icon: 'bi-shield-lock' },
    { key: 'preferences', label: 'Preferences', icon: 'bi-sliders' },
  ];

  // Profile
  editingProfile = false;
  form: ProfileInput = {};
  savingProfile = false;
  profileSuccess = '';
  profileViolations: Record<string, string> = {};

  personalInfo: Field[] = [];
  orgInfo: Field[] = [];
  accountInfo: Field[] = [];

  // Avatar
  avatarPreview: string | null = null;
  avatarFile: File | null = null;
  uploadingAvatar = false;
  avatarError = '';

  // Password
  password = { current: '', next: '', confirm: '' };
  showCurrent = false;
  showNext = false;
  changingPassword = false;
  passwordSuccess = '';
  passwordError = '';
  strength = { score: 0, label: '', colour: '#e9ecef' };

  // Consents
  savingConsents = false;
  consentSuccess = '';

  // Sessions
  loadingSessions = false;

  ngOnInit(): void {
    this.load();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  private load(): void {
    this.loading = true;

    forkJoin({ profile: this.profileService.show(), consents: this.profileService.consents() })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ profile, consents }) => {
          this.apply(profile);
          this.consents = consents;
          this.loading = false;
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.loading = false;
        },
      });
  }

  private apply(profile: User): void {
    this.profile = profile;

    this.form = {
      firstName: profile.firstName,
      lastName: profile.lastName,
      phone: profile.phone,
      locale: profile.locale,
    };

    this.personalInfo = [
      { label: 'First Name', value: profile.firstName },
      { label: 'Last Name', value: profile.lastName },
      { label: 'Email', value: profile.email },
      { label: 'Phone', value: profile.phone ?? '' },
      { label: 'Language', value: this.languageName(profile.locale) },
    ];

    this.orgInfo = [
      { label: 'Branch', value: profile.placement?.branchName ?? '' },
      { label: 'Department', value: profile.placement?.departmentName ?? '' },
      { label: 'Team', value: profile.placement?.teamName ?? '' },
      { label: 'Job Title', value: profile.placement?.jobTitleName ?? '' },
      { label: 'Manager', value: profile.placement?.managerName ?? '' },
      { label: 'Hire Date', value: profile.hireDate ?? '' },
    ];

    this.accountInfo = [
      { label: 'Last Signed In', value: profile.lastLoginAt ?? 'Never' },
      { label: 'Sign-ins', value: String(profile.loginCount) },
      { label: 'Joined', value: profile.createdAt ?? '' },
    ];
  }

  onTabChange(tab: Tab): void {
    this.activeTab = tab;

    if (tab === 'security' && this.sessions.length === 0) {
      this.loadSessions();
    }
  }

  // --- profile ------------------------------------------------------------

  saveProfile(): void {
    this.savingProfile = true;
    this.profileSuccess = '';
    this.profileViolations = {};
    this.error = null;

    this.profileService
      .update(this.form)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (profile) => {
          this.savingProfile = false;
          this.editingProfile = false;
          this.profileSuccess = 'Saved.';
          this.apply(profile);
        },
        error: (error: ApiError) => {
          this.savingProfile = false;
          this.profileViolations = Object.fromEntries(
            Object.entries(error.violations).map(([field, messages]) => [field, messages[0]]),
          );

          if (Object.keys(this.profileViolations).length === 0) {
            this.error = error.message;
          }
        },
      });
  }

  cancelProfileEdit(): void {
    this.editingProfile = false;
    this.profileViolations = {};

    if (this.profile !== null) {
      this.apply(this.profile);
    }
  }

  // --- avatar -------------------------------------------------------------

  /** The URL a browser fetches the picture from, made absolute. */
  get avatarSrc(): string | null {
    return this.profile?.avatarUrl == null ? null : this.api.urlFor(this.profile.avatarUrl);
  }

  onAvatarSelected(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    this.avatarError = '';

    if (file === undefined) {
      return;
    }

    // Checked here as a courtesy; the server checks the bytes it actually
    // received, which is the number that counts.
    if (file.size > 2 * 1024 * 1024) {
      this.avatarError = 'Keep the image under 2 MB.';

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
    this.avatarError = '';

    this.profileService
      .uploadAvatar(file)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (profile) => {
          this.uploadingAvatar = false;
          this.apply(profile);
          this.cancelAvatarUpload();
        },
        error: (error: ApiError) => {
          this.uploadingAvatar = false;
          this.avatarError = error.violation('file') ?? error.message;
        },
      });
  }

  removeAvatar(): void {
    if (!confirm('Remove your profile picture?')) {
      return;
    }

    this.profileService
      .removeAvatar()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (profile) => {
          this.apply(profile);
          this.cancelAvatarUpload();
        },
        error: (error: ApiError) => (this.avatarError = error.message),
      });
  }

  cancelAvatarUpload(): void {
    this.avatarPreview = null;
    this.avatarFile = null;

    if (this.avatarInputRef !== undefined) {
      this.avatarInputRef.nativeElement.value = '';
    }
  }

  // --- password -----------------------------------------------------------

  get canChangePassword(): boolean {
    return this.password.current !== ''
      && this.password.next.length >= 8
      && this.password.next === this.password.confirm;
  }

  gradePassword(): void {
    const value = this.password.next;
    let score = 0;

    if (value.length >= 12) {
      score++;
    }

    if (/[A-Z]/.test(value) && /[a-z]/.test(value)) {
      score++;
    }

    if (/\d/.test(value)) {
      score++;
    }

    if (/[^A-Za-z0-9]/.test(value)) {
      score++;
    }

    const levels: Array<[string, string]> = [
      ['Weak', '#dc3545'],
      ['Fair', '#fd7e14'],
      ['Good', '#ffc107'],
      ['Strong', '#198754'],
    ];

    const [label, colour] = levels[Math.max(0, score - 1)] ?? levels[0];

    this.strength = { score, label, colour };
  }

  /**
   * Changes the password, through the same route an administrator resetting
   * one uses. The server tells the two apart from who is asking, and demands
   * the current password when somebody is changing their own.
   */
  changePassword(): void {
    const userId = this.session.userId();

    if (userId === null) {
      return;
    }

    this.changingPassword = true;
    this.passwordSuccess = '';
    this.passwordError = '';

    this.usersService
      .setPassword(userId, this.password.next, this.password.current)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.changingPassword = false;
          this.passwordSuccess = 'Password changed.';
          this.password = { current: '', next: '', confirm: '' };
          this.strength = { score: 0, label: '', colour: '#e9ecef' };
        },
        error: (error: ApiError) => {
          this.changingPassword = false;
          this.passwordError = error.violation('password')
            ?? error.violation('currentPassword')
            ?? error.message;
        },
      });
  }

  // --- sessions -----------------------------------------------------------

  loadSessions(): void {
    this.loadingSessions = true;

    this.profileService
      .sessions()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (sessions) => {
          this.sessions = sessions;
          this.loadingSessions = false;
        },
        error: (error: ApiError) => {
          this.error = error.message;
          this.loadingSessions = false;
        },
      });
  }

  revokeSession(session: SignInSession): void {
    if (!confirm('Sign out of this session?')) {
      return;
    }

    this.profileService
      .revokeSession(session.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.loadSessions(),
        error: (error: ApiError) => (this.error = error.message),
      });
  }

  // --- consents -----------------------------------------------------------

  saveConsents(): void {
    this.savingConsents = true;
    this.consentSuccess = '';

    this.profileService
      .saveConsents(this.consents)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (consents) => {
          this.savingConsents = false;
          this.consents = consents;
          this.consentSuccess = 'Saved.';
        },
        error: (error: ApiError) => {
          this.savingConsents = false;
          this.error = error.message;
        },
      });
  }

  // --- presentation -------------------------------------------------------

  languageName(locale: string): string {
    return ({ en: 'English', fr: 'French', ar: 'Arabic' } as Record<string, string>)[locale] ?? locale;
  }

  avatarColour(name: string): string {
    const colours = ['#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f', '#edc948', '#b07aa1'];

    let hash = 0;

    for (let i = 0; i < name.length; i++) {
      hash = name.charCodeAt(i) + ((hash << 5) - hash);
    }

    return colours[Math.abs(hash) % colours.length];
  }

  /** A user-agent string, as the device it describes. */
  device(userAgent: string | null): string {
    if (userAgent === null || userAgent === '') {
      return 'Unknown device';
    }

    const browser = /Edg\//.test(userAgent) ? 'Edge'
      : /Chrome\//.test(userAgent) ? 'Chrome'
      : /Safari\//.test(userAgent) ? 'Safari'
      : /Firefox\//.test(userAgent) ? 'Firefox'
      : 'Browser';

    const platform = /Android/.test(userAgent) ? 'Android'
      : /iPhone|iPad/.test(userAgent) ? 'iOS'
      : /Windows/.test(userAgent) ? 'Windows'
      : /Mac OS/.test(userAgent) ? 'macOS'
      : /Linux/.test(userAgent) ? 'Linux'
      : '';

    return platform === '' ? browser : `${browser} on ${platform}`;
  }

  trackById(_index: number, item: { id: string }): string {
    return item.id;
  }

  goBack(): void {
    void this.router.navigate(['/account']);
  }
}
