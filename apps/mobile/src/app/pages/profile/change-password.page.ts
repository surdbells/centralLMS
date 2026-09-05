import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import {
  IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle, IonIcon,
} from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { SessionStore } from '../../core/session';
import { ApiError } from '../../core/api';
import { ToastService } from '../../components/toast/toast.service';
import { FieldComponent } from '../../components/field/field.component';
import { ButtonComponent } from '../../components/button/button.component';
import { PasswordPolicy } from '../../core/models';

@Component({
  selector: 'app-change-password',
  standalone: true,
  imports: [
    IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle, IonIcon,
    FormsModule, FieldComponent, ButtonComponent,
  ],
  template: `
    <ion-header [translucent]="true">
      <ion-toolbar>
        <ion-buttons slot="start"><ion-back-button defaultHref="/tabs/profile"></ion-back-button></ion-buttons>
        <ion-title>Change Password</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content [fullscreen]="true">
      <div class="wrap">
        <div class="intro">
          <span class="intro-ic"><ion-icon name="lock-closed-outline"></ion-icon></span>
          <p>Choose a strong password you don’t use anywhere else. You’ll need your current one to confirm it’s you.</p>
        </div>

        <form (ngSubmit)="save()">
          <app-field label="Current password" type="password" icon="lock-closed-outline"
                     autocomplete="current-password" enterkeyhint="next"
                     [(ngModel)]="current" name="current"
                     (ngModelChange)="currentServerError.set('')"
                     [error]="currentError()"></app-field>

          <app-field label="New password" type="password" icon="key-outline"
                     autocomplete="new-password" enterkeyhint="next"
                     [(ngModel)]="newPassword" name="newPassword"
                     [error]="submitted() && !meetsPolicy() ? 'Meet all the requirements below' : ''"></app-field>

          @if (newPassword()) {
            <ul class="reqs" aria-label="Password requirements">
              @for (r of requirements(); track r.label) {
                <li [class.met]="r.met">
                  <ion-icon [name]="r.met ? 'checkmark-circle' : 'ellipse-outline'" aria-hidden="true"></ion-icon>
                  <span>{{ r.label }}</span>
                </li>
              }
            </ul>
          }

          <app-field label="Confirm new password" type="password" icon="shield-checkmark-outline"
                     autocomplete="new-password" enterkeyhint="done"
                     [(ngModel)]="confirm" name="confirm"
                     [error]="submitted() && confirm() && confirm() !== newPassword() ? 'Passwords don’t match' : ''"></app-field>

          <div class="actions">
            <app-button type="submit" size="lg" block [loading]="saving()">Update password</app-button>
          </div>
        </form>
      </div>
    </ion-content>
  `,
  styles: [`
    .wrap { padding: 18px 16px calc(28px + env(safe-area-inset-bottom)); max-width: 560px; margin: 0 auto; }
    .intro { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 20px; padding: 14px;
      border-radius: var(--app-r); background: var(--app-tag-bg); color: var(--app-ink); }
    .intro-ic { flex: none; width: 38px; height: 38px; border-radius: 11px; display: grid; place-items: center;
      background: color-mix(in srgb, var(--ion-color-primary) 15%, transparent); color: var(--ion-color-primary); }
    .intro-ic ion-icon { font-size: 19px; }
    .intro p { margin: 0; font-size: .86rem; line-height: 1.5; color: var(--app-muted); }
    form { display: grid; gap: 15px; }
    .reqs { list-style: none; margin: -6px 2px 0; padding: 0; display: grid; gap: 6px; }
    .reqs li { display: flex; align-items: center; gap: 8px; font-size: .82rem; color: var(--app-muted); transition: color .15s ease; }
    .reqs li ion-icon { font-size: 16px; color: var(--app-line); transition: color .15s ease; }
    .reqs li.met { color: var(--app-ink); }
    .reqs li.met ion-icon { color: var(--ion-color-success); }
    .actions { margin-top: 10px; }
  `],
})
export class ChangePasswordPage implements OnInit {
  private readonly learner = inject(LearnerService);
  private readonly session = inject(SessionStore);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);

  readonly current = signal('');
  readonly newPassword = signal('');
  readonly confirm = signal('');

  readonly saving = signal(false);
  readonly submitted = signal(false);
  readonly currentServerError = signal('');
  private readonly policy = signal<PasswordPolicy | null>(null);

  readonly currentError = computed(
    () =>
      this.currentServerError() ||
      (this.submitted() && !this.current().trim() ? 'Enter your current password' : ''),
  );

  readonly requirements = computed<Array<{ label: string; met: boolean }>>(() => {
    const p = this.newPassword();
    const pol = this.policy();
    const min = pol?.minimumLength ?? 8;
    const reqs = [{ label: `At least ${min} characters`, met: p.length >= min }];
    if (pol?.requiresUppercase) reqs.push({ label: 'An uppercase letter', met: /\p{Lu}/u.test(p) });
    if (pol?.requiresLowercase ?? true) reqs.push({ label: 'A lowercase letter', met: /\p{Ll}/u.test(p) });
    if (pol?.requiresNumber ?? true) reqs.push({ label: 'A number', met: /\p{Nd}/u.test(p) });
    if (pol?.requiresSymbol) reqs.push({ label: 'A symbol', met: /[^\p{L}\p{Nd}]/u.test(p) });
    return reqs;
  });

  readonly meetsPolicy = computed(() => this.requirements().every((r) => r.met));

  ngOnInit(): void {
    const tenantId = this.session.session()?.tenantId;
    if (tenantId) {
      this.learner.passwordPolicy(tenantId).subscribe((p) => this.policy.set(p));
    }
  }

  save(): void {
    this.submitted.set(true);
    this.currentServerError.set('');

    if (!this.current().trim() || !this.meetsPolicy() || this.newPassword() !== this.confirm()) {
      return;
    }

    const userId = this.session.session()?.userId;
    if (!userId) {
      this.toast.error('Your session has expired. Please sign in again.');
      return;
    }

    this.saving.set(true);
    this.learner.changePassword(userId, this.current(), this.newPassword()).subscribe({
      next: () => {
        this.saving.set(false);
        this.toast.success('Your password has been changed.', { title: 'Password updated' });
        void this.router.navigateByUrl('/tabs/profile');
      },
      error: (e: unknown) => {
        this.saving.set(false);
        if (e instanceof ApiError) {
          const currentMsg = e.fieldError('currentPassword');
          if (currentMsg) {
            this.currentServerError.set(currentMsg);
          }
          this.toast.error(currentMsg || e.fieldError('password') || e.message, {
            title: 'Couldn’t change password',
          });
        } else {
          this.toast.error('Something went wrong. Please try again.', { title: 'Couldn’t change password' });
        }
      },
    });
  }
}
