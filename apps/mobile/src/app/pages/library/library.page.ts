import { Component, DestroyRef, OnInit, inject, signal } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { Subject, of } from 'rxjs';
import { catchError, switchMap, tap } from 'rxjs/operators';
import { IonContent, IonIcon, IonSkeletonText, IonRefresher, IonRefresherContent } from '@ionic/angular/standalone';
import { LibraryItem, LibraryService } from '../../core/library.service';
import { fileSize, timeAgo } from '../../core/ui';
import { BrandHeaderComponent } from '../../components/brand-header/brand-header.component';
import { EmptyStateComponent } from '../../components/empty-state/empty-state.component';
import { SearchComponent } from '../../components/search/search.component';
import { LibraryViewerComponent } from '../../components/library-viewer/library-viewer.component';

@Component({
  selector: 'app-library',
  standalone: true,
  imports: [
    IonContent, IonIcon, IonSkeletonText, IonRefresher, IonRefresherContent,
    BrandHeaderComponent, EmptyStateComponent, SearchComponent, LibraryViewerComponent,
  ],
  template: `
    <ion-content [fullscreen]="true">
      <ion-refresher slot="fixed" (ionRefresh)="reload($event)">
        <ion-refresher-content></ion-refresher-content>
      </ion-refresher>

      <app-brand-header title="Library" subtitle="Files shared with you" back></app-brand-header>

      <div class="pad">
        <app-search placeholder="Search the library" (search)="onSearch($event)"></app-search>

        @if (loading()) {
          @for (i of [1,2,3,4]; track i) {
            <div class="lib-row skel">
              <ion-skeleton-text animated style="width:46px;height:46px;border-radius:12px;"></ion-skeleton-text>
              <div style="flex:1">
                <ion-skeleton-text animated style="width:60%;height:13px;border-radius:5px;"></ion-skeleton-text>
                <ion-skeleton-text animated style="width:35%;height:9px;margin-top:9px;border-radius:4px;"></ion-skeleton-text>
              </div>
            </div>
          }
        } @else if (failed()) {
          <app-empty-state
            icon="cloud-offline-outline"
            title="Couldn't load the library"
            message="Check your connection and try again."
            actionLabel="Try again"
            (action)="reload()"></app-empty-state>
        } @else if (!items().length) {
          <app-empty-state
            icon="folder-open-outline"
            [title]="query() ? 'No matches' : 'Nothing shared yet'"
            [message]="query() ? 'No files match “' + query() + '”.' : 'Files your organisation shares with you will appear here.'"></app-empty-state>
        } @else {
          @for (f of items(); track f.id) {
            <button type="button" class="lib-row tappable rise" (click)="open(f)">
              <div class="lib-ic" [attr.data-kind]="f.viewKind"><ion-icon [name]="icon(f)" aria-hidden="true"></ion-icon></div>
              <div class="lib-body">
                <p class="lib-title">{{ f.title }}</p>
                <p class="lib-meta">{{ label(f) }}</p>
              </div>
              @if (f.viewable) {
                <ion-icon name="chevron-forward" class="lib-chev" aria-hidden="true"></ion-icon>
              } @else {
                <span class="lib-prep">Preparing</span>
              }
            </button>
          }
        }
      </div>
    </ion-content>

    <app-library-viewer [item]="selected()" (closed)="selected.set(null)"></app-library-viewer>
  `,
  styles: [`
    .pad { padding: 14px 16px calc(16px + env(safe-area-inset-bottom)); display: grid; gap: 10px; }
    .lib-row {
      width: 100%; display: flex; align-items: center; gap: 13px; text-align: left;
      background: var(--app-surface); border: var(--app-card-border); border-radius: var(--app-r);
      padding: 12px; box-shadow: var(--app-shadow); cursor: pointer;
    }
    .lib-row.skel { box-shadow: none; border: 0; cursor: default; }
    .lib-ic { flex: 0 0 auto; width: 46px; height: 46px; border-radius: var(--app-r-sm); display: grid; place-items: center; background: var(--app-tag-bg); color: var(--app-tag-ink); }
    .lib-ic ion-icon { font-size: 22px; }
    .lib-ic[data-kind="video"] { background: color-mix(in srgb, var(--ion-color-danger) 14%, transparent); color: var(--ion-color-danger); }
    .lib-ic[data-kind="audio"] { background: color-mix(in srgb, var(--ion-color-success) 15%, transparent); color: var(--ion-color-success); }
    .lib-ic[data-kind="image"] { background: color-mix(in srgb, var(--ion-color-warning) 16%, transparent); color: var(--ion-color-warning); }
    .lib-body { flex: 1; min-width: 0; }
    .lib-title { margin: 0; font-weight: 600; font-size: .95rem; color: var(--app-ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .lib-meta { margin: 3px 0 0; font-size: .77rem; color: var(--app-muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .lib-chev { flex: none; color: var(--app-muted); font-size: 18px; }
    .lib-prep { flex: none; font-size: .68rem; font-weight: 700; color: var(--ion-color-warning); background: color-mix(in srgb, var(--ion-color-warning) 14%, transparent); padding: 3px 8px; border-radius: 999px; }
  `],
})
export class LibraryPage implements OnInit {
  private readonly library = inject(LibraryService);
  private readonly destroyRef = inject(DestroyRef);

  readonly loading = signal(true);
  readonly failed = signal(false);
  readonly query = signal('');
  readonly items = signal<LibraryItem[]>([]);
  readonly selected = signal<LibraryItem | null>(null);

  private readonly load$ = new Subject<CustomEvent | undefined>();

  ngOnInit(): void {
    this.load$
      .pipe(
        tap((ev) => { if (!ev) { this.loading.set(true); } this.failed.set(false); }),
        switchMap((ev) =>
          this.library.list(this.query().trim() || undefined).pipe(
            tap({ error: () => this.failed.set(true) }),
            catchError(() => of([] as LibraryItem[])),
            tap(() => (ev?.target as { complete?: () => void })?.complete?.()),
          ),
        ),
        takeUntilDestroyed(this.destroyRef),
      )
      .subscribe((rows) => {
        this.items.set(rows);
        this.loading.set(false);
      });

    this.load$.next(undefined);
  }

  onSearch(value: string): void {
    this.query.set(value ?? '');
    this.load$.next(undefined);
  }

  reload(ev?: CustomEvent): void {
    this.load$.next(ev);
  }

  open(item: LibraryItem): void {
    if (item.viewable) {
      this.selected.set(item);
    }
  }

  icon(f: LibraryItem): string {
    switch (f.viewKind) {
      case 'video': return 'videocam-outline';
      case 'audio': return 'musical-notes-outline';
      case 'image': return 'image-outline';
      case 'pdf': return f.kind === 'document' ? 'document-outline' : 'document-text-outline';
      default: return 'document-outline';
    }
  }

  label(f: LibraryItem): string {
    const parts = [
      (f.extension || f.kind || '').toUpperCase(),
      fileSize(f.fileSize),
    ].filter(Boolean);
    if (f.createdAt) {
      parts.push(timeAgo(f.createdAt));
    }
    return parts.join(' · ');
  }
}
