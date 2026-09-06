import { Component, OnInit, inject, signal } from '@angular/core';
import {
  IonContent, IonIcon, IonSkeletonText, IonRefresher, IonRefresherContent,
} from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { LearnerDashboard } from '../../core/models';
import { BrandHeaderComponent } from '../../components/brand-header/brand-header.component';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';

@Component({
  selector: 'app-progress',
  standalone: true,
  imports: [
    IonContent, IonIcon, IonSkeletonText, IonRefresher, IonRefresherContent,
    BrandHeaderComponent, EmptyStateComponent,
  ],
  template: `
    <ion-content [fullscreen]="true">
      <ion-refresher slot="fixed" (ionRefresh)="reload($event)">
        <ion-refresher-content></ion-refresher-content>
      </ion-refresher>

      <app-brand-header title="Progress" subtitle="Track your learning journey"></app-brand-header>

      <div class="pad">
        @if (loading()) {
          <ion-skeleton-text animated style="width:100%; height:140px; border-radius:18px;"></ion-skeleton-text>
          <ion-skeleton-text animated style="width:50%; height:20px; margin-top:20px;"></ion-skeleton-text>
        } @else if (data()) {
        @if (data(); as d) {
          <!-- Ring -->
          <div class="ring-card rise">
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
            <app-empty-state
              icon="ribbon-outline"
              title="No certificates yet"
              message="Finish a course to earn your first one."></app-empty-state>
          }
          <div style="height: 16px"></div>
        }
        } @else {
          <app-empty-state
            icon="cloud-offline-outline"
            title="Couldn't load your progress"
            message="Check your connection and try again."
            actionLabel="Try again"
            (action)="reload()"></app-empty-state>
        }
      </div>
    </ion-content>
  `,
  styles: [`
    .pad { padding: 14px 16px calc(16px + env(safe-area-inset-bottom)); }
    .ring-card { display: flex; align-items: center; gap: 18px; background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r); padding: 18px; box-shadow: var(--app-shadow); }
    .ring { --p: 0; width: 108px; height: 108px; border-radius: 50%; flex: 0 0 auto;
      background: conic-gradient(var(--ion-color-primary) calc(var(--p) * 1%), var(--app-track) 0);
      display: grid; place-items: center; }
    .ring-in { width: 82px; height: 82px; border-radius: 50%; background: var(--app-surface); display: grid; place-items: center; }
    .ring-in .pct { font-size: 1.35rem; font-family: var(--app-font-display); font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); color: var(--ion-color-primary); }
    .ring-in .lbl { font-size: .68rem; }
    .ring-side { flex: 1; display: grid; gap: 10px; }
    .mini { display: flex; justify-content: space-between; align-items: baseline; }
    .mini .n { font-size: 1.2rem; font-family: var(--app-font-display); font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); }
    .mini .l { font-size: .8rem; }
    .tiles { display: grid; grid-template-columns: repeat(3,1fr); gap: 10px; margin-top: 12px; }
    .tile { background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r-sm); padding: 14px 8px; text-align: center; box-shadow: var(--app-shadow); }
    .tile ion-icon { font-size: 22px; color: var(--ion-color-primary); }
    .tile .n { display: block; font-size: 1.1rem; font-family: var(--app-font-display); font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); margin-top: 4px; }
    .tile .l { font-size: .68rem; }
    .section-title { margin: 22px 0 10px; }
    .week { display: flex; justify-content: space-between; background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r-sm); padding: 14px 12px; box-shadow: var(--app-shadow); }
    .day { text-align: center; }
    .dot { width: 26px; height: 26px; border-radius: 50%; background: var(--app-track); margin: 0 auto 6px; }
    .dot.on { background: var(--ion-color-primary); }
    .dl { font-size: .72rem; }
    .cert { display: flex; align-items: center; gap: 12px; background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r-sm); padding: 12px; margin-bottom: 10px; box-shadow: var(--app-shadow); }
    .cert-badge { width: 46px; height: 46px; border-radius: var(--app-r-sm); display: grid; place-items: center; background: linear-gradient(135deg, var(--ion-color-warning), #d98a1f); flex: 0 0 auto; }
    .cert-badge ion-icon { font-size: 24px; color: #fff; }
    .cert-body { flex: 1; min-width: 0; }
    .cert-title { margin: 0; font-weight: 600; font-size: .92rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cert-body .muted { margin: 2px 0 0; font-size: .76rem; }
    .pill { font-size: .68rem; font-weight: 700; padding: 4px 8px; border-radius: 20px; background: color-mix(in srgb, var(--ion-color-success) 15%, transparent); color: var(--ion-color-success); flex: 0 0 auto; }
    .pill.warn { background: color-mix(in srgb, var(--ion-color-warning) 16%, transparent); color: var(--ion-color-warning); }
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
