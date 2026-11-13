import { Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, forkJoin, of } from 'rxjs';
import { catchError, takeUntil } from 'rxjs/operators';
import { AdmintopComponent } from '../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../translate.pipe';
import { SkeletonComponent } from '../shared/skeleton/skeleton.component';
import { I18nService } from '../i18n.service';
import { ConfirmDialogService } from '../shared/components/confirmation-dialog/confirmation-dialog.component';
import {
  SettingsService,
  type SettingDefinition,
  type SettingValue,
} from '../services/settings.service';
import { TenantService, type Tenant } from '../services/tenant.service';
import { EmailTemplatesPanelComponent } from './email-templates-panel/email-templates-panel.component';
import { SelectComponent } from '../shared/select/select.component';
import { EmailTemplatesService } from '../services/email-templates.service';
import {
  SsoService,
  type SsoConnection,
  type SsoConnectionInput,
  type SsoProviderId,
} from '../services/sso.service';
import { ApiError } from '../core/api';

/** A tab. The catalogue's categories, plus the two that are not settings. */
export interface SettingsTab {
  key: string;
  label: string;
  description: string;
  icon: string;
}

/**
 * Everything an organisation can change about its own installation.
 *
 * **It said "Failed to load settings."** The screen posted to
 * `api.centrallms.com/tenant/settings`, which is not this API and does not
 * answer, so every tab was empty.
 *
 * **The form is built from the catalogue.** `/v1/settings/catalogue` returns
 * every setting the API actually honours, with its label, help text, type and
 * — usefully — where it takes effect. Rendering from that rather than from a
 * hand-written form is what stops the two drifting: the legacy screen offered
 * eight tabs and about fifty settings, of which the backend read five. A field
 * that does nothing is worse than a missing one, because somebody sets it and
 * believes it.
 *
 * The tabs that are gone had nothing behind them. What replaced two of them:
 * the organisation's own record, which is not settings at all, and its email
 * templates, which are.
 */
@Component({
  selector: 'app-tenant-settings',
  standalone: true,
  imports: [CommonModule, FormsModule, AdmintopComponent, EmailTemplatesPanelComponent, TranslatePipe, SkeletonComponent, SelectComponent],
  templateUrl: './tenant-settings.component.html',
  styleUrls: ['./tenant-settings.component.scss'],
})
export class TenantSettingsComponent implements OnInit, OnDestroy {
  private readonly settingsService = inject(SettingsService);
  private readonly tenantService = inject(TenantService);
  private readonly ssoService = inject(SsoService);
  private readonly router = inject(Router);
  private readonly i18n = inject(I18nService);
  private readonly dialog = inject(ConfirmDialogService);
  private readonly emailTemplates = inject(EmailTemplatesService);
  private readonly destroy$ = new Subject<void>();

  loading = true;
  saving = false;
  successMessage = '';
  errorMessage = '';

  activeTab = 'organisation';

  /** Built once the catalogue is in: the categories it actually has. */
  tabs: SettingsTab[] = [];

  // --- the organisation's own record --------------------------------------

  tenant: Tenant | null = null;
  tenantForm: Record<string, string> = {};

  // --- settings ------------------------------------------------------------

  /** Every setting the API honours, grouped by the tab it belongs on. */
  byCategory: Record<string, SettingDefinition[]> = {};

  /** What the form holds. Keyed by setting key. */
  values: Record<string, SettingValue> = {};

  /** What was loaded, so Save can send only what changed. */
  private loaded: Record<string, SettingValue> = {};

  // --- single sign-on ------------------------------------------------------

  /** Every provider, configured or not; env-managed ones are read-only. */
  ssoConnections: SsoConnection[] = [];

  /** Editable form per provider; the secret starts blank (it is never returned). */
  ssoForm: Record<string, {
    enabled: boolean;
    clientId: string;
    clientSecret: string;
    directory: string;
    domains: string;
    autoProvision: boolean;
  }> = {};

  /** The provider whose save/delete is in flight, to disable its controls. */
  ssoSavingProvider: string | null = null;

  /** The redirect URI an administrator registers with the provider. */
  readonly ssoRedirectUri = `${window.location.origin}/sso/callback`;

  private readonly icons: Record<string, string> = {
    organisation: 'bi-building',
    general: 'bi-globe',
    branding: 'bi-palette',
    notifications: 'bi-envelope',
    gamification: 'bi-trophy',
    security: 'bi-shield-lock',
    email: 'bi-envelope-paper',
    sso: 'bi-box-arrow-in-right',
  };

  ngOnInit(): void {
    this.load();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  private load(): void {
    this.loading = true;
    this.errorMessage = '';

    forkJoin({
      tenant: this.tenantService.show(),
      catalogue: this.settingsService.catalogue(),
      values: this.settingsService.show(),
      // Read-only on this screen; it must never stop the rest loading.
      sso: this.ssoService.connections().pipe(
        catchError(() => of([] as SsoConnection[])),
      ),
    })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ tenant, catalogue, values, sso }) => {
          this.tenant = tenant;
          this.tenantForm = {
            name: tenant.name,
            contactEmail: tenant.contactEmail ?? '',
            contactPhone: tenant.contactPhone ?? '',
            address: tenant.address ?? '',
            domain: tenant.domain ?? '',
            logoUrl: tenant.logoUrl ?? '',
            industry: tenant.industry ?? '',
            companySize: tenant.companySize ?? '',
          };

          this.byCategory = {};

          for (const definition of catalogue) {
            (this.byCategory[definition.category] ??= []).push(definition);
          }

          this.values = { ...values };
          this.loaded = { ...values };

          this.ssoConnections = sso;
          this.hydrateSsoForms();

          this.tabs = this.buildTabs(catalogue);
          this.loading = false;
        },
        error: (failure: ApiError) => {
          this.errorMessage = failure.message;
          this.loading = false;
        },
      });
  }

  /**
   * The tabs, from the catalogue plus the two that are not settings.
   *
   * A category with no settings gets no tab, so the screen can never offer an
   * empty one — which is what happened to four of the legacy's eight.
   */
  private buildTabs(catalogue: SettingDefinition[]): SettingsTab[] {
    const tabs: SettingsTab[] = [
      {
        key: 'organisation',
        label: this.i18n.t('tenant.tab_organisation'),
        description: this.i18n.t('tenant.tab_organisation_desc'),
        icon: this.icons['organisation'],
      },
    ];

    const labelKeys: Record<string, string> = {
      general: 'tenant.cat_general',
      branding: 'tenant.cat_branding',
      notifications: 'tenant.cat_notifications',
      gamification: 'tenant.cat_gamification',
      security: 'tenant.cat_security',
    };

    const described: Record<string, string> = {
      general: 'tenant.desc_general',
      branding: 'tenant.desc_branding',
      notifications: 'tenant.desc_notifications',
      gamification: 'tenant.desc_gamification',
      security: 'tenant.desc_security',
    };

    for (const category of Object.keys(this.byCategory)) {
      if (this.byCategory[category].length === 0) {
        continue;
      }

      tabs.push({
        key: category,
        label: labelKeys[category]
          ? this.i18n.t(labelKeys[category])
          : category.charAt(0).toUpperCase() + category.slice(1),
        description: described[category] ? this.i18n.t(described[category]) : '',
        icon: this.icons[category] ?? 'bi-sliders',
      });
    }

    // Always shown, even with nothing configured, so an administrator can see
    // the sign-in setup — including a connection managed from the environment.
    tabs.push({
      key: 'sso',
      label: this.i18n.t('tenant.sso_title'),
      description: this.i18n.t('tenant.sso_desc'),
      icon: this.icons['sso'],
    });

    // Always shown: the panel loads its own list and has its own error state.
    tabs.push({
      key: 'email',
      label: this.i18n.t('tenant.tab_email'),
      description: this.i18n.t('tenant.tab_email_desc'),
      icon: this.icons['email'],
    });

    return tabs;
  }

  setActiveTab(key: string): void {
    this.activeTab = key;
    this.successMessage = '';
    this.errorMessage = '';
  }

  get activeTabInfo(): SettingsTab | undefined {
    return this.tabs.find((t) => t.key === this.activeTab);
  }

  /** How many SSO providers are configured from the environment (read-only). */
  get ssoManagedCount(): number {
    return this.ssoConnections.filter((c) => c.managed).length;
  }

  // --- the organisation ----------------------------------------------------

  get tenantHasChanges(): boolean {
    const t = this.tenant;

    if (t === null) {
      return false;
    }

    return this.tenantForm['name'] !== t.name
      || this.tenantForm['contactEmail'] !== (t.contactEmail ?? '')
      || this.tenantForm['contactPhone'] !== (t.contactPhone ?? '')
      || this.tenantForm['address'] !== (t.address ?? '')
      || this.tenantForm['domain'] !== (t.domain ?? '')
      || this.tenantForm['logoUrl'] !== (t.logoUrl ?? '')
      || this.tenantForm['industry'] !== (t.industry ?? '')
      || this.tenantForm['companySize'] !== (t.companySize ?? '');
  }

  saveTenant(): void {
    this.saving = true;
    this.clearMessages();

    this.tenantService
      .update({
        name: this.tenantForm['name'],
        contactEmail: this.tenantForm['contactEmail'],
        contactPhone: this.tenantForm['contactPhone'],
        address: this.tenantForm['address'],
        domain: this.tenantForm['domain'],
        logoUrl: this.tenantForm['logoUrl'],
        industry: this.tenantForm['industry'],
        companySize: this.tenantForm['companySize'],
      })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (tenant) => {
          this.tenant = tenant;
          this.saving = false;
          this.successMessage = this.i18n.t('tenant.saved');
        },
        error: (failure: ApiError) => this.fail(failure),
      });
  }

  /** How much of its allowance the organisation is using. */
  get storagePercentage(): number {
    const limit = this.tenant?.limits.storage ?? 0;

    if (limit <= 0) {
      return 0;
    }

    return Math.min(100, Math.round(((this.tenant?.usage.storageMb ?? 0) / limit) * 100));
  }

  // --- settings ------------------------------------------------------------

  settingsFor(category: string): SettingDefinition[] {
    return this.byCategory[category] ?? [];
  }

  /** Whether this tab has anything unsaved. */
  hasChanges(category: string): boolean {
    return this.settingsFor(category).some((d) => this.values[d.key] !== this.loaded[d.key]);
  }

  saveCategory(category: string): void {
    const changed: Record<string, SettingValue> = {};

    // Only what moved. A screen showing one tab must not write back the
    // twenty settings on the others.
    for (const definition of this.settingsFor(category)) {
      if (this.values[definition.key] !== this.loaded[definition.key]) {
        changed[definition.key] = this.values[definition.key];
      }
    }

    if (Object.keys(changed).length === 0) {
      return;
    }

    this.saving = true;
    this.clearMessages();

    this.settingsService
      .update(changed)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (saved) => {
          this.values = { ...this.values, ...saved };
          this.loaded = { ...this.loaded, ...saved };
          this.saving = false;
          this.successMessage = this.i18n.t('tenant.saved');
        },
        error: (failure: ApiError) => this.fail(failure),
      });
  }

  async resetCategory(category: string): Promise<void> {
    if (!(await this.dialog.confirm(this.i18n.t('tenant.confirm_reset', { category }), { type: 'danger' }))) {
      return;
    }

    this.saving = true;
    this.clearMessages();

    this.settingsService
      .reset(category as 'general' | 'branding' | 'gamification' | 'security')
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (saved) => {
          this.values = { ...this.values, ...saved };
          this.loaded = { ...this.loaded, ...saved };
          this.saving = false;
          this.successMessage = this.i18n.t('tenant.put_back_defaults');
        },
        error: (failure: ApiError) => this.fail(failure),
      });
  }

  /** For `[(ngModel)]` on a checkbox, which wants a real boolean. */
  boolValue(key: string): boolean {
    return this.values[key] === true;
  }

  setBool(key: string, value: boolean): void {
    this.values[key] = value;
  }

  setNumber(key: string, value: string): void {
    const parsed = Number(value);
    this.values[key] = Number.isFinite(parsed) ? parsed : 0;
  }

  asString(key: string): string {
    const value = this.values[key];

    return value === undefined || value === null ? '' : String(value);
  }

  // --- email templates -----------------------------------------------------

  /** The logo the email header uses: the email logo, else the main one. */
  get emailLogoUrl(): string {
    return this.asString('email_header_logo_url').trim()
      || this.asString('logo_url').trim()
      || (this.tenant?.logoUrl ?? '');
  }

  get hasBrandingTab(): boolean {
    return this.tabs.some((t) => t.key === 'branding');
  }

  /** The logo settings an image can be uploaded for, and the asset each maps to. */
  logoAsset(key: string): 'logo' | 'email-logo' | null {
    return key === 'logo_url' ? 'logo' : key === 'email_header_logo_url' ? 'email-logo' : null;
  }

  /** Which logo is uploading, so only its button shows a spinner. */
  uploadingLogo: string | null = null;

  /**
   * Uploads a logo to the API's public address (what inboxes can load) and
   * puts it in the field. The server saves the setting itself, so the form
   * treats the new address as already saved.
   */
  uploadLogo(key: string, event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    const asset = this.logoAsset(key);

    input.value = '';

    if (!file || asset === null || this.uploadingLogo !== null) {
      return;
    }

    this.uploadingLogo = key;
    this.successMessage = '';
    this.errorMessage = '';

    this.emailTemplates
      .uploadLogo(asset, file)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.values[key] = result.url;
          this.loaded[key] = result.url;
          this.uploadingLogo = null;
          this.successMessage = this.i18n.t('tenant.saved');
        },
        error: (failure: ApiError) => {
          this.uploadingLogo = null;
          const first = failure?.violations ? Object.values(failure.violations).flat()[0] : undefined;
          this.errorMessage = first ?? failure?.message ?? this.i18n.t('tenant.email_error');
        },
      });
  }

  // --- shared --------------------------------------------------------------

  goBack(): void {
    void this.router.navigate(['/administration']);
  }

  // --- single sign-on ------------------------------------------------------

  /** The connection for a provider, configured or a blank placeholder. */
  ssoConn(provider: string): SsoConnection | undefined {
    return this.ssoConnections.find((c) => c.provider === provider);
  }

  /** Rebuild the editable forms from the connections (secret always blank). */
  private hydrateSsoForms(): void {
    this.ssoForm = {};

    for (const c of this.ssoConnections) {
      this.ssoForm[c.provider] = {
        enabled: c.enabled,
        clientId: c.clientId,
        clientSecret: '',
        directory: c.directory ?? '',
        domains: c.domains.join(', '),
        autoProvision: c.autoProvision,
      };
    }
  }

  saveSso(provider: SsoProviderId): void {
    const form = this.ssoForm[provider];

    if (!form || this.ssoSavingProvider) {
      return;
    }

    this.clearMessages();
    this.ssoSavingProvider = provider;

    const input: SsoConnectionInput = {
      clientId: form.clientId.trim(),
      enabled: form.enabled,
      // Only Microsoft has a directory; Google is narrowed by domains instead.
      directory: provider === 'microsoft' ? form.directory.trim() || null : null,
      domains: form.domains,
      autoProvision: form.autoProvision,
    };

    // A blank secret keeps the one already stored; the endpoint never returns it.
    const secret = form.clientSecret.trim();
    if (secret !== '') {
      input.clientSecret = secret;
    }

    this.ssoService
      .save(provider, input)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (saved) => {
          this.ssoConnections = this.ssoConnections.map((c) => (c.provider === provider ? saved : c));
          this.hydrateSsoForms();
          this.ssoSavingProvider = null;
          this.successMessage = this.i18n.t('tenant.saved');
        },
        error: (failure: ApiError) => {
          this.ssoSavingProvider = null;
          this.fail(failure);
        },
      });
  }

  async removeSso(provider: SsoProviderId): Promise<void> {
    const label = this.ssoConn(provider)?.providerLabel ?? provider;

    if (!(await this.dialog.confirm(this.i18n.t('tenant.sso_confirm_remove', { provider: label }), { type: 'danger' }))) {
      return;
    }

    this.clearMessages();
    this.ssoSavingProvider = provider;

    this.ssoService
      .remove(provider)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.ssoConnections = this.ssoConnections.map((c) =>
            c.provider === provider
              ? { ...c, id: null, enabled: false, clientId: '', hasSecret: false, directory: null, domains: [], autoProvision: false, managed: false }
              : c,
          );
          this.hydrateSsoForms();
          this.ssoSavingProvider = null;
          this.successMessage = this.i18n.t('tenant.sso_removed');
        },
        error: (failure: ApiError) => {
          this.ssoSavingProvider = null;
          this.fail(failure);
        },
      });
  }

  private fail(failure: ApiError): void {
    this.saving = false;

    const first = Object.values(failure.violations).flat()[0];

    this.errorMessage = first ?? failure.message;
  }

  private clearMessages(): void {
    this.successMessage = '';
    this.errorMessage = '';
  }
}
