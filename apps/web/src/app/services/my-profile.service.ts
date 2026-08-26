import { Injectable, inject } from '@angular/core';
import { Observable, filter, map } from 'rxjs';
import { ApiClient, Endpoints } from '../core/api';
import type { User } from './users.service';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/** What somebody may change about themselves. Nothing else is settable. */
export interface ProfileInput {
  firstName?: string;
  lastName?: string;
  phone?: string | null;
  locale?: string;
}

export interface Consents {
  privacy: boolean;
  marketing: boolean;
  dataProcessing: boolean;
}

/** One sign-in, from the refresh token that started it. */
export interface SignInSession {
  id: string;
  issuedAt: string;
  expiresAt: string;
  revokedAt: string | null;
  userAgent: string | null;
  ipAddress: string | null;
  active: boolean;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * The signed-in person's own record.
 *
 * Not one method here takes an id, and that is the point. The legacy's
 * `update-profile` took a `user_id` from the request body, so the screen had
 * to send one — and the server checked nothing about it.
 *
 * Email, status, roles and where somebody sits in the organisation are not
 * here: they are an administrator's to set, through {@link UsersService}.
 */
@Injectable({ providedIn: 'root' })
export class MyProfileService {
  private readonly api = inject(ApiClient);

  show(): Observable<User> {
    return this.api.get<User>(Endpoints.me.profile);
  }

  /** An absent field is left alone; a field sent empty is cleared. */
  update(input: ProfileInput): Observable<User> {
    return this.api.put<User>(Endpoints.me.profile, input);
  }

  consents(): Observable<Consents> {
    return this.api.get<Consents>(Endpoints.me.consents);
  }

  /**
   * The whole set, every time.
   *
   * An omitted consent is withdrawn — consent that is merely never mentioned
   * again is not consent.
   */
  saveConsents(consents: Consents): Observable<Consents> {
    return this.api.put<Consents>(Endpoints.me.consents, consents);
  }

  /**
   * Replaces the picture.
   *
   * The URL that comes back carries a checksum of the bytes, so a browser
   * holding the previous picture stops showing it.
   */
  uploadAvatar(file: File): Observable<User> {
    const form = new FormData();
    form.append('file', file);

    // Only the finished upload reaches the caller: a screen with one small
    // image has nothing to do with a progress percentage, and filtering the
    // events at every call site is how one of them forgets to.
    return this.api.upload<User>(Endpoints.me.avatar, form).pipe(
      filter((event): event is typeof event & { result: User } => event.state === 'done'),
      map((event) => event.result),
    );
  }

  removeAvatar(): Observable<User> {
    return this.api.delete<User>(Endpoints.me.avatar);
  }

  /** Every sign-in this account has had, newest first. */
  sessions(): Observable<SignInSession[]> {
    return this.api.get<SignInSession[]>(Endpoints.me.sessions);
  }

  /** Ends one. Scoped to the caller, so another person's id is a 404. */
  revokeSession(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.me.revokeSession(id));
  }
}
