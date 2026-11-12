// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 9
// Admin: Question Bank - Question List Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, forkJoin, of, takeUntil, debounceTime, distinctUntilChanged } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { TuiIcon } from '@taiga-ui/core';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { AssessmentsService } from '../../services/assessments.service';
import { ConfirmDialogService } from '../../shared/components/confirmation-dialog/confirmation-dialog.component';

// ==========================================
// INTERFACES
// ==========================================

interface Question {
  id: string;
  question_text: string;
  question_type: 'multiple_choice' | 'multiple_response' | 'true_false' | 'short_answer' | 'long_answer' | 'numeric' | 'fill_blank' | 'matching' | 'ordering' | 'file_upload' | 'hotspot';
  difficulty: 'easy' | 'medium' | 'hard';
  points: number;
  bank_id: string;
  bank_name: string;
  category_id: string;
  category_name: string;
  tags: string[];
  status: 'active' | 'inactive' | 'archived';
  explanation: string;
  time_limit_seconds: number | null;
  usage_count: number;
  created_by: string;
  created_by_name: string;
  created_at: string;
  updated_at: string;
  options?: any[];
  is_selected?: boolean;
}

interface QuestionBank {
  id: string;
  name: string;
  description: string;
  question_count: number;
  status: 'active' | 'inactive';
}

interface BankStats {
  total_questions: number;
  active_questions: number;
  total_banks: number;
  by_type: { type: string; count: number }[];
  by_difficulty: { difficulty: string; count: number }[];
}

interface FilterState {
  search: string;
  bank_id: string;
  question_type: string;
  difficulty: string;
  status: string;
  tag: string;
  sort_by: string;
  sort_dir: 'asc' | 'desc';
}

@Component({
  selector: 'app-question-list',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent, SkeletonComponent],
  template: `
    <app-admintop></app-admintop>

    <main class="main">
    <div class="ui-page question-list-container">
      <!-- Header -->
      <header class="ui-page-header">
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-database"></i></div>
        <div class="ui-page-header__titles">
          <h1>Question Bank</h1>
          <p>Manage questions across all banks and categories</p>
        </div>
        <div class="ui-page-header__actions">
          <button class="btn btn-outline-secondary" (click)="showImport = true">
            <tui-icon icon="@tui.upload"></tui-icon> Import
          </button>
          <button class="btn btn-primary" (click)="createQuestion()">
            <tui-icon icon="@tui.plus"></tui-icon> New Question
          </button>
        </div>
      </header>

      <!-- Stats Cards -->
      <div class="ui-kpi-grid ui-kpi-grid--4 stats-row">
        <button type="button" class="ui-kpi ui-kpi--brand" (click)="clearFilters()" [class.is-active]="!hasActiveFilters()">
          <span class="ui-kpi__label"><i class="bi bi-layers"></i> Total Questions</span>
          <span class="ui-kpi__value">{{ stats.total_questions }}</span>
        </button>
        <button type="button" class="ui-kpi ui-kpi--success" (click)="setFilter('status','active')" [class.is-active]="filters.status === 'active'">
          <span class="ui-kpi__label"><i class="bi bi-check-circle"></i> Active</span>
          <span class="ui-kpi__value">{{ stats.active_questions }}</span>
        </button>
        <button type="button" class="ui-kpi ui-kpi--warning" (click)="showBankManager = true">
          <span class="ui-kpi__label"><i class="bi bi-folder"></i> Banks</span>
          <span class="ui-kpi__value">{{ stats.total_banks }}</span>
          <span class="ui-kpi__sub">Manage banks</span>
        </button>
        <div class="ui-kpi ui-kpi--info">
          <span class="ui-kpi__label"><i class="bi bi-grid"></i> Question Types</span>
          <span class="ui-kpi__value">{{ stats.by_type?.length || 0 }}</span>
        </div>
      </div>

      <!-- Difficulty Breakdown Bar -->
      <div class="difficulty-bar" *ngIf="stats.by_difficulty?.length">
        <div class="diff-segment diff-easy"
             [style.width.%]="getDiffPercent('easy')"
             (click)="setFilter('difficulty','easy')"
             [title]="'Easy: ' + getDiffCount('easy')">
          <span *ngIf="getDiffPercent('easy') > 15">Easy {{ getDiffCount('easy') }}</span>
        </div>
        <div class="diff-segment diff-medium"
             [style.width.%]="getDiffPercent('medium')"
             (click)="setFilter('difficulty','medium')"
             [title]="'Medium: ' + getDiffCount('medium')">
          <span *ngIf="getDiffPercent('medium') > 15">Medium {{ getDiffCount('medium') }}</span>
        </div>
        <div class="diff-segment diff-hard"
             [style.width.%]="getDiffPercent('hard')"
             (click)="setFilter('difficulty','hard')"
             [title]="'Hard: ' + getDiffCount('hard')">
          <span *ngIf="getDiffPercent('hard') > 15">Hard {{ getDiffCount('hard') }}</span>
        </div>
      </div>

      <div class="alert alert-info d-flex align-items-center gap-2" *ngIf="notice">
        <tui-icon icon="@tui.info"></tui-icon>
        <span>{{ notice }}</span>
      </div>

      <section class="ui-section ui-section--flush questions-section">
        <div class="ui-section__head">
          <div>
            <h2 class="ui-section__title">Questions</h2>
            <p class="ui-section__subtitle">{{ totalItems }} in the current view</p>
          </div>
        </div>

        <!-- Filters Bar -->
        <div class="ui-toolbar filters-bar">
          <div class="ui-search search-box">
            <i class="bi bi-search"></i>
            <input type="search" placeholder="Search questions..." [(ngModel)]="filters.search"
                   (ngModelChange)="onSearch$.next($event)" />
            <button type="button" class="search-clear" *ngIf="filters.search" (click)="filters.search=''; loadQuestions()" aria-label="Clear search">
              <tui-icon icon="@tui.x" style="font-size:14px"></tui-icon>
            </button>
          </div>
          <div class="ui-filters">
            <select class="form-select filter-select" [(ngModel)]="filters.bank_id" (change)="loadQuestions()">
              <option value="">All Banks</option>
              <option *ngFor="let b of banks" [value]="b.id">{{ b.name }} ({{ b.question_count }})</option>
            </select>
            <select class="form-select filter-select" [(ngModel)]="filters.question_type" (change)="loadQuestions()">
              <option value="">All Types</option>
              <option *ngFor="let t of questionTypes" [value]="t.value">{{ t.label }}</option>
            </select>
            <select class="form-select filter-select" [(ngModel)]="filters.difficulty" (change)="loadQuestions()">
              <option value="">All Difficulty</option>
              <option value="easy">Easy</option>
              <option value="medium">Medium</option>
              <option value="hard">Hard</option>
            </select>
            <select class="form-select filter-select" [(ngModel)]="filters.status" (change)="loadQuestions()">
              <option value="">All Status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="archived">Archived</option>
            </select>
            <button class="btn btn-sm btn-outline-secondary" *ngIf="hasActiveFilters()" (click)="clearFilters()">
              <tui-icon icon="@tui.x" style="font-size:14px"></tui-icon> Clear
            </button>
          </div>
        </div>

        <!-- Tags Filter -->
        <div class="ui-filters tags-filter" *ngIf="allTags.length">
          <span class="tags-label">Tags:</span>
          <button *ngFor="let tag of allTags.slice(0, showAllTags ? allTags.length : 10)"
                  class="ui-filter tag-chip" [class.is-active]="filters.tag === tag"
                  (click)="toggleTag(tag)">{{ tag }}</button>
          <button class="btn-show-more" *ngIf="allTags.length > 10"
                  (click)="showAllTags = !showAllTags">
            {{ showAllTags ? 'Show less' : '+' + (allTags.length - 10) + ' more' }}
          </button>
        </div>

        <!-- Bulk Actions Bar -->
        <div class="bulk-bar" *ngIf="selectedCount > 0">
          <span class="ui-pill ui-pill--brand bulk-info">{{ selectedCount }} question{{ selectedCount > 1 ? 's' : '' }} selected</span>
          <div class="bulk-actions">
            <button class="btn btn-sm btn-outline-secondary" (click)="bulkAction('activate')">
              <tui-icon icon="@tui.check"></tui-icon> Activate
            </button>
            <button class="btn btn-sm btn-outline-secondary" (click)="bulkAction('deactivate')">
              <tui-icon icon="@tui.pause"></tui-icon> Deactivate
            </button>
            <button class="btn btn-sm btn-outline-secondary" (click)="bulkMoveModal = true">
              <tui-icon icon="@tui.folder"></tui-icon> Move to Bank
            </button>
            <button class="btn btn-sm btn-outline-secondary" (click)="bulkTagModal = true">
              <tui-icon icon="@tui.tag"></tui-icon> Add Tags
            </button>
            <button class="btn btn-sm btn-outline-secondary" (click)="exportSelected()">
              <tui-icon icon="@tui.download"></tui-icon> Export
            </button>
            <button class="btn btn-sm btn-danger" (click)="bulkAction('delete')">
              <tui-icon icon="@tui.trash-2"></tui-icon> Delete
            </button>
          </div>
          <button class="btn btn-sm btn-ghost" (click)="clearSelection()">Deselect All</button>
        </div>

        <!-- Loading -->
        <div class="table-skeleton" *ngIf="loading">
          <app-skeleton variant="table" [rows]="8" [cols]="10"></app-skeleton>
        </div>

        <!-- Empty State -->
        <div class="ui-state empty-state" *ngIf="!loading && questions.length === 0">
          <span class="ui-state__icon"><i class="bi bi-inbox"></i></span>
          <h3>No questions found</h3>
          <p *ngIf="hasActiveFilters()">Try adjusting your filters or search terms</p>
          <p *ngIf="!hasActiveFilters()">Create your first question to get started</p>
          <button class="btn btn-primary" (click)="createQuestion()">
            <tui-icon icon="@tui.plus"></tui-icon> Create Question
          </button>
        </div>

        <!-- Questions Table -->
        <div class="table-container" *ngIf="!loading && questions.length > 0">
          <table class="table data-table">
            <thead>
              <tr>
                <th class="col-check">
                  <input type="checkbox" class="form-check-input" [checked]="allSelected" (change)="toggleSelectAll()" />
                </th>
                <th class="col-question sortable" (click)="toggleSort('question_text')">
                  Question <tui-icon *ngIf="filters.sort_by === 'question_text'"
                    [icon]="filters.sort_dir === 'asc' ? '@tui.chevron-up' : '@tui.chevron-down'"
                    style="font-size:14px"></tui-icon>
                </th>
                <th class="col-type">Type</th>
                <th class="col-diff">Difficulty</th>
                <th class="col-points sortable" (click)="toggleSort('points')">Points</th>
                <th class="col-bank">Bank</th>
                <th class="col-tags">Tags</th>
                <th class="col-usage sortable" (click)="toggleSort('usage_count')">Usage</th>
                <th class="col-status">Status</th>
                <th class="col-actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr *ngFor="let q of questions; trackBy: trackById" [class.selected]="q.is_selected">
                <td class="col-check">
                  <input type="checkbox" class="form-check-input" [checked]="q.is_selected" (change)="toggleSelect(q)" />
                </td>
                <td class="col-question">
                  <div class="q-text" [innerHTML]="truncate(q.question_text, 120)"></div>
                  <div class="q-meta">
                    <span class="q-id">#{{ q.id }}</span>
                    <span class="q-date">{{ formatDate(q.created_at) }}</span>
                    <span class="q-author">by {{ q.created_by_name }}</span>
                  </div>
                </td>
                <td class="col-type">
                  <span class="ui-pill ui-pill--neutral type-badge" [attr.data-type]="q.question_type">
                    {{ getTypeLabel(q.question_type) }}
                  </span>
                </td>
                <td class="col-diff">
                  <span class="ui-pill diff-badge" [attr.data-diff]="q.difficulty">{{ q.difficulty }}</span>
                </td>
                <td class="col-points">{{ q.points }}</td>
                <td class="col-bank">
                  <span class="bank-name" (click)="setFilter('bank_id', q.bank_id.toString())">{{ q.bank_name }}</span>
                </td>
                <td class="col-tags">
                  <span *ngFor="let tag of q.tags?.slice(0,3)" class="tag-sm" (click)="toggleTag(tag)">{{ tag }}</span>
                  <span *ngIf="(q?.tags?.length ?? 0) > 3" class="tag-more">
                    +{{ (q?.tags?.length ?? 0) - 3 }}
                  </span>
                </td>
                <td class="col-usage">{{ q.usage_count }}</td>
                <td class="col-status">
                  <span class="status-dot" [attr.data-status]="q.status" [title]="q.status"></span>
                </td>
                <td class="col-actions">
                  <div class="action-group">
                    <button class="btn-icon" title="Preview" (click)="previewQuestion(q)">
                      <tui-icon icon="@tui.eye" style="font-size:16px"></tui-icon>
                    </button>
                    <button class="btn-icon" title="Edit" (click)="editQuestion(q)">
                      <tui-icon icon="@tui.edit" style="font-size:16px"></tui-icon>
                    </button>
                    <button class="btn-icon" title="Duplicate" (click)="duplicateQuestion(q)">
                      <tui-icon icon="@tui.copy" style="font-size:16px"></tui-icon>
                    </button>
                    <button class="btn-icon btn-icon-danger" title="Delete" (click)="confirmDelete(q)">
                      <tui-icon icon="@tui.trash-2" style="font-size:16px"></tui-icon>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="pagination-bar" *ngIf="totalPages > 1">
          <span class="page-info">
            Showing {{ (currentPage - 1) * pageSize + 1 }}-{{ Math.min(currentPage * pageSize, totalItems) }} of {{ totalItems }}
          </span>
          <div class="page-controls">
            <button class="btn-page" [disabled]="currentPage === 1" (click)="goToPage(1)">
              <tui-icon icon="@tui.chevrons-left" style="font-size:14px"></tui-icon>
            </button>
            <button class="btn-page" [disabled]="currentPage === 1" (click)="goToPage(currentPage - 1)">
              <tui-icon icon="@tui.chevron-left" style="font-size:14px"></tui-icon>
            </button>
            <button *ngFor="let p of getPageNumbers()" class="btn-page" [class.active]="p === currentPage"
                    (click)="goToPage(p)">{{ p }}</button>
            <button class="btn-page" [disabled]="currentPage === totalPages" (click)="goToPage(currentPage + 1)">
              <tui-icon icon="@tui.chevron-right" style="font-size:14px"></tui-icon>
            </button>
            <button class="btn-page" [disabled]="currentPage === totalPages" (click)="goToPage(totalPages)">
              <tui-icon icon="@tui.chevrons-right" style="font-size:14px"></tui-icon>
            </button>
          </div>
          <select class="form-select form-select-sm page-size-select" [(ngModel)]="pageSize" (change)="loadQuestions()">
            <option [value]="20">20 per page</option>
            <option [value]="50">50 per page</option>
            <option [value]="100">100 per page</option>
          </select>
        </div>
      </section>

      <!-- Bank Manager Modal -->
      <div class="modal-overlay" *ngIf="showBankManager" (click)="showBankManager = false">
        <div class="modal-content modal-lg" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h3><tui-icon icon="@tui.folder"></tui-icon> Manage Question Banks</h3>
            <button class="btn-close" (click)="showBankManager = false"><tui-icon icon="@tui.x"></tui-icon></button>
          </div>
          <div class="modal-body">
            <div class="bank-form-row">
              <input type="text" class="form-control" placeholder="New bank name..." [(ngModel)]="newBankName" />
              <input type="text" class="form-control" placeholder="Description (optional)" [(ngModel)]="newBankDesc" />
              <button class="btn btn-primary btn-sm" (click)="createBank()" [disabled]="!newBankName.trim()">
                <tui-icon icon="@tui.plus"></tui-icon> Add
              </button>
            </div>
            <div class="bank-list">
              <div *ngFor="let bank of banks" class="bank-item">
                <div class="bank-info">
                  <span class="bank-title">{{ bank.name }}</span>
                  <span class="bank-desc">{{ bank.description }}</span>
                  <span class="bank-count">{{ bank.question_count }} questions</span>
                </div>
                <div class="bank-actions">
                  <button class="btn-icon" (click)="editBank(bank)"><tui-icon icon="@tui.edit" style="font-size:14px"></tui-icon></button>
                  <button class="btn-icon btn-icon-danger" (click)="deleteBank(bank)" [disabled]="bank.question_count > 0">
                    <tui-icon icon="@tui.trash-2" style="font-size:14px"></tui-icon>
                  </button>
                </div>
              </div>
              <div class="empty-msg" *ngIf="banks.length === 0">No question banks created yet.</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Bulk Move Modal -->
      <div class="modal-overlay" *ngIf="bulkMoveModal" (click)="bulkMoveModal = false">
        <div class="modal-content modal-sm" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h3>Move {{ selectedCount }} Questions</h3>
            <button class="btn-close" (click)="bulkMoveModal = false"><tui-icon icon="@tui.x"></tui-icon></button>
          </div>
          <div class="modal-body">
            <label class="form-label">Select destination bank:</label>
            <select class="form-control" [(ngModel)]="bulkMoveTargetBank">
              <option value="">-- Select Bank --</option>
              <option *ngFor="let b of banks" [value]="b.id">{{ b.name }}</option>
            </select>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline-secondary" (click)="bulkMoveModal = false">Cancel</button>
            <button class="btn btn-primary" [disabled]="!bulkMoveTargetBank" (click)="executeBulkMove()">Move</button>
          </div>
        </div>
      </div>

      <!-- Bulk Tag Modal -->
      <div class="modal-overlay" *ngIf="bulkTagModal" (click)="bulkTagModal = false">
        <div class="modal-content modal-sm" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h3>Add Tags to {{ selectedCount }} Questions</h3>
            <button class="btn-close" (click)="bulkTagModal = false"><tui-icon icon="@tui.x"></tui-icon></button>
          </div>
          <div class="modal-body">
            <label class="form-label">Enter tags (comma-separated):</label>
            <input type="text" class="form-control" [(ngModel)]="bulkTagInput"
                   placeholder="e.g. compliance, safety, hr" />
            <div class="existing-tags" *ngIf="allTags.length">
              <span class="tags-label">Click to add:</span>
              <button *ngFor="let tag of allTags.slice(0,15)" class="tag-chip" (click)="addBulkTag(tag)">{{ tag }}</button>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline-secondary" (click)="bulkTagModal = false">Cancel</button>
            <button class="btn btn-primary" [disabled]="!bulkTagInput.trim()" (click)="executeBulkTag()">Add Tags</button>
          </div>
        </div>
      </div>

      <!-- Delete Confirmation Modal -->
      <div class="modal-overlay" *ngIf="deleteTarget" (click)="deleteTarget = null">
        <div class="modal-content modal-sm" (click)="$event.stopPropagation()">
          <div class="modal-header modal-header-danger">
            <h3><tui-icon icon="@tui.alert-triangle"></tui-icon> Delete Question</h3>
          </div>
          <div class="modal-body">
            <p>Are you sure you want to delete this question?</p>
            <div class="delete-preview" [innerHTML]="truncate(deleteTarget.question_text, 200)"></div>
            <p class="text-warn" *ngIf="deleteTarget.usage_count > 0">
              ⚠ This question is used in {{ deleteTarget.usage_count }} assessment(s).
            </p>
          </div>
          <div class="modal-footer">
            <button class="btn btn-outline-secondary" (click)="deleteTarget = null">Cancel</button>
            <button class="btn btn-danger" (click)="executeDelete(deleteTarget)">Delete</button>
          </div>
        </div>
      </div>

      <!-- Preview Panel -->
      <div class="preview-overlay" *ngIf="previewTarget" (click)="previewTarget = null">
        <div class="preview-panel" (click)="$event.stopPropagation()">
          <div class="preview-header">
            <h3><tui-icon icon="@tui.eye"></tui-icon> Question Preview</h3>
            <div class="preview-actions">
              <button class="btn btn-sm btn-outline-secondary" (click)="editQuestion(previewTarget)">
                <tui-icon icon="@tui.edit"></tui-icon> Edit
              </button>
              <button class="btn-close" (click)="previewTarget = null"><tui-icon icon="@tui.x"></tui-icon></button>
            </div>
          </div>
          <div class="preview-body">
            <div class="preview-meta">
              <span class="type-badge" [attr.data-type]="previewTarget.question_type">{{ getTypeLabel(previewTarget.question_type) }}</span>
              <span class="diff-badge" [attr.data-diff]="previewTarget.difficulty">{{ previewTarget.difficulty }}</span>
              <span class="points-badge">{{ previewTarget.points }} pts</span>
            </div>
            <div class="preview-question" [innerHTML]="previewTarget.question_text"></div>
            <div class="preview-options" *ngIf="previewTarget.options?.length">
              <div *ngFor="let opt of previewTarget.options; let i = index" class="preview-option"
                   [class.correct]="opt.isCorrect || opt.is_correct">
                <span class="opt-marker">{{ getOptionMarker(i) }}</span>
                <span class="opt-text">{{ opt.text || opt.option_text }}</span>
                <tui-icon *ngIf="opt.isCorrect || opt.is_correct" icon="@tui.check" style="font-size:14px;color:var(--ui-success, #2e7d4f)"></tui-icon>
              </div>
            </div>
            <div class="preview-explanation" *ngIf="previewTarget.explanation">
              <h4>Explanation</h4>
              <p [innerHTML]="previewTarget.explanation"></p>
            </div>
            <div class="preview-footer-info">
              <span>Bank: {{ previewTarget.bank_name }}</span>
              <span>Created: {{ formatDate(previewTarget.created_at) }}</span>
              <span>Used in {{ previewTarget.usage_count }} assessments</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Toast -->
      <div class="toast" *ngIf="toast.show" [class]="'toast toast-' + toast.type">
        <tui-icon [icon]="toast.type === 'success' ? '@tui.check-circle' : '@tui.alert-circle'" style="font-size:18px"></tui-icon>
        {{ toast.message }}
      </div>
    </div>
    </main>
  `,
  styles: [`
    :host { display: block; }
    /* Page chrome (header, KPIs, section, toolbar, pills, states) comes from the global UI kit. */
    .ui-kpi-grid { margin-bottom: 16px; }
    .ui-toolbar { margin-bottom: 12px; }
    .ui-search { flex: 1 1 260px; }
    .search-box input { padding-right: 30px; }

    /* Buttons */
    .btn-ghost { background: none; color: var(--ui-text-muted, #6a716b); border: none; }
    .btn-ghost:hover { color: var(--brand-primary); }
    .btn-icon { background: none; border: none; cursor: pointer; padding: 4px; border-radius: 6px; color: var(--ui-text-muted, #6a716b); transition: background .15s ease, color .15s ease; display: inline-flex; }
    .btn-icon:hover { background: var(--ui-surface-2, #f7f8f7); color: var(--ui-text, #1b1f1c); }
    .btn-icon-danger:hover { background: rgba(var(--ui-danger-rgb), .08); color: var(--ui-danger, #c0352b); }
    .btn-close { background: none; border: none; cursor: pointer; color: var(--ui-text-muted, #6a716b); padding: 4px; opacity: 1; width: auto; height: auto; display: inline-flex; }
    .btn-close:hover { color: var(--ui-text, #1b1f1c); }

    /* Difficulty Bar */
    .difficulty-bar { display: flex; height: 26px; border-radius: var(--ui-radius-ctl, 8px); overflow: hidden; margin-bottom: 20px; border: 1px solid var(--ui-border, #e7e9e7); }
    .diff-segment { display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; cursor: pointer; transition: opacity 0.15s; min-width: 2%; }
    .diff-segment:hover { opacity: 0.85; }
    .diff-easy { background: rgba(var(--ui-success-rgb), .16); color: var(--ui-success-text, #23643e); }
    .diff-medium { background: rgba(var(--ui-warning-rgb), .16); color: var(--ui-warning-text, #8f4b07); }
    .diff-hard { background: rgba(var(--ui-danger-rgb), .16); color: var(--ui-danger-text, #a12c24); }

    /* Filters */
    .search-clear { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--ui-text-subtle, #8d948e); padding: 2px; display: inline-flex; z-index: 1; }
    .filter-select { min-width: 140px; }

    /* Tags Filter */
    .tags-filter { align-items: center; padding: 0 22px 14px; }
    .tags-label { font-size: 13px; color: var(--ui-text-muted, #6a716b); font-weight: 500; }
    .btn-show-more { font-size: 12px; color: var(--brand-primary); background: none; border: none; cursor: pointer; }

    /* Bulk Actions */
    .bulk-bar { display: flex; align-items: center; gap: 12px; padding: 10px 22px; background: rgba(var(--brand-primary-rgb), .05);
      border-top: 1px solid rgba(var(--brand-primary-rgb), .18); flex-wrap: wrap; }
    .bulk-actions { display: flex; gap: 6px; flex-wrap: wrap; }

    /* Loading */
    .table-skeleton { padding: 16px 22px 22px; border-top: 1px solid var(--ui-border, #e7e9e7); }

    /* Empty */
    .empty-state { margin: 0 22px 22px; }

    /* Table */
    .table-container { overflow-x: auto; border-top: 1px solid var(--ui-border, #e7e9e7); }
    .data-table { margin: 0; }
    .data-table td { vertical-align: middle; }
    .data-table th { white-space: nowrap; }
    .data-table tr.selected > td { background: rgba(var(--brand-primary-rgb), .06); }
    .sortable { cursor: pointer; user-select: none; }
    .sortable:hover { color: var(--brand-primary); }
    .col-check { width: 40px; text-align: center; }
    .col-question { min-width: 280px; }
    .col-type { width: 130px; }
    .col-diff { width: 90px; }
    .col-points { width: 70px; text-align: center; }
    .col-bank { width: 140px; }
    .col-tags { width: 160px; }
    .col-usage { width: 70px; text-align: center; }
    .col-status { width: 60px; text-align: center; }
    .col-actions { width: 140px; }
    .q-text { line-height: 1.4; }
    .q-meta { display: flex; gap: 10px; margin-top: 4px; font-size: 12px; color: var(--ui-text-subtle, #8d948e); }
    .type-badge { white-space: nowrap; }
    .diff-badge { text-transform: capitalize; }
    .diff-badge[data-diff="easy"] { background: rgba(var(--ui-success-rgb), .1); border-color: rgba(var(--ui-success-rgb), .28); color: var(--ui-success-text); }
    .diff-badge[data-diff="medium"] { background: rgba(var(--ui-warning-rgb), .1); border-color: rgba(var(--ui-warning-rgb), .28); color: var(--ui-warning-text); }
    .diff-badge[data-diff="hard"] { background: rgba(var(--ui-danger-rgb), .1); border-color: rgba(var(--ui-danger-rgb), .28); color: var(--ui-danger-text); }
    .bank-name { color: var(--brand-primary); cursor: pointer; font-size: 13px; }
    .bank-name:hover { text-decoration: underline; }
    .tag-sm { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 11px; background: var(--ui-surface-2, #f7f8f7); border: 1px solid var(--ui-border, #e7e9e7); color: var(--ui-text-muted, #6a716b); cursor: pointer; margin: 1px; }
    .tag-sm:hover { border-color: rgba(var(--brand-primary-rgb), .45); color: var(--brand-primary); }
    .tag-more { font-size: 11px; color: var(--ui-text-subtle, #8d948e); }
    .status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }
    .status-dot[data-status="active"] { background: var(--ui-success, #2e7d4f); }
    .status-dot[data-status="inactive"] { background: var(--ui-warning, #b25e09); }
    .status-dot[data-status="archived"] { background: var(--ui-text-subtle, #8d948e); }
    .action-group { display: flex; gap: 2px; }

    /* Pagination */
    .pagination-bar { display: flex; align-items: center; justify-content: space-between; padding: 12px 22px; flex-wrap: wrap; gap: 10px; border-top: 1px solid var(--ui-border, #e7e9e7); }
    .page-info { font-size: 13px; color: var(--ui-text-muted, #6a716b); }
    .page-controls { display: flex; gap: 4px; }
    .btn-page { min-width: 32px; height: 32px; padding: 0 6px; display: flex; align-items: center; justify-content: center; border: 1px solid var(--ui-border, #e7e9e7); border-radius: var(--ui-radius-ctl, 8px); background: var(--ui-surface, #fff); cursor: pointer; font-size: 13px; color: var(--ui-text, #1b1f1c); transition: border-color .15s ease, color .15s ease; }
    .btn-page:hover:not(:disabled) { border-color: rgba(var(--brand-primary-rgb), .45); color: var(--brand-primary); }
    .btn-page.active { background: var(--brand-primary); color: var(--brand-primary-contrast, #fff); border-color: var(--brand-primary); }
    .btn-page:disabled { opacity: 0.4; cursor: not-allowed; }
    .page-size-select { width: auto; }

    /* Modals */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000; }
    .modal-content { background: var(--ui-surface, #fff); border-radius: var(--ui-radius-panel, 16px); width: 90%; max-height: 80vh; overflow-y: auto; }
    .modal-sm { max-width: 500px; }
    .modal-lg { max-width: 700px; }
    .modal-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--ui-border, #e7e9e7); }
    .modal-header h3 { font-size: 1.0625rem; font-weight: 650; color: var(--ui-text, #1b1f1c); display: flex; align-items: center; gap: 8px; margin: 0; }
    .modal-header-danger h3 { color: var(--ui-danger-text, #a12c24); }
    .modal-body { padding: 20px; }
    .modal-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 20px; border-top: 1px solid var(--ui-border, #e7e9e7); }
    .form-label { font-size: 13px; font-weight: 500; color: var(--ui-text, #1b1f1c); margin-bottom: 6px; }
    .text-warn { color: var(--ui-warning-text, #8f4b07); font-size: 13px; margin-top: 8px; }
    .delete-preview { padding: 12px; background: var(--ui-surface-2, #f7f8f7); border: 1px solid var(--ui-border, #e7e9e7); border-radius: var(--ui-radius-ctl, 8px); font-size: 14px; margin: 12px 0; }

    /* Bank Manager */
    .bank-form-row { display: flex; gap: 10px; margin-bottom: 16px; }
    .bank-form-row .form-control { flex: 1; }
    .bank-list { display: flex; flex-direction: column; gap: 8px; }
    .bank-item { display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; border-radius: var(--ui-radius-ctl, 8px); border: 1px solid var(--ui-border, #e7e9e7); }
    .bank-info { display: flex; flex-direction: column; gap: 2px; }
    .bank-title { font-weight: 600; color: var(--ui-text, #1b1f1c); }
    .bank-desc { font-size: 13px; color: var(--ui-text-muted, #6a716b); }
    .bank-count { font-size: 12px; color: var(--ui-text-subtle, #8d948e); }
    .bank-actions { display: flex; gap: 4px; }
    .empty-msg { text-align: center; padding: 20px; color: var(--ui-text-subtle, #8d948e); }
    .existing-tags { margin-top: 12px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .tag-chip { display: inline-flex; padding: 3px 10px; border-radius: 999px; font-size: 12px; border: 1px solid var(--ui-border, #e7e9e7); background: var(--ui-surface, #fff); cursor: pointer; color: var(--ui-text-muted, #6a716b); }
    .tag-chip:hover { border-color: rgba(var(--brand-primary-rgb), .45); color: var(--brand-primary); }

    /* Preview Panel */
    .preview-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1000; display: flex; justify-content: flex-end; }
    .preview-panel { width: 500px; max-width: 90vw; background: var(--ui-surface, #fff); height: 100%; overflow-y: auto; box-shadow: -4px 0 20px rgba(0,0,0,0.12); }
    .preview-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--ui-border, #e7e9e7); }
    .preview-header h3 { font-size: 1.0625rem; font-weight: 650; margin: 0; display: flex; align-items: center; gap: 8px; }
    .preview-actions { display: flex; gap: 8px; align-items: center; }
    .preview-body { padding: 20px; }
    .preview-meta { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
    .points-badge { display: inline-flex; align-items: center; padding: 2px 9px; border-radius: 999px; font-size: 11px; font-weight: 600; background: rgba(var(--brand-primary-rgb), .1); color: var(--brand-primary); border: 1px solid rgba(var(--brand-primary-rgb), .28); }
    .preview-question { font-size: 15px; line-height: 1.6; color: var(--ui-text, #1b1f1c); margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid var(--ui-border, #e7e9e7); }
    .preview-options { display: flex; flex-direction: column; gap: 8px; margin-bottom: 20px; }
    .preview-option { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: var(--ui-radius-ctl, 8px); background: var(--ui-surface, #fff); border: 1px solid var(--ui-border, #e7e9e7); }
    .preview-option.correct { background: rgba(var(--ui-success-rgb), .06); border-color: rgba(var(--ui-success-rgb), .45); }
    .opt-marker { width: 26px; height: 26px; border-radius: 50%; background: var(--ui-surface-2, #f7f8f7); border: 1px solid var(--ui-border, #e7e9e7); display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600; color: var(--ui-text-muted, #6a716b); flex-shrink: 0; }
    .preview-option.correct .opt-marker { background: var(--ui-success, #2e7d4f); border-color: var(--ui-success, #2e7d4f); color: #fff; }
    .opt-text { flex: 1; font-size: 14px; }
    .preview-explanation { padding: 14px; background: rgba(var(--ui-info-rgb), .06); border: 1px solid rgba(var(--ui-info-rgb), .22); border-radius: var(--ui-radius-ctl, 8px); margin-bottom: 16px; }
    .preview-explanation h4 { font-size: 13px; font-weight: 600; color: var(--ui-info-text, #1d518c); margin: 0 0 6px; }
    .preview-explanation p { font-size: 14px; color: var(--ui-text, #1b1f1c); margin: 0; }
    .preview-footer-info { display: flex; flex-wrap: wrap; gap: 16px; font-size: 13px; color: var(--ui-text-subtle, #8d948e); padding-top: 16px; border-top: 1px solid var(--ui-border, #e7e9e7); }

    /* Toast */
    .toast { position: fixed; bottom: 24px; right: 24px; padding: 12px 20px; border-radius: var(--ui-radius-card, 12px); display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; z-index: 2000; animation: slideUp 0.3s ease; box-shadow: var(--ui-shadow-pop); }
    .toast-success { background: #fff; color: var(--ui-success-text, #23643e); border: 1px solid rgba(var(--ui-success-rgb), .35); }
    .toast-error { background: #fff; color: var(--ui-danger-text, #a12c24); border: 1px solid rgba(var(--ui-danger-rgb), .35); }
    @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }

    @media (max-width: 768px) {
      .bulk-bar { flex-direction: column; align-items: flex-start; }
      .preview-panel { width: 100%; }
      .ui-filters .filter-select { flex: 1 1 140px; min-width: 0; }
    }
  `]
})
export class QuestionListComponent implements OnInit, OnDestroy {
  private assessments = inject(AssessmentsService);
  private router = inject(Router);
  private dialog = inject(ConfirmDialogService);
  private destroy$ = new Subject<void>();
  Math = Math;

  // Data
  private allQuestions: Question[] = [];  // the selected bank's questions, before client filters
  questions: Question[] = [];
  banks: QuestionBank[] = [];
  allTags: string[] = [];
  notice: string | null = null;
  stats: BankStats = { total_questions: 0, active_questions: 0, total_banks: 0, by_type: [], by_difficulty: [] };

  // State
  loading = false;
  currentPage = 1;
  pageSize = 20;
  totalItems = 0;
  totalPages = 0;
  showAllTags = false;
  showBankManager = false;
  showImport = false;
  bulkMoveModal = false;
  bulkTagModal = false;
  bulkMoveTargetBank = '';
  bulkTagInput = '';
  newBankName = '';
  newBankDesc = '';
  deleteTarget: Question | null = null;
  previewTarget: Question | null = null;

  // Search
  onSearch$ = new Subject<string>();

  // Filters
  filters: FilterState = {
    search: '', bank_id: '', question_type: '', difficulty: '', status: '', tag: '',
    sort_by: 'created_at', sort_dir: 'desc'
  };

  questionTypes = [
    { value: 'multiple_choice', label: 'Multiple Choice' },
    { value: 'multiple_response', label: 'Multiple Response' },
    { value: 'true_false', label: 'True / False' },
    { value: 'short_answer', label: 'Short Answer' },
    { value: 'long_answer', label: 'Long Answer' },
    { value: 'numeric', label: 'Numeric' },
    { value: 'fill_blank', label: 'Fill in the Blank' },
    { value: 'matching', label: 'Matching' },
    { value: 'ordering', label: 'Ordering' },
    { value: 'file_upload', label: 'File Upload' },
    { value: 'hotspot', label: 'Hotspot' }
  ];

  // Toast
  toast = { show: false, message: '', type: 'success' as 'success' | 'error' };

  ngOnInit(): void {
    this.onSearch$.pipe(
      debounceTime(400),
      distinctUntilChanged(),
      takeUntil(this.destroy$)
    ).subscribe(() => {
      this.currentPage = 1;
      this.applyView();
    });
    this.loadBanks();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // DATA LOADING
  // ==========================================

  /**
   * The new API lists questions only within a bank, so a bank must be chosen;
   * there is no cross-bank list. Once a bank is picked its questions are
   * fetched once and then searched, filtered, sorted and paged on the client.
   */
  loadQuestions(): void {
    const bankId = this.filters.bank_id;
    if (!bankId) {
      this.allQuestions = [];
      this.notice = 'Choose a bank to list its questions. The new API lists questions within a bank.';
      this.applyView();

      return;
    }
    this.notice = null;
    this.loading = true;
    const bankName = this.banks.find(b => b.id === bankId)?.name ?? '';
    this.assessments.bankQuestions(bankId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (page) => {
        this.allQuestions = (page.items || []).map((r) => this.toQuestion(r, bankId, bankName));
        this.applyView();
        this.loading = false;
      },
      error: () => { this.loading = false; this.showToast('Failed to load questions', 'error'); }
    });

    // The tag chips for this bank come from the server's distinct list.
    this.assessments.questionTags(bankId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (tags) => { this.allTags = tags; },
      error: () => { this.allTags = []; },
    });
  }

  private toQuestion(r: Record<string, unknown>, bankId: string, bankName: string): Question {
    return {
      id: String(r['id'] ?? ''),
      question_text: (r['text'] as string) ?? '',
      question_type: this.typeToForm((r['type'] as string) ?? '') as Question['question_type'],
      difficulty: this.difficultyToForm((r['difficulty'] as string) ?? '') as Question['difficulty'],
      points: Number(r['points'] ?? 0),
      bank_id: bankId,
      bank_name: bankName,
      category_id: '',
      category_name: '',
      tags: (r['tags'] as string[]) ?? [],
      status: (r['isActive'] === false) ? 'inactive' : 'active',
      explanation: (r['explanation'] as string) ?? '',
      time_limit_seconds: null,
      usage_count: 0,
      created_by: '',
      created_by_name: '',
      created_at: (r['createdAt'] as string) ?? '',
      updated_at: (r['updatedAt'] as string) ?? '',
      options: (r['options'] as any[]) ?? [],
      is_selected: false,
    };
  }

  private typeToForm(apiType: string): string {
    const m: Record<string, string> = {
      multiple_choice_single: 'multiple_choice', multiple_choice_multiple: 'multiple_response',
      fill_in_blanks: 'fill_blank',
    };
    return m[apiType] || apiType;
  }

  private difficultyToForm(apiD: string): string {
    const m: Record<string, string> = { beginner: 'easy', intermediate: 'medium', professional: 'hard' };
    return m[apiD] || (apiD || 'medium');
  }

  /** Client-side search / type / difficulty / status filtering, sort and paging. */
  private applyView(): void {
    let rows = [...this.allQuestions];
    const term = this.filters.search.trim().toLowerCase();
    if (term) rows = rows.filter(q => q.question_text.toLowerCase().includes(term));
    if (this.filters.question_type) rows = rows.filter(q => q.question_type === this.filters.question_type);
    if (this.filters.difficulty) rows = rows.filter(q => q.difficulty === this.filters.difficulty);
    if (this.filters.status) rows = rows.filter(q => q.status === this.filters.status);
    if (this.filters.tag) rows = rows.filter(q => (q.tags || []).includes(this.filters.tag));

    const dir = this.filters.sort_dir === 'asc' ? 1 : -1;
    const key = this.filters.sort_by;
    rows.sort((a, b) => {
      const av = (a as any)[key] ?? ''; const bv = (b as any)[key] ?? '';
      return av > bv ? dir : av < bv ? -dir : 0;
    });

    this.totalItems = rows.length;
    this.totalPages = Math.max(1, Math.ceil(rows.length / this.pageSize));
    if (this.currentPage > this.totalPages) this.currentPage = this.totalPages;
    const start = (this.currentPage - 1) * this.pageSize;
    this.questions = rows.slice(start, start + this.pageSize);
  }

  loadBanks(): void {
    this.assessments.banks().pipe(takeUntil(this.destroy$)).subscribe({
      next: (page) => {
        this.banks = (page.items || []).map((b) => ({
          id: b.id, name: b.name, description: b.description ?? '',
          question_count: b.questionCount, status: 'active',
        }));
        this.loadStats();
        this.loadQuestions();
      }
    });
  }

  /**
   * Tenant-wide counts from the server, not totalled from one loaded bank.
   *
   * The whole stats row shares one population — the difficulty bar divides its
   * counts by total_questions — so all four cards and the bar come from the
   * same cross-bank figures. The canonical type/difficulty values are folded
   * back to the form's easy/medium/hard so the badges and bar keep working.
   */
  private loadStats(): void {
    this.assessments.overallStatistics().pipe(takeUntil(this.destroy$)).subscribe({
      next: (s) => {
        this.stats = {
          total_questions: s.totalQuestions ?? 0,
          active_questions: s.activeQuestions ?? 0,
          total_banks: s.totalBanks ?? 0,
          by_type: (s.byType ?? []).map(t => ({ type: this.typeToForm(t.type), count: t.count })),
          by_difficulty: (s.byDifficulty ?? []).map(d => ({ difficulty: this.difficultyToForm(d.difficulty), count: d.count })),
        };
      },
      error: () => { /* leave the last-known figures rather than blanking them */ },
    });
  }

  // ==========================================
  // FILTERS
  // ==========================================

  setFilter(key: string, value: string): void {
    (this.filters as any)[key] = (this.filters as any)[key] === value ? '' : value;
    this.currentPage = 1;
    this.applyView();
  }

  toggleTag(tag: string): void {
    this.filters.tag = this.filters.tag === tag ? '' : tag;
    this.currentPage = 1;
    this.applyView();
  }

  clearFilters(): void {
    const bank = this.filters.bank_id;
    this.filters = { search: '', bank_id: bank, question_type: '', difficulty: '', status: '', tag: '', sort_by: 'created_at', sort_dir: 'desc' };
    this.currentPage = 1;
    this.applyView();
  }

  hasActiveFilters(): boolean {
    return !!(this.filters.search || this.filters.bank_id || this.filters.question_type || this.filters.difficulty || this.filters.status || this.filters.tag);
  }

  toggleSort(field: string): void {
    if (this.filters.sort_by === field) {
      this.filters.sort_dir = this.filters.sort_dir === 'asc' ? 'desc' : 'asc';
    } else {
      this.filters.sort_by = field;
      this.filters.sort_dir = 'asc';
    }
    this.loadQuestions();
  }

  // ==========================================
  // SELECTION / BULK
  // ==========================================

  get allSelected(): boolean { return this.questions.length > 0 && this.questions.every(q => q.is_selected); }
  get selectedCount(): number { return this.questions.filter(q => q.is_selected).length; }
  get selectedIds(): string[] { return this.questions.filter(q => q.is_selected).map(q => q.id); }
  private get selectedQuestions(): Question[] { return this.questions.filter(q => q.is_selected); }

  toggleSelectAll(): void {
    const val = !this.allSelected;
    this.questions.forEach(q => q.is_selected = val);
  }

  toggleSelect(q: Question): void { q.is_selected = !q.is_selected; }
  clearSelection(): void { this.questions.forEach(q => q.is_selected = false); }

  bulkAction(action: string): void {
    const targets = this.selectedQuestions;
    if (targets.length === 0) return;

    if (action === 'delete') {
      forkJoin(targets.map(q =>
        this.assessments.deleteQuestion(q.bank_id, q.id).pipe(catchError(() => of(null))),
      )).pipe(takeUntil(this.destroy$)).subscribe(() => {
        this.showToast(`${targets.length} question(s) deleted`, 'success');
        this.loadStats();
        this.loadQuestions();
      });

      return;
    }
    // activate / deactivate: one isActive update per selected question.
    const isActive = action === 'activate';
    forkJoin(targets.map(q =>
      this.assessments.updateQuestion(q.bank_id, q.id, { isActive }).pipe(catchError(() => of(null))),
    )).pipe(takeUntil(this.destroy$)).subscribe(() => {
      this.showToast(`${targets.length} question(s) updated`, 'success');
      this.loadStats();
      this.loadQuestions();
    });
  }

  executeBulkMove(): void {
    const target = this.bulkMoveTargetBank;
    const targets = this.selectedQuestions;
    if (!target || targets.length === 0) { this.bulkMoveModal = false; return; }

    forkJoin(targets.map(q =>
      this.assessments.moveQuestion(q.bank_id, q.id, target).pipe(catchError(() => of(null))),
    )).pipe(takeUntil(this.destroy$)).subscribe(() => {
      this.bulkMoveModal = false;
      this.bulkMoveTargetBank = '';
      this.showToast(`${targets.length} question(s) moved`, 'success');
      this.loadStats();
      this.loadQuestions();
    });
  }

  executeBulkTag(): void {
    const newTags = this.bulkTagInput.split(',').map(t => t.trim()).filter(t => t);
    const targets = this.selectedQuestions;
    if (newTags.length === 0 || targets.length === 0) { this.bulkTagModal = false; return; }

    // A partial update merges onto the stored question server-side, so sending
    // only the merged tag set preserves the question's options and text; the
    // set must include the existing tags because the body replaces the key.
    forkJoin(targets.map(q => {
      const merged = Array.from(new Set([...(q.tags || []), ...newTags]));

      return this.assessments.updateQuestion(q.bank_id, q.id, { tags: merged }).pipe(catchError(() => of(null)));
    })).pipe(takeUntil(this.destroy$)).subscribe(() => {
      this.bulkTagModal = false;
      this.bulkTagInput = '';
      this.showToast(`Tagged ${targets.length} question(s)`, 'success');
      this.loadQuestions();
    });
  }

  addBulkTag(tag: string): void {
    const current = this.bulkTagInput.split(',').map(t => t.trim()).filter(t => t);
    if (!current.includes(tag)) {
      current.push(tag);
      this.bulkTagInput = current.join(', ');
    }
  }

  /** No server export route; a simple CSV is built from the loaded rows. */
  exportSelected(): void {
    const rows = this.selectedQuestions.length ? this.selectedQuestions : this.allQuestions;
    if (rows.length === 0) { this.showToast('Nothing to export', 'error'); return; }
    const esc = (v: string) => `"${(v || '').replace(/"/g, '""')}"`;
    const csv = ['Question,Type,Difficulty,Points,Status']
      .concat(rows.map(q => [
        esc(this.truncate(q.question_text, 500)), q.question_type, q.difficulty, String(q.points), q.status,
      ].join(',')))
      .join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; a.download = 'questions_export.csv'; a.click();
    URL.revokeObjectURL(url);
    this.showToast('Export downloaded', 'success');
  }

  // ==========================================
  // QUESTION ACTIONS
  // ==========================================

  createQuestion(): void { this.router.navigate(['/admin/question-bank/create']); }
  editQuestion(q: Question | null): void { this.router.navigate(['/admin/question-bank/edit', q?.id]); }

  // No single-question GET; the already-loaded row (from the bank list) is the
  // preview.
  previewQuestion(q: Question | null): void {
    if (q) this.previewTarget = q;
  }

  duplicateQuestion(q: Question): void {
    this.assessments.duplicateQuestion(q.bank_id, q.id)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => { this.showToast('Question duplicated', 'success'); this.loadStats(); this.loadQuestions(); },
        error: () => this.showToast('Could not duplicate the question.', 'error'),
      });
  }

  confirmDelete(q: Question): void { this.deleteTarget = q; }

  executeDelete(q: Question): void {
    this.deleteTarget = null;
    this.assessments.deleteQuestion(q.bank_id, q.id)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => { this.showToast('Question deleted', 'success'); this.loadStats(); this.loadQuestions(); },
        error: () => this.showToast('Could not delete the question.', 'error'),
      });
  }

  // ==========================================
  // BANK MANAGEMENT
  // ==========================================

  createBank(): void {
    this.assessments.createBank({ name: this.newBankName.trim(), description: this.newBankDesc.trim() || null })
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => { this.showToast('Bank created', 'success'); this.newBankName = ''; this.newBankDesc = ''; this.loadBanks(); },
        error: () => this.showToast('Failed to create bank', 'error'),
      });
  }

  async editBank(bank: QuestionBank): Promise<void> {
    const newName = await this.dialog.prompt('Bank name:', { inputValue: bank.name });
    if (newName && newName.trim()) {
      this.assessments.updateBank(bank.id, { name: newName.trim() })
        .pipe(takeUntil(this.destroy$)).subscribe({
          next: () => { this.showToast('Bank updated', 'success'); this.loadBanks(); },
          error: () => this.showToast('Failed to update bank', 'error'),
        });
    }
  }

  async deleteBank(bank: QuestionBank): Promise<void> {
    if (bank.question_count > 0) { this.showToast('Cannot delete bank with questions', 'error'); return; }
    if (!(await this.dialog.confirm(`Delete bank "${bank.name}"?`, { type: 'danger' }))) return;
    this.assessments.deleteBank(bank.id).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => { this.showToast('Bank deleted', 'success'); this.loadBanks(); },
      error: () => this.showToast('Failed to delete bank', 'error'),
    });
  }

  // ==========================================
  // PAGINATION
  // ==========================================

  goToPage(page: number): void {
    if (page < 1 || page > this.totalPages) return;
    this.currentPage = page;
    this.applyView();
  }

  getPageNumbers(): number[] {
    const pages: number[] = [];
    const maxVisible = 5;
    let start = Math.max(1, this.currentPage - Math.floor(maxVisible / 2));
    let end = Math.min(this.totalPages, start + maxVisible - 1);
    if (end - start < maxVisible - 1) start = Math.max(1, end - maxVisible + 1);
    for (let i = start; i <= end; i++) pages.push(i);
    return pages;
  }

  // ==========================================
  // HELPERS
  // ==========================================

  trackById(_: number, q: Question): string { return q.id; }

  truncate(text: string, max: number): string {
    if (!text) return '';
    const stripped = text.replace(/<[^>]*>/g, '');
    return stripped.length > max ? stripped.substring(0, max) + '...' : stripped;
  }

  formatDate(date: string): string {
    if (!date) return '';
    return new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  }

  getTypeLabel(type: string): string {
    return this.questionTypes.find(t => t.value === type)?.label || type.replace(/_/g, ' ');
  }

  getOptionMarker(index: number): string {
    return String.fromCharCode(65 + index);
  }

  getDiffCount(diff: string): number {
    return this.stats.by_difficulty?.find(d => d.difficulty === diff)?.count || 0;
  }

  getDiffPercent(diff: string): number {
    const total = this.stats.total_questions || 1;
    return (this.getDiffCount(diff) / total) * 100;
  }

  showToast(message: string, type: 'success' | 'error'): void {
    this.toast = { show: true, message, type };
    setTimeout(() => this.toast.show = false, 3000);
  }
}
