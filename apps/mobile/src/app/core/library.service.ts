import { Injectable, inject } from '@angular/core';
import { Observable, map } from 'rxjs';
import { ApiClient } from './api';
import { environment } from '../../environments/environment';

export type LibraryViewKind = 'video' | 'audio' | 'pdf' | 'image';

/** A file shared with the learner in the digital library. */
export interface LibraryItem {
  id: string;
  kind: string; // video | audio | pdf | document | image
  viewKind: LibraryViewKind;
  title: string;
  description: string | null;
  originalFilename: string;
  fileSize: number;
  extension: string;
  pageCount: number | null;
  durationSeconds: number | null;
  conversionStatus: string; // not_required | pending | processing | done | failed
  /** Whether it can be viewed right now (a document is not, until converted). */
  viewable: boolean;
  uploaderName: string | null;
  createdAt: string | null;
}

interface Ticket {
  url: string;
}

/**
 * The digital library — files shared with an audience, viewed inline and never
 * downloaded. The bytes are only ever reached through a ticketed URL minted by
 * {@link viewUrl}: the ticket carries the authorisation, so the URL drops
 * straight into an <img>/<video>/<audio>/PDF frame with no header.
 */
@Injectable({ providedIn: 'root' })
export class LibraryService {
  private readonly api = inject(ApiClient);

  /** The library as it is shared with the current user. */
  list(search?: string): Observable<LibraryItem[]> {
    return this.api
      .getPaged<LibraryItem>('/library', { search, perPage: 100 })
      .pipe(map((page) => page.items));
  }

  /** A ready-to-use, ticketed inline URL for one item's bytes. */
  viewUrl(id: string): Observable<string> {
    return this.api
      .post<Ticket>(`/library/${id}/ticket`, {})
      .pipe(map((t) => environment.apiBaseUrl + t.url.replace(/^\/v1/, '')));
  }
}
