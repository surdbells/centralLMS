import { Component, ChangeDetectionStrategy, booleanAttribute, input } from '@angular/core';
import { IonIcon, IonSpinner } from '@ionic/angular/standalone';

export type ButtonVariant = 'solid' | 'outline' | 'ghost' | 'danger' | 'subtle';

/**
 * The app's button — a premium replacement for ion-button, built on the design
 * tokens so a solid button is a flat brand fill in Studio and a glowing gradient
 * in Bold, without per-theme CSS.
 *
 *   <app-button variant="solid" block (click)="save()">Save</app-button>
 *   <app-button variant="ghost" icon="log-out-outline" (click)="out()">Sign out</app-button>
 *
 * Listen with (click) on the host; while [loading] or [disabled] the inner
 * control is disabled, so clicks don't fire.
 */
@Component({
  selector: 'app-button',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [IonIcon, IonSpinner],
  host: {
    '[class.block]': 'block()',
  },
  template: `
    <button
      class="btn tappable"
      [class]="variant()"
      [class.lg]="size() === 'lg'"
      [class.icon-only]="iconOnly()"
      [attr.type]="type()"
      [disabled]="disabled() || loading()">
      @if (loading()) {
        <ion-spinner name="crescent"></ion-spinner>
      } @else {
        @if (icon()) { <ion-icon [name]="icon()" aria-hidden="true"></ion-icon> }
        <span class="lbl"><ng-content></ng-content></span>
      }
    </button>
  `,
  styles: [`
    :host { display: inline-block; }
    :host(.block) { display: block; }

    .btn {
      width: 100%;
      display: inline-flex; align-items: center; justify-content: center; gap: 8px;
      font-family: var(--app-font); font-weight: 700; font-size: 1rem; line-height: 1;
      height: 48px; padding: 0 18px; border: 0; border-radius: var(--app-r-btn);
      cursor: pointer; -webkit-tap-highlight-color: transparent;
      transition: filter 0.15s ease, opacity 0.15s ease;
    }
    .btn.lg { height: 52px; font-size: 1.02rem; }
    .btn.icon-only { width: 48px; padding: 0; }
    .btn ion-icon { font-size: 19px; }
    .btn:disabled { opacity: 0.5; cursor: default; }
    .btn:not(:disabled):active { filter: brightness(0.96); }
    ion-spinner { width: 22px; height: 22px; }

    .solid {
      background: var(--app-accent-fill); color: var(--app-accent-ink);
      box-shadow: var(--app-btn-shadow);
    }
    .danger { background: var(--ion-color-danger); color: #fff; }
    .subtle { background: var(--app-tag-bg); color: var(--app-tag-ink); }
    /* --btn-fg lets a caller retint ghost/outline (e.g. a danger sign-out). */
    .outline {
      background: transparent; color: var(--btn-fg, var(--ion-color-primary));
      box-shadow: inset 0 0 0 1.5px color-mix(in srgb, var(--btn-fg, var(--ion-color-primary)) 45%, var(--app-line));
    }
    .ghost { background: transparent; color: var(--btn-fg, var(--ion-color-primary)); }
    .ghost:not(:disabled):active, .outline:not(:disabled):active {
      background: var(--app-tag-bg); filter: none;
    }
  `],
})
export class ButtonComponent {
  readonly variant = input<ButtonVariant>('solid');
  readonly size = input<'md' | 'lg'>('md');
  readonly block = input(false, { transform: booleanAttribute });
  readonly disabled = input(false, { transform: booleanAttribute });
  readonly loading = input(false, { transform: booleanAttribute });
  readonly icon = input('');
  readonly iconOnly = input(false, { transform: booleanAttribute });
  readonly type = input<'button' | 'submit'>('button');
}
