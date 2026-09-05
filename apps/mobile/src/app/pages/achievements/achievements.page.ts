import { Component, inject, signal } from '@angular/core';
import { forkJoin } from 'rxjs';
import { IonContent, IonIcon, IonRefresher, IonRefresherContent, IonSkeletonText } from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { Gamification, PointEvent } from '../../core/models';
import { timeAgo } from '../../core/ui';
import { BrandHeaderComponent } from '../../components/brand-header/brand-header.component';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';
import { ProgressComponent } from '../../components/progress/progress.component';

@Component({
  selector: 'app-achievements',
  standalone: true,
  imports: [
    IonContent, IonIcon, IonRefresher, IonRefresherContent, IonSkeletonText,
    BrandHeaderComponent, EmptyStateComponent, ProgressComponent,
  ],
  template: `
    <ion-content [fullscreen]="true">
      <ion-refresher slot="fixed" (ionRefresh)="reload($event)">
        <ion-refresher-content></ion-refresher-content>
      </ion-refresher>

      <app-brand-header title="Achievements" subtitle="Your points and progress"></app-brand-header>

      <div class="pad">
        @if (loading()) {
          <ion-skeleton-text animated style="width:100%; height:150px; border-radius:20px;"></ion-skeleton-text>
        } @else if (failed()) {
          <app-empty-state
            icon="cloud-offline-outline"
            title="Couldn't load your achievements"
            message="Check your connection and try again."
            actionLabel="Try again"
            (action)="load()"></app-empty-state>
        } @else if (game()) {
          @if (game(); as g) {
          <!-- Points hero -->
          <div class="ach-hero">
            <div class="ach-pts">
              <span class="ach-pts-n">{{ g.points }}</span>
              <span class="ach-pts-l">points earned</span>
            </div>
            @if (g.rank; as r) {
              <div class="ach-rank"><ion-icon name="trophy-outline" aria-hidden="true"></ion-icon> #{{ r.rank }}</div>
            }
            @if (g.level.current || g.level.next) {
              <div class="ach-level">
                <div class="ach-level-top">
                  <span>{{ g.level.current || 'Getting started' }}</span>
                  @if (g.level.next) { <span class="muted">{{ g.level.next }}</span> }
                </div>
                <app-progress [value]="g.level.percentToNext / 100"></app-progress>
                @if (g.level.pointsToNext) {
                  <p class="ach-level-hint">{{ g.level.pointsToNext }} points to {{ g.level.next }}</p>
                }
              </div>
            }
          </div>

          @if (g.badges.length) {
            <div class="section-title">Badges</div>
            <div class="ach-badges">
              @for (b of g.badges; track b.id) {
                <div class="ach-badge">
                  <div class="ach-badge-ic"><ion-icon name="star" aria-hidden="true"></ion-icon></div>
                  <span class="ach-badge-nm">{{ b.name }}</span>
                </div>
              }
            </div>
          }

          @if (g.breakdown.length) {
            <div class="section-title">Where your points come from</div>
            <div class="ach-card">
              @for (b of g.breakdown; track b.reason) {
                <div class="ach-brow">
                  <span class="ach-blabel">{{ b.label }}</span>
                  <span class="ach-bpts">+{{ b.points }}</span>
                </div>
              }
            </div>
          }

          @if (history().length) {
            <div class="section-title">Points history</div>
            @for (h of history(); track h.id) {
              <div class="ach-hrow">
                <span class="ach-hpts">+{{ h.points }}</span>
                <div class="ach-hbody">
                  <p class="ach-htitle">{{ h.reasonLabel }}</p>
                  @if (h.subjectTitle) { <p class="ach-hsub">{{ h.subjectTitle }}</p> }
                </div>
                <span class="ach-htime">{{ ago(h.earnedAt) }}</span>
              </div>
            }
          }

          @if (!g.points && !history().length) {
            <app-empty-state
              icon="trophy-outline"
              title="No points yet"
              message="Finish courses and pass assessments to start earning."></app-empty-state>
          }
          }
        }
      </div>
    </ion-content>
  `,
  styles: [`
    .pad { padding: 14px 16px calc(16px + env(safe-area-inset-bottom)); }

    .ach-hero {
      position: relative; overflow: hidden; isolation: isolate;
      border-radius: var(--app-r-lg); padding: 22px; color: #fff;
      background:
        radial-gradient(130% 90% at 88% -25%, color-mix(in srgb, var(--brand-primary, var(--ion-color-primary)) 40%, #ffffff) 0%, transparent 55%),
        linear-gradient(150deg, var(--brand-primary, var(--ion-color-primary)) 0%, var(--brand-primary-shade, var(--ion-color-primary-shade)) 100%);
      box-shadow: var(--app-shadow-lg);
    }
    .ach-hero::before { content: ''; position: absolute; z-index: -1; top: -60px; right: -40px; width: 180px; height: 180px; border-radius: 50%; background: radial-gradient(circle, rgba(255,255,255,.2), transparent 68%); }
    .ach-pts { display: flex; flex-direction: column; }
    .ach-pts-n { font-family: var(--app-font-display); font-weight: 800; font-size: 3rem; line-height: 1; letter-spacing: -.02em; }
    .ach-pts-l { margin-top: 4px; font-size: .82rem; opacity: .85; }
    .ach-rank { position: absolute; top: 20px; right: 20px; display: inline-flex; align-items: center; gap: 5px; font-family: var(--app-font-display); font-weight: 800; font-size: 1.05rem; background: rgba(255,255,255,.2); padding: 6px 12px; border-radius: 999px; -webkit-backdrop-filter: blur(4px); backdrop-filter: blur(4px); }
    .ach-rank ion-icon { font-size: 16px; }
    .ach-level { margin-top: 18px; }
    .ach-level-top { display: flex; justify-content: space-between; font-size: .82rem; font-weight: 600; margin-bottom: 7px; }
    .ach-level-top .muted { color: rgba(255,255,255,.75); }
    .ach-level app-progress { --app-track: rgba(255,255,255,.25); }
    .ach-level-hint { margin: 8px 0 0; font-size: .78rem; opacity: .82; }

    .section-title { font-family: var(--app-font-display); font-size: 1.05rem; font-weight: 700; letter-spacing: var(--app-display-tracking); margin: 22px 0 10px; }

    .ach-card { background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r); box-shadow: var(--app-shadow); overflow: hidden; }
    .ach-brow { display: flex; align-items: center; justify-content: space-between; padding: 13px 14px; }
    .ach-brow + .ach-brow { border-top: 1px solid var(--app-line); }
    .ach-blabel { font-size: .92rem; color: var(--app-ink); }
    .ach-bpts { font-family: var(--app-font-display); font-weight: 800; color: var(--ion-color-primary); }

    .ach-badges { display: grid; grid-template-columns: repeat(auto-fill, minmax(90px, 1fr)); gap: 10px; }
    .ach-badge { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 14px 8px; background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r); box-shadow: var(--app-shadow); text-align: center; }
    .ach-badge-ic { width: 44px; height: 44px; border-radius: 50%; display: grid; place-items: center; color: var(--ion-color-warning); background: color-mix(in srgb, var(--ion-color-warning) 16%, transparent); }
    .ach-badge-ic ion-icon { font-size: 24px; }
    .ach-badge-nm { font-size: .76rem; font-weight: 600; color: var(--app-ink); }

    .ach-hrow { display: flex; align-items: center; gap: 12px; padding: 11px 2px; }
    .ach-hrow + .ach-hrow { border-top: 1px solid var(--app-line); }
    .ach-hpts { flex: none; width: 46px; height: 40px; border-radius: var(--app-r-sm); display: grid; place-items: center; font-family: var(--app-font-display); font-weight: 800; font-size: .92rem; color: var(--app-tag-ink); background: var(--app-tag-bg); }
    .ach-hbody { flex: 1; min-width: 0; }
    .ach-htitle { margin: 0; font-weight: 600; font-size: .92rem; color: var(--app-ink); }
    .ach-hsub { margin: 2px 0 0; font-size: .78rem; color: var(--app-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .ach-htime { flex: none; font-size: .74rem; color: var(--app-muted); }
  `],
})
export class AchievementsPage {
  private readonly learner = inject(LearnerService);

  readonly loading = signal(true);
  readonly failed = signal(false);
  readonly game = signal<Gamification | null>(null);
  readonly history = signal<PointEvent[]>([]);

  readonly ago = timeAgo;

  constructor() {
    this.load();
  }

  load(ev?: CustomEvent): void {
    if (!ev) {
      this.loading.set(true);
    }
    this.failed.set(false);
    forkJoin({
      game: this.learner.gamification(),
      history: this.learner.pointsHistory(),
    }).subscribe({
      next: ({ game, history }) => {
        this.game.set(game);
        this.history.set(history);
        this.loading.set(false);
        (ev?.target as { complete?: () => void })?.complete?.();
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
        (ev?.target as { complete?: () => void })?.complete?.();
      },
    });
  }

  reload(ev?: CustomEvent): void {
    this.load(ev);
  }
}
