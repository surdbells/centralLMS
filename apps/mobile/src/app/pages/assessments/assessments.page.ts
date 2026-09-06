import { Component, OnInit, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { IonContent, IonIcon, IonSkeletonText, IonRefresher, IonRefresherContent } from '@ionic/angular/standalone';
import { Assessment, AssessmentService } from '../../core/assessment.service';
import { BrandHeaderComponent } from '../../components/brand-header/brand-header.component';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';

@Component({
  selector: 'app-assessments',
  standalone: true,
  imports: [
    IonContent, IonIcon, IonSkeletonText, IonRefresher, IonRefresherContent,
    BrandHeaderComponent, EmptyStateComponent,
  ],
  template: `
    <ion-content [fullscreen]="true">
      <ion-refresher slot="fixed" (ionRefresh)="reload($event)">
        <ion-refresher-content></ion-refresher-content>
      </ion-refresher>

      <app-brand-header title="Assessments" subtitle="Quizzes and exams for you" back></app-brand-header>

      <div class="pad">
        @if (loading()) {
          @for (i of [1,2,3]; track i) {
            <div class="as-row skel">
              <ion-skeleton-text animated style="width:46px;height:46px;border-radius:12px;"></ion-skeleton-text>
              <div style="flex:1">
                <ion-skeleton-text animated style="width:55%;height:13px;border-radius:5px;"></ion-skeleton-text>
                <ion-skeleton-text animated style="width:35%;height:9px;margin-top:9px;border-radius:4px;"></ion-skeleton-text>
              </div>
            </div>
          }
        } @else if (failed()) {
          <app-empty-state icon="cloud-offline-outline" title="Couldn't load assessments"
            message="Check your connection and try again." actionLabel="Try again" (action)="load()"></app-empty-state>
        } @else if (!items().length) {
          <app-empty-state icon="clipboard-outline" title="No assessments yet"
            message="Quizzes and exams assigned to you will appear here."></app-empty-state>
        } @else {
          @for (a of items(); track a.assessmentId) {
            <button type="button" class="as-row tappable rise" (click)="open(a.assessmentId)">
              <div class="as-ic"><ion-icon [name]="a.proctoringLevel !== 'none' ? 'shield-checkmark-outline' : icon(a.kind)" aria-hidden="true"></ion-icon></div>
              <div class="as-body">
                <p class="as-title">{{ a.title }}</p>
                <p class="as-meta">{{ kindLabel(a.kind) }} · {{ a.questionCount }} {{ a.questionCount === 1 ? 'question' : 'questions' }}@if (a.passingScore) { · {{ a.passingScore }}% to pass }</p>
              </div>
              <div class="as-side">
                <span class="as-status" [attr.data-s]="a.enrollmentStatus">{{ statusLabel(a.enrollmentStatus) }}</span>
                @if (a.bestScore !== null && a.attemptCount > 0) { <span class="as-score">{{ a.bestScore }}%</span> }
              </div>
            </button>
          }
        }
      </div>
    </ion-content>
  `,
  styles: [`
    .pad { padding: 14px 16px calc(16px + env(safe-area-inset-bottom)); display: grid; gap: 10px; }
    .as-row { width: 100%; display: flex; align-items: center; gap: 13px; text-align: left; background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r); padding: 12px; box-shadow: var(--app-shadow); cursor: pointer; }
    .as-row.skel { box-shadow: none; border: 0; cursor: default; }
    .as-ic { flex: 0 0 auto; width: 46px; height: 46px; border-radius: var(--app-r-sm); display: grid; place-items: center; background: var(--app-tag-bg); color: var(--app-tag-ink); }
    .as-ic ion-icon { font-size: 23px; }
    .as-body { flex: 1; min-width: 0; }
    .as-title { margin: 0; font-weight: 600; font-size: .95rem; color: var(--app-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .as-meta { margin: 3px 0 0; font-size: .77rem; color: var(--app-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .as-side { flex: none; display: flex; flex-direction: column; align-items: flex-end; gap: 3px; }
    .as-status { font-size: .64rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--app-muted); background: var(--app-field); padding: 3px 8px; border-radius: 999px; }
    .as-status[data-s="passed"] { color: var(--ion-color-success); background: color-mix(in srgb, var(--ion-color-success) 15%, transparent); }
    .as-status[data-s="failed"] { color: var(--ion-color-danger); background: color-mix(in srgb, var(--ion-color-danger) 15%, transparent); }
    .as-status[data-s="in_progress"] { color: var(--ion-color-warning); background: color-mix(in srgb, var(--ion-color-warning) 16%, transparent); }
    .as-score { font-family: var(--app-font-display); font-weight: 800; font-size: .95rem; color: var(--ion-color-primary); }
  `],
})
export class AssessmentsPage implements OnInit {
  private readonly svc = inject(AssessmentService);
  private readonly router = inject(Router);

  readonly loading = signal(true);
  readonly failed = signal(false);
  readonly items = signal<Assessment[]>([]);

  ngOnInit(): void {
    this.load();
  }

  load(ev?: CustomEvent): void {
    if (!ev) { this.loading.set(true); }
    this.failed.set(false);
    this.svc.catalogue().subscribe({
      next: (a) => { this.items.set(a); this.loading.set(false); (ev?.target as { complete?: () => void })?.complete?.(); },
      error: () => { this.items.set([]); this.failed.set(true); this.loading.set(false); (ev?.target as { complete?: () => void })?.complete?.(); },
    });
  }

  reload(ev?: CustomEvent): void { this.load(ev); }

  open(id: string): void { void this.router.navigate(['/assessments', id]); }

  icon(kind: string): string {
    return kind === 'exam' ? 'document-text-outline' : kind === 'assignment' ? 'document-outline' : 'help-circle-outline';
  }

  kindLabel(kind: string): string {
    const map: Record<string, string> = { quiz: 'Quiz', exam: 'Exam', assignment: 'Assignment', survey: 'Survey' };
    return map[kind] ?? 'Assessment';
  }

  statusLabel(s: string): string {
    const map: Record<string, string> = { passed: 'Passed', failed: 'Failed', in_progress: 'In progress', not_started: 'Not started' };
    return map[s] ?? s;
  }
}
