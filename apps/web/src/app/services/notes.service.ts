import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { ApiClient, Endpoints, type Paged } from '../core/api';

/** A note somebody made against a lesson. */
export interface Note {
  id: string;
  lessonId: string | null;
  courseId: string | null;
  title: string;
  content: string;
  positionSeconds: number | null;
  isPinned: boolean;
  lessonTitle: string | null;
  moduleTitle: string | null;
  courseTitle: string | null;
  createdAt: string;
  updatedAt: string;
}

export interface NoteFilters {
  search?: string;
  courseId?: string;
  lessonId?: string;
  /** Only the pinned ones. */
  pinned?: boolean;
  page?: number;
  perPage?: number;
}

export interface NoteInput {
  title: string;
  content: string;
  lessonId?: string | null;
  courseId?: string | null;
  positionSeconds?: number | null;
}

export interface NoteStatistics {
  total: number;
  pinned: number;
  courses: number;
}

/**
 * The signed-in person's own notes.
 *
 * No id in any path: the API acts on whoever the token says is asking, so
 * there is no user to name and none to tamper with. The legacy `UserNotes`
 * endpoint took `user_id` and `tenant_id` from the request body.
 */
@Injectable({ providedIn: 'root' })
export class NotesService {
  private readonly api = inject(ApiClient);

  list(filters: NoteFilters = {}): Observable<Paged<Note>> {
    return this.api.getPaged<Note>(Endpoints.notes.list, { ...filters });
  }

  statistics(): Observable<NoteStatistics> {
    return this.api.get<NoteStatistics>(Endpoints.notes.statistics);
  }

  create(input: NoteInput): Observable<Note> {
    return this.api.post<Note>(Endpoints.notes.create, input);
  }

  update(id: string, input: Partial<NoteInput>): Observable<Note> {
    return this.api.put<Note>(Endpoints.notes.update(id), input);
  }

  /** Pinning is its own route because it is the one edit made from a list. */
  pin(id: string, pinned: boolean): Observable<Note> {
    return this.api.patch<Note>(Endpoints.notes.pin(id), { pinned });
  }

  delete(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.notes.delete(id));
  }
}
