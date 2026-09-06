import { Component, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { IonContent, IonIcon } from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { AuthService } from '../../core/auth.service';
import { SessionStore } from '../../core/session';
import { ThemeService, ThemeMode } from '../../core/theme.service';
import { BrandingService } from '../../core/branding.service';
import { Profile } from '../../core/models';
import { initialOf } from '../../core/ui';
import { ListComponent } from '../../components/list/list.component';
import { ButtonComponent } from '../../components/button/button.component';
import { SheetComponent } from '../../components/sheet/sheet.component';
import { AvatarComponent } from '../../components/avatar/avatar.component';

interface Row {
  readonly icon: string;
  readonly tone: string;
  readonly title: string;
  readonly sub: string;
  readonly url?: string;
}

@Component({
  selector: 'app-profile',
  standalone: true,
  imports: [IonContent, IonIcon, ListComponent, ButtonComponent, SheetComponent, AvatarComponent],
  template: `
    <ion-content [fullscreen]="true">
      <div class="wrap">
        <header class="head">
          <div>
            <h1>Settings</h1>
            <p>Manage your preferences and account</p>
          </div>
        </header>

        <!-- Profile card -->
        <button class="pcard" (click)="go('/profile/edit')" aria-label="Edit profile">
          <span class="pc-av">
            <app-avatar [url]="avatarUrl()" [name]="name()" [size]="60"></app-avatar>
            <span class="pc-cam" aria-hidden="true"><ion-icon name="camera-outline"></ion-icon></span>
          </span>
          <span class="pc-tx">
            <span class="pc-name">{{ name() }}</span>
            <span class="pc-email">{{ email() }}</span>
            <span class="pc-chip">{{ role() }}</span>
          </span>
          <span class="pc-edit" aria-hidden="true"><ion-icon name="create-outline"></ion-icon></span>
        </button>

        <!-- Account -->
        <p class="glabel">Account</p>
        <app-list>
          @for (r of account; track r.title) {
            <button class="row" (click)="go(r.url!)">
              <span class="ic" [attr.data-tone]="r.tone"><ion-icon [name]="r.icon"></ion-icon></span>
              <span class="tx"><span class="t">{{ r.title }}</span><span class="s">{{ r.sub }}</span></span>
              <ion-icon class="chev" name="chevron-forward" aria-hidden="true"></ion-icon>
            </button>
          }
          <div class="row static">
            <span class="ic" data-tone="amber"><ion-icon name="business-outline"></ion-icon></span>
            <span class="tx"><span class="t">Organization</span><span class="s">{{ org() || 'Your workspace' }}</span></span>
          </div>
        </app-list>

        <!-- Learning -->
        <p class="glabel">Learning</p>
        <app-list>
          @for (r of learning; track r.title) {
            <button class="row" (click)="go(r.url!)">
              <span class="ic" [attr.data-tone]="r.tone"><ion-icon [name]="r.icon"></ion-icon></span>
              <span class="tx"><span class="t">{{ r.title }}</span><span class="s">{{ r.sub }}</span></span>
              <ion-icon class="chev" name="chevron-forward" aria-hidden="true"></ion-icon>
            </button>
          }
        </app-list>

        <!-- Preferences -->
        <p class="glabel">Preferences</p>
        <app-list>
          <button class="row" (click)="themeOpen.set(true)">
            <span class="ic" data-tone="purple"><ion-icon name="color-palette-outline"></ion-icon></span>
            <span class="tx"><span class="t">Appearance</span><span class="s">How the app looks</span></span>
            <span class="val">{{ themeLabel() }}</span>
            <ion-icon class="chev" name="chevron-forward" aria-hidden="true"></ion-icon>
          </button>
        </app-list>

        <!-- About -->
        <p class="glabel">About</p>
        <app-list>
          <div class="row static">
            <span class="ic" data-tone="blue"><ion-icon name="information-circle-outline"></ion-icon></span>
            <span class="tx"><span class="t">Central LMS</span><span class="s">Learner app · version {{ version }}</span></span>
          </div>
        </app-list>

        <div class="signout">
          <app-button variant="ghost" block icon="log-out-outline"
                      style="--btn-fg: var(--ion-color-danger)" (click)="logout()">Log Out</app-button>
          <p class="ver">Central LMS · Learner</p>
        </div>
      </div>

      <app-sheet [(open)]="themeOpen" title="Appearance" subtitle="Choose how the app looks">
        <div class="opts">
          @for (o of themeOptions; track o.value) {
            <button class="opt" [class.active]="mode() === o.value" (click)="pickTheme(o.value)">
              <span class="opt-ic"><ion-icon [name]="o.icon"></ion-icon></span>
              <span class="opt-tx"><span class="opt-t">{{ o.label }}</span><span class="opt-s">{{ o.hint }}</span></span>
              @if (mode() === o.value) { <ion-icon class="opt-chk" name="checkmark" aria-hidden="true"></ion-icon> }
            </button>
          }
        </div>
      </app-sheet>
    </ion-content>
  `,
  styles: [`
    .wrap { padding: calc(12px + env(safe-area-inset-top)) 16px 8px; max-width: 640px; margin: 0 auto; }

    .head { padding: 6px 2px 14px; }
    .head h1 { margin: 0; font-size: 1.5rem; font-family: var(--app-font-display); font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); line-height: 1.15; }
    .head p { margin: 4px 0 0; color: var(--app-muted); font-size: .88rem; }

    /* Profile card */
    .pcard {
      width: 100%; text-align: left; border: 0; cursor: pointer; color: var(--app-ink);
      display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 14px;
      padding: 16px; margin-bottom: 22px;
      background: var(--app-surface); border: 1px solid var(--app-card-border);
      border-radius: var(--app-r-lg); box-shadow: var(--app-shadow);
      transition: transform .12s ease;
    }
    .pcard:active { transform: scale(.99); }
    .pc-av { position: relative; width: 60px; height: 60px; flex: none; }
    .pc-cam {
      position: absolute; right: -4px; bottom: -4px; width: 24px; height: 24px; border-radius: 999px;
      display: grid; place-items: center; background: var(--app-elevated, var(--app-surface));
      border: 2px solid var(--app-surface); box-shadow: var(--app-shadow);
    }
    .pc-cam ion-icon { font-size: 12px; color: var(--app-ink); }
    .pc-tx { min-width: 0; display: flex; flex-direction: column; gap: 3px; }
    .pc-name { font-size: 1.12rem; font-weight: 650; letter-spacing: -.01em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pc-email { font-size: .84rem; color: var(--app-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pc-chip {
      align-self: flex-start; margin-top: 3px; font-size: .68rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase;
      padding: 3px 9px; border-radius: 999px; color: var(--app-accent-ink, var(--ion-color-primary));
      background: color-mix(in srgb, var(--ion-color-primary) 14%, transparent);
    }
    .pc-edit { width: 34px; height: 34px; border-radius: 999px; display: grid; place-items: center;
      background: var(--app-field); color: var(--app-muted); }
    .pc-edit ion-icon { font-size: 17px; }

    .glabel { margin: 0 4px 8px; padding-top: 4px; font-size: .74rem; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: var(--app-muted); }
    app-list { display: block; margin-bottom: 22px; }

    /* Rows */
    .row {
      width: 100%; text-align: left; border: 0; background: transparent; cursor: pointer; color: var(--app-ink);
      display: flex; align-items: center; gap: 13px; padding: 13px 14px;
      transition: background .12s ease;
    }
    .row:active { background: var(--app-field); }
    .row.static { cursor: default; }
    .row.static:active { background: transparent; }
    .row + .row { border-top: 1px solid var(--app-line); }
    .ic { flex: none; width: 38px; height: 38px; border-radius: 11px; display: grid; place-items: center;
      background: color-mix(in srgb, var(--tone) 15%, transparent); }
    .ic ion-icon { font-size: 19px; color: var(--tone); }
    .ic[data-tone="blue"]   { --tone: var(--tone-blue); }
    .ic[data-tone="green"]  { --tone: var(--tone-green); }
    .ic[data-tone="purple"] { --tone: var(--tone-purple); }
    .ic[data-tone="amber"]  { --tone: var(--tone-amber); }
    .ic[data-tone="pink"]   { --tone: var(--tone-pink); }
    .tx { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
    .t { font-size: .96rem; font-weight: 550; letter-spacing: -.005em; }
    .s { font-size: .78rem; color: var(--app-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .val { font-size: .84rem; font-weight: 600; color: var(--app-muted); }
    .chev { flex: none; font-size: 16px; color: var(--app-muted); opacity: .6; }

    .signout { padding: 6px 0 calc(28px + env(safe-area-inset-bottom)); }
    .ver { text-align: center; margin: 12px 0 0; font-size: .76rem; color: var(--app-muted); }

    /* Theme sheet */
    .opts { display: grid; gap: 8px; }
    .opt {
      display: flex; align-items: center; gap: 13px; width: 100%; text-align: left; cursor: pointer; color: var(--app-ink);
      padding: 13px 14px; border-radius: var(--app-r); border: 1.5px solid var(--app-line);
      background: var(--app-field); transition: border-color .12s ease, background .12s ease;
    }
    .opt.active { border-color: var(--ion-color-primary); background: color-mix(in srgb, var(--ion-color-primary) 8%, var(--app-field)); }
    .opt-ic { flex: none; width: 36px; height: 36px; border-radius: 10px; display: grid; place-items: center;
      background: var(--app-surface); color: var(--app-ink); }
    .opt-ic ion-icon { font-size: 18px; }
    .opt.active .opt-ic { color: var(--ion-color-primary); }
    .opt-tx { flex: 1; display: flex; flex-direction: column; gap: 1px; }
    .opt-t { font-size: .96rem; font-weight: 600; }
    .opt-s { font-size: .78rem; color: var(--app-muted); }
    .opt-chk { flex: none; font-size: 20px; color: var(--ion-color-primary); }
  `],
})
export class ProfilePage {
  private readonly learner = inject(LearnerService);
  private readonly auth = inject(AuthService);
  private readonly session = inject(SessionStore);
  private readonly theme = inject(ThemeService);
  private readonly branding = inject(BrandingService);
  private readonly router = inject(Router);

  private readonly profile = signal<Profile | null>(null);

  readonly name = computed(() => this.session.session()?.name || this.profile()?.fullName || 'Learner');
  readonly email = computed(() => this.session.session()?.email || this.profile()?.email || '');
  readonly org = computed(() => this.branding.organisation() || '');
  readonly role = computed(() => (this.profile()?.jobTitle as string) || 'Learner');
  readonly avatarUrl = computed(() => (this.profile()?.avatarUrl as string) || null);

  readonly mode = this.theme.mode;
  readonly themeOpen = signal(false);
  readonly version = '1.0.0';

  readonly initial = initialOf;

  readonly account: Row[] = [
    { icon: 'person-outline', tone: 'blue', title: 'Personal Information', sub: 'Update your name and details', url: '/profile/edit' },
    { icon: 'lock-closed-outline', tone: 'green', title: 'Change Password', sub: 'Keep your account secure', url: '/profile/password' },
  ];

  readonly learning: Row[] = [
    { icon: 'cellular-outline', tone: 'blue', title: 'My Progress', sub: 'Track your course completion', url: '/tabs/progress' },
    { icon: 'medal-outline', tone: 'amber', title: 'Achievements', sub: 'Points, levels and badges', url: '/achievements' },
    { icon: 'ribbon-outline', tone: 'purple', title: 'Certificates', sub: 'Your earned credentials', url: '/certificates' },
    { icon: 'clipboard-outline', tone: 'green', title: 'Assessments', sub: 'Quizzes and exams', url: '/assessments' },
    { icon: 'trophy-outline', tone: 'amber', title: 'Leaderboard', sub: 'See how you rank', url: '/leaderboard' },
    { icon: 'trail-sign-outline', tone: 'pink', title: 'Learning Paths', sub: 'Guided journeys', url: '/paths' },
    { icon: 'folder-open-outline', tone: 'blue', title: 'Library', sub: 'Shared files and resources', url: '/library' },
  ];

  readonly themeOptions: Array<{ value: ThemeMode; label: string; hint: string; icon: string }> = [
    { value: 'system', label: 'System', hint: 'Match your device', icon: 'phone-portrait-outline' },
    { value: 'light', label: 'Light', hint: 'Studio — crisp and bright', icon: 'sunny-outline' },
    { value: 'dark', label: 'Dark', hint: 'Bold — deep and focused', icon: 'moon-outline' },
  ];

  readonly themeLabel = computed(() => {
    const m = this.mode();
    return m.charAt(0).toUpperCase() + m.slice(1);
  });

  // ionViewWillEnter (not ngOnInit) so returning from Edit Profile re-reads the
  // record — a changed name or a new photo shows without a manual refresh.
  ionViewWillEnter(): void {
    this.learner.profile().subscribe({
      next: (p) => this.profile.set(p),
      error: () => this.profile.set(null),
    });
  }

  pickTheme(mode: ThemeMode): void {
    this.theme.set(mode);
    this.themeOpen.set(false);
  }

  go(url: string): void {
    void this.router.navigateByUrl(url);
  }

  logout(): void {
    this.auth.logout();
    void this.router.navigateByUrl('/login', { replaceUrl: true });
  }
}
