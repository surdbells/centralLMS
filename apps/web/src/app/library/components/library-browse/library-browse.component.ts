import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HotToastService } from '@ngneat/hot-toast';
import { TopComponent } from '../../../partials/top/top.component';
import { LibraryViewerComponent } from '../library-viewer/library-viewer.component';
import { LibraryService, type LibraryItem, type LibraryKind } from '../../library.service';

/**
 * The digital library, as a learner sees it: the files shared with them.
 *
 * A grid of what they may see — nothing that is not shared with them ever
 * reaches here — and, on choosing one, an inline viewer that shows it without
 * offering a download.
 */
@Component({
  selector: 'app-library-browse',
  standalone: true,
  imports: [CommonModule, FormsModule, TopComponent, LibraryViewerComponent],
  templateUrl: './library-browse.component.html',
  styleUrl: './library-browse.component.scss',
})
export class LibraryBrowseComponent implements OnInit {
  private readonly library = inject(LibraryService);
  private readonly toast = inject(HotToastService);

  readonly loading = signal(true);
  readonly items = signal<LibraryItem[]>([]);
  readonly selected = signal<LibraryItem | null>(null);
  search = '';

  ngOnInit(): void {
    this.load();
  }

  load(): void {
    this.loading.set(true);
    this.library.list(this.search || undefined).subscribe({
      next: (paged) => {
        this.items.set(paged.items);
        this.loading.set(false);
      },
      error: () => {
        this.toast.error('The library could not be loaded.');
        this.loading.set(false);
      },
    });
  }

  open(item: LibraryItem): void {
    this.selected.set(item);
  }

  close(): void {
    this.selected.set(null);
  }

  icon(kind: LibraryKind): string {
    return {
      video: 'bi-play-btn',
      audio: 'bi-music-note-beamed',
      pdf: 'bi-file-earmark-pdf',
      document: 'bi-file-earmark-text',
      image: 'bi-image',
    }[kind];
  }

  kindClass(kind: LibraryKind): string {
    return {
      video: 'k-video',
      audio: 'k-audio',
      pdf: 'k-pdf',
      document: 'k-doc',
      image: 'k-image',
    }[kind];
  }

  kindLabel(kind: LibraryKind): string {
    return { video: 'Video', audio: 'Audio', pdf: 'PDF', document: 'Document', image: 'Image' }[kind];
  }

  size(bytes: number): string {
    if (bytes <= 0) {
      return '';
    }
    const units = ['B', 'KB', 'MB', 'GB'];
    const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
    return `${(bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
  }
}
