// live-training/integrations/integrations-settings.component.ts

import { Component, OnInit, OnDestroy, inject } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Subject } from 'rxjs';
import { takeUntil } from 'rxjs/operators';
import {
  VideoIntegrationService,
  IntegrationStatus,
  MeetingDetails,
  MeetingParticipant
} from '../services/video-integration.service';
import {AdmintopComponent} from '../../partials/top/admintop/admintop.component';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { ApiError } from '../../core/api';

interface PlatformConfig {
  id: string;
  name: string;
  description: string;
  icon: string;
  color: string;
  features: string[];
}

interface TestMeetingForm {
  topic: string;
  start_time: string;
  duration: number;
  timezone: string;
}

@Component({
  selector: 'app-integrations-settings',
  standalone: true,
  imports: [CommonModule, FormsModule, AdmintopComponent, TranslatePipe],
  templateUrl: './integrations-settings.component.html',
  styleUrls: ['./integrations-settings.component.scss']

})
export class IntegrationsSettingsComponent implements OnInit, OnDestroy {
  private readonly router = inject(Router);
  private readonly i18n = inject(I18nService);

  private destroy$ = new Subject<void>();

  platforms: PlatformConfig[] = [
    {
      id: 'zoom',
      name: 'Zoom',
      description: this.i18n.t('live.integrations.zoom_desc'),
      icon: 'bi-camera-video-fill',
      color: '#2D8CFF',
      features: [
        this.i18n.t('live.integrations.feat_auto_meeting'),
        this.i18n.t('live.integrations.feat_attendance_tracking'),
        this.i18n.t('live.integrations.feat_recording'),
        this.i18n.t('live.integrations.feat_breakout')
      ]
    },
    {
      id: 'microsoft_teams',
      name: 'Microsoft Teams',
      description: this.i18n.t('live.integrations.teams_desc'),
      icon: 'bi-microsoft-teams',
      color: '#6264A7',
      features: [
        this.i18n.t('live.integrations.feat_auto_meeting'),
        this.i18n.t('live.integrations.feat_attendance_reports'),
        this.i18n.t('live.integrations.feat_calendar_sync'),
        this.i18n.t('live.integrations.feat_chat')
      ]
    },
    {
      id: 'google_meet',
      name: 'Google Meet',
      description: this.i18n.t('live.integrations.meet_desc'),
      icon: 'bi-google',
      color: '#00897B',
      features: [
        this.i18n.t('live.integrations.feat_auto_meeting'),
        this.i18n.t('live.integrations.feat_calendar_integration'),
        this.i18n.t('live.integrations.feat_live_captions'),
        this.i18n.t('live.integrations.feat_screen_sharing')
      ]
    }
  ];

  integrations: IntegrationStatus[] = [];
  connecting: string | null = null;
  callbackMessage: string | null = null;
  callbackSuccess = false;

  // Test Meeting Modal
  showTestMeetingModal = false;
  testMeetingPlatform: string = '';
  testMeetingForm: TestMeetingForm = {
    topic: 'Test Meeting',
    start_time: '',
    duration: 30,
    timezone: 'UTC'
  };
  testMeetingResult: MeetingDetails | null = null;
  testMeetingLoading = false;

  // Meeting Details Modal
  showMeetingDetailsModal = false;
  currentMeeting: MeetingDetails | null = null;
  meetingParticipants: MeetingParticipant[] = [];
  loadingParticipants = false;

  // Connection Status Cache
  connectionStatusCache: Map<string, boolean> = new Map();

  constructor(
    private videoIntegrationService: VideoIntegrationService
  ) {}

  ngOnInit(): void {
    this.loadIntegrations();
    this.handleOAuthCallback();
    this.initTestMeetingForm();
  }

  ngOnDestroy(): void {
    this.destroy$.next();
    this.destroy$.complete();
  }

  private initTestMeetingForm(): void {
    // Set default start time to 1 hour from now
    const now = new Date();
    now.setHours(now.getHours() + 1);
    now.setMinutes(0);
    this.testMeetingForm.start_time = now.toISOString().slice(0, 16);
  }

  loadIntegrations(): void {
    this.videoIntegrationService.getAllIntegrationStatuses()
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (data: IntegrationStatus[]) => {
          this.integrations = data;
          // Update connection status cache
          data.forEach((integration: IntegrationStatus) => {
            this.connectionStatusCache.set(integration.platform, integration.connected);
          });
        },
        error: (err: Error) => console.error('Error loading integrations:', err)
      });
  }

  handleOAuthCallback(): void {
    // Check for OAuth callback parameters
    const params = new URLSearchParams(window.location.search);
    const code = params.get('code');
    const state = params.get('state');
    const error = params.get('error');

    if (error) {
      this.callbackSuccess = false;
      this.callbackMessage = this.i18n.t('live.integrations.auth_denied');
      window.history.replaceState({}, '', window.location.pathname);
      return;
    }

    if (code && state) {
      // Determine platform from state
      const platform = this.getPlatformFromState(state);
      if (platform) {
        this.connecting = platform;
        this.videoIntegrationService.exchangeCodeForTokens(platform, code, state)
          .pipe(takeUntil(this.destroy$))
          .subscribe({
            next: () => {
              this.connecting = null;
              this.callbackSuccess = true;
              this.callbackMessage = this.i18n.t('live.integrations.connected_success', { platform: this.getDisplayName(platform) });
              this.loadIntegrations();
              window.history.replaceState({}, '', window.location.pathname);
            },
            error: (err: Error) => {
              this.connecting = null;
              this.callbackSuccess = false;
              this.callbackMessage = this.i18n.t('live.integrations.connect_failed', { error: err.message || this.i18n.t('common.error_generic') });
              window.history.replaceState({}, '', window.location.pathname);
            }
          });
      }
    }
  }

  private getPlatformFromState(state: string): string | null {
    // Check which platform this callback is for — the key must match what the
    // service wrote at connect() time ('integration.state.<platform>').
    for (const platform of ['zoom', 'microsoft_teams', 'google_meet']) {
      const storedState = sessionStorage.getItem(`integration.state.${platform}`);
      if (storedState === state) {
        return platform;
      }
    }
    return null;
  }

  connect(platformId: string): void {
    this.connecting = platformId;
    this.callbackMessage = null;

    this.videoIntegrationService
      .connect(platformId as 'zoom' | 'google_meet' | 'microsoft_teams')
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: (started) => {
          // The page leaves for the provider; the spinner is fine until it does.
          window.location.href = started.authorizationUrl;
        },
        error: (err: unknown) => {
          // A deployment with no credentials for this provider answers
          // `platform_unavailable`. Clear the spinner and show the reason in
          // the banner rather than leaving the button spinning forever.
          this.connecting = null;
          this.callbackSuccess = false;
          this.callbackMessage = this.i18n.t('live.integrations.connect_failed', {
            error: err instanceof ApiError ? err.message : this.i18n.t('common.error_generic'),
          });
        },
      });
  }

  disconnect(platformId: string): void {
    if (!confirm(this.i18n.t('live.integrations.disconnect_confirm', { platform: this.getDisplayName(platformId) }))) {
      return;
    }

    // Use VideoIntegrationService disconnect method
    this.videoIntegrationService.disconnect(platformId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.loadIntegrations();
          this.connectionStatusCache.set(platformId, false);
          this.callbackSuccess = true;
          this.callbackMessage = this.i18n.t('live.integrations.disconnected', { platform: this.getDisplayName(platformId) });
        },
        error: err => {
          this.callbackSuccess = false;
          this.callbackMessage = this.i18n.t('live.integrations.disconnect_failed', { error: err.message });
        }
      });
  }

  setDefault(platformId: string): void {
    // Use VideoIntegrationService setDefaultPlatform method
    this.videoIntegrationService.setDefaultPlatform(platformId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.loadIntegrations();
          this.callbackSuccess = true;
          this.callbackMessage = this.i18n.t('live.integrations.default_set', { platform: this.getDisplayName(platformId) });
        },
        error: err => console.error('Error setting default:', err)
      });
  }

  // Check connection status using service method
  checkConnectionStatus(platformId: string): void {
    this.videoIntegrationService.isConnected(platformId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: connected => {
          this.connectionStatusCache.set(platformId, connected);
        },
        error: err => console.error('Error checking connection:', err)
      });
  }

  isConnected(platformId: string): boolean {
    const integration = this.integrations.find(i => i.platform === platformId);
    return integration?.connected || false;
  }

  isDefault(platformId: string): boolean {
    const integration = this.integrations.find(i => i.platform === platformId);
    return integration?.is_active || false;
  }

  getIntegration(platformId: string): IntegrationStatus | undefined {
    return this.integrations.find(i => i.platform === platformId);
  }

  // Use service helper methods
  getDisplayName(platformId: string): string {
    return this.videoIntegrationService.getPlatformDisplayName(platformId);
  }

  getIcon(platformId: string): string {
    return this.videoIntegrationService.getPlatformIcon(platformId);
  }

  getColor(platformId: string): string {
    return this.videoIntegrationService.getPlatformColor(platformId);
  }

  getPlatformName(platformId: string): string {
    return this.platforms.find(p => p.id === platformId)?.name || platformId;
  }

  // ==========================================
  // TEST MEETING FUNCTIONALITY
  // ==========================================

  openTestMeetingModal(platformId: string): void {
    this.testMeetingPlatform = platformId;
    this.testMeetingResult = null;
    this.initTestMeetingForm();
    this.showTestMeetingModal = true;
  }

  closeTestMeetingModal(): void {
    this.showTestMeetingModal = false;
    this.testMeetingResult = null;
    this.testMeetingPlatform = '';
  }

  createTestMeeting(): void {
    if (!this.testMeetingPlatform) return;

    this.testMeetingLoading = true;
    this.videoIntegrationService.createMeeting(this.testMeetingPlatform, this.testMeetingForm)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: meeting => {
          this.testMeetingResult = meeting;
          this.testMeetingLoading = false;
          this.callbackSuccess = true;
          this.callbackMessage = this.i18n.t('live.integrations.test_created');
        },
        error: err => {
          this.testMeetingLoading = false;
          this.callbackSuccess = false;
          this.callbackMessage = this.i18n.t('live.integrations.create_failed', { error: err.message });
        }
      });
  }

  // Get meeting details
  getMeetingDetails(platformId: string, meetingId: string): void {
    this.videoIntegrationService.getMeeting(platformId, meetingId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: meeting => {
          this.currentMeeting = meeting;
          this.showMeetingDetailsModal = true;
          this.loadMeetingParticipants(platformId, meetingId);
        },
        error: err => {
          this.callbackSuccess = false;
          this.callbackMessage = this.i18n.t('live.integrations.get_failed', { error: err.message });
        }
      });
  }

  // Load meeting participants
  loadMeetingParticipants(platformId: string, meetingId: string): void {
    this.loadingParticipants = true;
    this.videoIntegrationService.getMeetingParticipants(platformId, meetingId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: participants => {
          this.meetingParticipants = participants;
          this.loadingParticipants = false;
        },
        error: err => {
          console.error('Error loading participants:', err);
          this.loadingParticipants = false;
        }
      });
  }

  // Update meeting
  updateMeeting(platformId: string, meetingId: string, updates: Partial<MeetingDetails>): void {
    this.videoIntegrationService.updateMeeting(platformId, meetingId, updates)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: meeting => {
          this.currentMeeting = meeting;
          this.callbackSuccess = true;
          this.callbackMessage = this.i18n.t('live.integrations.update_success');
        },
        error: err => {
          this.callbackSuccess = false;
          this.callbackMessage = this.i18n.t('live.integrations.update_failed', { error: err.message });
        }
      });
  }

  // Delete meeting
  deleteMeeting(platformId: string, meetingId: string): void {
    if (!confirm(this.i18n.t('live.integrations.delete_meeting_confirm'))) return;

    this.videoIntegrationService.deleteMeeting(platformId, meetingId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.showMeetingDetailsModal = false;
          this.currentMeeting = null;
          this.testMeetingResult = null;
          this.callbackSuccess = true;
          this.callbackMessage = this.i18n.t('live.integrations.delete_success');
        },
        error: err => {
          this.callbackSuccess = false;
          this.callbackMessage = this.i18n.t('live.integrations.delete_failed', { error: err.message });
        }
      });
  }

  closeMeetingDetailsModal(): void {
    this.showMeetingDetailsModal = false;
    this.currentMeeting = null;
    this.meetingParticipants = [];
  }

  // Copy to clipboard
  copyToClipboard(text: string): void {
    navigator.clipboard.writeText(text).then(() => {
      this.callbackSuccess = true;
      this.callbackMessage = this.i18n.t('live.integrations.copied');
      setTimeout(() => this.callbackMessage = null, 2000);
    });
  }

  formatDate(dateStr: string | undefined): string {
    if (!dateStr) return '';
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit'
    });
  }

  /** Back to the programme list this screen was opened from. */
  goBack(): void {
    void this.router.navigate(['/live-training/admin']);
  }
}
