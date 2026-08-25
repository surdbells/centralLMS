import type { HttpEvent, HttpHandlerFn, HttpRequest } from '@angular/common/http';
import { inject } from '@angular/core';
import type { Observable } from 'rxjs';
import { SessionStore } from '../../auth/session.store';
import { ApiBackend } from '../api-backend';
import { API_BACKEND } from '../api-response';

/**
 * Attaches credentials in whichever form the target backend expects.
 *
 * The two are genuinely different protocols, not just different URLs:
 *
 *  - **New API** — `Authorization: Bearer <jwt>`.
 *  - **Legacy** — `token`, `id` and `tenant_id` merged into the request
 *    *body*, because that is what its 248 endpoints read.
 *
 * Doing this once here is what lets a module migrate without a single caller
 * changing. It was previously done by hand, per call, in every service.
 */
export function authInterceptor(
  request: HttpRequest<unknown>,
  next: HttpHandlerFn,
): Observable<HttpEvent<unknown>> {
  const session = inject(SessionStore).session();

  if (session === null) {
    return next(request);
  }

  const backend = request.context.get(API_BACKEND);

  if (backend === ApiBackend.V1) {
    return next(
      request.clone({
        setHeaders: { Authorization: `Bearer ${session.token}` },
      }),
    );
  }

  return next(applyLegacyCredentials(request, session.token, session.userId, session.tenantId));
}

/**
 * Merges legacy credentials into the request.
 *
 * GET and DELETE carry no body, so they take query parameters instead — the
 * legacy endpoints read `$_REQUEST`, which covers both.
 */
function applyLegacyCredentials(
  request: HttpRequest<unknown>,
  token: string,
  userId: string,
  tenantId: string,
): HttpRequest<unknown> {
  const credentials = { token, id: userId, tenant_id: tenantId };

  if (request.method === 'GET' || request.method === 'DELETE') {
    return request.clone({
      setParams: { token, id: userId, tenant_id: tenantId },
    });
  }

  // FormData uploads: append rather than replace, or the file is lost.
  if (request.body instanceof FormData) {
    const body = request.body;

    for (const [key, value] of Object.entries(credentials)) {
      if (!body.has(key)) {
        body.append(key, value);
      }
    }

    return request;
  }

  const body =
    request.body !== null && typeof request.body === 'object'
      ? { ...credentials, ...(request.body as Record<string, unknown>) }
      : credentials;

  return request.clone({ body });
}
