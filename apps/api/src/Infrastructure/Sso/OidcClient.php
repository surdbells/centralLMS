<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Sso;

use Central\Api\Domain\Sso\SsoConnection;
use Central\Api\Domain\Sso\SsoFailed;
use Central\Api\Domain\Sso\SsoProvider;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerInterface;

/**
 * The OpenID Connect half of signing in with somebody else's directory.
 *
 * One implementation for both providers, because both are OpenID Connect. What
 * the provider contributes is a discovery document; everything from there on
 * — the authorize URL, the code exchange, the token verification — is the
 * same.
 *
 * Nothing here decides who may sign in. It answers "did this directory really
 * say this about this person", and the caller decides what to do about it.
 */
final class OidcClient
{
    /**
     * How long a discovery document and its keys are held.
     *
     * Both providers rotate signing keys, so this cannot be forever; an hour
     * is short enough to pick up a rotation and long enough that signing in is
     * not two extra round trips every time.
     */
    private const METADATA_TTL = 3600;

    /** A token issued more than this long ago is not accepted. */
    private const MAX_AGE_SECONDS = 600;

    /** Clock skew allowed between us and the provider. */
    private const LEEWAY_SECONDS = 60;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Where to send somebody to be identified.
     *
     * `state` and `nonce` are both required and both checked on the way back:
     * the state proves the callback answers a redirect we sent, and the nonce
     * ties the token inside it to that same redirect.
     */
    public function authorizeUrl(
        SsoConnection $connection,
        string $redirectUri,
        string $state,
        string $nonce,
    ): string {
        $metadata = $this->metadata($connection->provider, $connection->directory);

        $query = [
            'client_id'     => $connection->clientId,
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => implode(' ', $connection->provider->scopes()),
            'state'         => $state,
            'nonce'         => $nonce,
            // Ask for an account each time rather than silently reusing
            // whichever one the browser happens to be signed into — on a
            // shared machine that is somebody else's.
            'prompt'        => 'select_account',
        ];

        // Google honours this as a hint, and refuses anything outside the
        // domain when it is set. Belt and braces: the email claim is checked
        // against the same list afterwards, because a hint is only a hint.
        if ($connection->provider === SsoProvider::Google && count($connection->domains) === 1) {
            $query['hd'] = $connection->domains[0];
        }

        return $metadata['authorization_endpoint'] . '?' . http_build_query($query);
    }

    /**
     * Exchanges the code for an identity, and checks it.
     *
     * @throws SsoFailed when the provider refuses, or says something that does
     *                   not verify
     *
     * @return array{subject: string, email: string, emailVerified: bool,
     *               firstName: string, lastName: string, hostedDomain: string|null}
     */
    public function exchange(
        SsoConnection $connection,
        string $code,
        string $redirectUri,
        string $nonce,
    ): array {
        $metadata = $this->metadata($connection->provider, $connection->directory);

        $response = $this->post($metadata['token_endpoint'], [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'client_id'     => $connection->clientId,
            'client_secret' => $connection->clientSecret,
        ]);

        $idToken = $response['id_token'] ?? null;

        if (!is_string($idToken) || $idToken === '') {
            throw new SsoFailed('The directory did not say who signed in.');
        }

        return $this->verify($connection, $metadata, $idToken, $nonce);
    }

    /**
     * @param  array<string, mixed> $metadata
     * @return array{subject: string, email: string, emailVerified: bool,
     *               firstName: string, lastName: string, hostedDomain: string|null}
     */
    private function verify(
        SsoConnection $connection,
        array $metadata,
        string $idToken,
        string $nonce,
    ): array {
        JWT::$leeway = self::LEEWAY_SECONDS;

        try {
            // Verified against the provider's published keys. An unverified
            // id_token is a base64 string anybody can write, and treating one
            // as proof of identity is the whole failure mode this guards.
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($this->keys($metadata)));
        } catch (\Throwable $failure) {
            $this->logger->warning('An SSO token did not verify', [
                'provider' => $connection->provider->value,
                'tenant'   => $connection->tenantId,
                'error'    => $failure->getMessage(),
            ]);

            throw new SsoFailed('That sign-in could not be verified. Try again.');
        }

        $issuers = $this->acceptableIssuers($metadata, $claims);

        if (!in_array((string) ($claims['iss'] ?? ''), $issuers, true)) {
            throw new SsoFailed('That sign-in came from somewhere unexpected.');
        }

        // The audience is this tenant's own client id. Without this check, a
        // token minted for any other application registered with the same
        // provider would be accepted here.
        if (!$this->audienceMatches($claims['aud'] ?? null, $connection->clientId)) {
            throw new SsoFailed('That sign-in was not meant for this site.');
        }

        if (!hash_equals($nonce, (string) ($claims['nonce'] ?? ''))) {
            throw new SsoFailed('That sign-in did not match the request. Try again.');
        }

        $issuedAt = (int) ($claims['iat'] ?? 0);

        if ($issuedAt > 0 && $issuedAt < time() - self::MAX_AGE_SECONDS) {
            throw new SsoFailed('That sign-in took too long. Try again.');
        }

        $subject = (string) ($claims['sub'] ?? '');
        $email   = strtolower(trim((string) ($claims['email'] ?? '')));

        if ($subject === '' || $email === '') {
            throw new SsoFailed('The directory did not give an email address for that account.');
        }

        return [
            'subject' => $subject,
            'email'   => $email,
            // Google says so explicitly. Entra does not send the claim at all
            // for a work account, where the address is the directory's own and
            // is verified by definition.
            'emailVerified' => $connection->provider === SsoProvider::Microsoft
                || filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL),
            'firstName' => $this->name($claims, 'given_name', $email),
            'lastName'  => (string) ($claims['family_name'] ?? ''),
            // Google's "which Workspace", when there is one.
            'hostedDomain' => isset($claims['hd']) ? strtolower((string) $claims['hd']) : null,
        ];
    }

    /**
     * The issuers a token may claim.
     *
     * Entra's issuer carries the directory's own id, so `{tenantid}` in the
     * discovery document is a placeholder that has to be filled from the token
     * — and then checked, which is why the token's `tid` is substituted in
     * rather than the check being skipped.
     *
     * @param  array<string, mixed> $metadata
     * @param  array<string, mixed> $claims
     * @return list<string>
     */
    private function acceptableIssuers(array $metadata, array $claims): array
    {
        $issuer = (string) ($metadata['issuer'] ?? '');

        if (!str_contains($issuer, '{tenantid}')) {
            return [$issuer];
        }

        $directory = (string) ($claims['tid'] ?? '');

        return $directory === ''
            ? []
            : [str_replace('{tenantid}', $directory, $issuer)];
    }

    private function audienceMatches(mixed $audience, string $clientId): bool
    {
        if (is_string($audience)) {
            return hash_equals($clientId, $audience);
        }

        if (!is_array($audience)) {
            return false;
        }

        foreach ($audience as $one) {
            if (is_string($one) && hash_equals($clientId, $one)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function name(array $claims, string $key, string $email): string
    {
        $value = trim((string) ($claims[$key] ?? ''));

        if ($value !== '') {
            return $value;
        }

        // Something legible rather than an empty name on the account. The
        // person can correct it; a blank one just looks broken.
        return ucfirst(strstr($email, '@', true) ?: $email);
    }

    /**
     * The provider's endpoints, cached.
     *
     * @return array<string, mixed>
     */
    private function metadata(SsoProvider $provider, ?string $directory): array
    {
        $url  = $provider->discoveryUrl($directory);
        $item = $this->cache->getItem('sso.metadata.' . sha1($url));

        if ($item->isHit()) {
            /** @var array<string, mixed> */
            return $item->get();
        }

        $metadata = $this->get($url);

        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri', 'issuer'] as $required) {
            if (!isset($metadata[$required]) || !is_string($metadata[$required])) {
                throw new SsoFailed('That directory is not answering properly. Try again later.');
            }
        }

        $item->set($metadata)->expiresAfter(self::METADATA_TTL);
        $this->cache->save($item);

        return $metadata;
    }

    /**
     * @param  array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function keys(array $metadata): array
    {
        $url  = (string) $metadata['jwks_uri'];
        $item = $this->cache->getItem('sso.jwks.' . sha1($url));

        if ($item->isHit()) {
            /** @var array<string, mixed> */
            return $item->get();
        }

        $keys = $this->get($url);

        $item->set($keys)->expiresAfter(self::METADATA_TTL);
        $this->cache->save($item);

        return $keys;
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $url): array
    {
        try {
            $response = $this->http->sendRequest(
                new \GuzzleHttp\Psr7\Request('GET', $url, ['Accept' => 'application/json']),
            );
        } catch (\Throwable $failure) {
            $this->logger->error('Could not reach an identity provider', [
                'url'   => $url,
                'error' => $failure->getMessage(),
            ]);

            throw new SsoFailed('Could not reach that directory. Try again.');
        }

        return $this->decode((string) $response->getBody(), $url);
    }

    /**
     * @param  array<string, string> $form
     * @return array<string, mixed>
     */
    private function post(string $url, array $form): array
    {
        try {
            $response = $this->http->sendRequest(
                new \GuzzleHttp\Psr7\Request(
                    'POST',
                    $url,
                    [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Accept'       => 'application/json',
                    ],
                    http_build_query($form),
                ),
            );
        } catch (\Throwable $failure) {
            $this->logger->error('An SSO code exchange failed', [
                'url'   => $url,
                'error' => $failure->getMessage(),
            ]);

            throw new SsoFailed('Could not reach that directory. Try again.');
        }

        $body = $this->decode((string) $response->getBody(), $url);

        if ($response->getStatusCode() >= 400) {
            // The provider's own words are logged and not shown: they name the
            // client id and the redirect URI, which is configuration rather
            // than anything the person signing in can act on.
            $this->logger->warning('An identity provider refused a code exchange', [
                'url'    => $url,
                'status' => $response->getStatusCode(),
                'error'  => $body['error'] ?? null,
                'detail' => $body['error_description'] ?? null,
            ]);

            throw new SsoFailed('That sign-in could not be completed. Try again.');
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body, string $url): array
    {
        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            $this->logger->error('An identity provider answered with something that is not JSON', [
                'url' => $url,
            ]);

            throw new SsoFailed('That directory is not answering properly. Try again later.');
        }

        return $decoded;
    }
}
