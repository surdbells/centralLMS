import type { AppEnvironment } from './environment.model';

/**
 * Production configuration.
 *
 * The previous file was `export const environment = {}` — the real values lived
 * only in the development file, so a production build had no API URL at all.
 */
export const environment: AppEnvironment = {
  production: true,

  api: {
    /** The legacy PHP backend. Shrinks to nothing as modules are migrated. */
    legacyBaseUrl: 'https://api.centrallms.com',
    /** The Slim/PostgreSQL API. */
    v1BaseUrl: 'https://api.centrallms.com/v1',
  },

  /**
   * Which backend serves each module. This is the strangler switchboard —
   * moving a module is a one-line change here, deployable and revertible on
   * its own. See core/api/api-routing.ts.
   */
  moduleBackends: {},
};
