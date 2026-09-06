import {
  ChangeDetectionStrategy, Component, DestroyRef, computed, effect, inject, input, signal,
} from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Subscription } from 'rxjs';
import { environment } from '../../../environments/environment';
import { initialOf, thumbStyle } from '../../core/ui';

/**
 * A circular avatar that shows the person's photo when they have one and their
 * initial on a deterministic colour when they don't.
 *
 * Avatars are served from a header-authenticated endpoint (`/users/{id}/avatar`),
 * so a plain `<img src>` — which cannot carry the bearer token — would 401. We
 * fetch the bytes through HttpClient (the auth interceptor attaches the token and
 * refreshes it on a 401) and hand the `<img>` an object URL instead, revoked when
 * it changes or the component goes away.
 */
@Component({
  selector: 'app-avatar',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <span class="av" [style.width.px]="size()" [style.height.px]="size()"
          [style.font-size.px]="size() * 0.4"
          [style]="objectUrl() ? transparent : bg()">
      @if (objectUrl(); as u) {
        <img [src]="u" [alt]="name()" />
      } @else {
        <span class="ini">{{ initial(name()) }}</span>
      }
    </span>
  `,
  styles: [`
    .av { position: relative; display: inline-grid; place-items: center; border-radius: 50%; overflow: hidden;
      color: #fff; font-family: var(--app-font-display); font-weight: 800; letter-spacing: var(--app-display-tracking); flex: none; }
    .av img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .ini { line-height: 1; }
  `],
})
export class AvatarComponent {
  private readonly http = inject(HttpClient);

  readonly url = input<string | null | undefined>(null);
  readonly name = input('');
  readonly size = input(40);

  readonly objectUrl = signal<string | null>(null);
  readonly bg = computed<Record<string, string>>(() => thumbStyle(this.name()));
  readonly transparent: Record<string, string> = { background: 'transparent' };
  readonly initial = initialOf;

  private live: string | null = null;
  private sub: Subscription | null = null;

  constructor() {
    effect(() => this.load(this.url() ?? null));
    inject(DestroyRef).onDestroy(() => this.teardown());
  }

  private load(url: string | null): void {
    this.teardown();
    this.objectUrl.set(null);
    if (!url) {
      return;
    }
    const full = url.startsWith('http') ? url : environment.apiBaseUrl + url;
    this.sub = this.http.get(full, { responseType: 'blob' }).subscribe({
      next: (blob) => {
        this.live = URL.createObjectURL(blob);
        this.objectUrl.set(this.live);
      },
      error: () => this.objectUrl.set(null),
    });
  }

  private teardown(): void {
    this.sub?.unsubscribe();
    this.sub = null;
    if (this.live) {
      URL.revokeObjectURL(this.live);
      this.live = null;
    }
  }
}
