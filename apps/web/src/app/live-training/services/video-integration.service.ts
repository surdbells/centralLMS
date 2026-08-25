// live-training/services/video-integration.service.ts

import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, of, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { environment } from '../../../environments/environment';
import {GlobalComponent} from '../../global-component';

// ==========================================
// INTERFACES
// ==========================================

export interface VideoIntegrationConfig {
  platform: 'zoom' | 'google_meet' | 'microsoft_teams';
  clientId: string;
  redirectUri: string;
  scopes: string[];
}

export interface OAuthToken {
  access_token: string;
  refresh_token: string;
  expires_in: number;
  token_type: string;
  scope?: string;
}

export interface MeetingDetails {
  id: string;
  topic: string;
  start_time: string;
  duration: number;
  timezone: string;
  join_url: string;
  host_url?: string;
  password?: string;
  platform: string;
}

export interface MeetingParticipant {
  id: string;
  name: string;
  email: string;
  join_time: string;
  leave_time?: string;
  duration_minutes: number;
}

export interface IntegrationStatus {
  platform: string;
  connected: boolean;
  is_active: boolean;
  expires_at?: string;
  account_email?: string;
}

// ==========================================
// SAFE ENVIRONMENT ACCESS
// ==========================================

// Type for environment with optional nested properties
type EnvironmentType = typeof environment & {
  api?: {
    zoomAPIkey?: string;
    teamsClientId?: string;
    googleClientId?: string;
  };
  apiUrl?: string;
  appUrl?: string;
  zoomClientId?: string;
  teamsClientId?: string;
  googleClientId?: string;
};

const env = environment as EnvironmentType;

// Conferencing client ids come from configuration, never from a literal.
// This previously fell back to a hardcoded Zoom API key, which shipped the
// credential in the browser bundle for anyone to read.
//
// These are being retired entirely: the API holds each tenant's OAuth
// credentials server-side (see video_integrations), so the browser never
// needs them. See docs/migration/ilt-rewrite.md.
const getZoomClientId = (): string => {
  return env.api?.zoomAPIkey || env.zoomClientId || '';
};

const getTeamsClientId = (): string => {
  return env.api?.teamsClientId || env.teamsClientId || '';
};

const getGoogleClientId = (): string => {
  return env.api?.googleClientId || env.googleClientId || '';
};


// ==========================================
// ZOOM CONFIGURATION
// ==========================================

const ZOOM_CONFIG: VideoIntegrationConfig = {
  platform: 'zoom',
  clientId: getZoomClientId(),
  redirectUri: GlobalComponent.integration_callback + '?platform=zoom',
  scopes: [
    'meeting:read',
    'meeting:write',
    'report:read:admin',
    'user:read'
  ]
};

// ==========================================
// MICROSOFT TEAMS CONFIGURATION
// ==========================================

const TEAMS_CONFIG: VideoIntegrationConfig = {
  platform: 'microsoft_teams',
  clientId: getTeamsClientId(),
  redirectUri: GlobalComponent.integration_callback + '?platform=teams',
  scopes: [
    'OnlineMeetings.ReadWrite',
    'OnlineMeetingArtifact.Read.All',
    'User.Read'
  ]
};

// ==========================================
// GOOGLE MEET CONFIGURATION
// ==========================================

const GOOGLE_CONFIG: VideoIntegrationConfig = {
  platform: 'google_meet',
  clientId: getGoogleClientId(),
  redirectUri: GlobalComponent.integration_callback + '?platform=meet',
  scopes: [
    'https://www.googleapis.com/auth/calendar',
    'https://www.googleapis.com/auth/calendar.events'
  ]
};

// ==========================================
// SERVICE
// ==========================================

@Injectable({
  providedIn: 'root'
})
export class VideoIntegrationService {
  private apiUrl = GlobalComponent.liveTraining;

  // Connection status cache
  private connectionStatusCache = new Map<string, { status: IntegrationStatus; timestamp: number }>();
  private readonly CACHE_DURATION = 5 * 60 * 1000; // 5 minutes

  constructor(private http: HttpClient) {}

  // ==========================================
  // OAUTH FLOW
  // ==========================================

  /**
   * Get OAuth authorization URL for a platform
   */
  getAuthorizationUrl(platform: 'zoom' | 'google_meet' | 'microsoft_teams', state?: string): string {
    const config = this.getConfig(platform);
    const stateParam = state || this.generateState();

    // Store state for verification
    sessionStorage.setItem(`oauth_state_${platform}`, stateParam);

    switch (platform) {
      case 'zoom':
        return this.getZoomAuthUrl(config, stateParam);
      case 'google_meet':
        return this.getGoogleAuthUrl(config, stateParam);
      case 'microsoft_teams':
        return this.getTeamsAuthUrl(config, stateParam);
      default:
        throw new Error(`Unsupported platform: ${platform}`);
    }
  }

  private getZoomAuthUrl(config: VideoIntegrationConfig, state: string): string {
    const params = new URLSearchParams({
      response_type: 'code',
      client_id: config.clientId,
      redirect_uri: config.redirectUri,
      state: state
    });
    return `https://zoom.us/oauth/authorize?${params.toString()}`;
  }

  private getGoogleAuthUrl(config: VideoIntegrationConfig, state: string): string {
    const params = new URLSearchParams({
      response_type: 'code',
      client_id: config.clientId,
      redirect_uri: config.redirectUri,
      scope: config.scopes.join(' '),
      state: state,
      access_type: 'offline',
      prompt: 'consent'
    });
    return `https://accounts.google.com/o/oauth2/v2/auth?${params.toString()}`;
  }

  private getTeamsAuthUrl(config: VideoIntegrationConfig, state: string): string {
    const params = new URLSearchParams({
      response_type: 'code',
      client_id: config.clientId,
      redirect_uri: config.redirectUri,
      scope: config.scopes.join(' '),
      state: state,
      response_mode: 'query'
    });
    return `https://login.microsoftonline.com/common/oauth2/v2.0/authorize?${params.toString()}`;
  }

  /**
   * Exchange authorization code for tokens
   */
  exchangeCode(platform: string, code: string): Observable<OAuthToken> {
    return this.http.post<any>(this.apiUrl, {
      action: 'exchange_oauth_code',
      platform,
      code
    }).pipe(
      map(response => {
        if (response.success) {
          return response.data;
        }
        throw new Error(response.message || 'Failed to exchange code');
      }),
      catchError(error => throwError(() => error))
    );
  }

  /**
   * Verify OAuth state parameter
   */
  verifyState(platform: string, state: string): boolean {
    const storedState = sessionStorage.getItem(`oauth_state_${platform}`);
    sessionStorage.removeItem(`oauth_state_${platform}`);
    return storedState === state;
  }

  // ==========================================
  // CONNECTION MANAGEMENT
  // ==========================================

  /**
   * Get all integration statuses
   */
  getAllIntegrationStatuses(): Observable<IntegrationStatus[]> {
    return this.http.post<any>(this.apiUrl, {
      action: 'get_all_integration_statuses'
    }).pipe(
      map(response => {
        if (response.success && Array.isArray(response.data)) {
          return response.data;
        }
        // Return default statuses for all platforms
        return [
          { platform: 'zoom', connected: false, is_active: false },
          { platform: 'google_meet', connected: false, is_active: false },
          { platform: 'microsoft_teams', connected: false, is_active: false }
        ];
      }),
      catchError(() => of([
        { platform: 'zoom', connected: false, is_active: false },
        { platform: 'google_meet', connected: false, is_active: false },
        { platform: 'microsoft_teams', connected: false, is_active: false }
      ]))
    );
  }

  /**
   * Get integration status for a platform (single platform)
   */
  getIntegrationStatus(platform: string): Observable<IntegrationStatus> {
    // Check cache first
    const cached = this.connectionStatusCache.get(platform);
    if (cached && Date.now() - cached.timestamp < this.CACHE_DURATION) {
      return of(cached.status);
    }

    return this.http.post<any>(this.apiUrl, {
      action: 'get_integration_status',
      platform
    }).pipe(
      map(response => {
        const status: IntegrationStatus = response.success ? response.data : {
          platform,
          connected: false,
          is_active: false
        };
        // Cache the result
        this.connectionStatusCache.set(platform, { status, timestamp: Date.now() });
        return status;
      }),
      catchError(() => of({
        platform,
        connected: false,
        is_active: false
      }))
    );
  }

  /**
   * Initiate OAuth connection flow for a platform
   * Opens authorization URL in a popup/redirect
   */
  connect(platform: 'zoom' | 'google_meet' | 'microsoft_teams'): void {
    const authUrl = this.getAuthorizationUrl(platform);
    // Open in same window for proper OAuth redirect
    window.location.href = authUrl;
  }

  /**
   * Exchange authorization code for tokens (alias for exchangeCode)
   */
  exchangeCodeForTokens(platform: string, code: string, state: string): Observable<OAuthToken> {
    // Verify state first
    if (!this.verifyState(platform, state)) {
      return throwError(() => new Error('Invalid OAuth state - possible CSRF attack'));
    }
    return this.exchangeCode(platform, code);
  }

  /**
   * Check if platform is connected (with caching)
   */
  isConnected(platform: string): Observable<boolean> {
    return this.getIntegrationStatus(platform).pipe(
      map(status => status.connected && status.is_active)
    );
  }

  /**
   * Disconnect integration
   */
  disconnect(platform: string): Observable<void> {
    // Clear cache on disconnect
    this.connectionStatusCache.delete(platform);

    return this.http.post<any>(this.apiUrl, {
      action: 'disconnect_integration',
      platform
    }).pipe(
      map(response => {
        if (!response.success) {
          throw new Error(response.message || 'Failed to disconnect');
        }
      }),
      catchError(error => throwError(() => error))
    );
  }

  /**
   * Set default platform for meetings
   */
  setDefaultPlatform(platform: string): Observable<void> {
    return this.http.post<any>(this.apiUrl, {
      action: 'set_default_platform',
      platform
    }).pipe(
      map(response => {
        if (!response.success) {
          throw new Error(response.message || 'Failed to set default platform');
        }
      }),
      catchError(error => throwError(() => error))
    );
  }

  // ==========================================
  // MEETING MANAGEMENT
  // ==========================================

  /**
   * Create a meeting on the specified platform
   */
  createMeeting(platform: string, meetingData: {
    topic: string;
    start_time: string;
    duration: number;
    timezone?: string;
    password?: string;
    settings?: Record<string, any>;
  }): Observable<MeetingDetails> {
    return this.http.post<any>(this.apiUrl, {
      action: 'create_meeting',
      platform,
      ...meetingData
    }).pipe(
      map(response => {
        if (response.success) {
          return response.data;
        }
        throw new Error(response.message || 'Failed to create meeting');
      }),
      catchError(error => throwError(() => error))
    );
  }

  /**
   * Get meeting details
   */
  getMeeting(platform: string, meetingId: string): Observable<MeetingDetails> {
    return this.http.post<any>(this.apiUrl, {
      action: 'get_meeting',
      platform,
      meeting_id: meetingId
    }).pipe(
      map(response => {
        if (response.success) {
          return response.data;
        }
        throw new Error(response.message || 'Failed to get meeting');
      }),
      catchError(error => throwError(() => error))
    );
  }

  /**
   * Update meeting
   */
  updateMeeting(platform: string, meetingId: string, meetingData: Partial<{
    topic: string;
    start_time: string;
    duration: number;
    timezone: string;
    password: string;
    settings: Record<string, any>;
  }>): Observable<MeetingDetails> {
    return this.http.post<any>(this.apiUrl, {
      action: 'update_meeting',
      platform,
      meeting_id: meetingId,
      ...meetingData
    }).pipe(
      map(response => {
        if (response.success) {
          return response.data;
        }
        throw new Error(response.message || 'Failed to update meeting');
      }),
      catchError(error => throwError(() => error))
    );
  }

  /**
   * Delete meeting
   */
  deleteMeeting(platform: string, meetingId: string): Observable<void> {
    return this.http.post<any>(this.apiUrl, {
      action: 'delete_meeting',
      platform,
      meeting_id: meetingId
    }).pipe(
      map(response => {
        if (!response.success) {
          throw new Error(response.message || 'Failed to delete meeting');
        }
      }),
      catchError(error => throwError(() => error))
    );
  }

  /**
   * Get meeting participants
   */
  getMeetingParticipants(platform: string, meetingId: string): Observable<MeetingParticipant[]> {
    return this.http.post<any>(this.apiUrl, {
      action: 'get_meeting_participants',
      platform,
      meeting_id: meetingId
    }).pipe(
      map(response => {
        if (response.success) {
          return response.data || [];
        }
        throw new Error(response.message || 'Failed to get participants');
      }),
      catchError(error => throwError(() => error))
    );
  }

  // ==========================================
  // HELPER METHODS
  // ==========================================

  private getConfig(platform: 'zoom' | 'google_meet' | 'microsoft_teams'): VideoIntegrationConfig {
    switch (platform) {
      case 'zoom':
        return ZOOM_CONFIG;
      case 'google_meet':
        return GOOGLE_CONFIG;
      case 'microsoft_teams':
        return TEAMS_CONFIG;
      default:
        throw new Error(`Unknown platform: ${platform}`);
    }
  }

  private generateState(): string {
    const array = new Uint8Array(16);
    crypto.getRandomValues(array);
    return Array.from(array, byte => byte.toString(16).padStart(2, '0')).join('');
  }

  /**
   * Get display name for platform
   */
  getPlatformDisplayName(platform: string): string {
    const names: Record<string, string> = {
      'zoom': 'Zoom',
      'google_meet': 'Google Meet',
      'microsoft_teams': 'Microsoft Teams'
    };
    return names[platform] || platform;
  }

  /**
   * Get icon class for platform
   */
  getPlatformIcon(platform: string): string {
    const icons: Record<string, string> = {
      'zoom': 'bi-camera-video',
      'google_meet': 'bi-google',
      'microsoft_teams': 'bi-microsoft-teams'
    };
    return icons[platform] || 'bi-camera-video';
  }

  /**
   * Get color class for platform
   */
  getPlatformColor(platform: string): string {
    const colors: Record<string, string> = {
      'zoom': '#2D8CFF',
      'google_meet': '#00897B',
      'microsoft_teams': '#6264A7'
    };
    return colors[platform] || '#6c757d';
  }
}
