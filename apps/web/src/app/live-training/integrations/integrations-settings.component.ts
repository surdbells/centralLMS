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
  imports: [CommonModule, FormsModule, AdmintopComponent],
  templateUrl: './integrations-settings.component.html',
  styleUrls: ['./integrations-settings.component.scss']

})
export class IntegrationsSettingsComponent implements OnInit, OnDestroy {
  private readonly router = inject(Router);

  private destroy$ = new Subject<void>();

  platforms: PlatformConfig[] = [
    {
      id: 'zoom',
      name: 'Zoom',
      description: 'Connect Zoom to automatically create and manage video meetings for your live training sessions.',
      icon: 'bi-camera-video-fill',
      color: '#2D8CFF',
      features: ['Auto meeting creation', 'Attendance tracking', 'Recording', 'Breakout rooms']
    },
    {
      id: 'microsoft_teams',
      name: 'Microsoft Teams',
      description: 'Integrate with Microsoft Teams for seamless enterprise video conferencing.',
      icon: 'bi-microsoft-teams',
      color: '#6264A7',
      features: ['Auto meeting creation', 'Attendance reports', 'Calendar sync', 'Chat integration']
    },
    {
      id: 'google_meet',
      name: 'Google Meet',
      description: 'Use Google Meet for simple, reliable video meetings integrated with Google Workspace.',
      icon: 'bi-google',
      color: '#00897B',
      features: ['Auto meeting creation', 'Calendar integration', 'Live captions', 'Screen sharing']
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
      this.callbackMessage = 'Authorization was denied or cancelled.';
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
              this.callbackMessage = `Successfully connected to ${this.getDisplayName(platform)}!`;
              this.loadIntegrations();
              window.history.replaceState({}, '', window.location.pathname);
            },
            error: (err: Error) => {
              this.connecting = null;
              this.callbackSuccess = false;
              this.callbackMessage = `Failed to connect: ${err.message || 'Unknown error'}`;
              window.history.replaceState({}, '', window.location.pathname);
            }
          });
      }
    }
  }

  private getPlatformFromState(state: string): string | null {
    // Check which platform this callback is for
    for (const platform of ['zoom', 'microsoft_teams', 'google_meet']) {
      const storedState = sessionStorage.getItem(`oauth_state_${platform}`);
      if (storedState === state) {
        return platform;
      }
    }
    return null;
  }

  connect(platformId: string): void {
    this.connecting = platformId;
    this.videoIntegrationService.connect(platformId as 'zoom' | 'google_meet' | 'microsoft_teams');

    // Note: Page will redirect for OAuth, so connecting state will be reset on return
  }

  disconnect(platformId: string): void {
    if (!confirm(`Disconnect from ${this.getDisplayName(platformId)}? Existing meetings will not be affected.`)) {
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
          this.callbackMessage = `Disconnected from ${this.getDisplayName(platformId)}`;
        },
        error: err => {
          this.callbackSuccess = false;
          this.callbackMessage = `Failed to disconnect: ${err.message}`;
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
          this.callbackMessage = `${this.getDisplayName(platformId)} is now your default platform`;
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
          this.callbackMessage = 'Test meeting created successfully!';
        },
        error: err => {
          this.testMeetingLoading = false;
          this.callbackSuccess = false;
          this.callbackMessage = `Failed to create meeting: ${err.message}`;
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
          this.callbackMessage = `Failed to get meeting: ${err.message}`;
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
          this.callbackMessage = 'Meeting updated successfully!';
        },
        error: err => {
          this.callbackSuccess = false;
          this.callbackMessage = `Failed to update meeting: ${err.message}`;
        }
      });
  }

  // Delete meeting
  deleteMeeting(platformId: string, meetingId: string): void {
    if (!confirm('Are you sure you want to delete this meeting?')) return;

    this.videoIntegrationService.deleteMeeting(platformId, meetingId)
      .pipe(takeUntil(this.destroy$))
      .subscribe({
        next: () => {
          this.showMeetingDetailsModal = false;
          this.currentMeeting = null;
          this.testMeetingResult = null;
          this.callbackSuccess = true;
          this.callbackMessage = 'Meeting deleted successfully!';
        },
        error: err => {
          this.callbackSuccess = false;
          this.callbackMessage = `Failed to delete meeting: ${err.message}`;
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
      this.callbackMessage = 'Copied to clipboard!';
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
