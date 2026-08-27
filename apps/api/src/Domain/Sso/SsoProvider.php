<?php

declare(strict_types=1);

namespace Central\Api\Domain\Sso;

/**
 * A directory somebody can sign in from.
 *
 * Both are OpenID Connect, which is why there is one implementation and an
 * enum rather than two of everything. What differs is the discovery document
 * and, for Entra, that the tenant chooses which directory to trust.
 *
 * "Active Directory" reached over the internet is Entra ID (what Azure AD was
 * called), and that is this `microsoft` case. An on-premises directory reached
 * by LDAP is a different thing and is not here.
 */
enum SsoProvider: string
{
    case Google = 'google';
    case Microsoft = 'microsoft';

    public function label(): string
    {
        return match ($this) {
            self::Google    => 'Google',
            self::Microsoft => 'Microsoft',
        };
    }

    /**
     * Where the provider publishes its endpoints and signing keys.
     *
     * Fetched rather than hardcoded: both rotate their signing keys, and a
     * pinned JWKS URL is a sign-in outage waiting for the next rotation.
     *
     * `$directory` is Entra's: `organizations` accepts any work or school
     * account, `common` also accepts personal Microsoft accounts, and a GUID
     * or domain pins it to one company. Google has no equivalent and ignores
     * it — a Workspace is narrowed by the `hd` claim instead, which is what
     * {@see SsoConnection::$domains} checks.
     */
    public function discoveryUrl(?string $directory): string
    {
        return match ($this) {
            self::Google => 'https://accounts.google.com/.well-known/openid-configuration',
            self::Microsoft => sprintf(
                'https://login.microsoftonline.com/%s/v2.0/.well-known/openid-configuration',
                // `organizations` and not `common`, so the default is a work
                // account. An LMS sold to companies has no business accepting
                // somebody's personal Hotmail as proof of who they are.
                $directory === null || trim($directory) === '' ? 'organizations' : trim($directory),
            ),
        };
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        // The three that OpenID Connect defines, and nothing else. Neither
        // provider is asked for a calendar, a mailbox or a directory listing,
        // because none of that is needed to establish who somebody is.
        return ['openid', 'email', 'profile'];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
