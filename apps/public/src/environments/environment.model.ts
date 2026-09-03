/**
 * What this application needs to know about the world.
 *
 * One field. The marketing site holds nothing of anybody's and talks to
 * nothing except the application it points at.
 */
export interface PublicEnvironment {
  readonly production: boolean;

  /**
   * Where the application lives, no trailing slash.
   *
   * A separate deployment since the two were split apart, so "Sign In" is a
   * link out rather than a route — there is no sign-in screen in this bundle,
   * which is the point.
   */
  readonly appUrl: string;

  /**
   * The new API, with the `/v1` prefix, no trailing slash.
   *
   * Self-service sign-up is the one thing this site asks the API for — the
   * plan catalogue, an onboarding code, and provisioning an organisation.
   */
  readonly apiBaseUrl: string;
}
