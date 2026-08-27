<?php

declare(strict_types=1);

namespace Central\Api\Domain\Sso;

/**
 * One organisation's arrangement with one directory.
 *
 * The credentials are the organisation's own: a company's Google Workspace or
 * Entra directory is not the platform's to speak for, and a shared client id
 * would let any tenant's sign-in vouch for any other's.
 */
final readonly class SsoConnection
{
    /**
     * @param list<string> $domains lower-cased, without the `@`
     */
    public function __construct(
        public string $id,
        public string $tenantId,
        public SsoProvider $provider,
        public bool $enabled,
        public string $clientId,
        /** Encrypted at rest and never returned by any endpoint. */
        public string $clientSecret,
        public ?string $directory = null,
        public array $domains = [],
        public bool $autoProvision = false,
    ) {
    }

    /**
     * Why this address may not sign in this way, or null if it may.
     *
     * The provider having vouched for an address is not on its own a reason to
     * let it in: `accounts.google.com` will happily vouch for a personal
     * Gmail. The domains are what makes "sign in with Google" mean "sign in
     * with your work account".
     */
    public function reasonToRefuse(string $email): ?string
    {
        if ($this->domains === []) {
            return null;
        }

        $at = strrpos($email, '@');

        if ($at === false) {
            return 'That account has no usable email address.';
        }

        if (in_array(strtolower(substr($email, $at + 1)), $this->domains, true)) {
            return null;
        }

        return sprintf(
            'Sign in here is limited to %s accounts.',
            self::list($this->domains),
        );
    }

    /**
     * @param  list<string> $domains
     */
    private static function list(array $domains): string
    {
        if (count($domains) === 1) {
            return $domains[0];
        }

        $last = array_pop($domains);

        return implode(', ', $domains) . ' and ' . $last;
    }

    /**
     * `acme.com, @acme.co.uk` either way round, because somebody will
     * write it both ways.
     *
     * @return list<string>
     */
    public static function parseDomains(string $raw): array
    {
        $domains = [];

        foreach (preg_split('/[,\s]+/', strtolower(trim($raw))) ?: [] as $domain) {
            $domain = ltrim(trim($domain), '@');

            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * What an administrator is shown.
     *
     * The secret is not in it, and there is no endpoint that returns it. An
     * administrator who has lost theirs gets a new one from the provider.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'provider'      => $this->provider->value,
            'providerLabel' => $this->provider->label(),
            'enabled'       => $this->enabled,
            'clientId'      => $this->clientId,
            'hasSecret'     => $this->clientSecret !== '',
            'directory'     => $this->directory,
            'domains'       => $this->domains,
            'autoProvision' => $this->autoProvision,
        ];
    }
}
