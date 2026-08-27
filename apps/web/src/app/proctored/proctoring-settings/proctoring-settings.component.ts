// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 12
// Admin: Proctoring Settings Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { HttpClient } from '@angular/common/http';
import { Subject, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { GlobalComponent } from '../../global-component';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';

// ==========================================
// INTERFACES
// ==========================================

interface ApiResponse<T> {
  code: number;
  status: string;
  data: T;
  message: string;
}

interface ProctoringPreset {
  preset_id: number;
  name: string;
  level: string;
  description: string;
  settings: ProctoringConfig;
}

interface ProctoringConfig {
  // General
  proctoring_level: string;
  require_system_check: boolean;
  allow_practice_run: boolean;

  // Browser Lockdown
  lock_browser: boolean;
  block_new_tabs: boolean;
  block_copy_paste: boolean;
  block_right_click: boolean;
  block_print_screen: boolean;
  block_keyboard_shortcuts: boolean;
  require_fullscreen: boolean;
  detect_fullscreen_exit: boolean;
  detect_tab_switch: boolean;
  detect_window_blur: boolean;

  // Webcam
  require_webcam: boolean;
  require_face_detection: boolean;
  snapshot_interval_seconds: number;
  detect_multiple_faces: boolean;
  detect_no_face: boolean;
  face_detection_sensitivity: string;

  // Screen
  require_screen_share: boolean;
  record_screen: boolean;
  screen_recording_quality: string;

  // ID Verification
  require_id_verification: boolean;
  require_photo_id: boolean;
  require_selfie: boolean;

  // Violations
  max_violations_before_flag: number;
  max_violations_before_terminate: number;
  auto_flag_threshold: number;
  auto_terminate: boolean;

  // Notifications
  warn_on_violation: boolean;
  show_violation_count: boolean;
  notify_admin_on_flag: boolean;
  notify_admin_on_terminate: boolean;

  // Environment
  require_single_monitor: boolean;
  detect_virtual_machine: boolean;
  detect_remote_desktop: boolean;
  require_stable_connection: boolean;
  min_bandwidth_kbps: number;
}

@Component({
  selector: 'app-proctoring-settings',
  standalone: true,
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent],
  template: `
    <app-admintop></app-admintop>

    <div class="proctoring-settings-container">
      <!-- Header -->
      <div class="settings-header" *ngIf="!embedded">
        <div class="header-left">
          <h1 class="page-title">
            <tui-icon icon="@tui.shield"></tui-icon>
            Proctoring Settings
          </h1>
          <p class="page-subtitle">Configure proctoring rules for assessments</p>
        </div>
        <div class="header-actions">
          <button class="btn btn-secondary" (click)="resetToDefaults()">
            <tui-icon icon="@tui.refresh-cw"></tui-icon> Reset
          </button>
          <button class="btn btn-primary" (click)="saveSettings()" [disabled]="saving">
            <span class="spinner-border spinner-border-sm me-1" *ngIf="saving"></span>
            <tui-icon icon="@tui.save" *ngIf="!saving"></tui-icon> Save Settings
          </button>
        </div>
      </div>

      <div class="settings-layout" *ngIf="!loading">
        <!-- LEFT: Settings Panels -->
        <div class="settings-left">
          <!-- Preset Selection -->
          <div class="settings-card">
            <div class="card-header">
              <h5><tui-icon icon="@tui.zap"></tui-icon> Quick Presets</h5>
            </div>
            <div class="card-body">
              <div class="preset-grid">
                <button *ngFor="let p of presets" class="preset-btn"
                        [class.active]="activePreset === p.level"
                        (click)="applyPreset(p)">
                  <tui-icon [icon]="getPresetIcon(p.level)"></tui-icon>
                  <span class="preset-name">{{ p.name }}</span>
                  <span class="preset-desc">{{ p.description }}</span>
                </button>
              </div>
            </div>
          </div>

          <!-- Browser Lockdown -->
          <div class="settings-card">
            <div class="card-header" (click)="toggleSection('browser')">
              <h5><tui-icon icon="@tui.monitor"></tui-icon> Browser Lockdown</h5>
              <tui-icon [icon]="expandedSections['browser'] ? '@tui.chevron-up' : '@tui.chevron-down'"></tui-icon>
            </div>
            <div class="card-body" *ngIf="expandedSections['browser']">
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Lock Browser</span>
                  <span class="setting-desc">Restrict browser navigation and tabs</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.lock_browser">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Block New Tabs</span>
                  <span class="setting-desc">Prevent opening new browser tabs</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.block_new_tabs">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Block Copy/Paste</span>
                  <span class="setting-desc">Disable clipboard operations</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.block_copy_paste">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Block Right-Click</span>
                  <span class="setting-desc">Disable context menu</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.block_right_click">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Block Print Screen</span>
                  <span class="setting-desc">Prevent screenshot capture</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.block_print_screen">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Block Keyboard Shortcuts</span>
                  <span class="setting-desc">Disable Alt+Tab, Ctrl+W, etc.</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.block_keyboard_shortcuts">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Require Fullscreen</span>
                  <span class="setting-desc">Force fullscreen mode during assessment</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.require_fullscreen">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Detect Tab Switch</span>
                  <span class="setting-desc">Log when learner switches tabs</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.detect_tab_switch">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Detect Window Blur</span>
                  <span class="setting-desc">Log when assessment window loses focus</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.detect_window_blur">
                  <span class="toggle-slider"></span>
                </label>
              </div>
            </div>
          </div>

          <!-- Webcam & Face Detection -->
          <div class="settings-card">
            <div class="card-header" (click)="toggleSection('webcam')">
              <h5><tui-icon icon="@tui.camera"></tui-icon> Webcam & Face Detection</h5>
              <tui-icon [icon]="expandedSections['webcam'] ? '@tui.chevron-up' : '@tui.chevron-down'"></tui-icon>
            </div>
            <div class="card-body" *ngIf="expandedSections['webcam']">
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Require Webcam</span>
                  <span class="setting-desc">Learner must enable webcam</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.require_webcam">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Face Detection (AI)</span>
                  <span class="setting-desc">Use TensorFlow.js for face detection</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.require_face_detection" [disabled]="!config.require_webcam">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Detect Multiple Faces</span>
                  <span class="setting-desc">Flag when more than one face detected</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.detect_multiple_faces" [disabled]="!config.require_webcam">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Detect No Face</span>
                  <span class="setting-desc">Flag when no face detected</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.detect_no_face" [disabled]="!config.require_webcam">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row" *ngIf="config.require_webcam">
                <div class="setting-info">
                  <span class="setting-label">Snapshot Interval</span>
                  <span class="setting-desc">Seconds between webcam captures</span>
                </div>
                <select class="form-select form-select-sm setting-input" [(ngModel)]="config.snapshot_interval_seconds">
                  <option [ngValue]="15">Every 15s</option>
                  <option [ngValue]="30">Every 30s</option>
                  <option [ngValue]="60">Every 60s</option>
                  <option [ngValue]="120">Every 2 min</option>
                  <option [ngValue]="300">Every 5 min</option>
                </select>
              </div>
              <div class="setting-row" *ngIf="config.require_face_detection">
                <div class="setting-info">
                  <span class="setting-label">Detection Sensitivity</span>
                  <span class="setting-desc">How strict face detection should be</span>
                </div>
                <select class="form-select form-select-sm setting-input" [(ngModel)]="config.face_detection_sensitivity">
                  <option value="low">Low (lenient)</option>
                  <option value="medium">Medium</option>
                  <option value="high">High (strict)</option>
                </select>
              </div>
            </div>
          </div>

          <!-- Screen Recording -->
          <div class="settings-card">
            <div class="card-header" (click)="toggleSection('screen')">
              <h5><tui-icon icon="@tui.airplay"></tui-icon> Screen Monitoring</h5>
              <tui-icon [icon]="expandedSections['screen'] ? '@tui.chevron-up' : '@tui.chevron-down'"></tui-icon>
            </div>
            <div class="card-body" *ngIf="expandedSections['screen']">
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Require Screen Share</span>
                  <span class="setting-desc">Learner must share their screen</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.require_screen_share">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Record Screen</span>
                  <span class="setting-desc">Save screen recording for review</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.record_screen" [disabled]="!config.require_screen_share">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row" *ngIf="config.record_screen">
                <div class="setting-info">
                  <span class="setting-label">Recording Quality</span>
                  <span class="setting-desc">Higher quality = more storage</span>
                </div>
                <select class="form-select form-select-sm setting-input" [(ngModel)]="config.screen_recording_quality">
                  <option value="low">Low (480p)</option>
                  <option value="medium">Medium (720p)</option>
                  <option value="high">High (1080p)</option>
                </select>
              </div>
            </div>
          </div>

          <!-- ID Verification -->
          <div class="settings-card">
            <div class="card-header" (click)="toggleSection('id')">
              <h5><tui-icon icon="@tui.credit-card"></tui-icon> ID Verification</h5>
              <tui-icon [icon]="expandedSections['id'] ? '@tui.chevron-up' : '@tui.chevron-down'"></tui-icon>
            </div>
            <div class="card-body" *ngIf="expandedSections['id']">
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Require ID Verification</span>
                  <span class="setting-desc">Learner must verify identity before start</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.require_id_verification">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Require Photo ID</span>
                  <span class="setting-desc">Upload photo of ID document</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.require_photo_id" [disabled]="!config.require_id_verification">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Require Selfie</span>
                  <span class="setting-desc">Take live photo for comparison</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.require_selfie" [disabled]="!config.require_id_verification">
                  <span class="toggle-slider"></span>
                </label>
              </div>
            </div>
          </div>

          <!-- Violation Thresholds -->
          <div class="settings-card">
            <div class="card-header" (click)="toggleSection('violations')">
              <h5><tui-icon icon="@tui.alert-triangle"></tui-icon> Violation Thresholds</h5>
              <tui-icon [icon]="expandedSections['violations'] ? '@tui.chevron-up' : '@tui.chevron-down'"></tui-icon>
            </div>
            <div class="card-body" *ngIf="expandedSections['violations']">
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Flag After # Violations</span>
                  <span class="setting-desc">Flag session for review after this many violations</span>
                </div>
                <input type="number" class="form-control form-control-sm setting-input"
                       [(ngModel)]="config.max_violations_before_flag" min="1" max="50">
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Terminate After # Violations</span>
                  <span class="setting-desc">Auto-terminate assessment after this many</span>
                </div>
                <input type="number" class="form-control form-control-sm setting-input"
                       [(ngModel)]="config.max_violations_before_terminate" min="1" max="100">
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Trust Score Flag Threshold</span>
                  <span class="setting-desc">Flag when trust score drops below (%)</span>
                </div>
                <input type="number" class="form-control form-control-sm setting-input"
                       [(ngModel)]="config.auto_flag_threshold" min="0" max="100">
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Auto-Terminate</span>
                  <span class="setting-desc">Automatically end assessment on threshold breach</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.auto_terminate">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Warn Learner on Violation</span>
                  <span class="setting-desc">Show warning popup to learner</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.warn_on_violation">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Show Violation Count</span>
                  <span class="setting-desc">Display violation count to learner</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.show_violation_count">
                  <span class="toggle-slider"></span>
                </label>
              </div>
            </div>
          </div>

          <!-- Environment Checks -->
          <div class="settings-card">
            <div class="card-header" (click)="toggleSection('environment')">
              <h5><tui-icon icon="@tui.settings"></tui-icon> Environment Checks</h5>
              <tui-icon [icon]="expandedSections['environment'] ? '@tui.chevron-up' : '@tui.chevron-down'"></tui-icon>
            </div>
            <div class="card-body" *ngIf="expandedSections['environment']">
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Require System Check</span>
                  <span class="setting-desc">Run compatibility check before start</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.require_system_check">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Allow Practice Run</span>
                  <span class="setting-desc">Let learner test setup before actual exam</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.allow_practice_run">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Single Monitor Only</span>
                  <span class="setting-desc">Block multi-monitor setups</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.require_single_monitor">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Detect Virtual Machine</span>
                  <span class="setting-desc">Block VMs and emulators</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.detect_virtual_machine">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Detect Remote Desktop</span>
                  <span class="setting-desc">Block RDP and screen sharing tools</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.detect_remote_desktop">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Require Stable Connection</span>
                  <span class="setting-desc">Minimum internet speed required</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.require_stable_connection">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row" *ngIf="config.require_stable_connection">
                <div class="setting-info">
                  <span class="setting-label">Min Bandwidth (kbps)</span>
                  <span class="setting-desc">Minimum upload/download speed</span>
                </div>
                <input type="number" class="form-control form-control-sm setting-input"
                       [(ngModel)]="config.min_bandwidth_kbps" min="100" step="100">
              </div>
            </div>
          </div>

          <!-- Notifications -->
          <div class="settings-card">
            <div class="card-header" (click)="toggleSection('notifications')">
              <h5><tui-icon icon="@tui.bell"></tui-icon> Admin Notifications</h5>
              <tui-icon [icon]="expandedSections['notifications'] ? '@tui.chevron-up' : '@tui.chevron-down'"></tui-icon>
            </div>
            <div class="card-body" *ngIf="expandedSections['notifications']">
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Notify on Flag</span>
                  <span class="setting-desc">Email admin when session is flagged</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.notify_admin_on_flag">
                  <span class="toggle-slider"></span>
                </label>
              </div>
              <div class="setting-row">
                <div class="setting-info">
                  <span class="setting-label">Notify on Terminate</span>
                  <span class="setting-desc">Email admin when session is auto-terminated</span>
                </div>
                <label class="toggle-switch">
                  <input type="checkbox" [(ngModel)]="config.notify_admin_on_terminate">
                  <span class="toggle-slider"></span>
                </label>
              </div>
            </div>
          </div>
        </div>

        <!-- RIGHT: Summary Sidebar -->
        <div class="settings-right">
          <div class="settings-card summary-card sticky-top">
            <div class="card-header"><h5>Active Settings Summary</h5></div>
            <div class="card-body">
              <div class="summary-level">
                <span class="level-badge" [ngClass]="'level-' + config.proctoring_level">
                  <tui-icon [icon]="getPresetIcon(config.proctoring_level)"></tui-icon>
                  {{ config.proctoring_level | titlecase }}
                </span>
              </div>
              <div class="summary-section">
                <h6>Browser</h6>
                <div class="summary-tags">
                  <span class="tag" [class.enabled]="config.lock_browser">Lock Browser</span>
                  <span class="tag" [class.enabled]="config.block_copy_paste">No Copy/Paste</span>
                  <span class="tag" [class.enabled]="config.require_fullscreen">Fullscreen</span>
                  <span class="tag" [class.enabled]="config.detect_tab_switch">Tab Detection</span>
                </div>
              </div>
              <div class="summary-section">
                <h6>Monitoring</h6>
                <div class="summary-tags">
                  <span class="tag" [class.enabled]="config.require_webcam">Webcam</span>
                  <span class="tag" [class.enabled]="config.require_face_detection">Face AI</span>
                  <span class="tag" [class.enabled]="config.require_screen_share">Screen Share</span>
                  <span class="tag" [class.enabled]="config.record_screen">Recording</span>
                </div>
              </div>
              <div class="summary-section">
                <h6>Verification</h6>
                <div class="summary-tags">
                  <span class="tag" [class.enabled]="config.require_id_verification">ID Check</span>
                  <span class="tag" [class.enabled]="config.require_system_check">System Check</span>
                  <span class="tag" [class.enabled]="config.require_single_monitor">Single Monitor</span>
                </div>
              </div>
              <div class="summary-section">
                <h6>Thresholds</h6>
                <div class="summary-stats">
                  <div class="stat-row">
                    <span>Flag after</span>
                    <span class="fw-bold">{{ config.max_violations_before_flag }} violations</span>
                  </div>
                  <div class="stat-row">
                    <span>Terminate after</span>
                    <span class="fw-bold">{{ config.max_violations_before_terminate }} violations</span>
                  </div>
                  <div class="stat-row">
                    <span>Trust threshold</span>
                    <span class="fw-bold">{{ config.auto_flag_threshold }}%</span>
                  </div>
                </div>
              </div>

              <!-- Save (embedded mode) -->
              <div class="mt-3" *ngIf="embedded">
                <button class="btn btn-primary w-100" (click)="saveSettings()" [disabled]="saving">
                  <span class="spinner-border spinner-border-sm me-1" *ngIf="saving"></span>
                  Save Settings
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Loading -->
      <div class="loading-state" *ngIf="loading">
        <div class="spinner-border text-primary"></div>
        <p>Loading settings...</p>
      </div>
    </div>
  `,
  styles: [`
    .proctoring-settings-container { padding: 1.5rem; }
    .settings-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1.5rem; }
    .page-title { font-size: 1.5rem; font-weight: 700; display: flex; align-items: center; gap: 0.5rem; margin: 0; }
    .page-subtitle { color: #6c757d; font-size: 0.875rem; margin: 0.25rem 0 0; }

    .settings-layout { display: grid; grid-template-columns: 1fr 300px; gap: 1.25rem; }
    .settings-card { background: #fff; border: 1px solid #e9ecef; border-radius: 0.5rem; margin-bottom: 0.75rem; }
    .card-header { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 1rem;
      background: #f8f9fa; border-radius: 0.5rem 0.5rem 0 0; cursor: pointer; border-bottom: 1px solid #e9ecef; }
    .card-header h5 { margin: 0; font-size: 0.9375rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem; }
    .card-body { padding: 0.75rem 1rem; }

    /* Presets */
    .preset-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; }
    .preset-btn {
      display: flex; flex-direction: column; align-items: center; gap: 0.25rem; padding: 0.75rem 0.5rem;
      border: 2px solid #dee2e6; border-radius: 0.5rem; background: #fff; cursor: pointer; text-align: center;
      transition: all 0.2s;
    }
    .preset-btn:hover { border-color: #6c757d; }
    .preset-btn.active { border-color: #0d6efd; background: #e8f0fe; }
    .preset-name { font-size: 0.8125rem; font-weight: 600; }
    .preset-desc { font-size: 0.625rem; color: #6c757d; }

    /* Settings Rows */
    .setting-row { display: flex; justify-content: space-between; align-items: center; padding: 0.625rem 0;
      border-bottom: 1px solid #f0f0f0; }
    .setting-row:last-child { border-bottom: none; }
    .setting-info { flex: 1; }
    .setting-label { font-size: 0.875rem; font-weight: 500; display: block; }
    .setting-desc { font-size: 0.6875rem; color: #6c757d; }
    .setting-input { width: 130px !important; flex-shrink: 0; }

    /* Toggle */
    .toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .toggle-slider {
      position: absolute; cursor: pointer; inset: 0; background: #ccc; border-radius: 24px; transition: 0.3s;
    }
    .toggle-slider::before {
      content: ''; position: absolute; height: 18px; width: 18px; left: 3px; bottom: 3px;
      background: #fff; border-radius: 50%; transition: 0.3s;
    }
    .toggle-switch input:checked + .toggle-slider { background: #0d6efd; }
    .toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }
    .toggle-switch input:disabled + .toggle-slider { opacity: 0.5; }

    /* Summary */
    .sticky-top { position: sticky; top: 1rem; }
    .summary-level { text-align: center; margin-bottom: 1rem; }
    .level-badge { display: inline-flex; align-items: center; gap: 0.375rem; padding: 0.375rem 1rem;
      border-radius: 2rem; font-weight: 600; font-size: 0.875rem; }
    .level-none { background: #e2e3e5; color: #6c757d; }
    .level-basic { background: #cfe2ff; color: #084298; }
    .level-standard { background: #fff3cd; color: #664d03; }
    .level-advanced { background: #f8d7da; color: #842029; }
    .summary-section { margin-bottom: 0.75rem; }
    .summary-section h6 { font-size: 0.75rem; font-weight: 600; color: #6c757d; text-transform: uppercase; margin-bottom: 0.375rem; }
    .summary-tags { display: flex; flex-wrap: wrap; gap: 0.25rem; }
    .tag { font-size: 0.6875rem; padding: 0.125rem 0.5rem; border-radius: 1rem;
      background: #e9ecef; color: #6c757d; }
    .tag.enabled { background: #d4edda; color: #155724; }
    .summary-stats { font-size: 0.8125rem; }
    .stat-row { display: flex; justify-content: space-between; padding: 0.25rem 0; }

    .loading-state { text-align: center; padding: 3rem; }

    @media (max-width: 992px) {
      .settings-layout { grid-template-columns: 1fr; }
      .preset-grid { grid-template-columns: repeat(2, 1fr); }
    }
  `]
})
export class ProctoringSettingsComponent implements OnInit, OnDestroy {
  private http = inject(HttpClient);
  private destroy$ = new Subject<void>();

  @Input() assessmentId: number | null = null;
  @Input() embedded = false;
  @Output() settingsSaved = new EventEmitter<ProctoringConfig>();

  loading = false;
  saving = false;
  activePreset = '';
  presets: ProctoringPreset[] = [];

  expandedSections: Record<string, boolean> = {
    browser: true, webcam: true, screen: false, id: false,
    violations: true, environment: false, notifications: false
  };

  config: ProctoringConfig = this.getDefaultConfig();

  ngOnInit(): void {
    this.loadPresets();
    if (this.assessmentId) this.loadSettings();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // DATA
  // ==========================================

  loadPresets(): void {
    const fd = new FormData();
    fd.append('action', 'get_proctoring_presets');

    this.http.post<ApiResponse<ProctoringPreset[]>>(
      `${GlobalComponent.baseURL}/user/proctoring`, fd
    ).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        if (res.code === 1) {
          this.presets = res.data || [];
        } else {
          this.presets = this.getDefaultPresets();
        }
      },
      error: () => { this.presets = this.getDefaultPresets(); }
    });
  }

  loadSettings(): void {
    if (!this.assessmentId) return;
    this.loading = true;
    const fd = new FormData();
    fd.append('action', 'get_proctoring_settings');
    fd.append('assessment_id', this.assessmentId.toString());

    this.http.post<ApiResponse<ProctoringConfig>>(
      `${GlobalComponent.baseURL}/user/proctoring`, fd
    ).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        if (res.code === 1 && res.data) {
          this.config = { ...this.getDefaultConfig(), ...res.data };
          this.activePreset = this.config.proctoring_level;
        }
        this.loading = false;
      },
      error: () => { this.loading = false; }
    });
  }

  saveSettings(): void {
    this.saving = true;
    const fd = new FormData();
    fd.append('action', 'save_proctoring_settings');
    if (this.assessmentId) fd.append('assessment_id', this.assessmentId.toString());
    fd.append('settings', JSON.stringify(this.config));

    this.http.post<ApiResponse<any>>(
      `${GlobalComponent.baseURL}/user/proctoring`, fd
    ).pipe(takeUntil(this.destroy$)).subscribe({
      next: (res) => {
        this.saving = false;
        if (res.code === 1) this.settingsSaved.emit(this.config);
      },
      error: () => { this.saving = false; }
    });
  }

  // ==========================================
  // PRESETS
  // ==========================================

  applyPreset(preset: ProctoringPreset): void {
    this.config = { ...this.getDefaultConfig(), ...preset.settings, proctoring_level: preset.level };
    this.activePreset = preset.level;
  }

  resetToDefaults(): void {
    this.config = this.getDefaultConfig();
    this.activePreset = 'none';
  }

  // ==========================================
  // HELPERS
  // ==========================================

  toggleSection(key: string): void {
    this.expandedSections[key] = !this.expandedSections[key];
  }

  getPresetIcon(level: string): string {
    const icons: Record<string, string> = {
      none: '@tui.unlock', basic: '@tui.lock', standard: '@tui.shield', advanced: '@tui.shield-off'
    };
    return icons[level] || '@tui.shield';
  }

  getDefaultConfig(): ProctoringConfig {
    return {
      proctoring_level: 'none', require_system_check: false, allow_practice_run: false,
      lock_browser: false, block_new_tabs: false, block_copy_paste: false, block_right_click: false,
      block_print_screen: false, block_keyboard_shortcuts: false, require_fullscreen: false,
      detect_fullscreen_exit: false, detect_tab_switch: false, detect_window_blur: false,
      require_webcam: false, require_face_detection: false, snapshot_interval_seconds: 60,
      detect_multiple_faces: false, detect_no_face: false, face_detection_sensitivity: 'medium',
      require_screen_share: false, record_screen: false, screen_recording_quality: 'medium',
      require_id_verification: false, require_photo_id: false, require_selfie: false,
      max_violations_before_flag: 5, max_violations_before_terminate: 15, auto_flag_threshold: 70,
      auto_terminate: false, warn_on_violation: true, show_violation_count: false,
      notify_admin_on_flag: true, notify_admin_on_terminate: true,
      require_single_monitor: false, detect_virtual_machine: false, detect_remote_desktop: false,
      require_stable_connection: false, min_bandwidth_kbps: 500
    };
  }

  getDefaultPresets(): ProctoringPreset[] {
    return [
      { preset_id: 1, name: 'None', level: 'none', description: 'No monitoring', settings: this.getDefaultConfig() },
      { preset_id: 2, name: 'Basic', level: 'basic', description: 'Browser lockdown',
        settings: { ...this.getDefaultConfig(), proctoring_level: 'basic', lock_browser: true, block_new_tabs: true,
          block_copy_paste: true, block_right_click: true, require_fullscreen: true, detect_tab_switch: true,
          detect_window_blur: true, require_system_check: true, warn_on_violation: true } },
      { preset_id: 3, name: 'Standard', level: 'standard', description: 'Browser + Webcam',
        settings: { ...this.getDefaultConfig(), proctoring_level: 'standard', lock_browser: true, block_new_tabs: true,
          block_copy_paste: true, block_right_click: true, block_print_screen: true, require_fullscreen: true,
          detect_tab_switch: true, detect_window_blur: true, require_webcam: true, require_face_detection: true,
          snapshot_interval_seconds: 30, detect_multiple_faces: true, detect_no_face: true,
          require_system_check: true, warn_on_violation: true, notify_admin_on_flag: true } },
      { preset_id: 4, name: 'Advanced', level: 'advanced', description: 'Full monitoring',
        settings: { ...this.getDefaultConfig(), proctoring_level: 'advanced', lock_browser: true, block_new_tabs: true,
          block_copy_paste: true, block_right_click: true, block_print_screen: true,
          block_keyboard_shortcuts: true, require_fullscreen: true, detect_fullscreen_exit: true,
          detect_tab_switch: true, detect_window_blur: true, require_webcam: true, require_face_detection: true,
          snapshot_interval_seconds: 15, detect_multiple_faces: true, detect_no_face: true,
          face_detection_sensitivity: 'high', require_screen_share: true, record_screen: true,
          require_id_verification: true, require_photo_id: true, require_selfie: true,
          auto_terminate: true, require_system_check: true, require_single_monitor: true,
          detect_virtual_machine: true, detect_remote_desktop: true, require_stable_connection: true,
          warn_on_violation: true, show_violation_count: true, notify_admin_on_flag: true,
          notify_admin_on_terminate: true } }
    ];
  }
}
