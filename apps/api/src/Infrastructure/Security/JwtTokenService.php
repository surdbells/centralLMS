<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Security;

use Central\Api\Domain\Authz\Role;
use Central\Api\Domain\Authz\SystemRole;
use Central\Api\Domain\Exception\AuthenticationFailed;
use Central\Api\Support\Clock;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Ramsey\Uuid\Uuid;

/**
 * Mints and verifies the access/refresh token pair.
 *
 * Two rules this class exists to enforce:
 *
 *  1. An access token and a refresh token are different things. Each carries a
 *     `typ` claim that is checked on verification, so a long-lived refresh
 *     token can never be presented as a bearer credential — a mistake that is
 *     easy to make and very expensive to discover in production.
 *  2. `jti` is always present, so an individual token can be revoked by
 *     denylisting its id without invalidating every session for that user.
 *
 * There is a third kind, and it is deliberately feeble: a media ticket. A
 * browser fetching an `<img src>` or seeking inside a `<video src>` sends no
 * Authorization header, so bytes cannot be protected the way JSON is. The
 * ticket is what stands in — see {@see issueMediaTicket()}.
 */
final class JwtTokenService
{
    private const TYPE_ACCESS  = 'access';
    private const TYPE_REFRESH = 'refresh';
    private const TYPE_MEDIA   = 'media';

    /**
     * How long a media ticket is good for.
     *
     * Long enough for a player to start and to keep seeking through a long
     * recording; short enough that a URL copied out of the address bar is
     * useless by the time it is pasted anywhere.
     */
    private const MEDIA_TTL = 900;

    private readonly string $secret;
    private readonly string $algorithm;
    private readonly string $issuer;
    private readonly string $audience;
    private readonly int $accessTtl;
    private readonly int $refreshTtl;
    private readonly int $leeway;

    /**
     * @param array<string, mixed> $config the `jwt` section of settings.php
     */
    public function __construct(array $config, private readonly Clock $clock)
    {
        $secret = (string) ($config['secret'] ?? '');

        // A blank or placeholder secret means every token in the system is
        // forgeable. Fail at construction rather than serving traffic.
        if (strlen($secret) < 32 || str_starts_with($secret, 'change-me')) {
            throw new \RuntimeException(
                'JWT_SECRET must be set to a random value of at least 32 characters. '
                . 'Generate one with: openssl rand -base64 48',
            );
        }

        $this->secret     = $secret;
        $this->algorithm  = (string) ($config['algorithm'] ?? 'HS256');
        $this->issuer     = (string) ($config['issuer'] ?? 'central-lms');
        $this->audience   = (string) ($config['audience'] ?? 'central-clients');
        $this->accessTtl  = (int) ($config['access_ttl'] ?? 900);
        $this->refreshTtl = (int) ($config['refresh_ttl'] ?? 2592000);
        $this->leeway     = (int) ($config['leeway'] ?? 10);
    }

    /**
     * @param list<Role|SystemRole|string> $roles roles the user holds; only their
     *                                             identifiers reach the token
     * @param array<string, mixed>         $extraClaims
     */
    public function issue(
        string $userId,
        string $email,
        array $roles,
        ?string $tenantId = null,
        array $extraClaims = [],
    ): TokenPair {
        $now = $this->clock->timestamp();

        $access = $this->encode([
            ...$extraClaims,
            'sub'   => $userId,
            'email' => $email,
            'tid'   => $tenantId,
            'roles' => self::roleIdentifiers($roles),
            'typ'   => self::TYPE_ACCESS,
        ], $now, $this->accessTtl);

        // The refresh token carries identity only. It grants no permissions,
        // so a leaked one cannot be replayed against a protected endpoint.
        $refresh = $this->encode([
            'sub' => $userId,
            'tid' => $tenantId,
            'typ' => self::TYPE_REFRESH,
        ], $now, $this->refreshTtl);

        return new TokenPair($access, $refresh, $this->accessTtl);
    }

    /**
     * A ticket that lets a browser fetch one file, briefly.
     *
     * `<video src>`, `<img src>` and a link somebody clicks all send no
     * Authorization header, so every media fetch in the application was
     * arriving unauthenticated and coming back 401. Nothing in the library
     * could be previewed and no lesson would play.
     *
     * Unlike the signed storage URL the legacy redirected to, this grants one
     * file and nothing else: `mid` names it, and a ticket for a document is
     * not a ticket for the video next to it. The bytes still come through the
     * application, so a file removed from the library stops being served the
     * moment the row goes, rather than when a storage signature happens to
     * expire.
     *
     * It is bearer credential all the same, and for its fifteen minutes
     * anybody holding it can read that one file. That is the cost of the
     * `src` attribute; it is why the window is small and why the ticket
     * carries no permissions beyond the file it names.
     */
    public function issueMediaTicket(string $userId, ?string $tenantId, string $mediaId): string
    {
        return $this->encode([
            'sub' => $userId,
            'tid' => $tenantId,
            'mid' => $mediaId,
            'typ' => self::TYPE_MEDIA,
        ], $this->clock->timestamp(), self::MEDIA_TTL);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AuthenticationFailed
     */
    public function verifyMediaTicket(string $ticket, string $mediaId): array
    {
        $claims = $this->verify($ticket, self::TYPE_MEDIA);

        if (($claims['mid'] ?? null) !== $mediaId) {
            // A ticket for one file presented for another. Signed, unexpired,
            // and still not permission to read this.
            throw new AuthenticationFailed(reason: 'ticket_for_another_file');
        }

        return $claims;
    }

    public function mediaTicketTtl(): int
    {
        return self::MEDIA_TTL;
    }

    /**
     * Verifies an access token and returns its claims.
     *
     * Deliberately returns claims rather than a user: resolving roles to
     * permissions needs the RoleRepository, which this class must not depend
     * on. {@see \Central\Api\Infrastructure\Security\TokenAuthenticator} composes
     * the two.
     *
     * @return array<string, mixed>
     *
     * @throws AuthenticationFailed
     */
    public function verifyAccessToken(string $token): array
    {
        return $this->verify($token, self::TYPE_ACCESS);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AuthenticationFailed
     */
    public function verifyRefreshToken(string $token): array
    {
        return $this->verify($token, self::TYPE_REFRESH);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AuthenticationFailed
     */
    private function verify(string $token, string $expectedType): array
    {
        if (trim($token) === '') {
            throw new AuthenticationFailed(reason: 'empty_token');
        }

        // firebase/php-jwt reads the wall clock unless told otherwise. Pointing
        // it at the injected Clock keeps expiry behaviour deterministic in
        // tests and consistent with the timestamps we mint tokens with.
        $previousLeeway    = JWT::$leeway;
        $previousTimestamp = JWT::$timestamp;
        JWT::$leeway       = $this->leeway;
        JWT::$timestamp    = $this->clock->timestamp();

        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algorithm));
        } catch (ExpiredException) {
            throw new AuthenticationFailed('Your session has expired. Please sign in again.', 'expired');
        } catch (SignatureInvalidException) {
            throw new AuthenticationFailed(reason: 'bad_signature');
        } catch (BeforeValidException) {
            throw new AuthenticationFailed(reason: 'not_yet_valid');
        } catch (\UnexpectedValueException | \InvalidArgumentException | \DomainException $e) {
            throw new AuthenticationFailed(reason: 'malformed: ' . $e->getMessage());
        } finally {
            JWT::$leeway    = $previousLeeway;
            JWT::$timestamp = $previousTimestamp;
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        if (($claims['typ'] ?? null) !== $expectedType) {
            throw new AuthenticationFailed(reason: 'wrong_token_type');
        }

        if (($claims['iss'] ?? null) !== $this->issuer) {
            throw new AuthenticationFailed(reason: 'bad_issuer');
        }

        if (($claims['aud'] ?? null) !== $this->audience) {
            throw new AuthenticationFailed(reason: 'bad_audience');
        }

        if (($claims['sub'] ?? '') === '') {
            throw new AuthenticationFailed(reason: 'no_subject');
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function encode(array $claims, int $issuedAt, int $ttl): string
    {
        return JWT::encode(
            array_filter([
                ...$claims,
                'iss' => $this->issuer,
                'aud' => $this->audience,
                'iat' => $issuedAt,
                'nbf' => $issuedAt,
                'exp' => $issuedAt + $ttl,
                'jti' => Uuid::uuid4()->toString(),
            ], static fn (mixed $v): bool => $v !== null),
            $this->secret,
            $this->algorithm,
        );
    }

    /**
     * Normalises whatever the caller has to hand into token role identifiers.
     *
     * @param list<Role|SystemRole|string> $roles
     *
     * @return list<string>
     */
    private static function roleIdentifiers(array $roles): array
    {
        return array_values(array_map(static function (Role|SystemRole|string $role): string {
            return match (true) {
                $role instanceof Role       => $role->id,
                $role instanceof SystemRole => $role->value,
                default                     => $role,
            };
        }, $roles));
    }

    public function accessTokenTtl(): int
    {
        return $this->accessTtl;
    }

    public function refreshTokenTtl(): int
    {
        return $this->refreshTtl;
    }
}
