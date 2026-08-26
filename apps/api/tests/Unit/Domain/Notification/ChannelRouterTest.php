<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Notification;

use Central\Api\Domain\Notification\ChannelRouter;
use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationPreference;
use Central\Api\Domain\Notification\NotificationType;
use Central\Api\Domain\Notification\QuietHours;
use Central\Api\Domain\Notification\Recipient;
use Central\Api\Domain\Notification\RoutingDecision;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The send/don't-send decision.
 *
 * Every rule here is one somebody could reasonably disagree with, which is why
 * they are all in one pure class with a test each rather than spread through
 * the actions.
 */
#[CoversClass(ChannelRouter::class)]
#[CoversClass(RoutingDecision::class)]
#[CoversClass(Recipient::class)]
final class ChannelRouterTest extends TestCase
{
    private const NOW = '2026-03-01 14:00:00';

    #[Test]
    public function the_type_decides_the_channels(): void
    {
        $decisions = $this->route(NotificationType::EnrollmentAssigned, $this->recipient());

        self::assertSame(['in_app', 'email'], $this->channels($decisions));
        self::assertTrue($this->for($decisions, NotificationChannel::Email)->send);
    }

    #[Test]
    public function a_quiet_type_goes_only_to_the_bell(): void
    {
        // Completing a course is worth a badge, not an email.
        $decisions = $this->route(NotificationType::CourseCompleted, $this->recipient());

        self::assertSame(['in_app'], $this->channels($decisions));
    }

    #[Test]
    public function in_app_is_added_even_when_the_caller_asks_for_email_only(): void
    {
        // Otherwise an email arrives about something the bell has never heard
        // of, and the person cannot find it afterwards.
        $decisions = $this->route(
            NotificationType::Announcement,
            $this->recipient(),
            [NotificationChannel::Email],
        );

        self::assertContains('in_app', $this->channels($decisions));
    }

    #[Test]
    public function a_channel_the_tenant_has_not_bought_is_skipped_with_a_reason(): void
    {
        $router = new ChannelRouter([NotificationChannel::InApp, NotificationChannel::Email]);

        $decision = $this->for(
            $router->route(
                NotificationType::SessionReminder,
                $this->recipient(phone: '+2348012345678'),
                new \DateTimeImmutable(self::NOW),
                [NotificationChannel::Sms],
            ),
            NotificationChannel::Sms,
        );

        self::assertFalse($decision->send);
        self::assertSame('The organisation has not enabled this channel.', $decision->reason);
    }

    #[Test]
    public function no_address_is_reported_before_the_preference(): void
    {
        // "You have no mobile number" is the one they can act on; "you opted
        // out of SMS" is not, for someone who did both.
        $recipient = $this->recipient(
            phone: null,
            preferences: [$this->preference(NotificationChannel::Sms, enabled: false)],
        );

        $decision = $this->for(
            $this->route(NotificationType::SessionReminder, $recipient, [NotificationChannel::Sms], sms: true),
            NotificationChannel::Sms,
        );

        self::assertFalse($decision->send);
        self::assertSame('No SMS address on record.', $decision->reason);
    }

    #[Test]
    public function an_opted_out_channel_is_skipped(): void
    {
        $recipient = $this->recipient(
            preferences: [$this->preference(NotificationChannel::Email, enabled: false)],
        );

        $decision = $this->for(
            $this->route(NotificationType::EnrollmentAssigned, $recipient),
            NotificationChannel::Email,
        );

        self::assertFalse($decision->send);
        self::assertSame('Turned off in their preferences.', $decision->reason);
    }

    #[Test]
    public function a_type_preference_beats_the_channel_wide_one(): void
    {
        // Silence email in general, keep the ones about sessions.
        $recipient = $this->recipient(preferences: [
            $this->preference(NotificationChannel::Email, enabled: false),
            $this->preference(NotificationChannel::Email, NotificationType::SessionReminder, enabled: true),
        ]);

        self::assertTrue($this->for(
            $this->route(NotificationType::SessionReminder, $recipient),
            NotificationChannel::Email,
        )->send);

        self::assertFalse($this->for(
            $this->route(NotificationType::EnrollmentAssigned, $recipient),
            NotificationChannel::Email,
        )->send);
    }

    #[Test]
    public function a_non_optional_type_cannot_be_silenced_in_the_app(): void
    {
        // A learner who muted "your session is cancelled" travels to a room
        // nobody is in.
        $recipient = $this->recipient(preferences: [
            $this->preference(NotificationChannel::InApp, NotificationType::SessionCancelled, enabled: false),
        ]);

        $decision = $this->for(
            $this->route(NotificationType::SessionCancelled, $recipient),
            NotificationChannel::InApp,
        );

        self::assertTrue($decision->send);
    }

    #[Test]
    public function a_non_optional_type_can_still_be_silenced_on_email(): void
    {
        // What is protected is that they are told, not how many times.
        $recipient = $this->recipient(preferences: [
            $this->preference(NotificationChannel::Email, NotificationType::SessionCancelled, enabled: false),
        ]);

        $decisions = $this->route(NotificationType::SessionCancelled, $recipient);

        self::assertTrue($this->for($decisions, NotificationChannel::InApp)->send);
        self::assertFalse($this->for($decisions, NotificationChannel::Email)->send);
    }

    // --- quiet hours ------------------------------------------------------

    #[Test]
    public function quiet_hours_defer_an_sms(): void
    {
        $recipient = $this->recipient(
            phone: '+2348012345678',
            quietHours: QuietHours::between('22:00', '07:00'),
            timezone: 'UTC',
        );

        $now = new \DateTimeImmutable('2026-03-01 23:30:00', new \DateTimeZone('UTC'));

        $decision = $this->for(
            (new ChannelRouter($this->allChannels()))->route(
                NotificationType::SessionReminder,
                $recipient,
                $now,
                [NotificationChannel::Sms],
            ),
            NotificationChannel::Sms,
        );

        self::assertTrue($decision->send);
        self::assertTrue($decision->isDeferred($now));
        self::assertSame('2026-03-02 07:00', $decision->sendAt?->format('Y-m-d H:i'));
    }

    #[Test]
    public function quiet_hours_do_not_hold_back_the_bell(): void
    {
        // Delaying the in-app record would make the bell lie about what has
        // happened; there is nothing to be woken by.
        $recipient = $this->recipient(quietHours: QuietHours::between('22:00', '07:00'));
        $now       = new \DateTimeImmutable('2026-03-01 23:30:00', new \DateTimeZone('UTC'));

        $decision = $this->for(
            (new ChannelRouter($this->allChannels()))->route(
                NotificationType::EnrollmentAssigned,
                $recipient,
                $now,
            ),
            NotificationChannel::InApp,
        );

        self::assertFalse($decision->isDeferred($now));
    }

    #[Test]
    public function quiet_hours_do_not_hold_back_an_urgent_message(): void
    {
        // A session cancelled at 06:00 for a 09:00 start must arrive before
        // the learner sets off.
        $recipient = $this->recipient(
            phone: '+2348012345678',
            quietHours: QuietHours::between('22:00', '07:00'),
        );

        $now = new \DateTimeImmutable('2026-03-01 23:30:00', new \DateTimeZone('UTC'));

        $decision = $this->for(
            (new ChannelRouter($this->allChannels()))->route(
                NotificationType::SessionCancelled,
                $recipient,
                $now,
                [NotificationChannel::Sms],
            ),
            NotificationChannel::Sms,
        );

        self::assertFalse($decision->isDeferred($now));
    }

    #[Test]
    public function quiet_hours_are_read_in_the_recipients_own_timezone(): void
    {
        // 23:30 in Lagos is 22:30 UTC. Comparing the UTC instant against the
        // window would put this outside it, and the person would be woken.
        $recipient = $this->recipient(
            phone: '+2348012345678',
            quietHours: QuietHours::between('23:00', '07:00'),
            timezone: 'Africa/Lagos',
        );

        $now = new \DateTimeImmutable('2026-03-01 22:30:00', new \DateTimeZone('UTC'));

        $decision = $this->for(
            (new ChannelRouter($this->allChannels()))->route(
                NotificationType::SessionReminder,
                $recipient,
                $now,
                [NotificationChannel::Sms],
            ),
            NotificationChannel::Sms,
        );

        self::assertTrue($decision->isDeferred($now));
    }

    #[Test]
    public function an_unknown_timezone_does_not_stop_the_message(): void
    {
        $recipient = $this->recipient(
            phone: '+2348012345678',
            quietHours: QuietHours::between('22:00', '07:00'),
            timezone: 'Mars/Olympus_Mons',
        );

        $now = new \DateTimeImmutable('2026-03-01 14:00:00', new \DateTimeZone('UTC'));

        $decision = $this->for(
            (new ChannelRouter($this->allChannels()))->route(
                NotificationType::SessionReminder,
                $recipient,
                $now,
                [NotificationChannel::Sms],
            ),
            NotificationChannel::Sms,
        );

        self::assertTrue($decision->send);
    }

    // --- helpers ----------------------------------------------------------

    /**
     * @param  list<NotificationChannel>|null $override
     * @return list<RoutingDecision>
     */
    private function route(
        NotificationType $type,
        Recipient $recipient,
        ?array $override = null,
        bool $sms = false,
    ): array {
        $channels = $sms ? $this->allChannels() : [NotificationChannel::InApp, NotificationChannel::Email];

        return (new ChannelRouter($channels))
            ->route($type, $recipient, new \DateTimeImmutable(self::NOW), $override);
    }

    /**
     * @return list<NotificationChannel>
     */
    private function allChannels(): array
    {
        return NotificationChannel::cases();
    }

    /**
     * @param list<NotificationPreference> $preferences
     */
    private function recipient(
        ?string $phone = null,
        array $preferences = [],
        ?QuietHours $quietHours = null,
        string $timezone = 'UTC',
    ): Recipient {
        return new Recipient(
            userId: 'user-1',
            email: 'learner@example.test',
            phone: $phone,
            pushTokens: [],
            preferences: $preferences,
            quietHours: $quietHours,
            timezone: $timezone,
            name: 'A Learner',
        );
    }

    private function preference(
        NotificationChannel $channel,
        ?NotificationType $type = null,
        bool $enabled = true,
    ): NotificationPreference {
        return new NotificationPreference(null, 'tenant-1', 'user-1', $type, $channel, $enabled);
    }

    /**
     * @param  list<RoutingDecision> $decisions
     * @return list<string>
     */
    private function channels(array $decisions): array
    {
        return array_map(
            static fn (RoutingDecision $d): string => $d->channel->value,
            $decisions,
        );
    }

    /**
     * @param list<RoutingDecision> $decisions
     */
    private function for(array $decisions, NotificationChannel $channel): RoutingDecision
    {
        foreach ($decisions as $decision) {
            if ($decision->channel === $channel) {
                return $decision;
            }
        }

        self::fail(sprintf('The router made no decision about %s.', $channel->value));
    }
}
