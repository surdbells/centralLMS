import { Component, computed, effect, inject, signal } from '@angular/core';
import { IonContent, IonRefresher, IonRefresherContent, IonSkeletonText } from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { ApiError } from '../../core/api';
import { LeaderboardEntry, LeaderboardResult } from '../../core/models';
import { initialOf, thumbStyle } from '../../core/ui';
import { BrandHeaderComponent } from '../../components/brand-header/brand-header.component';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';
import { SegmentComponent } from '../../components/segment/segment.component';

@Component({
  selector: 'app-leaderboard',
  standalone: true,
  imports: [
    IonContent, IonRefresher, IonRefresherContent, IonSkeletonText,
    BrandHeaderComponent, EmptyStateComponent, SegmentComponent,
  ],
  template: `
    <ion-content [fullscreen]="true">
      <ion-refresher slot="fixed" (ionRefresh)="reload($event)">
        <ion-refresher-content></ion-refresher-content>
      </ion-refresher>

      <app-brand-header title="Leaderboard" subtitle="See how you stack up"></app-brand-header>

      <div class="pad">
        <app-segment [(value)]="period" [segments]="periods" ariaLabel="Leaderboard period"></app-segment>

        @if (loading()) {
          @for (i of [1,2,3,4,5,6]; track i) {
            <div class="lb-row skel">
              <ion-skeleton-text animated style="width:22px;height:22px;border-radius:7px;"></ion-skeleton-text>
              <ion-skeleton-text animated style="width:38px;height:38px;border-radius:50%;"></ion-skeleton-text>
              <ion-skeleton-text animated style="width:44%;height:13px;border-radius:5px;"></ion-skeleton-text>
            </div>
          }
        } @else if (disabled()) {
          <app-empty-state
            icon="trophy-outline"
            title="No leaderboard here"
            message="Your organisation hasn't switched the leaderboard on."></app-empty-state>
        } @else if (failed()) {
          <app-empty-state
            icon="cloud-offline-outline"
            title="Couldn't load the leaderboard"
            message="Check your connection and try again."
            actionLabel="Try again"
            (action)="load()"></app-empty-state>
        } @else if (!entries().length) {
          <app-empty-state
            icon="trophy-outline"
            title="No one on the board yet"
            message="Earn points by finishing courses to claim a spot."></app-empty-state>
        } @else {
          <div class="board rise">
            @for (e of entries(); track e.userId) {
              <div class="lb-row" [class.me]="e.isViewer">
                <span class="lb-rank" [class]="'r' + (e.rank <= 3 ? e.rank : 0)">{{ e.rank }}</span>
                <div class="lb-av" [style]="av(e.name)">{{ initial(e.name) }}</div>
                <div class="lb-info">
                  <p class="lb-name"><span class="nm">{{ e.name }}</span>@if (e.isViewer) { <span class="you">You</span> }</p>
                  @if (e.department) { <p class="lb-dept">{{ e.department }}</p> }
                </div>
                <span class="lb-pts">{{ e.points }}<small>pts</small></span>
              </div>
            }
          </div>

          @if (viewer(); as v) {
            <p class="lb-yourank muted">Your rank</p>
            <div class="lb-row me pinned">
              <span class="lb-rank r0">{{ v.rank }}</span>
              <div class="lb-av" [style]="av('You')">Y</div>
              <div class="lb-info"><p class="lb-name">You</p></div>
              <span class="lb-pts">{{ v.points }}<small>pts</small></span>
            </div>
          }
        }
      </div>
    </ion-content>
  `,
  styles: [`
    .pad { padding: 14px 16px calc(16px + env(safe-area-inset-bottom)); }
    .board { margin-top: 14px; background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r); box-shadow: var(--app-shadow); overflow: hidden; }
    .lb-row { display: flex; align-items: center; gap: 12px; padding: 11px 14px; }
    .board .lb-row + .lb-row { border-top: 1px solid var(--app-line); }
    .lb-row.skel { margin-top: 10px; background: transparent; }
    .lb-row.me { background: var(--app-tag-bg); }
    .lb-row.pinned { margin-top: 10px; border: var(--app-card-border); border-radius: var(--app-r); box-shadow: var(--app-shadow); }

    .lb-rank {
      flex: 0 0 auto; width: 26px; height: 26px; border-radius: 8px; display: grid; place-items: center;
      font-family: var(--app-font-display); font-weight: 800; font-size: .82rem;
      color: var(--app-muted); background: var(--app-field);
    }
    .lb-rank.r1 { color: #7a5a00; background: linear-gradient(135deg, #ffe27a, #f5b301); }
    .lb-rank.r2 { color: #4a4a4a; background: linear-gradient(135deg, #eef1f5, #c3cad3); }
    .lb-rank.r3 { color: #6a3b18; background: linear-gradient(135deg, #f4c48c, #d68a4e); }

    .lb-av { flex: 0 0 auto; width: 38px; height: 38px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-weight: 800; font-size: .95rem; }
    .lb-info { flex: 1; min-width: 0; }
    .lb-name { margin: 0; display: flex; align-items: center; gap: 7px; min-width: 0; font-weight: 600; font-size: .95rem; color: var(--app-ink); }
    .lb-name .nm { min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .lb-name .you { flex: none; font-size: .62rem; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--app-tag-ink); background: color-mix(in srgb, var(--ion-color-primary) 16%, transparent); padding: 2px 6px; border-radius: 999px; }
    .lb-dept { margin: 2px 0 0; font-size: .76rem; color: var(--app-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .lb-pts { flex: 0 0 auto; font-family: var(--app-font-display); font-weight: 800; font-size: 1.05rem; color: var(--ion-color-primary); }
    .lb-pts small { font-family: var(--app-font); font-weight: 600; font-size: .64rem; color: var(--app-muted); margin-left: 3px; }
    .lb-yourank { margin: 18px 2px 0; font-size: .72rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; }
  `],
})
export class LeaderboardPage {
  private readonly learner = inject(LearnerService);

  readonly periods = [
    { value: 'all_time', label: 'All time' },
    { value: 'monthly', label: 'This month' },
    { value: 'weekly', label: 'This week' },
  ];

  readonly period = signal('all_time');
  readonly loading = signal(true);
  readonly disabled = signal(false);
  readonly failed = signal(false);
  private readonly result = signal<LeaderboardResult | null>(null);

  readonly entries = computed<LeaderboardEntry[]>(() => this.result()?.entries ?? []);
  readonly viewer = computed(() => this.result()?.viewer ?? null);

  readonly initial = initialOf;
  readonly av = thumbStyle;

  constructor() {
    // Reload whenever the period changes (and on first render).
    effect(() => {
      const p = this.period();
      this.fetch(p);
    });
  }

  load(): void {
    this.fetch(this.period());
  }

  reload(ev?: CustomEvent): void {
    this.fetch(this.period(), ev);
  }

  private fetch(period: string, ev?: CustomEvent): void {
    if (!ev) {
      this.loading.set(true);
    }
    this.disabled.set(false);
    this.failed.set(false);
    this.learner.leaderboard(period).subscribe({
      next: (r) => {
        this.result.set(r);
        this.loading.set(false);
        (ev?.target as { complete?: () => void })?.complete?.();
      },
      error: (err: unknown) => {
        this.result.set(null);
        this.loading.set(false);
        if (err instanceof ApiError && err.code === 'leaderboard_disabled') {
          this.disabled.set(true);
        } else {
          this.failed.set(true);
        }
        (ev?.target as { complete?: () => void })?.complete?.();
      },
    });
  }
}
