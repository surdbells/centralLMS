import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { IonContent, IonIcon } from '@ionic/angular/standalone';
import { BrandingService } from '../../core/branding.service';
import { I18nService } from '../../core/i18n.service';
import { TranslatePipe } from '../../core/translate.pipe';
import { FieldComponent } from '../../components/field/field.component';
import { ButtonComponent } from '../../components/button/button.component';

/**
 * First stop on a multi-tenant cloud with no default organisation: a device has
 * no subdomain, so the learner names their institution by its code. Resolving it
 * wears that organisation's brand and hands sign-in the tenant its credentials
 * belong to. Skipped entirely on a single-tenant / default deployment.
 */
@Component({
  selector: 'app-institution',
  standalone: true,
  imports: [FormsModule, IonContent, IonIcon, FieldComponent, ButtonComponent, TranslatePipe],
  template: `
    <ion-content [fullscreen]="true" class="inst-bg">
      <div class="inst-scrim"></div>

      <div class="inst-wrap">
        <div class="brand">
          <div class="brand-mark"><ion-icon name="school-outline"></ion-icon></div>
          <h1>{{ branding.organisation() || 'Learning Portal' }}</h1>
          <p class="muted">{{ 'institution.find' | translate }}</p>
        </div>

        <div class="inst-card">
          <p class="lead">{{ 'institution.enter_code_lead' | translate }}</p>
          <app-field
            class="field"
            [label]="'institution.code_label' | translate"
            icon="business-outline"
            [placeholder]="'institution.code_placeholder' | translate"
            autocomplete="off"
            enterkeyhint="go"
            [(ngModel)]="code"
            (enter)="submit()"
            [error]="error()"></app-field>
          <app-button block size="lg" class="submit" [loading]="busy()" (click)="submit()">{{ 'common.continue' | translate }}</app-button>
        </div>

        <p class="foot muted">{{ 'institution.no_code' | translate }}</p>
      </div>
    </ion-content>
  `,
  styles: [`
    .inst-bg { --background: var(--brand-primary, var(--ion-color-primary)); }
    .inst-scrim { position: absolute; inset: 0;
      background: linear-gradient(180deg,
        color-mix(in srgb, var(--brand-primary, #2400a0) 62%, transparent) 0%,
        var(--brand-primary-shade, var(--ion-color-primary-shade)) 82%); }
    .inst-wrap { position: relative; z-index: 1; min-height: 100%; display: flex; flex-direction: column; justify-content: center; padding: calc(24px + env(safe-area-inset-top)) 20px calc(24px + env(safe-area-inset-bottom)); }
    .brand { text-align: center; color: #fff; margin-bottom: 24px; }
    .brand-mark { width: 68px; height: 68px; margin: 0 auto 12px; border-radius: 20px; background: rgba(255,255,255,.16); display: grid; place-items: center; }
    .brand-mark ion-icon { font-size: 36px; color: #fff; }
    .brand h1 { font-family: var(--app-font-display); font-size: 1.55rem; font-weight: var(--app-display-weight); margin: 0; letter-spacing: var(--app-display-tracking); }
    .brand .muted { color: rgba(255,255,255,.82); margin-top: 4px; }
    .inst-card { background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r-lg); padding: 18px 14px 16px; box-shadow: var(--app-shadow-lg); }
    .lead { margin: 0 2px 4px; font-size: .9rem; line-height: 1.5; color: var(--app-muted); }
    .field { display: block; margin-top: 12px; }
    .submit { display: block; margin-top: 18px; }
    .foot { text-align: center; color: rgba(255,255,255,.75); margin-top: 18px; font-size: .82rem; }
  `],
})
export class InstitutionPage {
  readonly branding = inject(BrandingService);
  private readonly router = inject(Router);
  private readonly i18n = inject(I18nService);

  code = '';
  readonly busy = signal(false);
  readonly error = signal('');

  submit(): void {
    if (this.busy()) {
      return;
    }
    const code = this.code.trim();
    if (!code) {
      this.error.set(this.i18n.t('institution.err_empty'));
      return;
    }
    this.busy.set(true);
    this.error.set('');
    this.branding.resolveByCode(code).subscribe((ok) => {
      this.busy.set(false);
      if (ok) {
        void this.router.navigateByUrl('/login', { replaceUrl: true });
      } else {
        this.error.set(this.i18n.t('institution.err_not_found'));
      }
    });
  }
}
