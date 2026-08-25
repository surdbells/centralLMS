# ILT / virtual sessions — what was wrong and what replaces it

## The bug, precisely

`legacy/backend/class/LiveTraining.php:1628`:

```php
private function createVirtualMeeting(int $session_id, array $data): void
{
    // This would call the appropriate platform API
    // For now, just store placeholder data
    $meeting_url = "https://meet.example.com/" . bin2hex(random_bytes(8));
    ...
}
```

Every virtual or hybrid session ever created got a fake link. Meanwhile
`legacy/backend/class/VideoIntegrations.php` — 896 lines implementing all three
platforms competently — has **zero call sites** for
`VideoIntegrationManager::createSessionMeeting()` and `syncSessionAttendance()`.

So the feature was never broken so much as never connected. Two consequences
shape the rewrite:

- The legacy provider code is worth reading closely and porting, not
  discarding. It got the hard parts right (per-tenant tokens, refresh, an
  attendance report call).
- The wiring is the deliverable. A provider layer nobody calls is worth exactly
  nothing, which is the single most important lesson from the old code.

## What the schema says

`tenant_video_integrations` holds `client_id`, `client_secret`, `access_token`,
`refresh_token`, `token_expires_at`, `settings`, per tenant per platform. So:

- Credentials are **per tenant**, obtained by OAuth authorization-code flow —
  each customer connects their own Zoom/Microsoft/Google account.
- They are **not** deployment-level environment settings. An early draft of the
  new API assumed server-to-server credentials in `.env`; that was wrong and has
  been corrected.
- Platform identifiers are `zoom`, `google_meet`, `microsoft_teams`. The new
  enum uses those exact strings so the data migration needs no translation.

## The replacement — status

### Done and verified

| Piece | Where |
| --- | --- |
| `MeetingProviderId` (matching legacy enum values) | `apps/api/src/Domain/Ilt/MeetingProviderId.php` |
| `MeetingProvider` — schedule/reschedule/cancel/find/**fetchAttendance** | `Domain/Ilt/MeetingProvider.php` |
| `VideoOAuthFlow` — authorizationUrl/exchangeCode/refresh | `Domain/Ilt/VideoOAuthFlow.php` |
| `VideoIntegration` + repository contract | `Domain/Ilt/` |
| `MeetingRequest`, `Meeting`, `AttendanceRecord`, `TokenGrant` | `Domain/Ilt/` |
| Zoom / Google Meet / Teams adapters | `Infrastructure/Ilt/` |
| Shared transport, token refresh + persistence, error mapping | `Infrastructure/Ilt/AbstractMeetingProvider.php` |
| `MeetingProviderFactory` (replaces factory + manager) | `Infrastructure/Ilt/` |
| Encrypted credential storage (AES-256-GCM) | `Support/Cipher.php`, `Infrastructure/Persistence/DbalVideoIntegrationRepository.php` |
| `video_integrations` table | `migrations/Version20260825120000.php` — applied and round-trip tested |
| `GET /v1/ilt/providers` | `Application/Action/Ilt/ListMeetingProvidersAction.php` |
| `php bin/console ilt:check-providers [--tenant=…]` | `Application/Console/` |

Improvements over the legacy behaviour, each deliberate:

- **Credentials encrypted at rest.** The legacy table stored every customer's
  refresh token in plaintext.
- **Refreshed tokens persisted immediately**, outside any surrounding
  transaction. Zoom rotates the refresh token on every use and invalidates the
  old one, so a lost write permanently disconnects that tenant.
- **One default platform per tenant**, enforced by a partial unique index. The
  legacy schema allowed several rows with `is_default = 1`.
- **Idempotent cancel.** Deleting an already-deleted meeting is success, so a
  retrying queue worker cannot fail forever.
- **Empty attendance means "not ready", never "nobody came."** Reports appear
  minutes to hours after a meeting ends, and a missing report must never mark a
  room full of learners absent.
- **Capability-aware UI.** `GET /v1/ilt/providers` reports what this deployment
  supports and what this tenant has connected, so the session form cannot offer
  a platform that would fail on save.

### Not done yet — needs the surrounding domain

These depend on tables that have not been migrated (`tenants`, `users`,
`live_training_sessions`, `live_training_attendance`):

1. **OAuth connect endpoints** — `GET /v1/ilt/integrations/{platform}/authorize`
   and the callback. The adapters expose `authorizationUrl()` / `exchangeCode()`;
   the routes need a `tenants` table and a signed `state` store.
2. **Session scheduling wired to the provider** — the port of
   `createVirtualMeeting`, this time actually calling
   `MeetingProviderFactory::forTenant(...)->schedule(...)`. **This is the fix.**
3. **Attendance sync job** — a Messenger handler on a cron, calling
   `fetchAttendance()` and reconciling against
   `live_training_attendance` using each training's
   `min_virtual_duration_minutes`.
4. **Webhooks** — the legacy `user/video-webhooks.php` handles Zoom meeting
   lifecycle events; the Zoom signature check needs
   `ZOOM_WEBHOOK_SECRET_TOKEN`.

### Untested against live accounts

The three adapters are written against current API documentation but have **not
been exercised against real Zoom, Google or Microsoft accounts** — no
credentials were available. Treat them as needing an integration pass before
they go anywhere near production. `ilt:check-providers --tenant=<id>` is the
first thing to run once credentials exist.

## Configuration model

One OAuth app registration per platform belongs to us and lives in `.env`
(`ZOOM_CLIENT_ID`, `GOOGLE_CLIENT_ID`, `MS_CLIENT_ID`, …). Each tenant then
consents to that app, and their tokens go in `video_integrations`, encrypted
with `CREDENTIALS_ENCRYPTION_KEY`.

A tenant that insists on its own OAuth app can store `client_id` /
`client_secret` on its own row; the adapters prefer the row and fall back to
ours.

### Required scopes

| Platform | Scopes |
| --- | --- |
| Zoom | `meeting:write`, `meeting:read`, `user:read`, `report:read:admin` (attendance; needs a paid plan) |
| Google Meet | `calendar.events`, `admin.reports.audit.readonly` (attendance; connecting user must be a Workspace admin) |
| Teams | `offline_access`, `OnlineMeetings.ReadWrite`, `OnlineMeetingArtifact.Read.All` |

Notes worth keeping:

- Google needs `access_type=offline` **and** `prompt=consent`, or it issues no
  refresh token and the connection dies after an hour.
- Teams uses *delegated* permissions, not app-only. App-only would require a
  `New-CsApplicationAccessPolicy` PowerShell step in every customer tenant.
- Meet cannot start a cloud recording from the API; the UI hides that toggle
  for it via `MeetingProviderId::supportsCloudRecording()`.

## Schema decision still open

`live_sessions` (14 columns, course-attached) versus `live_trainings` +
`live_training_sessions` (50+ columns, venues, QR attendance, costs, hybrid).
The latter supersedes the former in every respect. Confirm `live_sessions` is
dead before porting, and record the answer in
[`inventory.md`](inventory.md#next-decisions-needed-from-the-team).
