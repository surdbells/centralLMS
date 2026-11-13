import { Component, signal } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { FormControl, FormsModule, ReactiveFormsModule } from '@angular/forms';
import { By } from '@angular/platform-browser';
import { provideRouter } from '@angular/router';
import { OverlayContainer } from '@angular/cdk/overlay';
import { BehaviorSubject } from 'rxjs';
import { I18nService } from '../../i18n.service';
import { SelectComponent, foldText } from './select.component';

class I18nStub {
  readonly lang$ = new BehaviorSubject<'en' | 'fr' | 'ar'>('en');
  get lang() { return this.lang$.value; }
  t(key: string): string {
    return key === 'status.active' ? 'Actif' : key;
  }
}

@Component({
  standalone: true,
  imports: [SelectComponent, FormsModule, ReactiveFormsModule],
  template: `
    <app-select inputId="model" [options]="options" bindLabel="name" bindValue="id"
                descriptionKey="hint" [(ngModel)]="model" />
    <app-select inputId="reactive" [options]="primitives" [formControl]="ctrl" />
    <app-select inputId="multi" [options]="options" bindLabel="name" bindValue="id"
                [multiple]="true" [value]="many()" (selectionChange)="many.set($event)" />
    <app-select inputId="plain" [options]="options" bindLabel="name" bindValue="id"
                [searchable]="false" [value]="plain()" (selectionChange)="plain.set($event)" />
  `,
})
class HostComponent {
  options = [
    { id: 1, name: 'Lagos', hint: 'South west' },
    { id: 2, name: 'Abuja', hint: 'Federal capital' },
    { id: 3, name: 'Enugu', hint: 'South east', disabled: true },
    { id: 4, name: 'Kaduna', hint: 'North west' },
    { id: 5, name: 'Pétion-Ville', hint: 'Elsewhere' },
  ];
  primitives = ['draft', 'published', 'archived'];
  model: number | null = 2;
  ctrl = new FormControl<string | null>('published');
  many = signal<number[]>([]);
  plain = signal<number | null>(null);
}

describe('SelectComponent', () => {
  let fixture: ComponentFixture<HostComponent>;
  let host: HostComponent;
  let overlay: HTMLElement;

  const select = (id: string): SelectComponent =>
    fixture.debugElement
      .queryAll(By.directive(SelectComponent))
      .map((d) => d.componentInstance as SelectComponent)
      .find((c) => c.inputId === id)!;

  const trigger = (id: string): HTMLButtonElement =>
    fixture.nativeElement.querySelector(`#${id}`) as HTMLButtonElement;

  const key = (el: HTMLElement, k: string) => {
    el.dispatchEvent(new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }));
    fixture.detectChanges();
  };

  const options = () => Array.from(overlay.querySelectorAll<HTMLElement>('[role="option"]'));

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [HostComponent],
      providers: [provideRouter([]), { provide: I18nService, useClass: I18nStub }],
    }).compileComponents();

    fixture = TestBed.createComponent(HostComponent);
    host = fixture.componentInstance;
    fixture.detectChanges();
    await fixture.whenStable();
    fixture.detectChanges();
    overlay = TestBed.inject(OverlayContainer).getContainerElement();
  });

  afterEach(() => {
    document.querySelectorAll('.cdk-overlay-container').forEach((n) => (n.innerHTML = ''));
  });

  // --- ControlValueAccessor ---------------------------------------------------

  it('writes the ngModel value into the trigger', async () => {
    await fixture.whenStable();
    fixture.detectChanges();
    expect(trigger('model').textContent).toContain('Abuja');
  });

  it('writes and reports reactive form values for primitive options', () => {
    expect(trigger('reactive').textContent).toContain('published');

    host.ctrl.setValue('archived');
    fixture.detectChanges();
    expect(trigger('reactive').textContent).toContain('archived');

    trigger('reactive').click();
    fixture.detectChanges();
    options()[0].click();
    fixture.detectChanges();
    expect(host.ctrl.value).toBe('draft');
    expect(host.ctrl.touched).toBeTrue();
  });

  it('honours setDisabledState', () => {
    host.ctrl.disable();
    fixture.detectChanges();
    expect(trigger('reactive').disabled).toBeTrue();
    trigger('reactive').click();
    fixture.detectChanges();
    expect(select('reactive').isOpen()).toBeFalse();
  });

  it('keeps value types: a number option does not match an unrelated string', () => {
    const cmp = select('model');
    cmp.writeValue('2');
    // numeric strings are accepted against numeric option values (query params)
    expect(cmp.selectedItems()[0]?.label).toBe('Abuja');
    cmp.writeValue('x2');
    expect(cmp.selectedItems().length).toBe(0);
  });

  // --- filtering --------------------------------------------------------------

  it('filters case- and accent-insensitively on label and description', () => {
    const cmp = select('model');
    trigger('model').click();
    fixture.detectChanges();

    cmp.onSearchInput('PETION');
    fixture.detectChanges();
    expect(options().map((o) => o.textContent!.trim())).toEqual([jasmine.stringContaining('Pétion-Ville')]);
    expect(overlay.querySelector('mark')?.textContent).toBe('Pétion');

    cmp.onSearchInput('south');
    fixture.detectChanges();
    expect(options().length).toBe(2); // Lagos (south west) + Enugu (south east)

    cmp.onSearchInput('zzz');
    fixture.detectChanges();
    expect(options().length).toBe(0);
    expect(overlay.textContent).toContain('common.select.no_results');
  });

  it('folds accents', () => {
    expect(foldText('Éñúgü')).toBe('enugu');
  });

  // --- keyboard ---------------------------------------------------------------

  it('opens with ArrowDown, skips disabled options, selects with Enter and closes', () => {
    const t = trigger('plain');
    key(t, 'ArrowDown');
    const cmp = select('plain');
    expect(cmp.isOpen()).toBeTrue();
    expect(t.getAttribute('aria-expanded')).toBe('true');

    // Lagos (0) -> Abuja (1) -> Kaduna (3; Enugu is disabled)
    key(t, 'ArrowDown');
    key(t, 'ArrowDown');
    expect(cmp.navItems()[cmp.activeIndex()].label).toBe('Kaduna');
    expect(t.getAttribute('aria-activedescendant')).toBe(cmp.navItems()[cmp.activeIndex()].id);

    key(t, 'Enter');
    expect(host.plain()).toBe(4);
    expect(cmp.isOpen()).toBeFalse();
  });

  it('closes on Escape without changing the value', () => {
    const t = trigger('plain');
    key(t, 'Enter');
    key(t, 'End');
    key(t, 'Escape');
    expect(select('plain').isOpen()).toBeFalse();
    expect(host.plain()).toBeNull();
  });

  it('type-ahead selects the matching option when not searchable', () => {
    key(trigger('plain'), 'k');
    expect(host.plain()).toBe(4);
  });

  it('search box keys navigate and select', () => {
    trigger('model').click();
    fixture.detectChanges();
    const input = overlay.querySelector<HTMLInputElement>('.as-search-input')!;
    input.value = 'a';
    input.dispatchEvent(new Event('input'));
    fixture.detectChanges();
    // "a" matches Lagos, Abuja, Enugu ("South east", disabled), Kaduna
    key(input, 'ArrowDown');
    key(input, 'ArrowDown');
    key(input, 'Enter');
    expect(host.model).toBe(4);
  });

  // --- multiple ---------------------------------------------------------------

  it('toggles values in multiple mode and keeps the panel open', () => {
    trigger('multi').click();
    fixture.detectChanges();
    options()[0].click();
    fixture.detectChanges();
    options()[1].click();
    fixture.detectChanges();
    expect(host.many()).toEqual([1, 2]);
    expect(select('multi').isOpen()).toBeTrue();

    options()[0].click();
    fixture.detectChanges();
    expect(host.many()).toEqual([2]);
  });

  it('select all / clear act on enabled, visible options', () => {
    const cmp = select('multi');
    trigger('multi').click();
    fixture.detectChanges();
    cmp.selectAllFiltered();
    fixture.detectChanges();
    expect(host.many()).toEqual([1, 2, 4, 5]); // Enugu is disabled
    expect(trigger('multi').textContent).toContain('+2');

    cmp.clearFiltered();
    fixture.detectChanges();
    expect(host.many()).toEqual([]);
  });
});
