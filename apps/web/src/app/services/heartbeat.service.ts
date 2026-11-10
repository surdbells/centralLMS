import { Injectable, NgZone, inject } from '@angular/core';
import { ApiClient, Endpoints } from '../core/api';
import { SessionStore } from '../core/auth/session.store';

/**
 * Tells the API the person is still here, so engagement analytics have a
 * session to measure (how long people spend, on what device).
 *
 * Pings on start, every minute while the app is open, and whenever the tab
 * becomes visible again — but only while signed in, and never holding up
 * Angular's change detection (the interval runs outside the zone).
 */
@Injectable({ providedIn: 'root' })
export class HeartbeatService {
  private readonly api = inject(ApiClient);
  private readonly store = inject(SessionStore);
  private readonly zone = inject(NgZone);

  private static readonly INTERVAL_MS = 60_000;
  private timer: ReturnType<typeof setInterval> | null = null;

  /** Begin pinging. Safe to call once at application start. */
  start(): void {
    if (this.timer !== null) {
      return;
    }

    this.ping();

    this.zone.runOutsideAngular(() => {
      this.timer = setInterval(() => this.ping(), HeartbeatService.INTERVAL_MS);

      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
          this.ping();
        }
      });
    });
  }

  private ping(): void {
    // Only while signed in — a ping is an authenticated call, and pinging while
    // logged out is just 401 noise.
    if (this.store.session() === null) {
      return;
    }

    this.api.post<{ sessionId: string | null }>(Endpoints.me.sessionPing, {}).subscribe({
      next: () => undefined,
      error: () => undefined,
    });
  }
}
