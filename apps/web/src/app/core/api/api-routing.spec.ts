import { TestBed } from '@angular/core/testing';
import type { AppEnvironment } from '../../../environments/environment.model';
import { ApiBackend } from './api-backend';
import { ApiModule } from './api-module';
import { APP_ENVIRONMENT, ApiRouter } from './api-routing';

describe('ApiRouter (strangler switchboard)', () => {
  function routerWith(overrides: Partial<AppEnvironment['moduleBackends']> = {}): ApiRouter {
    const environment: AppEnvironment = {
      production: false,
      api: {
        legacyBaseUrl: 'https://legacy.example.com',
        v1BaseUrl: 'https://api.example.com/v1',
      },
      moduleBackends: overrides,
    };

    TestBed.resetTestingModule();
    TestBed.configureTestingModule({
      providers: [{ provide: APP_ENVIRONMENT, useValue: environment }],
    });

    return TestBed.inject(ApiRouter);
  }

  it('sends unmigrated modules to the legacy backend', () => {
    const router = routerWith();

    expect(router.backendFor(ApiModule.Courses)).toBe(ApiBackend.Legacy);
    expect(router.url(ApiModule.Courses, '/user/courses/list')).toBe(
      'https://legacy.example.com/user/courses/list',
    );
  });

  it('defaults to legacy so an unlisted module cannot hit an unbuilt endpoint', () => {
    const router = routerWith({ [ApiModule.Dictionary]: ApiBackend.V1 });

    // Only Dictionary was migrated; every other module must be untouched.
    for (const module of Object.values(ApiModule)) {
      if (module !== ApiModule.Dictionary) {
        expect(router.backendFor(module)).toBe(ApiBackend.Legacy);
      }
    }
  });

  it('moves a single module without affecting any other', () => {
    const router = routerWith({ [ApiModule.Dictionary]: ApiBackend.V1 });

    expect(router.url(ApiModule.Dictionary, '/dictionary/branches/list')).toBe(
      'https://api.example.com/v1/dictionary/branches/list',
    );
    // Courses was not migrated and must still resolve to legacy.
    expect(router.url(ApiModule.Courses, '/user/courses/list')).toBe(
      'https://legacy.example.com/user/courses/list',
    );
  });

  it('reports migration state per module', () => {
    const router = routerWith({ [ApiModule.Ilt]: ApiBackend.V1 });

    expect(router.isMigrated(ApiModule.Ilt)).toBeTrue();
    expect(router.isMigrated(ApiModule.Reports)).toBeFalse();
  });

  describe('URL joining', () => {
    it('normalises a missing leading slash', () => {
      // GlobalComponent shipped 'utility/shared/user' without one, producing
      // https://api.centrallms.comutility/shared/user
      const router = routerWith();

      expect(router.url(ApiModule.Users, 'user/single')).toBe(
        'https://legacy.example.com/user/single',
      );
    });

    it('collapses doubled slashes', () => {
      // GlobalComponent had 'utility/shared//mark_notifications'.
      const router = routerWith();

      expect(router.url(ApiModule.Users, '/utility/shared//mark_notifications')).toBe(
        'https://legacy.example.com/utility/shared/mark_notifications',
      );
    });

    it('tolerates a trailing slash on the base URL', () => {
      TestBed.resetTestingModule();
      TestBed.configureTestingModule({
        providers: [
          {
            provide: APP_ENVIRONMENT,
            useValue: {
              production: false,
              api: { legacyBaseUrl: 'https://legacy.example.com/', v1BaseUrl: 'https://api/v1/' },
              moduleBackends: {},
            } satisfies AppEnvironment,
          },
        ],
      });

      expect(TestBed.inject(ApiRouter).url(ApiModule.Users, '/user/single')).toBe(
        'https://legacy.example.com/user/single',
      );
    });

    it('returns the bare base URL for an empty path', () => {
      expect(routerWith().url(ApiModule.Users, '')).toBe('https://legacy.example.com');
    });
  });

  it('snapshots every module for diagnostics', () => {
    const router = routerWith({ [ApiModule.Platform]: ApiBackend.V1 });
    const snapshot = router.snapshot();

    expect(snapshot.length).toBe(Object.values(ApiModule).length);
    expect(snapshot.find((s) => s.module === ApiModule.Platform)?.backend).toBe(ApiBackend.V1);
  });
});
