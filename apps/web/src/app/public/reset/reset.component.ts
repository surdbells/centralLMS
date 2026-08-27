import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { Title, Meta } from '@angular/platform-browser';
import { HotToastService } from '@ngneat/hot-toast';
import { FormsModule } from '@angular/forms';
import { TuiLoader } from '@taiga-ui/core';
import { CommonModule } from '@angular/common';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { TranslatePipe } from '../../translate.pipe';
import { LanguageSwitcherComponent } from '../../language-switcher.component';
import { AuthService } from '../../core/auth/auth.service';
import { ApiError } from '../../core/api';

/**
 * Recovering an account.
 *
 * The screen keeps its three panels — ask, sent, set — but the middle one no
 * longer collects a code, because the code was never real. `confirm` replied
 * with the one-time code *in its own response body*; the browser held it and
 * posted it back for the server to compare against what it had just handed
 * over. Then `reset` took an email address and a new password with nothing
 * tying it to either step before it, so anybody could set anybody's password
 * by knowing their address.
 *
 * What happens instead: asking sends a link, and the link is what proves
 * somebody reads that mailbox. The middle panel says so; the third opens when
 * the person arrives back here with a token in the address.
 */
@Component({
  selector: 'app-reset',
  standalone: true,
  imports: [
    FormsModule,
    TuiLoader,
    CommonModule,
    RouterLink,
    TranslatePipe,
    LanguageSwitcherComponent,
  ],
  templateUrl: './reset.component.html',
  styleUrl: './reset.component.css',
})
export class ResetComponent implements OnInit, OnDestroy {
  private readonly auth = inject(AuthService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly titleService = inject(Title);
  private readonly metaService = inject(Meta);
  private readonly toast = inject(HotToastService);
  private readonly destroy$ = new Subject<void>();

  fieldTextType = false;

  ui_controls = {
    loading: false,
    /** A link has been sent. The second panel. */
    confirmed: false,
    /** A usable link is in hand. The third panel. */
    validated: false,
  };

  reset = { email: '' };
  r_password = { password: '', confirm_password: '' };

  /**
   * Whether the link was an invitation rather than a reset, so the third
   * panel can say "choose a password" rather than "set a new one".
   */
  isInvitation = false;

  /** Held only to send back; it is never shown and never stored. */
  private token = '';

  ngOnInit(): void {
    this.titleService.setTitle('Reset Password | Central LMS Account Recovery');
    this.metaService.updateTag({
      name: 'title',
      content: 'Reset LMS Password | Central LMS Africa Support',
    });
    this.metaService.updateTag({
      name: 'description',
      content: 'Recover your Central LMS account password and continue your eLearning '
        + 'and training activities across Africa.',
    });
    this.metaService.updateTag({
      name: 'keywords',
      content: 'reset LMS password Africa, forgot password eLearning Nigeria, LMS account recovery',
    });

    const token = this.route.snapshot.queryParamMap.get('token');

    if (token !== null && token !== '') {
      this.acceptToken(token);
    }
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  /**
   * Somebody has arrived from the email.
   *
   * The link is checked before the form is offered, so an expired one is said
   * so plainly rather than after they have chosen and typed a password twice.
   */
  private acceptToken(token: string): void {
    this.ui_controls.loading = true;

    this.auth
      .checkPasswordToken(token)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (state) => {
          this.ui_controls.loading = false;

          if (!state.valid) {
            this.error_notification('This link has expired. Ask for a new one.');

            return;
          }

          this.token = token;
          this.isInvitation = state.purpose === 'invitation';
          this.ui_controls.confirmed = true;
          this.ui_controls.validated = true;
        },
        error: () => {
          this.ui_controls.loading = false;
          this.error_notification('Could not check that link. Try again.');
        },
      });
  }

  /**
   * Asks for a link.
   *
   * Succeeds identically whether or not the address has an account here — the
   * server answers the same way on purpose, because a reply that says "no such
   * account" is a way to read off who works somewhere. So this says what was
   * done, not what was found.
   */
  user_confirm(): void {
    const email = this.reset.email.trim();

    if (email === '') {
      this.error_notification('Email address is required');

      return;
    }

    this.ui_controls.loading = true;

    this.auth
      .forgotPassword(email)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.ui_controls.loading = false;
          this.ui_controls.confirmed = true;
          this.success_notification('If that address has an account, a link is on its way.');
        },
        error: (failure: ApiError) => {
          this.ui_controls.loading = false;
          this.error_notification(failure.violation('email') ?? failure.message);
        },
      });
  }

  /** Sends another, for a link that arrived too late to be used. */
  resend(): void {
    this.ui_controls.confirmed = false;
    this.user_confirm();
  }

  /** Back to the first panel, to correct a mistyped address. */
  start_over(): void {
    this.ui_controls.confirmed = false;
    this.ui_controls.validated = false;
    this.token = '';
  }

  reset_password(): void {
    if (this.r_password.password.length === 0) {
      this.error_notification('Password is required');

      return;
    }

    if (this.r_password.password !== this.r_password.confirm_password) {
      this.error_notification('Password does not match');

      return;
    }

    this.ui_controls.loading = true;

    this.auth
      .resetPassword(this.token, this.r_password.password)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.ui_controls.loading = false;
          this.success_notification(
            this.isInvitation
              ? 'Your account is ready. Sign in with your new password.'
              : 'Password changed. Sign in with your new password.',
          );
          void this.router.navigate(['/login']);
        },
        error: (failure: ApiError) => {
          this.ui_controls.loading = false;

          // The server refuses expired, spent and unknown links with one
          // message, deliberately: telling them apart tells whoever is holding
          // a link which kind of thing they hold.
          const said = failure.violation('token') ?? failure.violation('password');

          this.error_notification(said ?? failure.message);
        },
      });
  }

  error_notification(message: string): void {
    this.toast.error(message);
  }

  success_notification(message: string): void {
    this.toast.success(message);
  }
}
