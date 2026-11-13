// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 12
// Admin: Snapshot Gallery Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { SelectComponent } from '../../shared/select/select.component';
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
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent, SkeletonComponent, SelectComponent],
  template: `
    <app-admintop></app-admintop>

    <main class="main">
    <div class="ui-page snapshot-gallery-container">
      <!-- Header -->
      <header class="ui-page-header">
        <button type="button" class="ui-page-header__back" (click)="goBack()" aria-label="Back" title="Back">
          <i class="bi bi-chevron-left"></i>
        </button>
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-camera"></i></div>
        <div class="ui-page-header__titles">
          <h1>Snapshot Gallery</h1>
          <p *ngIf="sessionInfo">{{ sessionInfo.learner_name }} - {{ sessionInfo.assessment_title }}</p>
          <p *ngIf="!sessionInfo">Webcam snapshots captured during the proctored session</p>
        </div>
        <div class="ui-page-header__actions">
          <button class="btn btn-sm btn-outline-secondary" (click)="toggleView()">
            <tui-icon [icon]="viewMode === 'grid' ? '@tui.list' : '@tui.grid'"></tui-icon>
            {{ viewMode === 'grid' ? 'List' : 'Grid' }}
          </button>
          <button class="btn btn-sm btn-primary" (click)="downloadAll()" [disabled]="snapshots.length === 0">
            <tui-icon icon="@tui.download"></tui-icon> Export
          </button>
        </div>
      </header>

      <div class="alert alert-info d-flex align-items-center gap-2" *ngIf="notice">
        <tui-icon icon="@tui.info"></tui-icon>
        <span>{{ notice }}</span>
        <button type="button" class="btn-close ms-auto" (click)="notice = null"></button>
      </div>

      <!-- Session Info Bar -->
      <div class="ui-kpi-grid ui-kpi-grid--5 session-bar" *ngIf="sessionInfo">
        <div class="ui-kpi">
          <span class="ui-kpi__label"><i class="bi bi-shield-check"></i> Trust Score</span>
          <span class="ui-kpi__value chip-value" [ngClass]="getTrustClass(sessionInfo.trust_score)">{{ sessionInfo.trust_score }}<small>%</small></span>
        </div>
        <div class="ui-kpi ui-kpi--brand">
          <span class="ui-kpi__label"><i class="bi bi-images"></i> Total Snapshots</span>
          <span class="ui-kpi__value">{{ sessionInfo.total_snapshots }}</span>
        </div>
        <div class="ui-kpi ui-kpi--danger">
          <span class="ui-kpi__label"><i class="bi bi-flag"></i> Flagged</span>
          <span class="ui-kpi__value">{{ sessionInfo.flagged_snapshots }}</span>
        </div>
        <div class="ui-kpi ui-kpi--info">
          <span class="ui-kpi__label"><i class="bi bi-clock"></i> Duration</span>
          <span class="ui-kpi__value">{{ sessionInfo.duration_minutes }}<small>m</small></span>
        </div>
        <div class="ui-kpi">
          <span class="ui-kpi__label"><i class="bi bi-sliders"></i> Level</span>
          <span class="level-tag ui-pill" [ngClass]="'level-' + sessionInfo.proctoring_level">{{ sessionInfo.proctoring_level }}</span>
        </div>
      </div>

      <section class="ui-section snapshots-section">
        <!-- Filters -->
        <div class="ui-toolbar filters-bar">
          <div class="ui-filters filter-tabs">
            <button class="ui-filter filter-tab" [class.is-active]="filterMode === 'all'" (click)="filterMode = 'all'; applyFilter()">
              All <span class="ui-filter__count">{{ snapshots.length }}</span>
            </button>
            <button class="ui-filter filter-tab" [class.is-active]="filterMode === 'flagged'" (click)="filterMode = 'flagged'; applyFilter()">
              <tui-icon icon="@tui.flag"></tui-icon> Flagged <span class="ui-filter__count">{{ flaggedCount }}</span>
            </button>
            <button class="ui-filter filter-tab" [class.is-active]="filterMode === 'violations'" (click)="filterMode = 'violations'; applyFilter()">
              <tui-icon icon="@tui.alert-triangle"></tui-icon> Violations <span class="ui-filter__count">{{ violationCount }}</span>
            </button>
            <button class="ui-filter filter-tab" [class.is-active]="filterMode === 'no_face'" (click)="filterMode = 'no_face'; applyFilter()">
              <tui-icon icon="@tui.user-x"></tui-icon> No Face <span class="ui-filter__count">{{ noFaceCount }}</span>
            </button>
            <button class="ui-filter filter-tab" [class.is-active]="filterMode === 'multi_face'" (click)="filterMode = 'multi_face'; applyFilter()">
              <tui-icon icon="@tui.users"></tui-icon> Multiple <span class="ui-filter__count">{{ multiFaceCount }}</span>
            </button>
          </div>
          <app-select style="width:210px" [options]="sortOpts" [searchable]="false" [(ngModel)]="sortOrder" (selectionChange)="applyFilter()" ariaLabel="Sort" />
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
              <button class="btn btn-sm" [ngClass]="s.flagged ? 'btn-danger' : 'btn-outline-secondary'"
                      (click)="toggleFlag(s)" [title]="s.flagged ? 'Unflag' : 'Flag'">
                <tui-icon icon="@tui.flag"></tui-icon>
              </button>
            </div>
          </div>
        </div>

        <!-- Pagination -->
        <div class="pagination-bar" *ngIf="filteredSnapshots.length > pageSize">
          <span class="ui-muted small">{{ filteredSnapshots.length }} snapshots</span>
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
        <div class="ui-state empty-state" *ngIf="!loading && filteredSnapshots.length === 0">
          <span class="ui-state__icon"><i class="bi bi-camera-video-off"></i></span>
          <h3>{{ filterMode !== 'all' ? 'No matching snapshots' : 'No snapshots captured' }}</h3>
          <p *ngIf="filterMode !== 'all'">Try showing all snapshots</p>
        </div>
        <app-skeleton *ngIf="loading && viewMode === 'grid'" variant="cards" [count]="8"></app-skeleton>
        <app-skeleton *ngIf="loading && viewMode !== 'grid'" variant="list" [rows]="6"></app-skeleton>
      </section>

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
    </main>
  `,
  styles: [`
    /* Page chrome (header, KPIs, section, filters, states) comes from the global UI kit. */
    .ui-kpi-grid { margin-bottom: 20px; }
    .ui-toolbar { margin-bottom: 16px; justify-content: space-between; }
    .level-tag { align-self: flex-start; text-transform: capitalize; margin-top: 4px; }
    .level-basic { background: rgba(var(--ui-info-rgb), .1); border-color: rgba(var(--ui-info-rgb), .28); color: var(--ui-info-text); }
    .level-standard { background: rgba(var(--ui-warning-rgb), .1); border-color: rgba(var(--ui-warning-rgb), .28); color: var(--ui-warning-text); }
    .level-advanced { background: rgba(var(--ui-danger-rgb), .1); border-color: rgba(var(--ui-danger-rgb), .28); color: var(--ui-danger-text); }

    /* GRID */
    .snapshot-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; }
    .snapshot-card { background: var(--ui-surface, #fff); border: 1px solid var(--ui-border, #e7e9e7); border-radius: var(--ui-radius-card, 12px); overflow: hidden; cursor: pointer;
      transition: border-color .15s ease, box-shadow .2s ease; }
    .snapshot-card:hover { border-color: var(--ui-border-strong, #d9ddd9); box-shadow: 0 6px 20px rgba(16, 24, 18, .06); }
    .snapshot-card.flagged-card { border-color: rgba(var(--ui-danger-rgb), .55); }
    .snapshot-card.violation-card { border-color: rgba(var(--ui-warning-rgb), .55); }
    .snapshot-thumb { position: relative; aspect-ratio: 4/3; overflow: hidden; background: var(--ui-surface-2, #f7f8f7); }
    .snapshot-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .thumb-overlay { position: absolute; top: 0.375rem; left: 0.375rem; right: 0.375rem; display: flex;
      justify-content: space-between; align-items: flex-start; }
    .face-count { display: flex; align-items: center; gap: 0.125rem; font-size: 0.625rem; font-weight: 600;
      padding: 0.0625rem 0.375rem; border-radius: 1rem; }
    .face-ok { background: rgba(var(--ui-success-rgb), .9); color: #fff; }
    .face-none { background: rgba(var(--ui-danger-rgb), .9); color: #fff; }
    .face-multi { background: rgba(var(--ui-warning-rgb), .9); color: #fff; }
    .flag-icon, .violation-icon { font-size: 0.75rem; background: rgba(0,0,0,0.5); color: #fff; border-radius: 50%;
      width: 22px; height: 22px; display: flex; align-items: center; justify-content: center; }
    .flag-icon { color: #ff8a80; }
    .violation-icon { color: #ffd54f; }
    .confidence-bar { position: absolute; bottom: 0; left: 0; right: 0; height: 3px; background: rgba(0,0,0,0.2); }
    .confidence-fill { height: 100%; }
    .conf-high { background: var(--ui-success, #2e7d4f); }
    .conf-med { background: var(--ui-warning, #b25e09); }
    .conf-low { background: var(--ui-danger, #c0352b); }
    .snapshot-meta { padding: 0.5rem 0.625rem; display: flex; justify-content: space-between; font-size: 0.6875rem; }
    .snapshot-time { color: var(--ui-text-muted, #6a716b); }
    .snapshot-confidence { font-weight: 500; }

    /* LIST */
    .snapshot-list { display: flex; flex-direction: column; gap: 0.5rem; }
    .list-item { display: flex; align-items: center; gap: 0.75rem; padding: 0.5rem 0.75rem; background: var(--ui-surface, #fff);
      border: 1px solid var(--ui-border, #e7e9e7); border-radius: var(--ui-radius-ctl, 8px); cursor: pointer; transition: background .15s ease; }
    .list-item:hover { background: var(--ui-surface-2, #f7f8f7); }
    .list-item.flagged-card { border-left: 3px solid var(--ui-danger, #c0352b); }
    .list-thumb { width: 64px; height: 48px; object-fit: cover; border-radius: 6px; flex-shrink: 0; }
    .list-body { flex: 1; }
    .list-time { font-size: 0.8125rem; font-weight: 500; }
    .list-tags { display: flex; gap: 0.375rem; flex-wrap: wrap; margin-top: 0.125rem; }
    .face-tag, .conf-tag, .flag-tag, .violation-tag { font-size: 0.6875rem; padding: 1px 8px; border-radius: 999px; border: 1px solid transparent; }
    .face-tag.face-ok { background: rgba(var(--ui-success-rgb), .1); border-color: rgba(var(--ui-success-rgb), .28); color: var(--ui-success-text); }
    .face-tag.face-none { background: rgba(var(--ui-danger-rgb), .1); border-color: rgba(var(--ui-danger-rgb), .28); color: var(--ui-danger-text); }
    .face-tag.face-multi { background: rgba(var(--ui-warning-rgb), .1); border-color: rgba(var(--ui-warning-rgb), .28); color: var(--ui-warning-text); }
    .conf-tag { background: rgba(var(--ui-neutral-rgb), .1); border-color: rgba(var(--ui-neutral-rgb), .28); color: var(--ui-neutral-text); }
    .flag-tag { background: rgba(var(--ui-danger-rgb), .1); border-color: rgba(var(--ui-danger-rgb), .28); color: var(--ui-danger-text); }
    .violation-tag { background: rgba(var(--ui-warning-rgb), .1); border-color: rgba(var(--ui-warning-rgb), .28); color: var(--ui-warning-text); }
    .list-ai { display: flex; gap: 0.25rem; flex-wrap: wrap; margin-top: 0.25rem; }
    .ai-tag { font-size: 0.625rem; padding: 1px 6px; border-radius: 999px; }
    .ai-tag.warn { background: rgba(var(--ui-warning-rgb), .12); color: var(--ui-warning-text, #8f4b07); }
    .ai-tag.info { background: rgba(var(--ui-info-rgb), .12); color: var(--ui-info-text, #1d518c); }
    .ai-tag.danger { background: rgba(var(--ui-danger-rgb), .12); color: var(--ui-danger-text, #a12c24); }
    .list-actions { display: flex; gap: 0.25rem; flex-shrink: 0; }

    /* Pagination */
    .pagination-bar { display: flex; justify-content: space-between; align-items: center; padding: 16px 0 0; margin-top: 16px; border-top: 1px solid var(--ui-border, #e7e9e7); }
    .pagination-controls { display: flex; align-items: center; gap: 0.5rem; }
    .page-info { font-size: 0.8125rem; color: var(--ui-text-muted, #6a716b); }


    /* LIGHTBOX (dark media viewer, intentionally kept dark) */
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
    .lightbox-image { max-width: 100%; max-height: 70vh; border-radius: var(--ui-radius-ctl, 8px); }
    .lightbox-info { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0;
      color: #fff; gap: 1rem; }
    .lightbox-info-left { display: flex; gap: 1rem; align-items: center; }
    .lightbox-info-center { display: flex; gap: 0.375rem; flex-wrap: wrap; }
    .lb-time { font-size: 0.8125rem; }
    .lb-counter { font-size: 0.75rem; color: #adb5bd; }
    .lightbox-ai { background: rgba(255,255,255,0.08); border-radius: var(--ui-radius-ctl, 8px); padding: 0.5rem 0.75rem; color: #fff; }
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
  readonly sortOpts = [
    { label: 'Newest First', value: 'newest' }, { label: 'Oldest First', value: 'oldest' },
    { label: 'Confidence: Low → High', value: 'confidence_asc' }, { label: 'Confidence: High → Low', value: 'confidence_desc' },
  ];
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

  toggleFlag(s: Snapshot): void {
    const flagged = !s.flagged;
    const reason = flagged ? (s.flag_reason || 'Flagged during review') : undefined;
    this.assessments.flagSnapshot(s.snapshot_id, flagged, reason).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        s.flagged = flagged;
        s.flag_reason = flagged ? (reason ?? '') : '';
        if (this.lightboxSnapshot?.snapshot_id === s.snapshot_id) {
          this.lightboxSnapshot.flagged = s.flagged;
          this.lightboxSnapshot.flag_reason = s.flag_reason;
        }
        this.applyFilter();
      },
      error: () => { this.notice = 'Could not update the snapshot flag.'; },
    });
  }

  // The whole sitting as a downloadable evidence bundle (session, trust,
  // violations and every snapshot's metadata).
  downloadAll(): void {
    if (!this.sessionId) return;
    this.assessments.exportSitting(this.sessionId).pipe(takeUntil(this.destroy$)).subscribe({
      next: (blob) => {
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url; a.download = `proctoring-${this.sessionId}.json`; a.click();
        URL.revokeObjectURL(url);
      },
      error: () => { this.notice = 'Could not export the sitting.'; },
    });
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
