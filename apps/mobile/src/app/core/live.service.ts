import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { ApiClient } from './api';

/** What the in-app room needs to connect: identity, channel and short-lived tokens. */
export interface RoomToken {
  appId: string;
  channel: string;
  uid: string;
  displayName: string;
  rtcToken: string;
  rtmToken: string;
  role: 'host' | 'audience';
  isHost: boolean;
  expiresInSeconds: number;
  sessionTitle: string;
}

/** A session's booked meeting — null until an organiser sets the video up. */
export interface LiveMeeting {
  platform: 'zoom' | 'google_meet' | 'microsoft_teams' | 'agora' | string;
  joinUrl: string;
  password: string | null;
  scheduledAt: string | null;
}

export interface LiveSession {
  id: string;
  trainingId: string;
  title: string;
  description: string | null;
  delivery: 'physical' | 'virtual' | 'hybrid' | string;
  deliveryLabel: string;
  status: 'scheduled' | 'ongoing' | 'completed' | 'cancelled' | string;
  startsAt: string;
  endsAt: string;
  timezone: string;
  durationMinutes: number;
  venue: { name?: string; address?: string; room?: string } | null;
  meeting: LiveMeeting | null;
  isMandatory: boolean;
  allowLateJoin: boolean;
  // Flattened for the list — filled in by the service.
  trainingTitle?: string;
}

/** One of the learner's enrolled live trainings, with its sessions. */
export interface LiveEnrolment {
  id: string;
  trainingId: string;
  trainingTitle: string;
  status: string;
  sessions: LiveSession[];
}

/** The learner's live-class endpoints — the same ILT API the web app uses. */
@Injectable({ providedIn: 'root' })
export class LiveService {
  private readonly api = inject(ApiClient);

  /** The RTC/RTM tokens to open a session's in-app (Agora) room. */
  roomToken(sessionId: string): Observable<RoomToken> {
    return this.api.get<RoomToken>(`/ilt/sessions/${sessionId}/room-token`);
  }

  /**
   * Every live session the learner is enrolled in, flattened out of their
   * trainings and stamped with the training's title, newest start first.
   */
  sessions(): Observable<LiveSession[]> {
    return this.api.get<LiveEnrolment[]>('/me/trainings').pipe(
      map((enrolments) =>
        (enrolments ?? [])
          .flatMap((enrolment) =>
            (enrolment.sessions ?? []).map((session) => ({
              ...session,
              trainingTitle: enrolment.trainingTitle,
            })),
          )
          .filter((session) => session.status !== 'cancelled')
          .sort((a, b) => (a.startsAt < b.startsAt ? -1 : a.startsAt > b.startsAt ? 1 : 0)),
      ),
    );
  }
}
