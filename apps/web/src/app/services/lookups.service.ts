import { Injectable, inject } from '@angular/core';
import { Observable, shareReplay } from 'rxjs';
import { ApiClient, Endpoints, type LookupKind } from '../core/api';

export type { LookupKind };

/**
 * Reference data: a branch, a department, a team, a level, a job title, a
 * course category.
 *
 * One shape for all six. They were six tables with six sets of columns —
 * `branch_id`/`branch_name`, `department_id`/`department_name`, and so on —
 * and 52 files that each knew which was which.
 */
export interface Lookup {
  id: string;
  kind: LookupKind;
  name: string;
  description: string | null;
  sortOrder: number;
  isActive: boolean;
  createdAt: string | null;
  updatedAt: string | null;
  /** Present only for the kinds that have one. */
  code?: string | null;
  /** Present only for the kinds that nest — a department under a branch. */
  parentId?: string | null;
}

export interface LookupInput {
  name: string;
  description?: string | null;
  code?: string | null;
  parentId?: string | null;
  sortOrder?: number;
  isActive?: boolean;
}

/**
 * Reference data, for the pickers that fill themselves from it.
 *
 * Six routes parameterised by kind, where the legacy had six endpoints per
 * kind: `list_branches`, `list_departments`, `list_teams`… each with its own
 * response shape and its own bugs.
 */
@Injectable({ providedIn: 'root' })
export class LookupsService {
  private readonly api = inject(ApiClient);

  /** Cached per kind: a form with five pickers should not ask twice. */
  private readonly activeCache = new Map<LookupKind, Observable<Lookup[]>>();

  /** Everything, inactive included — for the screen that manages them. */
  list(kind: LookupKind): Observable<Lookup[]> {
    return this.api.get<Lookup[]>(Endpoints.lookups.list(kind));
  }

  /**
   * Just what a picker should offer.
   *
   * Cached for the lifetime of the session: reference data changes rarely,
   * and {@link forget} clears a kind after it has been edited.
   */
  active(kind: LookupKind): Observable<Lookup[]> {
    const cached = this.activeCache.get(kind);

    if (cached !== undefined) {
      return cached;
    }

    const request = this.api
      .get<Lookup[]>(Endpoints.lookups.active(kind))
      .pipe(shareReplay({ bufferSize: 1, refCount: false }));

    this.activeCache.set(kind, request);

    return request;
  }

  show(kind: LookupKind, id: string): Observable<Lookup> {
    return this.api.get<Lookup>(Endpoints.lookups.show(kind, id));
  }

  create(kind: LookupKind, input: LookupInput): Observable<Lookup> {
    this.forget(kind);

    return this.api.post<Lookup>(Endpoints.lookups.create(kind), input);
  }

  update(kind: LookupKind, id: string, input: Partial<LookupInput>): Observable<Lookup> {
    this.forget(kind);

    return this.api.put<Lookup>(Endpoints.lookups.update(kind, id), input);
  }

  /**
   * Switches one off rather than deleting it: people, courses and enrolments
   * point at these, and a deleted department would orphan every record filed
   * under it.
   */
  setStatus(kind: LookupKind, id: string, isActive: boolean): Observable<Lookup> {
    this.forget(kind);

    return this.api.patch<Lookup>(Endpoints.lookups.status(kind, id), { isActive });
  }

  forget(kind: LookupKind): void {
    this.activeCache.delete(kind);
  }
}
