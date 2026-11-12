<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Application\Notification;

use Central\Api\Application\Notification\EmailBrandingResolver;
use Central\Api\Application\Notification\EmailComposer;
use Central\Api\Application\Notification\Notifier;
use Central\Api\Domain\Notification\EmailLayout;
use Central\Api\Domain\Notification\EmailTemplate;
use Central\Api\Domain\Notification\EmailTemplateDefaults;
use Central\Api\Domain\Notification\EmailTemplateRenderer;
use Central\Api\Domain\Notification\EmailTemplateRepository;
use Central\Api\Domain\Notification\Notification;
use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationComposer;
use Central\Api\Domain\Notification\NotificationPriority;
use Central\Api\Domain\Notification\NotificationRepository;
use Central\Api\Domain\Notification\NotificationTransport;
use Central\Api\Domain\Notification\NotificationType;
use Central\Api\Domain\Notification\PendingDelivery;
use Central\Api\Domain\Notification\Recipient;
use Central\Api\Domain\Notification\TransportResult;
use Central\Api\Domain\Settings\SettingCatalogue;
use Central\Api\Domain\Settings\SettingsRepository;
use Central\Api\Domain\Settings\TenantSettings;
use Central\Api\Domain\Tenant\Tenant;
use Central\Api\Domain\Tenant\TenantRepository;
use Central\Api\Infrastructure\Notification\BrandedEmailTransport;
use Central\Api\Support\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Every email goes out composed and branded: default and custom wording
 * alike, old saved templates without a frame inside a frame, and something
 * still sent when composing fails.
 */
#[CoversClass(EmailComposer::class)]
#[CoversClass(BrandedEmailTransport::class)]
#[CoversClass(EmailBrandingResolver::class)]
#[CoversClass(Notifier::class)]
final class BrandedEmailTest extends TestCase
{
    private const TENANT = '11111111-1111-4111-8111-111111111111';
    private const LOGO   = 'https://cdn.example.com/logo.png';

    /** The navy card the old defaults put around everything. */
    private const LEGACY = '<div style="background:#f1f5f9;padding:24px;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;'
        . 'border:1px solid #e2e8f0;"><div style="background:#1f4e79;padding:20px 28px;">'
        . '<span style="color:#ffffff;font-size:18px;font-weight:700;">{{organisation}}</span></div>'
        . '<div style="padding:28px;"><p>Hi {{recipient_name}},</p><p>Our words on {{subject}}.</p>'
        . '<table><tr><td style="border-radius:8px;background:#1f4e79;"><a href="{{link}}" '
        . 'style="display:inline-block;color:#ffffff;text-decoration:none;">Go</a></td></tr></table>'
        . '</div><div style="padding:18px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;">'
        . '<p>This message was sent by {{organisation}}.</p></div></div></div>';

    #[Test]
    public function the_default_wording_goes_out_in_the_tenants_branding(): void
    {
        $email = $this->composer()->forDelivery($this->delivery(NotificationType::EnrollmentAssigned));

        self::assertSame('You have been assigned Fire Safety', $email['subject']);
        self::assertStringContainsString('<img src="' . self::LOGO . '" alt="Midwestern Learning"', $email['html']);
        self::assertStringContainsString('bgcolor="#3a1aa8"', $email['html']);
        self::assertStringContainsString('Hi Ada Lovelace,', $email['html']);
        self::assertStringContainsString('Fire Safety', $email['html']);
        self::assertStringContainsString('href="https://learn.example.com/player/c1"', $email['html']);
        self::assertStringNotContainsString('{{', $email['html']);
        self::assertStringContainsString('Start learning (https://learn.example.com/player/c1)', $email['text']);
    }

    #[Test]
    public function a_custom_template_is_framed_too_with_its_own_subject(): void
    {
        $template = new EmailTemplate('t', self::TENANT, NotificationType::EnrollmentAssigned, 'New: {{subject}}', '<p>Ours: {{subject}}</p>');
        $email    = $this->composer($template)->forDelivery($this->delivery(NotificationType::EnrollmentAssigned));

        self::assertSame('New: Fire Safety', $email['subject']);
        self::assertStringContainsString('<p>Ours: Fire Safety</p>', $email['html']);
        self::assertStringContainsString('bgcolor="#3a1aa8"', $email['html']);
    }

    #[Test]
    public function a_legacy_saved_card_is_unwrapped_not_double_wrapped(): void
    {
        $template = new EmailTemplate('t', self::TENANT, NotificationType::EnrollmentAssigned, null, self::LEGACY);
        $email    = $this->composer($template)->forDelivery($this->delivery(NotificationType::EnrollmentAssigned));

        self::assertStringNotContainsString('max-width:560px', $email['html']);
        self::assertStringNotContainsString('#1f4e79', $email['html']);
        self::assertSame(1, substr_count($email['html'], '<!DOCTYPE html>'));
        self::assertStringContainsString('Our words on Fire Safety.', $email['html']);
        // Its old navy button now wears the brand colour.
        self::assertStringContainsString('background:#3a1aa8;"><a href="https://learn.example.com/player/c1"', $email['html']);
    }

    #[Test]
    public function a_template_that_is_a_whole_document_is_sent_as_written(): void
    {
        $doc      = '<html><body><p>Mine, {{recipient_name}}</p></body></html>';
        $template = new EmailTemplate('t', self::TENANT, NotificationType::EnrollmentAssigned, null, $doc);
        $email    = $this->composer($template)->forDelivery($this->delivery(NotificationType::EnrollmentAssigned));

        self::assertSame('<html><body><p>Mine, Ada Lovelace</p></body></html>', $email['html']);
    }

    #[Test]
    public function the_transport_sends_the_composed_email_and_its_text(): void
    {
        $inner = $this->recordingTransport();
        (new BrandedEmailTransport($inner, $this->composer(), new NullLogger()))
            ->send($this->delivery(NotificationType::CertificateIssued));

        self::assertNotNull($inner->sent);
        self::assertStringContainsString('<!DOCTYPE html>', $inner->sent->body);
        self::assertSame('Your certificate for Fire Safety is ready', $inner->sent->title);
        self::assertIsString($inner->sent->data['textBody'] ?? null);
    }

    #[Test]
    public function layout_only_content_is_framed_as_it_is(): void
    {
        $email = $this->composer()->forDelivery($this->delivery(
            NotificationType::Announcement,
            '<p>Report attached</p>',
            ['emailLayout' => EmailComposer::LAYOUT_ONLY],
        ));

        self::assertStringContainsString('<p>Report attached</p>', $email['html']);
        self::assertStringContainsString('alt="Midwestern Learning"', $email['html']);
    }

    #[Test]
    public function when_composing_fails_the_plain_wording_still_goes_out_framed(): void
    {
        $templates = $this->createStub(EmailTemplateRepository::class);
        $templates->method('find')->willThrowException(new \RuntimeException('db down'));

        $inner = $this->recordingTransport();
        $result = (new BrandedEmailTransport($inner, $this->composer(null, $templates), new NullLogger()))
            ->send($this->delivery(NotificationType::PasswordReset, "Reset & go
now"));

        self::assertTrue($result->sent);
        self::assertNotNull($inner->sent);
        self::assertStringContainsString('<!DOCTYPE html>', $inner->sent->body);
        self::assertStringContainsString('Reset &amp; go<br>', $inner->sent->body);
    }

    #[Test]
    public function the_fallback_escapes_markup_in_a_notifications_plain_body(): void
    {
        $templates = $this->createStub(EmailTemplateRepository::class);
        $templates->method('find')->willThrowException(new \RuntimeException('db down'));

        $email = $this->composer(null, $templates)->fallback(
            $this->delivery(NotificationType::Announcement, '<img src=x onerror=alert(1)>'),
        );

        self::assertStringNotContainsString('<img src=x', $email['html']);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $email['html']);
    }

    #[Test]
    public function a_plain_text_template_keeps_its_paragraphs(): void
    {
        $template = new EmailTemplate(
            't',
            self::TENANT,
            NotificationType::EnrollmentAssigned,
            null,
            "Hello {{recipient_name}},\n\nYou are on {{subject}}.\nSee you there.",
        );
        $email = $this->composer($template)->forDelivery($this->delivery(NotificationType::EnrollmentAssigned));

        self::assertStringContainsString('>Hello Ada Lovelace,</p>', $email['html']);
        self::assertStringContainsString(">You are on Fire Safety.<br>\nSee you there.</p>", $email['html']);
    }

    #[Test]
    public function a_default_subject_is_not_treated_as_a_template(): void
    {
        $email = $this->composer()->forDelivery($this->delivery(
            NotificationType::EnrollmentAssigned,
            'plain',
            ['subjectTitle' => 'Using <table> and {{link}}'],
        ));

        self::assertSame('You have been assigned Using <table> and {{link}}', $email['subject']);
    }

    #[Test]
    public function the_notifier_stores_plain_words_with_the_name_and_a_link(): void
    {
        $repository = $this->createMock(NotificationRepository::class);
        $repository->method('recipients')->willReturn([
            'u1' => new Recipient('u1', 'ada@example.com', name: 'Ada Lovelace'),
        ]);
        $repository->method('entitledChannels')->willReturn([]);

        $stored = null;
        $repository->expects(self::once())->method('raise')->willReturnCallback(
            static function (Notification $n) use (&$stored): Notification {
                $stored = $n;

                return $n;
            },
        );

        $notifier = new Notifier(
            $repository,
            new NotificationComposer(),
            $this->settings(),
            new FrozenClock(),
            new NullLogger(),
            'https://learn.example.com',
        );

        self::assertSame(1, $notifier->notify(self::TENANT, ['u1'], NotificationType::EnrollmentAssigned, [
            'subjectTitle' => 'Fire Safety',
        ], 'c1'));

        self::assertInstanceOf(Notification::class, $stored);
        self::assertSame('You have been assigned Fire Safety', $stored->title);
        self::assertStringNotContainsString('<', $stored->body);
        self::assertSame('Ada Lovelace', $stored->data['recipientName']);
        self::assertSame('https://learn.example.com/player/c1', $stored->data['link']);
    }

    // --- helpers ----------------------------------------------------------

    private function composer(?EmailTemplate $template = null, ?EmailTemplateRepository $templates = null): EmailComposer
    {
        if ($templates === null) {
            $templates = $this->createStub(EmailTemplateRepository::class);
            $templates->method('find')->willReturn($template);
        }

        $tenants = $this->createStub(TenantRepository::class);
        $tenants->method('find')->willReturn(
            new Tenant(id: self::TENANT, name: 'Midwestern Learning', code: 'mw', status: 'active'),
        );

        return new EmailComposer(
            $templates,
            new EmailTemplateRenderer(),
            new EmailTemplateDefaults(),
            new NotificationComposer(),
            new EmailBrandingResolver($this->settings(), $tenants, new NullLogger(), 'https://learn.example.com'),
            new EmailLayout(),
        );
    }

    private function settings(): SettingsRepository
    {
        $settings = $this->createStub(SettingsRepository::class);
        $settings->method('forTenant')->willReturn(TenantSettings::resolve(self::TENANT, [
            'primary_color'         => '#3a1aa8',
            'email_header_logo_url' => self::LOGO,
            'show_powered_by'       => 'false',
        ], new SettingCatalogue()));

        return $settings;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function delivery(NotificationType $type, string $body = 'plain', array $data = []): PendingDelivery
    {
        return new PendingDelivery(
            deliveryId: 'd1',
            tenantId: self::TENANT,
            notificationId: 'n1',
            userId: 'u1',
            channel: NotificationChannel::Email,
            address: 'ada@example.com',
            type: $type,
            title: 'stored title',
            body: $body,
            data: [
                'subjectTitle'  => 'Fire Safety',
                'recipientName' => 'Ada Lovelace',
                'link'          => 'https://learn.example.com/player/c1',
                ...$data,
            ],
            priority: NotificationPriority::Normal,
            attempts: 0,
            maxAttempts: 3,
        );
    }

    private function recordingTransport(): RecordingTransport
    {
        return new RecordingTransport();
    }
}

/** Remembers what it was asked to send. */
final class RecordingTransport implements NotificationTransport
{
    public ?PendingDelivery $sent = null;

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Email;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function send(PendingDelivery $delivery): TransportResult
    {
        $this->sent = $delivery;

        return TransportResult::sent('m1');
    }
}
