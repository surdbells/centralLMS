import { HttpClient, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { environment } from '../../environments/environment';
import type { PageMeta, Paged } from './models';

/** The success envelope every endpoint writes: `{data, meta?}`. */
interface Envelope<T> {
  readonly data: T;
  readonly meta?: PageMeta;
}

type Query = Record<string, string | number | boolean | null | undefined>;

/**
 * The one place a request is made absolute and unwrapped.
 *
 * The API answers `{data: …}` on success and `{error: {code, message}}` on
 * failure; here a success is unwrapped to its `data` and a failure is left to
 * throw, so a screen works in the domain shapes and never touches the
 * envelope. The bearer token is not added here — that is the interceptor's
 * job, once, for every request.
 */
@Injectable({ providedIn: 'root' })
export class ApiClient {
  private readonly http = inject(HttpClient);
  private readonly base = environment.api.baseUrl;

  get<T>(path: string, query?: Query): Observable<T> {
    return this.http
      .get<Envelope<T>>(this.url(path), { params: toParams(query) })
      .pipe(map((res) => res.data));
  }

  /** A paged collection, both its items and the pagination it came with. */
  getPaged<T>(path: string, query?: Query): Observable<Paged<T>> {
    return this.http
      .get<Envelope<readonly T[]>>(this.url(path), { params: toParams(query) })
      .pipe(
        map((res) => ({
          items: res.data,
          meta: res.meta ?? { total: res.data.length, page: 1, perPage: res.data.length, totalPages: 1 },
        })),
      );
  }

  post<T>(path: string, body?: unknown): Observable<T> {
    return this.http.post<Envelope<T>>(this.url(path), body ?? {}).pipe(map((res) => res.data));
  }

  put<T>(path: string, body?: unknown): Observable<T> {
    return this.http.put<Envelope<T>>(this.url(path), body ?? {}).pipe(map((res) => res.data));
  }

  delete(path: string): Observable<void> {
    return this.http.delete<void>(this.url(path)).pipe(map(() => undefined));
  }

  private url(path: string): string {
    return this.base + path;
  }
}

function toParams(query?: Query): HttpParams {
  let params = new HttpParams();
  if (!query) {
    return params;
  }

  for (const [key, value] of Object.entries(query)) {
    if (value !== null && value !== undefined && value !== '') {
      params = params.set(key, String(value));
    }
  }

  return params;
}
