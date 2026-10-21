import { Component, Input, Output, EventEmitter, OnChanges, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import { HotToastService } from '@ngneat/hot-toast';
import { Question, QuestionOption, AnswerData, AttemptAnswer, getQuestionTypeLabel } from '../../../models/assessments.models';

@Component({
  selector: 'app-question-display',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="question-display" [class.disabled]="disabled">
      <!-- Question Header -->
      <div class="question-header">
        <span class="question-type-badge" [style.background]="getTypeBadgeColor()">
          {{ getTypeLabel() }}
        </span>
        <span class="question-points">{{ question.points }} {{ question.points === 1 ? 'point' : 'points' }}</span>
      </div>

      <!-- Question Text -->
      <div class="question-text" [innerHTML]="sanitizedQuestionText"></div>

      <!-- Question Media -->
      <div class="question-media" *ngIf="question.featured_image || question.audio_url || question.video_url">
        <img *ngIf="question.featured_image" [src]="question.featured_image" alt="Question image" class="question-image">
        <audio *ngIf="question.audio_url" [src]="question.audio_url" controls class="question-audio"></audio>
        <video *ngIf="question.video_url" [src]="question.video_url" controls class="question-video"></video>
      </div>

      <!-- Hint -->
      <div class="question-hint" *ngIf="question.hint && showHint">
        <i class="bi bi-lightbulb"></i>
        <span>{{ question.hint }}</span>
      </div>
      <button *ngIf="question.hint && !showHint" class="btn btn-sm btn-link" (click)="showHint = true">
        <i class="bi bi-lightbulb"></i> Show Hint
      </button>

      <!-- Answer Area -->
      <div class="answer-area">
        <!-- Multiple Choice Single -->
        <div *ngIf="question.question_type === 'multiple_choice_single'" class="mcq-options">
          <div
            *ngFor="let option of shuffledOptions; let i = index"
            class="mcq-option"
            [class.selected]="localAnswer.selected_option_id === option.id"
            [class.correct]="showFeedback && option.is_correct"
            [class.incorrect]="showFeedback && localAnswer.selected_option_id === option.id && !option.is_correct"
            (click)="!disabled && selectSingleOption(option.id!)">
            <div class="option-marker">{{ getOptionLetter(i) }}</div>
            <div class="option-content">
              <span [innerHTML]="sanitize(option.option_html || option.option_text)"></span>
              <img *ngIf="option.option_image" [src]="option.option_image" alt="Option image" class="option-image">
            </div>
            <i *ngIf="localAnswer.selected_option_id === option.id" class="bi bi-check-circle-fill selected-icon"></i>
          </div>
        </div>

        <!-- Multiple Choice Multiple -->
        <div *ngIf="question.question_type === 'multiple_choice_multiple'" class="mcq-options multiple">
          <div
            *ngFor="let option of shuffledOptions; let i = index"
            class="mcq-option"
            [class.selected]="isOptionSelected(option.id!)"
            [class.correct]="showFeedback && option.is_correct"
            [class.incorrect]="showFeedback && isOptionSelected(option.id!) && !option.is_correct"
            (click)="!disabled && toggleMultipleOption(option.id!)">
            <div class="option-checkbox">
              <i class="bi" [class.bi-check-square-fill]="isOptionSelected(option.id!)" [class.bi-square]="!isOptionSelected(option.id!)"></i>
            </div>
            <div class="option-content">
              <span [innerHTML]="sanitize(option.option_html || option.option_text)"></span>
              <img *ngIf="option.option_image" [src]="option.option_image" alt="Option image" class="option-image">
            </div>
          </div>
          <p class="helper-text">Select all that apply</p>
        </div>

        <!-- True/False -->
        <div *ngIf="question.question_type === 'true_false'" class="true-false-options">
          <button
            class="tf-option"
            [class.selected]="localAnswer.answer === true"
            [class.correct]="showFeedback && correctTFAnswer === true"
            [class.incorrect]="showFeedback && localAnswer.answer === true && correctTFAnswer !== true"
            [disabled]="disabled"
            (click)="selectTrueFalse(true)">
            <i class="bi bi-check-circle"></i>
            True
          </button>
          <button
            class="tf-option"
            [class.selected]="localAnswer.answer === false"
            [class.correct]="showFeedback && correctTFAnswer === false"
            [class.incorrect]="showFeedback && localAnswer.answer === false && correctTFAnswer !== false"
            [disabled]="disabled"
            (click)="selectTrueFalse(false)">
            <i class="bi bi-x-circle"></i>
            False
          </button>
        </div>

        <!-- Fill in the Blanks -->
        <div *ngIf="question.question_type === 'fill_in_blanks'" class="fill-blanks">
          <div class="blanks-text" [innerHTML]="renderBlanksQuestion()"></div>
          <div class="blanks-inputs">
            <div *ngFor="let blank of blankIndices" class="blank-input-group">
              <label>Blank {{ blank }}:</label>
              <input
                type="text"
                class="form-control"
                [value]="localAnswer.blanks?.[blank] || ''"
                (input)="onBlankInput(blank, $event)"
                [disabled]="disabled"
                placeholder="Enter your answer">
            </div>
          </div>
        </div>

        <!-- Short Answer -->
        <div *ngIf="question.question_type === 'short_answer'" class="short-answer">
          <input
            type="text"
            class="form-control form-control-lg"
            [(ngModel)]="localAnswer.text"
            (ngModelChange)="emitAnswer()"
            [disabled]="disabled"
            placeholder="Type your answer here...">
        </div>

        <!-- Long Answer / Essay -->
        <div *ngIf="question.question_type === 'long_answer'" class="long-answer">
          <textarea
            class="form-control"
            rows="10"
            [(ngModel)]="localAnswer.text"
            (ngModelChange)="emitAnswer()"
            [disabled]="disabled"
            placeholder="Write your answer here..."
            [attr.minlength]="question.min_words"
            [attr.maxlength]="question.max_words ? question.max_words * 10 : null">
          </textarea>
          <div class="word-count">
            <span>{{ getWordCount() }} words</span>
            <span *ngIf="question.min_words"> (Min: {{ question.min_words }})</span>
            <span *ngIf="question.max_words"> (Max: {{ question.max_words }})</span>
          </div>
        </div>

        <!-- Matching -->
        <div *ngIf="question.question_type === 'matching'" class="matching">
          <div class="matching-instruction">Match the items on the left with the correct items on the right.</div>
          <div class="matching-grid">
            <div *ngFor="let option of question.options" class="matching-row">
              <div class="matching-left">
                <span [innerHTML]="sanitize(option.option_html || option.option_text)"></span>
                <img *ngIf="option.option_image" [src]="option.option_image" class="matching-image">
              </div>
              <div class="matching-arrow">
                <i class="bi bi-arrow-right"></i>
              </div>
              <div class="matching-right">
                <select
                  class="form-select"
                  [value]="localAnswer.matches?.[option.id!] || ''"
                  (change)="onMatchChange(option.id!, $event)"
                  [disabled]="disabled">
                  <option value="">-- Select --</option>
                  <option *ngFor="let match of getMatchOptions()" [value]="match">{{ match }}</option>
                </select>
              </div>
            </div>
          </div>
        </div>

        <!-- Ordering -->
        <div *ngIf="question.question_type === 'ordering'" class="ordering">
          <div class="ordering-instruction">Drag and drop to arrange items in the correct order.</div>
          <div class="ordering-list" cdkDropList (cdkDropListDropped)="onOrderDrop($event)">
            <div
              *ngFor="let item of orderedItems; let i = index"
              class="ordering-item"
              cdkDrag
              [cdkDragDisabled]="disabled">
              <div class="order-number">{{ i + 1 }}</div>
              <div class="order-content">
                <span [innerHTML]="sanitize(item.option_html || item.option_text)"></span>
              </div>
              <div class="order-handle" cdkDragHandle>
                <i class="bi bi-grip-vertical"></i>
              </div>
            </div>
          </div>
        </div>

        <!-- Hotspot -->
        <div *ngIf="question.question_type === 'hotspot'" class="hotspot">
          <div class="hotspot-instruction">Click on the correct area of the image.</div>
          <div class="hotspot-container" (click)="onHotspotClick($event)">
            <img [src]="question.hotspot_image" alt="Hotspot image" class="hotspot-image" #hotspotImage>
            <div
              *ngIf="localAnswer.clicked_coords"
              class="hotspot-marker"
              [style.left.px]="localAnswer.clicked_coords.x"
              [style.top.px]="localAnswer.clicked_coords.y">
              <i class="bi bi-geo-alt-fill"></i>
            </div>
            <!-- Show correct areas in feedback mode -->
            <ng-container *ngIf="showFeedback">
              <div
                *ngFor="let option of question.options"
                class="hotspot-area"
                [class.correct-area]="option.is_correct"
                [style]="getHotspotAreaStyle(option)">
              </div>
            </ng-container>
          </div>
        </div>

        <!-- Numeric -->
        <div *ngIf="question.question_type === 'numeric'" class="numeric-answer">
          <div class="numeric-input-group">
            <input
              type="number"
              class="form-control form-control-lg"
              [(ngModel)]="localAnswer.value"
              (ngModelChange)="emitAnswer()"
              [disabled]="disabled"
              placeholder="Enter a number"
              [step]="question.numeric_tolerance || 'any'">
            <span *ngIf="question.numeric_unit" class="input-group-text">{{ question.numeric_unit }}</span>
          </div>
          <p *ngIf="question.numeric_tolerance" class="tolerance-hint">
            Tolerance: ±{{ question.numeric_tolerance }}
          </p>
        </div>

        <!-- File Upload -->
        <div *ngIf="question.question_type === 'file_upload'" class="file-upload">
          <div class="upload-zone"
               [class.has-file]="localAnswer.file_name"
               (dragover)="onDragOver($event)"
               (drop)="onFileDrop($event)">
            <input
              type="file"
              #fileInput
              (change)="onFileSelect($event)"
              [accept]="getAcceptedFileTypes()"
              [disabled]="disabled"
              style="display: none">

            <div *ngIf="!localAnswer.file_name" class="upload-placeholder" (click)="fileInput.click()">
              <i class="bi bi-cloud-upload"></i>
              <p>Drag & drop your file here, or click to browse</p>
              <span class="file-types">Accepted: {{ question.allowed_file_types }}</span>
              <span class="file-size">Max size: {{ question.max_file_size_mb }}MB</span>
            </div>

            <div *ngIf="localAnswer.file_name" class="uploaded-file">
              <i class="bi bi-file-earmark-check"></i>
              <div class="file-info">
                <span class="file-name">{{ localAnswer.file_name }}</span>
                <span class="file-size">{{ formatFileSize(localAnswer.file_size || 0) }}</span>
              </div>
              <button class="btn btn-sm btn-outline-danger" (click)="removeFile()" [disabled]="disabled">
                <i class="bi bi-x"></i>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Feedback Section (for review mode) -->
      <div *ngIf="showFeedback" class="feedback-section">
        <div class="feedback-result" [class.correct]="answer?.is_correct" [class.partial]="answer?.is_partial" [class.incorrect]="!answer?.is_correct && !answer?.is_partial">
          <i class="bi" [class.bi-check-circle-fill]="answer?.is_correct" [class.bi-dash-circle-fill]="answer?.is_partial" [class.bi-x-circle-fill]="!answer?.is_correct && !answer?.is_partial"></i>
          <span>
            {{ answer?.is_correct ? 'Correct!' : answer?.is_partial ? 'Partially Correct' : 'Incorrect' }}
            ({{ answer?.points_earned }}/{{ answer?.points_possible }} points)
          </span>
        </div>

        <div *ngIf="question.explanation" class="explanation">
          <strong>Explanation:</strong>
          <p>{{ question.explanation }}</p>
        </div>

        <div *ngIf="answer?.grader_feedback" class="grader-feedback">
          <strong>Instructor Feedback:</strong>
          <p>{{ answer.grader_feedback }}</p>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .question-display {
      background: #fff;
      border-radius: 12px;
      padding: 2rem;
      box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    }

    .question-display.disabled {
      opacity: 0.7;
      pointer-events: none;
    }

    .question-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 1.5rem;
    }

    .question-type-badge {
      padding: 0.375rem 0.75rem;
      border-radius: 20px;
      font-size: 0.75rem;
      font-weight: 600;
      color: #fff;
      text-transform: uppercase;
      letter-spacing: 0.025em;
    }

    .question-points {
      font-size: 0.875rem;
      color: #64748b;
      font-weight: 500;
    }

    .question-text {
      font-size: 1.125rem;
      line-height: 1.7;
      color: #1e293b;
      margin-bottom: 1.5rem;
    }

    .question-media {
      margin-bottom: 1.5rem;
    }

    .question-image {
      max-width: 100%;
      max-height: 400px;
      border-radius: 8px;
      object-fit: contain;
    }

    .question-audio, .question-video {
      width: 100%;
      max-width: 500px;
      border-radius: 8px;
    }

    .question-hint {
      display: flex;
      align-items: flex-start;
      gap: 0.5rem;
      padding: 1rem;
      background: #fef3c7;
      border-radius: 8px;
      margin-bottom: 1.5rem;
      color: #92400e;
    }

    .question-hint i {
      color: #f59e0b;
    }

    .answer-area {
      margin-top: 1.5rem;
    }

    /* MCQ Styles */
    .mcq-options {
      display: flex;
      flex-direction: column;
      gap: 0.75rem;
    }

    .mcq-option {
      display: flex;
      align-items: center;
      gap: 1rem;
      padding: 1rem 1.25rem;
      border: 2px solid #e2e8f0;
      border-radius: 10px;
      cursor: pointer;
      transition: all 0.2s;
    }

    .mcq-option:hover {
      border-color: #94a3b8;
      background: #f8fafc;
    }

    .mcq-option.selected {
      border-color: #3b82f6;
      background: #eff6ff;
    }

    .mcq-option.correct {
      border-color: #22c55e;
      background: #f0fdf4;
    }

    .mcq-option.incorrect {
      border-color: #ef4444;
      background: #fef2f2;
    }

    .option-marker {
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #e2e8f0;
      border-radius: 50%;
      font-weight: 600;
      color: #475569;
      flex-shrink: 0;
    }

    .mcq-option.selected .option-marker {
      background: #3b82f6;
      color: #fff;
    }

    .option-checkbox {
      font-size: 1.25rem;
      color: #94a3b8;
    }

    .mcq-option.selected .option-checkbox {
      color: #3b82f6;
    }

    .option-content {
      flex: 1;
    }

    .option-image {
      max-width: 200px;
      max-height: 150px;
      margin-top: 0.5rem;
      border-radius: 6px;
    }

    .selected-icon {
      color: #3b82f6;
      font-size: 1.25rem;
    }

    .helper-text {
      font-size: 0.875rem;
      color: #64748b;
      margin-top: 0.75rem;
      font-style: italic;
    }

    /* True/False */
    .true-false-options {
      display: flex;
      gap: 1rem;
    }

    .tf-option {
      flex: 1;
      padding: 1.5rem;
      border: 2px solid #e2e8f0;
      border-radius: 12px;
      background: #fff;
      cursor: pointer;
      transition: all 0.2s;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 0.5rem;
      font-size: 1.125rem;
      font-weight: 600;
    }

    .tf-option i {
      font-size: 2rem;
    }

    .tf-option:hover {
      border-color: #94a3b8;
      background: #f8fafc;
    }

    .tf-option.selected {
      border-color: #3b82f6;
      background: #eff6ff;
      color: #1d4ed8;
    }

    .tf-option.correct {
      border-color: #22c55e;
      background: #f0fdf4;
      color: #15803d;
    }

    .tf-option.incorrect {
      border-color: #ef4444;
      background: #fef2f2;
      color: #dc2626;
    }

    /* Fill in Blanks */
    .fill-blanks .blanks-text {
      font-size: 1.125rem;
      line-height: 2;
      margin-bottom: 1.5rem;
    }

    .fill-blanks .blanks-text .blank-slot {
      display: inline-block;
      min-width: 100px;
      border-bottom: 2px solid #3b82f6;
      margin: 0 0.25rem;
      padding: 0.125rem 0.5rem;
      background: #eff6ff;
    }

    .blanks-inputs {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .blank-input-group {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .blank-input-group label {
      min-width: 80px;
      font-weight: 500;
      color: #475569;
    }

    /* Long Answer */
    .long-answer textarea {
      resize: vertical;
      min-height: 200px;
    }

    .word-count {
      margin-top: 0.5rem;
      font-size: 0.875rem;
      color: #64748b;
      text-align: right;
    }

    /* Matching */
    .matching-instruction {
      margin-bottom: 1rem;
      color: #64748b;
      font-style: italic;
    }

    .matching-grid {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .matching-row {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .matching-left {
      flex: 1;
      padding: 1rem;
      background: #f8fafc;
      border-radius: 8px;
    }

    .matching-arrow {
      color: #94a3b8;
      font-size: 1.25rem;
    }

    .matching-right {
      flex: 1;
    }

    /* Ordering */
    .ordering-instruction {
      margin-bottom: 1rem;
      color: #64748b;
      font-style: italic;
    }

    .ordering-list {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }

    .ordering-item {
      display: flex;
      align-items: center;
      gap: 1rem;
      padding: 1rem;
      background: #fff;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      cursor: grab;
    }

    .ordering-item:active {
      cursor: grabbing;
    }

    .order-number {
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: #3b82f6;
      color: #fff;
      border-radius: 50%;
      font-weight: 600;
    }

    .order-content {
      flex: 1;
    }

    .order-handle {
      color: #94a3b8;
      cursor: grab;
    }

    /* Hotspot */
    .hotspot-instruction {
      margin-bottom: 1rem;
      color: #64748b;
      font-style: italic;
    }

    .hotspot-container {
      position: relative;
      display: inline-block;
      cursor: crosshair;
    }

    .hotspot-image {
      max-width: 100%;
      border-radius: 8px;
    }

    .hotspot-marker {
      position: absolute;
      transform: translate(-50%, -100%);
      font-size: 2rem;
      color: #ef4444;
      filter: drop-shadow(0 2px 4px rgba(0,0,0,0.3));
    }

    .hotspot-area {
      position: absolute;
      border: 3px dashed #22c55e;
      background: rgba(34, 197, 94, 0.1);
    }

    /* Numeric */
    .numeric-input-group {
      display: flex;
      max-width: 300px;
    }

    .numeric-input-group .form-control {
      text-align: center;
      font-size: 1.5rem;
    }

    .tolerance-hint {
      margin-top: 0.5rem;
      font-size: 0.875rem;
      color: #64748b;
    }

    /* File Upload */
    .upload-zone {
      border: 2px dashed #cbd5e1;
      border-radius: 12px;
      padding: 2rem;
      text-align: center;
      transition: all 0.2s;
    }

    .upload-zone:hover, .upload-zone.dragover {
      border-color: #3b82f6;
      background: #eff6ff;
    }

    .upload-zone.has-file {
      border-style: solid;
      border-color: #22c55e;
      background: #f0fdf4;
    }

    .upload-placeholder {
      cursor: pointer;
    }

    .upload-placeholder i {
      font-size: 3rem;
      color: #94a3b8;
      margin-bottom: 1rem;
    }

    .upload-placeholder p {
      margin: 0;
      color: #475569;
    }

    .upload-placeholder .file-types, .upload-placeholder .file-size {
      display: block;
      font-size: 0.75rem;
      color: #94a3b8;
      margin-top: 0.5rem;
    }

    .uploaded-file {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .uploaded-file i {
      font-size: 2.5rem;
      color: #22c55e;
    }

    .file-info {
      flex: 1;
      text-align: left;
    }

    .file-name {
      display: block;
      font-weight: 500;
      color: #1e293b;
    }

    .file-info .file-size {
      font-size: 0.75rem;
      color: #64748b;
    }

    /* Feedback */
    .feedback-section {
      margin-top: 2rem;
      padding-top: 1.5rem;
      border-top: 1px solid #e2e8f0;
    }

    .feedback-result {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 1rem;
      border-radius: 8px;
      font-weight: 600;
      margin-bottom: 1rem;
    }

    .feedback-result.correct {
      background: #f0fdf4;
      color: #15803d;
    }

    .feedback-result.partial {
      background: #fef3c7;
      color: #92400e;
    }

    .feedback-result.incorrect {
      background: #fef2f2;
      color: #dc2626;
    }

    .feedback-result i {
      font-size: 1.5rem;
    }

    .explanation, .grader-feedback {
      padding: 1rem;
      background: #f8fafc;
      border-radius: 8px;
      margin-top: 1rem;
    }

    .explanation strong, .grader-feedback strong {
      color: #1e293b;
    }

    .explanation p, .grader-feedback p {
      margin: 0.5rem 0 0;
      color: #475569;
    }
  `]
})
export class QuestionDisplayComponent implements OnChanges {
  @Input() question!: Question;
  @Input() answer?: AttemptAnswer;
  @Input() showFeedback = false;
  @Input() disabled = false;
  @Output() answerChanged = new EventEmitter<AnswerData>();

  localAnswer: AnswerData = {};
  shuffledOptions: QuestionOption[] = [];
  orderedItems: QuestionOption[] = [];
  showHint = false;

  constructor(private sanitizer: DomSanitizer, private toast: HotToastService) {}

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['question']) {
      this.initializeQuestion();
    }
    if (changes['answer']) {
      this.localAnswer = this.answer?.answer_data ? { ...this.answer.answer_data } : {};
    }
  }

  private initializeQuestion(): void {
    this.showHint = false;

    // Shuffle options for MCQ
    if (this.question.options) {
      this.shuffledOptions = [...this.question.options];
      // Only shuffle if not in feedback mode
      if (!this.showFeedback) {
        this.shuffledOptions.sort(() => Math.random() - 0.5);
      }
    }

    // Initialize ordered items for ordering question-banks
    if (this.question.question_type === 'ordering' && this.question.options) {
      if (this.localAnswer.order && this.localAnswer.order.length > 0) {
        this.orderedItems = this.localAnswer.order.map(id =>
          this.question.options!.find(o => o.id === id)!
        ).filter(Boolean);
      } else {
        this.orderedItems = [...this.question.options].sort(() => Math.random() - 0.5);
        this.localAnswer.order = this.orderedItems.map(o => o.id!);
      }
    }
  }

  get sanitizedQuestionText(): SafeHtml {
    return this.sanitizer.bypassSecurityTrustHtml(
      this.question.question_html || this.question.question_text
    );
  }

  sanitize(html: string): SafeHtml {
    return this.sanitizer.bypassSecurityTrustHtml(html);
  }

  get blankIndices(): number[] {
    const matches = this.question.question_text.match(/\[\[(\d+)\]\]/g) || [];
    return [...new Set(matches.map(m => parseInt(m.replace(/\[|\]/g, ''))))].sort((a, b) => a - b);
  }

  get correctTFAnswer(): boolean | null {
    const correct = this.question.options?.find(o => o.is_correct);
    return correct ? correct.option_text.toLowerCase() === 'true' : null;
  }

  getTypeLabel(): string {
    return getQuestionTypeLabel(this.question.question_type);
  }

  getTypeBadgeColor(): string {
    const colors: Record<string, string> = {
      'multiple_choice_single': '#3b82f6',
      'multiple_choice_multiple': '#8b5cf6',
      'true_false': '#10b981',
      'fill_in_blanks': '#f59e0b',
      'short_answer': '#06b6d4',
      'long_answer': '#ec4899',
      'matching': '#6366f1',
      'ordering': '#14b8a6',
      'hotspot': '#f97316',
      'numeric': '#84cc16',
      'file_upload': '#64748b'
    };
    return colors[this.question.question_type] || '#6b7280';
  }

  getOptionLetter(index: number): string {
    return String.fromCharCode(65 + index);
  }

  // MCQ Single
  selectSingleOption(optionId: number): void {
    this.localAnswer.selected_option_id = optionId;
    this.emitAnswer();
  }

  // MCQ Multiple
  isOptionSelected(optionId: number): boolean {
    return this.localAnswer.selected_option_ids?.includes(optionId) || false;
  }

  toggleMultipleOption(optionId: number): void {
    if (!this.localAnswer.selected_option_ids) {
      this.localAnswer.selected_option_ids = [];
    }
    const index = this.localAnswer.selected_option_ids.indexOf(optionId);
    if (index > -1) {
      this.localAnswer.selected_option_ids.splice(index, 1);
    } else {
      this.localAnswer.selected_option_ids.push(optionId);
    }
    this.emitAnswer();
  }

  // True/False
  selectTrueFalse(value: boolean): void {
    this.localAnswer.answer = value;
    this.emitAnswer();
  }

  // Fill in Blanks
  renderBlanksQuestion(): SafeHtml {
    let html = this.question.question_html || this.question.question_text;
    html = html.replace(/\[\[(\d+)\]\]/g, '<span class="blank-slot">____</span>');
    return this.sanitizer.bypassSecurityTrustHtml(html);
  }

  onBlankInput(blankIndex: number, event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    if (!this.localAnswer.blanks) {
      this.localAnswer.blanks = {};
    }
    this.localAnswer.blanks[blankIndex.toString()] = value;
    this.emitAnswer();
  }

  // Matching
  getMatchOptions(): string[] {
    return this.question.options?.map(o => o.match_text || '').filter(Boolean) || [];
  }

  onMatchChange(optionId: number, event: Event): void {
    const value = (event.target as HTMLSelectElement).value;
    if (!this.localAnswer.matches) {
      this.localAnswer.matches = {};
    }
    this.localAnswer.matches[optionId.toString()] = value;
    this.emitAnswer();
  }

  // Ordering
  onOrderDrop(event: any): void {
    const prev = event.previousIndex;
    const curr = event.currentIndex;

    const item = this.orderedItems.splice(prev, 1)[0];
    this.orderedItems.splice(curr, 0, item);

    this.localAnswer.order = this.orderedItems.map(o => o.id!);
    this.emitAnswer();
  }

  // Hotspot
  onHotspotClick(event: MouseEvent): void {
    const target = event.target as HTMLElement;
    const rect = target.getBoundingClientRect();

    this.localAnswer.clicked_coords = {
      x: Math.round(event.clientX - rect.left),
      y: Math.round(event.clientY - rect.top)
    };
    this.emitAnswer();
  }

  getHotspotAreaStyle(option: QuestionOption): any {
    if (!option.hotspot_coords) return {};

    const coords = option.hotspot_coords;

    if (this.question.hotspot_shape === 'rectangle') {
      return {
        left: coords.x1 + 'px',
        top: coords.y1 + 'px',
        width: (coords.x2! - coords.x1!) + 'px',
        height: (coords.y2! - coords.y1!) + 'px'
      };
    }

    if (this.question.hotspot_shape === 'circle') {
      return {
        left: (coords.cx! - coords.radius!) + 'px',
        top: (coords.cy! - coords.radius!) + 'px',
        width: (coords.radius! * 2) + 'px',
        height: (coords.radius! * 2) + 'px',
        borderRadius: '50%'
      };
    }

    return {};
  }

  // File Upload
  getAcceptedFileTypes(): string {
    return this.question.allowed_file_types
      .split(',')
      .map(t => '.' + t.trim())
      .join(',');
  }

  onDragOver(event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();
  }

  onFileDrop(event: DragEvent): void {
    event.preventDefault();
    event.stopPropagation();

    const files = event.dataTransfer?.files;
    if (files && files.length > 0) {
      this.handleFile(files[0]);
    }
  }

  onFileSelect(event: Event): void {
    const input = event.target as HTMLInputElement;
    if (input.files && input.files.length > 0) {
      this.handleFile(input.files[0]);
    }
  }

  private handleFile(file: File): void {
    // Validate file size
    const maxSize = (this.question.max_file_size_mb || 10) * 1024 * 1024;
    if (file.size > maxSize) {
      this.toast.error(`File too large. Maximum size is ${this.question.max_file_size_mb}MB.`);
      return;
    }

    // Validate file type
    const ext = file.name.split('.').pop()?.toLowerCase();
    const allowed = this.question.allowed_file_types.split(',').map(t => t.trim().toLowerCase());
    if (ext && !allowed.includes(ext)) {
      this.toast.error(`Invalid file type. Allowed types: ${this.question.allowed_file_types}`);
      return;
    }

    this.localAnswer.file_name = file.name;
    this.localAnswer.file_size = file.size;
    // In real implementation, upload file and get path
    this.localAnswer.file_path = 'pending_upload';
    this.emitAnswer();
  }

  removeFile(): void {
    delete this.localAnswer.file_name;
    delete this.localAnswer.file_size;
    delete this.localAnswer.file_path;
    this.emitAnswer();
  }

  formatFileSize(bytes: number): string {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }

  // Word count for long answer
  getWordCount(): number {
    const text = this.localAnswer.text || '';
    return text.trim() ? text.trim().split(/\s+/).length : 0;
  }

  // Emit answer changes
  emitAnswer(): void {
    this.answerChanged.emit({ ...this.localAnswer });
  }
}
