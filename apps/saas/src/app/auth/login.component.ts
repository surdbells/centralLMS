import { Component, OnInit, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { AuthService, type SignInError } from '../core/auth.service';
import { SessionStore } from '../core/session.store';

/**
 * The way into the console.
 *
 * The same credentials as the tenant application — a platform operator has an
 * ordinary account that happens to hold the super-admin role — so this is a
 * plain email and password. What makes it the operator sign-in is what
 * {@link AuthService.signIn} does after: it proves the account may run the
 * platform before it lets anyone in, and says so plainly when it may not.
 */
@Component({
  selector: 'app-login',
  standalone: true,
  imports: [FormsModule],
  template: `
    <div class="op-login-bg">
      <div class="op-login-card op-card">
        <div class="op-login-head">
          <span class="op-mark op-mark--lg"><i class="bi bi-hexagon-fill"></i></span>
          <h1>Operator Console</h1>
          <p>Sign in to manage the Central LMS platform.</p>
        </div>

        <form (ngSubmit)="submit()" class="op-login-body">
          @if (error()) {
            <div class="op-alert op-alert--danger" style="margin-bottom:1rem">
              <i class="bi bi-exclamation-triangle-fill"></i>
              <span>{{ message(error()!) }}</span>
            </div>
          }

          <div class="op-field">
            <label class="op-field__label" for="email">Email</label>
            <input id="email" name="email" type="email" class="op-input" autocomplete="username"
                   [(ngModel)]="email" [disabled]="busy()" required placeholder="you@centrallms.com" />
          </div>

          <div class="op-field">
            <label class="op-field__label" for="password">Password</label>
            <input id="password" name="password" type="password" class="op-input" autocomplete="current-password"
                   [(ngModel)]="password" [disabled]="busy()" required placeholder="••••••••" />
          </div>

          <button type="submit" class="op-btn op-btn--primary"
                  style="width:100%;justify-content:center;margin-top:0.35rem"
                  [disabled]="busy() || !email || !password">
            @if (busy()) { <span class="op-spinner"></span> Signing in… }
            @else { <i class="bi bi-box-arrow-in-right"></i> Sign in }
          </button>
        </form>

        <div class="op-login-foot">
          Not an operator? <a [href]="appUrl">Go to the application →</a>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .op-login-bg {
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 1.5rem;
      background: radial-gradient(1200px 600px at 50% -10%, #2a3350 0%, #171d2e 55%, #10131f 100%);
    }
    .op-login-card { width: 100%; max-width: 400px; overflow: hidden; }
    .op-login-head { text-align: center; padding: 2rem 2rem 0.5rem; }
    .op-login-head h1 { font-size: 1.35rem; font-weight: 700; margin: 0.9rem 0 0.3rem; }
    .op-login-head p { color: var(--op-ink-soft); font-size: 0.9rem; margin: 0; }
    .op-login-body { padding: 1.5rem 2rem 0.5rem; }
    .op-login-foot {
      padding: 1.1rem 2rem 1.75rem;
      text-align: center;
      font-size: 0.85rem;
      color: var(--op-ink-soft);
    }
    .op-login-foot a { color: var(--op-accent); text-decoration: none; font-weight: 600; }
    .op-mark--lg {
      width: 52px; height: 52px; border-radius: 14px; font-size: 1.5rem; margin: 0 auto;
      background: linear-gradient(135deg, var(--op-accent), #8b5cf6);
      display: grid; place-items: center; color: #fff;
    }
  `],
})
export class LoginComponent implements OnInit {
  private readonly auth = inject(AuthService);
  private readonly store = inject(SessionStore);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);

  email = '';
  password = '';
  readonly busy = signal(false);
  readonly error = signal<SignInError | null>(null);
  readonly appUrl = 'https://app.centrallms.com';

  ngOnInit(): void {
    // Already signed in — nothing to do here.
    if (this.store.isAuthenticated()) {
      void this.router.navigateByUrl(this.returnUrl());
    }
  }

  submit(): void {
    if (this.busy() || !this.email || !this.password) {
      return;
    }

    this.busy.set(true);
    this.error.set(null);

    this.auth.signIn(this.email.trim(), this.password).subscribe({
      next: (err) => {
        this.busy.set(false);
        if (err === null) {
          void this.router.navigateByUrl(this.returnUrl());
        } else {
          this.error.set(err);
          this.password = '';
        }
      },
      error: () => {
        this.busy.set(false);
        this.error.set('network');
      },
    });
  }

  message(error: SignInError): string {
    switch (error) {
      case 'credentials':
        return 'That email and password did not match. Please try again.';
      case 'inactive':
        return 'That account is not active. Contact platform support.';
      case 'not_operator':
        return 'That account is not a platform operator. This console is for platform administrators.';
      case 'network':
        return 'Could not reach the server. Check your connection and try again.';
    }
  }

  private returnUrl(): string {
    const url = this.route.snapshot.queryParamMap.get('returnUrl');

    // Only same-app absolute paths, never an off-site redirect smuggled in.
    return url && url.startsWith('/') && !url.startsWith('//') ? url : '/dashboard';
  }
}
