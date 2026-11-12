import { Subscription } from 'rxjs';
import { ApiError } from '../core/api';
import type { LibraryItem, LibraryKind, LibraryUploadInput, LibraryService } from './library.service';

// ---------------------------------------------------------------------------
// What the library accepts — mirrors the API's MediaKind (extensions + limits)
// so a file is refused here, with a reason, before any bytes are sent.
// ---------------------------------------------------------------------------

const EXTENSIONS: Record<LibraryKind, readonly string[]> = {
  video: ['mp4', 'webm', 'mov', 'avi', 'mkv', 'wmv', 'flv', 'm4v'],
  audio: ['mp3', 'wav', 'ogg', 'aac', 'm4a', 'flac', 'wma'],
  pdf: ['pdf'],
  image: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'],
  document: ['doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'csv'],
};

const MAX_BYTES: Record<LibraryKind, number> = {
  video: 2_147_483_648, // 2 GB
  audio: 524_288_000, // 500 MB
  pdf: 104_857_600, // 100 MB
  document: 52_428_800, // 50 MB
  image: 10_485_760, // 10 MB
};

/** For the file input's `accept` attribute. */
export const LIBRARY_ACCEPT = Object.values(EXTENSIONS)
  .flat()
  .map((ext) => `.${ext}`)
  .join(',');

/** How many files go up at once. */
export const UPLOAD_CONCURRENCY = 3;

export const KIND_ICONS: Record<LibraryKind, string> = {
  video: 'bi-play-btn',
  audio: 'bi-music-note-beamed',
  pdf: 'bi-file-earmark-pdf',
  document: 'bi-file-earmark-text',
  image: 'bi-image',
};

export const KIND_CLASSES: Record<LibraryKind, string> = {
  video: 'k-video',
  audio: 'k-audio',
  pdf: 'k-pdf',
  document: 'k-doc',
  image: 'k-image',
};

export const KIND_LABEL_KEYS: Record<LibraryKind, string> = {
  video: 'library.kind_video',
  audio: 'library.kind_audio',
  pdf: 'library.kind_pdf',
  document: 'library.kind_document',
  image: 'library.kind_image',
};

/** A file the library will not take, and why (an i18n key + its params). */
export interface RejectedFile {
  name: string;
  reasonKey: string;
  params?: Record<string, string>;
}

export function humanSize(bytes: number): string {
  if (!bytes || bytes <= 0) {
    return '';
  }
  const units = ['B', 'KB', 'MB', 'GB'];
  const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
  const value = bytes / Math.pow(1024, i);
  return `${i === 0 ? value.toFixed(0) : value.toFixed(1).replace(/\.0$/, '')} ${units[i]}`;
}

export function extensionOf(name: string): string {
  const base = name.split(/[\\/]/).pop() ?? name;
  const dot = base.lastIndexOf('.');
  return dot > 0 ? base.slice(dot + 1).toLowerCase() : '';
}

export function kindForExtension(ext: string): LibraryKind | null {
  for (const kind of Object.keys(EXTENSIONS) as LibraryKind[]) {
    if (EXTENSIONS[kind].includes(ext)) {
      return kind;
    }
  }
  return null;
}

/** Either the file's kind, or the reason it is refused. */
export function checkFile(file: File): { kind: LibraryKind } | { rejected: RejectedFile } {
  const ext = extensionOf(file.name);
  if (!ext) {
    return { rejected: { name: file.name, reasonKey: 'library.reject_no_extension' } };
  }
  if (ext === 'zip') {
    return { rejected: { name: file.name, reasonKey: 'library.reject_scorm' } };
  }
  const kind = kindForExtension(ext);
  if (!kind) {
    return { rejected: { name: file.name, reasonKey: 'library.reject_type', params: { ext } } };
  }
  if (file.size <= 0) {
    return { rejected: { name: file.name, reasonKey: 'library.reject_empty' } };
  }
  if (file.size > MAX_BYTES[kind]) {
    return {
      rejected: {
        name: file.name,
        reasonKey: 'library.reject_too_large',
        params: { size: humanSize(file.size), max: humanSize(MAX_BYTES[kind]) },
      },
    };
  }
  return { kind };
}

/** A readable default title from a filename: no extension, no underscores. */
export function titleFromFilename(name: string): string {
  const base = (name.split(/[\\/]/).pop() ?? name).replace(/\.[^.]+$/, '');
  const tidy = base.replace(/[_]+/g, ' ').replace(/\s*-\s*/g, ' - ').replace(/\s+/g, ' ').trim();
  const title = tidy || base || name;
  return (title.charAt(0).toUpperCase() + title.slice(1)).slice(0, 255);
}

/** The most useful message out of a failed upload. */
export function uploadErrorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const raw = error.details['violations'];
    if (raw && typeof raw === 'object') {
      for (const value of Object.values(raw as Record<string, unknown>)) {
        if (Array.isArray(value) && value.length) {
          return String(value[0]);
        }
        if (typeof value === 'string' && value) {
          return value;
        }
      }
    }
    if (error.status === 413) {
      return fallback;
    }
    return error.message || fallback;
  }
  return fallback;
}

// ---------------------------------------------------------------------------
// The batch queue
// ---------------------------------------------------------------------------

export type QueueStatus = 'queued' | 'uploading' | 'done' | 'failed' | 'cancelled';

export interface QueueEntry {
  readonly key: string;
  readonly file: File;
  readonly kind: LibraryKind;
  title: string;
  description: string;
  showDescription: boolean;
  status: QueueStatus;
  progress: number;
  error: string | null;
  result: LibraryItem | null;
  /**
   * Cancelled after every byte was sent: the server may already have stored
   * it, so it is not retried (that would leave a duplicate).
   */
  maybeSaved?: boolean;
}

/** What every file in the batch shares. */
export type BatchSettings = Omit<LibraryUploadInput, 'title' | 'description'>;

export interface QueueSummary {
  done: number;
  failed: number;
  cancelled: number;
}

/**
 * Uploads a batch of files to the library, a few at a time.
 *
 * Plain state plus callbacks — the component owns rendering; this owns the
 * concurrency, per-file progress, retry and cancellation.
 */
export class LibraryUploadQueue {
  entries: QueueEntry[] = [];
  private settings: BatchSettings | null = null;
  /** When set, the current run uploads only these entries (a retry). */
  private only: Set<string> | null = null;
  private readonly running = new Map<string, Subscription>();
  private seq = 0;

  constructor(
    private readonly library: LibraryService,
    private readonly fallbackError: () => string,
    private readonly onChange: () => void,
    private readonly onFinished: (summary: QueueSummary) => void,
  ) {}

  /** Adds files; returns the ones refused (duplicates included). */
  add(files: File[]): RejectedFile[] {
    const rejected: RejectedFile[] = [];
    for (const file of files) {
      const key = `${file.name}|${file.size}|${file.lastModified}`;
      if (this.entries.some((e) => e.key.startsWith(key + '|') && e.status !== 'done')) {
        rejected.push({ name: file.name, reasonKey: 'library.reject_duplicate' });
        continue;
      }
      const checked = checkFile(file);
      if ('rejected' in checked) {
        rejected.push(checked.rejected);
        continue;
      }
      this.entries.push({
        key: `${key}|${++this.seq}`,
        file,
        kind: checked.kind,
        title: titleFromFilename(file.name),
        description: '',
        showDescription: false,
        status: 'queued',
        progress: 0,
        error: null,
        result: null,
      });
    }
    this.onChange();
    return rejected;
  }

  remove(entry: QueueEntry): void {
    if (entry.status === 'uploading') {
      return;
    }
    this.entries = this.entries.filter((e) => e !== entry);
    this.onChange();
  }

  /** Drops finished entries — for starting a fresh batch after one completes. */
  clearFinished(): void {
    this.entries = this.entries.filter((e) => e.status !== 'done');
    this.onChange();
  }

  reset(): void {
    this.cancelRemaining();
    this.settings = null;
    this.entries = [];
    this.onChange();
  }

  get busy(): boolean {
    return this.running.size > 0;
  }

  get started(): boolean {
    return this.entries.some((e) => e.status !== 'queued');
  }

  count(status: QueueStatus): number {
    return this.entries.filter((e) => e.status === status).length;
  }

  /** Overall progress across the batch, 0–100. */
  get overall(): number {
    const active = this.entries.filter((e) => e.status !== 'cancelled');
    if (!active.length) {
      return 0;
    }
    const total = active.reduce((sum, e) => sum + e.file.size, 0) || 1;
    const sent = active.reduce(
      (sum, e) => sum + (e.status === 'done' || e.status === 'failed' ? e.file.size : (e.file.size * e.progress) / 100),
      0,
    );
    return Math.round((sent / total) * 100);
  }

  /** Every queued file whose title is filled in. */
  get ready(): boolean {
    const queued = this.entries.filter((e) => e.status === 'queued');
    return queued.length > 0 && queued.every((e) => e.title.trim() !== '');
  }

  start(settings: BatchSettings): void {
    this.settings = settings;
    this.only = null;
    this.pump();
  }

  /** Failed or cancelled entries that may safely be sent again. */
  get retryable(): QueueEntry[] {
    return this.entries.filter((e) => (e.status === 'failed' || e.status === 'cancelled') && !e.maybeSaved);
  }

  /**
   * Re-sends the failed and cancelled files — and only those: files added
   * since stay queued for "Upload all". `settings` replaces the batch's, so
   * a category or audience fixed after a failure is used.
   */
  retryFailed(settings?: BatchSettings): void {
    if (this.busy) {
      return;
    }
    if (settings) {
      this.settings = settings;
    }
    const retry = this.retryable;
    if (!retry.length || !this.settings) {
      return;
    }
    this.only = new Set(retry.map((e) => e.key));
    for (const e of retry) {
      e.status = 'queued';
      e.progress = 0;
      e.error = null;
    }
    this.pump();
  }

  /** Stops what has not finished: queued files are skipped, in-flight ones aborted. */
  cancelRemaining(): void {
    const wasBusy = this.busy;
    for (const [key, sub] of this.running) {
      const entry = this.entries.find((e) => e.key === key);
      // Fully sent: the server is storing it and will likely finish regardless.
      if (entry && entry.progress >= 99) {
        entry.maybeSaved = true;
      }
      sub.unsubscribe();
      if (entry) {
        entry.status = 'cancelled';
        entry.progress = 0;
      }
    }
    this.running.clear();
    for (const e of this.entries) {
      if (e.status === 'queued' && this.settings && (!this.only || this.only.has(e.key))) {
        e.status = 'cancelled';
      }
    }
    this.only = null;
    this.onChange();
    if (wasBusy) {
      this.finish();
    }
  }

  private pump(): void {
    if (!this.settings) {
      return;
    }
    while (this.running.size < UPLOAD_CONCURRENCY) {
      const only = this.only;
      const next = this.entries.find((e) => e.status === 'queued' && (!only || only.has(e.key)));
      if (!next) {
        break;
      }
      this.begin(next, this.settings);
    }
    this.onChange();
    if (this.running.size === 0) {
      this.finish();
    }
  }

  private begin(entry: QueueEntry, settings: BatchSettings): void {
    entry.status = 'uploading';
    entry.progress = 0;
    entry.error = null;

    const settle = () => {
      this.running.delete(entry.key);
      this.pump();
    };

    const sub = this.library
      .upload(entry.file, {
        ...settings,
        title: entry.title.trim() || titleFromFilename(entry.file.name),
        description: entry.description.trim() || undefined,
      })
      .subscribe({
        next: (p) => {
          // The last few percent are the server storing the file.
          entry.progress = p.state === 'done' ? 100 : Math.min(p.progress, 99);
          if (p.state === 'done') {
            entry.status = 'done';
            entry.result = p.result ?? null;
          }
          this.onChange();
        },
        error: (err: unknown) => {
          entry.status = 'failed';
          entry.progress = 0;
          entry.error = uploadErrorMessage(err, this.fallbackError());
          settle();
        },
        complete: () => {
          if (entry.status === 'uploading') {
            entry.status = 'done';
            entry.progress = 100;
          }
          settle();
        },
      });

    // A synchronous error settles before the subscription is stored.
    if (entry.status === 'uploading') {
      this.running.set(entry.key, sub);
    }
  }

  private finish(): void {
    this.only = null;
    const summary: QueueSummary = {
      done: this.count('done'),
      failed: this.count('failed'),
      cancelled: this.count('cancelled'),
    };
    this.onChange();
    this.onFinished(summary);
  }
}
