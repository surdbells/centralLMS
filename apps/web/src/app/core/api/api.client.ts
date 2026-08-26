import {
  HttpClient,
  HttpErrorResponse,
  HttpEventType,
  HttpParams,
  HttpRequest,
  HttpResponse,
} from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, map, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { ApiRouter } from './api-routing';
import {
  ApiError,
  codeForStatus,
  type ApiFailure,
  type ApiSuccess,
  type PageMeta,
  type Paged,
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
 * The single way this application talks to the API.
 *
 * Replaces `CrudService`, whose entire surface was
 * `post_request(data: any, endpoint: string): Observable<any>` — the caller
 * passing a raw URL string built by hand from `GlobalComponent`, with errors
 * going to `console.log` and the response typed `any`.
 *
 * Callers name a path from {@link Endpoints} and get the unwrapped payload, or
 * an {@link ApiError} with a code worth branching on.
 */
@Injectable({ providedIn: 'root' })
export class ApiClient {
  private readonly http = inject(HttpClient);
  private readonly router = inject(ApiRouter);

  get<T>(path: string, params?: QueryParams): Observable<T> {
    return this.request<T>('GET', path, undefined, params);
  }

  post<T>(path: string, body?: unknown, params?: QueryParams): Observable<T> {
    return this.request<T>('POST', path, body, params);
  }

  put<T>(path: string, body?: unknown): Observable<T> {
    return this.request<T>('PUT', path, body);
  }

  patch<T>(path: string, body?: unknown): Observable<T> {
    return this.request<T>('PATCH', path, body);
  }

  delete<T>(path: string, params?: QueryParams): Observable<T> {
    return this.request<T>('DELETE', path, undefined, params);
  }

  /**
   * A DELETE that names what to remove in its body.
   *
   * Separate from {@link delete} so the common case cannot pass a body by
   * accident, and so it is obvious at the call site that this one does. Taking
   * forty people off a course is a list, and a list of forty ids does not
   * belong in a query string.
   */
  deleteWith<T>(path: string, body: unknown): Observable<T> {
    return this.request<T>('DELETE', path, body);
  }

  /**
   * A list endpoint together with its pagination metadata.
   *
   * `meta` is synthesised for the endpoints that return a plain array — a
   * handful do, because a category list has no pages — so a caller can render
   * pagination without checking which sort it asked for.
   */
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

  /**
   * Multipart upload with progress reporting.
   *
   * Kept on the client so uploads authenticate through the same interceptor as
   * everything else. The original `MediaService` hardcoded the production URL
   * and appended credentials to the FormData by hand.
   */
  upload<T>(path: string, form: FormData): Observable<UploadProgress<T>> {
    return this.http
      .request(new HttpRequest('POST', this.router.url(path), form, { reportProgress: true }))
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
            return { state: 'done', progress: 100, result: this.unwrap<T>(event.body).payload };
          }

          return { state: 'pending', progress: 0 };
        }),
        catchError((error: unknown) => throwError(() => this.toApiError(error))),
      );
  }

  /**
   * A URL a browser can fetch directly — an `<img src>`, a link, a video.
   *
   * Only for paths the API serves as bytes rather than JSON.
   */
  urlFor(path: string): string {
    return this.router.url(path);
  }

  private request<T>(
    method: string,
    path: string,
    body?: unknown,
    params?: QueryParams,
  ): Observable<T> {
    return this.raw<T>(method, path, body, params).pipe(map((r) => r.payload));
  }

  private raw<T>(
    method: string,
    path: string,
    body?: unknown,
    params?: QueryParams,
  ): Observable<{ payload: T; meta?: PageMeta }> {
    return this.http
      .request<unknown>(method, this.router.url(path), {
        body,
        params: toHttpParams(params),
        observe: 'body',
      })
      .pipe(
        map((response) => this.unwrap<T>(response)),
        catchError((error: unknown) => throwError(() => this.toApiError(error))),
      );
  }

  /** Pulls the payload out of the envelope. */
  private unwrap<T>(response: unknown): { payload: T; meta?: PageMeta } {
    if (response === null || typeof response !== 'object') {
      return { payload: response as T };
    }

    if ('error' in response) {
      const { error } = response as ApiFailure;

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

    const success = response as ApiSuccess<T>;

    return { payload: success.data, meta: success.meta as PageMeta | undefined };
  }

  /**
   * Transport-level and unexpected failures.
   *
   * An {@link ApiError} thrown by `unwrap` passes through untouched — it has
   * already been normalised and is more precise than anything derivable here.
   */
  private toApiError(error: unknown): ApiError {
    if (error instanceof ApiError) {
      return error;
    }

    if (!(error instanceof HttpErrorResponse)) {
      return new ApiError('unknown_error', 'An unexpected error occurred.', 0);
    }

    const requestId = error.headers?.get('X-Request-Id') ?? undefined;

    // A refused request still carries the envelope, and it is more useful than
    // the status alone: it names the field that failed.
    if (error.error && typeof error.error === 'object' && 'error' in error.error) {
      const { error: detail } = error.error as ApiFailure;

      return new ApiError(detail.code, detail.message, error.status, detail.details ?? {}, requestId);
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

/** Maps an error code back to the status it was served with. */
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
