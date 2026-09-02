import { Directive, ElementRef, Input, OnChanges, OnDestroy, inject } from '@angular/core';
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

    const path = (this.path ?? '').trim();
    if (path === '') {
      this.el.nativeElement.removeAttribute('src');

      return;
    }

    this.sub = this.api.download(path).subscribe({
      next: (blob) => {
        this.revoke();
        this.objectUrl = URL.createObjectURL(blob);
        this.el.nativeElement.src = this.objectUrl;
      },
      // A missing or forbidden image just leaves the element without a src,
      // so the template's own fallback (an initial, a placeholder) shows.
      error: () => this.el.nativeElement.removeAttribute('src'),
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
