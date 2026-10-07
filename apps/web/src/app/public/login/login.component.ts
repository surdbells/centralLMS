import { Component, OnInit, inject } from '@angular/core';
import { CommonModule, NgIf } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Meta, Title } from '@angular/platform-browser';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { HotToastService } from '@ngneat/hot-toast';
import { TuiLoader } from '@taiga-ui/core';
import { ApiClient, ApiError, Endpoints } from '../../core/api';
import { AuthService } from '../../core/auth/auth.service';
import { BrandingService } from '../../core/branding.service';
import { SsoService, type SsoProviderId, type SsoProviderOption } from '../../services/sso.service';
import { LanguageSwitcherComponent } from '../../language-switcher.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { environment } from '../../../environments/environment';

/** Local-part@domain.tld, with no spaces. Deliberately permissive. */
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

@Component({
  selector: 'app-login',
  imports: [TuiLoader, FormsModule, CommonModule, NgIf, RouterLink, LanguageSwitcherComponent, TranslatePipe],
  templateUrl: './login.component.html',
  standalone: true,
  styleUrls: ['../auth-pane.css'],
})
export class LoginComponent implements OnInit {
  /** The marketing site, which is a separate application now. */
  readonly publicSiteUrl = environment.publicSiteUrl;

  private readonly auth = inject(AuthService);
  private readonly sso = inject(SsoService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly titleService = inject(Title);
  private readonly metaService = inject(Meta);
  private readonly toast = inject(HotToastService);
  private readonly api = inject(ApiClient);
  private readonly branding = inject(BrandingService);
  private readonly i18n = inject(I18nService);

  /** The organisation's logo and name, when a tenant is known for this screen. */
  logoUrl: string | null = null;
  orgName: string | null = null;
  /** The organisation's sign-in background, from branding settings. */
  loginBackgroundUrl: string | null = null;

  loading = false;

  /**
   * The directories this organisation accepts.
   *
   * Empty at `/login`, because there is no organisation to ask about yet —
   * an email and a password identify one on their own, a Google account
   * does not. An organisation hands out `/{tenantId}/login`, the same way
   * it hands out its registration link, and there the buttons appear.
   */
  ssoProviders: SsoProviderOption[] = [];

  /** The organisation this sign-in screen belongs to, if it belongs to one. */
  tenantId = '';
  fieldTextType = false;

  /** The organisation to register into (from public settings), for the sign-up link. */
  registerTenantId: string | null = null;

  /** Whether this organisation takes open sign-ups, so the sign-up link shows at all. */
  allowSelfRegistration = false;

  login = {
    email: '',
    password: '',
  };

  /** "Keep me signed in" — persist the session across browser restarts. */
  rememberMe = true;

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

    this.tenantId = this.route.snapshot.paramMap.get('tenantId') ?? '';

    if (this.tenantId !== '') {
      this.sso.providers(this.tenantId).subscribe({
        next: (providers) => (this.ssoProviders = providers),
        // A sign-in screen that will not draw because one optional
        // request failed is worse than one without the extra buttons.
        error: () => (this.ssoProviders = []),
      });
    }

    // Wear the organisation's logo, from the tenant in the URL or — the usual
    // case at plain /login — the last one this browser signed in to.
    this.loadBranding(this.tenantId || this.rememberedTenant());
  }

  private rememberedTenant(): string {
    try {
      return localStorage.getItem(BrandingService.LAST_TENANT_KEY) ?? '';
    } catch {
      return '';
    }
  }

  private loadBranding(tenantId: string): void {
    // With a tenant (from the URL or last sign-in) we ask for its branding; with
    // none we ask anyway, so a single-tenant or on-prem deployment answers with
    // its default organisation. A multi-tenant cloud answers neutral, which
    // clears back to the product's own look.
    const params = /^[0-9a-f-]{36}$/i.test(tenantId) ? { tenantId } : {};

    this.api
      .get<{
        tenantId: string | null;
        organisation: string | null;
        branding: Record<string, string | boolean>;
        allowSelfRegistration: boolean;
      }>(Endpoints.settings.public, params)
      .subscribe({
        next: (settings) => {
          // Paint the colours, logo and favicon so the sign-in buttons wear
          // the organisation's colour, not just its logo.
          this.branding.apply(settings.organisation, settings.branding);
          this.logoUrl = this.branding.logoUrl();
          this.orgName = this.branding.organisation();
          this.loginBackgroundUrl = this.branding.loginBackgroundUrl();
          // The resolved organisation to register into, and whether it takes
          // open sign-ups — so the "Sign up" link points at the right tenant
          // (/{tenantId}/register, not a bare /register that belongs to nobody)
          // and shows only when self-registration is enabled.
          this.registerTenantId = settings.tenantId;
          this.allowSelfRegistration = settings.allowSelfRegistration === true;
        },
        error: () => undefined,
      });
  }

  /**
   * Hands off to the directory.
   *
   * The URL is built server-side, because the state and nonce that tie the
   * round trip together are written down there before it is handed out.
   */
  signInWith(provider: SsoProviderId): void {
    this.loading = true;

    this.sso.start(this.tenantId, provider).subscribe({
      next: (url) => {
        // Not `router.navigate`: this leaves the application.
        window.location.href = url;
      },
      error: (failure: ApiError) => {
        this.loading = false;
        this.error_notification(failure.message);
      },
    });
  }

  user_login(): void {
    const email = this.login.email.trim();

    if (email.length === 0) {
      this.error_notification(this.i18n.t('auth.email_required'));

      return;
    }

    if (this.login.password.length === 0) {
      this.error_notification(this.i18n.t('auth.password_required'));

      return;
    }

    if (!EMAIL.test(email)) {
      this.error_notification(this.i18n.t('login.invalid_email'));

      return;
    }

    this.loading = true;

    this.auth.signIn({ email, password: this.login.password }, this.rememberMe).subscribe({
      next: (signedIn) => {
        this.loading = false;

        // A suspended organisation is refused a token at all, so reaching
        // here means the organisation is fine and only the account can be at
        // fault. The separate check this replaced existed because the legacy
        // backend signed people in first and reported the tenant afterwards.
        if (!signedIn.isActive) {
          this.error_notification(
            this.i18n.t('login.account_inactive'),
          );

          return;
        }

        this.success_notification(this.i18n.t('login.signed_in'));
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
            : this.i18n.t('login.server_unreachable'),
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
