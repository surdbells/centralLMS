import type { AppEnvironment } from './environment.model';

/**
 * Production configuration.
 *
 * The original file was `export const environment = {}` — the real values lived
 * only in the development file, so a production build had no API URL at all.
 */
export const environment: AppEnvironment = {
  production: true,

  api: {
    baseUrl: 'https://api-midwesternlearning.midwesternog.com/v1',
  },

  publicSiteUrl: 'https://midwesternlearning.midwesternog.com',
};
