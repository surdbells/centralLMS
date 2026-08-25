import { Component, OnInit, OnDestroy, inject, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { Subject, takeUntil, debounceTime } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { GlobalComponent } from '../../../global-component';

// ==========================================
// INTERFACES
// ==========================================

interface ApiResponse<T> {
  code: number;
  status: string;
  data: T;
  message: string;
}

interface QuickFeedback {
  feedback_id: number;
  title: string;
  content: string;
  category: string;
  comment_type: string;
  usage_count: number;
  is_system: boolean;
  created_at: string;
}

interface FeedbackCategory {
  value: string;
  label: string;
  icon: string;
  color: string;
}

@Component({
  selector: 'app-feedback-panel',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon],
  template: `
    <div class="feedback-panel-container">
      <!-- ==========================================
           STANDALONE MODE (full page)
           ========================================== -->
      <ng-container *ngIf="mode === 'standalone'">
        <!-- Header -->
        <div class="page-header">
          <div class="header-left">
            <h1 class="page-title">
              <tui-icon icon="@tui.zap"></tui-icon>
              Quick Feedback Library
            </h1>
            <p class="page-subtitle">Manage reusable feedback comments for faster grading</p>
          </div>
          <div class="header-actions">
            <button class="btn btn-primary" (click)="showCreateForm = true">
              <tui-icon icon="@tui.plus"></tui-icon> New Feedback
            </button>
          </div>
        </div>

        <!-- Stats -->
        <div class="stats-row">
          <div class="stat-card" *ngFor="let cat of categories"
               [class.active]="filterCategory === cat.value"
               (click)="filterCategory = filterCategory === cat.value ? '' : cat.value; applyFilters()">
            <tui-icon [icon]="cat.icon" [style.color]="cat.color"></tui-icon>
            <span class="stat-value">{{ getCountByCategory(cat.value) }}</span>
            <span class="stat-label">{{ cat.label }}</span>
          </div>
        </div>

        <!-- Search & Filter -->
        <div class="filters-bar">
          <div class="search-box">
            <tui-icon icon="@tui.search"></tui-icon>
            <input type="text" class="form-control" placeholder="Search feedback..."
                   [(ngModel)]="searchTerm" (ngModelChange)="onSearch()">
          </div>
          <select class="form-select form-select-sm" [(ngModel)]="filterCommentType" (change)="applyFilters()">
            <option value="">All Types</option>
            <option value="general">General</option>
            <option value="praise">Praise</option>
            <option value="correction">Correction</option>
            <option value="suggestion">Suggestion</option>
          </select>
          <select class="form-select form-select-sm" [(ngModel)]="sortBy" (change)="applyFilters()">
            <option value="most_used">Most Used</option>
            <option value="newest">Newest</option>
            <option value="alpha">A-Z</option>
          </select>
        </div>
      </ng-container>

      <!-- ==========================================
           FEEDBACK LIST
           ========================================== -->
      <div class="feedback-list" *ngIf="!loading">
        <div class="feedback-item" *ngFor="let f of filteredFeedback"
             (click)="mode === 'picker' ? selectFeedback(f) : null"
             [class.selectable]="mode === 'picker'">
          <div class="feedback-left">
            <span class="feedback-type-icon" [ngClass]="'type-' + f.comment_type">
              <tui-icon [icon]="getTypeIcon(f.comment_type)"></tui-icon>
            </span>
            <div class="feedback-content">
              <div class="feedback-title">
                {{ f.title }}
                <span class="badge bg-secondary-subtle text-secondary ms-1" *ngIf="f.is_system">System</span>
              </div>
              <div class="feedback-text">{{ f.content }}</div>
              <div class="feedback-meta">
                <span class="badge" [ngClass]="getCategoryBadgeClass(f.category)">{{ f.category }}</span>
                <span class="usage-count"><tui-icon icon="@tui.bar-chart-2"></tui-icon> {{ f.usage_count }} uses</span>
              </div>
            </div>
          </div>
          <div class="feedback-actions" *ngIf="mode === 'standalone'" (click)="$event.stopPropagation()">
            <button class="btn btn-sm btn-primary" (click)="editFeedback(f)" title="Edit">
              <tui-icon icon="@tui.edit"></tui-icon>
            </button>
            <button class="btn btn-sm btn-outline-danger" (click)="confirmDelete(f)" title="Delete"
                    [disabled]="f.is_system">
              <tui-icon icon="@tui.trash-2"></tui-icon>
            </button>
          </div>
        </div>

        <!-- Empty -->
        <div class="empty-state" *ngIf="filteredFeedback.length === 0">
          <tui-icon icon="@tui.message-circle"></tui-icon>
          <h5>{{ searchTerm || filterCategory ? 'No matching feedback' : 'No feedback items yet' }}</h5>
          <p class="text-muted">{{ searchTerm || filterCategory ? 'Try different filters' : 'Create your first quick feedback' }}</p>
        </div>
      </div>

      <!-- Loading -->
      <div class="loading-state" *ngIf="loading">
        <div class="spinner-border spinner-border-sm text-primary"></div>
        <span>Loading...</span>
      </div>

      <!-- ==========================================
           CREATE / EDIT MODAL
           ========================================== -->
      <div class="modal-overlay" *ngIf="showCreateForm" (click)="closeForm()">
        <div class="modal-dialog" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h5 class="modal-title">
              <tui-icon icon="@tui.plus-circle"></tui-icon>
              {{ editTarget ? 'Edit Feedback' : 'New Quick Feedback' }}
            </h5>
            <button class="btn-close" (click)="closeForm()"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">Title <span class="text-danger">*</span></label>
              <input type="text" class="form-control" [(ngModel)]="formData.title"
                     placeholder="e.g., Good analysis">
            </div>
            <div class="mb-3">
              <label class="form-label">Content <span class="text-danger">*</span></label>
              <textarea class="form-control" rows="3" [(ngModel)]="formData.content"
                        placeholder="The feedback text to insert..."></textarea>
            </div>
            <div class="row">
              <div class="col-md-6 mb-3">
                <label class="form-label">Category</label>
                <select class="form-select" [(ngModel)]="formData.category">
                  <option *ngFor="let cat of categories" [value]="cat.value">{{ cat.label }}</option>
                </select>
              </div>
              <div class="col-md-6 mb-3">
                <label class="form-label">Comment Type</label>
                <select class="form-select" [(ngModel)]="formData.comment_type">
                  <option value="general">General</option>
                  <option value="praise">Praise</option>
                  <option value="correction">Correction</option>
                  <option value="suggestion">Suggestion</option>
                  <option value="highlight">Highlight</option>
                </select>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" (click)="closeForm()">Cancel</button>
            <button class="btn btn-primary" (click)="saveFeedback()"
                    [disabled]="!formData.title.trim() || !formData.content.trim()">
              <tui-icon icon="@tui.check"></tui-icon> {{ editTarget ? 'Update' : 'Create' }}
            </button>
          </div>
        </div>
      </div>

      <!-- Delete Confirm -->
      <div class="modal-overlay" *ngIf="showDeleteModal" (click)="showDeleteModal = false">
        <div class="modal-dialog modal-sm" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h5 class="modal-title"><tui-icon icon="@tui.alert-triangle"></tui-icon> Delete Feedback</h5>
            <button class="btn-close" (click)="showDeleteModal = false"></button>
          </div>
          <div class="modal-body">
            <p>Delete <strong>{{ deleteTarget?.title }}</strong>?</p>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" (click)="showDeleteModal = false">Cancel</button>
            <button class="btn btn-danger" (click)="executeDelete()">Delete</button>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .feedback-panel-container { padding: 1.5rem; }

    .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; }
    .page-title { font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; margin: 0; }
    .page-subtitle { color: #6c757d; font-size: 0.875rem; margin: 0.25rem 0 0; }

    .stats-row { display: flex; gap: 0.75rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
    .stat-card {
      background: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 0.625rem 1rem;
      display: flex; align-items: center; gap: 0.5rem; cursor: pointer; transition: all 0.2s; flex: 1; min-width: 120px;
    }
    .stat-card:hover { border-color: #6c757d; }
    .stat-card.active { border-color: #0d6efd; background: #e8f0fe; }
    .stat-value { font-weight: 700; font-size: 1.125rem; }
    .stat-label { font-size: 0.75rem; color: #6c757d; }

    .filters-bar { display: flex; gap: 0.75rem; margin-bottom: 1rem; flex-wrap: wrap; align-items: center; }
    .search-box { position: relative; flex: 1; min-width: 200px; }
    .search-box tui-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #adb5bd; font-size: 0.875rem; }
    .search-box input { padding-left: 2rem; font-size: 0.875rem; }
    .form-select { width: auto; min-width: 120px; }

    /* Feedback List */
    .feedback-list { display: flex; flex-direction: column; gap: 0.5rem; }
    .feedback-item {
      display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem;
      padding: 0.75rem 1rem; background: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem;
      transition: all 0.2s;
    }
    .feedback-item:hover { border-color: #adb5bd; }
    .feedback-item.selectable { cursor: pointer; }
    .feedback-item.selectable:hover { border-color: #0d6efd; background: #f8f9fa; }
    .feedback-left { display: flex; gap: 0.75rem; flex: 1; }
    .feedback-type-icon { width: 36px; height: 36px; border-radius: 0.375rem; display: flex; align-items: center;
      justify-content: center; flex-shrink: 0; }
    .type-general { background: #e2e3e5; color: #6c757d; }
    .type-praise { background: #d4edda; color: #28a745; }
    .type-correction { background: #f8d7da; color: #dc3545; }
    .type-suggestion { background: #fff3cd; color: #856404; }
    .type-highlight { background: #d1ecf1; color: #0c5460; }
    .feedback-title { font-weight: 600; font-size: 0.875rem; margin-bottom: 0.125rem; }
    .feedback-text { font-size: 0.8125rem; color: #495057; line-height: 1.4; margin-bottom: 0.375rem; }
    .feedback-meta { display: flex; gap: 0.5rem; align-items: center; }
    .feedback-meta .badge { font-size: 0.6875rem; }
    .usage-count { font-size: 0.6875rem; color: #adb5bd; display: flex; align-items: center; gap: 0.25rem; }
    .feedback-actions { display: flex; gap: 0.25rem; flex-shrink: 0; }

    .empty-state { text-align: center; padding: 2rem; }
    .empty-state tui-icon { font-size: 2rem; color: #adb5bd; margin-bottom: 0.5rem; }
    .loading-state { text-align: center; padding: 1.5rem; display: flex; align-items: center; justify-content: center; gap: 0.5rem; }

    /* Modals */
    .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5);
      z-index: 1050; display: flex; align-items: center; justify-content: center; }
    .modal-dialog { background: #fff; border-radius: 0.5rem; max-width: 550px; width: 95%; }
    .modal-dialog.modal-sm { max-width: 380px; }
    .modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid #dee2e6; display: flex; justify-content: space-between; align-items: center; }
    .modal-title { font-size: 1.125rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; margin: 0; }
    .modal-body { padding: 1.25rem; }
    .modal-footer { padding: 0.75rem 1.25rem; border-top: 1px solid #dee2e6; display: flex; justify-content: flex-end; gap: 0.5rem; }
  `]
})
export class FeedbackPanelComponent implements OnInit, OnDestroy {
  private http = inject(HttpClient);
  private destroy$ = new Subject<void>();
  private searchSubject = new Subject<string>();

  @Input() mode: 'standalone' | 'picker' = 'standalone';
  @Output() feedbackSelected = new EventEmitter<QuickFeedback>();

  // Data
  feedbackList: QuickFeedback[] = [];
  filteredFeedback: QuickFeedback[] = [];

  // State
  loading = false;
  searchTerm = '';
  filterCategory = '';
  filterCommentType = '';
  sortBy = 'most_used';

  // Forms
  showCreateForm = false;
  showDeleteModal = false;
  editTarget: QuickFeedback | null = null;
  deleteTarget: QuickFeedback | null = null;
  formData = { title: '', content: '', category: 'general', comment_type: 'general' };

  categories: FeedbackCategory[] = [
    { value: 'content', label: 'Content', icon: '@tui.file-text', color: '#0d6efd' },
    { value: 'grammar', label: 'Grammar', icon: '@tui.type', color: '#6f42c1' },
    { value: 'structure', label: 'Structure', icon: '@tui.layout', color: '#198754' },
    { value: 'analysis', label: 'Analysis', icon: '@tui.trending-up', color: '#fd7e14' },
    { value: 'general', label: 'General', icon: '@tui.message-square', color: '#6c757d' },
  ];

  ngOnInit(): void {
    this.searchSubject.pipe(debounceTime(300), takeUntil(this.destroy$)).subscribe(() => this.applyFilters());
    this.loadFeedback();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // DATA
  // ==========================================

  loadFeedback(): void {
    this.loading = true;
    const fd = new FormData();
    fd.append('action', 'get_quick_feedback');

    this.http.post<ApiResponse<QuickFeedback[]>>(
      `${GlobalComponent.baseURL}/user/grading`, fd
    ).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        if (res.code === 1) {
          this.feedbackList = res.data || [];
          this.applyFilters();
        }
        this.loading = false;
      },
      error: () => { this.loading = false; }
    });
  }

  // ==========================================
  // FILTERS
  // ==========================================

  onSearch(): void { this.searchSubject.next(this.searchTerm); }

  applyFilters(): void {
    let result = [...this.feedbackList];

    if (this.searchTerm) {
      const term = this.searchTerm.toLowerCase();
      result = result.filter(f =>
        f.title.toLowerCase().includes(term) || f.content.toLowerCase().includes(term)
      );
    }

    if (this.filterCategory) {
      result = result.filter(f => f.category === this.filterCategory);
    }

    if (this.filterCommentType) {
      result = result.filter(f => f.comment_type === this.filterCommentType);
    }

    switch (this.sortBy) {
      case 'most_used': result.sort((a, b) => b.usage_count - a.usage_count); break;
      case 'newest': result.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime()); break;
      case 'alpha': result.sort((a, b) => a.title.localeCompare(b.title)); break;
    }

    this.filteredFeedback = result;
  }

  getCountByCategory(category: string): number {
    return this.feedbackList.filter(f => f.category === category).length;
  }

  // ==========================================
  // CRUD
  // ==========================================

  editFeedback(f: QuickFeedback): void {
    this.editTarget = f;
    this.formData = { title: f.title, content: f.content, category: f.category, comment_type: f.comment_type };
    this.showCreateForm = true;
  }

  saveFeedback(): void {
    const fd = new FormData();
    fd.append('action', this.editTarget ? 'update_quick_feedback' : 'create_quick_feedback');
    if (this.editTarget) fd.append('feedback_id', this.editTarget.feedback_id.toString());
    fd.append('title', this.formData.title);
    fd.append('content', this.formData.content);
    fd.append('category', this.formData.category);
    fd.append('comment_type', this.formData.comment_type);

    this.http.post<ApiResponse<any>>(
      `${GlobalComponent.baseURL}/user/grading`, fd
    ).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        if (res.code === 1) {
          this.closeForm();
          this.loadFeedback();
        }
      }
    });
  }

  confirmDelete(f: QuickFeedback): void {
    this.deleteTarget = f;
    this.showDeleteModal = true;
  }

  executeDelete(): void {
    if (!this.deleteTarget) return;
    const fd = new FormData();
    fd.append('action', 'delete_quick_feedback');
    fd.append('feedback_id', this.deleteTarget.feedback_id.toString());

    this.http.post<ApiResponse<any>>(
      `${GlobalComponent.baseURL}/user/grading`, fd
    ).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        if (res.code === 1) {
          this.showDeleteModal = false;
          this.deleteTarget = null;
          this.loadFeedback();
        }
      }
    });
  }

  closeForm(): void {
    this.showCreateForm = false;
    this.editTarget = null;
    this.formData = { title: '', content: '', category: 'general', comment_type: 'general' };
  }

  // ==========================================
  // PICKER MODE
  // ==========================================

  selectFeedback(f: QuickFeedback): void {
    if (this.mode === 'picker') {
      this.feedbackSelected.emit(f);
    }
  }

  // ==========================================
  // HELPERS
  // ==========================================

  getTypeIcon(type: string): string {
    const icons: Record<string, string> = {
      general: '@tui.message-square', praise: '@tui.thumbs-up', correction: '@tui.x-circle',
      suggestion: '@tui.lightbulb', highlight: '@tui.bookmark'
    };
    return icons[type] || '@tui.message-square';
  }

  getCategoryBadgeClass(category: string): string {
    const classes: Record<string, string> = {
      content: 'bg-primary-subtle text-primary', grammar: 'bg-purple-subtle text-purple',
      structure: 'bg-success-subtle text-success', analysis: 'bg-warning-subtle text-warning',
      general: 'bg-secondary-subtle text-secondary'
    };
    return classes[category] || 'bg-secondary-subtle text-secondary';
  }
}
