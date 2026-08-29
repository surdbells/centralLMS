// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 13
// Learner: Question Renderer Component
// Multi-Tenant LMS | Angular Standalone
// Renders all 11 question types for exam-taking
// =============================================

import { Component, Input, Output, EventEmitter, OnChanges, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { TuiIcon } from '@taiga-ui/core';

interface QuestionOption { id: string; option_text: string; order_position: number; image_url?: string; }
interface MatchPair { id: string; premise: string; response: string; }
interface OrderItem { id: string; item_text: string; }
interface FillBlank { blank_index: number; placeholder?: string; }

export interface PlayerQuestion {
  question_id: string; question_text: string; question_type: string;
  points: number; image_url?: string; hint?: string;
  time_limit_seconds?: number; required: boolean;
  options?: QuestionOption[]; match_pairs?: MatchPair[];
  order_items?: OrderItem[]; fill_blanks?: FillBlank[];
  hotspot_image_url?: string;
  numeric_unit?: string;
  allowed_extensions?: string; max_file_size_mb?: number;
  max_length?: number;
}

export interface QuestionAnswer {
  question_id: string;
  answer_type: string;
  selected_option_ids?: string[];
  text_answer?: string;
  numeric_answer?: number|null;
  blank_answers?: string[];
  match_answers?: {premise_id: string; response_id: string}[];
  order_answer?: string[];
  file_url?: string;
  hotspot_click?: {x: number; y: number}|null;
  is_flagged?: boolean;
  time_spent_seconds?: number;
}

@Component({
  selector: 'app-question-renderer',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon],
  template: `
    <div class="qr-container" *ngIf="question">
      <!-- Question Header -->
      <div class="qr-header">
        <div class="qr-points">{{ question.points }} pt{{ question.points > 1 ? 's' : '' }}</div>
        <button class="flag-btn" [class.flagged]="answer.is_flagged" (click)="toggleFlag()" title="Flag for review">
          <tui-icon [icon]="answer.is_flagged ? '@tui.flag' : '@tui.flag'" style="font-size:18px"></tui-icon>
        </button>
      </div>

      <!-- Question Text -->
      <div class="qr-body">
        <div class="q-text" [innerHTML]="question.question_text"></div>
        <img *ngIf="question.image_url" [src]="question.image_url" class="q-img" />
      </div>

      <!-- Hint -->
      <button class="hint-toggle" *ngIf="question.hint && !showHint" (click)="showHint=true">
        <tui-icon icon="@tui.lightbulb" style="font-size:14px"></tui-icon> Show Hint
      </button>
      <div class="hint-box" *ngIf="question.hint && showHint">
        <tui-icon icon="@tui.lightbulb" style="font-size:16px;color:#f59e0b"></tui-icon> {{ question.hint }}
      </div>

      <!-- =============== QUESTION TYPES =============== -->

      <!-- MULTIPLE CHOICE -->
      <div class="answer-area" *ngIf="question.question_type==='multiple_choice'">
        <div *ngFor="let o of question.options" class="mc-opt" [class.selected]="isSelected(o.id)"
             (click)="selectSingle(o.id)">
          <span class="mc-radio" [class.on]="isSelected(o.id)"></span>
          <span class="mc-label" [innerHTML]="o.option_text"></span>
        </div>
      </div>

      <!-- MULTIPLE RESPONSE -->
      <div class="answer-area" *ngIf="question.question_type==='multiple_response'">
        <div *ngFor="let o of question.options" class="mc-opt" [class.selected]="isSelected(o.id)"
             (click)="toggleOption(o.id)">
          <span class="mc-check" [class.on]="isSelected(o.id)">
            <tui-icon *ngIf="isSelected(o.id)" icon="@tui.check" style="font-size:12px;color:#fff"></tui-icon>
          </span>
          <span class="mc-label" [innerHTML]="o.option_text"></span>
        </div>
      </div>

      <!-- TRUE/FALSE -->
      <div class="answer-area tf-area" *ngIf="question.question_type==='true_false'">
        <button *ngFor="let o of question.options" class="tf-btn" [class.selected]="isSelected(o.id)"
                (click)="selectSingle(o.id)">
          <tui-icon [icon]="o.option_text==='True' ? '@tui.check-circle' : '@tui.x-circle'" style="font-size:22px"></tui-icon>
          {{ o.option_text }}
        </button>
      </div>

      <!-- SHORT ANSWER -->
      <div class="answer-area" *ngIf="question.question_type==='short_answer'">
        <input type="text" class="text-input" [(ngModel)]="answer.text_answer"
               (ngModelChange)="emitChange()" placeholder="Type your answer..." />
      </div>

      <!-- LONG ANSWER / ESSAY -->
      <div class="answer-area" *ngIf="question.question_type==='long_answer'">
        <textarea class="text-area" [(ngModel)]="answer.text_answer" (ngModelChange)="emitChange()"
                  placeholder="Write your answer..." rows="8"></textarea>
        <div class="word-info">
          <span>{{ wordCount }} words</span>
          <span *ngIf="question.max_length">/ {{ question.max_length }} max</span>
        </div>
      </div>

      <!-- NUMERIC -->
      <div class="answer-area" *ngIf="question.question_type==='numeric'">
        <div class="numeric-row">
          <input type="number" class="text-input numeric-input" [(ngModel)]="answer.numeric_answer"
                 (ngModelChange)="emitChange()" placeholder="Enter a number..." step="any" />
          <span class="unit-label" *ngIf="question.numeric_unit">{{ question.numeric_unit }}</span>
        </div>
      </div>

      <!-- FILL IN THE BLANK -->
      <div class="answer-area" *ngIf="question.question_type==='fill_blank'">
        <div *ngFor="let b of question.fill_blanks; let i=index" class="blank-row">
          <span class="blank-label">Blank {{ i+1 }}:</span>
          <input type="text" class="text-input blank-input" [(ngModel)]="blankAnswers[i]"
                 (ngModelChange)="syncBlanks()" [placeholder]="b.placeholder || 'Your answer...'" />
        </div>
      </div>

      <!-- MATCHING -->
      <div class="answer-area" *ngIf="question.question_type==='matching'">
        <div class="match-grid">
          <div class="match-hdr"><span>Premise</span><span></span><span>Your Match</span></div>
          <div *ngFor="let p of question.match_pairs; let i=index" class="match-row">
            <span class="match-premise">{{ p.premise }}</span>
            <tui-icon icon="@tui.arrow-right" style="font-size:14px;color:#94a3b8"></tui-icon>
            <select class="match-select" [(ngModel)]="matchSelections[i]" (ngModelChange)="syncMatches()">
              <option [ngValue]="null">-- Select --</option>
              <option *ngFor="let r of shuffledResponses" [ngValue]="r.id">{{ r.text }}</option>
            </select>
          </div>
        </div>
      </div>

      <!-- ORDERING -->
      <div class="answer-area" *ngIf="question.question_type==='ordering'">
        <p class="order-hint">Drag items to arrange in the correct order:</p>
        <div class="order-list">
          <div *ngFor="let item of orderItems; let i=index" class="order-item"
               draggable="true" (dragstart)="orderDragIdx=i" (dragover)="$event.preventDefault()"
               (drop)="reorder(i)">
            <span class="order-num">{{ i+1 }}</span>
            <tui-icon icon="@tui.grip-vertical" style="font-size:14px;color:#94a3b8;cursor:grab"></tui-icon>
            <span class="order-text">{{ item.text }}</span>
            <div class="order-arrows">
              <button class="arr-btn" (click)="moveItem(i,-1)" [disabled]="i===0">
                <tui-icon icon="@tui.chevron-up" style="font-size:14px"></tui-icon></button>
              <button class="arr-btn" (click)="moveItem(i,1)" [disabled]="i===orderItems.length-1">
                <tui-icon icon="@tui.chevron-down" style="font-size:14px"></tui-icon></button>
            </div>
          </div>
        </div>
      </div>

      <!-- FILE UPLOAD -->
      <div class="answer-area" *ngIf="question.question_type==='file_upload'">
        <div class="file-zone" *ngIf="!uploadedFileName" (click)="fileInput.click()">
          <tui-icon icon="@tui.upload-cloud" style="font-size:36px;color:#94a3b8"></tui-icon>
          <p>Click to upload or drag file here</p>
          <span class="hint">Accepted: {{ question.allowed_extensions || 'Any' }} · Max {{ question.max_file_size_mb || 10 }}MB</span>
          <input type="file" #fileInput hidden [accept]="getAcceptStr()" (change)="onFileSelect($event)" />
        </div>
        <div class="file-uploaded" *ngIf="uploadedFileName">
          <tui-icon icon="@tui.file" style="font-size:20px;color:#6366f1"></tui-icon>
          <div class="file-det"><span class="fname">{{ uploadedFileName }}</span><span class="fsize">{{ uploadedFileSize }}</span></div>
          <button class="btn-icon" (click)="removeFile()"><tui-icon icon="@tui.x" style="font-size:14px"></tui-icon></button>
        </div>
        <div class="upload-progress" *ngIf="uploading">
          <div class="progress-bar"><div class="progress-fill" [style.width.%]="uploadProgress"></div></div>
          <span>Uploading... {{ uploadProgress }}%</span>
        </div>
      </div>

      <!-- HOTSPOT -->
      <div class="answer-area" *ngIf="question.question_type==='hotspot'">
        <p class="hs-hint">Click on the correct area of the image:</p>
        <div class="hs-container" (click)="onHotspotClick($event)" #hsContainer>
          <img *ngIf="question.hotspot_image_url" [src]="question.hotspot_image_url" class="hs-image" />
          <div class="hs-marker" *ngIf="answer.hotspot_click"
               [style.left.px]="answer.hotspot_click.x - 12" [style.top.px]="answer.hotspot_click.y - 12">
            <tui-icon icon="@tui.crosshair" style="font-size:24px;color:#6366f1"></tui-icon>
          </div>
        </div>
        <button class="btn btn-sm btn-outline" *ngIf="answer.hotspot_click" (click)="answer.hotspot_click=null;emitChange()">
          <tui-icon icon="@tui.x"></tui-icon> Clear Selection
        </button>
      </div>
    </div>
  `,
  styles: [`
    :host { display: block; }
    .qr-container { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
    .qr-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
    .qr-points { font-size: 13px; font-weight: 600; color: #6366f1; background: #eef2ff; padding: 3px 10px; border-radius: 20px; }
    .flag-btn { background: none; border: none; cursor: pointer; color: #94a3b8; padding: 4px; border-radius: 6px; }
    .flag-btn:hover { color: #f59e0b; background: #fffbeb; }
    .flag-btn.flagged { color: #f59e0b; }

    .qr-body { margin-bottom: 20px; }
    .q-text { font-size: 17px; line-height: 1.7; color: #0f172a; }
    .q-img { max-width: 100%; border-radius: 8px; margin-top: 12px; border: 1px solid #e2e8f0; }

    .hint-toggle { background: none; border: none; color: #f59e0b; font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 4px; margin-bottom: 12px; }
    .hint-box { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; margin-bottom: 16px; font-size: 14px; color: #92400e; }
    .hint { font-size: 12px; color: #94a3b8; }

    .answer-area { margin-bottom: 8px; }

    /* MC / MR */
    .mc-opt { display: flex; align-items: center; gap: 14px; padding: 14px 18px; border: 2px solid #e2e8f0; border-radius: 12px; margin-bottom: 8px; cursor: pointer; transition: all 0.15s; }
    .mc-opt:hover { border-color: #a5b4fc; background: #fafaff; }
    .mc-opt.selected { border-color: #6366f1; background: #eef2ff; }
    .mc-radio { width: 22px; height: 22px; border-radius: 50%; border: 2px solid #d1d5db; flex-shrink: 0; transition: all 0.15s; }
    .mc-radio.on { border-color: #6366f1; background: #6366f1; box-shadow: inset 0 0 0 4px #fff; }
    .mc-check { width: 22px; height: 22px; border-radius: 6px; border: 2px solid #d1d5db; flex-shrink: 0; display: flex; align-items: center; justify-content: center; transition: all 0.15s; }
    .mc-check.on { border-color: #6366f1; background: #6366f1; }
    .mc-label { flex: 1; font-size: 15px; color: #1e293b; line-height: 1.5; }

    /* TF */
    .tf-area { display: flex; gap: 12px; }
    .tf-btn { display: flex; align-items: center; gap: 10px; padding: 18px 24px; border: 2px solid #e2e8f0; border-radius: 14px; background: #fff; cursor: pointer; font-size: 18px; font-weight: 600; flex: 1; justify-content: center; transition: all 0.15s; color: #334155; }
    .tf-btn:hover { border-color: #a5b4fc; }
    .tf-btn.selected { border-color: #6366f1; background: #eef2ff; color: #4f46e5; }

    /* Text inputs */
    .text-input { width: 100%; padding: 12px 16px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 16px; box-sizing: border-box; transition: border-color 0.15s; }
    .text-input:focus { border-color: #6366f1; outline: none; box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
    .text-area { width: 100%; padding: 14px 16px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 15px; line-height: 1.6; resize: vertical; font-family: inherit; box-sizing: border-box; }
    .text-area:focus { border-color: #6366f1; outline: none; }
    .word-info { display: flex; gap: 4px; justify-content: flex-end; font-size: 12px; color: #94a3b8; margin-top: 4px; }

    /* Numeric */
    .numeric-row { display: flex; align-items: center; gap: 10px; }
    .numeric-input { max-width: 250px; }
    .unit-label { font-size: 16px; font-weight: 500; color: #475569; }

    /* Fill blank */
    .blank-row { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
    .blank-label { font-size: 14px; font-weight: 600; color: #475569; min-width: 70px; }
    .blank-input { flex: 1; }

    /* Matching */
    .match-grid { display: flex; flex-direction: column; gap: 8px; }
    .match-hdr { display: grid; grid-template-columns: 1fr 24px 1fr; gap: 10px; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; padding: 0 4px; }
    .match-row { display: grid; grid-template-columns: 1fr 24px 1fr; gap: 10px; align-items: center; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; }
    .match-premise { font-size: 14px; font-weight: 500; color: #0f172a; }
    .match-select { width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; }
    .match-select:focus { border-color: #6366f1; outline: none; }

    /* Ordering */
    .order-hint { font-size: 13px; color: #64748b; margin: 0 0 10px; }
    .order-list { display: flex; flex-direction: column; gap: 6px; }
    .order-item { display: flex; align-items: center; gap: 10px; padding: 12px 14px; border: 2px solid #e2e8f0; border-radius: 10px; background: #fff; cursor: grab; transition: all 0.15s; }
    .order-item:hover { border-color: #a5b4fc; }
    .order-num { width: 28px; height: 28px; border-radius: 50%; background: #6366f1; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0; }
    .order-text { flex: 1; font-size: 15px; color: #1e293b; }
    .order-arrows { display: flex; flex-direction: column; gap: 2px; }
    .arr-btn { background: none; border: 1px solid #e2e8f0; border-radius: 4px; cursor: pointer; padding: 1px; color: #64748b; }
    .arr-btn:hover:not(:disabled) { border-color: #6366f1; color: #6366f1; }
    .arr-btn:disabled { opacity: 0.3; cursor: not-allowed; }

    /* File Upload */
    .file-zone { border: 2px dashed #e2e8f0; border-radius: 12px; padding: 30px; text-align: center; cursor: pointer; transition: all 0.15s; }
    .file-zone:hover { border-color: #6366f1; background: #f5f3ff; }
    .file-zone p { margin: 8px 0 4px; color: #475569; font-size: 14px; }
    .file-uploaded { display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; }
    .file-det { display: flex; flex-direction: column; flex: 1; }
    .fname { font-weight: 600; color: #0f172a; font-size: 14px; }
    .fsize { font-size: 12px; color: #94a3b8; }
    .btn-icon { background: none; border: none; cursor: pointer; padding: 4px; border-radius: 6px; color: #64748b; }
    .upload-progress { margin-top: 8px; display: flex; align-items: center; gap: 10px; font-size: 13px; color: #64748b; }
    .progress-bar { flex: 1; height: 6px; background: #e2e8f0; border-radius: 3px; overflow: hidden; }
    .progress-fill { height: 100%; background: #6366f1; border-radius: 3px; transition: width 0.3s; }

    /* Hotspot */
    .hs-hint { font-size: 13px; color: #64748b; margin: 0 0 10px; }
    .hs-container { position: relative; display: inline-block; cursor: crosshair; }
    .hs-image { max-width: 100%; border-radius: 10px; border: 2px solid #e2e8f0; }
    .hs-marker { position: absolute; pointer-events: none; }
    .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 500; border: none; cursor: pointer; }
    .btn-sm { padding: 5px 10px; font-size: 13px; }
    .btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; }

    @media (max-width: 600px) {
      .tf-area { flex-direction: column; }
      .match-row { grid-template-columns: 1fr; gap: 6px; } .match-hdr { display: none; }
    }
  `]
})
export class QuestionRendererComponent implements OnChanges {
  @Input() question!: PlayerQuestion;
  @Input() savedAnswer?: QuestionAnswer;
  @Output() answerChange = new EventEmitter<QuestionAnswer>();

  answer: QuestionAnswer = this.blankAnswer('');
  showHint = false;

  // Ordering
  orderItems: {id: string; text: string}[] = [];
  orderDragIdx = -1;

  // Matching
  matchSelections: (string|null)[] = [];
  shuffledResponses: {id: string; text: string}[] = [];

  // Fill blank
  blankAnswers: string[] = [];

  // File upload
  uploadedFileName = '';
  uploadedFileSize = '';
  uploading = false;
  uploadProgress = 0;

  get wordCount(): number { return (this.answer.text_answer || '').trim() ? (this.answer.text_answer || '').trim().split(/\s+/).length : 0; }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['question'] || changes['savedAnswer']) this.initAnswer();
  }

  initAnswer(): void {
    this.showHint = false;
    if (this.savedAnswer) {
      this.answer = { ...this.savedAnswer };
    } else {
      this.answer = this.blankAnswer(this.question?.question_id || '');
    }
    this.initTypeSpecific();
  }

  private initTypeSpecific(): void {
    const q = this.question;
    if (!q) return;

    // Ordering: shuffle items
    if (q.question_type === 'ordering' && q.order_items) {
      if (this.answer.order_answer?.length) {
        this.orderItems = this.answer.order_answer.map(id => {
          const item = q.order_items!.find(o => o.id === id);
          return { id, text: item?.item_text || '' };
        });
      } else {
        this.orderItems = [...q.order_items].map(o => ({ id: o.id, text: o.item_text })).sort(() => Math.random() - 0.5);
        this.syncOrder();
      }
    }

    // Matching: shuffle responses
    if (q.question_type === 'matching' && q.match_pairs) {
      this.shuffledResponses = q.match_pairs.map(p => ({ id: p.id, text: p.response })).sort(() => Math.random() - 0.5);
      if (this.answer.match_answers?.length) {
        this.matchSelections = q.match_pairs.map(p => {
          const saved = this.answer.match_answers!.find(m => m.premise_id === p.id);
          return saved ? saved.response_id : null;
        });
      } else {
        this.matchSelections = q.match_pairs.map(() => null);
      }
    }

    // Fill blank
    if (q.question_type === 'fill_blank') {
      this.blankAnswers = this.answer.blank_answers?.length ? [...this.answer.blank_answers] : (q.fill_blanks || []).map(() => '');
    }

    // File upload restore
    if (q.question_type === 'file_upload' && this.answer.file_url) {
      this.uploadedFileName = this.answer.file_url.split('/').pop() || 'Uploaded file';
      this.uploadedFileSize = '';
    }
  }

  blankAnswer(qId: string): QuestionAnswer {
    return {
      question_id: qId, answer_type: this.question?.question_type || '',
      selected_option_ids: [], text_answer: '', numeric_answer: null,
      blank_answers: [], match_answers: [], order_answer: [],
      file_url: '', hotspot_click: null, is_flagged: false, time_spent_seconds: 0
    };
  }

  // === Selection handlers ===
  isSelected(optId: string): boolean { return this.answer.selected_option_ids?.includes(optId) || false; }

  selectSingle(optId: string): void {
    this.answer.selected_option_ids = [optId];
    this.emitChange();
  }

  toggleOption(optId: string): void {
    const ids = this.answer.selected_option_ids || [];
    const idx = ids.indexOf(optId);
    if (idx >= 0) ids.splice(idx, 1); else ids.push(optId);
    this.answer.selected_option_ids = [...ids];
    this.emitChange();
  }

  toggleFlag(): void {
    this.answer.is_flagged = !this.answer.is_flagged;
    this.emitChange();
  }

  // === Matching sync ===
  syncMatches(): void {
    this.answer.match_answers = (this.question.match_pairs || []).map((p, i) => ({
      premise_id: p.id, response_id: this.matchSelections[i] || ''
    })).filter(m => m.response_id);
    this.emitChange();
  }

  // === Ordering ===
  reorder(targetIdx: number): void {
    if (this.orderDragIdx < 0 || this.orderDragIdx === targetIdx) return;
    const item = this.orderItems.splice(this.orderDragIdx, 1)[0];
    this.orderItems.splice(targetIdx, 0, item);
    this.orderDragIdx = -1;
    this.syncOrder();
  }

  moveItem(idx: number, dir: number): void {
    const target = idx + dir;
    if (target < 0 || target >= this.orderItems.length) return;
    [this.orderItems[idx], this.orderItems[target]] = [this.orderItems[target], this.orderItems[idx]];
    this.syncOrder();
  }

  syncOrder(): void {
    this.answer.order_answer = this.orderItems.map(i => i.id);
    this.emitChange();
  }

  // === Fill blank sync ===
  syncBlanks(): void {
    this.answer.blank_answers = [...this.blankAnswers];
    this.emitChange();
  }

  // === File upload ===
  getAcceptStr(): string {
    if (!this.question?.allowed_extensions) return '*';
    return this.question.allowed_extensions.split(',').map(e => '.' + e.trim()).join(',');
  }

  onFileSelect(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    const maxMb = this.question?.max_file_size_mb || 10;
    if (file.size > maxMb * 1024 * 1024) { alert(`File too large. Max ${maxMb}MB.`); return; }
    this.uploadedFileName = file.name;
    this.uploadedFileSize = this.formatSize(file.size);
    this.uploading = true; this.uploadProgress = 0;
    // Simulate upload progress
    const iv = setInterval(() => {
      this.uploadProgress += 20;
      if (this.uploadProgress >= 100) { clearInterval(iv); this.uploading = false; this.answer.file_url = 'uploaded/' + file.name; this.emitChange(); }
    }, 200);
  }

  removeFile(): void {
    this.uploadedFileName = ''; this.uploadedFileSize = '';
    this.answer.file_url = ''; this.emitChange();
  }

  formatSize(bytes: number): string {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes/1024).toFixed(1) + ' KB';
    return (bytes/1048576).toFixed(1) + ' MB';
  }

  // === Hotspot ===
  onHotspotClick(e: MouseEvent): void {
    const container = (e.target as HTMLElement).closest('.hs-container');
    if (!container) return;
    const rect = container.getBoundingClientRect();
    this.answer.hotspot_click = { x: e.clientX - rect.left, y: e.clientY - rect.top };
    this.emitChange();
  }

  emitChange(): void { this.answerChange.emit({ ...this.answer }); }
}
