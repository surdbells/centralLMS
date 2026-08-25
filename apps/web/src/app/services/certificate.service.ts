import { decodeSessionBlob } from '../core/utils/session-blob';
// certificate.service.ts - Angular Service for Certificates
// Multi-Tenant LMS - Proctored Assessment Module

import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, map } from 'rxjs';
import {GlobalComponent} from '../global-component';

// INTERFACES
export type TemplateType = 'completion' | 'achievement' | 'participation' | 'custom';
export type Orientation = 'landscape' | 'portrait';
export type PaperSize = 'a4' | 'letter' | 'custom';
export type ValidityType = 'lifetime' | 'fixed_period' | 'fixed_date';
export type CertificateStatus = 'active' | 'revoked' | 'expired' | 'superseded';
export type FieldType = 'text' | 'date' | 'number' | 'image' | 'qr_code';
export type DataSource = 'static' | 'learner' | 'assessment' | 'attempt' | 'certificate' | 'custom';

export interface CertificateTemplate {
  template_id: number;
  name: string;
  description: string | null;
  template_type: TemplateType;
  orientation: Orientation;
  paper_size: PaperSize;
  width_mm: number;
  height_mm: number;
  background_type: 'color' | 'image' | 'none';
  background_color: string;
  background_image_path: string | null;
  border_enabled: boolean;
  border_style: 'solid' | 'double' | 'ornate';
  border_color: string;
  border_width: number;
  border_margin: number;
  content_template: string;
  css_styles: string | null;
  logo_enabled: boolean;
  logo_path: string | null;
  logo_position: 'top-left' | 'top-center' | 'top-right';
  logo_width: number;
  signature_enabled: boolean;
  signature_image_path: string | null;
  signature_name: string | null;
  signature_title: string | null;
  qr_enabled: boolean;
  qr_position: 'bottom-left' | 'bottom-right' | 'bottom-center';
  qr_size: number;
  validity_type: ValidityType;
  validity_period_months: number | null;
  validity_end_date: string | null;
  status: 'draft' | 'active' | 'archived';
  is_default: boolean;
  usage_count: number;
  fields?: TemplateField[];
}

export interface TemplateField {
  field_id: number;
  template_id: number;
  field_name: string;
  field_label: string;
  field_type: FieldType;
  data_source: DataSource;
  data_field: string | null;
  static_value: string | null;
  format_pattern: string | null;
  position_x: number;
  position_y: number;
  width: number | null;
  height: number | null;
  alignment: 'left' | 'center' | 'right';
  font_family: string;
  font_size: number;
  font_weight: 'normal' | 'bold';
  font_style: 'normal' | 'italic';
  font_color: string;
  text_transform: 'none' | 'uppercase' | 'lowercase' | 'capitalize';
  sort_order: number;
  is_required: boolean;
}

export interface AssessmentCertificateSettings {
  setting_id: number;
  assessment_id: number;
  template_id: number;
  template_name: string;
  auto_issue: boolean;
  min_score: number | null;
  require_pass: boolean;
  custom_title: string | null;
  custom_subtitle: string | null;
  custom_description: string | null;
  notify_learner: boolean;
}

export interface Certificate {
  certificate_id: number;
  certificate_number: string;
  verification_code: string;
  verification_hash: string;
  template_id: number;
  template_name: string;
  assessment_id: number;
  attempt_id: number;
  user_id: number;
  learner_name: string;
  learner_email: string;
  assessment_title: string;
  score: number;
  percentage: number;
  grade: string;
  custom_data: any;
  pdf_path: string | null;
  issued_at: string;
  valid_from: string;
  valid_until: string | null;
  expired: boolean;
  status: CertificateStatus;
  revoked_at: string | null;
  revocation_reason: string | null;
  download_count: number;
  verification_count: number;
  verification_url: string;
}

export interface VerificationResult {
  is_valid: boolean;
  message: string;
  certificate: {
    certificate_number: string;
    learner_name: string;
    assessment_title: string;
    percentage: number;
    grade: string;
    issued_at: string;
    valid_until: string | null;
    status: CertificateStatus;
  } | null;
}

export interface CertificateStats {
  total: number;
  active: number;
  revoked: number;
  expired: number;
  total_downloads: number;
  total_verifications: number;
}

export interface ApiResponse<T> { response_code: number; status: 'success' | 'error'; data: T; message: string; }

@Injectable({ providedIn: 'root' })
export class CertificateService {
  private apiUrl = GlobalComponent.certificate;

  constructor(private http: HttpClient) {}
  session_data: any = ""
  user_session = {
    id: 0,
    tenant_id: 0,
    tenant: {
      id: 0,
      name: "",
      status: "",
    },
    token: "",
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    avatar: "",
    is_2fa: false,
    is_active: false,
    account_locked: false,
    is_admin: false,
    is_content: false,
    is_instructor: false,
    is_learner: false,
  };
  /**
   * Get auth payload for API requests
   */
  private getAuthPayload(): { token: string; user_id: number; tenant_id: number } {
    this.session_data = sessionStorage.getItem("SESSION");
    this.user_session = decodeSessionBlob(this.session_data) ?? this.user_session;
    return {
      token: this.user_session.token,
      user_id: this.user_session.id,
      tenant_id: this.user_session.tenant_id,
    };
  }

  // TEMPLATES
  createTemplate(data: Partial<CertificateTemplate>): Observable<{ template_id: number }> {
    return this.http.post<ApiResponse<{ template_id: number }>>(this.apiUrl, { ...this.getAuthPayload(), action: 'create-template', ...data }).pipe(map(res => res.data));
  }

  updateTemplate(template_id: number, data: Partial<CertificateTemplate>): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, { ...this.getAuthPayload(), action: 'update-template', template_id, ...data }).pipe(map(() => void 0));
  }

  deleteTemplate(template_id: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, { ...this.getAuthPayload(), action: 'delete-template', template_id }).pipe(map(() => void 0));
  }

  getTemplate(template_id: number): Observable<CertificateTemplate> {
    return this.http.post<ApiResponse<CertificateTemplate>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-template', template_id }).pipe(map(res => res.data));
  }

  getTemplates(filters: { status?: string; type?: TemplateType } = {}): Observable<CertificateTemplate[]> {
    return this.http.post<ApiResponse<CertificateTemplate[]>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-templates', ...filters }).pipe(map(res => res.data));
  }

  cloneTemplate(template_id: number): Observable<{ template_id: number }> {
    return this.http.post<ApiResponse<{ template_id: number }>>(this.apiUrl, { ...this.getAuthPayload(), action: 'clone-template', template_id }).pipe(map(res => res.data));
  }

  // FIELDS
  createField(data: Partial<TemplateField>): Observable<{ field_id: number }> {
    return this.http.post<ApiResponse<{ field_id: number }>>(this.apiUrl, { ...this.getAuthPayload(), action: 'create-field', ...data }).pipe(map(res => res.data));
  }

  getTemplateFields(template_id: number): Observable<TemplateField[]> {
    return this.http.post<ApiResponse<TemplateField[]>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-template-fields', template_id }).pipe(map(res => res.data));
  }

  deleteField(field_id: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, { ...this.getAuthPayload(), action: 'delete-field', field_id }).pipe(map(() => void 0));
  }

  // ASSESSMENT SETTINGS
  setAssessmentCertificate(assessment_id: number, data: Partial<AssessmentCertificateSettings>): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, { ...this.getAuthPayload(), action: 'set-assessment-certificate', assessment_id, ...data }).pipe(map(() => void 0));
  }

  getAssessmentCertificate(assessment_id: number): Observable<AssessmentCertificateSettings | null> {
    return this.http.post<ApiResponse<AssessmentCertificateSettings>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-assessment-certificate', assessment_id }).pipe(map(res => res.data));
  }

  // ISSUANCE
  issueCertificate(attempt_id: number, custom_data?: any): Observable<{ certificate_id: number; certificate_number: string; verification_code: string }> {
    return this.http.post<ApiResponse<{ certificate_id: number; certificate_number: string; verification_code: string }>>(this.apiUrl, { ...this.getAuthPayload(), action: 'issue-certificate', attempt_id, custom_data }).pipe(map(res => res.data));
  }

  bulkIssueCertificates(assessment_id: number): Observable<{ issued: number; failed: number }> {
    return this.http.post<ApiResponse<{ issued: number; failed: number }>>(this.apiUrl, { ...this.getAuthPayload(), action: 'bulk-issue', assessment_id }).pipe(map(res => res.data));
  }

  // RETRIEVAL
  getCertificate(certificate_id: number): Observable<Certificate> {
    return this.http.post<ApiResponse<Certificate>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-certificate', certificate_id }).pipe(map(res => res.data));
  }

  getCertificates(filters: { user_id?: number; assessment_id?: number; status?: CertificateStatus; limit?: number } = {}): Observable<Certificate[]> {
    return this.http.post<ApiResponse<Certificate[]>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-certificates', ...filters }).pipe(map(res => res.data));
  }

  getMyCertificates(): Observable<Certificate[]> {
    return this.http.post<ApiResponse<Certificate[]>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-my-certificates' }).pipe(map(res => res.data));
  }

  // VERIFICATION (Public)
  verifyCertificate(code: string, verifier_info?: { name?: string; email?: string; organization?: string }): Observable<VerificationResult> {
    return this.http.post<ApiResponse<VerificationResult>>(this.apiUrl, { action: 'verify', code, method: 'api', ...verifier_info }).pipe(map(res => res.data));
  }

  // REVOCATION & RENEWAL
  revokeCertificate(certificate_id: number, reason: string): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, { ...this.getAuthPayload(), action: 'revoke-certificate', certificate_id, reason }).pipe(map(() => void 0));
  }

  renewCertificate(certificate_id: number, reason?: string): Observable<{ certificate_id: number; certificate_number: string }> {
    return this.http.post<ApiResponse<{ certificate_id: number; certificate_number: string }>>(this.apiUrl, { ...this.getAuthPayload(), action: 'renew-certificate', certificate_id, reason }).pipe(map(res => res.data));
  }

  // DOWNLOAD
  trackDownload(certificate_id: number): Observable<void> {
    return this.http.post<ApiResponse<void>>(this.apiUrl, { ...this.getAuthPayload(), action: 'track-download', certificate_id }).pipe(map(() => void 0));
  }

  // STATS
  getCertificateStats(assessment_id?: number): Observable<CertificateStats> {
    return this.http.post<ApiResponse<CertificateStats>>(this.apiUrl, { ...this.getAuthPayload(), action: 'get-certificate-stats', assessment_id }).pipe(map(res => res.data));
  }

  // UTILITIES
  getStatusBadgeClass(status: CertificateStatus): string {
    return { 'active': 'bg-success', 'revoked': 'bg-danger', 'expired': 'bg-warning', 'superseded': 'bg-secondary' }[status] || 'bg-secondary';
  }

  getStatusLabel(status: CertificateStatus): string {
    return { 'active': 'Active', 'revoked': 'Revoked', 'expired': 'Expired', 'superseded': 'Superseded' }[status] || status;
  }

  getValidityTypeLabel(type: ValidityType): string {
    return { 'lifetime': 'Lifetime', 'fixed_period': 'Fixed Period', 'fixed_date': 'Fixed Date' }[type] || type;
  }

  formatCertificateNumber(number: string): string {
    return number; // Already formatted as CERT-YYYY-XXXXXXXX
  }

  isExpiringSoon(validUntil: string | null, daysThreshold: number = 30): boolean {
    if (!validUntil) return false;
    const expiryDate = new Date(validUntil);
    const today = new Date();
    const diffDays = Math.ceil((expiryDate.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));
    return diffDays > 0 && diffDays <= daysThreshold;
  }

  generateVerificationUrl(hash: string): string {
    return `${window.location.origin}/verify/${hash}`;
  }

  getAvailablePlaceholders(): { name: string; label: string; source: DataSource }[] {
    return [
      { name: 'learner_name', label: 'Learner Full Name', source: 'learner' },
      { name: 'learner_first_name', label: 'Learner First Name', source: 'learner' },
      { name: 'learner_last_name', label: 'Learner Last Name', source: 'learner' },
      { name: 'learner_email', label: 'Learner Email', source: 'learner' },
      { name: 'assessment_title', label: 'Assessment Title', source: 'assessment' },
      { name: 'score', label: 'Raw Score', source: 'attempt' },
      { name: 'percentage', label: 'Percentage Score', source: 'attempt' },
      { name: 'grade', label: 'Letter Grade', source: 'attempt' },
      { name: 'certificate_number', label: 'Certificate Number', source: 'certificate' },
      { name: 'verification_code', label: 'Verification Code', source: 'certificate' },
      { name: 'issue_date', label: 'Issue Date', source: 'certificate' },
      { name: 'valid_until', label: 'Valid Until Date', source: 'certificate' },
      { name: 'qr_code', label: 'QR Code', source: 'certificate' },
      { name: 'logo', label: 'Organization Logo', source: 'static' },
      { name: 'signature', label: 'Signature Image', source: 'static' },
      { name: 'signature_name', label: 'Signatory Name', source: 'static' },
      { name: 'signature_title', label: 'Signatory Title', source: 'static' }
    ];
  }
}
