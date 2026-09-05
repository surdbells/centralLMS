import { Component, OnInit, inject, signal } from '@angular/core';
import {
  IonContent, IonIcon, IonSkeletonText, IonRefresher, IonRefresherContent,
} from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { LearnerDashboard } from '../../core/models';
import { BrandHeaderComponent } from '../../components/brand-header/brand-header.component';

@Component({
  selector: 'app-progress',
  standalone: true,
  imports: [
    IonContent, IonIcon, IonSkeletonText, IonRefresher, IonRefresherContent,
    BrandHeaderComponent,
  ],
  template: `
    <ion-content [fullscreen]="true">
      <ion-refresher slot="fixed" (ionRefresh)="reload($event)">
        <ion-refresher-content></ion-refresher-content>
      </ion-refresher>

      <app-brand-header title="Progress"></app-brand-header>

      <div class="pad">
        @if (loading()) {
          <ion-skeleton-text animated style="width:100%; height:140px; border-radius:18px;"></ion-skeleton-text>
          <ion-skeleton-text animated style="width:50%; height:20px; margin-top:20px;"></ion-skeleton-text>
        } @else if (data()) {
        @if (data(); as d) {
          <!-- Ring -->
          <div class="ring-card">
            <div class="ring" [style.--p]="d.overview.overallProgress">
              <div class="ring-in">
                <span class="pct">{{ d.overview.overallProgress }}%</span>
                <span class="lbl muted">overall</span>
              </div>
            </div>
            <div class="ring-side">
              <div class="mini"><span class="n">{{ d.overview.completed }}</span><span class="l muted">Completed</span></div>
              <div class="mini"><span class="n">{{ d.overview.inProgress }}</span><span class="l muted">In progress</span></div>
              <div class="mini"><span class="n">{{ d.overview.enrolled }}</span><span class="l muted">Enrolled</span></div>
            </div>
          </div>

          <!-- Metric tiles -->
          <div class="tiles">
            <div class="tile">
              <ion-icon name="trophy-outline"></ion-icon>
              <span class="n">{{ d.overview.points }}</span><span class="l muted">Points</span>
            </div>
            <div class="tile">
              <ion-icon name="time-outline"></ion-icon>
              <span class="n">{{ hours(d.overview.secondsSpent) }}</span><span class="l muted">Learning time</span>
            </div>
            <div class="tile">
              <ion-icon name="flame-outline"></ion-icon>
              <span class="n">{{ d.streak.current }}</span><span class="l muted">Day streak</span>
            </div>
          </div>

          <!-- Streak week -->
          @if (d.streak.week.length) {
            <div class="section-title">This week</div>
            <div class="week">
              @for (day of d.streak.week; track day.date) {
                <div class="day">
                  <div class="dot" [class.on]="day.active"></div>
                  <span class="dl muted">{{ day.day.charAt(0) }}</span>
                </div>
              }
            </div>
          }

          <!-- Certificates -->
          <div class="section-title">Certificates</div>
          @if (d.certificates.length) {
            @for (c of d.certificates; track c.id) {
              <div class="cert">
                <div class="cert-badge"><ion-icon name="ribbon"></ion-icon></div>
                <div class="cert-body">
                  <p class="cert-title">{{ c.title }}</p>
                  <p class="muted">No. {{ c.number }}{{ c.issuedAt ? ' · ' + fmt(c.issuedAt) : '' }}</p>
                </div>
                <span class="pill" [class.warn]="!c.isValid">{{ c.statusLabel }}</span>
              </div>
            }
          } @else {
            <div class="empty">
              <ion-icon name="ribbon-outline"></ion-icon>
              <p>No certificates yet — finish a course to earn one.</p>
            </div>
          }
          <div style="height: 16px"></div>
        }
        } @else {
          <div class="empty">
            <ion-icon name="cloud-offline-outline"></ion-icon>
            <p>Couldn't load your progress.</p>
            <a (click)="reload()">Try again</a>
          </div>
        }
      </div>
    </ion-content>
  `,
  styles: [`
    .pad { padding: 14px 16px calc(16px + env(safe-area-inset-bottom)); }
    .ring-card { display: flex; align-items: center; gap: 18px; background: var(--ion-card-background); border-radius: 18px; padding: 18px; box-shadow: 0 8px 22px rgba(0,0,0,.07); }
    .ring { --p: 0; width: 108px; height: 108px; border-radius: 50%; flex: 0 0 auto;
      background: conic-gradient(var(--ion-color-primary) calc(var(--p) * 1%), var(--ion-color-light, #e6e8f0) 0);
      display: grid; place-items: center; }
    .ring-in { width: 82px; height: 82px; border-radius: 50%; background: var(--ion-card-background); display: grid; place-items: center; }
    .ring-in .pct { font-size: 1.35rem; font-weight: 800; color: var(--ion-color-primary); }
    .ring-in .lbl { font-size: .68rem; }
    .ring-side { flex: 1; display: grid; gap: 10px; }
    .mini { display: flex; justify-content: space-between; align-items: baseline; }
    .mini .n { font-size: 1.2rem; font-weight: 800; }
    .mini .l { font-size: .8rem; }
    .tiles { display: grid; grid-template-columns: repeat(3,1fr); gap: 10px; margin-top: 12px; }
    .tile { background: var(--ion-card-background); border-radius: 14px; padding: 14px 8px; text-align: center; box-shadow: 0 4px 14px rgba(0,0,0,.05); }
    .tile ion-icon { font-size: 22px; color: var(--ion-color-primary); }
    .tile .n { display: block; font-size: 1.1rem; font-weight: 800; margin-top: 4px; }
    .tile .l { font-size: .68rem; }
    .section-title { font-size: 1.05rem; font-weight: 700; margin: 22px 0 10px; }
    .week { display: flex; justify-content: space-between; background: var(--ion-card-background); border-radius: 14px; padding: 14px 12px; box-shadow: 0 4px 14px rgba(0,0,0,.05); }
    .day { text-align: center; }
    .dot { width: 26px; height: 26px; border-radius: 50%; background: var(--ion-color-light, #e6e8f0); margin: 0 auto 6px; }
    .dot.on { background: var(--ion-color-primary); }
    .dl { font-size: .72rem; }
    .cert { display: flex; align-items: center; gap: 12px; background: var(--ion-card-background); border-radius: 14px; padding: 12px; margin-bottom: 10px; box-shadow: 0 4px 14px rgba(0,0,0,.05); }
    .cert-badge { width: 46px; height: 46px; border-radius: 12px; display: grid; place-items: center; background: linear-gradient(135deg, var(--ion-color-warning), #d98a1f); flex: 0 0 auto; }
    .cert-badge ion-icon { font-size: 24px; color: #fff; }
    .cert-body { flex: 1; min-width: 0; }
    .cert-title { margin: 0; font-weight: 700; font-size: .92rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cert-body .muted { margin: 2px 0 0; font-size: .76rem; }
    .pill { font-size: .68rem; font-weight: 700; padding: 4px 8px; border-radius: 20px; background: rgba(45,157,91,.14); color: var(--ion-color-success); flex: 0 0 auto; }
    .pill.warn { background: rgba(232,163,58,.16); color: var(--ion-color-warning); }
    .empty { text-align: center; padding: 34px 16px; color: var(--ion-color-medium); }
    .empty ion-icon { font-size: 40px; }
    .empty a { color: var(--ion-color-primary); font-weight: 600; }
  `],
})
export class ProgressPage implements OnInit {
  private readonly learner = inject(LearnerService);

  readonly data = signal<LearnerDashboard | null>(null);
  readonly loading = signal(true);

  ngOnInit(): void {
    this.load();
  }

  reload(ev?: CustomEvent): void {
    this.load(ev);
  }

  private load(ev?: CustomEvent): void {
    if (!ev) {
      this.loading.set(true);
    }
    this.learner.dashboard().subscribe({
      next: (d) => { this.data.set(d); this.loading.set(false); (ev?.target as { complete?: () => void })?.complete?.(); },
      error: () => { this.data.set(null); this.loading.set(false); (ev?.target as { complete?: () => void })?.complete?.(); },
    });
  }

  hours(seconds: number): string {
    const m = Math.round((seconds ?? 0) / 60);
    if (m < 60) {
      return `${m}m`;
    }
    return `${Math.floor(m / 60)}h`;
  }

  fmt(iso: string): string {
    const d = new Date(iso);
    return isNaN(d.getTime()) ? '' : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  }
}
