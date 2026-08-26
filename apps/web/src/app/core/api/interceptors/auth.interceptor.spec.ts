import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { SessionStore } from '../../auth/session.store';
import { authInterceptor } from './auth.interceptor';

/**
 * Credentials are attached in exactly one place.
 *
 * They used to be pasted into the body of every call, by hand, in every
 * service — `{ token, id, tenant_id, ...payload }` — which is how the tenant
 * came to be something a caller could choose.
 */
describe('authInterceptor', () => {
  let http: HttpClient;
  let controller: HttpTestingController;
  let store: SessionStore;

  beforeEach(() => {
    // Both are cleared: SessionStore persists to localStorage, and a leftover
    // session from another spec would make these pass for the wrong reason.
    localStorage.clear();
    sessionStorage.clear();

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
      ],
    });

    http = TestBed.inject(HttpClient);
    controller = TestBed.inject(HttpTestingController);
    store = TestBed.inject(SessionStore);
  });

  afterEach(() => {
    controller.verify();
    localStorage.clear();
    sessionStorage.clear();
  });

  it('sends the bearer token', () => {
    store.set({
      token: 'jwt-token',
      refreshToken: 'refresh-token',
      userId: 'user-1',
      tenantId: 'tenant-1',
      email: 'ada@example.test',
      permissions: [],
    });

    http.get('/courses').subscribe();

    const request = controller.expectOne('/courses');

    expect(request.request.headers.get('Authorization')).toBe('Bearer jwt-token');

    request.flush({ data: [] });
  });

  it('sends nothing when nobody is signed in', () => {
    // The sign-in call itself goes through this interceptor.
    http.post('/auth/login', { email: 'ada@example.test', password: 'x' }).subscribe();

    const request = controller.expectOne('/auth/login');

    expect(request.request.headers.has('Authorization')).toBeFalse();

    request.flush({ data: {} });
  });

  it('leaves the body alone', () => {
    // It used to merge three credential fields into it, which meant a caller
    // could not send a payload with an `id` of its own.
    store.set({
      token: 'jwt-token',
      userId: 'user-1',
      tenantId: 'tenant-1',
      email: 'ada@example.test',
      permissions: [],
    });

    http.post('/courses', { id: 'something-else', title: 'Fire Safety' }).subscribe();

    const request = controller.expectOne('/courses');

    expect(request.request.body).toEqual({ id: 'something-else', title: 'Fire Safety' });

    request.flush({ data: {} });
  });

  it('does not touch a FormData upload', () => {
    store.set({
      token: 'jwt-token',
      userId: 'user-1',
      tenantId: 'tenant-1',
      email: 'ada@example.test',
      permissions: [],
    });

    const form = new FormData();
    form.append('file', new Blob(['x']), 'induction.mp4');

    http.post('/media', form).subscribe();

    const request = controller.expectOne('/media');
    const body = request.request.body as FormData;

    expect(body.has('file')).toBeTrue();
    expect(body.has('token')).toBeFalse();
    expect(request.request.headers.get('Authorization')).toBe('Bearer jwt-token');

    request.flush({ data: {} });
  });
});
