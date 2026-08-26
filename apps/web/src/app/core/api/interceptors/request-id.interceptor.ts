import type { HttpEvent, HttpHandlerFn, HttpRequest } from '@angular/common/http';
import type { Observable } from 'rxjs';

/**
 * Tags each request with a correlation id.
 *
 * The API echoes it back in `X-Request-Id` and stamps it on every log line and
 * error response, so an id from the browser console traces straight through to
 * the server logs and Sentry.
 */
export function requestIdInterceptor(
  request: HttpRequest<unknown>,
  next: HttpHandlerFn,
): Observable<HttpEvent<unknown>> {
  return next(request.clone({ setHeaders: { 'X-Request-Id': newRequestId() } }));
}

/**
 * A short, URL-safe id. The API rejects anything longer than 64 characters or
 * outside `[A-Za-z0-9._-]`, since the value reaches log files.
 */
function newRequestId(): string {
  const cryptoApi = globalThis.crypto;

  if (typeof cryptoApi?.randomUUID === 'function') {
    return cryptoApi.randomUUID();
  }

  const bytes = new Uint8Array(16);

  if (typeof cryptoApi?.getRandomValues === 'function') {
    cryptoApi.getRandomValues(bytes);
  } else {
    // Non-secure fallback: this is a correlation id, not a credential.
    for (let i = 0; i < bytes.length; i++) {
      bytes[i] = Math.floor(Math.random() * 256);
    }
  }

  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
}
