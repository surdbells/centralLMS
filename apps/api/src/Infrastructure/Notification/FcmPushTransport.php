<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Notification;

use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationTransport;
use Central\Api\Domain\Notification\PendingDelivery;
use Central\Api\Domain\Notification\TransportResult;
use Psr\Log\LoggerInterface;

/**
 * Push via Firebase Cloud Messaging.
 *
 * FCM's v1 API sends to one token per request and one person can have several
 * — a phone, a tablet, a reinstalled phone that left a dead token behind. So
 * this fans out, and the interesting decision is how to score the result:
 *
 * **One token succeeding is a success.** A dead token from an app somebody
 * uninstalled last year would otherwise fail the delivery, retry three times
 * and end as a red row on an admin screen describing a message the person
 * actually received on their current phone.
 *
 * A token FCM reports as unregistered is a permanent rejection — retrying
 * cannot help.
 */
final readonly class FcmPushTransport implements NotificationTransport
{
    public function __construct(
        private HttpSender $http,
        private string $projectId,
        private AccessTokenProvider $tokens,
        private LoggerInterface $logger,
    ) {
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Push;
    }

    public function isConfigured(): bool
    {
        return trim($this->projectId) !== '' && $this->tokens->isConfigured();
    }

    public function send(PendingDelivery $delivery): TransportResult
    {
        $tokens = $delivery->addresses();

        if ($tokens === []) {
            return TransportResult::rejected('No device tokens on record.');
        }

        try {
            $accessToken = $this->tokens->accessToken();
        } catch (\Throwable $e) {
            // A credentials problem rather than a delivery one, but it can be
            // transient — a clock skew, a blip fetching the token — so it is
            // worth another attempt.
            return TransportResult::failed('Could not authenticate with FCM: ' . $e->getMessage());
        }

        $url = sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $this->projectId);

        $sent      = 0;
        $messageId = null;
        $errors    = [];
        $permanent = 0;

        foreach ($tokens as $token) {
            $response = $this->http->postJson(
                $url,
                [
                    'message' => [
                        'token'        => $token,
                        'notification' => ['title' => $delivery->title, 'body' => $delivery->body],
                        'data'         => [
                            'type'           => $delivery->type->value,
                            'notificationId' => $delivery->notificationId,
                        ],
                    ],
                ],
                ['Authorization' => 'Bearer ' . $accessToken],
            );

            $status = $response['status'];

            if ($status >= 200 && $status < 300) {
                $sent++;

                $name      = HttpSender::decode($response['body'])['name'] ?? null;
                $messageId ??= is_string($name) ? $name : null;

                continue;
            }

            if ($status === 404 || $status === 400) {
                $permanent++;

                $this->logger->info('FCM rejected a device token', [
                    'status' => $status,
                    'token'  => substr($token, 0, 12) . '…',
                ]);
            }

            $errors[] = $status === 0
                ? ($response['error'] ?? 'unreachable')
                : sprintf('HTTP %d: %s', $status, mb_substr($response['body'], 0, 200));
        }

        if ($sent > 0) {
            return TransportResult::sent($messageId);
        }

        $reason = sprintf(
            'None of the %d device token(s) accepted the message. %s',
            count($tokens),
            implode(' ', array_slice($errors, 0, 3)),
        );

        // Every token permanently rejected means every device is gone, and
        // trying again will not conjure one.
        return $permanent === count($tokens)
            ? TransportResult::rejected($reason)
            : TransportResult::failed($reason);
    }
}
