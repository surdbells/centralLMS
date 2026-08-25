// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 12
// Admin: Session Review Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { ActivatedRoute, Router } from '@angular/router';
import { Subject, takeUntil, debounceTime } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { GlobalComponent } from '../../global-component';

// ==========================================
// INTERFACES
// ==========================================

interface ApiResponse<T> {
  code: number;
  status: string;
  data: T;
  message: string;
}

interface ProctoringSession {
  session_id: number;
  attempt_id: number;
  user_id: number;
  learner_name: string;
  learner_email: string;
  assessment_id: number;
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
  violation_id: number;
  violation_type: string;
  severity: string;
  description: string;
  evidence_url: string;
  created_at: string;
}

interface TimelineEvent {
  event_id: number;
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
  imports: [CommonModule, FormsModule, TuiIcon],
  template: `
    <div class="session-review-container">
      <!-- Header -->
      <div class="page-header">
        <div class="header-left">
          <h1 class="page-title">
            <tui-icon icon="@tui.eye"></tui-icon>
            Proctoring Session Review
          </h1>
          <p class="page-subtitle">Review and manage proctored assessment sessions</p>
        </div>
      </div>

      <!-- Stats -->
      <div class="stats-row">
        <div class="stat-card" (click)="filterStatus = ''; applyFilters()" [class.active]="!filterStatus">
          <span class="stat-value">{{ stats.total }}</span>
          <span class="stat-label">Total Sessions</span>
        </div>
        <div class="stat-card warning" (click)="filterStatus = 'pending_review'; applyFilters()"
             [class.active]="filterStatus === 'pending_review'">
          <span class="stat-value">{{ stats.pending_review }}</span>
          <span class="stat-label">Pending Review</span>
        </div>
        <div class="stat-card danger" (click)="filterStatus = 'flagged'; applyFilters()"
             [class.active]="filterStatus === 'flagged'">
          <span class="stat-value">{{ stats.flagged }}</span>
          <span class="stat-label">Flagged</span>
        </div>
        <div class="stat-card success" (click)="filterStatus = 'cleared'; applyFilters()"
             [class.active]="filterStatus === 'cleared'">
          <span class="stat-value">{{ stats.cleared }}</span>
          <span class="stat-label">Cleared</span>
        </div>
        <div class="stat-card" (click)="filterStatus = 'terminated'; applyFilters()"
             [class.active]="filterStatus === 'terminated'">
          <span class="stat-value">{{ stats.terminated }}</span>
          <span class="stat-label">Terminated</span>
        </div>
        <div class="stat-card info">
          <span class="stat-value">{{ stats.avg_trust_score }}%</span>
          <span class="stat-label">Avg Trust Score</span>
        </div>
      </div>

      <!-- Filters -->
      <div class="filters-bar">
        <div class="search-box">
          <tui-icon icon="@tui.search"></tui-icon>
          <input type="text" class="form-control" placeholder="Search learner or assessment..."
                 [(ngModel)]="searchTerm" (ngModelChange)="onSearch()">
        </div>
        <select class="form-select form-select-sm" [(ngModel)]="filterLevel" (change)="applyFilters()">
          <option value="">All Levels</option>
          <option value="basic">Basic</option>
          <option value="standard">Standard</option>
          <option value="advanced">Advanced</option>
        </select>
        <select class="form-select form-select-sm" [(ngModel)]="filterTrustRange" (change)="applyFilters()">
          <option value="">All Trust Scores</option>
          <option value="high">High (80-100%)</option>
          <option value="medium">Medium (50-79%)</option>
          <option value="low">Low (0-49%)</option>
        </select>
        <select class="form-select form-select-sm" [(ngModel)]="sortBy" (change)="applyFilters()">
          <option value="newest">Newest First</option>
          <option value="oldest">Oldest First</option>
          <option value="trust_asc">Trust: Low to High</option>
          <option value="trust_desc">Trust: High to Low</option>
          <option value="violations">Most Violations</option>
        </select>
      </div>

      <!-- Sessions Table -->
      <div class="sessions-table" *ngIf="!loading && filteredSessions.length > 0">
        <table class="table table-hover table-sm">
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
                  <div class="learner-avatar">{{ getInitials(s.learner_name) }}</div>
                  <div>
                    <div class="fw-500">{{ s.learner_name }}</div>
                    <div class="text-muted small">{{ s.learner_email }}</div>
                  </div>
                </div>
              </td>
              <td>
                <div class="fw-500">{{ truncate(s.assessment_title, 30) }}</div>
                <div class="text-muted small">Attempt #{{ s.attempt_id }}</div>
              </td>
              <td>
                <span class="level-tag" [ngClass]="'level-' + s.proctoring_level">
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
                <span class="status-badge" [ngClass]="'status-' + s.review_status">
                  {{ getStatusLabel(s.review_status) }}
                </span>
              </td>
              <td>{{ s.duration_minutes || 0 }}m</td>
              <td>
                <span class="text-muted small">{{ formatDate(s.started_at) }}</span>
              </td>
              <td>
                <div class="action-btns">
                  <button class="btn btn-sm btn-primary" (click)="openSessionDetail(s)" title="Review">
                    <tui-icon icon="@tui.eye"></tui-icon>
                  </button>
                  <button class="btn btn-sm btn-success" (click)="clearSession(s)" title="Clear"
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

        <!-- Pagination -->
        <div class="pagination-bar">
          <span class="text-muted small">{{ filteredSessions.length }} sessions</span>
          <div class="pagination-controls">
            <button class="btn btn-sm btn-secondary" (click)="page = page - 1" [disabled]="page <= 1">
              <tui-icon icon="@tui.chevron-left"></tui-icon>
            </button>
            <span class="page-info">{{ page }} / {{ totalPages }}</span>
            <button class="btn btn-sm btn-secondary" (click)="page = page + 1" [disabled]="page >= totalPages">
              <tui-icon icon="@tui.chevron-right"></tui-icon>
            </button>
          </div>
        </div>
      </div>

      <!-- Empty -->
      <div class="empty-state" *ngIf="!loading && filteredSessions.length === 0">
        <tui-icon icon="@tui.eye-off"></tui-icon>
        <h4>No Sessions Found</h4>
        <p class="text-muted">{{ searchTerm || filterStatus ? 'Try adjusting your filters' : 'No proctoring sessions recorded yet' }}</p>
      </div>

      <div class="loading-state" *ngIf="loading">
        <div class="spinner-border text-primary"></div>
        <p>Loading sessions...</p>
      </div>

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
              <button class="btn btn-sm btn-success" (click)="clearSession(selectedSession)">
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
                <span class="level-tag" [ngClass]="'level-' + selectedSession.proctoring_level">
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
                <span class="status-badge" [ngClass]="'status-' + selectedSession.review_status">
                  {{ getStatusLabel(selectedSession.review_status) }}
                </span>
              </div>
            </div>

            <!-- Tabs -->
            <div class="detail-tabs">
              <button class="tab-btn" [class.active]="detailTab === 'violations'" (click)="detailTab = 'violations'">
                <tui-icon icon="@tui.alert-triangle"></tui-icon> Violations ({{ sessionDetail?.violations?.length || 0 }})
              </button>
              <button class="tab-btn" [class.active]="detailTab === 'timeline'" (click)="detailTab = 'timeline'">
                <tui-icon icon="@tui.clock"></tui-icon> Timeline
              </button>
              <button class="tab-btn" [class.active]="detailTab === 'system'" (click)="detailTab = 'system'">
                <tui-icon icon="@tui.monitor"></tui-icon> System Info
              </button>
              <button class="tab-btn" [class.active]="detailTab === 'snapshots'" (click)="detailTab = 'snapshots'">
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
                  <span class="severity-badge" [ngClass]="'sev-' + v.severity">{{ v.severity }}</span>
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
              <p class="text-muted small mb-2">
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
  `,
  styles: [`
    .session-review-container { padding: 1.5rem; }
    .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; }
    .page-title { font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; margin: 0; }
    .page-subtitle { color: #6c757d; font-size: 0.875rem; margin: 0.25rem 0 0; }

    .stats-row { display: flex; gap: 0.75rem; margin-bottom: 1.25rem; flex-wrap: wrap; }
    .stat-card { background: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem; padding: 0.75rem 1rem;
      text-align: center; flex: 1; min-width: 100px; cursor: pointer; transition: all 0.2s; }
    .stat-card:hover { border-color: #6c757d; }
    .stat-card.active { border-color: #0d6efd; background: #e8f0fe; }
    .stat-card.warning .stat-value { color: #e67e22; }
    .stat-card.danger .stat-value { color: #e74c3c; }
    .stat-card.success .stat-value { color: #27ae60; }
    .stat-card.info .stat-value { color: #0d6efd; }
    .stat-value { display: block; font-size: 1.375rem; font-weight: 700; }
    .stat-label { font-size: 0.6875rem; color: #6c757d; }

    .filters-bar { display: flex; gap: 0.75rem; margin-bottom: 1rem; flex-wrap: wrap; }
    .search-box { position: relative; flex: 1; min-width: 200px; }
    .search-box tui-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #adb5bd; }
    .search-box input { padding-left: 2rem; font-size: 0.875rem; }
    .form-select { width: auto; min-width: 130px; }

    /* Table */
    .sessions-table { background: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem; overflow: hidden; }
    .sessions-table table { margin: 0; }
    .sessions-table th { font-size: 0.75rem; font-weight: 600; color: #6c757d; text-transform: uppercase;
      background: #f8f9fa; border-bottom: 2px solid #dee2e6; padding: 0.625rem 0.75rem; white-space: nowrap; }
    .sessions-table td { padding: 0.625rem 0.75rem; vertical-align: middle; font-size: 0.8125rem; }
    .flagged-row { background: #fff8e1 !important; }
    .terminated-row { background: #fce4ec !important; }
    .fw-500 { font-weight: 500; }

    .learner-cell { display: flex; align-items: center; gap: 0.5rem; }
    .learner-avatar { width: 32px; height: 32px; border-radius: 50%; background: #6c757d; color: #fff;
      display: flex; align-items: center; justify-content: center; font-size: 0.6875rem; font-weight: 600; flex-shrink: 0; }
    .level-tag { font-size: 0.6875rem; padding: 0.125rem 0.5rem; border-radius: 0.25rem; text-transform: capitalize; font-weight: 500; }
    .level-none { background: #e2e3e5; color: #6c757d; }
    .level-basic { background: #cfe2ff; color: #084298; }
    .level-standard { background: #fff3cd; color: #664d03; }
    .level-advanced { background: #f8d7da; color: #842029; }

    .trust-score-cell { display: flex; align-items: center; gap: 0.5rem; }
    .trust-bar { width: 60px; height: 6px; background: #e9ecef; border-radius: 3px; overflow: hidden; }
    .trust-fill { height: 100%; border-radius: 3px; transition: width 0.3s; }
    .trust-fill.trust-high { background: #28a745; }
    .trust-fill.trust-medium { background: #ffc107; }
    .trust-fill.trust-low { background: #dc3545; }
    .trust-value { font-size: 0.8125rem; font-weight: 600; }
    .trust-high { color: #28a745; }
    .trust-medium { color: #e67e22; }
    .trust-low { color: #dc3545; }

    .violation-count { font-weight: 600; }
    .violation-count.has-violations { color: #dc3545; }
    .status-badge { font-size: 0.6875rem; padding: 0.125rem 0.5rem; border-radius: 1rem; font-weight: 500; }
    .status-pending_review { background: #fff3cd; color: #856404; }
    .status-flagged { background: #f8d7da; color: #842029; }
    .status-cleared { background: #d4edda; color: #155724; }
    .status-terminated { background: #e2e3e5; color: #6c757d; }
    .status-in_progress { background: #cce5ff; color: #004085; }
    .action-btns { display: flex; gap: 0.25rem; }

    .pagination-bar { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem; border-top: 1px solid #e9ecef; }
    .pagination-controls { display: flex; align-items: center; gap: 0.5rem; }
    .page-info { font-size: 0.8125rem; color: #6c757d; }

    .empty-state, .loading-state { text-align: center; padding: 3rem; }
    .empty-state tui-icon { font-size: 2.5rem; color: #adb5bd; margin-bottom: 1rem; }

    /* Modal */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1050;
      display: flex; align-items: center; justify-content: center; }
    .modal-dialog { background: #fff; border-radius: 0.5rem; width: 95%; max-height: 90vh; display: flex; flex-direction: column; }
    .modal-dialog.modal-xl { max-width: 1100px; }
    .modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid #dee2e6; display: flex; justify-content: space-between; align-items: center; }
    .modal-title { font-size: 1.125rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; margin: 0; }
    .modal-header-actions { display: flex; align-items: center; gap: 0.375rem; }
    .modal-body { padding: 1.25rem; overflow-y: auto; flex: 1; }

    .session-info-bar { display: flex; flex-wrap: wrap; gap: 1rem; padding: 0.75rem 1rem; background: #f8f9fa;
      border-radius: 0.5rem; margin-bottom: 1rem; }
    .info-item { display: flex; flex-direction: column; }
    .info-label { font-size: 0.625rem; color: #6c757d; text-transform: uppercase; font-weight: 600; }
    .info-value { font-size: 0.875rem; font-weight: 500; }

    /* Tabs */
    .detail-tabs { display: flex; gap: 0.25rem; border-bottom: 2px solid #e9ecef; margin-bottom: 1rem; }
    .tab-btn { padding: 0.5rem 1rem; border: none; background: none; font-size: 0.8125rem; color: #6c757d;
      cursor: pointer; display: flex; align-items: center; gap: 0.375rem; border-bottom: 2px solid transparent;
      margin-bottom: -2px; }
    .tab-btn.active { color: #0d6efd; border-bottom-color: #0d6efd; font-weight: 500; }
    .tab-content { min-height: 200px; }
    .empty-tab { text-align: center; padding: 2rem; color: #6c757d; }

    /* Violations */
    .violation-list { display: flex; flex-direction: column; gap: 0.5rem; }
    .violation-item { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.625rem 0.75rem;
      border: 1px solid #e9ecef; border-radius: 0.375rem; border-left: 3px solid; }
    .severity-low { border-left-color: #ffc107; }
    .severity-medium { border-left-color: #fd7e14; }
    .severity-high { border-left-color: #dc3545; }
    .severity-critical { border-left-color: #6f42c1; }
    .violation-icon { width: 32px; height: 32px; border-radius: 50%; background: #f8f9fa; display: flex;
      align-items: center; justify-content: center; flex-shrink: 0; color: #6c757d; }
    .violation-type { font-weight: 600; font-size: 0.875rem; }
    .violation-desc { font-size: 0.8125rem; color: #495057; }
    .violation-time { font-size: 0.6875rem; color: #adb5bd; }
    .severity-badge { font-size: 0.625rem; padding: 0.125rem 0.5rem; border-radius: 1rem; text-transform: capitalize;
      font-weight: 500; white-space: nowrap; flex-shrink: 0; }
    .sev-low { background: #fff3cd; color: #856404; }
    .sev-medium { background: #ffe0b2; color: #e65100; }
    .sev-high { background: #f8d7da; color: #842029; }
    .sev-critical { background: #e8daef; color: #4a235a; }

    /* Timeline */
    .timeline { padding-left: 1rem; border-left: 2px solid #dee2e6; }
    .timeline-item { position: relative; padding: 0 0 1rem 1.5rem; }
    .timeline-dot { position: absolute; left: -1.6rem; top: 0.25rem; width: 10px; height: 10px; border-radius: 50%;
      background: #dee2e6; border: 2px solid #fff; }
    .event-violation .timeline-dot { background: #dc3545; }
    .event-action .timeline-dot { background: #0d6efd; }
    .event-system .timeline-dot { background: #6c757d; }
    .timeline-event { font-weight: 500; font-size: 0.875rem; }
    .timeline-desc { font-size: 0.8125rem; color: #495057; }
    .timeline-time { font-size: 0.6875rem; color: #adb5bd; }

    /* System Info */
    .system-info-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 0.75rem; }
    .sys-item { padding: 0.75rem; background: #f8f9fa; border-radius: 0.375rem; }
    .sys-label { display: block; font-size: 0.6875rem; color: #6c757d; text-transform: uppercase; font-weight: 600; }
    .sys-value { font-size: 0.875rem; font-weight: 500; }

    .review-notes-section h6 { font-size: 0.875rem; font-weight: 600; display: flex; align-items: center; gap: 0.375rem; margin-bottom: 0.5rem; }
  `]
})
export class SessionReviewComponent implements OnInit, OnDestroy {
  private http = inject(HttpClient);
  private router = inject(Router);
  private destroy$ = new Subject<void>();
  private searchSubject = new Subject<string>();

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
    const fd = new FormData();
    fd.append('action', 'get_proctoring_sessions');

    this.http.post<ApiResponse<ProctoringSession[]>>(
      `${GlobalComponent.baseURL}/user/proctoring`, fd
    ).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => { if (res.code === 1) { this.sessions = res.data || []; this.applyFilters(); } this.loading = false; },
      error: () => { this.loading = false; }
    });
  }

  loadStats(): void {
    const fd = new FormData();
    fd.append('action', 'get_proctoring_stats');
    this.http.post<ApiResponse<SessionStats>>(`${GlobalComponent.baseURL}/user/proctoring`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({ next: (res) => { if (res.code === 1) this.stats = res.data; } });
  }

  openSessionDetail(s: ProctoringSession): void {
    this.selectedSession = s;
    this.reviewNotes = s.review_notes || '';
    this.detailTab = 'violations';
    this.showDetailModal = true;

    const fd = new FormData();
    fd.append('action', 'get_session_detail');
    fd.append('session_id', s.session_id.toString());
    this.http.post<ApiResponse<SessionDetail>>(`${GlobalComponent.baseURL}/user/proctoring`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({ next: (res) => { if (res.code === 1) this.sessionDetail = res.data; } });
  }

  // ==========================================
  // ACTIONS
  // ==========================================

  clearSession(s: ProctoringSession): void {
    const fd = new FormData();
    fd.append('action', 'review_session');
    fd.append('session_id', s.session_id.toString());
    fd.append('review_status', 'cleared');
    fd.append('notes', this.reviewNotes);

    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/proctoring`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({ next: (res) => {
        if (res.code === 1) { s.review_status = 'cleared'; this.loadStats(); }
      }});
  }

  flagSession(s: ProctoringSession): void {
    const fd = new FormData();
    fd.append('action', 'review_session');
    fd.append('session_id', s.session_id.toString());
    fd.append('review_status', 'flagged');
    fd.append('notes', this.reviewNotes);

    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/proctoring`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({ next: (res) => {
        if (res.code === 1) { s.review_status = 'flagged'; this.loadStats(); }
      }});
  }

  saveReviewNotes(): void {
    if (!this.selectedSession) return;
    const fd = new FormData();
    fd.append('action', 'update_review_notes');
    fd.append('session_id', this.selectedSession.session_id.toString());
    fd.append('notes', this.reviewNotes);
    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/proctoring`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({ next: (res) => {
        if (res.code === 1 && this.selectedSession) this.selectedSession.review_notes = this.reviewNotes;
      }});
  }

  openSnapshotGallery(): void {
    if (this.selectedSession) {
      this.showDetailModal = false;
      this.router.navigate(['/admin/proctoring/snapshots', this.selectedSession.session_id]);
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
    if (this.filterStatus) result = result.filter(s => s.review_status === this.filterStatus);
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
