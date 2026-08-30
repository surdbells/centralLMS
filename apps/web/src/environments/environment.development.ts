import type { AppEnvironment } from './environment.model';

/**
 * Local development configuration.
 *
 * Note there is no Zoom key here any more. Conferencing credentials are held
 * per tenant by the API and never shipped to the browser — the original file
 * had a live Zoom API key committed in source.
 */
export const environment: AppEnvironment = {
  production: false,

  api: {
    // `pnpm --filter api serve`, or the Docker stack on the same port.
    baseUrl: 'http://127.0.0.1:8081/v1',
  },

  // `pnpm --filter public dev`.
  publicSiteUrl: 'http://localhost:4400',
};
