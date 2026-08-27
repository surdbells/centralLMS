import { Component, OnInit, inject } from '@angular/core';
import { CommonModule, NgIf } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Meta, Title } from '@angular/platform-browser';
import { Router, RouterLink } from '@angular/router';
import { HotToastService } from '@ngneat/hot-toast';
import { TuiLoader } from '@taiga-ui/core';
import { ApiError } from '../../core/api';
import { AuthService } from '../../core/auth/auth.service';
import { LanguageSwitcherComponent } from '../../language-switcher.component';
import { TranslatePipe } from '../../translate.pipe';
import { environment } from '../../../environments/environment';

/** Local-part@domain.tld, with no spaces. Deliberately permissive. */
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

@Component({
  selector: 'app-login',
  imports: [TuiLoader, FormsModule, CommonModule, NgIf, RouterLink, LanguageSwitcherComponent, TranslatePipe],
  templateUrl: './login.component.html',
  standalone: true,
  styleUrl: './login.component.css',
})
export class LoginComponent implements OnInit {
  /** The marketing site, which is a separate application now. */
  readonly publicSiteUrl = environment.publicSiteUrl;

  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly titleService = inject(Title);
  private readonly metaService = inject(Meta);
  private readonly toast = inject(HotToastService);

  loading = false;
  fieldTextType = false;

  login = {
    email: '',
    password: '',
  };

  ngOnInit(): void {
    sessionStorage.clear();

    this.titleService.setTitle('Login | Central LMS Africa User Portal');
    this.metaService.updateTag({
      name: 'title',
      content: 'LMS Login Africa | Access Your Central LMS Account',
    });
    this.metaService.updateTag({
      name: 'description',
      content:
        'Login to Central LMS to access your courses, training materials, and employee learning dashboard from anywhere in Africa.',
    });
    this.metaService.updateTag({
      name: 'keywords',
      content: 'LMS login Africa, eLearning login Nigeria, corporate training portal Africa, employee LMS login',
    });
  }

  user_login(): void {
    const email = this.login.email.trim();

    if (email.length === 0) {
      this.error_notification('Email address is required');

      return;
    }

    if (this.login.password.length === 0) {
      this.error_notification('Password is required');

      return;
    }

    if (!EMAIL.test(email)) {
      this.error_notification('Invalid email format provided');

      return;
    }

    this.loading = true;

    this.auth.signIn({ email, password: this.login.password }).subscribe({
      next: (signedIn) => {
        this.loading = false;

        // A suspended organisation is refused a token at all, so reaching
        // here means the organisation is fine and only the account can be at
        // fault. The separate check this replaced existed because the legacy
        // backend signed people in first and reported the tenant afterwards.
        if (!signedIn.isActive) {
          this.error_notification(
            'Your account is inactive, contact system administrator for support.',
          );

          return;
        }

        this.success_notification('Signed in');
        void this.router.navigate(['/', 'account']);
      },
      error: (error: unknown) => {
        this.loading = false;
        // `ApiError` carries a message written for a person; anything else is
        // a network fault. The previous version passed the raw
        // `HttpErrorResponse` to the toast, which rendered "[object Object]".
        this.error_notification(
          error instanceof ApiError
            ? error.message
            : 'Could not reach the server. Check your connection and try again.',
        );
      },
    });
  }

  toggleFieldTextType(): void {
    this.fieldTextType = !this.fieldTextType;
  }

  error_notification(message: string): void {
    this.toast.error(message);
  }

  success_notification(message: string): void {
    this.toast.success(message);
  }
}
