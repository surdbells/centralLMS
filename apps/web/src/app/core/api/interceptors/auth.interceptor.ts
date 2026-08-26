import type { HttpEvent, HttpHandlerFn, HttpRequest } from '@angular/common/http';
import { inject } from '@angular/core';
import type { Observable } from 'rxjs';
import { SessionStore } from '../../auth/session.store';

/**
 * Attaches the bearer token.
 *
 * One line, because there is one way to authenticate. It used to also merge
 * `token`, `id` and `tenant_id` into the request *body* for the legacy
 * backend, whose 248 endpoints read credentials from `$_REQUEST` — including
 * the tenant, which is why one signed-in user could read another
 * organisation's data by changing a number in a JSON payload.
 *
 * Doing this here at all is what stopped it being done by hand, per call, in
 * every service.
 */
export function authInterceptor(
  request: HttpRequest<unknown>,
  next: HttpHandlerFn,
): Observable<HttpEvent<unknown>> {
  const session = inject(SessionStore).session();

  if (session === null) {
    return next(request);
  }

  return next(request.clone({ setHeaders: { Authorization: `Bearer ${session.token}` } }));
}
