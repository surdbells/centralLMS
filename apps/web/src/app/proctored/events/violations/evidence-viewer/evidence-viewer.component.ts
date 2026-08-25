// evidence-viewer/evidence-viewer.component.ts
import { Component, Input, Output, EventEmitter, OnInit, ChangeDetectionStrategy, ChangeDetectorRef, ViewChild, ElementRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

// Services
import { ProctoringService, SessionViolation } from '../../services/proctoring.service';

@Component({
  selector: 'app-evidence-viewer',
  standalone: true,
  imports: [CommonModule, FormsModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="evidence-viewer">
      <!-- Video Player Section -->
      <div class="player-section">
        <div class="player-container">
          <!-- View Mode Toggle -->
          <div class="view-toggle">
            <button class="btn btn-sm" [class.btn-primary]="viewMode === 'webcam'" [class.btn-secondary]="viewMode !== 'webcam'"
                    (click)="setViewMode('webcam')" [disabled]="!hasWebcamRecording">
              <i class="bi bi-webcam me-1"></i> Webcam
            </button>
            <button class="btn btn-sm" [class.btn-primary]="viewMode === 'screen'" [class.btn-secondary]="viewMode !== 'screen'"
                    (click)="setViewMode('screen')" [disabled]="!hasScreenRecording">
              <i class="bi bi-display me-1"></i> Screen
            </button>
            <button class="btn btn-sm" [class.btn-primary]="viewMode === 'split'" [class.btn-secondary]="viewMode !== 'split'"
                    (click)="setViewMode('split')" [disabled]="!hasWebcamRecording || !hasScreenRecording">
              <i class="bi bi-layout-split me-1"></i> Split
            </button>
          </div>

          <!-- Video Players -->
          <div class="video-container" [class.split-view]="viewMode === 'split'">
            <div class="video-wrapper webcam" *ngIf="viewMode === 'webcam' || viewMode === 'split'">
              <video #webcamVideo [src]="webcamVideoUrl" (timeupdate)="onTimeUpdate($event)"
                     (loadedmetadata)="onVideoLoaded($event)" (ended)="onVideoEnded()"></video>
              <div class="video-label">Webcam</div>
              <div class="no-video" *ngIf="!hasWebcamRecording">
                <i class="bi bi-camera-video-off"></i>
                <p>No webcam recording</p>
              </div>
            </div>
            <div class="video-wrapper screen" *ngIf="viewMode === 'screen' || viewMode === 'split'">
              <video #screenVideo [src]="screenVideoUrl"></video>
              <div class="video-label">Screen</div>
              <div class="no-video" *ngIf="!hasScreenRecording">
                <i class="bi bi-display"></i>
                <p>No screen recording</p>
              </div>
            </div>
          </div>

          <!-- Video Controls -->
          <div class="video-controls">
            <div class="controls-left">
              <button class="btn btn-control" (click)="togglePlay()">
                <i class="bi" [ngClass]="isPlaying ? 'bi-pause-fill' : 'bi-play-fill'"></i>
              </button>
              <button class="btn btn-control" (click)="skipBackward()"><i class="bi bi-skip-backward-fill"></i></button>
              <button class="btn btn-control" (click)="skipForward()"><i class="bi bi-skip-forward-fill"></i></button>
              <span class="time-display">{{ formatTime(currentTime) }} / {{ formatTime(duration) }}</span>
            </div>
            <div class="controls-center">
              <div class="progress-bar-container" (click)="seekToPosition($event)">
                <div class="progress-bar">
                  <div class="progress-fill" [style.width.%]="progressPercent"></div>
                  <div *ngFor="let v of violations" class="violation-marker"
                       [style.left.%]="getViolationPosition(v)"
                       [class.critical]="v.severity === 'critical'" [class.high]="v.severity === 'high'"
                       (click)="seekToViolation(v); $event.stopPropagation()"></div>
                </div>
              </div>
            </div>
            <div class="controls-right">
              <select class="form-select form-select-sm playback-speed" [(ngModel)]="playbackSpeed" (change)="setPlaybackSpeed()">
                <option [value]="0.5">0.5x</option>
                <option [value]="1">1x</option>
                <option [value]="1.5">1.5x</option>
                <option [value]="2">2x</option>
              </select>
              <button class="btn btn-control" (click)="toggleMute()">
                <i class="bi" [ngClass]="isMuted ? 'bi-volume-mute-fill' : 'bi-volume-up-fill'"></i>
              </button>
              <button class="btn btn-control" (click)="toggleFullscreen()"><i class="bi bi-fullscreen"></i></button>
            </div>
          </div>
        </div>
      </div>

      <!-- Violations Quick Jump -->
      <div class="violations-jump" *ngIf="violations.length > 0">
        <h6><i class="bi bi-exclamation-triangle me-2"></i>Jump to Violation</h6>
        <div class="violations-list">
          <button *ngFor="let v of violations" class="violation-btn" [class.critical]="v.severity === 'critical'"
                  [class.high]="v.severity === 'high'" (click)="seekToViolation(v)">
            <span class="v-time">{{ formatTime(v.timestamp) }}</span>
            <span class="v-type">{{ formatViolationType(v.violation_type) }}</span>
          </button>
        </div>
      </div>

      <!-- Evidence Gallery -->
      <div class="evidence-gallery">
        <h6><i class="bi bi-images me-2"></i>Captured Evidence</h6>
        <div class="gallery-filters">
          <button class="btn btn-sm" [class.btn-primary]="galleryFilter === 'all'" [class.btn-secondary]="galleryFilter !== 'all'" (click)="galleryFilter = 'all'">All</button>
          <button class="btn btn-sm" [class.btn-primary]="galleryFilter === 'violations'" [class.btn-secondary]="galleryFilter !== 'violations'" (click)="galleryFilter = 'violations'">Violations</button>
          <button class="btn btn-sm" [class.btn-primary]="galleryFilter === 'periodic'" [class.btn-secondary]="galleryFilter !== 'periodic'" (click)="galleryFilter = 'periodic'">Periodic</button>
        </div>
        <div class="gallery-grid">
          <div *ngFor="let img of filteredEvidence" class="gallery-item" [class.violation]="img.is_violation" (click)="viewEvidence(img)">
            <img [src]="img.thumbnail_url" [alt]="img.type">
            <div class="item-overlay">
              <span>{{ formatTime(img.timestamp) }}</span>
              <span>{{ img.type }}</span>
            </div>
            <i class="bi bi-exclamation-triangle-fill violation-icon" *ngIf="img.is_violation"></i>
          </div>
        </div>
        <p class="text-center text-muted py-3" *ngIf="filteredEvidence.length === 0">No evidence available</p>
      </div>

      <!-- Lightbox -->
      <div class="lightbox" *ngIf="lightboxImage" (click)="closeLightbox()">
        <div class="lightbox-content" (click)="$event.stopPropagation()">
          <img [src]="lightboxImage.url" [alt]="lightboxImage.type">
          <div class="lightbox-info">
            <span>{{ formatTime(lightboxImage.timestamp) }} - {{ lightboxImage.type }}</span>
            <span *ngIf="lightboxImage.is_violation" class="badge bg-danger ms-2">Violation</span>
          </div>
          <button class="lightbox-close" (click)="closeLightbox()"><i class="bi bi-x-lg"></i></button>
          <button class="lightbox-prev" (click)="prevImage(); $event.stopPropagation()"><i class="bi bi-chevron-left"></i></button>
          <button class="lightbox-next" (click)="nextImage(); $event.stopPropagation()"><i class="bi bi-chevron-right"></i></button>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .evidence-viewer { }
    .player-section { background: #000; border-radius: 0.5rem; overflow: hidden; margin-bottom: 1rem; }
    .player-container { position: relative; }
    .view-toggle { position: absolute; top: 1rem; right: 1rem; z-index: 10; display: flex; gap: 0.25rem; }
    .video-container { position: relative; background: #000; min-height: 350px; display: flex; }
    .video-wrapper { flex: 1; position: relative; }
    .video-wrapper video { width: 100%; height: 350px; object-fit: contain; }
    .video-label { position: absolute; top: 0.5rem; left: 0.5rem; background: rgba(0,0,0,0.7); color: #fff; padding: 0.25rem 0.5rem; border-radius: 0.25rem; font-size: 0.75rem; }
    .no-video { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #6c757d; }
    .no-video i { font-size: 3rem; }
    .video-controls { display: flex; align-items: center; gap: 1rem; padding: 0.75rem 1rem; background: #1a1a1a; }
    .controls-left, .controls-right { display: flex; align-items: center; gap: 0.5rem; }
    .controls-center { flex: 1; }
    .btn-control { background: transparent; border: none; color: #fff; font-size: 1.25rem; padding: 0.25rem 0.5rem; }
    .btn-control:hover { color: #0d6efd; }
    .time-display { color: #fff; font-size: 0.875rem; font-family: monospace; }
    .playback-speed { width: 70px; }
    .progress-bar-container { cursor: pointer; padding: 0.5rem 0; }
    .progress-bar { position: relative; height: 6px; background: #444; border-radius: 3px; }
    .progress-fill { height: 100%; background: #0d6efd; border-radius: 3px; }
    .violation-marker { position: absolute; top: 50%; transform: translate(-50%, -50%); width: 12px; height: 12px; border-radius: 50%; cursor: pointer; border: 2px solid #fff; background: #ffc107; }
    .violation-marker.critical { background: #dc3545; }
    .violation-marker.high { background: #fd7e14; }
    .violations-jump { background: #fff; border-radius: 0.5rem; padding: 1rem; margin-bottom: 1rem; }
    .violations-list { display: flex; flex-wrap: wrap; gap: 0.5rem; margin-top: 0.5rem; }
    .violation-btn { display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 0.75rem; border: 1px solid #dee2e6; border-radius: 0.375rem; background: #fff; cursor: pointer; font-size: 0.8125rem; }
    .violation-btn:hover { border-color: #0d6efd; }
    .violation-btn.critical { border-color: #dc3545; background: #fff5f5; }
    .violation-btn.high { border-color: #fd7e14; background: #fff8f0; }
    .v-time { font-family: monospace; color: #6c757d; }
    .v-type { font-weight: 500; }
    .evidence-gallery { background: #fff; border-radius: 0.5rem; padding: 1rem; }
    .gallery-filters { display: flex; gap: 0.25rem; margin-bottom: 1rem; }
    .gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 0.75rem; }
    .gallery-item { position: relative; border-radius: 0.375rem; overflow: hidden; cursor: pointer; aspect-ratio: 4/3; }
    .gallery-item img { width: 100%; height: 100%; object-fit: cover; }
    .gallery-item.violation { border: 2px solid #dc3545; }
    .item-overlay { position: absolute; bottom: 0; left: 0; right: 0; padding: 0.5rem; background: linear-gradient(transparent, rgba(0,0,0,0.8)); color: #fff; font-size: 0.7rem; display: flex; justify-content: space-between; }
    .violation-icon { position: absolute; top: 0.5rem; right: 0.5rem; color: #dc3545; }
    .lightbox { position: fixed; inset: 0; background: rgba(0,0,0,0.9); z-index: 1100; display: flex; align-items: center; justify-content: center; }
    .lightbox-content { position: relative; max-width: 90%; }
    .lightbox-content img { max-width: 100%; max-height: 80vh; border-radius: 0.5rem; }
    .lightbox-info { text-align: center; color: #fff; margin-top: 1rem; }
    .lightbox-close { position: absolute; top: -40px; right: 0; background: transparent; border: none; color: #fff; font-size: 1.5rem; cursor: pointer; }
    .lightbox-prev, .lightbox-next { position: absolute; top: 50%; transform: translateY(-50%); background: rgba(255,255,255,0.2); border: none; color: #fff; font-size: 2rem; padding: 1rem; cursor: pointer; border-radius: 0.25rem; }
    .lightbox-prev { left: -60px; }
    .lightbox-next { right: -60px; }
  `]
})
export class EvidenceViewerComponent implements OnInit {
  @ViewChild('webcamVideo') webcamVideoEl!: ElementRef<HTMLVideoElement>;
  @ViewChild('screenVideo') screenVideoEl!: ElementRef<HTMLVideoElement>;

  @Input() sessionId!: number;
  @Input() hasWebcamRecording = false;
  @Input() hasScreenRecording = false;
  @Input() violations: SessionViolation[] = [];
  @Output() timestampClick = new EventEmitter<number>();

  viewMode: 'webcam' | 'screen' | 'split' = 'webcam';
  webcamVideoUrl = '';
  screenVideoUrl = '';
  evidenceItems: any[] = [];
  isPlaying = false;
  isMuted = false;
  currentTime = 0;
  duration = 0;
  playbackSpeed = 1;
  progressPercent = 0;
  galleryFilter: 'all' | 'violations' | 'periodic' = 'all';
  lightboxImage: any = null;
  lightboxIndex = 0;

  constructor(private proctoringService: ProctoringService, private cdr: ChangeDetectorRef) {}

  ngOnInit(): void {
    this.loadEvidence();
    if (this.hasWebcamRecording) this.webcamVideoUrl = `/api/proctoring/sessions/${this.sessionId}/recordings/webcam`;
    if (this.hasScreenRecording) this.screenVideoUrl = `/api/proctoring/sessions/${this.sessionId}/recordings/screen`;
    if (!this.hasWebcamRecording && this.hasScreenRecording) this.viewMode = 'screen';
  }

  loadEvidence(): void {
    this.proctoringService.getSessionEvidence(this.sessionId).subscribe(items => { this.evidenceItems = items; this.cdr.markForCheck(); });
  }

  get filteredEvidence(): any[] {
    switch (this.galleryFilter) {
      case 'violations': return this.evidenceItems.filter(e => e.is_violation);
      case 'periodic': return this.evidenceItems.filter(e => !e.is_violation);
      default: return this.evidenceItems;
    }
  }

  setViewMode(mode: 'webcam' | 'screen' | 'split'): void { this.viewMode = mode; }

  togglePlay(): void {
    const video = this.getActiveVideo();
    if (!video) return;
    this.isPlaying ? video.pause() : video.play();
    this.isPlaying = !this.isPlaying;
    this.syncVideos();
  }

  toggleMute(): void {
    const video = this.getActiveVideo();
    if (video) { video.muted = !video.muted; this.isMuted = video.muted; }
  }

  setPlaybackSpeed(): void {
    [this.webcamVideoEl?.nativeElement, this.screenVideoEl?.nativeElement].forEach(v => { if (v) v.playbackRate = this.playbackSpeed; });
  }

  skipForward(): void { this.seek(this.currentTime + 10); }
  skipBackward(): void { this.seek(this.currentTime - 10); }

  seek(time: number): void {
    const video = this.getActiveVideo();
    if (video) { video.currentTime = Math.max(0, Math.min(time, this.duration)); this.syncVideos(); }
  }

  seekToPosition(event: MouseEvent): void {
    const el = event.currentTarget as HTMLElement;
    const rect = el.getBoundingClientRect();
    this.seek((event.clientX - rect.left) / rect.width * this.duration);
  }

  seekToViolation(v: SessionViolation): void { this.seek(v.timestamp); this.timestampClick.emit(v.timestamp); }
  getViolationPosition(v: SessionViolation): number { return this.duration ? (v.timestamp / this.duration) * 100 : 0; }

  onTimeUpdate(event: Event): void {
    const video = event.target as HTMLVideoElement;
    this.currentTime = video.currentTime;
    this.progressPercent = this.duration ? (this.currentTime / this.duration) * 100 : 0;
    this.cdr.markForCheck();
  }

  onVideoLoaded(event: Event): void { this.duration = (event.target as HTMLVideoElement).duration; this.cdr.markForCheck(); }
  onVideoEnded(): void { this.isPlaying = false; this.cdr.markForCheck(); }

  toggleFullscreen(): void {
    const container = document.querySelector('.video-container');
    if (container) { document.fullscreenElement ? document.exitFullscreen() : container.requestFullscreen(); }
  }

  private getActiveVideo(): HTMLVideoElement | null { return this.webcamVideoEl?.nativeElement || this.screenVideoEl?.nativeElement || null; }

  private syncVideos(): void {
    if (this.viewMode === 'split' && this.webcamVideoEl && this.screenVideoEl) {
      const main = this.webcamVideoEl.nativeElement;
      const secondary = this.screenVideoEl.nativeElement;
      secondary.currentTime = main.currentTime;
      this.isPlaying ? secondary.play() : secondary.pause();
    }
  }

  viewEvidence(img: any): void { this.lightboxImage = img; this.lightboxIndex = this.filteredEvidence.indexOf(img); }
  closeLightbox(): void { this.lightboxImage = null; }
  prevImage(): void { this.lightboxIndex = (this.lightboxIndex - 1 + this.filteredEvidence.length) % this.filteredEvidence.length; this.lightboxImage = this.filteredEvidence[this.lightboxIndex]; }
  nextImage(): void { this.lightboxIndex = (this.lightboxIndex + 1) % this.filteredEvidence.length; this.lightboxImage = this.filteredEvidence[this.lightboxIndex]; }

  formatTime(seconds: number): string {
    if (!seconds && seconds !== 0) return '0:00';
    const mins = Math.floor(seconds / 60);
    const secs = Math.floor(seconds % 60);
    return `${mins}:${secs.toString().padStart(2, '0')}`;
  }

  formatViolationType(type: string): string { return type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()); }
}
