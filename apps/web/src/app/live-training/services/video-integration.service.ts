// live-training/services/video-integration.service.ts

import { Injectable, inject } from '@angular/core';
import { Observable, of, throwError } from 'rxjs';
import { map, tap } from 'rxjs/operators';
import { ApiClient, Endpoints } from '../../core/api';

export type Platform = 'zoom' | 'google_meet' | 'microsoft_teams';

export interface OAuthToken {
  platform: string;
  connected: boolean;
  account_email?: string;
  expires_at?: string;
}

export interface MeetingDetails {
  /** The provider's own id for the meeting. `meeting_id` is an alias. */
  id: string;
  meeting_id: string;
  topic: string;
  join_url: string;
  password?: string;
  start_time?: string;
  duration?: number;
  duration_minutes?: number;
  platform?: string;
  timezone?: string;
}

export interface MeetingParticipant {
  name: string;
  email: string;
  joined_at: string | null;
  left_at: string | null;
  duration_minutes: number;
}

export interface IntegrationStatus {
  platform: string;
  connected: boolean;
  is_active: boolean;
  expires_at?: string;
  account_email?: string;
  /** Whether this deployment has credentials for the platform at all. */
  available?: boolean;
}

/**
 * Connecting a tenant's Zoom, Google or Teams account.
 *
 * **The OAuth flow moved to the server, and that is the point of this
 * change.** This file used to hold three `VideoIntegrationConfig` literals
 * with a `clientId` read out of `environment.ts`, build the provider's
 * authorisation URL in the browser, and exchange the authorisation code for
 * an access token from the browser as well. That means the client secret has
 * to be reachable from the browser to complete the exchange, and the original
 * `environment.ts` did indeed have a live Zoom API key committed in it.
 *
 * The API holds the credentials encrypted, builds the authorisation URL, and
 * performs the exchange. Nothing here knows a client id, and no token ever
 * reaches this code — which is also why `exchangeCode` returns a connection
 * rather than a token.
 *
 * Meetings are booked by the API too, against the session they belong to:
 * `POST /trainings/{id}/sessions/{sessionId}/meeting`. Creating one from the
 * browser would need the access token in the browser.
 */
@Injectable({
  providedIn: 'root',
})
export class VideoIntegrationService {
  private readonly api = inject(ApiClient);

  /**
   * Where the provider sends the browser back to.
   *
   * Built from the running origin rather than configured, so a deployment
   * cannot be pointed at somebody else's callback by editing a constant. The
   * API refuses anything that is not https for the same reason — an http
   * redirect would carry the authorisation code in clear.
   */
  private redirectUri(): string {
    return `${window.location.origin}/integrations/callback`;
  }

  // ==========================================
  // CONNECTION
  // ==========================================

  getAllIntegrationStatuses(): Observable<IntegrationStatus[]> {
    return this.api
      .get<Array<Record<string, unknown>>>(Endpoints.integrations.list)
      .pipe(map((rows) => rows.map(toStatus)));
  }

  getIntegrationStatus(platform: string): Observable<IntegrationStatus> {
    return this.getAllIntegrationStatuses().pipe(
      map((all) => all.find((s) => s.platform === platform) ?? {
        platform,
        connected: false,
        is_active: false,
      }),
    );
  }

  isConnected(platform: string): Observable<boolean> {
    return this.getIntegrationStatus(platform).pipe(map((status) => status.connected));
  }

  /**
   * Starts the connection by sending the browser to the provider.
   *
   * The URL comes from the API, which knows the client id and signs the state
   * value with the tenant so the callback can tell whose account came back.
   */
  connect(platform: Platform): void {
    this.api
      .post<{ authorizationUrl: string; state: string }>(
        Endpoints.integrations.connect(platform),
        { redirectUri: this.redirectUri() },
      )
      .subscribe({
        next: (started) => {
          // Kept so the callback can check that the state it was handed is
          // the one we sent, which is what stops a replayed authorisation.
          sessionStorage.setItem(`integration.state.${platform}`, started.state);
          window.location.href = started.authorizationUrl;
        },
        error: (failure: { message?: string }) => {
          alert(failure.message ?? 'That provider could not be reached.');
        },
      });
  }

  exchangeCodeForTokens(platform: string, code: string, state: string): Observable<OAuthToken> {
    if (!this.verifyState(platform, state)) {
      return throwError(() => new Error(
        'That sign-in could not be verified. Start again from the integrations page.',
      ));
    }

    return this.api
      .post<Record<string, unknown>>(Endpoints.integrations.callback(platform), {
        code,
        state,
        redirectUri: this.redirectUri(),
      })
      .pipe(
        tap(() => sessionStorage.removeItem(`integration.state.${platform}`)),
        map((connection) => ({
          platform,
          connected: true,
          account_email: connection['accountEmail'] as string | undefined,
          expires_at: connection['expiresAt'] as string | undefined,
        })),
      );
  }

  /** Kept for the callback page, which calls it by this name. */
  exchangeCode(platform: string, code: string): Observable<OAuthToken> {
    return this.exchangeCodeForTokens(
      platform,
      code,
      sessionStorage.getItem(`integration.state.${platform}`) ?? '',
    );
  }

  /**
   * Whether the state handed back is the one we sent.
   *
   * The API checks this too — it has to, since a callback can be forged — but
   * checking here as well means a mismatched state never becomes a request.
   */
  verifyState(platform: string, state: string): boolean {
    const sent = sessionStorage.getItem(`integration.state.${platform}`);

    return sent !== null && sent === state;
  }

  disconnect(platform: string): Observable<void> {
    return this.api
      .delete(Endpoints.integrations.disconnect(platform))
      .pipe(map(() => undefined));
  }

  setDefaultPlatform(platform: string): Observable<void> {
    return this.api
      .patch(Endpoints.integrations.update(platform), { isDefault: true })
      .pipe(map(() => undefined));
  }

  // ==========================================
  // MEETINGS
  // ==========================================
  //
  // A meeting belongs to a session, and the API books it against that session
  // so the join link lands where learners will look for it. Creating one from
  // the browser would need the provider's access token in the browser, which
  // is the thing this change is about not doing.

  createMeeting(platform: string, meetingData: unknown): Observable<MeetingDetails> {
    const data = meetingData as Record<string, unknown>;
    const trainingId = String(data['trainingId'] ?? '');
    const sessionId = String(data['sessionId'] ?? '');

    if (trainingId === '' || sessionId === '') {
      return throwError(() => new Error(
        'A meeting is booked against a session. Open the session and use "Create meeting" there.',
      ));
    }

    return this.api
      .post<Record<string, unknown>>(Endpoints.sessions.meeting(trainingId, sessionId), {})
      .pipe(map((booked) => ({ ...toMeeting(booked), platform })));
  }

  getMeeting(_platform: string, _meetingId: string): Observable<MeetingDetails> {
    return notAvailable('Reading a meeting back from the provider');
  }

  updateMeeting(
    _platform: string,
    _meetingId: string,
    _meetingData: Record<string, unknown>,
  ): Observable<MeetingDetails> {
    return notAvailable('Editing a meeting at the provider');
  }

  deleteMeeting(_platform: string, _meetingId: string): Observable<void> {
    return notAvailable('Cancelling a meeting at the provider');
  }

  /**
   * Who actually joined.
   *
   * Read back from the provider by the API, not from the learner's browser —
   * a self-reported duration on something that decides a certification is a
   * number the learner controls. It is per session, so the attendance sync
   * endpoint is where it lives.
   */
  getMeetingParticipants(_platform: string, _meetingId: string): Observable<MeetingParticipant[]> {
    return of([]);
  }

  // ==========================================
  // PRESENTATION
  // ==========================================

  getPlatformDisplayName(platform: string): string {
    const names: Record<string, string> = {
      zoom: 'Zoom',
      google_meet: 'Google Meet',
      microsoft_teams: 'Microsoft Teams',
    };

    return names[platform] ?? platform;
  }

  getPlatformIcon(platform: string): string {
    const icons: Record<string, string> = {
      zoom: 'bi-camera-video',
      google_meet: 'bi-google',
      microsoft_teams: 'bi-microsoft',
    };

    return icons[platform] ?? 'bi-camera-video';
  }

  getPlatformColor(platform: string): string {
    const colours: Record<string, string> = {
      zoom: '#2D8CFF',
      google_meet: '#00897B',
      microsoft_teams: '#6264A7',
    };

    return colours[platform] ?? '#6c757d';
  }
}

// ---------------------------------------------------------------------------
// Translation
// ---------------------------------------------------------------------------

function toStatus(row: Record<string, unknown>): IntegrationStatus {
  return {
    platform: String(row['platform'] ?? ''),
    connected: row['connected'] === true,
    is_active: row['isActive'] !== false,
    expires_at: row['expiresAt'] as string | undefined,
    account_email: row['accountEmail'] as string | undefined,
    // The deployment may have no credentials for a platform at all, which is
    // a different thing from a tenant not having connected it — and the
    // difference decides whether "Connect" should be offered.
    available: row['available'] !== false,
  };
}

function toMeeting(row: Record<string, unknown>): MeetingDetails {
  const meeting = (row['meeting'] ?? row) as Record<string, unknown>;

  const id = String(meeting['id'] ?? '');

  return {
    id,
    meeting_id: id,
    topic: String(meeting['topic'] ?? row['title'] ?? ''),
    join_url: String(meeting['joinUrl'] ?? ''),
    password: meeting['password'] as string | undefined,
    start_time: meeting['scheduledAt'] as string | undefined,
    duration: meeting['durationMinutes'] as number | undefined,
    duration_minutes: meeting['durationMinutes'] as number | undefined,
    timezone: meeting['timezone'] as string | undefined,
  };
}

function notAvailable(what: string): Observable<never> {
  return throwError(() => new Error(`${what} is not available yet.`));
}
