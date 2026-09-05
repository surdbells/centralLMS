import {
  Component, ChangeDetectionStrategy, computed, effect, input, model, signal,
  viewChild, ElementRef,
} from '@angular/core';
import { FormsModule } from '@angular/forms';
import { IonIcon } from '@ionic/angular/standalone';
import { addIcons } from 'ionicons';
import { chevronDown, searchOutline, checkmark, closeCircle } from 'ionicons/icons';
import { SheetComponent } from '../sheet/sheet.component';

// Register the glyphs this component draws, so it renders offline regardless of
// the host app's global icon registry.
addIcons({ chevronDown, searchOutline, checkmark, closeCircle });

export interface SelectOption {
  value: string;
  label: string;
  hint?: string;
}

/**
 * A searchable select.
 *
 * The trigger reads like a form field (label above, value below, chevron). Tap
 * it and the design system's floating sheet rises with a search box already
 * focused and the options filtering as you type — the current choice carries a
 * check. It's the same component in both themes because it's built entirely
 * from tokens and the shared <app-sheet>.
 *
 *   <app-select [(value)]="category" [options]="opts"
 *               label="Category" placeholder="All categories" />
 */
@Component({
  selector: 'app-select',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, IonIcon, SheetComponent],
  template: `
    <button
      type="button"
      class="trigger tappable"
      [attr.aria-haspopup]="'listbox'"
      [attr.aria-expanded]="panelOpen()"
      (click)="openPanel()">
      <span class="tx">
        <span class="lbl">{{ label() }}</span>
        <span class="val" [class.ph]="!selected()">{{ selected()?.label || placeholder() }}</span>
      </span>
      <ion-icon name="chevron-down" class="chev" aria-hidden="true"></ion-icon>
    </button>

    <app-sheet [(open)]="panelOpen" [title]="label()">
      @if (searchable()) {
        <div class="search">
          <ion-icon name="search-outline" aria-hidden="true"></ion-icon>
          <input
            #box
            type="text"
            [placeholder]="searchPlaceholder()"
            [attr.aria-label]="'Search ' + (label() || 'options')"
            [ngModel]="query()"
            (ngModelChange)="query.set($event)"
            autocapitalize="off"
            autocorrect="off"
            spellcheck="false" />
          @if (query()) {
            <button type="button" class="clear" (click)="query.set('')" aria-label="Clear search">
              <ion-icon name="close-circle" aria-hidden="true"></ion-icon>
            </button>
          }
        </div>
      }

      <ul class="opts" role="listbox" [attr.aria-label]="label() || 'Options'">
        @for (o of filtered(); track o.value) {
          <li role="presentation">
            <button
              type="button"
              class="opt"
              role="option"
              [attr.aria-selected]="o.value === value()"
              [class.sel]="o.value === value()"
              (click)="choose(o)">
              <span class="ol">
                <span class="olt">{{ o.label }}</span>
                @if (o.hint) { <span class="olh">{{ o.hint }}</span> }
              </span>
              @if (o.value === value()) {
                <ion-icon name="checkmark" class="ck" aria-hidden="true"></ion-icon>
              }
            </button>
          </li>
        }
        @if (!filtered().length) {
          <li class="empty" role="presentation">
            @if (query().trim()) { No matches for “{{ query() }}” } @else { No options }
          </li>
        }
      </ul>
    </app-sheet>
  `,
  styles: [`
    :host { display: block; }

    .trigger {
      width: 100%; display: flex; align-items: center; gap: 10px;
      background: var(--app-field); border: 1px solid var(--app-line);
      border-radius: var(--app-r-sm); padding: 10px 12px; text-align: left;
      color: var(--app-ink); cursor: pointer;
    }
    .tx { display: flex; flex-direction: column; gap: 1px; min-width: 0; flex: 1; }
    .lbl {
      font-size: 0.7rem; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase;
      color: var(--app-muted);
    }
    .val {
      font-size: 0.98rem; font-weight: 600; color: var(--app-ink);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .val.ph { color: var(--app-muted); font-weight: 500; }
    .chev { font-size: 18px; color: var(--app-muted); flex: none; }

    .search {
      display: flex; align-items: center; gap: 8px;
      background: var(--app-field); border: 1px solid var(--app-line);
      border-radius: var(--app-r-sm); padding: 0 10px; margin-bottom: 8px;
    }
    .search ion-icon { font-size: 18px; color: var(--app-muted); flex: none; }
    .search input {
      flex: 1; border: 0; outline: 0; background: transparent; color: var(--app-ink);
      font: inherit; font-size: 1rem; padding: 12px 0; min-width: 0;
    }
    .search input::placeholder { color: var(--app-muted); }
    .clear { border: 0; background: transparent; padding: 4px; color: var(--app-muted); cursor: pointer; display: grid; }
    .clear ion-icon { font-size: 18px; }

    .opts { list-style: none; margin: 0; padding: 0; max-height: 52vh; overflow-y: auto; }
    .opt {
      width: 100%; display: flex; align-items: center; gap: 10px;
      background: transparent; border: 0; border-radius: var(--app-r-sm);
      padding: 12px 12px; text-align: left; color: var(--app-ink); cursor: pointer;
    }
    .opt:active { background: var(--app-field); }
    .opt.sel { background: var(--app-tag-bg); }
    .ol { display: flex; flex-direction: column; gap: 1px; min-width: 0; flex: 1; }
    .olt { font-size: 0.98rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .opt.sel .olt { color: var(--app-tag-ink); }
    .olh { font-size: 0.8rem; color: var(--app-muted); }
    .ck { font-size: 20px; color: var(--app-tag-ink); flex: none; }
    .empty { padding: 22px 8px; text-align: center; color: var(--app-muted); font-size: 0.92rem; }
  `],
})
export class SelectComponent {
  readonly value = model('');
  readonly options = input<SelectOption[]>([]);
  readonly label = input('');
  readonly placeholder = input('Select');
  readonly searchPlaceholder = input('Search');
  readonly searchable = input(true);

  readonly panelOpen = signal(false);
  readonly query = signal('');

  private readonly box = viewChild<ElementRef<HTMLInputElement>>('box');

  readonly selected = computed(() =>
    this.options().find((o) => o.value === this.value()) ?? null,
  );

  readonly filtered = computed(() => {
    const q = this.query().trim().toLowerCase();
    if (!q) {
      return this.options();
    }
    return this.options().filter(
      (o) => o.label.toLowerCase().includes(q) || (o.hint ?? '').toLowerCase().includes(q),
    );
  });

  constructor() {
    // Focus the search as soon as the sheet has risen.
    effect(() => {
      if (this.panelOpen() && this.searchable()) {
        setTimeout(() => this.box()?.nativeElement.focus(), 340);
      }
    });
  }

  openPanel(): void {
    this.query.set('');
    this.panelOpen.set(true);
  }

  choose(option: SelectOption): void {
    this.value.set(option.value);
    this.panelOpen.set(false);
  }
}
