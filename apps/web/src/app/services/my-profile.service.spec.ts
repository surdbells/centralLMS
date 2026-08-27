import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import type { AppEnvironment } from '../../environments/environment.model';
import { APP_ENVIRONMENT } from '../core/api/api-routing';
import { MyProfileService } from './my-profile.service';
import { RolesService } from './roles.service';

const BASE = 'https://api.example.com/v1';

function configure(): HttpTestingController {
  const environment: AppEnvironment = { production: false, api: { baseUrl: BASE }, publicSiteUrl: 'http://localhost:4400' };

  TestBed.configureTestingModule({
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      { provide: APP_ENVIRONMENT, useValue: environment },
    ],
  });

  return TestBed.inject(HttpTestingController);
}

describe('MyProfileService', () => {
  let profile: MyProfileService;
  let http: HttpTestingController;

  beforeEach(() => {
    http = configure();
    profile = TestBed.inject(MyProfileService);
  });

  afterEach(() => http.verify());

  it('never puts a user id in a self-service request', () => {
    // The whole security model of these routes. `update-profile` took a
    // `user_id` from the body and checked nothing about whose it was.
    profile.update({ firstName: 'Ada' }).subscribe();

    const request = http.expectOne(`${BASE}/me/profile`);

    expect(request.request.body).toEqual({ firstName: 'Ada' });
    expect(JSON.stringify(request.request.body)).not.toContain('userId');

    request.flush({ data: { id: 'u-1', firstName: 'Ada' } });
  });

  it('sends every consent, so an unticked one is withdrawn', () => {
    profile.saveConsents({ privacy: true, marketing: false, dataProcessing: false }).subscribe();

    const request = http.expectOne(`${BASE}/me/consents`);

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ privacy: true, marketing: false, dataProcessing: false });

    request.flush({ data: { privacy: true, marketing: false, dataProcessing: false } });
  });

  it('hands back the saved person once an avatar upload finishes, not its progress', () => {
    let received: { avatarUrl: string } | undefined;

    profile
      .uploadAvatar(new File(['x'], 'me.png', { type: 'image/png' }))
      .subscribe((user) => (received = user as unknown as { avatarUrl: string }));

    const request = http.expectOne(`${BASE}/me/avatar`);

    request.event({ type: 1, loaded: 50, total: 100 } as never);
    expect(received).toBeUndefined();

    request.flush({ data: { id: 'u-1', avatarUrl: '/v1/users/u-1/avatar?v=abc' } });

    expect(received?.avatarUrl).toBe('/v1/users/u-1/avatar?v=abc');
  });

  it('ends one session by id', () => {
    profile.revokeSession('s-1').subscribe();

    const request = http.expectOne(`${BASE}/me/sessions/s-1`);

    expect(request.request.method).toBe('DELETE');
    request.flush(null, { status: 204, statusText: 'No Content' });
  });
});

describe('RolesService', () => {
  let roles: RolesService;
  let http: HttpTestingController;

  beforeEach(() => {
    http = configure();
    roles = TestBed.inject(RolesService);
  });

  afterEach(() => http.verify());

  it('replaces a role’s permissions rather than adding to them', () => {
    roles.update('r-1', { permissions: ['course.view'] }).subscribe();

    const request = http.expectOne(`${BASE}/roles/r-1`);

    expect(request.request.method).toBe('PUT');
    expect(request.request.body).toEqual({ permissions: ['course.view'] });

    request.flush({ data: { id: 'r-1', permissions: ['course.view'] } });
  });

  it('creates a role with its permissions in one request', () => {
    // Two requests was how a role could exist and promise nothing: the legacy
    // created it, then set permissions separately, and the second could fail.
    roles.create({ name: 'Author', permissions: ['course.manage'] }).subscribe();

    const request = http.expectOne(`${BASE}/roles`);

    expect(request.request.method).toBe('POST');
    expect(request.request.body).toEqual({ name: 'Author', permissions: ['course.manage'] });

    request.flush({ data: { id: 'r-2' } });
  });

  it('has no way to assign a role to somebody', () => {
    // Deliberate: a person’s roles are set as a set through UsersService.
    expect((roles as unknown as Record<string, unknown>)['assignRole']).toBeUndefined();
    expect((roles as unknown as Record<string, unknown>)['removeRole']).toBeUndefined();
  });
});
