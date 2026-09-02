// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 12
// Admin: Snapshot Gallery Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { AssessmentsService } from '../../services/assessments.service';

// ==========================================
// INTERFACES
// ==========================================

interface Snapshot {
  snapshot_id: string;
  session_id: string;
  attempt_id: string;
  user_id: string;
  learner_name: string;
  image_url: string;
  thumbnail_url: string;
  faces_detected: number;
  face_confidence: number;
  ai_analysis: AiAnalysis | null;
  has_violation: boolean;
  violation_type: string;
  created_at: string;
  flagged: boolean;
  flag_reason: string;
}

interface AiAnalysis {
  faces_count: number;
  primary_face_confidence: number;
  face_match_score: number;
  looking_away: boolean;
  mouth_open: boolean;
  eyes_closed: boolean;
  suspicious: boolean;
  notes: string;
}

interface SnapshotSessionInfo {
  session_id: string;
  learner_name: string;
  learner_email: string;
  assessment_title: string;
  proctoring_level: string;
  trust_score: number;
  total_snapshots: number;
  flagged_snapshots: number;
  started_at: string;
  duration_minutes: number;
}

@Component({
  selector: 'app-snapshot-gallery',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>

    <div class="snapshot-gallery-container">
      <!-- Header -->
      <div class="page-header">
        <div class="header-left">
          <button class="btn btn-sm btn-secondary me-2" (click)="goBack()">
            <tui-icon icon="@tui.arrow-left"></tui-icon>
          </button>
          <div>
            <h1 class="page-title">
              <tui-icon icon="@tui.camera"></tui-icon>
              Snapshot Gallery
            </h1>
            <p class="page-subtitle" *ngIf="sessionInfo">
              {{ sessionInfo.learner_name }} - {{ sessionInfo.assessment_title }}
            </p>
          </div>
        </div>
        <div class="header-actions">
          <button class="btn btn-sm btn-primary" (click)="toggleView()">
            <tui-icon [icon]="viewMode === 'grid' ? '@tui.list' : '@tui.grid'"></tui-icon>
            {{ viewMode === 'grid' ? 'List' : 'Grid' }}
          </button>
          <button class="btn btn-sm btn-secondary" (click)="downloadAll()" [disabled]="snapshots.length === 0">
            <tui-icon icon="@tui.download"></tui-icon> Export
          </button>
        </div>
      </div>

      <div class="alert alert-info d-flex align-items-center gap-2" *ngIf="notice">
        <tui-icon icon="@tui.info"></tui-icon>
        <span>{{ notice }}</span>
        <button type="button" class="btn-close ms-auto" (click)="notice = null"></button>
      </div>

      <!-- Session Info Bar -->
      <div class="session-bar" *ngIf="sessionInfo">
        <div class="info-chip">
          <span class="chip-label">Trust Score</span>
          <span class="chip-value" [ngClass]="getTrustClass(sessionInfo.trust_score)">{{ sessionInfo.trust_score }}%</span>
        </div>
        <div class="info-chip">
          <span class="chip-label">Total Snapshots</span>
          <span class="chip-value">{{ sessionInfo.total_snapshots }}</span>
        </div>
        <div class="info-chip">
          <span class="chip-label">Flagged</span>
          <span class="chip-value text-danger">{{ sessionInfo.flagged_snapshots }}</span>
        </div>
        <div class="info-chip">
          <span class="chip-label">Duration</span>
          <span class="chip-value">{{ sessionInfo.duration_minutes }}m</span>
        </div>
        <div class="info-chip">
          <span class="chip-label">Level</span>
          <span class="level-tag" [ngClass]="'level-' + sessionInfo.proctoring_level">{{ sessionInfo.proctoring_level }}</span>
        </div>
      </div>

      <!-- Filters -->
      <div class="filters-bar">
        <div class="filter-tabs">
          <button class="filter-tab" [class.active]="filterMode === 'all'" (click)="filterMode = 'all'; applyFilter()">
            All ({{ snapshots.length }})
          </button>
          <button class="filter-tab" [class.active]="filterMode === 'flagged'" (click)="filterMode = 'flagged'; applyFilter()">
            <tui-icon icon="@tui.flag"></tui-icon> Flagged ({{ flaggedCount }})
          </button>
          <button class="filter-tab" [class.active]="filterMode === 'violations'" (click)="filterMode = 'violations'; applyFilter()">
            <tui-icon icon="@tui.alert-triangle"></tui-icon> Violations ({{ violationCount }})
          </button>
          <button class="filter-tab" [class.active]="filterMode === 'no_face'" (click)="filterMode = 'no_face'; applyFilter()">
            <tui-icon icon="@tui.user-x"></tui-icon> No Face ({{ noFaceCount }})
          </button>
          <button class="filter-tab" [class.active]="filterMode === 'multi_face'" (click)="filterMode = 'multi_face'; applyFilter()">
            <tui-icon icon="@tui.users"></tui-icon> Multiple ({{ multiFaceCount }})
          </button>
        </div>
        <select class="form-select form-select-sm" [(ngModel)]="sortOrder" (change)="applyFilter()">
          <option value="newest">Newest First</option>
          <option value="oldest">Oldest First</option>
          <option value="confidence_asc">Confidence: Low → High</option>
          <option value="confidence_desc">Confidence: High → Low</option>
        </select>
      </div>

      <!-- GRID VIEW -->
      <div class="snapshot-grid" *ngIf="!loading && viewMode === 'grid' && filteredSnapshots.length > 0">
        <div class="snapshot-card" *ngFor="let s of paginatedSnapshots; let i = index"
             [class.flagged-card]="s.flagged" [class.violation-card]="s.has_violation"
             (click)="openLightbox(s, i)">
          <div class="snapshot-thumb">
            <img [src]="s.thumbnail_url || s.image_url" [alt]="'Snapshot ' + s.snapshot_id" loading="lazy">
            <!-- Overlays -->
            <div class="thumb-overlay">
              <span class="face-count" [ngClass]="getFaceCountClass(s.faces_detected)">
                <tui-icon icon="@tui.user"></tui-icon> {{ s.faces_detected }}
              </span>
              <span class="flag-icon" *ngIf="s.flagged" title="Flagged">
                <tui-icon icon="@tui.flag"></tui-icon>
              </span>
              <span class="violation-icon" *ngIf="s.has_violation" title="{{ formatType(s.violation_type) }}">
                <tui-icon icon="@tui.alert-triangle"></tui-icon>
              </span>
            </div>
            <!-- Confidence Bar -->
            <div class="confidence-bar" *ngIf="s.face_confidence > 0">
              <div class="confidence-fill" [style.width.%]="s.face_confidence"
                   [ngClass]="getConfidenceClass(s.face_confidence)"></div>
            </div>
          </div>
          <div class="snapshot-meta">
            <span class="snapshot-time">{{ formatTime(s.created_at) }}</span>
            <span class="snapshot-confidence" *ngIf="s.face_confidence">{{ s.face_confidence }}%</span>
          </div>
        </div>
      </div>

      <!-- LIST VIEW -->
      <div class="snapshot-list" *ngIf="!loading && viewMode === 'list' && filteredSnapshots.length > 0">
        <div class="list-item" *ngFor="let s of paginatedSnapshots; let i = index"
             [class.flagged-card]="s.flagged" (click)="openLightbox(s, i)">
          <img [src]="s.thumbnail_url || s.image_url" class="list-thumb" [alt]="'Snapshot'">
          <div class="list-body">
            <div class="list-time">{{ formatDateTime(s.created_at) }}</div>
            <div class="list-tags">
              <span class="face-tag" [ngClass]="getFaceCountClass(s.faces_detected)">{{ s.faces_detected }} face{{ s.faces_detected !== 1 ? 's' : '' }}</span>
              <span class="conf-tag" *ngIf="s.face_confidence">{{ s.face_confidence }}% confidence</span>
              <span class="flag-tag" *ngIf="s.flagged">Flagged</span>
              <span class="violation-tag" *ngIf="s.has_violation">{{ formatType(s.violation_type) }}</span>
            </div>
            <div class="list-ai" *ngIf="s.ai_analysis">
              <span *ngIf="s.ai_analysis.looking_away" class="ai-tag warn">Looking Away</span>
              <span *ngIf="s.ai_analysis.eyes_closed" class="ai-tag warn">Eyes Closed</span>
              <span *ngIf="s.ai_analysis.mouth_open" class="ai-tag info">Mouth Open</span>
              <span *ngIf="s.ai_analysis.suspicious" class="ai-tag danger">Suspicious</span>
            </div>
          </div>
          <div class="list-actions" (click)="$event.stopPropagation()">
            <button class="btn btn-sm btn-primary" (click)="openLightbox(s, i)" title="View">
              <tui-icon icon="@tui.maximize-2"></tui-icon>
            </button>
            <button class="btn btn-sm" [ngClass]="s.flagged ? 'btn-danger' : 'btn-secondary'"
                    (click)="toggleFlag(s)" [title]="s.flagged ? 'Unflag' : 'Flag'">
              <tui-icon icon="@tui.flag"></tui-icon>
            </button>
          </div>
        </div>
      </div>

      <!-- Pagination -->
      <div class="pagination-bar" *ngIf="filteredSnapshots.length > pageSize">
        <span class="text-muted small">{{ filteredSnapshots.length }} snapshots</span>
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
      <div class="empty-state" *ngIf="!loading && filteredSnapshots.length === 0">
        <tui-icon icon="@tui.camera-off"></tui-icon>
        <h5>{{ filterMode !== 'all' ? 'No matching snapshots' : 'No snapshots captured' }}</h5>
        <p class="text-muted" *ngIf="filterMode !== 'all'">Try showing all snapshots</p>
      </div>
      <div class="loading-state" *ngIf="loading">
        <div class="spinner-border text-primary"></div>
        <p>Loading snapshots...</p>
      </div>

      <!-- ==========================================
           LIGHTBOX MODAL
           ========================================== -->
      <div class="lightbox-overlay" *ngIf="showLightbox && lightboxSnapshot" (click)="closeLightbox()">
        <div class="lightbox-content" (click)="$event.stopPropagation()">
          <!-- Navigation -->
          <button class="lightbox-nav nav-prev" (click)="prevSnapshot()" *ngIf="lightboxIndex > 0">
            <tui-icon icon="@tui.chevron-left"></tui-icon>
          </button>
          <button class="lightbox-nav nav-next" (click)="nextSnapshot()"
                  *ngIf="lightboxIndex < filteredSnapshots.length - 1">
            <tui-icon icon="@tui.chevron-right"></tui-icon>
          </button>

          <!-- Close -->
          <button class="lightbox-close" (click)="closeLightbox()">
            <tui-icon icon="@tui.x"></tui-icon>
          </button>

          <!-- Image -->
          <div class="lightbox-image-wrapper">
            <img [src]="lightboxSnapshot.image_url" [alt]="'Snapshot'" class="lightbox-image">
          </div>

          <!-- Info Panel -->
          <div class="lightbox-info">
            <div class="lightbox-info-left">
              <span class="lb-time">{{ formatDateTime(lightboxSnapshot.created_at) }}</span>
              <span class="lb-counter">{{ lightboxIndex + 1 }} / {{ filteredSnapshots.length }}</span>
            </div>
            <div class="lightbox-info-center">
              <span class="face-tag" [ngClass]="getFaceCountClass(lightboxSnapshot.faces_detected)">
                {{ lightboxSnapshot.faces_detected }} face{{ lightboxSnapshot.faces_detected !== 1 ? 's' : '' }}
              </span>
              <span class="conf-tag" *ngIf="lightboxSnapshot.face_confidence">
                {{ lightboxSnapshot.face_confidence }}% confidence
              </span>
              <span class="flag-tag" *ngIf="lightboxSnapshot.flagged">Flagged</span>
              <span class="violation-tag" *ngIf="lightboxSnapshot.has_violation">
                {{ formatType(lightboxSnapshot.violation_type) }}
              </span>
            </div>
            <div class="lightbox-info-right">
              <button class="btn btn-sm" [ngClass]="lightboxSnapshot.flagged ? 'btn-danger' : 'btn-outline-light'"
                      (click)="toggleFlag(lightboxSnapshot)">
                <tui-icon icon="@tui.flag"></tui-icon> {{ lightboxSnapshot.flagged ? 'Unflag' : 'Flag' }}
              </button>
            </div>
          </div>

          <!-- AI Analysis -->
          <div class="lightbox-ai" *ngIf="lightboxSnapshot.ai_analysis">
            <h6>AI Analysis</h6>
            <div class="ai-tags">
              <span class="ai-detail">Face Match: {{ lightboxSnapshot.ai_analysis.face_match_score }}%</span>
              <span *ngIf="lightboxSnapshot.ai_analysis.looking_away" class="ai-tag warn">Looking Away</span>
              <span *ngIf="lightboxSnapshot.ai_analysis.eyes_closed" class="ai-tag warn">Eyes Closed</span>
              <span *ngIf="lightboxSnapshot.ai_analysis.mouth_open" class="ai-tag info">Mouth Open</span>
              <span *ngIf="lightboxSnapshot.ai_analysis.suspicious" class="ai-tag danger">Suspicious</span>
              <span *ngIf="lightboxSnapshot.ai_analysis.notes" class="ai-note">{{ lightboxSnapshot.ai_analysis.notes }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .snapshot-gallery-container { padding: 1.5rem; }
    .page-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; }
    .header-left { display: flex; align-items: flex-start; gap: 0.5rem; }
    .page-title { font-size: 1.375rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; margin: 0; }
    .page-subtitle { color: #6c757d; font-size: 0.8125rem; margin: 0.125rem 0 0; }
    .header-actions { display: flex; gap: 0.5rem; }

    /* Session Bar */
    .session-bar { display: flex; flex-wrap: wrap; gap: 0.75rem; padding: 0.625rem 1rem; background: #f8f9fa;
      border-radius: 0.5rem; margin-bottom: 1rem; border: 1px solid #e9ecef; }
    .info-chip { display: flex; flex-direction: column; }
    .chip-label { font-size: 0.5625rem; color: #6c757d; text-transform: uppercase; font-weight: 600; }
    .chip-value { font-size: 0.9375rem; font-weight: 600; }
    .level-tag { font-size: 0.6875rem; padding: 0.0625rem 0.375rem; border-radius: 0.25rem; text-transform: capitalize; font-weight: 500; }
    .level-basic { background: #cfe2ff; color: #084298; }
    .level-standard { background: #fff3cd; color: #664d03; }
    .level-advanced { background: #f8d7da; color: #842029; }

    /* Filters */
    .filters-bar { display: flex; justify-content: space-between; align-items: center; gap: 0.75rem; margin-bottom: 1rem; flex-wrap: wrap; }
    .filter-tabs { display: flex; gap: 0.25rem; flex-wrap: wrap; }
    .filter-tab {
      padding: 0.375rem 0.75rem; font-size: 0.75rem; border: 1px solid #dee2e6; border-radius: 2rem;
      background: #fff; cursor: pointer; display: flex; align-items: center; gap: 0.25rem; transition: all 0.2s;
    }
    .filter-tab:hover { border-color: #6c757d; }
    .filter-tab.active { background: #0d6efd; color: #fff; border-color: #0d6efd; }

    /* GRID */
    .snapshot-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 0.75rem; }
    .snapshot-card { background: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem; overflow: hidden; cursor: pointer;
      transition: all 0.2s; }
    .snapshot-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.1); transform: translateY(-1px); }
    .snapshot-card.flagged-card { border-color: #dc3545; }
    .snapshot-card.violation-card { border-color: #ffc107; }
    .snapshot-thumb { position: relative; aspect-ratio: 4/3; overflow: hidden; background: #f0f0f0; }
    .snapshot-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .thumb-overlay { position: absolute; top: 0.375rem; left: 0.375rem; right: 0.375rem; display: flex;
      justify-content: space-between; align-items: flex-start; }
    .face-count { display: flex; align-items: center; gap: 0.125rem; font-size: 0.625rem; font-weight: 600;
      padding: 0.0625rem 0.375rem; border-radius: 1rem; }
    .face-ok { background: rgba(40,167,69,0.85); color: #fff; }
    .face-none { background: rgba(220,53,69,0.85); color: #fff; }
    .face-multi { background: rgba(255,193,7,0.85); color: #000; }
    .flag-icon, .violation-icon { font-size: 0.75rem; background: rgba(0,0,0,0.5); color: #fff; border-radius: 50%;
      width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; }
    .flag-icon { color: #f44336; }
    .violation-icon { color: #ffc107; }
    .confidence-bar { position: absolute; bottom: 0; left: 0; right: 0; height: 3px; background: rgba(0,0,0,0.2); }
    .confidence-fill { height: 100%; }
    .conf-high { background: #28a745; }
    .conf-med { background: #ffc107; }
    .conf-low { background: #dc3545; }
    .snapshot-meta { padding: 0.375rem 0.5rem; display: flex; justify-content: space-between; font-size: 0.6875rem; }
    .snapshot-time { color: #6c757d; }
    .snapshot-confidence { font-weight: 500; }

    /* LIST */
    .snapshot-list { display: flex; flex-direction: column; gap: 0.5rem; }
    .list-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0.75rem; background: #fff;
      border: 1px solid #e9ecef; border-radius: 0.5rem; cursor: pointer; transition: all 0.2s; }
    .list-item:hover { background: #f8f9fa; }
    .list-item.flagged-card { border-left: 3px solid #dc3545; }
    .list-thumb { width: 64px; height: 48px; object-fit: cover; border-radius: 0.375rem; flex-shrink: 0; }
    .list-body { flex: 1; }
    .list-time { font-size: 0.8125rem; font-weight: 500; }
    .list-tags { display: flex; gap: 0.375rem; flex-wrap: wrap; margin-top: 0.125rem; }
    .face-tag, .conf-tag, .flag-tag, .violation-tag { font-size: 0.625rem; padding: 0.0625rem 0.375rem; border-radius: 1rem; }
    .face-tag.face-ok { background: #d4edda; color: #155724; }
    .face-tag.face-none { background: #f8d7da; color: #842029; }
    .face-tag.face-multi { background: #fff3cd; color: #856404; }
    .conf-tag { background: #e2e3e5; color: #495057; }
    .flag-tag { background: #f8d7da; color: #842029; }
    .violation-tag { background: #fff3cd; color: #856404; }
    .list-ai { display: flex; gap: 0.25rem; flex-wrap: wrap; margin-top: 0.25rem; }
    .ai-tag { font-size: 0.5625rem; padding: 0.0625rem 0.25rem; border-radius: 0.25rem; }
    .ai-tag.warn { background: #fff3cd; color: #856404; }
    .ai-tag.info { background: #cce5ff; color: #004085; }
    .ai-tag.danger { background: #f8d7da; color: #842029; }
    .list-actions { display: flex; gap: 0.25rem; flex-shrink: 0; }

    /* Pagination */
    .pagination-bar { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; }
    .pagination-controls { display: flex; align-items: center; gap: 0.5rem; }
    .page-info { font-size: 0.8125rem; color: #6c757d; }

    .empty-state, .loading-state { text-align: center; padding: 3rem; }
    .empty-state tui-icon { font-size: 2.5rem; color: #adb5bd; margin-bottom: 0.75rem; }

    /* LIGHTBOX */
    .lightbox-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.92); z-index: 1060;
      display: flex; align-items: center; justify-content: center; }
    .lightbox-content { position: relative; max-width: 90vw; max-height: 90vh; display: flex; flex-direction: column; }
    .lightbox-close { position: absolute; top: -40px; right: 0; background: none; border: none; color: #fff;
      font-size: 1.5rem; cursor: pointer; z-index: 10; }
    .lightbox-nav { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.15);
      border: none; color: #fff; width: 44px; height: 44px; border-radius: 50%; cursor: pointer;
      display: flex; align-items: center; justify-content: center; z-index: 10; transition: background 0.2s; }
    .lightbox-nav:hover { background: rgba(255,255,255,0.3); }
    .nav-prev { left: -56px; }
    .nav-next { right: -56px; }
    .lightbox-image-wrapper { display: flex; justify-content: center; }
    .lightbox-image { max-width: 100%; max-height: 70vh; border-radius: 0.5rem; }
    .lightbox-info { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0;
      color: #fff; gap: 1rem; }
    .lightbox-info-left { display: flex; gap: 1rem; align-items: center; }
    .lightbox-info-center { display: flex; gap: 0.375rem; flex-wrap: wrap; }
    .lb-time { font-size: 0.8125rem; }
    .lb-counter { font-size: 0.75rem; color: #adb5bd; }
    .lightbox-ai { background: rgba(255,255,255,0.08); border-radius: 0.375rem; padding: 0.5rem 0.75rem; color: #fff; }
    .lightbox-ai h6 { font-size: 0.75rem; font-weight: 600; margin-bottom: 0.375rem; }
    .ai-tags { display: flex; gap: 0.375rem; flex-wrap: wrap; align-items: center; }
    .ai-detail { font-size: 0.75rem; }
    .ai-note { font-size: 0.6875rem; color: #adb5bd; font-style: italic; }

    @media (max-width: 768px) {
      .snapshot-grid { grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); }
      .lightbox-nav { display: none; }
    }
  `]
})
export class SnapshotGalleryComponent implements OnInit, OnDestroy {
  private assessments = inject(AssessmentsService);
  private route = inject(ActivatedRoute);
  private router = inject(Router);
  private destroy$ = new Subject<void>();

  // Really an attempt id now -- sittings are addressed by attempt.
  @Input() sessionIdInput: string | null = null;

  // Data
  snapshots: Snapshot[] = [];
  filteredSnapshots: Snapshot[] = [];
  sessionInfo: SnapshotSessionInfo | null = null;
  sessionId: string | null = null;
  notice: string | null = null;

  // State
  loading = false;
  viewMode: 'grid' | 'list' = 'grid';
  filterMode = 'all';
  sortOrder = 'oldest';
  page = 1;
  pageSize = 40;

  // Lightbox
  showLightbox = false;
  lightboxSnapshot: Snapshot | null = null;
  lightboxIndex = 0;

  get totalPages(): number { return Math.ceil(this.filteredSnapshots.length / this.pageSize) || 1; }
  get paginatedSnapshots(): Snapshot[] {
    const start = (this.page - 1) * this.pageSize;
    return this.filteredSnapshots.slice(start, start + this.pageSize);
  }
  get flaggedCount(): number { return this.snapshots.filter(s => s.flagged).length; }
  get violationCount(): number { return this.snapshots.filter(s => s.has_violation).length; }
  get noFaceCount(): number { return this.snapshots.filter(s => s.faces_detected === 0).length; }
  get multiFaceCount(): number { return this.snapshots.filter(s => s.faces_detected > 1).length; }

  ngOnInit(): void {
    // Read the route param as-is: a UUID coerced with + would be NaN.
    this.sessionId = this.sessionIdInput || this.route.snapshot.params['sessionId'] || null;
    if (this.sessionId) this.loadSnapshots();

    // Keyboard nav
    document.addEventListener('keydown', this.onKeyDown.bind(this));
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
    document.removeEventListener('keydown', this.onKeyDown.bind(this));
  }

  // ==========================================
  // DATA
  // ==========================================

  /**
   * The sitting carries the snapshots and enough metadata to derive the header
   * (counts and duration are worked out from the frames and the attempt rather
   * than read from a prepared projection).
   */
  loadSnapshots(): void {
    if (!this.sessionId) return;
    this.loading = true;
    this.assessments.sitting(this.sessionId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (payload) => {
        const rows = (payload['snapshots'] as Record<string, unknown>[]) ?? [];
        this.snapshots = rows.map((r) => this.toSnapshot(r));
        this.sessionInfo = this.deriveInfo(payload);
        this.applyFilter();
        this.loading = false;
      },
      error: () => { this.loading = false; }
    });
  }

  private toSnapshot(r: Record<string, unknown>): Snapshot {
    return {
      snapshot_id: String(r['id'] ?? ''),
      session_id: String(r['sessionId'] ?? this.sessionId ?? ''),
      attempt_id: String(r['attemptId'] ?? this.sessionId ?? ''),
      user_id: String(r['userId'] ?? ''),
      learner_name: (r['learnerName'] as string) ?? '',
      image_url: (r['imageUrl'] as string) ?? '',
      thumbnail_url: (r['thumbnailUrl'] as string) ?? (r['imageUrl'] as string) ?? '',
      faces_detected: Number(r['faceCount'] ?? (r['faceDetected'] ? 1 : 0)),
      face_confidence: Number(r['faceConfidence'] ?? 0),
      // The new payload carries no AI-analysis or per-snapshot violation link.
      ai_analysis: null,
      has_violation: (r['kind'] as string) === 'violation',
      violation_type: '',
      created_at: (r['capturedAt'] as string) ?? (r['createdAt'] as string) ?? '',
      flagged: !!r['flagged'],
      flag_reason: (r['flagReason'] as string) ?? '',
    };
  }

  private deriveInfo(payload: Record<string, unknown>): SnapshotSessionInfo {
    const session = (payload['session'] as Record<string, unknown>) ?? {};
    const attempt = (payload['attempt'] as Record<string, unknown>) ?? {};
    const trust = (payload['trust'] as Record<string, unknown>) ?? {};
    const start = (attempt['startedAt'] as string) ?? '';
    const end = (attempt['submittedAt'] as string) ?? '';
    const minutes = start && end
      ? Math.max(0, Math.round((new Date(end).getTime() - new Date(start).getTime()) / 60000))
      : 0;
    return {
      session_id: this.sessionId ?? '',
      learner_name: (attempt['userName'] as string) ?? (session['learnerName'] as string) ?? '',
      learner_email: (session['learnerEmail'] as string) ?? '',
      assessment_title: (attempt['assessmentTitle'] as string) ?? (session['assessmentTitle'] as string) ?? '',
      proctoring_level: (session['proctoringLevel'] as string) ?? '',
      trust_score: Number(trust['score'] ?? 100),
      total_snapshots: this.snapshots.length,
      flagged_snapshots: this.snapshots.filter((s) => s.flagged).length,
      started_at: start,
      duration_minutes: minutes,
    } as SnapshotSessionInfo;
  }

  // ==========================================
  // FILTERS
  // ==========================================

  applyFilter(): void {
    let result = [...this.snapshots];

    switch (this.filterMode) {
      case 'flagged': result = result.filter(s => s.flagged); break;
      case 'violations': result = result.filter(s => s.has_violation); break;
      case 'no_face': result = result.filter(s => s.faces_detected === 0); break;
      case 'multi_face': result = result.filter(s => s.faces_detected > 1); break;
    }

    switch (this.sortOrder) {
      case 'newest': result.sort((a, b) => new Date(b.created_at).getTime() - new Date(a.created_at).getTime()); break;
      case 'oldest': result.sort((a, b) => new Date(a.created_at).getTime() - new Date(b.created_at).getTime()); break;
      case 'confidence_asc': result.sort((a, b) => (a.face_confidence || 0) - (b.face_confidence || 0)); break;
      case 'confidence_desc': result.sort((a, b) => (b.face_confidence || 0) - (a.face_confidence || 0)); break;
    }

    this.filteredSnapshots = result;
    this.page = 1;
  }

  // ==========================================
  // ACTIONS
  // ==========================================

  // A snapshot's flag is set when it is captured; there is no reviewer route to
  // toggle it afterwards on the new API yet.
  toggleFlag(_s: Snapshot): void {
    this.notice = 'Re-flagging a snapshot from review is being rebuilt.';
  }

  // No snapshot-bundle export route; a reviewer opens a frame and saves it.
  downloadAll(): void {
    this.notice = 'Bulk snapshot export is being rebuilt. Open a snapshot to save it individually.';
  }

  toggleView(): void { this.viewMode = this.viewMode === 'grid' ? 'list' : 'grid'; }
  goBack(): void { this.router.navigate(['/admin/proctoring/sessions']); }

  // ==========================================
  // LIGHTBOX
  // ==========================================

  openLightbox(s: Snapshot, idx: number): void {
    this.lightboxSnapshot = s;
    this.lightboxIndex = (this.page - 1) * this.pageSize + idx;
    this.showLightbox = true;
  }

  closeLightbox(): void { this.showLightbox = false; }

  prevSnapshot(): void {
    if (this.lightboxIndex > 0) {
      this.lightboxIndex--;
      this.lightboxSnapshot = this.filteredSnapshots[this.lightboxIndex];
    }
  }

  nextSnapshot(): void {
    if (this.lightboxIndex < this.filteredSnapshots.length - 1) {
      this.lightboxIndex++;
      this.lightboxSnapshot = this.filteredSnapshots[this.lightboxIndex];
    }
  }

  onKeyDown(e: KeyboardEvent): void {
    if (!this.showLightbox) return;
    if (e.key === 'ArrowLeft') this.prevSnapshot();
    if (e.key === 'ArrowRight') this.nextSnapshot();
    if (e.key === 'Escape') this.closeLightbox();
  }

  // ==========================================
  // HELPERS
  // ==========================================

  getTrustClass(score: number): string {
    if (score >= 80) return 'text-success';
    if (score >= 50) return 'text-warning';
    return 'text-danger';
  }

  getFaceCountClass(count: number): string {
    if (count === 1) return 'face-ok';
    if (count === 0) return 'face-none';
    return 'face-multi';
  }

  getConfidenceClass(conf: number): string {
    if (conf >= 80) return 'conf-high';
    if (conf >= 50) return 'conf-med';
    return 'conf-low';
  }

  formatType(type: string): string { return type?.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()) || ''; }

  formatTime(d: string): string {
    return d ? new Date(d).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '';
  }

  formatDateTime(d: string): string {
    return d ? new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '';
  }
}
