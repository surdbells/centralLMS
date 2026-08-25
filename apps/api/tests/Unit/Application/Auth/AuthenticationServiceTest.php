<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Application\Auth;

use Central\Api\Application\Auth\AuthenticationService;
use Central\Api\Domain\Authz\Permission;
use Central\Api\Domain\Authz\SystemRole;
use Central\Api\Domain\Exception\AuthenticationFailed;
use Central\Api\Domain\Identity\User;
use Central\Api\Domain\Identity\UserStatus;
use Central\Api\Infrastructure\Security\JwtTokenService;
use Central\Api\Infrastructure\Security\PasswordHasher;
use Central\Api\Support\FrozenClock;
use Central\Api\Tests\Doubles\FakeRefreshTokenRepository;
use Central\Api\Tests\Doubles\FakeRoleRepository;
use Central\Api\Tests\Doubles\FakeUserRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AuthenticationService::class)]
final class AuthenticationServiceTest extends TestCase
{
    private const PASSWORD = 'CorrectHorseBattery9';

    private FrozenClock $clock;
    private PasswordHasher $hasher;
    private JwtTokenService $tokens;
    private FakeUserRepository $users;
    private FakeRefreshTokenRepository $refreshTokens;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock('2026-06-01T12:00:00+00:00');
        // Cost 4 keeps the suite fast; production is 12.
        $this->hasher = new PasswordHasher(4);
        $this->tokens = new JwtTokenService([
            'secret'      => 'a-test-secret-that-is-definitely-long-enough',
            'algorithm'   => 'HS256',
            'issuer'      => 'central-lms',
            'audience'    => 'central-clients',
            'access_ttl'  => 900,
            'refresh_ttl' => 2592000,
            'leeway'      => 10,
        ], $this->clock);

        $this->users = new FakeUserRepository();
        $this->refreshTokens = new FakeRefreshTokenRepository();
    }

    #[Test]
    public function a_valid_sign_in_returns_tokens_roles_and_permissions(): void
    {
        $this->givenUser();

        $result = $this->service()->login('ada@example.com', self::PASSWORD);

        self::assertNotSame('', $result->tokens->accessToken);
        self::assertNotSame('', $result->tokens->refreshToken);
        self::assertSame(900, $result->tokens->expiresIn);
        self::assertTrue($result->authenticated->can(Permission::IltSessionManage));
        self::assertSame('ada@example.com', $result->user->email);
    }

    #[Test]
    public function the_refresh_token_is_persisted_so_it_can_be_revoked(): void
    {
        $this->givenUser();

        $result = $this->service()->login('ada@example.com', self::PASSWORD);

        self::assertTrue($this->refreshTokens->isActive($result->tokens->refreshToken, $this->clock->now()));
    }

    #[Test]
    public function a_wrong_password_and_an_unknown_email_are_indistinguishable(): void
    {
        $this->givenUser();

        $messages = [];

        foreach ([['ada@example.com', 'wrong'], ['nobody@example.com', 'wrong']] as [$email, $password]) {
            try {
                $this->service()->login($email, $password);
                self::fail('Expected sign-in to be rejected.');
            } catch (AuthenticationFailed $e) {
                $messages[] = $e->getMessage();
            }
        }

        // Differing messages would let an attacker enumerate valid addresses.
        self::assertSame($messages[0], $messages[1]);
    }

    #[Test]
    public function an_unknown_email_still_costs_a_password_verification(): void
    {
        // Timing must not reveal whether the address exists, which means the
        // decoy hash has to actually be verified against.
        $this->givenUser();

        $started = hrtime(true);
        try {
            $this->service()->login('nobody@example.com', 'wrong');
        } catch (AuthenticationFailed) {
        }
        $unknownEmail = hrtime(true) - $started;

        $started = hrtime(true);
        try {
            $this->service()->login('ada@example.com', 'wrong');
        } catch (AuthenticationFailed) {
        }
        $knownEmail = hrtime(true) - $started;

        // Same order of magnitude — a skipped bcrypt would be ~100x faster.
        $ratio = max($unknownEmail, $knownEmail) / max(1, min($unknownEmail, $knownEmail));
        self::assertLessThan(10, $ratio, 'Sign-in timing differs enough to enumerate accounts.');
    }

    #[Test]
    public function a_suspended_account_cannot_sign_in_even_with_the_right_password(): void
    {
        $this->givenUser(status: UserStatus::Suspended);

        $this->expectException(AuthenticationFailed::class);
        $this->service()->login('ada@example.com', self::PASSWORD);
    }

    #[Test]
    public function an_invited_account_with_no_password_cannot_sign_in(): void
    {
        $this->users->add(new User(
            id: 'user-2',
            tenantId: 'tenant-1',
            email: 'invited@example.com',
            status: UserStatus::Invited,
            passwordHash: null,
            roleIds: [SystemRole::Learner->value],
        ));

        // Must fail on the missing hash, never compare against null.
        $this->expectException(AuthenticationFailed::class);
        $this->service()->login('invited@example.com', self::PASSWORD);
    }

    #[Test]
    public function a_password_hashed_at_a_lower_cost_is_upgraded_on_sign_in(): void
    {
        $weakHash = (new PasswordHasher(4))->hash(self::PASSWORD);
        $this->users->add(new User(
            id: 'user-1',
            tenantId: 'tenant-1',
            email: 'ada@example.com',
            status: UserStatus::Active,
            passwordHash: $weakHash,
            roleIds: [SystemRole::Instructor->value],
        ));

        // The service is configured with a higher cost than the stored hash.
        $service = $this->service(new PasswordHasher(5));
        $service->login('ada@example.com', self::PASSWORD);

        self::assertNotNull($this->users->rehashed['user-1'] ?? null, 'Hash should have been upgraded.');
        // And the new hash still verifies the same password.
        self::assertTrue((new PasswordHasher(5))->verify(self::PASSWORD, $this->users->rehashed['user-1']));
    }

    #[Test]
    public function a_successful_sign_in_is_recorded(): void
    {
        $this->givenUser();

        $this->service()->login('ada@example.com', self::PASSWORD);

        self::assertSame($this->clock->now()->getTimestamp(), $this->users->lastLogin['user-1']->getTimestamp());
    }

    #[Test]
    public function refreshing_rotates_the_token_and_kills_the_old_one(): void
    {
        $this->givenUser();
        $service = $this->service();

        $first = $service->login('ada@example.com', self::PASSWORD);
        $second = $service->refresh($first->tokens->refreshToken);

        self::assertNotSame($first->tokens->refreshToken, $second->tokens->refreshToken);
        // Replay of a stolen token fails once the real client has refreshed.
        self::assertFalse($this->refreshTokens->isActive($first->tokens->refreshToken, $this->clock->now()));
        self::assertTrue($this->refreshTokens->isActive($second->tokens->refreshToken, $this->clock->now()));
    }

    #[Test]
    public function a_revoked_refresh_token_is_rejected_even_though_its_signature_is_valid(): void
    {
        $this->givenUser();
        $service = $this->service();

        $result = $service->login('ada@example.com', self::PASSWORD);
        $service->logout($result->tokens->refreshToken);

        // The JWT still verifies; the server-side record is what stops it.
        $this->expectException(AuthenticationFailed::class);
        $service->refresh($result->tokens->refreshToken);
    }

    #[Test]
    public function an_access_token_cannot_be_used_to_refresh(): void
    {
        $this->givenUser();
        $service = $this->service();

        $result = $service->login('ada@example.com', self::PASSWORD);

        $this->expectException(AuthenticationFailed::class);
        $service->refresh($result->tokens->accessToken);
    }

    #[Test]
    public function suspending_a_user_stops_their_session_renewing(): void
    {
        $this->givenUser();
        $service = $this->service();

        $result = $service->login('ada@example.com', self::PASSWORD);

        // Administrator suspends the account mid-session.
        $this->users->add(new User(
            id: 'user-1',
            tenantId: 'tenant-1',
            email: 'ada@example.com',
            status: UserStatus::Suspended,
            passwordHash: $this->hasher->hash(self::PASSWORD),
            roleIds: [SystemRole::Instructor->value],
        ));

        try {
            $service->refresh($result->tokens->refreshToken);
            self::fail('Expected refresh to be refused.');
        } catch (AuthenticationFailed) {
            // The token is also burned, so it cannot be retried.
            self::assertFalse($this->refreshTokens->isActive($result->tokens->refreshToken, $this->clock->now()));
        }
    }

    #[Test]
    public function signing_out_everywhere_revokes_every_session(): void
    {
        $this->givenUser();
        $service = $this->service();

        $service->login('ada@example.com', self::PASSWORD);
        $service->login('ada@example.com', self::PASSWORD);
        $third = $service->login('ada@example.com', self::PASSWORD);

        self::assertSame(3, $service->logoutEverywhere('user-1'));
        self::assertFalse($this->refreshTokens->isActive($third->tokens->refreshToken, $this->clock->now()));
    }

    #[Test]
    public function signing_out_with_an_unknown_token_is_silent(): void
    {
        $this->givenUser();
        $service = $this->service();
        $live = $service->login('ada@example.com', self::PASSWORD);

        // Sign-out must always appear to succeed...
        $service->logout('not-a-real-token');
        $service->logout('');

        // ...without disturbing a genuine session.
        self::assertTrue($this->refreshTokens->isActive($live->tokens->refreshToken, $this->clock->now()));
    }

    private function givenUser(UserStatus $status = UserStatus::Active): void
    {
        $this->users->add(new User(
            id: 'user-1',
            tenantId: 'tenant-1',
            email: 'ada@example.com',
            status: $status,
            firstName: 'Ada',
            lastName: 'Lovelace',
            passwordHash: $this->hasher->hash(self::PASSWORD),
            roleIds: [SystemRole::Instructor->value],
        ));
    }

    private function service(?PasswordHasher $hasher = null): AuthenticationService
    {
        return new AuthenticationService(
            $this->users,
            new FakeRoleRepository(),
            $this->refreshTokens,
            $hasher ?? $this->hasher,
            $this->tokens,
            $this->clock,
            new NullLogger(),
        );
    }
}
