// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 14
// Learner: Certificate Viewer Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { FormsModule } from '@angular/forms';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import { Router, ActivatedRoute } from '@angular/router';
import { Subject, forkJoin, of, takeUntil } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { TuiIcon } from '@taiga-ui/core';
import { TopComponent } from '../../partials/top/top.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import {
  CertificatesService,
  type Certificate,
  type CertificateVerification,
} from '../../services/certificates.service';

// ==========================================
// INTERFACES
// ==========================================

/** The detail read adds the resolved verification link on top of the register row. */
type CertificateDetail = Certificate & { verificationUrl?: string };

@Component({
  selector: 'app-certificate-viewer',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, TopComponent, SkeletonComponent, TranslatePipe],
  template: `
    <app-top></app-top>

    <main class="main">
    <div class="ui-page">
    <!-- ==========================================
         VIEW MODE: Certificate List
         ========================================== -->
    <ng-container *ngIf="viewMode === 'list'">

      <!-- Header -->
      <header class="ui-page-header">
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-award"></i></div>
        <div class="ui-page-header__titles">
          <h1>{{ 'certViewer.title' | translate }}</h1>
          <p>{{ 'certViewer.earned_count' | translate: { count: certificates.length } }}</p>
        </div>
      </header>

      <section class="ui-section">
        <div class="ui-toolbar">
          <div class="ui-filters">
            <select class="form-select" [attr.aria-label]="'common.status' | translate"
                    [(ngModel)]="statusFilter" (ngModelChange)="filterCertificates()">
              <option value="all">{{ 'certViewer.all_statuses' | translate }}</option>
              <option value="active">{{ 'common.active' | translate }}</option>
              <option value="expired">{{ 'certViewer.status_expired' | translate }}</option>
              <option value="expiring_soon">{{ 'certViewer.status_expiring_soon' | translate }}</option>
              <option value="revoked">{{ 'certViewer.status_revoked' | translate }}</option>
            </select>
          </div>
          <label class="ui-search">
            <i class="bi bi-search"></i>
            <input type="search" [placeholder]="'certViewer.search_placeholder' | translate" [attr.aria-label]="'certViewer.search_placeholder' | translate"
                   [(ngModel)]="searchTerm" (ngModelChange)="filterCertificates()">
          </label>
        </div>

        <!-- Loading -->
        <app-skeleton *ngIf="loading" variant="cards" [count]="6"></app-skeleton>

        <!-- Certificate Grid -->
        <div class="ui-grid certificates-grid" *ngIf="!loading">
          <article class="ui-card certificate-card" *ngFor="let cert of filteredCertificates"
               [class.expired]="isExpired(cert)" [class.expiring]="isExpiringSoon(cert)"
               [class.revoked]="cert.status === 'revoked'" (click)="viewCertificate(cert.id)">

            <div class="cert-card-header">
              <div class="cert-icon"><i class="bi bi-award"></i></div>
              <span class="cert-status-badge badge" [ngClass]="getStatusBadgeClass(displayStatus(cert))">
                {{ getStatusLabel(displayStatus(cert)) }}
              </span>
            </div>

            <div class="ui-card__body cert-card-body">
              <h6 class="ui-card__title cert-title">{{ cert.title }}</h6>
              <div class="cert-number">
                <i class="bi bi-hash"></i>{{ cert.number }}
              </div>
              <div class="cert-meta">
                <div class="cert-meta-item">
                  <span class="meta-label">{{ 'certificates.score' | translate }}</span>
                  <span class="meta-value" [class.text-success]="(cert.percentage ?? 0) >= 70"
                        [class.text-danger]="cert.percentage !== null && cert.percentage < 50">
                    <ng-container *ngIf="cert.percentage !== null">{{ cert.percentage | number:'1.0-0' }}%<span *ngIf="cert.grade"> ({{ cert.grade }})</span></ng-container>
                    <span *ngIf="cert.percentage === null">-</span>
                  </span>
                </div>
                <div class="cert-meta-item">
                  <span class="meta-label">{{ 'certificates.issued' | translate }}</span>
                  <span class="meta-value">{{ formatDate(cert.issuedAt) }}</span>
                </div>
                <div class="cert-meta-item">
                  <span class="meta-label">{{ (cert.validUntil ? 'certViewer.expires' : 'certViewer.validity') | translate }}</span>
                  <span class="meta-value" [class.text-danger]="isExpired(cert)" [class.text-warning]="isExpiringSoon(cert)">
                    <ng-container *ngIf="cert.validUntil">
                      {{ formatDate(cert.validUntil) }}
                      <small *ngIf="cert.daysRemaining && cert.daysRemaining > 0">({{ 'certViewer.days_left' | translate: { days: cert.daysRemaining } }})</small>
                    </ng-container>
                    <span *ngIf="!cert.validUntil" class="text-success">{{ 'certViewer.no_expiry' | translate }}</span>
                  </span>
                </div>
              </div>
            </div>

            <div class="ui-card__footer cert-card-footer">
              <button type="button" class="btn btn-sm btn-primary" (click)="viewCertificate(cert.id); $event.stopPropagation()">
                <i class="bi bi-eye me-1"></i> {{ 'common.view' | translate }}
              </button>
              <button type="button" class="btn btn-sm btn-outline-secondary"
                      (click)="openForPrinting(cert.id); $event.stopPropagation()">
                <i class="bi bi-download me-1"></i> {{ 'common.download' | translate }}
              </button>
            </div>
          </article>

          <!-- Empty State -->
          <div class="ui-state full-width" *ngIf="filteredCertificates.length === 0 && !loading">
            <span class="ui-state__icon"><i class="bi bi-award"></i></span>
            <h3 *ngIf="certificates.length === 0">{{ 'certViewer.empty_title' | translate }}</h3>
            <h3 *ngIf="certificates.length > 0">{{ 'certViewer.no_match_title' | translate }}</h3>
            <p *ngIf="certificates.length === 0">{{ 'certViewer.empty_text' | translate }}</p>
            <p *ngIf="certificates.length > 0">{{ 'certViewer.no_match_text' | translate }}</p>
          </div>
        </div>
      </section>
    </ng-container>

    <!-- ==========================================
         VIEW MODE: Certificate Detail
         ========================================== -->
    <ng-container *ngIf="viewMode === 'detail'">
      <header class="ui-page-header">
        <button type="button" class="ui-page-header__back" (click)="backToList()" [attr.aria-label]="'certViewer.back_to_list' | translate" [title]="'certViewer.back_to_list' | translate">
          <i class="bi bi-chevron-left"></i>
        </button>
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-award"></i></div>
        <div class="ui-page-header__titles">
          <h1>{{ selectedCertificate?.title || ('learning.certificate' | translate) }}</h1>
          <p *ngIf="selectedCertificate">#{{ selectedCertificate.number }}</p>
        </div>
        <div class="ui-page-header__actions" *ngIf="!loadingDetail && selectedCertificate">
          <button type="button" class="btn btn-primary" (click)="openForPrinting(selectedCertificate.id)">
            <i class="bi bi-download me-1"></i> {{ 'certViewer.download_pdf' | translate }}
          </button>
          <button type="button" class="btn btn-outline-secondary" (click)="shareCertificate()">
            <i class="bi bi-share me-1"></i> {{ 'certViewer.share' | translate }}
          </button>
          <button type="button" class="btn btn-outline-secondary" (click)="verifyCertificate()">
            <i class="bi bi-shield-check me-1"></i> {{ 'certViewer.verify' | translate }}
          </button>
          <button type="button" class="btn btn-outline-secondary" (click)="openForPrinting(selectedCertificate.id)">
            <i class="bi bi-printer me-1"></i> {{ 'certViewer.print' | translate }}
          </button>
        </div>
      </header>

      <app-skeleton *ngIf="loadingDetail" variant="detail" height="420px"></app-skeleton>

      <div class="certificate-detail-wrapper" *ngIf="!loadingDetail && selectedCertificate">

        <!-- Status Alert -->
        <div class="alert d-flex align-items-center mb-3" [ngClass]="getDetailAlertClass()">
          <tui-icon [icon]="getDetailAlertIcon()" class="me-2"></tui-icon>
          <div>
            <strong>{{ getDetailAlertTitle() }}</strong>
            <p class="mb-0 small" *ngIf="isExpiringSoon(selectedCertificate)">
              {{ 'certViewer.expires_on' | translate: { date: formatDate(selectedCertificate.validUntil!), days: selectedCertificate.daysRemaining } }}
            </p>
          </div>
        </div>

        <!-- Certificate Preview (server-rendered, isolated) -->
        <section class="ui-section preview-section">
          <div class="certificate-preview-card">
            <div class="cert-preview-skeleton" *ngIf="!previewDoc">
              <app-skeleton variant="block" height="100%"></app-skeleton>
            </div>
            <iframe *ngIf="previewDoc" class="cert-preview-frame" [srcdoc]="previewDoc"
                    [title]="'certViewer.preview' | translate" sandbox="allow-same-origin"></iframe>
          </div>
        </section>

        <div class="ui-row">
        <!-- Details Table -->
        <section class="ui-section ui-section--flush">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title">{{ 'certViewer.details' | translate }}</h2></div>
          </div>
          <div class="ui-table-wrap">
            <table class="table table-sm details-table mb-0">
              <tbody>
                <tr><td class="ui-muted" style="width:180px">{{ 'certViewer.cert_number' | translate }}</td><td><strong>{{ selectedCertificate.number }}</strong></td></tr>
                <tr><td class="ui-muted">{{ 'certViewer.verification_code' | translate }}</td><td><code class="user-select-all">{{ selectedCertificate.verificationCode }}</code>
                  <button type="button" class="btn btn-sm btn-link p-0 ms-2" (click)="copyToClipboard(selectedCertificate.verificationCode)" [attr.aria-label]="'certViewer.copy' | translate"><i class="bi bi-copy"></i></button></td></tr>
                <tr><td class="ui-muted">{{ 'certViewer.awarded_for' | translate }}</td><td>{{ selectedCertificate.title }}</td></tr>
                <tr><td class="ui-muted">{{ 'certViewer.recipient' | translate }}</td><td>{{ selectedCertificate.learnerName }}</td></tr>
                <tr *ngIf="selectedCertificate.percentage !== null"><td class="ui-muted">{{ 'certificates.score' | translate }}</td><td>{{ selectedCertificate.percentage | number:'1.0-0' }}%<span *ngIf="selectedCertificate.grade"> - {{ selectedCertificate.grade }}</span></td></tr>
                <tr><td class="ui-muted">{{ 'certificates.issued' | translate }}</td><td>{{ formatDate(selectedCertificate.issuedAt) }}</td></tr>
                <tr><td class="ui-muted">{{ 'certViewer.expires' | translate }}</td><td><span *ngIf="selectedCertificate.validUntil">{{ formatDate(selectedCertificate.validUntil) }}</span><span *ngIf="!selectedCertificate.validUntil" class="text-success">{{ 'certViewer.no_expiry_lifetime' | translate }}</span></td></tr>
                <tr><td class="ui-muted">{{ 'common.status' | translate }}</td><td><span class="badge" [ngClass]="getStatusBadgeClass(displayStatus(selectedCertificate))">{{ getStatusLabel(displayStatus(selectedCertificate)) }}</span></td></tr>
                <tr><td class="ui-muted">{{ 'certViewer.verified' | translate }}</td><td>{{ 'certViewer.times' | translate: { count: selectedCertificate.verificationCount } }}</td></tr>
                <tr><td class="ui-muted">{{ 'certViewer.downloaded' | translate }}</td><td>{{ 'certViewer.times' | translate: { count: selectedCertificate.downloadCount } }}</td></tr>
                <tr *ngIf="selectedCertificate.verificationUrl"><td class="ui-muted">{{ 'certViewer.verify_url' | translate }}</td><td><a [href]="selectedCertificate.verificationUrl" target="_blank" rel="noopener" class="text-break">{{ selectedCertificate.verificationUrl }}</a>
                  <button type="button" class="btn btn-sm btn-link p-0 ms-2" (click)="copyToClipboard(selectedCertificate.verificationUrl)" [attr.aria-label]="'certViewer.copy' | translate"><i class="bi bi-copy"></i></button></td></tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Additional Information (custom data) -->
        <section class="ui-section ui-section--flush" *ngIf="customFields.length">
          <div class="ui-section__head">
            <div><h2 class="ui-section__title">{{ 'certViewer.additional_info' | translate }}</h2></div>
          </div>
          <div class="ui-table-wrap">
            <table class="table table-sm details-table mb-0">
              <tbody>
                <tr *ngFor="let field of customFields">
                  <td class="ui-muted" style="width:180px">{{ formatFieldName(field.key) }}</td>
                  <td>{{ field.value }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
        </div>
      </div>

      <!-- Not Found -->
      <div class="ui-state" *ngIf="!loadingDetail && !selectedCertificate && detailError">
        <span class="ui-state__icon"><i class="bi bi-exclamation-triangle"></i></span>
        <h3>{{ 'certViewer.not_found_title' | translate }}</h3>
        <p>{{ detailError }}</p>
        <button type="button" class="btn btn-primary" (click)="backToList()">{{ 'certViewer.back_to_list' | translate }}</button>
      </div>
    </ng-container>
    </div>
    </main>

    <!-- Share Modal -->
    <div class="modal-overlay" *ngIf="showShareModal" (click)="showShareModal = false">
      <div class="modal-box" (click)="$event.stopPropagation()">
        <div class="modal-hdr"><h6><i class="bi bi-share me-2"></i> {{ 'certViewer.share_title' | translate }}</h6>
          <button type="button" class="btn-close" (click)="showShareModal = false" [attr.aria-label]="'common.close' | translate"></button></div>
        <div class="modal-bdy">
          <div class="d-grid gap-2">
            <button type="button" class="btn btn-primary" (click)="shareVia('linkedin')"><i class="bi bi-box-arrow-up-right me-2"></i> {{ 'certViewer.share_linkedin' | translate }}</button>
            <button type="button" class="btn btn-outline-secondary" (click)="shareVia('email')"><i class="bi bi-envelope me-2"></i> {{ 'certViewer.share_email' | translate }}</button>
            <button type="button" class="btn btn-outline-secondary" (click)="shareVia('link')"><i class="bi bi-link-45deg me-2"></i> {{ 'certViewer.copy_link' | translate }}</button>
          </div>
          <div class="alert alert-success mt-3" *ngIf="shareSuccess"><i class="bi bi-check2 me-1"></i> {{ shareSuccess }}</div>
        </div>
      </div>
    </div>

    <!-- Verify Modal -->
    <div class="modal-overlay" *ngIf="showVerifyModal" (click)="showVerifyModal = false">
      <div class="modal-box" (click)="$event.stopPropagation()">
        <div class="modal-hdr"><h6><i class="bi bi-shield-check me-2"></i> {{ 'certViewer.verification' | translate }}</h6>
          <button type="button" class="btn-close" (click)="showVerifyModal = false" [attr.aria-label]="'common.close' | translate"></button></div>
        <div class="modal-bdy">
          <div class="text-center py-3" *ngIf="verifying"><div class="spinner-border spinner-border-sm text-secondary"></div><span class="ms-2">{{ 'certViewer.verifying' | translate }}</span></div>
          <div *ngIf="!verifying && verificationResult" class="text-center">
            <div class="mb-3" style="font-size: 3rem;">
              <tui-icon [icon]="verificationResult.isValid ? '@tui.check-circle' : '@tui.x-circle'"
                        [class.text-success]="verificationResult.isValid" [class.text-danger]="!verificationResult.isValid"></tui-icon>
            </div>
            <h6>{{ (verificationResult.isValid ? 'certViewer.verified_ok' : 'certViewer.verify_failed') | translate }}</h6>
            <p class="ui-muted">{{ verificationResult.message }}</p>
            <div *ngIf="verificationResult.isValid" class="text-start mt-3 border-top pt-3">
              <table class="table table-sm"><tbody>
                <tr><td class="ui-muted">{{ 'certViewer.recipient' | translate }}</td><td>{{ verificationResult.learnerName }}</td></tr>
                <tr><td class="ui-muted">{{ 'certViewer.awarded_for' | translate }}</td><td>{{ verificationResult.title }}</td></tr>
                <tr *ngIf="verificationResult.issuedAt"><td class="ui-muted">{{ 'certificates.issued' | translate }}</td><td>{{ formatDate(verificationResult.issuedAt) }}</td></tr>
                <tr><td class="ui-muted">{{ 'common.status' | translate }}</td><td><span class="badge" [ngClass]="getStatusBadgeClass(verificationResult.status)">{{ verificationResult.statusLabel || getStatusLabel(verificationResult.status) }}</span></td></tr>
              </tbody></table>
            </div>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    :host { display: block; }

    .certificates-grid { grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
    .full-width { grid-column: 1 / -1; }

    .certificate-card {
      cursor: pointer;
      &.expired { opacity: 0.75; border-left: 3px solid var(--ui-danger); }
      &.expiring { border-left: 3px solid var(--ui-warning); }
      &.revoked { opacity: 0.6; border-left: 3px solid var(--ui-text-subtle); }
    }

    .cert-card-header {
      display: flex; justify-content: space-between; align-items: center; padding: 16px 16px 0;
      .cert-icon { width: 40px; height: 40px; border-radius: var(--ui-radius-card); background: rgba(var(--brand-primary-rgb), .1); color: var(--brand-primary);
        display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
    }

    .cert-card-body {
      gap: 8px;
      .cert-title { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
      .cert-number { font-size: .75rem; color: var(--ui-text-muted); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    }

    .cert-meta .cert-meta-item {
      display: flex; justify-content: space-between; font-size: .8125rem; padding: 6px 0;
      border-bottom: 1px solid var(--ui-border); &:last-child { border-bottom: none; }
      .meta-label { color: var(--ui-text-muted); } .meta-value { font-weight: 600; color: var(--ui-text); }
    }

    .cert-card-footer { justify-content: flex-start; }

    .preview-section { padding: 16px; background: var(--ui-surface-2); }
    .certificate-preview-card {
      border: 1px solid var(--ui-border); border-radius: var(--ui-radius-card); overflow: hidden;
      box-shadow: 0 6px 20px rgba(16, 24, 18, .06); max-width: 800px; margin: 0 auto; aspect-ratio: 1.414/1;
      display: flex; align-items: center; justify-content: center; background: #fff;
    }

    .cert-preview-frame { width: 100%; height: 100%; border: 0; display: block; }
    .cert-preview-skeleton { width: 100%; height: 100%; }
    .cert-preview-skeleton app-skeleton { height: 100%; }

    .details-table { font-size: .8125rem; }
    .details-table td { vertical-align: middle; }
    .ui-section--flush > .ui-table-wrap { margin-top: 0; }

    .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(16, 24, 18, .4);
      display: flex; align-items: center; justify-content: center; z-index: 1050; }
    .modal-box { background: var(--ui-surface); border-radius: var(--ui-radius-panel); width: 90%; max-width: 500px; max-height: 80vh; overflow-y: auto; box-shadow: var(--ui-shadow-pop); }
    .modal-hdr { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid var(--ui-border); h6 { margin: 0; font-weight: 650; } }
    .modal-bdy { padding: 20px; }

    @media (max-width: 767.98px) {
      .certificates-grid { grid-template-columns: 1fr; }
      .certificate-preview-card { max-width: 100%; }
    }
  `]
})
export class CertificateViewerComponent implements OnInit, OnDestroy {
  private certs = inject(CertificatesService);
  private router = inject(Router);
  private route = inject(ActivatedRoute);
  private sanitizer = inject(DomSanitizer);
  private i18n = inject(I18nService);
  private destroy$ = new Subject<void>();

  // State
  viewMode: 'list' | 'detail' = 'list';
  loading = true;
  loadingDetail = false;
  certificates: Certificate[] = [];
  filteredCertificates: Certificate[] = [];
  selectedCertificate: CertificateDetail | null = null;
  previewDoc: SafeHtml | null = null;
  customFields: Array<{ key: string; value: string }> = [];
  detailError: string | null = null;

  // Filters
  searchTerm = '';
  statusFilter = 'all';

  // Modals
  showShareModal = false;
  shareSuccess: string | null = null;
  showVerifyModal = false;
  verifying = false;
  verificationResult: CertificateVerification | null = null;

  ngOnInit(): void {
    this.route.params.pipe(takeUntil(this.destroy$)).subscribe(params => {
      // Ids are UUID strings now, so read them as-is rather than coercing to
      // a number (which would turn a UUID into NaN and load nothing).
      const certId = (params['certificateId'] || params['id'] || '').toString();
      if (certId) {
        this.viewMode = 'detail';
        this.loadCertificateDetail(certId);
      } else {
        this.loadCertificates();
      }
    });
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // DATA LOADING
  // ==========================================

  loadCertificates(): void {
    this.loading = true;
    this.certs.mine().pipe(takeUntil(this.destroy$)).subscribe({
      next: (list) => {
        this.certificates = list || [];
        this.filterCertificates();
        this.loading = false;
      },
      error: () => { this.loading = false; }
    });
  }

  loadCertificateDetail(certId: string): void {
    this.loadingDetail = true;
    this.detailError = null;
    this.previewDoc = null;
    this.customFields = [];

    forkJoin({
      cert: this.certs.show(certId),
      rendered: this.certs.render(certId).pipe(catchError(() => of(null))),
    }).pipe(takeUntil(this.destroy$)).subscribe({
      next: ({ cert, rendered }) => {
        this.selectedCertificate = cert as CertificateDetail;
        this.customFields = this.toCustomFields(cert.customData);
        if (rendered) {
          const doc = `<!doctype html><html><head><meta charset="utf-8">`
            + `<style>html,body{margin:0;padding:0}${rendered.styles}</style></head>`
            + `<body>${rendered.html}</body></html>`;
          this.previewDoc = this.sanitizer.bypassSecurityTrustHtml(doc);
        }
        this.loadingDetail = false;
      },
      error: () => {
        this.detailError = this.i18n.t('certViewer.not_found');
        this.loadingDetail = false;
      }
    });
  }

  /** Flattens the free-form customData bag into printable name/value rows. */
  private toCustomFields(data: Record<string, unknown>): Array<{ key: string; value: string }> {
    if (!data) return [];
    return Object.entries(data)
      .filter(([, value]) => value !== null && value !== undefined && typeof value !== 'object')
      .map(([key, value]) => ({ key, value: String(value) }));
  }

  // ==========================================
  // FILTERING
  // ==========================================

  filterCertificates(): void {
    let filtered = [...this.certificates];
    if (this.searchTerm) {
      const term = this.searchTerm.toLowerCase();
      filtered = filtered.filter(c =>
        (c.title || '').toLowerCase().includes(term) ||
        (c.number || '').toLowerCase().includes(term)
      );
    }
    if (this.statusFilter !== 'all') {
      if (this.statusFilter === 'expiring_soon') {
        filtered = filtered.filter(c => this.isExpiringSoon(c));
      } else {
        filtered = filtered.filter(c => c.status === this.statusFilter);
      }
    }
    this.filteredCertificates = filtered;
  }

  // ==========================================
  // NAVIGATION
  // ==========================================

  viewCertificate(certId: string): void {
    this.viewMode = 'detail';
    this.loadCertificateDetail(certId);
  }

  backToList(): void {
    this.viewMode = 'list';
    this.selectedCertificate = null;
    this.previewDoc = null;
    this.detailError = null;
    if (this.certificates.length === 0) this.loadCertificates();
  }

  // ==========================================
  // CERTIFICATE ACTIONS
  // ==========================================

  /**
   * Opens the certificate as a printable page (the print dialogue is also
   * where "Save as PDF" lives). Opening it records the download server-side,
   * so there is no separate log call.
   */
  openForPrinting(certId: string): void {
    this.certs.openForPrinting(certId).pipe(takeUntil(this.destroy$)).subscribe();
  }

  shareCertificate(): void {
    this.showShareModal = true;
    this.shareSuccess = null;
  }

  shareVia(platform: string): void {
    if (!this.selectedCertificate) return;
    const url = this.selectedCertificate.verificationUrl
      || `${window.location.origin}/verify/${this.selectedCertificate.verificationCode}`;
    switch (platform) {
      case 'linkedin':
        window.open(`https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(url)}`, '_blank');
        this.shareSuccess = this.i18n.t('certViewer.opening_linkedin');
        break;
      case 'email': {
        const cert = this.selectedCertificate;
        const subject = encodeURIComponent(this.i18n.t('certViewer.email_subject', { title: cert.title }));
        const body = encodeURIComponent(
          this.i18n.t('certViewer.email_intro', { title: cert.title }) + '\n\n' +
          (cert.percentage !== null ? this.i18n.t('certViewer.email_score', { score: cert.percentage }) + '\n' : '') +
          this.i18n.t('certViewer.email_number', { number: cert.number }) + '\n' +
          this.i18n.t('certViewer.email_verify', { url })
        );
        window.open(`mailto:?subject=${subject}&body=${body}`);
        this.shareSuccess = this.i18n.t('certViewer.opening_email');
        break;
      }
      case 'link':
        this.copyToClipboard(url);
        this.shareSuccess = this.i18n.t('certViewer.link_copied');
        break;
    }
  }

  verifyCertificate(): void {
    if (!this.selectedCertificate) return;
    this.showVerifyModal = true;
    this.verifying = true;
    this.verificationResult = null;

    this.certs.verifyByCode(this.selectedCertificate.verificationCode)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (result) => {
          this.verificationResult = result;
          this.verifying = false;
        },
        error: () => {
          this.verificationResult = {
            found: false, isValid: false, message: this.i18n.t('certViewer.network_error'),
            number: null, learnerName: null, title: null, source: null,
            status: null, statusLabel: null, issuedAt: null, validUntil: null,
          };
          this.verifying = false;
        }
      });
  }

  copyToClipboard(text: string): void {
    navigator.clipboard.writeText(text).catch(() => {
      const textarea = document.createElement('textarea');
      textarea.value = text;
      document.body.appendChild(textarea);
      textarea.select();
      document.execCommand('copy');
      document.body.removeChild(textarea);
    });
  }

  // ==========================================
  // STATUS DERIVATION
  // ==========================================

  /** A live certificate inside its final month reads as "expiring soon". */
  isExpiringSoon(cert: Certificate): boolean {
    return cert.status === 'active' && cert.daysRemaining !== null
      && cert.daysRemaining > 0 && cert.daysRemaining <= 30;
  }

  isExpired(cert: Certificate): boolean {
    return cert.status === 'expired';
  }

  /** The badge shows "Expiring Soon" ahead of a still-active certificate's real status. */
  displayStatus(cert: Certificate | null): string {
    if (!cert) return '';
    return this.isExpiringSoon(cert) ? 'expiring_soon' : cert.status;
  }

  // ==========================================
  // FORMATTING HELPERS
  // ==========================================

  formatDate(date: string | null): string {
    if (!date) return '-';
    return new Date(date).toLocaleDateString(this.i18n.lang, { year: 'numeric', month: 'short', day: 'numeric' });
  }

  formatFieldName(name: string): string {
    return name.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  getStatusBadgeClass(status: string | null): string {
    const map: { [key: string]: string } = {
      'active': 'bg-success', 'expired': 'bg-danger', 'revoked': 'bg-secondary',
      'superseded': 'bg-secondary', 'expiring_soon': 'bg-warning text-dark', 'pending': 'bg-info'
    };
    return map[status || ''] || 'bg-secondary';
  }

  getStatusLabel(status: string | null): string {
    const map: { [key: string]: string } = {
      'active': 'common.active', 'expired': 'certViewer.status_expired', 'revoked': 'certViewer.status_revoked',
      'superseded': 'certViewer.status_superseded', 'expiring_soon': 'certViewer.status_expiring_soon', 'pending': 'common.pending'
    };
    const key = map[status || ''];
    return key ? this.i18n.t(key) : (status || '-');
  }

  getDetailAlertClass(): string {
    if (!this.selectedCertificate) return 'alert-info';
    if (this.isExpired(this.selectedCertificate)) return 'alert-danger';
    if (this.isExpiringSoon(this.selectedCertificate)) return 'alert-warning';
    if (this.selectedCertificate.status === 'revoked') return 'alert-secondary';
    return 'alert-success';
  }

  getDetailAlertIcon(): string {
    if (!this.selectedCertificate) return '@tui.info';
    if (this.isExpired(this.selectedCertificate)) return '@tui.alert-triangle';
    if (this.isExpiringSoon(this.selectedCertificate)) return '@tui.clock';
    if (this.selectedCertificate.status === 'revoked') return '@tui.x-circle';
    return '@tui.check-circle';
  }

  getDetailAlertTitle(): string {
    if (!this.selectedCertificate) return '';
    if (this.isExpired(this.selectedCertificate)) return this.i18n.t('certViewer.alert_expired');
    if (this.isExpiringSoon(this.selectedCertificate)) return this.i18n.t('certViewer.alert_expiring');
    if (this.selectedCertificate.status === 'revoked') return this.i18n.t('certViewer.alert_revoked');
    return this.i18n.t('certViewer.alert_valid');
  }
}
