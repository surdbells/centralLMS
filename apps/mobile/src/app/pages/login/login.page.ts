import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import {
  IonContent, IonInput, IonButton, IonSpinner, IonIcon, IonItem, IonNote,
} from '@ionic/angular/standalone';
import { AuthService } from '../../core/auth.service';
import { ApiError } from '../../core/api';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [FormsModule, IonContent, IonInput, IonButton, IonSpinner, IonIcon, IonItem, IonNote],
  template: `
    <ion-content [fullscreen]="true" class="login-bg">
      <div class="login-wrap">
        <div class="brand">
          <div class="brand-mark"><ion-icon name="school-outline"></ion-icon></div>
          <h1>Central LMS</h1>
          <p class="muted">Sign in to keep learning</p>
        </div>

        <div class="login-card">
          <ion-item lines="none" class="field">
            <ion-icon slot="start" name="mail-outline" aria-hidden="true"></ion-icon>
            <ion-input
              label="Email"
              labelPlacement="stacked"
              type="email"
              autocomplete="email"
              inputmode="email"
              placeholder="you@example.com"
              [(ngModel)]="email"
              (keyup.enter)="submit()"></ion-input>
          </ion-item>

          <ion-item lines="none" class="field">
            <ion-icon slot="start" name="lock-closed-outline" aria-hidden="true"></ion-icon>
            <ion-input
              label="Password"
              labelPlacement="stacked"
              type="password"
              autocomplete="current-password"
              placeholder="Your password"
              [(ngModel)]="password"
              (keyup.enter)="submit()"></ion-input>
          </ion-item>

          @if (error()) {
            <ion-note color="danger" class="err">{{ error() }}</ion-note>
          }

          <ion-button expand="block" class="submit" (click)="submit()" [disabled]="busy()">
            @if (busy()) { <ion-spinner name="crescent"></ion-spinner> }
            @else { Sign In }
          </ion-button>
        </div>

        <p class="foot muted">Your organisation is chosen for you when you sign in.</p>
      </div>
    </ion-content>
  `,
  styles: [`
    .login-bg { --background: linear-gradient(180deg, var(--ion-color-primary) 0%, var(--ion-color-primary-shade) 100%); }
    .login-wrap { min-height: 100%; display: flex; flex-direction: column; justify-content: center; padding: 24px 20px calc(24px + env(safe-area-inset-bottom)); }
    .brand { text-align: center; color: #fff; margin-bottom: 24px; }
    .brand-mark { width: 68px; height: 68px; margin: 0 auto 12px; border-radius: 20px; background: rgba(255,255,255,.16); display: grid; place-items: center; }
    .brand-mark ion-icon { font-size: 36px; color: #fff; }
    .brand h1 { font-size: 1.6rem; font-weight: 800; margin: 0; }
    .brand .muted { color: rgba(255,255,255,.8); margin-top: 4px; }
    .login-card { background: var(--ion-card-background); border-radius: var(--app-radius); padding: 10px 12px 16px; box-shadow: 0 20px 50px rgba(0,0,0,.25); }
    .field { --background: var(--ion-color-light, #f2f3f7); --border-radius: 12px; margin-top: 12px; --padding-start: 12px; }
    .field ion-icon { color: var(--ion-color-medium); margin-right: 8px; }
    .submit { margin-top: 18px; --border-radius: 12px; height: 48px; font-weight: 700; }
    .err { display: block; margin: 12px 4px 0; }
    .foot { text-align: center; color: rgba(255,255,255,.75); margin-top: 18px; font-size: .82rem; }
  `],
})
export class LoginPage {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);

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
        this.error.set(
          err instanceof ApiError && err.code !== 'unknown_error'
            ? err.message
            : 'Could not sign in. Check your details and try again.',
        );
      },
    });
  }
}
