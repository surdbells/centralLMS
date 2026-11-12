// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 10
// Admin: Assessment List Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '../../translate.pipe';
import { Subject, forkJoin, of, takeUntil, debounceTime, distinctUntilChanged } from 'rxjs';
import { catchError, map } from 'rxjs/operators';
import { TuiIcon } from '@taiga-ui/core';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import {
  AssessmentAdminService,
  type Assessment as ApiAssessment,
  type AssessmentStatus,
} from '../assessment-admin.service';

// ==========================================
// INTERFACES — the view-model this screen renders. The REST API is
// camelCase and UUID-keyed; the template stays as it was, so the API rows
// are mapped into this shape on the way in.
// ==========================================

interface Assessment {
  assessment_id: string;
  title: string;
  description: string;
  assessment_type: 'quiz' | 'exam' | 'practice' | 'survey';
  status: 'draft' | 'published' | 'closed' | 'archived';
  category_id: string | null;
  course_title: string | null;
  total_questions: number;
  total_points: number;
  time_limit_minutes: number;
  proctoring_level: 'none' | 'basic' | 'standard' | 'advanced';
  updated_at: string;
  // Stats
  total_attempts: number;
  total_completions: number;
  pass_rate: number;
  avg_score: number;
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
  category_id: string;
  name: string;
  assessment_count: number;
}

@Component({
  selector: 'app-assessment-list',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, TuiIcon, AdmintopComponent, SkeletonComponent, TranslatePipe],
  template: `
    <app-admintop></app-admintop>

    <main class="main">
    <div class="ui-page assessment-list-container">
      <!-- Header -->
      <header class="ui-page-header">
        <a routerLink="/admin/assessments" class="ui-page-header__back" [attr.aria-label]="'common.back' | translate"><i class="bi bi-chevron-left"></i></a>
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-clipboard-check"></i></div>
        <div class="ui-page-header__titles">
          <h1>Assessments</h1>
          <p>Create and manage assessments for your learners</p>
        </div>
        <div class="ui-page-header__actions">
          <button type="button" class="btn btn-primary" (click)="createNew()">
            <i class="bi bi-plus-lg"></i> New Assessment
          </button>
        </div>
      </header>

      <!-- Stats Cards -->
      <div class="ui-kpi-grid stats-row">
        <button type="button" class="ui-kpi ui-kpi--brand" (click)="filterByStatus('')" [class.is-active]="!filters.status">
          <span class="ui-kpi__label"><i class="bi bi-collection"></i> Total</span>
          <span class="ui-kpi__value">{{ stats.total }}</span>
        </button>
        <button type="button" class="ui-kpi ui-kpi--success" (click)="filterByStatus('published')" [class.is-active]="filters.status === 'published'">
          <span class="ui-kpi__label"><i class="bi bi-broadcast"></i> Published</span>
          <span class="ui-kpi__value">{{ stats.published }}</span>
        </button>
        <button type="button" class="ui-kpi" (click)="filterByStatus('draft')" [class.is-active]="filters.status === 'draft'">
          <span class="ui-kpi__label"><i class="bi bi-pencil"></i> Draft</span>
          <span class="ui-kpi__value">{{ stats.draft }}</span>
        </button>
        <button type="button" class="ui-kpi ui-kpi--danger" (click)="filterByStatus('closed')" [class.is-active]="filters.status === 'closed'">
          <span class="ui-kpi__label"><i class="bi bi-lock"></i> Closed</span>
          <span class="ui-kpi__value">{{ stats.closed }}</span>
        </button>
        <div class="ui-kpi ui-kpi--info">
          <span class="ui-kpi__label"><i class="bi bi-people"></i> Total Attempts</span>
          <span class="ui-kpi__value">{{ stats.total_attempts | number }}</span>
        </div>
        <div class="ui-kpi ui-kpi--success">
          <span class="ui-kpi__label"><i class="bi bi-percent"></i> Avg Pass Rate</span>
          <span class="ui-kpi__value">{{ stats.avg_pass_rate }}<small>%</small></span>
        </div>
      </div>

      <section class="ui-section">
        <!-- Toolbar -->
        <div class="ui-toolbar">
          <div class="ui-filters">
            <label class="ui-search search-box">
              <i class="bi bi-search"></i>
              <input type="search" placeholder="Search assessments..." aria-label="Search assessments" [(ngModel)]="filters.search"
                     (ngModelChange)="onSearchChange($event)">
              <button type="button" class="clear-btn" *ngIf="filters.search" (click)="filters.search = ''; loadAssessments()" aria-label="Clear search">
                <i class="bi bi-x-lg"></i>
              </button>
            </label>
            <select class="form-select" [(ngModel)]="filters.type" (change)="loadAssessments()" aria-label="Type">
              <option value="">All Types</option>
              <option value="quiz">Quiz</option>
              <option value="exam">Exam</option>
              <option value="practice">Practice</option>
              <option value="survey">Survey</option>
            </select>
            <select class="form-select" [(ngModel)]="filters.proctoring" (change)="loadAssessments()" aria-label="Proctoring">
              <option value="">All Proctoring</option>
              <option value="none">None</option>
              <option value="basic">Basic</option>
              <option value="standard">Standard</option>
              <option value="advanced">Advanced</option>
            </select>
            <select class="form-select" [(ngModel)]="filters.category_id" (change)="loadAssessments()" aria-label="Category">
              <option value="">All Categories</option>
              <option *ngFor="let c of categories" [value]="c.category_id">{{ c.name }} ({{ c.assessment_count }})</option>
            </select>
          </div>
          <div class="ui-filters">
            <select class="form-select" [(ngModel)]="sortField" (change)="loadAssessments()" aria-label="Sort by">
              <option value="updated_at">Last Updated</option>
              <option value="created_at">Created</option>
              <option value="title">Title</option>
              <option value="total_attempts">Most Attempts</option>
              <option value="pass_rate">Pass Rate</option>
            </select>
            <button type="button" class="btn btn-outline-secondary icon-btn" (click)="toggleSortDir()" [attr.aria-label]="sortDir === 'desc' ? 'Descending' : 'Ascending'">
              <i class="bi" [class.bi-sort-down]="sortDir === 'desc'" [class.bi-sort-up]="sortDir !== 'desc'"></i>
            </button>
            <div class="view-toggle" role="group" aria-label="View">
              <button type="button" class="vt" [class.active]="viewMode === 'grid'" (click)="viewMode = 'grid'" aria-label="Grid view">
                <i class="bi bi-grid"></i>
              </button>
              <button type="button" class="vt" [class.active]="viewMode === 'table'" (click)="viewMode = 'table'" aria-label="Table view">
                <i class="bi bi-list-ul"></i>
              </button>
            </div>
          </div>
        </div>

        <!-- Bulk Actions -->
        <div class="bulk-bar" *ngIf="selectedCount > 0">
          <span class="ui-pill ui-pill--brand">{{ selectedCount }} selected</span>
          <button type="button" class="btn btn-sm btn-primary" (click)="bulkAction('publish')">Publish</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" (click)="bulkAction('close')">Close</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" (click)="bulkAction('archive')">Archive</button>
          <button type="button" class="btn btn-sm btn-outline-danger" (click)="bulkAction('delete')">Delete</button>
          <button type="button" class="btn btn-sm btn-link" (click)="clearSelection()">Cancel</button>
        </div>

        <!-- GRID VIEW -->
        <div class="ui-grid assessment-grid" *ngIf="!loading && viewMode === 'grid' && assessments.length > 0">
          <article class="ui-card assessment-card" *ngFor="let a of assessments" [class.selected-card]="a.is_selected">
            <div class="card-header-bar">
              <input type="checkbox" class="form-check-input" [(ngModel)]="a.is_selected" (click)="$event.stopPropagation()" aria-label="Select">
              <span class="ui-pill type-badge" [ngClass]="'type-' + a.assessment_type">{{ a.assessment_type }}</span>
              <span class="ui-pill status-badge ms-auto" [ngClass]="'status-' + a.status" [title]="a.status"><span class="ui-pill__dot"></span>{{ a.status }}</span>
            </div>
            <div class="ui-card__body card-body-content" (click)="editAssessment(a)">
              <h3 class="ui-card__title">{{ a.title }}</h3>
              <p class="card-desc" *ngIf="a.description">{{ a.description | slice:0:80 }}{{ a.description.length > 80 ? '...' : '' }}</p>
              <div class="ui-card__meta card-meta">
                <span><i class="bi bi-question-circle"></i> {{ a.total_questions }} Q</span>
                <span><i class="bi bi-star"></i> {{ a.total_points }} pts</span>
                <span *ngIf="a.time_limit_minutes"><i class="bi bi-clock"></i> {{ a.time_limit_minutes }}m</span>
                <span *ngIf="a.proctoring_level !== 'none'" class="ui-pill proctor-chip" [ngClass]="'proctor-' + a.proctoring_level">
                  <i class="bi bi-shield-check"></i> {{ a.proctoring_level }}
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
            <div class="ui-card__footer card-footer-bar">
              <span class="card-date">{{ formatDate(a.updated_at) }}</span>
              <div class="card-actions">
                <button type="button" class="btn-ghost" (click)="previewAssessment(a)" title="Preview" aria-label="Preview">
                  <i class="bi bi-eye"></i>
                </button>
                <button type="button" class="btn-ghost" (click)="cloneAssessment(a)" title="Clone" aria-label="Clone">
                  <i class="bi bi-copy"></i>
                </button>
                <button type="button" class="btn-ghost" (click)="manageQuestions(a)" title="Questions" aria-label="Questions">
                  <i class="bi bi-list-ol"></i>
                </button>
                <button type="button" class="btn-ghost" (click)="viewResults(a)" title="Results" aria-label="Results">
                  <i class="bi bi-bar-chart"></i>
                </button>
                <div class="dropdown-wrapper">
                  <button type="button" class="btn-ghost" (click)="toggleMenu(a.assessment_id, $event)" aria-label="More actions">
                    <i class="bi bi-three-dots-vertical"></i>
                  </button>
                  <div class="dropdown-menu-custom" *ngIf="openMenuId === a.assessment_id">
                    <button type="button" (click)="editAssessment(a)"><i class="bi bi-pencil-square"></i> Edit</button>
                    <button type="button" (click)="manageAssignments(a)"><i class="bi bi-people"></i> Assignments</button>
                    <button type="button" *ngIf="a.status === 'draft'" (click)="publishAssessment(a)">
                      <i class="bi bi-send"></i> Publish
                    </button>
                    <button type="button" *ngIf="a.status === 'published'" (click)="closeAssessment(a)">
                      <i class="bi bi-lock"></i> Close
                    </button>
                    <hr>
                    <button type="button" class="text-danger" (click)="deleteAssessment(a)">
                      <i class="bi bi-trash3"></i> Delete
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </article>
        </div>

        <!-- TABLE VIEW -->
        <div class="ui-table-wrap" *ngIf="!loading && viewMode === 'table' && assessments.length > 0">
          <table class="table align-middle">
            <thead>
              <tr>
                <th width="30"><input type="checkbox" class="form-check-input" [checked]="allSelected" (change)="toggleSelectAll()" aria-label="Select all"></th>
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
                <td><input type="checkbox" class="form-check-input" [(ngModel)]="a.is_selected" aria-label="Select"></td>
                <td>
                  <a class="assess-link" (click)="editAssessment(a)">{{ a.title }}</a>
                  <small class="ui-muted d-block" *ngIf="a.course_title">{{ a.course_title }}</small>
                </td>
                <td><span class="ui-pill type-badge sm" [ngClass]="'type-' + a.assessment_type">{{ a.assessment_type }}</span></td>
                <td><span class="ui-pill status-badge" [ngClass]="'status-' + a.status"><span class="ui-pill__dot"></span>{{ a.status }}</span></td>
                <td class="text-center">{{ a.total_questions }}</td>
                <td class="text-center">{{ a.total_attempts }}</td>
                <td class="text-center"><span [ngClass]="getPassRateClass(a.pass_rate)">{{ a.pass_rate }}%</span></td>
                <td>
                  <span class="ui-pill proctor-chip sm" [ngClass]="'proctor-' + a.proctoring_level" *ngIf="a.proctoring_level !== 'none'">
                    {{ a.proctoring_level }}
                  </span>
                  <span class="ui-muted" *ngIf="a.proctoring_level === 'none'">-</span>
                </td>
                <td><small>{{ formatDate(a.updated_at) }}</small></td>
                <td>
                  <div class="table-actions">
                    <button type="button" class="btn-ghost" (click)="editAssessment(a)" title="Edit" aria-label="Edit">
                      <i class="bi bi-pencil-square"></i>
                    </button>
                    <button type="button" class="btn-ghost" (click)="cloneAssessment(a)" title="Clone" aria-label="Clone">
                      <i class="bi bi-copy"></i>
                    </button>
                    <button type="button" class="btn-ghost text-danger" (click)="deleteAssessment(a)" title="Delete" aria-label="Delete">
                      <i class="bi bi-trash3"></i>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div class="pagination-bar" *ngIf="totalPages > 1">
          <span class="ui-result-count mb-0">{{ totalItems }} assessments</span>
          <div class="pagination-controls">
            <button type="button" class="btn btn-sm btn-outline-secondary" (click)="page = 1; loadAssessments()" [disabled]="page <= 1" aria-label="First page">
              <i class="bi bi-chevron-double-left"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" (click)="page = page - 1; loadAssessments()" [disabled]="page <= 1" aria-label="Previous page">
              <i class="bi bi-chevron-left"></i>
            </button>
            <span class="page-info">Page {{ page }} of {{ totalPages }}</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" (click)="page = page + 1; loadAssessments()" [disabled]="page >= totalPages" aria-label="Next page">
              <i class="bi bi-chevron-right"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" (click)="page = totalPages; loadAssessments()" [disabled]="page >= totalPages" aria-label="Last page">
              <i class="bi bi-chevron-double-right"></i>
            </button>
          </div>
        </div>

        <!-- Empty State -->
        <div class="ui-state" *ngIf="!loading && assessments.length === 0">
          <span class="ui-state__icon"><i class="bi bi-clipboard"></i></span>
          <h3>{{ hasActiveFilters ? 'No matching assessments' : 'No assessments yet' }}</h3>
          <p>{{ hasActiveFilters ? 'Try adjusting your filters' : 'Create your first assessment to get started' }}</p>
          <button type="button" class="btn btn-primary" *ngIf="!hasActiveFilters" (click)="createNew()">
            <i class="bi bi-plus-lg"></i> Create Assessment
          </button>
          <button type="button" class="btn btn-outline-secondary" *ngIf="hasActiveFilters" (click)="resetFilters()">Reset Filters</button>
        </div>

        <!-- Loading -->
        <app-skeleton *ngIf="loading && viewMode === 'grid'" variant="cards" [count]="6"></app-skeleton>
        <app-skeleton *ngIf="loading && viewMode !== 'grid'" variant="table" [rows]="8" [cols]="10"></app-skeleton>
      </section>

      <!-- Delete Confirm Modal -->
      <div class="modal-overlay" *ngIf="showDeleteConfirm" (click)="showDeleteConfirm = false">
        <div class="modal-dialog" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h5><i class="bi bi-exclamation-triangle text-danger"></i> Confirm Delete</h5>
            <button type="button" class="btn-close" (click)="showDeleteConfirm = false" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>Are you sure you want to delete <strong>{{ deleteTarget?.title }}</strong>?</p>
            <p class="ui-muted small" *ngIf="deleteTarget?.total_attempts">
              This assessment has {{ deleteTarget?.total_attempts }} attempts. All data will be permanently lost.
            </p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" (click)="showDeleteConfirm = false">Cancel</button>
            <button type="button" class="btn btn-danger" (click)="confirmDelete()" [disabled]="deleting">
              <span class="spinner-border spinner-border-sm me-1" *ngIf="deleting"></span> Delete
            </button>
          </div>
        </div>
      </div>
    </div>
    </main>
  `,
  styles: [`
    :host { display: block; }

    .stats-row { grid-template-columns: repeat(6, minmax(0, 1fr)); margin-bottom: 20px; }
    @media (max-width: 1199.98px) { .stats-row { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
    @media (max-width: 767.98px) { .stats-row { grid-template-columns: repeat(2, minmax(0, 1fr)); } }

    /* Toolbar */
    .ui-toolbar { margin-bottom: 16px; }
    .search-box { flex-basis: 260px; }
    .search-box > input { padding-right: 32px; }
    .clear-btn { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; color: var(--ui-text-subtle); cursor: pointer; padding: 2px; line-height: 1; font-size: .75rem; }
    .clear-btn:hover { color: var(--ui-text); }
    .icon-btn { width: var(--ui-ctl-h); height: var(--ui-ctl-h); padding: 0; display: inline-flex; align-items: center; justify-content: center; }
    .view-toggle { display: inline-flex; border: 1px solid var(--ui-border); border-radius: var(--ui-radius-ctl); overflow: hidden; height: var(--ui-ctl-h); }
    .vt { width: 38px; border: 0; background: var(--ui-surface); cursor: pointer; color: var(--ui-text-muted); }
    .vt + .vt { border-left: 1px solid var(--ui-border); }
    .vt:hover { color: var(--ui-text); }
    .vt.active { background: rgba(var(--brand-primary-rgb), .08); color: var(--brand-primary); }

    /* Bulk Bar */
    .bulk-bar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; padding: 8px 12px; background: rgba(var(--brand-primary-rgb), .05);
      border: 1px solid rgba(var(--brand-primary-rgb), .2); border-radius: var(--ui-radius-card); margin-bottom: 16px; font-size: .8125rem; }

    /* Grid */
    .assessment-grid { grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
    .assessment-card { overflow: visible; }
    .assessment-card.selected-card { border-color: rgba(var(--brand-primary-rgb), .55); box-shadow: 0 0 0 3px rgba(var(--brand-primary-rgb), .1); }
    .card-header-bar { display: flex; align-items: center; gap: 8px; padding: 12px 16px 0; }
    .card-header-bar .form-check-input { margin: 0; }
    .card-body-content { cursor: pointer; gap: 8px; }
    .card-body-content:hover .ui-card__title { color: var(--brand-primary); }
    .card-desc { font-size: .8125rem; color: var(--ui-text-muted); margin: 0; line-height: 1.45; }
    .card-meta { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; font-size: .75rem; }
    .card-meta > span:not(.ui-pill) { display: inline-flex; align-items: center; gap: 4px; }
    .card-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px; text-align: center;
      background: var(--ui-surface-2); border: 1px solid var(--ui-border); border-radius: var(--ui-radius-ctl); padding: 8px; margin-top: 2px; }
    .mini-stat { display: flex; flex-direction: column; }
    .mini-val { font-size: .875rem; font-weight: 650; color: var(--ui-text); font-variant-numeric: tabular-nums; }
    .mini-lbl { font-size: .625rem; color: var(--ui-text-muted); text-transform: uppercase; letter-spacing: .03em; }
    .card-footer-bar { padding: 8px 12px 8px 16px; }
    .card-date { font-size: .75rem; color: var(--ui-text-subtle); }
    .card-actions { display: flex; gap: 2px; }
    .btn-ghost { width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center; background: none; border: none; color: var(--ui-text-muted); cursor: pointer; border-radius: var(--ui-radius-ctl); }
    .btn-ghost:hover { background: var(--ui-surface-2); color: var(--ui-text); }

    /* Badges: soft tones */
    .type-badge, .status-badge, .proctor-chip { text-transform: capitalize; height: 22px; font-size: .6875rem; }
    .type-badge { text-transform: uppercase; letter-spacing: .03em; }
    .type-quiz, .proctor-basic { background: rgba(var(--brand-primary-rgb), .1); border-color: rgba(var(--brand-primary-rgb), .28); color: var(--brand-primary); }
    .type-exam, .status-closed, .proctor-advanced { background: rgba(var(--ui-danger-rgb), .1); border-color: rgba(var(--ui-danger-rgb), .28); color: var(--ui-danger-text); }
    .type-practice, .status-published { background: rgba(var(--ui-success-rgb), .1); border-color: rgba(var(--ui-success-rgb), .28); color: var(--ui-success-text); }
    .type-survey, .proctor-standard { background: rgba(var(--ui-warning-rgb), .1); border-color: rgba(var(--ui-warning-rgb), .28); color: var(--ui-warning-text); }
    .status-draft, .status-archived { background: rgba(var(--ui-neutral-rgb), .08); border-color: rgba(var(--ui-neutral-rgb), .22); color: var(--ui-neutral-text); }

    .pass-high { color: var(--ui-success-text); font-weight: 600; }
    .pass-mid { color: var(--ui-warning-text); font-weight: 600; }
    .pass-low { color: var(--ui-danger-text); font-weight: 600; }

    /* Table */
    .table { font-size: .8125rem; }
    .table th { white-space: nowrap; }
    .assess-link { color: var(--ui-text); cursor: pointer; text-decoration: none; font-weight: 600; }
    .assess-link:hover { color: var(--brand-primary); }
    .table-actions { display: flex; gap: 2px; }

    /* Dropdown */
    .dropdown-wrapper { position: relative; }
    .dropdown-menu-custom { position: absolute; right: 0; top: 100%; background: var(--ui-surface); border: 1px solid var(--ui-border);
      border-radius: var(--ui-radius-card); box-shadow: var(--ui-shadow-pop); z-index: 20; min-width: 170px; padding: 4px; }
    .dropdown-menu-custom button { display: flex; align-items: center; gap: 8px; width: 100%; padding: 7px 10px;
      border: none; background: none; font-size: .8125rem; cursor: pointer; text-align: left; border-radius: 6px; color: var(--ui-text); }
    .dropdown-menu-custom button.text-danger { color: var(--ui-danger-text) !important; }
    .dropdown-menu-custom button:hover { background: var(--ui-surface-2); }
    .dropdown-menu-custom hr { margin: 4px 0; border-color: var(--ui-border); opacity: 1; }

    /* Pagination */
    .pagination-bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding-top: 16px; margin-top: 16px; border-top: 1px solid var(--ui-border); }
    .pagination-controls { display: flex; align-items: center; gap: 6px; }
    .page-info { font-size: .8125rem; color: var(--ui-text-muted); min-width: 100px; text-align: center; font-variant-numeric: tabular-nums; }

    /* Modal */
    .modal-overlay { position: fixed; inset: 0; background: rgba(16, 24, 18, .4); z-index: 1050;
      display: flex; align-items: center; justify-content: center; }
    .modal-dialog { background: var(--ui-surface); border-radius: var(--ui-radius-panel); max-width: 480px; width: 90%; margin: 0; box-shadow: var(--ui-shadow-pop); }
    .modal-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px;
      border-bottom: 1px solid var(--ui-border); }
    .modal-header h5 { display: flex; align-items: center; gap: 8px; margin: 0; font-size: 1rem; font-weight: 650; }
    .modal-body { padding: 20px; font-size: .875rem; }
    .modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 20px; border-top: 1px solid var(--ui-border); }
  `]
})
export class AssessmentListComponent implements OnInit, OnDestroy {
  private api = inject(AssessmentAdminService);
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
  openMenuId: string | null = null;

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
    this.api.list({
      page: this.page,
      perPage: this.pageSize,
      sort: this.sortField,
      order: this.sortDir,
      search: this.filters.search || undefined,
      status: this.filters.status || undefined,
      kind: this.filters.type || undefined,
      proctoringLevel: this.filters.proctoring || undefined,
      categoryId: this.filters.category_id || undefined,
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: (paged) => {
        this.assessments = paged.items.map(a => this.toVm(a));
        this.totalItems = paged.meta.total;
        this.loading = false;
      },
      error: () => { this.loading = false; }
    });
  }

  loadStats(): void {
    this.api.stats().pipe(takeUntil(this.destroy$)).subscribe({
      next: (s) => {
        this.stats = {
          total: s.total, published: s.published, draft: s.draft, closed: s.closed,
          total_attempts: s.totalAttempts, total_completions: s.totalCompletions,
          avg_pass_rate: s.avgPassRate,
        };
      }
    });
  }

  loadCategories(): void {
    // Assessment categories are not a resource on the new API yet; the filter
    // degrades to "All Categories" until one exists.
    this.categories = [];
  }

  /** Maps a REST row into the shape the template renders. */
  private toVm(a: ApiAssessment): Assessment {
    return {
      assessment_id: a.id,
      title: a.title,
      description: a.description ?? '',
      assessment_type: a.kind,
      status: a.status,
      category_id: a.categoryId,
      course_title: null,
      total_questions: a.questionCount,
      total_points: a.totalPoints,
      time_limit_minutes: a.timeLimitMinutes ?? 0,
      proctoring_level: a.proctoringLevel,
      updated_at: a.updatedAt ?? a.createdAt ?? '',
      total_attempts: a.totalAttempts,
      total_completions: a.totalCompletions,
      pass_rate: a.passRate,
      avg_score: a.avgScore,
      is_selected: false,
    };
  }

  // ==========================================
  // ACTIONS
  // ==========================================

  createNew(): void { this.router.navigate(['/admin/assessments/create']); }
  editAssessment(a: Assessment): void { this.router.navigate(['/admin/assessments', a.assessment_id, 'edit']); }
  previewAssessment(a: Assessment): void { this.router.navigate(['/admin/assessments', a.assessment_id, 'preview']); }
  manageQuestions(a: Assessment): void { this.router.navigate(['/admin/assessments', a.assessment_id, 'questions']); }
  manageAssignments(a: Assessment): void { this.router.navigate(['/admin/assessments', a.assessment_id, 'assignments']); }
  viewResults(a: Assessment): void { this.router.navigate(['/admin/assessments', a.assessment_id, 'results']); }

  cloneAssessment(a: Assessment): void {
    this.api.duplicate(a.assessment_id).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => { this.loadAssessments(); this.loadStats(); }
    });
  }

  publishAssessment(a: Assessment): void { this.updateStatus(a, 'published'); }
  closeAssessment(a: Assessment): void { this.updateStatus(a, 'closed'); }

  private updateStatus(a: Assessment, status: AssessmentStatus): void {
    this.api.setStatus(a.assessment_id, status).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => { a.status = status; this.loadStats(); }
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
    this.api.delete(this.deleteTarget.assessment_id).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.loadAssessments();
        this.loadStats();
        this.deleting = false;
        this.showDeleteConfirm = false;
      },
      error: () => { this.deleting = false; }
    });
  }

  bulkAction(action: string): void {
    const targets = this.assessments.filter(a => a.is_selected);
    if (targets.length === 0) return;
    // The legacy backend had one bulk endpoint; here each selected assessment
    // is acted on and the list reloads once every call has returned.
    const calls = targets.map(a => {
      const call$ = action === 'delete'
        ? this.api.delete(a.assessment_id)
        : this.api.setStatus(a.assessment_id, this.bulkStatus(action)).pipe(map(() => undefined));
      return call$.pipe(catchError(() => of(undefined)));
    });
    forkJoin(calls).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => { this.loadAssessments(); this.loadStats(); }
    });
  }

  private bulkStatus(action: string): AssessmentStatus {
    return action === 'publish' ? 'published' : action === 'close' ? 'closed' : 'archived';
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

  toggleMenu(id: string, e: Event): void {
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
