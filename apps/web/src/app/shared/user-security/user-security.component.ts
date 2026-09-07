import { Component, OnDestroy, inject } from '@angular/core';
import { TopComponent } from '../../partials/top/top.component';
import { TuiIcon, TuiLoader } from '@taiga-ui/core';
import { Router } from '@angular/router';
import { HotToastService } from '@ngneat/hot-toast';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject, takeUntil } from 'rxjs';
import { ApiError } from '../../core/api';
import { SessionStore } from '../../core/auth/session.store';
import { UsersService } from '../../services/users.service';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';

/**
 * Changing your own password.
 *
 * **It did not ask for the current one.** The legacy endpoint took an id, a
 * token and a new password, so anybody who reached this screen with a live
 * session — a colleague at an unlocked desk, a shared machine somebody had
 * walked away from, a browser left signed in — could change the password and
 * lock the owner out of their own account. The API asks for the current
 * password when the person changing it is the account holder, so the form now
 * asks too. That is a new field on this screen and it is the point of the
 * change.
 *
 * The rest is the usual retirement: no `atob(sessionStorage.SESSION)`, no id
 * or token copied into a request body, and a failure that says what went
 * wrong rather than being swallowed by a `response_code === 200` check that
 * had no branch for anything else.
 */
@Component({
  selector: 'app-user-security',
  standalone: true,
  imports: [
    TopComponent,
    CommonModule,
    FormsModule,
    TuiIcon,
    TuiLoader,
    TranslatePipe,
  ],
  templateUrl: './user-security.component.html',
  styleUrl: './user-security.component.css',
})
export class UserSecurityComponent implements OnDestroy {
  private readonly router = inject(Router);
  private readonly toast = inject(HotToastService);
  private readonly session = inject(SessionStore);
  private readonly users = inject(UsersService);
  private readonly i18n = inject(I18nService);

  private readonly destroy$ = new Subject<void>();

  ui_controls = {
    is_loading: false,
  };

  /**
   * The form's own state.
   *
   * `current_password` is new. The other two are what the template has always
   * bound to, so the markup keeps its names.
   */
  update_password = {
    current_password: '',
    password: '',
    confirm_password: '',
  };

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  goBack(): void {
    void this.router.navigate(['/account']);
  }

  error_notification(message: string): void {
    this.toast.error(message);
  }

  success_notification(message: string): void {
    this.toast.success(message);
  }

  password_change(): void {
    const session = this.session.session();

    if (session === null) {
      this.error_notification(this.i18n.t('security.toast_sign_in_again'));

      return;
    }

    const problem = this.whatIsWrong();

    if (problem !== null) {
      this.error_notification(problem);

      return;
    }

    this.ui_controls.is_loading = true;

    this.users
      .setPassword(
        session.userId,
        this.update_password.password,
        this.update_password.current_password,
      )
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.ui_controls.is_loading = false;
          this.update_password = { current_password: '', password: '', confirm_password: '' };
          this.success_notification(this.i18n.t('security.toast_password_changed'));
        },
        error: (error: ApiError) => {
          // The old screen had a branch for `response_code === 200` and one
          // for 400, and nothing at all for a wrong current password or a
          // password the policy rejects — so those failed in silence.
          this.ui_controls.is_loading = false;
          this.error_notification(error.message);
        },
      });
  }

  /** The first thing wrong with the form, or null. */
  private whatIsWrong(): string | null {
    if (this.update_password.current_password.length === 0) {
      return this.i18n.t('security.err_current_required');
    }

    if (this.update_password.password.length === 0) {
      return this.i18n.t('security.err_password_required');
    }

    if (this.update_password.confirm_password.length === 0) {
      return this.i18n.t('security.err_password_mismatch');
    }

    if (this.update_password.password !== this.update_password.confirm_password) {
      return this.i18n.t('security.err_password_mismatch');
    }

    return null;
  }
}
