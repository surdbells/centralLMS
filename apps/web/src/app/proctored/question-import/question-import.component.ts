// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 9
// Admin: Question Bank - Question Import Component
// Multi-Tenant LMS | Angular Standalone
// 4-step CSV/Excel import wizard
// =============================================

import { Component, OnDestroy, inject, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { Subject, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { GlobalComponent } from '../../global-component';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';

interface ApiResponse<T> { code: number; status: string; data: T; message: string; }
interface QuestionBank { id: number; name: string; }
interface ColumnMapping { source: string; target: string; }
interface ParsedRow { [key: string]: string; }
interface ValidationResult { row: number; valid: boolean; errors: string[]; data: ParsedRow; }
interface ImportStats { total: number; valid: number; invalid: number; imported: number; skipped: number; }

@Component({
  selector: 'app-question-import',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>

    <div class="import-overlay" (click)="close()">
      <div class="import-modal" (click)="$event.stopPropagation()">
        <div class="import-header">
          <h2><tui-icon icon="@tui.upload"></tui-icon> Import Questions</h2>
          <button class="btn-close" (click)="close()"><tui-icon icon="@tui.x"></tui-icon></button>
        </div>

        <div class="steps-bar">
          <div *ngFor="let s of steps; let i=index" class="step" [class.active]="currentStep===i"
               [class.completed]="currentStep > i" (click)="currentStep > i && (currentStep=i)">
            <span class="step-num">{{ currentStep > i ? '✓' : i+1 }}</span>
            <span class="step-label">{{ s }}</span>
          </div>
        </div>

        <!-- Step 1: Upload -->
        <div class="step-body" *ngIf="currentStep===0">
          <div class="upload-zone" (click)="fileInput.click()" [class.drag-over]="dragOver"
               (dragover)="$event.preventDefault();dragOver=true" (dragleave)="dragOver=false"
               (drop)="$event.preventDefault();dragOver=false;onFileDrop($event)">
            <tui-icon icon="@tui.upload-cloud" style="font-size:48px;color:#94a3b8"></tui-icon>
            <h3>Drop your file here</h3>
            <p>or click to browse</p>
            <span class="hint">CSV, Excel (.xlsx, .xls)</span>
            <input type="file" #fileInput hidden accept=".csv,.xlsx,.xls" (change)="onFileSelect($event)" />
          </div>
          <div class="file-info" *ngIf="selectedFile">
            <tui-icon icon="@tui.file-text" style="font-size:20px;color:#6366f1"></tui-icon>
            <div class="file-det"><span class="fname">{{ selectedFile.name }}</span><span class="fsize">{{ formatSize(selectedFile.size) }}</span></div>
            <button class="btn-icon" (click)="selectedFile=null;parsedData=[]"><tui-icon icon="@tui.x" style="font-size:14px"></tui-icon></button>
          </div>
          <div class="tpl-section">
            <h4>Need a template?</h4>
            <div class="tpl-btns">
              <button class="btn btn-sm btn-outline" (click)="downloadTemplate('csv')"><tui-icon icon="@tui.download" style="font-size:14px"></tui-icon> CSV</button>
              <button class="btn btn-sm btn-outline" (click)="downloadTemplate('xlsx')"><tui-icon icon="@tui.download" style="font-size:14px"></tui-icon> Excel</button>
            </div>
            <p class="tpl-info">Required: <strong>question_text, question_type, difficulty, points</strong><br/>
              Optional: options (pipe-separated), correct_answer, explanation, tags, hint</p>
          </div>
        </div>

        <!-- Step 2: Mapping -->
        <div class="step-body" *ngIf="currentStep===1">
          <h3>Map Columns</h3><p class="desc">Match your file columns to question fields.</p>
          <div class="map-grid">
            <div class="map-row map-hdr"><span>Your Column</span><span></span><span>Maps To</span></div>
            <div *ngFor="let col of sourceColumns; let i=index" class="map-row">
              <span class="src-col">{{ col }}</span>
              <tui-icon icon="@tui.arrow-right" style="font-size:14px;color:#94a3b8"></tui-icon>
              <select class="fc" [(ngModel)]="columnMappings[i].target">
                <option value="">-- Skip --</option>
                <option *ngFor="let f of targetFields" [value]="f.value" [disabled]="isFieldMapped(f.value,i)">{{ f.label }}</option>
              </select>
            </div>
          </div>
          <div class="auto-msg" *ngIf="autoDetectedCount>0">
            <tui-icon icon="@tui.check-circle" style="font-size:16px;color:#22c55e"></tui-icon> {{ autoDetectedCount }} auto-detected
          </div>
          <div class="map-warn" *ngIf="!hasRequiredMappings()">
            <tui-icon icon="@tui.alert-triangle" style="font-size:16px;color:#f59e0b"></tui-icon> Required: question_text, question_type
          </div>
        </div>

        <!-- Step 3: Validate -->
        <div class="step-body" *ngIf="currentStep===2">
          <div class="val-hdr">
            <h3>Preview & Validate</h3>
            <div class="val-stats">
              <span class="vs ok"><tui-icon icon="@tui.check-circle" style="font-size:14px"></tui-icon> {{ validCount }}</span>
              <span class="vs err" *ngIf="invalidCount>0"><tui-icon icon="@tui.alert-circle" style="font-size:14px"></tui-icon> {{ invalidCount }}</span>
              <span class="vs tot">{{ validationResults.length }} total</span>
            </div>
          </div>
          <div class="import-settings">
            <div class="fg"><label class="fl">Target Bank *</label>
              <select class="fc" [(ngModel)]="targetBankId"><option value="">-- Select --</option>
                <option *ngFor="let b of banks" [value]="b.id">{{ b.name }}</option></select></div>
            <div class="fg"><label class="fl">Default Difficulty</label>
              <select class="fc" [(ngModel)]="defaultDifficulty"><option value="easy">Easy</option><option value="medium">Medium</option><option value="hard">Hard</option></select></div>
            <div class="fg"><label class="fl">On Error</label>
              <select class="fc" [(ngModel)]="onError"><option value="skip">Skip invalid</option><option value="abort">Abort</option></select></div>
          </div>
          <div class="ptable-wrap">
            <table class="ptable">
              <thead><tr><th>St</th><th>#</th><th>Question</th><th>Type</th><th>Pts</th><th>Issues</th></tr></thead>
              <tbody>
                <tr *ngFor="let r of validationResults.slice(0,50); trackBy:trackByRow" [class.row-ok]="r.valid" [class.row-err]="!r.valid">
                  <td><tui-icon [icon]="r.valid?'@tui.check-circle':'@tui.alert-circle'" [style.color]="r.valid?'#22c55e':'#ef4444'" style="font-size:16px"></tui-icon></td>
                  <td>{{ r.row }}</td>
                  <td>{{ trunc(r.data['question_text']||'',60) }}</td>
                  <td>{{ r.data['question_type']||'-' }}</td>
                  <td>{{ r.data['points']||'-' }}</td>
                  <td><span class="err-txt" *ngFor="let e of r.errors">{{ e }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
          <p class="more" *ngIf="validationResults.length>50">Showing 50 of {{ validationResults.length }} rows</p>
        </div>

        <!-- Step 4: Results -->
        <div class="step-body" *ngIf="currentStep===3">
          <div class="result-center" *ngIf="importing">
            <div class="spinner"></div>
            <h3>Importing questions...</h3>
            <p>{{ importProgress }} of {{ importStats.total }} processed</p>
            <div class="progress-bar"><div class="progress-fill" [style.width.%]="(importProgress/importStats.total)*100"></div></div>
          </div>
          <div class="result-center" *ngIf="!importing && importDone">
            <tui-icon [icon]="importStats.imported>0?'@tui.check-circle':'@tui.alert-circle'"
                      [style.color]="importStats.imported>0?'#22c55e':'#ef4444'" style="font-size:48px"></tui-icon>
            <h3>{{ importStats.imported>0 ? 'Import Complete' : 'Import Failed' }}</h3>
            <div class="result-stats">
              <div class="rs"><span class="rs-val">{{ importStats.total }}</span><span class="rs-lbl">Total Rows</span></div>
              <div class="rs ok"><span class="rs-val">{{ importStats.imported }}</span><span class="rs-lbl">Imported</span></div>
              <div class="rs err" *ngIf="importStats.skipped>0"><span class="rs-val">{{ importStats.skipped }}</span><span class="rs-lbl">Skipped</span></div>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="import-footer">
          <button class="btn btn-outline" (click)="currentStep>0 && !importing ? currentStep=currentStep-1 : close()">
            {{ currentStep===0 ? 'Cancel' : 'Back' }}
          </button>
          <button class="btn btn-primary" (click)="nextStep()" [disabled]="!canProceed()">
            {{ currentStep===3 ? 'Done' : (currentStep===2 ? 'Import' : 'Next') }}
          </button>
        </div>
      </div>
    </div>
  `,
  styles: [`
    :host { display: block; }
    .import-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000; }
    .import-modal { background: #fff; border-radius: 16px; width: 90%; max-width: 800px; max-height: 85vh; display: flex; flex-direction: column; }
    .import-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 24px; border-bottom: 1px solid #e2e8f0; }
    .import-header h2 { font-size: 20px; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px; color: #0f172a; }
    .btn-close { background: none; border: none; cursor: pointer; color: #94a3b8; padding: 4px; } .btn-close:hover { color: #0f172a; }
    .steps-bar { display: flex; padding: 16px 24px; gap: 8px; border-bottom: 1px solid #f1f5f9; }
    .step { display: flex; align-items: center; gap: 8px; flex: 1; padding: 8px 12px; border-radius: 8px; cursor: default; }
    .step.active { background: #eef2ff; }
    .step.completed { cursor: pointer; }
    .step-num { width: 26px; height: 26px; border-radius: 50%; background: #e2e8f0; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; color: #64748b; }
    .step.active .step-num { background: #6366f1; color: #fff; }
    .step.completed .step-num { background: #22c55e; color: #fff; }
    .step-label { font-size: 13px; font-weight: 500; color: #64748b; }
    .step.active .step-label { color: #4f46e5; font-weight: 600; }
    .step-body { padding: 24px; overflow-y: auto; flex: 1; font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
    .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 500; border: none; cursor: pointer; transition: all 0.15s; }
    .btn-primary { background: #6366f1; color: #fff; } .btn-primary:hover { background: #4f46e5; } .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
    .btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; } .btn-outline:hover { background: #f9fafb; }
    .btn-sm { padding: 5px 10px; font-size: 13px; }
    .btn-icon { background: none; border: none; cursor: pointer; padding: 4px; border-radius: 6px; color: #64748b; }
    .upload-zone { border: 2px dashed #e2e8f0; border-radius: 12px; padding: 40px; text-align: center; cursor: pointer; transition: all 0.15s; margin-bottom: 16px; }
    .upload-zone:hover, .upload-zone.drag-over { border-color: #6366f1; background: #f5f3ff; }
    .upload-zone h3 { margin: 12px 0 4px; color: #0f172a; font-size: 18px; }
    .upload-zone p { margin: 0; color: #64748b; font-size: 14px; }
    .hint { font-size: 12px; color: #94a3b8; }
    .file-info { display: flex; align-items: center; gap: 12px; padding: 12px 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 16px; }
    .file-det { display: flex; flex-direction: column; flex: 1; }
    .fname { font-weight: 600; color: #0f172a; font-size: 14px; }
    .fsize { font-size: 12px; color: #94a3b8; }
    .tpl-section { padding: 16px; background: #f8fafc; border-radius: 10px; }
    .tpl-section h4 { margin: 0 0 10px; font-size: 15px; color: #0f172a; }
    .tpl-btns { display: flex; gap: 10px; margin-bottom: 10px; }
    .tpl-info { font-size: 13px; color: #64748b; line-height: 1.5; margin: 0; }
    .desc { font-size: 13px; color: #64748b; margin: -4px 0 16px; }
    .map-grid { display: flex; flex-direction: column; gap: 8px; }
    .map-row { display: grid; grid-template-columns: 1fr 30px 1fr; align-items: center; gap: 8px; padding: 8px; }
    .map-hdr { font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; padding-bottom: 4px; border-bottom: 1px solid #e2e8f0; }
    .src-col { font-size: 14px; font-weight: 500; color: #0f172a; padding: 6px 10px; background: #f1f5f9; border-radius: 6px; }
    .fc { width: 100%; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; }
    .fc:focus { border-color: #6366f1; outline: none; }
    .auto-msg { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #f0fdf4; border-radius: 8px; margin-top: 12px; font-size: 14px; color: #065f46; }
    .map-warn { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #fffbeb; border-radius: 8px; margin-top: 12px; font-size: 14px; color: #92400e; }
    .val-hdr { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
    .val-hdr h3 { margin: 0; font-size: 18px; }
    .val-stats { display: flex; gap: 12px; }
    .vs { display: flex; align-items: center; gap: 4px; font-size: 13px; font-weight: 500; }
    .vs.ok { color: #22c55e; } .vs.err { color: #ef4444; } .vs.tot { color: #64748b; }
    .import-settings { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px; margin-bottom: 16px; padding: 14px; background: #f8fafc; border-radius: 10px; }
    .fg { } .fl { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 4px; }
    .ptable-wrap { overflow-x: auto; border: 1px solid #e2e8f0; border-radius: 10px; }
    .ptable { width: 100%; border-collapse: collapse; font-size: 13px; }
    .ptable th { padding: 8px 12px; text-align: left; font-weight: 600; color: #64748b; background: #f8fafc; border-bottom: 2px solid #e2e8f0; white-space: nowrap; }
    .ptable td { padding: 8px 12px; border-bottom: 1px solid #f1f5f9; }
    .row-ok td { } .row-err td { background: #fef2f2; }
    .err-txt { display: block; font-size: 11px; color: #ef4444; }
    .more { font-size: 13px; color: #94a3b8; text-align: center; margin-top: 8px; }
    .result-center { display: flex; flex-direction: column; align-items: center; padding: 30px 0; text-align: center; }
    .result-center h3 { margin: 16px 0 8px; font-size: 20px; color: #0f172a; }
    .result-center p { color: #64748b; margin: 0 0 16px; }
    .spinner { width: 40px; height: 40px; border: 3px solid #e2e8f0; border-top-color: #6366f1; border-radius: 50%; animation: spin 0.8s linear infinite; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .progress-bar { width: 300px; height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden; }
    .progress-fill { height: 100%; background: #6366f1; border-radius: 4px; transition: width 0.3s; }
    .result-stats { display: flex; gap: 24px; margin-top: 16px; }
    .rs { text-align: center; }
    .rs-val { display: block; font-size: 28px; font-weight: 700; color: #0f172a; }
    .rs.ok .rs-val { color: #22c55e; } .rs.err .rs-val { color: #ef4444; }
    .rs-lbl { font-size: 13px; color: #64748b; }
    .import-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 24px; border-top: 1px solid #e2e8f0; }
    @media (max-width: 600px) { .import-settings { grid-template-columns: 1fr; } .steps-bar { flex-wrap: wrap; } }
  `]
})
export class QuestionImportComponent implements OnDestroy {
  @Output() onComplete = new EventEmitter<void>();
  @Output() onCancel = new EventEmitter<void>();

  private http = inject(HttpClient);
  private destroy$ = new Subject<void>();
  private apiUrl = GlobalComponent.baseURL + '/user/question-bank';

  steps = ['Upload', 'Map Columns', 'Validate', 'Import'];
  currentStep = 0;

  // Step 1
  selectedFile: File|null = null;
  parsedData: ParsedRow[] = [];
  sourceColumns: string[] = [];
  dragOver = false;

  // Step 2
  columnMappings: ColumnMapping[] = [];
  autoDetectedCount = 0;
  targetFields = [
    { value: 'question_text', label: 'Question Text *' },
    { value: 'question_type', label: 'Question Type *' },
    { value: 'difficulty', label: 'Difficulty' },
    { value: 'points', label: 'Points' },
    { value: 'options', label: 'Options (pipe-separated)' },
    { value: 'correct_answer', label: 'Correct Answer' },
    { value: 'explanation', label: 'Explanation' },
    { value: 'hint', label: 'Hint' },
    { value: 'tags', label: 'Tags' },
    { value: 'time_limit_seconds', label: 'Time Limit (sec)' },
    { value: 'category', label: 'Category' }
  ];

  // Step 3
  validationResults: ValidationResult[] = [];
  banks: QuestionBank[] = [];
  targetBankId = '';
  defaultDifficulty = 'medium';
  onError = 'skip';

  // Step 4
  importing = false;
  importDone = false;
  importProgress = 0;
  importStats: ImportStats = { total: 0, valid: 0, invalid: 0, imported: 0, skipped: 0 };

  get validCount(): number { return this.validationResults.filter(r => r.valid).length; }
  get invalidCount(): number { return this.validationResults.filter(r => !r.valid).length; }

  constructor() { this.loadBanks(); }
  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }

  loadBanks(): void {
    const fd = new FormData(); fd.append('action', 'get_banks');
    this.http.post<ApiResponse<QuestionBank[]>>(this.apiUrl, fd).pipe(takeUntil(this.destroy$))
      .subscribe({ next: r => { if (r.status === 'success') this.banks = r.data || []; } });
  }

  // Step 1: File handling
  onFileSelect(e: Event): void {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (file) { this.selectedFile = file; this.parseFile(file); }
  }

  onFileDrop(e: DragEvent): void {
    const file = e.dataTransfer?.files?.[0];
    if (file) { this.selectedFile = file; this.parseFile(file); }
  }

  parseFile(file: File): void {
    if (file.name.endsWith('.csv')) {
      const reader = new FileReader();
      reader.onload = (e) => {
        const text = e.target?.result as string;
        this.parseCSV(text);
      };
      reader.readAsText(file);
    } else {
      // For xlsx, upload to server for parsing
      const fd = new FormData(); fd.append('action', 'parse_import_file'); fd.append('file', file);
      this.http.post<ApiResponse<{columns: string[]; rows: ParsedRow[]}>>(this.apiUrl, fd)
        .pipe(takeUntil(this.destroy$)).subscribe({
          next: r => {
            if (r.status === 'success') {
              this.sourceColumns = r.data.columns;
              this.parsedData = r.data.rows;
              this.autoDetectMappings();
            }
          }
        });
    }
  }

  parseCSV(text: string): void {
    const lines = text.split('\n').filter(l => l.trim());
    if (lines.length < 2) return;
    const headers = this.parseCSVLine(lines[0]);
    this.sourceColumns = headers;
    this.parsedData = [];
    for (let i = 1; i < lines.length; i++) {
      const vals = this.parseCSVLine(lines[i]);
      const row: ParsedRow = {};
      headers.forEach((h, idx) => row[h] = vals[idx] || '');
      this.parsedData.push(row);
    }
    this.autoDetectMappings();
  }

  parseCSVLine(line: string): string[] {
    const result: string[] = []; let current = ''; let inQuotes = false;
    for (const ch of line) {
      if (ch === '"') { inQuotes = !inQuotes; }
      else if (ch === ',' && !inQuotes) { result.push(current.trim()); current = ''; }
      else { current += ch; }
    }
    result.push(current.trim());
    return result;
  }

  autoDetectMappings(): void {
    const aliases: Record<string, string[]> = {
      question_text: ['question_text', 'question', 'text', 'stem', 'body'],
      question_type: ['question_type', 'type', 'qtype'],
      difficulty: ['difficulty', 'diff', 'level'],
      points: ['points', 'score', 'marks', 'weight'],
      options: ['options', 'choices', 'answers'],
      correct_answer: ['correct_answer', 'correct', 'answer', 'key'],
      explanation: ['explanation', 'rationale', 'feedback'],
      hint: ['hint', 'clue'],
      tags: ['tags', 'labels', 'keywords', 'categories']
    };
    this.columnMappings = this.sourceColumns.map(col => {
      const lower = col.toLowerCase().replace(/[^a-z0-9]/g, '_');
      let target = '';
      for (const [field, names] of Object.entries(aliases)) {
        if (names.some(n => lower.includes(n))) { target = field; break; }
      }
      return { source: col, target };
    });
    this.autoDetectedCount = this.columnMappings.filter(m => m.target).length;
  }

  isFieldMapped(field: string, excludeIdx: number): boolean {
    return this.columnMappings.some((m, i) => i !== excludeIdx && m.target === field);
  }

  hasRequiredMappings(): boolean {
    return this.columnMappings.some(m => m.target === 'question_text') &&
           this.columnMappings.some(m => m.target === 'question_type');
  }

  // Step 3: Validation
  validateData(): void {
    const validTypes = ['multiple_choice','multiple_response','true_false','short_answer','long_answer','numeric','fill_blank','matching','ordering','file_upload','hotspot'];
    this.validationResults = this.parsedData.map((row, idx) => {
      const mapped: ParsedRow = {};
      this.columnMappings.forEach(m => { if (m.target) mapped[m.target] = row[m.source] || ''; });
      if (!mapped['difficulty']) mapped['difficulty'] = this.defaultDifficulty;
      if (!mapped['points']) mapped['points'] = '1';

      const errors: string[] = [];
      if (!mapped['question_text']?.trim()) errors.push('Missing question text');
      if (!mapped['question_type']?.trim()) errors.push('Missing type');
      else if (!validTypes.includes(mapped['question_type'].trim().toLowerCase())) errors.push('Invalid type: ' + mapped['question_type']);
      const pts = parseInt(mapped['points']);
      if (isNaN(pts) || pts < 1) errors.push('Invalid points');

      return { row: idx + 2, valid: errors.length === 0, errors, data: mapped };
    });
  }

  // Step 4: Import
  executeImport(): void {
    this.importing = true; this.importDone = false; this.importProgress = 0;
    const validRows = this.validationResults.filter(r => r.valid);
    this.importStats = { total: this.validationResults.length, valid: validRows.length, invalid: this.invalidCount, imported: 0, skipped: this.invalidCount };

    const fd = new FormData();
    fd.append('action', 'import_questions');
    fd.append('bank_id', this.targetBankId);
    fd.append('default_difficulty', this.defaultDifficulty);
    fd.append('on_error', this.onError);
    fd.append('questions', JSON.stringify(validRows.map(r => r.data)));

    this.http.post<ApiResponse<{imported: number; skipped: number}>>(this.apiUrl, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: r => {
          this.importing = false; this.importDone = true;
          if (r.status === 'success') {
            this.importStats.imported = r.data.imported;
            this.importStats.skipped = r.data.skipped;
          }
        },
        error: () => {
          this.importing = false; this.importDone = true;
          this.importStats.imported = 0;
        }
      });

    // Simulate progress
    const interval = setInterval(() => {
      if (this.importProgress < this.importStats.total - 1 && this.importing) {
        this.importProgress += Math.ceil(this.importStats.total / 10);
        this.importProgress = Math.min(this.importProgress, this.importStats.total - 1);
      } else { clearInterval(interval); this.importProgress = this.importStats.total; }
    }, 300);
  }

  // Navigation
  nextStep(): void {
    if (this.currentStep === 0 && this.parsedData.length > 0) { this.currentStep = 1; }
    else if (this.currentStep === 1 && this.hasRequiredMappings()) { this.validateData(); this.currentStep = 2; }
    else if (this.currentStep === 2 && this.targetBankId) { this.currentStep = 3; this.executeImport(); }
    else if (this.currentStep === 3) { this.onComplete.emit(); }
  }

  canProceed(): boolean {
    if (this.currentStep === 0) return this.parsedData.length > 0;
    if (this.currentStep === 1) return this.hasRequiredMappings();
    if (this.currentStep === 2) return !!this.targetBankId && this.validCount > 0;
    if (this.currentStep === 3) return this.importDone;
    return false;
  }

  close(): void { this.onCancel.emit(); }

  downloadTemplate(format: string): void {
    const headers = 'question_text,question_type,difficulty,points,options,correct_answer,explanation,tags,hint';
    const sample = '"What is 2+2?",multiple_choice,easy,1,"2|3|4|5",4,"Basic arithmetic","math,basics",""';
    const csv = headers + '\n' + sample;
    if (format === 'csv') {
      const blob = new Blob([csv], { type: 'text/csv' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a'); a.href = url; a.download = 'question_import_template.csv'; a.click();
      URL.revokeObjectURL(url);
    } else {
      const fd = new FormData(); fd.append('action', 'get_import_template'); fd.append('format', 'xlsx');
      this.http.post(this.apiUrl, fd, { responseType: 'blob' }).pipe(takeUntil(this.destroy$)).subscribe({
        next: blob => {
          const url = URL.createObjectURL(blob);
          const a = document.createElement('a'); a.href = url; a.download = 'question_import_template.xlsx'; a.click();
          URL.revokeObjectURL(url);
        }
      });
    }
  }

  formatSize(bytes: number): string {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1048576).toFixed(1) + ' MB';
  }

  trunc(text: string, max: number): string {
    if (!text) return ''; const s = text.replace(/<[^>]*>/g, '');
    return s.length > max ? s.substring(0, max) + '...' : s;
  }

  trackByRow(_: number, r: ValidationResult): number { return r.row; }
}
