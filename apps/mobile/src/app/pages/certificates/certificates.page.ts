import { Component, inject, signal } from '@angular/core';
import { IonContent, IonIcon, IonRefresher, IonRefresherContent, IonSkeletonText } from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { Certificate } from '../../core/models';
import { BrandHeaderComponent } from '../../components/brand-header/brand-header.component';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';

@Component({
  selector: 'app-certificates',
  standalone: true,
  imports: [
    IonContent, IonIcon, IonRefresher, IonRefresherContent, IonSkeletonText,
    BrandHeaderComponent, EmptyStateComponent,
  ],
  template: `
    <ion-content [fullscreen]="true">
      <ion-refresher slot="fixed" (ionRefresh)="reload($event)">
        <ion-refresher-content></ion-refresher-content>
      </ion-refresher>

      <app-brand-header title="Certificates" subtitle="Your earned credentials"></app-brand-header>

      <div class="pad">
        @if (loading()) {
          <ion-skeleton-text animated style="width:100%; height:200px; border-radius:20px;"></ion-skeleton-text>
        } @else if (failed()) {
          <app-empty-state
            icon="cloud-offline-outline"
            title="Couldn't load your certificates"
            message="Check your connection and try again."
            actionLabel="Try again"
            (action)="load()"></app-empty-state>
        } @else if (!certs().length) {
          <app-empty-state
            icon="ribbon-outline"
            title="No certificates yet"
            message="Finish a course or pass an assessment to earn your first one."></app-empty-state>
        } @else {
          @for (c of certs(); track c.id) {
            <div class="cert rise">
              <div class="cert-seal">
                <ion-icon name="ribbon" aria-hidden="true"></ion-icon>
                <div class="cert-seal-tx">
                  <p class="cert-src">{{ c.sourceLabel }}</p>
                  <p class="cert-issued">Issued {{ fmt(c.issuedAt) }}</p>
                </div>
                @if (c.grade) { <span class="cert-grade">{{ c.grade }}</span> }
              </div>

              <div class="cert-main">
                <h2 class="cert-title">{{ c.title }}</h2>

                <div class="cert-stats">
                  @if (c.percentage !== null) {
                    <div class="cs"><span class="csv">{{ c.percentage }}%</span><span class="csk">Score</span></div>
                  }
                  <div class="cs"><span class="csv">{{ shortDate(c.issuedAt) }}</span><span class="csk">Issued</span></div>
                  <div class="cs"><span class="csv">{{ c.validUntil ? shortDate(c.validUntil) : 'No expiry' }}</span><span class="csk">Valid until</span></div>
                </div>

                <div class="cert-ids">
                  <div class="cid"><span class="k">Certificate no.</span><span class="v">{{ c.number }}</span></div>
                  <div class="cid"><span class="k">Verification code</span><span class="v mono">{{ c.verificationCode }}</span></div>
                </div>

                <div class="cert-foot">
                  <span class="cert-status" [class.ok]="c.isValid" [class.bad]="!c.isValid">
                    <ion-icon [name]="c.isValid ? 'checkmark-circle' : 'alert-circle'" aria-hidden="true"></ion-icon>
                    {{ c.statusLabel }}
                  </span>
                  @if (c.daysRemaining !== null && c.daysRemaining > 0 && c.daysRemaining <= 60) {
                    <span class="cert-exp">Expires in {{ c.daysRemaining }} days</span>
                  }
                </div>
              </div>
            </div>
          }
        }
      </div>
    </ion-content>
  `,
  styles: [`
    .pad { padding: 14px 16px calc(16px + env(safe-area-inset-bottom)); }

    .cert { background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r-lg); box-shadow: var(--app-shadow-lg); overflow: hidden; margin-bottom: 14px; }

    .cert-seal {
      position: relative; display: flex; align-items: center; gap: 13px; padding: 18px; color: #fff;
      background:
        radial-gradient(120% 120% at 90% -20%, color-mix(in srgb, var(--brand-primary, var(--ion-color-primary)) 40%, #ffffff) 0%, transparent 55%),
        linear-gradient(135deg, var(--brand-primary, var(--ion-color-primary)), var(--brand-primary-shade, var(--ion-color-primary-shade)));
    }
    .cert-seal > ion-icon { font-size: 34px; flex: none; }
    .cert-seal-tx { min-width: 0; }
    .cert-src { margin: 0; font-size: .7rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: rgba(255,255,255,.85); }
    .cert-issued { margin: 2px 0 0; font-size: .82rem; color: rgba(255,255,255,.8); }
    .cert-grade { margin-left: auto; flex: none; width: 46px; height: 46px; border-radius: 50%; background: rgba(255,255,255,.22); display: grid; place-items: center; font-family: var(--app-font-display); font-weight: 800; font-size: 1.35rem; -webkit-backdrop-filter: blur(4px); backdrop-filter: blur(4px); }

    .cert-main { padding: 16px 18px 18px; }
    .cert-title { margin: 0; font-family: var(--app-font-display); font-size: 1.28rem; line-height: 1.16; font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); color: var(--app-ink); text-wrap: balance; }

    .cert-stats { display: flex; gap: 22px; margin: 14px 0 2px; }
    .cs { display: flex; flex-direction: column; }
    .csv { font-family: var(--app-font-display); font-weight: 800; font-size: 1.02rem; color: var(--app-ink); }
    .csk { font-size: .68rem; color: var(--app-muted); margin-top: 1px; }

    .cert-ids { margin-top: 16px; display: grid; gap: 10px; padding-top: 14px; border-top: 1px solid var(--app-line); }
    .cid { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .cid .k { font-size: .8rem; color: var(--app-muted); }
    .cid .v { font-size: .84rem; font-weight: 600; color: var(--app-ink); }
    .cid .v.mono { font-family: ui-monospace, Menlo, Consolas, monospace; letter-spacing: .04em; }

    .cert-foot { display: flex; align-items: center; gap: 10px; margin-top: 16px; }
    .cert-status { display: inline-flex; align-items: center; gap: 5px; font-size: .78rem; font-weight: 700; padding: 5px 11px; border-radius: 999px; }
    .cert-status ion-icon { font-size: 15px; }
    .cert-status.ok { color: var(--ion-color-success); background: color-mix(in srgb, var(--ion-color-success) 15%, transparent); }
    .cert-status.bad { color: var(--ion-color-danger); background: color-mix(in srgb, var(--ion-color-danger) 15%, transparent); }
    .cert-exp { font-size: .76rem; color: var(--ion-color-warning); font-weight: 600; }
  `],
})
export class CertificatesPage {
  private readonly learner = inject(LearnerService);

  readonly loading = signal(true);
  readonly failed = signal(false);
  readonly certs = signal<Certificate[]>([]);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.learner.certificates().subscribe({
      next: (c) => { this.certs.set(c); this.loading.set(false); },
      error: () => { this.certs.set([]); this.failed.set(true); this.loading.set(false); },
    });
  }

  reload(ev?: CustomEvent): void {
    this.learner.certificates().subscribe({
      next: (c) => { this.certs.set(c); (ev?.target as { complete?: () => void })?.complete?.(); },
      error: () => { this.failed.set(true); (ev?.target as { complete?: () => void })?.complete?.(); },
    });
  }

  fmt(iso: string | null): string {
    if (!iso) { return '—'; }
    const d = new Date(iso);
    return isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' });
  }

  shortDate(iso: string | null): string {
    if (!iso) { return '—'; }
    const d = new Date(iso);
    return isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  }
}
