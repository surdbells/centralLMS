import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { IonContent, IonIcon } from '@ionic/angular/standalone';
import { AuthService } from '../../core/auth.service';
import { ApiError } from '../../core/api';
import { BrandingService } from '../../core/branding.service';
import { FieldComponent } from '../../components/field/field.component';
import { ButtonComponent } from '../../components/button/button.component';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [FormsModule, IonContent, IonIcon, FieldComponent, ButtonComponent],
  template: `
    <ion-content [fullscreen]="true" class="login-bg">
      @if (branding.loginBackgroundUrl(); as bg) {
        <div class="login-photo" [style.background-image]="'url(' + bg + ')'"></div>
      }
      <div class="login-scrim"></div>

      <div class="login-wrap">
        <div class="brand">
          @if (branding.logoUrl(); as logo) {
            <div class="brand-logo"><img [src]="logo" [alt]="branding.organisation() || 'Logo'" /></div>
          } @else {
            <div class="brand-mark"><ion-icon name="school-outline"></ion-icon></div>
          }
          <h1>{{ branding.organisation() || 'Central LMS' }}</h1>
          <p class="muted">Sign in to keep learning</p>
        </div>

        <div class="login-card">
          <app-field
            class="field"
            label="Email"
            type="email"
            icon="mail-outline"
            placeholder="you@example.com"
            autocomplete="email"
            inputmode="email"
            enterkeyhint="next"
            [(ngModel)]="email"
            (enter)="submit()"></app-field>

          <app-field
            class="field"
            label="Password"
            type="password"
            icon="lock-closed-outline"
            placeholder="Your password"
            autocomplete="current-password"
            enterkeyhint="go"
            [(ngModel)]="password"
            (enter)="submit()"></app-field>

          @if (error()) {
            <p class="err">{{ error() }}</p>
          }

          <app-button block size="lg" class="submit" [loading]="busy()" (click)="submit()">Sign In</app-button>
        </div>

        <p class="foot muted">Your organisation is chosen for you when you sign in.</p>
      </div>
    </ion-content>
  `,
  styles: [`
    .login-bg { --background: var(--brand-primary, var(--ion-color-primary)); }
    .login-photo { position: absolute; inset: 0; background-size: cover; background-position: center; opacity: .32; }
    .login-scrim { position: absolute; inset: 0;
      background: linear-gradient(180deg,
        color-mix(in srgb, var(--brand-primary, #2400a0) 62%, transparent) 0%,
        var(--brand-primary-shade, var(--ion-color-primary-shade)) 82%); }
    .login-wrap { position: relative; z-index: 1; min-height: 100%; display: flex; flex-direction: column; justify-content: center; padding: 24px 20px calc(24px + env(safe-area-inset-bottom)); }
    .brand { text-align: center; color: #fff; margin-bottom: 24px; }
    .brand-logo { width: 116px; height: 116px; margin: 0 auto 16px; border-radius: 28px; background: #fff; display: grid; place-items: center; padding: 16px; box-shadow: 0 18px 40px rgba(0,0,0,.28); }
    .brand-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .brand-mark { width: 68px; height: 68px; margin: 0 auto 12px; border-radius: 20px; background: rgba(255,255,255,.16); display: grid; place-items: center; }
    .brand-mark ion-icon { font-size: 36px; color: #fff; }
    .brand h1 { font-family: var(--app-font-display); font-size: 1.55rem; font-weight: var(--app-display-weight); margin: 0; letter-spacing: var(--app-display-tracking); }
    .brand .muted { color: rgba(255,255,255,.82); margin-top: 4px; }
    .login-card { background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r-lg); padding: 10px 12px 16px; box-shadow: var(--app-shadow-lg); }
    .field { display: block; margin-top: 12px; }
    .submit { display: block; margin-top: 18px; }
    .err { margin: 12px 4px 0; color: var(--ion-color-danger); font-size: .85rem; font-weight: 500; }
    .foot { text-align: center; color: rgba(255,255,255,.75); margin-top: 18px; font-size: .82rem; }
  `],
})
export class LoginPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  readonly branding = inject(BrandingService);

  email = '';
  password = '';
  readonly busy = signal(false);
  readonly error = signal('');

  submit(): void {
    if (this.busy()) {
      return;
    }
    const email = this.email.trim();
    if (!email || !this.password) {
      this.error.set('Enter your email and password.');
      return;
    }
    this.busy.set(true);
    this.error.set('');
    this.auth.login(email, this.password).subscribe({
      next: () => {
        this.busy.set(false);
        void this.router.navigateByUrl('/tabs/home', { replaceUrl: true });
      },
      error: (err: unknown) => {
        this.busy.set(false);
        this.error.set(this.messageFor(err));
      },
    });
  }

  private messageFor(err: unknown): string {
    if (err instanceof ApiError) {
      if (err.code === 'network_error') {
        return err.message;
      }
      if (err.code === 'account_inactive') {
        return err.message;
      }
      // Any other refusal on a sign-in attempt means the credentials were wrong.
      if (err.status === 401 || err.status === 422 || err.status === 400) {
        return 'Incorrect email or password.';
      }
      return err.message || 'Could not sign in. Please try again.';
    }
    return 'Could not sign in. Check your details and try again.';
  }
}
