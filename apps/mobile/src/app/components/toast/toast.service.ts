import { Injectable, signal } from '@angular/core';

export type ToastVariant = 'success' | 'error' | 'warning' | 'info';

export interface ToastOptions {
  /** A bold first line above the message. */
  title?: string;
  /** Milliseconds on screen; 0 keeps it until dismissed. */
  duration?: number;
}

export interface Toast extends ToastOptions {
  id: number;
  variant: ToastVariant;
  message: string;
  /** Set while the leave animation plays, just before removal. */
  leaving: boolean;
}

const DEFAULTS: Record<ToastVariant, number> = {
  success: 3200,
  info: 3600,
  warning: 4200,
  error: 5200,
};

/**
 * The app's own toast/notification service — a premium replacement for Ionic's
 * ToastController. Toasts stack from the top, wear the design-system surface
 * with a semantic accent per state, auto-dismiss (longer for errors), and can
 * be tapped away. Inject it and call success/error/warning/info, or show() for
 * full control. <app-toast-host> (mounted once at the app root) renders them.
 */
@Injectable({ providedIn: 'root' })
export class ToastService {
  private readonly _toasts = signal<Toast[]>([]);
  readonly toasts = this._toasts.asReadonly();

  private seq = 0;

  show(variant: ToastVariant, message: string, options: ToastOptions = {}): number {
    const id = ++this.seq;
    const duration = options.duration ?? DEFAULTS[variant];
    this._toasts.update((list) => [...list, { id, variant, message, leaving: false, ...options, duration }]);
    if (duration > 0) {
      setTimeout(() => this.dismiss(id), duration);
    }
    return id;
  }

  success(message: string, options?: ToastOptions): number { return this.show('success', message, options); }
  error(message: string, options?: ToastOptions): number { return this.show('error', message, options); }
  warning(message: string, options?: ToastOptions): number { return this.show('warning', message, options); }
  info(message: string, options?: ToastOptions): number { return this.show('info', message, options); }

  /** Begin the leave animation, then remove the toast once it has played. */
  dismiss(id: number): void {
    let present = false;
    this._toasts.update((list) => {
      present = list.some((t) => t.id === id && !t.leaving);
      return list.map((t) => (t.id === id ? { ...t, leaving: true } : t));
    });
    if (present) {
      setTimeout(() => this._toasts.update((list) => list.filter((t) => t.id !== id)), 240);
    }
  }

  clear(): void {
    this._toasts.set([]);
  }
}
