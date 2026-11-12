<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Domain\Notification;

use Central\Api\Domain\Notification\EmailBranding;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What an organisation's settings are allowed to put into an email.
 */
#[CoversClass(EmailBranding::class)]
final class EmailBrandingTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function colours(): iterable
    {
        yield 'six digits'           => ['#3A1AA8', '#3a1aa8'];
        yield 'three digits'         => ['#fa0', '#ffaa00'];
        yield 'alpha dropped'        => ['#3a1aa880', '#3a1aa8'];
        yield 'css injection'        => ['red;background:url(x)', EmailBranding::DEFAULT_COLOUR];
        yield 'named colour refused' => ['purple', EmailBranding::DEFAULT_COLOUR];
        yield 'empty'                => ['', EmailBranding::DEFAULT_COLOUR];
    }

    #[Test]
    #[DataProvider('colours')]
    public function colours_are_validated_and_normalised(string $given, string $expected): void
    {
        self::assertSame($expected, (new EmailBranding('Acme', $given))->primary);
    }

    #[Test]
    public function an_invalid_fallback_still_yields_a_colour(): void
    {
        self::assertSame(EmailBranding::DEFAULT_COLOUR, EmailBranding::colour('nope', 'also-nope'));
    }

    #[Test]
    public function a_dark_brand_gets_white_button_text_and_is_its_own_ink(): void
    {
        $brand = new EmailBranding('Acme', '#3a1aa8');

        self::assertSame('#ffffff', $brand->onPrimary);
        self::assertSame('#3a1aa8', $brand->ink);
    }

    #[Test]
    public function a_light_brand_gets_dark_button_text_and_darkened_ink(): void
    {
        $brand = new EmailBranding('Acme', '#f5d142');

        self::assertSame('#111827', $brand->onPrimary);
        self::assertNotSame('#f5d142', $brand->ink);
        self::assertGreaterThanOrEqual(4.5, EmailBranding::contrast($brand->ink, '#ffffff'));
    }

    #[Test]
    public function contrast_matches_the_wcag_extremes(): void
    {
        self::assertEqualsWithDelta(21.0, EmailBranding::contrast('#000000', '#ffffff'), 0.01);
        self::assertEqualsWithDelta(1.0, EmailBranding::contrast('#777777', '#777777'), 0.01);
    }

    /**
     * @return iterable<string, array{string|null, string|null}>
     */
    public static function logos(): iterable
    {
        $ok = 'https://cdn.example.com/logo.png';

        yield 'absolute https'      => [$ok, $ok];
        yield 'public branding url' => ['https://api.example.com/v1/public/branding/x/logo?v=1a2b', 'https://api.example.com/v1/public/branding/x/logo?v=1a2b'];
        yield 'plain http'          => ['http://cdn.example.com/logo.png', null];
        yield 'relative'            => ['/v1/media/abc/file', null];
        yield 'short-lived ticket'  => ['https://api.example.com/v1/media/abc/file?ticket=xyz', null];
        yield 'signed s3'           => ['https://bucket.s3.amazonaws.com/logo.png?X-Amz-Signature=abc', null];
        yield 'javascript'          => ['javascript:alert(1)', null];
        yield 'nothing'             => [null, null];
    }

    #[Test]
    #[DataProvider('logos')]
    public function only_a_logo_an_inbox_can_load_is_kept(?string $given, ?string $expected): void
    {
        self::assertSame($expected, (new EmailBranding('Acme', '#000000', $given))->logoUrl);
    }

    #[Test]
    public function the_site_address_must_be_a_web_address(): void
    {
        self::assertSame('https://learn.example.com', (new EmailBranding('Acme', '#000', null, 'https://learn.example.com/'))->siteUrl);
        self::assertSame('', (new EmailBranding('Acme', '#000', null, 'javascript:alert(1)'))->siteUrl);
    }
}
