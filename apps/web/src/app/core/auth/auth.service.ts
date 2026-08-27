import { Injectable, inject } from '@angular/core';
import { Observable, map, of, tap } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { ApiClient, Endpoints } from '../api';
import { SessionStore, type Session } from './session.store';

/** What `/auth/login` returns. */
interface LoginPayload {
  readonly accessToken: string;
  readonly refreshToken: string;
  readonly user: {
    readonly id: string;
    readonly tenantId: string;
    readonly email: string;
    readonly firstName: string;
    readonly lastName: string;
    readonly fullName: string;
    readonly status: string;
  };
  readonly roles: ReadonlyArray<{ readonly id: string; readonly slug: string; readonly name: string }>;
  readonly permissions: readonly string[];
}

/** Everything a signed-in caller is. */
export interface SignedIn {
  readonly session: Session;
  readonly fullName: string;
  readonly roles: readonly string[];
  /** False when the account exists but has been deactivated. */
  readonly isActive: boolean;
}

export interface Credentials {
  readonly email: string;
  readonly password: string;
}

/** The key the not-yet-migrated components still read their identity out of. */
const LEGACY_SESSION_KEY = 'SESSION';

/**
 * Signing in, out, and refreshing.
 *
 * One dialect now. This class used to read two — the legacy backend returned a
 * flat snake_case body with role membership as four booleans, and the branch
 * that told them apart stayed while the two ran side by side. The legacy
 * backend is gone, and so is the branch.
 *
 * **It still writes the legacy session blob.** Sixty components read
 * `sessionStorage.SESSION` for the caller's name and id. They are being moved
 * to {@link SessionStore}; until the last one does, removing this would blank
 * out those screens. It is a bridge with an end date, and
 * {@link writeLegacyBlob} is the single place to delete when that date comes.
 */
/** What the server will say about a password link, and no more than this. */
export interface PasswordTokenState {
  valid: boolean;
  /** `reset` or `invitation`, or null when the link means nothing. */
  purpose: 'reset' | 'invitation' | null;
}

@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly api = inject(ApiClient);
  private readonly store = inject(SessionStore);

  signIn(credentials: Credentials): Observable<SignedIn> {
    return this.api.post<LoginPayload>(Endpoints.auth.login, credentials).pipe(
      map((payload) => this.interpret(payload)),
      tap((signedIn) => {
        // Only a usable account is stored. Signing somebody in and then
        // showing them a "your account is inactive" screen leaves a valid
        // session behind that a refresh would walk straight back into.
        if (signedIn.isActive) {
          this.store.set(signedIn.session);
          this.writeLegacyBlob(signedIn);
        }
      }),
    );
  }

  /**
   * Exchanges the refresh token for a new access token.
   */
  refresh(): Observable<boolean> {
    const session = this.store.session();

    if (session?.refreshToken === undefined) {
      return of(false);
    }

    return this.api
      .post<{ accessToken: string; refreshToken?: string }>(Endpoints.auth.refresh, {
        refreshToken: session.refreshToken,
      })
      .pipe(
        tap(({ accessToken, refreshToken }) => this.store.updateToken(accessToken, refreshToken)),
        map(() => true),
        catchError(() => {
          // A refresh token the server will not honour is not recoverable;
          // holding on to it would retry on every request.
          this.signOutLocally();

          return of(false);
        }),
      );
  }

  /**
   * Signs out here and there.
   *
   * The local half runs regardless of what the server says. A logout that
   * fails silently because the network was down, leaving the person still
   * signed in on a shared machine, is the wrong way for this to break.
   */
  signOut(): Observable<void> {
    const session = this.store.session();

    this.signOutLocally();

    if (session === null) {
      return of(undefined);
    }

    return this.api
      .post<unknown>(Endpoints.auth.logout, { refreshToken: session.refreshToken })
      .pipe(
        map(() => undefined),
        catchError(() => of(undefined)),
      );
  }

  /**
   * Asks for a link to set a new password.
   *
   * Completes the same way whether or not an account exists — the server
   * answers 204 either way, deliberately, because a reply that says "no
   * such account" is a way to read off who works somewhere.
   */
  forgotPassword(email: string): Observable<void> {
    return this.api
      .post<unknown>(Endpoints.auth.forgotPassword, { email })
      .pipe(map(() => undefined));
  }

  /**
   * Whether a link is still good, so a form can say so before somebody
   * types a new password into it.
   *
   * Nothing about the account comes back, not even the address it was
   * sent to. `purpose` distinguishes an invitation from a reset, which is
   * only used to choose the wording.
   */
  checkPasswordToken(token: string): Observable<PasswordTokenState> {
    return this.api.get<PasswordTokenState>(Endpoints.auth.checkPasswordToken, { token });
  }

  /**
   * Redeems a link and sets the password.
   *
   * Every session opened with the old password is revoked server-side, so
   * there is nothing to sign out of locally afterwards.
   */
  resetPassword(token: string, password: string): Observable<void> {
    return this.api
      .post<unknown>(Endpoints.auth.resetPassword, { token, password })
      .pipe(map(() => undefined));
  }

  private signOutLocally(): void {
    this.store.clear();
    sessionStorage.removeItem(LEGACY_SESSION_KEY);
    sessionStorage.removeItem('IS_CURRENT');
  }

  private interpret(payload: LoginPayload): SignedIn {
    return {
      session: {
        token: payload.accessToken,
        refreshToken: payload.refreshToken,
        userId: payload.user.id,
        tenantId: payload.user.tenantId,
        email: payload.user.email,
        permissions: payload.permissions,
      },
      fullName: payload.user.fullName,
      roles: payload.roles.map((role) => role.slug),
      isActive: payload.user.status === 'active',
    };
  }

  /**
   * Leaves the blob the not-yet-migrated components read.
   *
   * Shaped exactly as the legacy login response was, because that is what
   * `atob(sessionStorage.SESSION)` is parsed into in sixty files. Delete this
   * when the last of them moves to {@link SessionStore}.
   */
  private writeLegacyBlob(signedIn: SignedIn): void {
    const [firstName = '', ...rest] = signedIn.fullName.split(' ');

    const blob = {
      id: signedIn.session.userId,
      tenant_id: signedIn.session.tenantId,
      tenant: { id: signedIn.session.tenantId, name: '', status: 'active' },
      token: signedIn.session.token,
      first_name: firstName,
      last_name: rest.join(' '),
      email: signedIn.session.email ?? '',
      phone: '',
      avatar: '',
      is_2fa: false,
      is_active: signedIn.isActive,
      account_locked: false,
      is_admin: signedIn.roles.includes('org_admin') || signedIn.roles.includes('tenant_admin'),
      is_content: signedIn.roles.includes('content_creator'),
      is_instructor: signedIn.roles.includes('instructor'),
      is_learner: signedIn.roles.includes('learner'),
    };

    // Encoded as UTF-8 before base64: `btoa` throws on any character above
    // U+00FF, so a name with an accent in it would take the whole sign-in
    // down without this.
    const json = JSON.stringify(blob);
    const bytes = new TextEncoder().encode(json);
    const binary = Array.from(bytes, (byte) => String.fromCharCode(byte)).join('');

    sessionStorage.setItem(LEGACY_SESSION_KEY, btoa(binary));
  }
}
