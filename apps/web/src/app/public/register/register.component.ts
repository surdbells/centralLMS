import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { HotToastService } from '@ngneat/hot-toast';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Title, Meta } from '@angular/platform-browser';
import { TuiLoader } from '@taiga-ui/core';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { LanguageSwitcherComponent } from '../../language-switcher.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { DIAL_CODES, DialCode } from '../../dial-codes';
import { RegistrationService } from '../../services/registration.service';
import { ApiClient, ApiError, Endpoints } from '../../core/api';
import { BrandingService } from '../../core/branding.service';
import { environment } from '../../../environments/environment';

/**
 * Registering yourself, in a named organisation.
 *
 * The organisation comes from the address — `/{tenantId}/register` — because
 * an account has to belong to one. The legacy posted to a single
 * `authentication/register` that asked for no organisation at all and inserted
 * rows with no `tenant_id`, so a self-registered account belonged to nobody.
 *
 * Closed unless the organisation has opened it, and the screen says so rather
 * than offering a form that will be refused.
 *
 * **The password fields are gone**, and that is the one real change to this
 * markup. Registering asks for a link and the password is chosen through it,
 * which is the same path somebody invited by an administrator walks — one
 * mechanism rather than two, and no password accepted for an address before
 * somebody has shown they read the post there. The panel that used to collect
 * a six-digit code is still here and still says "check your email"; the code
 * itself was never real, having been sent to this browser by the server and
 * posted straight back for comparison.
 */
@Component({
  selector: 'app-register',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    TuiLoader,
    RouterLink,
    LanguageSwitcherComponent,
    TranslatePipe,
  ],
  templateUrl: './register.component.html',
  styleUrls: ['./register.component.css', '../auth-pane.css'],
})
export class RegisterComponent implements OnInit, OnDestroy {
  /** The marketing site, which is a separate application now. */
  readonly publicSiteUrl = environment.publicSiteUrl;

  private readonly registration = inject(RegistrationService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly titleService = inject(Title);
  private readonly metaService = inject(Meta);
  private readonly toast = inject(HotToastService);
  private readonly i18n = inject(I18nService);
  private readonly api = inject(ApiClient);
  private readonly branding = inject(BrandingService);
  private readonly destroy$ = new Subject<void>();

  /** The organisation's logo and sign-in background, so the page is white-labelled. */
  logoUrl: string | null = null;
  loginBackgroundUrl: string | null = null;

  dialCodes: DialCode[] = DIAL_CODES;
  codeSearch = '';

  ui_controls = {
    loading: false,
    /** The form has been sent. The second panel. */
    registered: false,
  };

  register = {
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    countryCode: '+234',
  };

  /** Whose door this is, once the link has been asked about. */
  organisation = '';

  /** Whether this organisation takes registrations at all. */
  isOpen = false;

  /** The domains it accepts, empty when it accepts any. */
  domains: string[] = [];

  /** Set when the link names no organisation, or one that does not exist. */
  notFound = false;

  private tenantId = '';

  ngOnInit(): void {
    this.titleService.setTitle('Create Account | Central LMS Africa Registration');
    this.metaService.updateTag({
      name: 'title',
      content: 'Sign Up LMS Africa | Register for Central Learning Management System',
    });
    this.metaService.updateTag({
      name: 'description',
      content: 'Register for Central LMS to access corporate training, online courses, '
        + 'and employee development tools across Africa.',
    });
    this.metaService.updateTag({
      name: 'keywords',
      content: 'register LMS Africa, sign up eLearning Nigeria, create LMS account Africa',
    });

    const tenantId = this.route.snapshot.paramMap.get('tenantId');

    if (tenantId === null || tenantId === '') {
      // Reached without an organisation. There is no such thing as registering
      // into no organisation, so there is nothing to draw.
      this.notFound = true;

      return;
    }

    this.tenantId = tenantId;
    this.loadBranding(tenantId);
    this.loadOpening();
  }

  /** Wear the organisation's logo and background from its public branding. */
  private loadBranding(tenantId: string): void {
    const params = /^[0-9a-f-]{36}$/i.test(tenantId) ? { tenantId } : {};

    this.api
      .get<{ organisation: string | null; branding: Record<string, string | boolean> }>(
        Endpoints.settings.public,
        params,
      )
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (settings) => {
          this.branding.apply(settings.organisation, settings.branding);
          this.logoUrl = this.branding.logoUrl();
          this.loginBackgroundUrl = this.branding.loginBackgroundUrl();
        },
        error: () => undefined,
      });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  private loadOpening(): void {
    this.ui_controls.loading = true;

    this.registration
      .opening(this.tenantId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (opening) => {
          this.organisation = opening.organisation;
          this.isOpen = opening.isOpen;
          this.domains = opening.domains;
          this.ui_controls.loading = false;
        },
        error: () => {
          this.notFound = true;
          this.ui_controls.loading = false;
        },
      });
  }

  /** "Open to acme.com and acme.co.uk addresses", for the form to say so. */
  get domainNote(): string {
    if (this.domains.length === 0) {
      return '';
    }

    if (this.domains.length === 1) {
      return this.i18n.t('register.domain_note_one', { domain: this.domains[0] });
    }

    const domains = [...this.domains];
    const last = domains.pop();

    return this.i18n.t('register.domain_note_many', {
      domains: domains.join(', '),
      last,
    });
  }

  get selectedDial(): DialCode | undefined {
    return this.dialCodes.find((d) => d.code === this.register.countryCode);
  }

  filteredDialCodes(): DialCode[] {
    const query = this.codeSearch.trim().toLowerCase();

    if (query === '') {
      return this.dialCodes;
    }

    return this.dialCodes.filter(
      (d) => d.name.toLowerCase().includes(query) || d.code.includes(query),
    );
  }

  selectCode(d: DialCode): void {
    this.register.countryCode = d.code;
    this.codeSearch = '';
  }

  get fullPhone(): string {
    return `${this.register.countryCode}${this.register.phone.replace(/\D/g, '')}`;
  }

  /**
   * Sends the form.
   *
   * Kept under its old name because the template's submit binds to it. What it
   * asks for is a link rather than a one-time code.
   */
  send_otp(): void {
    if (this.register.first_name.trim() === '' || this.register.last_name.trim() === '') {
      this.error_notification(this.i18n.t('register.name_required'));

      return;
    }

    if (this.register.email.trim() === '') {
      this.error_notification(this.i18n.t('auth.email_required'));

      return;
    }

    this.ui_controls.loading = true;

    this.registration
      .register(this.tenantId, {
        firstName: this.register.first_name.trim(),
        lastName: this.register.last_name.trim(),
        email: this.register.email.trim(),
        phone: this.register.phone.trim() === '' ? undefined : this.fullPhone,
      })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.ui_controls.loading = false;
          this.ui_controls.registered = true;
        },
        error: (failure: ApiError) => {
          this.ui_controls.loading = false;

          this.error_notification(
            failure.violation('email')
            ?? failure.violation('firstName')
            ?? failure.violation('lastName')
            ?? failure.message,
          );
        },
      });
  }

  /** Back to the form, to correct a mistyped address. */
  change_email(): void {
    this.ui_controls.registered = false;
  }

  goToLogin(): void {
    void this.router.navigate(['/login']);
  }

  error_notification(message: string): void {
    this.toast.error(message);
  }

  success_notification(message: string): void {
    this.toast.success(message);
  }
}
