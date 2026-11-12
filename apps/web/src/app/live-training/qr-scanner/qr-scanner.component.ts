// live-training/qr-scanner/qr-scanner.component.ts

import { Component, OnInit, OnDestroy, Output, EventEmitter, ViewEncapsulation, inject } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule, Location } from '@angular/common';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { LiveTrainingService, CheckInResult } from '../live-training.service';
import { TopComponent } from '../../partials/top/top.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';

declare var Html5Qrcode: any;

@Component({
  selector: 'app-qr-scanner',
  standalone: true,
  imports: [CommonModule, TopComponent, TranslatePipe],
  encapsulation: ViewEncapsulation.None,
  template: `
    <app-top></app-top>

    <main class="main">
    <div class="ui-page qr-scanner-page">
      <!-- Scanner Header -->
      <header class="ui-page-header">
        <button type="button" class="ui-page-header__back" (click)="goBack()" [attr.aria-label]="'common.back' | translate"><i class="bi bi-chevron-left"></i></button>
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-qr-code-scan"></i></div>
        <div class="ui-page-header__titles">
          <h1>{{ 'live.qr.title' | translate }}</h1>
          <p>{{ 'live.learner.scan_desc' | translate }}</p>
        </div>
        <div class="ui-page-header__actions">
          <button type="button" class="btn btn-outline-secondary" (click)="close()" [attr.aria-label]="'common.close' | translate">
            <i class="bi bi-x-lg me-1"></i>{{ 'common.close' | translate }}
          </button>
        </div>
      </header>

    <section class="ui-section qr-scanner-container">
      <!-- Scanner View -->
      <div class="scanner-body">
        <div *ngIf="!scanning && !result" class="scanner-start text-center py-5">
          <div class="scanner-icon mb-4">
            <span class="ui-state__icon"><i class="bi bi-qr-code"></i></span>
          </div>
          <h3 class="scanner-heading">{{ 'live.qr.ready' | translate }}</h3>
          <p class="ui-muted mb-4">
            {{ 'live.qr.ready_hint' | translate }}
          </p>
          <button class="btn btn-primary" (click)="startScanning()">
            <i class="bi bi-camera me-1"></i>{{ 'live.qr.start_camera' | translate }}
          </button>
        </div>

        <div *ngIf="scanning" class="scanner-active">
          <div id="qr-reader" class="qr-reader"></div>
          <div class="scanner-instructions mt-3 text-center">
            <p class="ui-muted mb-2">
              <i class="bi bi-info-circle me-1"></i>
              {{ 'live.qr.point_camera' | translate }}
            </p>
            <button class="btn btn-outline-secondary" (click)="stopScanning()">
              <i class="bi bi-stop-circle me-1"></i>{{ 'live.qr.stop_scanning' | translate }}
            </button>
          </div>
        </div>

        <!-- Success Result -->
        <div *ngIf="result && result.success" class="scanner-result success text-center py-5">
          <div class="result-icon mb-4">
            <i class="bi bi-check-circle-fill result-glyph text-success"></i>
          </div>
          <h3 class="scanner-heading text-success mb-3">{{ 'live.qr.marked' | translate }}</h3>
          <div class="result-details">
            <div class="detail-item">
              <span class="label">{{ 'live.qr.status_label' | translate }}</span>
              <span class="badge" [ngClass]="getStatusBadgeClass(result.data?.status || 'present')">
                {{ result.data?.status | titlecase }}
              </span>
            </div>
            <div class="detail-item" *ngIf="result.data?.title">
              <span class="label">{{ 'live.common.session_colon' | translate }}</span>
              <span class="value">{{ result.data?.title }}</span>
            </div>
          </div>
          <button class="btn btn-primary mt-4" (click)="close()">
            <i class="bi bi-check me-1"></i>{{ 'common.done' | translate }}
          </button>
        </div>

        <!-- Error Result -->
        <div *ngIf="result && !result.success" class="scanner-result error text-center py-5">
          <div class="result-icon mb-4">
            <i class="bi bi-x-circle-fill result-glyph text-danger"></i>
          </div>
          <h3 class="scanner-heading text-danger mb-3">{{ 'live.qr.unable' | translate }}</h3>
          <p class="ui-muted mb-4">{{ result.message }}</p>
          <div class="d-flex gap-2 justify-content-center">
            <button class="btn btn-primary" (click)="tryAgain()">
              <i class="bi bi-arrow-repeat me-1"></i>{{ 'live.qr.try_again' | translate }}
            </button>
            <button class="btn btn-outline-secondary" (click)="close()">
              {{ 'common.close' | translate }}
            </button>
          </div>
        </div>

        <!-- Loading -->
        <div *ngIf="processing" class="scanner-processing text-center py-5">
          <div class="spinner-border text-primary mb-3" role="status"></div>
          <p class="ui-muted">{{ 'live.qr.verifying' | translate }}</p>
        </div>
      </div>

      <!-- Camera Permission Error -->
      <div *ngIf="cameraError" class="camera-error ui-state">
        <span class="ui-state__icon"><i class="bi bi-camera-video-off"></i></span>
        <h3>{{ 'live.qr.camera_required' | translate }}</h3>
        <p class="ui-muted mb-3">
          {{ 'live.qr.camera_hint' | translate }}
        </p>
        <button class="btn btn-primary" (click)="requestCameraPermission()">
          <i class="bi bi-unlock me-1"></i>{{ 'live.qr.grant_permission' | translate }}
        </button>
      </div>
    </section>
    </div>
    </main>
  `,
  styles: [`
    /* Page chrome comes from the global UI kit (styles.scss). These rules are
       global (ViewEncapsulation.None, for the scanner library's own markup),
       so every one is scoped under .qr-scanner-page. */
    .qr-scanner-page .qr-scanner-container {
      max-width: 480px;
      padding: 0;
      overflow: hidden;
    }

    .qr-scanner-page .scanner-body {
      padding: 20px;
      min-height: 350px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .qr-scanner-page .scanner-heading {
      font-size: 1rem;
      font-weight: 650;
      margin-bottom: 6px;
    }

    .qr-scanner-page .result-glyph {
      font-size: 3rem;
      line-height: 1;
    }

    .qr-scanner-page .qr-reader {
      width: 100%;
      border-radius: var(--ui-radius-card, 12px);
      overflow: hidden;
    }

    #qr-reader video {
      border-radius: var(--ui-radius-card, 12px);
    }

    #qr-reader__scan_region {
      min-height: 250px !important;
    }

    .qr-scanner-page .result-details {
      border: 1px solid var(--ui-border);
      padding: 12px 16px;
      border-radius: var(--ui-radius-card, 12px);
      display: inline-block;
      font-size: .875rem;
    }

    .qr-scanner-page .detail-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      padding: 6px 0;
    }

    .qr-scanner-page .detail-item .label {
      color: var(--ui-text-muted);
      font-weight: 500;
    }

    .qr-scanner-page .detail-item .value {
      font-weight: 600;
    }

    .qr-scanner-page .camera-error {
      margin: 16px;
    }
  `]
})
export class QrScannerComponent implements OnInit, OnDestroy {
  private readonly backLocation = inject(Location);
  private readonly navRouter = inject(Router);
  private destroy$ = new Subject<void>();
  private html5QrCode: any;

  @Output() onSuccess = new EventEmitter<CheckInResult>();
  @Output() onError = new EventEmitter<string>();
  @Output() onClose = new EventEmitter<void>();

  scanning = false;
  processing = false;
  cameraError = false;
  result: { success: boolean; message?: string; data?: CheckInResult } | null = null;

  constructor(private liveTrainingService: LiveTrainingService, private i18n: I18nService) {}

  /** Header back button: the previous page, or /mytraining when there is no history (fresh tab / deep link). */
  goBack(): void {
    this.stopScanning();
    if (window.history.length > 1) {
      this.backLocation.back();
      return;
    }
    void this.navRouter.navigate(['/mytraining']);
  }

  ngOnInit(): void {
    // Load Html5Qrcode library if not already loaded
    this.loadQrScannerLibrary();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
    this.stopScanning();
  }

  private loadQrScannerLibrary(): void {
    if (typeof Html5Qrcode !== 'undefined') return;

    const script = document.createElement('script');
    script.src = 'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js';
    script.async = true;
    document.head.appendChild(script);
  }

  async startScanning(): Promise<void> {
    this.result = null;
    this.cameraError = false;

    try {
      // Check for camera permission
      const stream = await navigator.mediaDevices.getUserMedia({ video: true });
      stream.getTracks().forEach(track => track.stop());

      this.scanning = true;

      // Wait for DOM to update
      setTimeout(() => {
        this.initializeScanner();
      }, 100);

    } catch (err) {
      console.error('Camera permission denied:', err);
      this.cameraError = true;
    }
  }

  private initializeScanner(): void {
    const container = document.getElementById('qr-reader');
    if (!container) return;

    this.html5QrCode = new Html5Qrcode('qr-reader');

    const config = {
      fps: 10,
      qrbox: { width: 250, height: 250 },
      aspectRatio: 1.0
    };

    this.html5QrCode.start(
      { facingMode: 'environment' },
      config,
      (decodedText: string) => this.onQrCodeScanned(decodedText),
      (_errorMessage: string) => {
        // Ignore scanning errors (continuous scanning generates errors for non-QR images)
      }
    ).catch((err: any) => {
      console.error('Scanner start error:', err);
      this.scanning = false;
      this.cameraError = true;
    });
  }

  stopScanning(): void {
    if (this.html5QrCode) {
      this.html5QrCode.stop().catch((err: any) => {
        console.error('Error stopping scanner:', err);
      });
    }
    this.scanning = false;
  }

  private onQrCodeScanned(qrToken: string): void {
    // Stop scanning immediately to prevent multiple scans
    this.stopScanning();
    this.processing = true;

    // Validate the token format
    if (!this.isValidQrToken(qrToken)) {
      this.result = {
        success: false,
        message: this.i18n.t('live.qr.invalid_format')
      };
      this.processing = false;
      return;
    }

    // Send to backend
    this.liveTrainingService.checkIn(qrToken)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (checkIn) => {
          this.processing = false;
          this.result = {
            success: true,
            // The server's own words: being marked late is a different
            // outcome from being marked present, and worth reading.
            message: checkIn.message,
            data: checkIn,
          };
          this.onSuccess.emit(checkIn);
        },
        error: (err: { message?: string }) => {
          this.processing = false;
          this.result = {
            success: false,
            // `err.error.message` is the legacy envelope's shape. An
            // ApiError has no `error`, so every failure — an expired
            // code, a signed-off register, not being enrolled — read as
            // the same flat "Failed to mark attendance".
            message: err.message ?? this.i18n.t('live.learner.mark_failed'),
          };
          this.onError.emit(this.result.message!);
        }
      });
  }

  /**
   * Whether this looks like a code at all.
   *
   * It used to insist on 64 hex characters, which was the legacy
   * token's shape — so a code from the new API was rejected here
   * before it ever reached the server. Deciding whether a code is
   * valid is the server's job; this only catches an empty scan.
   */
  private isValidQrToken(token: string): boolean {
    return token.trim().length > 0;
  }

  tryAgain(): void {
    this.result = null;
    void this.startScanning();
  }

  requestCameraPermission(): void {
    this.cameraError = false;
    void this.startScanning();
  }

  close(): void {
    this.stopScanning();
    this.onClose.emit();
  }

  getStatusBadgeClass(status: string): string {
    switch (status) {
      case 'present': return 'bg-success';
      case 'late': return 'bg-warning text-dark';
      case 'absent': return 'bg-danger';
      default: return 'bg-secondary';
    }
  }

  formatTime(datetime: string | null | undefined): string {
    if (!datetime) return '';
    return new Date(datetime).toLocaleTimeString('en-US', {
      hour: 'numeric',
      minute: '2-digit',
      hour12: true
    });
  }
}
