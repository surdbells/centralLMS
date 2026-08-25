<?php

/**
 * Video Conferencing Integration Manager
 *
 * Handles integration with Zoom, Microsoft Teams, and Google Meet
 *
 * @package LMS
 * @version 1.0.0
 */

namespace LMS\VideoConferencing;

/**
 * Base interface for video conferencing providers
 */
interface VideoProviderInterface
{
    public function authenticate(string $code): array;
    public function refreshToken(string $refresh_token): array;
    public function createMeeting(array $data): array;
    public function updateMeeting(string $meeting_id, array $data): array;
    public function deleteMeeting(string $meeting_id): bool;
    public function getMeeting(string $meeting_id): array;
    public function getAttendanceReport(string $meeting_id): array;
}

/**
 * Zoom Integration
 */
class ZoomProvider implements VideoProviderInterface
{
    private string $client_id;
    private string $client_secret;
    private string $access_token;
    private string $base_url = 'https://api.zoom.us/v2';
    private string $auth_url = 'https://zoom.us/oauth';

    public function __construct(array $config)
    {
        $this->client_id = $config['client_id'] ?? '';
        $this->client_secret = $config['client_secret'] ?? '';
        $this->access_token = $config['access_token'] ?? '';
    }

    /**
     * Exchange authorization code for tokens
     */
    public function authenticate(string $code): array
    {
        $response = $this->request('POST', "{$this->auth_url}/token", [
            'form_params' => [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->getRedirectUri()
            ],
            'auth' => [$this->client_id, $this->client_secret]
        ], false);

        return [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'],
            'expires_in' => $response['expires_in'],
            'token_expires_at' => date('Y-m-d H:i:s', time() + $response['expires_in'])
        ];
    }

    /**
     * Refresh access token
     */
    public function refreshToken(string $refresh_token): array
    {
        $response = $this->request('POST', "{$this->auth_url}/token", [
            'form_params' => [
                'grant_type' => 'refresh_token',
                'refresh_token' => $refresh_token
            ],
            'auth' => [$this->client_id, $this->client_secret]
        ], false);

        return [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? $refresh_token,
            'expires_in' => $response['expires_in'],
            'token_expires_at' => date('Y-m-d H:i:s', time() + $response['expires_in'])
        ];
    }

    /**
     * Create a Zoom meeting
     */
    public function createMeeting(array $data): array
    {
        $meeting_data = [
            'topic' => $data['title'],
            'type' => 2, // Scheduled meeting
            'start_time' => $this->formatDateTime($data['start_datetime']),
            'duration' => $data['duration_minutes'] ?? 60,
            'timezone' => $data['timezone'] ?? 'UTC',
            'agenda' => $data['description'] ?? '',
            'settings' => [
                'host_video' => true,
                'participant_video' => true,
                'join_before_host' => $data['allow_early_join'] ?? false,
                'mute_upon_entry' => true,
                'waiting_room' => $data['waiting_room'] ?? true,
                'auto_recording' => $data['auto_record'] ?? 'none',
                'meeting_authentication' => false
            ]
        ];

        if (!empty($data['password'])) {
            $meeting_data['password'] = $data['password'];
        }

        $response = $this->request('POST', '/users/me/meetings', ['json' => $meeting_data]);

        return [
            'meeting_id' => (string) $response['id'],
            'meeting_url' => $response['join_url'],
            'host_url' => $response['start_url'],
            'password' => $response['password'] ?? null,
            'provider' => 'zoom',
            'raw_response' => $response
        ];
    }

    /**
     * Update a Zoom meeting
     */
    public function updateMeeting(string $meeting_id, array $data): array
    {
        $update_data = [];

        if (isset($data['title'])) $update_data['topic'] = $data['title'];
        if (isset($data['start_datetime'])) $update_data['start_time'] = $this->formatDateTime($data['start_datetime']);
        if (isset($data['duration_minutes'])) $update_data['duration'] = $data['duration_minutes'];
        if (isset($data['description'])) $update_data['agenda'] = $data['description'];

        $this->request('PATCH', "/meetings/{$meeting_id}", ['json' => $update_data]);

        return $this->getMeeting($meeting_id);
    }

    /**
     * Delete a Zoom meeting
     */
    public function deleteMeeting(string $meeting_id): bool
    {
        $this->request('DELETE', "/meetings/{$meeting_id}");
        return true;
    }

    /**
     * Get meeting details
     */
    public function getMeeting(string $meeting_id): array
    {
        $response = $this->request('GET', "/meetings/{$meeting_id}");

        return [
            'meeting_id' => (string) $response['id'],
            'topic' => $response['topic'],
            'start_time' => $response['start_time'],
            'duration' => $response['duration'],
            'join_url' => $response['join_url'],
            'host_url' => $response['start_url'] ?? null,
            'status' => $response['status'],
            'provider' => 'zoom'
        ];
    }

    /**
     * Get meeting attendance report
     */
    public function getAttendanceReport(string $meeting_id): array
    {
        try {
            // Get past meeting instances
            $instances = $this->request('GET', "/past_meetings/{$meeting_id}/instances");

            $attendees = [];
            foreach ($instances['meetings'] ?? [] as $instance) {
                $participants = $this->request('GET', "/past_meetings/{$instance['uuid']}/participants");

                foreach ($participants['participants'] ?? [] as $participant) {
                    $attendees[] = [
                        'email' => $participant['user_email'] ?? null,
                        'name' => $participant['name'] ?? 'Unknown',
                        'join_time' => $participant['join_time'],
                        'leave_time' => $participant['leave_time'],
                        'duration_minutes' => $participant['duration'] ?? 0,
                        'attentiveness_score' => $participant['attentiveness_score'] ?? null
                    ];
                }
            }

            return [
                'meeting_id' => $meeting_id,
                'attendees' => $attendees,
                'total_participants' => count($attendees)
            ];
        } catch (\Exception $e) {
            return ['meeting_id' => $meeting_id, 'attendees' => [], 'error' => $e->getMessage()];
        }
    }

    private function request(string $method, string $endpoint, array $options = [], bool $useAuth = true): array
    {
        $url = strpos($endpoint, 'http') === 0 ? $endpoint : $this->base_url . $endpoint;

        $headers = ['Content-Type' => 'application/json'];
        if ($useAuth) {
            $headers['Authorization'] = "Bearer {$this->access_token}";
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers)
        ]);

        if (isset($options['json'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options['json']));
        }

        if (isset($options['form_params'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($options['form_params']));
        }

        if (isset($options['auth'])) {
            curl_setopt($ch, CURLOPT_USERPWD, implode(':', $options['auth']));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \Exception("Zoom API Error: " . $response);
        }

        return json_decode($response, true) ?? [];
    }

    private function formatHeaders(array $headers): array
    {
        return array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers);
    }

    private function formatDateTime(string $datetime): string
    {
        return date('Y-m-d\TH:i:s\Z', strtotime($datetime));
    }

    private function getRedirectUri(): string
    {
        return rtrim($_ENV['APP_URL'] ?? '', '/') . '/api/integrations/zoom/callback';
    }
}

/**
 * Microsoft Teams Integration
 */
class MicrosoftTeamsProvider implements VideoProviderInterface
{
    private string $client_id;
    private string $client_secret;
    private string $access_token;
    private string $tenant_id;
    private string $base_url = 'https://graph.microsoft.com/v1.0';
    private string $auth_url = 'https://login.microsoftonline.com';

    public function __construct(array $config)
    {
        $this->client_id = $config['client_id'] ?? '';
        $this->client_secret = $config['client_secret'] ?? '';
        $this->access_token = $config['access_token'] ?? '';
        $this->tenant_id = $config['tenant_id'] ?? 'common';
    }

    public function authenticate(string $code): array
    {
        $response = $this->request('POST', "{$this->auth_url}/{$this->tenant_id}/oauth2/v2.0/token", [
            'form_params' => [
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'code' => $code,
                'redirect_uri' => $this->getRedirectUri(),
                'grant_type' => 'authorization_code',
                'scope' => 'https://graph.microsoft.com/.default offline_access'
            ]
        ], false);

        return [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'],
            'expires_in' => $response['expires_in'],
            'token_expires_at' => date('Y-m-d H:i:s', time() + $response['expires_in'])
        ];
    }

    public function refreshToken(string $refresh_token): array
    {
        $response = $this->request('POST', "{$this->auth_url}/{$this->tenant_id}/oauth2/v2.0/token", [
            'form_params' => [
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'refresh_token' => $refresh_token,
                'grant_type' => 'refresh_token',
                'scope' => 'https://graph.microsoft.com/.default offline_access'
            ]
        ], false);

        return [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'] ?? $refresh_token,
            'expires_in' => $response['expires_in'],
            'token_expires_at' => date('Y-m-d H:i:s', time() + $response['expires_in'])
        ];
    }

    public function createMeeting(array $data): array
    {
        $start_time = new \DateTime($data['start_datetime']);
        $end_time = clone $start_time;
        $end_time->modify("+{$data['duration_minutes']} minutes");

        $meeting_data = [
            'subject' => $data['title'],
            'startDateTime' => $start_time->format('Y-m-d\TH:i:s'),
            'endDateTime' => $end_time->format('Y-m-d\TH:i:s'),
            'lobbyBypassSettings' => [
                'scope' => $data['waiting_room'] ?? true ? 'organization' : 'everyone'
            ],
            'isEntryExitAnnounced' => true,
            'allowedPresenters' => 'everyone'
        ];

        $response = $this->request('POST', '/me/onlineMeetings', ['json' => $meeting_data]);

        return [
            'meeting_id' => $response['id'],
            'meeting_url' => $response['joinWebUrl'],
            'host_url' => $response['joinWebUrl'],
            'dial_in' => $response['audioConferencing'] ?? null,
            'provider' => 'microsoft_teams',
            'raw_response' => $response
        ];
    }

    public function updateMeeting(string $meeting_id, array $data): array
    {
        $update_data = [];

        if (isset($data['title'])) $update_data['subject'] = $data['title'];
        if (isset($data['start_datetime'])) {
            $start_time = new \DateTime($data['start_datetime']);
            $update_data['startDateTime'] = $start_time->format('Y-m-d\TH:i:s');

            if (isset($data['duration_minutes'])) {
                $end_time = clone $start_time;
                $end_time->modify("+{$data['duration_minutes']} minutes");
                $update_data['endDateTime'] = $end_time->format('Y-m-d\TH:i:s');
            }
        }

        $this->request('PATCH', "/me/onlineMeetings/{$meeting_id}", ['json' => $update_data]);
        return $this->getMeeting($meeting_id);
    }

    public function deleteMeeting(string $meeting_id): bool
    {
        $this->request('DELETE', "/me/onlineMeetings/{$meeting_id}");
        return true;
    }

    public function getMeeting(string $meeting_id): array
    {
        $response = $this->request('GET', "/me/onlineMeetings/{$meeting_id}");

        return [
            'meeting_id' => $response['id'],
            'subject' => $response['subject'],
            'start_time' => $response['startDateTime'],
            'end_time' => $response['endDateTime'],
            'join_url' => $response['joinWebUrl'],
            'provider' => 'microsoft_teams'
        ];
    }

    public function getAttendanceReport(string $meeting_id): array
    {
        try {
            $response = $this->request('GET', "/me/onlineMeetings/{$meeting_id}/attendanceReports");

            $attendees = [];
            foreach ($response['value'] ?? [] as $report) {
                $records = $this->request('GET', "/me/onlineMeetings/{$meeting_id}/attendanceReports/{$report['id']}/attendanceRecords");

                foreach ($records['value'] ?? [] as $record) {
                    $attendees[] = [
                        'email' => $record['emailAddress'] ?? null,
                        'name' => $record['identity']['displayName'] ?? 'Unknown',
                        'join_time' => $record['attendanceIntervals'][0]['joinDateTime'] ?? null,
                        'leave_time' => $record['attendanceIntervals'][0]['leaveDateTime'] ?? null,
                        'duration_minutes' => $record['totalAttendanceInSeconds'] / 60 ?? 0
                    ];
                }
            }

            return [
                'meeting_id' => $meeting_id,
                'attendees' => $attendees,
                'total_participants' => count($attendees)
            ];
        } catch (\Exception $e) {
            return ['meeting_id' => $meeting_id, 'attendees' => [], 'error' => $e->getMessage()];
        }
    }

    private function request(string $method, string $endpoint, array $options = [], bool $useAuth = true): array
    {
        $url = strpos($endpoint, 'http') === 0 ? $endpoint : $this->base_url . $endpoint;

        $headers = ['Content-Type' => 'application/json'];
        if ($useAuth) {
            $headers['Authorization'] = "Bearer {$this->access_token}";
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers)
        ]);

        if (isset($options['json'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options['json']));
        }

        if (isset($options['form_params'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($options['form_params']));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \Exception("Microsoft Graph API Error: " . $response);
        }

        return json_decode($response, true) ?? [];
    }

    private function getRedirectUri(): string
    {
        return rtrim($_ENV['APP_URL'] ?? '', '/') . '/api/integrations/teams/callback';
    }
}

/**
 * Google Meet Integration
 */
class GoogleMeetProvider implements VideoProviderInterface
{
    private string $client_id;
    private string $client_secret;
    private string $access_token;
    private string $calendar_api = 'https://www.googleapis.com/calendar/v3';
    private string $auth_url = 'https://oauth2.googleapis.com';

    public function __construct(array $config)
    {
        $this->client_id = $config['client_id'] ?? '';
        $this->client_secret = $config['client_secret'] ?? '';
        $this->access_token = $config['access_token'] ?? '';
    }

    public function authenticate(string $code): array
    {
        $response = $this->request('POST', "{$this->auth_url}/token", [
            'form_params' => [
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'code' => $code,
                'redirect_uri' => $this->getRedirectUri(),
                'grant_type' => 'authorization_code'
            ]
        ], false);

        return [
            'access_token' => $response['access_token'],
            'refresh_token' => $response['refresh_token'],
            'expires_in' => $response['expires_in'],
            'token_expires_at' => date('Y-m-d H:i:s', time() + $response['expires_in'])
        ];
    }

    public function refreshToken(string $refresh_token): array
    {
        $response = $this->request('POST', "{$this->auth_url}/token", [
            'form_params' => [
                'client_id' => $this->client_id,
                'client_secret' => $this->client_secret,
                'refresh_token' => $refresh_token,
                'grant_type' => 'refresh_token'
            ]
        ], false);

        return [
            'access_token' => $response['access_token'],
            'refresh_token' => $refresh_token,
            'expires_in' => $response['expires_in'],
            'token_expires_at' => date('Y-m-d H:i:s', time() + $response['expires_in'])
        ];
    }

    public function createMeeting(array $data): array
    {
        $start_time = new \DateTime($data['start_datetime']);
        $end_time = clone $start_time;
        $end_time->modify("+{$data['duration_minutes']} minutes");

        // Create a calendar event with Google Meet
        $event_data = [
            'summary' => $data['title'],
            'description' => $data['description'] ?? '',
            'start' => [
                'dateTime' => $start_time->format('c'),
                'timeZone' => $data['timezone'] ?? 'UTC'
            ],
            'end' => [
                'dateTime' => $end_time->format('c'),
                'timeZone' => $data['timezone'] ?? 'UTC'
            ],
            'conferenceData' => [
                'createRequest' => [
                    'requestId' => uniqid('meet_'),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet']
                ]
            ]
        ];

        $response = $this->request(
            'POST',
            "{$this->calendar_api}/calendars/primary/events?conferenceDataVersion=1",
            ['json' => $event_data]
        );

        $meet_link = $response['conferenceData']['entryPoints'][0]['uri'] ?? null;

        return [
            'meeting_id' => $response['id'],
            'meeting_url' => $meet_link,
            'host_url' => $meet_link,
            'calendar_event_id' => $response['id'],
            'provider' => 'google_meet',
            'raw_response' => $response
        ];
    }

    public function updateMeeting(string $meeting_id, array $data): array
    {
        $update_data = [];

        if (isset($data['title'])) $update_data['summary'] = $data['title'];
        if (isset($data['description'])) $update_data['description'] = $data['description'];

        if (isset($data['start_datetime'])) {
            $start_time = new \DateTime($data['start_datetime']);
            $update_data['start'] = [
                'dateTime' => $start_time->format('c'),
                'timeZone' => $data['timezone'] ?? 'UTC'
            ];

            if (isset($data['duration_minutes'])) {
                $end_time = clone $start_time;
                $end_time->modify("+{$data['duration_minutes']} minutes");
                $update_data['end'] = [
                    'dateTime' => $end_time->format('c'),
                    'timeZone' => $data['timezone'] ?? 'UTC'
                ];
            }
        }

        $this->request('PATCH', "{$this->calendar_api}/calendars/primary/events/{$meeting_id}", ['json' => $update_data]);
        return $this->getMeeting($meeting_id);
    }

    public function deleteMeeting(string $meeting_id): bool
    {
        $this->request('DELETE', "{$this->calendar_api}/calendars/primary/events/{$meeting_id}");
        return true;
    }

    public function getMeeting(string $meeting_id): array
    {
        $response = $this->request('GET', "{$this->calendar_api}/calendars/primary/events/{$meeting_id}");

        $meet_link = $response['conferenceData']['entryPoints'][0]['uri'] ?? null;

        return [
            'meeting_id' => $response['id'],
            'summary' => $response['summary'],
            'start_time' => $response['start']['dateTime'],
            'end_time' => $response['end']['dateTime'],
            'join_url' => $meet_link,
            'provider' => 'google_meet'
        ];
    }

    public function getAttendanceReport(string $meeting_id): array
    {
        // Google Meet attendance reports require Workspace admin access
        // This is a simplified implementation
        return [
            'meeting_id' => $meeting_id,
            'attendees' => [],
            'note' => 'Google Meet attendance requires Workspace Admin SDK'
        ];
    }

    private function request(string $method, string $endpoint, array $options = [], bool $useAuth = true): array
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($useAuth) {
            $headers['Authorization'] = "Bearer {$this->access_token}";
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers)
        ]);

        if (isset($options['json'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($options['json']));
        }

        if (isset($options['form_params'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($options['form_params']));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \Exception("Google API Error: " . $response);
        }

        return json_decode($response, true) ?? [];
    }

    private function getRedirectUri(): string
    {
        return rtrim($_ENV['APP_URL'] ?? '', '/') . '/api/integrations/google/callback';
    }
}

/**
 * Video Provider Factory
 */
class VideoProviderFactory
{
    public static function create(string $platform, array $config): VideoProviderInterface
    {
        switch ($platform) {
            case 'zoom':
                return new ZoomProvider($config);
            case 'microsoft_teams':
                return new MicrosoftTeamsProvider($config);
            case 'google_meet':
                return new GoogleMeetProvider($config);
            default:
                throw new \InvalidArgumentException("Unknown video platform: {$platform}");
        }
    }
}

/**
 * Integration Manager - handles all video conferencing operations
 */
class VideoIntegrationManager
{
    private $conn;
    private int $tenant_id;

    public function __construct($db_connection, int $tenant_id)
    {
        $this->conn = $db_connection;
        $this->tenant_id = $tenant_id;
    }

    /**
     * Get provider instance with valid tokens
     */
    public function getProvider(string $platform): VideoProviderInterface
    {
        $integration = $this->getIntegration($platform);

        if (!$integration) {
            throw new \Exception("Integration not found for platform: {$platform}");
        }

        // Check if token needs refresh
        if ($this->tokenNeedsRefresh($integration)) {
            $integration = $this->refreshIntegrationToken($integration);
        }

        return VideoProviderFactory::create($platform, [
            'client_id' => $integration['client_id'],
            'client_secret' => $integration['client_secret'],
            'access_token' => $integration['access_token'],
            'tenant_id' => $integration['settings']['tenant_id'] ?? null
        ]);
    }

    /**
     * Create meeting for a session
     */
    public function createSessionMeeting(int $session_id, array $session_data): array
    {
        $platform = $session_data['video_platform'];
        $provider = $this->getProvider($platform);

        $meeting = $provider->createMeeting([
            'title' => $session_data['title'],
            'description' => $session_data['description'] ?? '',
            'start_datetime' => "{$session_data['session_date']} {$session_data['start_time']}",
            'duration_minutes' => $this->calculateDuration($session_data['start_time'], $session_data['end_time']),
            'timezone' => $session_data['timezone'] ?? 'UTC',
            'waiting_room' => true
        ]);

        // Update session with meeting details
        $this->updateSessionMeetingData($session_id, $meeting);

        return $meeting;
    }

    /**
     * Sync attendance from video platform
     */
    public function syncSessionAttendance(int $session_id): array
    {
        $stmt = $this->conn->prepare("SELECT * FROM live_training_sessions WHERE session_id = ?");
        $stmt->bind_param("i", $session_id);
        $stmt->execute();
        $session = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$session || !$session['video_platform'] || !$session['external_meeting_id']) {
            return ['synced' => 0];
        }

        $provider = $this->getProvider($session['video_platform']);
        $report = $provider->getAttendanceReport($session['external_meeting_id']);

        $synced = 0;
        foreach ($report['attendees'] as $attendee) {
            if ($this->updateAttendanceFromReport($session_id, $attendee)) {
                $synced++;
            }
        }

        return ['synced' => $synced, 'total' => count($report['attendees'])];
    }

    private function getIntegration(string $platform): ?array
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM tenant_video_integrations WHERE tenant_id = ? AND platform = ? AND is_enabled = 1"
        );
        $stmt->bind_param("is", $this->tenant_id, $platform);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($result) {
            $result['settings'] = json_decode($result['settings'], true) ?? [];
        }

        return $result;
    }

    private function tokenNeedsRefresh(array $integration): bool
    {
        if (!$integration['token_expires_at']) return false;
        $expires = new \DateTime($integration['token_expires_at']);
        $now = new \DateTime();
        $now->modify('+5 minutes'); // Refresh 5 minutes before expiry
        return $now >= $expires;
    }

    private function refreshIntegrationToken(array $integration): array
    {
        $provider = VideoProviderFactory::create($integration['platform'], [
            'client_id' => $integration['client_id'],
            'client_secret' => $integration['client_secret']
        ]);

        $tokens = $provider->refreshToken($integration['refresh_token']);

        // Update database
        $stmt = $this->conn->prepare(
            "UPDATE tenant_video_integrations SET 
                access_token = ?, refresh_token = ?, token_expires_at = ?, last_refreshed_at = NOW()
             WHERE integration_id = ?"
        );
        $stmt->bind_param(
            "sssi",
            $tokens['access_token'],
            $tokens['refresh_token'],
            $tokens['token_expires_at'],
            $integration['integration_id']
        );
        $stmt->execute();
        $stmt->close();

        $integration['access_token'] = $tokens['access_token'];
        $integration['refresh_token'] = $tokens['refresh_token'];
        $integration['token_expires_at'] = $tokens['token_expires_at'];

        return $integration;
    }

    private function updateSessionMeetingData(int $session_id, array $meeting): void
    {
        $stmt = $this->conn->prepare(
            "UPDATE live_training_sessions SET 
                meeting_id = ?, meeting_url = ?, join_url = ?, host_url = ?, external_meeting_id = ?
             WHERE session_id = ?"
        );
        $stmt->bind_param(
            "sssssi",
            $meeting['meeting_id'],
            $meeting['meeting_url'],
            $meeting['meeting_url'],
            $meeting['host_url'],
            $meeting['meeting_id'],
            $session_id
        );
        $stmt->execute();
        $stmt->close();
    }

    private function updateAttendanceFromReport(int $session_id, array $attendee): bool
    {
        // Find user by email
        $stmt = $this->conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $attendee['email']);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$user) return false;

        $duration = $attendee['duration_minutes'] ?? 0;
        $status = $duration >= 30 ? 'present' : 'absent'; // Min 30 min for present

        $stmt = $this->conn->prepare(
            "UPDATE live_training_attendance SET 
                status = ?, attendance_mode = 'virtual',
                virtual_join_time = ?, virtual_leave_time = ?, virtual_duration_minutes = ?,
                marked_at = NOW()
             WHERE session_id = ? AND user_id = ?"
        );
        $stmt->bind_param(
            "sssiii",
            $status,
            $attendee['join_time'],
            $attendee['leave_time'],
            $duration,
            $session_id,
            $user['id']
        );
        $result = $stmt->execute();
        $stmt->close();

        return $result;
    }

    private function calculateDuration(string $start_time, string $end_time): int
    {
        $start = strtotime($start_time);
        $end = strtotime($end_time);
        return ($end - $start) / 60;
    }
}