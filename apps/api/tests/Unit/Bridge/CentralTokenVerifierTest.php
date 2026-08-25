<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Bridge;

use Central\Api\Domain\Authz\SystemRole;
use Central\Api\Infrastructure\Security\JwtTokenService;
use Central\Api\Support\Clock;
use Central\Api\Support\FrozenClock;
use Central\Api\Support\SystemClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../../infra/legacy-bridge/CentralTokenVerifier.php';

/**
 * The Phase 0 bridge: the legacy backend accepting tokens issued by the new
 * API, so both can authenticate the same caller during the migration.
 *
 * Tested against the *real* {@see JwtTokenService}, not a hand-rolled token —
 * if the two ever disagree about signing, this fails.
 */
final class CentralTokenVerifierTest extends TestCase
{
    private const SECRET = 'a-shared-secret-that-is-definitely-long-enough';
    private const USER_ID = '6f53a1bb-5434-4680-ba57-caf66327de9d';

    private Clock $clock;
    private JwtTokenService $tokens;

    protected function setUp(): void
    {
        // The bridge validates expiry against the wall clock, so tokens
        // under test must be issued against it as well.
        $this->clock = new SystemClock();
        $this->tokens = $this->tokenService();

        putenv('JWT_SECRET=' . self::SECRET);
        putenv('JWT_ISSUER=central-lms');
        putenv('JWT_AUDIENCE=central-clients');
        putenv('LEGACY_AUTH_AUDIT_ONLY=false');
    }

    protected function tearDown(): void
    {
        foreach (['JWT_SECRET', 'JWT_ISSUER', 'JWT_AUDIENCE', 'LEGACY_AUTH_AUDIT_ONLY'] as $name) {
            putenv($name);
        }
    }

    // --- the bug being fixed ------------------------------------------------

    #[Test]
    public function garbage_credentials_are_rejected(): void
    {
        // The original returned 100 for exactly this, which is what left the
        // entire legacy API open.
        $verifier = $this->verifier();

        self::assertSame(0.0, $verifier->verify('anything-at-all', '1'));
        self::assertSame(0.0, $verifier->verify('x', '999999'));
        self::assertSame(0.0, $verifier->verify('', '1'));
        self::assertSame(0.0, $verifier->verify(null, null));
    }

    // --- branch 1: tokens from the new API ----------------------------------

    #[Test]
    public function an_access_token_from_the_new_api_is_accepted(): void
    {
        $pair = $this->tokens->issue(self::USER_ID, 'admin@acme.test', [SystemRole::TenantAdmin], 'tenant-1');

        self::assertSame(100.0, $this->verifier()->verify($pair->accessToken, self::USER_ID));
    }

    #[Test]
    public function a_token_for_a_different_user_is_rejected(): void
    {
        // The token proves who the caller is; the request body says who they
        // claim to be. Disagreement means impersonation — which is exactly
        // what the old code permitted.
        $pair = $this->tokens->issue(self::USER_ID, 'admin@acme.test', [SystemRole::TenantAdmin]);

        self::assertSame(0.0, $this->verifier()->verify($pair->accessToken, 'some-other-user-id'));
    }

    #[Test]
    public function a_refresh_token_cannot_be_used_as_a_bearer_credential(): void
    {
        $pair = $this->tokens->issue(self::USER_ID, 'admin@acme.test', [SystemRole::Learner]);

        self::assertSame(0.0, $this->verifier()->verify($pair->refreshToken, self::USER_ID));
    }

    #[Test]
    public function a_token_signed_with_another_secret_is_rejected(): void
    {
        $attacker = $this->tokenService('a-completely-different-secret-of-good-length');
        $pair = $attacker->issue(self::USER_ID, 'attacker@example.com', [SystemRole::SuperAdmin]);

        self::assertSame(0.0, $this->verifier()->verify($pair->accessToken, self::USER_ID));
    }

    #[Test]
    public function an_expired_token_is_rejected(): void
    {
        $pair = $this->tokens->issue(self::USER_ID, 'admin@acme.test', [SystemRole::Learner]);

        // The verifier uses the wall clock, so mint one that is already stale.
        $expired = $this->tokenService(clock: new FrozenClock('2020-01-01T00:00:00+00:00'))
            ->issue(self::USER_ID, 'admin@acme.test', [SystemRole::Learner]);

        self::assertSame(100.0, $this->verifier()->verify($pair->accessToken, self::USER_ID));
        self::assertSame(0.0, $this->verifier()->verify($expired->accessToken, self::USER_ID));
    }

    #[Test]
    public function a_token_for_another_audience_is_rejected(): void
    {
        $other = $this->tokenService(audience: 'some-other-app');
        $pair = $other->issue(self::USER_ID, 'admin@acme.test', [SystemRole::Learner]);

        self::assertSame(0.0, $this->verifier()->verify($pair->accessToken, self::USER_ID));
    }

    #[Test]
    public function a_missing_shared_secret_fails_closed(): void
    {
        $pair = $this->tokens->issue(self::USER_ID, 'admin@acme.test', [SystemRole::Learner]);

        putenv('JWT_SECRET=');

        // Misconfiguration must not silently accept everything — which is
        // precisely how the original behaved.
        self::assertSame(0.0, $this->verifier()->verify($pair->accessToken, self::USER_ID));
    }

    // --- branch 2: existing legacy sessions ---------------------------------

    #[Test]
    public function a_matching_legacy_token_is_accepted_so_nobody_is_signed_out(): void
    {
        $stored = bin2hex(random_bytes(20));

        self::assertSame(100.0, $this->verifier($stored)->verify($stored, '42'));
    }

    #[Test]
    public function a_wrong_legacy_token_is_rejected(): void
    {
        $stored = bin2hex(random_bytes(20));

        self::assertSame(0.0, $this->verifier($stored)->verify(bin2hex(random_bytes(20)), '42'));
    }

    #[Test]
    public function a_partially_matching_legacy_token_is_rejected(): void
    {
        // The original used similar_text(), a *fuzzy* similarity measure, so a
        // near-miss would have scored highly. Only an exact match passes.
        $stored = bin2hex(random_bytes(20));
        $nearMiss = substr($stored, 0, -2) . 'ff';

        self::assertSame(0.0, $this->verifier($stored)->verify($nearMiss, '42'));
    }

    #[Test]
    public function a_user_with_no_stored_token_is_rejected(): void
    {
        self::assertSame(0.0, $this->verifier(null)->verify('some-token', '42'));
    }

    // --- audit mode ---------------------------------------------------------

    #[Test]
    public function audit_mode_allows_failures_through_but_is_off_by_default(): void
    {
        putenv('LEGACY_AUTH_AUDIT_ONLY=true');
        self::assertSame(100.0, $this->verifier()->verify('rubbish', '42'));

        // The safe default for an auth check is to enforce.
        putenv('LEGACY_AUTH_AUDIT_ONLY');
        self::assertSame(0.0, $this->verifier()->verify('rubbish', '42'));
    }

    // --- helpers ------------------------------------------------------------

    private function verifier(?string $storedToken = null): \CentralTokenVerifier
    {
        return new class ($storedToken) extends \CentralTokenVerifier {
            public function __construct(private readonly ?string $storedToken)
            {
                // Deliberately skips the parent constructor's mysqli handle —
                // storedTokenFor() is overridden, so no connection is needed.
                parent::__construct(null);
            }

            protected function storedTokenFor($userId)
            {
                return $this->storedToken;
            }
        };
    }

    private function tokenService(
        string $secret = self::SECRET,
        string $audience = 'central-clients',
        ?Clock $clock = null,
    ): JwtTokenService {
        return new JwtTokenService([
            'secret'      => $secret,
            'algorithm'   => 'HS256',
            'issuer'      => 'central-lms',
            'audience'    => $audience,
            'access_ttl'  => 900,
            'refresh_ttl' => 2592000,
            'leeway'      => 10,
        ], $clock ?? $this->clock);
    }
}
