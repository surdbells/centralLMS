import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import {
  IonContent, IonRefresher, IonRefresherContent, IonIcon, IonProgressBar,
  IonSkeletonText, IonRippleEffect,
} from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { SessionStore } from '../../core/session';
import { LearnerDashboard } from '../../core/models';
import { firstName, initialOf, minutesLabel, thumbStyle } from '../../core/ui';

@Component({
  selector: 'app-home',
  standalone: true,
  imports: [
    IonContent, IonRefresher, IonRefresherContent, IonIcon, IonProgressBar,
    IonSkeletonText, IonRippleEffect,
  ],
  template: `
    <ion-content [fullscreen]="true">
      <ion-refresher slot="fixed" (ionRefresh)="reload($event)">
        <ion-refresher-content></ion-refresher-content>
      </ion-refresher>

      <div class="hero">
        <p class="hi">Hello, {{ name() }} 👋</p>
        <h1>Keep it going.</h1>
      </div>

      @if (loading()) {
        <div class="pad">
          <ion-skeleton-text animated style="width: 100%; height: 84px; border-radius: 16px;"></ion-skeleton-text>
          <ion-skeleton-text animated style="width: 60%; height: 20px; margin-top: 20px;"></ion-skeleton-text>
          <ion-skeleton-text animated style="width: 100%; height: 96px; border-radius: 16px; margin-top: 12px;"></ion-skeleton-text>
        </div>
      } @else if (data()) {
        @if (data(); as d) {
        <!-- Stats -->
        <div class="stats">
          <div class="stat"><span class="n">{{ d.overview.inProgress }}</span><span class="l">In progress</span></div>
          <div class="stat"><span class="n">{{ d.overview.completed }}</span><span class="l">Completed</span></div>
          <div class="stat"><span class="n">{{ d.overview.certificates }}</span><span class="l">Certificates</span></div>
          <div class="stat"><span class="n">{{ d.streak.current }}🔥</span><span class="l">Day streak</span></div>
        </div>

        <!-- Continue learning -->
        @if (d.courses.inProgress.length) {
          <div class="pad">
            <div class="section-title">Continue learning</div>
          </div>
          <div class="rail">
            @for (c of d.courses.inProgress; track c.id) {
              <div class="rail-card ion-activatable" (click)="openCourse(c.courseId)">
                <ion-ripple-effect></ion-ripple-effect>
                <div class="thumb" [style]="thumb(c.courseTitle || '')">
                  <span>{{ initial(c.courseTitle || '') }}</span>
                </div>
                <div class="rc-body">
                  <p class="rc-title">{{ c.courseTitle }}</p>
                  <ion-progress-bar [value]="c.progressPercentage / 100"></ion-progress-bar>
                  <p class="rc-sub muted">{{ c.progressPercentage }}% complete</p>
                </div>
              </div>
            }
          </div>
        } @else {
          <div class="pad">
            <div class="empty">
              <ion-icon name="school-outline"></ion-icon>
              <p>Nothing in progress yet.</p>
              <a (click)="goCourses()">Browse the catalogue →</a>
            </div>
          </div>
        }

        <!-- Deadlines -->
        @if (d.deadlines.length) {
          <div class="pad">
            <div class="section-title">Due soon</div>
            @for (dl of d.deadlines; track dl.enrollmentId) {
              <div class="row ion-activatable" (click)="openCourse(dl.courseId)">
                <ion-ripple-effect></ion-ripple-effect>
                <ion-icon class="row-ic" [name]="dl.urgency === 'critical' ? 'alert-circle-outline' : 'calendar-outline'"
                          [style.color]="dl.urgency === 'normal' ? 'var(--ion-color-medium)' : 'var(--ion-color-warning)'"></ion-icon>
                <div class="row-body">
                  <p class="row-title">{{ dl.courseTitle }}</p>
                  <p class="muted">{{ dl.daysRemaining }} day{{ dl.daysRemaining === 1 ? '' : 's' }} left · {{ dl.progress }}%</p>
                </div>
                <ion-icon name="chevron-forward" class="chev"></ion-icon>
              </div>
            }
          </div>
        }

        <!-- Recommended -->
        @if (d.recommended.length) {
          <div class="pad">
            <div class="section-title">Recommended for you</div>
            @for (r of d.recommended; track r.courseId) {
              <div class="row ion-activatable" (click)="openCourse(r.courseId)">
                <ion-ripple-effect></ion-ripple-effect>
                <div class="mini-thumb" [style]="thumb(r.title)"><span>{{ initial(r.title) }}</span></div>
                <div class="row-body">
                  <p class="row-title">{{ r.title }}</p>
                  <p class="muted">{{ r.category }}{{ mins(r.durationMinutes) ? ' · ' + mins(r.durationMinutes) : '' }}</p>
                </div>
                <ion-icon name="chevron-forward" class="chev"></ion-icon>
              </div>
            }
          </div>
        }

        <div style="height: 12px"></div>
        }
      } @else {
        <div class="pad">
          <div class="empty">
            <ion-icon name="cloud-offline-outline"></ion-icon>
            <p>Couldn't load your dashboard.</p>
            <a (click)="reload()">Try again</a>
          </div>
        </div>
      }
    </ion-content>
  `,
  styles: [`
    .hero { padding: calc(env(safe-area-inset-top) + 20px) 20px 18px; background: linear-gradient(135deg, var(--ion-color-primary), var(--ion-color-primary-shade)); color: #fff; border-radius: 0 0 24px 24px; }
    .hero .hi { margin: 0; opacity: .85; font-size: .95rem; }
    .hero h1 { margin: 2px 0 0; font-size: 1.7rem; font-weight: 800; }
    .pad { padding: 0 16px; }
    .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; padding: 0 16px; margin: -22px 0 8px; }
    .stat { background: var(--ion-card-background); border-radius: 14px; padding: 12px 6px; text-align: center; box-shadow: 0 6px 18px rgba(0,0,0,.06); }
    .stat .n { display: block; font-size: 1.15rem; font-weight: 800; color: var(--ion-color-primary); }
    .stat .l { font-size: .66rem; color: var(--ion-color-medium); }
    .section-title { font-size: 1.05rem; font-weight: 700; margin: 18px 0 10px; }
    .rail { display: flex; gap: 12px; overflow-x: auto; padding: 0 16px 6px; scrollbar-width: none; }
    .rail::-webkit-scrollbar { display: none; }
    .rail-card { position: relative; overflow: hidden; flex: 0 0 78%; max-width: 300px; background: var(--ion-card-background); border-radius: 16px; box-shadow: 0 8px 22px rgba(0,0,0,.08); }
    .thumb { height: 96px; display: grid; place-items: center; }
    .thumb span { color: #fff; font-size: 2.2rem; font-weight: 800; opacity: .95; }
    .rc-body { padding: 10px 12px 12px; }
    .rc-title { margin: 0 0 8px; font-weight: 700; font-size: .95rem; line-height: 1.25; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .rc-sub { margin: 6px 0 0; font-size: .78rem; }
    .row { position: relative; overflow: hidden; display: flex; align-items: center; gap: 12px; background: var(--ion-card-background); border-radius: 14px; padding: 12px; margin-bottom: 10px; box-shadow: 0 4px 14px rgba(0,0,0,.05); }
    .row-ic { font-size: 24px; flex: 0 0 auto; }
    .mini-thumb { width: 44px; height: 44px; border-radius: 10px; display: grid; place-items: center; flex: 0 0 auto; }
    .mini-thumb span { color: #fff; font-weight: 800; }
    .row-body { flex: 1; min-width: 0; }
    .row-title { margin: 0; font-weight: 600; font-size: .92rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .row-body .muted { margin: 2px 0 0; font-size: .78rem; }
    .chev { color: var(--ion-color-medium); flex: 0 0 auto; }
    .empty { text-align: center; padding: 40px 16px; color: var(--ion-color-medium); }
    .empty ion-icon { font-size: 40px; }
    .empty p { margin: 10px 0 6px; }
    .empty a { color: var(--ion-color-primary); font-weight: 600; }
  `],
})
export class HomePage implements OnInit {
  private readonly learner = inject(LearnerService);
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);

  readonly data = signal<LearnerDashboard | null>(null);
  readonly loading = signal(true);
  readonly name = computed(() => firstName(this.session.session()?.name));

  readonly thumb = thumbStyle;
  readonly initial = initialOf;
  readonly mins = minutesLabel;

  ngOnInit(): void {
    this.load();
  }

  reload(ev?: CustomEvent): void {
    this.load(ev);
  }

  private load(ev?: CustomEvent): void {
    if (!ev) {
      this.loading.set(true);
    }
    this.learner.dashboard().subscribe({
      next: (d) => {
        this.data.set(d);
        this.loading.set(false);
        (ev?.target as { complete?: () => void })?.complete?.();
      },
      error: () => {
        this.loading.set(false);
        this.data.set(null);
        (ev?.target as { complete?: () => void })?.complete?.();
      },
    });
  }

  openCourse(courseId: string): void {
    void this.router.navigate(['/courses', courseId]);
  }

  goCourses(): void {
    void this.router.navigateByUrl('/tabs/courses');
  }
}
