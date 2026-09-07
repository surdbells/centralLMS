import { Component, OnDestroy, inject } from '@angular/core';
import { Router, RouterLink } from '@angular/router';
import { HotToastService } from '@ngneat/hot-toast';
import { Subject, takeUntil } from 'rxjs';
import { AuthService } from '../../core/auth/auth.service';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';

@Component({
  selector: 'app-side',
    imports: [ RouterLink, TranslatePipe ],
  standalone: true,
  templateUrl: './side.component.html',
  styleUrl: './side.component.css'
})
export class SideComponent implements OnDestroy {
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  private readonly toast = inject(HotToastService);
  private readonly i18n = inject(I18nService);

  private readonly destroy$ = new Subject<void>();

  ui_controls = {
    is_loading: false,
  };

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  /**
   * Signs out on the server, then leaves.
   *
   * It used to clear `localStorage` and `sessionStorage` and navigate away,
   * which looks identical and is not the same thing: the refresh token stayed
   * valid for its full month on the server. Somebody signing out on a shared
   * machine had not signed out — anybody who recovered that token from a
   * backup, a synced profile or a browser extension could have gone on using
   * the account. The account owner had been told they were out.
   */
  sign_out(): void {
    this.auth
      .signOut()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => this.leave(),
        // The token may already be gone. Either way the person asked to
        // leave, so they leave.
        error: () => this.leave(),
      });
  }

  private leave(): void {
    this.success_notification(this.i18n.t('auth.logged_out'));
    void this.router.navigate(['/']);
  }
}