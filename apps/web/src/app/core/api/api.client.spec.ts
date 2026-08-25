import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import type { AppEnvironment } from '../../../environments/environment.model';
import { ApiBackend } from './api-backend';
import { ApiModule } from './api-module';
import { APP_ENVIRONMENT } from './api-routing';
import { ApiClient } from './api.client';
import { ApiError } from './api-response';

/**
 * The client's job is to make both backends look identical to callers. These
 * tests pin that down from both sides.
 */
describe('ApiClient', () => {
  let client: ApiClient;
  let http: HttpTestingController;

  const LEGACY = 'https://legacy.example.com';
  const V1 = 'https://api.example.com/v1';

  beforeEach(() => {
    const environment: AppEnvironment = {
      production: false,
      api: { legacyBaseUrl: LEGACY, v1BaseUrl: V1 },
      // Assessments is migrated; Courses is not. One of each.
      moduleBackends: { [ApiModule.Assessments]: ApiBackend.V1 },
    };

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: APP_ENVIRONMENT, useValue: environment },
      ],
    });

    client = TestBed.inject(ApiClient);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  describe('legacy backend', () => {
    it('unwraps the { response_code, status, data } envelope', () => {
      let result: unknown;
      client.get(ApiModule.Courses, '/user/courses/list').subscribe((r) => (result = r));

      http.expectOne(`${LEGACY}/user/courses/list`).flush({
        response_code: 200,
        status: 'success',
        message: '',
        data: [{ id: 1, title: 'Onboarding' }],
      });

      expect(result).toEqual([{ id: 1, title: 'Onboarding' }]);
    });

    it('treats an error reported inside a 200 body as a failure', () => {
      // send_response() never calls http_response_code(), so EVERY legacy
      // response is HTTP 200 with the real status in the body. Without this,
      // a 401 would reach callers looking like a successful empty response.
      let error: ApiError | undefined;
      client
        .get(ApiModule.Courses, '/user/courses/list')
        .subscribe({ error: (e: ApiError) => (error = e) });

      http.expectOne(`${LEGACY}/user/courses/list`).flush(
        { response_code: 401, status: 'failed', message: 'Unauthorized request', data: [] },
        { status: 200, statusText: 'OK' },
      );

      expect(error).toBeInstanceOf(ApiError);
      expect(error!.code).toBe('unauthenticated');
      expect(error!.status).toBe(401);
      expect(error!.isUnauthenticated).toBeTrue();
    });

    it('maps legacy status codes onto the shared error vocabulary', () => {
      const cases: Array<[number, string]> = [
        [400, 'bad_request'],
        [403, 'forbidden'],
        [404, 'not_found'],
        [409, 'conflict'],
        [422, 'validation_failed'],
        [500, 'internal_error'],
      ];

      for (const [code, expected] of cases) {
        let error: ApiError | undefined;
        client.get(ApiModule.Courses, '/x').subscribe({ error: (e: ApiError) => (error = e) });
        http.expectOne(`${LEGACY}/x`).flush({ response_code: code, message: 'nope' });

        expect(error!.code)
          .withContext(`legacy ${code}`)
          .toBe(expected);
      }
    });

    it('passes through a response that has no envelope at all', () => {
      let result: unknown;
      client.get(ApiModule.Courses, '/raw').subscribe((r) => (result = r));

      http.expectOne(`${LEGACY}/raw`).flush({ id: 7, name: 'unwrapped' });

      expect(result).toEqual({ id: 7, name: 'unwrapped' });
    });
  });

  describe('new API', () => {
    it('unwraps the { data } envelope', () => {
      let result: unknown;
      client.get(ApiModule.Assessments, '/assessments').subscribe((r) => (result = r));

      http.expectOne(`${V1}/assessments`).flush({ data: [{ id: 'a1' }] });

      expect(result).toEqual([{ id: 'a1' }]);
    });

    it('turns an { error } envelope into an ApiError with its details', () => {
      let error: ApiError | undefined;
      client
        .post(ApiModule.Assessments, '/assessments', { title: '' })
        .subscribe({ error: (e: ApiError) => (error = e) });

      http.expectOne(`${V1}/assessments`).flush(
        {
          error: {
            code: 'validation_failed',
            message: 'The submitted data is invalid.',
            details: { violations: { title: ['This value should not be blank.'] } },
          },
        },
        { status: 422, statusText: 'Unprocessable Entity' },
      );

      expect(error!.code).toBe('validation_failed');
      expect(error!.isValidation).toBeTrue();
      expect(error!.violations['title']).toEqual(['This value should not be blank.']);
    });

    it('exposes an empty violations map for non-validation errors', () => {
      // So a form can bind to it without a null check.
      let error: ApiError | undefined;
      client.get(ApiModule.Assessments, '/x').subscribe({ error: (e: ApiError) => (error = e) });

      http
        .expectOne(`${V1}/x`)
        .flush({ error: { code: 'forbidden', message: 'No.' } }, { status: 403, statusText: 'Forbidden' });

      expect(error!.violations).toEqual({});
      expect(error!.isForbidden).toBeTrue();
    });

    it('captures the request id for support tracing', () => {
      let error: ApiError | undefined;
      client.get(ApiModule.Assessments, '/x').subscribe({ error: (e: ApiError) => (error = e) });

      http.expectOne(`${V1}/x`).flush(
        { error: { code: 'internal_error', message: 'Boom.' } },
        { status: 500, statusText: 'Server Error', headers: { 'X-Request-Id': 'req-123' } },
      );

      expect(error!.requestId).toBe('req-123');
      expect(error!.isRetryable).toBeTrue();
    });
  });

  describe('shared behaviour', () => {
    it('reports a transport failure as a network error', () => {
      let error: ApiError | undefined;
      client.get(ApiModule.Courses, '/x').subscribe({ error: (e: ApiError) => (error = e) });

      http.expectOne(`${LEGACY}/x`).error(new ProgressEvent('error'), { status: 0 });

      expect(error!.code).toBe('network_error');
      expect(error!.isRetryable).toBeTrue();
    });

    it('drops empty query parameters instead of sending "undefined"', () => {
      client
        .get(ApiModule.Courses, '/search', { q: 'angular', page: 2, tag: undefined, sort: '' })
        .subscribe();

      const request = http.expectOne((r) => r.url === `${LEGACY}/search`);

      expect(request.request.params.get('q')).toBe('angular');
      expect(request.request.params.get('page')).toBe('2');
      expect(request.request.params.has('tag')).toBeFalse();
      expect(request.request.params.has('sort')).toBeFalse();
      request.flush({ data: [] });
    });

    it('preserves pagination metadata from the new API', () => {
      let paged: { items: unknown[]; meta: { total: number; totalPages: number } } | undefined;
      client
        .getPaged(ApiModule.Assessments, '/assessments')
        .subscribe((r) => (paged = r as typeof paged));

      http.expectOne(`${V1}/assessments`).flush({
        data: [{ id: 1 }, { id: 2 }],
        meta: { total: 40, page: 1, perPage: 2, totalPages: 20 },
      });

      expect(paged!.items.length).toBe(2);
      expect(paged!.meta.total).toBe(40);
      expect(paged!.meta.totalPages).toBe(20);
    });

    it('synthesises single-page metadata for legacy lists', () => {
      // Legacy sends no meta, so pagination controls would otherwise break the
      // moment a module is migrated back and forth.
      let paged: { items: unknown[]; meta: { total: number; totalPages: number } } | undefined;
      client
        .getPaged(ApiModule.Courses, '/user/courses/list')
        .subscribe((r) => (paged = r as typeof paged));

      http
        .expectOne(`${LEGACY}/user/courses/list`)
        .flush({ response_code: 200, data: [{ id: 1 }, { id: 2 }, { id: 3 }] });

      expect(paged!.items.length).toBe(3);
      expect(paged!.meta.total).toBe(3);
      expect(paged!.meta.totalPages).toBe(1);
    });

    it('returns the same shape from both backends for the same logical call', () => {
      // The migration guarantee, stated as a test.
      let fromLegacy: unknown;
      let fromV1: unknown;

      client.get(ApiModule.Courses, '/list').subscribe((r) => (fromLegacy = r));
      http.expectOne(`${LEGACY}/list`).flush({ response_code: 200, data: [{ id: 1 }] });

      client.get(ApiModule.Assessments, '/list').subscribe((r) => (fromV1 = r));
      http.expectOne(`${V1}/list`).flush({ data: [{ id: 1 }] });

      expect(fromLegacy).toEqual(fromV1);
    });
  });
});
