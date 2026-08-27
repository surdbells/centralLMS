import type { PublicEnvironment } from './environment.model';

/** Local development. */
export const environment: PublicEnvironment = {
  production: false,
  // `pnpm --filter web dev`.
  appUrl: 'http://localhost:4300',
};
