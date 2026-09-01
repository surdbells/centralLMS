// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 14
// Learner: Certificate Viewer Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import { Router, ActivatedRoute } from '@angular/router';
import { Subject, forkJoin, of, takeUntil } from 'rxjs';
import { catchError } from 'rxjs/operators';
import { TuiIcon } from '@taiga-ui/core';
import { TopComponent } from '../../partials/top/top.component';
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
  imports: [CommonModule, FormsModule, TuiIcon, TopComponent],
  template: `
    <app-top></app-top>

    <!-- ==========================================
         VIEW MODE: Certificate List
         ========================================== -->
    <ng-container *ngIf="viewMode === 'list'">

      <!-- Header -->
      <div class="page-header mb-4">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div>
            <h5 class="mb-1">
              <tui-icon icon="@tui.award" class="me-2"></tui-icon> My Certificates
            </h5>
            <p class="text-muted mb-0">{{ certificates.length }} certificate(s) earned</p>
          </div>
          <div class="d-flex gap-2">
            <div class="input-group input-group-sm" style="max-width: 250px;">
              <span class="input-group-text"><tui-icon icon="@tui.search"></tui-icon></span>
              <input type="text" class="form-control" placeholder="Search certificates..."
                     [(ngModel)]="searchTerm" (ngModelChange)="filterCertificates()">
            </div>
            <select class="form-select form-select-sm" style="max-width: 150px;"
                    [(ngModel)]="statusFilter" (ngModelChange)="filterCertificates()">
              <option value="all">All Status</option>
              <option value="active">Active</option>
              <option value="expired">Expired</option>
              <option value="expiring_soon">Expiring Soon</option>
              <option value="revoked">Revoked</option>
            </select>
          </div>
        </div>
      </div>

      <!-- Loading -->
      <div class="text-center py-5" *ngIf="loading">
        <div class="spinner-border text-primary"></div>
        <p class="mt-2 text-muted">Loading certificates...</p>
      </div>

      <!-- Certificate Grid -->
      <div class="certificates-grid" *ngIf="!loading">
        <div class="certificate-card" *ngFor="let cert of filteredCertificates"
             [class.expired]="isExpired(cert)" [class.expiring]="isExpiringSoon(cert)"
             [class.revoked]="cert.status === 'revoked'" (click)="viewCertificate(cert.id)">

          <div class="cert-card-header">
            <div class="cert-icon"><tui-icon icon="@tui.award"></tui-icon></div>
            <span class="cert-status-badge badge" [ngClass]="getStatusBadgeClass(displayStatus(cert))">
              {{ getStatusLabel(displayStatus(cert)) }}
            </span>
          </div>

          <div class="cert-card-body">
            <h6 class="cert-title">{{ cert.title }}</h6>
            <div class="cert-number">
              <tui-icon icon="@tui.hash" class="me-1"></tui-icon>{{ cert.number }}
            </div>
            <div class="cert-meta">
              <div class="cert-meta-item">
                <span class="meta-label">Score</span>
                <span class="meta-value" [class.text-success]="(cert.percentage ?? 0) >= 70"
                      [class.text-danger]="cert.percentage !== null && cert.percentage < 50">
                  <ng-container *ngIf="cert.percentage !== null">{{ cert.percentage | number:'1.0-0' }}%<span *ngIf="cert.grade"> ({{ cert.grade }})</span></ng-container>
                  <span *ngIf="cert.percentage === null">-</span>
                </span>
              </div>
              <div class="cert-meta-item">
                <span class="meta-label">Issued</span>
                <span class="meta-value">{{ formatDate(cert.issuedAt) }}</span>
              </div>
              <div class="cert-meta-item">
                <span class="meta-label">{{ cert.validUntil ? 'Expires' : 'Validity' }}</span>
                <span class="meta-value" [class.text-danger]="isExpired(cert)" [class.text-warning]="isExpiringSoon(cert)">
                  <ng-container *ngIf="cert.validUntil">
                    {{ formatDate(cert.validUntil) }}
                    <small *ngIf="cert.daysRemaining && cert.daysRemaining > 0">({{ cert.daysRemaining }}d left)</small>
                  </ng-container>
                  <span *ngIf="!cert.validUntil" class="text-success">No Expiry</span>
                </span>
              </div>
            </div>
          </div>

          <div class="cert-card-footer">
            <button class="btn btn-sm btn-primary" (click)="viewCertificate(cert.id); $event.stopPropagation()">
              <tui-icon icon="@tui.eye" class="me-1"></tui-icon> View
            </button>
            <button class="btn btn-sm btn-secondary"
                    (click)="openForPrinting(cert.id); $event.stopPropagation()">
              <tui-icon icon="@tui.download" class="me-1"></tui-icon> Download
            </button>
          </div>
        </div>

        <!-- Empty State -->
        <div class="empty-state full-width" *ngIf="filteredCertificates.length === 0 && !loading">
          <tui-icon icon="@tui.award" class="empty-icon"></tui-icon>
          <h6 *ngIf="certificates.length === 0">No Certificates Yet</h6>
          <h6 *ngIf="certificates.length > 0">No Matching Certificates</h6>
          <p *ngIf="certificates.length === 0">Complete assessments to earn certificates!</p>
          <p *ngIf="certificates.length > 0">Try adjusting your search or filter</p>
        </div>
      </div>
    </ng-container>

    <!-- ==========================================
         VIEW MODE: Certificate Detail
         ========================================== -->
    <ng-container *ngIf="viewMode === 'detail'">
      <div class="mb-3">
        <button class="btn btn-sm btn-secondary" (click)="backToList()">
          <tui-icon icon="@tui.arrow-left" class="me-1"></tui-icon> Back to Certificates
        </button>
      </div>

      <div class="text-center py-5" *ngIf="loadingDetail">
        <div class="spinner-border text-primary"></div>
        <p class="mt-2 text-muted">Loading certificate...</p>
      </div>

      <div class="certificate-detail-wrapper" *ngIf="!loadingDetail && selectedCertificate">

        <!-- Status Alert -->
        <div class="alert d-flex align-items-center mb-3" [ngClass]="getDetailAlertClass()">
          <tui-icon [icon]="getDetailAlertIcon()" class="me-2"></tui-icon>
          <div>
            <strong>{{ getDetailAlertTitle() }}</strong>
            <p class="mb-0 small" *ngIf="isExpiringSoon(selectedCertificate)">
              Expires {{ formatDate(selectedCertificate.validUntil!) }} ({{ selectedCertificate.daysRemaining }}d left)
            </p>
          </div>
        </div>

        <!-- Certificate Preview (server-rendered, isolated) -->
        <div class="certificate-preview-card">
          <div class="text-center py-5 w-100" *ngIf="!previewDoc">
            <div class="spinner-border spinner-border-sm text-primary"></div>
            <span class="ms-2 text-muted">Rendering certificate...</span>
          </div>
          <iframe *ngIf="previewDoc" class="cert-preview-frame" [srcdoc]="previewDoc"
                  title="Certificate preview" sandbox="allow-same-origin"></iframe>
        </div>

        <!-- Action Bar -->
        <div class="cert-actions-bar mt-3">
          <div class="d-flex justify-content-center gap-2 flex-wrap">
            <button class="btn btn-primary" (click)="openForPrinting(selectedCertificate.id)">
              <tui-icon icon="@tui.download" class="me-1"></tui-icon> Download PDF
            </button>
            <button class="btn btn-secondary" (click)="shareCertificate()">
              <tui-icon icon="@tui.share-2" class="me-1"></tui-icon> Share
            </button>
            <button class="btn btn-secondary" (click)="verifyCertificate()">
              <tui-icon icon="@tui.shield" class="me-1"></tui-icon> Verify
            </button>
            <button class="btn btn-secondary" (click)="openForPrinting(selectedCertificate.id)">
              <tui-icon icon="@tui.printer" class="me-1"></tui-icon> Print
            </button>
          </div>
        </div>

        <!-- Details Table -->
        <div class="card mt-4">
          <div class="card-header">
            <h6 class="mb-0"><tui-icon icon="@tui.info" class="me-2"></tui-icon> Certificate Details</h6>
          </div>
          <div class="card-body p-0">
            <table class="table table-sm mb-0">
              <tbody>
                <tr><td class="text-muted fw-light" style="width:180px">Certificate Number</td><td><strong>{{ selectedCertificate.number }}</strong></td></tr>
                <tr><td class="text-muted fw-light">Verification Code</td><td><code class="user-select-all">{{ selectedCertificate.verificationCode }}</code>
                  <button class="btn btn-sm btn-link p-0 ms-2" (click)="copyToClipboard(selectedCertificate.verificationCode)"><tui-icon icon="@tui.copy"></tui-icon></button></td></tr>
                <tr><td class="text-muted fw-light">Assessment</td><td>{{ selectedCertificate.title }}</td></tr>
                <tr><td class="text-muted fw-light">Recipient</td><td>{{ selectedCertificate.learnerName }}</td></tr>
                <tr *ngIf="selectedCertificate.percentage !== null"><td class="text-muted fw-light">Score</td><td>{{ selectedCertificate.percentage | number:'1.0-0' }}%<span *ngIf="selectedCertificate.grade"> - {{ selectedCertificate.grade }}</span></td></tr>
                <tr><td class="text-muted fw-light">Issued</td><td>{{ formatDate(selectedCertificate.issuedAt) }}</td></tr>
                <tr><td class="text-muted fw-light">Expires</td><td><span *ngIf="selectedCertificate.validUntil">{{ formatDate(selectedCertificate.validUntil) }}</span><span *ngIf="!selectedCertificate.validUntil" class="text-success">No Expiry (Lifetime)</span></td></tr>
                <tr><td class="text-muted fw-light">Status</td><td><span class="badge" [ngClass]="getStatusBadgeClass(displayStatus(selectedCertificate))">{{ getStatusLabel(displayStatus(selectedCertificate)) }}</span></td></tr>
                <tr><td class="text-muted fw-light">Verified</td><td>{{ selectedCertificate.verificationCount }} time(s)</td></tr>
                <tr><td class="text-muted fw-light">Downloaded</td><td>{{ selectedCertificate.downloadCount }} time(s)</td></tr>
                <tr *ngIf="selectedCertificate.verificationUrl"><td class="text-muted fw-light">Verify URL</td><td><a [href]="selectedCertificate.verificationUrl" target="_blank" rel="noopener" class="text-break">{{ selectedCertificate.verificationUrl }}</a>
                  <button class="btn btn-sm btn-link p-0 ms-2" (click)="copyToClipboard(selectedCertificate.verificationUrl)"><tui-icon icon="@tui.copy"></tui-icon></button></td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Additional Information (custom data) -->
        <div class="card mt-3" *ngIf="customFields.length">
          <div class="card-header">
            <h6 class="mb-0"><tui-icon icon="@tui.list" class="me-2"></tui-icon> Additional Information</h6>
          </div>
          <div class="card-body p-0">
            <table class="table table-sm mb-0">
              <tbody>
                <tr *ngFor="let field of customFields">
                  <td class="text-muted fw-light" style="width:180px">{{ formatFieldName(field.key) }}</td>
                  <td>{{ field.value }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Not Found -->
      <div class="empty-state" *ngIf="!loadingDetail && !selectedCertificate && detailError">
        <tui-icon icon="@tui.alert-triangle" class="empty-icon text-danger"></tui-icon>
        <h6>Certificate Not Found</h6>
        <p>{{ detailError }}</p>
        <button class="btn btn-primary" (click)="backToList()">Back to Certificates</button>
      </div>
    </ng-container>

    <!-- Share Modal -->
    <div class="modal-overlay" *ngIf="showShareModal" (click)="showShareModal = false">
      <div class="modal-box" (click)="$event.stopPropagation()">
        <div class="modal-hdr"><h6><tui-icon icon="@tui.share-2" class="me-2"></tui-icon> Share Certificate</h6>
          <button class="btn-close" (click)="showShareModal = false"></button></div>
        <div class="modal-bdy">
          <div class="d-grid gap-2">
            <button class="btn btn-primary" (click)="shareVia('linkedin')"><tui-icon icon="@tui.external-link" class="me-2"></tui-icon> Share on LinkedIn</button>
            <button class="btn btn-secondary" (click)="shareVia('email')"><tui-icon icon="@tui.mail" class="me-2"></tui-icon> Share via Email</button>
            <button class="btn btn-secondary" (click)="shareVia('link')"><tui-icon icon="@tui.link" class="me-2"></tui-icon> Copy Verification Link</button>
          </div>
          <div class="alert alert-success mt-3" *ngIf="shareSuccess"><tui-icon icon="@tui.check" class="me-1"></tui-icon> {{ shareSuccess }}</div>
        </div>
      </div>
    </div>

    <!-- Verify Modal -->
    <div class="modal-overlay" *ngIf="showVerifyModal" (click)="showVerifyModal = false">
      <div class="modal-box" (click)="$event.stopPropagation()">
        <div class="modal-hdr"><h6><tui-icon icon="@tui.shield" class="me-2"></tui-icon> Verification</h6>
          <button class="btn-close" (click)="showVerifyModal = false"></button></div>
        <div class="modal-bdy">
          <div class="text-center py-3" *ngIf="verifying"><div class="spinner-border spinner-border-sm text-primary"></div><span class="ms-2">Verifying...</span></div>
          <div *ngIf="!verifying && verificationResult" class="text-center">
            <div class="mb-3" style="font-size: 3rem;">
              <tui-icon [icon]="verificationResult.isValid ? '@tui.check-circle' : '@tui.x-circle'"
                        [class.text-success]="verificationResult.isValid" [class.text-danger]="!verificationResult.isValid"></tui-icon>
            </div>
            <h6>{{ verificationResult.isValid ? 'Certificate Verified!' : 'Verification Failed' }}</h6>
            <p class="text-muted">{{ verificationResult.message }}</p>
            <div *ngIf="verificationResult.isValid" class="text-start mt-3 border-top pt-3">
              <table class="table table-sm"><tbody>
                <tr><td class="text-muted">Recipient</td><td>{{ verificationResult.learnerName }}</td></tr>
                <tr><td class="text-muted">Assessment</td><td>{{ verificationResult.title }}</td></tr>
                <tr *ngIf="verificationResult.issuedAt"><td class="text-muted">Issued</td><td>{{ formatDate(verificationResult.issuedAt) }}</td></tr>
                <tr><td class="text-muted">Status</td><td><span class="badge" [ngClass]="getStatusBadgeClass(verificationResult.status)">{{ verificationResult.statusLabel || getStatusLabel(verificationResult.status) }}</span></td></tr>
              </tbody></table>
            </div>
          </div>
        </div>
      </div>
    </div>
  `,
  styles: [`
    :host { display: block; }

    .certificates-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 1rem; }
    .full-width { grid-column: 1 / -1; }

    .certificate-card {
      background: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem;
      overflow: hidden; cursor: pointer; transition: all 0.2s;
      &:hover { border-color: #c9a227; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transform: translateY(-2px); }
      &.expired { opacity: 0.7; border-left: 4px solid #dc3545; }
      &.expiring { border-left: 4px solid #ffc107; }
      &.revoked { opacity: 0.6; border-left: 4px solid #6c757d; }
    }

    .cert-card-header {
      display: flex; justify-content: space-between; align-items: center; padding: 1rem 1rem 0;
      .cert-icon { width: 40px; height: 40px; border-radius: 50%; background: #fff8e6; color: #c9a227;
        display: flex; align-items: center; justify-content: center; }
    }

    .cert-card-body {
      padding: 1rem;
      .cert-title { font-size: 0.95rem; margin-bottom: 0.5rem; color: #212529;
        display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
      .cert-number { font-size: 0.8rem; color: #6c757d; margin-bottom: 0.75rem; font-family: monospace; }
    }

    .cert-meta .cert-meta-item {
      display: flex; justify-content: space-between; font-size: 0.8rem; padding: 0.25rem 0;
      border-bottom: 1px solid #f8f9fa; &:last-child { border-bottom: none; }
      .meta-label { color: #6c757d; } .meta-value { font-weight: 600; color: #212529; }
    }

    .cert-card-footer { display: flex; gap: 0.5rem; padding: 0.75rem 1rem; border-top: 1px solid #f8f9fa; background: #fafbfc; }

    .certificate-preview-card {
      border: 1px solid #dee2e6; border-radius: 0.5rem; overflow: hidden;
      box-shadow: 0 4px 12px rgba(0,0,0,0.1); max-width: 800px; margin: 0 auto; aspect-ratio: 1.414/1;
      display: flex; align-items: center; justify-content: center; background: #fff;
    }

    .cert-preview-frame { width: 100%; height: 100%; border: 0; display: block; }

    .cert-actions-bar { text-align: center; }

    .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5);
      display: flex; align-items: center; justify-content: center; z-index: 1050; }
    .modal-box { background: #fff; border-radius: 0.75rem; width: 90%; max-width: 500px; max-height: 80vh; overflow-y: auto; }
    .modal-hdr { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.25rem; border-bottom: 1px solid #e9ecef; h6 { margin: 0; } }
    .modal-bdy { padding: 1.25rem; }

    .empty-state { text-align: center; padding: 3rem 1rem; color: #6c757d;
      .empty-icon { font-size: 3rem; margin-bottom: 1rem; opacity: 0.3; } }

    @media (max-width: 768px) {
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
        this.detailError = 'Certificate not found';
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
        this.shareSuccess = 'Opening LinkedIn...';
        break;
      case 'email':
        const subject = encodeURIComponent(`Certificate Verification - ${this.selectedCertificate.title}`);
        const body = encodeURIComponent(
          `I'd like to share my certificate for "${this.selectedCertificate.title}".\n\n` +
          (this.selectedCertificate.percentage !== null ? `Score: ${this.selectedCertificate.percentage}%\n` : '') +
          `Certificate #: ${this.selectedCertificate.number}\n` +
          `Verify at: ${url}`
        );
        window.open(`mailto:?subject=${subject}&body=${body}`);
        this.shareSuccess = 'Opening email client...';
        break;
      case 'link':
        this.copyToClipboard(url);
        this.shareSuccess = 'Verification link copied to clipboard!';
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
            found: false, isValid: false, message: 'Network error during verification',
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
    return new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
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
      'active': 'Active', 'expired': 'Expired', 'revoked': 'Revoked',
      'superseded': 'Superseded', 'expiring_soon': 'Expiring Soon', 'pending': 'Pending'
    };
    return map[status || ''] || status || '-';
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
    if (this.isExpired(this.selectedCertificate)) return 'This certificate has expired';
    if (this.isExpiringSoon(this.selectedCertificate)) return 'This certificate is expiring soon';
    if (this.selectedCertificate.status === 'revoked') return 'This certificate has been revoked';
    return 'This certificate is valid and active';
  }
}
