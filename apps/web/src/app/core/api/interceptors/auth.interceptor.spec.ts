import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import type { AppEnvironment } from '../../../../environments/environment.model';
import { SessionStore } from '../../auth/session.store';
import { ApiBackend } from '../api-backend';
import { ApiModule } from '../api-module';
import { APP_ENVIRONMENT } from '../api-routing';
import { ApiClient } from '../api.client';
import { authInterceptor } from './auth.interceptor';

/**
 * The two backends authenticate differently. This is what makes that
 * invisible to callers — and what must keep working through a migration.
 */
describe('authInterceptor', () => {
  let client: ApiClient;
  let http: HttpTestingController;
  let session: SessionStore;

  const LEGACY = 'https://legacy.example.com';
  const V1 = 'https://api.example.com/v1';

  beforeEach(() => {
    const environment: AppEnvironment = {
      production: false,
      api: { legacyBaseUrl: LEGACY, v1BaseUrl: V1 },
      moduleBackends: { [ApiModule.Assessments]: ApiBackend.V1 },
    };

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(withInterceptors([authInterceptor])),
        provideHttpClientTesting(),
        { provide: APP_ENVIRONMENT, useValue: environment },
      ],
    });

    client = TestBed.inject(ApiClient);
    http = TestBed.inject(HttpTestingController);
    session = TestBed.inject(SessionStore);

    session.set({
      token: 'the-token',
      userId: 'user-42',
      tenantId: 'tenant-9',
      email: 'a@example.com',
    });
  });

  afterEach(() => {
    http.verify();
    session.clear();
  });

  it('sends a bearer header to the new API', () => {
    client.get(ApiModule.Assessments, '/assessments').subscribe();

    const request = http.expectOne(`${V1}/assessments`);

    expect(request.request.headers.get('Authorization')).toBe('Bearer the-token');
    // Credentials must never leak into the body for the new API.
    expect(request.request.body).toBeNull();
    request.flush({ data: [] });
  });

  it('merges credentials into the body for a legacy POST', () => {
    client.post(ApiModule.Courses, '/user/courses/create', { title: 'New course' }).subscribe();

    const request = http.expectOne(`${LEGACY}/user/courses/create`);
    const body = request.request.body as Record<string, unknown>;

    expect(body['token']).toBe('the-token');
    expect(body['id']).toBe('user-42');
    expect(body['tenant_id']).toBe('tenant-9');
    // The caller's own payload survives.
    expect(body['title']).toBe('New course');
    // ...and no bearer header is sent, since legacy ignores it.
    expect(request.request.headers.has('Authorization')).toBeFalse();
    request.flush({ response_code: 200, data: {} });
  });

  it('does not let credentials overwrite a caller field of the same name', () => {
    // Caller data is spread last on purpose.
    client.post(ApiModule.Courses, '/x', { id: 'course-1' }).subscribe();

    const request = http.expectOne(`${LEGACY}/x`);

    expect((request.request.body as Record<string, unknown>)['id']).toBe('course-1');
    request.flush({ response_code: 200 });
  });

  it('uses query parameters for legacy GET, which has no body', () => {
    client.get(ApiModule.Courses, '/user/courses/list').subscribe();

    const request = http.expectOne((r) => r.url === `${LEGACY}/user/courses/list`);

    expect(request.request.params.get('token')).toBe('the-token');
    expect(request.request.params.get('id')).toBe('user-42');
    expect(request.request.params.get('tenant_id')).toBe('tenant-9');
    request.flush({ response_code: 200, data: [] });
  });

  it('appends to FormData rather than replacing it, so uploads survive', () => {
    const form = new FormData();
    form.append('file', new Blob(['x']), 'notes.pdf');

    client.post(ApiModule.Media, '/user/media/upload', form).subscribe();

    const request = http.expectOne(`${LEGACY}/user/media/upload`);
    const body = request.request.body as FormData;

    expect(body.get('file')).toBeTruthy();
    expect(body.get('token')).toBe('the-token');
    request.flush({ response_code: 200 });
  });

  it('sends nothing extra when there is no session', () => {
    session.clear();

    client.get(ApiModule.Courses, '/public').subscribe();

    const request = http.expectOne((r) => r.url === `${LEGACY}/public`);

    expect(request.request.headers.has('Authorization')).toBeFalse();
    expect(request.request.params.has('token')).toBeFalse();
    request.flush({ response_code: 200, data: [] });
  });

  it('switches credential scheme automatically when a module migrates', () => {
    // Same logical call, different backend — the caller code is identical.
    client.post(ApiModule.Courses, '/thing', { a: 1 }).subscribe();
    const legacyRequest = http.expectOne(`${LEGACY}/thing`);
    expect((legacyRequest.request.body as Record<string, unknown>)['token']).toBe('the-token');
    legacyRequest.flush({ response_code: 200 });

    client.post(ApiModule.Assessments, '/thing', { a: 1 }).subscribe();
    const v1Request = http.expectOne(`${V1}/thing`);
    expect(v1Request.request.headers.get('Authorization')).toBe('Bearer the-token');
    expect((v1Request.request.body as Record<string, unknown>)['token']).toBeUndefined();
    v1Request.flush({ data: {} });
  });
});
