// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 11
// Admin: Grading Queue Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { Subject, takeUntil, debounceTime } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { AdmintopComponent } from '../../../partials/top/admintop/admintop.component';
import { AssessmentsService, type QueuedAttempt } from '../../../services/assessments.service';
import { SessionStore } from '../../../core/auth/session.store';

// ==========================================
// INTERFACES
// ==========================================

/**
 * One queue row. The new marking queue is per ATTEMPT, not per answer, so a
 * row is a whole submission with a count of answers left to mark rather than a
 * single question. The per-question fields are kept for the template but carry
 * that summary; ids are UUID strings.
 */
interface GradingQueueItem {
  queue_id: string;   // the attempt id -- the row's identity
  attempt_id: string;
  assessment_id: string;
  assessment_title: string;
  assessment_type: string;
  question_text: string;
  question_type: string;
  section_title: string;
  learner_name: string;
  learner_email: string;
  learner_avatar: string | null;
  assigned_grader_id: string | null;
  assigned_grader_name: string | null;
  status: string;
  priority: string;      // display label derived from the numeric priority
  priorityNum: number;   // what the API stores and prioritise() sends
  awaiting: number;      // answers still to mark on this attempt
  max_points: number;
  submitted_at: string;
  has_rubric: boolean;
  rubric_name: string | null;
  attempt_number: number;
  answer_preview: string;
}

interface QueueStats {
  total_pending: number;
  total_in_progress: number;
  total_graded_today: number;
  total_overdue: number;
  my_assigned: number;
  avg_grading_time_minutes: number;
}

interface GraderOption {
  user_id: string;
  full_name: string;
  email: string;
  pending_count: number;
}

@Component({
  selector: 'app-grading-queue',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>

    <div class="grading-queue-container">
      <!-- ==========================================
           HEADER
           ========================================== -->
      <div class="page-header">
        <div class="header-left">
          <h1 class="page-title">
            <tui-icon icon="@tui.clipboard-check"></tui-icon>
            Grading Queue
          </h1>
          <p class="page-subtitle">Review and grade pending submissions</p>
        </div>
        <div class="header-actions">
          <button class="btn btn-secondary btn-sm" (click)="loadQueue()">
            <tui-icon icon="@tui.refresh-cw"></tui-icon> Refresh
          </button>
          <button class="btn btn-primary btn-sm" (click)="autoAssign()" [disabled]="stats.total_pending === 0">
            <tui-icon icon="@tui.shuffle"></tui-icon> Auto-Assign
          </button>
        </div>
      </div>

      <!-- ==========================================
           STATS CARDS
           ========================================== -->
      <div class="stats-row">
        <div class="stat-card pending" (click)="filterByStatus('pending')">
          <div class="stat-icon"><tui-icon icon="@tui.clock"></tui-icon></div>
          <div class="stat-info">
            <span class="stat-value">{{ stats.total_pending }}</span>
            <span class="stat-label">Pending</span>
          </div>
        </div>
        <div class="stat-card in-progress" (click)="filterByStatus('in_progress')">
          <div class="stat-icon"><tui-icon icon="@tui.edit-3"></tui-icon></div>
          <div class="stat-info">
            <span class="stat-value">{{ stats.total_in_progress }}</span>
            <span class="stat-label">In Progress</span>
          </div>
        </div>
        <div class="stat-card graded" (click)="filterByStatus('graded')">
          <div class="stat-icon"><tui-icon icon="@tui.check-circle"></tui-icon></div>
          <div class="stat-info">
            <span class="stat-value">{{ stats.total_graded_today }}</span>
            <span class="stat-label">Graded Today</span>
          </div>
        </div>
        <div class="stat-card overdue" (click)="filterByStatus('overdue')">
          <div class="stat-icon"><tui-icon icon="@tui.alert-triangle"></tui-icon></div>
          <div class="stat-info">
            <span class="stat-value">{{ stats.total_overdue }}</span>
            <span class="stat-label">Overdue</span>
          </div>
        </div>
        <div class="stat-card assigned" (click)="filterByAssigned()">
          <div class="stat-icon"><tui-icon icon="@tui.user-check"></tui-icon></div>
          <div class="stat-info">
            <span class="stat-value">{{ stats.my_assigned }}</span>
            <span class="stat-label">My Queue</span>
          </div>
        </div>
        <div class="stat-card time">
          <div class="stat-icon"><tui-icon icon="@tui.timer"></tui-icon></div>
          <div class="stat-info">
            <span class="stat-value">{{ stats.avg_grading_time_minutes | number:'1.0-0' }}m</span>
            <span class="stat-label">Avg Time</span>
          </div>
        </div>
      </div>

      <!-- ==========================================
           FILTERS & SEARCH
           ========================================== -->
      <div class="filters-bar">
        <div class="search-box">
          <tui-icon icon="@tui.search"></tui-icon>
          <input type="text" class="form-control" placeholder="Search learner, assessment, question..."
                 [(ngModel)]="searchTerm" (ngModelChange)="onSearchChange($event)">
          <button class="btn-clear" *ngIf="searchTerm" (click)="searchTerm = ''; applyFilters()">
            <tui-icon icon="@tui.x"></tui-icon>
          </button>
        </div>

        <div class="filter-group">
          <select class="form-select form-select-sm" [(ngModel)]="filters.status" (change)="applyFilters()">
            <option value="">All Status</option>
            <option value="pending">Pending</option>
            <option value="in_progress">In Progress</option>
            <option value="graded">Graded</option>
            <option value="overdue">Overdue</option>
          </select>

          <select class="form-select form-select-sm" [(ngModel)]="filters.priority" (change)="applyFilters()">
            <option value="">All Priority</option>
            <option value="high">High</option>
            <option value="medium">Medium</option>
            <option value="low">Low</option>
          </select>

          <select class="form-select form-select-sm" [(ngModel)]="filters.question_type" (change)="applyFilters()">
            <option value="">All Question Types</option>
            <option value="essay">Essay</option>
            <option value="short_answer">Short Answer</option>
            <option value="file_upload">File Upload</option>
            <option value="fill_blank">Fill in Blank</option>
          </select>

          <select class="form-select form-select-sm" [(ngModel)]="filters.assessment_id" (change)="applyFilters()">
            <option value="">All Assessments</option>
            <option *ngFor="let a of assessmentOptions" [value]="a.id">{{ a.title }}</option>
          </select>

          <select class="form-select form-select-sm" [(ngModel)]="filters.assigned_to" (change)="applyFilters()">
            <option value="">All Graders</option>
            <option value="me">Assigned to Me</option>
            <option value="unassigned">Unassigned</option>
            <option *ngFor="let g of graderOptions" [value]="g.user_id">{{ g.full_name }}</option>
          </select>
        </div>

        <div class="filter-actions">
          <button class="btn btn-sm btn-secondary" (click)="resetFilters()" *ngIf="hasActiveFilters()">
            <tui-icon icon="@tui.x"></tui-icon> Clear
          </button>
          <div class="bulk-actions" *ngIf="selectedItems.length > 0">
            <span class="badge bg-primary me-2">{{ selectedItems.length }} selected</span>
            <button class="btn btn-sm btn-primary" (click)="showBulkAssignModal = true">
              <tui-icon icon="@tui.user-plus"></tui-icon> Assign
            </button>
            <button class="btn btn-sm btn-success" (click)="bulkMarkPriority('high')">
              <tui-icon icon="@tui.arrow-up"></tui-icon> High Priority
            </button>
          </div>
        </div>
      </div>

      <!-- ==========================================
           QUEUE TABLE
           ========================================== -->
      <div class="queue-table-wrapper" *ngIf="!loading">
        <table class="table table-hover" *ngIf="queueItems.length > 0">
          <thead>
            <tr>
              <th class="col-check">
                <input type="checkbox" class="form-check-input"
                       [checked]="allSelected" (change)="toggleSelectAll()">
              </th>
              <th class="col-priority">Priority</th>
              <th class="col-learner">Learner</th>
              <th class="col-assessment">Assessment</th>
              <th class="col-question">Question</th>
              <th class="col-type">Type</th>
              <th class="col-submitted">Submitted</th>
              <th class="col-grader">Assigned To</th>
              <th class="col-status">Status</th>
              <th class="col-actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr *ngFor="let item of queueItems; let i = index"
                [class.selected]="isSelected(item.queue_id)"
                [class.overdue]="isOverdue(item)"
                [class.high-priority]="item.priority === 'high'">
              <td class="col-check">
                <input type="checkbox" class="form-check-input"
                       [checked]="isSelected(item.queue_id)"
                       (change)="toggleSelection(item.queue_id)">
              </td>
              <td class="col-priority">
                <span class="priority-badge" [ngClass]="'priority-' + item.priority"
                      (click)="cyclePriority(item)">
                  <tui-icon [icon]="getPriorityIcon(item.priority)"></tui-icon>
                </span>
              </td>
              <td class="col-learner">
                <div class="learner-cell">
                  <div class="avatar-sm" [style.background]="getAvatarColor(item.learner_name)">
                    {{ item.learner_name.charAt(0).toUpperCase() }}
                  </div>
                  <div class="learner-info">
                    <span class="learner-name">{{ item.learner_name }}</span>
                    <span class="learner-meta">Attempt #{{ item.attempt_number }}</span>
                  </div>
                </div>
              </td>
              <td class="col-assessment">
                <div class="assessment-cell">
                  <span class="assessment-title">{{ item.assessment_title }}</span>
                  <span class="badge badge-type" *ngIf="item.assessment_type" [ngClass]="'badge-' + item.assessment_type">
                    {{ item.assessment_type }}
                  </span>
                </div>
              </td>
              <td class="col-question">
                <div class="question-cell" [title]="item.question_text">
                  {{ truncate(item.question_text, 60) }}
                  <span class="section-label" *ngIf="item.section_title">{{ item.section_title }}</span>
                </div>
              </td>
              <td class="col-type">
                <span class="badge badge-qtype" *ngIf="item.question_type" [ngClass]="'badge-' + item.question_type">
                  <tui-icon [icon]="getQuestionTypeIcon(item.question_type)"></tui-icon>
                  {{ formatQuestionType(item.question_type) }}
                </span>
                <span class="text-muted" *ngIf="!item.question_type">-</span>
              </td>
              <td class="col-submitted">
                <span class="submitted-time">{{ getTimeAgo(item.submitted_at) }}</span>
                <span class="submitted-date text-muted">{{ formatDate(item.submitted_at) }}</span>
              </td>
              <td class="col-grader">
                <div class="grader-cell" *ngIf="item.assigned_grader_name">
                  <span class="grader-name">{{ item.assigned_grader_name }}</span>
                </div>
                <button class="btn btn-sm btn-secondary" *ngIf="!item.assigned_grader_id"
                        (click)="assignToMe(item)">
                  <tui-icon icon="@tui.user-plus"></tui-icon> Claim
                </button>
              </td>
              <td class="col-status">
                <span class="status-badge" [ngClass]="'status-' + item.status">
                  {{ formatStatus(item.status) }}
                </span>
              </td>
              <td class="col-actions">
                <div class="action-btns">
                  <button class="btn btn-sm btn-primary" (click)="openGrading(item)"
                          title="Grade">
                    <tui-icon icon="@tui.edit-3"></tui-icon>
                  </button>
                  <button class="btn btn-sm btn-secondary" (click)="previewAnswer(item)"
                          title="Preview">
                    <tui-icon icon="@tui.eye"></tui-icon>
                  </button>
                  <div class="dropdown d-inline-block">
                    <button class="btn btn-sm btn-secondary" (click)="item._showMenu = !item._showMenu">
                      <tui-icon icon="@tui.more-vertical"></tui-icon>
                    </button>
                    <div class="dropdown-menu" [class.show]="item._showMenu">
                      <button class="dropdown-item" (click)="assignToGrader(item); item._showMenu = false">
                        <tui-icon icon="@tui.user-plus"></tui-icon> Assign To...
                      </button>
                      <button class="dropdown-item" (click)="changePriority(item, 'high'); item._showMenu = false">
                        <tui-icon icon="@tui.arrow-up"></tui-icon> Set High Priority
                      </button>
                      <button class="dropdown-item" (click)="changePriority(item, 'low'); item._showMenu = false">
                        <tui-icon icon="@tui.arrow-down"></tui-icon> Set Low Priority
                      </button>
                      <div class="dropdown-divider"></div>
                      <button class="dropdown-item text-danger" (click)="skipItem(item); item._showMenu = false">
                        <tui-icon icon="@tui.skip-forward"></tui-icon> Skip
                      </button>
                    </div>
                  </div>
                </div>
              </td>
            </tr>
          </tbody>
        </table>

        <!-- Empty State -->
        <div class="empty-state" *ngIf="queueItems.length === 0">
          <tui-icon icon="@tui.inbox"></tui-icon>
          <h4>{{ hasActiveFilters() ? 'No Matching Items' : 'Queue is Empty' }}</h4>
          <p class="text-muted">{{ hasActiveFilters() ? 'Try adjusting your filters' : 'All submissions have been graded!' }}</p>
          <button class="btn btn-primary" *ngIf="hasActiveFilters()" (click)="resetFilters()">
            Clear Filters
          </button>
        </div>

        <!-- Pagination -->
        <div class="pagination-bar" *ngIf="totalPages > 1">
          <div class="page-info">
            Showing {{ (currentPage - 1) * pageSize + 1 }}-{{ Math.min(currentPage * pageSize, totalItems) }} of {{ totalItems }}
          </div>
          <div class="page-controls">
            <button class="btn btn-sm btn-secondary" [disabled]="currentPage === 1" (click)="goToPage(1)">
              <tui-icon icon="@tui.chevrons-left"></tui-icon>
            </button>
            <button class="btn btn-sm btn-secondary" [disabled]="currentPage === 1" (click)="goToPage(currentPage - 1)">
              <tui-icon icon="@tui.chevron-left"></tui-icon>
            </button>
            <ng-container *ngFor="let p of getPageNumbers()">
              <button class="btn btn-sm" [ngClass]="p === currentPage ? 'btn-primary' : 'btn-secondary'"
                      *ngIf="p !== '...'" (click)="goToPage(+p)">{{ p }}</button>
              <span class="page-ellipsis" *ngIf="p === '...'">...</span>
            </ng-container>
            <button class="btn btn-sm btn-secondary" [disabled]="currentPage === totalPages" (click)="goToPage(currentPage + 1)">
              <tui-icon icon="@tui.chevron-right"></tui-icon>
            </button>
            <button class="btn btn-sm btn-secondary" [disabled]="currentPage === totalPages" (click)="goToPage(totalPages)">
              <tui-icon icon="@tui.chevrons-right"></tui-icon>
            </button>
          </div>
          <div class="page-size">
            <select class="form-select form-select-sm" [(ngModel)]="pageSize" (change)="onPageSizeChange()">
              <option [value]="10">10</option>
              <option [value]="25">25</option>
              <option [value]="50">50</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Loading State -->
      <div class="loading-state" *ngIf="loading">
        <div class="spinner-border text-primary"></div>
        <p>Loading grading queue...</p>
      </div>

      <!-- ==========================================
           ANSWER PREVIEW MODAL
           ========================================== -->
      <div class="modal-overlay" *ngIf="showPreviewModal" (click)="showPreviewModal = false">
        <div class="modal-dialog modal-lg" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h5 class="modal-title">
              <tui-icon icon="@tui.eye"></tui-icon> Answer Preview
            </h5>
            <button class="btn-close" (click)="showPreviewModal = false"></button>
          </div>
          <div class="modal-body" *ngIf="previewItem">
            <div class="preview-meta">
              <div class="meta-row">
                <span class="meta-label">Learner:</span>
                <span class="meta-value">{{ previewItem.learner_name }}</span>
              </div>
              <div class="meta-row">
                <span class="meta-label">Assessment:</span>
                <span class="meta-value">{{ previewItem.assessment_title }}</span>
              </div>
              <div class="meta-row">
                <span class="meta-label">Question Type:</span>
                <span class="badge badge-qtype" [ngClass]="'badge-' + previewItem.question_type">
                  {{ formatQuestionType(previewItem.question_type) }}
                </span>
              </div>
              <div class="meta-row">
                <span class="meta-label">Max Points:</span>
                <span class="meta-value">{{ previewItem.max_points }}</span>
              </div>
            </div>
            <div class="preview-question">
              <h6>Question</h6>
              <div class="question-content" [innerHTML]="previewItem.question_text"></div>
            </div>
            <div class="preview-answer">
              <h6>Learner's Answer</h6>
              <div class="answer-content">
                <pre *ngIf="previewItem.question_type === 'short_answer' || previewItem.question_type === 'fill_blank'">{{ previewItem.answer_preview }}</pre>
                <div *ngIf="previewItem.question_type === 'essay'" class="essay-answer" [innerHTML]="previewItem.answer_preview"></div>
                <div *ngIf="previewItem.question_type === 'file_upload'" class="file-answer">
                  <tui-icon icon="@tui.file"></tui-icon>
                  <span>{{ previewItem.answer_preview }}</span>
                </div>
              </div>
            </div>
            <div class="preview-rubric" *ngIf="previewItem.has_rubric">
              <div class="badge bg-info-subtle text-info">
                <tui-icon icon="@tui.list"></tui-icon> Rubric: {{ previewItem.rubric_name }}
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" (click)="showPreviewModal = false">Close</button>
            <button class="btn btn-primary" (click)="openGrading(previewItem!); showPreviewModal = false">
              <tui-icon icon="@tui.edit-3"></tui-icon> Grade Now
            </button>
          </div>
        </div>
      </div>

      <!-- ==========================================
           BULK ASSIGN MODAL
           ========================================== -->
      <div class="modal-overlay" *ngIf="showBulkAssignModal" (click)="showBulkAssignModal = false">
        <div class="modal-dialog" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h5 class="modal-title">
              <tui-icon icon="@tui.users"></tui-icon> Assign {{ selectedItems.length }} Items
            </h5>
            <button class="btn-close" (click)="showBulkAssignModal = false"></button>
          </div>
          <div class="modal-body">
            <div class="grader-list">
              <div class="grader-option" *ngFor="let g of graderOptions"
                   [class.selected]="bulkAssignGraderId === g.user_id"
                   (click)="bulkAssignGraderId = g.user_id">
                <div class="avatar-sm" [style.background]="getAvatarColor(g.full_name)">
                  {{ g.full_name.charAt(0).toUpperCase() }}
                </div>
                <div class="grader-details">
                  <span class="grader-name">{{ g.full_name }}</span>
                  <span class="grader-meta">{{ g.email }} · {{ g.pending_count }} pending</span>
                </div>
                <tui-icon icon="@tui.check" *ngIf="bulkAssignGraderId === g.user_id"></tui-icon>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" (click)="showBulkAssignModal = false">Cancel</button>
            <button class="btn btn-primary" (click)="executeBulkAssign()" [disabled]="!bulkAssignGraderId">
              <tui-icon icon="@tui.user-check"></tui-icon> Assign
            </button>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .grading-queue-container { padding: 1.5rem; }

    /* Header */
    .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; }
    .page-title { font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; margin: 0; }
    .page-subtitle { color: #6c757d; font-size: 0.875rem; margin: 0.25rem 0 0; }
    .header-actions { display: flex; gap: 0.5rem; }

    /* Stats */
    .stats-row { display: grid; grid-template-columns: repeat(6, 1fr); gap: 1rem; margin-bottom: 1.5rem; }
    .stat-card {
      background: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 1rem;
      display: flex; align-items: center; gap: 0.75rem; cursor: pointer; transition: all 0.2s;
    }
    .stat-card:hover { border-color: #6c757d; transform: translateY(-1px); }
    .stat-icon { width: 40px; height: 40px; border-radius: 0.5rem; display: flex; align-items: center; justify-content: center; }
    .stat-card.pending .stat-icon { background: #fff3cd; color: #856404; }
    .stat-card.in-progress .stat-icon { background: #cce5ff; color: #004085; }
    .stat-card.graded .stat-icon { background: #d4edda; color: #155724; }
    .stat-card.overdue .stat-icon { background: #f8d7da; color: #721c24; }
    .stat-card.assigned .stat-icon { background: #e2d9f3; color: #563d7c; }
    .stat-card.time .stat-icon { background: #d1ecf1; color: #0c5460; }
    .stat-value { font-size: 1.25rem; font-weight: 700; display: block; }
    .stat-label { font-size: 0.75rem; color: #6c757d; }

    /* Filters */
    .filters-bar { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; margin-bottom: 1.25rem;
      background: #f8f9fa; padding: 0.75rem 1rem; border-radius: 0.5rem; }
    .search-box { position: relative; flex: 1; min-width: 200px; }
    .search-box tui-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #adb5bd; font-size: 0.875rem; }
    .search-box input { padding-left: 2rem; font-size: 0.875rem; }
    .btn-clear { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); border: none; background: none; color: #6c757d; cursor: pointer; }
    .filter-group { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .filter-group .form-select { width: auto; min-width: 140px; font-size: 0.8125rem; }
    .filter-actions { display: flex; gap: 0.5rem; align-items: center; margin-left: auto; }

    /* Table */
    .queue-table-wrapper { background: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem; overflow: hidden; }
    .table { margin: 0; font-size: 0.875rem; }
    .table thead th { background: #f8f9fa; border-bottom: 2px solid #dee2e6; font-weight: 600; font-size: 0.8125rem;
      text-transform: uppercase; letter-spacing: 0.03em; color: #495057; white-space: nowrap; padding: 0.75rem; }
    .table tbody td { padding: 0.75rem; vertical-align: middle; }
    .table tbody tr:hover { background: #f8f9fa; }
    .table tbody tr.selected { background: #e8f0fe; }
    .table tbody tr.overdue { border-left: 3px solid #dc3545; }
    .table tbody tr.high-priority { border-left: 3px solid #fd7e14; }

    /* Cells */
    .learner-cell { display: flex; align-items: center; gap: 0.5rem; }
    .avatar-sm { width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center;
      color: #fff; font-size: 0.75rem; font-weight: 600; flex-shrink: 0; }
    .learner-name { font-weight: 500; display: block; font-size: 0.875rem; }
    .learner-meta { font-size: 0.75rem; color: #6c757d; }
    .assessment-cell { display: flex; flex-direction: column; gap: 0.25rem; }
    .assessment-title { font-weight: 500; font-size: 0.875rem; }
    .question-cell { font-size: 0.8125rem; max-width: 200px; }
    .section-label { display: block; font-size: 0.6875rem; color: #6c757d; margin-top: 0.125rem; }
    .submitted-time { display: block; font-size: 0.8125rem; }
    .submitted-date { display: block; font-size: 0.6875rem; }
    .grader-name { font-size: 0.8125rem; font-weight: 500; }

    /* Badges */
    .priority-badge { cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
      width: 28px; height: 28px; border-radius: 50%; transition: all 0.2s; }
    .priority-high { background: #f8d7da; color: #dc3545; }
    .priority-medium { background: #fff3cd; color: #856404; }
    .priority-low { background: #d4edda; color: #28a745; }
    .badge-type { font-size: 0.6875rem; text-transform: capitalize; }
    .badge-exam { background: #dc354520; color: #dc3545; }
    .badge-quiz { background: #0d6efd20; color: #0d6efd; }
    .badge-practice { background: #19875420; color: #198754; }
    .badge-survey { background: #6f42c120; color: #6f42c1; }
    .badge-assignment { background: #fd7e1420; color: #fd7e14; }
    .badge-qtype { font-size: 0.6875rem; display: inline-flex; align-items: center; gap: 0.25rem; }
    .badge-essay { background: #6f42c120; color: #6f42c1; }
    .badge-short_answer { background: #0d6efd20; color: #0d6efd; }
    .badge-file_upload { background: #fd7e1420; color: #fd7e14; }
    .badge-fill_blank { background: #0dcaf020; color: #0dcaf0; }
    .status-badge { padding: 0.25rem 0.625rem; border-radius: 1rem; font-size: 0.75rem; font-weight: 500; }
    .status-pending { background: #fff3cd; color: #856404; }
    .status-in_progress { background: #cce5ff; color: #004085; }
    .status-graded { background: #d4edda; color: #155724; }
    .status-overdue { background: #f8d7da; color: #721c24; }

    /* Actions */
    .action-btns { display: flex; gap: 0.25rem; }
    .dropdown { position: relative; }
    .dropdown-menu { position: absolute; right: 0; top: 100%; z-index: 1000; background: #fff; border: 1px solid #dee2e6;
      border-radius: 0.375rem; box-shadow: 0 4px 12px rgba(0,0,0,0.1); min-width: 180px; display: none; }
    .dropdown-menu.show { display: block; }
    .dropdown-item { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.75rem; border: none;
      background: none; width: 100%; text-align: left; font-size: 0.8125rem; cursor: pointer; }
    .dropdown-item:hover { background: #f8f9fa; }
    .dropdown-divider { height: 0; margin: 0.25rem 0; border-top: 1px solid #e9ecef; }

    /* Pagination */
    .pagination-bar { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem;
      border-top: 1px solid #e9ecef; }
    .page-info { font-size: 0.8125rem; color: #6c757d; }
    .page-controls { display: flex; gap: 0.25rem; }
    .page-ellipsis { padding: 0.25rem 0.5rem; color: #6c757d; }
    .page-size .form-select { width: 70px; }

    /* Empty & Loading */
    .empty-state { text-align: center; padding: 3rem; }
    .empty-state tui-icon { font-size: 2.5rem; color: #adb5bd; margin-bottom: 1rem; }
    .empty-state h4 { font-weight: 600; margin-bottom: 0.5rem; }
    .loading-state { text-align: center; padding: 3rem; }

    /* Modals */
    .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5);
      z-index: 1050; display: flex; align-items: center; justify-content: center; }
    .modal-dialog { background: #fff; border-radius: 0.5rem; max-width: 700px; width: 95%; max-height: 90vh;
      display: flex; flex-direction: column; }
    .modal-dialog.modal-lg { max-width: 800px; }
    .modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid #dee2e6; display: flex; align-items: center; justify-content: space-between; }
    .modal-title { font-size: 1.125rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; margin: 0; }
    .modal-body { padding: 1.25rem; overflow-y: auto; flex: 1; }
    .modal-footer { padding: 0.75rem 1.25rem; border-top: 1px solid #dee2e6; display: flex; justify-content: flex-end; gap: 0.5rem; }

    /* Preview Modal */
    .preview-meta { background: #f8f9fa; border-radius: 0.375rem; padding: 0.75rem; margin-bottom: 1rem; }
    .meta-row { display: flex; gap: 0.5rem; margin-bottom: 0.375rem; }
    .meta-row:last-child { margin-bottom: 0; }
    .meta-label { font-weight: 600; font-size: 0.8125rem; min-width: 100px; color: #6c757d; }
    .meta-value { font-size: 0.8125rem; }
    .preview-question, .preview-answer { margin-bottom: 1rem; }
    .preview-question h6, .preview-answer h6 { font-weight: 600; font-size: 0.8125rem; text-transform: uppercase;
      color: #6c757d; margin-bottom: 0.5rem; }
    .question-content, .answer-content { background: #f8f9fa; border-radius: 0.375rem; padding: 1rem; font-size: 0.875rem; }
    .essay-answer { white-space: pre-wrap; line-height: 1.6; }
    .file-answer { display: flex; align-items: center; gap: 0.5rem; }

    /* Bulk Assign */
    .grader-option { display: flex; align-items: center; gap: 0.75rem; padding: 0.75rem; border: 1px solid #e9ecef;
      border-radius: 0.375rem; margin-bottom: 0.5rem; cursor: pointer; transition: all 0.2s; }
    .grader-option:hover { background: #f8f9fa; }
    .grader-option.selected { border-color: #0d6efd; background: #e8f0fe; }
    .grader-details { flex: 1; }
    .grader-details .grader-name { display: block; font-weight: 500; }
    .grader-details .grader-meta { display: block; font-size: 0.75rem; color: #6c757d; }

    /* Responsive */
    @media (max-width: 1200px) { .stats-row { grid-template-columns: repeat(3, 1fr); } }
    @media (max-width: 768px) {
      .stats-row { grid-template-columns: repeat(2, 1fr); }
      .filters-bar { flex-direction: column; }
      .filter-group { width: 100%; }
      .filter-group .form-select { min-width: 0; flex: 1; }
    }
  `]
})
export class GradingQueueComponent implements OnInit, OnDestroy {
  private assessments = inject(AssessmentsService);
  private session = inject(SessionStore);
  private router = inject(Router);
  private destroy$ = new Subject<void>();
  private searchSubject = new Subject<string>();

  Math = Math;

  // Data
  private allItems: GradingQueueItem[] = [];   // the fetched queue, before client filters
  queueItems: (GradingQueueItem & { _showMenu?: boolean })[] = [];
  stats: QueueStats = { total_pending: 0, total_in_progress: 0, total_graded_today: 0, total_overdue: 0, my_assigned: 0, avg_grading_time_minutes: 0 };
  assessmentOptions: { id: string; title: string }[] = [];
  // No grader roster endpoint exists, so the only assignee the queue can offer
  // is the marker themselves.
  graderOptions: GraderOption[] = [];

  // State
  loading = false;
  searchTerm = '';
  currentPage = 1;
  pageSize = 25;
  totalItems = 0;
  totalPages = 0;
  selectedItems: string[] = [];
  allSelected = false;

  // Filters
  filters = {
    status: '',
    priority: '',
    question_type: '',
    assessment_id: '',
    assigned_to: ''
  };

  // Modals
  showPreviewModal = false;
  showBulkAssignModal = false;
  previewItem: GradingQueueItem | null = null;
  bulkAssignGraderId: string | null = null;

  ngOnInit(): void {
    this.searchSubject.pipe(debounceTime(300), takeUntil(this.destroy$)).subscribe(() => this.applyFilters());
    const me = this.session.userId();
    if (me) {
      this.graderOptions = [{ user_id: me, full_name: 'Me (assign to myself)', email: '', pending_count: 0 }];
    }
    this.loadQueue();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // DATA LOADING
  // ==========================================

  /**
   * The new queue is a flat, attempt-level list scoped only by mine/unassigned;
   * it carries no server-side search, status, priority or assessment filter and
   * no paging, so those are applied on the client (mirroring assessment-history).
   */
  loadQueue(): void {
    this.loading = true;
    this.assessments.gradingQueue({
      mine: this.filters.assigned_to === 'me',
      unassigned: this.filters.assigned_to === 'unassigned',
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: ({ items }) => {
        this.allItems = (items || []).map((a) => this.toQueueItem(a));
        this.buildAssessmentOptions();
        this.computeStats();
        this.applyView();
        this.loading = false;
      },
      error: () => { this.loading = false; }
    });
  }

  private toQueueItem(a: QueuedAttempt): GradingQueueItem {
    const priorityNum = a.assignment?.priority ?? 0;
    const awaiting = a.awaitingMarking ?? 0;
    return {
      queue_id: a.id,
      attempt_id: a.id,
      assessment_id: a.assessmentId,
      assessment_title: a.assessmentTitle ?? '',
      assessment_type: '',
      question_text: `${awaiting} answer${awaiting === 1 ? '' : 's'} awaiting marking`,
      question_type: '',
      section_title: '',
      learner_name: a.userName ?? 'Learner',
      learner_email: '',
      learner_avatar: null,
      assigned_grader_id: a.assignment?.assignedTo ?? null,
      assigned_grader_name: a.assignment?.assigneeName ?? null,
      status: a.assignment ? (a.assignment.isOpen === false ? 'skipped' : 'in_progress') : 'pending',
      priority: this.priorityLabel(priorityNum),
      priorityNum,
      awaiting,
      max_points: 0,
      submitted_at: a.submittedAt ?? '',
      has_rubric: false,
      rubric_name: null,
      attempt_number: 1,
      answer_preview: '',
    };
  }

  private priorityLabel(n: number): string {
    return n > 0 ? 'high' : n < 0 ? 'low' : 'medium';
  }

  private priorityValue(label: string): number {
    return label === 'high' ? 1 : label === 'low' ? -1 : 0;
  }

  /** The distinct assessments present in the queue, for the filter dropdown. */
  private buildAssessmentOptions(): void {
    const seen = new Map<string, string>();
    for (const it of this.allItems) {
      if (it.assessment_id && !seen.has(it.assessment_id)) seen.set(it.assessment_id, it.assessment_title);
    }
    this.assessmentOptions = Array.from(seen, ([id, title]) => ({ id, title }));
  }

  /**
   * What can be totalled from the attempt list itself. The workload figures the
   * legacy stats endpoint gave (graded today, average grading time) have no
   * source on the new API, so they stay zero rather than guess.
   */
  private computeStats(): void {
    const me = this.session.userId();
    this.stats = {
      total_pending: this.allItems.filter((i) => i.status === 'pending').length,
      total_in_progress: this.allItems.filter((i) => i.status === 'in_progress').length,
      total_graded_today: 0,
      total_overdue: this.allItems.filter((i) => this.isOverdue(i)).length,
      my_assigned: this.allItems.filter((i) => i.assigned_grader_id === me).length,
      avg_grading_time_minutes: 0,
    };
  }

  /** Client-side search, status/priority/assessment filtering, and paging. */
  private applyView(): void {
    let rows = [...this.allItems];
    const term = this.searchTerm.trim().toLowerCase();
    if (term) {
      rows = rows.filter((r) =>
        r.learner_name.toLowerCase().includes(term) ||
        r.assessment_title.toLowerCase().includes(term));
    }
    if (this.filters.status) {
      rows = this.filters.status === 'overdue'
        ? rows.filter((r) => this.isOverdue(r))
        : rows.filter((r) => r.status === this.filters.status);
    }
    if (this.filters.priority) rows = rows.filter((r) => r.priority === this.filters.priority);
    if (this.filters.assessment_id) rows = rows.filter((r) => r.assessment_id === this.filters.assessment_id);

    this.totalItems = rows.length;
    this.totalPages = Math.max(1, Math.ceil(rows.length / this.pageSize));
    if (this.currentPage > this.totalPages) this.currentPage = this.totalPages;
    const start = (this.currentPage - 1) * this.pageSize;
    this.queueItems = rows.slice(start, start + this.pageSize);
  }

  // ==========================================
  // FILTERS
  // ==========================================

  onSearchChange(term: string): void { this.searchSubject.next(term); }

  applyFilters(): void {
    this.currentPage = 1;
    this.selectedItems = [];
    this.allSelected = false;
    this.loadQueue();
  }

  resetFilters(): void {
    this.searchTerm = '';
    this.filters = { status: '', priority: '', question_type: '', assessment_id: '', assigned_to: '' };
    this.applyFilters();
  }

  hasActiveFilters(): boolean {
    return !!(this.searchTerm || this.filters.status || this.filters.priority ||
              this.filters.question_type || this.filters.assessment_id || this.filters.assigned_to);
  }

  filterByStatus(status: string): void {
    this.filters.status = status;
    this.applyFilters();
  }

  filterByAssigned(): void {
    this.filters.assigned_to = 'me';
    this.applyFilters();
  }

  // ==========================================
  // SELECTION
  // ==========================================

  toggleSelectAll(): void {
    this.allSelected = !this.allSelected;
    this.selectedItems = this.allSelected ? this.queueItems.map(i => i.queue_id) : [];
  }

  toggleSelection(id: string): void {
    const idx = this.selectedItems.indexOf(id);
    if (idx === -1) this.selectedItems.push(id); else this.selectedItems.splice(idx, 1);
    this.allSelected = this.selectedItems.length === this.queueItems.length;
  }

  isSelected(id: string): boolean { return this.selectedItems.includes(id); }

  // ==========================================
  // ACTIONS
  // ==========================================

  openGrading(item: GradingQueueItem): void {
    // Grading is keyed by the attempt now, not a queue-item id.
    this.router.navigate(['/admin/grading', item.attempt_id]);
  }

  previewAnswer(item: GradingQueueItem): void {
    this.previewItem = item;
    this.showPreviewModal = true;
  }

  assignToMe(item: GradingQueueItem & { _showMenu?: boolean }): void {
    const me = this.session.userId();
    if (!me) return;
    this.assessments.assignForMarking(item.queue_id, me)
      .pipe(takeUntil(this.destroy$)).subscribe({ next: () => this.loadQueue() });
  }

  assignToGrader(item: GradingQueueItem): void {
    this.selectedItems = [item.queue_id];
    this.showBulkAssignModal = true;
  }

  executeBulkAssign(): void {
    // With no grader roster, assignment goes to the marker themselves.
    const marker = this.bulkAssignGraderId || this.session.userId();
    if (!marker || this.selectedItems.length === 0) return;
    this.assessments.assignManyForMarking(this.selectedItems, [marker])
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => {
          this.showBulkAssignModal = false;
          this.selectedItems = [];
          this.bulkAssignGraderId = null;
          this.loadQueue();
        }
      });
  }

  /** No single auto-assign route: claim every unassigned attempt for the marker. */
  autoAssign(): void {
    const me = this.session.userId();
    if (!me) return;
    this.assessments.gradingQueue({ unassigned: true })
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: ({ items }) => {
          const ids = (items || []).map((a) => a.id);
          if (ids.length === 0) return;
          this.assessments.assignManyForMarking(ids, [me])
            .pipe(takeUntil(this.destroy$)).subscribe({ next: () => this.loadQueue() });
        }
      });
  }

  changePriority(item: GradingQueueItem, priority: string): void {
    this.assessments.prioritiseForMarking(item.queue_id, this.priorityValue(priority))
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => { item.priority = priority; item.priorityNum = this.priorityValue(priority); }
      });
  }

  cyclePriority(item: GradingQueueItem): void {
    const cycle: Record<string, string> = { low: 'medium', medium: 'high', high: 'low' };
    this.changePriority(item, cycle[item.priority] || 'medium');
  }

  /** No batch-priority route, so fan out one call per selected attempt. */
  bulkMarkPriority(priority: string): void {
    const value = this.priorityValue(priority);
    for (const id of this.selectedItems) {
      this.assessments.prioritiseForMarking(id, value).pipe(takeUntil(this.destroy$)).subscribe();
    }
    this.selectedItems = [];
    setTimeout(() => this.loadQueue(), 300);
  }

  skipItem(item: GradingQueueItem): void {
    this.assessments.skipForMarking(item.queue_id)
      .pipe(takeUntil(this.destroy$)).subscribe({ next: () => this.loadQueue() });
  }

  // ==========================================
  // PAGINATION
  // ==========================================

  goToPage(page: number): void {
    if (page < 1 || page > this.totalPages) return;
    this.currentPage = page;
    this.applyView();
  }

  onPageSizeChange(): void {
    this.currentPage = 1;
    this.applyView();
  }

  getPageNumbers(): (string | number)[] {
    const pages: (string | number)[] = [];
    const total = this.totalPages;
    const current = this.currentPage;
    if (total <= 7) { for (let i = 1; i <= total; i++) pages.push(i); return pages; }
    pages.push(1);
    if (current > 3) pages.push('...');
    for (let i = Math.max(2, current - 1); i <= Math.min(total - 1, current + 1); i++) pages.push(i);
    if (current < total - 2) pages.push('...');
    pages.push(total);
    return pages;
  }

  // ==========================================
  // HELPERS
  // ==========================================

  truncate(text: string, len: number): string {
    return text && text.length > len ? text.substring(0, len) + '...' : text || '';
  }

  formatDate(dateStr: string): string {
    if (!dateStr) return '';
    return new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
  }

  getTimeAgo(dateStr: string): string {
    if (!dateStr) return '';
    const now = new Date().getTime();
    const then = new Date(dateStr).getTime();
    const diffMin = Math.floor((now - then) / 60000);
    if (diffMin < 60) return `${diffMin}m ago`;
    const diffH = Math.floor(diffMin / 60);
    if (diffH < 24) return `${diffH}h ago`;
    const diffD = Math.floor(diffH / 24);
    return `${diffD}d ago`;
  }

  isOverdue(item: GradingQueueItem): boolean {
    if (item.status === 'graded') return false;
    const submitted = new Date(item.submitted_at).getTime();
    const now = new Date().getTime();
    return (now - submitted) > 48 * 60 * 60 * 1000;
  }

  formatStatus(status: string): string {
    return status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  formatQuestionType(type: string): string {
    const labels: Record<string, string> = {
      essay: 'Essay', short_answer: 'Short Answer', file_upload: 'File Upload',
      fill_blank: 'Fill in Blank', matching: 'Matching', ordering: 'Ordering'
    };
    return labels[type] || type;
  }

  getPriorityIcon(priority: string): string {
    return priority === 'high' ? '@tui.arrow-up' : priority === 'low' ? '@tui.arrow-down' : '@tui.minus';
  }

  getQuestionTypeIcon(type: string): string {
    const icons: Record<string, string> = {
      essay: '@tui.file-text', short_answer: '@tui.type', file_upload: '@tui.upload',
      fill_blank: '@tui.edit', matching: '@tui.link', ordering: '@tui.list'
    };
    return icons[type] || '@tui.help-circle';
  }

  getAvatarColor(name: string): string {
    const colors = ['#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f', '#edc948', '#b07aa1', '#ff9da7'];
    let hash = 0;
    for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return colors[Math.abs(hash) % colors.length];
  }
}
