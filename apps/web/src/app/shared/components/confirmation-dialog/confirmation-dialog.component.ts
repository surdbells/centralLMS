// shared/components/confirmation-dialog/confirmation-dialog.component.ts
//
// A premium, self-mounting confirmation / prompt dialog that replaces the
// browser's native confirm() and prompt(). The service creates the component on
// demand and appends it to <body>, so it works inside any layout without a host
// in the template, and returns a Promise the caller can await.

import {
  ApplicationRef,
  Component,
  ElementRef,
  EnvironmentInjector,
  EventEmitter,
  HostListener,
  Injectable,
  Input,
  Output,
  ViewChild,
  createComponent,
  inject,
  type EmbeddedViewRef,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';

export type ConfirmType = 'danger' | 'warning' | 'info' | 'success';

export interface ConfirmDialogData {
  title?: string;
  message: string;
  confirmText?: string;
  cancelText?: string;
  type?: ConfirmType;
  showCancel?: boolean;
  /** 'confirm' resolves boolean; 'prompt' resolves the typed string or null. */
  mode?: 'confirm' | 'prompt';
  inputLabel?: string;
  inputPlaceholder?: string;
  inputValue?: string;
  inputRequired?: boolean;
}

@Component({
  selector: 'app-confirmation-dialog',
  standalone: true,
  imports: [CommonModule, FormsModule, TranslatePipe],
  template: `
    <div class="cd-backdrop" (mousedown)="onBackdrop($event)">
      <div class="cd-card" [class.shake]="shaking" role="dialog" aria-modal="true">
        <div class="cd-icon" [ngClass]="'t-' + type">
          <i class="bi" [ngClass]="iconClass"></i>
        </div>
        <h3 class="cd-title">{{ data.title }}</h3>
        <p class="cd-message" [innerHTML]="data.message"></p>

        <div class="cd-field" *ngIf="data.mode === 'prompt'">
          <label *ngIf="data.inputLabel" class="cd-label">{{ data.inputLabel }}</label>
          <input #promptInput type="text" class="cd-input" [(ngModel)]="inputValue"
                 [placeholder]="data.inputPlaceholder || ''" (keyup.enter)="onConfirm()" />
        </div>

        <div class="cd-actions">
          <button type="button" class="cd-btn cd-btn-ghost" *ngIf="data.showCancel !== false"
                  (click)="onCancel()">
            {{ data.cancelText || ('common.cancel' | translate) }}
          </button>
          <button #confirmBtn type="button" class="cd-btn" [ngClass]="'cd-btn-' + type"
                  (click)="onConfirm()">
            {{ data.confirmText || ('common.confirm' | translate) }}
          </button>
        </div>
      </div>
    </div>
  `,
  styles: [`
    :host { position: fixed; inset: 0; z-index: 2000; }
    .cd-backdrop {
      position: fixed; inset: 0;
      background: rgba(15, 23, 42, 0.5);
      backdrop-filter: blur(4px);
      -webkit-backdrop-filter: blur(4px);
      display: grid; place-items: center;
      padding: 1rem;
      animation: cd-fade 0.16s ease-out;
    }
    .cd-card {
      width: 100%; max-width: 420px;
      background: #fff;
      border-radius: 18px;
      padding: 1.9rem 1.75rem 1.5rem;
      text-align: center;
      box-shadow: 0 24px 60px rgba(2, 6, 23, 0.28);
      animation: cd-pop 0.2s cubic-bezier(0.22, 1, 0.36, 1);
    }
    .cd-card.shake { animation: cd-shake 0.3s ease; }
    .cd-icon {
      width: 64px; height: 64px; margin: 0 auto 1rem;
      border-radius: 50%;
      display: grid; place-items: center;
      font-size: 1.8rem;
    }
    .cd-icon.t-danger  { background: #fee2e2; color: #dc2626; box-shadow: 0 0 0 8px rgba(220,38,38,0.08); }
    .cd-icon.t-warning { background: #fef3c7; color: #d97706; box-shadow: 0 0 0 8px rgba(217,119,6,0.08); }
    .cd-icon.t-info    { background: #dbeafe; color: #2563eb; box-shadow: 0 0 0 8px rgba(37,99,235,0.08); }
    .cd-icon.t-success { background: #dcfce7; color: #16a34a; box-shadow: 0 0 0 8px rgba(22,163,74,0.08); }
    .cd-title { margin: 0 0 0.4rem; font-size: 1.2rem; font-weight: 700; color: #0f172a; }
    .cd-message { margin: 0; color: #64748b; font-size: 0.92rem; line-height: 1.55; }
    .cd-field { margin-top: 1rem; text-align: left; }
    .cd-label { display: block; font-size: 0.8rem; font-weight: 600; color: #334155; margin-bottom: 0.3rem; }
    .cd-input {
      width: 100%; padding: 0.6rem 0.75rem;
      border: 1px solid #d7dce1; border-radius: 10px; font-size: 0.92rem;
      outline: none; transition: border-color 0.15s, box-shadow 0.15s;
    }
    .cd-input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }
    .cd-actions { display: flex; gap: 0.6rem; justify-content: center; margin-top: 1.5rem; }
    .cd-btn {
      appearance: none; border: 0; cursor: pointer;
      padding: 0.6rem 1.25rem; border-radius: 10px;
      font-weight: 600; font-size: 0.9rem; min-width: 110px;
      transition: transform 0.08s ease, filter 0.15s ease, background 0.15s ease;
    }
    .cd-btn:active { transform: translateY(1px); }
    .cd-btn-ghost { background: #f1f5f9; color: #475569; }
    .cd-btn-ghost:hover { background: #e2e8f0; }
    .cd-btn-danger  { background: #dc2626; color: #fff; }
    .cd-btn-danger:hover  { filter: brightness(1.07); }
    .cd-btn-warning { background: #d97706; color: #fff; }
    .cd-btn-warning:hover { filter: brightness(1.07); }
    .cd-btn-info    { background: var(--brand-primary, #2563eb); color: #fff; }
    .cd-btn-info:hover    { filter: brightness(1.07); }
    .cd-btn-success { background: #16a34a; color: #fff; }
    .cd-btn-success:hover { filter: brightness(1.07); }
    @keyframes cd-fade { from { opacity: 0; } to { opacity: 1; } }
    @keyframes cd-pop { from { transform: scale(0.94) translateY(8px); opacity: 0; } to { transform: scale(1) translateY(0); opacity: 1; } }
    @keyframes cd-shake { 0%,100% { transform: translateX(0); } 20%,60% { transform: translateX(-6px); } 40%,80% { transform: translateX(6px); } }
    @media (prefers-color-scheme: dark) {
      .cd-card { background: #1e293b; }
      .cd-title { color: #f1f5f9; }
      .cd-message { color: #94a3b8; }
      .cd-input { background: #0f172a; border-color: #334155; color: #e2e8f0; }
      .cd-btn-ghost { background: #334155; color: #cbd5e1; }
    }
  `],
})
export class ConfirmationDialogComponent {
  @Input() data: ConfirmDialogData = { message: '' };
  @Output() result = new EventEmitter<boolean | string | null>();

  @ViewChild('confirmBtn') confirmBtn?: ElementRef<HTMLButtonElement>;
  @ViewChild('promptInput') promptInput?: ElementRef<HTMLInputElement>;

  inputValue = '';
  shaking = false;

  get type(): ConfirmType {
    return this.data.type ?? 'info';
  }

  get iconClass(): string {
    return {
      danger: 'bi-exclamation-triangle-fill',
      warning: 'bi-exclamation-circle-fill',
      info: 'bi-question-circle-fill',
      success: 'bi-check-circle-fill',
    }[this.type];
  }

  ngOnInit(): void {
    this.inputValue = this.data.inputValue ?? '';
  }

  ngAfterViewInit(): void {
    // Land focus where the person acts, so Enter/Esc work immediately.
    setTimeout(() => (this.promptInput?.nativeElement ?? this.confirmBtn?.nativeElement)?.focus(), 0);
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    this.onCancel();
  }

  onConfirm(): void {
    if (this.data.mode === 'prompt') {
      const value = this.inputValue.trim();
      if (this.data.inputRequired && value === '') {
        this.shaking = true;
        setTimeout(() => (this.shaking = false), 320);
        return;
      }
      this.result.emit(value === '' ? null : value);
      return;
    }
    this.result.emit(true);
  }

  onCancel(): void {
    this.result.emit(this.data.mode === 'prompt' ? null : false);
  }

  onBackdrop(event: MouseEvent): void {
    if ((event.target as HTMLElement).classList.contains('cd-backdrop')) {
      this.onCancel();
    }
  }
}

/**
 * Opens the dialog and awaits the answer. Drop-in for native confirm()/prompt():
 *
 *   if (!(await this.dialog.confirm('Delete this?'))) return;
 *   const name = await this.dialog.prompt('New name');
 */
@Injectable({ providedIn: 'root' })
export class ConfirmDialogService {
  private readonly appRef = inject(ApplicationRef);
  private readonly envInjector = inject(EnvironmentInjector);
  private readonly i18n = inject(I18nService);

  /** Resolves true when confirmed, false when cancelled. */
  confirm(message: string, opts: Partial<ConfirmDialogData> = {}): Promise<boolean> {
    return this.open({
      title: this.i18n.t('common.please_confirm'),
      type: 'info',
      ...opts,
      message,
      mode: 'confirm',
    }) as Promise<boolean>;
  }

  danger(message: string, opts: Partial<ConfirmDialogData> = {}): Promise<boolean> {
    return this.confirm(message, { type: 'danger', confirmText: this.i18n.t('common.delete'), ...opts });
  }

  warning(message: string, opts: Partial<ConfirmDialogData> = {}): Promise<boolean> {
    return this.confirm(message, { type: 'warning', ...opts });
  }

  /** Resolves the typed value, or null if cancelled / left empty. */
  prompt(message: string, opts: Partial<ConfirmDialogData> = {}): Promise<string | null> {
    return this.open({
      title: this.i18n.t('common.please_confirm'),
      type: 'info',
      ...opts,
      message,
      mode: 'prompt',
    }) as Promise<string | null>;
  }

  private open(data: ConfirmDialogData): Promise<boolean | string | null> {
    return new Promise((resolve) => {
      const ref = createComponent(ConfirmationDialogComponent, {
        environmentInjector: this.envInjector,
      });
      ref.instance.data = data;

      const done = (value: boolean | string | null): void => {
        resolve(value);
        this.appRef.detachView(ref.hostView);
        ref.destroy();
      };
      ref.instance.result.subscribe(done);

      this.appRef.attachView(ref.hostView);
      document.body.appendChild((ref.hostView as EmbeddedViewRef<unknown>).rootNodes[0] as Node);
    });
  }
}
