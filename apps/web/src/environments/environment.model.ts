import type { ApiBackend } from '../app/core/api/api-backend';
import type { ApiModule } from '../app/core/api/api-module';

/**
 * The shape every environment file must satisfy.
 *
 * Typing this is the point: the old environment files were untyped object
 * literals that had silently drifted apart, so `environment.api.baseUrl`
 * existed in development and was `undefined` in production.
 */
export interface AppEnvironment {
  readonly production: boolean;

  readonly api: {
    /** Base URL of the legacy PHP backend, no trailing slash. */
    readonly legacyBaseUrl: string;
    /** Base URL of the Slim API, no trailing slash. */
    readonly v1BaseUrl: string;
  };

  /**
   * Per-module backend overrides. Anything absent falls back to
   * {@link ApiBackend.Legacy}, so a newly added module keeps working against
   * the old backend until it is deliberately migrated.
   */
  readonly moduleBackends: Partial<Record<ApiModule, ApiBackend>>;
}
