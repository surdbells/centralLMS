<?php

declare(strict_types=1);

namespace Central\Api\Domain\Certificate;

/**
 * The three identifiers every certificate carries, and why there are three.
 *
 * - **number** is printed on the face and quoted in emails. It is readable and
 *   sequential-looking, which makes it useful to humans and guessable to
 *   everyone else — so it is never the thing that unlocks a record.
 * - **code** is short enough to read down a phone line. Same weakness, same
 *   rule.
 * - **hash** is what a verification URL carries. It is random, long, and the
 *   only one of the three that a lookup will accept without also being told
 *   who is asking.
 *
 * Conflating them is the mistake this class exists to prevent: a verification
 * endpoint keyed on a sequential number lets anyone walk the whole register
 * by adding one.
 */
final readonly class CertificateIdentity
{
    private function __construct(
        public string $number,
        public string $code,
        public string $hash,
    ) {
    }

    /**
     * Characters that survive being read aloud, written down and re-typed.
     *
     * No O/0, I/1, S/5 or Z/2 — a code is quoted over the phone often enough
     * that the ambiguity is a real support cost.
     */
    private const ALPHABET = 'ABCDEFGHJKLMNPQRTUVWXY346789';

    /**
     * @param string $tenantCode the tenant's short code, for a readable prefix
     * @param int    $sequence   how many certificates that tenant has issued this year
     */
    public static function mint(string $tenantCode, int $year, int $sequence): self
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $tenantCode) ?? '');
        $prefix = substr($prefix, 0, 6);

        if ($prefix === '') {
            $prefix = 'CERT';
        }

        return new self(
            number: sprintf('%s-%d-%05d', $prefix, $year, max(1, $sequence)),
            code: self::randomCode(10),
            // 32 bytes of randomness, not a hash of the number: hashing
            // something guessable produces something guessable.
            hash: bin2hex(random_bytes(32)),
        );
    }

    /**
     * Rebuilds an identity read back from storage.
     */
    public static function existing(string $number, string $code, string $hash): self
    {
        return new self($number, $code, $hash);
    }

    private static function randomCode(int $length): string
    {
        $alphabet = self::ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * Whether a string could be one of our codes at all.
     *
     * Cheap enough to run before touching the database, which keeps a
     * verification endpoint from becoming a way to make the database do work
     * on request.
     */
    public static function looksLikeCode(string $candidate): bool
    {
        $candidate = strtoupper(trim($candidate));

        return $candidate !== ''
            && strlen($candidate) <= 20
            && strspn($candidate, self::ALPHABET) === strlen($candidate);
    }

    public static function looksLikeHash(string $candidate): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', trim($candidate)) === 1;
    }
}
