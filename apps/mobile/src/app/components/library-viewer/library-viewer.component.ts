import {
  Component, ChangeDetectionStrategy, effect, inject, input, output, signal, computed,
} from '@angular/core';
import { DomSanitizer, SafeResourceUrl } from '@angular/platform-browser';
import { IonIcon, IonSpinner } from '@ionic/angular/standalone';
import { LibraryItem, LibraryService } from '../../core/library.service';

/**
 * A full-screen viewer for one library file. The bytes are fetched as a
 * ticketed inline URL and shown in the right element for the file's kind —
 * image, video, audio or a PDF frame. Never a download. Set `[item]` to open;
 * emits `(closed)` when dismissed.
 */
@Component({
  selector: 'app-library-viewer',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [IonIcon, IonSpinner],
  template: `
    @if (item(); as it) {
      <div class="lv" role="dialog" aria-modal="true" [attr.aria-label]="it.title">
        <div class="lv-bar">
          <button type="button" class="lv-close" aria-label="Close" (click)="closed.emit()">
            <ion-icon name="close" aria-hidden="true"></ion-icon>
          </button>
          <span class="lv-title">{{ it.title }}</span>
        </div>

        <div class="lv-stage">
          @if (loading()) {
            <ion-spinner name="crescent"></ion-spinner>
          } @else if (!it.viewable) {
            <div class="lv-msg">
              <ion-icon name="hourglass-outline" aria-hidden="true"></ion-icon>
              <p>This document is being prepared for viewing. Check back shortly.</p>
            </div>
          } @else if (failed()) {
            <div class="lv-msg">
              <ion-icon name="cloud-offline-outline" aria-hidden="true"></ion-icon>
              <p>Couldn't open this file. Try again.</p>
            </div>
          } @else if (url()) {
            @if (url(); as u) {
              @switch (it.viewKind) {
                @case ('image') { <img [src]="u" [alt]="it.title" class="lv-img" /> }
                @case ('video') { <video [src]="u" class="lv-video" controls playsinline></video> }
                @case ('audio') {
                  <div class="lv-audio">
                    <ion-icon name="musical-notes-outline" aria-hidden="true"></ion-icon>
                    <p class="lv-audio-title">{{ it.title }}</p>
                    <audio [src]="u" controls></audio>
                  </div>
                }
                @case ('pdf') { <iframe [src]="safeUrl()" class="lv-frame" title="Document"></iframe> }
              }
            }
          }
        </div>
      </div>
    }
  `,
  styles: [`
    .lv {
      position: fixed; inset: 0; z-index: 20000; display: flex; flex-direction: column;
      background: #0b0a14;
    }
    .lv-bar {
      display: flex; align-items: center; gap: 10px; flex: none;
      padding: calc(env(safe-area-inset-top) + 8px) 12px 8px;
      color: #fff; background: rgba(0, 0, 0, 0.4);
    }
    .lv-close { flex: none; border: 0; background: rgba(255,255,255,.14); color: #fff; width: 38px; height: 38px; border-radius: 50%; display: grid; place-items: center; cursor: pointer; }
    .lv-close ion-icon { font-size: 20px; }
    .lv-title { min-width: 0; font-weight: 600; font-size: .96rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    .lv-stage { flex: 1; min-height: 0; display: grid; place-items: center; padding: 8px; overflow: auto; }
    .lv-stage ion-spinner { --color: #fff; width: 34px; height: 34px; }
    .lv-img { max-width: 100%; max-height: 100%; object-fit: contain; }
    .lv-video { max-width: 100%; max-height: 100%; }
    .lv-frame { width: 100%; height: 100%; border: 0; background: #fff; }
    .lv-audio { text-align: center; color: #fff; padding: 24px; }
    .lv-audio ion-icon { font-size: 56px; opacity: .8; }
    .lv-audio-title { margin: 12px 0 16px; font-weight: 600; }
    .lv-msg { text-align: center; color: rgba(255,255,255,.85); padding: 32px; max-width: 22rem; }
    .lv-msg ion-icon { font-size: 44px; opacity: .8; }
    .lv-msg p { margin: 14px 0 0; line-height: 1.5; }
  `],
})
export class LibraryViewerComponent {
  private readonly library = inject(LibraryService);
  private readonly sanitizer = inject(DomSanitizer);

  readonly item = input<LibraryItem | null>(null);
  readonly closed = output<void>();

  readonly loading = signal(false);
  readonly failed = signal(false);
  readonly url = signal<string | null>(null);

  readonly safeUrl = computed<SafeResourceUrl | null>(() => {
    const u = this.url();
    return u ? this.sanitizer.bypassSecurityTrustResourceUrl(u) : null;
  });

  constructor() {
    effect(() => {
      const it = this.item();
      this.url.set(null);
      this.failed.set(false);
      if (!it || !it.viewable) {
        this.loading.set(false);
        return;
      }
      this.loading.set(true);
      this.library.viewUrl(it.id).subscribe({
        next: (u) => { this.url.set(u); this.loading.set(false); },
        error: () => { this.failed.set(true); this.loading.set(false); },
      });
    });
  }
}
