import { Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { IonContent, IonIcon, IonSkeletonText } from '@ionic/angular/standalone';
import { BrandHeaderComponent } from '../../components/brand-header/brand-header.component';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';
import { LiveService, LiveSession } from '../../core/live.service';

/**
 * The learner's live classes — upcoming and in-progress sessions across all
 * their trainings. An in-app (Agora) session opens the room right here; an
 * external one opens its platform link. A virtual session whose organiser
 * hasn't set the video up yet shows as not-ready rather than a dead button.
 */
@Component({
  selector: 'app-live',
  standalone: true,
  imports: [IonContent, IonIcon, IonSkeletonText, BrandHeaderComponent, EmptyStateComponent],
  template: `
    <ion-content [fullscreen]="true">
      <app-brand-header title="Live Classes" subtitle="Your upcoming and live sessions" back></app-brand-header>

      <div class="pad">
        @if (loading()) {
          @for (i of [1,2,3]; track i) {
            <div class="card skel">
              <ion-skeleton-text animated style="width:46%;height:15px;border-radius:5px;"></ion-skeleton-text>
              <ion-skeleton-text animated style="width:70%;height:11px;margin-top:10px;border-radius:4px;"></ion-skeleton-text>
            </div>
          }
        } @else if (failed()) {
          <app-empty-state icon="cloud-offline-outline" title="Couldn't load your classes"
            message="Check your connection and try again." actionLabel="Try again" (action)="load()"></app-empty-state>
        } @else if (!sessions().length) {
          <app-empty-state icon="videocam-outline" title="No live classes"
            message="When you're enrolled in a session it'll show up here."></app-empty-state>
        } @else {
          @for (s of sessions(); track s.id) {
            <div class="card" [class.live]="isLive(s)">
              <div class="c-top">
                <span class="c-when">
                  @if (isLive(s)) { <span class="live-dot"></span> Live now }
                  @else { <ion-icon name="time-outline"></ion-icon> {{ whenLabel(s) }} }
                </span>
                @if (s.meeting?.platform === 'agora') { <span class="c-tag"><ion-icon name="radio-outline"></ion-icon> In-app</span> }
              </div>
              <p class="c-title">{{ s.title }}</p>
              @if (s.trainingTitle) { <p class="c-sub">{{ s.trainingTitle }}</p> }
              <div class="c-foot">
                <span class="c-time">{{ timeRange(s) }}</span>
                @if (joinable(s)) {
                  <button class="join" (click)="join(s)">
                    <ion-icon name="videocam"></ion-icon> Join
                  </button>
                } @else if (!s.meeting && s.delivery !== 'physical') {
                  <span class="c-note">Not set up yet</span>
                } @else if (isPast(s)) {
                  <span class="c-note">Ended</span>
                } @else if (s.delivery === 'physical') {
                  <span class="c-note">In person</span>
                } @else {
                  <span class="c-note">Opens 15 min before</span>
                }
              </div>
            </div>
          }
        }
      </div>
    </ion-content>
  `,
  styles: [`
    .pad { padding: 8px 16px calc(16px + env(safe-area-inset-bottom)); }
    .card { background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r); padding: 15px; margin-bottom: 12px; box-shadow: var(--app-shadow); }
    .card.live { border-color: color-mix(in srgb, var(--ion-color-danger) 45%, transparent); }
    .card.skel { box-shadow: none; }
    .c-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 8px; }
    .c-when { display: inline-flex; align-items: center; gap: 6px; font-size: .78rem; font-weight: 600; color: var(--app-muted); }
    .card.live .c-when { color: var(--ion-color-danger); }
    .c-when ion-icon { font-size: 14px; }
    .live-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--ion-color-danger); }
    .c-tag { display: inline-flex; align-items: center; gap: 4px; font-size: .66rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em;
      padding: 3px 8px; border-radius: 999px; color: var(--ion-color-primary); background: color-mix(in srgb, var(--ion-color-primary) 13%, transparent); }
    .c-tag ion-icon { font-size: 12px; }
    .c-title { margin: 0; font-weight: 650; font-size: 1.02rem; letter-spacing: -.01em; }
    .c-sub { margin: 3px 0 0; font-size: .82rem; color: var(--app-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .c-foot { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 12px; }
    .c-time { font-size: .8rem; color: var(--app-muted); }
    .c-note { font-size: .8rem; color: var(--app-muted); font-weight: 500; }
    .join { display: inline-flex; align-items: center; gap: 6px; border: 0; cursor: pointer; font-weight: 700; font-size: .86rem;
      padding: 9px 16px; border-radius: var(--app-r-pill, 999px); color: var(--app-accent-ink); background: var(--app-accent-fill); box-shadow: var(--app-btn-shadow); }
    .join ion-icon { font-size: 16px; }
  `],
})
export class LivePage implements OnInit {
  private readonly live = inject(LiveService);
  private readonly router = inject(Router);

  readonly sessions = signal<LiveSession[]>([]);
  readonly loading = signal(true);
  readonly failed = signal(false);

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.failed.set(false);
    this.live.sessions().subscribe({
      next: (list) => {
        // Hide sessions that finished more than a couple of hours ago.
        const cutoff = Date.now() - 2 * 3600_000;
        this.sessions.set(list.filter((s) => new Date(s.endsAt).getTime() > cutoff));
        this.loading.set(false);
      },
      error: () => {
        this.failed.set(true);
        this.loading.set(false);
      },
    });
  }

  join(session: LiveSession): void {
    const meeting = session.meeting;
    if (!meeting) {
      return;
    }
    if (meeting.platform === 'agora') {
      void this.router.navigate(['/live/room', session.id]);
    } else if (meeting.joinUrl) {
      window.open(meeting.joinUrl, '_blank');
    }
  }

  /** Joinable from 15 minutes before start until the end, once a meeting exists. */
  joinable(s: LiveSession): boolean {
    if (!s.meeting || s.delivery === 'physical') {
      return false;
    }
    const now = Date.now();
    const start = new Date(s.startsAt).getTime() - 15 * 60_000;
    const end = new Date(s.endsAt).getTime();
    return now >= start && now <= end;
  }

  isLive(s: LiveSession): boolean {
    if (s.status === 'ongoing') {
      return true;
    }
    const now = Date.now();
    return now >= new Date(s.startsAt).getTime() && now <= new Date(s.endsAt).getTime();
  }

  isPast(s: LiveSession): boolean {
    return Date.now() > new Date(s.endsAt).getTime();
  }

  whenLabel(s: LiveSession): string {
    const start = new Date(s.startsAt);
    const now = new Date();
    const sameDay = start.toDateString() === now.toDateString();
    const tomorrow = new Date(now); tomorrow.setDate(now.getDate() + 1);
    const isTomorrow = start.toDateString() === tomorrow.toDateString();
    const time = start.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    if (this.isPast(s)) { return start.toLocaleDateString([], { month: 'short', day: 'numeric' }); }
    if (sameDay) { return `Today · ${time}`; }
    if (isTomorrow) { return `Tomorrow · ${time}`; }
    return start.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric' }) + ` · ${time}`;
  }

  timeRange(s: LiveSession): string {
    const start = new Date(s.startsAt);
    const end = new Date(s.endsAt);
    const opts: Intl.DateTimeFormatOptions = { hour: 'numeric', minute: '2-digit' };
    return `${start.toLocaleTimeString([], opts)} – ${end.toLocaleTimeString([], opts)}`;
  }
}
