import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import {
  IonContent, IonRefresher, IonRefresherContent, IonIcon,
  IonSkeletonText, IonRippleEffect,
} from '@ionic/angular/standalone';
import { LearnerService } from '../../core/learner.service';
import { SessionStore } from '../../core/session';
import { BrandingService } from '../../core/branding.service';
import { ActivityEntry, LearnerDashboard } from '../../core/models';
import { firstName, initialOf, minutesLabel, thumbStyle, timeAgo } from '../../core/ui';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';
import { ProgressComponent } from '../../components/progress/progress.component';

@Component({
  selector: 'app-home',
  standalone: true,
  imports: [
    IonContent, IonRefresher, IonRefresherContent, IonIcon,
    IonSkeletonText, IonRippleEffect, EmptyStateComponent, ProgressComponent,
  ],
  template: `
    <ion-content [fullscreen]="true">
      <ion-refresher slot="fixed" (ionRefresh)="reload($event)">
        <ion-refresher-content></ion-refresher-content>
      </ion-refresher>

      <div class="hero">
        <div class="hero-top">
          <span class="hero-org">{{ branding.organisation() || 'Central LMS' }}</span>
          @if (branding.logoUrl(); as logo) {
            <div class="hero-logo"><img [src]="logo" [alt]="branding.organisation() || 'Logo'" /></div>
          }
        </div>
        <p class="hi">Hello, {{ name() }} 👋</p>
        <h1>Keep it going.</h1>
      </div>

      @if (loading()) {
        <div class="stats">
          @for (i of [1,2,3,4]; track i) {
            <div class="stat">
              <ion-skeleton-text animated style="width: 44%; height: 20px; margin: 2px auto 8px; border-radius: 6px;"></ion-skeleton-text>
              <ion-skeleton-text animated style="width: 78%; height: 8px; margin: 0 auto; border-radius: 4px;"></ion-skeleton-text>
            </div>
          }
        </div>
        <div class="pad">
          <ion-skeleton-text animated style="width: 46%; height: 18px; margin: 20px 0 12px; border-radius: 6px;"></ion-skeleton-text>
        </div>
        <div class="rail">
          <div class="rail-card">
            <ion-skeleton-text animated style="width: 100%; height: 96px; --border-radius: 0;"></ion-skeleton-text>
            <div class="rc-body">
              <ion-skeleton-text animated style="width: 82%; height: 14px; border-radius: 5px;"></ion-skeleton-text>
              <ion-skeleton-text animated style="width: 100%; height: 7px; margin-top: 12px; border-radius: 4px;"></ion-skeleton-text>
            </div>
          </div>
        </div>
      } @else if (data()) {
        @if (data(); as d) {
        <!-- Stats -->
        <div class="stats rise">
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
              <div class="rail-card ion-activatable tappable" (click)="openCourse(c.courseId)">
                <ion-ripple-effect></ion-ripple-effect>
                <div class="thumb" [style]="thumb(c.courseTitle || '')">
                  <span>{{ initial(c.courseTitle || '') }}</span>
                </div>
                <div class="rc-body">
                  <p class="rc-title">{{ c.courseTitle }}</p>
                  <app-progress [value]="c.progressPercentage / 100"></app-progress>
                  <p class="rc-sub muted">{{ c.progressPercentage }}% complete</p>
                </div>
              </div>
            }
          </div>
        } @else {
          <app-empty-state
            icon="school-outline"
            title="Nothing in progress yet"
            message="Pick a course and it'll show up here to pick back up."
            actionLabel="Browse the catalogue"
            (action)="goCourses()"></app-empty-state>
        }

        <!-- Recent activity -->
        @if (d.recentActivity.length) {
          <div class="pad">
            <div class="act-head">
              <div class="section-title">Recent activity</div>
              @if (d.achievements?.rank; as r) {
                <span class="rank-chip"><ion-icon name="trophy-outline"></ion-icon> #{{ r.rank }} · {{ r.points }} pts</span>
              }
            </div>
            @for (a of d.recentActivity; track $index) {
              <div class="act">
                <div class="act-ic" [style.background]="actTint(a)">
                  <ion-icon [name]="actIcon(a)" [style.color]="actColor(a)"></ion-icon>
                </div>
                <div class="act-body">
                  <p class="act-title">{{ actTitle(a) }}</p>
                  @if (actSub(a)) { <p class="muted">{{ actSub(a) }}</p> }
                </div>
                <span class="act-time muted">{{ ago(a.at) }}</span>
              </div>
            }
          </div>
        }

        <!-- Deadlines -->
        @if (d.deadlines.length) {
          <div class="pad">
            <div class="section-title">Due soon</div>
            @for (dl of d.deadlines; track dl.enrollmentId) {
              <div class="row ion-activatable tappable" (click)="openCourse(dl.courseId)">
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
              <div class="row ion-activatable tappable" (click)="openCourse(r.courseId)">
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
        <app-empty-state
          icon="cloud-offline-outline"
          title="Couldn't load your dashboard"
          message="Check your connection and try again."
          actionLabel="Try again"
          (action)="reload()"></app-empty-state>
      }
    </ion-content>
  `,
  styles: [`
    .hero { padding: calc(env(safe-area-inset-top) + 16px) 20px 20px; background: linear-gradient(135deg, var(--brand-primary, var(--ion-color-primary)), var(--brand-primary-shade, var(--ion-color-primary-shade))); color: #fff; border-radius: 0 0 var(--app-r-lg) var(--app-r-lg); }
    .hero-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
    .hero-org { font-size: .72rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: rgba(255,255,255,.82); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .hero-logo { flex: 0 0 auto; width: 42px; height: 42px; border-radius: var(--app-r-sm); background: #fff; display: grid; place-items: center; padding: 5px; box-shadow: 0 6px 16px rgba(0,0,0,.16); }
    .hero-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .hero .hi { margin: 0; opacity: .9; font-size: .98rem; }
    .hero h1 { margin: 3px 0 0; font-size: 1.7rem; font-family: var(--app-font-display); font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); }
    .pad { padding: 0 16px; }
    .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; padding: 0 16px; margin: -22px 0 8px; }
    .stat { background: var(--ion-card-background); border: var(--app-card-border); border-radius: var(--app-r); padding: 12px 6px; text-align: center; box-shadow: var(--app-shadow); }
    .stat .n { display: block; font-size: 1.15rem; font-family: var(--app-font-display); font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); color: var(--ion-color-primary); }
    .stat .l { font-size: .66rem; color: var(--ion-color-medium); }
    .section-title { font-family: var(--app-font-display); font-size: 1.05rem; font-weight: 700; letter-spacing: var(--app-display-tracking); margin: 18px 0 10px; }
    .act-head { display: flex; align-items: center; justify-content: space-between; }
    .rank-chip { display: inline-flex; align-items: center; gap: 5px; font-size: .74rem; font-weight: 700; color: var(--app-tag-ink); background: var(--app-tag-bg); padding: 5px 10px; border-radius: var(--app-r-pill); }
    .rank-chip ion-icon { font-size: 14px; }
    .act { display: flex; align-items: center; gap: 12px; padding: 10px 2px; }
    .act + .act { border-top: 1px solid var(--app-hairline); }
    .act-ic { width: 40px; height: 40px; border-radius: var(--app-r-sm); display: grid; place-items: center; flex: 0 0 auto; }
    .act-ic ion-icon { font-size: 20px; }
    .act-body { flex: 1; min-width: 0; }
    .act-title { margin: 0; font-weight: 600; font-size: .92rem; }
    .act-body .muted { margin: 2px 0 0; font-size: .78rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .act-time { flex: 0 0 auto; font-size: .74rem; }
    .rail { display: flex; gap: 12px; overflow-x: auto; padding: 0 16px 6px; scrollbar-width: none; }
    .rail::-webkit-scrollbar { display: none; }
    .rail-card { position: relative; overflow: hidden; flex: 0 0 78%; max-width: 300px; background: var(--ion-card-background); border: var(--app-card-border); border-radius: var(--app-r); box-shadow: var(--app-shadow); }
    .thumb { height: 96px; display: grid; place-items: center; }
    .thumb span { color: #fff; font-size: 2.2rem; font-family: var(--app-font-display); font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); opacity: .95; }
    .rc-body { padding: 10px 12px 12px; }
    .rc-title { margin: 0 0 8px; font-weight: 700; font-size: .95rem; line-height: 1.25; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .rc-sub { margin: 6px 0 0; font-size: .78rem; }
    .row { position: relative; overflow: hidden; display: flex; align-items: center; gap: 12px; background: var(--ion-card-background); border: var(--app-card-border); border-radius: var(--app-r); padding: 12px; margin-bottom: 10px; box-shadow: var(--app-shadow); }
    .row-ic { font-size: 24px; flex: 0 0 auto; }
    .mini-thumb { width: 44px; height: 44px; border-radius: var(--app-r-sm); display: grid; place-items: center; flex: 0 0 auto; }
    .mini-thumb span { color: #fff; font-family: var(--app-font-display); font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); }
    .row-body { flex: 1; min-width: 0; }
    .row-title { margin: 0; font-weight: 600; font-size: .92rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .row-body .muted { margin: 2px 0 0; font-size: .78rem; }
    .chev { color: var(--ion-color-medium); flex: 0 0 auto; }
  `],
})
export class HomePage implements OnInit {
  private readonly learner = inject(LearnerService);
  private readonly session = inject(SessionStore);
  private readonly router = inject(Router);
  readonly branding = inject(BrandingService);

  readonly data = signal<LearnerDashboard | null>(null);
  readonly loading = signal(true);
  readonly name = computed(() => firstName(this.session.session()?.name));

  readonly thumb = thumbStyle;
  readonly initial = initialOf;
  readonly mins = minutesLabel;
  readonly ago = timeAgo;

  ngOnInit(): void {
    this.load();
  }

  // --- recent activity presentation ---------------------------------------

  actIcon(a: ActivityEntry): string {
    switch (a.kind) {
      case 'certificate': return 'ribbon';
      case 'completion': return 'checkmark-circle';
      case 'enrollment': return 'book-outline';
      case 'points': return 'trophy-outline';
      case 'badge': return 'star';
      default: return 'flame-outline';
    }
  }

  actColor(a: ActivityEntry): string {
    switch (a.kind) {
      case 'certificate': return '#d98a1f';
      case 'completion': return 'var(--ion-color-success)';
      case 'points': case 'badge': return 'var(--ion-color-primary)';
      default: return 'var(--ion-color-medium)';
    }
  }

  actTint(a: ActivityEntry): string {
    return `color-mix(in srgb, ${this.actColor(a)} 14%, transparent)`;
  }

  actTitle(a: ActivityEntry): string {
    switch (a.kind) {
      case 'points': return `+${a.detail ?? 0} points`;
      case 'certificate': return 'Certificate earned';
      case 'completion': return 'Course completed';
      case 'enrollment': return 'Enrolled in a course';
      case 'badge': return 'Badge earned';
      default: return this.humanize(a.kind);
    }
  }

  actSub(a: ActivityEntry): string {
    // For a points entry the subject is the reason ("certificate_earned"); for
    // the rest it's the thing itself (a course or certificate name).
    return a.kind === 'points' ? this.humanize(a.subject) : (a.subject ?? '');
  }

  private humanize(value: string | null): string {
    const s = (value ?? '').replace(/_/g, ' ').trim();
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : '';
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
