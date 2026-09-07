import { Component, computed, effect, inject, signal } from '@angular/core';
import {
  IonContent, IonRefresher, IonRefresherContent, IonSkeletonText, IonIcon,
} from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { ApiError } from '../../core/api';
import { LeaderboardEntry, LeaderboardResult } from '../../core/models';
import { BrandHeaderComponent } from '../../components/brand-header/brand-header.component';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';
import { AvatarComponent } from '../../components/avatar/avatar.component';
import { SheetComponent } from '../../components/sheet/sheet.component';
import { TranslatePipe } from '../../core/translate.pipe';
import { I18nService } from '../../core/i18n.service';

type Tab = 'xp' | 'badges' | 'certificates';

@Component({
  selector: 'app-leaderboard',
  standalone: true,
  imports: [
    IonContent, IonRefresher, IonRefresherContent, IonSkeletonText, IonIcon,
    BrandHeaderComponent, EmptyStateComponent, AvatarComponent, SheetComponent, TranslatePipe,
  ],
  template: `
    <ion-content [fullscreen]="true">
      <ion-refresher slot="fixed" (ionRefresh)="reload($event)">
        <ion-refresher-content></ion-refresher-content>
      </ion-refresher>

      <app-brand-header [title]="'leaderboard.title' | translate" [subtitle]="'leaderboard.subtitle' | translate" back>
        <button actions type="button" class="chip" (click)="periodOpen.set(true)" [attr.aria-label]="'leaderboard.change_period' | translate">
          <ion-icon name="funnel-outline" aria-hidden="true"></ion-icon>
          <span>{{ periodLabel() }}</span>
          <ion-icon name="chevron-down" aria-hidden="true"></ion-icon>
        </button>
      </app-brand-header>

      <!-- Tabs -->
      <div class="tabs">
        @for (t of tabs; track t.value) {
          <button type="button" class="tab" [class.on]="tab() === t.value" (click)="tab.set(t.value)">{{ t.label }}</button>
        }
      </div>

      @if (tab() !== 'xp') {
        <app-empty-state
          [icon]="tab() === 'badges' ? 'medal-outline' : 'ribbon-outline'"
          [title]="(tab() === 'badges' ? 'leaderboard.badge_rankings' : 'leaderboard.certificate_rankings') | translate"
          [message]="'leaderboard.board_coming_soon' | translate"></app-empty-state>
      } @else if (loading()) {
        <div class="pad">
          <div class="podium-skel">
            <ion-skeleton-text animated style="width:30%;height:150px;border-radius:18px;"></ion-skeleton-text>
            <ion-skeleton-text animated style="width:34%;height:184px;border-radius:18px;"></ion-skeleton-text>
            <ion-skeleton-text animated style="width:30%;height:150px;border-radius:18px;"></ion-skeleton-text>
          </div>
          @for (i of [1,2,3,4,5]; track i) {
            <div class="lrow skel">
              <ion-skeleton-text animated style="width:20px;height:20px;border-radius:6px;"></ion-skeleton-text>
              <ion-skeleton-text animated style="width:40px;height:40px;border-radius:50%;"></ion-skeleton-text>
              <ion-skeleton-text animated style="width:44%;height:13px;border-radius:5px;"></ion-skeleton-text>
            </div>
          }
        </div>
      } @else if (disabled()) {
        <app-empty-state icon="trophy-outline" [title]="'leaderboard.none_here_title' | translate"
          [message]="'leaderboard.none_here_message' | translate"></app-empty-state>
      } @else if (failed()) {
        <app-empty-state icon="cloud-offline-outline" [title]="'leaderboard.load_failed_title' | translate"
          [message]="'common.offline' | translate" [actionLabel]="'common.retry' | translate" (action)="load()"></app-empty-state>
      } @else if (!entries().length) {
        <app-empty-state icon="trophy-outline" [title]="'leaderboard.empty_title' | translate"
          [message]="'leaderboard.empty_message' | translate"></app-empty-state>
      } @else {
        <div class="pad">
          <!-- Podium -->
          <div class="podium">
            @if (second(); as e) {
              <div class="pod pod2" [class.me]="e.isViewer">
                <div class="banner b2"><span>2</span></div>
                <div class="pod-av ring2"><app-avatar [url]="e.avatarUrl" [name]="e.name" [size]="64"></app-avatar></div>
                <p class="pod-name">{{ e.name }}</p>
                @if (levelLabel(e)) { <p class="pod-lvl">{{ levelLabel(e) }}</p> }
                <p class="pod-xp c2">{{ xp(e.points) }}</p>
              </div>
            }
            @if (first(); as e) {
              <div class="pod pod1" [class.me]="e.isViewer">
                <div class="confetti" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div>
                <div class="banner b1"><span>1</span></div>
                <div class="pod-av ring1"><app-avatar [url]="e.avatarUrl" [name]="e.name" [size]="84"></app-avatar></div>
                <p class="pod-name">{{ e.name }}</p>
                @if (levelLabel(e)) { <p class="pod-lvl">{{ levelLabel(e) }}</p> }
                <p class="pod-xp c1">{{ xp(e.points) }}</p>
              </div>
            }
            @if (third(); as e) {
              <div class="pod pod3" [class.me]="e.isViewer">
                <div class="banner b3"><span>3</span></div>
                <div class="pod-av ring3"><app-avatar [url]="e.avatarUrl" [name]="e.name" [size]="64"></app-avatar></div>
                <p class="pod-name">{{ e.name }}</p>
                @if (levelLabel(e)) { <p class="pod-lvl">{{ levelLabel(e) }}</p> }
                <p class="pod-xp c3">{{ xp(e.points) }}</p>
              </div>
            }
          </div>

          <!-- Ranked list -->
          @if (rest().length || viewer()) {
            <div class="list-head">
              <span class="lh-rank">{{ 'leaderboard.rank' | translate }}</span>
              <span class="lh-learner">{{ 'leaderboard.learner' | translate }}</span>
              <span class="lh-xp">{{ 'leaderboard.xp_points' | translate }}</span>
            </div>
            <div class="lb-list">
              @for (e of rest(); track e.userId) {
                <div class="lrow" [class.me]="e.isViewer">
                  <span class="lr-rank">{{ e.rank }}</span>
                  <app-avatar [url]="e.avatarUrl" [name]="e.name" [size]="40"></app-avatar>
                  <div class="lr-info">
                    <p class="lr-name">{{ e.name }}@if (e.isViewer) { <span class="you"> {{ 'leaderboard.you' | translate }}</span> }</p>
                    @if (levelLabel(e)) { <p class="lr-lvl">{{ levelLabel(e) }}</p> }
                  </div>
                  <span class="lr-xp">{{ xp(e.points) }}</span>
                </div>
              }
              @if (viewer(); as v) {
                <div class="lrow me pinned">
                  <span class="lr-rank">{{ v.rank }}</span>
                  <app-avatar [url]="v.avatarUrl" [name]="v.name" [size]="40"></app-avatar>
                  <div class="lr-info">
                    <p class="lr-name">{{ v.name }}<span class="you"> {{ 'leaderboard.you' | translate }}</span></p>
                    @if (levelLabel(v)) { <p class="lr-lvl">{{ levelLabel(v) }}</p> }
                  </div>
                  <span class="lr-xp">{{ xp(v.points) }}</span>
                </div>
              }
            </div>
          }

          <!-- Encouragement -->
          <div class="foot">
            <span class="foot-ic"><ion-icon name="trophy-outline" aria-hidden="true"></ion-icon></span>
            <div class="foot-tx">
              <p class="foot-t">{{ 'leaderboard.foot_title' | translate }}</p>
              <p class="foot-s">{{ 'leaderboard.foot_subtitle' | translate }}</p>
            </div>
          </div>
        </div>
      }

      <app-sheet [(open)]="periodOpen" [title]="'leaderboard.time_period' | translate" [subtitle]="'leaderboard.rank_learners_over' | translate">
        <div class="opts">
          @for (p of periods; track p.value) {
            <button type="button" class="opt" [class.active]="period() === p.value" (click)="pickPeriod(p.value)">
              <span class="opt-t">{{ p.label }}</span>
              @if (period() === p.value) { <ion-icon name="checkmark" aria-hidden="true"></ion-icon> }
            </button>
          }
        </div>
      </app-sheet>
    </ion-content>
  `,
  styles: [`
    .pad { padding: 4px 16px calc(16px + env(safe-area-inset-bottom)); }

    /* Filter chip in the header */
    .chip { display: inline-flex; align-items: center; gap: 5px; padding: 7px 10px; border-radius: 999px;
      background: var(--app-surface); border: 1px solid var(--app-line); color: var(--app-ink);
      font-size: .8rem; font-weight: 600; white-space: nowrap; cursor: pointer; box-shadow: var(--app-shadow); }
    .chip ion-icon:first-child { font-size: 14px; color: var(--app-muted); }
    .chip ion-icon:last-child { font-size: 13px; color: var(--app-muted); }

    /* Tabs */
    .tabs { display: flex; gap: 8px; padding: 6px 16px 12px; }
    .tab { flex: 1; padding: 11px 8px; border-radius: 12px; border: 1px solid transparent; background: transparent;
      color: var(--app-muted); font-size: .9rem; font-weight: 600; cursor: pointer; transition: all .14s ease; }
    .tab.on { color: var(--ion-color-primary); background: color-mix(in srgb, var(--ion-color-primary) 10%, var(--app-surface));
      border-color: color-mix(in srgb, var(--ion-color-primary) 28%, transparent); }

    /* Podium */
    .podium { display: flex; align-items: flex-end; justify-content: center; gap: 10px; margin: 10px 0 6px; }
    .pod { position: relative; flex: 1 1 0; min-width: 0; max-width: 132px; text-align: center;
      padding: 34px 8px 16px; border-radius: 18px; border: 1px solid var(--app-line); background: var(--app-surface); }
    .pod1 { order: 2; padding-top: 44px; padding-bottom: 22px;
      background: color-mix(in srgb, #f5b301 12%, var(--app-surface)); border-color: color-mix(in srgb, #f5b301 34%, transparent);
      box-shadow: 0 18px 34px -20px color-mix(in srgb, #f5b301 60%, transparent); }
    .pod2 { order: 1; background: color-mix(in srgb, #8a97a8 12%, var(--app-surface)); border-color: color-mix(in srgb, #8a97a8 30%, transparent); }
    .pod3 { order: 3; background: color-mix(in srgb, #e07b39 12%, var(--app-surface)); border-color: color-mix(in srgb, #e07b39 30%, transparent); }
    .pod.me { outline: 2px solid var(--ion-color-primary); outline-offset: 1px; }

    .banner { position: absolute; top: -14px; left: 50%; transform: translateX(-50%); width: 34px; height: 40px;
      display: grid; place-items: center; padding-bottom: 6px; color: #fff;
      clip-path: polygon(0 0, 100% 0, 100% 74%, 50% 100%, 0 74%); }
    .banner span { font-family: var(--app-font-display); font-weight: 800; font-size: 1.05rem; line-height: 1; }
    .b1 { background: linear-gradient(160deg, #ffcf4a, #f0a000); }
    .b2 { background: linear-gradient(160deg, #b8c2ce, #8a97a8); }
    .b3 { background: linear-gradient(160deg, #eea36c, #d06e2f); }

    .pod-av { display: inline-grid; place-items: center; border-radius: 50%; padding: 3px; }
    .ring1 { background: color-mix(in srgb, #f5b301 55%, transparent); }
    .ring2 { background: color-mix(in srgb, #8a97a8 50%, transparent); }
    .ring3 { background: color-mix(in srgb, #e07b39 50%, transparent); }
    .pod-name { margin: 8px 0 0; font-weight: 700; font-size: .9rem; color: var(--app-ink); line-height: 1.2;
      display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .pod1 .pod-name { font-size: .96rem; }
    .pod-lvl { margin: 2px 0 0; font-size: .74rem; color: var(--app-muted); }
    .pod-xp { margin: 7px 0 0; font-family: var(--app-font-display); font-weight: 800; font-size: 1rem; }
    .pod1 .pod-xp { font-size: 1.12rem; }
    .c1 { color: #e09b00; }
    .c2 { color: var(--ion-color-primary); }
    .c3 { color: #d06e2f; }

    .confetti { position: absolute; inset: 0; overflow: hidden; border-radius: 18px; pointer-events: none; }
    .confetti i { position: absolute; width: 6px; height: 6px; border-radius: 1.5px; opacity: .85; }
    .confetti i:nth-child(1) { left: 14%; top: 16%; background: #f5b301; transform: rotate(20deg); }
    .confetti i:nth-child(2) { left: 82%; top: 20%; background: #3b6fe0; transform: rotate(-15deg); }
    .confetti i:nth-child(3) { left: 22%; top: 40%; background: #db4a7e; transform: rotate(35deg); }
    .confetti i:nth-child(4) { left: 74%; top: 44%; background: #1f9d57; transform: rotate(10deg); border-radius: 50%; }
    .confetti i:nth-child(5) { left: 10%; top: 62%; background: #7a52d8; transform: rotate(-25deg); }
    .confetti i:nth-child(6) { left: 88%; top: 60%; background: #f5b301; transform: rotate(40deg); border-radius: 50%; }
    .confetti i:nth-child(7) { left: 40%; top: 12%; background: #e07b39; transform: rotate(-10deg); }
    .confetti i:nth-child(8) { left: 60%; top: 30%; background: #3b6fe0; transform: rotate(25deg); border-radius: 50%; }

    /* List */
    .list-head { display: flex; align-items: center; gap: 10px; padding: 18px 12px 9px; font-size: .8rem; font-weight: 600; color: var(--app-muted); }
    .lh-rank { flex: none; width: 34px; text-align: center; white-space: nowrap; }
    .lh-learner { flex: 1; padding-left: 50px; }
    .lh-xp { flex: none; }

    .lb-list { background: var(--app-surface); border: 1px solid var(--app-line); border-radius: var(--app-r); overflow: hidden; box-shadow: var(--app-shadow); }
    .lrow { display: flex; align-items: center; gap: 10px; padding: 11px 12px; }
    .lb-list .lrow + .lrow { border-top: 1px solid var(--app-line); }
    .lrow.skel { padding: 11px 6px; }
    .lrow.me { background: color-mix(in srgb, var(--ion-color-primary) 9%, var(--app-surface)); }
    .lb-list .lrow.pinned { border-top: 2px solid color-mix(in srgb, var(--ion-color-primary) 22%, var(--app-line)); }
    .lr-rank { flex: none; width: 34px; text-align: center; font-family: var(--app-font-display); font-weight: 800; font-size: .95rem; color: var(--app-muted); }
    .lrow.me .lr-rank { color: var(--ion-color-primary); }
    .lr-info { flex: 1; min-width: 0; }
    .lr-name { margin: 0; font-weight: 600; font-size: .92rem; color: var(--app-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .lrow.me .lr-name { color: var(--ion-color-primary); }
    .lr-name .you { font-weight: 700; }
    .lr-lvl { margin: 2px 0 0; font-size: .76rem; color: var(--app-muted); }
    .lr-xp { flex: none; font-family: var(--app-font-display); font-weight: 800; font-size: .98rem; color: var(--ion-color-primary); }

    /* Footer */
    .foot { display: flex; align-items: center; gap: 13px; margin-top: 16px; padding: 15px; border-radius: var(--app-r);
      background: color-mix(in srgb, var(--ion-color-primary) 8%, var(--app-surface)); border: 1px solid color-mix(in srgb, var(--ion-color-primary) 16%, transparent); }
    .foot-ic { flex: none; width: 46px; height: 46px; border-radius: 50%; display: grid; place-items: center;
      background: color-mix(in srgb, var(--ion-color-primary) 16%, transparent); color: var(--ion-color-primary); }
    .foot-ic ion-icon { font-size: 24px; }
    .foot-t { margin: 0; font-weight: 700; font-size: .92rem; color: var(--app-ink); }
    .foot-s { margin: 3px 0 0; font-size: .8rem; line-height: 1.4; color: var(--app-muted); }

    .podium-skel { display: flex; align-items: flex-end; justify-content: center; gap: 10px; margin: 10px 0; }

    /* Period sheet */
    .opts { display: grid; gap: 8px; }
    .opt { display: flex; align-items: center; justify-content: space-between; width: 100%; text-align: left; cursor: pointer; color: var(--app-ink);
      padding: 14px; border-radius: var(--app-r); border: 1.5px solid var(--app-line); background: var(--app-field); }
    .opt.active { border-color: var(--ion-color-primary); background: color-mix(in srgb, var(--ion-color-primary) 8%, var(--app-field)); }
    .opt-t { font-size: .96rem; font-weight: 600; }
    .opt ion-icon { font-size: 20px; color: var(--ion-color-primary); }
  `],
})
export class LeaderboardPage {
  private readonly learner = inject(LearnerService);
  private readonly i18n = inject(I18nService);

  readonly tabs: Array<{ value: Tab; label: string }> = [
    { value: 'xp', label: this.i18n.t('leaderboard.xp_points') },
    { value: 'badges', label: this.i18n.t('leaderboard.badges') },
    { value: 'certificates', label: this.i18n.t('leaderboard.certificates') },
  ];

  readonly periods = [
    { value: 'daily', label: this.i18n.t('leaderboard.periods.daily') },
    { value: 'weekly', label: this.i18n.t('leaderboard.periods.weekly') },
    { value: 'monthly', label: this.i18n.t('leaderboard.periods.monthly') },
    { value: 'all_time', label: this.i18n.t('leaderboard.periods.all_time') },
  ];

  readonly tab = signal<Tab>('xp');
  readonly period = signal('monthly');
  readonly periodOpen = signal(false);
  readonly loading = signal(true);
  readonly disabled = signal(false);
  readonly failed = signal(false);
  private readonly result = signal<LeaderboardResult | null>(null);

  readonly entries = computed<LeaderboardEntry[]>(() => this.result()?.entries ?? []);
  readonly first = computed(() => this.entries()[0] ?? null);
  readonly second = computed(() => this.entries()[1] ?? null);
  readonly third = computed(() => this.entries()[2] ?? null);
  readonly rest = computed(() => this.entries().slice(3));
  readonly viewer = computed(() => this.result()?.viewer ?? null);

  readonly periodLabel = computed(
    () => this.periods.find((p) => p.value === this.period())?.label ?? this.i18n.t('leaderboard.periods.monthly'),
  );

  constructor() {
    effect(() => {
      const p = this.period();
      this.fetch(p);
    });
  }

  xp(points: number): string {
    return `${(points ?? 0).toLocaleString()} XP`;
  }

  levelLabel(e: LeaderboardEntry): string {
    const n = e.level?.name?.trim();
    if (!n) {
      return '';
    }
    return /^\d+$/.test(n) ? this.i18n.t('leaderboard.level_n', { n }) : n;
  }

  pickPeriod(value: string): void {
    this.period.set(value);
    this.periodOpen.set(false);
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
