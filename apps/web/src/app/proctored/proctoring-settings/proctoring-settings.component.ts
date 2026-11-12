// =============================================
// PROCTORED ASSESSMENT MODULE - PHASE 12
// Admin: Proctoring Settings Component
// Multi-Tenant LMS | Angular Standalone
// =============================================

import { Component, OnInit, OnDestroy, inject, Input, Output, EventEmitter } from '@angular/core';
import { CommonModule } from '@angular/common';
import { SkeletonComponent } from '../../shared/skeleton/skeleton.component';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute } from '@angular/router';
import { Subject, takeUntil } from 'rxjs';
import { TuiIcon } from '@taiga-ui/core';
import { AdmintopComponent } from '../../partials/top/admintop/admintop.component';
import { AssessmentsService } from '../../services/assessments.service';

// ==========================================
// INTERFACES
// ==========================================

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
  imports: [CommonModule, FormsModule, TuiIcon, AdmintopComponent, SkeletonComponent],
  template: `
    <app-admintop></app-admintop>

    <main class="main">
    <div class="ui-page proctoring-settings-container">
      <!-- Header -->
      <header class="ui-page-header settings-header" *ngIf="!embedded">
        <div class="ui-page-header__icon" aria-hidden="true"><i class="bi bi-shield-check"></i></div>
        <div class="ui-page-header__titles">
          <h1>Proctoring Settings</h1>
          <p>Configure proctoring rules for assessments</p>
        </div>
        <div class="ui-page-header__actions">
          <button class="btn btn-outline-secondary" (click)="resetToDefaults()">
            <tui-icon icon="@tui.refresh-cw"></tui-icon> Reset
          </button>
          <button class="btn btn-primary" (click)="saveSettings()" [disabled]="saving">
            <span class="spinner-border spinner-border-sm me-1" *ngIf="saving"></span>
            <tui-icon icon="@tui.save" *ngIf="!saving"></tui-icon> Save Settings
          </button>
        </div>
      </header>

      <div class="alert alert-warning d-flex align-items-center gap-2" *ngIf="saveError">
        <tui-icon icon="@tui.alert-triangle"></tui-icon>
        <span>{{ saveError }}</span>
        <button type="button" class="btn-close ms-auto" (click)="saveError = null"></button>
      </div>

      <div class="settings-layout" *ngIf="!loading">
        <!-- LEFT: Settings Panels -->
        <div class="settings-left">
          <!-- Preset Selection -->
          <div class="ui-section settings-card">
            <div class="ui-section__head">
              <h2 class="ui-section__title"><tui-icon icon="@tui.zap"></tui-icon> Quick Presets</h2>
            </div>
            <div class="section-body">
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
          <div class="ui-section settings-card">
            <div class="ui-section__head is-toggle" (click)="toggleSection('browser')">
              <h2 class="ui-section__title"><tui-icon icon="@tui.monitor"></tui-icon> Browser Lockdown</h2>
              <tui-icon [icon]="expandedSections['browser'] ? '@tui.chevron-up' : '@tui.chevron-down'"></tui-icon>
            </div>
            <div class="section-body" *ngIf="expandedSections['browser']">
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
          <div class="ui-section settings-card">
            <div class="ui-section__head is-toggle" (click)="toggleSection('webcam')">
              <h2 class="ui-section__title"><tui-icon icon="@tui.camera"></tui-icon> Webcam & Face Detection</h2>
              <tui-icon [icon]="expandedSections['webcam'] ? '@tui.chevron-up' : '@tui.chevron-down'"></tui-icon>
            </div>
            <div class="section-body" *ngIf="expandedSections['webcam']">
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
          <div class="ui-section settings-card">
            <div class="ui-section__head is-toggle" (click)="toggleSection('screen')">
              <h2 class="ui-section__title"><tui-icon icon="@tui.airplay"></tui-icon> Screen Monitoring</h2>
              <tui-icon [icon]="expandedSections['screen'] ? '@tui.chevron-up' : '@tui.chevron-down'"></tui-icon>
            </div>
            <div class="section-body" *ngIf="expandedSections['screen']">
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
          <div class="ui-section settings-card">
            <div class="ui-section__head is-toggle" (click)="toggleSection('id')">
              <h2 class="ui-section__title"><tui-icon icon="@tui.credit-card"></tui-icon> ID Verification</h2>
              <tui-icon [icon]="expandedSections['id'] ? '@tui.chevron-up' : '@tui.chevron-down'"></tui-icon>
            </div>
            <div class="section-body" *ngIf="expandedSections['id']">
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
          <div class="ui-section settings-card">
            <div class="ui-section__head is-toggle" (click)="toggleSection('violations')">
              <h2 class="ui-section__title"><tui-icon icon="@tui.alert-triangle"></tui-icon> Violation Thresholds</h2>
              <tui-icon [icon]="expandedSections['violations'] ? '@tui.chevron-up' : '@tui.chevron-down'"></tui-icon>
            </div>
            <div class="section-body" *ngIf="expandedSections['violations']">
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
          <div class="ui-section settings-card">
            <div class="ui-section__head is-toggle" (click)="toggleSection('environment')">
              <h2 class="ui-section__title"><tui-icon icon="@tui.settings"></tui-icon> Environment Checks</h2>
              <tui-icon [icon]="expandedSections['environment'] ? '@tui.chevron-up' : '@tui.chevron-down'"></tui-icon>
            </div>
            <div class="section-body" *ngIf="expandedSections['environment']">
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
          <div class="ui-section settings-card">
            <div class="ui-section__head is-toggle" (click)="toggleSection('notifications')">
              <h2 class="ui-section__title"><tui-icon icon="@tui.bell"></tui-icon> Admin Notifications</h2>
              <tui-icon [icon]="expandedSections['notifications'] ? '@tui.chevron-up' : '@tui.chevron-down'"></tui-icon>
            </div>
            <div class="section-body" *ngIf="expandedSections['notifications']">
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
          <div class="ui-section settings-card summary-card summary-sticky">
            <div class="ui-section__head"><h2 class="ui-section__title">Active Settings Summary</h2></div>
            <div class="section-body">
              <div class="summary-level">
                <span class="ui-pill level-badge" [ngClass]="'level-' + config.proctoring_level">
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
      <app-skeleton *ngIf="loading" variant="form" [rows]="6"></app-skeleton>
    </div>
    </main>
  `,
  styles: [`
    /* Page chrome (header, sections, pills) comes from the global UI kit. */
    .ui-section__head { align-items: center; }
    .ui-section__title { display: flex; align-items: center; gap: 8px; }
    .ui-section__title tui-icon { color: var(--ui-text-subtle, #8d948e); }
    .ui-section__head.is-toggle { cursor: pointer; user-select: none; }
    .ui-section__head.is-toggle:last-child { margin-bottom: 0; }
    .ui-section__head.is-toggle > tui-icon { color: var(--ui-text-muted, #6a716b); }

    .settings-layout { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 20px; align-items: start; }

    /* Presets */
    .preset-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.5rem; }
    .preset-btn {
      display: flex; flex-direction: column; align-items: center; gap: 0.25rem; padding: 0.75rem 0.5rem;
      border: 1px solid var(--ui-border, #e7e9e7); border-radius: var(--ui-radius-card, 12px); background: var(--ui-surface, #fff); cursor: pointer; text-align: center;
      transition: border-color .15s ease, background .15s ease;
    }
    .preset-btn:hover { border-color: var(--ui-border-strong, #d9ddd9); }
    .preset-btn.active { border-color: var(--brand-primary); background: rgba(var(--brand-primary-rgb), .08); box-shadow: 0 0 0 1px var(--brand-primary) inset; }
    .preset-btn.active tui-icon { color: var(--brand-primary); }
    .preset-name { font-size: 0.8125rem; font-weight: 600; }
    .preset-desc { font-size: 0.6875rem; color: var(--ui-text-muted, #6a716b); }

    /* Settings Rows */
    .setting-row { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 0.625rem 0;
      border-bottom: 1px solid var(--ui-border, #e7e9e7); }
    .setting-row:first-child { padding-top: 0; }
    .setting-row:last-child { border-bottom: none; padding-bottom: 0; }
    .setting-info { flex: 1; }
    .setting-label { font-size: 0.875rem; font-weight: 500; display: block; }
    .setting-desc { font-size: 0.75rem; color: var(--ui-text-muted, #6a716b); }
    .setting-input { width: 140px !important; flex-shrink: 0; }

    /* Toggle */
    .toggle-switch { position: relative; display: inline-block; width: 40px; height: 22px; flex-shrink: 0; margin: 0; }
    .toggle-switch input { opacity: 0; width: 0; height: 0; }
    .toggle-slider {
      position: absolute; cursor: pointer; inset: 0; background: var(--ui-border-strong, #d9ddd9); border-radius: 22px; transition: background .2s ease;
    }
    .toggle-slider::before {
      content: ''; position: absolute; height: 16px; width: 16px; left: 3px; bottom: 3px;
      background: #fff; border-radius: 50%; transition: transform .2s ease; box-shadow: 0 1px 2px rgba(16, 24, 18, .2);
    }
    .toggle-switch input:checked + .toggle-slider { background: var(--brand-primary); }
    .toggle-switch input:checked + .toggle-slider::before { transform: translateX(18px); }
    .toggle-switch input:focus-visible + .toggle-slider { outline: 2px solid rgba(var(--brand-primary-rgb), .35); outline-offset: 2px; }
    .toggle-switch input:disabled + .toggle-slider { opacity: 0.5; cursor: not-allowed; }

    /* Summary */
    .summary-sticky { position: sticky; top: 1rem; }
    .summary-level { text-align: center; margin-bottom: 1rem; }
    .level-badge { font-size: 0.8125rem; padding: 4px 14px; }
    .level-none { background: rgba(var(--ui-neutral-rgb), .1); border-color: rgba(var(--ui-neutral-rgb), .28); color: var(--ui-neutral-text); }
    .level-basic { background: rgba(var(--ui-info-rgb), .1); border-color: rgba(var(--ui-info-rgb), .28); color: var(--ui-info-text); }
    .level-standard { background: rgba(var(--ui-warning-rgb), .1); border-color: rgba(var(--ui-warning-rgb), .28); color: var(--ui-warning-text); }
    .level-advanced { background: rgba(var(--ui-danger-rgb), .1); border-color: rgba(var(--ui-danger-rgb), .28); color: var(--ui-danger-text); }
    .summary-section { margin-bottom: 0.875rem; }
    .summary-section h6 { font-size: 0.6875rem; font-weight: 600; color: var(--ui-text-muted, #6a716b); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 0.375rem; }
    .summary-tags { display: flex; flex-wrap: wrap; gap: 0.25rem; }
    .tag { font-size: 0.6875rem; padding: 2px 8px; border-radius: 999px; border: 1px solid var(--ui-border, #e7e9e7);
      background: var(--ui-surface-2, #f7f8f7); color: var(--ui-text-subtle, #8d948e); }
    .tag.enabled { background: rgba(var(--brand-primary-rgb), .08); border-color: rgba(var(--brand-primary-rgb), .28); color: var(--brand-primary); }
    .summary-stats { font-size: 0.8125rem; }
    .stat-row { display: flex; justify-content: space-between; padding: 0.25rem 0; }


    @media (max-width: 992px) {
      .settings-layout { grid-template-columns: 1fr; }
      .preset-grid { grid-template-columns: repeat(2, 1fr); }
      .summary-sticky { position: static; }
    }
  `]
})
export class ProctoringSettingsComponent implements OnInit, OnDestroy {
  private assessments = inject(AssessmentsService);
  private route = inject(ActivatedRoute);
  private destroy$ = new Subject<void>();

  @Input() assessmentId: string | null = null;
  @Input() embedded = false;
  @Output() settingsSaved = new EventEmitter<ProctoringConfig>();

  loading = false;
  saving = false;
  saveError: string | null = null;
  activePreset = '';
  presets: ProctoringPreset[] = [];

  expandedSections: Record<string, boolean> = {
    browser: true, webcam: true, screen: false, id: false,
    violations: true, environment: false, notifications: false
  };

  config: ProctoringConfig = this.getDefaultConfig();

  ngOnInit(): void {
    if (!this.assessmentId) {
      this.assessmentId = this.route.snapshot.paramMap.get('id')
        ?? this.route.parent?.snapshot.paramMap.get('id')
        ?? null;
    }
    // The preset cards are client-side quick-apply templates; the server no
    // longer serves a preset list independent of an assessment.
    this.presets = this.getDefaultPresets();
    if (this.assessmentId) this.loadSettings();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  // ==========================================
  // DATA
  // ==========================================

  loadSettings(): void {
    if (!this.assessmentId) return;
    this.loading = true;
    this.assessments.proctoringSettings(this.assessmentId)
      .pipe(takeUntil(this.destroy$)).subscribe({
        next: (payload) => {
          const level = (payload['level'] as string) ?? 'none';
          const settings = (payload['settings'] as Record<string, unknown>) ?? {};
          this.config = this.fromApi(level, settings);
          this.activePreset = level;
          this.loading = false;
        },
        error: () => { this.loading = false; }
      });
  }

  saveSettings(): void {
    if (!this.assessmentId) {
      // The new API only stores proctoring under an assessment; there is no
      // tenant-wide default to save from the standalone page.
      this.saveError = 'Open an assessment to save its proctoring settings.';
      return;
    }
    this.saving = true;
    this.saveError = null;
    this.assessments.saveProctoringSettings(this.assessmentId, {
      level: this.config.proctoring_level,
      settings: this.toApiSettings(this.config),
    } as Record<string, unknown>).pipe(takeUntil(this.destroy$)).subscribe({
      next: () => {
        this.saving = false;
        this.settingsSaved.emit(this.config);
      },
      error: (err) => {
        this.saving = false;
        const e = err as { error?: { error?: { code?: string; message?: string } } };
        this.saveError = e?.error?.error?.code === 'assessment_not_editable'
          ? 'This assessment is published; move it back to draft to change proctoring.'
          : (e?.error?.error?.message || 'Proctoring settings could not be saved.');
      }
    });
  }

  /**
   * Maps the new API's proctoring settings onto this screen's flat config.
   * The new surface is narrower than the legacy one, so options it does not
   * carry keep their defaults.
   */
  private fromApi(level: string, s: Record<string, unknown>): ProctoringConfig {
    const b = (key: string, fallback = false): boolean =>
      typeof s[key] === 'boolean' ? s[key] as boolean : fallback;
    const n = (key: string, fallback: number): number =>
      typeof s[key] === 'number' ? s[key] as number : fallback;

    return {
      ...this.getDefaultConfig(),
      proctoring_level: level,
      lock_browser: b('requireFullscreen') || b('preventCopyPaste') || b('preventTabSwitch'),
      block_copy_paste: b('preventCopyPaste'),
      block_right_click: b('preventRightClick'),
      require_fullscreen: b('requireFullscreen'),
      block_keyboard_shortcuts: b('detectDevTools'),
      detect_tab_switch: b('preventTabSwitch'),
      detect_window_blur: b('windowBlurDetection'),
      max_violations_before_flag: n('maxTabSwitches', 5),
      warn_on_violation: b('warnOnTabSwitch', true),
      require_webcam: b('requireWebcam'),
      require_face_detection: b('faceDetection'),
      snapshot_interval_seconds: n('snapshotIntervalSeconds', n('webcamCheckIntervalSeconds', 60)),
      detect_multiple_faces: b('flagMultipleFaces'),
      detect_no_face: b('flagNoFace'),
      record_screen: b('recordScreen'),
      require_id_verification: b('requireIdVerification'),
    };
  }

  /** The reverse: this screen's config onto the fields the new API stores. */
  private toApiSettings(c: ProctoringConfig): Record<string, unknown> {
    return {
      preventTabSwitch: c.detect_tab_switch || c.block_new_tabs,
      preventCopyPaste: c.block_copy_paste,
      preventRightClick: c.block_right_click,
      requireFullscreen: c.require_fullscreen || c.lock_browser,
      detectDevTools: c.block_keyboard_shortcuts,
      windowBlurDetection: c.detect_window_blur,
      maxTabSwitches: c.max_violations_before_flag,
      warnOnTabSwitch: c.warn_on_violation,
      requireWebcam: c.require_webcam,
      webcamCheckIntervalSeconds: c.snapshot_interval_seconds,
      faceDetection: c.require_face_detection,
      flagMultipleFaces: c.detect_multiple_faces,
      flagNoFace: c.detect_no_face,
      captureSnapshots: c.require_webcam,
      snapshotIntervalSeconds: c.snapshot_interval_seconds,
      recordScreen: c.record_screen,
      requireIdVerification: c.require_id_verification,
    };
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
