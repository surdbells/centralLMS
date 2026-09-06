<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Infrastructure\Ilt;

use Central\Api\Infrastructure\Ilt\AgoraTokenBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The token format is Agora's, so it can't be checked against their servers
 * here — but it can be checked against itself: a token must decode back to the
 * exact inputs, carry a valid HMAC over its own payload, and be reproducible
 * from a fixed salt + issue time. A packing slip anywhere breaks all three.
 */
final class AgoraTokenBuilderTest extends TestCase
{
    private const APP_ID   = '970CA35de60c44645bbae8a215061b33';
    private const APP_CERT = '5CFd2fd1755d40ecb72977518be15d3b';

    #[Test]
    public function rtc_token_round_trips_with_a_valid_signature(): void
    {
        $builder = new AgoraTokenBuilder(self::APP_ID, self::APP_CERT);
        $channel = 'sess-7d72365eb983485397e3e3f9d460bdda';
        $account = 'user-42';
        $expire  = 600;
        $issueTs = 1_700_000_000;
        $salt    = 12345;

        $token = $builder->rtcToken($channel, $account, $expire, $issueTs, $salt);

        self::assertStringStartsWith('007', $token);

        $decoded = $this->decode($token);

        self::assertSame(self::APP_ID, $decoded['appId']);
        self::assertSame($issueTs, $decoded['issueTs']);
        self::assertSame($expire, $decoded['expire']);
        self::assertSame($salt, $decoded['salt']);
        self::assertSame(1, $decoded['serviceCount']);
        self::assertSame(1, $decoded['serviceType']); // RTC
        self::assertSame($channel, $decoded['channel']);
        self::assertSame($account, $decoded['account']);
        // join + publish audio/video/data, each living for the full window.
        self::assertSame([1 => $expire, 2 => $expire, 3 => $expire, 4 => $expire], $decoded['privileges']);

        // The signature must be a valid HMAC over the payload, under the key
        // derived from cert -> issueTs -> salt.
        $signing = hash_hmac('sha256', self::APP_CERT, pack('V', $issueTs), true);
        $signing = hash_hmac('sha256', $signing, pack('V', $salt), true);
        self::assertTrue(hash_equals(
            hash_hmac('sha256', $decoded['signingInfo'], $signing, true),
            $decoded['signature'],
        ));
    }

    #[Test]
    public function rtm_token_carries_the_login_service_for_the_account(): void
    {
        $builder = new AgoraTokenBuilder(self::APP_ID, self::APP_CERT);

        $token   = $builder->rtmToken('user-42', 600, 1_700_000_000, 12345);
        $decoded = $this->decode($token, rtc: false);

        self::assertStringStartsWith('007', $token);
        self::assertSame(2, $decoded['serviceType']); // RTM
        self::assertSame('user-42', $decoded['account']);
        self::assertSame([1 => 600], $decoded['privileges']); // login
    }

    #[Test]
    public function a_fixed_salt_and_issue_time_reproduce_the_same_token(): void
    {
        $builder = new AgoraTokenBuilder(self::APP_ID, self::APP_CERT);

        self::assertSame(
            $builder->rtcToken('c', 'u', 600, 1_700_000_000, 999),
            $builder->rtcToken('c', 'u', 600, 1_700_000_000, 999),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $token, bool $rtc = true): array
    {
        $content = (string) gzuncompress(base64_decode(substr($token, 3)));
        $offset  = 0;

        $u16 = static function () use ($content, &$offset): int {
            $v = unpack('v', substr($content, $offset, 2))[1];
            $offset += 2;
            return $v;
        };
        $u32 = static function () use ($content, &$offset): int {
            $v = unpack('V', substr($content, $offset, 4))[1];
            $offset += 4;
            return $v;
        };
        $str = static function () use ($content, &$offset, $u16): string {
            $n = $u16();
            $s = substr($content, $offset, $n);
            $offset += $n;
            return $s;
        };

        $signature       = $str();
        $signingInfoFrom = $offset;
        $appId           = $str();
        $issueTs         = $u32();
        $expire          = $u32();
        $salt            = $u32();
        $serviceCount    = $u16();
        $serviceType     = $u16();

        $privCount  = $u16();
        $privileges = [];
        for ($i = 0; $i < $privCount; $i++) {
            $privileges[$u16()] = $u32();
        }

        $channel = $rtc ? $str() : '';
        $account = $str();

        return [
            'signature'    => $signature,
            'signingInfo'  => substr($content, $signingInfoFrom),
            'appId'        => $appId,
            'issueTs'      => $issueTs,
            'expire'       => $expire,
            'salt'         => $salt,
            'serviceCount' => $serviceCount,
            'serviceType'  => $serviceType,
            'privileges'   => $privileges,
            'channel'      => $channel,
            'account'      => $account,
        ];
    }
}
