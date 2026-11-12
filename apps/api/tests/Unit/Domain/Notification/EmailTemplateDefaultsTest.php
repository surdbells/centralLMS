<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Notification;

use Central\Api\Domain\Notification\EmailLayout;
use Central\Api\Domain\Notification\EmailTemplateDefaults;
use Central\Api\Domain\Notification\EmailTemplateRenderer;
use Central\Api\Domain\Notification\NotificationChannel;
use Central\Api\Domain\Notification\NotificationType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every email has rich default content, every emailed type is listed, and
 * values are escaped on the way into HTML.
 */
#[CoversClass(EmailTemplateDefaults::class)]
#[CoversClass(EmailTemplateRenderer::class)]
#[CoversClass(EmailLayout::class)]
#[CoversClass(NotificationType::class)]
final class EmailTemplateDefaultsTest extends TestCase
{
    #[Test]
    public function every_type_has_content_without_a_frame_or_a_hard_coded_colour(): void
    {
        $defaults = new EmailTemplateDefaults();
        $renderer = new EmailTemplateRenderer();

        foreach (NotificationType::cases() as $type) {
            $body = $defaults->body($type);

            self::assertStringContainsString('{{recipient_name}}', $body, $type->value);
            self::assertStringNotContainsString('#1f4e79', $body, $type->value);
            self::assertStringNotContainsString('max-width:560px', $body, $type->value);
            self::assertStringNotContainsString('<html', $body, $type->value);
            self::assertSame([], $renderer->unknownPlaceholders($body), $type->value);
        }
    }

    #[Test]
    public function buttons_use_the_brand_colour_and_only_appear_with_a_link(): void
    {
        $body = (new EmailTemplateDefaults())->body(NotificationType::EnrollmentAssigned);

        self::assertStringContainsString('bgcolor="{{brand_color}}"', $body);
        self::assertStringContainsString('color:{{brand_text_color}};', $body);
        self::assertStringContainsString('<!--if:link-->', $body);
    }

    #[Test]
    public function every_transactional_type_is_emailed_and_so_listed_in_settings(): void
    {
        foreach (NotificationType::cases() as $type) {
            $emailed = in_array(NotificationChannel::Email, $type->defaultChannels(), true);
            $forum   = in_array($type, [NotificationType::ForumReply, NotificationType::ForumMention], true);

            self::assertSame(!$forum, $emailed, $type->value);
            self::assertNotSame('', $type->emailDescription(), $type->value);
            self::assertContains(
                $type->emailGroup(),
                ['account', 'enrolment', 'certificates', 'sessions', 'announcements'],
                $type->value,
            );
        }
    }

    #[Test]
    public function course_enrolment_is_findable_by_name(): void
    {
        self::assertStringContainsStringIgnoringCase('enrolment', NotificationType::EnrollmentAssigned->label());
        self::assertSame('enrolment', NotificationType::EnrollmentAssigned->emailGroup());
    }

    #[Test]
    public function email_values_are_html_escaped_and_conditionals_resolved(): void
    {
        $rendered = (new EmailTemplateRenderer())->renderEmail(
            'Enrolled on {{subject}}',
            '<p>{{subject}}</p><!--if:due_date--><p>Due {{due_date}}</p><!--/if:due_date-->'
                . '<a href="{{link}}">Go</a><div>{{default_body}}</div><i>{{recipient_name}}</i>',
            ['subjectTitle' => 'Health & Safety <L2>', 'link' => 'https://x.example.com/?a=1&b="2"', 'dueDate' => ''],
            ['recipient_name' => 'Cost $1 \\0', 'default_body' => "Line one\nSee https://x.example.com/p"],
        );

        // The subject is plain text: the course's own words survive, tags and all.
        self::assertSame('Enrolled on Health & Safety <L2>', $rendered['title']);
        self::assertStringContainsString('<p>Health &amp; Safety &lt;L2&gt;</p>', $rendered['body']);
        self::assertStringNotContainsString('Due', $rendered['body']);
        self::assertStringContainsString('href="https://x.example.com/?a=1&amp;b=&quot;2&quot;"', $rendered['body']);
        self::assertStringContainsString('Line one<br>', $rendered['body']);
        self::assertStringContainsString('<a href="https://x.example.com/p"', $rendered['body']);
        // A "$1" or backslash in a value goes in literally, not as a back-reference.
        self::assertStringContainsString('<i>Cost $1 \\0</i>', $rendered['body']);
    }

    #[Test]
    public function the_brand_placeholders_are_part_of_the_closed_list(): void
    {
        foreach (['brand_color', 'brand_text_color', 'logo_url', 'site_url'] as $name) {
            self::assertContains($name, EmailTemplateRenderer::PLACEHOLDERS);
        }
    }

    #[Test]
    public function a_link_that_is_not_http_is_replaced_by_the_fallback(): void
    {
        $rendered = (new EmailTemplateRenderer())->renderEmail(
            'x',
            '<a href="{{link}}">Go</a>',
            ['link' => 'javascript:alert(1)'],
            ['link' => 'https://learn.example.com'],
        );

        self::assertStringNotContainsString('javascript:', $rendered['body']);
        self::assertStringContainsString('href="https://learn.example.com"', $rendered['body']);
    }
}
