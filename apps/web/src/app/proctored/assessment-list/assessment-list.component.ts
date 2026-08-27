// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 10
// Admin: Assessment List Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { Router } from '@angular/router';
import { Subject, takeUntil, debounceTime, distinctUntilChanged } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { GlobalComponent } from '../../global-component';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';

// ==========================================
// INTERFACES
// ==========================================

interface ApiResponse<T> { code: number; status: string; data: T; message: string; }

interface Assessment {
  assessment_id: number;
  title: string;
  description: string;
  assessment_type: 'quiz' | 'exam' | 'practice' | 'survey';
  status: 'draft' | 'published' | 'closed' | 'archived';
  category_id: number;
  category_name: string;
  course_id: number | null;
  course_title: string | null;
  training_id: number | null;
  total_questions: number;
  total_points: number;
  time_limit_minutes: number;
  max_attempts: number;
  passing_score: number;
  passing_type: 'percentage' | 'points';
  proctoring_level: 'none' | 'basic' | 'standard' | 'advanced';
  shuffle_questions: boolean;
  shuffle_options: boolean;
  show_results: boolean;
  availability_start: string | null;
  availability_end: string | null;
  created_by: number;
  created_by_name: string;
  created_at: string;
  updated_at: string;
  // Stats
  total_attempts: number;
  total_completions: number;
  pass_rate: number;
  avg_score: number;
  assigned_count: number;
  section_count: number;
  is_selected?: boolean;
}

interface AssessmentStats {
  total: number;
  published: number;
  draft: number;
  closed: number;
  total_attempts: number;
  total_completions: number;
  avg_pass_rate: number;
}

interface Category {
  category_id: number;
  name: string;
  assessment_count: number;
}

@Component({
  selector: 'app-assessment-list',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>

    <div class="assessment-list-container">
      <!-- Header -->
      <div class="page-header">
        <div>
          <h1 class="page-title"><tui-icon icon="@tui.clipboard-list"></tui-icon> Assessments</h1>
          <p class="page-subtitle">Create and manage assessments for your learners</p>
        </div>
        <button class="btn btn-primary" (click)="createNew()">
          <tui-icon icon="@tui.plus"></tui-icon> New Assessment
        </button>
      </div>

      <!-- Stats Cards -->
      <div class="stats-row">
        <div class="stat-card" (click)="filterByStatus('')" [class.active]="!filters.status">
          <div class="stat-value">{{ stats.total }}</div>
          <div class="stat-label">Total</div>
        </div>
        <div class="stat-card stat-published" (click)="filterByStatus('published')" [class.active]="filters.status === 'published'">
          <div class="stat-value">{{ stats.published }}</div>
          <div class="stat-label">Published</div>
        </div>
        <div class="stat-card stat-draft" (click)="filterByStatus('draft')" [class.active]="filters.status === 'draft'">
          <div class="stat-value">{{ stats.draft }}</div>
          <div class="stat-label">Draft</div>
        </div>
        <div class="stat-card stat-closed" (click)="filterByStatus('closed')" [class.active]="filters.status === 'closed'">
          <div class="stat-value">{{ stats.closed }}</div>
          <div class="stat-label">Closed</div>
        </div>
        <div class="stat-card stat-attempts">
          <div class="stat-value">{{ stats.total_attempts | number }}</div>
          <div class="stat-label">Total Attempts</div>
        </div>
        <div class="stat-card stat-pass">
          <div class="stat-value">{{ stats.avg_pass_rate }}%</div>
          <div class="stat-label">Avg Pass Rate</div>
        </div>
      </div>

      <!-- Toolbar -->
      <div class="toolbar">
        <div class="toolbar-left">
          <div class="search-box">
            <tui-icon icon="@tui.search"></tui-icon>
            <input type="text" placeholder="Search assessments..." [(ngModel)]="filters.search"
                   (ngModelChange)="onSearchChange($event)">
            <button class="clear-btn" *ngIf="filters.search" (click)="filters.search = ''; loadAssessments()">
              <tui-icon icon="@tui.x"></tui-icon>
            </button>
          </div>
          <select class="form-select form-select-sm" [(ngModel)]="filters.type" (change)="loadAssessments()">
            <option value="">All Types</option>
            <option value="quiz">Quiz</option>
            <option value="exam">Exam</option>
            <option value="practice">Practice</option>
            <option value="survey">Survey</option>
          </select>
          <select class="form-select form-select-sm" [(ngModel)]="filters.proctoring" (change)="loadAssessments()">
            <option value="">All Proctoring</option>
            <option value="none">None</option>
            <option value="basic">Basic</option>
            <option value="standard">Standard</option>
            <option value="advanced">Advanced</option>
          </select>
          <select class="form-select form-select-sm" [(ngModel)]="filters.category_id" (change)="loadAssessments()">
            <option value="">All Categories</option>
            <option *ngFor="let c of categories" [value]="c.category_id">{{ c.name }} ({{ c.assessment_count }})</option>
          </select>
        </div>
        <div class="toolbar-right">
          <select class="form-select form-select-sm" [(ngModel)]="sortField" (change)="loadAssessments()">
            <option value="updated_at">Last Updated</option>
            <option value="created_at">Created</option>
            <option value="title">Title</option>
            <option value="total_attempts">Most Attempts</option>
            <option value="pass_rate">Pass Rate</option>
          </select>
          <button class="btn btn-sm btn-secondary" (click)="toggleSortDir()">
            <tui-icon [icon]="sortDir === 'desc' ? '@tui.arrow-down' : '@tui.arrow-up'"></tui-icon>
          </button>
          <button class="btn btn-sm" [ngClass]="viewMode === 'grid' ? 'btn-primary' : 'btn-secondary'"
                  (click)="viewMode = 'grid'">
            <tui-icon icon="@tui.grid"></tui-icon>
          </button>
          <button class="btn btn-sm" [ngClass]="viewMode === 'table' ? 'btn-primary' : 'btn-secondary'"
                  (click)="viewMode = 'table'">
            <tui-icon icon="@tui.list"></tui-icon>
          </button>
        </div>
      </div>

      <!-- Bulk Actions -->
      <div class="bulk-bar" *ngIf="selectedCount > 0">
        <span>{{ selectedCount }} selected</span>
        <button class="btn btn-sm btn-success" (click)="bulkAction('publish')">Publish</button>
        <button class="btn btn-sm btn-outline-warning" (click)="bulkAction('close')">Close</button>
        <button class="btn btn-sm btn-secondary" (click)="bulkAction('archive')">Archive</button>
        <button class="btn btn-sm btn-outline-danger" (click)="bulkAction('delete')">Delete</button>
        <button class="btn btn-sm btn-link" (click)="clearSelection()">Cancel</button>
      </div>

      <!-- GRID VIEW -->
      <div class="assessment-grid" *ngIf="!loading && viewMode === 'grid' && assessments.length > 0">
        <div class="assessment-card" *ngFor="let a of assessments" [class.selected-card]="a.is_selected">
          <div class="card-header-bar">
            <input type="checkbox" [(ngModel)]="a.is_selected" (click)="$event.stopPropagation()">
            <span class="type-badge" [ngClass]="'type-' + a.assessment_type">{{ a.assessment_type }}</span>
            <span class="status-dot" [ngClass]="'dot-' + a.status" [title]="a.status"></span>
          </div>
          <div class="card-body-content" (click)="editAssessment(a)">
            <h3 class="card-title">{{ a.title }}</h3>
            <p class="card-desc" *ngIf="a.description">{{ a.description | slice:0:80 }}{{ a.description.length > 80 ? '...' : '' }}</p>
            <div class="card-meta">
              <span><tui-icon icon="@tui.help-circle"></tui-icon> {{ a.total_questions }} Q</span>
              <span><tui-icon icon="@tui.star"></tui-icon> {{ a.total_points }} pts</span>
              <span *ngIf="a.time_limit_minutes"><tui-icon icon="@tui.clock"></tui-icon> {{ a.time_limit_minutes }}m</span>
              <span *ngIf="a.proctoring_level !== 'none'" class="proctor-chip" [ngClass]="'proctor-' + a.proctoring_level">
                <tui-icon icon="@tui.shield"></tui-icon> {{ a.proctoring_level }}
              </span>
            </div>
            <div class="card-stats">
              <div class="mini-stat">
                <span class="mini-val">{{ a.total_attempts }}</span>
                <span class="mini-lbl">Attempts</span>
              </div>
              <div class="mini-stat">
                <span class="mini-val">{{ a.total_completions }}</span>
                <span class="mini-lbl">Completed</span>
              </div>
              <div class="mini-stat">
                <span class="mini-val" [ngClass]="getPassRateClass(a.pass_rate)">{{ a.pass_rate }}%</span>
                <span class="mini-lbl">Pass Rate</span>
              </div>
              <div class="mini-stat">
                <span class="mini-val">{{ a.avg_score | number:'1.0-1' }}%</span>
                <span class="mini-lbl">Avg Score</span>
              </div>
            </div>
          </div>
          <div class="card-footer-bar">
            <span class="card-date">{{ formatDate(a.updated_at) }}</span>
            <div class="card-actions">
              <button class="btn btn-sm btn-ghost" (click)="previewAssessment(a)" title="Preview">
                <tui-icon icon="@tui.eye"></tui-icon>
              </button>
              <button class="btn btn-sm btn-ghost" (click)="cloneAssessment(a)" title="Clone">
                <tui-icon icon="@tui.copy"></tui-icon>
              </button>
              <button class="btn btn-sm btn-ghost" (click)="manageQuestions(a)" title="Questions">
                <tui-icon icon="@tui.list-ordered"></tui-icon>
              </button>
              <button class="btn btn-sm btn-ghost" (click)="viewResults(a)" title="Results">
                <tui-icon icon="@tui.bar-chart-2"></tui-icon>
              </button>
              <div class="dropdown-wrapper">
                <button class="btn btn-sm btn-ghost" (click)="toggleMenu(a.assessment_id, $event)">
                  <tui-icon icon="@tui.more-vertical"></tui-icon>
                </button>
                <div class="dropdown-menu-custom" *ngIf="openMenuId === a.assessment_id">
                  <button (click)="editAssessment(a)"><tui-icon icon="@tui.edit-3"></tui-icon> Edit</button>
                  <button (click)="manageAssignments(a)"><tui-icon icon="@tui.users"></tui-icon> Assignments</button>
                  <button *ngIf="a.status === 'draft'" (click)="publishAssessment(a)">
                    <tui-icon icon="@tui.send"></tui-icon> Publish
                  </button>
                  <button *ngIf="a.status === 'published'" (click)="closeAssessment(a)">
                    <tui-icon icon="@tui.lock"></tui-icon> Close
                  </button>
                  <hr>
                  <button class="text-danger" (click)="deleteAssessment(a)">
                    <tui-icon icon="@tui.trash-2"></tui-icon> Delete
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- TABLE VIEW -->
      <div class="table-responsive" *ngIf="!loading && viewMode === 'table' && assessments.length > 0">
        <table class="table table-hover">
          <thead>
            <tr>
              <th width="30"><input type="checkbox" [checked]="allSelected" (change)="toggleSelectAll()"></th>
              <th>Assessment</th>
              <th>Type</th>
              <th>Status</th>
              <th class="text-center">Questions</th>
              <th class="text-center">Attempts</th>
              <th class="text-center">Pass Rate</th>
              <th>Proctoring</th>
              <th>Updated</th>
              <th width="100">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr *ngFor="let a of assessments" [class.table-active]="a.is_selected">
              <td><input type="checkbox" [(ngModel)]="a.is_selected"></td>
              <td>
                <a class="assess-link" (click)="editAssessment(a)">{{ a.title }}</a>
                <small class="text-muted d-block" *ngIf="a.course_title">{{ a.course_title }}</small>
              </td>
              <td><span class="type-badge sm" [ngClass]="'type-' + a.assessment_type">{{ a.assessment_type }}</span></td>
              <td><span class="status-badge" [ngClass]="'status-' + a.status">{{ a.status }}</span></td>
              <td class="text-center">{{ a.total_questions }}</td>
              <td class="text-center">{{ a.total_attempts }}</td>
              <td class="text-center"><span [ngClass]="getPassRateClass(a.pass_rate)">{{ a.pass_rate }}%</span></td>
              <td>
                <span class="proctor-chip sm" [ngClass]="'proctor-' + a.proctoring_level" *ngIf="a.proctoring_level !== 'none'">
                  {{ a.proctoring_level }}
                </span>
                <span class="text-muted" *ngIf="a.proctoring_level === 'none'">-</span>
              </td>
              <td><small>{{ formatDate(a.updated_at) }}</small></td>
              <td>
                <div class="table-actions">
                  <button class="btn btn-sm btn-ghost" (click)="editAssessment(a)" title="Edit">
                    <tui-icon icon="@tui.edit-3"></tui-icon>
                  </button>
                  <button class="btn btn-sm btn-ghost" (click)="cloneAssessment(a)" title="Clone">
                    <tui-icon icon="@tui.copy"></tui-icon>
                  </button>
                  <button class="btn btn-sm btn-ghost text-danger" (click)="deleteAssessment(a)" title="Delete">
                    <tui-icon icon="@tui.trash-2"></tui-icon>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div class="pagination-bar" *ngIf="totalPages > 1">
        <span class="text-muted small">{{ totalItems }} assessments</span>
        <div class="pagination-controls">
          <button class="btn btn-sm btn-secondary" (click)="page = 1; loadAssessments()" [disabled]="page <= 1">
            <tui-icon icon="@tui.chevrons-left"></tui-icon>
          </button>
          <button class="btn btn-sm btn-secondary" (click)="page = page - 1; loadAssessments()" [disabled]="page <= 1">
            <tui-icon icon="@tui.chevron-left"></tui-icon>
          </button>
          <span class="page-info">Page {{ page }} of {{ totalPages }}</span>
          <button class="btn btn-sm btn-secondary" (click)="page = page + 1; loadAssessments()" [disabled]="page >= totalPages">
            <tui-icon icon="@tui.chevron-right"></tui-icon>
          </button>
          <button class="btn btn-sm btn-secondary" (click)="page = totalPages; loadAssessments()" [disabled]="page >= totalPages">
            <tui-icon icon="@tui.chevrons-right"></tui-icon>
          </button>
        </div>
      </div>

      <!-- Empty State -->
      <div class="empty-state" *ngIf="!loading && assessments.length === 0">
        <tui-icon icon="@tui.clipboard"></tui-icon>
        <h4>{{ hasActiveFilters ? 'No matching assessments' : 'No assessments yet' }}</h4>
        <p class="text-muted">{{ hasActiveFilters ? 'Try adjusting your filters' : 'Create your first assessment to get started' }}</p>
        <button class="btn btn-primary" *ngIf="!hasActiveFilters" (click)="createNew()">
          <tui-icon icon="@tui.plus"></tui-icon> Create Assessment
        </button>
        <button class="btn btn-secondary" *ngIf="hasActiveFilters" (click)="resetFilters()">Reset Filters</button>
      </div>

      <!-- Loading -->
      <div class="loading-state" *ngIf="loading">
        <div class="spinner-border text-primary"></div>
        <p>Loading assessments...</p>
      </div>

      <!-- Delete Confirm Modal -->
      <div class="modal-overlay" *ngIf="showDeleteConfirm" (click)="showDeleteConfirm = false">
        <div class="modal-dialog" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h5><tui-icon icon="@tui.alert-triangle"></tui-icon> Confirm Delete</h5>
            <button class="btn-close" (click)="showDeleteConfirm = false"></button>
          </div>
          <div class="modal-body">
            <p>Are you sure you want to delete <strong>{{ deleteTarget?.title }}</strong>?</p>
            <p class="text-muted small" *ngIf="deleteTarget?.total_attempts">
              This assessment has {{ deleteTarget?.total_attempts }} attempts. All data will be permanently lost.
            </p>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary" (click)="showDeleteConfirm = false">Cancel</button>
            <button class="btn btn-danger" (click)="confirmDelete()" [disabled]="deleting">
              <span class="spinner-border spinner-border-sm me-1" *ngIf="deleting"></span> Delete
            </button>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .assessment-list-container { padding: 1.5rem; }
    .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.25rem; }
    .page-title { font-size: 1.375rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; margin: 0; }
    .page-subtitle { color: #6c757d; font-size: 0.8125rem; margin: 0.125rem 0 0; }

    /* Stats */
    .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 0.75rem; margin-bottom: 1.25rem; }
    .stat-card { background: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 0.75rem 1rem;
      cursor: pointer; transition: all 0.2s; text-align: center; }
    .stat-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
    .stat-card.active { border-color: #0d6efd; background: #f0f6ff; }
    .stat-value { font-size: 1.375rem; font-weight: 700; }
    .stat-label { font-size: 0.6875rem; color: #6c757d; text-transform: uppercase; font-weight: 600; }
    .stat-published .stat-value { color: #198754; }
    .stat-draft .stat-value { color: #6c757d; }
    .stat-closed .stat-value { color: #dc3545; }
    .stat-attempts .stat-value { color: #0d6efd; }
    .stat-pass .stat-value { color: #0d6efd; }

    /* Toolbar */
    .toolbar { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1rem;
      flex-wrap: wrap; }
    .toolbar-left, .toolbar-right { display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
    .search-box { position: relative; display: flex; align-items: center; }
    .search-box tui-icon { position: absolute; left: 0.5rem; font-size: 0.875rem; color: #adb5bd; }
    .search-box input { padding: 0.375rem 2rem 0.375rem 1.75rem; border: 1px solid #dee2e6; border-radius: 0.375rem;
      font-size: 0.8125rem; width: 220px; }
    .search-box input:focus { outline: none; border-color: #86b7fe; }
    .clear-btn { position: absolute; right: 0.375rem; background: none; border: none; color: #adb5bd; cursor: pointer; padding: 0; }
    .form-select-sm { font-size: 0.8125rem; max-width: 150px; }

    /* Bulk Bar */
    .bulk-bar { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1rem; background: #e7f1ff;
      border-radius: 0.5rem; margin-bottom: 1rem; font-size: 0.8125rem; font-weight: 500; }

    /* Grid */
    .assessment-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem; }
    .assessment-card { background: #fff; border: 1px solid #e9ecef; border-radius: 0.625rem; overflow: hidden;
      transition: all 0.2s; }
    .assessment-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
    .assessment-card.selected-card { border-color: #0d6efd; background: #fafcff; }
    .card-header-bar { display: flex; align-items: center; justify-content: space-between; padding: 0.5rem 0.75rem;
      background: #f8f9fa; border-bottom: 1px solid #f0f0f0; }
    .card-body-content { padding: 0.75rem; cursor: pointer; }
    .card-title { font-size: 0.9375rem; font-weight: 600; margin: 0 0 0.25rem; }
    .card-desc { font-size: 0.75rem; color: #6c757d; margin: 0 0 0.5rem; line-height: 1.4; }
    .card-meta { display: flex; gap: 0.625rem; flex-wrap: wrap; font-size: 0.6875rem; color: #6c757d; margin-bottom: 0.625rem; }
    .card-meta span { display: flex; align-items: center; gap: 0.125rem; }
    .card-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.25rem; text-align: center;
      background: #f8f9fa; margin: 0 -0.75rem -0.75rem; padding: 0.5rem 0.75rem; border-top: 1px solid #f0f0f0; }
    .mini-stat { display: flex; flex-direction: column; }
    .mini-val { font-size: 0.875rem; font-weight: 600; }
    .mini-lbl { font-size: 0.5625rem; color: #6c757d; text-transform: uppercase; }
    .card-footer-bar { display: flex; align-items: center; justify-content: space-between; padding: 0.375rem 0.75rem;
      border-top: 1px solid #f0f0f0; }
    .card-date { font-size: 0.6875rem; color: #adb5bd; }
    .card-actions { display: flex; gap: 0.125rem; }
    .btn-ghost { background: none; border: none; color: #6c757d; cursor: pointer; padding: 0.25rem; border-radius: 0.25rem; }
    .btn-ghost:hover { background: #f0f0f0; color: #212529; }

    /* Badges */
    .type-badge { font-size: 0.625rem; padding: 0.125rem 0.5rem; border-radius: 1rem; font-weight: 600; text-transform: uppercase; }
    .type-badge.sm { font-size: 0.5625rem; }
    .type-quiz { background: #cfe2ff; color: #084298; }
    .type-exam { background: #f8d7da; color: #842029; }
    .type-practice { background: #d1e7dd; color: #0f5132; }
    .type-survey { background: #fff3cd; color: #664d03; }
    .status-dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
    .dot-published { background: #198754; }
    .dot-draft { background: #adb5bd; }
    .dot-closed { background: #dc3545; }
    .dot-archived { background: #6c757d; }
    .status-badge { font-size: 0.6875rem; padding: 0.125rem 0.5rem; border-radius: 0.25rem; font-weight: 500; text-transform: capitalize; }
    .status-published { background: #d1e7dd; color: #0f5132; }
    .status-draft { background: #e2e3e5; color: #495057; }
    .status-closed { background: #f8d7da; color: #842029; }
    .status-archived { background: #e2e3e5; color: #6c757d; }
    .proctor-chip { font-size: 0.625rem; padding: 0.0625rem 0.375rem; border-radius: 0.25rem; text-transform: capitalize; font-weight: 500;
      display: inline-flex; align-items: center; gap: 0.125rem; }
    .proctor-chip.sm { font-size: 0.5625rem; }
    .proctor-basic { background: #cfe2ff; color: #084298; }
    .proctor-standard { background: #fff3cd; color: #664d03; }
    .proctor-advanced { background: #f8d7da; color: #842029; }

    .pass-high { color: #198754; font-weight: 600; }
    .pass-mid { color: #fd7e14; font-weight: 600; }
    .pass-low { color: #dc3545; font-weight: 600; }

    /* Table */
    .table { font-size: 0.8125rem; }
    .table th { font-size: 0.6875rem; text-transform: uppercase; color: #6c757d; font-weight: 600; white-space: nowrap; }
    .assess-link { color: #0d6efd; cursor: pointer; text-decoration: none; font-weight: 500; }
    .assess-link:hover { text-decoration: underline; }
    .table-actions { display: flex; gap: 0.25rem; }

    /* Dropdown */
    .dropdown-wrapper { position: relative; }
    .dropdown-menu-custom { position: absolute; right: 0; top: 100%; background: #fff; border: 1px solid #e9ecef;
      border-radius: 0.5rem; box-shadow: 0 4px 12px rgba(0,0,0,0.1); z-index: 20; min-width: 160px; padding: 0.25rem 0; }
    .dropdown-menu-custom button { display: flex; align-items: center; gap: 0.5rem; width: 100%; padding: 0.375rem 0.75rem;
      border: none; background: none; font-size: 0.8125rem; cursor: pointer; text-align: left; }
    .dropdown-menu-custom button:hover { background: #f8f9fa; }
    .dropdown-menu-custom hr { margin: 0.25rem 0; border-color: #f0f0f0; }

    /* Pagination */
    .pagination-bar { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; margin-top: 0.5rem; }
    .pagination-controls { display: flex; align-items: center; gap: 0.375rem; }
    .page-info { font-size: 0.8125rem; color: #6c757d; min-width: 100px; text-align: center; }

    .empty-state, .loading-state { text-align: center; padding: 3rem; }
    .empty-state tui-icon { font-size: 2.5rem; color: #adb5bd; margin-bottom: 0.75rem; }

    /* Modal */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1050;
      display: flex; align-items: center; justify-content: center; }
    .modal-dialog { background: #fff; border-radius: 0.75rem; max-width: 480px; width: 90%; }
    .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem;
      border-bottom: 1px solid #e9ecef; }
    .modal-header h5 { display: flex; align-items: center; gap: 0.5rem; margin: 0; font-size: 1rem; }
    .modal-body { padding: 1.25rem; }
    .modal-footer { display: flex; justify-content: flex-end; gap: 0.5rem; padding: 0.75rem 1.25rem; border-top: 1px solid #e9ecef; }

    @media (max-width: 768px) {
      .assessment-grid { grid-template-columns: 1fr; }
      .toolbar { flex-direction: column; }
      .stats-row { grid-template-columns: repeat(3, 1fr); }
    }
  `]
})
export class AssessmentListComponent implements OnInit, OnDestroy {
  private http = inject(HttpClient);
  private router = inject(Router);
  private destroy$ = new Subject<void>();
  private searchSubject = new Subject<string>();

  assessments: Assessment[] = [];
  categories: Category[] = [];
  stats: AssessmentStats = { total: 0, published: 0, draft: 0, closed: 0, total_attempts: 0, total_completions: 0, avg_pass_rate: 0 };

  loading = false;
  viewMode: 'grid' | 'table' = 'grid';
  page = 1;
  pageSize = 12;
  totalItems = 0;
  sortField = 'updated_at';
  sortDir = 'desc';
  openMenuId: number | null = null;

  // Delete
  showDeleteConfirm = false;
  deleteTarget: Assessment | null = null;
  deleting = false;

  filters = { search: '', status: '', type: '', proctoring: '', category_id: '' };

  get totalPages(): number { return Math.ceil(this.totalItems / this.pageSize) || 1; }
  get selectedCount(): number { return this.assessments.filter(a => a.is_selected).length; }
  get allSelected(): boolean { return this.assessments.length > 0 && this.assessments.every(a => a.is_selected); }
  get hasActiveFilters(): boolean {
    return !!(this.filters.search || this.filters.status || this.filters.type || this.filters.proctoring || this.filters.category_id);
  }

  ngOnInit(): void {
    this.searchSubject.pipe(debounceTime(400), distinctUntilChanged(), takeUntil(this.destroy$))
      .subscribe(() => { this.page = 1; this.loadAssessments(); });
    this.loadCategories();
    this.loadStats();
    this.loadAssessments();
    document.addEventListener('click', this.closeMenuOnClick.bind(this));
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
    document.removeEventListener('click', this.closeMenuOnClick.bind(this));
  }

  onSearchChange(val: string): void { this.searchSubject.next(val); }

  // ==========================================
  // DATA LOADING
  // ==========================================

  loadAssessments(): void {
    this.loading = true;
    const fd = new FormData();
    fd.append('action', 'get_assessments');
    fd.append('page', this.page.toString());
    fd.append('limit', this.pageSize.toString());
    fd.append('sort', this.sortField);
    fd.append('order', this.sortDir);
    if (this.filters.search) fd.append('search', this.filters.search);
    if (this.filters.status) fd.append('status', this.filters.status);
    if (this.filters.type) fd.append('type', this.filters.type);
    if (this.filters.proctoring) fd.append('proctoring_level', this.filters.proctoring);
    if (this.filters.category_id) fd.append('category_id', this.filters.category_id);

    this.http.post<ApiResponse<{ items: Assessment[], total: number }>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => {
          if (res.code === 1) {
            this.assessments = (res.data.items || []).map(a => ({ ...a, is_selected: false }));
            this.totalItems = res.data.total || 0;
          }
          this.loading = false;
        },
        error: () => { this.loading = false; }
      });
  }

  loadStats(): void {
    const fd = new FormData();
    fd.append('action', 'get_assessment_stats');
    this.http.post<ApiResponse<AssessmentStats>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => { if (res.code === 1) this.stats = res.data; }
      });
  }

  loadCategories(): void {
    const fd = new FormData();
    fd.append('action', 'get_categories');
    this.http.post<ApiResponse<Category[]>>(`${GlobalComponent.baseURL}/user/question-bank`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => { if (res.code === 1) this.categories = res.data || []; }
      });
  }

  // ==========================================
  // ACTIONS
  // ==========================================

  createNew(): void { this.router.navigate(['/admin/assessments/new']); }
  editAssessment(a: Assessment): void { this.router.navigate(['/admin/assessments', a.assessment_id, 'edit']); }
  previewAssessment(a: Assessment): void { this.router.navigate(['/admin/assessments', a.assessment_id, 'preview']); }
  manageQuestions(a: Assessment): void { this.router.navigate(['/admin/assessments', a.assessment_id, 'questions']); }
  manageAssignments(a: Assessment): void { this.router.navigate(['/admin/assessments', a.assessment_id, 'assignments']); }
  viewResults(a: Assessment): void { this.router.navigate(['/admin/assessments', a.assessment_id, 'results']); }

  cloneAssessment(a: Assessment): void {
    const fd = new FormData();
    fd.append('action', 'clone_assessment');
    fd.append('assessment_id', a.assessment_id.toString());
    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => { if (res.code === 1) { this.loadAssessments(); this.loadStats(); } }
      });
  }

  publishAssessment(a: Assessment): void { this.updateStatus(a, 'published'); }
  closeAssessment(a: Assessment): void { this.updateStatus(a, 'closed'); }

  private updateStatus(a: Assessment, status: string): void {
    const fd = new FormData();
    fd.append('action', 'update_assessment_status');
    fd.append('assessment_id', a.assessment_id.toString());
    fd.append('status', status);
    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => { if (res.code === 1) { a.status = status as any; this.loadStats(); } }
      });
    this.openMenuId = null;
  }

  deleteAssessment(a: Assessment): void {
    this.deleteTarget = a;
    this.showDeleteConfirm = true;
    this.openMenuId = null;
  }

  confirmDelete(): void {
    if (!this.deleteTarget) return;
    this.deleting = true;
    const fd = new FormData();
    fd.append('action', 'delete_assessment');
    fd.append('assessment_id', this.deleteTarget.assessment_id.toString());
    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => {
          if (res.code === 1) { this.loadAssessments(); this.loadStats(); }
          this.deleting = false;
          this.showDeleteConfirm = false;
        },
        error: () => { this.deleting = false; }
      });
  }

  bulkAction(action: string): void {
    const ids = this.assessments.filter(a => a.is_selected).map(a => a.assessment_id);
    if (ids.length === 0) return;
    const fd = new FormData();
    fd.append('action', 'bulk_assessment_action');
    fd.append('bulk_action', action);
    fd.append('assessment_ids', JSON.stringify(ids));
    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/assessment`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => { if (res.code === 1) { this.loadAssessments(); this.loadStats(); } }
      });
  }

  // ==========================================
  // UI HELPERS
  // ==========================================

  filterByStatus(status: string): void {
    this.filters.status = status;
    this.page = 1;
    this.loadAssessments();
  }

  toggleSortDir(): void {
    this.sortDir = this.sortDir === 'desc' ? 'asc' : 'desc';
    this.loadAssessments();
  }

  toggleSelectAll(): void {
    const newVal = !this.allSelected;
    this.assessments.forEach(a => a.is_selected = newVal);
  }

  clearSelection(): void { this.assessments.forEach(a => a.is_selected = false); }

  resetFilters(): void {
    this.filters = { search: '', status: '', type: '', proctoring: '', category_id: '' };
    this.page = 1;
    this.loadAssessments();
  }

  toggleMenu(id: number, e: Event): void {
    e.stopPropagation();
    this.openMenuId = this.openMenuId === id ? null : id;
  }

  closeMenuOnClick(): void { this.openMenuId = null; }

  getPassRateClass(rate: number): string {
    if (rate >= 70) return 'pass-high';
    if (rate >= 40) return 'pass-mid';
    return 'pass-low';
  }

  formatDate(d: string): string {
    return d ? new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
  }
}
