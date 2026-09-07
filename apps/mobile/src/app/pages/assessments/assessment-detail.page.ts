import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { forkJoin } from 'rxjs';
import {
  IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle,
  IonIcon, IonSkeletonText,
} from '@ionic/angular/standalone';
import { Assessment, Attempt, AssessmentService } from '../../core/assessment.service';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';
import { TranslatePipe } from '../../core/translate.pipe';
import { I18nService } from '../../core/i18n.service';

@Component({
  selector: 'app-assessment-detail',
  standalone: true,
  imports: [
    IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle,
    IonIcon, IonSkeletonText, EmptyStateComponent, TranslatePipe,
  ],
  template: `
    <ion-header [translucent]="true">
      <ion-toolbar>
        <ion-buttons slot="start"><ion-back-button defaultHref="/assessments"></ion-back-button></ion-buttons>
        <ion-title>{{ assessment()?.title || ('assessments.kind_assessment' | translate) }}</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content [fullscreen]="true">
      @if (loading()) {
        <div class="pad">
          <ion-skeleton-text animated style="width:70%; height:24px; margin-top:8px;"></ion-skeleton-text>
          <ion-skeleton-text animated style="width:100%; height:120px; border-radius:16px; margin-top:16px;"></ion-skeleton-text>
        </div>
      } @else if (assessment()) {
        @if (assessment(); as a) {
        <div class="pad">
          <div class="hdr">
            <div class="hdr-ic"><ion-icon [name]="a.proctoringLevel !== 'none' ? 'shield-checkmark-outline' : icon(a.kind)" aria-hidden="true"></ion-icon></div>
            <div class="hdr-tx">
              <p class="kind">{{ kindLabel(a.kind) }}@if (a.courseTitle) { · {{ a.courseTitle }} }</p>
              <h1 class="title">{{ a.title }}</h1>
            </div>
          </div>

          @if (a.description) { <p class="desc">{{ a.description }}</p> }

          <div class="info surface">
            <div class="cell"><span class="k">{{ 'assessments.info_questions' | translate }}</span><span class="v">{{ a.questionCount }}</span></div>
            <div class="cell"><span class="k">{{ 'assessments.info_total_points' | translate }}</span><span class="v">{{ a.totalPoints }}</span></div>
            @if (a.passingScore) { <div class="cell"><span class="k">{{ 'assessments.info_pass_mark' | translate }}</span><span class="v">{{ a.passingScore }}%</span></div> }
            @if (a.timeLimitMinutes) { <div class="cell"><span class="k">{{ 'assessments.info_time_limit' | translate }}</span><span class="v">{{ 'assessments.minutes' | translate:{ n: a.timeLimitMinutes } }}</span></div> }
            <div class="cell"><span class="k">{{ 'assessments.info_attempts' | translate }}</span><span class="v">{{ a.attemptCount }}{{ a.maxAttempts ? ' / ' + a.maxAttempts : '' }}</span></div>
            @if (a.bestScore !== null) { <div class="cell"><span class="k">{{ 'assessments.info_best_score' | translate }}</span><span class="v hi">{{ a.bestScore }}%</span></div> }
          </div>

          <div class="take" [class.proctored]="a.proctoringLevel !== 'none'">
            <ion-icon [name]="a.proctoringLevel !== 'none' ? 'shield-checkmark-outline' : 'desktop-outline'" aria-hidden="true"></ion-icon>
            <p>
              @if (a.proctoringLevel !== 'none') {
                {{ 'assessments.proctored_notice' | translate }}
              } @else {
                {{ 'assessments.web_notice' | translate }}
              }
            </p>
          </div>

          @if (myAttempts().length) {
            <div class="section-title">{{ 'assessments.your_attempts' | translate }}</div>
            @for (at of myAttempts(); track at.id) {
              <div class="at surface">
                <span class="at-n">#{{ at.attemptNumber }}</span>
                <div class="at-body">
                  <p class="at-status">{{ at.statusLabel }}</p>
                  <p class="at-date">{{ fmt(at.submittedAt || at.startedAt) }}</p>
                </div>
                @if (at.percentage !== null) {
                  <span class="at-score" [class.pass]="at.passed === true" [class.fail]="at.passed === false">{{ at.percentage }}%</span>
                }
              </div>
            }
          }

          <div style="height: 16px"></div>
        </div>
        }
      } @else {
        <app-empty-state icon="cloud-offline-outline" [title]="'assessments.detail_load_error_title' | translate"
          [message]="'common.offline' | translate" [actionLabel]="'common.retry' | translate" (action)="load()"></app-empty-state>
      }
    </ion-content>
  `,
  styles: [`
    ion-content { --padding-bottom: calc(16px + env(safe-area-inset-bottom)); }
    .pad { padding: 12px 16px 0; }
    .hdr { display: flex; align-items: center; gap: 13px; margin-top: 8px; }
    .hdr-ic { flex: none; width: 52px; height: 52px; border-radius: var(--app-r-sm); display: grid; place-items: center; background: var(--app-tag-bg); color: var(--app-tag-ink); }
    .hdr-ic ion-icon { font-size: 26px; }
    .hdr-tx { min-width: 0; }
    .kind { margin: 0; font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--app-muted); }
    .title { margin: 3px 0 0; font-family: var(--app-font-display); font-size: 1.35rem; line-height: 1.15; font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); color: var(--app-ink); }
    .desc { margin: 16px 0 0; color: var(--ion-text-color); opacity: .85; line-height: 1.5; }

    .info { margin-top: 18px; padding: 6px 4px; display: grid; grid-template-columns: 1fr 1fr; }
    .cell { padding: 12px 14px; display: flex; flex-direction: column; gap: 2px; }
    .cell .k { font-size: .72rem; color: var(--app-muted); }
    .cell .v { font-family: var(--app-font-display); font-weight: 800; font-size: 1.05rem; color: var(--app-ink); }
    .cell .v.hi { color: var(--ion-color-primary); }

    .take { display: flex; gap: 11px; align-items: flex-start; margin-top: 16px; padding: 14px; border-radius: var(--app-r); background: var(--app-tag-bg); color: var(--app-ink); }
    .take.proctored { background: color-mix(in srgb, var(--ion-color-warning) 14%, transparent); }
    .take ion-icon { flex: none; font-size: 22px; color: var(--ion-color-primary); margin-top: 1px; }
    .take.proctored ion-icon { color: var(--ion-color-warning); }
    .take p { margin: 0; font-size: .88rem; line-height: 1.45; }

    .section-title { margin: 24px 0 10px; }
    .at { display: flex; align-items: center; gap: 12px; padding: 12px 14px; margin-bottom: 10px; }
    .at-n { flex: none; font-family: var(--app-font-display); font-weight: 800; font-size: .9rem; color: var(--app-muted); }
    .at-body { flex: 1; min-width: 0; }
    .at-status { margin: 0; font-weight: 600; font-size: .92rem; color: var(--app-ink); }
    .at-date { margin: 2px 0 0; font-size: .77rem; color: var(--app-muted); }
    .at-score { flex: none; font-family: var(--app-font-display); font-weight: 800; font-size: 1.02rem; color: var(--app-muted); }
    .at-score.pass { color: var(--ion-color-success); }
    .at-score.fail { color: var(--ion-color-danger); }
  `],
})
export class AssessmentDetailPage implements OnInit {
  private readonly svc = inject(AssessmentService);
  private readonly route = inject(ActivatedRoute);
  private readonly i18n = inject(I18nService);

  private id = '';
  readonly loading = signal(true);
  readonly assessment = signal<Assessment | null>(null);
  private readonly allAttempts = signal<Attempt[]>([]);

  readonly myAttempts = computed(() =>
    this.allAttempts()
      .filter((a) => a.assessmentId === this.id)
      .sort((a, b) => b.attemptNumber - a.attemptNumber),
  );

  ngOnInit(): void {
    this.id = this.route.snapshot.paramMap.get('id') ?? '';
    this.load();
  }

  load(): void {
    this.loading.set(true);
    forkJoin({ list: this.svc.catalogue(), attempts: this.svc.attempts() }).subscribe({
      next: ({ list, attempts }) => {
        this.assessment.set(list.find((a) => a.assessmentId === this.id) ?? null);
        this.allAttempts.set(attempts);
        this.loading.set(false);
      },
      error: () => { this.assessment.set(null); this.loading.set(false); },
    });
  }

  fmt(iso: string | null): string {
    if (!iso) { return '—'; }
    const d = new Date(iso);
    return isNaN(d.getTime()) ? '—' : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
  }

  icon(kind: string): string {
    return kind === 'exam' ? 'document-text-outline' : kind === 'assignment' ? 'document-outline' : 'help-circle-outline';
  }

  kindLabel(kind: string): string {
    const map: Record<string, string> = { quiz: 'assessments.kind_quiz', exam: 'assessments.kind_exam', assignment: 'assessments.kind_assignment', survey: 'assessments.kind_survey' };
    return this.i18n.t(map[kind] ?? 'assessments.kind_assessment');
  }
}
