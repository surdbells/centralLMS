// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 12
// Admin: Session Review Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslatePipe } from '../../translate.pipe';
import { Subject, takeUntil, debounceTime } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { AssessmentsService } from '../../services/assessments.service';

// ==========================================
// INTERFACES
// ==========================================

interface ProctoringSession {
  session_id: string;
  attempt_id: string;
  user_id: string;
  learner_name: string;
  learner_email: string;
  assessment_id: string;
  assessment_title: string;
  proctoring_level: string;
  status: string;
  review_status: string;
  trust_score: number;
  violation_count: number;
  snapshot_count: number;
  started_at: string;
  ended_at: string;
  duration_minutes: number;
  ip_address: string;
  browser: string;
  os: string;
  reviewed_by_name: string;
  reviewed_at: string;
  review_notes: string;
  flags: string[];
}

interface SessionStats {
  total: number;
  pending_review: number;
  flagged: number;
  cleared: number;
  terminated: number;
  avg_trust_score: number;
}

interface SessionDetail extends ProctoringSession {
  violations: SessionViolation[];
  timeline: TimelineEvent[];
  system_check: SystemCheckResult;
}

interface SessionViolation {
  violation_id: string;
  violation_type: string;
  severity: string;
  description: string;
  evidence_url: string;
  created_at: string;
}

interface TimelineEvent {
  event_id: string;
  event_type: string;
  description: string;
  created_at: string;
  data: any;
}

interface SystemCheckResult {
  browser: string;
  os: string;
  webcam: boolean;
  microphone: boolean;
  screen_share: boolean;
  bandwidth_kbps: number;
  passed: boolean;
}

@Component({
  selector: 'app-session-review',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, TuiIcon, AdmintopComponent, SkeletonComponent, TranslatePipe],
  template: `
    <app-admintop></app-admintop>

    <main class="main">
    <div class="ui-page session-review-container">
      <!-- Header -->
      <header class="ui-page-header">
        <a routerLink="/administration" class="ui-page-header__back" [attr.aria-label]="'common.back' | translate"><i class="bi bi-chevron-left"></i></a>
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-eye"></i></div>
        <div class="ui-page-header__titles">
          <h1>Proctoring Session Review</h1>
          <p>Review and manage proctored assessment sessions</p>
        </div>
      </header>

      <div class="alert alert-info d-flex align-items-center gap-2" *ngIf="notice">
        <tui-icon icon="@tui.info"></tui-icon>
        <span>{{ notice }}</span>
        <button type="button" class="btn-close ms-auto" (click)="notice = null"></button>
      </div>

      <!-- Stats -->
      <div class="ui-kpi-grid stats-row">
        <button type="button" class="ui-kpi ui-kpi--brand" (click)="filterStatus = ''; applyFilters()" [class.is-active]="!filterStatus">
          <span class="ui-kpi__label"><i class="bi bi-collection"></i> Total Sessions</span>
          <span class="ui-kpi__value">{{ stats.total }}</span>
        </button>
        <button type="button" class="ui-kpi ui-kpi--warning" (click)="filterStatus = 'pending_review'; applyFilters()"
             [class.is-active]="filterStatus === 'pending_review'">
          <span class="ui-kpi__label"><i class="bi bi-hourglass-split"></i> Pending Review</span>
          <span class="ui-kpi__value">{{ stats.pending_review }}</span>
        </button>
        <button type="button" class="ui-kpi ui-kpi--danger" (click)="filterStatus = 'flagged'; applyFilters()"
             [class.is-active]="filterStatus === 'flagged'">
          <span class="ui-kpi__label"><i class="bi bi-flag"></i> Flagged</span>
          <span class="ui-kpi__value">{{ stats.flagged }}</span>
        </button>
        <button type="button" class="ui-kpi ui-kpi--success" (click)="filterStatus = 'cleared'; applyFilters()"
             [class.is-active]="filterStatus === 'cleared'">
          <span class="ui-kpi__label"><i class="bi bi-check-circle"></i> Cleared</span>
          <span class="ui-kpi__value">{{ stats.cleared }}</span>
        </button>
        <button type="button" class="ui-kpi" (click)="filterStatus = 'terminated'; applyFilters()"
             [class.is-active]="filterStatus === 'terminated'">
          <span class="ui-kpi__label"><i class="bi bi-x-octagon"></i> Terminated</span>
          <span class="ui-kpi__value">{{ stats.terminated }}</span>
        </button>
        <div class="ui-kpi ui-kpi--info">
          <span class="ui-kpi__label"><i class="bi bi-shield-check"></i> Avg Trust Score</span>
          <span class="ui-kpi__value">{{ stats.avg_trust_score }}<small>%</small></span>
        </div>
      </div>

      <section class="ui-section ui-section--flush sessions-section">
        <div class="ui-section__head">
          <div>
            <h2 class="ui-section__title">Sessions</h2>
            <p class="ui-section__subtitle">{{ filteredSessions.length }} sessions</p>
          </div>
        </div>

        <!-- Filters -->
        <div class="ui-toolbar filters-bar">
          <div class="ui-search search-box">
            <i class="bi bi-search"></i>
            <input type="search" placeholder="Search learner or assessment..."
                   [(ngModel)]="searchTerm" (ngModelChange)="onSearch()">
          </div>
          <div class="ui-filters">
            <select class="form-select" [(ngModel)]="filterLevel" (change)="applyFilters()">
              <option value="">All Levels</option>
              <option value="basic">Basic</option>
              <option value="standard">Standard</option>
              <option value="advanced">Advanced</option>
            </select>
            <select class="form-select" [(ngModel)]="filterTrustRange" (change)="applyFilters()">
              <option value="">All Trust Scores</option>
              <option value="high">High (80-100%)</option>
              <option value="medium">Medium (50-79%)</option>
              <option value="low">Low (0-49%)</option>
            </select>
            <select class="form-select" [(ngModel)]="sortBy" (change)="applyFilters()">
              <option value="newest">Newest First</option>
              <option value="oldest">Oldest First</option>
              <option value="trust_asc">Trust: Low to High</option>
              <option value="trust_desc">Trust: High to Low</option>
              <option value="violations">Most Violations</option>
            </select>
          </div>
        </div>

        <!-- Sessions Table -->
        <div class="sessions-table" *ngIf="!loading && filteredSessions.length > 0">
          <div class="table-responsive">
          <table class="table table-hover">
            <thead>
              <tr>
                <th>Learner</th>
                <th>Assessment</th>
                <th>Level</th>
                <th>Trust Score</th>
                <th>Violations</th>
                <th>Status</th>
                <th>Duration</th>
                <th>Date</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr *ngFor="let s of paginatedSessions" [class.flagged-row]="s.review_status === 'flagged'"
                  [class.terminated-row]="s.status === 'terminated'">
                <td>
                  <div class="learner-cell">
                    <div class="ui-avatar learner-avatar">{{ getInitials(s.learner_name) }}</div>
                    <div>
                      <div class="fw-500">{{ s.learner_name }}</div>
                      <div class="ui-muted small">{{ s.learner_email }}</div>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="fw-500">{{ truncate(s.assessment_title, 30) }}</div>
                  <div class="ui-muted small">Attempt #{{ s.attempt_id }}</div>
                </td>
                <td>
                  <span class="ui-pill level-tag" [ngClass]="'level-' + s.proctoring_level">
                    {{ s.proctoring_level }}
                  </span>
                </td>
                <td>
                  <div class="trust-score-cell">
                    <div class="trust-bar">
                      <div class="trust-fill" [style.width.%]="s.trust_score"
                           [ngClass]="getTrustClass(s.trust_score)"></div>
                    </div>
                    <span class="trust-value" [ngClass]="getTrustClass(s.trust_score)">{{ s.trust_score }}%</span>
                  </div>
                </td>
                <td>
                  <span class="violation-count" [class.has-violations]="s.violation_count > 0">
                    {{ s.violation_count }}
                  </span>
                </td>
                <td>
                  <span class="ui-pill status-badge" [ngClass]="'status-' + s.review_status">
                    <span class="ui-pill__dot"></span>{{ getStatusLabel(s.review_status) }}
                  </span>
                </td>
                <td>{{ s.duration_minutes || 0 }}m</td>
                <td>
                  <span class="ui-muted small">{{ formatDate(s.started_at) }}</span>
                </td>
                <td>
                  <div class="action-btns">
                    <button class="btn btn-sm btn-primary" (click)="openSessionDetail(s)" title="Review">
                      <tui-icon icon="@tui.eye"></tui-icon>
                    </button>
                    <button class="btn btn-sm btn-outline-success" (click)="clearSession(s)" title="Clear"
                            *ngIf="s.review_status !== 'cleared'">
                      <tui-icon icon="@tui.check"></tui-icon>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" (click)="flagSession(s)" title="Flag"
                            *ngIf="s.review_status !== 'flagged'">
                      <tui-icon icon="@tui.flag"></tui-icon>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
          </div>

          <!-- Pagination -->
          <div class="pagination-bar">
            <span class="ui-muted small">{{ filteredSessions.length }} sessions</span>
            <div class="pagination-controls">
              <button class="btn btn-sm btn-outline-secondary" (click)="page = page - 1" [disabled]="page <= 1">
                <tui-icon icon="@tui.chevron-left"></tui-icon>
              </button>
              <span class="page-info">{{ page }} / {{ totalPages }}</span>
              <button class="btn btn-sm btn-outline-secondary" (click)="page = page + 1" [disabled]="page >= totalPages">
                <tui-icon icon="@tui.chevron-right"></tui-icon>
              </button>
            </div>
          </div>
        </div>

        <!-- Empty -->
        <div class="ui-state empty-state" *ngIf="!loading && filteredSessions.length === 0">
          <span class="ui-state__icon"><i class="bi bi-eye-slash"></i></span>
          <h3>No Sessions Found</h3>
          <p>{{ searchTerm || filterStatus ? 'Try adjusting your filters' : 'No proctoring sessions recorded yet' }}</p>
        </div>

        <div class="table-skeleton" *ngIf="loading">
          <app-skeleton variant="table" [rows]="8" [cols]="9"></app-skeleton>
        </div>
      </section>

      <!-- ==========================================
           SESSION DETAIL MODAL
           ========================================== -->
      <div class="modal-overlay" *ngIf="showDetailModal && selectedSession" (click)="showDetailModal = false">
        <div class="modal-dialog modal-xl" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h5 class="modal-title">
              <tui-icon icon="@tui.eye"></tui-icon>
              Session Review - {{ selectedSession.learner_name }}
            </h5>
            <div class="modal-header-actions">
              <button class="btn btn-sm btn-outline-success" (click)="clearSession(selectedSession)">
                <tui-icon icon="@tui.check"></tui-icon> Clear
              </button>
              <button class="btn btn-sm btn-outline-danger" (click)="flagSession(selectedSession)">
                <tui-icon icon="@tui.flag"></tui-icon> Flag
              </button>
              <button class="btn-close ms-2" (click)="showDetailModal = false"></button>
            </div>
          </div>
          <div class="modal-body">
            <!-- Session Info Bar -->
            <div class="session-info-bar">
              <div class="info-item">
                <span class="info-label">Assessment</span>
                <span class="info-value">{{ selectedSession.assessment_title }}</span>
              </div>
              <div class="info-item">
                <span class="info-label">Level</span>
                <span class="ui-pill level-tag" [ngClass]="'level-' + selectedSession.proctoring_level">
                  {{ selectedSession.proctoring_level }}
                </span>
              </div>
              <div class="info-item">
                <span class="info-label">Trust Score</span>
                <span class="fw-bold" [ngClass]="getTrustClass(selectedSession.trust_score)">
                  {{ selectedSession.trust_score }}%
                </span>
              </div>
              <div class="info-item">
                <span class="info-label">Violations</span>
                <span class="fw-bold text-danger">{{ selectedSession.violation_count }}</span>
              </div>
              <div class="info-item">
                <span class="info-label">Duration</span>
                <span>{{ selectedSession.duration_minutes }}m</span>
              </div>
              <div class="info-item">
                <span class="info-label">Status</span>
                <span class="ui-pill status-badge" [ngClass]="'status-' + selectedSession.review_status">
                  {{ getStatusLabel(selectedSession.review_status) }}
                </span>
              </div>
            </div>

            <!-- Tabs -->
            <div class="ui-tabs detail-tabs">
              <button class="ui-tab tab-btn" [class.is-active]="detailTab === 'violations'" (click)="detailTab = 'violations'">
                <tui-icon icon="@tui.alert-triangle"></tui-icon> Violations ({{ sessionDetail?.violations?.length || 0 }})
              </button>
              <button class="ui-tab tab-btn" [class.is-active]="detailTab === 'timeline'" (click)="detailTab = 'timeline'">
                <tui-icon icon="@tui.clock"></tui-icon> Timeline
              </button>
              <button class="ui-tab tab-btn" [class.is-active]="detailTab === 'system'" (click)="detailTab = 'system'">
                <tui-icon icon="@tui.monitor"></tui-icon> System Info
              </button>
              <button class="ui-tab tab-btn" [class.is-active]="detailTab === 'snapshots'" (click)="detailTab = 'snapshots'">
                <tui-icon icon="@tui.camera"></tui-icon> Snapshots ({{ selectedSession.snapshot_count }})
              </button>
            </div>

            <!-- Violations Tab -->
            <div class="tab-content" *ngIf="detailTab === 'violations'">
              <div class="violation-list" *ngIf="sessionDetail?.violations?.length">
                <div class="violation-item" *ngFor="let v of sessionDetail!.violations"
                     [ngClass]="'severity-' + v.severity">
                  <div class="violation-icon">
                    <tui-icon [icon]="getViolationIcon(v.violation_type)"></tui-icon>
                  </div>
                  <div class="violation-info">
                    <div class="violation-type">{{ formatViolationType(v.violation_type) }}</div>
                    <div class="violation-desc">{{ v.description }}</div>
                    <div class="violation-time">{{ formatDateTime(v.created_at) }}</div>
                  </div>
                  <span class="ui-pill severity-badge" [ngClass]="'sev-' + v.severity">{{ v.severity }}</span>
                </div>
              </div>
              <div class="empty-tab" *ngIf="!sessionDetail?.violations?.length">
                <tui-icon icon="@tui.check-circle"></tui-icon>
                <p>No violations recorded</p>
              </div>
            </div>

            <!-- Timeline Tab -->
            <div class="tab-content" *ngIf="detailTab === 'timeline'">
              <div class="timeline" *ngIf="sessionDetail?.timeline?.length">
                <div class="timeline-item" *ngFor="let e of sessionDetail!.timeline"
                     [ngClass]="'event-' + getEventCategory(e.event_type)">
                  <div class="timeline-dot"></div>
                  <div class="timeline-content">
                    <div class="timeline-event">{{ formatEventType(e.event_type) }}</div>
                    <div class="timeline-desc" *ngIf="e.description">{{ e.description }}</div>
                    <div class="timeline-time">{{ formatDateTime(e.created_at) }}</div>
                  </div>
                </div>
              </div>
              <div class="empty-tab" *ngIf="!sessionDetail?.timeline?.length">
                <p>No timeline events</p>
              </div>
            </div>

            <!-- System Info Tab -->
            <div class="tab-content" *ngIf="detailTab === 'system'">
              <div class="system-info-grid">
                <div class="sys-item">
                  <span class="sys-label">IP Address</span>
                  <span class="sys-value">{{ selectedSession.ip_address || 'N/A' }}</span>
                </div>
                <div class="sys-item">
                  <span class="sys-label">Browser</span>
                  <span class="sys-value">{{ selectedSession.browser || 'N/A' }}</span>
                </div>
                <div class="sys-item">
                  <span class="sys-label">Operating System</span>
                  <span class="sys-value">{{ selectedSession.os || 'N/A' }}</span>
                </div>
                <div class="sys-item" *ngIf="sessionDetail?.system_check">
                  <span class="sys-label">System Check</span>
                  <span class="sys-value" [class.text-success]="sessionDetail!.system_check.passed"
                        [class.text-danger]="!sessionDetail!.system_check.passed">
                    {{ sessionDetail!.system_check.passed ? 'Passed' : 'Failed' }}
                  </span>
                </div>
                <div class="sys-item" *ngIf="sessionDetail?.system_check">
                  <span class="sys-label">Webcam</span>
                  <span class="sys-value">{{ sessionDetail!.system_check.webcam ? 'Available' : 'Not available' }}</span>
                </div>
                <div class="sys-item" *ngIf="sessionDetail?.system_check">
                  <span class="sys-label">Bandwidth</span>
                  <span class="sys-value">{{ sessionDetail!.system_check.bandwidth_kbps || 'N/A' }} kbps</span>
                </div>
              </div>
            </div>

            <!-- Snapshots Tab -->
            <div class="tab-content" *ngIf="detailTab === 'snapshots'">
              <p class="ui-muted small mb-2">
                View snapshots in the <a href="javascript:void(0)" (click)="openSnapshotGallery()">Snapshot Gallery</a>
              </p>
              <button class="btn btn-primary" (click)="openSnapshotGallery()">
                <tui-icon icon="@tui.image"></tui-icon> Open Gallery ({{ selectedSession.snapshot_count }} snapshots)
              </button>
            </div>

            <!-- Review Notes -->
            <div class="review-notes-section mt-3">
              <h6><tui-icon icon="@tui.edit-3"></tui-icon> Review Notes</h6>
              <textarea class="form-control" rows="3" [(ngModel)]="reviewNotes"
                        placeholder="Add review notes..."></textarea>
              <button class="btn btn-sm btn-primary mt-2" (click)="saveReviewNotes()">
                <tui-icon icon="@tui.save"></tui-icon> Save Notes
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
    </main>
  `,
  styles: [`
    /* Page chrome (header, KPIs, section, toolbar, tabs, pills, states) comes from the global UI kit. */
    .ui-kpi-grid { margin-bottom: 20px; }
    .ui-toolbar { margin-bottom: 16px; }
    .ui-search { flex: 1 1 260px; }

    /* Table */
    .sessions-table { border-top: 1px solid var(--ui-border, #e7e9e7); }
    .sessions-table table { margin: 0; }
    .sessions-table th { white-space: nowrap; }
    .sessions-table td { vertical-align: middle; }
    .flagged-row > td { background: rgba(var(--ui-warning-rgb), .06) !important; }
    .terminated-row > td { background: rgba(var(--ui-danger-rgb), .05) !important; }
    .fw-500 { font-weight: 500; }

    .learner-cell { display: flex; align-items: center; gap: 0.5rem; }
    .learner-avatar { width: 32px; height: 32px; font-size: 0.6875rem; font-weight: 600; flex-shrink: 0; }
    .level-tag { text-transform: capitalize; }
    .level-none { background: rgba(var(--ui-neutral-rgb), .1); border-color: rgba(var(--ui-neutral-rgb), .28); color: var(--ui-neutral-text); }
    .level-basic { background: rgba(var(--ui-info-rgb), .1); border-color: rgba(var(--ui-info-rgb), .28); color: var(--ui-info-text); }
    .level-standard { background: rgba(var(--ui-warning-rgb), .1); border-color: rgba(var(--ui-warning-rgb), .28); color: var(--ui-warning-text); }
    .level-advanced { background: rgba(var(--ui-danger-rgb), .1); border-color: rgba(var(--ui-danger-rgb), .28); color: var(--ui-danger-text); }

    .trust-score-cell { display: flex; align-items: center; gap: 0.5rem; }
    .trust-bar { width: 60px; height: 6px; background: var(--ui-surface-2, #f7f8f7); border: 1px solid var(--ui-border, #e7e9e7); border-radius: 999px; overflow: hidden; }
    .trust-fill { height: 100%; border-radius: 999px; transition: width 0.3s; }
    .trust-fill.trust-high { background: var(--ui-success, #2e7d4f); }
    .trust-fill.trust-medium { background: var(--ui-warning, #b25e09); }
    .trust-fill.trust-low { background: var(--ui-danger, #c0352b); }
    .trust-value { font-size: 0.8125rem; font-weight: 600; }
    .trust-high { color: var(--ui-success-text, #23643e); }
    .trust-medium { color: var(--ui-warning-text, #8f4b07); }
    .trust-low { color: var(--ui-danger-text, #a12c24); }

    .violation-count { font-weight: 600; }
    .violation-count.has-violations { color: var(--ui-danger-text, #a12c24); }
    .status-badge { white-space: nowrap; }
    .status-pending_review { background: rgba(var(--ui-warning-rgb), .1); border-color: rgba(var(--ui-warning-rgb), .28); color: var(--ui-warning-text); }
    .status-flagged { background: rgba(var(--ui-danger-rgb), .1); border-color: rgba(var(--ui-danger-rgb), .28); color: var(--ui-danger-text); }
    .status-cleared { background: rgba(var(--ui-success-rgb), .1); border-color: rgba(var(--ui-success-rgb), .28); color: var(--ui-success-text); }
    .status-terminated { background: rgba(var(--ui-neutral-rgb), .1); border-color: rgba(var(--ui-neutral-rgb), .28); color: var(--ui-neutral-text); }
    .status-in_progress { background: rgba(var(--ui-info-rgb), .1); border-color: rgba(var(--ui-info-rgb), .28); color: var(--ui-info-text); }
    .action-btns { display: flex; gap: 0.25rem; }

    .pagination-bar { display: flex; justify-content: space-between; align-items: center; padding: 12px 22px; border-top: 1px solid var(--ui-border, #e7e9e7); }
    .pagination-controls { display: flex; align-items: center; gap: 0.5rem; }
    .page-info { font-size: 0.8125rem; color: var(--ui-text-muted, #6a716b); }

    .empty-state { margin: 0 22px 22px; }
    .table-skeleton { padding: 16px 22px 22px; border-top: 1px solid var(--ui-border, #e7e9e7); }

    /* Modal */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1050;
      display: flex; align-items: center; justify-content: center; }
    .modal-dialog { background: var(--ui-surface, #fff); border-radius: var(--ui-radius-card, 12px); width: 95%; max-height: 90vh; display: flex; flex-direction: column; }
    .modal-dialog.modal-xl { max-width: 1100px; }
    .modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--ui-border, #e7e9e7); display: flex; justify-content: space-between; align-items: center; gap: .75rem; flex-wrap: wrap; }
    .modal-title { font-size: 1.0625rem; font-weight: 650; display: flex; align-items: center; gap: 0.5rem; margin: 0; }
    .modal-header-actions { display: flex; align-items: center; gap: 0.375rem; }
    .modal-body { padding: 1.25rem; overflow-y: auto; flex: 1; }

    .session-info-bar { display: flex; flex-wrap: wrap; gap: 1.25rem; padding: 0.75rem 1rem; background: var(--ui-surface-2, #f7f8f7);
      border: 1px solid var(--ui-border, #e7e9e7); border-radius: var(--ui-radius-card, 12px); margin-bottom: 1rem; }
    .info-item { display: flex; flex-direction: column; align-items: flex-start; gap: 2px; }
    .info-label { font-size: 0.6875rem; color: var(--ui-text-muted, #6a716b); text-transform: uppercase; letter-spacing: .03em; font-weight: 600; }
    .info-value { font-size: 0.875rem; font-weight: 500; }

    /* Tabs */
    .detail-tabs { margin-bottom: 1rem; }
    .tab-content { min-height: 200px; }
    .empty-tab { text-align: center; padding: 2rem; color: var(--ui-text-muted, #6a716b); }

    /* Violations */
    .violation-list { display: flex; flex-direction: column; gap: 0.5rem; }
    .violation-item { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.625rem 0.75rem;
      border: 1px solid var(--ui-border, #e7e9e7); border-radius: var(--ui-radius-ctl, 8px); border-left: 3px solid; }
    .severity-low { border-left-color: var(--ui-info, #2563a8); }
    .severity-medium { border-left-color: var(--ui-warning, #b25e09); }
    .severity-high { border-left-color: var(--ui-danger, #c0352b); }
    .severity-critical { border-left-color: var(--ui-danger-text, #a12c24); }
    .violation-icon { width: 32px; height: 32px; border-radius: 50%; background: var(--ui-surface-2, #f7f8f7); display: flex;
      align-items: center; justify-content: center; flex-shrink: 0; color: var(--ui-text-muted, #6a716b); }
    .violation-info { flex: 1; min-width: 0; }
    .violation-type { font-weight: 600; font-size: 0.875rem; }
    .violation-desc { font-size: 0.8125rem; color: var(--ui-text-muted, #6a716b); }
    .violation-time { font-size: 0.6875rem; color: var(--ui-text-subtle, #8d948e); }
    .severity-badge { text-transform: capitalize; white-space: nowrap; flex-shrink: 0; }
    .sev-low { background: rgba(var(--ui-info-rgb), .1); border-color: rgba(var(--ui-info-rgb), .28); color: var(--ui-info-text); }
    .sev-medium { background: rgba(var(--ui-warning-rgb), .1); border-color: rgba(var(--ui-warning-rgb), .28); color: var(--ui-warning-text); }
    .sev-high { background: rgba(var(--ui-danger-rgb), .1); border-color: rgba(var(--ui-danger-rgb), .28); color: var(--ui-danger-text); }
    .sev-critical { background: rgba(var(--ui-danger-rgb), .16); border-color: rgba(var(--ui-danger-rgb), .4); color: var(--ui-danger-text); }

    /* Timeline */
    .timeline { padding-left: 1rem; border-left: 2px solid var(--ui-border, #e7e9e7); }
    .timeline-item { position: relative; padding: 0 0 1rem 1.5rem; }
    .timeline-dot { position: absolute; left: -1.6rem; top: 0.25rem; width: 10px; height: 10px; border-radius: 50%;
      background: var(--ui-border-strong, #d9ddd9); border: 2px solid #fff; }
    .event-violation .timeline-dot { background: var(--ui-danger, #c0352b); }
    .event-action .timeline-dot { background: var(--brand-primary); }
    .event-system .timeline-dot { background: var(--ui-neutral, #4b524c); }
    .timeline-event { font-weight: 500; font-size: 0.875rem; }
    .timeline-desc { font-size: 0.8125rem; color: var(--ui-text-muted, #6a716b); }
    .timeline-time { font-size: 0.6875rem; color: var(--ui-text-subtle, #8d948e); }

    /* System Info */
    .system-info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.75rem; }
    .sys-item { padding: 0.75rem; background: var(--ui-surface-2, #f7f8f7); border: 1px solid var(--ui-border, #e7e9e7); border-radius: var(--ui-radius-ctl, 8px); }
    .sys-label { display: block; font-size: 0.6875rem; color: var(--ui-text-muted, #6a716b); text-transform: uppercase; letter-spacing: .03em; font-weight: 600; }
    .sys-value { font-size: 0.875rem; font-weight: 500; }

    .review-notes-section { padding-top: 1rem; border-top: 1px solid var(--ui-border, #e7e9e7); }
    .review-notes-section h6 { font-size: 0.875rem; font-weight: 600; display: flex; align-items: center; gap: 0.375rem; margin-bottom: 0.5rem; }
  `]
})
export class SessionReviewComponent implements OnInit, OnDestroy {
  private assessments = inject(AssessmentsService);
  private router = inject(Router);
  private destroy$ = new Subject<void>();
  private searchSubject = new Subject<string>();

  // Some sitting-level actions have no route yet; this explains why when used.
  notice: string | null = null;

  // Data
  sessions: ProctoringSession[] = [];
  filteredSessions: ProctoringSession[] = [];
  stats: SessionStats = { total: 0, pending_review: 0, flagged: 0, cleared: 0, terminated: 0, avg_trust_score: 0 };

  // State
  loading = false;
  searchTerm = '';
  filterStatus = '';
  filterLevel = '';
  filterTrustRange = '';
  sortBy = 'newest';
  page = 1;
  pageSize = 15;

  // Detail
  showDetailModal = false;
  selectedSession: ProctoringSession | null = null;
  sessionDetail: SessionDetail | null = null;
  detailTab = 'violations';
  reviewNotes = '';

  get totalPages(): number { return Math.ceil(this.filteredSessions.length / this.pageSize) || 1; }
  get paginatedSessions(): ProctoringSession[] {
    const start = (this.page - 1) * this.pageSize;
    return this.filteredSessions.slice(start, start + this.pageSize);
  }

  ngOnInit(): void {
    this.searchSubject.pipe(debounceTime(300), takeUntil(this.destroy$)).subscribe(() => this.applyFilters());
    this.loadSessions();
    this.loadStats();
  }

  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }

  // ==========================================
  // DATA
  // ==========================================

  loadSessions(): void {
    this.loading = true;
    // The reviewer queue of flagged sittings, keyed by attempt id.
    this.assessments.flaggedSittings().pipe(takeUntil(this.destroy$)).subscribe({
      next: (page) => {
        this.sessions = (page.items || []).map((r) => this.toSession(r));
        this.loadStats();
        this.applyFilters();
        this.loading = false;
      },
      error: () => { this.loading = false; }
    });
  }

  private toSession(r: Record<string, unknown>): ProctoringSession {
    const attemptId = String(r['attemptId'] ?? r['id'] ?? '');
    return {
      session_id: String(r['sessionId'] ?? attemptId),
      attempt_id: attemptId,
      user_id: String(r['userId'] ?? ''),
      learner_name: (r['learnerName'] as string) ?? (r['userName'] as string) ?? '',
      learner_email: (r['learnerEmail'] as string) ?? '',
      assessment_id: String(r['assessmentId'] ?? ''),
      assessment_title: (r['assessmentTitle'] as string) ?? '',
      proctoring_level: (r['proctoringLevel'] as string) ?? '',
      status: (r['status'] as string) ?? '',
      // The API's verdict is pending/cleared/flagged; the screen's vocabulary
      // spells the unreviewed state 'pending_review'.
      review_status: ((r['reviewStatus'] as string) ?? 'pending') === 'pending'
        ? 'pending_review'
        : (r['reviewStatus'] as string),
      trust_score: Number(r['trustScore'] ?? 100),
      violation_count: Number(r['violationCount'] ?? 0),
      snapshot_count: Number(r['snapshotCount'] ?? 0),
      started_at: (r['startedAt'] as string) ?? '',
      ended_at: (r['endedAt'] as string) ?? (r['submittedAt'] as string) ?? '',
      duration_minutes: Number(r['durationMinutes'] ?? 0),
      ip_address: (r['ipAddress'] as string) ?? '',
      browser: (r['browser'] as string) ?? '',
      os: (r['os'] as string) ?? '',
      reviewed_by_name: (r['reviewedByName'] as string) ?? '',
      reviewed_at: (r['reviewedAt'] as string) ?? '',
      review_notes: (r['reviewNotes'] as string) ?? '',
      flags: (r['flags'] as string[]) ?? [],
    };
  }

  /** No session-stats route exists; the figures are totalled from the queue. */
  loadStats(): void {
    const trust = this.sessions.map((s) => s.trust_score);
    this.stats = {
      total: this.sessions.length,
      pending_review: this.sessions.filter((s) => s.review_status === 'pending_review').length,
      flagged: this.sessions.filter((s) => s.review_status === 'flagged').length,
      cleared: this.sessions.filter((s) => s.review_status === 'cleared').length,
      terminated: this.sessions.filter((s) => s.status === 'terminated').length,
      avg_trust_score: trust.length ? Math.round(trust.reduce((a, b) => a + b, 0) / trust.length) : 0,
    };
  }

  openSessionDetail(s: ProctoringSession): void {
    this.selectedSession = s;
    this.reviewNotes = s.review_notes || '';
    this.detailTab = 'violations';
    this.showDetailModal = true;
    this.notice = null;

    // The whole sitting, keyed by attempt id: session/attempt/trust, plus its
    // violations and snapshots.
    this.assessments.sitting(s.attempt_id).pipe(takeUntil(this.destroy$)).subscribe({
      next: (payload) => { this.sessionDetail = this.toDetail(s, payload); }
    });
  }

  private toDetail(s: ProctoringSession, payload: Record<string, unknown>): SessionDetail {
    const trust = payload['trust'] as Record<string, unknown> | undefined;
    const violations = ((payload['violations'] as Record<string, unknown>[]) ?? []).map((v) => ({
      violation_id: String(v['id'] ?? ''),
      violation_type: (v['type'] as string) ?? '',
      severity: (v['severity'] as string) ?? 'medium',
      description: (v['description'] as string) ?? '',
      evidence_url: (v['evidenceUrl'] as string) ?? '',
      created_at: (v['occurredAt'] as string) ?? (v['createdAt'] as string) ?? '',
    }));
    return {
      ...s,
      trust_score: trust ? Number(trust['score'] ?? s.trust_score) : s.trust_score,
      violation_count: violations.length,
      snapshot_count: ((payload['snapshots'] as unknown[]) ?? []).length,
      violations,
      // No structured timeline or system-check projection on the new API yet.
      timeline: [],
      system_check: {
        browser: s.browser, os: s.os, webcam: false, microphone: false,
        screen_share: false, bandwidth_kbps: 0, passed: true,
      },
    };
  }

  // ==========================================
  // ACTIONS
  // ==========================================

  clearSession(s: ProctoringSession): void {
    this.reviewSitting(s, 'cleared');
  }

  flagSession(s: ProctoringSession): void {
    this.reviewSitting(s, 'flagged');
  }

  saveReviewNotes(): void {
    if (!this.selectedSession) return;
    // Saving notes keeps the current verdict (mapped back to the API's word).
    const current = this.selectedSession.review_status === 'pending_review'
      ? 'pending'
      : (this.selectedSession.review_status as 'cleared' | 'flagged');
    this.reviewSitting(this.selectedSession, current);
  }

  private reviewSitting(s: ProctoringSession, status: 'cleared' | 'flagged' | 'pending'): void {
    // The notes textarea (this.reviewNotes) belongs to the open modal's
    // session. A Clear/Flag click on another row must carry THAT row's own
    // notes, not the last-opened session's — otherwise one sitting's notes
    // land on another.
    const actingOnOpen = this.showDetailModal && this.selectedSession?.attempt_id === s.attempt_id;
    const notes = (actingOnOpen ? this.reviewNotes : (s.review_notes || '')).trim() || undefined;

    this.assessments.reviewSitting(s.attempt_id, {
      status,
      notes,
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.notice = status === 'cleared' ? 'Sitting cleared.'
          : status === 'flagged' ? 'Sitting flagged.' : 'Review notes saved.';
        this.showDetailModal = false;
        this.loadSessions();
      },
      error: () => { this.notice = 'Could not save the review.'; },
    });
  }

  openSnapshotGallery(): void {
    if (this.selectedSession) {
      this.showDetailModal = false;
      // Keyed by the attempt id now.
      this.router.navigate(['/admin/proctoring/snapshots', this.selectedSession.attempt_id]);
    }
  }

  // ==========================================
  // FILTERS
  // ==========================================

  onSearch(): void { this.searchSubject.next(this.searchTerm); }

  applyFilters(): void {
    let result = [...this.sessions];

    if (this.searchTerm) {
      const t = this.searchTerm.toLowerCase();
      result = result.filter(s => s.learner_name?.toLowerCase().includes(t) ||
        s.learner_email?.toLowerCase().includes(t) || s.assessment_title?.toLowerCase().includes(t));
    }
    // 'terminated' is a lifecycle status, not a review verdict — the stat card
    // counts s.status, so its filter must key off the same field, while the
    // pending/cleared/flagged cards key off the review verdict.
    if (this.filterStatus === 'terminated') result = result.filter(s => s.status === 'terminated');
    else if (this.filterStatus) result = result.filter(s => s.review_status === this.filterStatus);
    if (this.filterLevel) result = result.filter(s => s.proctoring_level === this.filterLevel);
    if (this.filterTrustRange) {
      result = result.filter(s => {
        if (this.filterTrustRange === 'high') return s.trust_score >= 80;
        if (this.filterTrustRange === 'medium') return s.trust_score >= 50 && s.trust_score < 80;
        return s.trust_score < 50;
      });
    }

    switch (this.sortBy) {
      case 'newest': result.sort((a, b) => new Date(b.started_at).getTime() - new Date(a.started_at).getTime()); break;
      case 'oldest': result.sort((a, b) => new Date(a.started_at).getTime() - new Date(b.started_at).getTime()); break;
      case 'trust_asc': result.sort((a, b) => a.trust_score - b.trust_score); break;
      case 'trust_desc': result.sort((a, b) => b.trust_score - a.trust_score); break;
      case 'violations': result.sort((a, b) => b.violation_count - a.violation_count); break;
    }

    this.filteredSessions = result;
    this.page = 1;
  }

  // ==========================================
  // HELPERS
  // ==========================================

  getTrustClass(score: number): string {
    if (score >= 80) return 'trust-high';
    if (score >= 50) return 'trust-medium';
    return 'trust-low';
  }

  getStatusLabel(status: string): string {
    const labels: Record<string, string> = {
      pending_review: 'Pending Review', flagged: 'Flagged', cleared: 'Cleared',
      terminated: 'Terminated', in_progress: 'In Progress'
    };
    return labels[status] || status;
  }

  getInitials(name: string): string {
    return name?.split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase() || '?';
  }

  truncate(text: string, len: number): string {
    return text?.length > len ? text.substring(0, len) + '...' : text || '';
  }

  formatDate(d: string): string {
    return d ? new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '';
  }

  formatDateTime(d: string): string {
    return d ? new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
  }

  formatViolationType(type: string): string {
    return type?.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) || '';
  }

  formatEventType(type: string): string {
    return type?.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) || '';
  }

  getViolationIcon(type: string): string {
    const icons: Record<string, string> = {
      tab_switch: '@tui.external-link', window_blur: '@tui.eye-off', copy_paste: '@tui.clipboard',
      right_click: '@tui.mouse-pointer', fullscreen_exit: '@tui.minimize-2',
      multiple_faces: '@tui.users', no_face: '@tui.user-x', screenshot: '@tui.camera-off',
      keyboard_shortcut: '@tui.command', print_screen: '@tui.printer'
    };
    return icons[type] || '@tui.alert-triangle';
  }

  getEventCategory(type: string): string {
    if (type.includes('violation') || type.includes('flag')) return 'violation';
    if (type.includes('start') || type.includes('end') || type.includes('submit')) return 'action';
    return 'system';
  }
}
