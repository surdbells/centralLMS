<?php

declare(strict_types=1);

namespace Central\Api\Support;

/**
 * Authenticated symmetric encryption for secrets held at rest.
 *
 * Used for tenant OAuth credentials: a `refresh_token` for a customer's Zoom
 * or Microsoft account is a long-lived key to their conferencing tenancy, and
 * the legacy system stored those in plaintext. A database backup leak should
 * not hand an attacker every customer's meeting platform.
 *
 * AES-256-GCM, so ciphertext is tamper-evident as well as unreadable —
 * flipping bits fails the tag check instead of silently decrypting to garbage.
 * Output is `v1.<base64 nonce>.<base64 tag>.<base64 ciphertext>`; the version
 * prefix is what makes a future key or algorithm rotation possible.
 */
final class Cipher
{
    private const CIPHER  = 'aes-256-gcm';
    private const VERSION = 'v1';
    private const KEY_BYTES = 32;
    private const NONCE_BYTES = 12;
    private const TAG_BYTES = 16;

    private readonly string $key;

    public function __construct(string $base64Key)
    {
        $key = base64_decode($base64Key, true);

        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new \RuntimeException(
                'CREDENTIALS_ENCRYPTION_KEY must be 32 bytes, base64-encoded. '
                . 'Generate one with: openssl rand -base64 32',
            );
        }

        $this->key = $key;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(self::NONCE_BYTES);
        $tag   = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_BYTES,
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return implode('.', [
            self::VERSION,
            base64_encode($nonce),
            base64_encode($tag),
            base64_encode($ciphertext),
        ]);
    }

    /**
     * @throws \RuntimeException when the value is malformed, tampered with, or
     *                           encrypted under a different key
     */
    public function decrypt(string $payload): string
    {
        $parts = explode('.', $payload, 4);

        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            throw new \RuntimeException('Ciphertext is malformed or uses an unsupported version.');
        }

        [, $nonce, $tag, $ciphertext] = array_map(
            static fn (string $part): string|false => base64_decode($part, true),
            $parts,
        );

        if ($nonce === false || $tag === false || $ciphertext === false) {
            throw new \RuntimeException('Ciphertext is not valid base64.');
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
        );

        if ($plaintext === false) {
            // Wrong key or tampered payload — indistinguishable on purpose.
            throw new \RuntimeException('Could not decrypt value: wrong key or corrupted data.');
        }

        return $plaintext;
    }

    /**
     * Convenience for nullable columns.
     */
    public function encryptNullable(?string $plaintext): ?string
    {
        return $plaintext === null || $plaintext === '' ? null : $this->encrypt($plaintext);
    }

    /**
     * Returns null rather than throwing when a stored value cannot be read.
     *
     * A credential encrypted under a rotated key should disconnect that one
     * integration and prompt a reconnect, not break every request that happens
     * to load the row.
     */
    public function decryptNullable(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        try {
            return $this->decrypt($payload);
        } catch (\RuntimeException) {
            return null;
        }
    }
}
