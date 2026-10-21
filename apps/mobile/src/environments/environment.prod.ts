/** Production. The app that ships in the store talks to the production API. */
export const environment = {
  production: true,
  apiBaseUrl: 'https://api.centrallms.com/v1',
  /**
   * A fixed institution code for a single-tenant build — set, the app resolves
   * that organisation at launch and never shows the institution-code screen.
   * Empty on a generic/multi-tenant build. Baked per client by
   * scripts/set-env.mjs from the INSTITUTION_CODE build variable.
   */
  institutionCode: '',
};
