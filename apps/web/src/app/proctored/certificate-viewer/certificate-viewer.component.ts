// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 14
// Learner: Certificate Viewer Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { Router, ActivatedRoute } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
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

interface Certificate {
  certificate_id: number;
  certificate_number: string;
  verification_code: string;
  template_id: number;
  template_name: string;
  assessment_id: number;
  assessment_title: string;
  attempt_id: number;
  learner_id: number;
  learner_name: string;
  learner_email: string;
  score: number;
  max_score: number;
  percentage: number;
  grade: string;
  issue_date: string;
  expiry_date: string | null;
  status: string;
  days_until_expiry: number | null;
  is_expired: boolean;
  is_expiring_soon: boolean;
  pdf_url: string | null;
  verification_url: string;
  verification_count: number;
  download_count: number;
  template: CertificateTemplate;
  fields: CertificateField[];
}

interface CertificateTemplate {
  template_id: number;
  name: string;
  orientation: string;
  background_color: string;
  background_image: string | null;
  border_enabled: boolean;
  border_style: string;
  border_color: string;
  logo_url: string | null;
  logo_position: string;
  signature_enabled: boolean;
  signature_image: string | null;
  signature_name: string;
  signature_title: string;
  qr_enabled: boolean;
  content_template: string;
}

interface CertificateField {
  field_id: number;
  field_name: string;
  field_value: string;
  field_type: string;
}

interface CertificateListItem {
  certificate_id: number;
  certificate_number: string;
  assessment_title: string;
  assessment_type: string;
  score: number;
  percentage: number;
  grade: string;
  issue_date: string;
  expiry_date: string | null;
  status: string;
  is_expired: boolean;
  is_expiring_soon: boolean;
  days_until_expiry: number | null;
  pdf_url: string | null;
}

interface VerificationResult {
  valid: boolean;
  certificate: {
    certificate_number: string;
    learner_name: string;
    assessment_title: string;
    score: number;
    percentage: number;
    grade: string;
    issue_date: string;
    expiry_date: string | null;
    status: string;
  } | null;
  message: string;
}

@Component({
  selector: 'app-certificate-viewer',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon],
  template: `
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
             [class.expired]="cert.is_expired" [class.expiring]="cert.is_expiring_soon"
             [class.revoked]="cert.status === 'revoked'" (click)="viewCertificate(cert.certificate_id)">

          <div class="cert-card-header">
            <div class="cert-icon"><tui-icon icon="@tui.award"></tui-icon></div>
            <span class="cert-status-badge badge" [ngClass]="getStatusBadgeClass(cert.status)">
              {{ getStatusLabel(cert.status) }}
            </span>
          </div>

          <div class="cert-card-body">
            <h6 class="cert-title">{{ cert.assessment_title }}</h6>
            <div class="cert-number">
              <tui-icon icon="@tui.hash" class="me-1"></tui-icon>{{ cert.certificate_number }}
            </div>
            <div class="cert-meta">
              <div class="cert-meta-item">
                <span class="meta-label">Score</span>
                <span class="meta-value" [class.text-success]="cert.percentage >= 70"
                      [class.text-danger]="cert.percentage < 50">
                  {{ cert.percentage | number:'1.0-0' }}% ({{ cert.grade }})
                </span>
              </div>
              <div class="cert-meta-item">
                <span class="meta-label">Issued</span>
                <span class="meta-value">{{ formatDate(cert.issue_date) }}</span>
              </div>
              <div class="cert-meta-item">
                <span class="meta-label">{{ cert.expiry_date ? 'Expires' : 'Validity' }}</span>
                <span class="meta-value" [class.text-danger]="cert.is_expired" [class.text-warning]="cert.is_expiring_soon">
                  <ng-container *ngIf="cert.expiry_date">
                    {{ formatDate(cert.expiry_date) }}
                    <small *ngIf="cert.days_until_expiry && cert.days_until_expiry > 0">({{ cert.days_until_expiry }}d left)</small>
                  </ng-container>
                  <span *ngIf="!cert.expiry_date" class="text-success">No Expiry</span>
                </span>
              </div>
            </div>
          </div>

          <div class="cert-card-footer">
            <button class="btn btn-sm btn-primary" (click)="viewCertificate(cert.certificate_id); $event.stopPropagation()">
              <tui-icon icon="@tui.eye" class="me-1"></tui-icon> View
            </button>
            <button class="btn btn-sm btn-secondary" *ngIf="cert.pdf_url"
                    (click)="downloadCertificateFromList(cert); $event.stopPropagation()">
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
            <p class="mb-0 small" *ngIf="selectedCertificate.is_expiring_soon">
              Expires {{ formatDate(selectedCertificate.expiry_date!) }} ({{ selectedCertificate.days_until_expiry }}d left)
            </p>
          </div>
        </div>

        <!-- Certificate Preview Card -->
        <div class="certificate-preview-card" [style.background-color]="selectedCertificate.template.background_color">
          <div class="cert-preview-border" *ngIf="selectedCertificate.template.border_enabled"
               [style.border-color]="selectedCertificate.template.border_color"
               [style.border-style]="selectedCertificate.template.border_style">

            <div class="cert-logo-area" *ngIf="selectedCertificate.template.logo_url">
              <img [src]="selectedCertificate.template.logo_url" alt="Logo" class="cert-logo-img">
            </div>

            <div class="cert-content-area" [innerHTML]="renderCertificateContent()"></div>

            <div class="cert-signature-area" *ngIf="selectedCertificate.template.signature_enabled">
              <img *ngIf="selectedCertificate.template.signature_image"
                   [src]="selectedCertificate.template.signature_image" alt="Signature" class="sig-img">
              <div class="sig-line"></div>
              <div class="sig-name">{{ selectedCertificate.template.signature_name }}</div>
              <div class="sig-title">{{ selectedCertificate.template.signature_title }}</div>
            </div>

            <div class="cert-footer-area">
              <span class="cert-num-text">Certificate No: {{ selectedCertificate.certificate_number }}</span>
            </div>
          </div>
        </div>

        <!-- Action Bar -->
        <div class="cert-actions-bar mt-3">
          <div class="d-flex justify-content-center gap-2 flex-wrap">
            <button class="btn btn-primary" *ngIf="selectedCertificate.pdf_url" (click)="downloadSelectedCertificate()">
              <tui-icon icon="@tui.download" class="me-1"></tui-icon> Download PDF
            </button>
            <button class="btn btn-secondary" (click)="shareCertificate()">
              <tui-icon icon="@tui.share-2" class="me-1"></tui-icon> Share
            </button>
            <button class="btn btn-secondary" (click)="verifyCertificate()">
              <tui-icon icon="@tui.shield" class="me-1"></tui-icon> Verify
            </button>
            <button class="btn btn-secondary" (click)="printCertificate()">
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
                <tr><td class="text-muted fw-light" style="width:180px">Certificate Number</td><td><strong>{{ selectedCertificate.certificate_number }}</strong></td></tr>
                <tr><td class="text-muted fw-light">Verification Code</td><td><code class="user-select-all">{{ selectedCertificate.verification_code }}</code>
                  <button class="btn btn-sm btn-link p-0 ms-2" (click)="copyToClipboard(selectedCertificate.verification_code)"><tui-icon icon="@tui.copy"></tui-icon></button></td></tr>
                <tr><td class="text-muted fw-light">Assessment</td><td>{{ selectedCertificate.assessment_title }}</td></tr>
                <tr><td class="text-muted fw-light">Recipient</td><td>{{ selectedCertificate.learner_name }}</td></tr>
                <tr><td class="text-muted fw-light">Score</td><td>{{ selectedCertificate.score | number:'1.0-1' }}/{{ selectedCertificate.max_score | number:'1.0-1' }} ({{ selectedCertificate.percentage | number:'1.0-0' }}%) - {{ selectedCertificate.grade }}</td></tr>
                <tr><td class="text-muted fw-light">Issued</td><td>{{ formatDate(selectedCertificate.issue_date) }}</td></tr>
                <tr><td class="text-muted fw-light">Expires</td><td><span *ngIf="selectedCertificate.expiry_date">{{ formatDate(selectedCertificate.expiry_date) }}</span><span *ngIf="!selectedCertificate.expiry_date" class="text-success">No Expiry (Lifetime)</span></td></tr>
                <tr><td class="text-muted fw-light">Status</td><td><span class="badge" [ngClass]="getStatusBadgeClass(selectedCertificate.status)">{{ getStatusLabel(selectedCertificate.status) }}</span></td></tr>
                <tr><td class="text-muted fw-light">Verified</td><td>{{ selectedCertificate.verification_count }} time(s)</td></tr>
                <tr><td class="text-muted fw-light">Downloaded</td><td>{{ selectedCertificate.download_count }} time(s)</td></tr>
                <tr><td class="text-muted fw-light">Verify URL</td><td><a [href]="selectedCertificate.verification_url" target="_blank" class="text-break">{{ selectedCertificate.verification_url }}</a>
                  <button class="btn btn-sm btn-link p-0 ms-2" (click)="copyToClipboard(selectedCertificate.verification_url)"><tui-icon icon="@tui.copy"></tui-icon></button></td></tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Dynamic Fields -->
        <div class="card mt-3" *ngIf="selectedCertificate.fields?.length">
          <div class="card-header">
            <h6 class="mb-0"><tui-icon icon="@tui.list" class="me-2"></tui-icon> Additional Information</h6>
          </div>
          <div class="card-body p-0">
            <table class="table table-sm mb-0">
              <tbody>
                <tr *ngFor="let field of selectedCertificate.fields">
                  <td class="text-muted fw-light" style="width:180px">{{ formatFieldName(field.field_name) }}</td>
                  <td>{{ field.field_value }}</td>
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
              <tui-icon [icon]="verificationResult.valid ? '@tui.check-circle' : '@tui.x-circle'"
                        [class.text-success]="verificationResult.valid" [class.text-danger]="!verificationResult.valid"></tui-icon>
            </div>
            <h6>{{ verificationResult.valid ? 'Certificate Verified!' : 'Verification Failed' }}</h6>
            <p class="text-muted">{{ verificationResult.message }}</p>
            <div *ngIf="verificationResult.valid && verificationResult.certificate" class="text-start mt-3 border-top pt-3">
              <table class="table table-sm"><tbody>
                <tr><td class="text-muted">Recipient</td><td>{{ verificationResult.certificate.learner_name }}</td></tr>
                <tr><td class="text-muted">Assessment</td><td>{{ verificationResult.certificate.assessment_title }}</td></tr>
                <tr><td class="text-muted">Score</td><td>{{ verificationResult.certificate.percentage | number:'1.0-0' }}% ({{ verificationResult.certificate.grade }})</td></tr>
                <tr><td class="text-muted">Status</td><td><span class="badge" [ngClass]="getStatusBadgeClass(verificationResult.certificate.status)">{{ getStatusLabel(verificationResult.certificate.status) }}</span></td></tr>
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
    }

    .cert-preview-border {
      height: 100%; padding: 2rem; border-width: 8px; display: flex; flex-direction: column;
      align-items: center; justify-content: center; text-align: center; position: relative;
    }

    .cert-logo-area { margin-bottom: 1.5rem; }
    .cert-logo-img { max-height: 60px; max-width: 200px; }
    .cert-content-area { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100%; }

    .cert-signature-area {
      margin-top: 1.5rem; text-align: center;
      .sig-img { max-height: 50px; margin-bottom: 0.5rem; }
      .sig-line { width: 200px; border-bottom: 1px solid #333; margin: 0 auto 0.25rem; }
      .sig-name { font-weight: 700; font-size: 0.9rem; }
      .sig-title { font-size: 0.8rem; color: #666; }
    }

    .cert-footer-area { margin-top: 1.5rem; }
    .cert-num-text { font-size: 0.75rem; color: #999; font-family: monospace; }
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
      .cert-preview-border { padding: 1rem; }
    }
  `]
})
export class CertificateViewerComponent implements OnInit, OnDestroy {
  private http = inject(HttpClient);
  private router = inject(Router);
  private route = inject(ActivatedRoute);
  private destroy$ = new Subject<void>();

  // State
  viewMode: 'list' | 'detail' = 'list';
  loading = true;
  loadingDetail = false;
  certificates: CertificateListItem[] = [];
  filteredCertificates: CertificateListItem[] = [];
  selectedCertificate: Certificate | null = null;
  detailError: string | null = null;

  // Filters
  searchTerm = '';
  statusFilter = 'all';

  // Modals
  showShareModal = false;
  shareSuccess: string | null = null;
  showVerifyModal = false;
  verifying = false;
  verificationResult: VerificationResult | null = null;

  ngOnInit(): void {
    this.route.params.pipe(takeUntil(this.destroy$)).subscribe(params => {
      const certId = +params['certificateId'] || +params['id'] || 0;
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
    const fd = new FormData();
    fd.append('action', 'get_learner_certificates');

    this.http.post<ApiResponse<any>>(
      `${GlobalComponent.baseURL}/user/certificate`, fd
    ).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        if (res.code === 1) {
          this.certificates = res.data || [];
          this.filterCertificates();
        }
        this.loading = false;
      },
      error: () => { this.loading = false; }
    });
  }

  loadCertificateDetail(certId: number): void {
    this.loadingDetail = true;
    this.detailError = null;
    const fd = new FormData();
    fd.append('action', 'get_certificate_detail');
    fd.append('certificate_id', certId.toString());

    this.http.post<ApiResponse<any>>(
      `${GlobalComponent.baseURL}/user/certificate`, fd
    ).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        if (res.code === 1 && res.data) {
          this.selectedCertificate = res.data;
        } else {
          this.detailError = res.message || 'Certificate not found';
        }
        this.loadingDetail = false;
      },
      error: () => {
        this.detailError = 'Network error loading certificate';
        this.loadingDetail = false;
      }
    });
  }

  // ==========================================
  // FILTERING
  // ==========================================

  filterCertificates(): void {
    let filtered = [...this.certificates];
    if (this.searchTerm) {
      const term = this.searchTerm.toLowerCase();
      filtered = filtered.filter(c =>
        c.assessment_title.toLowerCase().includes(term) ||
        c.certificate_number.toLowerCase().includes(term)
      );
    }
    if (this.statusFilter !== 'all') {
      if (this.statusFilter === 'expiring_soon') {
        filtered = filtered.filter(c => c.is_expiring_soon);
      } else {
        filtered = filtered.filter(c => c.status === this.statusFilter);
      }
    }
    this.filteredCertificates = filtered;
  }

  // ==========================================
  // NAVIGATION
  // ==========================================

  viewCertificate(certId: number): void {
    this.viewMode = 'detail';
    this.loadCertificateDetail(certId);
  }

  backToList(): void {
    this.viewMode = 'list';
    this.selectedCertificate = null;
    this.detailError = null;
    if (this.certificates.length === 0) this.loadCertificates();
  }

  // ==========================================
  // CERTIFICATE ACTIONS
  // ==========================================

  downloadCertificateFromList(cert: CertificateListItem): void {
    if (cert.pdf_url) {
      this.triggerDownload(cert.pdf_url, `certificate-${cert.certificate_number}.pdf`);
      this.logDownload(cert.certificate_id);
    }
  }

  downloadSelectedCertificate(): void {
    if (this.selectedCertificate?.pdf_url) {
      this.triggerDownload(this.selectedCertificate.pdf_url,
        `certificate-${this.selectedCertificate.certificate_number}.pdf`);
      this.logDownload(this.selectedCertificate.certificate_id);
    }
  }

  private triggerDownload(url: string, filename: string): void {
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    a.target = '_blank';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
  }

  private logDownload(certId: number): void {
    const fd = new FormData();
    fd.append('action', 'log_certificate_download');
    fd.append('certificate_id', certId.toString());
    this.http.post(`${GlobalComponent.baseURL}/user/certificate`, fd).subscribe();
  }

  shareCertificate(): void {
    this.showShareModal = true;
    this.shareSuccess = null;
  }

  shareVia(platform: string): void {
    if (!this.selectedCertificate) return;
    const url = this.selectedCertificate.verification_url;
    const title = `Certificate: ${this.selectedCertificate.assessment_title}`;

    switch (platform) {
      case 'linkedin':
        window.open(`https://www.linkedin.com/sharing/share-offsite/?url=${encodeURIComponent(url)}`, '_blank');
        this.shareSuccess = 'Opening LinkedIn...';
        break;
      case 'email':
        const subject = encodeURIComponent(`Certificate Verification - ${this.selectedCertificate.assessment_title}`);
        const body = encodeURIComponent(
          `I'd like to share my certificate for "${this.selectedCertificate.assessment_title}".\n\n` +
          `Score: ${this.selectedCertificate.percentage}%\n` +
          `Certificate #: ${this.selectedCertificate.certificate_number}\n` +
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

    const fd = new FormData();
    fd.append('action', 'verify_certificate');
    fd.append('verification_code', this.selectedCertificate.verification_code);

    this.http.post<ApiResponse<any>>(
      `${GlobalComponent.baseURL}/user/certificate`, fd
    ).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        if (res.code === 1) {
          this.verificationResult = res.data;
        } else {
          this.verificationResult = { valid: false, certificate: null, message: res.message || 'Verification failed' };
        }
        this.verifying = false;
      },
      error: () => {
        this.verificationResult = { valid: false, certificate: null, message: 'Network error during verification' };
        this.verifying = false;
      }
    });
  }

  printCertificate(): void {
    window.print();
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
  // RENDERING
  // ==========================================

  renderCertificateContent(): string {
    if (!this.selectedCertificate?.template?.content_template) return '';
    let content = this.selectedCertificate.template.content_template;
    const fieldMap: { [key: string]: string } = {
      '{{learner_name}}': this.selectedCertificate.learner_name,
      '{{assessment_title}}': this.selectedCertificate.assessment_title,
      '{{score}}': String(this.selectedCertificate.score),
      '{{max_score}}': String(this.selectedCertificate.max_score),
      '{{percentage}}': String(this.selectedCertificate.percentage),
      '{{grade}}': this.selectedCertificate.grade,
      '{{issue_date}}': this.formatDate(this.selectedCertificate.issue_date),
      '{{expiry_date}}': this.selectedCertificate.expiry_date ? this.formatDate(this.selectedCertificate.expiry_date) : 'No Expiry',
      '{{certificate_number}}': this.selectedCertificate.certificate_number,
      '{{verification_code}}': this.selectedCertificate.verification_code,
    };

    // Replace dynamic fields
    if (this.selectedCertificate.fields) {
      for (const field of this.selectedCertificate.fields) {
        fieldMap[`{{${field.field_name}}}`] = field.field_value;
      }
    }

    for (const [placeholder, value] of Object.entries(fieldMap)) {
      content = content.replace(new RegExp(placeholder.replace(/[{}]/g, '\\$&'), 'g'), value);
    }
    return content;
  }

  getQRCodeUrl(): string {
    if (!this.selectedCertificate) return '';
    return `https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=${encodeURIComponent(this.selectedCertificate.verification_url)}`;
  }

  // ==========================================
  // FORMATTING HELPERS
  // ==========================================

  formatDate(date: string): string {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
  }

  formatFieldName(name: string): string {
    return name.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
  }

  getStatusBadgeClass(status: string): string {
    const map: { [key: string]: string } = {
      'active': 'bg-success', 'expired': 'bg-danger', 'revoked': 'bg-secondary',
      'expiring_soon': 'bg-warning text-dark', 'pending': 'bg-info'
    };
    return map[status] || 'bg-secondary';
  }

  getStatusLabel(status: string): string {
    const map: { [key: string]: string } = {
      'active': 'Active', 'expired': 'Expired', 'revoked': 'Revoked',
      'expiring_soon': 'Expiring Soon', 'pending': 'Pending'
    };
    return map[status] || status;
  }

  getDetailAlertClass(): string {
    if (!this.selectedCertificate) return 'alert-info';
    if (this.selectedCertificate.is_expired) return 'alert-danger';
    if (this.selectedCertificate.is_expiring_soon) return 'alert-warning';
    if (this.selectedCertificate.status === 'revoked') return 'alert-secondary';
    return 'alert-success';
  }

  getDetailAlertIcon(): string {
    if (!this.selectedCertificate) return '@tui.info';
    if (this.selectedCertificate.is_expired) return '@tui.alert-triangle';
    if (this.selectedCertificate.is_expiring_soon) return '@tui.clock';
    if (this.selectedCertificate.status === 'revoked') return '@tui.x-circle';
    return '@tui.check-circle';
  }

  getDetailAlertTitle(): string {
    if (!this.selectedCertificate) return '';
    if (this.selectedCertificate.is_expired) return 'This certificate has expired';
    if (this.selectedCertificate.is_expiring_soon) return 'This certificate is expiring soon';
    if (this.selectedCertificate.status === 'revoked') return 'This certificate has been revoked';
    return 'This certificate is valid and active';
  }
}
