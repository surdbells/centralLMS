// components/rich-text-editor/rich-text-editor.component.ts
import { Component, Input, Output, EventEmitter, ViewChild, ElementRef, forwardRef, OnInit, OnDestroy, ChangeDetectionStrategy, ChangeDetectorRef, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ControlValueAccessor, NG_VALUE_ACCESSOR, FormsModule } from '@angular/forms';
import { TranslatePipe } from '../../../translate.pipe';
import { I18nService } from '../../../i18n.service';
import { ConfirmDialogService } from '../confirmation-dialog/confirmation-dialog.component';

@Component({
  selector: 'app-rich-text-editor',
  standalone: true,
  imports: [CommonModule, FormsModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [{
    provide: NG_VALUE_ACCESSOR,
    useExisting: forwardRef(() => RichTextEditorComponent),
    multi: true
  }],
  template: `
    <div class="editor-container" [class.disabled]="disabled" [class.focused]="isFocused">
      <!-- Toolbar -->
      <div class="editor-toolbar" *ngIf="showToolbar">
        <div class="toolbar-group">
          <button type="button" class="toolbar-btn" (click)="execCommand('bold')" [title]="'shared.editor.bold' | translate">
            <i class="bi bi-type-bold"></i>
          </button>
          <button type="button" class="toolbar-btn" (click)="execCommand('italic')" [title]="'shared.editor.italic' | translate">
            <i class="bi bi-type-italic"></i>
          </button>
          <button type="button" class="toolbar-btn" (click)="execCommand('underline')" [title]="'shared.editor.underline' | translate">
            <i class="bi bi-type-underline"></i>
          </button>
          <button type="button" class="toolbar-btn" (click)="execCommand('strikethrough')" [title]="'shared.editor.strikethrough' | translate">
            <i class="bi bi-type-strikethrough"></i>
          </button>
        </div>

        <div class="toolbar-divider"></div>

        <div class="toolbar-group">
          <button type="button" class="toolbar-btn" (click)="execCommand('insertUnorderedList')" [title]="'shared.editor.bullet_list' | translate">
            <i class="bi bi-list-ul"></i>
          </button>
          <button type="button" class="toolbar-btn" (click)="execCommand('insertOrderedList')" [title]="'shared.editor.numbered_list' | translate">
            <i class="bi bi-list-ol"></i>
          </button>
        </div>

        <div class="toolbar-divider"></div>

        <div class="toolbar-group">
          <button type="button" class="toolbar-btn" (click)="execCommand('justifyLeft')" [title]="'shared.editor.align_left' | translate">
            <i class="bi bi-text-left"></i>
          </button>
          <button type="button" class="toolbar-btn" (click)="execCommand('justifyCenter')" [title]="'shared.editor.align_center' | translate">
            <i class="bi bi-text-center"></i>
          </button>
          <button type="button" class="toolbar-btn" (click)="execCommand('justifyRight')" [title]="'shared.editor.align_right' | translate">
            <i class="bi bi-text-right"></i>
          </button>
        </div>

        <div class="toolbar-divider" *ngIf="allowLinks || allowImages"></div>

        <div class="toolbar-group" *ngIf="allowLinks || allowImages">
          <button type="button" class="toolbar-btn" (click)="insertLink()" [title]="'shared.editor.insert_link' | translate" *ngIf="allowLinks">
            <i class="bi bi-link-45deg"></i>
          </button>
          <button type="button" class="toolbar-btn" (click)="insertImage()" [title]="'shared.editor.insert_image' | translate" *ngIf="allowImages">
            <i class="bi bi-image"></i>
          </button>
        </div>

        <div class="toolbar-divider"></div>

        <div class="toolbar-group">
          <button type="button" class="toolbar-btn" (click)="execCommand('removeFormat')" [title]="'shared.editor.clear_formatting' | translate">
            <i class="bi bi-eraser"></i>
          </button>
        </div>

        <div class="toolbar-group ms-auto" *ngIf="showWordCount">
          <span class="word-count" [class.over-limit]="isOverLimit">
            {{ wordCount }}<span *ngIf="maxWords"> / {{ maxWords }}</span> {{ 'shared.editor.words' | translate }}
          </span>
        </div>
      </div>
      
      <!-- Editor Area -->
      <div 
        #editor
        class="editor-content"
        [attr.contenteditable]="!disabled"
        [style.minHeight.px]="minHeight"
        [style.maxHeight.px]="maxHeight"
        [attr.placeholder]="placeholder || ('shared.editor.placeholder' | translate)"
        (input)="onInput($event)"
        (focus)="onFocus()"
        (blur)="onBlur()"
        (paste)="onPaste($event)">
      </div>
      
      <!-- Character/Word Limit Warning -->
      <div class="editor-footer" *ngIf="showCharCount || (showWordCount && !showToolbar)">
        <span class="char-count" *ngIf="showCharCount" [class.over-limit]="charCount > maxChars">
          {{ charCount }}<span *ngIf="maxChars"> / {{ maxChars }}</span> {{ 'shared.editor.characters' | translate }}
        </span>
        <span class="word-count" *ngIf="showWordCount && !showToolbar" [class.over-limit]="isOverLimit">
          {{ wordCount }}<span *ngIf="maxWords"> / {{ maxWords }}</span> {{ 'shared.editor.words' | translate }}
        </span>
      </div>
    </div>
  `,
  styles: [`
    .editor-container {
      border: 1px solid #ced4da;
      border-radius: 0.375rem;
      overflow: hidden;
      transition: border-color 0.15s, box-shadow 0.15s;
    }
    .editor-container.focused {
      border-color: #86b7fe;
      box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.25);
    }
    .editor-container.disabled {
      background: #e9ecef;
      pointer-events: none;
    }
    
    .editor-toolbar {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      padding: 0.5rem;
      background: #f8f9fa;
      border-bottom: 1px solid #dee2e6;
      gap: 0.25rem;
    }
    .toolbar-group { display: flex; gap: 0.125rem; }
    .toolbar-divider {
      width: 1px;
      height: 24px;
      background: #dee2e6;
      margin: 0 0.5rem;
    }
    .toolbar-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 32px;
      height: 32px;
      border: none;
      background: transparent;
      border-radius: 0.25rem;
      color: #495057;
      cursor: pointer;
      transition: all 0.15s;
    }
    .toolbar-btn:hover { background: #e9ecef; }
    .toolbar-btn:active { background: #dee2e6; }
    
    .editor-content {
      padding: 0.75rem;
      outline: none;
      overflow-y: auto;
      line-height: 1.6;
    }
    .editor-content:empty:before {
      content: attr(placeholder);
      color: #6c757d;
      pointer-events: none;
    }
    .editor-content:focus:empty:before { color: #adb5bd; }
    
    .editor-footer {
      display: flex;
      justify-content: flex-end;
      padding: 0.25rem 0.5rem;
      background: #f8f9fa;
      border-top: 1px solid #dee2e6;
      font-size: 0.75rem;
      color: #6c757d;
    }
    .word-count, .char-count { font-size: 0.75rem; color: #6c757d; }
    .over-limit { color: #dc3545; font-weight: 500; }
  `]
})
export class RichTextEditorComponent implements ControlValueAccessor, OnInit, OnDestroy {
  @ViewChild('editor') editor!: ElementRef<HTMLDivElement>;

  @Input() placeholder: string = '';
  @Input() minHeight: number = 150;
  @Input() maxHeight: number = 400;
  @Input() showToolbar: boolean = true;
  @Input() showWordCount: boolean = false;
  @Input() showCharCount: boolean = false;
  @Input() maxWords: number = 0;
  @Input() maxChars: number = 0;
  @Input() minWords: number = 0;
  @Input() allowLinks: boolean = true;
  @Input() allowImages: boolean = false;
  @Input() disabled: boolean = false;

  @Output() wordCountChange = new EventEmitter<number>();
  @Output() charCountChange = new EventEmitter<number>();
  @Output() focused = new EventEmitter<void>();
  @Output() blurred = new EventEmitter<void>();

  isFocused: boolean = false;
  wordCount: number = 0;
  charCount: number = 0;

  private onChange: (value: string) => void = () => {};
  private onTouched: () => void = () => {};

  private i18n = inject(I18nService);
  private dialog = inject(ConfirmDialogService);

  constructor(private cdr: ChangeDetectorRef) {}

  ngOnInit(): void {}

  ngOnDestroy(): void {}

  writeValue(value: string): void {
    if (this.editor?.nativeElement) {
      this.editor.nativeElement.innerHTML = value || '';
      this.updateCounts();
    }
  }

  registerOnChange(fn: (value: string) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  setDisabledState(isDisabled: boolean): void {
    this.disabled = isDisabled;
    this.cdr.markForCheck();
  }

  execCommand(command: string, value?: string): void {
    document.execCommand(command, false, value);
    this.editor.nativeElement.focus();
    this.emitChange();
  }

  async insertLink(): Promise<void> {
    const url = await this.dialog.prompt(this.i18n.t('shared.editor.prompt_url'));
    if (url) {
      this.execCommand('createLink', url);
    }
  }

  async insertImage(): Promise<void> {
    const url = await this.dialog.prompt(this.i18n.t('shared.editor.prompt_image_url'));
    if (url) {
      this.execCommand('insertImage', url);
    }
  }

  onInput(event: Event): void {
    this.emitChange();
  }

  onFocus(): void {
    this.isFocused = true;
    this.focused.emit();
  }

  onBlur(): void {
    this.isFocused = false;
    this.onTouched();
    this.blurred.emit();
  }

  onPaste(event: ClipboardEvent): void {
    event.preventDefault();
    const text = event.clipboardData?.getData('text/plain') || '';
    document.execCommand('insertText', false, text);
    this.emitChange();
  }

  private emitChange(): void {
    const html = this.editor.nativeElement.innerHTML;
    this.onChange(html);
    this.updateCounts();
  }

  private updateCounts(): void {
    const text = this.editor?.nativeElement?.textContent || '';
    this.charCount = text.length;
    this.wordCount = text.trim() ? text.trim().split(/\s+/).length : 0;
    this.wordCountChange.emit(this.wordCount);
    this.charCountChange.emit(this.charCount);
    this.cdr.markForCheck();
  }

  get isOverLimit(): boolean {
    return (this.maxWords > 0 && this.wordCount > this.maxWords) ||
           (this.maxChars > 0 && this.charCount > this.maxChars);
  }

  get isBelowMinimum(): boolean {
    return this.minWords > 0 && this.wordCount < this.minWords;
  }

  getHtml(): string {
    return this.editor?.nativeElement?.innerHTML || '';
  }

  getText(): string {
    return this.editor?.nativeElement?.textContent || '';
  }

  clear(): void {
    if (this.editor?.nativeElement) {
      this.editor.nativeElement.innerHTML = '';
      this.emitChange();
    }
  }

  focus(): void {
    this.editor?.nativeElement?.focus();
  }
}
