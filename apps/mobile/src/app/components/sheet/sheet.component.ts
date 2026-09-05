import {
  Component, HostListener, effect, input, model, output, signal, viewChild, ElementRef,
} from '@angular/core';

/**
 * A premium floating bottom sheet.
 *
 * Rises from the bottom over a blurred scrim, with a grabber you can drag to
 * dismiss and a spring settle. It wears the design system: a crisp white panel
 * with a hairline in Studio (light), a floating dark-indigo panel with a soft
 * glow in Bold (dark) — all from tokens, so it's correct in both themes without
 * a line of per-theme CSS.
 *
 * Controlled with two-way `[(open)]`. Title/subtitle are optional; the body is
 * projected, and an `[actions]` slot pins buttons to the foot above the safe
 * area:
 *
 *   <app-sheet [(open)]="show" title="Enrol in this course" subtitle="…">
 *     <p>Some content.</p>
 *     <ion-button actions expand="block">Confirm</ion-button>
 *   </app-sheet>
 */
@Component({
  selector: 'app-sheet',
  standalone: true,
  template: `
    @if (mounted()) {
      <div class="scrim" [class.show]="shown()" (click)="dismiss()" aria-hidden="true"></div>
      <div
        #panel
        class="panel"
        [class.show]="shown()"
        role="dialog"
        aria-modal="true"
        [attr.aria-label]="title() || 'Sheet'"
        tabindex="-1"
        [style.transform]="drag() ? 'translateY(' + drag() + 'px)' : null"
        [style.transition]="grabbing() ? 'none' : null">
        <div class="grip" (pointerdown)="grab($event)"><span class="bar"></span></div>
        @if (title()) {
          <header class="head">
            <h2 class="title">{{ title() }}</h2>
            @if (subtitle()) { <p class="sub">{{ subtitle() }}</p> }
          </header>
        }
        <div class="body"><ng-content></ng-content></div>
        <div class="actions"><ng-content select="[actions]"></ng-content></div>
      </div>
    }
  `,
  styles: [`
    :host { position: fixed; inset: 0; z-index: 1000; pointer-events: none; }

    .scrim {
      position: fixed; inset: 0; pointer-events: auto;
      background: rgba(8, 6, 20, 0.46);
      -webkit-backdrop-filter: blur(3px); backdrop-filter: blur(3px);
      opacity: 0; transition: opacity 0.28s ease;
    }
    .scrim.show { opacity: 1; }

    .panel {
      position: fixed; left: 0; right: 0; bottom: 0; pointer-events: auto;
      max-height: 90vh; overflow-y: auto; overscroll-behavior: contain;
      background: var(--app-elevated);
      border: var(--app-card-border); border-bottom: 0;
      border-top-left-radius: var(--app-r-lg); border-top-right-radius: var(--app-r-lg);
      box-shadow: var(--app-shadow-lg);
      padding: 4px 18px calc(20px + env(safe-area-inset-bottom));
      transform: translateY(101%);
      transition: transform 0.34s cubic-bezier(0.22, 0.72, 0.2, 1);
      will-change: transform;
    }
    .panel.show { transform: translateY(0); }

    .grip { display: grid; place-items: center; padding: 10px 0 8px; touch-action: none; cursor: grab; }
    .grip:active { cursor: grabbing; }
    .bar { width: 42px; height: 5px; border-radius: 999px; background: var(--app-track); }

    .head { margin: 2px 2px 14px; }
    .title {
      font-family: var(--app-font-display); font-weight: var(--app-display-weight);
      font-size: 1.32rem; line-height: 1.12; letter-spacing: var(--app-display-tracking);
      margin: 0; color: var(--app-ink); text-wrap: balance;
    }
    .sub { color: var(--app-muted); margin: 5px 0 0; font-size: 0.92rem; line-height: 1.4; }

    .body { color: var(--app-ink); }
    .actions:not(:empty) { display: grid; gap: 9px; margin-top: 18px; }

    @media (prefers-reduced-motion: reduce) {
      .panel { transition-duration: 0.001s; }
      .scrim { transition-duration: 0.001s; }
    }
  `],
})
export class SheetComponent {
  readonly open = model(false);
  readonly title = input('');
  readonly subtitle = input('');
  readonly dismissed = output<void>();

  private readonly panel = viewChild<ElementRef<HTMLElement>>('panel');

  /** In the DOM (kept mounted through the leave animation). */
  readonly mounted = signal(false);
  /** The "open" visual state that drives the transitions. */
  readonly shown = signal(false);
  /** Live drag offset in px while a grab is in progress. */
  readonly drag = signal(0);
  readonly grabbing = signal(false);

  private wasOpen = false;
  private startY = 0;
  private opener: HTMLElement | null = null;

  constructor() {
    effect(() => {
      const isOpen = this.open();
      if (isOpen === this.wasOpen) {
        return;
      }
      this.wasOpen = isOpen;
      if (isOpen) {
        // Remember who opened us so focus can return there on close.
        this.opener = (document.activeElement as HTMLElement | null) ?? null;
        this.mounted.set(true);
        this.lockScroll(true);
        // A short timer (not requestAnimationFrame, which browsers pause for
        // hidden documents) lets the panel paint at its off-screen start before
        // we flip it to the shown state, so the slide-up transition always runs.
        setTimeout(() => {
          this.shown.set(true);
          this.panel()?.nativeElement.focus({ preventScroll: true });
        }, 20);
      } else {
        this.shown.set(false);
        this.drag.set(0);
        this.lockScroll(false);
        setTimeout(() => this.mounted.set(false), 320);
        // Return focus to the control that opened the sheet.
        const opener = this.opener;
        this.opener = null;
        if (opener?.isConnected) {
          opener.focus({ preventScroll: true });
        }
        this.dismissed.emit();
      }
    });
  }

  dismiss(): void {
    if (this.open()) {
      this.open.set(false);
    }
  }

  grab(event: PointerEvent): void {
    this.startY = event.clientY;
    this.grabbing.set(true);
  }

  @HostListener('document:pointermove', ['$event'])
  onMove(event: PointerEvent): void {
    if (!this.grabbing()) {
      return;
    }
    this.drag.set(Math.max(0, event.clientY - this.startY));
  }

  @HostListener('document:pointerup')
  onRelease(): void {
    if (!this.grabbing()) {
      return;
    }
    this.grabbing.set(false);
    if (this.drag() > 92) {
      this.open.set(false); // effect clears the drag and slides it out
    } else {
      this.drag.set(0); // snap back
    }
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    this.dismiss();
  }

  // Keep Tab focus inside the panel while it's open (aria-modal contract).
  @HostListener('document:keydown.tab', ['$event'])
  onTab(event: KeyboardEvent): void {
    this.trap(event, false);
  }

  @HostListener('document:keydown.shift.tab', ['$event'])
  onShiftTab(event: KeyboardEvent): void {
    this.trap(event, true);
  }

  private trap(event: KeyboardEvent, back: boolean): void {
    if (!this.mounted()) {
      return;
    }
    const panel = this.panel()?.nativeElement;
    if (!panel) {
      return;
    }
    const items = Array.from(
      panel.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
      ),
    ).filter((el) => el.offsetParent !== null);
    const active = document.activeElement;
    const inside = panel.contains(active);
    if (!items.length) {
      event.preventDefault();
      panel.focus({ preventScroll: true });
      return;
    }
    const first = items[0];
    const last = items[items.length - 1];
    if (back && (active === first || active === panel || !inside)) {
      event.preventDefault();
      last.focus();
    } else if (!back && (active === last || !inside)) {
      event.preventDefault();
      first.focus();
    }
  }

  private lockScroll(lock: boolean): void {
    try {
      document.body.style.overflow = lock ? 'hidden' : '';
    } catch {
      /* non-DOM environment; nothing to lock */
    }
  }
}
