// course-player/components/video-player/video-player.component.ts

import { Component, Input, Output, EventEmitter, ViewChild, ElementRef, OnInit, OnDestroy, AfterViewInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject, fromEvent, interval } from 'rxjs';
import { takeUntil, throttleTime, debounceTime } from 'rxjs/operators';

export interface VideoEvent {
  type: 'play' | 'pause' | 'seek' | 'complete' | 'buffer' | 'error' | 'speed_change' | 'fullscreen' | 'progress';
  position: number;
  duration: number;
  data?: any;
}

export interface VideoProgress {
  currentTime: number;
  duration: number;
  percentage: number;
  buffered: number;
}

@Component({
  selector: 'app-video-player',
  standalone: true,
  imports: [CommonModule, FormsModule],
  template: `
    <div class="video-player-container"
         [class.fullscreen]="isFullscreen"
         (mousemove)="showControls()"
         (mouseleave)="hideControlsDelayed()">

      <!-- Video Element -->
      <video #videoElement
             class="video-element"
             [src]="src"
             [poster]="poster"
             (loadedmetadata)="onLoadedMetadata()"
             (timeupdate)="onTimeUpdate()"
             (play)="onPlay()"
             (pause)="onPause()"
             (ended)="onEnded()"
             (waiting)="onBuffering(true)"
             (playing)="onBuffering(false)"
             (error)="onError($event)"
             (progress)="onProgress()"
             preload="metadata"
             playsinline>
      </video>

      <!-- Loading Spinner -->
      <div class="loading-overlay" *ngIf="isBuffering">
        <div class="spinner-border text-light" role="status">
          <span class="visually-hidden">Loading...</span>
        </div>
      </div>

      <!-- Big Play Button -->
      <button class="big-play-button" *ngIf="!isPlaying && !isBuffering" (click)="togglePlay()">
        <i class="bi bi-play-fill"></i>
      </button>

      <!-- Controls Overlay -->
      <div class="controls-overlay" [class.visible]="controlsVisible || !isPlaying">

        <!-- Progress Bar -->
        <div class="progress-container">
          <div class="progress-bar-wrapper"
               (click)="seekToPosition($event)"
               (mousemove)="updateSeekPreview($event)"
               (mouseleave)="seekPreviewTime = null">

            <!-- Buffered -->
            <div class="progress-buffered" [style.width.%]="bufferedPercentage"></div>

            <!-- Played -->
            <div class="progress-played" [style.width.%]="progressPercentage"></div>

            <!-- Heatmap (optional) -->
            <div class="progress-heatmap" *ngIf="showHeatmap">
              <div *ngFor="let segment of heatmapSegments"
                   class="heatmap-segment"
                   [style.left.%]="segment.start"
                   [style.width.%]="segment.width"
                   [style.opacity]="segment.intensity">
              </div>
            </div>

            <!-- Seek Handle -->
            <div class="progress-handle" [style.left.%]="progressPercentage"></div>

            <!-- Preview Tooltip -->
            <div class="seek-preview" *ngIf="seekPreviewTime !== null" [style.left.%]="seekPreviewPosition">
              {{ formatTime(seekPreviewTime) }}
            </div>
          </div>
        </div>

        <!-- Control Buttons -->
        <div class="controls-row">
          <div class="controls-left">
            <!-- Play/Pause -->
            <button class="control-btn" (click)="togglePlay()" [title]="isPlaying ? 'Pause (Space)' : 'Play (Space)'">
              <i class="bi" [class.bi-play-fill]="!isPlaying" [class.bi-pause-fill]="isPlaying"></i>
            </button>

            <!-- Skip Backward -->
            <button class="control-btn" (click)="skip(-10)" title="Back 10s (←)">
              <i class="bi bi-skip-backward-fill"></i>
            </button>

            <!-- Skip Forward -->
            <button class="control-btn" (click)="skip(10)" title="Forward 10s (→)">
              <i class="bi bi-skip-forward-fill"></i>
            </button>

            <!-- Volume -->
            <div class="volume-control">
              <button class="control-btn" (click)="toggleMute()" [title]="isMuted ? 'Unmute (M)' : 'Mute (M)'">
                <i class="bi"
                   [class.bi-volume-up-fill]="!isMuted && volume > 0.5"
                   [class.bi-volume-down-fill]="!isMuted && volume <= 0.5 && volume > 0"
                   [class.bi-volume-mute-fill]="isMuted || volume === 0"></i>
              </button>
              <input type="range"
                     class="volume-slider"
                     min="0" max="1" step="0.05"
                     [value]="volume"
                     (input)="setVolume($any($event.target).value)">
            </div>

            <!-- Time Display -->
            <span class="time-display">
              {{ formatTime(currentTime) }} / {{ formatTime(duration) }}
            </span>
          </div>

          <div class="controls-right">
            <!-- Playback Speed -->
            <div class="speed-control dropdown">
              <button class="control-btn dropdown-toggle" data-bs-toggle="dropdown">
                {{ playbackRate }}x
              </button>
              <ul class="dropdown-menu dropdown-menu-dark">
                <li *ngFor="let speed of playbackSpeeds">
                  <button class="dropdown-item"
                          [class.active]="playbackRate === speed"
                          (click)="setPlaybackRate(speed)">
                    {{ speed }}x
                  </button>
                </li>
              </ul>
            </div>

            <!-- Chapters (if available) -->
            <div class="chapters-control dropdown" *ngIf="chapters.length > 0">
              <button class="control-btn dropdown-toggle" data-bs-toggle="dropdown" title="Chapters">
                <i class="bi bi-list-ul"></i>
              </button>
              <ul class="dropdown-menu dropdown-menu-dark">
                <li *ngFor="let chapter of chapters">
                  <button class="dropdown-item" (click)="seekToTime(chapter.time)">
                    <span class="chapter-time">{{ formatTime(chapter.time) }}</span>
                    {{ chapter.title }}
                  </button>
                </li>
              </ul>
            </div>

            <!-- Bookmark -->
            <button class="control-btn" (click)="addBookmark()" title="Add Bookmark (B)">
              <i class="bi bi-bookmark"></i>
            </button>

            <!-- Picture in Picture -->
            <button class="control-btn" (click)="togglePiP()" title="Picture in Picture" *ngIf="supportsPiP">
              <i class="bi bi-pip"></i>
            </button>

            <!-- Fullscreen -->
            <button class="control-btn" (click)="toggleFullscreen()" [title]="isFullscreen ? 'Exit Fullscreen (F)' : 'Fullscreen (F)'">
              <i class="bi" [class.bi-fullscreen]="!isFullscreen" [class.bi-fullscreen-exit]="isFullscreen"></i>
            </button>
          </div>
        </div>
      </div>

      <!-- Keyboard Shortcuts Hint -->
      <div class="shortcuts-hint" *ngIf="showShortcutsHint">
        <div class="hint-content">
          <span><kbd>Space</kbd> Play/Pause</span>
          <span><kbd>←</kbd><kbd>→</kbd> Seek</span>
          <span><kbd>↑</kbd><kbd>↓</kbd> Volume</span>
          <span><kbd>F</kbd> Fullscreen</span>
          <span><kbd>M</kbd> Mute</span>
        </div>
      </div>
    </div>
  `,
  styles: [`
    .video-player-container {
      position: relative;
      width: 100%;
      height: 100%;
      background: #000;
      overflow: hidden;

      &.fullscreen {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        z-index: 9999;
      }
    }

    .video-element {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }

    .loading-overlay {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      z-index: 10;
    }

    .big-play-button {
      position: absolute;
      top: 50%;
      left: 50%;
      transform: translate(-50%, -50%);
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background: rgba(0, 0, 0, 0.7);
      border: 2px solid #fff;
      color: #fff;
      font-size: 2rem;
      cursor: pointer;
      transition: all 0.2s;
      z-index: 5;

      &:hover {
        background: rgba(201, 162, 39, 0.9);
        transform: translate(-50%, -50%) scale(1.1);
      }

      i {
        margin-left: 4px;
      }
    }

    .controls-overlay {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      background: linear-gradient(transparent, rgba(0, 0, 0, 0.8));
      padding: 2rem 1rem 1rem;
      opacity: 0;
      transition: opacity 0.3s;
      z-index: 10;

      &.visible {
        opacity: 1;
      }
    }

    .progress-container {
      margin-bottom: 0.75rem;
    }

    .progress-bar-wrapper {
      position: relative;
      height: 4px;
      background: rgba(255, 255, 255, 0.3);
      border-radius: 2px;
      cursor: pointer;
      transition: height 0.2s;

      &:hover {
        height: 8px;

        .progress-handle {
          opacity: 1;
          transform: translateX(-50%) scale(1);
        }
      }
    }

    .progress-buffered {
      position: absolute;
      top: 0;
      left: 0;
      height: 100%;
      background: rgba(255, 255, 255, 0.4);
      border-radius: 2px;
    }

    .progress-played {
      position: absolute;
      top: 0;
      left: 0;
      height: 100%;
      background: #303234;
      border-radius: 2px;
    }

    .progress-heatmap {
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 100%;
      pointer-events: none;

      .heatmap-segment {
        position: absolute;
        top: 0;
        height: 100%;
        background: #ff6b6b;
      }
    }

    .progress-handle {
      position: absolute;
      top: 50%;
      width: 14px;
      height: 14px;
      background: #303234;
      border-radius: 50%;
      transform: translateX(-50%) scale(0);
      opacity: 0;
      transition: all 0.2s;
      pointer-events: none;
    }

    .seek-preview {
      position: absolute;
      bottom: 100%;
      transform: translateX(-50%);
      background: rgba(0, 0, 0, 0.9);
      color: #fff;
      padding: 0.25rem 0.5rem;
      border-radius: 4px;
      font-size: 0.75rem;
      white-space: nowrap;
      margin-bottom: 8px;
    }

    .controls-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .controls-left,
    .controls-right {
      display: flex;
      align-items: center;
      gap: 0.25rem;
    }

    .control-btn {
      background: transparent;
      border: none;
      color: #fff;
      padding: 0.5rem;
      cursor: pointer;
      font-size: 1.25rem;
      opacity: 0.9;
      transition: all 0.2s;
      border-radius: 4px;

      &:hover {
        opacity: 1;
        background: rgba(255, 255, 255, 0.1);
      }
    }

    .volume-control {
      display: flex;
      align-items: center;

      .volume-slider {
        width: 0;
        opacity: 0;
        transition: all 0.2s;
        accent-color: #303234;
      }

      &:hover .volume-slider {
        width: 70px;
        opacity: 1;
        margin-left: 0.25rem;
      }
    }

    .time-display {
      font-size: 0.875rem;
      color: rgba(255, 255, 255, 0.9);
      font-variant-numeric: tabular-nums;
      margin-left: 0.5rem;
    }

    .speed-control,
    .chapters-control {
      .dropdown-toggle::after {
        display: none;
      }

      .dropdown-menu {
        min-width: auto;
      }

      .chapter-time {
        color: #303234;
        margin-right: 0.5rem;
        font-size: 0.75rem;
      }
    }

    .shortcuts-hint {
      position: absolute;
      top: 1rem;
      left: 50%;
      transform: translateX(-50%);
      background: rgba(0, 0, 0, 0.8);
      padding: 0.5rem 1rem;
      border-radius: 0.5rem;
      z-index: 15;

      .hint-content {
        display: flex;
        gap: 1rem;
        font-size: 0.75rem;
        color: rgba(255, 255, 255, 0.8);

        kbd {
          background: rgba(255, 255, 255, 0.2);
          padding: 0.125rem 0.375rem;
          border-radius: 4px;
          margin-right: 0.25rem;
        }
      }
    }
  `]
})
export class VideoPlayerComponent implements OnInit, OnDestroy, AfterViewInit {
  @ViewChild('videoElement') videoRef!: ElementRef<HTMLVideoElement>;

  @Input() src!: string;
  @Input() poster?: string;
  @Input() startTime = 0;
  @Input() autoplay = false;
  @Input() chapters: { time: number; title: string }[] = [];
  @Input() showHeatmap = false;
  @Input() heatmapSegments: { start: number; width: number; intensity: number }[] = [];

  @Output() videoEvent = new EventEmitter<VideoEvent>();
  @Output() progressUpdate = new EventEmitter<VideoProgress>();
  @Output() bookmarkRequest = new EventEmitter<number>();

  private destroy$ = new Subject<void>();
  private video!: HTMLVideoElement;

  // State
  isPlaying = false;
  isBuffering = false;
  isMuted = false;
  isFullscreen = false;
  controlsVisible = true;
  showShortcutsHint = false;

  // Progress
  currentTime = 0;
  duration = 0;
  progressPercentage = 0;
  bufferedPercentage = 0;

  // Volume
  volume = 1;
  previousVolume = 1;

  // Playback
  playbackRate = 1;
  playbackSpeeds = [0.5, 0.75, 1, 1.25, 1.5, 1.75, 2];

  // Seek preview
  seekPreviewTime: number | null = null;
  seekPreviewPosition = 0;

  // Features
  supportsPiP = false;

  // Tracking
  private watchedSegments: Set<number> = new Set();
  private lastReportedPosition = 0;
  private controlsTimeout: any;

  ngOnInit(): void {
    this.supportsPiP = 'pictureInPictureEnabled' in document;
  }

  ngAfterViewInit(): void {
    this.video = this.videoRef.nativeElement;

    // Set initial volume from localStorage
    const savedVolume = localStorage.getItem('videoPlayerVolume');
    if (savedVolume) {
      this.volume = parseFloat(savedVolume);
      this.video.volume = this.volume;
    }

    // Set initial playback rate
    const savedRate = localStorage.getItem('videoPlayerRate');
    if (savedRate) {
      this.playbackRate = parseFloat(savedRate);
      this.video.playbackRate = this.playbackRate;
    }

    // Setup keyboard shortcuts
    this.setupKeyboardShortcuts();

    // Track progress at intervals
    interval(5000)
      .pipe(takeUntil(this.destroy$))
      .subscribe(() => this.reportProgress());
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
    this.reportProgress();

    if (this.controlsTimeout) {
      clearTimeout(this.controlsTimeout);
    }
  }

  // Video Events
  onLoadedMetadata(): void {
    this.duration = this.video.duration;

    if (this.startTime > 0) {
      this.video.currentTime = this.startTime;
    }

    if (this.autoplay) {
      this.video.play().catch(() => {});
    }
  }

  onTimeUpdate(): void {
    this.currentTime = this.video.currentTime;
    this.progressPercentage = (this.currentTime / this.duration) * 100;

    // Track watched segments (10-second chunks)
    const segment = Math.floor(this.currentTime / 10);
    this.watchedSegments.add(segment);

    // Emit progress update
    this.progressUpdate.emit({
      currentTime: this.currentTime,
      duration: this.duration,
      percentage: this.progressPercentage,
      buffered: this.bufferedPercentage
    });
  }

  onProgress(): void {
    if (this.video.buffered.length > 0) {
      const bufferedEnd = this.video.buffered.end(this.video.buffered.length - 1);
      this.bufferedPercentage = (bufferedEnd / this.duration) * 100;
    }
  }

  onPlay(): void {
    this.isPlaying = true;
    this.emitEvent('play');
  }

  onPause(): void {
    this.isPlaying = false;
    this.emitEvent('pause');
  }

  onEnded(): void {
    this.isPlaying = false;
    this.emitEvent('complete');
  }

  onBuffering(isBuffering: boolean): void {
    this.isBuffering = isBuffering;
    if (isBuffering) {
      this.emitEvent('buffer');
    }
  }

  onError(event: Event): void {
    console.error('Video error:', event);
    this.emitEvent('error', { error: event });
  }

  // Controls
  togglePlay(): void {
    if (this.isPlaying) {
      this.video.pause();
    } else {
      this.video.play();
    }
  }

  skip(seconds: number): void {
    const newTime = Math.max(0, Math.min(this.duration, this.currentTime + seconds));
    this.video.currentTime = newTime;
    this.emitEvent('seek', { from: this.currentTime, to: newTime });
  }

  seekToTime(time: number): void {
    this.video.currentTime = time;
    this.emitEvent('seek', { from: this.currentTime, to: time });
  }

  seekToPosition(event: MouseEvent): void {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const percentage = (event.clientX - rect.left) / rect.width;
    const newTime = percentage * this.duration;
    this.seekToTime(newTime);
  }

  updateSeekPreview(event: MouseEvent): void {
    const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
    const percentage = (event.clientX - rect.left) / rect.width;
    this.seekPreviewPosition = percentage * 100;
    this.seekPreviewTime = percentage * this.duration;
  }

  setVolume(value: number | string): void {
    this.volume = typeof value === 'string' ? parseFloat(value) : value;
    this.video.volume = this.volume;
    this.isMuted = this.volume === 0;
    localStorage.setItem('videoPlayerVolume', this.volume.toString());
  }

  toggleMute(): void {
    if (this.isMuted) {
      this.setVolume(this.previousVolume || 0.5);
    } else {
      this.previousVolume = this.volume;
      this.setVolume(0);
    }
    this.isMuted = !this.isMuted;
  }

  setPlaybackRate(rate: number): void {
    this.playbackRate = rate;
    this.video.playbackRate = rate;
    localStorage.setItem('videoPlayerRate', rate.toString());
    this.emitEvent('speed_change', { rate });
  }

  toggleFullscreen(): void {
    if (!document.fullscreenElement) {
      this.videoRef.nativeElement.parentElement?.requestFullscreen();
      this.isFullscreen = true;
    } else {
      document.exitFullscreen();
      this.isFullscreen = false;
    }
    this.emitEvent('fullscreen', { fullscreen: this.isFullscreen });
  }

  async togglePiP(): Promise<void> {
    try {
      if (document.pictureInPictureElement) {
        await document.exitPictureInPicture();
      } else {
        await this.video.requestPictureInPicture();
      }
    } catch (err) {
      console.error('PiP error:', err);
    }
  }

  addBookmark(): void {
    this.bookmarkRequest.emit(Math.floor(this.currentTime));
  }

  // Controls visibility
  showControls(): void {
    this.controlsVisible = true;
    this.hideControlsDelayed();
  }

  hideControlsDelayed(): void {
    if (this.controlsTimeout) {
      clearTimeout(this.controlsTimeout);
    }

    if (this.isPlaying) {
      this.controlsTimeout = setTimeout(() => {
        this.controlsVisible = false;
      }, 3000);
    }
  }

  // Keyboard shortcuts
  private setupKeyboardShortcuts(): void {
    fromEvent<KeyboardEvent>(document, 'keydown')
      .pipe(takeUntil(this.destroy$))
      .subscribe(event => {
        // Skip if typing in input
        if (event.target instanceof HTMLInputElement || event.target instanceof HTMLTextAreaElement) {
          return;
        }

        switch (event.key) {
          case ' ':
          case 'k':
            event.preventDefault();
            this.togglePlay();
            break;
          case 'ArrowLeft':
          case 'j':
            event.preventDefault();
            this.skip(-10);
            break;
          case 'ArrowRight':
          case 'l':
            event.preventDefault();
            this.skip(10);
            break;
          case 'ArrowUp':
            event.preventDefault();
            this.setVolume(Math.min(1, this.volume + 0.1));
            break;
          case 'ArrowDown':
            event.preventDefault();
            this.setVolume(Math.max(0, this.volume - 0.1));
            break;
          case 'm':
            this.toggleMute();
            break;
          case 'f':
            this.toggleFullscreen();
            break;
          case 'b':
            this.addBookmark();
            break;
          case '0':
          case '1':
          case '2':
          case '3':
          case '4':
          case '5':
          case '6':
          case '7':
          case '8':
          case '9':
            const percent = parseInt(event.key) * 10;
            this.seekToTime((percent / 100) * this.duration);
            break;
        }

        this.showControls();
      });
  }

  // Helpers
  formatTime(seconds: number): string {
    if (isNaN(seconds)) return '0:00';

    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const secs = Math.floor(seconds % 60);

    if (hours > 0) {
      return `${hours}:${minutes.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;
    }
    return `${minutes}:${secs.toString().padStart(2, '0')}`;
  }

  private emitEvent(type: VideoEvent['type'], data?: any): void {
    this.videoEvent.emit({
      type,
      position: Math.floor(this.currentTime),
      duration: Math.floor(this.duration),
      data
    });
  }

  private reportProgress(): void {
    if (Math.abs(this.currentTime - this.lastReportedPosition) >= 5) {
      this.lastReportedPosition = this.currentTime;
      this.emitEvent('progress', {
        watchedSegments: Array.from(this.watchedSegments),
        totalWatched: this.watchedSegments.size * 10
      });
    }
  }

  // Public methods for parent component
  play(): void {
    this.video.play();
  }

  pause(): void {
    this.video.pause();
  }

  getCurrentTime(): number {
    return this.currentTime;
  }

  getDuration(): number {
    return this.duration;
  }

  getWatchedPercentage(): number {
    const totalSegments = Math.ceil(this.duration / 10);
    return totalSegments > 0 ? (this.watchedSegments.size / totalSegments) * 100 : 0;
  }
}
