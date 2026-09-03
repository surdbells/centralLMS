import { Injectable, inject } from '@angular/core';
import { Observable } from 'rxjs';
import { map } from 'rxjs/operators';
import { ApiClient, Endpoints, type Paged, type UploadProgress } from '../core/api';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/** The original file's kind. A document is viewed as a PDF once converted. */
export type LibraryKind = 'video' | 'audio' | 'pdf' | 'document' | 'image';

/** What a viewer is actually shown — a converted document views as a PDF. */
export type ViewKind = 'video' | 'audio' | 'pdf' | 'image';

export type ConversionStatus = 'not_required' | 'pending' | 'processing' | 'done' | 'failed';

export type AudienceType = 'all' | 'role' | 'department' | 'branch' | 'team';

export interface LibraryAudience {
  type: AudienceType;
  label: string;
  criteria: Record<string, string[]>;
}

export interface LibraryItem {
  id: string;
  kind: LibraryKind;
  viewKind: ViewKind;
  title: string;
  description: string | null;
  originalFilename: string;
  fileSize: number;
  mimeType: string;
  extension: string;
  pageCount: number | null;
  durationSeconds: number | null;
  conversionStatus: ConversionStatus;
  /** Whether it can be viewed right now (a document is not, until converted). */
  viewable: boolean;
  audience: LibraryAudience;
  uploadedBy: string | null;
  uploaderName: string | null;
  createdAt: string | null;
  updatedAt: string | null;
  /** The streaming path. Never a download — the bytes are always inline. */
  viewUrl: string;
}

/** What a ticket request answers with — a URL that carries its own credential. */
export interface LibraryTicket {
  url: string;
  ticket: string;
  expiresIn: number;
}

export interface LibraryAudienceInput {
  targetType: AudienceType;
  /** The ids the audience names; empty (and ignored) for 'all'. */
  targetIds: string[];
}

export interface LibraryUploadInput extends LibraryAudienceInput {
  title: string;
  description?: string;
}

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * The digital library: files shared with an audience, viewable but never
 * downloaded.
 *
 * The bytes are only ever reached through a ticketed, inline-only URL minted by
 * {@link viewUrlFor}; nothing here builds a download link, because there is no
 * download route to build one for.
 */
@Injectable({ providedIn: 'root' })
export class LibraryService {
  private readonly api = inject(ApiClient);

  /** The library as it is shared with the current user. */
  list(search?: string, page = 1, perPage = 60): Observable<Paged<LibraryItem>> {
    return this.api.getPaged<LibraryItem>(Endpoints.library.list, { search, page, perPage });
  }

  /** The whole shelf, for management. */
  listManage(search?: string, page = 1, perPage = 60): Observable<Paged<LibraryItem>> {
    return this.api.getPaged<LibraryItem>(Endpoints.library.manageList, { search, page, perPage });
  }

  upload(file: File, input: LibraryUploadInput): Observable<UploadProgress<LibraryItem>> {
    const form = new FormData();
    form.append('file', file, file.name);
    form.append('title', input.title);
    if (input.description) {
      form.append('description', input.description);
    }
    form.append('targetType', input.targetType);
    if (input.targetType !== 'all') {
      form.append('targetIds', JSON.stringify(input.targetIds));
    }
    return this.api.upload<LibraryItem>(Endpoints.library.upload, form);
  }

  update(id: string, input: LibraryUploadInput): Observable<LibraryItem> {
    return this.api.put<LibraryItem>(Endpoints.library.update(id), {
      title: input.title,
      description: input.description ?? null,
      targetType: input.targetType,
      targetIds: input.targetType === 'all' ? [] : input.targetIds,
    });
  }

  delete(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.library.delete(id));
  }

  /**
   * A ready-to-use, ticketed URL for one item's bytes.
   *
   * The ticket carries the authorisation, so the URL can go straight into a
   * `<video src>`, a `<img src>` or a PDF frame, none of which send a header.
   * It is fetched, never constructed: the API mints it after checking the item
   * is shared with the caller.
   */
  viewUrlFor(item: LibraryItem | { id: string }): Observable<string> {
    return this.api
      .post<LibraryTicket>(Endpoints.library.ticket(item.id), {})
      .pipe(map((ticket) => this.api.urlFor(ticket.url.replace(/^\/v1/, ''))));
  }
}
