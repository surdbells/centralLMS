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
import { AvatarComponent } from '../../components/avatar/avatar.component';

@Component({
  selector: 'app-home',
  standalone: true,
  imports: [
    IonContent, IonRefresher, IonRefresherContent, IonIcon,
    IonSkeletonText, IonRippleEffect, EmptyStateComponent, ProgressComponent, AvatarComponent,
  ],
  template: `
    <ion-content [fullscreen]="true">
      <ion-refresher slot="fixed" (ionRefresh)="reload($event)">
        <ion-refresher-content></ion-refresher-content>
      </ion-refresher>

      <!-- Top app-bar -->
      <div class="topbar">
        <div class="tb-brand">
          @if (branding.logoUrl(); as logo) {
            <div class="tb-logo"><img [src]="logo" [alt]="branding.organisation() || 'Logo'" /></div>
          }
          <div class="tb-tx">
            <p class="tb-org">{{ branding.organisation() || 'Central LMS' }}</p>
            <p class="tb-tag">Learn. Grow. Succeed.</p>
          </div>
        </div>
        <button class="tb-avatar" [class.has-photo]="avatarUrl()" (click)="goProfile()" aria-label="Your profile">
          @if (avatarUrl(); as u) {
            <app-avatar [url]="u" [name]="fullName()" [size]="42"></app-avatar>
          } @else {
            {{ initial(fullName()) }}
          }
        </button>
      </div>

      <!-- Hero -->
      <div class="hero rise">
        <div class="hero-head">
          <div class="hero-greet">
            <p class="hi">{{ greeting() }} 👋</p>
            <h1>{{ fullName() }}</h1>
            <p class="sub">Keep learning, keep growing!</p>
          </div>
          @if (data()?.achievements?.rank; as r) {
            <button class="badge" (click)="goAchievements()">
              <ion-icon name="shield-checkmark-outline" aria-hidden="true"></ion-icon>
              <span class="badge-tx">
                <span class="badge-n">#{{ r.rank }}</span>
                <span class="badge-l">{{ r.points }} points</span>
              </span>
            </button>
          }
        </div>

        @if (data(); as d) {
          <div class="hprog">
            <div class="hp-top">
              <span class="hp-title">Overall progress</span>
              <button class="hp-link" (click)="goProgress()">View details</button>
            </div>
            <div class="hp-bar">
              <app-progress [value]="d.overview.overallProgress / 100"></app-progress>
              <span class="hp-pct">{{ d.overview.overallProgress }}%</span>
            </div>
          </div>

          <div class="hstats">
            <div class="hstat"><div class="hs-ic"><ion-icon name="book-outline"></ion-icon></div><div class="hs-tx"><span class="hs-n">{{ d.overview.enrolled }}</span><span class="hs-l">Enrolled</span></div></div>
            <div class="hstat"><div class="hs-ic"><ion-icon name="play-circle-outline"></ion-icon></div><div class="hs-tx"><span class="hs-n">{{ d.overview.inProgress }}</span><span class="hs-l">In Progress</span></div></div>
            <div class="hstat"><div class="hs-ic"><ion-icon name="trophy-outline"></ion-icon></div><div class="hs-tx"><span class="hs-n">{{ d.overview.completed }}</span><span class="hs-l">Completed</span></div></div>
            <div class="hstat"><div class="hs-ic"><ion-icon name="time-outline"></ion-icon></div><div class="hs-tx"><span class="hs-n">{{ learningTime(d.overview.secondsSpent) }}</span><span class="hs-l">Learning</span></div></div>
          </div>
        }
      </div>

      <!-- Quick actions -->
      <div class="quick surface rise">
        @for (q of quickActions; track q.url) {
          <button type="button" class="qa tappable" [attr.data-tone]="q.tone" (click)="go(q.url)">
            <div class="qa-ic"><ion-icon [name]="q.icon" aria-hidden="true"></ion-icon></div>
            <span class="qa-l">{{ q.label }}</span>
          </button>
        }
      </div>

      @if (loading()) {
        <div class="pad">
          <ion-skeleton-text animated style="width: 46%; height: 18px; margin: 20px 0 12px; border-radius: 6px;"></ion-skeleton-text>
        </div>
        <div class="rail">
          <div class="rail-card">
            <ion-skeleton-text animated style="width: 100%; height: 108px; --border-radius: 0;"></ion-skeleton-text>
            <div class="rc-body">
              <ion-skeleton-text animated style="width: 82%; height: 14px; border-radius: 5px;"></ion-skeleton-text>
              <ion-skeleton-text animated style="width: 100%; height: 7px; margin-top: 12px; border-radius: 4px;"></ion-skeleton-text>
            </div>
          </div>
        </div>
      } @else if (data()) {
        @if (data(); as d) {
        <!-- Continue learning -->
        @if (d.courses.inProgress.length) {
          <div class="pad">
            <div class="act-head">
              <div class="section-title">Continue learning</div>
              <button class="see-all" (click)="goCourses()">See all</button>
            </div>
          </div>
          <div class="rail">
            @for (c of d.courses.inProgress; track c.id) {
              <div class="rail-card ion-activatable tappable" (click)="openCourse(c.courseId)">
                <ion-ripple-effect></ion-ripple-effect>
                <div class="thumb" [style]="thumb(c.courseTitle || '')">
                  <span class="rc-mark">{{ initial(c.courseTitle || '') }}</span>
                  <span class="rc-play"><ion-icon name="play" aria-hidden="true"></ion-icon></span>
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
            <div class="section-title">Recent activity</div>
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

        <!-- Due soon -->
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
    /* ── Top app-bar ── */
    .topbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: calc(env(safe-area-inset-top) + 10px) 16px 8px; }
    .tb-brand { display: flex; align-items: center; gap: 11px; min-width: 0; }
    .tb-logo { flex: none; width: 42px; height: 42px; border-radius: var(--app-r-sm); background: #fff; display: grid; place-items: center; padding: 5px; box-shadow: var(--app-shadow); border: var(--app-card-border); }
    .tb-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .tb-tx { min-width: 0; }
    .tb-org { margin: 0; font-family: var(--app-font-display); font-weight: 800; font-size: 1.02rem; color: var(--app-ink); letter-spacing: var(--app-display-tracking); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .tb-tag { margin: 1px 0 0; font-size: .72rem; color: var(--app-muted); }
    .tb-avatar { flex: none; width: 42px; height: 42px; padding: 0; border-radius: 50%; border: 0; overflow: hidden; display: grid; place-items: center; background: var(--app-accent-fill); color: var(--app-accent-ink); font-family: var(--app-font-display); font-weight: 800; font-size: 1rem; cursor: pointer; box-shadow: var(--app-btn-shadow); }
    .tb-avatar.has-photo { background: transparent; box-shadow: none; }

    /* ── Hero ── */
    .hero {
      position: relative; overflow: hidden; isolation: isolate;
      margin: 4px 12px 0; padding: 20px; color: #fff; border-radius: var(--app-r-lg);
      background:
        radial-gradient(135% 90% at 88% -20%, color-mix(in srgb, var(--brand-primary, var(--ion-color-primary)) 42%, #ffffff) 0%, transparent 54%),
        linear-gradient(155deg, var(--brand-primary, var(--ion-color-primary)) 0%, var(--brand-primary-shade, var(--ion-color-primary-shade)) 100%);
      box-shadow: 0 20px 44px -24px color-mix(in srgb, var(--brand-primary, #2400a0) 78%, transparent);
    }
    .hero::before { content: ''; position: absolute; z-index: -1; top: -70px; right: -40px; width: 200px; height: 200px; border-radius: 50%; background: radial-gradient(circle, rgba(255,255,255,.2), transparent 68%); }
    .hero-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
    .hero-greet { min-width: 0; }
    .hero .hi { margin: 0; opacity: .88; font-size: .95rem; font-weight: 500; }
    .hero h1 { margin: 3px 0 0; font-size: 1.7rem; line-height: 1.1; font-family: var(--app-font-display); font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); }
    .hero .sub { margin: 5px 0 0; opacity: .82; font-size: .84rem; }
    .badge { flex: none; display: flex; align-items: center; gap: 8px; border: 0; cursor: pointer; color: #fff; background: rgba(255,255,255,.16); border: 1px solid rgba(255,255,255,.22); padding: 8px 12px; border-radius: var(--app-r-sm); -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px); }
    .badge > ion-icon { font-size: 22px; }
    .badge-tx { display: flex; flex-direction: column; line-height: 1.1; }
    .badge-n { font-family: var(--app-font-display); font-weight: 800; font-size: 1.02rem; }
    .badge-l { font-size: .64rem; opacity: .82; white-space: nowrap; }

    .hprog { margin-top: 18px; background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.14); border-radius: var(--app-r-sm); padding: 13px 14px; }
    .hp-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 9px; }
    .hp-title { font-size: .82rem; font-weight: 600; }
    .hp-link { border: 0; background: transparent; color: #fff; opacity: .9; font-size: .78rem; font-weight: 600; cursor: pointer; text-decoration: underline; }
    .hp-bar { display: flex; align-items: center; gap: 12px; }
    .hp-bar app-progress { flex: 1; --app-track: rgba(255,255,255,.28); --app-accent-fill: #fff; }
    .hp-pct { flex: none; font-family: var(--app-font-display); font-weight: 800; font-size: .95rem; }

    .hstats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 9px; margin-top: 12px; }
    .hstat { background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.12); border-radius: var(--app-r-sm); padding: 12px 13px; display: flex; flex-direction: row; align-items: center; gap: 11px; }
    .hs-ic { flex: none; width: 38px; height: 38px; border-radius: 11px; background: rgba(255,255,255,.16); display: grid; place-items: center; }
    .hs-ic ion-icon { font-size: 20px; color: #fff; }
    .hs-tx { display: flex; flex-direction: column; line-height: 1.1; min-width: 0; }
    .hs-n { font-family: var(--app-font-display); font-weight: 800; font-size: 1.25rem; }
    .hs-l { font-size: .72rem; opacity: .82; white-space: nowrap; }

    /* ── Quick actions ── */
    .quick { margin: 14px 12px 4px; padding: 14px 8px; display: grid; grid-template-columns: repeat(5, 1fr); gap: 4px; }
    .qa { display: flex; flex-direction: column; align-items: center; gap: 7px; border: 0; background: transparent; cursor: pointer; padding: 4px 2px; }
    .qa-ic { width: 46px; height: 46px; border-radius: 15px; display: grid; place-items: center; background: color-mix(in srgb, var(--tone) 16%, transparent); color: var(--tone); }
    .qa-ic ion-icon { font-size: 23px; }
    .qa-l { font-size: .62rem; font-weight: 600; color: var(--app-ink); text-align: center; line-height: 1.1; white-space: nowrap; letter-spacing: -.01em; }
    .qa[data-tone="blue"] { --tone: #3b6fe0; }
    .qa[data-tone="green"] { --tone: #1f9d57; }
    .qa[data-tone="purple"] { --tone: #7a52d8; }
    .qa[data-tone="amber"] { --tone: #db8a1c; }
    .qa[data-tone="pink"] { --tone: #db4a7e; }

    /* ── Sections ── */
    .pad { padding: 0 16px; }
    .section-title { font-family: var(--app-font-display); font-size: 1.05rem; font-weight: 700; letter-spacing: var(--app-display-tracking); margin: 18px 0 10px; }
    .act-head { display: flex; align-items: center; justify-content: space-between; }
    .see-all { border: 0; background: transparent; color: var(--ion-color-primary); font-weight: 600; font-size: .82rem; cursor: pointer; }

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
    .rail-card { position: relative; overflow: hidden; flex: 0 0 80%; max-width: 320px; background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r); box-shadow: var(--app-shadow-lg); }
    .thumb { position: relative; overflow: hidden; height: 108px; display: grid; place-items: center; }
    .thumb::after { content: ''; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(0,0,0,0) 45%, rgba(0,0,0,.32)); }
    .rc-mark { color: #fff; font-size: 2.6rem; font-family: var(--app-font-display); font-weight: var(--app-display-weight); letter-spacing: var(--app-display-tracking); opacity: .5; }
    .rc-play { position: absolute; z-index: 1; right: 10px; bottom: 10px; width: 34px; height: 34px; border-radius: 50%; background: rgba(255,255,255,.94); display: grid; place-items: center; box-shadow: 0 6px 16px -4px rgba(0,0,0,.4); }
    .rc-play ion-icon { font-size: 16px; color: var(--ion-color-primary); margin-left: 2px; }
    .rc-body { padding: 10px 12px 12px; }
    .rc-title { margin: 0 0 8px; font-weight: 700; font-size: .95rem; line-height: 1.25; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    .rc-sub { margin: 6px 0 0; font-size: .78rem; }

    .row { position: relative; overflow: hidden; display: flex; align-items: center; gap: 12px; background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r); padding: 12px; margin-bottom: 10px; box-shadow: var(--app-shadow); }
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
  readonly fullName = computed(() => this.session.session()?.name || 'Learner');
  readonly avatarUrl = signal<string | null>(null);

  readonly quickActions = [
    { label: 'Resources', icon: 'library-outline', tone: 'blue', url: '/tabs/courses' },
    { label: 'Assessments', icon: 'clipboard-outline', tone: 'green', url: '/assessments' },
    { label: 'Certificates', icon: 'ribbon-outline', tone: 'purple', url: '/certificates' },
    { label: 'Achievements', icon: 'medal-outline', tone: 'amber', url: '/achievements' },
    { label: 'Library', icon: 'folder-open-outline', tone: 'pink', url: '/library' },
  ];

  readonly thumb = thumbStyle;
  readonly initial = initialOf;
  readonly mins = minutesLabel;
  readonly ago = timeAgo;

  ngOnInit(): void {
    this.load();
  }

  // ionViewWillEnter (not ngOnInit) so a picture changed over in Edit Profile
  // shows when the learner returns to the Home tab — the tab stays cached, so
  // ngOnInit never runs again.
  ionViewWillEnter(): void {
    this.learner.profile().subscribe({
      next: (p) => this.avatarUrl.set((p.avatarUrl as string) || null),
      error: () => this.avatarUrl.set(null),
    });
  }

  greeting(): string {
    const h = new Date().getHours();
    return h < 12 ? 'Good morning,' : h < 18 ? 'Good afternoon,' : 'Good evening,';
  }

  learningTime(seconds: number): string {
    const label = minutesLabel(Math.round((seconds ?? 0) / 60));
    return label || '0m';
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

  go(url: string): void {
    void this.router.navigateByUrl(url);
  }

  goCourses(): void {
    void this.router.navigateByUrl('/tabs/courses');
  }

  goProfile(): void {
    void this.router.navigateByUrl('/tabs/profile');
  }

  goProgress(): void {
    void this.router.navigateByUrl('/tabs/progress');
  }

  goAchievements(): void {
    void this.router.navigateByUrl('/achievements');
  }
}
