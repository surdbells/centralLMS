import {
  Component, ChangeDetectionStrategy, forwardRef, input, output, signal, computed,
} from '@angular/core';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';
import { IonIcon } from '@ionic/angular/standalone';

let uid = 0;

/**
 * A premium solid form field — the app's replacement for ion-item + ion-input.
 *
 * A filled field on the design-system surface with a hairline that lifts to the
 * brand accent + a soft focus ring; optional leading icon; a password reveal
 * toggle (type="password") or a clear button (clearable); and error / hint text
 * below. It's a ControlValueAccessor, so it drops into [(ngModel)] and reactive
 * forms unchanged:
 *
 *   <app-field label="Email" type="email" icon="mail-outline"
 *              [(ngModel)]="email" [error]="emailError()"></app-field>
 */
@Component({
  selector: 'app-field',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [IonIcon],
  providers: [
    { provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => FieldComponent), multi: true },
  ],
  template: `
    @if (label()) {
      <label class="lbl" [attr.for]="id">
        {{ label() }}@if (required()) { <span class="req" aria-hidden="true">*</span> }
      </label>
    }

    <div class="control" [class.focused]="focused()" [class.invalid]="!!error()" [class.disabled]="disabled()">
      @if (icon()) { <ion-icon class="lead" [name]="icon()" aria-hidden="true"></ion-icon> }
      <input
        [id]="id"
        [type]="inputType()"
        [value]="value()"
        [placeholder]="placeholder()"
        [disabled]="disabled()"
        [attr.inputmode]="inputmode() || null"
        [attr.autocomplete]="autocomplete() || null"
        [attr.enterkeyhint]="enterkeyhint() || null"
        [attr.aria-required]="required() ? 'true' : null"
        [attr.aria-invalid]="!!error() || null"
        [attr.aria-describedby]="(error() || hint()) ? id + '-msg' : null"
        (input)="onInput($event)"
        (focus)="focused.set(true)"
        (blur)="onBlur()"
        (keyup.enter)="enter.emit()" />

      @if (type() === 'password') {
        <button type="button" class="trail" (click)="reveal.set(!reveal())"
                [attr.aria-label]="reveal() ? 'Hide password' : 'Show password'">
          <ion-icon [name]="reveal() ? 'eye-off-outline' : 'eye-outline'" aria-hidden="true"></ion-icon>
        </button>
      } @else if (clearable() && value() && !disabled()) {
        <button type="button" class="trail" aria-label="Clear" (click)="clear()">
          <ion-icon name="close-circle" aria-hidden="true"></ion-icon>
        </button>
      }
    </div>

    @if (error()) {
      <p class="msg err" [id]="id + '-msg'">{{ error() }}</p>
    } @else if (hint()) {
      <p class="msg hint" [id]="id + '-msg'">{{ hint() }}</p>
    }
  `,
  styles: [`
    :host { display: block; }

    .lbl {
      display: block; font-size: 0.82rem; font-weight: 600;
      color: var(--app-muted); margin: 0 0 6px 2px;
    }
    .req { color: var(--ion-color-danger); }

    .control {
      display: flex; align-items: center; gap: 9px;
      background: var(--app-field);
      border: 1.5px solid var(--app-line); border-radius: var(--app-r-sm);
      padding: 0 12px;
      transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
    }
    .control.focused {
      border-color: var(--ion-color-primary);
      box-shadow: 0 0 0 3px var(--app-ring);
    }
    .control.invalid { border-color: var(--ion-color-danger); }
    .control.invalid.focused {
      box-shadow: 0 0 0 3px color-mix(in srgb, var(--ion-color-danger) 26%, transparent);
    }
    .control.disabled { opacity: 0.55; }

    input {
      flex: 1; min-width: 0; border: 0; outline: 0; background: transparent;
      color: var(--app-ink); font: inherit; font-size: 1rem; padding: 13px 0;
    }
    input::placeholder { color: var(--app-muted); }

    .lead { flex: none; font-size: 19px; color: var(--app-muted); }
    .trail {
      flex: none; border: 0; background: transparent; padding: 4px; margin-right: -4px;
      color: var(--app-muted); display: grid; cursor: pointer;
    }
    .trail ion-icon { font-size: 19px; }
    .trail:active { opacity: 0.6; }

    .msg { margin: 6px 2px 0; font-size: 0.8rem; line-height: 1.35; }
    .err { color: var(--ion-color-danger); }
    .hint { color: var(--app-muted); }
  `],
})
export class FieldComponent implements ControlValueAccessor {
  readonly label = input('');
  readonly type = input<'text' | 'email' | 'password' | 'number' | 'tel' | 'url' | 'search'>('text');
  readonly placeholder = input('');
  readonly icon = input('');
  readonly hint = input('');
  readonly error = input('');
  readonly required = input(false);
  readonly clearable = input(false);
  readonly inputmode = input('');
  readonly autocomplete = input('');
  readonly enterkeyhint = input('');

  readonly id = `axf-${++uid}`;
  readonly value = signal('');
  readonly focused = signal(false);
  readonly reveal = signal(false);
  readonly disabled = signal(false);

  readonly inputType = computed(() =>
    this.type() === 'password' && this.reveal() ? 'text' : this.type(),
  );

  readonly enter = output<void>();

  private onChange: (value: string) => void = () => undefined;
  private onTouched: () => void = () => undefined;

  onInput(event: Event): void {
    const v = (event.target as HTMLInputElement).value;
    this.value.set(v);
    this.onChange(v);
  }

  onBlur(): void {
    this.focused.set(false);
    this.onTouched();
  }

  clear(): void {
    this.value.set('');
    this.onChange('');
  }

  // ControlValueAccessor
  writeValue(value: string | null): void { this.value.set(value ?? ''); }
  registerOnChange(fn: (value: string) => void): void { this.onChange = fn; }
  registerOnTouched(fn: () => void): void { this.onTouched = fn; }
  setDisabledState(isDisabled: boolean): void { this.disabled.set(isDisabled); }
}
