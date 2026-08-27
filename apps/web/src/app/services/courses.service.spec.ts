import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import type { AppEnvironment } from '../../environments/environment.model';
import { APP_ENVIRONMENT } from '../core/api/api-routing';
import { CoursesService } from './courses.service';

describe('CoursesService', () => {
  let courses: CoursesService;
  let http: HttpTestingController;

  const BASE = 'https://api.example.com/v1';

  beforeEach(() => {
    const environment: AppEnvironment = { production: false, api: { baseUrl: BASE }, publicSiteUrl: 'http://localhost:4400' };

    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: APP_ENVIRONMENT, useValue: environment },
      ],
    });

    courses = TestBed.inject(CoursesService);
    http = TestBed.inject(HttpTestingController);
  });

  afterEach(() => http.verify());

  it('does not send a status when saving', () => {
    // Publishing is a separate call with a separate permission and its own
    // refusal. A status field on the save would look like it worked.
    courses.create({ title: 'Fire Safety' }).subscribe();

    const request = http.expectOne(`${BASE}/courses`);

    expect(request.request.body).toEqual({ title: 'Fire Safety' });
    request.flush({ data: { id: 'c-1', status: 'draft' } });
  });

  it('publishes through its own route', () => {
    courses.setStatus('c-1', 'published').subscribe();

    const request = http.expectOne(`${BASE}/courses/c-1/status`);

    expect(request.request.method).toBe('PATCH');
    expect(request.request.body).toEqual({ status: 'published' });

    request.flush({ data: { id: 'c-1', status: 'published' } });
  });

  it('reports what a duplicate brought across', () => {
    let received: { copiedModules: number; copiedLessons: number } | undefined;

    courses.duplicate('c-1').subscribe((copy) => (received = copy));

    http.expectOne(`${BASE}/courses/c-1/duplicate`).flush({
      data: { id: 'c-2', title: 'Fire Safety (copy)', copiedModules: 3, copiedLessons: 11 },
    });

    expect(received?.copiedModules).toBe(3);
    expect(received?.copiedLessons).toBe(11);
  });

  it('fetches the whole outline in one request', () => {
    // The builder used to fetch the course, then its modules, then the lessons
    // of each module — sixteen requests for a fifteen-module course.
    courses.outline('c-1').subscribe();

    const request = http.expectOne(`${BASE}/courses/c-1/outline`);

    expect(request.request.method).toBe('GET');
    request.flush({ data: { course: { id: 'c-1' }, modules: [] } });
  });

  it('reorders as one list, not a position per row', () => {
    // A position-per-row update that failed halfway left two modules claiming
    // the same place.
    courses.reorderModules('c-1', ['m-2', 'm-1', 'm-3']).subscribe();

    const request = http.expectOne(`${BASE}/courses/c-1/modules/order`);

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ moduleIds: ['m-2', 'm-1', 'm-3'] });

    request.flush({ data: [] });
  });

  it('surfaces the server’s refusal when a course cannot be deleted', () => {
    // "Nineteen people are enrolled — archive it instead" is worth reading.
    let message: string | undefined;

    courses.delete('c-1').subscribe({ error: (e: { message: string }) => (message = e.message) });

    http.expectOne(`${BASE}/courses/c-1`).flush(
      {
        error: {
          code: 'course_in_use',
          message: '19 people are enrolled on "Fire Safety". Archive it instead.',
        },
      },
      { status: 409, statusText: 'Conflict' },
    );

    expect(message).toContain('Archive it instead');
  });
});
