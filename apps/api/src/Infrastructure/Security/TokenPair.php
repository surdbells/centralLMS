<?php

declare(strict_types=1);

namespace Central\Api\Infrastructure\Security;

final readonly class TokenPair
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public int $expiresIn,
        public string $tokenType = 'Bearer',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'accessToken'  => $this->accessToken,
            'refreshToken' => $this->refreshToken,
            'tokenType'    => $this->tokenType,
            'expiresIn'    => $this->expiresIn,
        ];
    }
}
