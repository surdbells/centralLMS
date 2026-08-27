import { Component, OnInit, OnDestroy, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { Subject, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { GlobalComponent } from '../../global-component';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';

interface Section {
  id: number;
  assessment_id: number;
  title: string;
  description: string;
  sort_order: number;
  question_count: number;
  total_points: number;
  questions?: SectionQuestion[];
  collapsed: boolean;
  editing: boolean;
  editTitle: string;
  editDescription: string;
}

interface SectionQuestion {
  id: number;
  question_text: string;
  question_type: string;
  points: number;
  sort_order: number;
  difficulty: string;
}

interface ApiResponse<T> { status: string; data: T; message?: string; }

@Component({
  selector: 'app-section-manager',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>

    <div class="section-manager">
      <!-- Header -->
      <div class="sm-header">
        <div class="sm-header-left">
          <h3>Sections</h3>
          <span class="sm-stats">
            {{ sections.length }} section{{ sections.length !== 1 ? 's' : '' }} ·
            {{ totalQuestions }} question{{ totalQuestions !== 1 ? 's' : '' }} ·
            {{ totalPoints }} points
          </span>
        </div>
        <div class="sm-header-actions">
          <button class="btn-sm-action" (click)="expandAll()" title="Expand all">
            <tui-icon icon="@tui.chevrons-down"></tui-icon>
          </button>
          <button class="btn-sm-action" (click)="collapseAll()" title="Collapse all">
            <tui-icon icon="@tui.chevrons-up"></tui-icon>
          </button>
          <button class="btn-add-section" (click)="addSection()">
            <tui-icon icon="@tui.plus"></tui-icon> Add Section
          </button>
        </div>
      </div>

      <!-- Sections List -->
      <div class="sections-list" *ngIf="sections.length > 0">
        <div class="section-block" *ngFor="let section of sections; let i = index; let first = first; let last = last"
             [class.dragging]="dragIndex === i"
             [class.drag-over]="dragOverIndex === i"
             draggable="true"
             (dragstart)="onDragStart($event, i)"
             (dragover)="onDragOver($event, i)"
             (dragleave)="onDragLeave()"
             (drop)="onDrop($event, i)"
             (dragend)="onDragEnd()">

          <!-- Section Header -->
          <div class="section-header" (click)="toggleCollapse(section)">
            <div class="section-drag-handle" title="Drag to reorder">
              <tui-icon icon="@tui.grip-vertical"></tui-icon>
            </div>

            <tui-icon [icon]="section.collapsed ? '@tui.chevron-right' : '@tui.chevron-down'"
                      style="font-size:16px;color:#94a3b8"></tui-icon>

            <div class="section-title-area" *ngIf="!section.editing">
              <span class="section-title">{{ section.title || 'Untitled Section' }}</span>
              <span class="section-meta">
                {{ section.question_count }} question{{ section.question_count !== 1 ? 's' : '' }} ·
                {{ section.total_points }} pts
              </span>
            </div>

            <!-- Inline Edit -->
            <div class="section-edit-area" *ngIf="section.editing" (click)="$event.stopPropagation()">
              <input type="text" class="edit-input title-input" [(ngModel)]="section.editTitle"
                     placeholder="Section title" (keydown.enter)="saveSection(section)" autofocus />
              <input type="text" class="edit-input desc-input" [(ngModel)]="section.editDescription"
                     placeholder="Description (optional)" (keydown.enter)="saveSection(section)" />
            </div>

            <div class="section-actions" (click)="$event.stopPropagation()">
              <button class="btn-icon" *ngIf="section.editing" (click)="saveSection(section)" title="Save">
                <tui-icon icon="@tui.check" style="color:#22c55e"></tui-icon>
              </button>
              <button class="btn-icon" *ngIf="section.editing" (click)="cancelEdit(section)" title="Cancel">
                <tui-icon icon="@tui.x" style="color:#ef4444"></tui-icon>
              </button>
              <button class="btn-icon" *ngIf="!section.editing" (click)="startEdit(section)" title="Edit">
                <tui-icon icon="@tui.pencil"></tui-icon>
              </button>
              <button class="btn-icon" *ngIf="!section.editing" (click)="moveSection(i, -1)" [disabled]="first" title="Move up">
                <tui-icon icon="@tui.arrow-up"></tui-icon>
              </button>
              <button class="btn-icon" *ngIf="!section.editing" (click)="moveSection(i, 1)" [disabled]="last" title="Move down">
                <tui-icon icon="@tui.arrow-down"></tui-icon>
              </button>
              <button class="btn-icon danger" *ngIf="!section.editing" (click)="confirmDeleteSection(section)" title="Delete">
                <tui-icon icon="@tui.trash-2"></tui-icon>
              </button>
            </div>
          </div>

          <!-- Section Body -->
          <div class="section-body" *ngIf="!section.collapsed">
            <div class="section-description" *ngIf="section.description && !section.editing">
              {{ section.description }}
            </div>

            <!-- Questions in Section -->
            <div class="section-questions" *ngIf="section.questions && section.questions.length > 0">
              <div class="sq-item" *ngFor="let q of section.questions; let qi = index; let qFirst = first; let qLast = last"
                   draggable="true"
                   (dragstart)="onQuestionDragStart($event, section, qi)"
                   (dragover)="onQuestionDragOver($event, section, qi)"
                   (drop)="onQuestionDrop($event, section, qi)"
                   [class.q-drag-over]="questionDragOverSection === section.id && questionDragOverIndex === qi">
                <span class="sq-drag"><tui-icon icon="@tui.grip-vertical" style="font-size:14px"></tui-icon></span>
                <span class="sq-type-icon">{{ getTypeIcon(q.question_type) }}</span>
                <span class="sq-text">{{ truncate(stripHtml(q.question_text), 80) }}</span>
                <span class="sq-difficulty" [class]="'diff-' + q.difficulty">{{ q.difficulty }}</span>
                <div class="sq-points">
                  <input type="number" class="points-input" [ngModel]="q.points" min="0"
                         (ngModelChange)="updateQuestionPoints(section, q, $event)"
                         (click)="$event.stopPropagation()" /> pts
                </div>
                <div class="sq-actions">
                  <button class="btn-icon-sm" (click)="moveQuestion(section, qi, -1)" [disabled]="qFirst" title="Move up">
                    <tui-icon icon="@tui.chevron-up"></tui-icon>
                  </button>
                  <button class="btn-icon-sm" (click)="moveQuestion(section, qi, 1)" [disabled]="qLast" title="Move down">
                    <tui-icon icon="@tui.chevron-down"></tui-icon>
                  </button>
                  <div class="move-to-dropdown" *ngIf="sections.length > 1">
                    <button class="btn-icon-sm" (click)="toggleMoveMenu(q.id)" title="Move to section">
                      <tui-icon icon="@tui.corner-down-right"></tui-icon>
                    </button>
                    <div class="move-menu" *ngIf="activeMoveMenu === q.id">
                      <div class="move-menu-title">Move to section:</div>
                      <button class="move-menu-item" *ngFor="let s of sections"
                              [disabled]="s.id === section.id"
                              (click)="moveQuestionToSection(section, q, s)">
                        {{ s.title || 'Untitled Section' }}
                      </button>
                    </div>
                  </div>
                  <button class="btn-icon-sm danger" (click)="removeQuestion(section, q)" title="Remove">
                    <tui-icon icon="@tui.x"></tui-icon>
                  </button>
                </div>
              </div>
            </div>

            <!-- Empty State -->
            <div class="section-empty" *ngIf="!section.questions || section.questions.length === 0">
              <tui-icon icon="@tui.inbox" style="font-size:24px;color:#cbd5e1"></tui-icon>
              <p>No questions in this section</p>
              <button class="btn-add-questions" (click)="onAddQuestions.emit(section.id)">
                <tui-icon icon="@tui.plus"></tui-icon> Add Questions
              </button>
            </div>

            <!-- Section Footer -->
            <div class="section-footer" *ngIf="section.questions && section.questions.length > 0">
              <button class="btn-add-questions-sm" (click)="onAddQuestions.emit(section.id)">
                <tui-icon icon="@tui.plus"></tui-icon> Add Questions
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Empty State -->
      <div class="empty-state" *ngIf="sections.length === 0 && !loading">
        <tui-icon icon="@tui.layers" style="font-size:48px;color:#cbd5e1"></tui-icon>
        <h3>No Sections Yet</h3>
        <p>Create sections to organize your assessment questions</p>
        <button class="btn-add-section" (click)="addSection()">
          <tui-icon icon="@tui.plus"></tui-icon> Add First Section
        </button>
      </div>

      <!-- Delete Confirmation Modal -->
      <div class="modal-overlay" *ngIf="deleteTarget" (click)="deleteTarget = null">
        <div class="modal-card" (click)="$event.stopPropagation()">
          <h3>Delete Section</h3>
          <p>Are you sure you want to delete <strong>{{ deleteTarget.title || 'Untitled Section' }}</strong>?</p>
          <p class="warning-text" *ngIf="deleteTarget.question_count > 0">
            <tui-icon icon="@tui.alert-triangle" style="color:#f59e0b;font-size:16px"></tui-icon>
            This section contains {{ deleteTarget.question_count }} question(s). They will be removed from this assessment but remain in the question bank.
          </p>
          <div class="modal-actions">
            <button class="btn-cancel" (click)="deleteTarget = null">Cancel</button>
            <button class="btn-delete" (click)="deleteSection(deleteTarget)">Delete Section</button>
          </div>
        </div>
      </div>

      <!-- Loading -->
      <div class="loading-state" *ngIf="loading">
        <div class="spinner"></div>
      </div>
    </div>
  `,
  styles: [`
    :host { display: block; }
    .section-manager { }

    .sm-header {
      display: flex; align-items: center; justify-content: space-between;
      margin-bottom: 16px; flex-wrap: wrap; gap: 12px;
    }
    .sm-header h3 { font-size: 16px; font-weight: 600; color: #0f172a; margin: 0; }
    .sm-stats { font-size: 13px; color: #64748b; margin-left: 12px; }
    .sm-header-actions { display: flex; gap: 8px; align-items: center; }
    .btn-sm-action {
      background: #f1f5f9; border: 1px solid #e2e8f0; padding: 6px; border-radius: 6px;
      cursor: pointer; display: flex; color: #64748b;
    }
    .btn-sm-action:hover { background: #e2e8f0; }
    .btn-add-section {
      display: flex; align-items: center; gap: 6px; background: #6366f1; color: white;
      border: none; padding: 8px 16px; border-radius: 6px; font-size: 13px;
      font-weight: 500; cursor: pointer;
    }
    .btn-add-section:hover { background: #4f46e5; }

    /* Section Blocks */
    .sections-list { display: flex; flex-direction: column; gap: 8px; }
    .section-block {
      background: white; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;
      transition: all 0.2s;
    }
    .section-block.dragging { opacity: 0.5; }
    .section-block.drag-over { border-color: #6366f1; box-shadow: 0 0 0 2px rgba(99,102,241,0.2); }

    .section-header {
      display: flex; align-items: center; gap: 8px; padding: 12px 16px;
      background: #f8fafc; cursor: pointer; border-bottom: 1px solid #e2e8f0;
    }
    .section-header:hover { background: #f1f5f9; }

    .section-drag-handle { cursor: grab; color: #cbd5e1; display: flex; }
    .section-drag-handle:active { cursor: grabbing; }

    .section-title-area { flex: 1; min-width: 0; }
    .section-title { font-size: 15px; font-weight: 600; color: #0f172a; display: block; }
    .section-meta { font-size: 12px; color: #94a3b8; }

    .section-edit-area { flex: 1; display: flex; flex-direction: column; gap: 6px; }
    .edit-input {
      padding: 6px 10px; border: 1px solid #e2e8f0; border-radius: 4px;
      font-size: 14px; outline: none; width: 100%;
    }
    .edit-input:focus { border-color: #6366f1; }
    .title-input { font-weight: 600; }
    .desc-input { font-size: 13px; color: #64748b; }

    .section-actions { display: flex; gap: 4px; align-items: center; }
    .btn-icon {
      background: none; border: none; padding: 4px; cursor: pointer; color: #94a3b8;
      border-radius: 4px; display: flex;
    }
    .btn-icon:hover { background: #e2e8f0; color: #334155; }
    .btn-icon:disabled { opacity: 0.3; cursor: default; }
    .btn-icon.danger:hover { background: #fef2f2; color: #ef4444; }

    /* Section Body */
    .section-body { padding: 0; }
    .section-description {
      padding: 8px 16px; font-size: 13px; color: #64748b; background: #fafbfc;
      border-bottom: 1px solid #f1f5f9;
    }

    /* Questions */
    .section-questions { }
    .sq-item {
      display: flex; align-items: center; gap: 8px; padding: 10px 16px;
      border-bottom: 1px solid #f1f5f9; font-size: 14px;
      transition: background 0.15s;
    }
    .sq-item:hover { background: #fafbfc; }
    .sq-item.q-drag-over { background: #ede9fe; border-bottom-color: #6366f1; }
    .sq-drag { cursor: grab; color: #cbd5e1; display: flex; flex-shrink: 0; }
    .sq-type-icon { font-size: 16px; flex-shrink: 0; }
    .sq-text { flex: 1; min-width: 0; color: #334155; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sq-difficulty {
      font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 500;
      text-transform: capitalize; flex-shrink: 0;
    }
    .diff-easy { background: #d1fae5; color: #059669; }
    .diff-medium { background: #fef3c7; color: #d97706; }
    .diff-hard { background: #fce7f3; color: #db2777; }
    .sq-points { display: flex; align-items: center; gap: 4px; font-size: 13px; color: #64748b; flex-shrink: 0; }
    .points-input {
      width: 48px; padding: 3px 6px; border: 1px solid #e2e8f0; border-radius: 4px;
      text-align: center; font-size: 13px; outline: none;
    }
    .points-input:focus { border-color: #6366f1; }
    .sq-actions { display: flex; gap: 2px; align-items: center; flex-shrink: 0; }
    .btn-icon-sm {
      background: none; border: none; padding: 3px; cursor: pointer; color: #94a3b8;
      border-radius: 3px; display: flex;
    }
    .btn-icon-sm:hover { background: #e2e8f0; color: #334155; }
    .btn-icon-sm:disabled { opacity: 0.3; cursor: default; }
    .btn-icon-sm.danger:hover { background: #fef2f2; color: #ef4444; }

    /* Move to section dropdown */
    .move-to-dropdown { position: relative; }
    .move-menu {
      position: absolute; right: 0; top: 100%; background: white;
      border: 1px solid #e2e8f0; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.1);
      min-width: 180px; z-index: 10; padding: 4px;
    }
    .move-menu-title { font-size: 11px; color: #94a3b8; padding: 6px 10px; text-transform: uppercase; }
    .move-menu-item {
      display: block; width: 100%; text-align: left; padding: 8px 10px;
      border: none; background: none; cursor: pointer; font-size: 13px;
      border-radius: 4px; color: #334155;
    }
    .move-menu-item:hover { background: #f1f5f9; }
    .move-menu-item:disabled { opacity: 0.4; cursor: default; }

    /* Empty & Footer */
    .section-empty {
      text-align: center; padding: 32px 16px; color: #94a3b8;
    }
    .section-empty p { margin: 8px 0 16px; font-size: 14px; }
    .btn-add-questions {
      display: inline-flex; align-items: center; gap: 6px;
      background: #ede9fe; color: #6366f1; border: none; padding: 8px 16px;
      border-radius: 6px; font-size: 13px; font-weight: 500; cursor: pointer;
    }
    .btn-add-questions:hover { background: #ddd6fe; }
    .section-footer { padding: 8px 16px; border-top: 1px solid #f1f5f9; }
    .btn-add-questions-sm {
      display: inline-flex; align-items: center; gap: 4px; font-size: 13px;
      color: #6366f1; background: none; border: none; cursor: pointer; padding: 4px 0;
    }
    .btn-add-questions-sm:hover { text-decoration: underline; }

    /* Empty State */
    .empty-state { text-align: center; padding: 60px 20px; }
    .empty-state h3 { font-size: 18px; font-weight: 600; color: #334155; margin: 16px 0 8px; }
    .empty-state p { color: #94a3b8; margin-bottom: 24px; font-size: 14px; }

    /* Modal */
    .modal-overlay {
      position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex;
      align-items: center; justify-content: center; z-index: 200;
    }
    .modal-card { background: white; border-radius: 10px; padding: 28px; max-width: 440px; width: 100%; }
    .modal-card h3 { font-size: 18px; font-weight: 600; margin: 0 0 12px; }
    .modal-card p { font-size: 14px; color: #334155; margin-bottom: 12px; }
    .warning-text {
      display: flex; align-items: flex-start; gap: 8px; background: #fffbeb;
      padding: 12px; border-radius: 6px; color: #92400e; font-size: 13px;
    }
    .modal-actions { display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px; }
    .btn-cancel {
      padding: 8px 20px; border: 1px solid #e2e8f0; border-radius: 6px;
      background: white; cursor: pointer; font-size: 14px;
    }
    .btn-delete {
      padding: 8px 20px; border: none; border-radius: 6px; background: #ef4444;
      color: white; cursor: pointer; font-weight: 500; font-size: 14px;
    }

    .loading-state { display: flex; justify-content: center; padding: 40px; }
    .spinner {
      width: 32px; height: 32px; border: 3px solid #e2e8f0;
      border-top-color: #6366f1; border-radius: 50%; animation: spin 0.8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    @media (max-width: 768px) {
      .sq-difficulty { display: none; }
      .sq-text { max-width: 140px; }
      .sm-header { flex-direction: column; align-items: flex-start; }
    }
  `]
})
export class SectionManagerComponent implements OnInit, OnDestroy {
  @Input() assessmentId!: number;
  @Input() sections: Section[] = [];
  @Output() sectionsChange = new EventEmitter<Section[]>();
  @Output() onAddQuestions = new EventEmitter<number>();
  @Output() onSectionUpdated = new EventEmitter<void>();

  private destroy$ = new Subject<void>();
  private apiUrl = GlobalComponent.baseURL + '/user/assessment';

  loading = false;
  deleteTarget: Section | null = null;
  activeMoveMenu: number | null = null;

  // Drag state - sections
  dragIndex: number | null = null;
  dragOverIndex: number | null = null;

  // Drag state - questions
  questionDragSection: Section | null = null;
  questionDragIndex: number | null = null;
  questionDragOverSection: number | null = null;
  questionDragOverIndex: number | null = null;

  get totalQuestions(): number { return this.sections.reduce((t, s) => t + (s.question_count || 0), 0); }
  get totalPoints(): number { return this.sections.reduce((t, s) => t + (s.total_points || 0), 0); }

  constructor(private http: HttpClient) {}

  ngOnInit(): void {
    if (this.sections.length === 0 && this.assessmentId) this.loadSections();
    document.addEventListener('click', this.closeMenus);
  }

  ngOnDestroy(): void {
    this.destroy$.next(); this.destroy$.complete();
    document.removeEventListener('click', this.closeMenus);
  }

  private closeMenus = () => { this.activeMoveMenu = null; };

  loadSections(): void {
    this.loading = true;
    const fd = new FormData();
    fd.append('action', 'get_assessment_sections');
    fd.append('assessment_id', this.assessmentId.toString());

    this.http.post<ApiResponse<Section[]>>(this.apiUrl, fd).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        if (res.status === 'success') {
          this.sections = (res.data || []).map(s => ({
            ...s, collapsed: false, editing: false, editTitle: s.title, editDescription: s.description
          }));
          this.sectionsChange.emit(this.sections);
        }
        this.loading = false;
      },
      error: () => { this.loading = false; }
    });
  }

  addSection(): void {
    const fd = new FormData();
    fd.append('action', 'create_section');
    fd.append('assessment_id', this.assessmentId.toString());
    fd.append('title', `Section ${this.sections.length + 1}`);
    fd.append('sort_order', this.sections.length.toString());

    this.http.post<ApiResponse<any>>(this.apiUrl, fd).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        if (res.status === 'success') {
          const newSection: Section = {
            id: res.data.id || res.data.section_id,
            assessment_id: this.assessmentId,
            title: `Section ${this.sections.length + 1}`,
            description: '', sort_order: this.sections.length,
            question_count: 0, total_points: 0, questions: [],
            collapsed: false, editing: true,
            editTitle: `Section ${this.sections.length + 1}`, editDescription: ''
          };
          this.sections.push(newSection);
          this.sectionsChange.emit(this.sections);
          this.onSectionUpdated.emit();
        }
      }
    });
  }

  startEdit(section: Section): void {
    section.editTitle = section.title;
    section.editDescription = section.description;
    section.editing = true;
  }

  cancelEdit(section: Section): void {
    section.editing = false;
  }

  saveSection(section: Section): void {
    const fd = new FormData();
    fd.append('action', 'update_section');
    fd.append('section_id', section.id.toString());
    fd.append('title', section.editTitle);
    fd.append('description', section.editDescription);

    this.http.post<ApiResponse<any>>(this.apiUrl, fd).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        if (res.status === 'success') {
          section.title = section.editTitle;
          section.description = section.editDescription;
          section.editing = false;
          this.onSectionUpdated.emit();
        }
      }
    });
  }

  confirmDeleteSection(section: Section): void {
    this.deleteTarget = section;
  }

  deleteSection(section: Section | null): void {
    const fd = new FormData();
    fd.append('action', 'delete_section');
    if (section?.id !== undefined && section?.id !== null) {
      fd.append('section_id', section.id.toString());
    }

    this.http.post<ApiResponse<any>>(this.apiUrl, fd).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        if (res.status === 'success') {
          this.sections = this.sections.filter(s => s.id !== section?.id);
          this.deleteTarget = null;
          this.sectionsChange.emit(this.sections);
          this.onSectionUpdated.emit();
        }
      }
    });
  }

  toggleCollapse(section: Section): void {
    if (!section.editing) section.collapsed = !section.collapsed;
  }

  expandAll(): void { this.sections.forEach(s => s.collapsed = false); }
  collapseAll(): void { this.sections.forEach(s => s.collapsed = true); }

  moveSection(index: number, direction: number): void {
    const newIdx = index + direction;
    if (newIdx < 0 || newIdx >= this.sections.length) return;
    [this.sections[index], this.sections[newIdx]] = [this.sections[newIdx], this.sections[index]];
    this.saveSectionOrder();
  }

  private saveSectionOrder(): void {
    const order = this.sections.map((s, i) => ({ id: s.id, sort_order: i }));
    const fd = new FormData();
    fd.append('action', 'reorder_sections');
    fd.append('assessment_id', this.assessmentId.toString());
    fd.append('order', JSON.stringify(order));

    this.http.post<ApiResponse<any>>(this.apiUrl, fd).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => { this.sectionsChange.emit(this.sections); this.onSectionUpdated.emit(); }
    });
  }

  // Section drag & drop
  onDragStart(event: DragEvent, index: number): void {
    this.dragIndex = index;
    event.dataTransfer?.setData('text/plain', 'section');
  }
  onDragOver(event: DragEvent, index: number): void {
    event.preventDefault();
    this.dragOverIndex = index;
  }
  onDragLeave(): void { this.dragOverIndex = null; }
  onDrop(event: DragEvent, index: number): void {
    event.preventDefault();
    if (this.dragIndex !== null && this.dragIndex !== index) {
      const item = this.sections.splice(this.dragIndex, 1)[0];
      this.sections.splice(index, 0, item);
      this.saveSectionOrder();
    }
    this.dragIndex = null;
    this.dragOverIndex = null;
  }
  onDragEnd(): void { this.dragIndex = null; this.dragOverIndex = null; }

  // Question drag & drop
  onQuestionDragStart(event: DragEvent, section: Section, index: number): void {
    event.stopPropagation();
    this.questionDragSection = section;
    this.questionDragIndex = index;
    event.dataTransfer?.setData('text/plain', 'question');
  }
  onQuestionDragOver(event: DragEvent, section: Section, index: number): void {
    event.preventDefault();
    event.stopPropagation();
    this.questionDragOverSection = section.id;
    this.questionDragOverIndex = index;
  }
  onQuestionDrop(event: DragEvent, targetSection: Section, targetIndex: number): void {
    event.preventDefault();
    event.stopPropagation();
    if (!this.questionDragSection || this.questionDragIndex === null) return;

    const srcSection = this.questionDragSection;
    const srcIndex = this.questionDragIndex;
    const question = srcSection.questions![srcIndex];

    if (srcSection.id === targetSection.id) {
      // Reorder within section
      srcSection.questions!.splice(srcIndex, 1);
      srcSection.questions!.splice(targetIndex, 0, question);
      this.saveQuestionOrder(srcSection);
    } else {
      // Move to different section
      srcSection.questions!.splice(srcIndex, 1);
      srcSection.question_count--;
      srcSection.total_points -= question.points;
      targetSection.questions!.splice(targetIndex, 0, question);
      targetSection.question_count++;
      targetSection.total_points += question.points;
      this.apiMoveQuestionToSection(question, targetSection);
    }

    this.questionDragSection = null;
    this.questionDragIndex = null;
    this.questionDragOverSection = null;
    this.questionDragOverIndex = null;
  }

  moveQuestion(section: Section, index: number, direction: number): void {
    const questions = section.questions!;
    const newIdx = index + direction;
    if (newIdx < 0 || newIdx >= questions.length) return;
    [questions[index], questions[newIdx]] = [questions[newIdx], questions[index]];
    this.saveQuestionOrder(section);
  }

  toggleMoveMenu(questionId: number): void {
    this.activeMoveMenu = this.activeMoveMenu === questionId ? null : questionId;
  }

  moveQuestionToSection(fromSection: Section, question: SectionQuestion, toSection: Section): void {
    const idx = fromSection.questions!.findIndex(q => q.id === question.id);
    if (idx >= 0) {
      fromSection.questions!.splice(idx, 1);
      fromSection.question_count--;
      fromSection.total_points -= question.points;
      toSection.questions = toSection.questions || [];
      toSection.questions.push(question);
      toSection.question_count++;
      toSection.total_points += question.points;
      this.apiMoveQuestionToSection(question, toSection);
    }
    this.activeMoveMenu = null;
  }

  removeQuestion(section: Section, question: SectionQuestion): void {
    const fd = new FormData();
    fd.append('action', 'remove_question');
    fd.append('assessment_id', this.assessmentId.toString());
    fd.append('question_id', question.id.toString());

    this.http.post<ApiResponse<any>>(this.apiUrl, fd).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        if (res.status === 'success') {
          section.questions = section.questions!.filter(q => q.id !== question.id);
          section.question_count--;
          section.total_points -= question.points;
          this.sectionsChange.emit(this.sections);
          this.onSectionUpdated.emit();
        }
      }
    });
  }

  updateQuestionPoints(section: Section, question: SectionQuestion, points: number): void {
    const oldPoints = question.points;
    question.points = points;
    section.total_points += (points - oldPoints);

    const fd = new FormData();
    fd.append('action', 'update_question_points');
    fd.append('assessment_id', this.assessmentId.toString());
    fd.append('question_id', question.id.toString());
    fd.append('points', points.toString());

    this.http.post<ApiResponse<any>>(this.apiUrl, fd).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => { this.onSectionUpdated.emit(); }
    });
  }

  private saveQuestionOrder(section: Section): void {
    const order = section.questions!.map((q, i) => ({ id: q.id, sort_order: i }));
    const fd = new FormData();
    fd.append('action', 'reorder_questions');
    fd.append('section_id', section.id.toString());
    fd.append('order', JSON.stringify(order));

    this.http.post<ApiResponse<any>>(this.apiUrl, fd).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => { this.onSectionUpdated.emit(); }
    });
  }

  private apiMoveQuestionToSection(question: SectionQuestion, toSection: Section): void {
    const fd = new FormData();
    fd.append('action', 'move_question_to_section');
    fd.append('question_id', question.id.toString());
    fd.append('section_id', toSection.id.toString());

    this.http.post<ApiResponse<any>>(this.apiUrl, fd).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.sectionsChange.emit(this.sections);
        this.onSectionUpdated.emit();
      }
    });
  }

  getTypeIcon(type: string): string {
    const icons: Record<string, string> = {
      multiple_choice: '◉', multiple_select: '☑', true_false: '⊘', short_answer: '✎',
      long_answer: '¶', essay: '📝', fill_in_blank: '▢', matching: '⇄',
      ordering: '↕', numeric: '#', hotspot: '◎'
    };
    return icons[type] || '•';
  }

  truncate(text: string, max: number): string {
    return text.length > max ? text.substring(0, max) + '...' : text;
  }

  stripHtml(html: string): string {
    const div = document.createElement('div');
    div.innerHTML = html || '';
    return div.textContent || div.innerText || '';
  }
}
