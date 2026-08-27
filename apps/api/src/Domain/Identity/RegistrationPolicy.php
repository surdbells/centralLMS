<?php

declare(strict_types=1);

namespace Central\Api\Domain\Identity;

use Central\Api\Domain\Settings\SettingKey;

/**
 * Whether an organisation lets people make themselves an account, and who.
 *
 * Read from the tenant's own settings rather than decided globally: one
 * organisation opening its doors is not a reason for the next one to.
 *
 * The default is closed. The legacy's `register.php` was open to anyone who
 * found it and inserted rows with no `tenant_id` at all, so a self-registered
 * account belonged to no organisation and was visible from all of them.
 */
final readonly class RegistrationPolicy
{
    /**
     * @param list<string> $domains lower-cased, without the `@`
     */
    private function __construct(
        public bool $isOpen,
        public array $domains,
    ) {
    }

    /**
     * @param array<string, string|int|bool> $settings
     */
    public static function from(array $settings): self
    {
        return new self(
            isOpen: filter_var(
                $settings[SettingKey::PublicRegistration->value] ?? false,
                FILTER_VALIDATE_BOOL,
            ),
            domains: self::parse((string) ($settings[SettingKey::RegistrationDomains->value] ?? '')),
        );
    }

    /** Closed, for anything that needs a policy before it has settings. */
    public static function closed(): self
    {
        return new self(false, []);
    }

    /**
     * Why this address may not register, or null if it may.
     *
     * One message for "we are closed" and one for "not that address". The
     * second has to name the domains, or somebody with a personal address is
     * left guessing what is wanted.
     */
    public function reasonToRefuse(string $email): ?string
    {
        if (!$this->isOpen) {
            return 'This organisation does not take registrations. Ask an administrator for an invitation.';
        }

        if ($this->domains === []) {
            return null;
        }

        $at = strrpos($email, '@');

        if ($at === false) {
            return 'Enter a valid email address.';
        }

        $domain = strtolower(substr($email, $at + 1));

        if (in_array($domain, $this->domains, true)) {
            return null;
        }

        return sprintf(
            'Registration here is open to %s addresses only.',
            self::list($this->domains),
        );
    }

    /**
     * @return list<string>
     */
    private static function parse(string $raw): array
    {
        $domains = [];

        foreach (preg_split('/[,\s]+/', strtolower(trim($raw))) ?: [] as $domain) {
            // A domain written as `@acme.com` or `acme.com` means the same
            // thing, and somebody will write it both ways.
            $domain = ltrim(trim($domain), '@');

            if ($domain !== '') {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    /**
     * @param list<string> $domains
     */
    private static function list(array $domains): string
    {
        if (count($domains) === 1) {
            return $domains[0];
        }

        $last = array_pop($domains);

        return implode(', ', $domains) . ' and ' . $last;
    }
}
