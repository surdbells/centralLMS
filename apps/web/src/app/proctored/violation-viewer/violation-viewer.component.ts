// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 12
// Admin: Violation Viewer Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { Subject, takeUntil, debounceTime } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { GlobalComponent } from '../../global-component';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';

// ==========================================
// INTERFACES
// ==========================================

interface ApiResponse<T> {
  code: number;
  status: string;
  data: T;
  message: string;
}

interface Violation {
  violation_id: number;
  session_id: number;
  attempt_id: number;
  user_id: number;
  learner_name: string;
  assessment_title: string;
  violation_type: string;
  severity: string;
  description: string;
  evidence_url: string;
  evidence_type: string;
  ip_address: string;
  created_at: string;
  dismissed: boolean;
  dismissed_by_name: string;
  dismissed_at: string;
  dismiss_reason: string;
}

interface ViolationStats {
  total: number;
  by_severity: { low: number; medium: number; high: number; critical: number };
  by_type: { type: string; count: number }[];
  top_offenders: { learner_name: string; count: number }[];
}

interface ViolationType {
  value: string;
  label: string;
  icon: string;
  category: string;
}

@Component({
  selector: 'app-violation-viewer',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>

    <div class="violation-viewer-container">
      <!-- Header -->
      <div class="page-header" *ngIf="!sessionMode">
        <div class="header-left">
          <h1 class="page-title">
            <tui-icon icon="@tui.alert-triangle"></tui-icon>
            Violation Viewer
          </h1>
          <p class="page-subtitle">Analyze proctoring violations across all sessions</p>
        </div>
      </div>

      <!-- Stats Row -->
      <div class="stats-row" *ngIf="!sessionMode">
        <div class="stat-card" (click)="filterSeverity = ''; applyFilters()" [class.active]="!filterSeverity">
          <span class="stat-value">{{ violationStats.total }}</span>
          <span class="stat-label">Total</span>
        </div>
        <div class="stat-card sev-low" (click)="filterSeverity = 'low'; applyFilters()" [class.active]="filterSeverity === 'low'">
          <span class="stat-value">{{ violationStats.by_severity.low }}</span>
          <span class="stat-label">Low</span>
        </div>
        <div class="stat-card sev-med" (click)="filterSeverity = 'medium'; applyFilters()" [class.active]="filterSeverity === 'medium'">
          <span class="stat-value">{{ violationStats.by_severity.medium }}</span>
          <span class="stat-label">Medium</span>
        </div>
        <div class="stat-card sev-high" (click)="filterSeverity = 'high'; applyFilters()" [class.active]="filterSeverity === 'high'">
          <span class="stat-value">{{ violationStats.by_severity.high }}</span>
          <span class="stat-label">High</span>
        </div>
        <div class="stat-card sev-crit" (click)="filterSeverity = 'critical'; applyFilters()" [class.active]="filterSeverity === 'critical'">
          <span class="stat-value">{{ violationStats.by_severity.critical }}</span>
          <span class="stat-label">Critical</span>
        </div>
      </div>

      <!-- Top Violation Types -->
      <div class="type-chips" *ngIf="!sessionMode && violationStats.by_type.length > 0">
        <span class="type-chip" *ngFor="let t of violationStats.by_type.slice(0, 8)"
              [class.active]="filterType === t.type"
              (click)="filterType = filterType === t.type ? '' : t.type; applyFilters()">
          <tui-icon [icon]="getViolationIcon(t.type)"></tui-icon>
          {{ formatType(t.type) }} ({{ t.count }})
        </span>
      </div>

      <!-- Filters -->
      <div class="filters-bar">
        <div class="search-box">
          <tui-icon icon="@tui.search"></tui-icon>
          <input type="text" class="form-control" placeholder="Search violations..."
                 [(ngModel)]="searchTerm" (ngModelChange)="onSearch()">
        </div>
        <select class="form-select form-select-sm" [(ngModel)]="filterDismissed" (change)="applyFilters()">
          <option value="">All</option>
          <option value="active">Active</option>
          <option value="dismissed">Dismissed</option>
        </select>
        <select class="form-select form-select-sm" [(ngModel)]="sortBy" (change)="applyFilters()">
          <option value="newest">Newest</option>
          <option value="oldest">Oldest</option>
          <option value="severity">Severity</option>
        </select>
      </div>

      <!-- Bulk Actions -->
      <div class="bulk-bar" *ngIf="selectedIds.length > 0">
        <span class="text-muted small">{{ selectedIds.length }} selected</span>
        <button class="btn btn-sm btn-secondary" (click)="bulkDismiss()">
          <tui-icon icon="@tui.x-circle"></tui-icon> Dismiss Selected
        </button>
      </div>

      <!-- Violations List -->
      <div class="violations-list" *ngIf="!loading && filteredViolations.length > 0">
        <div class="violation-card" *ngFor="let v of paginatedViolations"
             [ngClass]="'border-' + v.severity" [class.dismissed]="v.dismissed">
          <div class="violation-select">
            <input type="checkbox" [checked]="selectedIds.includes(v.violation_id)"
                   (change)="toggleSelect(v.violation_id)">
          </div>
          <div class="violation-severity-icon" [ngClass]="'sev-icon-' + v.severity">
            <tui-icon [icon]="getViolationIcon(v.violation_type)"></tui-icon>
          </div>
          <div class="violation-body">
            <div class="violation-header-row">
              <span class="violation-type-label">{{ formatType(v.violation_type) }}</span>
              <span class="severity-pill" [ngClass]="'pill-' + v.severity">{{ v.severity }}</span>
              <span class="dismissed-pill" *ngIf="v.dismissed">Dismissed</span>
            </div>
            <p class="violation-description" *ngIf="v.description">{{ v.description }}</p>
            <div class="violation-meta">
              <span *ngIf="!sessionMode"><tui-icon icon="@tui.user"></tui-icon> {{ v.learner_name }}</span>
              <span *ngIf="!sessionMode"><tui-icon icon="@tui.file-text"></tui-icon> {{ truncate(v.assessment_title, 25) }}</span>
              <span><tui-icon icon="@tui.clock"></tui-icon> {{ formatDateTime(v.created_at) }}</span>
            </div>
            <!-- Dismiss info -->
            <div class="dismiss-info" *ngIf="v.dismissed">
              Dismissed by {{ v.dismissed_by_name }} - {{ v.dismiss_reason }}
            </div>
          </div>
          <div class="violation-actions">
            <button class="btn btn-sm btn-primary" *ngIf="v.evidence_url"
                    (click)="viewEvidence(v)" title="View Evidence">
              <tui-icon icon="@tui.image"></tui-icon>
            </button>
            <button class="btn btn-sm btn-secondary" *ngIf="!v.dismissed"
                    (click)="openDismissModal(v)" title="Dismiss">
              <tui-icon icon="@tui.x-circle"></tui-icon>
            </button>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <div class="pagination-bar" *ngIf="filteredViolations.length > pageSize">
        <span class="text-muted small">{{ filteredViolations.length }} violations</span>
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

      <!-- Empty / Loading -->
      <div class="empty-state" *ngIf="!loading && filteredViolations.length === 0">
        <tui-icon icon="@tui.check-circle"></tui-icon>
        <h5>{{ hasFilters() ? 'No Matching Violations' : 'No Violations Recorded' }}</h5>
      </div>
      <div class="loading-state" *ngIf="loading">
        <div class="spinner-border text-primary"></div>
        <p>Loading violations...</p>
      </div>

      <!-- ==========================================
           EVIDENCE MODAL
           ========================================== -->
      <div class="modal-overlay" *ngIf="showEvidenceModal && evidenceTarget" (click)="showEvidenceModal = false">
        <div class="modal-dialog" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h5 class="modal-title">
              <tui-icon icon="@tui.image"></tui-icon> Evidence - {{ formatType(evidenceTarget.violation_type) }}
            </h5>
            <button class="btn-close" (click)="showEvidenceModal = false"></button>
          </div>
          <div class="modal-body text-center">
            <img *ngIf="evidenceTarget.evidence_type === 'image'" [src]="evidenceTarget.evidence_url"
                 class="evidence-image" alt="Violation evidence">
            <video *ngIf="evidenceTarget.evidence_type === 'video'" [src]="evidenceTarget.evidence_url"
                   controls class="evidence-video"></video>
            <div class="evidence-details mt-3">
              <p><strong>Type:</strong> {{ formatType(evidenceTarget.violation_type) }}</p>
              <p><strong>Severity:</strong> <span [ngClass]="'pill-' + evidenceTarget.severity" class="severity-pill">{{ evidenceTarget.severity }}</span></p>
              <p><strong>Time:</strong> {{ formatDateTime(evidenceTarget.created_at) }}</p>
              <p *ngIf="evidenceTarget.description"><strong>Description:</strong> {{ evidenceTarget.description }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- ==========================================
           DISMISS MODAL
           ========================================== -->
      <div class="modal-overlay" *ngIf="showDismissModal && dismissTarget" (click)="showDismissModal = false">
        <div class="modal-dialog modal-sm" (click)="$event.stopPropagation()">
          <div class="modal-header">
            <h5 class="modal-title"><tui-icon icon="@tui.x-circle"></tui-icon> Dismiss Violation</h5>
            <button class="btn-close" (click)="showDismissModal = false"></button>
          </div>
          <div class="modal-body">
            <p class="small">Dismissing: <strong>{{ formatType(dismissTarget.violation_type) }}</strong></p>
            <div class="mb-3">
              <label class="form-label">Reason</label>
              <select class="form-select form-select-sm" [(ngModel)]="dismissReason">
                <option value="false_positive">False Positive</option>
                <option value="technical_issue">Technical Issue</option>
                <option value="acceptable_behavior">Acceptable Behavior</option>
                <option value="duplicate">Duplicate</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="mb-3" *ngIf="dismissReason === 'other'">
              <label class="form-label">Details</label>
              <textarea class="form-control" rows="2" [(ngModel)]="dismissNote" placeholder="Explain..."></textarea>
            </div>
          </div>
          <div class="modal-footer">
            <button class="btn btn-secondary btn-sm" (click)="showDismissModal = false">Cancel</button>
            <button class="btn btn-primary btn-sm" (click)="executeDismiss()">Dismiss</button>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .violation-viewer-container { padding: 1.5rem; }
    .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; }
    .page-title { font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; margin: 0; }
    .page-subtitle { color: #6c757d; font-size: 0.875rem; margin: 0.25rem 0 0; }

    .stats-row { display: flex; gap: 0.75rem; margin-bottom: 1rem; flex-wrap: wrap; }
    .stat-card { background: #fff; border: 2px solid #e9ecef; border-radius: 0.5rem; padding: 0.625rem 1rem;
      text-align: center; flex: 1; min-width: 80px; cursor: pointer; transition: all 0.2s; }
    .stat-card:hover { border-color: #6c757d; }
    .stat-card.active { background: #e8f0fe; border-color: #0d6efd; }
    .stat-value { display: block; font-size: 1.25rem; font-weight: 700; }
    .stat-label { font-size: 0.6875rem; color: #6c757d; }
    .sev-low .stat-value { color: #ffc107; }
    .sev-med .stat-value { color: #fd7e14; }
    .sev-high .stat-value { color: #dc3545; }
    .sev-crit .stat-value { color: #6f42c1; }

    .type-chips { display: flex; gap: 0.375rem; flex-wrap: wrap; margin-bottom: 1rem; }
    .type-chip {
      display: flex; align-items: center; gap: 0.25rem; padding: 0.25rem 0.625rem; font-size: 0.75rem;
      border: 1px solid #dee2e6; border-radius: 2rem; cursor: pointer; background: #fff; transition: all 0.2s;
    }
    .type-chip:hover { border-color: #6c757d; }
    .type-chip.active { background: #0d6efd; color: #fff; border-color: #0d6efd; }

    .filters-bar { display: flex; gap: 0.75rem; margin-bottom: 0.75rem; flex-wrap: wrap; }
    .search-box { position: relative; flex: 1; min-width: 200px; }
    .search-box tui-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: #adb5bd; }
    .search-box input { padding-left: 2rem; font-size: 0.875rem; }
    .form-select { width: auto; min-width: 120px; }

    .bulk-bar { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0.75rem; background: #e8f0fe;
      border-radius: 0.375rem; margin-bottom: 0.75rem; }

    /* Violation Cards */
    .violations-list { display: flex; flex-direction: column; gap: 0.5rem; }
    .violation-card {
      display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.75rem 1rem;
      background: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem; border-left: 4px solid;
    }
    .violation-card.dismissed { opacity: 0.6; }
    .border-low { border-left-color: #ffc107; }
    .border-medium { border-left-color: #fd7e14; }
    .border-high { border-left-color: #dc3545; }
    .border-critical { border-left-color: #6f42c1; }
    .violation-select { padding-top: 0.25rem; }
    .violation-severity-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center;
      justify-content: center; flex-shrink: 0; }
    .sev-icon-low { background: #fff3cd; color: #856404; }
    .sev-icon-medium { background: #ffe0b2; color: #e65100; }
    .sev-icon-high { background: #f8d7da; color: #842029; }
    .sev-icon-critical { background: #e8daef; color: #4a235a; }
    .violation-body { flex: 1; }
    .violation-header-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem; }
    .violation-type-label { font-weight: 600; font-size: 0.875rem; }
    .severity-pill { font-size: 0.625rem; padding: 0.0625rem 0.375rem; border-radius: 1rem; text-transform: capitalize; font-weight: 500; }
    .pill-low { background: #fff3cd; color: #856404; }
    .pill-medium { background: #ffe0b2; color: #e65100; }
    .pill-high { background: #f8d7da; color: #842029; }
    .pill-critical { background: #e8daef; color: #4a235a; }
    .dismissed-pill { font-size: 0.625rem; padding: 0.0625rem 0.375rem; border-radius: 1rem; background: #e2e3e5; color: #6c757d; }
    .violation-description { font-size: 0.8125rem; color: #495057; margin: 0.25rem 0; }
    .violation-meta { display: flex; gap: 0.75rem; font-size: 0.6875rem; color: #adb5bd; }
    .violation-meta span { display: flex; align-items: center; gap: 0.25rem; }
    .dismiss-info { font-size: 0.6875rem; color: #6c757d; font-style: italic; margin-top: 0.25rem; }
    .violation-actions { display: flex; gap: 0.25rem; flex-shrink: 0; }

    .pagination-bar { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; }
    .pagination-controls { display: flex; align-items: center; gap: 0.5rem; }
    .page-info { font-size: 0.8125rem; color: #6c757d; }

    .empty-state, .loading-state { text-align: center; padding: 2rem; }
    .empty-state tui-icon { font-size: 2rem; color: #adb5bd; margin-bottom: 0.5rem; }

    /* Modals */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1050;
      display: flex; align-items: center; justify-content: center; }
    .modal-dialog { background: #fff; border-radius: 0.5rem; max-width: 600px; width: 95%; }
    .modal-dialog.modal-sm { max-width: 400px; }
    .modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid #dee2e6; display: flex; justify-content: space-between; align-items: center; }
    .modal-title { font-size: 1rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; margin: 0; }
    .modal-body { padding: 1.25rem; }
    .modal-footer { padding: 0.75rem 1.25rem; border-top: 1px solid #dee2e6; display: flex; justify-content: flex-end; gap: 0.5rem; }
    .evidence-image { max-width: 100%; max-height: 400px; border-radius: 0.375rem; border: 1px solid #dee2e6; }
    .evidence-video { max-width: 100%; border-radius: 0.375rem; }
  `]
})
export class ViolationViewerComponent implements OnInit, OnDestroy {
  private http = inject(HttpClient);
  private destroy$ = new Subject<void>();
  private searchSubject = new Subject<string>();

  @Input() sessionId: number | null = null;
  @Input() sessionMode = false;

  // Data
  violations: Violation[] = [];
  filteredViolations: Violation[] = [];
  violationStats: ViolationStats = { total: 0, by_severity: { low: 0, medium: 0, high: 0, critical: 0 }, by_type: [], top_offenders: [] };

  // State
  loading = false;
  searchTerm = '';
  filterSeverity = '';
  filterType = '';
  filterDismissed = '';
  sortBy = 'newest';
  page = 1;
  pageSize = 20;
  selectedIds: number[] = [];

  // Modals
  showEvidenceModal = false;
  showDismissModal = false;
  evidenceTarget: Violation | null = null;
  dismissTarget: Violation | null = null;
  dismissReason = 'false_positive';
  dismissNote = '';

  get totalPages(): number { return Math.ceil(this.filteredViolations.length / this.pageSize) || 1; }
  get paginatedViolations(): Violation[] {
    const start = (this.page - 1) * this.pageSize;
    return this.filteredViolations.slice(start, start + this.pageSize);
  }

  ngOnInit(): void {
    this.searchSubject.pipe(debounceTime(300), takeUntil(this.destroy$)).subscribe(() => this.applyFilters());
    this.loadViolations();
    if (!this.sessionMode) this.loadStats();
  }

  ngOnDestroy(): void { this.destroy$.next(); this.destroy$.complete(); }

  loadViolations(): void {
    this.loading = true;
    const fd = new FormData();
    fd.append('action', this.sessionId ? 'get_session_violations' : 'get_all_violations');
    if (this.sessionId) fd.append('session_id', this.sessionId.toString());

    this.http.post<ApiResponse<Violation[]>>(`${GlobalComponent.baseURL}/user/proctoring`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => { if (res.code === 1) { this.violations = res.data || []; this.applyFilters(); } this.loading = false; },
        error: () => { this.loading = false; }
      });
  }

  loadStats(): void {
    const fd = new FormData();
    fd.append('action', 'get_violation_stats');
    this.http.post<ApiResponse<ViolationStats>>(`${GlobalComponent.baseURL}/user/proctoring`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({ next: (res) => { if (res.code === 1) this.violationStats = res.data; } });
  }

  // ==========================================
  // FILTERS
  // ==========================================

  onSearch(): void { this.searchSubject.next(this.searchTerm); }

  applyFilters(): void {
    let result = [...this.violations];
    if (this.searchTerm) {
      const t = this.searchTerm.toLowerCase();
      result = result.filter(v => v.learner_name?.toLowerCase().includes(t) ||
        v.violation_type.toLowerCase().includes(t) || v.description?.toLowerCase().includes(t));
    }
    if (this.filterSeverity) result = result.filter(v => v.severity === this.filterSeverity);
    if (this.filterType) result = result.filter(v => v.violation_type === this.filterType);
    if (this.filterDismissed === 'active') result = result.filter(v => !v.dismissed);
    if (this.filterDismissed === 'dismissed') result = result.filter(v => v.dismissed);

    const sevOrder: Record<string, number> = { critical: 0, high: 1, medium: 2, low: 3 };
    switch (this.sortBy) {
      case 'newest': result.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime()); break;
      case 'oldest': result.sort((a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime()); break;
      case 'severity': result.sort((a, b) => (sevOrder[a.severity] ?? 4) - (sevOrder[b.severity] ?? 4)); break;
    }
    this.filteredViolations = result;
    this.page = 1;
  }

  hasFilters(): boolean { return !!(this.searchTerm || this.filterSeverity || this.filterType || this.filterDismissed); }

  // ==========================================
  // ACTIONS
  // ==========================================

  toggleSelect(id: number): void {
    const idx = this.selectedIds.indexOf(id);
    idx === -1 ? this.selectedIds.push(id) : this.selectedIds.splice(idx, 1);
  }

  viewEvidence(v: Violation): void {
    this.evidenceTarget = v;
    this.showEvidenceModal = true;
  }

  openDismissModal(v: Violation): void {
    this.dismissTarget = v;
    this.dismissReason = 'false_positive';
    this.dismissNote = '';
    this.showDismissModal = true;
  }

  executeDismiss(): void {
    if (!this.dismissTarget) return;
    const fd = new FormData();
    fd.append('action', 'dismiss_violation');
    fd.append('violation_id', this.dismissTarget.violation_id.toString());
    fd.append('reason', this.dismissReason === 'other' ? this.dismissNote : this.dismissReason);

    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/proctoring`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => {
          if (res.code === 1) {
            this.dismissTarget!.dismissed = true;
            this.showDismissModal = false;
          }
        }
      });
  }

  bulkDismiss(): void {
    const fd = new FormData();
    fd.append('action', 'bulk_dismiss_violations');
    fd.append('violation_ids', JSON.stringify(this.selectedIds));
    fd.append('reason', 'bulk_dismiss');

    this.http.post<ApiResponse<any>>(`${GlobalComponent.baseURL}/user/proctoring`, fd)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (res) => {
          if (res.code === 1) {
            this.violations.filter(v => this.selectedIds.includes(v.violation_id)).forEach(v => v.dismissed = true);
            this.selectedIds = [];
            this.applyFilters();
          }
        }
      });
  }

  // ==========================================
  // HELPERS
  // ==========================================

  formatType(type: string): string { return type?.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) || ''; }

  formatDateTime(d: string): string {
    return d ? new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
  }

  truncate(text: string, len: number): string { return text?.length > len ? text.substring(0, len) + '...' : text || ''; }

  getViolationIcon(type: string): string {
    const icons: Record<string, string> = {
      tab_switch: '@tui.external-link', window_blur: '@tui.eye-off', copy_paste: '@tui.clipboard',
      right_click: '@tui.mouse-pointer', fullscreen_exit: '@tui.minimize-2', multiple_faces: '@tui.users',
      no_face: '@tui.user-x', screenshot: '@tui.camera-off', keyboard_shortcut: '@tui.command',
      print_screen: '@tui.printer', virtual_machine: '@tui.box', remote_desktop: '@tui.cast',
      browser_devtools: '@tui.code', screen_share_stopped: '@tui.monitor-off'
    };
    return icons[type] || '@tui.alert-triangle';
  }
}
