<?php

declare(strict_types=1);

namespace Central\Api\Domain\Notification;

/**
 * Decides which channels one notification goes out on, and when.
 *
 * The whole of the send/don't-send decision lives here, and it is pure: no
 * clock, no database, no transport. Every rule below is a case someone can
 * read and disagree with, which is the point — in the legacy these decisions
 * did not exist anywhere, because nothing sent anything.
 *
 * Every candidate channel produces a routing decision, including the ones
 * that will not be sent. A channel that is silently dropped is a channel
 * nobody can explain the absence of later, and "why didn't I get the email"
 * is the single most common question a notification system has to answer.
 */
final readonly class ChannelRouter
{
    /**
     * @param list<NotificationChannel> $tenantChannels channels the tenant has
     *                                                  enabled at all. SMS and
     *                                                  push cost money and are
     *                                                  off unless bought.
     */
    public function __construct(
        private array $tenantChannels = [
            NotificationChannel::InApp,
            NotificationChannel::Email,
        ],
    ) {
    }

    /**
     * @param  list<NotificationChannel>|null $override channels the caller
     *                                                  insists on, instead of
     *                                                  the type's defaults
     * @return list<RoutingDecision>
     */
    public function route(
        NotificationType $type,
        Recipient $recipient,
        \DateTimeImmutable $now,
        ?array $override = null,
    ): array {
        $candidates = $override ?? $type->defaultChannels();

        // In-app is not optional and not configurable. It is the record that
        // the notification happened, and every other channel is a copy of it.
        // Leaving it out on request would produce an email about something
        // the bell has never heard of.
        if (!in_array(NotificationChannel::InApp, $candidates, true)) {
            array_unshift($candidates, NotificationChannel::InApp);
        }

        $decisions = [];

        foreach ($candidates as $channel) {
            $decisions[] = $this->decide($type, $channel, $recipient, $now);
        }

        return $decisions;
    }

    private function decide(
        NotificationType $type,
        NotificationChannel $channel,
        Recipient $recipient,
        \DateTimeImmutable $now,
    ): RoutingDecision {
        if ($channel !== NotificationChannel::InApp && !in_array($channel, $this->tenantChannels, true)) {
            return RoutingDecision::skip($channel, 'The organisation has not enabled this channel.');
        }

        // Ordered before the preference check on purpose: "you have no mobile
        // number" is more useful than "you opted out of SMS" to someone who
        // did both, and it is the one they can fix.
        if (!$recipient->canReceiveOn($channel)) {
            return RoutingDecision::skip($channel, sprintf(
                'No %s address on record.',
                $channel->label(),
            ));
        }

        // A type that may not be switched off may still be switched off on
        // the noisy channels — what is protected is that they are told, not
        // how many times.
        $mayOptOut = $type->isOptional() || $channel !== NotificationChannel::InApp;

        if ($mayOptOut && !$recipient->allows($type, $channel)) {
            return RoutingDecision::skip($channel, 'Turned off in their preferences.');
        }

        return RoutingDecision::send($channel, $this->when($type, $channel, $recipient, $now));
    }

    /**
     * When it may be sent, taking quiet hours into account.
     */
    private function when(
        NotificationType $type,
        NotificationChannel $channel,
        Recipient $recipient,
        \DateTimeImmutable $now,
    ): \DateTimeImmutable {
        $quiet = $recipient->quietHours;

        if (
            $quiet === null
            || !$channel->isIntrusive()
            || $type->priority()->ignoresQuietHours()
        ) {
            return $now;
        }

        $local = $recipient->localTime($now);

        if (!$quiet->covers($local)) {
            return $now;
        }

        // Back to UTC: the delivery row stores an instant, and the whole
        // reason quiet hours were never implemented correctly anywhere is
        // that someone compared a wall clock to one.
        return $quiet->nextOpening($local)->setTimezone($now->getTimezone());
    }
}
