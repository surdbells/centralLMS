/**
 * The shape every environment file must satisfy.
 *
 * Typing this is the point: the original environment files were untyped object
 * literals that had silently drifted apart, so `environment.api.baseUrl`
 * existed in development and was `undefined` in production.
 *
 * There is one base URL. During the migration there were two, with a
 * per-module switchboard deciding which served what; every module has now
 * moved, so the switchboard and the second URL are gone. See
 * `core/api/api-routing.ts`.
 */
export interface AppEnvironment {
  readonly production: boolean;

  readonly api: {
    /** Base URL of the API, no trailing slash. */
    readonly baseUrl: string;
  };

  /**
   * Where the marketing site lives, no trailing slash.
   *
   * A separate application since the two were split apart, so "Back to
   * website" cannot be a relative link any more — `/` is the sign-in
   * screen here.
   */
  readonly publicSiteUrl: string;
}
