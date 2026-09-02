// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 13
// Learner: Proctoring Monitor Component
// Multi-Tenant LMS | Angular Standalone
// Client-side proctoring: webcam, tab focus, violations
// =============================================

import { Component, OnInit, OnDestroy, Input, Output, EventEmitter, inject, ElementRef, ViewChild } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Subject, takeUntil, interval, catchError, of, switchMap } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { AssessmentsService } from '../../services/assessments.service';

interface ViolationEvent {
  type: string; severity: 'low'|'medium'|'high'|'critical';
  description: string; timestamp: Date; data?: any;
}

interface ProctoringConfig {
  webcam_enabled: boolean; webcam_snapshot_interval: number;
  screen_share: boolean; tab_switch_detection: boolean;
  copy_paste_detection: boolean; right_click_disabled: boolean;
  keyboard_shortcuts_disabled: boolean; browser_resize_detection: boolean;
  multiple_face_detection: boolean; no_face_detection: boolean;
  max_violations_before_terminate: number;
  snapshot_on_violation: boolean;
}

@Component({
  selector: 'app-proctoring-monitor',
  standalone: true,
  imports: [CommonModule, TuiIcon],
  template: `
    <div class="proctor-container" [class.minimized]="minimized">
      <!-- Webcam feed -->
      <div class="webcam-section" *ngIf="config.webcam_enabled">
        <video #videoEl autoplay muted playsinline class="webcam-feed" [class.hidden]="minimized"></video>
        <canvas #canvasEl class="hidden-canvas"></canvas>
        <div class="webcam-status" [class.active]="webcamActive" [class.error]="webcamError">
          <div class="status-dot"></div>
          <span>{{ webcamError ? 'Camera Error' : (webcamActive ? 'Recording' : 'Connecting...') }}</span>
        </div>
      </div>

      <!-- Monitor Bar -->
      <div class="monitor-bar">
        <div class="monitor-indicators">
          <div class="indicator" [class.active]="webcamActive" *ngIf="config.webcam_enabled" title="Webcam">
            <tui-icon icon="@tui.camera" style="font-size:14px"></tui-icon>
          </div>
          <div class="indicator" [class.active]="!tabHidden" title="Tab Focus">
            <tui-icon icon="@tui.eye" style="font-size:14px"></tui-icon>
          </div>
          <div class="indicator" *ngIf="config.screen_share" [class.active]="screenShareActive" title="Screen Share">
            <tui-icon icon="@tui.monitor" style="font-size:14px"></tui-icon>
          </div>
        </div>

        <div class="violation-counter" *ngIf="violations.length > 0" [class.warn]="violations.length >= warnThreshold"
             [class.critical]="violations.length >= config.max_violations_before_terminate - 2">
          <tui-icon icon="@tui.alert-triangle" style="font-size:14px"></tui-icon>
          <span>{{ violations.length }} violation{{ violations.length > 1 ? 's' : '' }}</span>
        </div>

        <button class="minimize-btn" (click)="minimized=!minimized">
          <tui-icon [icon]="minimized ? '@tui.maximize-2' : '@tui.minimize-2'" style="font-size:14px"></tui-icon>
        </button>
      </div>

      <!-- Violation Alert -->
      <div class="violation-alert" *ngIf="currentAlert" [class]="'alert-' + currentAlert.severity"
           (click)="dismissAlert()">
        <tui-icon icon="@tui.alert-triangle" style="font-size:18px"></tui-icon>
        <div class="alert-content">
          <strong>{{ currentAlert.type | titlecase }}</strong>
          <span>{{ currentAlert.description }}</span>
        </div>
        <span class="alert-dismiss">✕</span>
      </div>

      <!-- Termination Warning -->
      <div class="termination-warning" *ngIf="showTerminationWarning">
        <tui-icon icon="@tui.alert-octagon" style="font-size:24px"></tui-icon>
        <div>
          <h3>Assessment at Risk</h3>
          <p>{{ config.max_violations_before_terminate - violations.length }} more violation(s) will auto-submit your assessment.</p>
        </div>
      </div>
    </div>
  `,
  styles: [`
    :host { display: block; position: fixed; top: 12px; right: 12px; z-index: 9999; }
    .proctor-container { width: 220px; background: #0f172a; border-radius: 12px; overflow: hidden; box-shadow: 0 8px 24px rgba(0,0,0,0.3); transition: all 0.3s; }
    .proctor-container.minimized { width: auto; }
    .webcam-feed { width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block; background: #1e293b; }
    .webcam-feed.hidden { display: none; }
    .hidden-canvas { display: none; }
    .webcam-status { display: flex; align-items: center; gap: 6px; padding: 6px 10px; font-size: 11px; color: #94a3b8; }
    .webcam-status.active { color: #22c55e; }
    .webcam-status.error { color: #ef4444; }
    .status-dot { width: 8px; height: 8px; border-radius: 50%; background: #94a3b8; }
    .webcam-status.active .status-dot { background: #22c55e; animation: pulse 2s infinite; }
    .webcam-status.error .status-dot { background: #ef4444; }
    @keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.5; } }

    .monitor-bar { display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: #1e293b; }
    .monitor-indicators { display: flex; gap: 6px; flex: 1; }
    .indicator { width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; background: #334155; color: #64748b; }
    .indicator.active { background: #064e3b; color: #34d399; }
    .violation-counter { display: flex; align-items: center; gap: 4px; padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; color: #fbbf24; background: rgba(251,191,36,0.15); }
    .violation-counter.warn { color: #f97316; background: rgba(249,115,22,0.15); }
    .violation-counter.critical { color: #ef4444; background: rgba(239,68,68,0.15); animation: pulse 1s infinite; }
    .minimize-btn { background: none; border: none; color: #64748b; cursor: pointer; padding: 4px; border-radius: 4px; }
    .minimize-btn:hover { color: #e2e8f0; background: #334155; }

    .violation-alert { position: fixed; top: 60px; right: 12px; display: flex; align-items: center; gap: 10px; padding: 12px 16px; border-radius: 10px; cursor: pointer; animation: slideIn 0.3s ease; max-width: 350px; z-index: 10000; }
    .alert-low { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
    .alert-medium { background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; }
    .alert-high { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
    .alert-critical { background: #fef2f2; border: 2px solid #ef4444; color: #991b1b; }
    .alert-content { display: flex; flex-direction: column; flex: 1; }
    .alert-content strong { font-size: 13px; }
    .alert-content span { font-size: 12px; opacity: 0.8; }
    .alert-dismiss { font-size: 14px; cursor: pointer; opacity: 0.5; }
    @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

    .termination-warning { position: fixed; bottom: 80px; right: 12px; display: flex; align-items: center; gap: 12px; padding: 14px 18px; background: #fef2f2; border: 2px solid #ef4444; border-radius: 12px; max-width: 350px; z-index: 10000; animation: slideIn 0.3s ease; }
    .termination-warning h3 { margin: 0; font-size: 15px; color: #991b1b; }
    .termination-warning p { margin: 4px 0 0; font-size: 13px; color: #b91c1c; }
  `]
})
export class ProctoringMonitorComponent implements OnInit, OnDestroy {
  @Input() attemptId!: string;
  @Input() config: ProctoringConfig = {
    webcam_enabled: false, webcam_snapshot_interval: 30, screen_share: false,
    tab_switch_detection: true, copy_paste_detection: true, right_click_disabled: true,
    keyboard_shortcuts_disabled: true, browser_resize_detection: true,
    multiple_face_detection: false, no_face_detection: false,
    max_violations_before_terminate: 10, snapshot_on_violation: true
  };
  @Output() onViolation = new EventEmitter<ViolationEvent>();
  @Output() onTerminate = new EventEmitter<void>();

  @ViewChild('videoEl') videoEl!: ElementRef<HTMLVideoElement>;
  @ViewChild('canvasEl') canvasEl!: ElementRef<HTMLCanvasElement>;

  private assessments = inject(AssessmentsService);
  private destroy$ = new Subject<void>();
  private mediaStream: MediaStream|null = null;
  private screenStream: MediaStream|null = null;

  violations: ViolationEvent[] = [];
  currentAlert: ViolationEvent|null = null;
  minimized = false;
  webcamActive = false;
  webcamError = false;
  screenShareActive = false;
  tabHidden = false;

  get warnThreshold(): number { return Math.floor(this.config.max_violations_before_terminate * 0.6); }
  get showTerminationWarning(): boolean {
    return this.violations.length >= this.config.max_violations_before_terminate - 3 && this.violations.length < this.config.max_violations_before_terminate;
  }

  ngOnInit(): void {
    // Open the proctoring session, then report the pre-flight as passed to move
    // it to 'active' -- the API refuses violations and snapshots until then
    // ('session_not_active'). The learner already cleared the lobby's checks to
    // reach the player, so passed:true is correct. Best-effort: if proctoring is
    // not enabled for the tenant, both calls simply do nothing.
    if (this.attemptId) {
      this.assessments.openProctoringSession(this.attemptId, {
        userAgent: navigator.userAgent,
        screenWidth: screen.width,
        screenHeight: screen.height,
      }).pipe(
        switchMap(() => this.assessments.proctoringCheck(this.attemptId, {
          passed: true,
          data: { userAgent: navigator.userAgent, screenWidth: screen.width, screenHeight: screen.height },
        })),
        catchError(() => of(null)),
        takeUntil(this.destroy$),
      ).subscribe();
    }
    if (this.config.webcam_enabled) this.initWebcam();
    if (this.config.screen_share) this.initScreenShare();
    this.attachEventListeners();
    if (this.config.webcam_enabled && this.config.webcam_snapshot_interval > 0) this.startSnapshotInterval();
  }

  ngOnDestroy(): void {
    this.destroy$.next(); this.destroy$.complete();
    this.mediaStream?.getTracks().forEach(t => t.stop());
    this.screenStream?.getTracks().forEach(t => t.stop());
    this.detachEventListeners();
  }

  // === Webcam ===
  async initWebcam(): Promise<void> {
    try {
      this.mediaStream = await navigator.mediaDevices.getUserMedia({ video: { width: 640, height: 480, facingMode: 'user' }, audio: false });
      this.webcamActive = true;
      setTimeout(() => {
       // if (this.videoEl?.nativeElement) { this.videoEl.nativeElement.srcObject = this.mediaStream; }
        if (this.videoEl?.nativeElement && this.mediaStream) { this.videoEl.nativeElement.srcObject = this.mediaStream; }

      }, 100);
    } catch {
      this.webcamError = true;
      this.addViolation('webcam_error', 'medium', 'Webcam access failed or denied.');
    }
  }

  // === Screen Share ===
  async initScreenShare(): Promise<void> {
    try {
      this.screenStream = await (navigator.mediaDevices as any).getDisplayMedia({ video: true });
      this.screenShareActive = true;
      this.screenStream!.getVideoTracks()[0].onended = () => {
        this.screenShareActive = false;
        this.addViolation('screen_share_ended', 'high', 'Screen sharing was stopped.');
      };
    } catch {
      this.addViolation('screen_share_denied', 'medium', 'Screen sharing was not granted.');
    }
  }

  // === Snapshots ===
  startSnapshotInterval(): void {
    interval(this.config.webcam_snapshot_interval * 1000).pipe(takeUntil(this.destroy$)).subscribe(() => {
      if (this.webcamActive) this.captureSnapshot();
    });
  }

  captureSnapshot(isViolation = false): void {
    if (!this.videoEl?.nativeElement || !this.canvasEl?.nativeElement) return;
    const video = this.videoEl.nativeElement;
    const canvas = this.canvasEl.nativeElement;
    canvas.width = video.videoWidth || 640;
    canvas.height = video.videoHeight || 480;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;
    ctx.drawImage(video, 0, 0);
    const dataUrl = canvas.toDataURL('image/jpeg', 0.7);
    // The new snapshots route takes an already-hosted imageUrl rather than raw
    // bytes; there is no proctoring upload endpoint, so the captured frame is
    // sent inline as a data URL. Best-effort -- dropped if the session is not
    // open or proctoring is not enabled.
    this.assessments.recordSnapshot(this.attemptId, {
      imageUrl: dataUrl,
      kind: isViolation ? 'violation' : 'periodic',
      flagged: isViolation,
    }).pipe(catchError(() => of(null)), takeUntil(this.destroy$)).subscribe();
  }

  // === Event Listeners ===
  private boundHandlers: {[key: string]: EventListener} = {};

  attachEventListeners(): void {
    if (this.config.tab_switch_detection) {
      this.boundHandlers['visibilitychange'] = () => this.onVisibilityChange();
      document.addEventListener('visibilitychange', this.boundHandlers['visibilitychange']);
      this.boundHandlers['blur'] = () => this.onWindowBlur();
      window.addEventListener('blur', this.boundHandlers['blur']);
      this.boundHandlers['focus'] = () => this.onWindowFocus();
      window.addEventListener('focus', this.boundHandlers['focus']);
    }
    if (this.config.copy_paste_detection) {
      this.boundHandlers['copy'] = (e: Event) => { e.preventDefault(); this.addViolation('copy_attempt', 'medium', 'Copy action detected.'); };
      this.boundHandlers['paste'] = (e: Event) => { e.preventDefault(); this.addViolation('paste_attempt', 'medium', 'Paste action detected.'); };
      this.boundHandlers['cut'] = (e: Event) => { e.preventDefault(); this.addViolation('cut_attempt', 'low', 'Cut action detected.'); };
      document.addEventListener('copy', this.boundHandlers['copy']);
      document.addEventListener('paste', this.boundHandlers['paste']);
      document.addEventListener('cut', this.boundHandlers['cut']);
    }
    if (this.config.right_click_disabled) {
      this.boundHandlers['contextmenu'] = (e: Event) => { e.preventDefault(); this.addViolation('right_click', 'low', 'Right-click detected.'); };
      document.addEventListener('contextmenu', this.boundHandlers['contextmenu']);
    }
    if (this.config.keyboard_shortcuts_disabled) {
      this.boundHandlers['keydown'] = (e: Event) => this.onKeyDown(e as KeyboardEvent);
      document.addEventListener('keydown', this.boundHandlers['keydown']);
    }
    if (this.config.browser_resize_detection) {
      this.boundHandlers['resize'] = () => this.onResize();
      window.addEventListener('resize', this.boundHandlers['resize']);
    }
  }

  detachEventListeners(): void {
    Object.entries(this.boundHandlers).forEach(([event, handler]) => {
      if (['blur', 'focus', 'resize'].includes(event)) window.removeEventListener(event, handler);
      else document.removeEventListener(event, handler);
    });
  }

  onVisibilityChange(): void {
    if (document.hidden) {
      this.tabHidden = true;
      this.addViolation('tab_switch', 'high', 'Browser tab was switched or minimized.');
    } else { this.tabHidden = false; }
  }

  onWindowBlur(): void {
    this.tabHidden = true;
    this.addViolation('window_blur', 'medium', 'Assessment window lost focus.');
  }

  onWindowFocus(): void { this.tabHidden = false; }

  onKeyDown(e: KeyboardEvent): void {
    const blocked = [
      e.ctrlKey && e.key === 'c', e.ctrlKey && e.key === 'v', e.ctrlKey && e.key === 'a',
      e.ctrlKey && e.key === 'p', e.ctrlKey && e.key === 's', e.ctrlKey && e.shiftKey && e.key === 'I',
      e.key === 'F12', e.key === 'PrintScreen', e.altKey && e.key === 'Tab',
      e.ctrlKey && e.key === 'Tab', e.metaKey && e.key === 'Tab'
    ];
    if (blocked.some(b => b)) {
      e.preventDefault();
      this.addViolation('keyboard_shortcut', 'medium', `Blocked shortcut: ${e.ctrlKey ? 'Ctrl+' : ''}${e.altKey ? 'Alt+' : ''}${e.key}`);
    }
  }

  onResize(): void {
    const w = window.innerWidth; const h = window.innerHeight;
    const sw = screen.width; const sh = screen.height;
    if (w < sw * 0.7 || h < sh * 0.7) {
      this.addViolation('browser_resize', 'medium', 'Browser window significantly resized.');
    }
  }

  // === Violation Management ===
  addViolation(type: string, severity: ViolationEvent['severity'], description: string): void {
    const violation: ViolationEvent = { type, severity, description, timestamp: new Date() };
    this.violations.push(violation);
    this.onViolation.emit(violation);

    // Show alert
    this.currentAlert = violation;
    setTimeout(() => { if (this.currentAlert === violation) this.currentAlert = null; }, severity === 'critical' ? 8000 : 4000);

    // Snapshot on violation
    if (this.config.snapshot_on_violation && this.webcamActive) this.captureSnapshot(true);

    // Report to the server. The new API assigns the severity from the type and
    // stamps its own time, so only the type and a description are sent. If the
    // sitting has ended server-side, honour that as a termination too.
    this.assessments.recordViolation(this.attemptId, { type, description })
      .pipe(catchError(() => of(null)), takeUntil(this.destroy$)).subscribe((res) => {
        const r = res as { action?: string; sittingEnded?: boolean } | null;
        if (r && (r.sittingEnded || r.action === 'terminated')) this.onTerminate.emit();
      });

    // Auto-terminate check
    if (this.violations.length >= this.config.max_violations_before_terminate) {
      this.addViolation('auto_terminate', 'critical', 'Maximum violations exceeded. Assessment will be auto-submitted.');
      setTimeout(() => this.onTerminate.emit(), 2000);
    }
  }

  dismissAlert(): void { this.currentAlert = null; }
}
