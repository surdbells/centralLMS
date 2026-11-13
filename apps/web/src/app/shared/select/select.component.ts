import {
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  Component,
  ContentChild,
  DestroyRef,
  Directive,
  ElementRef,
  EventEmitter,
  Injector,
  Input,
  Output,
  TemplateRef,
  ViewChild,
  afterNextRender,
  booleanAttribute,
  computed,
  forwardRef,
  inject,
  signal,
} from '@angular/core';
import { NgTemplateOutlet } from '@angular/common';
import {
  ControlValueAccessor,
  NG_VALUE_ACCESSOR,
  NgControl,
} from '@angular/forms';
import {
  CdkConnectedOverlay,
  ConnectedPosition,
  Overlay,
  OverlayModule,
  ScrollStrategy,
} from '@angular/cdk/overlay';
import { NavigationStart, Router } from '@angular/router';
import { takeUntilDestroyed, toSignal } from '@angular/core/rxjs-interop';
import { filter } from 'rxjs';
import { I18nService } from '../../i18n.service';
import { TranslatePipe } from '../../translate.pipe';

/** Custom option row: `<ng-template appSelectOption let-opt let-selected="selected">`. */
@Directive({ selector: 'ng-template[appSelectOption]', standalone: true })
export class SelectOptionDirective {
  readonly template = inject<TemplateRef<SelectOptionContext>>(TemplateRef);
}

/** Custom trigger label (single mode): `<ng-template appSelectLabel let-opt>`. */
@Directive({ selector: 'ng-template[appSelectLabel]', standalone: true })
export class SelectLabelDirective {
  readonly template = inject<TemplateRef<SelectOptionContext>>(TemplateRef);
}

/**
 * Optional panel footer, below the list:
 * `<ng-template appSelectFooter let-query let-close="close">`.
 * Used for actions such as quick-adding the searched text.
 */
@Directive({ selector: 'ng-template[appSelectFooter]', standalone: true })
export class SelectFooterDirective {
  readonly template = inject<TemplateRef<SelectFooterContext>>(TemplateRef);
}

export interface SelectFooterContext {
  /** The current (trimmed) search text. */
  $implicit: string;
  /** Closes the panel and returns focus to the trigger. */
  close: () => void;
}

export interface SelectOptionContext {
  /** The raw option object / primitive as passed in [options]. */
  $implicit: any;
  /** Resolved (translated) label. */
  label: string;
  selected: boolean;
}

/** A normalised option, as the panel renders it. */
export interface SelectItem {
  id: string;
  option: any;
  value: any;
  label: string;
  description: string | null;
  icon: string | null;
  disabled: boolean;
  group: string | null;
}

interface Segment {
  text: string;
  hit: boolean;
}

type Row =
  | { kind: 'group'; label: string; key: string }
  | { kind: 'item'; item: SelectItem; index: number; key: string; label: Segment[]; desc: Segment[] | null };

let nextId = 0;

/** Lower-case and strip accents, keeping a map back to the original indices. */
function fold(text: string): { folded: string; map: number[] } {
  let folded = '';
  const map: number[] = [];
  for (let i = 0; i < text.length; i++) {
    const f = text[i].normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    for (let k = 0; k < f.length; k++) {
      folded += f[k];
      map.push(i);
    }
  }
  return { folded, map };
}

export function foldText(text: string): string {
  return fold(text).folded;
}

function highlight(text: string, query: string): Segment[] {
  if (!query) return [{ text, hit: false }];
  const { folded, map } = fold(text);
  const at = folded.indexOf(query);
  if (at < 0) return [{ text, hit: false }];
  const start = map[at];
  const end = map[at + query.length - 1] + 1;
  const out: Segment[] = [];
  if (start > 0) out.push({ text: text.slice(0, start), hit: false });
  out.push({ text: text.slice(start, end), hit: true });
  if (end < text.length) out.push({ text: text.slice(end), hit: false });
  return out;
}

/**
 * The app's one dropdown: a premium, searchable select.
 *
 * A form control (ngModel / formControlName / formControl) or a plain
 * [value] + (selectionChange) pair. Options can be objects or primitives; the
 * panel renders in a CDK overlay so it escapes overflow:hidden cards, tables and
 * modals (Bootstrap and Taiga).
 */
@Component({
  selector: 'app-select',
  standalone: true,
  imports: [OverlayModule, NgTemplateOutlet, TranslatePipe],
  templateUrl: './select.component.html',
  styleUrl: './select.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => SelectComponent),
      multi: true,
    },
  ],
  host: {
    class: 'app-select',
    '[class.app-select--sm]': "size === 'sm'",
    '[class.app-select--open]': 'isOpen()',
    '[class.app-select--disabled]': 'isDisabled()',
  },
})
export class SelectComponent implements ControlValueAccessor {
  private readonly i18n = inject(I18nService);
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly overlay = inject(Overlay);
  private readonly injector = inject(Injector);
  private readonly destroyRef = inject(DestroyRef);
  private readonly host = inject<ElementRef<HTMLElement>>(ElementRef);
  private readonly router = inject(Router, { optional: true });

  // --- inputs ---------------------------------------------------------------

  @Input() set options(v: readonly any[] | null | undefined) {
    this._options.set(v ? [...v] : []);
  }
  @Input() set bindLabel(v: string) { this._bindLabel.set(v || 'label'); }
  @Input() set bindValue(v: string) { this._bindValue.set(v || 'value'); }
  @Input() set groupBy(v: string | null | undefined) { this._groupBy.set(v || null); }
  @Input() set disabledKey(v: string) { this._disabledKey.set(v || 'disabled'); }
  @Input() set descriptionKey(v: string | null | undefined) { this._descriptionKey.set(v || null); }
  @Input() set iconKey(v: string | null | undefined) { this._iconKey.set(v || null); }
  @Input({ transform: booleanAttribute }) set translateLabels(v: boolean) { this._translate.set(v); }

  @Input() placeholder = '';
  @Input({ transform: booleanAttribute }) searchable = true;
  @Input() searchPlaceholder = '';
  @Input({ transform: booleanAttribute }) clearable = false;
  @Input({ transform: booleanAttribute }) set multiple(v: boolean) { this._multiple.set(v); }
  get multiple(): boolean { return this._multiple(); }
  @Input() set compareWith(fn: ((a: any, b: any) => boolean) | null | undefined) {
    this._compareWith.set(fn ?? null);
  }
  @Input() size: 'sm' | 'md' = 'md';
  @Input({ transform: booleanAttribute }) invalid = false;
  @Input({ transform: booleanAttribute }) set disabled(v: boolean) { this._disabledInput.set(v); }
  @Input({ transform: booleanAttribute }) set loading(v: boolean) { this.isLoading.set(v); }
  @Input() noResultsText = '';
  @Input() emptyText = '';
  @Input() inputId: string | null = null;
  @Input() ariaLabel: string | null = null;
  /** Non-form use: the current value. */
  @Input() set value(v: any) { this._value.set(v); }
  get value(): any { return this._value(); }

  // --- outputs --------------------------------------------------------------

  /** Two-way partner of [value]. */
  @Output() readonly valueChange = new EventEmitter<any>();
  /** The new value, after a user pick (also fires for clear). */
  @Output() readonly selectionChange = new EventEmitter<any>();
  @Output() readonly opened = new EventEmitter<void>();
  @Output() readonly closed = new EventEmitter<void>();
  /** The search text as the user types (searchable mode). */
  @Output() readonly searchChange = new EventEmitter<string>();

  @ContentChild(SelectOptionDirective) optionTpl?: SelectOptionDirective;
  @ContentChild(SelectLabelDirective) labelTpl?: SelectLabelDirective;
  @ContentChild(SelectFooterDirective) footerTpl?: SelectFooterDirective;

  @ViewChild('trigger', { static: true }) triggerRef!: ElementRef<HTMLButtonElement>;
  @ViewChild('searchInput') searchRef?: ElementRef<HTMLInputElement>;
  @ViewChild('listbox') listboxRef?: ElementRef<HTMLElement>;
  @ViewChild(CdkConnectedOverlay) connected?: CdkConnectedOverlay;

  // --- state ----------------------------------------------------------------

  readonly uid = `app-select-${nextId++}`;
  readonly listboxId = `${this.uid}-listbox`;

  private readonly _options = signal<any[]>([]);
  private readonly _bindLabel = signal('label');
  private readonly _bindValue = signal('value');
  private readonly _groupBy = signal<string | null>(null);
  private readonly _disabledKey = signal('disabled');
  private readonly _descriptionKey = signal<string | null>(null);
  private readonly _iconKey = signal<string | null>(null);
  private readonly _translate = signal(false);
  private readonly _multiple = signal(false);
  private readonly _compareWith = signal<((a: any, b: any) => boolean) | null>(null);
  private readonly _disabledInput = signal(false);
  private readonly _disabledForm = signal(false);
  readonly isLoading = signal(false);
  private readonly _value = signal<any>(null);

  /** Re-resolve translated labels when the language switches. */
  private readonly lang = toSignal(this.i18n.lang$, { initialValue: this.i18n.lang });

  readonly isOpen = signal(false);
  readonly query = signal('');
  readonly activeIndex = signal(-1);
  readonly panelWidth = signal(220);

  readonly isDisabled = computed(() => this._disabledInput() || this._disabledForm());

  readonly positions: ConnectedPosition[] = [
    { originX: 'start', originY: 'bottom', overlayX: 'start', overlayY: 'top', offsetY: 4 },
    { originX: 'start', originY: 'top', overlayX: 'start', overlayY: 'bottom', offsetY: -4 },
    { originX: 'end', originY: 'bottom', overlayX: 'end', overlayY: 'top', offsetY: 4 },
    { originX: 'end', originY: 'top', overlayX: 'end', overlayY: 'bottom', offsetY: -4 },
  ];
  readonly scrollStrategy: ScrollStrategy = this.overlay.scrollStrategies.reposition();

  /** All options, normalised. */
  readonly items = computed<SelectItem[]>(() => {
    this.lang();
    const translate = this._translate();
    const bl = this._bindLabel();
    const bv = this._bindValue();
    const gb = this._groupBy();
    const dk = this._disabledKey();
    const desc = this._descriptionKey();
    const ik = this._iconKey();
    return this._options().map((o, i) => {
      const isObj = o !== null && typeof o === 'object';
      const rawLabel = isObj ? o[bl] : o;
      const label = rawLabel == null ? '' : String(rawLabel);
      const rawDesc = isObj && desc ? o[desc] : null;
      return {
        id: `${this.uid}-opt-${i}`,
        option: o,
        value: isObj ? (bv in o ? o[bv] : o) : o,
        label: translate ? this.i18n.t(label) : label,
        description: rawDesc == null || rawDesc === '' ? null : translate ? this.i18n.t(String(rawDesc)) : String(rawDesc),
        icon: isObj && ik ? o[ik] ?? null : null,
        disabled: isObj ? !!o[dk] : false,
        group: isObj && gb ? (o[gb] == null ? null : translate ? this.i18n.t(String(o[gb])) : String(o[gb])) : null,
      };
    });
  });

  private readonly foldedQuery = computed(() => foldText(this.query().trim()));

  /** Options that survive the search, in display order. */
  readonly filtered = computed<SelectItem[]>(() => {
    const q = this.foldedQuery();
    const all = this.items();
    if (!q) return all;
    return all.filter(
      (it) => foldText(it.label).includes(q) || (!!it.description && foldText(it.description).includes(q)),
    );
  });

  /** Panel rows: group headers interleaved with options. */
  readonly rows = computed<Row[]>(() => {
    const q = this.foldedQuery();
    const list = this.filtered();
    const grouped = !!this._groupBy();
    const out: Row[] = [];
    let last: string | null | undefined = undefined;
    // Keep each group's options together, groups in first-seen order.
    const order = grouped ? this.groupOrder(list) : list;
    order.forEach((item, index) => {
      if (grouped && item.group !== last) {
        last = item.group;
        if (item.group) out.push({ kind: 'group', label: item.group, key: `g:${item.group}:${index}` });
      }
      out.push({
        kind: 'item',
        item,
        index,
        key: item.id,
        label: highlight(item.label, q),
        desc: item.description ? highlight(item.description, q) : null,
      });
    });
    return out;
  });

  /** Navigable (rendered) items in order; activeIndex indexes this. */
  readonly navItems = computed<SelectItem[]>(() =>
    this.rows().flatMap((r) => (r.kind === 'item' ? [r.item] : [])),
  );

  readonly activeId = computed(() => {
    const i = this.activeIndex();
    return this.isOpen() ? this.navItems()[i]?.id ?? null : null;
  });

  readonly selectedItems = computed<SelectItem[]>(() => {
    const v = this._value();
    const items = this.items();
    if (this._multiple()) {
      const arr: any[] = Array.isArray(v) ? v : [];
      return arr
        .map((x) => items.find((it) => this.same(it.value, x)))
        .filter((x): x is SelectItem => !!x);
    }
    if (this.isEmpty(v)) return [];
    const hit = items.find((it) => this.same(it.value, v));
    return hit ? [hit] : [];
  });

  readonly hasValue = computed(() => {
    const v = this._value();
    return this._multiple() ? Array.isArray(v) && v.length > 0 : !this.isEmpty(v);
  });

  /** Trigger text (single) / chip list (multiple). */
  readonly displayLabel = computed(() => this.selectedItems().map((i) => i.label).join(', '));

  readonly maxChips = 2;
  readonly chips = computed(() => this.selectedItems().slice(0, this.maxChips));
  readonly overflow = computed(() => Math.max(0, this.selectedItems().length - this.maxChips));

  readonly allFilteredSelected = computed(() => {
    const sel = this.navItems().filter((i) => !i.disabled);
    return sel.length > 0 && sel.every((i) => this.isSelected(i));
  });

  private onChange: (v: any) => void = () => {};
  private onTouched: () => void = () => {};
  private ngControl: NgControl | null = null;
  private typeBuffer = '';
  private typeTimer: ReturnType<typeof setTimeout> | null = null;
  private scrollCleanup: (() => void) | null = null;

  constructor() {
    this.router?.events
      .pipe(filter((e) => e instanceof NavigationStart), takeUntilDestroyed(this.destroyRef))
      .subscribe(() => this.close(false));
    // Resolve NgControl lazily (it injects us as its value accessor).
    queueMicrotask(() => {
      this.ngControl = this.injector.get(NgControl, null, { self: true, optional: true });
      // OnPush: refresh the invalid styling when status/touched/pristine change
      // from outside (markAllAsTouched, cross-field validators, setErrors).
      this.ngControl?.control?.events
        .pipe(takeUntilDestroyed(this.destroyRef))
        .subscribe(() => this.cdr.markForCheck());
      this.cdr.markForCheck();
    });
    this.destroyRef.onDestroy(() => {
      if (this.typeTimer) clearTimeout(this.typeTimer);
      this.scrollCleanup?.();
    });
  }

  // --- derived for template -------------------------------------------------

  get showInvalid(): boolean {
    const c = this.ngControl;
    return this.invalid || !!(c && c.invalid && (c.touched || c.dirty));
  }

  get resolvedPlaceholder(): string {
    return this.placeholder || this.i18n.t('common.select.placeholder');
  }

  isSelected(item: SelectItem): boolean {
    const v = this._value();
    if (this._multiple()) return Array.isArray(v) && v.some((x) => this.same(item.value, x));
    return !this.isEmpty(v) && this.same(item.value, v);
  }

  readonly footerCtx = computed<SelectFooterContext>(() => ({
    $implicit: this.query().trim(),
    close: () => this.close(true),
  }));

  ctx(item: SelectItem): SelectOptionContext {
    return { $implicit: item.option, label: item.label, selected: this.isSelected(item) };
  }

  // --- open / close -----------------------------------------------------------

  toggle(): void {
    this.isOpen() ? this.close(true) : this.open();
  }

  open(): void {
    if (this.isOpen() || this.isDisabled()) return;
    const w = this.triggerRef.nativeElement.getBoundingClientRect().width;
    const vw = typeof window !== 'undefined' ? window.innerWidth : 1024;
    this.panelWidth.set(Math.min(Math.max(w, 220), Math.max(vw - 16, 160)));
    this.query.set('');
    const sel = this.selectedItems()[0];
    const nav = this.navItems();
    let idx = sel ? nav.findIndex((i) => i.id === sel.id) : -1;
    if (idx < 0) idx = this.nextEnabled(-1, 1);
    this.activeIndex.set(idx);
    this.isOpen.set(true);
    this.opened.emit();
    this.cdr.markForCheck();
  }

  /** Called by the overlay once attached. */
  onAttach(): void {
    // CDK's reposition strategy only hears document scrolls; element scroll
    // events don't bubble, so scrolling a modal / overflow container would
    // leave the panel behind. Listen in the capture phase instead.
    this.scrollCleanup?.();
    if (typeof document !== 'undefined') {
      const handler = (e: Event) => {
        const pane = this.connected?.overlayRef?.overlayElement;
        if (pane && e.target instanceof Node && pane.contains(e.target)) return; // the list itself
        this.connected?.overlayRef?.updatePosition();
      };
      document.addEventListener('scroll', handler, { capture: true, passive: true });
      this.scrollCleanup = () => {
        document.removeEventListener('scroll', handler, { capture: true });
        this.scrollCleanup = null;
      };
    }
    afterNextRender(
      () => {
        if (this.searchable) this.searchRef?.nativeElement.focus({ preventScroll: true });
        this.scrollActiveIntoView();
      },
      { injector: this.injector },
    );
  }

  close(restoreFocus: boolean): void {
    this.scrollCleanup?.();
    if (!this.isOpen()) return;
    this.isOpen.set(false);
    this.query.set('');
    this.onTouched();
    this.closed.emit();
    if (restoreFocus) this.triggerRef.nativeElement.focus({ preventScroll: true });
    this.cdr.markForCheck();
  }

  onOutsideClick(event: MouseEvent): void {
    const t = event.target as Node | null;
    if (t && this.host.nativeElement.contains(t)) return; // the trigger toggles itself
    this.close(false);
  }

  onDetach(): void {
    this.scrollCleanup?.();
    if (this.isOpen()) this.close(false);
  }

  // --- selection --------------------------------------------------------------

  pick(item: SelectItem): void {
    if (item.disabled) return;
    if (this._multiple()) {
      const cur: any[] = Array.isArray(this._value()) ? [...this._value()] : [];
      const at = cur.findIndex((x) => this.same(item.value, x));
      if (at >= 0) cur.splice(at, 1);
      else cur.push(item.value);
      this.commit(cur);
      const idx = this.navItems().findIndex((i) => i.id === item.id);
      if (idx >= 0) this.activeIndex.set(idx);
      return;
    }
    this.commit(item.value);
    this.close(true);
  }

  clear(event?: Event): void {
    event?.stopPropagation();
    if (this.isDisabled()) return;
    this.commit(this._multiple() ? [] : null);
    this.onTouched();
    this.triggerRef.nativeElement.focus({ preventScroll: true });
  }

  selectAllFiltered(): void {
    const cur: any[] = Array.isArray(this._value()) ? [...this._value()] : [];
    for (const it of this.navItems()) {
      if (!it.disabled && !cur.some((x) => this.same(it.value, x))) cur.push(it.value);
    }
    this.commit(cur);
  }

  clearFiltered(): void {
    const visible = this.navItems();
    const cur: any[] = Array.isArray(this._value()) ? this._value() : [];
    // With a search active, only the visible matches are cleared.
    const next = this.query().trim()
      ? cur.filter((x) => !visible.some((it) => !it.disabled && this.same(it.value, x)))
      : [];
    this.commit(next);
  }

  private commit(v: any): void {
    this._value.set(v);
    this.onChange(v);
    this.valueChange.emit(v);
    this.selectionChange.emit(v);
    this.cdr.markForCheck();
  }

  // --- keyboard ---------------------------------------------------------------

  onTriggerKeydown(e: KeyboardEvent): void {
    if (this.isDisabled()) return;
    const open = this.isOpen();
    switch (e.key) {
      case 'ArrowDown':
      case 'ArrowUp':
        e.preventDefault();
        if (!open) this.open();
        else this.move(e.key === 'ArrowDown' ? 1 : -1);
        return;
      case 'Enter':
      case ' ':
        if (open && !this.searchable) {
          e.preventDefault();
          this.pickActive();
          return;
        }
        if (!open) {
          e.preventDefault();
          this.open();
        }
        return;
      case 'Escape':
        if (open) {
          e.preventDefault();
          e.stopPropagation();
          this.close(true);
        }
        return;
      case 'Tab':
        if (open) this.close(false);
        return;
      case 'Home':
      case 'End':
        if (open) {
          e.preventDefault();
          this.activeIndex.set(e.key === 'Home' ? this.nextEnabled(-1, 1) : this.nextEnabled(this.navItems().length, -1));
          this.scrollActiveIntoView();
        }
        return;
      case 'Backspace':
      case 'Delete':
        if (!open && this.clearable && this.hasValue()) {
          e.preventDefault();
          this.clear();
        }
        return;
    }
    if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
      if (this.searchable) {
        // Start searching with the typed character.
        e.preventDefault();
        this.open();
        this.query.set(e.key);
        this.searchChange.emit(e.key);
        this.activeIndex.set(this.nextEnabled(-1, 1));
      } else {
        this.typeAhead(e.key);
      }
    }
  }

  onSearchKeydown(e: KeyboardEvent): void {
    switch (e.key) {
      case 'ArrowDown':
      case 'ArrowUp':
        e.preventDefault();
        this.move(e.key === 'ArrowDown' ? 1 : -1);
        return;
      case 'Home':
      case 'End':
        if (!this.query()) {
          e.preventDefault();
          this.activeIndex.set(e.key === 'Home' ? this.nextEnabled(-1, 1) : this.nextEnabled(this.navItems().length, -1));
          this.scrollActiveIntoView();
        }
        return;
      case 'Enter':
        e.preventDefault();
        this.pickActive();
        return;
      case 'Escape':
        e.preventDefault();
        e.stopPropagation();
        this.close(true);
        return;
      case 'Tab':
        // Let the browser's default Tab move on from the trigger to the next
        // (or, with Shift, previous) focusable element.
        this.triggerRef.nativeElement.focus({ preventScroll: true });
        this.close(false);
        return;
    }
  }

  onSearchInput(text: string): void {
    this.query.set(text);
    this.searchChange.emit(text);
    this.activeIndex.set(this.nextEnabled(-1, 1));
    if (this.listboxRef) this.listboxRef.nativeElement.scrollTop = 0;
  }

  private pickActive(): void {
    const item = this.navItems()[this.activeIndex()];
    if (item) this.pick(item);
  }

  private move(dir: 1 | -1): void {
    const next = this.nextEnabled(this.activeIndex(), dir);
    if (next >= 0) this.activeIndex.set(next);
    this.scrollActiveIntoView();
  }

  private nextEnabled(from: number, dir: 1 | -1): number {
    const nav = this.navItems();
    for (let i = from + dir; i >= 0 && i < nav.length; i += dir) {
      if (!nav[i].disabled) return i;
    }
    return from >= 0 && from < nav.length ? from : -1;
  }

  private typeAhead(ch: string): void {
    this.typeBuffer += foldText(ch);
    if (this.typeTimer) clearTimeout(this.typeTimer);
    this.typeTimer = setTimeout(() => (this.typeBuffer = ''), 600);
    const nav = this.navItems();
    if (!nav.length) return;
    const start = this.typeBuffer.length === 1 ? this.activeIndex() + 1 : Math.max(this.activeIndex(), 0);
    for (let k = 0; k < nav.length; k++) {
      const i = (start + k) % nav.length;
      if (!nav[i].disabled && foldText(nav[i].label).startsWith(this.typeBuffer)) {
        this.activeIndex.set(i);
        if (!this.isOpen() && !this._multiple()) this.commit(nav[i].value);
        this.scrollActiveIntoView();
        return;
      }
    }
  }

  private scrollActiveIntoView(): void {
    const id = this.activeId();
    if (!id || typeof document === 'undefined') return;
    afterNextRender(
      () => document.getElementById(id)?.scrollIntoView({ block: 'nearest' }),
      { injector: this.injector },
    );
  }

  onRowHover(index: number, item: SelectItem): void {
    if (!item.disabled) this.activeIndex.set(index);
  }

  // --- comparison -------------------------------------------------------------

  private isEmpty(v: any): boolean {
    return v === null || v === undefined || v === '';
  }

  /**
   * compareWith when given; otherwise strict equality, except that a numeric
   * string written into a select whose option values are numbers matches that
   * number (route params / query strings arrive as strings).
   */
  private same(optionValue: any, v: any): boolean {
    const cmp = this._compareWith();
    if (cmp) return cmp(optionValue, v);
    if (optionValue === v) return true;
    return typeof optionValue === 'number' && typeof v === 'string' && v.trim() !== '' && Number(v) === optionValue;
  }

  private groupOrder(list: SelectItem[]): SelectItem[] {
    const groups = new Map<string | null, SelectItem[]>();
    for (const it of list) {
      const g = groups.get(it.group);
      if (g) g.push(it);
      else groups.set(it.group, [it]);
    }
    return [...groups.values()].flat();
  }

  // --- ControlValueAccessor ---------------------------------------------------

  writeValue(v: any): void {
    this._value.set(v);
    this.cdr.markForCheck();
  }

  registerOnChange(fn: (v: any) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  setDisabledState(isDisabled: boolean): void {
    this._disabledForm.set(isDisabled);
    if (isDisabled) this.close(false);
    this.cdr.markForCheck();
  }

  onTriggerBlur(): void {
    if (!this.isOpen()) this.onTouched();
  }

  focus(): void {
    this.triggerRef.nativeElement.focus();
  }
}

export const SELECT_IMPORTS = [SelectComponent, SelectOptionDirective, SelectLabelDirective, SelectFooterDirective] as const;
