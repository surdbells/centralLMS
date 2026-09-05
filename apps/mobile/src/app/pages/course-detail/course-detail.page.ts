import { Component, OnInit, inject, signal } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import {
  IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle,
  IonIcon, IonButton, IonProgressBar, IonSkeletonText, IonRippleEffect,
  IonSpinner, ToastController,
} from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { CourseDetails } from '../../core/models';
import { initialOf, minutesLabel, thumbStyle } from '../../core/ui';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';

@Component({
  selector: 'app-course-detail',
  standalone: true,
  imports: [
    IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle,
    IonIcon, IonButton, IonProgressBar, IonSkeletonText, IonRippleEffect, IonSpinner,
    EmptyStateComponent,
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
              <ion-progress-bar [value]="c.progressPercentage / 100"></ion-progress-bar>
              <p class="muted">{{ c.progressPercentage }}% complete</p>
            </div>
            <ion-button expand="block" class="cta" (click)="continueCourse(c)">
              <ion-icon name="play-outline" slot="start"></ion-icon>
              {{ c.progressPercentage > 0 ? 'Continue' : 'Start course' }}
            </ion-button>
          } @else {
            <ion-button expand="block" class="cta" (click)="enroll(c)" [disabled]="enrolling()">
              @if (enrolling()) { <ion-spinner name="crescent"></ion-spinner> }
              @else { Enrol — it's free }
            </ion-button>
          }

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
    .title { margin: 4px 0 10px; font-size: 1.4rem; font-weight: 800; line-height: 1.2; }
    .meta { display: flex; flex-wrap: wrap; gap: 14px; font-size: .82rem; }
    .meta span { display: inline-flex; align-items: center; gap: 4px; }
    .prog { margin: 16px 0 4px; }
    .prog .muted { margin: 6px 0 0; font-size: .8rem; }
    .cta { margin: 16px 0 4px; --border-radius: 12px; height: 48px; font-weight: 700; }
    .section-title { font-size: 1.05rem; font-weight: 700; margin: 22px 0 10px; }
    .desc { margin: 0; color: var(--ion-text-color); opacity: .85; line-height: 1.5; white-space: pre-line; }
    .module { font-weight: 700; margin: 16px 0 8px; font-size: .95rem; }
    .lesson { position: relative; overflow: hidden; display: flex; align-items: center; gap: 12px; background: var(--ion-card-background); border-radius: 12px; padding: 12px; margin-bottom: 8px; box-shadow: 0 3px 10px rgba(0,0,0,.04); }
    .lesson.locked { opacity: .7; }
    .l-ic { font-size: 24px; flex: 0 0 auto; }
    .l-body { flex: 1; min-width: 0; }
    .l-title { margin: 0; font-weight: 600; font-size: .92rem; }
    .l-body .muted { margin: 2px 0 0; font-size: .76rem; }
    .empty { text-align: center; padding: 60px 16px; color: var(--ion-color-medium); }
    .empty ion-icon { font-size: 42px; }
    .empty a { color: var(--ion-color-primary); font-weight: 600; }
  `],
})
export class CourseDetailPage implements OnInit {
  private readonly learner = inject(LearnerService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly toast = inject(ToastController);

  private courseId = '';
  readonly course = signal<CourseDetails | null>(null);
  readonly loading = signal(true);
  readonly enrolling = signal(false);

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
      next: async () => {
        this.enrolling.set(false);
        await this.note('You are enrolled. Enjoy the course!');
        this.load();
      },
      error: async () => {
        this.enrolling.set(false);
        await this.note("Couldn't enrol just now. Try again.", 'danger');
      },
    });
  }

  async openLesson(c: CourseDetails, lessonId: string): Promise<void> {
    if (!c.isEnrolled) {
      await this.note('Enrol to start the lessons.');
      return;
    }
    void this.router.navigate(['/courses', c.courseId, 'lessons', lessonId]);
  }

  continueCourse(c: CourseDetails): void {
    const next = this.nextLessonId(c);
    if (next) {
      void this.router.navigate(['/courses', c.courseId, 'lessons', next]);
    } else {
      void this.note('This course has no lessons yet.');
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

  private async note(message: string, color = 'dark'): Promise<void> {
    const t = await this.toast.create({ message, duration: 2200, position: 'bottom', color });
    await t.present();
  }
}
