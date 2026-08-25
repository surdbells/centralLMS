<?php

declare(strict_types=1);

namespace Central\Api\Domain\Ilt;

/**
 * The result of an OAuth token exchange, normalised across platforms.
 */
final readonly class TokenGrant
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public \DateTimeImmutable $expiresAt,
        public ?string $scope = null,
    ) {
    }

    /**
     * @param array<string, mixed> $response a decoded token endpoint response
     */
    public static function fromResponse(array $response, \DateTimeImmutable $now): self
    {
        $accessToken = (string) ($response['access_token'] ?? '');

        if ($accessToken === '') {
            throw new \InvalidArgumentException('Token response contained no access_token.');
        }

        // Default to an hour: every one of the three platforms uses 3600, and
        // a missing expires_in should not mean "never expires".
        $expiresIn = (int) ($response['expires_in'] ?? 3600);

        $refreshToken = isset($response['refresh_token']) && $response['refresh_token'] !== ''
            ? (string) $response['refresh_token']
            : null;

        return new self(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: $now->modify(sprintf('+%d seconds', max(60, $expiresIn))),
            scope: isset($response['scope']) ? (string) $response['scope'] : null,
        );
    }
}
