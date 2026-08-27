import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import type { AppEnvironment } from '../../../environments/environment.model';
import { APP_ENVIRONMENT } from './api-routing';
import { ApiClient } from './api.client';
import { ApiError } from './api-response';

/**
 * The client's job is to hand callers a payload or an {@link ApiError}, and
 * never anything in between. These pin that down.
 */
describe('ApiClient', () => {
  let client: ApiClient;
  let http: HttpTestingController;

  const BASE = 'https://api.example.com/v1';

  beforeEach(() => {
    const environment: AppEnvironment = {
      production: false,
      api: { baseUrl: BASE },
      publicSiteUrl: 'http://localhost:4400',
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

  describe('addressing', () => {
    it('joins the base URL and the path', () => {
      client.get('/courses').subscribe();

      const request = http.expectOne(`${BASE}/courses`);

      expect(request.request.url).toBe(`${BASE}/courses`);
      request.flush({ data: [] });
    });

    it('does not double a slash, or drop one', () => {
      // `GlobalComponent` produced both `...comutility/shared/user` and
      // `.../shared//mark_notifications`, and nothing noticed.
      client.get('courses').subscribe();
      const missingSlash = http.expectOne(`${BASE}/courses`);
      expect(missingSlash.request.url).toBe(`${BASE}/courses`);
      missingSlash.flush({ data: [] });

      client.get('//courses//1').subscribe();
      const doubledSlash = http.expectOne(`${BASE}/courses/1`);
      expect(doubledSlash.request.url).toBe(`${BASE}/courses/1`);
      doubledSlash.flush({ data: {} });
    });

    it('drops empty query parameters rather than sending "undefined"', () => {
      client.get('/courses', { page: 1, search: undefined, kind: '' }).subscribe();

      const request = http.expectOne((r) => r.url === `${BASE}/courses`);

      expect(request.request.params.get('page')).toBe('1');
      expect(request.request.params.has('search')).toBeFalse();
      expect(request.request.params.has('kind')).toBeFalse();

      request.flush({ data: [] });
    });
  });

  describe('unwrapping', () => {
    it('returns the payload rather than the envelope', () => {
      let received: unknown;

      client.get('/courses/1').subscribe((payload) => (received = payload));

      http.expectOne(`${BASE}/courses/1`).flush({ data: { id: '1', title: 'Fire Safety' } });

      expect(received).toEqual({ id: '1', title: 'Fire Safety' });
    });

    it('keeps pagination metadata beside the items', () => {
      let received: { items: unknown[]; meta: { total: number } } | undefined;

      client
        .getPaged<{ id: string }>('/courses')
        .subscribe((paged) => (received = paged));

      http.expectOne((r) => r.url === `${BASE}/courses`).flush({
        data: [{ id: '1' }],
        meta: { total: 12, page: 2, perPage: 1, totalPages: 12 },
      });

      expect(received?.items.length).toBe(1);
      expect(received?.meta.total).toBe(12);
    });

    it('synthesises a single page when the endpoint returns a bare list', () => {
      // A category list has no pages, and a caller should not have to know
      // which sort of endpoint it asked.
      let received: { meta: { total: number; totalPages: number } } | undefined;

      client.getPaged<{ id: string }>('/skills/categories').subscribe((p) => (received = p));

      http
        .expectOne((r) => r.url === `${BASE}/skills/categories`)
        .flush({ data: [{ id: '1' }, { id: '2' }] });

      expect(received?.meta.total).toBe(2);
      expect(received?.meta.totalPages).toBe(1);
    });
  });

  describe('failures', () => {
    it('turns an error envelope into an ApiError with its code', () => {
      let error: ApiError | undefined;

      client.post('/courses', {}).subscribe({ error: (e: ApiError) => (error = e) });

      http.expectOne(`${BASE}/courses`).flush(
        {
          error: {
            code: 'validation_failed',
            message: 'The submitted data is invalid.',
            details: { violations: { title: ['Give the course a title.'] } },
          },
        },
        { status: 422, statusText: 'Unprocessable Entity' },
      );

      expect(error).toBeInstanceOf(ApiError);
      expect(error?.code).toBe('validation_failed');
      expect(error?.isValidation).toBeTrue();
      expect(error?.violation('title')).toBe('Give the course a title.');
    });

    it('exposes violations as an empty object for anything that is not a 422', () => {
      // So a form can bind to it without checking first.
      let error: ApiError | undefined;

      client.get('/courses/1').subscribe({ error: (e: ApiError) => (error = e) });

      http
        .expectOne(`${BASE}/courses/1`)
        .flush({ error: { code: 'not_found', message: 'No such course.' } }, { status: 404, statusText: 'Not Found' });

      expect(error?.violations).toEqual({});
      expect(error?.isNotFound).toBeTrue();
    });

    it('reports an unreachable server as something a person can act on', () => {
      let error: ApiError | undefined;

      client.get('/courses').subscribe({ error: (e: ApiError) => (error = e) });

      http
        .expectOne((r) => r.url === `${BASE}/courses`)
        .error(new ProgressEvent('error'), { status: 0, statusText: 'Unknown Error' });

      expect(error?.code).toBe('network_error');
      expect(error?.message).toContain('Could not reach the server');
      expect(error?.isRetryable).toBeTrue();
    });

    it('keeps the request id, so a console error traces to a server log', () => {
      let error: ApiError | undefined;

      client.get('/courses').subscribe({ error: (e: ApiError) => (error = e) });

      http.expectOne((r) => r.url === `${BASE}/courses`).flush(
        { error: { code: 'internal_error', message: 'Something failed.' } },
        { status: 500, statusText: 'Server Error', headers: { 'X-Request-Id': 'abc-123' } },
      );

      expect(error?.requestId).toBe('abc-123');
    });

    it('maps a status with no envelope onto the same vocabulary', () => {
      let error: ApiError | undefined;

      client.get('/nope').subscribe({ error: (e: ApiError) => (error = e) });

      http.expectOne(`${BASE}/nope`).flush('Not found', { status: 404, statusText: 'Not Found' });

      expect(error?.code).toBe('not_found');
    });
  });

  describe('uploads', () => {
    it('reports progress and then the created item', () => {
      const events: string[] = [];
      let result: { id: string } | undefined;

      const form = new FormData();
      form.append('file', new Blob(['x']), 'induction.mp4');

      client.upload<{ id: string }>('/media', form).subscribe((event) => {
        events.push(event.state);

        if (event.state === 'done') {
          result = event.result;
        }
      });

      const request = http.expectOne(`${BASE}/media`);

      expect(request.request.reportProgress).toBeTrue();

      request.event({ type: 1, loaded: 50, total: 100 } as never);
      request.flush({ data: { id: 'media-1' } });

      expect(events).toContain('uploading');
      expect(result).toEqual({ id: 'media-1' });
    });
  });

  it('builds a URL for bytes a browser fetches directly', () => {
    expect(client.urlFor('/media/abc/file')).toBe(`${BASE}/media/abc/file`);
  });
});
