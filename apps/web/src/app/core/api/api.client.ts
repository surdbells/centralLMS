import {
  HttpClient,
  HttpContext,
  HttpErrorResponse,
  HttpEventType,
  HttpParams,
  HttpRequest,
  HttpResponse,
} from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { ApiBackend } from './api-backend';
import { ApiModule } from './api-module';
import { ApiRouter } from './api-routing';
import type { EndpointPath } from './endpoint-path';
import {
  API_BACKEND,
  ApiError,
  codeForStatus,
  type LegacyEnvelope,
  type PageMeta,
  type Paged,
  type V1Failure,
  type V1Success,
} from './api-response';

/** Progress of an in-flight upload. */
export interface UploadProgress<T> {
  readonly state: 'pending' | 'uploading' | 'done';
  /** 0–100. */
  readonly progress: number;
  readonly result?: T;
}

/** Query parameters, with `undefined`/`null` entries dropped rather than sent as "undefined". */
export type QueryParams = Record<string, string | number | boolean | null | undefined>;

/**
 * The single way this application talks to a backend.
 *
 * Replaces `CrudService`, whose entire surface was
 * `post_request(data: any, endpoint: string): Observable<any>` with the caller
 * passing a raw URL string and errors going to `console.log`.
 *
 * Two responsibilities beyond issuing the request:
 *
 *  1. **Route by module.** Callers name a module, never a base URL, so a
 *     module can be migrated to the new API without touching any caller.
 *  2. **Normalise both dialects.** The legacy and new APIs disagree about
 *     success shape, error shape *and* HTTP status. Every caller sees the same
 *     unwrapped payload and the same {@link ApiError}, so a component does not
 *     change when its module migrates.
 */
@Injectable({ providedIn: 'root' })
export class ApiClient {
  private readonly http = inject(HttpClient);
  private readonly router = inject(ApiRouter);

  get<T>(module: ApiModule, path: EndpointPath, params?: QueryParams): Observable<T> {
    return this.request<T>('GET', module, path, undefined, params);
  }

  post<T>(module: ApiModule, path: EndpointPath, body?: unknown, params?: QueryParams): Observable<T> {
    return this.request<T>('POST', module, path, body, params);
  }

  put<T>(module: ApiModule, path: EndpointPath, body?: unknown): Observable<T> {
    return this.request<T>('PUT', module, path, body);
  }

  patch<T>(module: ApiModule, path: EndpointPath, body?: unknown): Observable<T> {
    return this.request<T>('PATCH', module, path, body);
  }

  delete<T>(module: ApiModule, path: EndpointPath, params?: QueryParams): Observable<T> {
    return this.request<T>('DELETE', module, path, undefined, params);
  }

  /**
   * A list endpoint together with its pagination metadata.
   *
   * The new API returns `meta`; legacy responses have none, so a single-page
   * `meta` is synthesised from the row count. Callers can therefore render
   * pagination identically either side of a migration.
   */
  getPaged<T>(module: ApiModule, path: EndpointPath, params?: QueryParams): Observable<Paged<T>> {
    return this.raw<T[]>('GET', module, path, undefined, params).pipe(
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

  /**
   * Multipart upload with progress reporting.
   *
   * Kept on the client so uploads route and authenticate through the same
   * switchboard as everything else. The old `MediaService` hardcoded the
   * production URL and appended credentials to the FormData by hand.
   */
  upload<T>(module: ApiModule, path: EndpointPath, form: FormData): Observable<UploadProgress<T>> {
    const backend = this.router.backendFor(module);

    return this.http
      .request(
        new HttpRequest('POST', this.router.url(module, path), form, {
          reportProgress: true,
          context: new HttpContext().set(API_BACKEND, backend),
        }),
      )
      .pipe(
        map((event): UploadProgress<T> => {
          if (event.type === HttpEventType.UploadProgress) {
            return {
              state: 'uploading',
              // `total` is absent when the server sends no content-length.
              progress: event.total ? Math.round((event.loaded / event.total) * 100) : 0,
            };
          }

          if (event instanceof HttpResponse) {
            return {
              state: 'done',
              progress: 100,
              result: this.unwrap<T>(event.body, backend).payload,
            };
          }

          return { state: 'pending', progress: 0 };
        }),
        catchError((error: unknown) => throwError(() => this.toApiError(error, backend))),
      );
  }

  private request<T>(
    method: string,
    module: ApiModule,
    path: EndpointPath,
    body?: unknown,
    params?: QueryParams,
  ): Observable<T> {
    return this.raw<T>(method, module, path, body, params).pipe(map((r) => r.payload));
  }

  private raw<T>(
    method: string,
    module: ApiModule,
    path: EndpointPath,
    body?: unknown,
    params?: QueryParams,
  ): Observable<{ payload: T; meta?: PageMeta }> {
    const backend = this.router.backendFor(module);
    const url = this.router.url(module, path);

    return this.http
      .request<unknown>(method, url, {
        body,
        params: toHttpParams(params),
        context: new HttpContext().set(API_BACKEND, backend),
        observe: 'body',
      })
      .pipe(
        map((response) => this.unwrap<T>(response, backend)),
        catchError((error: unknown) => throwError(() => this.toApiError(error, backend))),
      );
  }

  /**
   * Pulls the payload out of whichever envelope the backend used.
   *
   * The legacy backend's `send_response()` never calls `http_response_code()`,
   * so **every legacy response arrives as HTTP 200** with the real status in
   * the body. A failure therefore has to be detected here rather than by
   * `HttpClient`, or a 401 would reach callers looking like success.
   */
  private unwrap<T>(response: unknown, backend: ApiBackend): { payload: T; meta?: PageMeta } {
    if (response === null || typeof response !== 'object') {
      return { payload: response as T };
    }

    if (backend === ApiBackend.V1) {
      if ('error' in response) {
        const { error } = response as V1Failure;

        throw new ApiError(
          error.code,
          error.message,
          statusForCode(error.code),
          error.details ?? {},
          typeof error.details?.['requestId'] === 'string'
            ? (error.details['requestId'] as string)
            : undefined,
        );
      }

      const success = response as V1Success<T>;

      return { payload: success.data, meta: success.meta as PageMeta | undefined };
    }

    const legacy = response as LegacyEnvelope<T>;
    const code = legacy.response_code ?? 200;

    if (code >= 400) {
      throw new ApiError(
        codeForStatus(code),
        legacy.message?.trim() || 'The request failed.',
        code,
      );
    }

    // Some legacy endpoints return the payload directly, with no envelope.
    const payload = 'data' in legacy ? (legacy.data as T) : (response as T);

    return { payload };
  }

  /**
   * Transport-level and unexpected failures.
   *
   * An {@link ApiError} thrown by `unwrap` passes through untouched — it has
   * already been normalised and is more precise than anything derivable here.
   */
  private toApiError(error: unknown, backend: ApiBackend): ApiError {
    if (error instanceof ApiError) {
      return error;
    }

    if (!(error instanceof HttpErrorResponse)) {
      return new ApiError('unknown_error', 'An unexpected error occurred.', 0);
    }

    const requestId = error.headers?.get('X-Request-Id') ?? undefined;

    // The new API reports failures with a real status *and* an error envelope.
    if (backend === ApiBackend.V1 && error.error && typeof error.error === 'object' && 'error' in error.error) {
      const { error: detail } = error.error as V1Failure;

      return new ApiError(
        detail.code,
        detail.message,
        error.status,
        detail.details ?? {},
        requestId,
      );
    }

    if (error.status === 0) {
      return new ApiError(
        'network_error',
        'Could not reach the server. Check your connection and try again.',
        0,
        {},
        requestId,
      );
    }

    const message =
      (typeof error.error === 'object' && error.error !== null && 'message' in error.error
        ? String((error.error as { message?: unknown }).message ?? '')
        : '') || error.message;

    return new ApiError(codeForStatus(error.status), message, error.status, {}, requestId);
  }
}

/** Maps a new-API error code back to the status it was served with. */
function statusForCode(code: string): number {
  switch (code) {
    case 'validation_failed':
      return 422;
    case 'unauthenticated':
      return 401;
    case 'forbidden':
      return 403;
    case 'not_found':
    case 'route_not_found':
      return 404;
    case 'conflict':
      return 409;
    case 'rate_limited':
      return 429;
    case 'internal_error':
      return 500;
    default:
      return 400;
  }
}

function toHttpParams(params?: QueryParams): HttpParams | undefined {
  if (!params) {
    return undefined;
  }

  let httpParams = new HttpParams();

  for (const [key, value] of Object.entries(params)) {
    // Dropping empties keeps `?page=undefined` out of request logs.
    if (value !== undefined && value !== null && value !== '') {
      httpParams = httpParams.set(key, String(value));
    }
  }

  return httpParams;
}
