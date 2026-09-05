import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { environment } from '../../environments/environment';

export interface PageMeta {
  total: number;
  page: number;
  perPage: number;
  totalPages: number;
}

export interface Paged<T> {
  items: T[];
  meta: PageMeta;
}

export type QueryParams = Record<string, string | number | boolean | null | undefined>;

/**
 * The one way this app talks to the API — the same envelope @central/web uses:
 * a success carries its payload under `data` (and a list its `meta`), a failure
 * carries `{ error: { code, message } }`. Callers get the unwrapped payload.
 */
@Injectable({ providedIn: 'root' })
export class ApiClient {
  private readonly http = inject(HttpClient);
  private readonly base = environment.apiBaseUrl;

  get<T>(path: string, params?: QueryParams): Observable<T> {
    return this.raw<T>('GET', path, undefined, params).pipe(map((r) => r.payload));
  }

  post<T>(path: string, body?: unknown, params?: QueryParams): Observable<T> {
    return this.raw<T>('POST', path, body, params).pipe(map((r) => r.payload));
  }

  put<T>(path: string, body?: unknown): Observable<T> {
    return this.raw<T>('PUT', path, body).pipe(map((r) => r.payload));
  }

  getPaged<T>(path: string, params?: QueryParams): Observable<Paged<T>> {
    return this.raw<T[]>('GET', path, undefined, params).pipe(
      map(({ payload, meta }) => {
        const items = Array.isArray(payload) ? payload : [];
        return {
          items,
          meta: meta ?? {
            total: items.length,
            page: 1,
            perPage: items.length,
            totalPages: items.length > 0 ? 1 : 0,
          },
        };
      }),
    );
  }

  private raw<T>(
    method: string,
    path: string,
    body?: unknown,
    params?: QueryParams,
  ): Observable<{ payload: T; meta?: PageMeta }> {
    return this.http
      .request<unknown>(method, this.base + path, { body, params: toParams(params), observe: 'body' })
      .pipe(
        map((response) => unwrap<T>(response)),
        // A refused request arrives here as an HttpErrorResponse (Slim serves
        // failures with a non-2xx status), so callers can rely on ApiError and
        // read the server's own code/message rather than the raw HTTP error.
        catchError((err: unknown) => throwError(() => toApiError(err))),
      );
  }
}

function unwrap<T>(response: unknown): { payload: T; meta?: PageMeta } {
  if (response === null || typeof response !== 'object') {
    return { payload: response as T };
  }
  const maybeError = (response as { error?: unknown }).error;
  if (maybeError != null && typeof maybeError === 'object') {
    const { code, message, details } = maybeError as {
      code: string;
      message: string;
      details?: Record<string, string[]>;
    };
    throw new ApiError(code, message, 0, details ?? {});
  }
  const success = response as { data: T; meta?: PageMeta };
  return { payload: success.data, meta: success.meta };
}

/** Normalise anything thrown by the HTTP layer into an ApiError. */
function toApiError(err: unknown): ApiError {
  if (err instanceof ApiError) {
    return err;
  }
  if (err instanceof HttpErrorResponse) {
    const body = err.error as {
      error?: { code?: string; message?: string; details?: Record<string, string[]> };
    } | null;
    if (body?.error) {
      return new ApiError(
        body.error.code ?? 'error',
        body.error.message ?? 'Request failed.',
        err.status,
        body.error.details ?? {},
      );
    }
    if (err.status === 0) {
      return new ApiError('network_error', 'Could not reach the server. Check your connection and try again.', 0);
    }
    return new ApiError('http_error', err.message || 'Request failed.', err.status);
  }
  return new ApiError('unknown_error', 'An unexpected error occurred.');
}

function toParams(params?: QueryParams): HttpParams | undefined {
  if (!params) {
    return undefined;
  }
  let p = new HttpParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') {
      p = p.set(key, String(value));
    }
  }
  return p;
}

/** A refused request, normalised to a code, message and (when known) status. */
export class ApiError extends Error {
  constructor(
    readonly code: string,
    message: string,
    readonly status = 0,
    /** Per-field validation messages from a 422, keyed by field name. */
    readonly details: Record<string, string[]> = {},
  ) {
    super(message);
    this.name = 'ApiError';
  }

  /** The first message for a field, if the server reported one. */
  fieldError(field: string): string | null {
    return this.details[field]?.[0] ?? null;
  }
}
