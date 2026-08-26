import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient, Endpoints, type Paged } from '../core/api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/** One thing somebody did. */
export interface AuditEvent {
  id: string;
  actorId: string | null;
  /** Their name now, or their address once they have been deleted. */
  actorName: string | null;
  actorEmail: string | null;
  /** The route's name: `users.create`, `roles.delete`. */
  action: string;
  /** The same thing as a sentence, worked out from the action. */
  description: string;
  method: string;
  path: string;
  /** What was acted on, or what was created. */
  subjectId: string | null;
  status: number;
  ipAddress: string | null;
  userAgent: string | null;
  /** Ties a row to a line in the server log. */
  requestId: string | null;
  createdAt: string;
}

export interface AuditFilters {
  actorId?: string;
  /** An exact action, or a module prefix such as `users`. */
  action?: string;
  subjectId?: string;
  from?: string;
  until?: string;
  failuresOnly?: boolean;
  page?: number;
  perPage?: number;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * The audit trail.
 *
 * Read-only, and deliberately so: there is no `record()` here because there is
 * no route behind one. Entries come from the API's own middleware, from what
 * the router matched — an endpoint that let a client post its own audit entry
 * would let a client post somebody else's.
 *
 * A filter the server cannot parse comes back as a 422 rather than being
 * quietly ignored, so a narrowed query that returns everything is a bug this
 * screen will hear about.
 */
@Injectable({ providedIn: 'root' })
export class AuditService {
  private readonly api = inject(ApiClient);

  list(filters: AuditFilters = {}): Observable<Paged<AuditEvent>> {
    return this.api.getPaged<AuditEvent>(Endpoints.audit.list, { ...filters });
  }

  /**
   * The actions this organisation's trail actually contains.
   *
   * Not every route the application has: a filter offering two hundred
   * options, four of which have ever occurred here, is a filter nobody uses.
   */
  actions(): Observable<string[]> {
    return this.api.get<string[]>(Endpoints.audit.actions);
  }
}
