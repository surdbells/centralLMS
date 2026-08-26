import { ApiBackend } from './api-backend';

/**
 * A path that may differ between the two backends.
 *
 * Most endpoints keep the same path either side of a migration, so a plain
 * string is the common case. Where the new API has genuinely reshaped a route
 * — `/authentication/login` becoming `/auth/login`, or 36 dictionary endpoints
 * collapsing into six — both spellings are declared together and the router
 * picks.
 *
 * The alternative is an `if (router.isMigrated(...))` at every call site, which
 * is the one thing this API layer exists to avoid: a component should not
 * change, or even know, when its module moves.
 */
export type EndpointPath =
  | string
  | {
      readonly legacy: string;
      readonly v1: string;
    };

/**
 * The path this backend actually serves.
 */
export function resolvePath(path: EndpointPath, backend: ApiBackend): string {
  if (typeof path === 'string') {
    return path;
  }

  return backend === ApiBackend.V1 ? path.v1 : path.legacy;
}
