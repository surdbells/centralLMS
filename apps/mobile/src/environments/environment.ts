/**
 * Development. The app talks to the local API, same as @central/web's dev.
 * For a real device/emulator, point this at a host the device can reach.
 */
export const environment = {
  production: false,
  apiBaseUrl: 'http://127.0.0.1:8081/v1',
};
