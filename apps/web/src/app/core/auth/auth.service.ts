import { Injectable, inject } from '@angular/core';
import { Observable, map, of, tap } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { ApiClient, ApiRouter, Endpoints } from '../api';
import { SessionStore, type Session } from './session.store';

// ---------------------------------------------------------------------------
// The two dialects
// ---------------------------------------------------------------------------

/** What `/v1/auth/login` returns. */
interface V1LoginPayload {
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

/**
 * What the legacy login returns, once `ApiClient` has unwrapped the envelope.
 *
 * Flat, snake_case, and with the role membership expressed as four booleans.
 */
interface LegacyLoginPayload {
  readonly id: number;
  readonly tenant_id: number;
  readonly token: string;
  readonly email: string;
  readonly first_name: string;
  readonly last_name: string;
  readonly is_active: boolean;
  readonly is_admin?: boolean;
  readonly is_content?: boolean;
  readonly is_instructor?: boolean;
  readonly is_learner?: boolean;
  readonly tenant?: { readonly id: number; readonly name: string; readonly status: string };
}

/** Everything a signed-in caller is. */
export interface SignedIn {
  readonly session: Session;
  readonly fullName: string;
  readonly roles: readonly string[];
  /** False when the account exists but has been deactivated. */
  readonly isActive: boolean;
  /** False when the organisation itself is inactive. */
  readonly tenantIsActive: boolean;
}

export interface Credentials {
  readonly email: string;
  readonly password: string;
}

/** The key 57 components still read their identity out of. */
const LEGACY_SESSION_KEY = 'SESSION';

/**
 * Signing in, out, and refreshing.
 *
 * Two things are worth knowing about this class.
 *
 * **It still reads both dialects.** The bodies differ, the response shapes
 * differ, and the legacy expresses roles as four booleans where the new API
 * returns a permission list. That branch stays until the legacy backend is
 * switched off — it costs one type guard, and it is what makes the flip
 * revertible if something is found in production.
 *
 * **It writes the legacy session blob as well as the store.** Fifty-seven
 * components still read `sessionStorage.SESSION` for the caller's name and id.
 * They are being migrated to {@link SessionStore}; until the last one moves,
 * removing this would blank out those screens. It is a bridge with an end
 * date, not a design — {@link writeLegacyBlob} is the single place to delete.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly api = inject(ApiClient);
  private readonly router = inject(ApiRouter);
  private readonly store = inject(SessionStore);

  signIn(credentials: Credentials): Observable<SignedIn> {
    return this.api
      .post<V1LoginPayload | LegacyLoginPayload>(
        Endpoints.auth.module,
        Endpoints.auth.login,
        credentials,
      )
      .pipe(
        map((payload) => this.interpret(payload)),
        tap((signedIn) => {
        // Only a usable account is stored. Signing somebody in and then
        // showing them a "your account is inactive" screen leaves a valid
        // session behind that a refresh would walk straight back into.
          if (signedIn.isActive && signedIn.tenantIsActive) {
            this.store.set(signedIn.session);
            this.writeLegacyBlob(signedIn);
          }
        }),
      );
  }

  /**
   * Exchanges the refresh token for a new access token.
   *
   * Legacy has no equivalent — its tokens never rotated — so this is a no-op
   * there rather than an error: the interceptor calls it on a 401, and on the
   * old backend a 401 means the credentials are wrong, not stale.
   */
  refresh(): Observable<boolean> {
    const session = this.store.session();

    if (session?.refreshToken === undefined || !this.router.isMigrated(Endpoints.auth.module)) {
      return of(false);
    }

    return this.api
      .post<{ accessToken: string; refreshToken?: string }>(
        Endpoints.auth.module,
        Endpoints.auth.refresh,
        { refreshToken: session.refreshToken },
      )
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
   * Signs out here and, where the backend supports it, there too.
   *
   * The local half runs regardless of what the server says. A logout that
   * fails silently because the network was down, leaving the person still
   * signed in on a shared machine, is the wrong way for this to break.
   */
  signOut(): Observable<void> {
    const session = this.store.session();

    this.signOutLocally();

    if (session === null || !this.router.isMigrated(Endpoints.auth.module)) {
      return of(undefined);
    }

    return this.api
      .post<unknown>(Endpoints.auth.module, Endpoints.auth.logout, {
        refreshToken: session.refreshToken,
      })
      .pipe(
        map(() => undefined),
        catchError(() => of(undefined)),
      );
  }

  private signOutLocally(): void {
    this.store.clear();
    sessionStorage.removeItem(LEGACY_SESSION_KEY);
    sessionStorage.removeItem('IS_CURRENT');
  }

  // --- reading whichever dialect answered --------------------------------

  private interpret(payload: V1LoginPayload | LegacyLoginPayload): SignedIn {
    return isV1(payload) ? this.fromV1(payload) : this.fromLegacy(payload);
  }

  private fromV1(payload: V1LoginPayload): SignedIn {
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
      // The new API refuses to issue a token for a suspended organisation at
      // all, so reaching here means it is active.
      tenantIsActive: true,
    };
  }

  private fromLegacy(payload: LegacyLoginPayload): SignedIn {
    const roles: string[] = [];

    // Four booleans rather than a role list. Mapped onto the new API's slugs
    // so a guard written against one keeps working against the other.
    if (payload.is_admin === true) {
      roles.push('org_admin');
    }

    if (payload.is_content === true) {
      roles.push('content_creator');
    }

    if (payload.is_instructor === true) {
      roles.push('instructor');
    }

    if (payload.is_learner === true) {
      roles.push('learner');
    }

    return {
      session: {
        token: payload.token,
        userId: String(payload.id),
        tenantId: String(payload.tenant_id),
        email: payload.email,
        // The legacy sends no permission list, so nothing is claimed. Guards
        // fall back to roles; the API is the authority in either case.
        permissions: [],
      },
      fullName: `${payload.first_name} ${payload.last_name}`.trim(),
      roles,
      isActive: payload.is_active === true,
      tenantIsActive: payload.tenant === undefined || payload.tenant.status === 'active',
    };
  }

  /**
   * Leaves the blob the unmigrated components read.
   *
   * Shaped exactly as the legacy login response was, because that is what
   * `atob(sessionStorage.SESSION)` is parsed into in 57 files. Delete this
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

/**
 * Which backend answered.
 *
 * Read from the payload rather than from the router, so a module flipped
 * halfway through a session — or a stale cached response — is interpreted as
 * what it actually is.
 */
function isV1(payload: V1LoginPayload | LegacyLoginPayload): payload is V1LoginPayload {
  return typeof (payload as V1LoginPayload).accessToken === 'string';
}
