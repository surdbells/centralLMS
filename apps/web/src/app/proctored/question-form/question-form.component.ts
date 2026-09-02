// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 9
// Admin: Question Bank - Question Form Component
// Multi-Tenant LMS | Angular Standalone
// Supports all 11 question types
// =============================================

import { Component, OnInit, OnDestroy, inject, ViewChild, ElementRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Subject, forkJoin, of, takeUntil } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { TuiIcon } from '@taiga-ui/core';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { AssessmentsService, type QuestionInput } from '../../services/assessments.service';
import { AuthImgDirective } from '../../core/api';

interface QuestionBank { id: string; name: string; }
interface Category { id: string; name: string; }
interface QuestionOption { id?: string; option_text: string; is_correct: boolean; feedback: string; order_position: number; }
interface MatchPair { id?: string; premise: string; response: string; order_position: number; }
interface OrderItem { id?: string; item_text: string; correct_position: number; }
interface FillBlank { id?: string; blank_index: number; acceptable_answers: string[]; case_sensitive: boolean; }
interface HotspotArea { id?: string; shape: 'rect'|'circle'; x: number; y: number; width: number; height: number; label: string; is_correct: boolean; }

interface QuestionForm {
  question_id: string|null; bank_id: string|null; category_id: string|null;
  question_text: string; question_type: string; difficulty: string; points: number;
  time_limit_seconds: number|null; explanation: string; hint: string; tags: string;
  status: string; image_url: string;
  options: QuestionOption[]; match_pairs: MatchPair[]; order_items: OrderItem[];
  fill_blanks: FillBlank[]; hotspot_areas: HotspotArea[];
  numeric_answer: number|null; numeric_tolerance: number; numeric_unit: string;
  sample_answer: string; max_length: number;
  allowed_extensions: string; max_file_size_mb: number; hotspot_image_url: string;
}

@Component({
  selector: 'app-question-form',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent, AuthImgDirective],
  template: `
    <app-admintop></app-admintop>

    <div class="question-form-container">
      <div class="page-header">
        <button class="btn btn-ghost" (click)="goBack()"><tui-icon icon="@tui.arrow-left"></tui-icon> Back</button>
        <h1 class="page-title">{{ isEdit ? 'Edit Question' : 'Create Question' }}</h1>
        <div class="header-actions">
          <button class="btn btn-outline" (click)="saveDraft()" [disabled]="saving">Save Draft</button>
          <button class="btn btn-primary" (click)="save()" [disabled]="saving || !isValid()">
            {{ saving ? 'Saving...' : (isEdit ? 'Update' : 'Create') }}
          </button>
        </div>
      </div>

      <div class="form-layout">
        <div class="form-main">
          <!-- Type Selector -->
          <div class="form-section" *ngIf="!isEdit">
            <h2 class="section-title">Question Type</h2>
            <div class="type-grid">
              <button *ngFor="let t of questionTypes" class="type-card"
                      [class.selected]="form.question_type === t.value" (click)="selectType(t.value)">
                <tui-icon [icon]="t.icon" style="font-size:22px"></tui-icon>
                <span class="type-name">{{ t.label }}</span>
                <span class="type-desc">{{ t.desc }}</span>
              </button>
            </div>
          </div>

          <!-- Question Text -->
          <div class="form-section">
            <h2 class="section-title">Question Text *</h2>
            <div class="toolbar">
              <button class="tb" (click)="insertTag('b')" title="Bold"><strong>B</strong></button>
              <button class="tb" (click)="insertTag('i')" title="Italic"><em>I</em></button>
              <button class="tb" (click)="insertTag('u')" title="Underline"><u>U</u></button>
              <button class="tb" (click)="insertTag('code')" title="Code">&lt;/&gt;</button>
            </div>
            <textarea class="form-textarea no-top-radius" rows="5" #questionTextarea
                      [(ngModel)]="form.question_text" placeholder="Enter question text (HTML supported)..."></textarea>
          </div>

          <!-- Question Image (non-hotspot) -->
          <div class="form-section" *ngIf="form.question_type !== 'hotspot'">
            <h2 class="section-title">Image <span class="opt">(Optional)</span></h2>
            <div class="upload-area" *ngIf="!form.image_url" (click)="imgInput.click()">
              <tui-icon icon="@tui.upload-cloud" style="font-size:32px;color:#94a3b8"></tui-icon>
              <p>Click or drag image here</p>
              <input type="file" #imgInput hidden accept="image/*" (change)="onImageSelect($event)" />
            </div>
            <div class="img-preview" *ngIf="form.image_url">
              <img *ngIf="isInternalImage(form.image_url); else extImg" [appAuthImg]="form.image_url" />
              <ng-template #extImg><img [src]="form.image_url" /></ng-template>
              <button class="btn btn-sm btn-outline" (click)="form.image_url=''"><tui-icon icon="@tui.x"></tui-icon> Remove</button>
            </div>
          </div>

          <!-- MULTIPLE CHOICE / MULTIPLE RESPONSE -->
          <div class="form-section" *ngIf="form.question_type==='multiple_choice' || form.question_type==='multiple_response'">
            <div class="section-row"><h2 class="section-title">Answer Options</h2>
              <button class="btn btn-sm btn-outline" (click)="addOption()"><tui-icon icon="@tui.plus"></tui-icon> Add</button>
            </div>
            <p class="desc">{{ form.question_type==='multiple_choice' ? 'Select ONE correct answer.' : 'Select ALL correct answers.' }}</p>
            <div class="opt-list">
              <div *ngFor="let o of form.options; let i=index; trackBy:trackByIndex" class="opt-row" [class.correct]="o.is_correct"
                   draggable="true" (dragstart)="dragIdx=i" (dragover)="$event.preventDefault()" (drop)="dropItem(i,'options')">
                <tui-icon icon="@tui.grip-vertical" style="font-size:14px;color:#94a3b8;cursor:grab"></tui-icon>
                <div class="marker" (click)="toggleCorrect(i)">
                  <span *ngIf="form.question_type==='multiple_choice'" class="radio" [class.on]="o.is_correct"></span>
                  <span *ngIf="form.question_type==='multiple_response'" class="check" [class.on]="o.is_correct"></span>
                </div>
                <div class="opt-inputs">
                  <input type="text" class="opt-text" [(ngModel)]="o.option_text" [placeholder]="'Option '+getMarker(i)" />
                  <input type="text" class="opt-fb" [(ngModel)]="o.feedback" placeholder="Feedback (optional)" />
                </div>
                <button class="btn-icon btn-danger" (click)="removeOption(i)" *ngIf="form.options.length>2">
                  <tui-icon icon="@tui.trash-2" style="font-size:14px"></tui-icon>
                </button>
              </div>
            </div>
          </div>

          <!-- TRUE/FALSE -->
          <div class="form-section" *ngIf="form.question_type==='true_false'">
            <h2 class="section-title">Correct Answer</h2>
            <div class="tf-row">
              <button class="tf-btn" [class.sel]="form.options[0]?.is_correct===true" (click)="setTF(true)">
                <tui-icon icon="@tui.check-circle" style="font-size:20px"></tui-icon> True</button>
              <button class="tf-btn" [class.sel]="form.options[0]?.is_correct===false" (click)="setTF(false)">
                <tui-icon icon="@tui.x-circle" style="font-size:20px"></tui-icon> False</button>
            </div>
            <div class="fb-group"><label>Correct feedback:</label><input class="form-control" [(ngModel)]="form.options[0].feedback" /></div>
            <div class="fb-group"><label>Incorrect feedback:</label><input class="form-control" [(ngModel)]="form.options[1].feedback" /></div>
          </div>

          <!-- SHORT ANSWER -->
          <div class="form-section" *ngIf="form.question_type==='short_answer'">
            <h2 class="section-title">Accepted Answers</h2>
            <div class="ans-list">
              <div *ngFor="let o of form.options; let i=index" class="ans-row">
                <input class="form-control" [(ngModel)]="o.option_text" placeholder="Accepted answer..." />
                <button class="btn-icon btn-danger" (click)="removeOption(i)" *ngIf="form.options.length>1">
                  <tui-icon icon="@tui.x" style="font-size:14px"></tui-icon></button>
              </div>
              <button class="btn btn-sm btn-outline" (click)="addShortAnswer()"><tui-icon icon="@tui.plus"></tui-icon> Add Alternative</button>
            </div>
            <div class="mt-12"><label class="form-label">Sample Answer:</label>
              <textarea class="form-textarea" rows="3" [(ngModel)]="form.sample_answer" placeholder="Model answer for graders..."></textarea>
            </div>
          </div>

          <!-- LONG ANSWER -->
          <div class="form-section" *ngIf="form.question_type==='long_answer'">
            <h2 class="section-title">Essay Settings</h2>
            <div class="grid-2">
              <div class="fg"><label class="form-label">Max Word Count</label><input type="number" class="form-control" [(ngModel)]="form.max_length" min="10" /></div>
              <div class="fg"><label class="form-label">Grading</label><select class="form-control"><option>Manual</option><option>Rubric-Based</option></select></div>
            </div>
            <div class="mt-12"><label class="form-label">Model Answer / Rubric:</label>
              <textarea class="form-textarea" rows="4" [(ngModel)]="form.sample_answer" placeholder="Grading criteria..."></textarea></div>
          </div>

          <!-- NUMERIC -->
          <div class="form-section" *ngIf="form.question_type==='numeric'">
            <h2 class="section-title">Numeric Answer</h2>
            <div class="grid-3">
              <div class="fg"><label class="form-label">Answer *</label><input type="number" class="form-control" [(ngModel)]="form.numeric_answer" step="any" /></div>
              <div class="fg"><label class="form-label">Tolerance (±)</label><input type="number" class="form-control" [(ngModel)]="form.numeric_tolerance" step="any" min="0" /></div>
              <div class="fg"><label class="form-label">Unit</label><input class="form-control" [(ngModel)]="form.numeric_unit" placeholder="kg, m/s..." /></div>
            </div>
            <div class="range-box" *ngIf="form.numeric_answer!==null">
              Accepted: <strong>{{ (form.numeric_answer||0) - form.numeric_tolerance }} - {{ (form.numeric_answer||0) + form.numeric_tolerance }} {{ form.numeric_unit }}</strong>
            </div>
          </div>

          <!-- FILL IN THE BLANK -->
          <div class="form-section" *ngIf="form.question_type==='fill_blank'">
            <h2 class="section-title">Blanks</h2>
            <p class="desc">Use <code>___</code> in question text to mark blanks. Define answers below.</p>
            <div class="blanks-list">
              <div *ngFor="let b of form.fill_blanks; let i=index" class="blank-card">
                <div class="blank-hdr">
                  <strong>Blank {{ i+1 }}</strong>
                  <label class="cb-label"><input type="checkbox" [(ngModel)]="b.case_sensitive" /> Case-sensitive</label>
                </div>
                <div *ngFor="let a of b.acceptable_answers; let j=index" class="ans-row">
                  <input class="form-control" [(ngModel)]="b.acceptable_answers[j]" placeholder="Answer..." />
                  <button class="btn-icon btn-danger" *ngIf="b.acceptable_answers.length>1" (click)="b.acceptable_answers.splice(j,1)">
                    <tui-icon icon="@tui.x" style="font-size:14px"></tui-icon></button>
                </div>
                <button class="btn btn-sm btn-ghost" (click)="b.acceptable_answers.push('')">+ Alternative</button>
              </div>
            </div>
            <button class="btn btn-sm btn-outline" (click)="addBlank()"><tui-icon icon="@tui.plus"></tui-icon> Add Blank</button>
          </div>

          <!-- MATCHING -->
          <div class="form-section" *ngIf="form.question_type==='matching'">
            <div class="section-row"><h2 class="section-title">Matching Pairs</h2>
              <button class="btn btn-sm btn-outline" (click)="addMatch()"><tui-icon icon="@tui.plus"></tui-icon> Add</button>
            </div>
            <div class="match-list">
              <div class="match-hdr"><span>Premise</span><span></span><span>Response</span><span></span></div>
              <div *ngFor="let p of form.match_pairs; let i=index" class="match-row"
                   draggable="true" (dragstart)="dragIdx=i" (dragover)="$event.preventDefault()" (drop)="dropItem(i,'match_pairs')">
                <input class="form-control" [(ngModel)]="p.premise" placeholder="Premise..." />
                <tui-icon icon="@tui.arrow-right" style="font-size:14px;color:#94a3b8"></tui-icon>
                <input class="form-control" [(ngModel)]="p.response" placeholder="Response..." />
                <button class="btn-icon btn-danger" (click)="removeMatch(i)" *ngIf="form.match_pairs.length>2">
                  <tui-icon icon="@tui.trash-2" style="font-size:14px"></tui-icon></button>
              </div>
            </div>
          </div>

          <!-- ORDERING -->
          <div class="form-section" *ngIf="form.question_type==='ordering'">
            <div class="section-row"><h2 class="section-title">Items (Correct Order)</h2>
              <button class="btn btn-sm btn-outline" (click)="addOrder()"><tui-icon icon="@tui.plus"></tui-icon> Add</button>
            </div>
            <div class="order-list">
              <div *ngFor="let it of form.order_items; let i=index" class="order-row"
                   draggable="true" (dragstart)="dragIdx=i" (dragover)="$event.preventDefault()" (drop)="dropItem(i,'order_items')">
                <span class="order-num">{{ i+1 }}</span>
                <tui-icon icon="@tui.grip-vertical" style="font-size:14px;color:#94a3b8;cursor:grab"></tui-icon>
                <input class="form-control" [(ngModel)]="it.item_text" placeholder="Item..." />
                <button class="btn-icon btn-danger" (click)="removeOrder(i)" *ngIf="form.order_items.length>2">
                  <tui-icon icon="@tui.trash-2" style="font-size:14px"></tui-icon></button>
              </div>
            </div>
          </div>

          <!-- FILE UPLOAD -->
          <div class="form-section" *ngIf="form.question_type==='file_upload'">
            <h2 class="section-title">Upload Settings</h2>
            <div class="grid-2">
              <div class="fg"><label class="form-label">Allowed Types</label>
                <input class="form-control" [(ngModel)]="form.allowed_extensions" placeholder="pdf,docx,jpg" />
                <div class="hint">Comma-separated extensions</div></div>
              <div class="fg"><label class="form-label">Max Size (MB)</label>
                <input type="number" class="form-control" [(ngModel)]="form.max_file_size_mb" min="1" max="100" /></div>
            </div>
            <div class="mt-12"><label class="form-label">Grading Instructions:</label>
              <textarea class="form-textarea" rows="3" [(ngModel)]="form.sample_answer" placeholder="How to grade uploads..."></textarea></div>
          </div>

          <!-- HOTSPOT -->
          <div class="form-section" *ngIf="form.question_type==='hotspot'">
            <h2 class="section-title">Hotspot Image</h2>
            <div class="upload-area" *ngIf="!form.hotspot_image_url" (click)="hsInput.click()">
              <tui-icon icon="@tui.image" style="font-size:32px;color:#94a3b8"></tui-icon>
              <p>Upload image for hotspot</p>
              <input type="file" #hsInput hidden accept="image/*" (change)="onHotspotSelect($event)" />
            </div>
            <div *ngIf="form.hotspot_image_url" class="hs-editor">
              <div class="hs-img" (click)="addHotspot($event)" #hsContainer>
                <img *ngIf="isInternalImage(form.hotspot_image_url); else hsExt" [appAuthImg]="form.hotspot_image_url" />
                <ng-template #hsExt><img [src]="form.hotspot_image_url" /></ng-template>
                <div *ngFor="let a of form.hotspot_areas; let i=index" class="hs-area" [class.correct]="a.is_correct"
                     [style.left.px]="a.x" [style.top.px]="a.y" [style.width.px]="a.width" [style.height.px]="a.height"
                     [style.borderRadius]="a.shape==='circle'?'50%':'4px'">
                  <span class="hs-lbl">{{ a.label||(i+1) }}</span>
                </div>
              </div>
              <div class="hs-list">
                <h3>Areas (click image to add)</h3>
                <div *ngFor="let a of form.hotspot_areas; let i=index" class="hs-row">
                  <input class="form-control fc-sm" [(ngModel)]="a.label" placeholder="Label" />
                  <select class="form-control fc-sm" [(ngModel)]="a.shape"><option value="rect">Rect</option><option value="circle">Circle</option></select>
                  <label class="cb-label"><input type="checkbox" [(ngModel)]="a.is_correct" /> Correct</label>
                  <button class="btn-icon btn-danger" (click)="form.hotspot_areas.splice(i,1)"><tui-icon icon="@tui.trash-2" style="font-size:14px"></tui-icon></button>
                </div>
              </div>
              <button class="btn btn-sm btn-outline" (click)="form.hotspot_image_url=''"><tui-icon icon="@tui.x"></tui-icon> Change Image</button>
            </div>
          </div>

          <!-- Explanation -->
          <div class="form-section">
            <h2 class="section-title">Explanation <span class="opt">(Optional)</span></h2>
            <textarea class="form-textarea" rows="3" [(ngModel)]="form.explanation" placeholder="Explain correct answer..."></textarea>
          </div>

          <!-- Hint -->
          <div class="form-section">
            <h2 class="section-title">Hint <span class="opt">(Optional)</span></h2>
            <input class="form-control" [(ngModel)]="form.hint" placeholder="Optional hint for learners" />
          </div>
        </div>

        <!-- SIDEBAR -->
        <div class="form-sidebar">
          <div class="side-card">
            <h3 class="sc-title">Settings</h3>
            <div class="fg"><label class="form-label">Bank *</label>
              <select class="form-control" [(ngModel)]="form.bank_id">
                <option [value]="null">-- Select --</option>
                <option *ngFor="let b of banks" [value]="b.id">{{ b.name }}</option></select></div>
            <div class="fg"><label class="form-label">Category</label>
              <select class="form-control" [(ngModel)]="form.category_id">
                <option [value]="null">None</option>
                <option *ngFor="let c of categories" [value]="c.id">{{ c.name }}</option></select></div>
            <div class="fg"><label class="form-label">Difficulty *</label>
              <div class="diff-sel">
                <button class="db" [class.sel]="form.difficulty==='easy'" [class.easy]="form.difficulty==='easy'" (click)="form.difficulty='easy'">Easy</button>
                <button class="db" [class.sel]="form.difficulty==='medium'" [class.med]="form.difficulty==='medium'" (click)="form.difficulty='medium'">Medium</button>
                <button class="db" [class.sel]="form.difficulty==='hard'" [class.hard]="form.difficulty==='hard'" (click)="form.difficulty='hard'">Hard</button>
              </div></div>
            <div class="fg"><label class="form-label">Points *</label><input type="number" class="form-control" [(ngModel)]="form.points" min="1" max="100" /></div>
            <div class="fg"><label class="form-label">Time Limit (sec)</label><input type="number" class="form-control" [(ngModel)]="form.time_limit_seconds" min="0" placeholder="None" /></div>
            <div class="fg"><label class="form-label">Status</label>
              <select class="form-control" [(ngModel)]="form.status"><option value="active">Active</option><option value="inactive">Inactive</option><option value="archived">Archived</option></select></div>
          </div>
          <div class="side-card">
            <h3 class="sc-title">Tags</h3>
            <input class="form-control" [(ngModel)]="form.tags" placeholder="Comma-separated" />
            <div class="tag-preview" *ngIf="form.tags">
              <span *ngFor="let t of parseTags(form.tags)" class="tag-chip">{{ t }}</span></div>
            <div class="pop-tags" *ngIf="existingTags.length">
              <span class="hint">Popular:</span>
              <button *ngFor="let t of existingTags.slice(0,8)" class="tag-chip tc" (click)="addTag(t)">{{ t }}</button></div>
          </div>
          <div class="side-card side-info" *ngIf="form.question_type">
            <h3 class="sc-title"><tui-icon [icon]="getTypeIcon(form.question_type)" style="font-size:16px"></tui-icon> {{ getTypeLabel(form.question_type) }}</h3>
            <p class="info-desc">{{ getTypeDescription(form.question_type) }}</p>
          </div>
          <div class="side-card side-err" *ngIf="validationErrors.length">
            <h3 class="sc-title" style="color:#ef4444"><tui-icon icon="@tui.alert-triangle" style="font-size:16px"></tui-icon> Issues</h3>
            <ul class="err-list"><li *ngFor="let e of validationErrors">{{ e }}</li></ul>
          </div>
        </div>
      </div>
      <div class="toast" *ngIf="toast.show" [class]="'toast toast-'+toast.type">
        <tui-icon [icon]="toast.type==='success'?'@tui.check-circle':'@tui.alert-circle'" style="font-size:18px"></tui-icon> {{ toast.message }}
      </div>
    </div>
  `,
  styles: [`
    :host { display: block; }
    .question-form-container { max-width: 1200px; margin: 0 auto; padding: 24px; font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
    .page-header { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; flex-wrap: wrap; }
    .page-title { font-size: 22px; font-weight: 700; color: #0f172a; margin: 0; flex: 1; }
    .header-actions { display: flex; gap: 10px; }
    .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; font-size: 14px; font-weight: 500; border: none; cursor: pointer; transition: all 0.15s; }
    .btn-primary { background: #6366f1; color: #fff; } .btn-primary:hover { background: #4f46e5; } .btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
    .btn-outline { background: #fff; color: #374151; border: 1px solid #d1d5db; } .btn-outline:hover { background: #f9fafb; }
    .btn-ghost { background: none; color: #64748b; border: none; } .btn-ghost:hover { color: #334155; }
    .btn-sm { padding: 5px 10px; font-size: 13px; }
    .btn-icon { background: none; border: none; cursor: pointer; padding: 4px; border-radius: 6px; color: #64748b; }
    .btn-icon:hover { background: #f1f5f9; } .btn-danger:hover { background: #fef2f2; color: #ef4444; }
    .form-layout { display: grid; grid-template-columns: 1fr 300px; gap: 24px; align-items: start; }
    .form-section { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 16px; }
    .section-title { font-size: 16px; font-weight: 600; color: #0f172a; margin: 0 0 12px; }
    .section-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
    .section-row .section-title { margin: 0; }
    .desc { font-size: 13px; color: #64748b; margin: -4px 0 12px; } .opt { font-size: 13px; color: #94a3b8; font-weight: 400; }
    .form-control { width: 100%; padding: 9px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; box-sizing: border-box; }
    .form-control:focus { border-color: #6366f1; outline: none; box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
    .fc-sm { padding: 6px 10px; font-size: 13px; width: auto; }
    .form-textarea { width: 100%; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px; resize: vertical; font-family: inherit; box-sizing: border-box; }
    .form-textarea:focus { border-color: #6366f1; outline: none; box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
    .no-top-radius { border-radius: 0 0 8px 8px; }
    .form-label { display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 6px; }
    .fg { margin-bottom: 14px; } .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; } .mt-12 { margin-top: 12px; }
    .hint { font-size: 12px; color: #94a3b8; margin-top: 4px; }
    .toolbar { display: flex; gap: 4px; padding: 6px 8px; border: 1px solid #e2e8f0; border-bottom: none; border-radius: 8px 8px 0 0; background: #f8fafc; }
    .tb { width: 32px; height: 28px; display: flex; align-items: center; justify-content: center; border: none; background: none; cursor: pointer; border-radius: 4px; font-size: 14px; color: #475569; }
    .tb:hover { background: #e2e8f0; }
    .type-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 10px; }
    .type-card { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 12px 8px; border: 2px solid #e2e8f0; border-radius: 10px; background: #fff; cursor: pointer; transition: all 0.15s; text-align: center; }
    .type-card:hover { border-color: #a5b4fc; background: #f5f3ff; } .type-card.selected { border-color: #6366f1; background: #eef2ff; }
    .type-name { font-size: 12px; font-weight: 600; color: #0f172a; } .type-desc { font-size: 10px; color: #94a3b8; line-height: 1.2; }
    .opt-list { display: flex; flex-direction: column; gap: 8px; }
    .opt-row { display: flex; align-items: flex-start; gap: 8px; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; }
    .opt-row.correct { border-color: #86efac; background: #f0fdf4; }
    .marker { cursor: pointer; padding-top: 4px; }
    .radio { width: 20px; height: 20px; border-radius: 50%; border: 2px solid #d1d5db; display: block; }
    .radio.on { border-color: #22c55e; background: #22c55e; box-shadow: inset 0 0 0 3px #fff; }
    .check { width: 20px; height: 20px; border-radius: 4px; border: 2px solid #d1d5db; display: block; }
    .check.on { border-color: #22c55e; background: #22c55e; }
    .opt-inputs { flex: 1; display: flex; flex-direction: column; gap: 4px; }
    .opt-text { border: none; border-bottom: 1px solid #e2e8f0; padding: 4px 0; font-size: 14px; outline: none; background: transparent; width: 100%; }
    .opt-text:focus { border-bottom-color: #6366f1; }
    .opt-fb { border: none; padding: 2px 0; font-size: 12px; color: #64748b; outline: none; background: transparent; width: 100%; }
    .tf-row { display: flex; gap: 12px; margin-bottom: 16px; }
    .tf-btn { display: flex; align-items: center; gap: 8px; padding: 14px 24px; border: 2px solid #e2e8f0; border-radius: 10px; background: #fff; cursor: pointer; font-size: 16px; font-weight: 600; flex: 1; justify-content: center; }
    .tf-btn:hover { border-color: #a5b4fc; } .tf-btn.sel { border-color: #6366f1; background: #eef2ff; color: #4f46e5; }
    .fb-group { margin-bottom: 10px; } .fb-group label { font-size: 13px; color: #64748b; margin-bottom: 4px; display: block; }
    .ans-list { display: flex; flex-direction: column; gap: 8px; }
    .ans-row { display: flex; gap: 8px; align-items: center; } .ans-row .form-control { flex: 1; }
    .range-box { margin-top: 12px; padding: 10px 14px; background: #f0fdf4; border-radius: 8px; font-size: 14px; color: #065f46; }
    .blanks-list { display: flex; flex-direction: column; gap: 12px; margin-bottom: 12px; }
    .blank-card { padding: 14px; border: 1px solid #e2e8f0; border-radius: 8px; background: #f8fafc; }
    .blank-hdr { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
    .cb-label { display: flex; align-items: center; gap: 6px; font-size: 13px; color: #475569; cursor: pointer; }
    .match-list { display: flex; flex-direction: column; gap: 8px; }
    .match-hdr { display: grid; grid-template-columns: 1fr 20px 1fr 32px; gap: 8px; padding: 0 8px; font-size: 12px; font-weight: 600; color: #64748b; text-transform: uppercase; }
    .match-row { display: grid; grid-template-columns: 1fr 20px 1fr 32px; gap: 8px; align-items: center; padding: 8px; border: 1px solid #e2e8f0; border-radius: 8px; }
    .order-list { display: flex; flex-direction: column; gap: 6px; margin-bottom: 12px; }
    .order-row { display: flex; align-items: center; gap: 8px; padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; }
    .order-num { width: 28px; height: 28px; border-radius: 50%; background: #6366f1; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; flex-shrink: 0; }
    .order-row .form-control { flex: 1; }
    .upload-area { border: 2px dashed #e2e8f0; border-radius: 10px; padding: 30px; text-align: center; cursor: pointer; }
    .upload-area:hover { border-color: #a5b4fc; background: #f5f3ff; }
    .upload-area p { margin: 8px 0 0; color: #475569; font-size: 14px; }
    .img-preview { display: flex; flex-direction: column; align-items: center; gap: 10px; }
    .img-preview img { max-width: 100%; max-height: 250px; border-radius: 8px; border: 1px solid #e2e8f0; }
    .hs-editor { display: flex; flex-direction: column; gap: 16px; }
    .hs-img { position: relative; display: inline-block; cursor: crosshair; }
    .hs-img img { max-width: 100%; border-radius: 8px; }
    .hs-area { position: absolute; border: 2px solid #6366f1; background: rgba(99,102,241,0.15); display: flex; align-items: center; justify-content: center; }
    .hs-area.correct { border-color: #22c55e; background: rgba(34,197,94,0.15); }
    .hs-lbl { font-size: 10px; font-weight: 600; color: #fff; background: rgba(0,0,0,0.5); padding: 1px 4px; border-radius: 3px; }
    .hs-list h3 { font-size: 14px; font-weight: 600; margin: 0 0 8px; }
    .hs-row { display: flex; gap: 8px; align-items: center; margin-bottom: 6px; }
    .side-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 16px; margin-bottom: 16px; }
    .sc-title { font-size: 15px; font-weight: 600; color: #0f172a; margin: 0 0 12px; display: flex; align-items: center; gap: 8px; }
    .diff-sel { display: flex; gap: 6px; }
    .db { flex: 1; padding: 7px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 13px; font-weight: 500; background: #fff; cursor: pointer; }
    .db:hover { border-color: #a5b4fc; }
    .db.sel { color: #fff; border-color: transparent; }
    .db.easy { background: #22c55e; } .db.med { background: #f59e0b; } .db.hard { background: #ef4444; }
    .tag-preview { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 8px; }
    .tag-chip { display: inline-flex; padding: 2px 8px; border-radius: 20px; font-size: 12px; border: 1px solid #e2e8f0; background: #f8fafc; color: #475569; }
    .tc { cursor: pointer; } .tc:hover { border-color: #6366f1; color: #6366f1; }
    .pop-tags { margin-top: 10px; display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
    .side-info { background: #f5f3ff; border-color: #e0e7ff; }
    .info-desc { font-size: 13px; color: #475569; line-height: 1.5; margin: 0; }
    .side-err { background: #fef2f2; border-color: #fecaca; }
    .err-list { margin: 0; padding: 0 0 0 18px; font-size: 13px; color: #dc2626; }
    .err-list li { margin-bottom: 4px; }
    .toast { position: fixed; bottom: 24px; right: 24px; padding: 12px 20px; border-radius: 10px; display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; z-index: 2000; animation: slideUp 0.3s ease; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    .toast-success { background: #f0fdf4; color: #15803d; border: 1px solid #86efac; }
    .toast-error { background: #fef2f2; color: #dc2626; border: 1px solid #fca5a5; }
    @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    @keyframes spin { to { transform: rotate(360deg); } }
    @media (max-width: 768px) { .form-layout { grid-template-columns: 1fr; } .type-grid { grid-template-columns: repeat(3, 1fr); } .grid-2, .grid-3 { grid-template-columns: 1fr; } }
  `]
})
export class QuestionFormComponent implements OnInit, OnDestroy {
  @ViewChild('questionTextarea') questionTextarea!: ElementRef<HTMLTextAreaElement>;
  private assessments = inject(AssessmentsService);
  private router = inject(Router);
  private route = inject(ActivatedRoute);
  private destroy$ = new Subject<void>();

  isEdit = false;
  saving = false;
  uploadingImage = false;
  banks: QuestionBank[] = [];
  categories: Category[] = [];
  existingTags: string[] = [];
  dragIdx = -1;
  toast = { show: false, message: '', type: 'success' as 'success'|'error' };

  questionTypes = [
    { value: 'multiple_choice', label: 'Multiple Choice', icon: '@tui.circle-dot', desc: 'One correct answer' },
    { value: 'multiple_response', label: 'Multiple Response', icon: '@tui.check-square', desc: 'Multiple correct' },
    { value: 'true_false', label: 'True / False', icon: '@tui.toggle-left', desc: 'Binary choice' },
    { value: 'short_answer', label: 'Short Answer', icon: '@tui.type', desc: 'Brief text input' },
    { value: 'long_answer', label: 'Essay', icon: '@tui.file-text', desc: 'Extended writing' },
    { value: 'numeric', label: 'Numeric', icon: '@tui.hash', desc: 'Number with tolerance' },
    { value: 'fill_blank', label: 'Fill Blank', icon: '@tui.minus-square', desc: 'Complete sentences' },
    { value: 'matching', label: 'Matching', icon: '@tui.git-merge', desc: 'Match pairs' },
    { value: 'ordering', label: 'Ordering', icon: '@tui.list-ordered', desc: 'Arrange in order' },
    { value: 'file_upload', label: 'File Upload', icon: '@tui.upload', desc: 'Submit files' },
    { value: 'hotspot', label: 'Hotspot', icon: '@tui.target', desc: 'Click on image' }
  ];

  form: QuestionForm = this.getBlankForm();

  get validationErrors(): string[] {
    const e: string[] = [];
    if (!this.form.question_text.trim()) e.push('Question text is required');
    if (!this.form.question_type) e.push('Select a question type');
    if (!this.form.bank_id) e.push('Select a question bank');
    if (!this.form.difficulty) e.push('Select difficulty');
    if (this.form.points < 1) e.push('Points must be at least 1');
    const t = this.form.question_type;
    if ((t === 'multiple_choice' || t === 'multiple_response') && !this.form.options.some(o => o.is_correct)) e.push('Mark at least one correct answer');
    if ((t === 'multiple_choice' || t === 'multiple_response') && this.form.options.some(o => !o.option_text.trim())) e.push('All options need text');
    if (t === 'short_answer' && !this.form.options.some(o => o.option_text.trim())) e.push('Add at least one accepted answer');
    if (t === 'numeric' && this.form.numeric_answer === null) e.push('Numeric answer is required');
    if (t === 'matching' && this.form.match_pairs.some(p => !p.premise.trim() || !p.response.trim())) e.push('All match pairs need text');
    if (t === 'ordering' && this.form.order_items.some(o => !o.item_text.trim())) e.push('All order items need text');
    if (t === 'hotspot' && !this.form.hotspot_image_url) e.push('Upload a hotspot image');
    if (t === 'hotspot' && this.form.hotspot_areas.length < 2) e.push('Mark at least two hotspot regions');
    if (t === 'hotspot' && !this.form.hotspot_areas.some(a => a.is_correct)) e.push('Mark at least one correct region');
    return e;
  }

  get detectedBlanks(): number[] {
    const matches = this.form.question_text.match(/___/g);
    return matches ? Array.from({length: matches.length}, (_, i) => i) : [];
  }

  ngOnInit(): void {
    const id = this.route.snapshot.paramMap.get('id');
    this.isEdit = !!id;
    this.loadBanks(id);
  }

  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }

  getBlankForm(): QuestionForm {
    return {
      question_id: null, bank_id: null, category_id: null, question_text: '',
      question_type: 'multiple_choice', difficulty: 'medium', points: 1,
      time_limit_seconds: null, explanation: '', hint: '', tags: '', status: 'active', image_url: '',
      options: [
        { option_text: '', is_correct: false, feedback: '', order_position: 0 },
        { option_text: '', is_correct: false, feedback: '', order_position: 1 },
        { option_text: '', is_correct: false, feedback: '', order_position: 2 },
        { option_text: '', is_correct: false, feedback: '', order_position: 3 }
      ],
      match_pairs: [
        { premise: '', response: '', order_position: 0 },
        { premise: '', response: '', order_position: 1 },
        { premise: '', response: '', order_position: 2 }
      ],
      order_items: [
        { item_text: '', correct_position: 0 },
        { item_text: '', correct_position: 1 },
        { item_text: '', correct_position: 2 }
      ],
      fill_blanks: [{ blank_index: 0, acceptable_answers: [''], case_sensitive: false }],
      hotspot_areas: [],
      numeric_answer: null, numeric_tolerance: 0, numeric_unit: '',
      sample_answer: '', max_length: 500,
      allowed_extensions: 'pdf,docx,jpg,png', max_file_size_mb: 10, hotspot_image_url: ''
    };
  }

  loadBanks(editId?: string | null): void {
    // Categories have no endpoint on the new API, so that control stays empty.
    this.categories = [];
    this.existingTags = [];
    this.assessments.banks().pipe(takeUntil(this.destroy$)).subscribe({
      next: (page) => {
        this.banks = (page.items || []).map((b) => ({ id: b.id, name: b.name }));
        // Tag suggestions: the distinct tags across the tenant's banks, merged.
        if (this.banks.length) {
          forkJoin(this.banks.map((b) =>
            this.assessments.questionTags(b.id).pipe(catchError(() => of([] as string[]))),
          )).pipe(takeUntil(this.destroy$)).subscribe((lists) => {
            this.existingTags = Array.from(new Set(lists.flat())).sort();
          });
        }
        // Edit-load: there is no single-question GET, and the route carries no
        // bank id, so the question is found by scanning the banks' questions.
        if (editId) this.loadQuestion(editId);
      }
    });
  }

  loadQuestion(id: string): void {
    const lookups = this.banks.map((b) =>
      this.assessments.bankQuestions(b.id).pipe(
        map((page) => (page.items || []).map((q) => ({ bankId: b.id, q }))),
        catchError(() => of([] as Array<{ bankId: string; q: Record<string, unknown> }>)),
      ),
    );
    if (lookups.length === 0) return;
    forkJoin(lookups).pipe(takeUntil(this.destroy$)).subscribe((results) => {
      const flat = results.flat();
      const hit = flat.find((x) => String((x.q as { id?: string }).id) === id);
      if (hit) this.form = this.fromApiQuestion(hit.bankId, hit.q as Record<string, unknown>);
      else this.showToast('This question could not be found to edit.', 'error');
    });
  }

  /** Maps a bank question (review shape) back onto the form. */
  private fromApiQuestion(bankId: string, q: Record<string, unknown>): QuestionForm {
    const type = this.typeToForm((q['type'] as string) ?? 'multiple_choice_single');
    const options = ((q['options'] as Record<string, unknown>[]) ?? []).map((o, i) => ({
      id: o['id'] as string | undefined,
      option_text: (o['text'] as string) ?? '',
      is_correct: !!o['isCorrect'],
      feedback: (o['feedback'] as string) ?? '',
      order_position: (o['sortOrder'] as number) ?? i,
    }));
    const base = this.getBlankForm();
    return {
      ...base,
      question_id: (q['id'] as string) ?? null,
      bank_id: bankId,
      question_text: (q['text'] as string) ?? '',
      question_type: type,
      difficulty: this.difficultyToForm((q['difficulty'] as string) ?? ''),
      points: (q['points'] as number) ?? 1,
      explanation: (q['explanation'] as string) ?? '',
      hint: (q['hint'] as string) ?? '',
      tags: ((q['tags'] as string[]) ?? []).join(', '),
      image_url: type === 'hotspot' ? '' : ((q['mediaUrl'] as string) ?? ''),
      hotspot_image_url: type === 'hotspot' ? ((q['mediaUrl'] as string) ?? '') : '',
      status: (q['isActive'] === false) ? 'inactive' : 'active',
      numeric_answer: (q['numericAnswer'] as number) ?? null,
      numeric_tolerance: (q['numericTolerance'] as number) ?? 0,
      sample_answer: (q['modelAnswer'] as string) ?? '',
      options: options.length ? options : base.options,
      match_pairs: type === 'matching' && options.length
        ? options.map((o, i) => ({ id: o.id, premise: o.option_text, response: String((((q['options'] as Record<string, unknown>[])[i]) || {})['matchText'] ?? ''), order_position: o.order_position }))
        : base.match_pairs,
      order_items: type === 'ordering' && options.length
        ? options.map((o) => ({ id: o.id, item_text: o.option_text, correct_position: o.order_position }))
        : base.order_items,
      hotspot_areas: type === 'hotspot' && options.length
        ? options.map((o, i) => {
            const geo = { shape: 'rect' as 'rect'|'circle', x: 0, y: 0, width: 40, height: 40 };
            try {
              Object.assign(geo, JSON.parse(String((((q['options'] as Record<string, unknown>[])[i]) || {})['matchText'] ?? '{}')));
            } catch { /* a region without stored geometry keeps the defaults */ }
            return { id: o.id, shape: geo.shape, x: geo.x, y: geo.y, width: geo.width, height: geo.height, label: o.option_text, is_correct: o.is_correct };
          })
        : base.hotspot_areas,
    };
  }

  selectType(type: string): void {
    this.form.question_type = type;
    if (type === 'true_false') {
      this.form.options = [
        { option_text: 'True', is_correct: true, feedback: '', order_position: 0 },
        { option_text: 'False', is_correct: false, feedback: '', order_position: 1 }
      ];
    }
  }

  // === Options ===
  addOption(): void {
    this.form.options.push({ option_text: '', is_correct: false, feedback: '', order_position: this.form.options.length });
  }
  removeOption(i: number): void { this.form.options.splice(i, 1); }
  addShortAnswer(): void { this.form.options.push({ option_text: '', is_correct: true, feedback: '', order_position: this.form.options.length }); }

  toggleCorrect(i: number): void {
    if (this.form.question_type === 'multiple_choice') {
      this.form.options.forEach((o, idx) => o.is_correct = idx === i);
    } else {
      this.form.options[i].is_correct = !this.form.options[i].is_correct;
    }
  }

  setTF(val: boolean): void {
    this.form.options[0].is_correct = val;
    this.form.options[1].is_correct = !val;
  }

  // === Matching ===
  addMatch(): void { this.form.match_pairs.push({ premise: '', response: '', order_position: this.form.match_pairs.length }); }
  removeMatch(i: number): void { this.form.match_pairs.splice(i, 1); }

  // === Ordering ===
  addOrder(): void { this.form.order_items.push({ item_text: '', correct_position: this.form.order_items.length }); }
  removeOrder(i: number): void { this.form.order_items.splice(i, 1); this.form.order_items.forEach((it, idx) => it.correct_position = idx); }

  // === Fill Blank ===
  addBlank(): void { this.form.fill_blanks.push({ blank_index: this.form.fill_blanks.length, acceptable_answers: [''], case_sensitive: false }); }

  // === Hotspot ===
  addHotspot(e: MouseEvent): void {
    const rect = (e.target as HTMLElement).closest('.hs-img')?.getBoundingClientRect();
    if (!rect) return;
    const x = e.clientX - rect.left - 25; const y = e.clientY - rect.top - 25;
    this.form.hotspot_areas.push({ shape: 'rect', x: Math.max(0, x), y: Math.max(0, y), width: 50, height: 50, label: '', is_correct: true });
  }

  // === Drag & Drop ===
  dropItem(targetIdx: number, list: 'options'|'match_pairs'|'order_items'): void {
    if (this.dragIdx < 0 || this.dragIdx === targetIdx) return;
    const arr = this.form[list] as any[];
    const item = arr.splice(this.dragIdx, 1)[0];
    arr.splice(targetIdx, 0, item);
    if (list === 'order_items') arr.forEach((it: any, idx: number) => it.correct_position = idx);
    this.dragIdx = -1;
  }

  // === Image Upload ===
  onImageSelect(e: Event): void { this.uploadImage(e, 'image_url'); }
  onHotspotSelect(e: Event): void { this.uploadImage(e, 'hotspot_image_url'); }

  private uploadImage(e: Event, field: 'image_url'|'hotspot_image_url'): void {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;

    this.uploadingImage = true;
    this.assessments.uploadQuestionImage(file).pipe(takeUntil(this.destroy$)).subscribe({
      next: (mediaUrl) => {
        this.form[field] = mediaUrl;
        this.uploadingImage = false;
        this.showToast('Image uploaded', 'success');
      },
      error: (err) => {
        this.uploadingImage = false;
        const msg = (err as { error?: { error?: { message?: string } } })?.error?.error?.message;
        this.showToast(msg || 'Image upload failed', 'error');
      },
    });
    // Allow re-selecting the same file after a remove.
    input.value = '';
  }

  /** Our uploaded images are base-relative and header-authed (appAuthImg); a
   *  pasted absolute URL renders with a plain src. */
  isInternalImage(url: string): boolean {
    return !!url && url.startsWith('/');
  }

  // === Rich Text ===
  insertTag(tag: string): void {
    const ta = this.questionTextarea?.nativeElement;
    if (!ta) return;
    const start = ta.selectionStart; const end = ta.selectionEnd;
    const sel = this.form.question_text.substring(start, end);
    const wrap = `<${tag}>${sel}</${tag}>`;
    this.form.question_text = this.form.question_text.substring(0, start) + wrap + this.form.question_text.substring(end);
  }

  // === Tags ===
  parseTags(str: string): string[] { return str.split(',').map(t => t.trim()).filter(t => t); }
  addTag(tag: string): void {
    const tags = this.parseTags(this.form.tags);
    if (!tags.includes(tag)) { tags.push(tag); this.form.tags = tags.join(', '); }
  }

  // === Helpers ===
  getMarker(i: number): string { return String.fromCharCode(65 + i); }
  getTypeLabel(type: string): string { return this.questionTypes.find(t => t.value === type)?.label || type; }
  getTypeIcon(type: string): string { return this.questionTypes.find(t => t.value === type)?.icon || '@tui.help-circle'; }
  getTypeDescription(type: string): string {
    const descs: Record<string, string> = {
      multiple_choice: 'Learner selects one correct answer from the options. Auto-graded.',
      multiple_response: 'Learner selects all correct answers. Partial scoring supported.',
      true_false: 'Simple binary choice. Auto-graded.',
      short_answer: 'Learner types a brief text answer. Matched against accepted answers.',
      long_answer: 'Extended writing response. Requires manual or rubric-based grading.',
      numeric: 'Learner enters a number. Graded with configurable tolerance range.',
      fill_blank: 'Learner fills in missing words. Each blank has accepted answers.',
      matching: 'Learner matches premises to responses. Auto-graded.',
      ordering: 'Learner arranges items in correct sequence. Auto-graded.',
      file_upload: 'Learner uploads files. Requires manual grading.',
      hotspot: 'Learner clicks on correct region(s) of an image. Auto-graded.'
    };
    return descs[type] || '';
  }

  trackByIndex(i: number): number { return i; }
  isValid(): boolean { return this.validationErrors.length === 0; }
  goBack(): void { this.router.navigate(['/admin/question-bank']); }

  // === Save ===
  saveDraft(): void { this.form.status = 'inactive'; this.save(); }

  save(): void {
    if (!this.isValid()) { this.showToast('Please fix validation errors', 'error'); return; }
    const bankId = this.form.bank_id;
    if (!bankId) { this.showToast('Select a question bank', 'error'); return; }
    this.saving = true;
    const t = this.form.question_type;

    const input: QuestionInput = {
      type: this.typeToApi(t),
      text: this.form.question_text,
      points: this.form.points,
      explanation: this.form.explanation || null,
      hint: this.form.hint || null,
      difficulty: this.difficultyToApi(this.form.difficulty),
      isActive: this.form.status === 'active',
      // A hotspot keeps its image in a separate field; everything else in
      // image_url. Either way it persists to the question's mediaUrl.
      mediaUrl: (t === 'hotspot' ? this.form.hotspot_image_url : this.form.image_url) || null,
      tags: this.parseTags(this.form.tags),
      options: this.buildOptions(t),
    };
    if (t === 'numeric') {
      input.numericAnswer = this.form.numeric_answer ?? 0;
      input.numericTolerance = this.form.numeric_tolerance;
    }
    if (t === 'long_answer' || t === 'short_answer') {
      input.modelAnswer = this.form.sample_answer || null;
    }

    const obs = this.isEdit && this.form.question_id
      ? this.assessments.updateQuestion(bankId, this.form.question_id, input)
      : this.assessments.createQuestion(bankId, input);

    obs.pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.saving = false;
        this.showToast(this.isEdit ? 'Question updated' : 'Question created', 'success');
        setTimeout(() => this.goBack(), 1000);
      },
      error: (err) => {
        this.saving = false;
        const e = err as { error?: { error?: { message?: string } } };
        this.showToast(e?.error?.error?.message || 'Save failed', 'error');
      }
    });
  }

  /** Builds the API option list for the types that carry one. */
  private buildOptions(t: string): QuestionInput['options'] {
    if (t === 'multiple_choice' || t === 'multiple_response' || t === 'true_false') {
      return this.form.options.filter(o => o.option_text.trim())
        .map((o, i) => ({ text: o.option_text, isCorrect: o.is_correct, feedback: o.feedback || null, sortOrder: i }));
    }
    if (t === 'matching') {
      return this.form.match_pairs.filter(p => p.premise.trim())
        .map((p, i) => ({ text: p.premise, matchText: p.response, isCorrect: true, sortOrder: i }));
    }
    if (t === 'ordering') {
      return this.form.order_items.filter(o => o.item_text.trim())
        .map((o) => ({ text: o.item_text, isCorrect: true, sortOrder: o.correct_position }));
    }
    if (t === 'short_answer') {
      // The accepted answers are correct options — the grader marks a short
      // answer right when it matches the model answer OR any correct option.
      return this.form.options.filter(o => o.option_text.trim())
        .map((o, i) => ({ text: o.option_text.trim(), isCorrect: true, sortOrder: i }));
    }
    if (t === 'hotspot') {
      // The schema has no coordinate columns, so each region is stored as an
      // option: the label as text, its geometry packed into matchText so the
      // editor can restore it, and is_correct carried through. (Auto-grading a
      // hotspot still needs backend support; this just makes it saveable.)
      return this.form.hotspot_areas.map((a, i) => ({
        text: (a.label || '').trim() || `Region ${i + 1}`,
        matchText: JSON.stringify({ shape: a.shape, x: a.x, y: a.y, width: a.width, height: a.height }),
        isCorrect: a.is_correct,
        sortOrder: i,
      }));
    }
    // long/numeric/fill/file carry no options on the new model.
    return undefined;
  }

  private typeToApi(t: string): string {
    const m: Record<string, string> = {
      multiple_choice: 'multiple_choice_single', multiple_response: 'multiple_choice_multiple',
      true_false: 'true_false', short_answer: 'short_answer', long_answer: 'long_answer',
      numeric: 'numeric', fill_blank: 'fill_in_blanks', matching: 'matching', ordering: 'ordering',
      file_upload: 'file_upload', hotspot: 'hotspot',
    };
    return m[t] || t;
  }

  private typeToForm(apiType: string): string {
    const m: Record<string, string> = {
      multiple_choice_single: 'multiple_choice', multiple_choice_multiple: 'multiple_response',
      fill_in_blanks: 'fill_blank', long_answer: 'long_answer',
    };
    return m[apiType] || apiType;
  }

  private difficultyToApi(d: string): string {
    return d === 'easy' ? 'easy' : d === 'hard' ? 'hard' : 'medium';
  }

  private difficultyToForm(apiD: string): string {
    const m: Record<string, string> = {
      beginner: 'easy', intermediate: 'medium', professional: 'hard',
      easy: 'easy', medium: 'medium', hard: 'hard',
    };
    return m[apiD] || 'medium';
  }

  showToast(message: string, type: 'success'|'error'): void {
    this.toast = { show: true, message, type };
    setTimeout(() => this.toast.show = false, 3000);
  }
}
