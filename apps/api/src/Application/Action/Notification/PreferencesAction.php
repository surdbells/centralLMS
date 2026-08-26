<?php

declare(strict_types=1);

namespace Central\Api\Application\Action\Notification;

use Central\Api\Application\Http\Json;
use Central\Api\Domain\Exception\ValidationFailed;
use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationPreference;
use Central\Api\Domain\Notification\NotificationType;
use Central\Api\Domain\Notification\QuietHours;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What each person wants to be told about, and when.
 *
 * GET /v1/notifications/preferences
 * PUT /v1/notifications/preferences
 *
 * Own preferences only — there is no user id in the path, and an administrator
 * setting somebody else's notification preferences is not a feature anybody
 * asked for.
 *
 * GET returns the whole catalogue with each type's current state, not just the
 * stored rows. Rows are opt-outs, so a fresh account has none, and a screen
 * built from the stored rows alone would show a person nothing to configure.
 */
final class PreferencesAction extends NotificationAction
{
    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->tenantId($request);
        $userId   = $this->userId($request);

        $stored = $this->notifications->preferencesFor($tenantId, $userId);
        $known  = [];

        foreach ($stored as $preference) {
            // '*' is the channel-wide row's key. A type named '*' would
            // collide, which is one more reason the catalogue is a closed enum.
            $key          = ($preference->type === null ? '*' : $preference->type->value)
                . '|' . $preference->channel->value;
            $known[$key]  = $preference->enabled;
        }

        $available = [
            NotificationChannel::InApp,
            NotificationChannel::Email,
            ...$this->notifications->entitledChannels($tenantId),
        ];

        $types = [];

        foreach (NotificationType::cases() as $type) {
            $channels = [];

            foreach ($available as $channel) {
                $key = $type->value . '|' . $channel->value;

                $channels[] = [
                    'channel'    => $channel->value,
                    'label'      => $channel->label(),
                    // The type row wins over the channel-wide one, matching
                    // what Recipient::allows() does at send time. Two places
                    // implementing this rule differently is how a preference
                    // screen ends up lying.
                    'enabled'    => $known[$key] ?? $known['*|' . $channel->value] ?? true,
                    'isDefault'  => in_array($channel, $type->defaultChannels(), true),
                    // The one thing the screen must not offer to switch off.
                    'locked'     => $channel === NotificationChannel::InApp && !$type->isOptional(),
                ];
            }

            $types[] = [
                'type'     => $type->value,
                'label'    => $type->label(),
                'optional' => $type->isOptional(),
                'channels' => $channels,
            ];
        }

        return Json::ok($response, [
            'types'      => $types,
            'quietHours' => $this->quietHoursPayload($tenantId, $userId),
        ]);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tenantId = $this->tenantId($request);
        $userId   = $this->userId($request);
        $body     = $this->body($request);

        $violations = [];

        // Quiet hours and preference rows travel together, because a settings
        // screen saves them together. Either may be absent.
        if (array_key_exists('quietHours', $body)) {
            $this->saveQuietHours($tenantId, $userId, $body['quietHours'], $violations);
        }

        $saved = [];

        if (isset($body['preferences'])) {
            if (!is_array($body['preferences'])) {
                $violations['preferences'] = 'Send a list of preferences.';
            } else {
                foreach (array_values($body['preferences']) as $index => $row) {
                    if (!is_array($row)) {
                        $violations['preferences.' . $index] = 'Each preference is an object.';

                        continue;
                    }

                    $rowViolations = [];
                    $preference    = NotificationPreference::fromInput($tenantId, $userId, $row, $rowViolations);

                    foreach ($rowViolations as $field => $message) {
                        $violations['preferences.' . $index . '.' . $field] = $message;
                    }

                    if ($preference !== null) {
                        $saved[] = $preference;
                    }
                }
            }
        }

        // Nothing is written unless all of it is valid: a settings screen that
        // saves four rows out of five and reports an error leaves the person
        // unable to tell which four.
        if ($violations !== []) {
            throw ValidationFailed::fromArray($violations);
        }

        foreach ($saved as $preference) {
            $this->notifications->savePreference($preference);
        }

        return $this->show($request, $response);
    }

    /**
     * @param array<string, string> $violations
     */
    private function saveQuietHours(
        string $tenantId,
        string $userId,
        mixed $value,
        array &$violations,
    ): void {
        if ($value === null) {
            $this->notifications->saveQuietHours($tenantId, $userId, null);

            return;
        }

        if (!is_array($value)) {
            $violations['quietHours'] = 'Send {"start": "22:00", "end": "07:00"}, or null to clear it.';

            return;
        }

        $start = isset($value['start']) ? (string) $value['start'] : null;
        $end   = isset($value['end']) ? (string) $value['end'] : null;

        if ($start === null || $end === null) {
            $violations['quietHours'] = 'Both ends are needed — a window with one end is not a window.';

            return;
        }

        $quietHours = QuietHours::tryBetween($start, $end);

        if ($quietHours === null) {
            $violations['quietHours'] = 'Times look like 22:00.';

            return;
        }

        $this->notifications->saveQuietHours($tenantId, $userId, $quietHours);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function quietHoursPayload(string $tenantId, string $userId): ?array
    {
        foreach ($this->notifications->recipients($tenantId, [$userId]) as $recipient) {
            $quiet = $recipient->quietHours;

            return $quiet === null ? null : [
                'start'          => $quiet->start(),
                'end'            => $quiet->end(),
                'timezone'       => $recipient->timezone,
                'wrapsMidnight'  => $quiet->wrapsMidnight(),
                // Only SMS and push are held back. Saying so on the screen
                // stops someone setting quiet hours and then wondering why
                // the bell still lit up overnight.
                'appliesTo'      => [NotificationChannel::Sms->value, NotificationChannel::Push->value],
            ];
        }

        return null;
    }
}
