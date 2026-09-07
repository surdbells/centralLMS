import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import {
  IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle,
  IonIcon, IonSkeletonText, IonRippleEffect,
} from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { CourseDetails } from '../../core/models';
import { initialOf, minutesLabel, thumbStyle } from '../../core/ui';
import { TranslatePipe } from '../../core/translate.pipe';
import { I18nService } from '../../core/i18n.service';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';
import { SheetComponent } from '../../components/sheet/sheet.component';
import { ToastService, ToastVariant } from '../../components/toast/toast.service';
import { ButtonComponent } from '../../components/button/button.component';
import { ProgressComponent } from '../../components/progress/progress.component';

@Component({
  selector: 'app-course-detail',
  standalone: true,
  imports: [
    IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle,
    IonIcon, IonSkeletonText, IonRippleEffect,
    EmptyStateComponent, SheetComponent, ButtonComponent, ProgressComponent,
    TranslatePipe,
  ],
  template: `
    <ion-header [translucent]="true">
      <ion-toolbar>
        <ion-buttons slot="start"><ion-back-button defaultHref="/tabs/courses"></ion-back-button></ion-buttons>
        <ion-title>{{ course()?.title || ('courses.course' | translate) }}</ion-title>
      </ion-toolbar>
    </ion-header>

    <ion-content [fullscreen]="true">
      @if (loading()) {
        <div class="pad">
          <ion-skeleton-text animated style="width:100%; height:150px; border-radius:18px;"></ion-skeleton-text>
          <ion-skeleton-text animated style="width:70%; height:24px; margin-top:16px;"></ion-skeleton-text>
          <ion-skeleton-text animated style="width:100%; height:64px; margin-top:16px; border-radius:12px;"></ion-skeleton-text>
        </div>
      } @else if (course()) {
        @if (course(); as c) {
        <div class="banner" [style]="thumb(c.title)">
          <span class="mark">{{ initial(c.title) }}</span>
          <div class="banner-cap">
            <span class="banner-cat">{{ c.categoryName || ('courses.course' | translate) }}</span>
            <h1 class="banner-title">{{ c.title }}</h1>
          </div>
        </div>

        <div class="pad">
          <div class="meta muted">
            @if (mins(c.durationMinutes)) { <span><ion-icon name="time-outline"></ion-icon> {{ mins(c.durationMinutes) }}</span> }
            <span><ion-icon name="list-outline"></ion-icon> {{ 'course.lessons_count' | translate:{ count: lessonCount() } }}</span>
            @if (c.level) { <span><ion-icon name="cellular-outline"></ion-icon> {{ c.level }}</span> }
          </div>

          @if (c.isEnrolled) {
            <div class="prog">
              <app-progress [value]="c.progressPercentage / 100"></app-progress>
              <p class="muted">{{ 'course.percent_complete' | translate:{ percent: c.progressPercentage } }}</p>
            </div>
            <app-button block size="lg" class="cta" icon="play-outline" (click)="continueCourse(c)">
              {{ c.progressPercentage > 0 ? ('common.continue' | translate) : ('course.start' | translate) }}
            </app-button>
          } @else {
            <app-button block size="lg" class="cta" (click)="enrolOpen.set(true)">
              {{ 'course.enrol_free' | translate }}
            </app-button>
          }

          <app-sheet [(open)]="enrolOpen" [title]="'course.enrol_title' | translate" [subtitle]="c.title">
            <div class="enrol-facts">
              <span><ion-icon name="pricetag-outline" aria-hidden="true"></ion-icon> {{ 'course.free' | translate }}</span>
              <span><ion-icon name="list-outline" aria-hidden="true"></ion-icon> {{ 'course.lessons_count' | translate:{ count: lessonCount() } }}</span>
              @if (mins(c.durationMinutes)) {
                <span><ion-icon name="time-outline" aria-hidden="true"></ion-icon> {{ mins(c.durationMinutes) }}</span>
              }
            </div>
            <p class="enrol-note muted">{{ 'course.enrol_note' | translate }}</p>
            <app-button actions block size="lg" class="cta" [loading]="enrolling()" (click)="enroll(c)">{{ 'course.confirm_enrolment' | translate }}</app-button>
            <app-button actions block variant="ghost" class="later" style="--btn-fg: var(--app-muted)" (click)="enrolOpen.set(false)">{{ 'course.not_now' | translate }}</app-button>
          </app-sheet>

          @if (c.description) {
            <div class="section-title">{{ 'course.about' | translate }}</div>
            <p class="desc">{{ c.description }}</p>
          }

          <div class="section-title">{{ 'course.lessons' | translate }}</div>
          @for (m of c.modules; track m.moduleId || mi; let mi = $index) {
            @if (c.modules.length > 1) { <p class="module">{{ mi + 1 }}. {{ m.title }}</p> }
            @for (l of m.lessons; track l.lessonId || $index) {
              <div class="lesson ion-activatable tappable" [class.locked]="!c.isEnrolled" (click)="openLesson(c, l.lessonId)">
                <ion-ripple-effect></ion-ripple-effect>
                <ion-icon class="l-ic"
                  [name]="l.isCompleted ? 'checkmark-circle' : (c.isEnrolled ? 'play-circle-outline' : 'lock-closed-outline')"
                  [style.color]="l.isCompleted ? 'var(--ion-color-success)' : 'var(--ion-color-medium)'"></ion-icon>
                <div class="l-body">
                  <p class="l-title">{{ l.title }}</p>
                  <p class="muted">{{ typeLabel(l.lessonType) }}{{ mins(l.durationMinutes) ? ' · ' + mins(l.durationMinutes) : '' }}</p>
                </div>
              </div>
            }
          }
          <div style="height: 16px"></div>
        </div>
        }
      } @else {
        <app-empty-state
          icon="cloud-offline-outline"
          [title]="'course.load_failed' | translate"
          [message]="'common.offline' | translate"
          [actionLabel]="'common.retry' | translate"
          (action)="load()"></app-empty-state>
      }
    </ion-content>
  `,
  styles: [`
    ion-content { --padding-bottom: calc(16px + env(safe-area-inset-bottom)); }
    .pad { padding: 16px 16px 0; }
    .banner { position: relative; overflow: hidden; height: 212px; border-radius: 0 0 26px 26px; }
    .banner::after { content: ''; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,0) 30%, rgba(0,0,0,.16) 55%, rgba(0,0,0,.56) 100%); }
    .banner .mark { position: absolute; top: 44%; left: 50%; transform: translate(-50%, -50%); color: rgba(255,255,255,.92); font-family: var(--app-font-display); font-size: 5rem; font-weight: var(--app-display-weight); opacity: .45; }
    .banner-cap { position: absolute; z-index: 1; left: 0; right: 0; bottom: 0; padding: 16px 18px 18px; }
    .banner-cat { display: inline-block; font-size: .66rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #fff; background: rgba(255,255,255,.22); padding: 4px 9px; border-radius: 999px; -webkit-backdrop-filter: blur(4px); backdrop-filter: blur(4px); }
    .banner-title { margin: 9px 0 0; font-family: var(--app-font-display); font-size: 1.62rem; line-height: 1.14; font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); color: #fff; text-wrap: balance; text-shadow: 0 2px 12px rgba(0,0,0,.3); }
    .meta { display: flex; flex-wrap: wrap; gap: 14px; font-size: .82rem; }
    .meta span { display: inline-flex; align-items: center; gap: 4px; }
    .prog { margin: 16px 0 4px; }
    .prog .muted { margin: 6px 0 0; font-size: .8rem; }
    .cta { display: block; margin: 16px 0 4px; }
    .later { display: block; margin: 2px 0 0; }
    .enrol-facts { display: flex; flex-wrap: wrap; gap: 14px; color: var(--app-muted); font-size: .85rem; }
    .enrol-facts span { display: inline-flex; align-items: center; gap: 5px; }
    .enrol-facts ion-icon { font-size: 16px; }
    .enrol-note { margin: 12px 0 0; line-height: 1.45; font-size: .9rem; }
    .section-title { margin: 22px 0 10px; }
    .desc { margin: 0; color: var(--ion-text-color); opacity: .85; line-height: 1.5; white-space: pre-line; }
    .module { font-weight: 700; margin: 16px 0 8px; font-size: .95rem; }
    .lesson { position: relative; overflow: hidden; display: flex; align-items: center; gap: 12px; background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r-sm); padding: 12px; margin-bottom: 8px; box-shadow: var(--app-shadow); }
    .lesson.locked { opacity: .7; }
    .l-ic { font-size: 24px; flex: 0 0 auto; }
    .l-body { flex: 1; min-width: 0; }
    .l-title { margin: 0; font-weight: 600; font-size: .92rem; }
    .l-body .muted { margin: 2px 0 0; font-size: .76rem; }
  `],
})
export class CourseDetailPage implements OnInit {
  private readonly learner = inject(LearnerService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastService);
  private readonly i18n = inject(I18nService);

  private courseId = '';
  readonly course = signal<CourseDetails | null>(null);
  readonly loading = signal(true);
  readonly enrolling = signal(false);
  readonly enrolOpen = signal(false);

  readonly thumb = thumbStyle;
  readonly initial = initialOf;
  readonly mins = minutesLabel;

  ngOnInit(): void {
    this.courseId = this.route.snapshot.paramMap.get('id') ?? '';
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.learner.courseDetails(this.courseId).subscribe({
      next: (c) => { this.course.set(c); this.loading.set(false); },
      error: () => { this.course.set(null); this.loading.set(false); },
    });
  }

  lessonCount(): number {
    return (this.course()?.modules ?? []).reduce((n, m) => n + m.lessons.length, 0);
  }

  enroll(c: CourseDetails): void {
    this.enrolling.set(true);
    this.learner.selfEnroll(c.courseId).subscribe({
      next: () => {
        this.enrolling.set(false);
        this.enrolOpen.set(false);
        this.note(this.i18n.t('course.enrolled_success'), 'success');
        this.load();
      },
      error: () => {
        this.enrolling.set(false);
        this.note(this.i18n.t('course.enrol_failed'), 'error');
      },
    });
  }

  openLesson(c: CourseDetails, lessonId: string): void {
    if (!c.isEnrolled) {
      this.note(this.i18n.t('course.enrol_to_start'));
      return;
    }
    void this.router.navigate(['/courses', c.courseId, 'lessons', lessonId]);
  }

  continueCourse(c: CourseDetails): void {
    const next = this.nextLessonId(c);
    if (next) {
      void this.router.navigate(['/courses', c.courseId, 'lessons', next]);
    } else {
      this.note(this.i18n.t('course.no_lessons_yet'));
    }
  }

  private nextLessonId(c: CourseDetails): string | null {
    for (const m of c.modules) {
      for (const l of m.lessons) {
        if (!l.isCompleted) {
          return l.lessonId;
        }
      }
    }
    return c.modules[0]?.lessons[0]?.lessonId ?? null;
  }

  typeLabel(t: string): string {
    const map: Record<string, string> = {
      video: 'lesson.type.video', text: 'lesson.type.reading', document: 'lesson.type.document', quiz: 'lesson.type.quiz',
      scorm: 'lesson.type.interactive', audio: 'lesson.type.audio', assignment: 'lesson.type.assignment',
    };
    return this.i18n.t(map[t] ?? 'lesson.type.lesson');
  }

  private note(message: string, variant: ToastVariant = 'info'): void {
    this.toast.show(variant, message);
  }
}
