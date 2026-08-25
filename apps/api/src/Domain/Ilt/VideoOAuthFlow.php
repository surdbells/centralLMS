<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * The "Connect Zoom" / "Connect Teams" handshake, per platform.
 *
 * Separate from {@see MeetingProvider} because it runs at a different time and
 * with different inputs: once, interactively, with a redirect and a consent
 * screen, versus continuously and headlessly for the life of the connection.
 */
interface VideoOAuthFlow
{
    public function id(): MeetingProviderId;

    /**
     * The URL to send the admin to in order to grant access.
     *
     * `state` is echoed back by the platform and must be verified on return —
     * it is what stops an attacker from tricking an admin into connecting an
     * account the attacker controls.
     */
    public function authorizationUrl(string $state, string $redirectUri): string;

    /**
     * Trade the `code` from the callback for the first token pair.
     *
     * @throws Exception\MeetingProviderError
     */
    public function exchangeCode(string $code, string $redirectUri): TokenGrant;

    /**
     * Trade a refresh token for a fresh access token.
     *
     * @throws Exception\MeetingProviderError
     */
    public function refresh(string $refreshToken): TokenGrant;
}
