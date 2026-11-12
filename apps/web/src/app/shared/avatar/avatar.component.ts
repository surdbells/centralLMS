import { ChangeDetectionStrategy, ChangeDetectorRef, Component, Input, OnChanges, inject } from '@angular/core';
import { NgIf } from '@angular/common';
import { AuthImgDirective } from '../../core/api/auth-img.directive';

/**
 * A person's avatar: their photo when they have one, otherwise their initials
 * on a brand-tinted circle — never the browser's broken-image icon.
 *
 * Avatars come from the API as base-relative, auth-protected paths
 * (`/users/{id}/avatar?v=…`). A plain `<img [src]>` resolves those against the
 * web app's origin and sends no token, so it broke. This loads them through
 * AuthImgDirective (with the token) and falls back to initials when the path is
 * empty or the image fails. Data/blob URLs (local previews) and absolute URLs
 * are shown directly, with the same fallback.
 *
 *   <app-avatar [src]="user.avatarUrl" [name]="user.name" />            fills its container
 *   <app-avatar [src]="user.avatarUrl" [name]="user.name" [size]="36" />
 */
@Component({
  selector: 'app-avatar',
  standalone: true,
  imports: [NgIf, AuthImgDirective],
  changeDetection: ChangeDetectionStrategy.OnPush,
  host: {
    '[style.width.px]': 'size',
    '[style.height.px]': 'size',
    '[style.font-size.px]': 'size ? size * 0.38 : null',
    '[attr.title]': 'name || null',
  },
  template: `
    <ng-container *ngIf="src && !failed">
      <img *ngIf="isApiPath; else direct" [appAuthImg]="src" [alt]="name" (appAuthImgError)="onError($event)">
      <ng-template #direct><img [src]="src" [alt]="name" (error)="onError(true)"></ng-template>
    </ng-container>
    <span class="av-initials" *ngIf="!src || failed" aria-hidden="true">{{ initials }}</span>
  `,
  styles: [`
    :host {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
      width: 100%;
      height: 100%;
      border-radius: 50%;
      overflow: hidden;
      background: rgba(var(--brand-primary-rgb, 48, 50, 52), .12);
      color: var(--brand-primary, #303234);
      font-weight: 650;
      line-height: 1;
      letter-spacing: .01em;
      user-select: none;
    }
    img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .av-initials { font-size: inherit; text-transform: uppercase; }
  `],
})
export class AvatarComponent implements OnChanges {
  /** The avatar path/URL the API returned, or null/empty when none. */
  @Input() src: string | null | undefined = null;
  /** The person's name — used for the initials and the alt text. */
  @Input() name: string | null | undefined = '';
  /** Fixed size in px; omit to fill the parent box. */
  @Input() size: number | null = null;

  failed = false;
  private readonly cdr = inject(ChangeDetectorRef);

  ngOnChanges(): void {
    // A new person or picture gets a fresh attempt.
    this.failed = false;
  }

  /** API paths are base-relative ("/users/…"); anything else is shown as-is. */
  get isApiPath(): boolean {
    const s = (this.src ?? '').trim();
    return s.startsWith('/') && !s.startsWith('//');
  }

  get initials(): string {
    const parts = (this.name ?? '').trim().split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '?';
    const first = parts[0][0] ?? '';
    const last = parts.length > 1 ? parts[parts.length - 1][0] ?? '' : '';
    return (first + last).toUpperCase();
  }

  onError(failed: boolean): void {
    if (this.failed !== failed) {
      this.failed = failed;
      this.cdr.markForCheck();
    }
  }
}
