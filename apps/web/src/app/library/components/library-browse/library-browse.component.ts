import { Component, OnDestroy, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HotToastService } from '@ngneat/hot-toast';
import { Subscription } from 'rxjs';
import { TopComponent } from '../../../partials/top/top.component';
import { LibraryViewerComponent } from '../library-viewer/library-viewer.component';
import { LibraryService, type LibraryCategory, type LibraryItem, type LibraryKind } from '../../library.service';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';
import { SkeletonComponent } from '../../../shared/skeleton/skeleton.component';

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
  imports: [CommonModule, FormsModule, TopComponent, LibraryViewerComponent, TranslatePipe, SkeletonComponent],
  templateUrl: './library-browse.component.html',
  styleUrl: './library-browse.component.scss',
})
export class LibraryBrowseComponent implements OnInit, OnDestroy {
  private readonly library = inject(LibraryService);
  private readonly toast = inject(HotToastService);
  private readonly i18n = inject(I18nService);

  readonly loading = signal(true);
  readonly loadError = signal(false);
  readonly items = signal<LibraryItem[]>([]);
  readonly selected = signal<LibraryItem | null>(null);
  search = '';
  private listSub?: Subscription;

  // --- categories ---
  readonly categoriesLoading = signal(true);
  readonly allCategories = signal<LibraryCategory[]>([]);
  readonly uncategorisedCount = signal(0);
  /** null = everything, 'none' = uncategorised, otherwise a category id. */
  readonly categoryFilter = signal<string | null>(null);
  /** Only shelves with something on them for this learner. */
  readonly categories = computed(() => this.allCategories().filter((c) => c.itemCount > 0));
  readonly totalCount = computed(
    () => this.categories().reduce((sum, c) => sum + c.itemCount, 0) + this.uncategorisedCount(),
  );
  /** Chips are only worth showing once at least one category has files. */
  readonly showChips = computed(() => this.categories().length > 0);
  readonly activeCategoryName = computed(() => {
    const id = this.categoryFilter();
    return this.allCategories().find((c) => c.id === id)?.name ?? '';
  });

  ngOnInit(): void {
    this.load();
    this.loadCategories();
  }

  ngOnDestroy(): void {
    this.listSub?.unsubscribe();
  }

  load(): void {
    this.loading.set(true);
    this.loadError.set(false);
    this.listSub?.unsubscribe();
    this.listSub = this.library.list(this.search.trim() || undefined, this.categoryFilter()).subscribe({
      next: (paged) => {
        this.items.set(paged.items);
        this.loading.set(false);
      },
      error: () => {
        this.toast.error(this.i18n.t('library.load_error'));
        this.loadError.set(true);
        this.loading.set(false);
      },
    });
  }

  private loadCategories(): void {
    this.categoriesLoading.set(true);
    this.library.categories().subscribe({
      next: (list) => {
        this.allCategories.set(list.categories);
        this.uncategorisedCount.set(list.uncategorisedCount);
        this.categoriesLoading.set(false);
      },
      // Without categories the library still works — just without the chips.
      error: () => this.categoriesLoading.set(false),
    });
  }

  setCategoryFilter(filter: string | null): void {
    if (this.categoryFilter() === filter) {
      return;
    }
    this.categoryFilter.set(filter);
    this.load();
  }

  onSearchChange(): void {
    if (this.search === '') {
      this.load();
    }
  }

  clearFilters(): void {
    this.search = '';
    this.categoryFilter.set(null);
    this.load();
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
    const keys: Record<LibraryKind, string> = {
      video: 'library.kind_video',
      audio: 'library.kind_audio',
      pdf: 'library.kind_pdf',
      document: 'library.kind_document',
      image: 'library.kind_image',
    };
    return this.i18n.t(keys[kind]);
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
