<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Support;

use Central\Api\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new HtmlSanitizer();
    }

    public function testKeepsAllowedFormatting(): void
    {
        $out = $this->sanitizer->clean('<p>Hello <strong>world</strong> and <em>code</em></p>');
        self::assertSame('<p>Hello <strong>world</strong> and <em>code</em></p>', $out);
    }

    public function testKeepsListsAndCode(): void
    {
        $out = $this->sanitizer->clean('<ul><li>one</li></ul><pre><code>x = 1</code></pre>');
        self::assertStringContainsString('<ul><li>one</li></ul>', $out);
        self::assertStringContainsString('<pre><code>x = 1</code></pre>', $out);
    }

    public function testStripsScriptEntirely(): void
    {
        $out = $this->sanitizer->clean('<p>hi</p><script>alert(1)</script>');
        self::assertStringNotContainsString('alert', $out);
        self::assertStringNotContainsString('<script', $out);
        self::assertStringContainsString('<p>hi</p>', $out);
    }

    public function testStripsEventHandlerAttributes(): void
    {
        $out = $this->sanitizer->clean('<p onclick="steal()">hi</p>');
        self::assertStringNotContainsString('onclick', $out);
        self::assertStringContainsString('hi', $out);
    }

    public function testUnwrapsUnknownTagKeepingText(): void
    {
        $out = $this->sanitizer->clean('<div class="x">kept <span style="color:red">text</span></div>');
        self::assertStringNotContainsString('<div', $out);
        self::assertStringNotContainsString('<span', $out);
        self::assertStringNotContainsString('style', $out);
        self::assertStringContainsString('kept', $out);
        self::assertStringContainsString('text', $out);
    }

    public function testDropsJavascriptHref(): void
    {
        $out = $this->sanitizer->clean('<a href="javascript:alert(1)">x</a>');
        self::assertStringNotContainsString('javascript', $out);
    }

    public function testKeepsSafeHrefAndHardensIt(): void
    {
        $out = $this->sanitizer->clean('<a href="https://example.com">link</a>');
        self::assertStringContainsString('href="https://example.com"', $out);
        self::assertStringContainsString('rel="noopener nofollow ugc"', $out);
        self::assertStringContainsString('target="_blank"', $out);
    }

    public function testEmptyInputStaysEmpty(): void
    {
        self::assertSame('', $this->sanitizer->clean('   '));
    }
}
