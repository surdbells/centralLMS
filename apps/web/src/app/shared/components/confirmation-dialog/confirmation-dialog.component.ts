// components/confirmation-dialog/confirmation-dialog.component.ts
import { Component, Input, Output, EventEmitter, ChangeDetectionStrategy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';

export interface ConfirmDialogConfig {
  title: string;
  message: string;
  confirmText?: string;
  cancelText?: string;
  type?: 'info' | 'warning' | 'danger' | 'success';
  showCancel?: boolean;
  inputRequired?: boolean;
  inputLabel?: string;
  inputPlaceholder?: string;
}

@Component({
  selector: 'app-confirmation-dialog',
  standalone: true,
  imports: [CommonModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="modal-backdrop" *ngIf="isOpen" (click)="onBackdropClick($event)">
      <div class="modal-dialog" [class.modal-dialog-centered]="centered" role="dialog">
        <div class="modal-content">
          <div class="modal-header" [ngClass]="headerClass">
            <div class="modal-icon" *ngIf="showIcon">
              <i class="bi" [ngClass]="iconClass"></i>
            </div>
            <h5 class="modal-title">{{ config.title }}</h5>
            <button type="button" class="btn-close" (click)="close()" *ngIf="showCloseButton"></button>
          </div>
          
          <div class="modal-body">
            <p [innerHTML]="config.message"></p>
            
            <div class="input-group mt-3" *ngIf="config.inputRequired">
              <label class="form-label w-100" *ngIf="config.inputLabel">{{ config.inputLabel }}</label>
              <input 
                type="text" 
                class="form-control" 
                [placeholder]="config.inputPlaceholder || ''"
                [(ngModel)]="inputValue"
                (keyup.enter)="confirm()">
            </div>
          </div>
          
          <div class="modal-footer">
            <button 
              type="button" 
              class="btn btn-secondary" 
              (click)="cancel()"
              *ngIf="config.showCancel !== false">
              {{ config.cancelText || ('common.cancel' | translate) }}
            </button>
            <button 
              type="button" 
              class="btn" 
              [ngClass]="confirmButtonClass"
              (click)="confirm()"
              [disabled]="config.inputRequired && !inputValue?.trim()">
              {{ config.confirmText || ('common.confirm' | translate) }}
            </button>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .modal-backdrop {
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1050;
      animation: fadeIn 0.15s ease-out;
    }
    .modal-dialog {
      max-width: 500px;
      width: 90%;
      animation: slideIn 0.2s ease-out;
    }
    .modal-content {
      border: none;
      border-radius: 0.5rem;
      box-shadow: 0 0.5rem 1rem rgba(0, 0, 0, 0.15);
    }
    .modal-header {
      border-bottom: none;
      padding: 1.5rem 1.5rem 0.5rem;
    }
    .modal-header.header-danger { background: #fff5f5; }
    .modal-header.header-warning { background: #fffbeb; }
    .modal-header.header-success { background: #f0fdf4; }
    .modal-header.header-info { background: #eff6ff; }
    .modal-icon {
      width: 48px;
      height: 48px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-right: 1rem;
      font-size: 1.5rem;
    }
    .modal-icon.icon-danger { background: #fee2e2; color: #dc3545; }
    .modal-icon.icon-warning { background: #fef3c7; color: #f59e0b; }
    .modal-icon.icon-success { background: #d1fae5; color: #10b981; }
    .modal-icon.icon-info { background: #dbeafe; color: #3b82f6; }
    .modal-title { margin: 0; flex-grow: 1; }
    .modal-body { padding: 1rem 1.5rem; color: #6b7280; }
    .modal-footer { border-top: none; padding: 0.5rem 1.5rem 1.5rem; }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
    @keyframes slideIn { from { transform: translateY(-20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
  `]
})
export class ConfirmationDialogComponent {
  @Input() config: ConfirmDialogConfig = { title: '', message: '' };
  @Input() isOpen: boolean = false;
  @Input() centered: boolean = true;
  @Input() showIcon: boolean = true;
  @Input() showCloseButton: boolean = true;

  @Output() confirmed = new EventEmitter<string | boolean>();
  @Output() cancelled = new EventEmitter<void>();
  @Output() closed = new EventEmitter<void>();

  inputValue: string = '';

  get type(): string {
    return this.config.type || 'info';
  }

  get headerClass(): string {
    return `header-${this.type}`;
  }

  get iconClass(): string {
    const icons: Record<string, string> = {
      'danger': 'bi-exclamation-triangle-fill icon-danger',
      'warning': 'bi-exclamation-circle-fill icon-warning',
      'success': 'bi-check-circle-fill icon-success',
      'info': 'bi-info-circle-fill icon-info'
    };
    return icons[this.type] || icons['info'];
  }

  get confirmButtonClass(): string {
    const classes: Record<string, string> = {
      'danger': 'btn-danger',
      'warning': 'btn-warning',
      'success': 'btn-success',
      'info': 'btn-primary'
    };
    return classes[this.type] || 'btn-primary';
  }

  confirm(): void {
    if (this.config.inputRequired && !this.inputValue?.trim()) return;
    this.confirmed.emit(this.config.inputRequired ? this.inputValue : true);
    this.reset();
  }

  cancel(): void {
    this.cancelled.emit();
    this.reset();
  }

  close(): void {
    this.closed.emit();
    this.reset();
  }

  onBackdropClick(event: MouseEvent): void {
    if ((event.target as HTMLElement).classList.contains('modal-backdrop')) {
      this.close();
    }
  }

  private reset(): void {
    this.inputValue = '';
  }
}


// components/confirmation-dialog/confirmation-dialog.service.ts
import { Injectable } from '@angular/core';
import { Subject, Observable } from 'rxjs';

@Injectable({ providedIn: 'root' })
export class ConfirmationDialogService {
  private confirmSubject = new Subject<{ config: ConfirmDialogConfig; resolve: (value: any) => void }>();
  
  confirm$ = this.confirmSubject.asObservable();

  confirm(config: ConfirmDialogConfig): Promise<string | boolean> {
    return new Promise((resolve) => {
      this.confirmSubject.next({ config, resolve });
    });
  }

  danger(title: string, message: string, confirmText: string = 'Delete'): Promise<boolean> {
    return this.confirm({ title, message, confirmText, type: 'danger' }) as Promise<boolean>;
  }

  warning(title: string, message: string, confirmText: string = 'Continue'): Promise<boolean> {
    return this.confirm({ title, message, confirmText, type: 'warning' }) as Promise<boolean>;
  }

  info(title: string, message: string, confirmText: string = 'OK'): Promise<boolean> {
    return this.confirm({ title, message, confirmText, type: 'info', showCancel: false }) as Promise<boolean>;
  }

  prompt(title: string, message: string, inputLabel: string, inputPlaceholder?: string): Promise<string> {
    return this.confirm({ 
      title, message, 
      inputRequired: true, 
      inputLabel, 
      inputPlaceholder,
      type: 'info' 
    }) as Promise<string>;
  }
}
