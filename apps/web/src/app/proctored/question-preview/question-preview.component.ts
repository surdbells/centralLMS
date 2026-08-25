// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 9
// Admin: Question Bank - Question Preview Component
// Multi-Tenant LMS | Angular Standalone
// Interactive preview with answer checking
// =============================================

import { Component, Input, Output, EventEmitter, OnChanges, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { TuiIcon } from '@taiga-ui/core';

interface QuestionOption { option_text: string; is_correct: boolean; feedback: string; order_position: number; }
interface MatchPair { premise: string; response: string; order_position: number; }
interface OrderItem { item_text: string; correct_position: number; }
interface FillBlank { blank_index: number; acceptable_answers: string[]; case_sensitive: boolean; }
interface HotspotArea { shape: 'rect'|'circle'; x: number; y: number; width: number; height: number; label: string; is_correct: boolean; }

interface PreviewQuestion {
  id: number;
  question_text: string;
  question_type: string;
  difficulty: string;
  points: number;
  explanation: string;
  hint: string;
  image_url?: string;
  time_limit_seconds?: number;
  options?: QuestionOption[];
  match_pairs?: MatchPair[];
  order_items?: OrderItem[];
  fill_blanks?: FillBlank[];
  hotspot_areas?: HotspotArea[];
  hotspot_image_url?: string;
  numeric_answer?: number;
  numeric_tolerance?: number;
  numeric_unit?: string;
  sample_answer?: string;
  max_length?: number;
  allowed_extensions?: string;
  max_file_size_mb?: number;
}

@Component({
  selector: 'app-question-preview',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon],
  template: `
    <div class="preview-container">
      <!-- Header -->
      <div class="preview-header">
        <div class="preview-info">
          <span class="type-badge" [attr.data-type]="question.question_type">{{ typeLabel }}</span>
          <span class="diff-badge" [attr.data-diff]="question.difficulty">{{ question.difficulty }}</span>
          <span class="pts-badge">{{ question.points }} pt{{ question.points > 1 ? 's' : '' }}</span>
          <span class="time-badge" *ngIf="question.time_limit_seconds">
            <tui-icon icon="@tui.clock" style="font-size:14px"></tui-icon> {{ question.time_limit_seconds }}s
          </span>
        </div>
        <div class="preview-mode-toggle">
          <button class="mode-btn" [class.active]="mode==='answer'" (click)="mode='answer'; resetAnswer()">
            <tui-icon icon="@tui.edit-3" style="font-size:14px"></tui-icon> Try It
          </button>
          <button class="mode-btn" [class.active]="mode==='review'" (click)="mode='review'">
            <tui-icon icon="@tui.eye" style="font-size:14px"></tui-icon> Review
          </button>
        </div>
        <button class="btn-close" *ngIf="closable" (click)="onClose.emit()">
          <tui-icon icon="@tui.x" style="font-size:18px"></tui-icon>
        </button>
      </div>

      <!-- Question Text -->
      <div class="question-body">
        <div class="q-text" [innerHTML]="question.question_text"></div>
        <img *ngIf="question.image_url" [src]="question.image_url" class="q-image" />
      </div>

      <!-- Hint -->
      <div class="hint-box" *ngIf="question.hint && showHint">
        <tui-icon icon="@tui.lightbulb" style="font-size:16px;color:#f59e0b"></tui-icon>
        <span>{{ question.hint }}</span>
      </div>
      <button class="btn btn-sm btn-ghost hint-btn" *ngIf="question.hint && !showHint && mode==='answer'"
              (click)="showHint=true">
        <tui-icon icon="@tui.lightbulb" style="font-size:14px"></tui-icon> Show Hint
      </button>

      <!-- ============== ANSWER MODE ============== -->
      <ng-container *ngIf="mode==='answer'">

        <!-- Multiple Choice -->
        <div class="answer-area" *ngIf="question.question_type==='multiple_choice'">
          <div *ngFor="let o of question.options; let i=index" class="mc-option"
               [class.selected]="selectedOptions[i]"
               [class.correct]="submitted && o.is_correct"
               [class.incorrect]="submitted && selectedOptions[i] && !o.is_correct"
               (click)="!submitted && selectMC(i)">
            <span class="mc-marker" [class.sel]="selectedOptions[i]">{{ getMarker(i) }}</span>
            <span class="mc-text">{{ o.option_text }}</span>
            <tui-icon *ngIf="submitted && o.is_correct" icon="@tui.check" style="font-size:16px;color:#22c55e;margin-left:auto"></tui-icon>
            <tui-icon *ngIf="submitted && selectedOptions[i] && !o.is_correct" icon="@tui.x" style="font-size:16px;color:#ef4444;margin-left:auto"></tui-icon>
          </div>
        </div>

        <!-- Multiple Response -->
        <div class="answer-area" *ngIf="question.question_type==='multiple_response'">
          <div *ngFor="let o of question.options; let i=index" class="mc-option"
               [class.selected]="selectedOptions[i]"
               [class.correct]="submitted && o.is_correct"
               [class.incorrect]="submitted && selectedOptions[i] && !o.is_correct"
               (click)="!submitted && toggleMR(i)">
            <span class="mr-marker" [class.sel]="selectedOptions[i]">
              <tui-icon *ngIf="selectedOptions[i]" icon="@tui.check" style="font-size:12px"></tui-icon>
            </span>
            <span class="mc-text">{{ o.option_text }}</span>
            <tui-icon *ngIf="submitted && o.is_correct" icon="@tui.check" style="font-size:16px;color:#22c55e;margin-left:auto"></tui-icon>
          </div>
        </div>

        <!-- True/False -->
        <div class="answer-area tf-area" *ngIf="question.question_type==='true_false'">
          <button class="tf-choice" [class.selected]="tfAnswer===true"
                  [class.correct]="submitted && question.options?.[0]?.is_correct===true"
                  [class.incorrect]="submitted && tfAnswer===true && question.options?.[0]?.is_correct!==true"
                  (click)="!submitted && (tfAnswer=true)">
            <tui-icon icon="@tui.check-circle" style="font-size:22px"></tui-icon> True
          </button>
          <button class="tf-choice" [class.selected]="tfAnswer===false"
                  [class.correct]="submitted && question.options?.[0]?.is_correct===false"
                  [class.incorrect]="submitted && tfAnswer===false && question.options?.[0]?.is_correct!==false"
                  (click)="!submitted && (tfAnswer=false)">
            <tui-icon icon="@tui.x-circle" style="font-size:22px"></tui-icon> False
          </button>
        </div>

        <!-- Short Answer -->
        <div class="answer-area" *ngIf="question.question_type==='short_answer'">
          <input type="text" class="answer-input" [(ngModel)]="textAnswer" placeholder="Type your answer..."
                 [disabled]="submitted" />
          <div class="answer-feedback" *ngIf="submitted">
            <tui-icon [icon]="isCorrectShort() ? '@tui.check-circle' : '@tui.x-circle'"
                      [style.color]="isCorrectShort() ? '#22c55e' : '#ef4444'" style="font-size:18px"></tui-icon>
            <span> {{ isCorrectShort() ? 'Correct!' : 'Incorrect. Accepted: ' + getAcceptedOptions() }} </span>
          </div>
        </div>

        <!-- Long Answer -->
        <div class="answer-area" *ngIf="question.question_type==='long_answer'">
          <textarea class="answer-textarea" [(ngModel)]="textAnswer" [disabled]="submitted"
                    placeholder="Write your essay answer..." rows="6"></textarea>
          <div class="word-count">{{ wordCount }} / {{ question.max_length || 500 }} words</div>
        </div>

        <!-- Numeric -->
        <div class="answer-area" *ngIf="question.question_type==='numeric'">
          <div class="numeric-input-row">
            <input type="number" class="answer-input" [(ngModel)]="numericAnswer" [disabled]="submitted"
                   placeholder="Enter number..." step="any" />
            <span class="unit" *ngIf="question.numeric_unit">{{ question.numeric_unit }}</span>
          </div>
          <div class="answer-feedback" *ngIf="submitted">
            <tui-icon [icon]="isCorrectNumeric() ? '@tui.check-circle' : '@tui.x-circle'"
                      [style.color]="isCorrectNumeric() ? '#22c55e' : '#ef4444'" style="font-size:18px"></tui-icon>
            <span>{{ isCorrectNumeric() ? 'Correct!' : 'Incorrect. Answer: ' + question.numeric_answer + ' (±' + question.numeric_tolerance + ')' }}</span>
          </div>
        </div>

        <!-- Fill in the Blank -->
        <div class="answer-area" *ngIf="question.question_type==='fill_blank'">
          <div *ngFor="let b of question.fill_blanks; let i=index" class="blank-answer-row">
            <span class="blank-num">Blank {{ i+1 }}:</span>
            <input type="text" class="answer-input" [(ngModel)]="blankAnswers[i]" [disabled]="submitted"
                   placeholder="Your answer..." />
            <tui-icon *ngIf="submitted" [icon]="isCorrectBlank(i) ? '@tui.check-circle' : '@tui.x-circle'"
                      [style.color]="isCorrectBlank(i) ? '#22c55e' : '#ef4444'" style="font-size:18px"></tui-icon>
          </div>
        </div>

        <!-- Matching -->
        <div class="answer-area" *ngIf="question.question_type==='matching'">
          <div class="match-preview">
            <div *ngFor="let p of question.match_pairs; let i=index" class="match-preview-row">
              <span class="match-premise">{{ p.premise }}</span>
              <tui-icon icon="@tui.arrow-right" style="font-size:14px;color:#94a3b8"></tui-icon>
              <select class="form-control match-select" [(ngModel)]="matchAnswers[i]" [disabled]="submitted">
                <option value="">-- Select --</option>
                <option *ngFor="let r of shuffledResponses" [value]="r">{{ r }}</option>
              </select>
              <tui-icon *ngIf="submitted" [icon]="matchAnswers[i]===p.response ? '@tui.check-circle' : '@tui.x-circle'"
                        [style.color]="matchAnswers[i]===p.response ? '#22c55e' : '#ef4444'" style="font-size:18px"></tui-icon>
            </div>
          </div>
        </div>

        <!-- Ordering -->
        <div class="answer-area" *ngIf="question.question_type==='ordering'">
          <div class="order-preview">
            <div *ngFor="let item of orderAnswer; let i=index" class="order-preview-item"
                 draggable="true" (dragstart)="orderDragIdx=i" (dragover)="$event.preventDefault()"
                 (drop)="reorderItem(i)"
                 [class.correct]="submitted && item.text === question.order_items?.[i]?.item_text"
                 [class.incorrect]="submitted && item.text !== question.order_items?.[i]?.item_text">
              <span class="order-pos">{{ i+1 }}</span>
              <tui-icon icon="@tui.grip-vertical" style="font-size:14px;color:#94a3b8;cursor:grab"></tui-icon>
              <span class="order-text">{{ item.text }}</span>
            </div>
          </div>
        </div>

        <!-- File Upload (preview mode only) -->
        <div class="answer-area" *ngIf="question.question_type==='file_upload'">
          <div class="file-upload-preview">
            <tui-icon icon="@tui.upload-cloud" style="font-size:36px;color:#94a3b8"></tui-icon>
            <p>File upload area (preview only)</p>
            <span class="hint">Accepted: {{ question.allowed_extensions || 'Any' }} | Max: {{ question.max_file_size_mb || 10 }}MB</span>
          </div>
        </div>

        <!-- Hotspot -->
        <div class="answer-area" *ngIf="question.question_type==='hotspot'">
          <div class="hs-preview" (click)="!submitted && onHotspotClick($event)" #hsPreview>
            <img *ngIf="question.hotspot_image_url" [src]="question.hotspot_image_url" class="hs-img" />
            <div *ngFor="let a of question.hotspot_areas; let i=index" class="hs-overlay"
                 [class.show-correct]="submitted && a.is_correct"
                 [style.left.px]="a.x" [style.top.px]="a.y"
                 [style.width.px]="a.width" [style.height.px]="a.height"
                 [style.borderRadius]="a.shape==='circle'?'50%':'4px'">
            </div>
            <div *ngIf="hotspotClick" class="hs-click-marker"
                 [style.left.px]="hotspotClick.x - 10" [style.top.px]="hotspotClick.y - 10"
                 [class.correct]="submitted && isCorrectHotspot()"
                 [class.incorrect]="submitted && !isCorrectHotspot()">
            </div>
          </div>
        </div>

        <!-- Submit Button -->
        <div class="submit-area" *ngIf="!submitted && question.question_type !== 'file_upload'">
          <button class="btn btn-primary" (click)="submit()" [disabled]="!canSubmit()">
            <tui-icon icon="@tui.check" style="font-size:16px"></tui-icon> Check Answer
          </button>
          <button class="btn btn-ghost" (click)="resetAnswer()">Reset</button>
        </div>

        <!-- Result -->
        <div class="result-card" *ngIf="submitted" [class.correct]="isOverallCorrect()" [class.incorrect]="!isOverallCorrect()">
          <div class="result-header">
            <tui-icon [icon]="isOverallCorrect() ? '@tui.check-circle' : '@tui.x-circle'" style="font-size:22px"></tui-icon>
            <span>{{ isOverallCorrect() ? 'Correct!' : 'Incorrect' }}</span>
            <span class="result-score">{{ getScore() }}/{{ question.points }} pts</span>
          </div>
          <!-- Feedback -->
          <div class="result-feedback" *ngIf="getFeedback()">{{ getFeedback() }}</div>
        </div>

        <!-- Explanation (after submit) -->
        <div class="explanation-card" *ngIf="submitted && question.explanation">
          <h4><tui-icon icon="@tui.info" style="font-size:16px"></tui-icon> Explanation</h4>
          <div [innerHTML]="question.explanation"></div>
        </div>

        <button class="btn btn-outline mt-12" *ngIf="submitted" (click)="resetAnswer()">
          <tui-icon icon="@tui.refresh-cw" style="font-size:14px"></tui-icon> Try Again
        </button>
      </ng-container>

      <!-- ============== REVIEW MODE ============== -->
      <ng-container *ngIf="mode==='review'">
        <!-- Show correct answers -->
        <div class="review-section" *ngIf="question.question_type==='multiple_choice' || question.question_type==='multiple_response'">
          <h4>Options</h4>
          <div *ngFor="let o of question.options; let i=index" class="review-option" [class.correct]="o.is_correct">
            <span class="mc-marker" [class.sel]="o.is_correct">{{ getMarker(i) }}</span>
            <span>{{ o.option_text }}</span>
            <tui-icon *ngIf="o.is_correct" icon="@tui.check" style="font-size:14px;color:#22c55e;margin-left:auto"></tui-icon>
            <span class="review-fb" *ngIf="o.feedback">{{ o.feedback }}</span>
          </div>
        </div>

        <div class="review-section" *ngIf="question.question_type==='true_false'">
          <h4>Correct Answer</h4>
          <p class="review-answer">{{ question.options?.[0]?.is_correct ? 'True' : 'False' }}</p>
        </div>

        <div class="review-section" *ngIf="question.question_type==='short_answer'">
          <h4>Accepted Answers</h4>
          <div class="review-chips">
            <span *ngFor="let o of question.options" class="review-chip">{{ o.option_text }}</span>
          </div>
        </div>

        <div class="review-section" *ngIf="question.question_type==='numeric'">
          <h4>Correct Answer</h4>
          <p class="review-answer">{{ question.numeric_answer }} ± {{ question.numeric_tolerance }} {{ question.numeric_unit }}</p>
        </div>

        <div class="review-section" *ngIf="question.question_type==='fill_blank'">
          <h4>Blanks</h4>
          <div *ngFor="let b of question.fill_blanks; let i=index" class="review-blank">
            <strong>Blank {{ i+1 }}:</strong> {{ b.acceptable_answers.join(' / ') }}
            <span *ngIf="b.case_sensitive" class="case-badge">Case-sensitive</span>
          </div>
        </div>

        <div class="review-section" *ngIf="question.question_type==='matching'">
          <h4>Correct Pairs</h4>
          <div *ngFor="let p of question.match_pairs" class="review-match">
            <span>{{ p.premise }}</span>
            <tui-icon icon="@tui.arrow-right" style="font-size:14px;color:#22c55e"></tui-icon>
            <span>{{ p.response }}</span>
          </div>
        </div>

        <div class="review-section" *ngIf="question.question_type==='ordering'">
          <h4>Correct Order</h4>
          <div *ngFor="let it of question.order_items; let i=index" class="review-order">
            <span class="order-pos">{{ i+1 }}</span> {{ it.item_text }}
          </div>
        </div>

        <div class="review-section" *ngIf="question.question_type==='long_answer' || question.question_type==='file_upload'">
          <h4>{{ question.question_type==='long_answer' ? 'Sample Answer' : 'Grading Instructions' }}</h4>
          <p class="review-answer">{{ question.sample_answer || 'Not provided' }}</p>
        </div>

        <div class="review-section" *ngIf="question.explanation">
          <h4>Explanation</h4>
          <div [innerHTML]="question.explanation"></div>
        </div>
      </ng-container>
    </div>
  `,
  styles: [`
    :host { display: block; }
    .preview-container { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; max-width: 800px; }
    .preview-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
    .preview-info { display: flex; gap: 8px; flex: 1; flex-wrap: wrap; align-items: center; }
    .type-badge { padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
    .type-badge[data-type="multiple_choice"] { background: #dbeafe; color: #1e40af; }
    .type-badge[data-type="multiple_response"] { background: #e0e7ff; color: #3730a3; }
    .type-badge[data-type="true_false"] { background: #d1fae5; color: #065f46; }
    .type-badge[data-type="short_answer"] { background: #fef3c7; color: #92400e; }
    .type-badge[data-type="long_answer"] { background: #fde68a; color: #78350f; }
    .type-badge[data-type="numeric"] { background: #e0f2fe; color: #075985; }
    .type-badge[data-type="fill_blank"] { background: #fce7f3; color: #9d174d; }
    .type-badge[data-type="matching"] { background: #ede9fe; color: #5b21b6; }
    .type-badge[data-type="ordering"] { background: #ccfbf1; color: #115e59; }
    .type-badge[data-type="file_upload"] { background: #f1f5f9; color: #475569; }
    .type-badge[data-type="hotspot"] { background: #fef2f2; color: #991b1b; }
    .diff-badge { padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: capitalize; }
    .diff-badge[data-diff="easy"] { background: #d1fae5; color: #065f46; }
    .diff-badge[data-diff="medium"] { background: #fef3c7; color: #92400e; }
    .diff-badge[data-diff="hard"] { background: #fef2f2; color: #991b1b; }
    .pts-badge { padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: 600; background: #f1f5f9; color: #475569; }
    .time-badge { display: flex; align-items: center; gap: 4px; font-size: 12px; color: #64748b; }
    .preview-mode-toggle { display: flex; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
    .mode-btn { display: flex; align-items: center; gap: 6px; padding: 6px 14px; border: none; background: #fff; cursor: pointer; font-size: 13px; font-weight: 500; color: #64748b; }
    .mode-btn:hover { background: #f8fafc; }
    .mode-btn.active { background: #6366f1; color: #fff; }
    .btn-close { background: none; border: none; cursor: pointer; color: #94a3b8; padding: 4px; }
    .btn-close:hover { color: #0f172a; }
    .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 500; border: none; cursor: pointer; transition: all 0.15s; }
    .btn-primary { background: #6366f1; color: #fff; } .btn-primary:hover { background: #4f46e5; } .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
    .btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; }
    .btn-ghost { background: none; color: #64748b; border: none; }
    .btn-sm { padding: 5px 10px; font-size: 13px; }
    .mt-12 { margin-top: 12px; }

    .question-body { margin-bottom: 20px; }
    .q-text { font-size: 17px; line-height: 1.7; color: #0f172a; }
    .q-image { max-width: 100%; border-radius: 8px; margin-top: 12px; border: 1px solid #e2e8f0; }

    .hint-box { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; margin-bottom: 16px; font-size: 14px; color: #92400e; }
    .hint-btn { margin-bottom: 16px; }
    .hint { font-size: 12px; color: #94a3b8; }

    .answer-area { margin-bottom: 16px; }

    /* MC Options */
    .mc-option { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 10px; margin-bottom: 8px; cursor: pointer; transition: all 0.15s; }
    .mc-option:hover { border-color: #a5b4fc; background: #f5f3ff; }
    .mc-option.selected { border-color: #6366f1; background: #eef2ff; }
    .mc-option.correct { border-color: #22c55e; background: #f0fdf4; }
    .mc-option.incorrect { border-color: #ef4444; background: #fef2f2; }
    .mc-marker { width: 30px; height: 30px; border-radius: 50%; border: 2px solid #d1d5db; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; color: #64748b; flex-shrink: 0; transition: all 0.15s; }
    .mc-marker.sel { border-color: #6366f1; background: #6366f1; color: #fff; }
    .mc-text { flex: 1; font-size: 15px; color: #334155; }
    .mr-marker { width: 24px; height: 24px; border-radius: 4px; border: 2px solid #d1d5db; display: flex; align-items: center; justify-content: center; color: #fff; flex-shrink: 0; }
    .mr-marker.sel { border-color: #6366f1; background: #6366f1; }

    /* TF */
    .tf-area { display: flex; gap: 12px; }
    .tf-choice { display: flex; align-items: center; gap: 8px; padding: 16px 24px; border: 2px solid #e2e8f0; border-radius: 12px; background: #fff; cursor: pointer; font-size: 17px; font-weight: 600; flex: 1; justify-content: center; transition: all 0.15s; }
    .tf-choice:hover { border-color: #a5b4fc; }
    .tf-choice.selected { border-color: #6366f1; background: #eef2ff; }
    .tf-choice.correct { border-color: #22c55e; background: #f0fdf4; }
    .tf-choice.incorrect { border-color: #ef4444; background: #fef2f2; }

    /* Input answers */
    .answer-input { width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 16px; box-sizing: border-box; }
    .answer-input:focus { border-color: #6366f1; outline: none; }
    .answer-input:disabled { background: #f8fafc; }
    .answer-textarea { width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 15px; resize: vertical; font-family: inherit; box-sizing: border-box; }
    .answer-textarea:focus { border-color: #6366f1; outline: none; }
    .word-count { text-align: right; font-size: 12px; color: #94a3b8; margin-top: 4px; }
    .answer-feedback { display: flex; align-items: center; gap: 8px; padding: 10px 14px; border-radius: 8px; margin-top: 10px; font-size: 14px; }

    /* Numeric */
    .numeric-input-row { display: flex; align-items: center; gap: 10px; }
    .numeric-input-row .answer-input { flex: 1; }
    .unit { font-size: 16px; color: #475569; font-weight: 500; }

    /* Fill Blank */
    .blank-answer-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
    .blank-num { font-size: 14px; font-weight: 600; color: #475569; min-width: 60px; }
    .blank-answer-row .answer-input { flex: 1; }

    /* Matching */
    .match-preview-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
    .match-premise { font-size: 14px; font-weight: 500; color: #0f172a; min-width: 120px; }
    .match-select { flex: 1; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; }
    .form-control { padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; }

    /* Ordering */
    .order-preview-item { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 6px; cursor: grab; transition: all 0.15s; }
    .order-preview-item:hover { border-color: #a5b4fc; }
    .order-preview-item.correct { border-color: #22c55e; background: #f0fdf4; }
    .order-preview-item.incorrect { border-color: #ef4444; background: #fef2f2; }
    .order-pos { width: 26px; height: 26px; border-radius: 50%; background: #6366f1; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; }
    .order-text { font-size: 14px; }

    /* File Upload */
    .file-upload-preview { border: 2px dashed #e2e8f0; border-radius: 10px; padding: 30px; text-align: center; }
    .file-upload-preview p { margin: 8px 0 4px; color: #475569; }

    /* Hotspot */
    .hs-preview { position: relative; display: inline-block; cursor: crosshair; }
    .hs-preview .hs-img { max-width: 100%; border-radius: 8px; }
    .hs-overlay { position: absolute; border: 2px solid transparent; }
    .hs-overlay.show-correct { border-color: #22c55e; background: rgba(34,197,94,0.2); }
    .hs-click-marker { position: absolute; width: 20px; height: 20px; border-radius: 50%; border: 3px solid #6366f1; background: rgba(99,102,241,0.3); }
    .hs-click-marker.correct { border-color: #22c55e; background: rgba(34,197,94,0.3); }
    .hs-click-marker.incorrect { border-color: #ef4444; background: rgba(239,68,68,0.3); }

    /* Submit */
    .submit-area { display: flex; gap: 10px; margin-top: 8px; }

    /* Result */
    .result-card { padding: 14px 18px; border-radius: 10px; margin-top: 16px; }
    .result-card.correct { background: #f0fdf4; border: 1px solid #86efac; }
    .result-card.incorrect { background: #fef2f2; border: 1px solid #fca5a5; }
    .result-header { display: flex; align-items: center; gap: 10px; font-size: 16px; font-weight: 600; }
    .result-card.correct .result-header { color: #15803d; }
    .result-card.incorrect .result-header { color: #dc2626; }
    .result-score { margin-left: auto; font-size: 14px; }
    .result-feedback { margin-top: 8px; font-size: 14px; color: #475569; }

    .explanation-card { padding: 14px 18px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px; margin-top: 12px; }
    .explanation-card h4 { font-size: 14px; font-weight: 600; color: #92400e; margin: 0 0 6px; display: flex; align-items: center; gap: 6px; }

    /* Review */
    .review-section { margin-bottom: 20px; }
    .review-section h4 { font-size: 14px; font-weight: 600; color: #475569; margin: 0 0 10px; text-transform: uppercase; letter-spacing: 0.05em; }
    .review-option { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 6px; }
    .review-option.correct { border-color: #86efac; background: #f0fdf4; }
    .review-fb { font-size: 12px; color: #64748b; font-style: italic; margin-left: auto; }
    .review-answer { font-size: 16px; font-weight: 600; color: #0f172a; padding: 10px 14px; background: #f0fdf4; border-radius: 8px; border: 1px solid #86efac; }
    .review-chips { display: flex; gap: 6px; flex-wrap: wrap; }
    .review-chip { padding: 4px 12px; background: #f0fdf4; border: 1px solid #86efac; border-radius: 20px; font-size: 14px; color: #065f46; }
    .review-blank { margin-bottom: 6px; font-size: 14px; }
    .case-badge { font-size: 11px; background: #fef3c7; color: #92400e; padding: 1px 6px; border-radius: 3px; margin-left: 6px; }
    .review-match { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; font-size: 14px; padding: 8px 14px; background: #f0fdf4; border-radius: 8px; }
    .review-order { display: flex; align-items: center; gap: 10px; margin-bottom: 6px; font-size: 14px; }
  `]
})
export class QuestionPreviewComponent implements OnChanges {
  @Input() question!: PreviewQuestion;
  @Input() closable = true;
  @Input() initialMode: 'answer'|'review' = 'answer';
  @Output() onClose = new EventEmitter<void>();

  mode: 'answer'|'review' = 'answer';
  submitted = false;
  showHint = false;

  // Answer state
  selectedOptions: boolean[] = [];
  tfAnswer: boolean|null = null;
  textAnswer = '';
  numericAnswer: number|null = null;
  blankAnswers: string[] = [];
  matchAnswers: string[] = [];
  orderAnswer: {text: string; origIdx: number}[] = [];
  shuffledResponses: string[] = [];
  hotspotClick: {x: number; y: number}|null = null;
  orderDragIdx = -1;

  typeLabels: Record<string, string> = {
    multiple_choice: 'Multiple Choice', multiple_response: 'Multiple Response', true_false: 'True / False',
    short_answer: 'Short Answer', long_answer: 'Essay', numeric: 'Numeric', fill_blank: 'Fill in the Blank',
    matching: 'Matching', ordering: 'Ordering', file_upload: 'File Upload', hotspot: 'Hotspot'
  };

  get typeLabel(): string { return this.typeLabels[this.question?.question_type] || ''; }
  get wordCount(): number { return this.textAnswer.trim() ? this.textAnswer.trim().split(/\s+/).length : 0; }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['question'] || changes['initialMode']) {
      this.mode = this.initialMode;
      this.resetAnswer();
    }
  }

  resetAnswer(): void {
    this.submitted = false;
    this.showHint = false;
    this.selectedOptions = (this.question?.options || []).map(() => false);
    this.tfAnswer = null;
    this.textAnswer = '';
    this.numericAnswer = null;
    this.blankAnswers = (this.question?.fill_blanks || []).map(() => '');
    this.matchAnswers = (this.question?.match_pairs || []).map(() => '');
    this.hotspotClick = null;

    // Shuffle responses for matching
    if (this.question?.match_pairs) {
      this.shuffledResponses = [...this.question.match_pairs.map(p => p.response)].sort(() => Math.random() - 0.5);
    }
    // Shuffle order items
    if (this.question?.order_items) {
      this.orderAnswer = this.question.order_items.map((it, idx) => ({ text: it.item_text, origIdx: idx }))
        .sort(() => Math.random() - 0.5);
    }
  }

  // Selection
  selectMC(i: number): void { this.selectedOptions = this.selectedOptions.map((_, idx) => idx === i); }
  toggleMR(i: number): void { this.selectedOptions[i] = !this.selectedOptions[i]; }

  // Reorder
  reorderItem(targetIdx: number): void {
    if (this.orderDragIdx < 0 || this.orderDragIdx === targetIdx) return;
    const item = this.orderAnswer.splice(this.orderDragIdx, 1)[0];
    this.orderAnswer.splice(targetIdx, 0, item);
    this.orderDragIdx = -1;
  }

  // Hotspot click
  onHotspotClick(e: MouseEvent): void {
    const container = (e.target as HTMLElement).closest('.hs-preview');
    if (!container) return;
    const rect = container.getBoundingClientRect();
    this.hotspotClick = { x: e.clientX - rect.left, y: e.clientY - rect.top };
  }

  // Can submit
  canSubmit(): boolean {
    const t = this.question?.question_type;
    if (t === 'multiple_choice' || t === 'multiple_response') return this.selectedOptions.some(s => s);
    if (t === 'true_false') return this.tfAnswer !== null;
    if (t === 'short_answer') return !!this.textAnswer.trim();
    if (t === 'long_answer') return !!this.textAnswer.trim();
    if (t === 'numeric') return this.numericAnswer !== null;
    if (t === 'fill_blank') return this.blankAnswers.some(a => a.trim());
    if (t === 'matching') return this.matchAnswers.some(a => a);
    if (t === 'ordering') return true;
    if (t === 'hotspot') return !!this.hotspotClick;
    return false;
  }

  submit(): void { this.submitted = true; }

  // Correctness checks
  isCorrectShort(): boolean {
    return (this.question.options || []).some(o =>
      o.option_text.toLowerCase().trim() === this.textAnswer.toLowerCase().trim()
    );
  }

  isCorrectNumeric(): boolean {
    if (this.numericAnswer === null || this.question.numeric_answer === undefined) return false;
    return Math.abs(this.numericAnswer - (this.question.numeric_answer || 0)) <= (this.question.numeric_tolerance || 0);
  }

  isCorrectBlank(i: number): boolean {
    const blank = this.question.fill_blanks?.[i];
    if (!blank) return false;
    const ans = this.blankAnswers[i]?.trim() || '';
    return blank.acceptable_answers.some(a => blank.case_sensitive ? a.trim() === ans : a.trim().toLowerCase() === ans.toLowerCase());
  }

  isCorrectHotspot(): boolean {
    if (!this.hotspotClick) return false;
    return (this.question.hotspot_areas || []).some(a => a.is_correct &&
      this.hotspotClick!.x >= a.x && this.hotspotClick!.x <= a.x + a.width &&
      this.hotspotClick!.y >= a.y && this.hotspotClick!.y <= a.y + a.height
    );
  }

  isOverallCorrect(): boolean {
    const t = this.question.question_type;
    if (t === 'multiple_choice') {
      return this.selectedOptions.every((sel, i) => sel === (this.question.options?.[i]?.is_correct || false));
    }
    if (t === 'multiple_response') {
      return this.selectedOptions.every((sel, i) => sel === (this.question.options?.[i]?.is_correct || false));
    }
    if (t === 'true_false') {
      return this.tfAnswer === this.question.options?.[0]?.is_correct;
    }
    if (t === 'short_answer') return this.isCorrectShort();
    if (t === 'numeric') return this.isCorrectNumeric();
    if (t === 'fill_blank') return this.blankAnswers.every((_, i) => this.isCorrectBlank(i));
    if (t === 'matching') return this.matchAnswers.every((a, i) => a === this.question.match_pairs?.[i]?.response);
    if (t === 'ordering') return this.orderAnswer.every((item, i) => item.text === this.question.order_items?.[i]?.item_text);
    if (t === 'hotspot') return this.isCorrectHotspot();
    return false;
  }

  getScore(): number {
    if (this.isOverallCorrect()) return this.question.points;
    const t = this.question.question_type;
    // Partial scoring for multiple response
    if (t === 'multiple_response') {
      const opts = this.question.options || [];
      const correctCount = opts.filter((o, i) => this.selectedOptions[i] === o.is_correct).length;
      return Math.round((correctCount / opts.length) * this.question.points * 10) / 10;
    }
    // Partial scoring for fill blank
    if (t === 'fill_blank') {
      const blanks = this.question.fill_blanks || [];
      const correctCount = blanks.filter((_, i) => this.isCorrectBlank(i)).length;
      return Math.round((correctCount / blanks.length) * this.question.points * 10) / 10;
    }
    return 0;
  }

  getFeedback(): string {
    const t = this.question.question_type;
    if (t === 'multiple_choice' || t === 'multiple_response') {
      const selIdx = this.selectedOptions.findIndex(s => s);
      if (selIdx >= 0 && this.question.options?.[selIdx]?.feedback) return this.question.options[selIdx].feedback;
    }
    if (t === 'true_false') {
      const correct = this.question.options?.[0]?.is_correct;
      if (this.tfAnswer === correct) return this.question.options?.[0]?.feedback || '';
      return this.question.options?.[1]?.feedback || '';
    }
    return '';
  }

  getMarker(i: number): string { return String.fromCharCode(65 + i); }

  getAcceptedOptions(): string {
    return this.question?.options
      ?.map(o => o.option_text)
      .join(', ') || '';
  }
}
