<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Notification;

use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationTransport;
use Central\Api\Domain\Notification\PendingDelivery;
use Central\Api\Domain\Notification\TransportResult;
use Psr\Log\LoggerInterface;

/**
 * SMS via Termii.
 *
 * Termii answers 200 with `{"code": "...", "message": "..."}` for a good many
 * failures, so the status code is not the answer and the body has to be read.
 */
final readonly class TermiiSmsTransport implements NotificationTransport
{
    /**
     * A single segment is 160 GSM-7 characters; anything longer is billed as
     * several. Notifications are short by design, and the composed body plus
     * a title is comfortably inside one — but a course with a very long name
     * could push it over, so it is truncated rather than quietly costing
     * three times as much.
     */
    private const MAX_LENGTH = 155;

    public function __construct(
        private HttpSender $http,
        private string $apiKey,
        private string $senderId,
        private string $baseUrl,
        private LoggerInterface $logger,
    ) {
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Sms;
    }

    public function isConfigured(): bool
    {
        return trim($this->apiKey) !== '' && trim($this->senderId) !== '';
    }

    public function send(PendingDelivery $delivery): TransportResult
    {
        $number = $this->normalise($delivery->address);

        if ($number === null) {
            return TransportResult::rejected(sprintf(
                '"%s" is not a phone number that can be dialled internationally.',
                $delivery->address,
            ));
        }

        $response = $this->http->postJson(rtrim($this->baseUrl, '/') . '/api/sms/send', [
            'to'      => $number,
            'from'    => $this->senderId,
            'sms'     => $this->text($delivery),
            'type'    => 'plain',
            'channel' => 'generic',
            'api_key' => $this->apiKey,
        ]);

        $status = $response['status'];
        $body   = $response['body'];

        if ($status === 0) {
            return TransportResult::failed($response['error'] ?? 'The SMS provider could not be reached.');
        }

        $decoded = HttpSender::decode($body);

        if ($status >= 200 && $status < 300 && ($decoded['code'] ?? null) === 'ok') {
            $id = $decoded['message_id'] ?? null;

            return TransportResult::sent(is_string($id) ? $id : null);
        }

        $reason = is_string($decoded['message'] ?? null)
            ? (string) $decoded['message']
            : sprintf('The SMS provider returned HTTP %d.', $status);

        $this->logger->warning('Termii rejected a message', [
            'status' => $status,
            'body'   => mb_substr($body, 0, 500),
        ]);

        return $status >= 400 && $status < 500 && $status !== 429
            ? TransportResult::rejected($reason)
            : TransportResult::failed($reason);
    }

    /**
     * Title and body in one segment.
     */
    private function text(PendingDelivery $delivery): string
    {
        $text = trim($delivery->title . '. ' . $delivery->body);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return mb_strlen($text) <= self::MAX_LENGTH
            ? $text
            : mb_substr($text, 0, self::MAX_LENGTH - 1) . '…';
    }

    /**
     * To E.164 without the plus, which is what Termii wants.
     *
     * Returns null rather than guessing at a local number: a seven-digit
     * string is not a number anyone can send to, and prefixing a country code
     * on a hunch sends a message to a stranger.
     */
    private function normalise(string $raw): ?string
    {
        $digits = (string) preg_replace('/\D+/', '', $raw);

        if (str_starts_with(trim($raw), '+')) {
            return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
        }

        // 00 is the other international prefix in common use.
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : null;
    }
}
