// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 12
// Admin: Violation Viewer Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { FormsModule } from '@angular/forms';
import { Subject, takeUntil, debounceTime } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '../../translate.pipe';
import { AssessmentsService } from '../../services/assessments.service';

// ==========================================
// INTERFACES
// ==========================================

interface Violation {
  violation_id: string;
  session_id: string;
  attempt_id: string;
  user_id: string;
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
  imports: [CommonModule, FormsModule, RouterLink, TuiIcon, AdmintopComponent, SkeletonComponent, TranslatePipe],
  template: `
    <app-admintop></app-admintop>

    <main class="main">
    <div class="ui-page violation-viewer-container">
      <!-- Header -->
      <header class="ui-page-header" *ngIf="!sessionMode">
        <a routerLink="/admin/proctoring/sessions" class="ui-page-header__back" [attr.aria-label]="'common.back' | translate"><i class="bi bi-chevron-left"></i></a>
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-exclamation-triangle"></i></div>
        <div class="ui-page-header__titles">
          <h1>Violation Viewer</h1>
          <p>Analyze proctoring violations across all sessions</p>
        </div>
      </header>

      <!-- Stats Row -->
      <div class="ui-kpi-grid ui-kpi-grid--5 stats-row" *ngIf="!sessionMode">
        <button type="button" class="ui-kpi ui-kpi--brand" (click)="filterSeverity = ''; applyFilters()" [class.is-active]="!filterSeverity">
          <span class="ui-kpi__label"><i class="bi bi-list-ul"></i> Total</span>
          <span class="ui-kpi__value">{{ violationStats.total }}</span>
        </button>
        <button type="button" class="ui-kpi ui-kpi--info" (click)="filterSeverity = 'low'; applyFilters()" [class.is-active]="filterSeverity === 'low'">
          <span class="ui-kpi__label"><i class="bi bi-info-circle"></i> Low</span>
          <span class="ui-kpi__value">{{ violationStats.by_severity.low }}</span>
        </button>
        <button type="button" class="ui-kpi ui-kpi--warning" (click)="filterSeverity = 'medium'; applyFilters()" [class.is-active]="filterSeverity === 'medium'">
          <span class="ui-kpi__label"><i class="bi bi-exclamation-circle"></i> Medium</span>
          <span class="ui-kpi__value">{{ violationStats.by_severity.medium }}</span>
        </button>
        <button type="button" class="ui-kpi ui-kpi--danger" (click)="filterSeverity = 'high'; applyFilters()" [class.is-active]="filterSeverity === 'high'">
          <span class="ui-kpi__label"><i class="bi bi-exclamation-triangle"></i> High</span>
          <span class="ui-kpi__value">{{ violationStats.by_severity.high }}</span>
        </button>
        <button type="button" class="ui-kpi ui-kpi--danger" (click)="filterSeverity = 'critical'; applyFilters()" [class.is-active]="filterSeverity === 'critical'">
          <span class="ui-kpi__label"><i class="bi bi-x-octagon"></i> Critical</span>
          <span class="ui-kpi__value">{{ violationStats.by_severity.critical }}</span>
        </button>
      </div>

      <section class="ui-section violations-section">
        <div class="ui-section__head">
          <div>
            <h2 class="ui-section__title">Violations</h2>
            <p class="ui-section__subtitle">{{ filteredViolations.length }} violations</p>
          </div>
          <div class="ui-section__actions bulk-bar" *ngIf="selectedIds.length > 0">
            <span class="ui-pill ui-pill--brand">{{ selectedIds.length }} selected</span>
            <button class="btn btn-sm btn-outline-secondary" (click)="bulkDismiss()">
              <tui-icon icon="@tui.x-circle"></tui-icon> Dismiss Selected
            </button>
          </div>
        </div>

        <!-- Top Violation Types -->
        <div class="ui-filters type-chips" *ngIf="!sessionMode && violationStats.by_type.length > 0">
          <button type="button" class="ui-filter type-chip" *ngFor="let t of violationStats.by_type.slice(0, 8)"
                [class.is-active]="filterType === t.type"
                (click)="filterType = filterType === t.type ? '' : t.type; applyFilters()">
            <tui-icon [icon]="getViolationIcon(t.type)"></tui-icon>
            {{ formatType(t.type) }} <span class="ui-filter__count">{{ t.count }}</span>
          </button>
        </div>

        <!-- Filters -->
        <div class="ui-toolbar filters-bar">
          <div class="ui-search search-box">
            <i class="bi bi-search"></i>
            <input type="search" placeholder="Search violations..."
                   [(ngModel)]="searchTerm" (ngModelChange)="onSearch()">
          </div>
          <div class="ui-filters">
            <select class="form-select" [(ngModel)]="filterDismissed" (change)="applyFilters()">
              <option value="">All</option>
              <option value="active">Active</option>
              <option value="dismissed">Dismissed</option>
            </select>
            <select class="form-select" [(ngModel)]="sortBy" (change)="applyFilters()">
              <option value="newest">Newest</option>
              <option value="oldest">Oldest</option>
              <option value="severity">Severity</option>
            </select>
          </div>
        </div>

        <!-- Violations List -->
        <div class="violations-list" *ngIf="!loading && filteredViolations.length > 0">
          <div class="violation-card" *ngFor="let v of paginatedViolations"
               [ngClass]="'border-' + v.severity" [class.dismissed]="v.dismissed">
            <div class="violation-select">
              <input type="checkbox" class="form-check-input" [checked]="selectedIds.includes(v.violation_id)"
                     (change)="toggleSelect(v.violation_id)">
            </div>
            <div class="violation-severity-icon" [ngClass]="'sev-icon-' + v.severity">
              <tui-icon [icon]="getViolationIcon(v.violation_type)"></tui-icon>
            </div>
            <div class="violation-body">
              <div class="violation-header-row">
                <span class="violation-type-label">{{ formatType(v.violation_type) }}</span>
                <span class="ui-pill severity-pill" [ngClass]="'pill-' + v.severity">{{ v.severity }}</span>
                <span class="ui-pill ui-pill--neutral dismissed-pill" *ngIf="v.dismissed">Dismissed</span>
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
              <button class="btn btn-sm btn-outline-secondary" *ngIf="!v.dismissed"
                      (click)="openDismissModal(v)" title="Dismiss">
                <tui-icon icon="@tui.x-circle"></tui-icon>
              </button>
            </div>
          </div>
        </div>

        <!-- Pagination -->
        <div class="pagination-bar" *ngIf="filteredViolations.length > pageSize">
          <span class="ui-muted small">{{ filteredViolations.length }} violations</span>
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

        <!-- Empty / Loading -->
        <div class="ui-state empty-state" *ngIf="!loading && filteredViolations.length === 0">
          <span class="ui-state__icon"><i class="bi bi-check-circle"></i></span>
          <h3>{{ hasFilters() ? 'No Matching Violations' : 'No Violations Recorded' }}</h3>
        </div>
        <app-skeleton *ngIf="loading" variant="list" [rows]="6"></app-skeleton>
      </section>

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
              <p><strong>Severity:</strong> <span [ngClass]="'pill-' + evidenceTarget.severity" class="ui-pill severity-pill">{{ evidenceTarget.severity }}</span></p>
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
            <button class="btn btn-outline-secondary btn-sm" (click)="showDismissModal = false">Cancel</button>
            <button class="btn btn-primary btn-sm" (click)="executeDismiss()">Dismiss</button>
          </div>
        </div>
      </div>
    </div>
    </main>
  `,
  styles: [`
    /* Page chrome (header, KPIs, section, toolbar, pills, states) comes from the global UI kit. */
    .ui-kpi-grid { margin-bottom: 20px; }
    .ui-section__head { align-items: center; }
    .ui-toolbar { margin-bottom: 16px; }
    .ui-search { flex: 1 1 260px; }
    .type-chips { margin-bottom: 14px; }

    /* Violation Cards */
    .violations-list { display: flex; flex-direction: column; gap: 0.5rem; }
    .violation-card {
      display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.75rem 1rem;
      background: var(--ui-surface, #fff); border: 1px solid var(--ui-border, #e7e9e7); border-radius: var(--ui-radius-ctl, 8px); border-left: 3px solid;
    }
    .violation-card.dismissed { opacity: 0.6; }
    .border-low { border-left-color: var(--ui-info, #2563a8); }
    .border-medium { border-left-color: var(--ui-warning, #b25e09); }
    .border-high { border-left-color: var(--ui-danger, #c0352b); }
    .border-critical { border-left-color: var(--ui-danger-text, #a12c24); }
    .violation-select { padding-top: 0.25rem; }
    .violation-severity-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center;
      justify-content: center; flex-shrink: 0; }
    .sev-icon-low { background: rgba(var(--ui-info-rgb), .1); color: var(--ui-info-text, #1d518c); }
    .sev-icon-medium { background: rgba(var(--ui-warning-rgb), .1); color: var(--ui-warning-text, #8f4b07); }
    .sev-icon-high { background: rgba(var(--ui-danger-rgb), .1); color: var(--ui-danger-text, #a12c24); }
    .sev-icon-critical { background: rgba(var(--ui-danger-rgb), .18); color: var(--ui-danger-text, #a12c24); }
    .violation-body { flex: 1; min-width: 0; }
    .violation-header-row { display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem; flex-wrap: wrap; }
    .violation-type-label { font-weight: 600; font-size: 0.875rem; }
    .severity-pill { text-transform: capitalize; }
    .pill-low { background: rgba(var(--ui-info-rgb), .1); border-color: rgba(var(--ui-info-rgb), .28); color: var(--ui-info-text); }
    .pill-medium { background: rgba(var(--ui-warning-rgb), .1); border-color: rgba(var(--ui-warning-rgb), .28); color: var(--ui-warning-text); }
    .pill-high { background: rgba(var(--ui-danger-rgb), .1); border-color: rgba(var(--ui-danger-rgb), .28); color: var(--ui-danger-text); }
    .pill-critical { background: rgba(var(--ui-danger-rgb), .16); border-color: rgba(var(--ui-danger-rgb), .4); color: var(--ui-danger-text); }
    .violation-description { font-size: 0.8125rem; color: var(--ui-text-muted, #6a716b); margin: 0.25rem 0; }
    .violation-meta { display: flex; gap: 0.75rem; flex-wrap: wrap; font-size: 0.75rem; color: var(--ui-text-subtle, #8d948e); }
    .violation-meta span { display: flex; align-items: center; gap: 0.25rem; }
    .dismiss-info { font-size: 0.75rem; color: var(--ui-text-muted, #6a716b); font-style: italic; margin-top: 0.25rem; }
    .violation-actions { display: flex; gap: 0.25rem; flex-shrink: 0; }

    .pagination-bar { display: flex; justify-content: space-between; align-items: center; padding: 16px 0 0; margin-top: 16px; border-top: 1px solid var(--ui-border, #e7e9e7); }
    .pagination-controls { display: flex; align-items: center; gap: 0.5rem; }
    .page-info { font-size: 0.8125rem; color: var(--ui-text-muted, #6a716b); }


    /* Modals */
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1050;
      display: flex; align-items: center; justify-content: center; }
    .modal-dialog { background: var(--ui-surface, #fff); border-radius: var(--ui-radius-card, 12px); max-width: 600px; width: 95%; }
    .modal-dialog.modal-sm { max-width: 400px; }
    .modal-header { padding: 1rem 1.25rem; border-bottom: 1px solid var(--ui-border, #e7e9e7); display: flex; justify-content: space-between; align-items: center; }
    .modal-title { font-size: 1rem; font-weight: 650; display: flex; align-items: center; gap: 0.5rem; margin: 0; }
    .modal-body { padding: 1.25rem; }
    .modal-footer { padding: 0.75rem 1.25rem; border-top: 1px solid var(--ui-border, #e7e9e7); display: flex; justify-content: flex-end; gap: 0.5rem; }
    .evidence-image { max-width: 100%; max-height: 400px; border-radius: var(--ui-radius-ctl, 8px); border: 1px solid var(--ui-border, #e7e9e7); }
    .evidence-video { max-width: 100%; border-radius: var(--ui-radius-ctl, 8px); }
  `]
})
export class ViolationViewerComponent implements OnInit, OnDestroy {
  private assessments = inject(AssessmentsService);
  private destroy$ = new Subject<void>();
  private searchSubject = new Subject<string>();

  // In the new API a sitting is addressed by its attempt id, so this input is
  // really an attempt id when the viewer is embedded in a sitting review.
  @Input() sessionId: string | null = null;
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
  selectedIds: string[] = [];

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
    // Violations are reachable per sitting (by attempt id) only. There is no
    // cross-sitting "all violations" list on the new API, so the global view
    // shows nothing rather than a broken call.
    if (!this.sessionId) {
      this.violations = [];
      this.applyFilters();

      return;
    }
    this.loading = true;
    this.assessments.sitting(this.sessionId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (payload) => {
        const rows = (payload['violations'] as Record<string, unknown>[]) ?? [];
        this.violations = rows.map((r) => this.toViolation(r));
        this.applyFilters();
        this.loading = false;
      },
      error: () => { this.loading = false; }
    });
  }

  /** Maps a sitting's violation row onto this screen's shape. */
  private toViolation(r: Record<string, unknown>): Violation {
    const status = (r['status'] as string) ?? '';
    return {
      violation_id: String(r['id'] ?? ''),
      session_id: String(r['sessionId'] ?? this.sessionId ?? ''),
      attempt_id: String(r['attemptId'] ?? this.sessionId ?? ''),
      user_id: String(r['userId'] ?? ''),
      learner_name: (r['learnerName'] as string) ?? '',
      assessment_title: (r['assessmentTitle'] as string) ?? '',
      violation_type: (r['type'] as string) ?? '',
      severity: (r['severity'] as string) ?? 'medium',
      description: (r['description'] as string) ?? '',
      evidence_url: (r['evidenceUrl'] as string) ?? '',
      evidence_type: (r['evidenceType'] as string) ?? '',
      ip_address: (r['ipAddress'] as string) ?? '',
      created_at: (r['occurredAt'] as string) ?? (r['createdAt'] as string) ?? '',
      dismissed: status === 'false_positive',
      dismissed_by_name: (r['reviewedByName'] as string) ?? '',
      dismissed_at: (r['reviewedAt'] as string) ?? '',
      dismiss_reason: (r['notes'] as string) ?? '',
    };
  }

  /** No violation-analytics route exists; the stats are totalled client-side. */
  loadStats(): void {
    const sev = { low: 0, medium: 0, high: 0, critical: 0 } as Record<string, number>;
    const byType = new Map<string, number>();
    for (const v of this.violations) {
      if (sev[v.severity] !== undefined) sev[v.severity]++;
      byType.set(v.violation_type, (byType.get(v.violation_type) ?? 0) + 1);
    }
    this.violationStats = {
      total: this.violations.length,
      by_severity: sev as ViolationStats['by_severity'],
      by_type: Array.from(byType, ([type, count]) => ({ type, count })),
      top_offenders: [],
    };
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

  toggleSelect(id: string): void {
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
    // Dismissing marks the violation a false positive -- the status the new
    // review takes, which also removes it from the trust score. The reason and
    // any note are carried across as free text.
    const notes = this.dismissReason === 'other' ? this.dismissNote : this.dismissReason;
    this.assessments.reviewViolation(this.dismissTarget.violation_id, { status: 'false_positive', notes })
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: () => {
          this.dismissTarget!.dismissed = true;
          this.showDismissModal = false;
        }
      });
  }

  bulkDismiss(): void {
    // No batch review route, so fan out one call per selected violation.
    const ids = [...this.selectedIds];
    for (const id of ids) {
      this.assessments.reviewViolation(id, { status: 'false_positive', notes: 'bulk_dismiss' })
        .pipe(takeUntil(this.destroy$)).subscribe();
    }
    this.violations.filter(v => ids.includes(v.violation_id)).forEach(v => v.dismissed = true);
    this.selectedIds = [];
    this.applyFilters();
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
