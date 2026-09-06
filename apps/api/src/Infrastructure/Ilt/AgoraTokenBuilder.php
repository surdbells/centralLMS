<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Ilt;

/**
 * Agora AccessToken2 ("007") builder — RTC (media) and RTM (signalling) tokens.
 *
 * Unlike the OAuth providers, Agora needs no per-tenant connection: the class
 * runs in-app, and the deployment mints a short-lived token per user per
 * session from its App ID + App Certificate. This is a faithful, self-contained
 * port of Agora's reference AccessToken2 algorithm — no dependency, no network.
 *
 * A token is a deterministic function of (appId, appCertificate, salt, issueTs,
 * expire, services), so `salt`/`issueTs` are injectable to pin the output in a
 * test. Everything is packed little-endian; the payload is zlib-compressed and
 * base64-encoded behind the version prefix, exactly as the SDK expects.
 */
final class AgoraTokenBuilder
{
    private const VERSION = '007';

    private const SERVICE_RTC = 1;
    private const SERVICE_RTM = 2;

    // RTC privileges.
    private const PRIV_JOIN_CHANNEL   = 1;
    private const PRIV_PUBLISH_AUDIO  = 2;
    private const PRIV_PUBLISH_VIDEO  = 3;
    private const PRIV_PUBLISH_DATA   = 4;

    // RTM privileges.
    private const PRIV_RTM_LOGIN = 1;

    public function __construct(
        private readonly string $appId,
        private readonly string $appCertificate,
    ) {
    }

    /**
     * An RTC token for a channel and string user account, granting join and the
     * three publish rights (a live class is interactive; who appears on screen
     * is the room UI's concern, not the token's).
     *
     * @param int $expire seconds from `issueTs` the token and its privileges live
     */
    public function rtcToken(
        string $channel,
        string $account,
        int $expire,
        ?int $issueTs = null,
        ?int $salt = null,
    ): string {
        $service = self::packUint16(self::SERVICE_RTC)
            . self::packPrivileges([
                self::PRIV_JOIN_CHANNEL  => $expire,
                self::PRIV_PUBLISH_AUDIO => $expire,
                self::PRIV_PUBLISH_VIDEO => $expire,
                self::PRIV_PUBLISH_DATA  => $expire,
            ])
            . self::packString($channel)
            . self::packString($account);

        return $this->build($service, $expire, $issueTs, $salt);
    }

    /**
     * An RTM (signalling) token for a string user account — powers the in-room
     * chat, raise-hand and roster.
     */
    public function rtmToken(
        string $account,
        int $expire,
        ?int $issueTs = null,
        ?int $salt = null,
    ): string {
        $service = self::packUint16(self::SERVICE_RTM)
            . self::packPrivileges([self::PRIV_RTM_LOGIN => $expire])
            . self::packString($account);

        return $this->build($service, $expire, $issueTs, $salt);
    }

    private function build(string $service, int $expire, ?int $issueTs, ?int $salt): string
    {
        $issueTs ??= time();
        $salt    ??= random_int(1, 99999999);

        // signing = HMAC(key = uint32(issueTs), msg = appCertificate)
        //           then HMAC(key = uint32(salt), msg = <that>).
        $signing = hash_hmac('sha256', $this->appCertificate, self::packUint32($issueTs), true);
        $signing = hash_hmac('sha256', $signing, self::packUint32($salt), true);

        // One service per token here, so the service count is always 1.
        $signingInfo = self::packString($this->appId)
            . self::packUint32($issueTs)
            . self::packUint32($expire)
            . self::packUint32($salt)
            . self::packUint16(1)
            . $service;

        $signature = hash_hmac('sha256', $signingInfo, $signing, true);
        $content   = self::packString($signature) . $signingInfo;

        return self::VERSION . base64_encode((string) gzcompress($content));
    }

    /**
     * A privileges map: uint16 count, then (uint16 privilege, uint32 expire)*.
     *
     * @param array<int, int> $privileges
     */
    private static function packPrivileges(array $privileges): string
    {
        $out = self::packUint16(count($privileges));
        foreach ($privileges as $privilege => $expire) {
            $out .= self::packUint16($privilege) . self::packUint32($expire);
        }

        return $out;
    }

    private static function packUint16(int $value): string
    {
        return pack('v', $value);
    }

    private static function packUint32(int $value): string
    {
        return pack('V', $value);
    }

    private static function packString(string $value): string
    {
        return self::packUint16(strlen($value)) . $value;
    }
}
