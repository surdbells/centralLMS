/**
 * Development. The app talks to the local API, same as @central/web's dev.
 * For a real device/emulator, point this at a host the device can reach.
 */
export const environment = {
  production: false,
  apiBaseUrl: 'http://127.0.0.1:8081/v1',
  /**
   * A fixed institution code for a single-tenant build. When set, the app
   * resolves that organisation at launch and never shows the institution-code
   * screen. Empty on a generic/multi-tenant build. Baked per client by
   * scripts/set-env.mjs from the INSTITUTION_CODE build variable.
   */
  institutionCode: '',
};
