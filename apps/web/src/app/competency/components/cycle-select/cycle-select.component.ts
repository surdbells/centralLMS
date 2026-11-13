import {
  Component,
  DestroyRef,
  Input,
  computed,
  forwardRef,
  inject,
  signal,
} from '@angular/core';
import {
  ControlValueAccessor,
  NG_VALUE_ACCESSOR,
} from '@angular/forms';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { HotToastService } from '@ngneat/hot-toast';
import { LookupsService } from '../../../services/lookups.service';
import { SessionStore } from '../../../core/auth/session.store';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';
import { SelectComponent, SelectFooterDirective } from '../../../shared/select/select.component';

/** Stands in for the "all cycles" entry, whose public value is ''. */
const ALL = '__all_cycles__';

/**
 * A searchable cycle picker, backed by the managed cycle list. Built on the
 * shared <app-select>, with a footer action to quick-add the searched name.
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
  imports: [SelectComponent, SelectFooterDirective, TranslatePipe],
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => CycleSelectComponent),
      multi: true,
    },
  ],
  template: `
    <app-select
      class="cs"
      [options]="options()"
      [size]="size"
      [disabled]="disabled"
      [placeholder]="placeholder || ('competency.cycle_select.default_placeholder' | translate)"
      [noResultsText]="'competency.cycle_select.no_matching' | translate"
      [loading]="loading()"
      [value]="innerValue()"
      (selectionChange)="onPick($event)"
      (searchChange)="query.set($event)"
      (opened)="query.set('')"
      (closed)="onTouched()">
      <ng-template appSelectFooter let-close="close">
        @if (canQuickAdd()) {
          <button type="button" class="app-select-action" [disabled]="creating()" (click)="quickAdd(close)">
            <i class="bi bi-plus-lg"></i>
            <span>{{ 'competency.cycle_select.add_named' | translate: { name: query().trim() } }}</span>
            @if (creating()) { <span class="spinner-border spinner-border-sm ms-auto"></span> }
          </button>
        }
      </ng-template>
    </app-select>
  `,
  styles: [`
    :host { display: block; min-width: 0; }
  `],
})
export class CycleSelectComponent implements ControlValueAccessor {
  /** Placeholder shown when nothing is chosen. Empty falls back to a translated default. */
  @Input() placeholder = '';
  /** Offer an explicit "all cycles" entry that clears the value. */
  @Input() set allowAll(v: boolean) { this._allowAll.set(v); }
  get allowAll(): boolean { return this._allowAll(); }
  @Input() set allLabel(v: string) { this._allLabel.set(v); }
  get allLabel(): string { return this._allLabel(); }
  @Input() size: 'sm' | 'md' = 'md';
  @Input() disabled = false;

  private readonly lookups = inject(LookupsService);
  private readonly session = inject(SessionStore);
  private readonly toast = inject(HotToastService);
  private readonly destroyRef = inject(DestroyRef);
  private readonly i18n = inject(I18nService);

  /** The chosen cycle name; '' means none / all. */
  readonly value = signal('');

  private readonly _allowAll = signal(false);
  private readonly _allLabel = signal('All cycles');
  readonly cycles = signal<string[]>([]);
  /** What the user has typed into the picker's search box. */
  readonly query = signal('');
  readonly creating = signal(false);
  readonly loading = signal(true);

  /** Quick-add is a convenience for whoever may manage the competency exercise. */
  readonly canManage = computed(() => this.session.can()('competency.manage'));

  /** The rows <app-select> shows: an optional "all" entry, then the cycles. */
  readonly options = computed(() => {
    const cycles = this.cycles().map((c) => ({ label: c, value: c }));
    return this.allowAll ? [{ label: this.allLabel, value: ALL }, ...cycles] : cycles;
  });

  /** The value handed to <app-select>; '' maps to the "all" entry when offered. */
  readonly innerValue = computed(() => {
    const v = this.value();
    return v ? v : this.allowAll ? ALL : null;
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
  onTouched: () => void = () => {};

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
        next: (rows) => {
          this.loading.set(false);
          this.cycles.set(
            rows
              .map((r) => r.name)
              .sort((a, b) => b.localeCompare(a, undefined, { numeric: true })),
          );
        },
        error: () => {
          this.loading.set(false);
          this.toast.error(this.i18n.t('competency.cycle_select.load_error'));
        },
      });
  }

  onPick(v: string | null): void {
    this.setValue(!v || v === ALL ? '' : v);
  }

  quickAdd(close: () => void): void {
    const name = this.query().trim();

    if (!name || this.creating()) {
      return;
    }

    this.creating.set(true);
    this.lookups.create('cycle', { name }).subscribe({
      next: (created) => {
        this.creating.set(false);
        this.reload();
        this.setValue(created.name);
        close();
        this.toast.success(this.i18n.t('competency.cycle_select.added', { name: created.name }));
      },
      error: () => {
        this.creating.set(false);
        this.toast.error(this.i18n.t('competency.cycle_select.add_error'));
      },
    });
  }

  private setValue(name: string): void {
    this.value.set(name);
    this.onChange(name);
  }

  // --- ControlValueAccessor --------------------------------------------------

  writeValue(value: string | null): void {
    this.value.set(value ?? '');
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
