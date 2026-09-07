import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import {
  IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle,
  IonIcon, IonSkeletonText,
} from '@ionic/angular/standalone';
import { LearningPathService, PathDetail } from '../../core/learning-path.service';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';
import { ButtonComponent } from '../../components/button/button.component';
import { ProgressComponent } from '../../components/progress/progress.component';
import { ToastService } from '../../components/toast/toast.service';
import { TranslatePipe } from '../../core/translate.pipe';
import { I18nService } from '../../core/i18n.service';

@Component({
  selector: 'app-path-detail',
  standalone: true,
  imports: [
    IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle,
    IonIcon, IonSkeletonText, EmptyStateComponent, ButtonComponent, ProgressComponent, TranslatePipe,
  ],
  template: `
    <ion-header [translucent]="true">
      <ion-toolbar>
        <ion-buttons slot="start"><ion-back-button defaultHref="/paths"></ion-back-button></ion-buttons>
        <ion-title>{{ path()?.title || ('paths.learning_path' | translate) }}</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content [fullscreen]="true">
      @if (loading()) {
        <div class="pad">
          <ion-skeleton-text animated style="width:100%; height:150px; border-radius:20px;"></ion-skeleton-text>
          <ion-skeleton-text animated style="width:70%; height:22px; margin-top:16px;"></ion-skeleton-text>
        </div>
      } @else if (path()) {
        @if (path(); as p) {
        <div class="banner">
          <ion-icon name="trail-sign-outline" class="banner-mark" aria-hidden="true"></ion-icon>
          <div class="banner-cap">
            <div class="banner-chips">
              @if (p.level) { <span class="chip">{{ levelLabel(p.level) }}</span> }
              @if (p.categoryName) { <span class="chip">{{ p.categoryName }}</span> }
            </div>
            <h1 class="banner-title">{{ p.title }}</h1>
          </div>
        </div>

        <div class="pad">
          <div class="meta muted">
            <span><ion-icon name="layers-outline"></ion-icon> {{ p.itemCount }} {{ (p.itemCount === 1 ? 'paths.item' : 'paths.items') | translate }}</span>
            @if (p.estimatedHours) { <span><ion-icon name="time-outline"></ion-icon> {{ p.estimatedHours }}h</span> }
            @if (p.pacingLabel) { <span><ion-icon name="cellular-outline"></ion-icon> {{ p.pacingLabel }}</span> }
          </div>

          @if (p.enrollment) {
            <div class="prog surface">
              <div class="prog-top">
                <span class="prog-pct">{{ p.progress.percent }}%</span>
                <span class="prog-lbl muted">{{ 'paths.progress_of_complete' | translate:{ completed: p.progress.completed, total: p.progress.total } }}</span>
              </div>
              <app-progress [value]="p.progress.percent / 100"></app-progress>
              @if (p.progress.pointsAvailable) {
                <p class="prog-pts muted">{{ 'paths.points_earned' | translate:{ earned: p.progress.pointsEarned, available: p.progress.pointsAvailable } }}</p>
              }
            </div>
          } @else {
            <app-button block size="lg" class="cta" [loading]="enrolling()" (click)="enroll(p)">{{ 'paths.enrol_in_path' | translate }}</app-button>
          }

          @if (p.summary || p.description) {
            <div class="section-title">{{ 'paths.about' | translate }}</div>
            <p class="desc">{{ p.description || p.summary }}</p>
          }

          @if (p.objectives.length) {
            <div class="section-title">{{ 'paths.what_youll_learn' | translate }}</div>
            <ul class="objectives">
              @for (o of p.objectives; track $index) {
                <li><ion-icon name="checkmark-circle" aria-hidden="true"></ion-icon><span>{{ o }}</span></li>
              }
            </ul>
          }

          @if (p.sections.length) {
            <div class="section-title">{{ 'paths.path_structure' | translate }}</div>
            @for (s of p.sections; track s.id; let i = $index) {
              <div class="sec surface">
                <div class="sec-num">{{ i + 1 }}</div>
                <div class="sec-body">
                  <p class="sec-title">{{ s.title }}@if (s.isMilestone) { <span class="milestone">{{ 'paths.milestone' | translate }}</span> }</p>
                  @if (s.description) { <p class="sec-desc muted">{{ s.description }}</p> }
                  <p class="sec-meta muted">{{ s.itemCount }} {{ (s.itemCount === 1 ? 'paths.item' : 'paths.items') | translate }}</p>
                </div>
              </div>
            }
          }

          <div style="height: 16px"></div>
        </div>
        }
      } @else {
        <app-empty-state icon="cloud-offline-outline" [title]="'paths.detail_load_failed' | translate"
          [message]="'common.offline' | translate" [actionLabel]="'common.retry' | translate" (action)="load()"></app-empty-state>
      }
    </ion-content>
  `,
  styles: [`
    ion-content { --padding-bottom: calc(16px + env(safe-area-inset-bottom)); }
    .pad { padding: 16px 16px 0; }
    .banner {
      position: relative; overflow: hidden; height: 172px; border-radius: 0 0 26px 26px; color: #fff;
      background:
        radial-gradient(130% 90% at 88% -25%, color-mix(in srgb, var(--brand-primary, var(--ion-color-primary)) 40%, #ffffff) 0%, transparent 55%),
        linear-gradient(150deg, var(--brand-primary, var(--ion-color-primary)) 0%, var(--brand-primary-shade, var(--ion-color-primary-shade)) 100%);
    }
    .banner-mark { position: absolute; top: 40%; right: 8%; transform: translateY(-50%); font-size: 6rem; opacity: .16; }
    .banner-cap { position: absolute; left: 0; right: 0; bottom: 0; padding: 16px 18px 18px; }
    .banner-chips { display: flex; gap: 6px; margin-bottom: 8px; }
    .chip { font-size: .66rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #fff; background: rgba(255,255,255,.22); padding: 3px 9px; border-radius: 999px; -webkit-backdrop-filter: blur(4px); backdrop-filter: blur(4px); }
    .banner-title { margin: 0; font-family: var(--app-font-display); font-size: 1.5rem; line-height: 1.14; font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); text-wrap: balance; text-shadow: 0 2px 12px rgba(0,0,0,.3); }

    .meta { display: flex; flex-wrap: wrap; gap: 14px; font-size: .82rem; margin-top: 16px; }
    .meta span { display: inline-flex; align-items: center; gap: 4px; }

    .prog { margin-top: 16px; padding: 16px; }
    .prog-top { display: flex; align-items: baseline; gap: 10px; margin-bottom: 10px; }
    .prog-pct { font-family: var(--app-font-display); font-weight: 800; font-size: 1.4rem; color: var(--ion-color-primary); }
    .prog-lbl { font-size: .84rem; }
    .prog-pts { margin: 10px 0 0; font-size: .78rem; }
    .cta { display: block; margin-top: 18px; }

    .section-title { margin: 24px 0 10px; }
    .desc { margin: 0; color: var(--ion-text-color); opacity: .85; line-height: 1.5; white-space: pre-line; }
    .objectives { list-style: none; margin: 0; padding: 0; }
    .objectives li { display: flex; gap: 10px; align-items: flex-start; margin: 0 0 10px; font-size: .92rem; color: var(--app-ink); }
    .objectives ion-icon { flex: none; font-size: 19px; color: var(--ion-color-success); margin-top: 1px; }

    .sec { display: flex; gap: 13px; padding: 14px; margin-bottom: 10px; }
    .sec-num { flex: none; width: 30px; height: 30px; border-radius: var(--app-r-sm); display: grid; place-items: center; font-family: var(--app-font-display); font-weight: 800; font-size: .9rem; color: var(--app-tag-ink); background: var(--app-tag-bg); }
    .sec-body { flex: 1; min-width: 0; }
    .sec-title { margin: 0; font-weight: 700; font-size: .95rem; color: var(--app-ink); }
    .milestone { margin-left: 8px; font-size: .62rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--ion-color-warning); background: color-mix(in srgb, var(--ion-color-warning) 16%, transparent); padding: 2px 6px; border-radius: 999px; }
    .sec-desc { margin: 4px 0 0; font-size: .82rem; line-height: 1.4; }
    .sec-meta { margin: 6px 0 0; font-size: .76rem; }
  `],
})
export class PathDetailPage implements OnInit {
  private readonly paths = inject(LearningPathService);
  private readonly route = inject(ActivatedRoute);
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(I18nService);

  private pathId = '';
  readonly path = signal<PathDetail | null>(null);
  readonly loading = signal(true);
  readonly enrolling = signal(false);

  ngOnInit(): void {
    this.pathId = this.route.snapshot.paramMap.get('id') ?? '';
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.paths.detail(this.pathId).subscribe({
      next: (p) => { this.path.set(p); this.loading.set(false); },
      error: () => { this.path.set(null); this.loading.set(false); },
    });
  }

  enroll(p: PathDetail): void {
    this.enrolling.set(true);
    this.paths.enroll(p.id).subscribe({
      next: () => {
        this.enrolling.set(false);
        this.toast.success(this.i18n.t('paths.enrol_success'));
        this.load();
      },
      error: () => {
        this.enrolling.set(false);
        this.toast.error(this.i18n.t('paths.enrol_error'));
      },
    });
  }

  levelLabel(l: string): string {
    const map: Record<string, string> = {
      beginner: 'paths.level.beginner', intermediate: 'paths.level.intermediate', advanced: 'paths.level.advanced',
      expert: 'paths.level.expert', all_levels: 'paths.level.all_levels',
    };
    const key = map[l];
    return key ? this.i18n.t(key) : l;
  }
}
