import { HttpContextToken } from '@angular/common/http';
import { ApiBackend } from './api-backend';

/**
 * Carries the backend serving a request through the interceptor chain, so the
 * auth interceptor knows whether to send a bearer header or legacy body
 * credentials without re-deriving the module from the URL.
 */
export const API_BACKEND = new HttpContextToken<ApiBackend>(() => ApiBackend.Legacy);

/** Legacy PHP envelope: `{ response_code, status, message, data }`. */
export interface LegacyEnvelope<T> {
  response_code?: number;
  status?: string;
  message?: string;
  data?: T;
}

/** New API success envelope: `{ data, meta? }`. */
export interface V1Success<T> {
  data: T;
  meta?: PageMeta & Record<string, unknown>;
}

/** New API failure envelope: `{ error: { code, message, details? } }`. */
export interface V1Failure {
  error: {
    code: string;
    message: string;
    details?: Record<string, unknown>;
  };
}

export interface PageMeta {
  total: number;
  page: number;
  perPage: number;
  totalPages: number;
}

/** A response with its pagination metadata preserved. */
export interface Paged<T> {
  items: T[];
  meta: PageMeta;
}

/**
 * A failed API call, normalised across both backends.
 *
 * `code` is the thing to branch on. The new API returns stable identifiers
 * (`validation_failed`, `unauthenticated`, …); legacy responses are mapped
 * onto the same vocabulary so that a component's error handling does not have
 * to change when its module migrates.
 */
export class ApiError extends Error {
  constructor(
    readonly code: string,
    message: string,
    readonly status: number,
    readonly details: Record<string, unknown> = {},
    readonly requestId?: string,
  ) {
    super(message);
    this.name = 'ApiError';
    // Restores the prototype chain so `instanceof ApiError` works when the
    // build targets ES5-era output.
    Object.setPrototypeOf(this, ApiError.prototype);
  }

  get isValidation(): boolean {
    return this.code === 'validation_failed';
  }

  get isUnauthenticated(): boolean {
    return this.code === 'unauthenticated';
  }

  get isForbidden(): boolean {
    return this.code === 'forbidden';
  }

  get isNotFound(): boolean {
    return this.code === 'not_found' || this.code === 'route_not_found';
  }

  /** Whether retrying the identical request could plausibly succeed. */
  get isRetryable(): boolean {
    return this.status === 0 || this.status === 429 || this.status >= 500;
  }

  /**
   * Field-level messages from a 422, keyed by field name. Empty for any other
   * kind of failure, so a form can bind to it unconditionally.
   */
  get violations(): Record<string, string[]> {
    const raw = this.details['violations'];

    return typeof raw === 'object' && raw !== null ? (raw as Record<string, string[]>) : {};
  }
}

/**
 * HTTP status → stable error code, for legacy responses that carry no code of
 * their own. Keeps both backends speaking the same error vocabulary.
 */
export function codeForStatus(status: number): string {
  switch (status) {
    case 0:
      return 'network_error';
    case 400:
      return 'bad_request';
    case 401:
      return 'unauthenticated';
    case 403:
      return 'forbidden';
    case 404:
      return 'not_found';
    case 409:
      return 'conflict';
    case 422:
      return 'validation_failed';
    case 429:
      return 'rate_limited';
    default:
      return status >= 500 ? 'internal_error' : 'request_failed';
  }
}
