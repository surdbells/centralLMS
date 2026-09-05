import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import {
  IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle,
  IonIcon, IonSkeletonText, IonRippleEffect,
} from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { CourseDetails } from '../../core/models';
import { initialOf, minutesLabel, thumbStyle } from '../../core/ui';
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
  ],
  template: `
    <ion-header [translucent]="true">
      <ion-toolbar>
        <ion-buttons slot="start"><ion-back-button defaultHref="/tabs/courses"></ion-back-button></ion-buttons>
        <ion-title>Course</ion-title>
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
        </div>

        <div class="pad">
          <p class="cat muted">{{ c.categoryName || 'Course' }}</p>
          <h1 class="title">{{ c.title }}</h1>
          <div class="meta muted">
            @if (mins(c.durationMinutes)) { <span><ion-icon name="time-outline"></ion-icon> {{ mins(c.durationMinutes) }}</span> }
            <span><ion-icon name="list-outline"></ion-icon> {{ lessonCount() }} lessons</span>
            @if (c.level) { <span><ion-icon name="cellular-outline"></ion-icon> {{ c.level }}</span> }
          </div>

          @if (c.isEnrolled) {
            <div class="prog">
              <app-progress [value]="c.progressPercentage / 100"></app-progress>
              <p class="muted">{{ c.progressPercentage }}% complete</p>
            </div>
            <app-button block size="lg" class="cta" icon="play-outline" (click)="continueCourse(c)">
              {{ c.progressPercentage > 0 ? 'Continue' : 'Start course' }}
            </app-button>
          } @else {
            <app-button block size="lg" class="cta" (click)="enrolOpen.set(true)">
              Enrol — it's free
            </app-button>
          }

          <app-sheet [(open)]="enrolOpen" title="Enrol in this course" [subtitle]="c.title">
            <div class="enrol-facts">
              <span><ion-icon name="pricetag-outline" aria-hidden="true"></ion-icon> Free</span>
              <span><ion-icon name="list-outline" aria-hidden="true"></ion-icon> {{ lessonCount() }} lessons</span>
              @if (mins(c.durationMinutes)) {
                <span><ion-icon name="time-outline" aria-hidden="true"></ion-icon> {{ mins(c.durationMinutes) }}</span>
              }
            </div>
            <p class="enrol-note muted">You'll be enrolled straight away and can start the first lesson right now.</p>
            <app-button actions block size="lg" class="cta" [loading]="enrolling()" (click)="enroll(c)">Confirm enrolment</app-button>
            <app-button actions block variant="ghost" class="later" style="--btn-fg: var(--app-muted)" (click)="enrolOpen.set(false)">Not now</app-button>
          </app-sheet>

          @if (c.description) {
            <div class="section-title">About</div>
            <p class="desc">{{ c.description }}</p>
          }

          <div class="section-title">Lessons</div>
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
          title="Couldn't load this course"
          message="Check your connection and try again."
          actionLabel="Try again"
          (action)="load()"></app-empty-state>
      }
    </ion-content>
  `,
  styles: [`
    .pad { padding: 0 16px; }
    .banner { height: 150px; display: grid; place-items: center; }
    .banner .mark { color: #fff; font-size: 3.4rem; font-weight: 800; opacity: .95; }
    .cat { margin: 16px 0 0; text-transform: uppercase; font-size: .72rem; letter-spacing: .04em; }
    .title { margin: 4px 0 10px; font-family: var(--app-font-display); font-size: 1.5rem; font-weight: var(--app-display-weight); line-height: 1.16; letter-spacing: var(--app-display-tracking); }
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
    .section-title { font-family: var(--app-font-display); font-size: 1.08rem; font-weight: 700; margin: 22px 0 10px; letter-spacing: var(--app-display-tracking); }
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
        this.note('You are enrolled. Enjoy the course!', 'success');
        this.load();
      },
      error: () => {
        this.enrolling.set(false);
        this.note("Couldn't enrol just now. Try again.", 'error');
      },
    });
  }

  openLesson(c: CourseDetails, lessonId: string): void {
    if (!c.isEnrolled) {
      this.note('Enrol to start the lessons.');
      return;
    }
    void this.router.navigate(['/courses', c.courseId, 'lessons', lessonId]);
  }

  continueCourse(c: CourseDetails): void {
    const next = this.nextLessonId(c);
    if (next) {
      void this.router.navigate(['/courses', c.courseId, 'lessons', next]);
    } else {
      this.note('This course has no lessons yet.');
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
      video: 'Video', text: 'Reading', document: 'Document', quiz: 'Quiz',
      scorm: 'Interactive', audio: 'Audio', assignment: 'Assignment',
    };
    return map[t] ?? 'Lesson';
  }

  private note(message: string, variant: ToastVariant = 'info'): void {
    this.toast.show(variant, message);
  }
}
