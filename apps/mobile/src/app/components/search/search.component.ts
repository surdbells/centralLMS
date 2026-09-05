import {
  Component, ChangeDetectionStrategy, input, output, signal, OnDestroy,
} from '@angular/core';
import { IonIcon } from '@ionic/angular/standalone';

/**
 * A debounced search field replacing ion-searchbar. Solid field on the design
 * surface with a leading magnifier and a clear button; emits (search) after the
 * user pauses typing, and immediately on clear.
 *
 *   <app-search placeholder="Search courses" (search)="onSearch($event)" />
 */
@Component({
  selector: 'app-search',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [IonIcon],
  template: `
    <div class="box" [class.focused]="focused()">
      <ion-icon name="search-outline" aria-hidden="true"></ion-icon>
      <input
        type="search"
        enterkeyhint="search"
        autocapitalize="off"
        autocorrect="off"
        spellcheck="false"
        [placeholder]="placeholder()"
        [value]="value()"
        (input)="onInput($event)"
        (focus)="focused.set(true)"
        (blur)="focused.set(false)"
        [attr.aria-label]="placeholder() || 'Search'" />
      @if (value()) {
        <button type="button" class="clear" aria-label="Clear search" (click)="clear()">
          <ion-icon name="close-circle" aria-hidden="true"></ion-icon>
        </button>
      }
    </div>
  `,
  styles: [`
    :host { display: block; }
    .box {
      display: flex; align-items: center; gap: 8px;
      background: var(--app-field); border: 1.5px solid var(--app-line);
      border-radius: var(--app-r-sm); padding: 0 10px;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .box.focused { border-color: var(--ion-color-primary); box-shadow: 0 0 0 3px var(--app-ring); }
    .box > ion-icon { flex: none; font-size: 18px; color: var(--app-muted); }
    input {
      flex: 1; min-width: 0; border: 0; outline: 0; background: transparent;
      color: var(--app-ink); font: inherit; font-size: 0.98rem; padding: 11px 0;
      -webkit-appearance: none;
    }
    input::placeholder { color: var(--app-muted); }
    input::-webkit-search-cancel-button { display: none; }
    .clear { flex: none; border: 0; background: transparent; padding: 4px; color: var(--app-muted); display: grid; cursor: pointer; }
    .clear ion-icon { font-size: 18px; }
    .clear:active { opacity: 0.6; }
  `],
})
export class SearchComponent implements OnDestroy {
  readonly placeholder = input('Search');
  readonly debounce = input(300);
  readonly search = output<string>();

  readonly value = signal('');
  readonly focused = signal(false);

  private timer: ReturnType<typeof setTimeout> | null = null;

  onInput(event: Event): void {
    const v = (event.target as HTMLInputElement).value;
    this.value.set(v);
    this.schedule(v);
  }

  clear(): void {
    this.value.set('');
    this.cancel();
    this.search.emit('');
  }

  private schedule(v: string): void {
    this.cancel();
    this.timer = setTimeout(() => this.search.emit(v), this.debounce());
  }

  private cancel(): void {
    if (this.timer) {
      clearTimeout(this.timer);
      this.timer = null;
    }
  }

  ngOnDestroy(): void {
    this.cancel();
  }
}
