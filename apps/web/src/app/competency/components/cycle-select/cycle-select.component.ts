import {
  Component,
  DestroyRef,
  Input,
  computed,
  forwardRef,
  inject,
  signal,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import {
  ControlValueAccessor,
  FormsModule,
  NG_VALUE_ACCESSOR,
} from '@angular/forms';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { HotToastService } from '@ngneat/hot-toast';
import { LookupsService } from '../../../services/lookups.service';
import { SessionStore } from '../../../core/auth/session.store';

/**
 * A searchable cycle picker, backed by the managed cycle list.
 *
 * The cycle a report or a new assessment belongs to used to be free text or a
 * plain <select> over whatever cycles happened to exist. This offers the managed
 * list, filters as you type, and — for anyone who may edit reference data — lets
 * a new cycle be added on the spot rather than sending them to another screen.
 *
 * It is a form control (value = the cycle's name), so it drops into ngModel and
 * reactive forms wherever a cycle is chosen. An empty value means "none", or
 * "all cycles" where {@link allowAll} is set.
 */
@Component({
  selector: 'app-cycle-select',
  standalone: true,
  imports: [CommonModule, FormsModule],
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => CycleSelectComponent),
      multi: true,
    },
  ],
  template: `
    <div class="cs" [class.cs-sm]="size === 'sm'">
      <input
        type="text"
        class="form-control cs-input"
        [class.form-control-sm]="size === 'sm'"
        [placeholder]="placeholder"
        [ngModel]="query()"
        [disabled]="disabled"
        (ngModelChange)="onQueryInput($event)"
        (focus)="openMenu()"
        (blur)="closeMenu()"
        role="combobox"
        [attr.aria-expanded]="open()"
        aria-autocomplete="list" />

      @if (open() && !disabled) {
        <ul class="cs-menu" role="listbox">
          @if (allowAll) {
            <li class="cs-opt cs-all" role="option" (click)="chooseAll()">{{ allLabel }}</li>
          }
          @for (c of filtered(); track c) {
            <li class="cs-opt" role="option" [class.cs-selected]="c === value" (click)="choose(c)">{{ c }}</li>
          }
          @if (!filtered().length && !canQuickAdd()) {
            <li class="cs-empty">No matching cycle</li>
          }
          @if (canQuickAdd()) {
            <li class="cs-opt cs-add" role="option" (click)="quickAdd()">
              <i class="bi bi-plus-lg"></i> Add “{{ query().trim() }}”
              @if (creating()) { <span class="spinner-border spinner-border-sm ms-1"></span> }
            </li>
          }
        </ul>
      }
    </div>
  `,
  styles: [`
    .cs { position: relative; }
    .cs-input { cursor: text; }
    .cs-menu {
      position: absolute; z-index: 20; top: calc(100% + 2px); left: 0; right: 0;
      margin: 0; padding: .25rem 0; list-style: none;
      max-height: 260px; overflow-y: auto;
      background: #fff; border: 1px solid #d7dce1; border-radius: .5rem;
      box-shadow: 0 8px 24px rgba(0, 0, 0, .12);
    }
    .cs-opt { padding: .4rem .75rem; cursor: pointer; font-size: .9rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .cs-opt:hover { background: #eef4fb; }
    .cs-selected { font-weight: 600; color: #1f5f8b; }
    .cs-all { color: #566; border-bottom: 1px solid #eee; }
    .cs-add { color: #1f7a4d; border-top: 1px solid #eee; }
    .cs-empty { padding: .4rem .75rem; color: #98a2ad; font-size: .85rem; }
  `],
})
export class CycleSelectComponent implements ControlValueAccessor {
  /** Placeholder shown when nothing is chosen. */
  @Input() placeholder = 'Select a cycle';
  /** Offer an explicit "all cycles" entry that clears the value. */
  @Input() allowAll = false;
  @Input() allLabel = 'All cycles';
  @Input() size: 'sm' | 'md' = 'md';
  @Input() disabled = false;

  private readonly lookups = inject(LookupsService);
  private readonly session = inject(SessionStore);
  private readonly toast = inject(HotToastService);
  private readonly destroyRef = inject(DestroyRef);

  /** The chosen cycle name; '' means none / all. */
  value = '';

  readonly cycles = signal<string[]>([]);
  readonly query = signal('');
  readonly open = signal(false);
  readonly creating = signal(false);

  /** Quick-add is a convenience for whoever may manage the competency exercise. */
  readonly canManage = computed(() => this.session.can()('competency.manage'));

  readonly filtered = computed(() => {
    const q = this.query().trim().toLowerCase();
    const all = this.cycles();
    return q ? all.filter((c) => c.toLowerCase().includes(q)) : all;
  });

  /** Whether the typed text is a name no cycle yet has, so it can be created. */
  readonly canQuickAdd = computed(() => {
    const q = this.query().trim();
    return (
      q.length > 0 &&
      this.canManage() &&
      !this.cycles().some((c) => c.toLowerCase() === q.toLowerCase())
    );
  });

  private onChange: (v: string) => void = () => {};
  private onTouched: () => void = () => {};

  constructor() {
    this.reload();
  }

  private reload(): void {
    this.lookups
      .active('cycle')
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe({
        // Newest first, the way cycles have always been listed. Numeric so
        // "2026 H2" sorts above "2026 H1" and "9" below "10".
        next: (rows) =>
          this.cycles.set(
            rows
              .map((r) => r.name)
              .sort((a, b) => b.localeCompare(a, undefined, { numeric: true })),
          ),
        error: () => this.toast.error('The cycles could not be loaded.'),
      });
  }

  onQueryInput(text: string): void {
    this.query.set(text);
    this.open.set(true);
  }

  openMenu(): void {
    this.open.set(true);
  }

  closeMenu(): void {
    // A tick behind the blur so an option click registers first; then the box
    // falls back to showing the chosen value rather than a half-typed search.
    setTimeout(() => {
      this.open.set(false);
      this.query.set(this.value);
      this.onTouched();
    }, 150);
  }

  choose(name: string): void {
    this.setValue(name);
    this.open.set(false);
  }

  chooseAll(): void {
    this.setValue('');
    this.open.set(false);
  }

  quickAdd(): void {
    const name = this.query().trim();

    if (!name || this.creating()) {
      return;
    }

    this.creating.set(true);
    this.lookups.create('cycle', { name }).subscribe({
      next: (created) => {
        this.creating.set(false);
        this.reload();
        this.choose(created.name);
        this.toast.success(`Cycle “${created.name}” added.`);
      },
      error: () => {
        this.creating.set(false);
        this.toast.error('That cycle could not be added.');
      },
    });
  }

  private setValue(name: string): void {
    this.value = name;
    this.query.set(name);
    this.onChange(name);
  }

  // --- ControlValueAccessor --------------------------------------------------

  writeValue(value: string | null): void {
    this.value = value ?? '';
    this.query.set(this.value);
  }

  registerOnChange(fn: (v: string) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  setDisabledState(isDisabled: boolean): void {
    this.disabled = isDisabled;
  }
}
