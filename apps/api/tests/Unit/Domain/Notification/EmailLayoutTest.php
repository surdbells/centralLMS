<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Notification;

use Central\Api\Domain\Notification\EmailBranding;
use Central\Api\Domain\Notification\EmailLayout;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The branded frame every email goes out in.
 */
#[CoversClass(EmailLayout::class)]
#[CoversClass(EmailBranding::class)]
final class EmailLayoutTest extends TestCase
{
    private const LOGO = 'https://cdn.example.com/logo.png';

    #[Test]
    public function it_carries_the_logo_the_colour_the_name_and_the_site(): void
    {
        $html = (new EmailLayout())->wrap(
            '<p>Body</p>',
            new EmailBranding('Midwestern Learning', '#3a1aa8', self::LOGO, 'https://learn.example.com'),
            'Subject',
            'Preview text',
        );

        self::assertStringStartsWith('<!DOCTYPE html>', $html);
        self::assertStringContainsString('<img src="' . self::LOGO . '" alt="Midwestern Learning"', $html);
        self::assertStringContainsString('bgcolor="#3a1aa8"', $html);
        self::assertStringContainsString('Sent by <strong style="color:#374151;">Midwestern Learning</strong>', $html);
        self::assertStringContainsString('href="https://learn.example.com/mysettings"', $html);
        self::assertStringContainsString('<p>Body</p>', $html);
        self::assertStringContainsString('Preview text', $html);
        self::assertStringContainsString('width="600"', $html);
    }

    #[Test]
    public function without_a_usable_logo_the_name_is_shown_in_a_readable_colour(): void
    {
        $brand = new EmailBranding('Acme & Co', '#f5d142', 'https://x.example.com/logo.png?ticket=abc');
        $html  = (new EmailLayout())->wrap('<p>Body</p>', $brand);

        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('ticket=', $html);
        self::assertStringContainsString('color:' . $brand->ink . ';">Acme &amp; Co</span>', $html);
    }

    #[Test]
    public function the_organisation_name_is_escaped(): void
    {
        $html = (new EmailLayout())->wrap('', new EmailBranding('<script>x</script>', '#000000', self::LOGO));

        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('alt="&lt;script&gt;x&lt;/script&gt;"', $html);
    }

    #[Test]
    public function powered_by_follows_the_setting(): void
    {
        $layout = new EmailLayout();

        self::assertStringContainsString('Powered by Central LMS', $layout->wrap('', new EmailBranding('A', '#000', null, '', true)));
        self::assertStringNotContainsString('Powered by', $layout->wrap('', new EmailBranding('A', '#000', null, '', false)));
    }

    #[Test]
    public function the_plain_text_keeps_paragraphs_facts_and_link_addresses(): void
    {
        $inner = EmailLayout::heading('Hello')
            . EmailLayout::paragraph('First &amp; foremost.')
            . EmailLayout::facts([['Course', 'Fire Safety']])
            . EmailLayout::button('Start', 'https://x.example.com/go', '#000', '#fff');

        $text = (new EmailLayout())->text($inner, new EmailBranding('Acme', '#000', null, 'https://learn.example.com'));

        self::assertStringContainsString("Hello\n\nFirst & foremost.", $text);
        self::assertStringContainsString('Course: Fire Safety', $text);
        self::assertStringContainsString('Start (https://x.example.com/go)', $text);
        self::assertStringEndsWith("Sent by Acme\nhttps://learn.example.com", $text);
        self::assertStringNotContainsString('<', $text);
    }

    #[Test]
    public function conditional_sections_are_marked_with_comments(): void
    {
        self::assertSame('<!--if:due_date-->x<!--/if:due_date-->', EmailLayout::when('due_date', 'x'));
    }
}
