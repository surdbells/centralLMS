<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Notification;

/**
 * Exchanges a Google service-account key for an OAuth access token.
 *
 * FCM's v1 API needs one, and a service-account key is not one — it is an RSA
 * private key that has to sign a JWT which is then traded for a token that
 * lasts an hour. Cached in memory for the process, with a minute of headroom,
 * because a worker sending fifty pushes should do this once rather than fifty
 * times.
 */
final class AccessTokenProvider
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private ?string $token = null;

    private int $expiresAt = 0;

    /** @var array{client_email?: string, private_key?: string, token_uri?: string}|null */
    private ?array $credentials = null;

    public function __construct(
        private readonly HttpSender $http,
        private readonly string $credentialsPath,
        private readonly \Central\Api\Support\Clock $clock,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * @throws \RuntimeException when the key is missing or unusable
     */
    public function accessToken(): string
    {
        $now = $this->clock->timestamp();

        if ($this->token !== null && $this->expiresAt > $now + 60) {
            return $this->token;
        }

        $credentials = $this->credentials()
            ?? throw new \RuntimeException('No Firebase service-account key is configured.');

        $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';

        $assertion = $this->assertion($credentials, $tokenUri, $now);

        $response = $this->http->postForm($tokenUri, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $assertion,
        ]);

        if ($response['status'] === 0 || $response['status'] >= 300) {
            throw new \RuntimeException(sprintf(
                'Google refused the service-account assertion (HTTP %d).',
                $response['status'],
            ));
        }

        $decoded = HttpSender::decode($response['body']);
        $token   = $decoded['access_token'] ?? null;

        if (!is_string($token) || $token === '') {
            throw new \RuntimeException('Google returned no access token.');
        }

        $this->token     = $token;
        $this->expiresAt = $now + (int) ($decoded['expires_in'] ?? 3600);

        return $token;
    }

    /**
     * @param array{client_email?: string, private_key?: string, token_uri?: string} $credentials
     */
    private function assertion(array $credentials, string $tokenUri, int $now): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];

        $claims = [
            'iss'   => $credentials['client_email'] ?? '',
            'scope' => self::SCOPE,
            'aud'   => $tokenUri,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $signingInput = $this->base64Url((string) json_encode($header))
            . '.' . $this->base64Url((string) json_encode($claims));

        $key = openssl_pkey_get_private($credentials['private_key'] ?? '');

        if ($key === false) {
            throw new \RuntimeException('The Firebase service-account private key could not be read.');
        }

        $signature = '';

        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign the service-account assertion.');
        }

        return $signingInput . '.' . $this->base64Url($signature);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @return array{client_email?: string, private_key?: string, token_uri?: string}|null
     */
    private function credentials(): ?array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        if ($this->credentialsPath === '' || !is_readable($this->credentialsPath)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($this->credentialsPath), true);

        if (!is_array($decoded) || !isset($decoded['client_email'], $decoded['private_key'])) {
            return null;
        }

        /** @var array{client_email?: string, private_key?: string, token_uri?: string} $decoded */
        return $this->credentials = $decoded;
    }
}
