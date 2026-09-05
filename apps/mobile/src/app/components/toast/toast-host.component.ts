import { Component, ChangeDetectionStrategy, inject } from '@angular/core';
import { IonIcon } from '@ionic/angular/standalone';
import { ToastService, ToastVariant } from './toast.service';

/**
 * Renders the stack of live toasts. Mount once, at the app root, above the
 * router outlet. Reads {@link ToastService} — the screens never touch this
 * component, they just call the service.
 */
@Component({
  selector: 'app-toast-host',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [IonIcon],
  template: `
    <div class="host" aria-live="polite" aria-atomic="false">
      @for (t of toast.toasts(); track t.id) {
        <div
          class="toast"
          [class.leaving]="t.leaving"
          [attr.data-variant]="t.variant"
          role="status"
          (click)="toast.dismiss(t.id)">
          <div class="ic"><ion-icon [name]="icon(t.variant)" aria-hidden="true"></ion-icon></div>
          <div class="tx">
            @if (t.title) { <p class="tt">{{ t.title }}</p> }
            <p class="tm">{{ t.message }}</p>
          </div>
          <button
            type="button"
            class="x"
            aria-label="Dismiss"
            (click)="$event.stopPropagation(); toast.dismiss(t.id)">
            <ion-icon name="close" aria-hidden="true"></ion-icon>
          </button>
        </div>
      }
    </div>
  `,
  styles: [`
    .host {
      position: fixed; top: calc(env(safe-area-inset-top) + 8px); left: 0; right: 0;
      z-index: 30000; display: flex; flex-direction: column; align-items: center;
      gap: 8px; padding: 0 12px; pointer-events: none;
    }

    .toast {
      pointer-events: auto; width: 100%; max-width: 460px;
      display: flex; align-items: flex-start; gap: 11px;
      background: var(--app-elevated);
      border: var(--app-card-border); border-left: 3px solid var(--tone);
      border-radius: var(--app-r); box-shadow: var(--app-shadow-lg);
      padding: 12px 12px 12px 13px; cursor: pointer;
      animation: toast-in 0.28s cubic-bezier(0.2, 0.7, 0.2, 1) both;
    }
    .toast.leaving { animation: toast-out 0.22s ease forwards; }

    .toast[data-variant="success"] { --tone: var(--ion-color-success); }
    .toast[data-variant="error"]   { --tone: var(--ion-color-danger); }
    .toast[data-variant="warning"] { --tone: var(--ion-color-warning); }
    .toast[data-variant="info"]    { --tone: var(--app-info); }

    .ic {
      flex: none; width: 30px; height: 30px; border-radius: var(--app-r-sm);
      display: grid; place-items: center; margin-top: 1px;
      background: color-mix(in srgb, var(--tone) 16%, transparent);
    }
    .ic ion-icon { font-size: 19px; color: var(--tone); }

    .tx { flex: 1; min-width: 0; }
    .tt { margin: 0; font-weight: 700; font-size: 0.92rem; color: var(--app-ink); line-height: 1.25; }
    .tm { margin: 0; font-size: 0.88rem; color: var(--app-muted); line-height: 1.4; }
    .tt + .tm { margin-top: 2px; }
    .toast:not(:has(.tt)) .tm { color: var(--app-ink); font-weight: 500; }

    .x {
      flex: none; border: 0; background: transparent; padding: 2px; margin: -2px -2px 0 0;
      color: var(--app-muted); display: grid; cursor: pointer; border-radius: 999px;
    }
    .x ion-icon { font-size: 18px; }
    .x:active { opacity: 0.6; }

    @keyframes toast-in {
      from { opacity: 0; transform: translateY(-14px) scale(0.98); }
      to { opacity: 1; transform: none; }
    }
    @keyframes toast-out {
      to { opacity: 0; transform: translateY(-10px); }
    }
    @media (prefers-reduced-motion: reduce) {
      .toast, .toast.leaving { animation-duration: 0.001s; }
    }
  `],
})
export class ToastHostComponent {
  readonly toast = inject(ToastService);

  icon(variant: ToastVariant): string {
    switch (variant) {
      case 'success': return 'checkmark-circle';
      case 'error': return 'alert-circle';
      case 'warning': return 'warning';
      case 'info': return 'information-circle';
    }
  }
}
