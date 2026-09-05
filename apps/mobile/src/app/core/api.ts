import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
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
      .pipe(map((response) => unwrap<T>(response)));
  }
}

function unwrap<T>(response: unknown): { payload: T; meta?: PageMeta } {
  if (response === null || typeof response !== 'object') {
    return { payload: response as T };
  }
  if ('error' in response) {
    const { error } = response as { error: { code: string; message: string } };
    throw new ApiError(error.code, error.message);
  }
  const success = response as { data: T; meta?: PageMeta };
  return { payload: success.data, meta: success.meta };
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

/** A refused request, normalised to a code and message. */
export class ApiError extends Error {
  constructor(
    readonly code: string,
    message: string,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}
