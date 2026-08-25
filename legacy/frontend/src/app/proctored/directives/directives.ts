// directives/fullscreen.directive.ts
import { Directive, ElementRef, HostListener, Output, EventEmitter } from '@angular/core';

@Directive({
  selector: '[appFullscreen]',
  standalone: true
})
export class FullscreenDirective {
  @Output() fullscreenChange = new EventEmitter<boolean>();

  constructor(private el: ElementRef) {}

  @HostListener('document:fullscreenchange')
  @HostListener('document:webkitfullscreenchange')
  @HostListener('document:mozfullscreenchange')
  @HostListener('document:MSFullscreenChange')
  onFullscreenChange(): void {
    this.fullscreenChange.emit(this.isFullscreen);
  }

  get isFullscreen(): boolean {
    return !!(
      document.fullscreenElement ||
      (document as any).webkitFullscreenElement ||
      (document as any).mozFullScreenElement ||
      (document as any).msFullscreenElement
    );
  }

  enterFullscreen(): Promise<void> {
    const elem = this.el.nativeElement;
    if (elem.requestFullscreen) return elem.requestFullscreen();
    if (elem.webkitRequestFullscreen) return elem.webkitRequestFullscreen();
    if (elem.mozRequestFullScreen) return elem.mozRequestFullScreen();
    if (elem.msRequestFullscreen) return elem.msRequestFullscreen();
    return Promise.reject('Fullscreen not supported');
  }

  exitFullscreen(): Promise<void> {
    if (document.exitFullscreen) return document.exitFullscreen();
    if ((document as any).webkitExitFullscreen) return (document as any).webkitExitFullscreen();
    if ((document as any).mozCancelFullScreen) return (document as any).mozCancelFullScreen();
    if ((document as any).msExitFullscreen) return (document as any).msExitFullscreen();
    return Promise.reject('Fullscreen not supported');
  }

  toggleFullscreen(): Promise<void> {
    return this.isFullscreen ? this.exitFullscreen() : this.enterFullscreen();
  }
}


// directives/copy-prevention.directive.ts
import { Directive, HostListener, Input, Output, EventEmitter } from '@angular/core';

@Directive({
  selector: '[appCopyPrevention]',
  standalone: true
})
export class CopyPreventionDirective {
  @Input() preventCopy: boolean = true;
  @Input() preventPaste: boolean = true;
  @Input() preventCut: boolean = true;
  @Input() preventSelectAll: boolean = true;
  @Input() preventRightClick: boolean = true;
  @Input() preventPrintScreen: boolean = true;
  @Input() preventDevTools: boolean = true;

  @Output() violationDetected = new EventEmitter<{ type: string; timestamp: Date }>();

  @HostListener('copy', ['$event'])
  onCopy(event: ClipboardEvent): void {
    if (this.preventCopy) {
      event.preventDefault();
      this.emitViolation('copy_attempt');
    }
  }

  @HostListener('paste', ['$event'])
  onPaste(event: ClipboardEvent): void {
    if (this.preventPaste) {
      event.preventDefault();
      this.emitViolation('paste_attempt');
    }
  }

  @HostListener('cut', ['$event'])
  onCut(event: ClipboardEvent): void {
    if (this.preventCut) {
      event.preventDefault();
      this.emitViolation('cut_attempt');
    }
  }

  @HostListener('contextmenu', ['$event'])
  onRightClick(event: MouseEvent): void {
    if (this.preventRightClick) {
      event.preventDefault();
      this.emitViolation('right_click');
    }
  }

  @HostListener('document:keydown', ['$event'])
  onKeyDown(event: KeyboardEvent): void {
    // Prevent Ctrl+A (Select All)
    if (this.preventSelectAll && event.ctrlKey && event.key === 'a') {
      event.preventDefault();
      this.emitViolation('select_all_attempt');
    }

    // Prevent Ctrl+C/V/X
    if (event.ctrlKey) {
      if (this.preventCopy && event.key === 'c') {
        event.preventDefault();
        this.emitViolation('copy_attempt');
      }
      if (this.preventPaste && event.key === 'v') {
        event.preventDefault();
        this.emitViolation('paste_attempt');
      }
      if (this.preventCut && event.key === 'x') {
        event.preventDefault();
        this.emitViolation('cut_attempt');
      }
    }

    // Prevent Print Screen
    if (this.preventPrintScreen && event.key === 'PrintScreen') {
      event.preventDefault();
      this.emitViolation('print_screen');
    }

    // Prevent Dev Tools (F12, Ctrl+Shift+I/J/C)
    if (this.preventDevTools) {
      if (event.key === 'F12') {
        event.preventDefault();
        this.emitViolation('dev_tools_attempt');
      }
      if (event.ctrlKey && event.shiftKey && ['I', 'i', 'J', 'j', 'C', 'c'].includes(event.key)) {
        event.preventDefault();
        this.emitViolation('dev_tools_attempt');
      }
    }
  }

  private emitViolation(type: string): void {
    this.violationDetected.emit({ type, timestamp: new Date() });
  }
}


// directives/auto-save.directive.ts
import { Directive, Input, Output, EventEmitter, OnInit, OnDestroy } from '@angular/core';
import { Subject, debounceTime, takeUntil } from 'rxjs';

@Directive({
  selector: '[appAutoSave]',
  standalone: true
})
export class AutoSaveDirective implements OnInit, OnDestroy {
  @Input() autoSaveDelay: number = 5000; // 5 seconds
  @Input() autoSaveEnabled: boolean = true;

  @Output() autoSave = new EventEmitter<void>();

  private saveSubject = new Subject<void>();
  private destroy$ = new Subject<void>();

  ngOnInit(): void {
    this.saveSubject.pipe(
      debounceTime(this.autoSaveDelay),
      takeUntil(this.destroy$)
    ).subscribe(() => {
      if (this.autoSaveEnabled) {
        this.autoSave.emit();
      }
    });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  triggerSave(): void {
    this.saveSubject.next();
  }

  saveNow(): void {
    if (this.autoSaveEnabled) {
      this.autoSave.emit();
    }
  }
}


// directives/click-outside.directive.ts
import { Directive, ElementRef, Output, EventEmitter, HostListener } from '@angular/core';

@Directive({
  selector: '[appClickOutside]',
  standalone: true
})
export class ClickOutsideDirective {
  @Output() clickOutside = new EventEmitter<MouseEvent>();

  constructor(private el: ElementRef) {}

  @HostListener('document:click', ['$event'])
  onClick(event: MouseEvent): void {
    if (!this.el.nativeElement.contains(event.target)) {
      this.clickOutside.emit(event);
    }
  }
}


// directives/tooltip.directive.ts
import { Directive, Input, ElementRef, OnDestroy, HostListener } from '@angular/core';

@Directive({
  selector: '[appTooltip]',
  standalone: true
})
export class TooltipDirective implements OnDestroy {
  @Input('appTooltip') tooltipText: string = '';
  @Input() tooltipPosition: 'top' | 'bottom' | 'left' | 'right' = 'top';
  @Input() tooltipDelay: number = 200;

  private tooltipElement: HTMLDivElement | null = null;
  private showTimeout: any;

  constructor(private el: ElementRef) {}

  @HostListener('mouseenter')
  onMouseEnter(): void {
    if (!this.tooltipText) return;
    this.showTimeout = setTimeout(() => this.show(), this.tooltipDelay);
  }

  @HostListener('mouseleave')
  onMouseLeave(): void {
    clearTimeout(this.showTimeout);
    this.hide();
  }

  ngOnDestroy(): void {
    this.hide();
    clearTimeout(this.showTimeout);
  }

  private show(): void {
    this.tooltipElement = document.createElement('div');
    this.tooltipElement.className = `app-tooltip app-tooltip-${this.tooltipPosition}`;
    this.tooltipElement.textContent = this.tooltipText;
    document.body.appendChild(this.tooltipElement);
    this.positionTooltip();
    this.tooltipElement.classList.add('show');
  }

  private hide(): void {
    if (this.tooltipElement) {
      this.tooltipElement.remove();
      this.tooltipElement = null;
    }
  }

  private positionTooltip(): void {
    if (!this.tooltipElement) return;
    const hostRect = this.el.nativeElement.getBoundingClientRect();
    const tooltipRect = this.tooltipElement.getBoundingClientRect();
    let top = 0, left = 0;

    switch (this.tooltipPosition) {
      case 'top':
        top = hostRect.top - tooltipRect.height - 8;
        left = hostRect.left + (hostRect.width - tooltipRect.width) / 2;
        break;
      case 'bottom':
        top = hostRect.bottom + 8;
        left = hostRect.left + (hostRect.width - tooltipRect.width) / 2;
        break;
      case 'left':
        top = hostRect.top + (hostRect.height - tooltipRect.height) / 2;
        left = hostRect.left - tooltipRect.width - 8;
        break;
      case 'right':
        top = hostRect.top + (hostRect.height - tooltipRect.height) / 2;
        left = hostRect.right + 8;
        break;
    }

    this.tooltipElement.style.top = `${top + window.scrollY}px`;
    this.tooltipElement.style.left = `${left + window.scrollX}px`;
  }
}
