import { Component, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { Location } from '@angular/common';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import {
  IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle,
  IonIcon, IonSkeletonText, IonSpinner,
} from '@ionic/angular/standalone';
import { ButtonComponent } from '../../components/button/button.component';
import { LearnerService } from '../../core/learner.service';
import { MediaService } from '../../core/media.service';
import { Lesson } from '../../core/models';
import { minutesLabel } from '../../core/ui';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';
import { ToastService } from '../../components/toast/toast.service';
import { TranslatePipe } from '../../core/translate.pipe';
import { I18nService } from '../../core/i18n.service';

type RenderMode = 'none' | 'html' | 'video' | 'audio' | 'pdf' | 'fallback';

@Component({
  selector: 'app-lesson',
  standalone: true,
  imports: [
    IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle,
    IonIcon, IonSkeletonText, IonSpinner, EmptyStateComponent, ButtonComponent,
    TranslatePipe,
  ],
  template: `
    <ion-header [translucent]="true">
      <ion-toolbar>
        <ion-buttons slot="start"><ion-back-button [defaultHref]="'/courses/' + courseId"></ion-back-button></ion-buttons>
        <ion-title>{{ lesson()?.title || ('lesson.type.lesson' | translate) }}</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content [fullscreen]="true">
      @if (loading()) {
        <div class="pad">
          <ion-skeleton-text animated style="width:80%; height:26px; margin-top:8px;"></ion-skeleton-text>
          <ion-skeleton-text animated style="width:100%; height:200px; border-radius:16px; margin-top:20px;"></ion-skeleton-text>
        </div>
      } @else if (lesson()) {
        @if (lesson(); as l) {
        <div class="pad">
          <p class="type muted">{{ typeLabel(l.lessonType) }}{{ mins(l.durationMinutes) ? ' · ' + mins(l.durationMinutes) : '' }}</p>
          <h1 class="title">{{ l.title }}</h1>

          @switch (renderMode()) {
            @case ('html') {
              <div class="lesson-html" [innerHTML]="l.contentText"></div>
            }
            @case ('video') {
              @if (mediaUrl(); as u) {
                <video [src]="u" class="lesson-media" controls playsinline></video>
              } @else if (mediaLoading()) {
                <div class="stage"><ion-spinner name="crescent"></ion-spinner></div>
              } @else {
                <div class="stage">
                  <ion-icon name="play-circle-outline" aria-hidden="true"></ion-icon>
                  <p>{{ 'lesson.video_unavailable' | translate }}</p>
                </div>
              }
            }
            @case ('audio') {
              @if (mediaUrl(); as u) {
                <div class="lesson-audio">
                  <ion-icon name="musical-notes-outline" aria-hidden="true"></ion-icon>
                  <audio [src]="u" controls></audio>
                </div>
              } @else if (mediaLoading()) {
                <div class="stage"><ion-spinner name="crescent"></ion-spinner></div>
              } @else {
                <div class="stage"><ion-icon name="musical-notes-outline" aria-hidden="true"></ion-icon><p>{{ 'lesson.audio_unavailable' | translate }}</p></div>
              }
            }
            @case ('pdf') {
              @if (safeFrame()) {
                <iframe [src]="safeFrame()" class="lesson-frame" [title]="'lesson.document_title' | translate"></iframe>
              } @else if (mediaLoading()) {
                <div class="stage"><ion-spinner name="crescent"></ion-spinner></div>
              } @else {
                <div class="stage"><ion-icon name="document-text-outline" aria-hidden="true"></ion-icon><p>{{ 'lesson.document_unavailable' | translate }}</p></div>
              }
            }
            @default {
              <div class="stage">
                <ion-icon [name]="stageIcon(l.lessonType)" aria-hidden="true"></ion-icon>
                <p>{{ stageHint(l.lessonType) }}</p>
              </div>
            }
          }

          @if (done()) {
            <div class="done">
              <ion-icon name="checkmark-circle" aria-hidden="true"></ion-icon>
              <span>{{ 'lesson.completed' | translate }}</span>
            </div>
          } @else {
            <app-button block size="lg" class="cta" icon="checkmark-circle-outline" [loading]="saving()" (click)="complete()">
              {{ 'lesson.mark_complete' | translate }}
            </app-button>
          }
        </div>
        }
      } @else {
        <app-empty-state
          icon="cloud-offline-outline"
          [title]="'lesson.load_failed' | translate"
          [message]="'lesson.load_failed_msg' | translate"></app-empty-state>
      }
    </ion-content>
  `,
  styles: [`
    ion-content { --padding-bottom: calc(16px + env(safe-area-inset-bottom)); }
    .pad { padding: 8px 16px 24px; }
    .type { text-transform: uppercase; letter-spacing: .04em; font-size: .72rem; margin: 8px 0 0; }
    .title { margin: 4px 0 18px; font-family: var(--app-font-display); font-size: 1.4rem; font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); line-height: 1.2; }

    .lesson-media { width: 100%; max-height: 60vh; border-radius: var(--app-r); background: #000; display: block; }
    .lesson-frame { width: 100%; height: 72vh; border: var(--app-card-border); border-radius: var(--app-r); background: #fff; }
    .lesson-audio { display: flex; align-items: center; gap: 12px; background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r); padding: 16px; box-shadow: var(--app-shadow); }
    .lesson-audio ion-icon { font-size: 30px; color: var(--ion-color-primary); flex: none; }
    .lesson-audio audio { flex: 1; min-width: 0; }

    .stage { background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r); padding: 40px 20px; text-align: center; box-shadow: var(--app-shadow); }
    .stage ion-icon { font-size: 52px; color: var(--ion-color-primary); }
    .stage ion-spinner { --color: var(--ion-color-primary); }
    .stage p { margin: 14px 0 0; color: var(--app-muted); line-height: 1.5; }

    /* Rich text lesson body (innerHTML — pierced with ::ng-deep, scoped to :host). */
    .lesson-html { color: var(--app-ink); font-size: 1rem; line-height: 1.65; }
    :host ::ng-deep .lesson-html h1, :host ::ng-deep .lesson-html h2, :host ::ng-deep .lesson-html h3 {
      font-family: var(--app-font-display); font-weight: var(--app-display-weight); line-height: 1.2; margin: 1.3em 0 .5em; letter-spacing: var(--app-display-tracking);
    }
    :host ::ng-deep .lesson-html h2 { font-size: 1.25rem; }
    :host ::ng-deep .lesson-html h3 { font-size: 1.08rem; }
    :host ::ng-deep .lesson-html p { margin: 0 0 1em; }
    :host ::ng-deep .lesson-html ul, :host ::ng-deep .lesson-html ol { padding-left: 1.4em; margin: 0 0 1em; }
    :host ::ng-deep .lesson-html li { margin: .3em 0; }
    :host ::ng-deep .lesson-html a { color: var(--ion-color-primary); text-decoration: underline; }
    :host ::ng-deep .lesson-html img { max-width: 100%; height: auto; border-radius: var(--app-r-sm); margin: .5em 0; }
    :host ::ng-deep .lesson-html blockquote { margin: 0 0 1em; padding: 4px 0 4px 14px; border-left: 3px solid var(--app-line); color: var(--app-muted); }
    :host ::ng-deep .lesson-html pre { background: var(--app-field); padding: 12px; border-radius: var(--app-r-sm); overflow-x: auto; }
    :host ::ng-deep .lesson-html table { width: 100%; border-collapse: collapse; margin: 0 0 1em; display: block; overflow-x: auto; }
    :host ::ng-deep .lesson-html th, :host ::ng-deep .lesson-html td { border: 1px solid var(--app-line); padding: 8px 10px; text-align: left; }

    .cta { display: block; margin-top: 24px; }
    .done { margin-top: 24px; display: flex; align-items: center; justify-content: center; gap: 8px; color: var(--ion-color-success); font-weight: 700; padding: 14px; }
    .done ion-icon { font-size: 24px; }
  `],
})
export class LessonPage implements OnInit, OnDestroy {
  private readonly learner = inject(LearnerService);
  private readonly media = inject(MediaService);
  private readonly route = inject(ActivatedRoute);
  private readonly location = inject(Location);
  private readonly toast = inject(ToastService);
  private readonly sanitizer = inject(DomSanitizer);
  private readonly i18n = inject(I18nService);

  private backTimer?: ReturnType<typeof setTimeout>;

  courseId = '';
  private lessonId = '';
  readonly lesson = signal<Lesson | null>(null);
  readonly loading = signal(true);
  readonly saving = signal(false);
  private readonly markedDone = signal(false);
  readonly done = computed(() => this.markedDone() || !!this.lesson()?.isCompleted);

  readonly mediaUrl = signal<string | null>(null);
  readonly mediaLoading = signal(false);

  readonly mins = minutesLabel;

  readonly renderMode = computed<RenderMode>(() => {
    const l = this.lesson();
    if (!l) {
      return 'none';
    }
    const t = l.lessonType;
    if ((t === 'text' || t === 'html') && l.contentText) {
      return 'html';
    }
    if (t === 'video') { return 'video'; }
    if (t === 'audio') { return 'audio'; }
    if (t === 'pdf' || t === 'document') { return 'pdf'; }
    return 'fallback';
  });

  readonly safeFrame = computed<SafeResourceUrl | null>(() => {
    const u = this.mediaUrl();
    return u ? this.sanitizer.bypassSecurityTrustResourceUrl(u) : null;
  });

  ngOnInit(): void {
    this.courseId = this.route.snapshot.paramMap.get('courseId') ?? '';
    this.lessonId = this.route.snapshot.paramMap.get('lessonId') ?? '';
    this.learner.courseDetails(this.courseId).subscribe({
      next: (c) => {
        const found = c.modules.flatMap((m) => m.lessons).find((l) => l.lessonId === this.lessonId) ?? null;
        this.lesson.set(found);
        this.loading.set(false);
        if (found && ['video', 'audio', 'pdf', 'document'].includes(found.lessonType) && found.contentUrl) {
          this.resolveMedia(found.contentUrl);
        }
      },
      error: () => this.loading.set(false),
    });
  }

  ngOnDestroy(): void {
    // The completion toast schedules a back(); don't let it fire after the user
    // has already navigated away.
    if (this.backTimer) {
      clearTimeout(this.backTimer);
    }
  }

  private resolveMedia(reference: string): void {
    this.mediaLoading.set(true);
    this.media.resolve(reference).subscribe({
      next: (url) => { this.mediaUrl.set(url); this.mediaLoading.set(false); },
      error: () => { this.mediaUrl.set(null); this.mediaLoading.set(false); },
    });
  }

  complete(): void {
    this.saving.set(true);
    this.learner.markLessonComplete(this.courseId, this.lessonId).subscribe({
      next: () => {
        this.saving.set(false);
        this.markedDone.set(true);
        this.toast.success(this.i18n.t('lesson.completed_toast'), { duration: 1600 });
        this.backTimer = setTimeout(() => this.location.back(), 900);
      },
      error: () => {
        this.saving.set(false);
        this.toast.error(this.i18n.t('lesson.save_failed'));
      },
    });
  }

  typeLabel(t: string): string {
    const map: Record<string, string> = {
      video: 'lesson.type.video', text: 'lesson.type.reading', html: 'lesson.type.reading', document: 'lesson.type.document',
      pdf: 'lesson.type.document', quiz: 'lesson.type.quiz', scorm: 'lesson.type.interactive', audio: 'lesson.type.audio', assignment: 'lesson.type.assignment',
    };
    return this.i18n.t(map[t] ?? 'lesson.type.lesson');
  }

  stageIcon(t: string): string {
    const map: Record<string, string> = {
      quiz: 'help-circle-outline', scorm: 'play-circle-outline', assignment: 'document-text-outline',
    };
    return map[t] ?? 'document-text-outline';
  }

  stageHint(t: string): string {
    if (t === 'quiz') {
      return this.i18n.t('lesson.hint_quiz');
    }
    if (t === 'scorm') {
      return this.i18n.t('lesson.hint_scorm');
    }
    if (t === 'assignment') {
      return this.i18n.t('lesson.hint_assignment');
    }
    return this.i18n.t('lesson.hint_default');
  }
}
