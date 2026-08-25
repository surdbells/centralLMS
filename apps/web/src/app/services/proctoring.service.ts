import { decodeSessionBlob } from '../core/utils/session-blob';
// proctoring.service.ts
// Angular Service for Proctoring
// Multi-Tenant LMS - Proctored Assessment Module

import { Injectable, NgZone } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, Subject, BehaviorSubject, map, takeUntil, fromEvent } from 'rxjs';
import {GlobalComponent} from '../global-component';

// ==========================================
// INTERFACES
// ==========================================

export type ProctoringLevel = 'none' | 'basic' | 'standard' | 'advanced';
export type SessionStatus = 'pending_check' | 'system_check' | 'active' | 'paused' | 'completed' | 'terminated';
export type ViolationType =
  | 'tab_switch' | 'window_blur' | 'fullscreen_exit' | 'copy_attempt' | 'paste_attempt'
  | 'right_click' | 'print_screen' | 'dev_tools_open' | 'browser_resize'
  | 'no_face_detected' | 'multiple_faces' | 'face_not_centered' | 'face_too_far'
  | 'face_too_close' | 'face_obscured' | 'different_person'
  | 'voice_detected' | 'multiple_voices' | 'excessive_noise'
  | 'phone_detected' | 'book_detected' | 'another_screen' | 'suspicious_object'
  | 'looking_away' | 'suspicious_movement' | 'idle_timeout' | 'rapid_answers'
  | 'connection_lost' | 'vpn_detected' | 'proxy_detected'
  | 'manual_flag' | 'ai_anomaly' | 'other';
export type ViolationSeverity = 'low' | 'medium' | 'high' | 'critical';
export type ReviewDecision = 'approved' | 'suspicious' | 'rejected';
export type ReviewStatus = 'pending' | 'valid' | 'false_positive' | 'inconclusive';

export interface ProctoringSettings {
  setting_id: number;
  assessment_id: number;
  proctoring_level: ProctoringLevel;
  prevent_tab_switch: boolean;
  prevent_copy_paste: boolean;
  prevent_right_click: boolean;
  prevent_print_screen: boolean;
  require_fullscreen: boolean;
  detect_dev_tools: boolean;
  max_tab_switches: number;
  tab_switch_warning: boolean;
  window_blur_detection: boolean;
  require_webcam: boolean;
  webcam_check_interval_seconds: number;
  face_detection_enabled: boolean;
  multiple_face_detection: boolean;
  no_face_detection: boolean;
  face_movement_threshold: number;
  capture_snapshots: boolean;
  snapshot_interval_seconds: number;
  snapshot_on_violation: boolean;
  snapshot_quality: number;
  require_microphone: boolean;
  audio_detection_enabled: boolean;
  audio_threshold_db: number;
  record_screen: boolean;
  record_webcam: boolean;
  record_audio: boolean;
  max_recording_size_mb: number;
  require_id_verification: boolean;
  id_verification_type: 'photo' | 'live' | 'document';
  face_match_threshold: number;
  require_room_scan: boolean;
  room_scan_duration_seconds: number;
  ai_behavior_analysis: boolean;
  ai_anomaly_detection: boolean;
  ai_gaze_tracking: boolean;
  violation_threshold: number;
  auto_submit_on_violations: boolean;
  auto_submit_violation_count: number;
  pause_on_violation: boolean;
  notify_admin_on_violation: boolean;
  notify_threshold: number;
  send_violation_report: boolean;
  min_browser_version: { [key: string]: number } | null;
  allowed_browsers: string[] | null;
  require_desktop: boolean;
  min_screen_width: number;
  min_screen_height: number;
  require_stable_connection: boolean;
  min_bandwidth_mbps: number;
}

export interface ProctoringPreset {
  preset_id: number;
  name: string;
  description: string | null;
  proctoring_level: ProctoringLevel;
  settings: Partial<ProctoringSettings>;
  is_default: boolean;
}

export interface ProctoringSession {
  session_id: number;
  attempt_id: number;
  status: SessionStatus;
  system_check_passed: boolean | null;
  system_check_at: string | null;
  system_check_data: SystemCheckData | null;
  id_verified: boolean | null;
  face_match_score: number | null;
  room_scan_completed: boolean | null;
  started_at: string | null;
  ended_at: string | null;
  total_snapshots: number;
  total_violations: number;
  violation_score: number;
  trust_score: number;
  face_detected_percentage: number | null;
  multiple_faces_detected_count: number;
  no_face_detected_count: number;
  tab_switch_count: number;
  window_blur_count: number;
  fullscreen_exit_count: number;
  flagged_for_review: boolean;
  flagged_reason: string | null;
  reviewed: boolean;
  review_decision: ReviewDecision | null;
  first_name?: string;
  last_name?: string;
  email?: string;
}

export interface SystemCheckData {
  browser_name: string;
  browser_version: string;
  browser_supported: boolean;
  os_name: string;
  os_version: string;
  device_type: string;
  screen_width: number;
  screen_height: number;
  screen_sufficient: boolean;
  camera_permission: 'granted' | 'denied' | 'prompt' | 'not_required';
  camera_working: boolean | null;
  microphone_permission: 'granted' | 'denied' | 'prompt' | 'not_required';
  microphone_working: boolean | null;
  screen_share_permission: 'granted' | 'denied' | 'prompt' | 'not_required';
  connection_type: string;
  downlink_mbps: number;
  network_sufficient: boolean;
  webcam_available: boolean | null;
  webcam_resolution: string | null;
  all_checks_passed: boolean;
  failed_checks: string[];
  warnings: string[];
}

export interface Violation {
  violation_id: number;
  session_id: number;
  attempt_id: number;
  violation_type: ViolationType;
  severity: ViolationSeverity;
  violation_score: number;
  description: string | null;
  question_id: number | null;
  snapshot_path: string | null;
  event_data: any | null;
  action_taken: string;
  user_acknowledged: boolean;
  reviewed: boolean;
  review_status: ReviewStatus;
  timestamp: string;
}

export interface Snapshot {
  snapshot_id: number;
  session_id: number;
  attempt_id: number;
  snapshot_type: 'periodic' | 'violation' | 'question_start' | 'question_submit' | 'manual';
  image_path: string;
  thumbnail_path: string | null;
  question_id: number | null;
  time_elapsed_seconds: number;
  face_detected: boolean | null;
  face_count: number | null;
  face_confidence: number | null;
  face_position: { x: number; y: number; width: number; height: number } | null;
  objects_detected: string[] | null;
  flagged: boolean;
  captured_at: string;
}

export interface ProctoringStats {
  total_sessions: number;
  completed_sessions: number;
  flagged_sessions: number;
  reviewed_sessions: number;
  pending_review: number;
  avg_trust_score: number | null;
  avg_violations: number;
  total_violations: number;
  total_snapshots: number;
  violation_breakdown: { [key: string]: number };
}

export interface ViolationEvent {
  type: ViolationType;
  severity: ViolationSeverity;
  description?: string;
  data?: any;
}

export interface ApiResponse<T> {
  response_code: number;
  status: 'success' | 'error';
  data: T;
  message: string;
}

// ==========================================
// SERVICE
// ==========================================

@Injectable({
  providedIn: 'root'
})
export class ProctoringService {
  private apiUrl = GlobalComponent.proctoring;

  private monitoringActive = false;
  private stopMonitoring$ = new Subject<void>();
  private trustScore$ = new BehaviorSubject<number>(100);
  private violationCount$ = new BehaviorSubject<number>(0);
  private currentSettings: ProctoringSettings | null = null;
  private videoStream: MediaStream | null = null;
  private videoElement: HTMLVideoElement | null = null;

  constructor(private http: HttpClient, private ngZone: NgZone) {}

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

  // ==========================================
  // API METHODS
  // ==========================================

  getSettings(assessment_id: number): Observable<ProctoringSettings | null> {
    const payload = { ...this.getAuthPayload(), action: 'get-settings', assessment_id };
    return this.http.post<ApiResponse<ProctoringSettings>>(this.apiUrl, payload).pipe(map(res => res.data));
  }

  saveSettings(assessment_id: number, settings: Partial<ProctoringSettings>): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'save-settings', assessment_id, ...settings };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload).pipe(map(() => void 0));
  }

  applyPreset(assessment_id: number, preset_id: number): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'apply-preset', assessment_id, preset_id };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload).pipe(map(() => void 0));
  }

  getPresets(): Observable<ProctoringPreset[]> {
    const payload = { ...this.getAuthPayload(), action: 'get-presets' };
    return this.http.post<ApiResponse<ProctoringPreset[]>>(this.apiUrl, payload).pipe(map(res => res.data));
  }

  createSession(attempt_id: number): Observable<{ session: ProctoringSession; is_new: boolean }> {
    const payload = { ...this.getAuthPayload(), action: 'create-session', attempt_id };
    return this.http.post<ApiResponse<{ session: ProctoringSession; is_new: boolean }>>(this.apiUrl, payload)
      .pipe(map(res => res.data));
  }

  getSession(attempt_id: number): Observable<ProctoringSession> {
    const payload = { ...this.getAuthPayload(), action: 'get-session', attempt_id };
    return this.http.post<ApiResponse<ProctoringSession>>(this.apiUrl, payload).pipe(map(res => res.data));
  }

  updateSessionStatus(session_id: number, status: SessionStatus): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'update-session-status', session_id, status };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload).pipe(map(() => void 0));
  }

  saveSystemCheck(session_id: number, checkData: Partial<SystemCheckData>): Observable<{ passed: boolean }> {
    const payload = { ...this.getAuthPayload(), action: 'save-system-check', session_id, ...checkData };
    return this.http.post<ApiResponse<{ passed: boolean }>>(this.apiUrl, payload).pipe(map(res => res.data));
  }

  logViolation(session_id: number, attempt_id: number, violation: ViolationEvent): Observable<{
    violation_id: number; trust_score: number; total_violations: number;
  }> {
    const payload = {
      ...this.getAuthPayload(), action: 'log-violation', session_id, attempt_id,
      violation_type: violation.type, severity: violation.severity,
      description: violation.description, event_data: violation.data
    };
    return this.http.post<ApiResponse<{ violation_id: number; trust_score: number; total_violations: number; }>>(this.apiUrl, payload)
      .pipe(map(res => {
        this.trustScore$.next(res.data.trust_score);
        this.violationCount$.next(res.data.total_violations);
        return res.data;
      }));
  }

  getViolations(attempt_id: number): Observable<Violation[]> {
    const payload = { ...this.getAuthPayload(), action: 'get-violations', attempt_id };
    return this.http.post<ApiResponse<Violation[]>>(this.apiUrl, payload).pipe(map(res => res.data));
  }

  acknowledgeViolation(violation_id: number): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'acknowledge-violation', violation_id };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload).pipe(map(() => void 0));
  }

  saveSnapshot(session_id: number, attempt_id: number, data: Partial<Snapshot>): Observable<{ snapshot_id: number }> {
    const payload = { ...this.getAuthPayload(), action: 'save-snapshot', session_id, attempt_id, ...data };
    return this.http.post<ApiResponse<{ snapshot_id: number }>>(this.apiUrl, payload).pipe(map(res => res.data));
  }

  getSnapshots(attempt_id: number, type?: string): Observable<Snapshot[]> {
    const payload = { ...this.getAuthPayload(), action: 'get-snapshots', attempt_id, type };
    return this.http.post<ApiResponse<Snapshot[]>>(this.apiUrl, payload).pipe(map(res => res.data));
  }

  getSessionsForReview(filters: { flagged_only?: boolean; reviewed?: boolean; assessment_id?: number; page?: number; limit?: number; } = {}): Observable<ProctoringSession[]> {
    const payload = { ...this.getAuthPayload(), action: 'get-sessions-review', ...filters };
    return this.http.post<ApiResponse<ProctoringSession[]>>(this.apiUrl, payload).pipe(map(res => res.data));
  }

  reviewSession(session_id: number, decision: ReviewDecision, notes?: string): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'review-session', session_id, decision, notes };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload).pipe(map(() => void 0));
  }

  reviewViolation(violation_id: number, status: ReviewStatus, notes?: string): Observable<void> {
    const payload = { ...this.getAuthPayload(), action: 'review-violation', violation_id, status, notes };
    return this.http.post<ApiResponse<void>>(this.apiUrl, payload).pipe(map(() => void 0));
  }

  getProctoringStats(assessment_id: number): Observable<ProctoringStats> {
    const payload = { ...this.getAuthPayload(), action: 'get-proctoring-stats', assessment_id };
    return this.http.post<ApiResponse<ProctoringStats>>(this.apiUrl, payload).pipe(map(res => res.data));
  }

  // ==========================================
  // BROWSER MONITORING
  // ==========================================

  startMonitoring(session_id: number, attempt_id: number, settings: ProctoringSettings): void {
    if (this.monitoringActive) return;
    this.monitoringActive = true;
    this.currentSettings = settings;

    this.ngZone.runOutsideAngular(() => {
      if (settings.window_blur_detection) {
        fromEvent(document, 'visibilitychange').pipe(takeUntil(this.stopMonitoring$)).subscribe(() => {
          if (document.hidden) {
            this.handleViolation(session_id, attempt_id, { type: 'tab_switch', severity: 'medium', description: 'Tab switch detected' });
          }
        });
        fromEvent(window, 'blur').pipe(takeUntil(this.stopMonitoring$)).subscribe(() => {
          this.handleViolation(session_id, attempt_id, { type: 'window_blur', severity: 'low', description: 'Window lost focus' });
        });
      }

      if (settings.require_fullscreen) {
        fromEvent(document, 'fullscreenchange').pipe(takeUntil(this.stopMonitoring$)).subscribe(() => {
          if (!document.fullscreenElement) {
            this.handleViolation(session_id, attempt_id, { type: 'fullscreen_exit', severity: 'medium', description: 'Exited fullscreen' });
          }
        });
      }

      if (settings.prevent_copy_paste) {
        fromEvent(document, 'copy').pipe(takeUntil(this.stopMonitoring$)).subscribe((e: Event) => {
          e.preventDefault();
          this.handleViolation(session_id, attempt_id, { type: 'copy_attempt', severity: 'medium', description: 'Copy blocked' });
        });
        fromEvent(document, 'paste').pipe(takeUntil(this.stopMonitoring$)).subscribe((e: Event) => {
          e.preventDefault();
          this.handleViolation(session_id, attempt_id, { type: 'paste_attempt', severity: 'medium', description: 'Paste blocked' });
        });
      }

      if (settings.prevent_right_click) {
        fromEvent(document, 'contextmenu').pipe(takeUntil(this.stopMonitoring$)).subscribe((e: Event) => {
          e.preventDefault();
          this.handleViolation(session_id, attempt_id, { type: 'right_click', severity: 'low', description: 'Right-click blocked' });
        });
      }

      if (settings.prevent_print_screen || settings.detect_dev_tools) {
        fromEvent<KeyboardEvent>(document, 'keydown').pipe(takeUntil(this.stopMonitoring$)).subscribe((e: KeyboardEvent) => {
          if (e.key === 'PrintScreen' && settings.prevent_print_screen) {
            e.preventDefault();
            this.handleViolation(session_id, attempt_id, { type: 'print_screen', severity: 'high', description: 'Print screen blocked' });
          }
          if ((e.key === 'F12' || (e.ctrlKey && e.shiftKey && e.key === 'I')) && settings.detect_dev_tools) {
            e.preventDefault();
            this.handleViolation(session_id, attempt_id, { type: 'dev_tools_open', severity: 'high', description: 'Dev tools access blocked' });
          }
        });
      }
    });
  }

  stopMonitoring(): void {
    this.monitoringActive = false;
    this.stopMonitoring$.next();
    this.stopWebcam();
  }

  private handleViolation(session_id: number, attempt_id: number, violation: ViolationEvent): void {
    this.ngZone.run(() => {
      this.logViolation(session_id, attempt_id, violation).subscribe({
        error: (err) => console.error('Failed to log violation:', err)
      });
    });
  }

  // ==========================================
  // WEBCAM
  // ==========================================

  async initializeWebcam(videoElement: HTMLVideoElement): Promise<boolean> {
    try {
      this.videoStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: 640, height: 480 }, audio: false });
      videoElement.srcObject = this.videoStream;
      this.videoElement = videoElement;
      return true;
    } catch (error) {
      console.error('Webcam init failed:', error);
      return false;
    }
  }

  stopWebcam(): void {
    if (this.videoStream) {
      this.videoStream.getTracks().forEach(track => track.stop());
      this.videoStream = null;
    }
    if (this.videoElement) {
      this.videoElement.srcObject = null;
      this.videoElement = null;
    }
  }

  captureSnapshot(): string | null {
    if (!this.videoElement) return null;
    const canvas = document.createElement('canvas');
    canvas.width = this.videoElement.videoWidth;
    canvas.height = this.videoElement.videoHeight;
    const ctx = canvas.getContext('2d');
    if (!ctx) return null;
    ctx.drawImage(this.videoElement, 0, 0);
    return canvas.toDataURL('image/jpeg', 0.8);
  }

  // ==========================================
  // SYSTEM CHECK
  // ==========================================

  async performSystemCheck(settings: ProctoringSettings): Promise<SystemCheckData> {
    const checkData: SystemCheckData = {
      browser_name: this.getBrowserName(),
      browser_version: this.getBrowserVersion(),
      browser_supported: true,
      os_name: this.getOSName(),
      os_version: '',
      device_type: this.getDeviceType(),
      screen_width: window.screen.width,
      screen_height: window.screen.height,
      screen_sufficient: window.screen.width >= settings.min_screen_width && window.screen.height >= settings.min_screen_height,
      camera_permission: 'not_required',
      camera_working: null,
      microphone_permission: 'not_required',
      microphone_working: null,
      screen_share_permission: 'not_required',
      connection_type: (navigator as any).connection?.effectiveType || 'unknown',
      downlink_mbps: (navigator as any).connection?.downlink || 0,
      network_sufficient: true,
      webcam_available: null,
      webcam_resolution: null,
      all_checks_passed: true,
      failed_checks: [],
      warnings: []
    };

    if (settings.allowed_browsers?.length) {
      checkData.browser_supported = settings.allowed_browsers.includes(checkData.browser_name.toLowerCase());
      if (!checkData.browser_supported) { checkData.failed_checks.push('browser'); checkData.all_checks_passed = false; }
    }
    if (!checkData.screen_sufficient) { checkData.failed_checks.push('screen_size'); checkData.all_checks_passed = false; }
    if (settings.require_desktop && checkData.device_type !== 'desktop') { checkData.failed_checks.push('device_type'); checkData.all_checks_passed = false; }

    if (settings.require_webcam) {
      try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        checkData.webcam_available = devices.some(d => d.kind === 'videoinput');
        if (checkData.webcam_available) {
          const stream = await navigator.mediaDevices.getUserMedia({ video: true });
          checkData.camera_permission = 'granted';
          checkData.camera_working = true;
          stream.getTracks().forEach(t => t.stop());
        } else { checkData.failed_checks.push('camera'); checkData.all_checks_passed = false; }
      } catch { checkData.camera_permission = 'denied'; checkData.failed_checks.push('camera'); checkData.all_checks_passed = false; }
    }

    if (settings.require_microphone) {
      try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        checkData.microphone_permission = 'granted';
        checkData.microphone_working = true;
        stream.getTracks().forEach(t => t.stop());
      } catch { checkData.microphone_permission = 'denied'; checkData.failed_checks.push('microphone'); checkData.all_checks_passed = false; }
    }

    if (settings.require_stable_connection) {
      checkData.network_sufficient = checkData.downlink_mbps >= settings.min_bandwidth_mbps;
      if (!checkData.network_sufficient) checkData.warnings.push('low_bandwidth');
    }

    return checkData;
  }

  // ==========================================
  // FULLSCREEN
  // ==========================================

  async requestFullscreen(): Promise<boolean> {
    try { await document.documentElement.requestFullscreen(); return true; }
    catch { return false; }
  }

  async exitFullscreen(): Promise<void> {
    if (document.fullscreenElement) await document.exitFullscreen();
  }

  isFullscreen(): boolean { return !!document.fullscreenElement; }

  // ==========================================
  // UTILITIES
  // ==========================================

  getBrowserName(): string {
    const ua = navigator.userAgent;
    if (ua.includes('Firefox')) return 'Firefox';
    if (ua.includes('Chrome')) return 'Chrome';
    if (ua.includes('Safari')) return 'Safari';
    if (ua.includes('Edge')) return 'Edge';
    return 'Unknown';
  }

  getBrowserVersion(): string {
    const match = navigator.userAgent.match(/(Chrome|Firefox|Safari|Edge)\/(\d+)/);
    return match ? match[2] : '';
  }

  getOSName(): string {
    const ua = navigator.userAgent;
    if (ua.includes('Windows')) return 'Windows';
    if (ua.includes('Mac')) return 'macOS';
    if (ua.includes('Linux')) return 'Linux';
    if (ua.includes('Android')) return 'Android';
    if (ua.includes('iOS')) return 'iOS';
    return 'Unknown';
  }

  getDeviceType(): string {
    const ua = navigator.userAgent;
    if (/Mobi|Android/i.test(ua)) return 'mobile';
    if (/Tablet|iPad/i.test(ua)) return 'tablet';
    return 'desktop';
  }

  getViolationTypeLabel(type: ViolationType): string {
    const labels: Record<string, string> = {
      'tab_switch': 'Tab Switch', 'window_blur': 'Window Lost Focus', 'fullscreen_exit': 'Fullscreen Exit',
      'copy_attempt': 'Copy Attempt', 'paste_attempt': 'Paste Attempt', 'right_click': 'Right Click',
      'print_screen': 'Print Screen', 'dev_tools_open': 'Dev Tools Access', 'no_face_detected': 'No Face Detected',
      'multiple_faces': 'Multiple Faces', 'looking_away': 'Looking Away', 'phone_detected': 'Phone Detected'
    };
    return labels[type] || type.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
  }

  getSeverityBadgeClass(severity: ViolationSeverity): string {
    return { 'low': 'bg-info', 'medium': 'bg-warning', 'high': 'bg-danger', 'critical': 'bg-dark' }[severity] || 'bg-secondary';
  }

  getTrustScoreClass(score: number): string {
    if (score >= 80) return 'text-success';
    if (score >= 60) return 'text-warning';
    return 'text-danger';
  }

  getTrustScore$(): Observable<number> { return this.trustScore$.asObservable(); }
  getViolationCount$(): Observable<number> { return this.violationCount$.asObservable(); }
}
