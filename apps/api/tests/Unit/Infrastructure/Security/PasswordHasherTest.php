<?php

declare(strict_types=1);

namespace Central\Api\Tests\Unit\Infrastructure\Security;

use Central\Api\Infrastructure\Security\PasswordHasher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordHasher::class)]
final class PasswordHasherTest extends TestCase
{
    /** Cost 4 keeps the suite fast; production uses 12. */
    private const TEST_COST = 4;

    #[Test]
    public function a_hashed_password_verifies(): void
    {
        $hasher = new PasswordHasher(self::TEST_COST);
        $hash   = $hasher->hash('correct horse battery staple');

        self::assertTrue($hasher->verify('correct horse battery staple', $hash));
        self::assertFalse($hasher->verify('wrong horse battery staple', $hash));
    }

    #[Test]
    public function hashing_is_salted_so_the_same_password_never_produces_the_same_hash(): void
    {
        $hasher = new PasswordHasher(self::TEST_COST);

        self::assertNotSame($hasher->hash('same-password'), $hasher->hash('same-password'));
    }

    #[Test]
    public function legacy_bcrypt_hashes_verify_unchanged(): void
    {
        // The migration's most important property: existing users keep their
        // passwords. This is a bcrypt hash of "legacy-password" produced
        // independently of the class under test.
        $legacyHash = password_hash('legacy-password', PASSWORD_BCRYPT, ['cost' => self::TEST_COST]);

        $hasher = new PasswordHasher(self::TEST_COST);

        self::assertTrue($hasher->verify('legacy-password', $legacyHash));
        self::assertTrue($hasher->isSupported($legacyHash));
    }

    #[Test]
    public function raising_the_cost_marks_old_hashes_for_rehash(): void
    {
        $oldHash = (new PasswordHasher(4))->hash('a-password');

        // Same password, higher work factor: the hash still verifies, and is
        // flagged so it can be upgraded during a successful login.
        $stronger = new PasswordHasher(6);

        self::assertTrue($stronger->verify('a-password', $oldHash));
        self::assertTrue($stronger->needsRehash($oldHash));
        self::assertFalse($stronger->needsRehash($stronger->hash('a-password')));
    }

    #[Test]
    public function non_bcrypt_hashes_are_reported_as_unsupported(): void
    {
        // Legacy rows holding md5/sha1 must be found and force-reset, never
        // silently accepted.
        $hasher = new PasswordHasher(self::TEST_COST);

        self::assertFalse($hasher->isSupported(md5('a-password')));
        self::assertFalse($hasher->isSupported(sha1('a-password')));
        self::assertFalse($hasher->isSupported(''));
    }

    #[Test]
    public function a_corrupt_or_empty_stored_hash_fails_the_login_rather_than_the_request(): void
    {
        $hasher = new PasswordHasher(self::TEST_COST);

        self::assertFalse($hasher->verify('a-password', ''));
        self::assertFalse($hasher->verify('a-password', 'not-a-hash'));
        self::assertFalse($hasher->verify('', 'not-a-hash'));
    }

    #[Test]
    public function over_long_passwords_are_rejected_rather_than_silently_truncated(): void
    {
        $hasher = new PasswordHasher(self::TEST_COST);

        // bcrypt ignores everything past 72 bytes. Accepting a 200-character
        // password and comparing only its first 72 would be a quiet lie.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/72 bytes/');

        $hasher->hash(str_repeat('a', 73));
    }

    #[Test]
    public function an_over_long_password_never_verifies_against_a_truncated_hash(): void
    {
        $hasher = new PasswordHasher(self::TEST_COST);
        $hash   = $hasher->hash(str_repeat('a', 72));

        // Without the length guard, 73 a's would verify against a hash of 72.
        self::assertFalse($hasher->verify(str_repeat('a', 73), $hash));
    }

    #[Test]
    public function an_empty_password_cannot_be_hashed(): void
    {
        $hasher = new PasswordHasher(self::TEST_COST);

        $this->expectException(\InvalidArgumentException::class);
        $hasher->hash('   ');
    }
}
