import { Injectable, inject } from '@angular/core';
import { Observable, map, of, shareReplay } from 'rxjs';
import { catchError, tap } from 'rxjs/operators';
import { ApiClient, Endpoints, type Paged, type UploadProgress } from '../core/api';

export type { UploadProgress };

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/** What a file is. Decided by the API from the extension, never from the browser's mime type. */
export type MediaKind = 'video' | 'audio' | 'pdf' | 'scorm' | 'image' | 'document';

export interface MediaItem {
  id: string;
  kind: MediaKind;
  title: string;
  description: string | null;
  originalFilename: string;
  fileSize: number;
  mimeType: string;
  extension: string;
  durationSeconds: number | null;
  /** `M:SS` or `H:MM:SS`, already formatted. */
  duration: string | null;
  width: number | null;
  height: number | null;
  pageCount: number | null;
  scormVersion: string | null;
  metadata: Record<string, unknown>;
  rootMediaId: string | null;
  versionNumber: number;
  versionLabel: string | null;
  versionNotes: string | null;
  versionCount: number;
  isLatest: boolean;
  courseId: string | null;
  courseTitle: string | null;
  uploadedBy: string | null;
  uploaderName: string | null;
  createdAt: string | null;
  updatedAt: string | null;
  /** Where to fetch the bytes — always through the API, never a storage URL. */
  url: string;
}

/** What `POST /media/{id}/ticket` answers with. */
export interface MediaTicket {
  /** Path, including the ticket, relative to the API root. */
  url: string;
  ticket: string;
  expiresIn: number;
}

export interface MediaStatistics {
  total: number;
  byKind: Record<MediaKind, number>;
  bytesUsed: number;
  /** Null when the organisation has no storage limit set. */
  limitBytes: number | null;
  bytesRemaining: number | null;
  accepts: Record<MediaKind, { extensions: string[]; maxSize: number }>;
}

export interface MediaFilters {
  search?: string;
  kind?: MediaKind;
  courseId?: string;
  uploadedBy?: string;
  includeOldVersions?: boolean;
  page?: number;
  perPage?: number;
}

export interface UploadDetails {
  title?: string;
  description?: string;
  kind?: MediaKind;
  courseId?: string;
  versionLabel?: string;
  versionNotes?: string;
  /** Read from the file by the browser; the API stores them as display hints. */
  durationSeconds?: number;
  width?: number;
  height?: number;
  pageCount?: number;
}

export interface FileValidation {
  valid: boolean;
  error?: string;
  kind?: MediaKind;
}

/** Presentation only — an icon and a colour per kind. */
const APPEARANCE: Readonly<Record<MediaKind, { icon: string; colour: string; label: string }>> = {
  video: { icon: 'bi-camera-video-fill', colour: '#6366f1', label: 'Video' },
  audio: { icon: 'bi-music-note-beamed', colour: '#10b981', label: 'Audio' },
  pdf: { icon: 'bi-file-earmark-pdf-fill', colour: '#ef4444', label: 'PDF' },
  scorm: { icon: 'bi-box-seam-fill', colour: '#f59e0b', label: 'SCORM package' },
  image: { icon: 'bi-image-fill', colour: '#0ea5e9', label: 'Image' },
  document: { icon: 'bi-file-earmark-text-fill', colour: '#64748b', label: 'Document' },
};

// ---------------------------------------------------------------------------
// Service
// ---------------------------------------------------------------------------

/**
 * The media library.
 *
 * Two things worth knowing.
 *
 * **The upload rules come from the server.** `accepts` on the statistics
 * endpoint is generated from the same code that enforces them, so the
 * extensions and size limits shown to somebody choosing a file cannot drift
 * from the ones that will refuse it. This service used to carry its own copy
 * of that table.
 *
 * **A file's URL is an API path.** `item.url` is `/media/{id}/file`, served
 * with the caller's token checked on every fetch. The original service
 * redirected to a signed storage link, which then worked for anybody it was
 * forwarded to.
 */
@Injectable({ providedIn: 'root' })
export class MediaService {
  private readonly api = inject(ApiClient);

  /** Cached because it is fetched to render a file picker and never changes. */
  private acceptances$?: Observable<MediaStatistics['accepts']>;

  list(filters: MediaFilters = {}): Observable<Paged<MediaItem>> {
    return this.api.getPaged<MediaItem>(Endpoints.media.list, { ...filters });
  }

  show(id: string): Observable<MediaItem> {
    return this.api.get<MediaItem>(Endpoints.media.show(id));
  }

  statistics(): Observable<MediaStatistics> {
    return this.api.get<MediaStatistics>(Endpoints.media.statistics);
  }

  /** Uploads a file, reporting progress. */
  upload(file: File, details: UploadDetails = {}): Observable<UploadProgress<MediaItem>> {
    return this.api.upload<MediaItem>(Endpoints.media.upload, this.formFor(file, details));
  }

  /**
   * Replaces a file, keeping the old one.
   *
   * The previous version stays readable, so a course pointing at it is not
   * broken by somebody uploading a revision.
   */
  uploadVersion(id: string, file: File, details: UploadDetails = {}): Observable<UploadProgress<MediaItem>> {
    return this.api.upload<MediaItem>(Endpoints.media.uploadVersion(id), this.formFor(file, details));
  }

  /** Every version of a file, newest first. */
  versions(id: string): Observable<MediaItem[]> {
    return this.api.get<MediaItem[]>(Endpoints.media.versions(id));
  }

  /**
   * Brings an older version back as the newest one.
   *
   * The chain is not rewritten — a new version is written whose bytes are the
   * old one's — so the history still shows what was current in between, and
   * the row that was restored from stays where it was.
   */
  restore(id: string): Observable<MediaItem> {
    return this.api.post<MediaItem>(Endpoints.media.restore(id), {});
  }

  /** Title, description, course and version notes. Never the file itself. */
  update(id: string, changes: Partial<Pick<MediaItem, 'title' | 'description' | 'courseId' | 'versionLabel' | 'versionNotes'>>): Observable<MediaItem> {
    return this.api.put<MediaItem>(Endpoints.media.update(id), changes);
  }

  delete(id: string, allVersions = false): Observable<void> {
    return this.api.delete<void>(Endpoints.media.delete(id), { allVersions });
  }

  /**
   * A URL for an `<img>`, a `<video>` or a link somebody clicks.
   *
   * Asynchronous because it has to be. The bytes are served by the API, and a
   * browser fetching a `src` sends no Authorization header — so this used to
   * return a URL that came back 401 every time. Nothing in the media library
   * could be previewed, no download link worked, and no lesson whose content
   * came from the library would play, in any browser, ever.
   *
   * So the URL is fetched rather than constructed: the API mints a ticket for
   * this one file, good for a quarter of an hour, and the URL carries it.
   * Ranged requests still work, which matters — a blob would have made a
   * player download a two-hour recording before it could show the first
   * frame, and seeking impossible.
   */
  fileUrl(item: MediaItem | { id: string }): Observable<string> {
    return this.api
      .post<MediaTicket>(Endpoints.media.ticket(item.id), {})
      .pipe(map((ticket) => this.api.urlFor(ticket.url.replace(/^\/v1/, ''))));
  }

  /**
   * How a lesson refers to a library file.
   *
   * Stored in `lessons.content_url`, which is free text and has to hold
   * something stable. Not a fetchable URL: the bytes need a ticket that lasts
   * minutes, so writing one here would put a credential in the database and
   * leave the lesson broken a quarter of an hour later.
   */
  static referenceFor(mediaId: string): string {
    return `media:${mediaId}`;
  }

  /**
   * The file a lesson points at, or null if it points somewhere else.
   *
   * Recognises the `/v1/media/{id}/file` URLs written before references
   * existed, so lessons authored against the old shape keep playing.
   */
  static idFromReference(reference: string | null): string | null {
    if (reference === null) {
      return null;
    }

    const match = /^(?:media:|.*\/v1\/media\/)([0-9a-f-]{36})(?:\/file)?/i.exec(reference.trim());

    return match === null ? null : match[1];
  }

  /**
   * A URL for whatever a lesson points at.
   *
   * A library file gets a ticket; anything else — a YouTube embed, a link
   * somebody pasted — is passed through untouched.
   */
  resolve(reference: string | null): Observable<string | null> {
    const id = MediaService.idFromReference(reference);

    return id === null ? of(reference) : this.fileUrl({ id });
  }

  /** The same URL, asking the browser to save the file rather than show it. */
  downloadUrl(item: MediaItem | { id: string }): Observable<string> {
    return this.fileUrl(item).pipe(map((url) => `${url}&download=1`));
  }

  /**
   * Opens a file in a new tab.
   *
   * The tab is opened first and pointed at the URL afterwards. Opening it
   * once the ticket arrives would be a popup the browser did not see a
   * click for, and every blocker stops those — which is the same nothing
   * happening that the missing ticket used to cause.
   */
  openInNewTab(item: MediaItem | { id: string }, download = false): Observable<boolean> {
    const tab = window.open('', '_blank', 'noopener');
    const url = download ? this.downloadUrl(item) : this.fileUrl(item);

    return url.pipe(
      map((resolved) => {
        if (tab === null) {
          return false;
        }

        tab.location.href = resolved;

        return true;
      }),
      tap({ error: () => tab?.close() }),
    );
  }
  // --- what may be uploaded ------------------------------------------------

  /**
   * The extensions and size limits the API will accept.
   *
   * Fetched rather than hardcoded — see the class docblock.
   */
  acceptedTypes(): Observable<MediaStatistics['accepts']> {
    this.acceptances$ ??= this.statistics().pipe(
      map((statistics) => statistics.accepts),
      // A picker that cannot reach the server still has to open. The upload
      // itself is refused by the API either way, so the cost of guessing wrong
      // here is a late error message rather than a bad file.
      catchError(() => of({} as MediaStatistics['accepts'])),
      shareReplay({ bufferSize: 1, refCount: false }),
    );

    return this.acceptances$;
  }

  /**
   * Checks a file against the rules the server just gave us.
   *
   * Advisory: it saves somebody a two-gigabyte upload that was always going to
   * be refused. The API checks again, from the extension rather than the mime
   * type the browser reports, because the browser is told that by whatever is
   * doing the sending.
   */
  validate(file: File, accepts: MediaStatistics['accepts']): FileValidation {
    const kind = this.kindOf(file, accepts);

    if (kind === null) {
      const allowed = Object.values(accepts).flatMap((a) => a.extensions);

      return {
        valid: false,
        error: allowed.length === 0
          ? 'That file type is not accepted.'
          : `That file type is not accepted. Try: ${allowed.slice(0, 12).join(', ')}.`,
      };
    }

    if (file.size === 0) {
      return { valid: false, error: 'The file is empty.' };
    }

    const limit = accepts[kind].maxSize;

    if (file.size > limit) {
      return {
        valid: false,
        error: `${this.labelFor(kind)} files are limited to ${this.formatFileSize(limit)}.`,
      };
    }

    return { valid: true, kind };
  }

  kindOf(file: File, accepts: MediaStatistics['accepts']): MediaKind | null {
    // The last extension, and only the last: `report.pdf.exe` is an exe.
    const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

    if (extension === '') {
      return null;
    }

    for (const [kind, rules] of Object.entries(accepts)) {
      if (rules.extensions.includes(extension)) {
        return kind as MediaKind;
      }
    }

    return null;
  }

  // --- presentation --------------------------------------------------------

  iconFor(kind: MediaKind): string {
    return APPEARANCE[kind]?.icon ?? 'bi-file-earmark';
  }

  colourFor(kind: MediaKind): string {
    return APPEARANCE[kind]?.colour ?? '#64748b';
  }

  labelFor(kind: MediaKind): string {
    return APPEARANCE[kind]?.label ?? kind;
  }

  formatFileSize(bytes: number): string {
    if (!Number.isFinite(bytes) || bytes <= 0) {
      return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const exponent = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);

    return `${parseFloat((bytes / Math.pow(1024, exponent)).toFixed(2))} ${units[exponent]}`;
  }

  private formFor(file: File, details: UploadDetails): FormData {
    const form = new FormData();
    form.append('file', file);

    for (const [key, value] of Object.entries(details)) {
      if (value !== undefined && value !== null && value !== '') {
        form.append(key, String(value));
      }
    }

    return form;
  }
}
