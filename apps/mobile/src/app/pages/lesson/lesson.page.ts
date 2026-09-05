import { Component, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { Location } from '@angular/common';
import {
  IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle,
  IonIcon, IonButton, IonSpinner, IonSkeletonText, ToastController,
} from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { Lesson } from '../../core/models';
import { minutesLabel } from '../../core/ui';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';

@Component({
  selector: 'app-lesson',
  standalone: true,
  imports: [
    IonContent, IonHeader, IonToolbar, IonButtons, IonBackButton, IonTitle,
    IonIcon, IonButton, IonSpinner, IonSkeletonText, EmptyStateComponent,
  ],
  template: `
    <ion-header [translucent]="true">
      <ion-toolbar>
        <ion-buttons slot="start"><ion-back-button [defaultHref]="'/courses/' + courseId"></ion-back-button></ion-buttons>
        <ion-title>Lesson</ion-title>
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

          <div class="stage">
            <ion-icon [name]="stageIcon(l.lessonType)"></ion-icon>
            <p>{{ stageHint(l.lessonType) }}</p>
          </div>

          @if (done()) {
            <div class="done">
              <ion-icon name="checkmark-circle"></ion-icon>
              <span>Completed</span>
            </div>
          } @else {
            <ion-button expand="block" class="cta" (click)="complete()" [disabled]="saving()">
              @if (saving()) { <ion-spinner name="crescent"></ion-spinner> }
              @else { <ion-icon name="checkmark-circle-outline" slot="start"></ion-icon> Mark as complete }
            </ion-button>
          }
        </div>
        }
      } @else {
        <app-empty-state
          icon="cloud-offline-outline"
          title="Couldn't load this lesson"
          message="Go back and open it again."></app-empty-state>
      }
    </ion-content>
  `,
  styles: [`
    .pad { padding: 8px 16px 24px; }
    .type { text-transform: uppercase; letter-spacing: .04em; font-size: .72rem; margin: 8px 0 0; }
    .title { margin: 4px 0 20px; font-size: 1.4rem; font-weight: 800; line-height: 1.25; }
    .stage { background: var(--ion-card-background); border-radius: 16px; padding: 40px 20px; text-align: center; box-shadow: 0 6px 18px rgba(0,0,0,.06); }
    .stage ion-icon { font-size: 56px; color: var(--ion-color-primary); }
    .stage p { margin: 14px 0 0; color: var(--ion-color-medium); line-height: 1.5; }
    .cta { margin-top: 22px; --border-radius: 12px; height: 48px; font-weight: 700; }
    .done { margin-top: 22px; display: flex; align-items: center; justify-content: center; gap: 8px; color: var(--ion-color-success); font-weight: 700; padding: 14px; }
    .done ion-icon { font-size: 24px; }
    .empty { text-align: center; padding: 60px 16px; color: var(--ion-color-medium); }
    .empty ion-icon { font-size: 42px; }
  `],
})
export class LessonPage implements OnInit, OnDestroy {
  private readonly learner = inject(LearnerService);
  private readonly route = inject(ActivatedRoute);
  private readonly location = inject(Location);
  private readonly toast = inject(ToastController);

  private backTimer?: ReturnType<typeof setTimeout>;

  courseId = '';
  private lessonId = '';
  readonly lesson = signal<Lesson | null>(null);
  readonly loading = signal(true);
  readonly saving = signal(false);
  private readonly markedDone = signal(false);
  readonly done = computed(() => this.markedDone() || !!this.lesson()?.isCompleted);

  readonly mins = minutesLabel;

  ngOnInit(): void {
    this.courseId = this.route.snapshot.paramMap.get('courseId') ?? '';
    this.lessonId = this.route.snapshot.paramMap.get('lessonId') ?? '';
    this.learner.courseDetails(this.courseId).subscribe({
      next: (c) => {
        const found = c.modules.flatMap((m) => m.lessons).find((l) => l.lessonId === this.lessonId);
        this.lesson.set(found ?? null);
        this.loading.set(false);
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

  complete(): void {
    this.saving.set(true);
    this.learner.markLessonComplete(this.courseId, this.lessonId).subscribe({
      next: async () => {
        this.saving.set(false);
        this.markedDone.set(true);
        const t = await this.toast.create({ message: 'Lesson completed 🎉', duration: 1600, position: 'bottom', color: 'success' });
        await t.present();
        this.backTimer = setTimeout(() => this.location.back(), 900);
      },
      error: async () => {
        this.saving.set(false);
        const t = await this.toast.create({ message: "Couldn't save progress. Try again.", duration: 2200, position: 'bottom', color: 'danger' });
        await t.present();
      },
    });
  }

  typeLabel(t: string): string {
    const map: Record<string, string> = {
      video: 'Video', text: 'Reading', document: 'Document', quiz: 'Quiz',
      scorm: 'Interactive', audio: 'Audio', assignment: 'Assignment',
    };
    return map[t] ?? 'Lesson';
  }

  stageIcon(t: string): string {
    const map: Record<string, string> = {
      video: 'play-circle-outline', text: 'document-text-outline',
      document: 'document-text-outline', quiz: 'help-circle-outline',
    };
    return map[t] ?? 'document-text-outline';
  }

  stageHint(t: string): string {
    if (t === 'video') {
      return 'This lesson is a video. Rich in-app playback is coming; mark it complete when you have watched it.';
    }
    if (t === 'quiz') {
      return 'This lesson is a quiz. Take it in the full course, then mark it complete here.';
    }
    return 'Open the lesson content, then mark it complete to record your progress.';
  }
}
