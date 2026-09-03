import { Component, Input, OnChanges, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { DomSanitizer, type SafeResourceUrl } from '@angular/platform-browser';
import { LibraryService, type LibraryItem } from '../../library.service';

/**
 * Shows one library item inline, and does what can be done to keep it from
 * being saved.
 *
 * The requirement is that content is viewable but not downloadable. What a
 * server can enforce it does: the bytes come from a ticketed, inline-only URL,
 * so there is no link to a file and no download control. What only the client
 * can discourage, it discourages: the native download/print buttons are hidden
 * (a PDF is framed with the viewer toolbar off; a video and audio player is
 * given `controlsList="nodownload"` and no picture-in-picture), the right-click
 * menu and dragging are blocked, and the media is not selectable. A determined
 * viewer can still capture what their own screen shows — no web page can stop
 * that — but nothing here hands them the file.
 */
@Component({
  selector: 'app-library-viewer',
  standalone: true,
  imports: [CommonModule],
  templateUrl: './library-viewer.component.html',
  styleUrl: './library-viewer.component.scss',
})
export class LibraryViewerComponent implements OnChanges {
  @Input() item: LibraryItem | null = null;

  private readonly library = inject(LibraryService);
  private readonly sanitizer = inject(DomSanitizer);

  loading = false;
  error = false;
  /** For the media elements, which take a plain string. */
  url: string | null = null;
  /** For the PDF frame, which Angular requires be marked trusted. */
  safeUrl: SafeResourceUrl | null = null;

  ngOnChanges(): void {
    this.url = null;
    this.safeUrl = null;
    this.error = false;

    const item = this.item;
    if (item === null) {
      return;
    }

    if (!item.viewable) {
      // A document still converting has nothing to show yet.
      this.error = true;
      return;
    }

    this.loading = true;
    this.library.viewUrlFor(item).subscribe({
      next: (url) => {
        this.url = url;
        // Hide the browser PDF viewer's toolbar, which is where its download
        // and print buttons live.
        const framed = item.viewKind === 'pdf' ? `${url}#toolbar=0&navpanes=0&scrollbar=0&view=FitH` : url;
        this.safeUrl = this.sanitizer.bypassSecurityTrustResourceUrl(framed);
        this.loading = false;
      },
      error: () => {
        this.error = true;
        this.loading = false;
      },
    });
  }

  /** Swallows the context menu, so "Save as" is not one right-click away. */
  block(event: Event): boolean {
    event.preventDefault();
    return false;
  }
}
