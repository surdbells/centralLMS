<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Infrastructure\Security;

use Central\Api\Domain\Authz\SystemRole;
use Central\Api\Domain\Exception\AuthenticationFailed;
use Central\Api\Infrastructure\Security\JwtTokenService;
use Central\Api\Support\FrozenClock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Token mechanics only — minting, expiry, signature, audience, type
 * separation. Turning claims into a user is {@see TokenAuthenticatorTest}.
 */
#[CoversClass(JwtTokenService::class)]
final class JwtTokenServiceTest extends TestCase
{
    private const SECRET = 'a-test-secret-that-is-definitely-long-enough';

    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock('2026-06-01T12:00:00+00:00');
    }

    #[Test]
    public function it_issues_a_token_carrying_identity_and_role_identifiers(): void
    {
        $service = $this->service();

        $pair   = $service->issue('user-1', 'tutor@example.com', [SystemRole::Instructor], 'tenant-9');
        $claims = $service->verifyAccessToken($pair->accessToken);

        self::assertSame('user-1', $claims['sub']);
        self::assertSame('tutor@example.com', $claims['email']);
        self::assertSame('tenant-9', $claims['tid']);
        self::assertSame(['instructor'], $claims['roles']);
    }

    #[Test]
    public function the_token_carries_role_ids_and_never_permissions(): void
    {
        $service = $this->service();

        $pair   = $service->issue('user-1', 'a@example.com', [SystemRole::TenantAdmin]);
        $claims = $service->verifyAccessToken($pair->accessToken);

        // Permissions are resolved per request from the RoleRepository, so a
        // token minted before a role was edited cannot carry stale grants.
        self::assertArrayNotHasKey('permissions', $claims);
        self::assertArrayHasKey('roles', $claims);
    }

    #[Test]
    public function every_token_has_a_unique_id_so_it_can_be_revoked_individually(): void
    {
        $service = $this->service();

        $first  = $service->verifyAccessToken($service->issue('u', 'a@example.com', [])->accessToken);
        $second = $service->verifyAccessToken($service->issue('u', 'a@example.com', [])->accessToken);

        self::assertNotSame($first['jti'], $second['jti']);
    }

    #[Test]
    public function a_refresh_token_is_rejected_where_an_access_token_is_expected(): void
    {
        $service = $this->service();
        $pair    = $service->issue('user-1', 'learner@example.com', [SystemRole::Learner]);

        // The whole point of the `typ` claim: a long-lived refresh token must
        // never work as a bearer credential.
        $this->expectException(AuthenticationFailed::class);
        $service->verifyAccessToken($pair->refreshToken);
    }

    #[Test]
    public function an_access_token_is_rejected_where_a_refresh_token_is_expected(): void
    {
        $service = $this->service();
        $pair    = $service->issue('user-1', 'learner@example.com', [SystemRole::Learner]);

        $this->expectException(AuthenticationFailed::class);
        $service->verifyRefreshToken($pair->accessToken);
    }

    #[Test]
    public function the_refresh_token_grants_no_roles(): void
    {
        $service = $this->service();
        $pair    = $service->issue('user-1', 'a@example.com', [SystemRole::SuperAdmin], 'tenant-1');

        $claims = $service->verifyRefreshToken($pair->refreshToken);

        // A leaked refresh token proves identity, never capability.
        self::assertArrayNotHasKey('roles', $claims);
        self::assertSame('user-1', $claims['sub']);
    }

    #[Test]
    public function it_rejects_an_expired_access_token(): void
    {
        $service = $this->service(accessTtl: 900);
        $pair    = $service->issue('user-1', 'learner@example.com', [SystemRole::Learner]);

        // Past the 900s TTL and the 10s leeway.
        $this->clock->advance('+1000 seconds');

        $this->expectException(AuthenticationFailed::class);
        $service->verifyAccessToken($pair->accessToken);
    }

    #[Test]
    public function it_accepts_a_token_that_is_only_just_inside_the_leeway(): void
    {
        $service = $this->service(accessTtl: 900);
        $pair    = $service->issue('user-1', 'learner@example.com', [SystemRole::Learner]);

        $this->clock->advance('+905 seconds');

        // Clocks between servers drift; the leeway stops that from logging
        // people out at random.
        self::assertSame('user-1', $service->verifyAccessToken($pair->accessToken)['sub']);
    }

    #[Test]
    public function it_rejects_a_token_signed_with_a_different_secret(): void
    {
        $attacker = $this->service(secret: 'a-completely-different-secret-of-good-length');
        $pair     = $attacker->issue('user-1', 'attacker@example.com', [SystemRole::SuperAdmin]);

        $this->expectException(AuthenticationFailed::class);
        $this->service()->verifyAccessToken($pair->accessToken);
    }

    #[Test]
    public function it_rejects_a_token_issued_for_another_audience(): void
    {
        $other = $this->service(audience: 'some-other-app');
        $pair  = $other->issue('user-1', 'user@example.com', [SystemRole::Learner]);

        $this->expectException(AuthenticationFailed::class);
        $this->service()->verifyAccessToken($pair->accessToken);
    }

    #[Test]
    public function it_refuses_to_start_with_a_placeholder_secret(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/JWT_SECRET/');

        new JwtTokenService(['secret' => 'change-me-to-a-long-random-string'], $this->clock);
    }

    #[Test]
    public function it_refuses_to_start_with_a_short_secret(): void
    {
        $this->expectException(\RuntimeException::class);

        new JwtTokenService(['secret' => 'too-short'], $this->clock);
    }

    #[Test]
    public function malformed_input_is_rejected_rather_than_crashing(): void
    {
        $service = $this->service();

        $inputs   = ['', '   ', 'not-a-jwt', 'a.b', 'a.b.c.d'];
        $rejected = 0;

        foreach ($inputs as $garbage) {
            try {
                $service->verifyAccessToken($garbage);
            } catch (AuthenticationFailed) {
                ++$rejected;
            }
        }

        self::assertCount($rejected, $inputs, 'Every malformed token must be rejected.');
    }

    private function service(
        string $secret = self::SECRET,
        string $audience = 'central-clients',
        int $accessTtl = 900,
    ): JwtTokenService {
        return new JwtTokenService([
            'secret'      => $secret,
            'algorithm'   => 'HS256',
            'issuer'      => 'central-lms',
            'audience'    => $audience,
            'access_ttl'  => $accessTtl,
            'refresh_ttl' => 2592000,
            'leeway'      => 10,
        ], $this->clock);
    }
}
