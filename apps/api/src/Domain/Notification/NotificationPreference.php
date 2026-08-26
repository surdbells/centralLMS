<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * One person's answer to "do you want this kind of message on this channel".
 *
 * A null `type` means the whole channel. Rows are opt-*outs* in practice —
 * absence means yes — which keeps the table small and means a new
 * notification type reaches everyone without a backfill.
 */
final readonly class NotificationPreference
{
    public function __construct(
        public ?string $id,
        public string $tenantId,
        public string $userId,
        /** Null covers every type on the channel. */
        public ?NotificationType $type,
        public NotificationChannel $channel,
        public bool $enabled,
    ) {
    }

    /**
     * Builds from a request body, rejecting what cannot be honoured.
     *
     * The interesting rule is the last one: a type that is not optional
     * cannot be switched off on the in-app channel. Silently accepting the
     * change and then ignoring it would be worse than refusing — the person
     * would believe they had muted something they had not.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string> $violations
     */
    public static function fromInput(
        string $tenantId,
        string $userId,
        array $input,
        array &$violations,
    ): ?self {
        $rawChannel = is_string($input['channel'] ?? null) ? trim($input['channel']) : '';
        $channel    = NotificationChannel::tryFrom($rawChannel);

        if ($channel === null) {
            $violations['channel'] = 'Choose one of: ' . implode(', ', NotificationChannel::values()) . '.';
        }

        $type = null;

        if (isset($input['type']) && $input['type'] !== '') {
            $type = NotificationType::tryFrom((string) $input['type']);

            if ($type === null) {
                $violations['type'] = 'Not a notification type. Omit it to set the whole channel.';
            }
        }

        if (!array_key_exists('enabled', $input) || !is_bool($input['enabled'])) {
            $violations['enabled'] = 'Send true or false.';
        }

        $enabled = (bool) ($input['enabled'] ?? true);

        if (
            $violations === []
            && $channel === NotificationChannel::InApp
            && $type !== null
            && !$type->isOptional()
            && !$enabled
        ) {
            $violations['type'] = sprintf(
                '"%s" cannot be switched off in the app — it tells you something has changed.',
                $type->label(),
            );
        }

        if ($violations !== []) {
            return null;
        }

        \assert($channel instanceof NotificationChannel);

        return new self(null, $tenantId, $userId, $type, $channel, $enabled);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'      => $this->id,
            'type'    => $this->type?->value,
            'channel' => $this->channel->value,
            'enabled' => $this->enabled,
        ];
    }
}
