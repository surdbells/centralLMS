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
  /** The category it is filed under; null when uncategorised. */
  categoryId: string | null;
  categoryName: string | null;
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
  /** The category to file it under; null (or absent) for uncategorised. */
  categoryId?: string | null;
}

/** A shelf the library is organised into. */
export interface LibraryCategory {
  id: string;
  name: string;
  description: string | null;
  sortOrder: number;
  /** Items in it — for a learner, only the ones shared with them. */
  itemCount: number;
}

/** The categories, plus how many items sit in none of them. */
export interface LibraryCategoryList {
  categories: LibraryCategory[];
  uncategorisedCount: number;
}

export interface LibraryCategoryInput {
  name?: string;
  description?: string | null;
  sortOrder?: number;
}

/** A category filter: a category id, `'none'` for uncategorised, or null for everything. */
export type LibraryCategoryFilter = string | null;

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
  list(search?: string, categoryId: LibraryCategoryFilter = null, page = 1, perPage = 60): Observable<Paged<LibraryItem>> {
    return this.api.getPaged<LibraryItem>(Endpoints.library.list, {
      search,
      categoryId: categoryId ?? undefined,
      page,
      perPage,
    });
  }

  /** The whole shelf, for management. */
  listManage(search?: string, categoryId: LibraryCategoryFilter = null, page = 1, perPage = 200): Observable<Paged<LibraryItem>> {
    return this.api.getPaged<LibraryItem>(Endpoints.library.manageList, {
      search,
      categoryId: categoryId ?? undefined,
      page,
      perPage,
    });
  }

  // --- categories ---------------------------------------------------------

  /**
   * The categories with their item counts. By default the counts cover only
   * what the caller can see (matching the browse list); `scope: 'all'` — the
   * manage page — counts the whole shelf for a library manager.
   */
  categories(scope?: 'all'): Observable<LibraryCategoryList> {
    return this.api.getPaged<LibraryCategory>(Endpoints.library.categories, scope ? { scope } : undefined).pipe(
      map((paged) => {
        const meta = paged.meta as unknown as Record<string, unknown> | undefined;
        const raw = Number(meta?.['uncategorisedCount'] ?? 0);
        return {
          categories: paged.items,
          uncategorisedCount: Number.isFinite(raw) ? raw : 0,
        };
      }),
    );
  }

  createCategory(input: LibraryCategoryInput): Observable<LibraryCategory> {
    return this.api.post<LibraryCategory>(Endpoints.library.categories, input);
  }

  updateCategory(id: string, input: LibraryCategoryInput): Observable<LibraryCategory> {
    return this.api.put<LibraryCategory>(Endpoints.library.category(id), input);
  }

  deleteCategory(id: string): Observable<void> {
    return this.api.delete<void>(Endpoints.library.category(id));
  }

  upload(file: File, input: LibraryUploadInput): Observable<UploadProgress<LibraryItem>> {
    const form = new FormData();
    form.append('file', file, file.name);
    form.append('title', input.title);
    if (input.description) {
      form.append('description', input.description);
    }
    if (input.categoryId) {
      form.append('categoryId', input.categoryId);
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
      categoryId: input.categoryId ?? null,
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
