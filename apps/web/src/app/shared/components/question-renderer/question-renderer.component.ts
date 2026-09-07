// components/question-renderer/question-renderer.component.ts
import { Component, Input, Output, EventEmitter, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { StatusBadgeComponent } from '../status-badge/status-badge.component';
import { TranslatePipe } from '../../../translate.pipe';

export interface QuestionOption {
  option_id: number;
  option_text: string;
  option_html?: string;
  is_correct: boolean;
  sort_order: number;
}

export interface QuestionSettings {
  shuffle_options?: boolean;
  case_sensitive?: boolean;
  partial_credit?: boolean;
  min_words?: number;
  max_words?: number;
  allowed_file_types?: string[];
  max_file_size_mb?: number;
  tolerance?: number;
  tolerance_type?: 'absolute' | 'percentage';
}

export interface Question {
  question_id: number;
  question_type: string;
  question_text: string;
  question_html?: string;
  explanation?: string;
  options?: QuestionOption[];
  correct_answer?: any;
  settings?: QuestionSettings;
}

@Component({
  selector: 'app-question-renderer',
  standalone: true,
  imports: [CommonModule, FormsModule, StatusBadgeComponent, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="question-container" [class.answered]="isAnswered" [class.flagged]="flagged">
      <div class="question-header">
        <div class="question-number" *ngIf="showNumber">
          <span class="number">Q{{ questionNumber }}</span>
          <i class="bi bi-flag-fill text-warning ms-2" *ngIf="flagged" [title]="'shared.question.flagged' | translate"></i>
        </div>
        <div class="question-meta">
          <app-status-badge [value]="question.question_type" type="question" size="sm"></app-status-badge>
          <span class="points" *ngIf="showPoints">{{ points }} {{ (points !== 1 ? 'shared.question.pts' : 'shared.question.pt') | translate }}</span>
        </div>
      </div>

      <div class="question-text" [innerHTML]="question.question_html || question.question_text"></div>

      <div class="question-body" [ngSwitch]="question.question_type">

        <!-- Multiple Choice -->
        <ng-container *ngSwitchCase="'multiple_choice'">
          <div class="options-list">
            <div *ngFor="let opt of displayOptions; let i = index"
                 class="option-item"
                 [class.selected]="answer === opt.option_id"
                 [class.correct]="showCorrect && opt.is_correct"
                 [class.incorrect]="showCorrect && answer === opt.option_id && !opt.is_correct"
                 (click)="!disabled && selectOption(opt.option_id)">
              <div class="option-marker radio"><div class="inner" *ngIf="answer === opt.option_id"></div></div>
              <span class="option-letter">{{ getLetter(i) }}.</span>
              <span class="option-text" [innerHTML]="opt.option_html || opt.option_text"></span>
              <i class="bi bi-check-lg text-success ms-auto" *ngIf="showCorrect && opt.is_correct"></i>
            </div>
          </div>
        </ng-container>

        <!-- Multiple Response -->
        <ng-container *ngSwitchCase="'multiple_response'">
          <div class="options-list">
            <div *ngFor="let opt of displayOptions; let i = index"
                 class="option-item"
                 [class.selected]="isSelected(opt.option_id)"
                 [class.correct]="showCorrect && opt.is_correct"
                 (click)="!disabled && toggleOption(opt.option_id)">
              <div class="option-marker checkbox"><i class="bi bi-check" *ngIf="isSelected(opt.option_id)"></i></div>
              <span class="option-letter">{{ getLetter(i) }}.</span>
              <span class="option-text" [innerHTML]="opt.option_html || opt.option_text"></span>
              <i class="bi bi-check-lg text-success ms-auto" *ngIf="showCorrect && opt.is_correct"></i>
            </div>
          </div>
        </ng-container>

        <!-- True/False -->
        <ng-container *ngSwitchCase="'true_false'">
          <div class="options-list tf">
            <div class="option-item" [class.selected]="answer === true" (click)="!disabled && selectBool(true)">
              <div class="option-marker radio"><div class="inner" *ngIf="answer === true"></div></div>
              <span>{{ 'quiz.true' | translate }}</span>
            </div>
            <div class="option-item" [class.selected]="answer === false" (click)="!disabled && selectBool(false)">
              <div class="option-marker radio"><div class="inner" *ngIf="answer === false"></div></div>
              <span>{{ 'quiz.false' | translate }}</span>
            </div>
          </div>
        </ng-container>

        <!-- Short Answer -->
        <ng-container *ngSwitchCase="'short_answer'">
          <input type="text" class="form-control" style="max-width:500px" [value]="answer || ''" [disabled]="disabled"
                 placeholder="{{ 'shared.question.answer_placeholder' | translate }}" (input)="onText($event)">
          <div class="correct-answer mt-2" *ngIf="showCorrect">{{ 'shared.question.correct' | translate }} {{ question.correct_answer }}</div>
        </ng-container>

        <!-- Long Answer -->
        <ng-container *ngSwitchCase="'long_answer'">
          <textarea class="form-control" rows="6" [value]="answer || ''" [disabled]="disabled"
                    placeholder="{{ 'shared.question.answer_placeholder' | translate }}" (input)="onText($event)"></textarea>
          <div class="word-info mt-1" *ngIf="question.settings?.min_words || question.settings?.max_words">
            <small [class.text-danger]="wordCount > (question.settings?.max_words || 9999)">
              {{ 'shared.question.n_words' | translate:{ count: wordCount } }}
              <span *ngIf="question.settings?.min_words">{{ 'shared.question.min' | translate:{ n: question.settings?.min_words } }}</span>
              <span *ngIf="question.settings?.max_words">{{ 'shared.question.max' | translate:{ n: question.settings?.max_words } }}</span>
            </small>
          </div>
        </ng-container>

        <!-- Numeric -->
        <ng-container *ngSwitchCase="'numeric'">
          <input type="number" class="form-control" style="max-width:200px" [value]="answer" [disabled]="disabled"
                 placeholder="{{ 'shared.question.enter_number' | translate }}" step="any" (input)="onNumeric($event)">
          <div class="correct-answer mt-2" *ngIf="showCorrect">
            {{ 'shared.question.correct' | translate }} {{ question.correct_answer }}
            <span *ngIf="question.settings?.tolerance">(± {{ question.settings?.tolerance }}{{ question.settings?.tolerance_type === 'percentage' ? '%' : '' }})</span>
          </div>
        </ng-container>

        <!-- Fill in Blank -->
        <ng-container *ngSwitchCase="'fill_in_blank'">
          <div class="fill-blanks" [innerHTML]="renderBlanks()"></div>
        </ng-container>

        <!-- Matching -->
        <ng-container *ngSwitchCase="'matching'">
          <div class="matching-pairs">
            <div *ngFor="let opt of question.options; let i = index" class="match-row">
              <div class="match-left">{{ opt.option_text }}</div>
              <span class="match-arrow">→</span>
              <select class="form-select match-select" [disabled]="disabled" [value]="getMatch(i)" (change)="onMatch(i, $event)">
                <option value="">{{ 'shared.question.select' | translate }}</option>
                <option *ngFor="let r of matchRightOptions" [value]="r">{{ r }}</option>
              </select>
            </div>
          </div>
        </ng-container>

        <!-- Ordering -->
        <ng-container *ngSwitchCase="'ordering'">
          <div class="ordering-list">
            <div *ngFor="let item of orderItems; let i = index" class="order-item"
                 draggable="true" (dragstart)="dragStart(i)" (dragover)="$event.preventDefault()" (drop)="drop(i)">
              <i class="bi bi-grip-vertical text-muted"></i>
              <span class="order-num">{{ i + 1 }}.</span>
              <span>{{ item }}</span>
            </div>
          </div>
        </ng-container>

        <!-- File Upload -->
        <ng-container *ngSwitchCase="'file_upload'">
          <div class="file-upload" *ngIf="!uploadedFile">
            <input type="file" #fileIn [accept]="question.settings?.allowed_file_types?.join(',')"
                   [disabled]="disabled" (change)="onFile($event)" hidden>
            <button class="btn btn-primary" (click)="fileIn.click()" [disabled]="disabled">
              <i class="bi bi-upload me-1"></i> {{ 'shared.question.upload_file' | translate }}
            </button>
            <small class="d-block mt-1 text-muted" *ngIf="question.settings?.allowed_file_types">
              {{ 'shared.uploader.allowed' | translate:{ types: question.settings?.allowed_file_types?.join(', ') } }}
              <span *ngIf="question.settings?.max_file_size_mb">| {{ 'shared.question.max_mb' | translate:{ size: question.settings?.max_file_size_mb } }}</span>
            </small>
          </div>
          <div class="uploaded-file d-flex align-items-center gap-2" *ngIf="uploadedFile">
            <i class="bi bi-file-earmark"></i>
            <span>{{ uploadedFile.name }}</span>
            <button class="btn btn-sm btn-link text-danger" (click)="removeFile()" [disabled]="disabled">
              <i class="bi bi-x-lg"></i>
            </button>
          </div>
        </ng-container>

        <!-- Hotspot (placeholder) -->
        <ng-container *ngSwitchCase="'hotspot'">
          <div class="hotspot-placeholder text-muted">
            <i class="bi bi-image"></i> {{ 'shared.question.hotspot_hint' | translate }}
          </div>
        </ng-container>

      </div>

      <div class="explanation mt-3 p-3 bg-warning-subtle rounded" *ngIf="showExplanation && question.explanation">
        <strong><i class="bi bi-lightbulb me-1"></i> {{ 'shared.question.explanation' | translate }}</strong>
        <div [innerHTML]="question.explanation"></div>
      </div>

      <div class="feedback mt-3 p-3 bg-info-subtle rounded" *ngIf="feedback">
        <strong><i class="bi bi-chat-text me-1"></i> {{ 'shared.question.feedback' | translate }}</strong> {{ feedback }}
      </div>
    </div>
  `,
  styles: [`
    .question-container { background:#fff; border:1px solid #dee2e6; border-radius:0.5rem; padding:1.25rem; margin-bottom:1rem; }
    .question-container.flagged { border-left:4px solid #ffc107; }
    .question-container.answered { border-left:4px solid #198754; }
    .question-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:0.75rem; }
    .question-number { font-weight:600; font-size:1.1rem; display:flex; align-items:center; }
    .question-meta { display:flex; align-items:center; gap:0.75rem; }
    .points { font-size:0.875rem; color:#6c757d; }
    .question-text { font-size:1.05rem; line-height:1.6; margin-bottom:1rem; }
    .options-list { display:flex; flex-direction:column; gap:0.5rem; }
    .option-item { display:flex; align-items:flex-start; gap:0.75rem; padding:0.75rem 1rem; border:1px solid #dee2e6; border-radius:0.375rem; cursor:pointer; transition:all 0.15s; }
    .option-item:hover { background:#f8f9fa; }
    .option-item.selected { background:#e7f1ff; border-color:#0d6efd; }
    .option-item.correct { background:#d1e7dd; border-color:#198754; }
    .option-item.incorrect { background:#f8d7da; border-color:#dc3545; }
    .option-marker { width:20px; height:20px; border:2px solid #adb5bd; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .option-marker.radio { border-radius:50%; }
    .option-marker.checkbox { border-radius:0.25rem; }
    .option-item.selected .option-marker { border-color:#0d6efd; }
    .option-marker .inner { width:10px; height:10px; background:#0d6efd; border-radius:50%; }
    .option-item.selected .option-marker.checkbox { background:#0d6efd; color:#fff; }
    .option-letter { font-weight:500; color:#6c757d; }
    .correct-answer { padding:0.5rem 0.75rem; background:#d1e7dd; border-radius:0.25rem; font-size:0.875rem; }
    .matching-pairs { display:flex; flex-direction:column; gap:0.5rem; max-width:500px; }
    .match-row { display:flex; align-items:center; gap:0.5rem; }
    .match-left { flex:1; padding:0.5rem; background:#f8f9fa; border-radius:0.25rem; }
    .match-select { flex:1; }
    .ordering-list { display:flex; flex-direction:column; gap:0.5rem; max-width:400px; }
    .order-item { display:flex; align-items:center; gap:0.5rem; padding:0.75rem; background:#f8f9fa; border:1px solid #dee2e6; border-radius:0.25rem; cursor:grab; }
    .order-num { font-weight:500; color:#6c757d; }
  `]
})
export class QuestionRendererComponent {
  @Input() question!: Question;
  @Input() questionNumber: number = 1;
  @Input() points: number = 1;
  @Input() answer: any;
  @Input() flagged: boolean = false;
  @Input() disabled: boolean = false;
  @Input() showNumber: boolean = true;
  @Input() showPoints: boolean = true;
  @Input() showCorrect: boolean = false;
  @Input() showExplanation: boolean = false;
  @Input() shuffleOptions: boolean = false;
  @Input() feedback: string = '';

  @Output() answerChange = new EventEmitter<any>();

  uploadedFile: File | null = null;
  private dragIdx = -1;

  get displayOptions(): QuestionOption[] {
    const opts = this.question.options || [];
    return this.shuffleOptions ? [...opts].sort(() => Math.random() - 0.5) : opts;
  }

  get isAnswered(): boolean {
    if (this.answer == null) return false;
    if (Array.isArray(this.answer)) return this.answer.length > 0;
    if (typeof this.answer === 'string') return this.answer.trim().length > 0;
    return true;
  }

  get wordCount(): number {
    return typeof this.answer === 'string' && this.answer.trim() ? this.answer.trim().split(/\s+/).length : 0;
  }

  get matchRightOptions(): string[] {
    return (this.question.correct_answer as any)?.rightOptions || (this.question.options || []).map(o => o.option_text);
  }

  get orderItems(): string[] {
    if (Array.isArray(this.answer) && this.answer.length) return this.answer;
    return (this.question.options || []).map(o => o.option_text);
  }

  getLetter(i: number): string { return String.fromCharCode(65 + i); }

  isSelected(id: number): boolean {
    return Array.isArray(this.answer) && this.answer.includes(id);
  }

  selectOption(id: number): void { this.answerChange.emit(id); }

  toggleOption(id: number): void {
    const arr = Array.isArray(this.answer) ? [...this.answer] : [];
    const idx = arr.indexOf(id);
    idx > -1 ? arr.splice(idx, 1) : arr.push(id);
    this.answerChange.emit(arr);
  }

  selectBool(val: boolean): void { this.answerChange.emit(val); }

  onText(e: Event): void { this.answerChange.emit((e.target as HTMLInputElement).value); }

  onNumeric(e: Event): void {
    const v = parseFloat((e.target as HTMLInputElement).value);
    this.answerChange.emit(isNaN(v) ? null : v);
  }

  getMatch(i: number): string { return (this.answer as any)?.[i] || ''; }

  onMatch(i: number, e: Event): void {
    const val = (e.target as HTMLSelectElement).value;
    const curr = { ...(this.answer || {}) };
    curr[i] = val;
    this.answerChange.emit(curr);
  }

  dragStart(i: number): void { this.dragIdx = i; }

  drop(target: number): void {
    if (this.dragIdx === target) return;
    const items = [...this.orderItems];
    const [removed] = items.splice(this.dragIdx, 1);
    items.splice(target, 0, removed);
    this.answerChange.emit(items);
    this.dragIdx = -1;
  }

  onFile(e: Event): void {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (file) {
      this.uploadedFile = file;
      this.answerChange.emit({ file, name: file.name, size: file.size, type: file.type });
    }
  }

  removeFile(): void {
    this.uploadedFile = null;
    this.answerChange.emit(null);
  }

  renderBlanks(): string {
    let text = this.question.question_html || this.question.question_text;
    let idx = 0;
    return text.replace(/\[BLANK\]/g, () => {
      const val = Array.isArray(this.answer) ? this.answer[idx] || '' : '';
      return `<input type="text" class="fill-input form-control d-inline" style="width:150px" value="${val}" data-idx="${idx++}" ${this.disabled ? 'disabled' : ''}>`;
    });
  }
}
