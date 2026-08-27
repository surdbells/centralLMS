import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { APP_ENVIRONMENT } from '../api/api-routing';
import type { AppEnvironment } from '../../../environments/environment.model';
import { AuthService } from './auth.service';
import { SessionStore } from './session.store';

const V1_BASE = 'https://api.example.com/v1';

function environment(): AppEnvironment {
  return { production: false, api: { baseUrl: V1_BASE }, publicSiteUrl: 'http://localhost:4400' };
}

const V1_RESPONSE = {
  data: {
    accessToken: 'access-token',
    refreshToken: 'refresh-token',
    tokenType: 'Bearer',
    expiresIn: 900,
    user: {
      id: 'user-uuid',
      tenantId: 'tenant-uuid',
      email: 'ada@example.test',
      firstName: 'Ada',
      lastName: 'Lovelace',
      fullName: 'Ada Lovelace',
      status: 'active',
    },
    roles: [{ id: 'role-uuid', slug: 'org_admin', name: 'Organisation Admin' }],
    permissions: ['course.manage', 'report.view'],
  },
};

describe('AuthService', () => {
  let http: HttpTestingController;
  let store: SessionStore;

  function configure(): AuthService {
    TestBed.resetTestingModule();

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: APP_ENVIRONMENT, useValue: environment() },
      ],
    });

    http = TestBed.inject(HttpTestingController);
    store = TestBed.inject(SessionStore);

    return TestBed.inject(AuthService);
  }

  // The store persists to localStorage and reads it back on construction, so
  // both have to go or a session leaks from one test into the next.
  beforeEach(() => {
    sessionStorage.clear();
    localStorage.clear();
  });

  afterEach(() => {
    http.verify();
    sessionStorage.clear();
    localStorage.clear();
  });

  describe('signing in', () => {
    it('posts the credentials to /auth/login', () => {
      const auth = configure();

      auth.signIn({ email: 'ada@example.test', password: 'secret' }).subscribe();

      const request = http.expectOne(`${V1_BASE}/auth/login`);

      expect(request.request.method).toBe('POST');
      expect(request.request.body).toEqual({ email: 'ada@example.test', password: 'secret' });
      request.flush(V1_RESPONSE);
    });

    it('reads identity and permissions out of the response', () => {
      const auth = configure();
      let signedIn: unknown;

      auth.signIn({ email: 'ada@example.test', password: 'secret' }).subscribe((r) => (signedIn = r));
      http.expectOne(`${V1_BASE}/auth/login`).flush(V1_RESPONSE);

      expect(store.userId()).toBe('user-uuid');
      expect(store.tenantId()).toBe('tenant-uuid');
      expect(store.permissions()).toEqual(['course.manage', 'report.view']);
      expect(store.can()('course.manage')).toBeTrue();
      expect(store.can()('billing.manage')).toBeFalse();
      expect(signedIn).toBeTruthy();
    });

    it('keeps the refresh token', () => {
      const auth = configure();

      auth.signIn({ email: 'ada@example.test', password: 'secret' }).subscribe();
      http.expectOne(`${V1_BASE}/auth/login`).flush(V1_RESPONSE);

      expect(store.session()?.refreshToken).toBe('refresh-token');
    });

    it('leaves the legacy blob behind for the components still reading it', () => {
      // Sixty components still read sessionStorage.SESSION. Until they move,
      // signing in has to write it or they all break. Delete this test with
      // `writeLegacyBlob`.
      const auth = configure();

      auth.signIn({ email: 'ada@example.test', password: 'secret' }).subscribe();
      http.expectOne(`${V1_BASE}/auth/login`).flush(V1_RESPONSE);

      const raw = sessionStorage.getItem('SESSION');

      expect(raw).not.toBeNull();

      const blob = JSON.parse(atob(raw as string)) as Record<string, unknown>;

      expect(blob['id']).toBe('user-uuid');
      expect(blob['tenant_id']).toBe('tenant-uuid');
      expect(blob['token']).toBe('access-token');
      expect(blob['is_admin']).toBeTrue();
      expect(blob['is_active']).toBeTrue();
    });

    it('writes a blob a non-ascii name does not break', () => {
      // `btoa` throws above U+00FF, which would take the whole sign-in down.
      const auth = configure();

      auth.signIn({ email: 'zoe@example.test', password: 'secret' }).subscribe();
      http.expectOne(`${V1_BASE}/auth/login`).flush({
        data: {
          ...V1_RESPONSE.data,
          user: { ...V1_RESPONSE.data.user, fullName: 'Zoë Böhm', firstName: 'Zoë', lastName: 'Böhm' },
        },
      });

      const raw = sessionStorage.getItem('SESSION');
      const bytes = Uint8Array.from(atob(raw as string), (c) => c.charCodeAt(0));
      const blob = JSON.parse(new TextDecoder().decode(bytes)) as Record<string, unknown>;

      expect(blob['first_name']).toBe('Zoë');
      expect(blob['last_name']).toBe('Böhm');
    });

    it('refreshes the access token without disturbing the identity', () => {
      const auth = configure();

      auth.signIn({ email: 'ada@example.test', password: 'secret' }).subscribe();
      http.expectOne(`${V1_BASE}/auth/login`).flush(V1_RESPONSE);

      let refreshed: boolean | undefined;
      auth.refresh().subscribe((r) => (refreshed = r));

      const request = http.expectOne(`${V1_BASE}/auth/refresh`);

      expect(request.request.body).toEqual({ refreshToken: 'refresh-token' });
      request.flush({ data: { accessToken: 'newer-token' } });

      expect(refreshed).toBeTrue();
      expect(store.session()?.token).toBe('newer-token');
      expect(store.userId()).toBe('user-uuid');
    });

    it('signs out locally when the refresh token is rejected', () => {
      // Holding on to a token the server will not honour means retrying it on
      // every subsequent request.
      const auth = configure();

      auth.signIn({ email: 'ada@example.test', password: 'secret' }).subscribe();
      http.expectOne(`${V1_BASE}/auth/login`).flush(V1_RESPONSE);

      let refreshed: boolean | undefined;
      auth.refresh().subscribe((r) => (refreshed = r));

      http
        .expectOne(`${V1_BASE}/auth/refresh`)
        .flush({ error: { code: 'invalid_token', message: 'no' } }, { status: 401, statusText: 'Unauthorized' });

      expect(refreshed).toBeFalse();
      expect(store.isAuthenticated()).toBeFalse();
      expect(sessionStorage.getItem('SESSION')).toBeNull();
    });

    it('tells the server about a sign-out', () => {
      const auth = configure();

      auth.signIn({ email: 'ada@example.test', password: 'secret' }).subscribe();
      http.expectOne(`${V1_BASE}/auth/login`).flush(V1_RESPONSE);

      auth.signOut().subscribe();

      const request = http.expectOne(`${V1_BASE}/auth/logout`);

      expect(request.request.body).toEqual({ refreshToken: 'refresh-token' });
      request.flush({ data: null });
    });

    it('signs out here even when the server cannot be reached', () => {
      // Leaving somebody signed in on a shared machine because the network
      // was down is the wrong way for this to fail.
      const auth = configure();

      auth.signIn({ email: 'ada@example.test', password: 'secret' }).subscribe();
      http.expectOne(`${V1_BASE}/auth/login`).flush(V1_RESPONSE);

      auth.signOut().subscribe();

      expect(store.isAuthenticated()).toBeFalse();
      expect(sessionStorage.getItem('SESSION')).toBeNull();

      http.expectOne(`${V1_BASE}/auth/logout`).error(new ProgressEvent('network'));
    });
  });
});
