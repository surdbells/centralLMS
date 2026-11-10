<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Support;

use Central\Api\Support\DeviceDetector;
use PHPUnit\Framework\TestCase;

final class DeviceDetectorTest extends TestCase
{
    /**
     * @dataProvider agents
     */
    public function testClassifies(string $expected, ?string $userAgent): void
    {
        self::assertSame($expected, DeviceDetector::classify($userAgent));
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function agents(): iterable
    {
        yield 'iphone' => [
            DeviceDetector::MOBILE,
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148',
        ];
        yield 'android phone' => [
            DeviceDetector::MOBILE,
            'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36',
        ];
        yield 'capacitor shell' => [
            DeviceDetector::MOBILE,
            'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 Chrome/118 Capacitor',
        ];
        yield 'ipad' => [
            DeviceDetector::TABLET,
            'Mozilla/5.0 (iPad; CPU OS 16_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148',
        ];
        yield 'android tablet (no mobile token)' => [
            DeviceDetector::TABLET,
            'Mozilla/5.0 (Linux; Android 13; SM-X700) AppleWebKit/537.36 Chrome/119 Safari/537.36',
        ];
        yield 'kindle' => [
            DeviceDetector::TABLET,
            'Mozilla/5.0 (Linux; U; Android 9; KFMAWI) AppleWebKit/537.36 Silk/95 like Chrome Safari',
        ];
        yield 'windows desktop' => [
            DeviceDetector::DESKTOP,
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120 Safari/537.36',
        ];
        yield 'mac desktop' => [
            DeviceDetector::DESKTOP,
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15',
        ];
        yield 'empty' => [DeviceDetector::DESKTOP, ''];
        yield 'null' => [DeviceDetector::DESKTOP, null];
    }
}
