// live-training/qr-scanner/qr-scanner.component.ts

import { Component, OnInit, OnDestroy, Output, EventEmitter, ViewEncapsulation } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import { LiveTrainingService, CheckInResult } from '../live-training.service';

declare var Html5Qrcode: any;

@Component({
  selector: 'app-qr-scanner',
  standalone: true,
  imports: [CommonModule],
  encapsulation: ViewEncapsulation.None,
  template: `
    <div class="qr-scanner-container">
      <!-- Scanner Header -->
      <div class="scanner-header">
        <h5 class="mb-0">
          <i class="bi bi-qr-code-scan me-2"></i>
          Scan Attendance QR Code
        </h5>
        <button class="btn btn-sm btn-secondary" (click)="close()">
          <i class="bi bi-x-lg"></i>
        </button>
      </div>

      <!-- Scanner View -->
      <div class="scanner-body">
        <div *ngIf="!scanning && !result" class="scanner-start text-center py-5">
          <div class="scanner-icon mb-4">
            <i class="bi bi-qr-code display-1 text-primary"></i>
          </div>
          <h5>Ready to Scan</h5>
          <p class="text-muted mb-4">
            Position the QR code within the camera frame to mark your attendance
          </p>
          <button class="btn btn-primary btn-lg" (click)="startScanning()">
            <i class="bi bi-camera me-2"></i>Start Camera
          </button>
        </div>

        <div *ngIf="scanning" class="scanner-active">
          <div id="qr-reader" class="qr-reader"></div>
          <div class="scanner-instructions mt-3 text-center">
            <p class="text-muted mb-2">
              <i class="bi bi-info-circle me-1"></i>
              Point your camera at the QR code displayed by your instructor
            </p>
            <button class="btn btn-secondary" (click)="stopScanning()">
              <i class="bi bi-stop-circle me-1"></i>Stop Scanning
            </button>
          </div>
        </div>

        <!-- Success Result -->
        <div *ngIf="result && result.success" class="scanner-result success text-center py-5">
          <div class="result-icon mb-4">
            <i class="bi bi-check-circle-fill display-1 text-success"></i>
          </div>
          <h4 class="text-success mb-3">Attendance Marked!</h4>
          <div class="result-details">
            <div class="detail-item">
              <span class="label">Status:</span>
              <span class="badge" [ngClass]="getStatusBadgeClass(result.data?.status || 'present')">
                {{ result.data?.status | titlecase }}
              </span>
            </div>
            <div class="detail-item" *ngIf="result.data?.title">
              <span class="label">Session:</span>
              <span class="value">{{ result.data?.title }}</span>
            </div>
          </div>
          <button class="btn btn-primary mt-4" (click)="close()">
            <i class="bi bi-check me-1"></i>Done
          </button>
        </div>

        <!-- Error Result -->
        <div *ngIf="result && !result.success" class="scanner-result error text-center py-5">
          <div class="result-icon mb-4">
            <i class="bi bi-x-circle-fill display-1 text-danger"></i>
          </div>
          <h4 class="text-danger mb-3">Unable to Mark Attendance</h4>
          <p class="text-muted mb-4">{{ result.message }}</p>
          <div class="d-flex gap-2 justify-content-center">
            <button class="btn btn-primary" (click)="tryAgain()">
              <i class="bi bi-arrow-repeat me-1"></i>Try Again
            </button>
            <button class="btn btn-secondary" (click)="close()">
              Close
            </button>
          </div>
        </div>

        <!-- Loading -->
        <div *ngIf="processing" class="scanner-processing text-center py-5">
          <div class="spinner-border text-primary mb-3" role="status"></div>
          <p class="text-muted">Verifying attendance...</p>
        </div>
      </div>

      <!-- Camera Permission Error -->
      <div *ngIf="cameraError" class="camera-error p-4 text-center">
        <i class="bi bi-camera-video-off display-4 text-warning mb-3"></i>
        <h5>Camera Access Required</h5>
        <p class="text-muted mb-3">
          Please allow camera access to scan the QR code
        </p>
        <button class="btn btn-warning" (click)="requestCameraPermission()">
          <i class="bi bi-unlock me-1"></i>Grant Permission
        </button>
      </div>
    </div>
  `,
  styles: [`
    .qr-scanner-container {
      background: #fff;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
      max-width: 400px;
      margin: auto;
    }

    .scanner-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 1rem 1.25rem;
      border-bottom: 1px solid #f0f0f0;
      background: #f8f9fa;
    }

    .scanner-body {
      padding: 1.5rem;
      min-height: 350px;
      display: flex;
      flex-direction: column;
      justify-content: center;
    }

    .scanner-icon i {
      opacity: 0.8;
    }

    .qr-reader {
      width: 100%;
      border-radius: 12px;
      overflow: hidden;
    }

    #qr-reader video {
      border-radius: 12px;
    }

    #qr-reader__scan_region {
      min-height: 250px !important;
    }

    .result-details {
      background: #f8f9fa;
      padding: 1rem;
      border-radius: 10px;
      display: inline-block;
    }

    .detail-item {
      display: flex;
      justify-content: space-between;
      gap: 1rem;
      padding: 0.5rem 0;

      .label {
        color: #6c757d;
        font-weight: 500;
      }

      .value {
        font-weight: 600;
      }
    }

    .camera-error {
      background: #fff3cd;
      border-radius: 12px;
      margin: 1rem;
    }

    .badge {
      font-size: 0.875rem;
      padding: 0.5em 0.75em;
    }
  `]
})
export class QrScannerComponent implements OnInit, OnDestroy {
  private destroy$ = new Subject<void>();
  private html5QrCode: any;

  @Output() onSuccess = new EventEmitter<CheckInResult>();
  @Output() onError = new EventEmitter<string>();
  @Output() onClose = new EventEmitter<void>();

  scanning = false;
  processing = false;
  cameraError = false;
  result: { success: boolean; message?: string; data?: CheckInResult } | null = null;

  constructor(private liveTrainingService: LiveTrainingService) {}

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
        message: 'Invalid QR code format. Please scan the correct attendance QR code.'
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
            message: err.message ?? 'That could not be marked. Please try again.',
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
