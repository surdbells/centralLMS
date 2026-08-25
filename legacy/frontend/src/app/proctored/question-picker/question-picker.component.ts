import { Component, OnInit, OnDestroy, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { Subject, takeUntil, debounceTime, distinctUntilChanged } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { GlobalComponent } from '../../global-component';

interface PickerQuestion {
  id: number;
  question_text: string;
  question_type: string;
  difficulty: string;
  points: number;
  bank_id: number;
  bank_name: string;
  category_id: number;
  tags: string[];
  usage_count: number;
  selected?: boolean;
}

interface QuestionBank { id: number; name: string; question_count: number; }
interface ApiResponse<T> { status: string; data: T; total?: number; message?: string; }

@Component({
  selector: 'app-question-picker',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon],
  template: `
    <div class="picker-overlay" (click)="onClose.emit()">
      <div class="picker-modal" (click)="$event.stopPropagation()">
        <div class="picker-header">
          <h3><tui-icon icon="@tui.plus-circle"></tui-icon> Add Questions</h3>
          <span class="picker-subtitle" *ngIf="targetSectionName">to {{ targetSectionName }}</span>
          <button class="btn-close" (click)="onClose.emit()"><tui-icon icon="@tui.x"></tui-icon></button>
        </div>

        <div class="picker-filters">
          <div class="search-box">
            <tui-icon icon="@tui.search" style="font-size:16px;color:#94a3b8"></tui-icon>
            <input type="text" placeholder="Search questions..." [(ngModel)]="searchQuery"
                   (ngModelChange)="onSearch$.next($event)" />
          </div>
          <select class="filter-select" [(ngModel)]="filterBank" (change)="search()">
            <option value="">All Banks</option>
            <option *ngFor="let b of banks" [value]="b.id">{{ b.name }} ({{ b.question_count }})</option>
          </select>
          <select class="filter-select" [(ngModel)]="filterType" (change)="search()">
            <option value="">All Types</option>
            <option value="multiple_choice">Multiple Choice</option>
            <option value="multiple_select">Multiple Select</option>
            <option value="true_false">True/False</option>
            <option value="short_answer">Short Answer</option>
            <option value="long_answer">Long Answer</option>
            <option value="essay">Essay</option>
            <option value="fill_in_blank">Fill in Blank</option>
            <option value="matching">Matching</option>
            <option value="ordering">Ordering</option>
            <option value="numeric">Numeric</option>
            <option value="hotspot">Hotspot</option>
          </select>
          <select class="filter-select sm" [(ngModel)]="filterDifficulty" (change)="search()">
            <option value="">All Levels</option>
            <option value="easy">Easy</option>
            <option value="medium">Medium</option>
            <option value="hard">Hard</option>
          </select>
        </div>

        <div class="quick-add-bar" *ngIf="questions.length > 0">
          <label class="select-all-label">
            <input type="checkbox" [checked]="allVisibleSelected" (change)="toggleSelectAll()" /> Select All Visible
          </label>
          <span class="result-count">{{ total }} question{{ total !== 1 ? 's' : '' }} found</span>
          <button class="btn-random" (click)="showRandomOptions = !showRandomOptions" *ngIf="total > 0">
            <tui-icon icon="@tui.shuffle"></tui-icon> Random Pick
          </button>
          <div class="random-options" *ngIf="showRandomOptions">
            <input type="number" class="random-input" [(ngModel)]="randomCount" min="1" [max]="total" />
            <button class="btn-sm-primary" (click)="randomPick()">Pick</button>
          </div>
        </div>

        <div class="picker-body">
          <div class="question-list" *ngIf="!loading && questions.length > 0">
            <div class="q-item" *ngFor="let q of questions"
                 [class.selected]="q.selected" [class.already-added]="isAlreadyAdded(q.id)"
                 (click)="!isAlreadyAdded(q.id) && toggleQuestion(q)">
              <div class="q-checkbox">
                <input type="checkbox" [checked]="q.selected" [disabled]="isAlreadyAdded(q.id)" />
              </div>
              <div class="q-content">
                <div class="q-top-row">
                  <span class="q-type-badge">{{ formatType(q.question_type) }}</span>
                  <span class="q-diff" [class]="'diff-' + q.difficulty">{{ q.difficulty }}</span>
                  <span class="q-pts">{{ q.points }} pts</span>
                  <span class="q-bank-name" *ngIf="q.bank_name">{{ q.bank_name }}</span>
                </div>
                <div class="q-text">{{ truncate(stripHtml(q.question_text), 120) }}</div>
                <div class="q-tags" *ngIf="q.tags && q.tags.length > 0">
                  <span class="q-tag" *ngFor="let tag of q.tags.slice(0, 3)">{{ tag }}</span>
                  <span class="q-tag-more" *ngIf="q.tags.length > 3">+{{ q.tags.length - 3 }}</span>
                </div>
                <div class="q-added-label" *ngIf="isAlreadyAdded(q.id)">
                  <tui-icon icon="@tui.check" style="font-size:12px"></tui-icon> Already added
                </div>
              </div>
            </div>
          </div>

          <div class="loading-state" *ngIf="loading">
            <div class="spinner"></div>
            <p>Searching questions...</p>
          </div>

          <div class="empty-state" *ngIf="!loading && questions.length === 0">
            <tui-icon icon="@tui.search" style="font-size:36px;color:#cbd5e1"></tui-icon>
            <p>No questions found</p>
            <button class="btn-link" (click)="clearFilters()">Clear all filters</button>
          </div>

          <div class="picker-pagination" *ngIf="totalPages > 1">
            <button class="btn-page" (click)="goToPage(currentPage - 1)" [disabled]="currentPage <= 1">
              <tui-icon icon="@tui.chevron-left"></tui-icon>
            </button>
            <span class="page-info">Page {{ currentPage }} of {{ totalPages }}</span>
            <button class="btn-page" (click)="goToPage(currentPage + 1)" [disabled]="currentPage >= totalPages">
              <tui-icon icon="@tui.chevron-right"></tui-icon>
            </button>
          </div>
        </div>

        <div class="picker-footer">
          <div class="selected-info">
            <span *ngIf="selectedCount > 0"><strong>{{ selectedCount }}</strong> selected ({{ selectedPoints }} pts)</span>
            <span *ngIf="selectedCount === 0" class="text-muted">No questions selected</span>
          </div>
          <div class="footer-actions">
            <button class="btn-cancel" (click)="onClose.emit()">Cancel</button>
            <button class="btn-add" [disabled]="selectedCount === 0" (click)="addSelected()">
              <tui-icon icon="@tui.plus"></tui-icon> Add {{ selectedCount }} Question{{ selectedCount !== 1 ? 's' : '' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .picker-overlay {
      position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex;
      align-items: center; justify-content: center; z-index: 200; padding: 20px;
    }
    .picker-modal {
      background: white; border-radius: 12px; width: 100%; max-width: 800px;
      max-height: 90vh; display: flex; flex-direction: column;
      box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    }
    .picker-header {
      display: flex; align-items: center; gap: 12px; padding: 20px 24px;
      border-bottom: 1px solid #e2e8f0;
    }
    .picker-header h3 {
      display: flex; align-items: center; gap: 8px; font-size: 18px;
      font-weight: 600; margin: 0;
    }
    .picker-subtitle { font-size: 14px; color: #6366f1; font-weight: 500; }
    .btn-close {
      margin-left: auto; background: #f1f5f9; border: none; padding: 6px;
      border-radius: 6px; cursor: pointer; display: flex; color: #64748b;
    }
    .btn-close:hover { background: #e2e8f0; }

    .picker-filters {
      display: flex; gap: 8px; padding: 12px 24px; border-bottom: 1px solid #f1f5f9; flex-wrap: wrap;
    }
    .search-box {
      flex: 1; min-width: 200px; display: flex; align-items: center; gap: 8px;
      background: #f1f5f9; border-radius: 6px; padding: 0 12px;
    }
    .search-box input {
      flex: 1; border: none; background: none; padding: 9px 0; font-size: 14px;
      outline: none; color: #0f172a;
    }
    .filter-select {
      padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 6px;
      font-size: 13px; background: white; outline: none; cursor: pointer;
    }
    .filter-select.sm { max-width: 120px; }

    .quick-add-bar {
      display: flex; align-items: center; gap: 12px; padding: 8px 24px;
      background: #fafbfc; border-bottom: 1px solid #f1f5f9; flex-wrap: wrap;
    }
    .select-all-label {
      display: flex; align-items: center; gap: 6px; font-size: 13px; color: #64748b; cursor: pointer;
    }
    .result-count { font-size: 13px; color: #94a3b8; }
    .btn-random {
      margin-left: auto; display: flex; align-items: center; gap: 4px;
      background: none; border: 1px solid #e2e8f0; padding: 4px 12px;
      border-radius: 6px; font-size: 13px; color: #6366f1; cursor: pointer;
    }
    .btn-random:hover { background: #ede9fe; }
    .random-options { display: flex; gap: 6px; align-items: center; }
    .random-input { width: 60px; padding: 4px 8px; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 13px; }
    .btn-sm-primary {
      background: #6366f1; color: white; border: none; padding: 4px 12px;
      border-radius: 4px; font-size: 13px; cursor: pointer;
    }

    .picker-body { flex: 1; overflow-y: auto; min-height: 200px; }

    .q-item {
      display: flex; gap: 12px; padding: 14px 24px; border-bottom: 1px solid #f1f5f9;
      cursor: pointer; transition: background 0.15s;
    }
    .q-item:hover { background: #f8fafc; }
    .q-item.selected { background: #ede9fe; }
    .q-item.already-added { opacity: 0.5; cursor: default; }
    .q-checkbox { padding-top: 2px; flex-shrink: 0; }
    .q-checkbox input { width: 16px; height: 16px; cursor: pointer; accent-color: #6366f1; }
    .q-content { flex: 1; min-width: 0; }
    .q-top-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; flex-wrap: wrap; }
    .q-type-badge {
      font-size: 11px; padding: 2px 8px; border-radius: 4px; font-weight: 600;
      background: #f1f5f9; color: #475569; text-transform: uppercase;
    }
    .q-diff {
      font-size: 11px; padding: 2px 8px; border-radius: 10px; font-weight: 500; text-transform: capitalize;
    }
    .diff-easy { background: #d1fae5; color: #059669; }
    .diff-medium { background: #fef3c7; color: #d97706; }
    .diff-hard { background: #fce7f3; color: #db2777; }
    .q-pts { font-size: 12px; color: #64748b; font-weight: 500; }
    .q-bank-name { font-size: 12px; color: #94a3b8; margin-left: auto; }
    .q-text { font-size: 14px; color: #334155; line-height: 1.4; margin-bottom: 4px; }
    .q-tags { display: flex; gap: 4px; flex-wrap: wrap; }
    .q-tag {
      font-size: 11px; padding: 1px 8px; background: #ede9fe; color: #6366f1;
      border-radius: 10px;
    }
    .q-tag-more { font-size: 11px; color: #94a3b8; padding: 1px 4px; }
    .q-added-label {
      display: flex; align-items: center; gap: 4px; font-size: 12px;
      color: #22c55e; font-weight: 500; margin-top: 4px;
    }

    .loading-state { display: flex; flex-direction: column; align-items: center; padding: 48px; }
    .spinner {
      width: 32px; height: 32px; border: 3px solid #e2e8f0;
      border-top-color: #6366f1; border-radius: 50%; animation: spin 0.8s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }
    .loading-state p { color: #94a3b8; margin-top: 12px; font-size: 14px; }

    .empty-state { text-align: center; padding: 48px; }
    .empty-state p { color: #94a3b8; margin: 12px 0; font-size: 14px; }
    .btn-link { background: none; border: none; color: #6366f1; cursor: pointer; font-size: 13px; }
    .btn-link:hover { text-decoration: underline; }

    .picker-pagination {
      display: flex; align-items: center; justify-content: center; gap: 12px;
      padding: 12px; border-top: 1px solid #f1f5f9;
    }
    .btn-page {
      background: #f1f5f9; border: none; padding: 6px; border-radius: 4px;
      cursor: pointer; display: flex;
    }
    .btn-page:disabled { opacity: 0.3; cursor: default; }
    .page-info { font-size: 13px; color: #64748b; }

    .picker-footer {
      display: flex; align-items: center; justify-content: space-between;
      padding: 16px 24px; border-top: 1px solid #e2e8f0; background: #fafbfc;
      border-radius: 0 0 12px 12px;
    }
    .selected-info { font-size: 14px; color: #334155; }
    .text-muted { color: #94a3b8; }
    .footer-actions { display: flex; gap: 10px; }
    .btn-cancel {
      padding: 10px 20px; border: 1px solid #e2e8f0; border-radius: 8px;
      background: white; cursor: pointer; font-size: 14px;
    }
    .btn-add {
      display: flex; align-items: center; gap: 6px; padding: 10px 20px;
      border: none; border-radius: 8px; background: #6366f1; color: white;
      font-weight: 600; font-size: 14px; cursor: pointer;
    }
    .btn-add:disabled { opacity: 0.5; cursor: default; }
    .btn-add:hover:not(:disabled) { background: #4f46e5; }

    @media (max-width: 640px) {
      .picker-modal { max-height: 95vh; margin: 10px; }
      .picker-filters { flex-direction: column; }
      .search-box { min-width: 100%; }
      .q-bank-name { display: none; }
    }
  `]
})
export class QuestionPickerComponent implements OnInit, OnDestroy {
  @Input() assessmentId!: number;
  @Input() targetSectionId?: number;
  @Input() targetSectionName?: string;
  @Input() existingQuestionIds: number[] = [];
  @Output() onClose = new EventEmitter<void>();
  @Output() onQuestionsSelected = new EventEmitter<{ questions: PickerQuestion[]; sectionId?: number }>();

  private destroy$ = new Subject<void>();
  private apiUrl = GlobalComponent.baseURL + '/user/assessment';
  onSearch$ = new Subject<string>();

  loading = false;
  questions: PickerQuestion[] = [];
  banks: QuestionBank[] = [];
  total = 0;
  currentPage = 1;
  perPage = 20;
  totalPages = 1;

  searchQuery = '';
  filterBank = '';
  filterType = '';
  filterDifficulty = '';

  showRandomOptions = false;
  randomCount = 5;

  // Track selections across pages
  private selectedMap = new Map<number, PickerQuestion>();

  get selectedCount(): number { return this.selectedMap.size; }
  get selectedPoints(): number {
    let pts = 0;
    this.selectedMap.forEach(q => pts += q.points);
    return pts;
  }
  get allVisibleSelected(): boolean {
    const selectable = this.questions.filter(q => !this.isAlreadyAdded(q.id));
    return selectable.length > 0 && selectable.every(q => q.selected);
  }

  constructor(private http: HttpClient) {}

  ngOnInit(): void {
    this.loadBanks();
    this.search();

    this.onSearch$.pipe(
      debounceTime(400), distinctUntilChanged(), takeUntil(this.destroy$)
    ).subscribe(() => {
      this.currentPage = 1;
      this.search();
    });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  loadBanks(): void {
    const fd = new FormData();
    fd.append('action', 'get_question_banks');
    this.http.post<ApiResponse<QuestionBank[]>>(this.apiUrl, fd).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => { if (res.status === 'success') this.banks = res.data || []; }
    });
  }

  search(): void {
    this.loading = true;
    const fd = new FormData();
    fd.append('action', 'search_questions_for_assessment');
    fd.append('assessment_id', this.assessmentId.toString());
    fd.append('page', this.currentPage.toString());
    fd.append('per_page', this.perPage.toString());
    if (this.searchQuery) fd.append('search', this.searchQuery);
    if (this.filterBank) fd.append('bank_id', this.filterBank);
    if (this.filterType) fd.append('question_type', this.filterType);
    if (this.filterDifficulty) fd.append('difficulty', this.filterDifficulty);

    this.http.post<ApiResponse<PickerQuestion[]>>(this.apiUrl, fd).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        if (res.status === 'success') {
          this.questions = (res.data || []).map(q => ({
            ...q,
            selected: this.selectedMap.has(q.id)
          }));
          this.total = res.total || this.questions.length;
          this.totalPages = Math.ceil(this.total / this.perPage);
        }
        this.loading = false;
      },
      error: () => { this.loading = false; }
    });
  }

  toggleQuestion(q: PickerQuestion): void {
    q.selected = !q.selected;
    if (q.selected) {
      this.selectedMap.set(q.id, q);
    } else {
      this.selectedMap.delete(q.id);
    }
  }

  toggleSelectAll(): void {
    const selectable = this.questions.filter(q => !this.isAlreadyAdded(q.id));
    const allSelected = this.allVisibleSelected;
    selectable.forEach(q => {
      q.selected = !allSelected;
      if (q.selected) this.selectedMap.set(q.id, q); else this.selectedMap.delete(q.id);
    });
  }

  randomPick(): void {
    // Clear current selections
    this.selectedMap.clear();
    this.questions.forEach(q => q.selected = false);

    const available = this.questions.filter(q => !this.isAlreadyAdded(q.id));
    const count = Math.min(this.randomCount, available.length);
    const shuffled = [...available].sort(() => Math.random() - 0.5);

    for (let i = 0; i < count; i++) {
      shuffled[i].selected = true;
      this.selectedMap.set(shuffled[i].id, shuffled[i]);
    }
    this.showRandomOptions = false;
  }

  isAlreadyAdded(questionId: number): boolean {
    return this.existingQuestionIds.includes(questionId);
  }

  clearFilters(): void {
    this.searchQuery = '';
    this.filterBank = '';
    this.filterType = '';
    this.filterDifficulty = '';
    this.currentPage = 1;
    this.search();
  }

  goToPage(page: number): void {
    if (page < 1 || page > this.totalPages) return;
    this.currentPage = page;
    this.search();
  }

  addSelected(): void {
    const selected = Array.from(this.selectedMap.values());
    this.onQuestionsSelected.emit({
      questions: selected,
      sectionId: this.targetSectionId
    });
    this.onClose.emit();
  }

  formatType(type: string): string {
    return type.replace(/_/g, ' ');
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
