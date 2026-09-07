import {
  Component,
  ElementRef,
  forwardRef,
  Input,
  ViewChild,
  inject,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';
import { DomSanitizer, type SafeHtml } from '@angular/platform-browser';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';

/** One toolbar control. */
interface Tool {
  cmd: string;
  value?: string;
  icon: string;
  title: string;
}

/**
 * A small rich-text editor for HTML — email templates, and anywhere else an
 * admin needs to write formatted content the platform will send or show.
 *
 * A `contenteditable` surface with a toolbar of the formatting an email
 * actually uses, and a "Source" toggle for editing the HTML by hand. It is a
 * `ControlValueAccessor`, so it drops into a form with `[(ngModel)]` like any
 * input. Deliberately dependency-free: it uses the platform's own editing
 * commands and Bootstrap icons rather than pulling in a heavyweight editor
 * and a second icon font.
 */
@Component({
  selector: 'app-rich-html-editor',
  standalone: true,
  imports: [CommonModule, FormsModule, TranslatePipe],
  templateUrl: './rich-html-editor.component.html',
  styleUrl: './rich-html-editor.component.scss',
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => RichHtmlEditorComponent),
      multi: true,
    },
  ],
})
export class RichHtmlEditorComponent implements ControlValueAccessor {
  private readonly sanitizer = inject(DomSanitizer);
  private readonly i18n = inject(I18nService);

  @Input() placeholder = '';
  @Input() minHeight = 220;

  @ViewChild('surface') private surface?: ElementRef<HTMLDivElement>;

  /** The HTML being edited. */
  html = '';
  /** Editing the raw HTML rather than the formatted view. */
  showSource = false;
  disabled = false;

  readonly tools: Array<Tool | 'sep'> = [
    { cmd: 'bold', icon: 'bi-type-bold', title: 'shared.editor.bold' },
    { cmd: 'italic', icon: 'bi-type-italic', title: 'shared.editor.italic' },
    { cmd: 'underline', icon: 'bi-type-underline', title: 'shared.editor.underline' },
    { cmd: 'strikeThrough', icon: 'bi-type-strikethrough', title: 'shared.editor.strikethrough' },
    'sep',
    { cmd: 'formatBlock', value: 'H1', icon: 'bi-type-h1', title: 'shared.editor.heading1' },
    { cmd: 'formatBlock', value: 'H2', icon: 'bi-type-h2', title: 'shared.editor.heading2' },
    { cmd: 'formatBlock', value: 'P', icon: 'bi-paragraph', title: 'shared.editor.paragraph' },
    'sep',
    { cmd: 'insertUnorderedList', icon: 'bi-list-ul', title: 'shared.editor.bullet_list' },
    { cmd: 'insertOrderedList', icon: 'bi-list-ol', title: 'shared.editor.numbered_list' },
    { cmd: 'blockquote', icon: 'bi-quote', title: 'shared.editor.quote' },
    'sep',
    { cmd: 'justifyLeft', icon: 'bi-text-left', title: 'shared.editor.align_left' },
    { cmd: 'justifyCenter', icon: 'bi-text-center', title: 'shared.editor.align_center' },
    { cmd: 'justifyRight', icon: 'bi-text-right', title: 'shared.editor.align_right' },
    'sep',
    { cmd: 'createLink', icon: 'bi-link-45deg', title: 'shared.editor.insert_link' },
    { cmd: 'unlink', icon: 'bi-link', title: 'shared.editor.remove_link' },
    { cmd: 'removeFormat', icon: 'bi-eraser', title: 'shared.editor.clear_formatting' },
  ];

  /** The last selection made inside the surface, so a toolbar click that
   *  steals focus can still act on what the user had selected. */
  private savedRange: Range | null = null;

  private onChange: (value: string) => void = () => undefined;
  private onTouched: () => void = () => undefined;

  // --- ControlValueAccessor ----------------------------------------------

  writeValue(value: string | null): void {
    this.html = value ?? '';
    // Push into the surface without re-triggering (input) which would move the
    // caret; only when the incoming value differs from what is shown.
    const el = this.surface?.nativeElement;
    if (el && el.innerHTML !== this.html) {
      el.innerHTML = this.html;
    }
  }

  registerOnChange(fn: (value: string) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  setDisabledState(disabled: boolean): void {
    this.disabled = disabled;
  }

  // --- editing ------------------------------------------------------------

  /** Remembers the caret/selection while it is inside the surface. */
  saveSelection(): void {
    const sel = window.getSelection();
    const el = this.surface?.nativeElement;
    if (sel && sel.rangeCount > 0 && el && el.contains(sel.anchorNode)) {
      this.savedRange = sel.getRangeAt(0).cloneRange();
    }
  }

  private restoreSelection(): void {
    const el = this.surface?.nativeElement;
    if (!el) {
      return;
    }
    el.focus();
    if (this.savedRange) {
      const sel = window.getSelection();
      sel?.removeAllRanges();
      sel?.addRange(this.savedRange);
    }
  }

  /** Runs a formatting command against the current selection. */
  run(tool: Tool, event: Event): void {
    event.preventDefault();
    if (this.disabled) {
      return;
    }
    // The mousedown that fired this kept the surface's selection; restore the
    // remembered range too so a toolbar press acts on what was highlighted.
    this.restoreSelection();

    if (tool.cmd === 'createLink') {
      const url = window.prompt(this.i18n.t('shared.editor.prompt_link_address'), 'https://');
      if (url) {
        document.execCommand('createLink', false, url);
      }
    } else if (tool.cmd === 'blockquote') {
      document.execCommand('formatBlock', false, 'BLOCKQUOTE');
    } else {
      document.execCommand(tool.cmd, false, tool.value);
    }
    this.saveSelection();
    this.syncFromSurface();
  }

  /** The surface changed — read its HTML back out. */
  syncFromSurface(): void {
    const el = this.surface?.nativeElement;
    if (el) {
      this.html = el.innerHTML;
      this.onChange(this.html);
    }
  }

  onSourceChange(value: string): void {
    this.html = value;
    this.onChange(value);
    const el = this.surface?.nativeElement;
    if (el) {
      el.innerHTML = value;
    }
  }

  toggleSource(): void {
    this.showSource = !this.showSource;
    if (!this.showSource) {
      // Coming back from source: push whatever was typed into the surface.
      const el = this.surface?.nativeElement;
      if (el) {
        el.innerHTML = this.html;
      }
    }
  }

  blurred(): void {
    this.onTouched();
  }

  /** Inserts text (a placeholder) at the caret. */
  insert(text: string): void {
    if (this.disabled) {
      return;
    }
    if (this.showSource) {
      this.html = `${this.html}${text}`;
      this.onChange(this.html);
      return;
    }
    this.restoreSelection();
    document.execCommand('insertText', false, text);
    this.saveSelection();
    this.syncFromSurface();
  }

  get preview(): SafeHtml {
    return this.sanitizer.bypassSecurityTrustHtml(this.html || '');
  }

  isActive(tool: Tool): boolean {
    try {
      if (tool.cmd === 'formatBlock' && tool.value) {
        return document.queryCommandValue('formatBlock').toUpperCase() === tool.value;
      }
      return document.queryCommandState(tool.cmd);
    } catch {
      return false;
    }
  }
}
