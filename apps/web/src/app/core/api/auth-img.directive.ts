import { Directive, ElementRef, EventEmitter, Input, OnChanges, OnDestroy, Output, inject } from '@angular/core';
import { Subscription } from 'rxjs';
import { ApiClient } from './api.client';

/**
 * Loads an image the API serves behind authentication into an `<img>`.
 *
 * A browser sends no Authorization header on an `<img src>`, so an image
 * route guarded by a bearer token comes back 401 and renders as a broken
 * image -- which is exactly what an avatar did. This fetches the bytes the
 * normal way (through the same interceptor, so it carries the token), turns
 * them into an object URL, and points the element at that.
 *
 * Give it the base-relative API path (the value the API returns, e.g.
 * `/users/{id}/avatar?v=...`); an empty value clears the image so a fallback
 * can show. The object URL is revoked when it is replaced and on destroy.
 */
@Directive({
  selector: 'img[appAuthImg]',
  standalone: true,
})
export class AuthImgDirective implements OnChanges, OnDestroy {
  @Input('appAuthImg') path: string | null = '';

  /**
   * Emits `true` when the image could not be loaded (missing, forbidden or a
   * network fault) and `false` once it loads. Whether a set URL actually
   * resolves cannot be told from the URL itself, so a template that shows
   * initials or a placeholder binds this to swap them in on failure rather
   * than only when the URL is blank. The element is also hidden on failure, so
   * it never shows the browser's broken-image icon even with no binding.
   */
  @Output() appAuthImgError = new EventEmitter<boolean>();

  private readonly api = inject(ApiClient);
  private readonly el = inject<ElementRef<HTMLImageElement>>(ElementRef);
  private sub?: Subscription;
  private objectUrl?: string;

  ngOnChanges(): void {
    this.load();
  }

  private load(): void {
    this.sub?.unsubscribe();
    this.revoke();

    const el = this.el.nativeElement;
    const path = (this.path ?? '').trim();
    if (path === '') {
      el.removeAttribute('src');
      el.hidden = false;

      return;
    }

    this.sub = this.api.download(path).subscribe({
      next: (blob) => {
        this.revoke();
        this.objectUrl = URL.createObjectURL(blob);
        el.src = this.objectUrl;
        el.hidden = false;
        this.appAuthImgError.emit(false);
      },
      // A missing or forbidden image leaves no src. Hide the element so it does
      // not render the broken-image icon, and signal the failure so the
      // template's own fallback (an initial, a placeholder) can take over.
      error: () => {
        el.removeAttribute('src');
        el.hidden = true;
        this.appAuthImgError.emit(true);
      },
    });
  }

  private revoke(): void {
    if (this.objectUrl) {
      URL.revokeObjectURL(this.objectUrl);
      this.objectUrl = undefined;
    }
  }

  ngOnDestroy(): void {
    this.sub?.unsubscribe();
    this.revoke();
  }
}
