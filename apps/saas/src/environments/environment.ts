import type { SaasEnvironment } from './environment.model';

/** Production. */
export const environment: SaasEnvironment = {
  production: true,

  api: {
    baseUrl: 'https://api.centrallms.com/v1',
  },

  appUrl: 'https://app.centrallms.com',
};
