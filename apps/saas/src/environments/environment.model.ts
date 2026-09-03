/**
 * What the operator console needs to know about the world.
 *
 * The API it manages the platform through, and where the tenant-facing
 * application lives (so a provisioned organisation's admin can be pointed at
 * their sign-in).
 */
export interface SaasEnvironment {
  readonly production: boolean;

  readonly api: {
    /** The new API, with the `/v1` prefix, no trailing slash. */
    readonly baseUrl: string;
  };

  /** Where the tenant application lives, no trailing slash. */
  readonly appUrl: string;
}
