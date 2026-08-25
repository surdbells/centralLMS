// live-training/components/qr-scanner/qr-scanner.component.ts

import { Component, EventEmitter, Output, OnInit, OnDestroy, ViewChild, ElementRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

@Component({
  selector: 'app-qr-scanner',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="qr-scanner-container">
      <!-- Scanner View -->
      <div class="scanner-wrapper" *ngIf="!manualMode">
        <div class="scanner-viewport">
          <video #videoElement class="scanner-video" playsinline></video>
          <div class="scanner-overlay">
            <div class="scanner-frame">
              <div class="corner top-left"></div>
              <div class="corner top-right"></div>
              <div class="corner bottom-left"></div>
              <div class="corner bottom-right"></div>
              <div class="scan-line"></div>
            </div>
          </div>
        </div>

        <div class="scanner-controls mt-3">
          <p class="text-center text-muted mb-3">
            <i class="bi bi-camera me-2"></i>
            Position the QR code within the frame
          </p>

          <!-- Camera Selection -->
          <div class="mb-3" *ngIf="cameras.length > 1">
            <select class="form-select" [(ngModel)]="selectedCamera" (change)="switchCamera()">
              <option *ngFor="let camera of cameras; let i = index" [value]="camera.deviceId">
                {{ camera.label || 'Camera ' + (i + 1) }}
              </option>
            </select>
          </div>

          <div class="d-flex gap-2 justify-content-center">
            <button class="btn btn-secondary" (click)="toggleManualMode()">
              <i class="bi bi-keyboard me-2"></i>Enter Manually
            </button>
            <button class="btn btn-outline-danger" (click)="stopScanner()">
              <i class="bi bi-x-lg me-2"></i>Cancel
            </button>
          </div>
        </div>
      </div>

      <!-- Manual Entry -->
      <div class="manual-entry" *ngIf="manualMode">
        <div class="text-center mb-4">
          <div class="manual-icon mb-3">
            <i class="bi bi-qr-code"></i>
          </div>
          <h5>Enter QR Code Manually</h5>
          <p class="text-muted">If you can't scan the code, enter it below</p>
        </div>

        <div class="mb-3">
          <input type="text" class="form-control form-control-lg text-center"
                 [(ngModel)]="manualCode"
                 placeholder="Enter QR code value"
                 (keyup.enter)="submitManualCode()">
        </div>

        <div class="d-flex gap-2 justify-content-center">
          <button class="btn btn-primary" (click)="submitManualCode()" [disabled]="!manualCode.trim()">
            <i class="bi bi-check-lg me-2"></i>Submit
          </button>
          <button class="btn btn-secondary" (click)="toggleManualMode()">
            <i class="bi bi-camera me-2"></i>Scan Instead
          </button>
        </div>
      </div>

      <!-- Error Message -->
      <div class="alert alert-danger mt-3" *ngIf="error">
        <i class="bi bi-exclamation-triangle me-2"></i>
        {{ error }}
      </div>

      <!-- Camera Permission -->
      <div class="camera-permission" *ngIf="permissionDenied">
        <div class="text-center py-4">
          <i class="bi bi-camera-video-off display-4 text-muted mb-3"></i>
          <h5>Camera Access Required</h5>
          <p class="text-muted">Please allow camera access to scan QR codes</p>
          <button class="btn btn-primary" (click)="requestPermission()">
            <i class="bi bi-shield-check me-2"></i>Grant Access
          </button>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .qr-scanner-container {
      max-width: 400px;
      margin: 0 auto;
    }

    .scanner-wrapper {
      width: 100%;
    }

    .scanner-viewport {
      position: relative;
      width: 100%;
      aspect-ratio: 1;
      border-radius: 16px;
      overflow: hidden;
      background: #000;
    }

    .scanner-video {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .scanner-overlay {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .scanner-frame {
      width: 70%;
      height: 70%;
      position: relative;
      border: 2px solid rgba(255, 255, 255, 0.3);
      border-radius: 16px;
    }

    .corner {
      position: absolute;
      width: 24px;
      height: 24px;
      border-color: #303234;
      border-style: solid;
    }

    .top-left {
      top: -2px;
      left: -2px;
      border-width: 4px 0 0 4px;
      border-radius: 8px 0 0 0;
    }

    .top-right {
      top: -2px;
      right: -2px;
      border-width: 4px 4px 0 0;
      border-radius: 0 8px 0 0;
    }

    .bottom-left {
      bottom: -2px;
      left: -2px;
      border-width: 0 0 4px 4px;
      border-radius: 0 0 0 8px;
    }

    .bottom-right {
      bottom: -2px;
      right: -2px;
      border-width: 0 4px 4px 0;
      border-radius: 0 0 8px 0;
    }

    .scan-line {
      position: absolute;
      top: 0;
      left: 5%;
      right: 5%;
      height: 3px;
      background: linear-gradient(90deg, transparent, #303234, transparent);
      animation: scan 2s ease-in-out infinite;
    }

    @keyframes scan {
      0%, 100% { top: 5%; }
      50% { top: 95%; }
    }

    .manual-icon {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background: rgba(201, 162, 39, 0.15);
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto;

      i {
        font-size: 2.5rem;
        color: #303234;
      }
    }
  `]
})
export class QrScannerComponent implements OnInit, OnDestroy {
  @ViewChild('videoElement') videoElement!: ElementRef<HTMLVideoElement>;
  @Output() scanned = new EventEmitter<string>();
  @Output() cancelled = new EventEmitter<void>();

  cameras: MediaDeviceInfo[] = [];
  selectedCamera = '';
  manualMode = false;
  manualCode = '';
  error = '';
  permissionDenied = false;

  private stream: MediaStream | null = null;
  private scanInterval: any;

  async ngOnInit(): Promise<void> {
    await this.initScanner();
  }

  ngOnDestroy(): void {
    this.stopScanner();
  }

  async initScanner(): Promise<void> {
    try {
      // Get available cameras
      const devices = await navigator.mediaDevices.enumerateDevices();
      this.cameras = devices.filter(d => d.kind === 'videoinput');

      if (this.cameras.length > 0) {
        this.selectedCamera = this.cameras[0].deviceId;
        await this.startCamera();
      }
    } catch (err: any) {
      if (err.name === 'NotAllowedError') {
        this.permissionDenied = true;
      } else {
        this.error = 'Failed to access camera: ' + err.message;
      }
    }
  }

  async startCamera(): Promise<void> {
    try {
      this.stream = await navigator.mediaDevices.getUserMedia({
        video: {
          deviceId: this.selectedCamera ? { exact: this.selectedCamera } : undefined,
          facingMode: 'environment',
          width: { ideal: 1280 },
          height: { ideal: 720 }
        }
      });

      if (this.videoElement) {
        this.videoElement.nativeElement.srcObject = this.stream;
        await this.videoElement.nativeElement.play();
        this.startScanning();
      }
    } catch (err: any) {
      this.error = 'Failed to start camera: ' + err.message;
    }
  }

  startScanning(): void {
    // Use BarcodeDetector API if available (modern browsers)
    if ('BarcodeDetector' in window) {
      this.scanWithBarcodeDetector();
    } else {
      // Fallback: Use canvas-based scanning or manual entry
      this.manualMode = true;
      this.error = 'QR scanning not supported. Please enter the code manually.';
    }
  }

  private async scanWithBarcodeDetector(): Promise<void> {
    const barcodeDetector = new (window as any).BarcodeDetector({
      formats: ['qr_code']
    });

    this.scanInterval = setInterval(async () => {
      if (!this.videoElement?.nativeElement) return;

      try {
        const barcodes = await barcodeDetector.detect(this.videoElement.nativeElement);
        if (barcodes.length > 0) {
          const qrValue = barcodes[0].rawValue;
          this.onCodeScanned(qrValue);
        }
      } catch (err) {
        // Continue scanning
      }
    }, 200);
  }

  private onCodeScanned(value: string): void {
    this.stopScanner();
    this.scanned.emit(value);
  }

  async switchCamera(): Promise<void> {
    this.stopStream();
    await this.startCamera();
  }

  toggleManualMode(): void {
    this.manualMode = !this.manualMode;
    if (this.manualMode) {
      this.stopStream();
    } else {
      this.startCamera();
    }
  }

  submitManualCode(): void {
    if (this.manualCode.trim()) {
      this.scanned.emit(this.manualCode.trim());
    }
  }

  async requestPermission(): Promise<void> {
    this.permissionDenied = false;
    this.error = '';
    await this.initScanner();
  }

  stopScanner(): void {
    if (this.scanInterval) {
      clearInterval(this.scanInterval);
      this.scanInterval = null;
    }
    this.stopStream();
    this.cancelled.emit();
  }

  private stopStream(): void {
    if (this.stream) {
      this.stream.getTracks().forEach(track => track.stop());
      this.stream = null;
    }
  }
}
