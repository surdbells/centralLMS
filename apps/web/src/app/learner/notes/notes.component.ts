import { Component, OnDestroy, OnInit, inject, signal } from '@angular/core';
import { TopComponent } from '../../partials/top/top.component';
import { Router } from '@angular/router';
import { HotToastService } from '@ngneat/hot-toast';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { TuiIcon, TuiLoader } from '@taiga-ui/core';
import { Subject, takeUntil } from 'rxjs';
import { ApiError, type PageMeta } from '../../core/api';
import { NotesService, type Note } from '../../services/notes.service';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';

export type { Note };

/**
 * The notes somebody made while working through a course.
 *
 * **The screen fetched them and drew none of them.** It asked the legacy
 * `UserNotes` endpoint on load, assigned the answer to `notes`, and its
 * template was a heading, a back arrow and a spinner — twenty-four lines with
 * no list in them. Every note anybody has ever written here has been
 * invisible since the feature was added, and the fetch that proved the data
 * was there is what made it look finished.
 *
 * The API has always been able to list, search, pin, edit and delete them.
 * This screen now does.
 */
@Component({
  selector: 'app-notes',
  standalone: true,
  imports: [
    TopComponent,
    CommonModule,
    FormsModule,
    TuiIcon,
    TuiLoader,
    TranslatePipe,
  ],
  templateUrl: './notes.component.html',
  styleUrl: './notes.component.css',
})
export class NotesComponent implements OnInit, OnDestroy {
  private readonly router = inject(Router);
  private readonly toast = inject(HotToastService);
  private readonly notesService = inject(NotesService);
  private readonly i18n = inject(I18nService);

  private readonly destroy$ = new Subject<void>();

  public readonly collapsed = signal(true);

  notes: Note[] = [];
  meta: PageMeta = { total: 0, page: 1, perPage: 10, totalPages: 0 };

  searchQuery = '';
  filterPinned = false;

  /** The note open for editing, or null. */
  editingNote: Note | null = null;
  draft = { title: '', content: '' };

  ui_controls = {
    is_loading: false,
  };

  ngOnInit(): void {
    this.get_notes();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // --- reading ------------------------------------------------------------

  get_notes(page = 1, perPage = this.meta.perPage): void {
    this.ui_controls.is_loading = true;

    this.notesService
      .list({
        page,
        perPage,
        search: this.searchQuery.trim() === '' ? undefined : this.searchQuery.trim(),
        // Undefined rather than false: `pinned=false` asks for the notes that
        // are *not* pinned, which is a different question from "all of them".
        pinned: this.filterPinned ? true : undefined,
      })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: ({ items, meta }) => {
          this.notes = items;
          this.meta = meta;
          this.ui_controls.is_loading = false;
        },
        error: (error: ApiError) => {
          // The spinner stops either way. It used to run for ever on a
          // failure, which on a screen that drew nothing was indistinguishable
          // from working.
          this.ui_controls.is_loading = false;
          this.error_notification(error.message);
        },
      });
  }

  search(): void {
    this.get_notes(1);
  }

  togglePinnedFilter(): void {
    this.filterPinned = !this.filterPinned;
    this.get_notes(1);
  }

  goToPage(page: number): void {
    if (page >= 1 && page <= this.meta.totalPages) {
      this.get_notes(page);
    }
  }

  // --- writing ------------------------------------------------------------

  togglePin(note: Note): void {
    this.notesService
      .pin(note.id, !note.isPinned)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (updated) => {
          note.isPinned = updated.isPinned;

          // A pinned note sorts to the top, so the list it is in has changed.
          if (this.filterPinned) {
            this.get_notes(this.meta.page);
          }
        },
        error: (error: ApiError) => this.error_notification(error.message),
      });
  }

  edit(note: Note): void {
    this.editingNote = note;
    this.draft = { title: note.title, content: note.content };
  }

  cancelEdit(): void {
    this.editingNote = null;
    this.draft = { title: '', content: '' };
  }

  saveEdit(): void {
    const note = this.editingNote;

    if (note === null) {
      return;
    }

    if (this.draft.content.trim() === '') {
      this.error_notification(this.i18n.t('notes.empty_note_error'));

      return;
    }

    this.notesService
      .update(note.id, { title: this.draft.title, content: this.draft.content })
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (updated) => {
          note.title = updated.title;
          note.content = updated.content;
          this.cancelEdit();
          this.success_notification(this.i18n.t('notes.saved'));
        },
        error: (error: ApiError) => this.error_notification(error.message),
      });
  }

  remove(note: Note): void {
    this.notesService
      .delete(note.id)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.success_notification(this.i18n.t('notes.deleted'));
          // The page it was on, unless it was the only thing left there.
          this.get_notes(this.notes.length === 1 ? Math.max(1, this.meta.page - 1) : this.meta.page);
        },
        error: (error: ApiError) => this.error_notification(error.message),
      });
  }

  // --- presentation -------------------------------------------------------

  /** Where in a lesson the note was taken, as a timestamp. */
  positionOf(note: Note): string | null {
    if (note.positionSeconds === null) {
      return null;
    }

    const minutes = Math.floor(note.positionSeconds / 60);
    const seconds = Math.floor(note.positionSeconds % 60);

    return `${minutes}:${String(seconds).padStart(2, '0')}`;
  }

  /** The course and lesson a note belongs to, where the API knows them. */
  contextOf(note: Note): string {
    return [note.courseTitle, note.moduleTitle, note.lessonTitle]
      .filter((part): part is string => part !== null && part !== '')
      .join(' · ');
  }

  goBack(): void {
    void this.router.navigate(['/account']);
  }

  error_notification(message: string): void {
    this.toast.error(message);
  }

  success_notification(message: string): void {
    this.toast.success(message);
  }
}
