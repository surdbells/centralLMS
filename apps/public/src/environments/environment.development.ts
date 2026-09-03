import type { PublicEnvironment } from './environment.model';

/** Local development. */
export const environment: PublicEnvironment = {
  production: false,
  // `pnpm --filter web dev`.
  appUrl: 'http://localhost:4300',
  // `pnpm --filter api serve`, or the Docker stack on the same port.
  apiBaseUrl: 'http://127.0.0.1:8081/v1',
};
