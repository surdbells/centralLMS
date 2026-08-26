import { ApiBackend } from '../app/core/api/api-backend';
import { ApiModule } from '../app/core/api/api-module';
import type { AppEnvironment } from './environment.model';

/**
 * Local development configuration.
 *
 * Note there is no Zoom key here any more. Conferencing credentials are held
 * per tenant by the API and never shipped to the browser — the previous file
 * had a live Zoom API key committed in source.
 */
export const environment: AppEnvironment = {
  production: false,

  api: {
    legacyBaseUrl: 'https://api.centrallms.com',
    v1BaseUrl: 'http://127.0.0.1:8099/v1',
  },

  /**
   * Point a module at the new API here to test a migration locally, e.g.
   *
   *   [ApiModule.Dictionary]: ApiBackend.V1,
   *
   * Everything not listed stays on the legacy backend.
   */
  moduleBackends: {
    [ApiModule.Platform]: ApiBackend.V1,

    // Migrated. Sign-in, refresh and sign-out all come from the new API; the
    // legacy session blob is still written for the components that have not
    // moved yet. See core/auth/auth.service.ts.
    [ApiModule.Auth]: ApiBackend.V1,
  },
};
