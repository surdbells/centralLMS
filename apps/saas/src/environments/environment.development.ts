import type { SaasEnvironment } from './environment.model';

/** Local development. */
export const environment: SaasEnvironment = {
  production: false,

  api: {
    // `pnpm --filter api serve`, or the Docker stack on the same port.
    baseUrl: 'http://127.0.0.1:8081/v1',
  },

  // `pnpm --filter web dev`.
  appUrl: 'http://localhost:4300',
};
