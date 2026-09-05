import { Injectable, inject } from '@angular/core';
import { Observable, map, of } from 'rxjs';
import { ApiClient } from './api';
import { environment } from '../../environments/environment';

interface Ticket {
  url: string;
}

/**
 * Resolves whatever a lesson points at into a URL a player can use.
 *
 * A lesson's contentUrl is a reference, not a fetchable link: a media-library
 * file (`media:{id}` or a `/media/{id}/file` URL) whose bytes need a short-lived
 * ticket, or an external link (a YouTube embed, a pasted URL) that is used as-is.
 * A library file gets a ticketed, inline URL from POST /media/{id}/ticket;
 * anything else passes through untouched.
 */
@Injectable({ providedIn: 'root' })
export class MediaService {
  private readonly api = inject(ApiClient);

  static idFromReference(reference: string | null): string | null {
    if (reference === null) {
      return null;
    }
    const match = /^(?:media:|.*\/v1\/media\/)([0-9a-f-]{36})(?:\/file)?/i.exec(reference.trim());
    return match === null ? null : match[1];
  }

  resolve(reference: string | null): Observable<string | null> {
    const id = MediaService.idFromReference(reference);
    if (id === null) {
      return of(reference);
    }
    return this.api
      .post<Ticket>(`/media/${id}/ticket`, {})
      .pipe(map((t) => environment.apiBaseUrl + t.url.replace(/^\/v1/, '')));
  }
}
